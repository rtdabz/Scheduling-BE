<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

/** Pure configuration changes; callers retain authorization, preflight and writes. */
final class GenerationAdjustmentInterpreter
{
    public const TYPES = [
        'set_pattern', 'clear_pattern', 'disable_lecture_lab_split', 'disable_minor_split',
        'enable_hybrid_split', 'set_hybrid_split', 'disable_hybrid_split', 'disable_section_hybrid',
        'set_delivery_mode', 'enable_friday_saturday_split', 'add_preferred_day',
        'enable_balanced_split', 'set_integrated_hybrid',
    ];

    public static function supports(array $adjustment): bool
    {
        $type = $adjustment['type'] ?? '';
        $value = $adjustment['value'] ?? null;

        return in_array($type, self::TYPES, true) && match ($type) {
            'set_pattern' => is_string($value) && $value !== '' && SchedulingPolicy::isValidPreferredPattern($value),
            'set_delivery_mode' => $value === null || $value === 'automatic' || (is_string($value) && SchedulingPolicy::isValidDeliveryMode($value)),
            'add_preferred_day' => in_array($value, SchedulingPolicy::PERSISTABLE_DAYS, true),
            default => true,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $configs  Trusted authorized section/course scope.
     * @param  list<array<string, mixed>>  $adjustments  Explicitly selected operations, never an automatic retry.
     * @return array{configs: array<int, array<string, mixed>>, applied: list<array<string, mixed>>}
     */
    public function apply(array $configs, array $adjustments): array
    {
        $writes = [];
        $unique = [];
        // Group witnesses are independent. A combined timetable has not been
        // probed, so these enhancements require selection and regeneration alone.
        $enhancements = array_filter($adjustments, static fn (array $adjustment): bool => in_array($adjustment['type'] ?? '', ['enable_balanced_split', 'set_integrated_hybrid'], true));
        if ($enhancements !== [] && count($adjustments) > 1) {
            throw new InvalidArgumentException('Apply one session alternative and generate again before combining adjustments.');
        }
        foreach ($adjustments as $adjustment) {
            if (! self::supports($adjustment)) {
                throw new InvalidArgumentException('This adjustment is guidance only. Review the configuration instead.');
            }
            $sectionId = (int) ($adjustment['section_id'] ?? 0);
            $courseId = (int) ($adjustment['course_id'] ?? 0);
            $type = $adjustment['type'];
            $sectionLevel = in_array($type, ['disable_section_hybrid', 'enable_friday_saturday_split', 'add_preferred_day'], true);
            if (! isset($configs[$sectionId]) || ($sectionLevel && $courseId !== 0)
                || (! $sectionLevel && ! in_array($courseId, array_map('intval', $configs[$sectionId]['course_ids'] ?? []), true))) {
                throw new InvalidArgumentException('The selected adjustment is outside the configured section/course scope.');
            }
            $value = $type === 'set_delivery_mode' ? ($adjustment['value'] ?? 'automatic') : ($adjustment['value'] ?? null);
            $signature = json_encode([$type, $sectionId, $courseId, $value]);
            if (isset($unique[$signature])) {
                continue;
            }
            foreach ($this->intendedChanges($configs[$sectionId], $type, $courseId, $value) as $field => $desired) {
                $key = $sectionId.':'.$field;
                if (array_key_exists($key, $writes) && $writes[$key] !== $desired) {
                    throw new InvalidArgumentException('Selected adjustments conflict for the same target. Select one alternative and try again.');
                }
                $writes[$key] = $desired;
            }
            $unique[$signature] = $adjustment;
        }

        $yearLevelTargets = [];
        foreach ($unique as $adjustment) {
            if (in_array($adjustment['type'], ['add_preferred_day', 'enable_friday_saturday_split'], true)) {
                $key = json_encode([$adjustment['type'], $adjustment['value'] ?? null]);
                $yearLevelTargets[$key][(int) $adjustment['section_id']] = true;
            }
        }
        foreach ($yearLevelTargets as $targets) {
            if (array_diff_key($configs, $targets) !== []) {
                throw new InvalidArgumentException('A year-level adjustment must include every configured section. Refresh the recommendations.');
            }
        }

        $next = $configs;
        $applied = [];
        foreach ($unique as $adjustment) {
            $sectionId = (int) $adjustment['section_id'];
            $updated = $this->applyOne($next[$sectionId], $adjustment['type'], (int) ($adjustment['course_id'] ?? 0), $adjustment['value'] ?? null);
            if ($updated !== $next[$sectionId]) {
                $next[$sectionId] = $updated;
                $applied[] = $adjustment;
            }
        }

        return ['configs' => $next, 'applied' => $applied];
    }

    /** Semantic writes detect conflicts even when an operation is currently a no-op. */
    private function intendedChanges(array $config, string $type, int $courseId, mixed $value): array
    {
        $key = (string) $courseId;

        return match ($type) {
            'set_pattern', 'clear_pattern' => [$key.':pattern' => $type === 'clear_pattern' ? null : $value],
            'disable_lecture_lab_split' => [$key.':integrated' => false],
            'enable_balanced_split' => [$key.':balanced' => true, $key.':hybrid' => false],
            'set_integrated_hybrid' => [$key.':integrated' => true, $key.':mode' => 'automatic'],
            'disable_minor_split' => [$key.':balanced' => false, $key.':hybrid' => false, $key.':pattern' => null],
            'enable_hybrid_split' => [$key.':hybrid' => true, $key.':mode' => 'automatic'],
            'set_hybrid_split' => [$key.':balanced' => true, $key.':hybrid' => true, $key.':mode' => 'automatic'],
            'disable_hybrid_split' => [$key.':hybrid' => false, $key.':mode' => 'on-site'],
            'set_delivery_mode' => [$key.':mode' => $value, ...($value === 'online' ? [$key.':hybrid' => false] : [])],
            'disable_section_hybrid' => array_fill_keys(array_map(static fn ($id): string => $id.':integrated', $config['course_ids'] ?? []), false),
            'enable_friday_saturday_split' => ['friday_saturday' => true],
            'add_preferred_day' => ['day:'.$value => true],
        };
    }

    private function applyOne(array $config, string $type, int $courseId, mixed $value): array
    {
        $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
        $balancedIds = array_map('intval', $config['balanced_split_course_ids'] ?? []);
        $hybridIds = array_map('intval', $config['hybrid_split_course_ids'] ?? []);
        $modes = $config['delivery_modes_by_course_id'] ?? [];
        switch ($type) {
            case 'enable_balanced_split':
                $config['balanced_split_course_ids'] = array_values(array_unique([...$balancedIds, $courseId]));
                $config['hybrid_split_course_ids'] = array_values(array_diff($hybridIds, [$courseId]));
                break;
            case 'set_integrated_hybrid':
                $config['selected_split_session_course_ids'] = array_values(array_unique([...$splitIds, $courseId]));
                $config['is_hybrid'] = true;
                unset($modes[$courseId]);
                $config['delivery_modes_by_course_id'] = $modes;
                break;
            case 'set_pattern':
                if (in_array($courseId, $balancedIds, true)) {
                    $config['preferred_patterns'][$courseId] = SchedulingPolicy::normalizePreferredPattern($value);
                }
                break;
            case 'clear_pattern':
                unset($config['preferred_patterns'][$courseId]);
                break;
            case 'disable_lecture_lab_split':
                if (in_array($courseId, $splitIds, true)) {
                    $config['selected_split_session_course_ids'] = array_values(array_diff($splitIds, [$courseId]));
                    if ($config['selected_split_session_course_ids'] === []) {
                        $config['is_hybrid'] = false;
                    }
                }
                break;
            case 'disable_minor_split':
                if (in_array($courseId, $balancedIds, true)) {
                    $config['balanced_split_course_ids'] = array_values(array_diff($balancedIds, [$courseId]));
                    $config['hybrid_split_course_ids'] = array_values(array_diff($hybridIds, [$courseId]));
                    unset($config['preferred_patterns'][$courseId]);
                }
                break;
            case 'enable_hybrid_split':
            case 'set_hybrid_split':
                if (($type === 'set_hybrid_split' || in_array($courseId, $balancedIds, true)) && ! in_array($courseId, $hybridIds, true)) {
                    $config['balanced_split_course_ids'] = array_values(array_unique([...$balancedIds, $courseId]));
                    $config['hybrid_split_course_ids'] = [...$hybridIds, $courseId];
                    unset($modes[$courseId]);
                    $config['delivery_modes_by_course_id'] = $modes;
                }
                break;
            case 'disable_hybrid_split':
                if (in_array($courseId, $hybridIds, true)) {
                    $config['hybrid_split_course_ids'] = array_values(array_diff($hybridIds, [$courseId]));
                    $modes[$courseId] = 'on-site';
                    $config['delivery_modes_by_course_id'] = $modes;
                }
                break;
            case 'disable_section_hybrid':
                if ($splitIds !== [] || ($config['is_hybrid'] ?? false)) {
                    $config['selected_split_session_course_ids'] = [];
                    $config['is_hybrid'] = false;
                }
                break;
            case 'set_delivery_mode':
                if ($value === null || $value === 'automatic') {
                    unset($modes[$courseId]);
                } else {
                    $modes[$courseId] = $value;
                }
                if ($modes !== ($config['delivery_modes_by_course_id'] ?? [])) {
                    $config['delivery_modes_by_course_id'] = $modes;
                }
                if ($value === 'online' && in_array($courseId, $hybridIds, true)) {
                    $config['hybrid_split_course_ids'] = array_values(array_diff($hybridIds, [$courseId]));
                }
                break;
            case 'enable_friday_saturday_split':
                if (! ($config['allow_friday_saturday_split'] ?? false)) {
                    $config['allow_friday_saturday_split'] = true;
                }
                break;
            case 'add_preferred_day':
                $days = SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null);
                if ($days !== null && ! in_array($value, $days, true)) {
                    $config['allowed_days'] = SchedulingPolicy::normalizeAllowedDays([...$days, $value]);
                }
                break;
        }

        return $config;
    }
}
