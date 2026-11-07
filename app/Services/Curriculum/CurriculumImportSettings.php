<?php

namespace App\Services\Curriculum;

use App\Models\Course;
use App\Models\CurriculumClassificationRule;
use App\Models\Departments;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CurriculumImportSettings
{
    public const MAX_MINUTES_PER_UNIT = 600;

    public const MAX_RULES = 200;

    public const MAX_MATCH_LENGTH = 64;

    public static function normalizeCode(mixed $code): string
    {
        return is_string($code) ? trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper($code))) : '';
    }

    public static function prefixMatches(string $normalizedCode, string $prefix): bool
    {
        if ($prefix === '' || ! str_starts_with($normalizedCode, $prefix)) {
            return false;
        }
        if (strlen($normalizedCode) === strlen($prefix)) {
            return true;
        }
        $next = $normalizedCode[strlen($prefix)];
        $last = $prefix[strlen($prefix) - 1];

        // A prefix ends at a word boundary or before a digit/separator: IT matches IT 101 and IT101, not ITEM 101.
        return ctype_digit($next) || in_array($next, [' ', '-', '/', '.', '_'], true)
            || ! ctype_alpha($last);
    }

    /** @return Collection<int, CurriculumClassificationRule> */
    public function rules(int $departmentId): Collection
    {
        return CurriculumClassificationRule::query()
            ->where('department_id', $departmentId)
            ->orderBy('match_value')
            ->get();
    }

    /**
     * Every rule is an enabled prefix; the longest matching prefix wins.
     *
     * @param  Collection<int, CurriculumClassificationRule>  $rules
     * @return array{id: int|null, match_kind: string, match_value: string, category: string}|null
     */
    public static function classify(string $normalizedCode, Collection $rules): ?array
    {
        $prefix = $rules
            ->filter(fn ($rule): bool => self::prefixMatches($normalizedCode, (string) $rule->match_value))
            ->sortByDesc(fn ($rule): int => strlen((string) $rule->match_value))
            ->first();

        return $prefix === null ? null : self::ruleSummary($prefix);
    }

    /** @return array{id: int|null, match_kind: string, match_value: string, category: string} */
    private static function ruleSummary(object $rule): array
    {
        return [
            'id' => isset($rule->id) ? (int) $rule->id : null,
            'match_kind' => CurriculumClassificationRule::KIND_PREFIX,
            'match_value' => (string) $rule->match_value,
            'category' => (string) $rule->category,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(Departments $department, bool $canManage): array
    {
        $rules = $this->rules((int) $department->id);
        $codes = Course::query()
            ->where('department_id', $department->id)
            ->orderBy('course_code')
            ->pluck('course_code')
            ->map(fn ($code): string => self::normalizeCode($code))
            ->unique()
            ->values();

        return [
            'department_id' => (int) $department->id,
            'revision' => (int) $department->curriculum_import_settings_revision,
            'lecture_minutes_per_unit' => (int) $department->lecture_minutes_per_unit,
            'laboratory_minutes_per_unit' => (int) $department->laboratory_minutes_per_unit,
            'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
            'max_minutes_per_unit' => self::MAX_MINUTES_PER_UNIT,
            'can_manage' => $canManage,
            'rules' => $rules->map(fn (CurriculumClassificationRule $rule): array => [
                'id' => (int) $rule->id,
                'match_value' => $rule->match_value,
                'category' => $rule->category,
                'sample_matches' => $codes
                    ->filter(fn (string $code): bool => self::prefixMatches($code, (string) $rule->match_value))
                    ->take(5)
                    ->values()
                    ->all(),
            ])->values()->all(),
            'overlaps' => self::overlaps($rules->map(fn ($rule): array => [
                'match_value' => $rule->match_value,
                'category' => $rule->category,
            ])->all()),
        ];
    }

    /**
     * Longer prefixes with a different category than a shorter prefix they fall under.
     *
     * @param  list<array{match_value: string, category: string}>  $rules
     * @return list<array{broader: string, specific: string, broader_category: string, specific_category: string}>
     */
    public static function overlaps(array $rules): array
    {
        $overlaps = [];

        foreach ($rules as $specific) {
            foreach ($rules as $broader) {
                if ($specific['category'] === $broader['category']
                    || strlen($specific['match_value']) <= strlen($broader['match_value'])) {
                    continue;
                }
                if (self::prefixMatches($specific['match_value'], $broader['match_value'])) {
                    $overlaps[] = [
                        'broader' => $broader['match_value'],
                        'specific' => $specific['match_value'],
                        'broader_category' => $broader['category'],
                        'specific_category' => $specific['category'],
                    ];
                }
            }
        }

        return $overlaps;
    }

    /**
     * Replaces the department's rates and rules atomically. Every rule is stored as an enabled prefix.
     * Existing placements, courses, and schedules are never recalculated.
     *
     * @param  array{expected_revision: int, lecture_minutes_per_unit: int, laboratory_minutes_per_unit: int, rules: list<array<string, mixed>>, acknowledge_overlaps?: bool}  $input
     */
    public function update(Departments $department, array $input): Departments
    {
        $rules = [];
        $errors = [];
        $seen = [];
        foreach ($input['rules'] as $index => $rule) {
            $value = self::normalizeCode($rule['match_value'] ?? '');
            if ($value === '' || mb_strlen($value) > self::MAX_MATCH_LENGTH || preg_match('/\p{Cc}/u', $value)) {
                $errors["rules.$index.match_value"] = 'Enter a literal code or prefix of 1 to '.self::MAX_MATCH_LENGTH.' printable characters.';

                continue;
            }
            if (isset($seen[$value])) {
                $errors["rules.$index.match_value"] = sprintf('The prefix "%s" is listed more than once.', $value);

                continue;
            }
            $seen[$value] = true;
            $rules[] = [
                'match_kind' => CurriculumClassificationRule::KIND_PREFIX,
                'match_value' => $value,
                'category' => (string) $rule['category'],
                'enabled' => true,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $overlaps = self::overlaps($rules);
        if ($overlaps !== [] && ! ($input['acknowledge_overlaps'] ?? false)) {
            throw ValidationException::withMessages([
                'acknowledge_overlaps' => 'Some specific rules classify codes differently from a broader prefix. Confirm these exceptions before saving.',
            ]);
        }

        return DB::transaction(function () use ($department, $input, $rules): Departments {
            $locked = Departments::query()->lockForUpdate()->findOrFail($department->id);
            if ((int) $locked->curriculum_import_settings_revision !== (int) $input['expected_revision']) {
                throw new CurriculumImportConflict('These import settings were changed by someone else. Reload them and apply your changes again.');
            }

            CurriculumClassificationRule::query()->where('department_id', $locked->id)->delete();
            $now = now();
            if ($rules !== []) {
                CurriculumClassificationRule::query()->insert(array_map(fn (array $rule): array => $rule + [
                    'department_id' => $locked->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rules));
            }

            $locked->forceFill([
                'lecture_minutes_per_unit' => (int) $input['lecture_minutes_per_unit'],
                'laboratory_minutes_per_unit' => (int) $input['laboratory_minutes_per_unit'],
                'curriculum_import_settings_revision' => (int) $locked->curriculum_import_settings_revision + 1,
            ])->save();
            SchedulingPolicy::clearFieldCourseCache();

            return $locked;
        });
    }
}
