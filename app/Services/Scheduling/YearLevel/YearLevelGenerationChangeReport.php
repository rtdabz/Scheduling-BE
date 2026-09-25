<?php

namespace App\Services\Scheduling\YearLevel;

/**
 * What a successful year-level run delivered differently from what was asked.
 *
 * A generated timetable can be valid and still not be the one the scheduler
 * configured: the retry ladder may have relaxed a preference, a lecture may
 * have gone online because every room was taken, a laboratory may be waiting
 * on a room. Each of those is reported once, grouped by kind, so the summary
 * can say what changed instead of leaving it to be spotted row by row.
 *
 * Only facts visible in the result are reported. Hard rules are never relaxed,
 * so there is nothing to say about them.
 */
class YearLevelGenerationChangeReport
{
    public const KIND_PREFERENCE_RELAXED = 'preference_relaxed';

    public const KIND_SINGLE_MEETING = 'split_session_single_meeting';

    public const KIND_MOVED_ONLINE = 'lecture_moved_online';

    public const KIND_ROOM_TBA = 'room_tba';

    /**
     * @param  array<string, mixed>|null  $strategy  the retry strategy that produced the result
     * @param  list<array<string, mixed>>  $splitFallbacks
     * @param  list<array<string, mixed>>  $schedules
     * @param  array<int, string>  $sectionNames
     * @param  array<int, string>  $courseCodes
     * @param  array<string, mixed>|null  $bottleneck  what blocked the original configuration
     * @param  list<array<string, mixed>>  $attempts  every attempt the run made, in order
     * @return list<array<string, mixed>>
     */
    public function build(
        ?array $strategy,
        array $splitFallbacks,
        array $schedules,
        array $sectionNames,
        array $courseCodes,
        ?array $bottleneck = null,
        array $attempts = [],
    ): array {
        $changes = [];

        // A retry that only re-ordered the search changed nothing the
        // scheduler configured, so it is not reported as a change.
        $relaxations = array_values((array) ($strategy['adjustments'] ?? []));
        if ($strategy !== null && $relaxations !== []) {
            $changes[] = [
                ...$this->change(
                    self::KIND_PREFERENCE_RELAXED,
                    'warning',
                    (string) ($strategy['label'] ?? 'Preference adjusted on retry'),
                    (string) ($strategy['description'] ?? 'The configuration as entered found no timetable, so the generator relaxed a preference and tried again.'),
                    array_map(
                        fn (array $adjustment): array => [
                            ...$this->item(
                                $adjustment,
                                $sectionNames,
                                $courseCodes,
                                $this->describeRelaxation($adjustment),
                            ),
                            'adjustment_type' => (string) ($adjustment['type'] ?? ''),
                            'adjustment_value' => $adjustment['value'] ?? null,
                        ],
                        $relaxations,
                    ),
                ),
                // Why the retry was needed, so the change can be explained by
                // what the generator detected rather than by the fix alone.
                'detected_issue' => $bottleneck === null ? null : [
                    'type' => (string) ($bottleneck['type'] ?? ''),
                    'section_name' => (string) ($bottleneck['section_name'] ?? ''),
                    'course_code' => (string) ($bottleneck['course_code'] ?? ''),
                    'detected_cause' => (string) ($bottleneck['detected_cause'] ?? ''),
                ],
                'failed_attempts' => count(array_filter(
                    $attempts,
                    static fn (array $attempt): bool => ($attempt['outcome'] ?? '') === 'failed',
                )),
            ];
        }

        if ($splitFallbacks !== []) {
            $changes[] = $this->change(
                self::KIND_SINGLE_MEETING,
                'warning',
                'Split session changed to one meeting',
                'These courses were set to meet twice a week, but no second slot was free, so each meets once instead.',
                array_map(
                    fn (array $fallback): array => $this->item($fallback, $sectionNames, $courseCodes, 'Meets once a week instead of twice'),
                    $splitFallbacks,
                ),
            );
        }

        $online = $this->classesWhere(
            $schedules,
            static fn (array $row): bool => ! empty($row['lecture_online_fallback']),
        );
        if ($online !== []) {
            $changes[] = $this->change(
                self::KIND_MOVED_ONLINE,
                'warning',
                'Lecture moved online',
                'No lecture room was free at a time that fit, so these lectures were scheduled online.',
                $this->classItems($online, $sectionNames, $courseCodes, 'Scheduled online: no lecture room was available'),
            );
        }

        $tba = $this->classesWhere(
            $schedules,
            static fn (array $row): bool => ($row['mode'] ?? 'on-site') === 'on-site' && empty($row['room_id']),
        );
        if ($tba !== []) {
            $changes[] = $this->change(
                self::KIND_ROOM_TBA,
                'critical',
                'Room to be assigned',
                'These in-person meetings have a time but no room yet. Assign a room before publishing.',
                $this->classItems($tba, $sectionNames, $courseCodes, 'Room TBA'),
                resolved: false,
            );
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function change(
        string $kind,
        string $severity,
        string $title,
        string $description,
        array $items,
        bool $resolved = true,
    ): array
    {
        return [
            'kind' => $kind,
            'severity' => $severity,
            'status' => $resolved ? 'resolved' : 'active',
            'resolved' => $resolved,
            'title' => $title,
            'description' => $description,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, string>  $sectionNames
     * @param  array<int, string>  $courseCodes
     * @return array<string, mixed>
     */
    private function item(array $source, array $sectionNames, array $courseCodes, string $detail): array
    {
        $sectionId = (int) ($source['section_id'] ?? 0);
        $courseId = (int) ($source['course_id'] ?? 0);

        return [
            'section_id' => $sectionId,
            'section_name' => (string) ($source['section_name'] ?? $sectionNames[$sectionId] ?? 'Section '.$sectionId),
            'course_id' => $courseId,
            'course_code' => (string) ($source['course_code'] ?? $courseCodes[$courseId] ?? 'Course '.$courseId),
            'detail' => $detail,
        ];
    }

    /** @param  array<string, mixed>  $adjustment */
    private function describeRelaxation(array $adjustment): string
    {
        $value = $adjustment['value'] ?? null;

        return match ((string) ($adjustment['type'] ?? '')) {
            'set_pattern' => "Meeting pattern changed to {$value}",
            'clear_pattern' => 'Meeting pattern set to Automatic',
            'disable_lecture_lab_split' => 'Lecture/lab split turned off',
            'disable_minor_split' => 'Split Session turned off; scheduled as one meeting',
            'enable_friday_saturday_split' => 'Friday + Saturday allowed as paired days',
            'add_preferred_day' => "{$value} added to the Preferred Days",
            'set_delivery_mode' => $value === 'automatic' ? 'Delivery mode set to Automatic' : "Delivery mode set to {$value}",
            'disable_section_hybrid' => 'Hybrid delivery turned off for the section',
            default => 'Configuration adjusted',
        };
    }

    /**
     * One entry per section and course that has at least one matching row:
     * `schedules.day` is one row per meeting, so an MWF class would otherwise
     * be reported three times.
     *
     * @param  list<array<string, mixed>>  $schedules
     * @param  callable(array<string, mixed>): bool  $matches
     * @return array<string, array{section_id: int, course_id: int, days: list<string>}>
     */
    private function classesWhere(array $schedules, callable $matches): array
    {
        $classes = [];
        foreach ($schedules as $row) {
            if (! $matches($row)) {
                continue;
            }

            $sectionId = (int) ($row['section_id'] ?? 0);
            $courseId = (int) ($row['course_id'] ?? 0);
            $key = $sectionId.'|'.$courseId;
            $classes[$key] ??= ['section_id' => $sectionId, 'course_id' => $courseId, 'days' => []];

            $day = (string) ($row['day'] ?? '');
            if ($day !== '' && ! in_array($day, $classes[$key]['days'], true)) {
                $classes[$key]['days'][] = $day;
            }
        }

        return $classes;
    }

    /**
     * @param  array<string, array{section_id: int, course_id: int, days: list<string>}>  $classes
     * @param  array<int, string>  $sectionNames
     * @param  array<int, string>  $courseCodes
     * @return list<array<string, mixed>>
     */
    private function classItems(array $classes, array $sectionNames, array $courseCodes, string $detail): array
    {
        return array_values(array_map(
            fn (array $class): array => $this->item(
                $class,
                $sectionNames,
                $courseCodes,
                $class['days'] === [] ? $detail : $detail.' ('.implode(', ', $class['days']).')',
            ),
            $classes,
        ));
    }
}
