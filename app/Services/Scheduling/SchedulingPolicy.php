<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Services\TimeslotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class SchedulingPolicy
{
    public const SLOT_MINUTES = 30;

    /** End of the ordinary field-course day. Shared by generation and validation. */
    public const FIELD_DAY_END_TIME = '17:00:00';

    private static ?string $cachedOpeningTime = null;
    private static ?string $cachedClosingTime = null;

    /** @var array<int, list<int>> */
    private static array $cachedStartSlotsByDuration = [];


    /** @var array<string, true>|null */
    /** @var array<string, array<string, true>> */
    private static array $cachedFieldCourseCodeMap = [];

    /** @var array<int, array<string, true>>|null */
    private static ?array $cachedCourseCategoryMap = null;

    public const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    /** Mon-Fri only. Used for PATHFIT and other non-NSTP field courses. */
    public const WEEKDAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
    ];

    /** Mon-Sat. Used for major and non-field minor course day constraints. */
    public const WEEKDAYS_AND_SATURDAY = [
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
        'revision',
        'conditionally_approved',
    ];

    /**
     * Statuses at which a schedule may be given an instructor. Instructor
     * assignment is a post-VPAA-approval step, so anything earlier in the
     * workflow — and `finalized`, which is locked — is refused.
     */
    /**
     * The bands an instructor's load climbs through. Assignment is allowed in
     * every one of them: the bands decide what the user is asked to confirm and
     * what the load is called, not whether the save is permitted.
     */
    public const LOAD_TIER_BASIC = 'basic';

    public const LOAD_TIER_OVERLOAD = 'overload';

    public const LOAD_TIER_PROBONO = 'probono';

    public const LOAD_TIER_BEYOND_CEILING = 'beyond_ceiling';

    public const LOAD_TIER_LABELS = [
        self::LOAD_TIER_BASIC => 'Basic Load',
        self::LOAD_TIER_OVERLOAD => 'Overload',
        self::LOAD_TIER_PROBONO => 'Pro-bono',
        self::LOAD_TIER_BEYOND_CEILING => 'Beyond ceiling',
    ];

    public const INSTRUCTOR_ASSIGNABLE_STATUSES = ['approved', 'faculty_assignment'];

    /**
     * Statuses at which an existing instructor assignment counts as real: it is
     * listed in the assignment workspace and included in teaching load. A row
     * that fell back to `draft`, `completed` or `revision` is no longer an
     * approved assignment, so it must not inflate anyone's load.
     */
    public const INSTRUCTOR_ASSIGNED_STATUSES = ['approved', 'faculty_assignment', 'finalized'];

    /** @var array<string, array{0: string, 1: string}> */
    public const FIXED_MEETING_PATTERNS = [
        'MW' => ['Monday', 'Wednesday'],
        'TTh' => ['Tuesday', 'Thursday'],
    ];

    public const CUSTOM_PATTERN_REGEX = '/^days:([0-6])-([0-6])$/';

    public const MAX_CLASSES_PER_DAY = 3;

    public const SOFT_SATURDAY_PENALTY = 8;
    public const SOFT_LATE_START_AFTER_SLOT = 22;
    public const SOFT_LATE_SLOT_PENALTY = 2;
    public const SOFT_GAP_SLOT_PENALTY = 1200;
    public const SOFT_UNUSABLE_GAP_PENALTY = 5000;
    public const SOFT_ROOM_IDLE_GAP_SLOT_PENALTY = 90;
    public const SOFT_UNUSABLE_ROOM_GAP_PENALTY = 900;
    public const SOFT_FILLABLE_ROOM_GAP_BONUS_PENALTY = 450;
    public const SOFT_ROOM_CHANGE_PENALTY = 1;
    /**
     * Applied when a major course that prefers a laboratory room is assigned
     * to a lecture room because no laboratory was available for the department.
     */
    public const SOFT_LAB_FALLBACK_PENALTY = 15;
    /**
     * Applied when an on-site course is scheduled online as a fallback.
     */
    public const SOFT_ONLINE_FALLBACK_PENALTY = 1000;
    /** Prefer a feasible weekday physical placement over a weekend placement. */
    public const SOFT_WEEKDAY_PHYSICAL_MIGRATION_PENALTY = 6000;
    /** Prefer a feasible weekday physical placement over online delivery. */
    public const SOFT_WEEKDAY_ONLINE_MIGRATION_PENALTY = 12000;

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
            'description' => 'CSP-generated schedules use the Monday-Sunday grid used by the scheduler UI.',
            'enforced_by' => ['csp'],
        ],
        'operating_hours' => [
            'severity' => 'hard',
            'category' => 'time',
            'description' => 'Schedules must be within configured institution operating hours.',
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
        'subject_section_time_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'Different online sections taking the same subject cannot overlap in the same term and time slot.',
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
        'major_faculty_department_alignment' => [
            'severity'    => 'hard',
            'category'    => 'faculty',
            'description' => 'A major subject must be assigned to an instructor from the department that offers it, and cannot be delegated to another department.',
            'enforced_by' => ['rule_engine'],
        ],
        'major_faculty_program_alignment' => [
            'severity'    => 'hard',
            'category'    => 'faculty',
            'description' => 'A major subject tied to a program must be assigned to an instructor belonging to that program.',
            'enforced_by' => ['rule_engine'],
        ],
        'service_subject_faculty_department_alignment' => [
            'severity'    => 'hard',
            'category'    => 'faculty',
            'description' => 'A GEC service subject must be assigned to an instructor from the college that offers it. Any other minor may be taught by an instructor from any department.',
            'enforced_by' => ['rule_engine'],
        ],
        'part_time_faculty_availability' => [
            'severity'    => 'hard',
            'category'    => 'faculty',
            'description' => 'Part-time instructors can only be assigned from 5:00 PM onward on weekdays, or at any time on Saturdays or Sundays.',
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
            'description' => 'Prefer weekday schedules over Saturday or Sunday schedules.',
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
            'category' => 'schedule_compactness',
            'description' => 'Prefer compact daily section schedules with fewer gaps.',
            'enforced_by' => ['csp'],
        ],
        'facility_utilization_gap_penalty' => [
            'severity' => 'soft',
            'category' => 'resource_fairness',
            'description' => 'Prefer schedules that reduce fillable idle gaps in physical rooms during operating hours.',
            'enforced_by' => ['csp'],
        ],
        'room_change_penalty' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer keeping adjacent section classes in the same room when possible.',
            'enforced_by' => ['csp'],
        ],
        'lab_preference' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Major courses that require a laboratory room prefer a lab; a lecture room is accepted as a fallback when no lab is available for the department.',
            'enforced_by' => ['csp'],
        ],
        'online_preference' => [
            'severity' => 'soft',
            'category' => 'preference',
            'description' => 'Prefer on-site physical room assignments over online delivery when physical rooms are available.',
            'enforced_by' => ['csp'],
        ],
        'faculty_unit_ceiling' => [
            'severity' => 'hard',
            'category' => 'workload',
            'description' => 'Keep an instructor at or below their unit ceiling (maximum units, less deload, plus overload and pro bono allowances).',
            'enforced_by' => ['instructor_assignment'],
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

    /**
     * The units an instructor is expected to carry: their maximum less whatever
     * has been deloaded for administrative or other duties.
     */
    public static function facultyRequiredUnits(mixed $faculty): int
    {
        $max = (int) ($faculty->max_units ?? 0);
        $deload = (int) ($faculty->deload_units ?? 0);

        return max(0, $max - $deload);
    }

    /**
     * The highest load an instructor may carry before the assignment counts as
     * over-ceiling: the required load plus the overload and pro bono units that
     * were explicitly granted to them. Kept soft on purpose — a chair may still
     * need to overload someone, so the assignment warns instead of refusing.
     */
    public static function facultyUnitCeiling(mixed $faculty): int
    {
        return self::facultyRequiredUnits($faculty)
            + (int) ($faculty->overload_units ?? 0)
            + (int) ($faculty->probono_units ?? 0);
    }

    /**
     * The instructor's Basic Load: the maximum units they were given, less any
     * deload. A dean with a 21-unit maximum and 6 units of deload has a 15-unit
     * Basic Load, and anything past that is an overload.
     *
     * Named alias of facultyRequiredUnits() so the tier code reads in the same
     * vocabulary the scheduling staff use.
     */
    public static function facultyBasicLoad(mixed $faculty): int
    {
        return self::facultyRequiredUnits($faculty);
    }

    /**
     * Which band a total load of $units falls in for this instructor. The bands
     * stack in the order the allowances are granted: Basic Load first, then the
     * overload allowance, then pro bono. A load past all three is beyond the
     * ceiling — still assignable, since the ceiling is deliberately soft, but
     * named so the confirmation can say as much.
     */
    public static function facultyLoadTier(mixed $faculty, int $units): string
    {
        $basic = self::facultyBasicLoad($faculty);

        if ($units <= $basic) {
            return self::LOAD_TIER_BASIC;
        }

        if ($units <= $basic + (int) ($faculty->overload_units ?? 0)) {
            return self::LOAD_TIER_OVERLOAD;
        }

        return $units <= self::facultyUnitCeiling($faculty)
            ? self::LOAD_TIER_PROBONO
            : self::LOAD_TIER_BEYOND_CEILING;
    }

    public static function loadTierLabel(string $tier): string
    {
        return self::LOAD_TIER_LABELS[$tier] ?? $tier;
    }

    public static function openingTime(): string
    {
        return self::$cachedOpeningTime ??= self::normalizeTime(
            app(TimeslotService::class)->settings()->opening_time
        );
    }

    public static function closingTime(): string
    {
        return self::$cachedClosingTime ??= self::normalizeTime(
            app(TimeslotService::class)->settings()->closing_time
        );
    }

    public static function clearTimeCache(): void
    {
        self::$cachedOpeningTime = null;
        self::$cachedClosingTime = null;
        self::$cachedStartSlotsByDuration = [];
    }

    public static function totalSlots(): int
    {
        $minutes = self::timeToMinutes(self::closingTime()) - self::timeToMinutes(self::openingTime());

        return max(0, intdiv($minutes, self::SLOT_MINUTES));
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
        $totalSlots = self::totalSlots();

        if ($slot < 0 || $slot > $totalSlots) {
            throw new InvalidArgumentException(sprintf(
                'Slot %d is outside the valid 0-%d range.',
                $slot,
                $totalSlots,
            ));
        }

        $minutes = self::timeToMinutes(self::openingTime())
            + ($slot * self::SLOT_MINUTES);

        return sprintf(
            '%02d:%02d:00',
            intdiv($minutes, 60),
            $minutes % 60,
        );
    }

    /** @return list<int> */
    public static function generatedStartSlotsForDuration(int $durationSlots): array
    {
        if (isset(self::$cachedStartSlotsByDuration[$durationSlots])) {
            return self::$cachedStartSlotsByDuration[$durationSlots];
        }

        $durationMinutes = $durationSlots * self::SLOT_MINUTES;
        $openingMinutes = self::timeToMinutes(self::openingTime());

        return self::$cachedStartSlotsByDuration[$durationSlots] = array_values(array_filter(
            array_map(
                static fn (string $time): ?int => self::timeToSlot($time, $openingMinutes),
                app(TimeslotService::class)->generateStartTimes($durationMinutes),
            ),
            static fn (?int $slot): bool => $slot !== null,
        ));
    }

    public static function timeToMinutes(string $time): int
    {
        $parts = explode(':', self::normalizeTime($time));

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    private static function timeToSlot(string $time, int $openingMinutes): ?int
    {
        $minutes = self::timeToMinutes(Carbon::parse($time)->format('H:i:s'));
        $offset = $minutes - $openingMinutes;

        if ($offset < 0 || $offset % self::SLOT_MINUTES !== 0) {
            return null;
        }

        return (int) ($offset / self::SLOT_MINUTES);
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

    /**
     * Returns true when the course is an NSTP-type course (ROTC, CWTS, or LTS).
     */
    public static function isNstpCourse(Course $course): bool
    {
        $code     = strtoupper((string) ($course->course_code ?? $course->subject_code ?? ''));
        $name     = strtoupper((string) ($course->course_name ?? $course->subject_name ?? ''));
        $category = strtolower((string) ($course->course_category ?? $course->subject_category ?? ''));

        if (in_array($category, ['nstp', 'rotc', 'cwts', 'lts'], true)) {
            return true;
        }

        foreach (['NSTP', 'ROTC', 'CWTS', 'LTS'] as $keyword) {
            if (str_contains($code, $keyword) || str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the course requires a field room (PATHFIT, NSTP, etc.).
     */
    public static function isFieldCourse(Course $course): bool
    {
        if (self::courseHasCategory($course, 'Field')) {
            return true;
        }

        if ($course->room_type_required === 'field') {
            return true;
        }

        if (self::isNstpCourse($course)) {
            return true;
        }

        // Configured field-course codes are per department. A course with no
        // owning department is a shared minor, whose field-ness is global.
        $departmentId = $course->department_id === null ? null : (int) $course->department_id;
        $code = self::normalizeCourseCode((string) ($course->course_code ?? $course->subject_code ?? ''));

        return isset(self::fieldCourseCodeMap($departmentId)[$code]);
    }

    public static function isCasServiceCourse(Course $course): bool
    {
        if (self::courseHasCategory($course, 'GEC')) {
            return true;
        }

        $code = strtoupper(trim((string) $course->course_code));
        $normalized = preg_replace('/[^A-Z0-9]/', '', $code) ?? $code;

        return str_starts_with($normalized, 'GEC');
    }

    public static function isLaboratoryCourse(Course $course): bool
    {
        return self::courseHasCategory($course, 'Laboratory')
            || (int) ($course->lab_hours ?? 0) > 0
            || (string) ($course->room_type_required ?? '') === 'laboratory';
    }

    public static function effectiveRoomType(Course $course, ?string $meetingType = null): string
    {
        if ($meetingType !== null && in_array($meetingType, ['lecture', 'laboratory', 'field'], true)) {
            return $meetingType;
        }

        if (self::isFieldCourse($course)) {
            return 'field';
        }

        return self::isLaboratoryCourse($course)
            ? 'laboratory'
            : ((string) ($course->room_type_required ?: 'lecture'));
    }

    public static function allowsRoomTbaFallback(Course $course, ?string $meetingType = null): bool
    {
        return self::effectiveRoomType($course, $meetingType) === 'laboratory';
    }

    public static function allowsOnlineRoomFallback(Course $course, ?string $meetingType = null): bool
    {
        return self::effectiveRoomType($course, $meetingType) === 'lecture'
            && ! self::isFieldCourse($course)
            // A split course retains the parent course's laboratory metadata.
            // When the row explicitly identifies its lecture component, apply
            // the lecture delivery rule instead of rejecting it because another
            // component of the same course requires a laboratory.
            && ($meetingType === 'lecture' || ! self::isLaboratoryCourse($course));
    }

    public static function courseHasCategory(Course $course, string $categoryName): bool
    {
        $normalized = self::normalizeCategoryName($categoryName);

        if ($course->relationLoaded('categories')) {
            return $course->categories->contains(
                static fn ($category): bool => self::normalizeCategoryName((string) $category->name) === $normalized,
            );
        }

        return isset(self::courseCategoryMap()[(int) $course->id][$normalized]);
    }

    /**
     * The department whose instructors may teach this course.
     *
     * An explicit override on the course wins: a secretary may delegate GEC 101 to
     * the College of Arts and Sciences even though Information Technology owns it.
     * With no override the derived rule stands — a GEC subject is taught by the
     * college that offers it, every other minor is open to any department, and a
     * major is covered by the own-department and program rules below instead.
     */
    public static function assignedTeachingDepartmentId(Course $course): ?int
    {
        if ($course->teaching_department_id !== null) {
            return (int) $course->teaching_department_id;
        }

        if (self::isCasServiceCourse($course) && $course->department_id !== null) {
            return (int) $course->department_id;
        }

        return null;
    }

    /**
     * Whether this course's teaching may be handed to another college at all.
     *
     * Only a service or minor course can be: a major belongs to the department —
     * and program — that offers it, so delegating one would contradict the
     * own-department and program rules the engine enforces below. The management
     * endpoint refuses a major on this basis rather than storing an override the
     * rule engine would then ignore.
     */
    public static function isDelegableCourse(Course $course): bool
    {
        return ! self::isMajorCourse($course);
    }

    /**
     * A major course belongs to the department that offers it, so it is taught by
     * that department's own instructors — never delegated the way a GEC service
     * course is handed to the college that offers it.
     */
    public static function isMajorCourse(Course $course): bool
    {
        return self::normalizeCategoryName(
            (string) ($course->course_category ?? $course->subject_category ?? 'major')
        ) === 'major';
    }

    /**
     * The department whose instructors may teach this major. Falls back to the
     * section's department for a course with no owning department of its own.
     */
    public static function majorTeachingDepartmentId(Course $course, ?int $sectionDepartmentId = null): ?int
    {
        if ($course->department_id !== null) {
            return (int) $course->department_id;
        }

        return $sectionDepartmentId;
    }

    /**
     * The program an instructor must belong to in order to teach this course, or
     * null when the course is not tied to one. Only majors carry the restriction:
     * a service or minor course is taught across programs by design.
     */
    public static function requiredTeachingProgramId(Course $course): ?int
    {
        if (! self::isMajorCourse($course)) {
            return $course->teaching_program_id === null ? null : (int) $course->teaching_program_id;
        }

        return $course->program_id === null ? null : (int) $course->program_id;
    }

    /**
     * Whether field-course assignment is in effect for a department.
     *
     * Derived from whether any codes are configured, rather than stored in a
     * separate marker row. The stored flag could only ever be set to true — no
     * caller cleared it — so a department that removed its last field course was
     * left permanently 'enabled' with an empty list (audit finding #35).
     */
    public static function fieldCourseSettingEnabled(?int $departmentId = null): bool
    {
        return self::fieldCourseCodeMap($departmentId) !== [];
    }

    /**
     * Configured field-course codes for a department, merged with the codes that
     * apply institution-wide (rows with no department, i.e. shared minors).
     *
     * @return array<string, true>
     */
    public static function fieldCourseCodeMap(?int $departmentId = null): array
    {
        $bucket = $departmentId === null ? 'shared' : (string) $departmentId;

        if (isset(self::$cachedFieldCourseCodeMap[$bucket])) {
            return self::$cachedFieldCourseCodeMap[$bucket];
        }

        if (!self::fieldCourseSettingsTableExists()) {
            return self::$cachedFieldCourseCodeMap[$bucket] = [];
        }

        return self::$cachedFieldCourseCodeMap[$bucket] = DB::table('field_course_settings')
            ->whereNotNull('course_code')
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id');
                if ($departmentId !== null) {
                    $query->orWhere('department_id', $departmentId);
                }
            })
            ->pluck('course_code')
            ->map(static fn ($code): string => self::normalizeCourseCode((string) $code))
            ->filter()
            ->mapWithKeys(static fn (string $code): array => [$code => true])
            ->all();
    }

    public static function normalizeCourseCode(string $courseCode): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $courseCode) ?? $courseCode));
    }

    public static function clearFieldCourseCache(): void
    {
        self::$cachedFieldCourseCodeMap = [];
    }

    public static function clearCourseCategoryCache(): void
    {
        self::$cachedCourseCategoryMap = null;
    }

    /**
     * @return array<int, array<string, true>>
     */
    private static function courseCategoryMap(): array
    {
        if (self::$cachedCourseCategoryMap !== null) {
            return self::$cachedCourseCategoryMap;
        }

        if (!self::courseCategoriesTableExists()) {
            return self::$cachedCourseCategoryMap = [];
        }

        $map = [];
        DB::table('course_category_mapping')
            ->join('course_categories', 'course_categories.id', '=', 'course_category_mapping.category_id')
            ->get(['course_category_mapping.course_id', 'course_categories.name'])
            ->each(static function ($row) use (&$map): void {
                $courseId = (int) $row->course_id;
                $map[$courseId] ??= [];
                $map[$courseId][self::normalizeCategoryName((string) $row->name)] = true;
            });

        return self::$cachedCourseCategoryMap = $map;
    }

    private static function normalizeCategoryName(string $categoryName): string
    {
        return strtolower(trim($categoryName));
    }

    private static function courseCategoriesTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('course_categories')
                && DB::getSchemaBuilder()->hasTable('course_category_mapping');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function fieldCourseSettingsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('field_course_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
