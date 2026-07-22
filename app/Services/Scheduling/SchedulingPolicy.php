<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class SchedulingPolicy
{
    public const OPERATING_START_MINUTES = 7 * 60;
    public const SLOT_MINUTES = 30;
    public const TOTAL_SLOTS = 28;

    public const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];

    public const PERSISTABLE_DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    public const DELIVERY_MODES = ['on-site', 'online', 'field'];
    public const ROOM_TYPES = ['lecture', 'laboratory', 'field', 'online'];
    public const ROOM_STATUSES = ['available', 'not available'];
    public const COURSE_CATEGORIES = ['major', 'minor'];
    public const SUBJECT_CATEGORIES = ['major', 'minor'];
    public const YEAR_LEVELS = ['1', '2', '3', '4'];
    public const SEMESTERS = ['1st', '2nd', 'summer'];
    public const ACTIVE_STATUSES = ['active', 'inactive'];
    public const SCHEDULE_STATUSES = [
        'draft',
        'completed',
        'submitted',
        'approved_by_dean',
        'rejected_by_dean',
        'approved',
        'faculty_assignment',
        'finalized',
        'rejected',
    ];

    /** @var array<string, array{0: string, 1: string}> */
    public const FIXED_MEETING_PATTERNS = [
        'MW' => ['Monday', 'Wednesday'],
        'TTh' => ['Tuesday', 'Thursday'],
    ];

    public const CUSTOM_PATTERN_REGEX = '/^days:([0-5])-([0-5])$/';

    public const SOFT_SATURDAY_PENALTY = 8;
    public const SOFT_LATE_START_AFTER_SLOT = 22;
    public const SOFT_LATE_SLOT_PENALTY = 2;
    public const SOFT_GAP_SLOT_PENALTY = 3;
    public const SOFT_ROOM_CHANGE_PENALTY = 1;

    /**
     * Canonical constraint catalog shared by RuleEngine, CSP, and request validation.
     *
     * Severity:
     * - hard: invalid schedules are rejected or pruned from CSP domains.
     * - soft: valid schedules are ranked lower by the CSP scorer.
     */
    public const CONSTRAINT_CATALOG = [
        'valid_day' => [
            'severity' => 'hard',
            'category' => 'calendar',
            'description' => 'A persisted schedule day must be one of the supported institutional day names.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'csp_generation_day' => [
            'severity' => 'hard',
            'category' => 'calendar',
            'description' => 'CSP-generated schedules use the Monday-Saturday grid used by the scheduler UI.',
            'enforced_by' => ['csp'],
        ],
        'operating_hours' => [
            'severity' => 'hard',
            'category' => 'time',
            'description' => 'Schedules must be within 7:00 AM and 9:00 PM.',
            'enforced_by' => ['request_validation', 'rule_engine', 'csp'],
        ],
        'slot_grid' => [
            'severity' => 'hard',
            'category' => 'time',
            'description' => 'Schedule times must be represented as 30-minute slots on the operating-hours grid.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'term_enabled' => [
            'severity' => 'hard',
            'category' => 'academic_term',
            'description' => 'Schedules can only be created for enabled academic terms.',
            'enforced_by' => ['rule_engine'],
        ],
        'section_term_alignment' => [
            'severity' => 'hard',
            'category' => 'academic_term',
            'description' => 'The selected section must belong to the selected schedule term.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'section_term_semester_alignment' => [
            'severity' => 'hard',
            'category' => 'academic_term',
            'description' => 'A section semester must match its academic term semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'section_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'A section cannot attend overlapping classes in the same term.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'room_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'A room cannot host overlapping classes in the same term.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'faculty_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'An assigned faculty member cannot teach overlapping classes in the same term.',
            'enforced_by' => ['rule_engine'],
        ],
        'room_type_match' => [
            'severity' => 'hard',
            'category' => 'room',
            'description' => 'A subject must be scheduled in a room matching its required room type.',
            'enforced_by' => ['request_validation', 'rule_engine', 'csp'],
        ],
        'room_availability' => [
            'severity' => 'hard',
            'category' => 'room',
            'description' => 'Schedules can only assign rooms marked available.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'room_department_alignment' => [
            'severity' => 'hard',
            'category' => 'room',
            'description' => 'A room must be shared or owned by the scheduled section department.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'preferred_pattern' => [
            'severity' => 'hard',
            'category' => 'meeting_pattern',
            'description' => 'When a meeting pattern is declared, all generated or saved days must belong to that pattern.',
            'enforced_by' => ['request_validation', 'rule_engine', 'csp'],
        ],
        'delivery_mode' => [
            'severity' => 'hard',
            'category' => 'delivery',
            'description' => 'Delivery mode must be on-site, online, or field.',
            'enforced_by' => ['request_validation', 'csp'],
        ],
        'delivery_room_alignment' => [
            'severity' => 'hard',
            'category' => 'delivery',
            'description' => 'Online schedules must use online rooms, field schedules must use field rooms, and on-site schedules must use physical lecture or laboratory rooms.',
            'enforced_by' => ['rule_engine'],
        ],
        'hybrid_mode' => [
            'severity' => 'hard',
            'category' => 'delivery',
            'description' => 'Field schedules cannot be marked hybrid.',
            'enforced_by' => ['request_validation', 'csp'],
        ],
        'subject_section_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'CSP subjects must be active and match the section year level and semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'schedule_department_alignment' => [
            'severity' => 'hard',
            'category' => 'department',
            'description' => 'The persisted schedule department must match the selected section department.',
            'enforced_by' => ['rule_engine'],
        ],
        'faculty_active' => [
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'Inactive faculty members cannot be assigned to schedules.',
            'enforced_by' => ['rule_engine'],
        ],
        'section_active' => [
            'severity' => 'hard',
            'category' => 'section',
            'description' => 'Inactive sections cannot be scheduled.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'subject_active' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'Inactive subjects cannot be scheduled.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'duplicate_section_subject' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'A recommendation cannot be accepted if the section already has a schedule for that subject in the same term.',
            'enforced_by' => ['recommendation_acceptance'],
        ],
        'major_department_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'Major subjects with a department must match the section department.',
            'enforced_by' => ['csp'],
        ],
        'recommendation_atomic_acceptance' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'Recommendation acceptance must create all schedule rows and audit history in one database transaction.',
            'enforced_by' => ['recommendation_acceptance'],
        ],
        'atomic_multi_block_schedule' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'Linked schedule blocks for split, hybrid, or multi-day meetings must be validated and saved as one atomic operation.',
            'enforced_by' => ['schedule_batch_api', 'rule_engine', 'csp', 'recommendation_acceptance'],
        ],
        'recommendation_audit_history' => [
            'severity' => 'hard',
            'category' => 'audit',
            'description' => 'Recommendation generation, review, acceptance, and rejection must be recorded in scheduling audit history.',
            'enforced_by' => ['recommendation_api'],
        ],
        'saturday_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer weekday schedules over Saturday schedules.',
            'enforced_by' => ['csp'],
        ],
        'late_slot_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer earlier start times over late-day starts.',
            'enforced_by' => ['csp'],
        ],
        'split_balance_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer balanced durations when a subject is split across two meeting days.',
            'enforced_by' => ['csp'],
        ],
        'gap_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer compact daily section schedules with fewer gaps.',
            'enforced_by' => ['csp'],
        ],
        'room_change_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer keeping adjacent section classes in the same room when possible.',
            'enforced_by' => ['csp'],
        ],
    ];

    public static function catalog(): array
    {
        return self::CONSTRAINT_CATALOG;
    }

    public static function hardConstraintIds(): array
    {
        return self::constraintIdsBySeverity('hard');
    }

    public static function softConstraintIds(): array
    {
        return self::constraintIdsBySeverity('soft');
    }

    public static function allowedDaysRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::PERSISTABLE_DAYS);
    }

    public static function allowedDeliveryModesRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::DELIVERY_MODES);
    }

    public static function allowedScheduleStatusesRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::SCHEDULE_STATUSES);
    }

    public static function allowedRoomTypesRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::ROOM_TYPES);
    }

    public static function allowedRoomStatusesRule(string|array $prefix = []): array
    {
        $rules = is_array($prefix) ? $prefix : array_filter(explode('|', $prefix));
        $rules[] = Rule::in(self::ROOM_STATUSES);

        return $rules;
    }

    public static function allowedSubjectCategoriesRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::SUBJECT_CATEGORIES);
    }

    public static function allowedYearLevelsRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::YEAR_LEVELS);
    }

    public static function allowedSemestersRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::SEMESTERS);
    }

    public static function allowedActiveStatusesRule(string $prefix): string
    {
        return $prefix.'|in:'.implode(',', self::ACTIVE_STATUSES);
    }

    public static function openingTime(): string
    {
        return self::slotToTime(0);
    }

    public static function closingTime(): string
    {
        return self::slotToTime(self::TOTAL_SLOTS);
    }

    public static function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    public static function isWithinOperatingHours(string $startTime, string $endTime): bool
    {
        $start = self::normalizeTime($startTime);
        $end = self::normalizeTime($endTime);

        return $start >= self::openingTime() && $end <= self::closingTime();
    }

    public static function slotToTime(int $slot): string
    {
        if ($slot < 0 || $slot > self::TOTAL_SLOTS) {
            throw new InvalidArgumentException(sprintf(
                'Slot %d is outside the valid 0-%d range.',
                $slot,
                self::TOTAL_SLOTS,
            ));
        }

        $minutes = self::OPERATING_START_MINUTES
            + ($slot * self::SLOT_MINUTES);

        return sprintf(
            '%02d:%02d:00',
            intdiv($minutes, 60),
            $minutes % 60,
        );
    }

    public static function dayIndex(string $day): int
    {
        $index = array_search($day, self::DAYS, true);

        if ($index === false) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported CSP scheduling day "%s".',
                $day,
            ));
        }

        return $index;
    }

    public static function isValidDeliveryMode(string $deliveryMode): bool
    {
        return in_array($deliveryMode, self::DELIVERY_MODES, true);
    }

    public static function isValidRoomType(string $roomType): bool
    {
        return in_array($roomType, self::ROOM_TYPES, true);
    }

    public static function isValidYearLevel(string $yearLevel): bool
    {
        return in_array($yearLevel, self::YEAR_LEVELS, true);
    }

    public static function isValidSemester(string $semester): bool
    {
        return in_array($semester, self::SEMESTERS, true);
    }

    public static function normalizePreferredPattern(mixed $preferredPattern): ?string
    {
        if ($preferredPattern === null || $preferredPattern === '') {
            return null;
        }

        $preferredPattern = (string) $preferredPattern;

        if (array_key_exists($preferredPattern, self::FIXED_MEETING_PATTERNS)) {
            return $preferredPattern;
        }

        if (preg_match(self::CUSTOM_PATTERN_REGEX, $preferredPattern, $matches) === 1) {
            if ($matches[1] === $matches[2]) {
                throw new InvalidArgumentException(
                    'Preferred pattern days must be two different days.',
                );
            }

            return $preferredPattern;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported preferred pattern "%s".',
            $preferredPattern,
        ));
    }

    public static function isValidPreferredPattern(mixed $preferredPattern): bool
    {
        try {
            self::normalizePreferredPattern($preferredPattern);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{0: string, 1: string}|null */
    public static function allowedDaysForPattern(mixed $preferredPattern): ?array
    {
        $preferredPattern = self::normalizePreferredPattern($preferredPattern);

        if ($preferredPattern === null) {
            return null;
        }

        if (array_key_exists($preferredPattern, self::FIXED_MEETING_PATTERNS)) {
            return self::FIXED_MEETING_PATTERNS[$preferredPattern];
        }

        if (preg_match(self::CUSTOM_PATTERN_REGEX, $preferredPattern, $matches) === 1) {
            return [
                self::DAYS[(int) $matches[1]],
                self::DAYS[(int) $matches[2]],
            ];
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported preferred pattern "%s".',
            $preferredPattern,
        ));
    }

    private static function constraintIdsBySeverity(string $severity): array
    {
        return array_keys(array_filter(
            self::CONSTRAINT_CATALOG,
            static fn (array $constraint): bool =>
                $constraint['severity'] === $severity,
        ));
    }
}
