<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Support;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Services\TimeslotService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class SchedulingPolicy
{
    public const SLOT_MINUTES = 30;

    public const DEFAULT_FIELD_DAY_END_TIME = '17:00:00';

    private static ?string $cachedOpeningTime = null;

    private static ?string $cachedClosingTime = null;

    private static ?string $cachedFieldDayEndTime = null;

    public const LAB_ROOM_TYPES = ['laboratory', 'lecture', 'either'];

    /** @var array<int, string> */
    private static array $cachedLabRoomTypeByDepartment = [];

    /** @var array<int, list<int>> */
    private static array $cachedStartSlotsByDuration = [];

    /** @var array<string, true>|null */
    /** @var array<string, array<string, true>> */
    private static array $cachedFieldCourseCodeMap = [];

    public const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    public const WEEKDAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
    ];

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
        'reassignment',
        'finalized',
        'rejected',
        'revision',
        'conditionally_approved',
    ];

    public const MANUAL_STATUS_TRANSITIONS = [
        'draft' => ['completed'],
        'revision' => ['completed'],
        'completed' => ['draft'],
        'rejected_by_dean' => ['revision'],
        'rejected' => ['revision'],
        'approved' => ['finalized'],
        'faculty_assignment' => ['finalized'],
        'reassignment' => ['finalized'],
        'finalized' => ['reassignment'],
    ];

    public static function allowsManualStatusChange(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::MANUAL_STATUS_TRANSITIONS[$from] ?? [], true);
    }

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

    public const INSTRUCTOR_ASSIGNABLE_STATUSES = ['approved', 'faculty_assignment', 'reassignment'];

    public const VPAA_VISIBLE_STATUSES = ['approved', 'faculty_assignment', 'reassignment', 'finalized'];

    public const INSTRUCTOR_ASSIGNED_STATUSES = ['approved', 'faculty_assignment', 'reassignment', 'finalized'];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    public const FIXED_MEETING_PATTERNS = [
        'MW' => ['Monday', 'Wednesday'],
        'TTh' => ['Tuesday', 'Thursday'],
        'FS' => ['Friday', 'Saturday'],
    ];

    public const AUTO_SPLIT_PATTERNS = ['MW', 'TTh'];

    public const SINGLE_MEETING_PREFERRED_DAYS = ['Friday', 'Saturday'];

    /**
     * @return list<string>|null
     */
    public static function normalizeAllowedDays(mixed $days): ?array
    {
        if (! is_array($days)) {
            return null;
        }

        $chosen = array_flip(array_map(
            static fn (mixed $day): string => ucfirst(strtolower(trim((string) $day))),
            $days,
        ));
        $normalized = array_values(array_filter(self::DAYS, static fn (string $day): bool => isset($chosen[$day])));

        return $normalized === [] || count($normalized) === count(self::DAYS) ? null : $normalized;
    }

    /**
     * @param  list<string>  $days
     * @param  list<string>|null  $allowedDays
     */
    public static function countAllowedDays(array $days, ?array $allowedDays): int
    {
        return $allowedDays === null ? count($days) : count(array_intersect($days, $allowedDays));
    }

    public const LECTURE_SLOTS_PER_UNIT = 2;

    public const LABORATORY_SLOTS_PER_UNIT = 6;

    public static function unitMinutes(mixed $units): int
    {
        return (int) round((float) ($units ?? 0) * 60);
    }

    public static function maxUnitsPerComponent(int $slotsPerUnit): int
    {
        return max(1, intdiv(self::totalSlots(), max(1, $slotsPerUnit)));
    }

    public const CUSTOM_PATTERN_REGEX = '/^days:([0-6])-([0-6])$/';

    public const CONSECUTIVE_PATTERN_REGEX = '/^consecutive:([2-7])$/';

    public const MIN_CONSECUTIVE_DAYS = 2;

    public const SOFT_LATE_START_AFTER_SLOT = 22;

    public const SOFT_LATE_SLOT_PENALTY = 2;

    public const SOFT_GAP_SLOT_PENALTY = 1200;

    public const SOFT_UNUSABLE_GAP_PENALTY = 5000;

    public const SOFT_ROOM_IDLE_GAP_SLOT_PENALTY = 90;

    public const SOFT_UNUSABLE_ROOM_GAP_PENALTY = 900;

    public const SOFT_FILLABLE_ROOM_GAP_BONUS_PENALTY = 450;

    public const SOFT_ROOM_CHANGE_PENALTY = 1;

    public const SOFT_LAB_FALLBACK_PENALTY = 15;

    public const SOFT_ONLINE_FALLBACK_PENALTY = 1000;

    public const SOFT_MIXED_MODE_COURSE_OVERLAP_PENALTY = 2000;

    public const SOFT_SPLIT_PAIR_BREAK_PENALTY = 2000;

    public const SOFT_WEEKDAY_PHYSICAL_MIGRATION_PENALTY = 6000;

    public const SOFT_WEEKDAY_ONLINE_MIGRATION_PENALTY = 12000;

    /**
     * @var list<int>
     */
    public const CLASSROOM_SCHEDULABLE_BLOCK_SLOTS = [3, 4, 6];

    public static function classroomBestRemainderAfterSchedulableBlocks(int $gapSlots): int
    {
        $reachable = array_fill(0, max(0, $gapSlots) + 1, false);
        $reachable[0] = true;

        for ($slots = 1; $slots <= $gapSlots; $slots++) {
            foreach (self::CLASSROOM_SCHEDULABLE_BLOCK_SLOTS as $blockSlots) {
                if ($slots >= $blockSlots && $reachable[$slots - $blockSlots]) {
                    $reachable[$slots] = true;
                    break;
                }
            }
        }

        for ($usedSlots = $gapSlots; $usedSlots >= 0; $usedSlots--) {
            if ($reachable[$usedSlots]) {
                return $gapSlots - $usedSlots;
            }
        }

        return $gapSlots;
    }

    public const CONSTRAINT_CATALOG = [
        'required_field' => [
            'severity' => 'hard',
            'category' => 'input',
            'description' => 'A schedule operation must include every field required to identify and place the meeting.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'configuration_reference' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'Every course-specific generation option must reference a course selected for the section.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'course_duration' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'Every selected course must have a positive duration representable on the scheduling grid.',
            'enforced_by' => ['schedule_generation_preflight', 'generation_configuration_validation', 'csp'],
        ],
        'department_profile_mismatch' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'A standard scheduling profile cannot generate laboratory course components.',
            'enforced_by' => ['schedule_generation_preflight', 'generation_configuration_validation'],
        ],
        'invalid_department_setting' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'Department scheduling settings must be compatible with the selected scheduling profile.',
            'enforced_by' => ['schedule_generation_preflight', 'generation_configuration_validation'],
        ],
        'no_physical_rooms' => [
            'severity' => 'hard',
            'category' => 'resource_capacity',
            'description' => 'A configuration that requires physical lecture placement needs at least one eligible available physical room.',
            'enforced_by' => ['generation_feasibility', 'generation_configuration_validation'],
        ],
        'forced_day_multi_meeting_conflict' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'A forced single day cannot satisfy a course configuration that requires meetings on different days.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'forced_day_capacity_exceeded' => [
            'severity' => 'hard',
            'category' => 'resource_capacity',
            'description' => 'Courses forced onto one day cannot require more section time than the operating-hours window provides.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'forced_day_room_pressure' => [
            'severity' => 'warning',
            'category' => 'resource_capacity',
            'description' => 'Courses forced onto one day can demand more room-time of a room type than that day supplies, so some meetings will fall back to online or Room TBA.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'same_day_concentration' => [
            'severity' => 'warning',
            'category' => 'configuration_anomaly',
            'description' => 'Multiple selected courses forced onto the same day create a concentrated but not necessarily impossible configuration.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'laboratory_room_unresolved' => [
            'severity' => 'warning',
            'category' => 'resource_availability',
            'description' => 'Laboratory generation may proceed with Room TBA when no eligible laboratory room is available.',
            'enforced_by' => ['generation_configuration_validation', 'csp'],
        ],
        'no_feasible_schedule' => [
            'severity' => 'hard',
            'category' => 'generation_result',
            'description' => 'No candidate satisfies all hard constraints for the validated generation configuration.',
            'enforced_by' => ['generate_schedule_plan'],
        ],
        'semester_exists' => [
            'severity' => 'hard',
            'category' => 'relational_integrity',
            'description' => 'The selected academic semester must exist.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'section_exists' => [
            'severity' => 'hard',
            'category' => 'relational_integrity',
            'description' => 'The selected section must exist.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'subject_exists' => [
            'severity' => 'hard',
            'category' => 'relational_integrity',
            'description' => 'The selected course or subject must exist.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'room_exists' => [
            'severity' => 'hard',
            'category' => 'relational_integrity',
            'description' => 'A selected room must exist unless the configured placement explicitly permits an unresolved room.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'faculty_exists' => [
            'severity' => 'hard',
            'category' => 'relational_integrity',
            'description' => 'A selected instructor must exist.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'valid_day' => [
            'severity' => 'hard',
            'category' => 'calendar',
            'description' => 'A persisted schedule day must be one of the supported institutional day names.',
            'enforced_by' => ['request_validation', 'rule_engine'],
        ],
        'csp_generation_day' => [
            'severity' => 'hard',
            'category' => 'calendar',
            'description' => 'CSP-generated schedules use the Monday-Saturday grid, plus Sunday when the department allows Sunday classes.',
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
        'semester_enabled' => [
            'severity' => 'hard',
            'category' => 'academic_semester',
            'description' => 'Schedules can only be created for enabled academic semesters.',
            'enforced_by' => ['rule_engine'],
        ],
        'section_semester_alignment' => [
            'severity' => 'hard',
            'category' => 'academic_semester',
            'description' => 'The selected section must belong to the selected schedule semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'section_semester_period_alignment' => [
            'severity' => 'hard',
            'category' => 'academic_semester',
            'description' => 'The section period must match its academic semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'section_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'A section cannot attend overlapping classes in the same semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'subject_section_time_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'Different online sections taking the same subject cannot overlap in the same semester and time slot.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'room_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'A room cannot host overlapping classes in the same semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'faculty_conflict' => [
            'severity' => 'hard',
            'category' => 'resource_conflict',
            'description' => 'An assigned faculty member cannot teach overlapping classes in the same semester.',
            'enforced_by' => ['rule_engine', 'batch_conflict_validator'],
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
            'description' => 'A room must be shared or owned by the scheduled section department, and used on a day it belongs to the section program when the department divides its rooms between programs.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'preferred_pattern' => [
            'severity' => 'hard',
            'category' => 'meeting_pattern',
            'description' => 'When a meeting pattern is declared, all generated or saved days must belong to that pattern.',
            'enforced_by' => ['request_validation', 'rule_engine', 'csp'],
        ],
        'sunday_classes' => [
            'severity' => 'hard',
            'category' => 'calendar',
            'description' => 'A class may meet on Sunday only when its department allows Sunday classes.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'forced_course_day' => [
            'severity' => 'hard',
            'category' => 'meeting_pattern',
            'description' => 'A course with a Required Day must be scheduled on that day.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'delivery_mode' => [
            'severity' => 'hard',
            'category' => 'delivery',
            'description' => 'Delivery mode must be on-site, online, or field.',
            'enforced_by' => ['request_validation', 'csp'],
        ],
        'hybrid_mode' => [
            'severity' => 'hard',
            'category' => 'delivery',
            'description' => 'Field schedules cannot be marked hybrid.',
            'enforced_by' => ['request_validation', 'csp'],
        ],
        'field_evening_window' => [
            'severity' => 'hard',
            'category' => 'time',
            'description' => 'Field courses must end by the institution\'s field end time (Settings, Operating hours).',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'subject_section_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'CSP subjects must be active and match the section year level and semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'subject_section_semester_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'The course curriculum semester must match the section semester.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'subject_section_year_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'The course curriculum year level must match the section year level.',
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
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'A major subject must be assigned to an instructor from the department that offers it, and cannot be delegated to another department.',
            'enforced_by' => ['rule_engine'],
        ],
        'major_faculty_program_alignment' => [
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'A major subject tied to a program must be assigned to an instructor belonging to that program.',
            'enforced_by' => ['rule_engine'],
        ],
        'service_subject_faculty_department_alignment' => [
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'A GEC service subject must be assigned to an instructor from the college that offers it. Any other minor may be taught by an instructor from any department.',
            'enforced_by' => ['rule_engine'],
        ],
        'service_subject_faculty_program_alignment' => [
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'A service subject tied to a teaching program must use an instructor assigned to that program.',
            'enforced_by' => ['rule_engine'],
        ],
        'part_time_faculty_availability' => [
            'severity' => 'hard',
            'category' => 'faculty',
            'description' => 'A part-time instructor with recorded availability windows can only be assigned inside them. One with no windows recorded is unrestricted.',
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
            'description' => 'A recommendation cannot be accepted if the section already has a schedule for that subject in the same semester.',
            'enforced_by' => ['recommendation_acceptance'],
        ],
        'major_department_alignment' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'Major subjects with a department must match the section department.',
            'enforced_by' => ['csp'],
        ],
        'hybrid_component_count' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A hybrid meeting group must contain exactly its required lecture and laboratory components.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'hybrid_components' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A hybrid group must contain one lecture component and one laboratory component.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'hybrid_eligibility' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Hybrid scheduling is limited to eligible lecture-and-laboratory courses under the department setting.',
            'enforced_by' => ['rule_engine', 'csp', 'schedule_generation_preflight'],
        ],
        'hybrid_component_type' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Every hybrid meeting must identify whether it is the lecture or laboratory component.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'hybrid_component_shape' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'An Integrated Hybrid lecture is online and its laboratory on-site, at the lengths chosen for the course; each Hybrid Split meeting lasts the fixed Hybrid Split length.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'minor_split_component_count' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A configured minor split session must contain exactly two linked meetings.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'minor_split_eligibility' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Balanced split sessions are available for minor courses and for majors with lecture or laboratory units selected in Step 2.',
            'enforced_by' => ['rule_engine', 'csp', 'schedule_generation_preflight'],
        ],
        'minor_split_pattern' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'The days in a minor split group must match its configured meeting pattern.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'minor_split_duration' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'The combined duration of a minor split group must not exceed the course contact-hour requirement.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'split_group_same_time' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Every meeting of a Split Session, Hybrid Split or Consecutive Days class uses the same start and end time.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'consecutive_day_count' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A Consecutive Days class keeps all N of its linked meetings.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'consecutive_days' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A Consecutive Days class meets on the days ticked in Setup Courses (back-to-back or not); moved as a whole, it never wraps into the next week.',
            'enforced_by' => ['csp', 'manual_move'],
        ],
        'consecutive_mode' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Every meeting of a Consecutive Days class uses one delivery mode.',
            'enforced_by' => ['rule_engine', 'csp'],
        ],
        'consecutive_days_shape' => [
            'severity' => 'hard',
            'category' => 'configuration',
            'description' => 'A course set to Consecutive Days is not also split, Integrated or pinned to a two-day pattern in the same run.',
            'enforced_by' => ['generation_configuration_validation'],
        ],
        'class_duration' => [
            'severity' => 'hard',
            'category' => 'time',
            'description' => "A section's meetings for one course may not add up to more weekly time than the course carries (the larger of the Generator's single-block and lecture/laboratory shapes). An Integrated class's lecture and laboratory take the lengths the user sets, so each is judged on its own against one teaching day.",
            'enforced_by' => ['rule_engine'],
        ],
        'split_group_day_separation' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'Linked split or hybrid components that require separate meetings cannot use the same day.',
            'enforced_by' => ['schedule_batch_api', 'split_schedule_service', 'csp'],
        ],
        'split_unresolvable' => [
            'severity' => 'hard',
            'category' => 'meeting_group',
            'description' => 'A requested split cannot be persisted when no valid companion meeting can be resolved.',
            'enforced_by' => ['schedule_batch_api', 'split_schedule_service'],
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
        'duplicate_section_course' => [
            'severity' => 'hard',
            'category' => 'curriculum',
            'description' => 'Recommendation acceptance cannot create a course already represented by a non-replaceable schedule for the section and semester.',
            'enforced_by' => ['recommendation_acceptance'],
        ],
        'concurrent_write' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'A schedule write must fail when the scheduling scope lock cannot be acquired safely.',
            'enforced_by' => ['schedule_batch_api', 'schedule_plan_commit'],
        ],
        'plan_not_commit_ready' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'Only a complete, confirmed, resource-resolved schedule plan may enter persistence.',
            'enforced_by' => ['schedule_plan_commit'],
        ],
        'schedule_plan_scope_mismatch' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'Every committed plan row must match the configured semester, department, section, and course scope.',
            'enforced_by' => ['schedule_plan_commit'],
        ],
        'stale_schedule_plan' => [
            'severity' => 'hard',
            'category' => 'transaction',
            'description' => 'A schedule plan must be regenerated when its scheduling snapshot fingerprint is stale.',
            'enforced_by' => ['schedule_plan_commit'],
        ],
        'instructor_assignment_stage' => [
            'severity' => 'hard',
            'category' => 'workflow',
            'description' => 'Instructor assignment is allowed only while the schedule is in an instructor-assignable workflow stage.',
            'enforced_by' => ['schedule_api', 'instructor_assignment'],
        ],
        'missing_schedule' => [
            'severity' => 'hard',
            'category' => 'workflow',
            'description' => 'A bulk workflow operation cannot proceed when one of its target schedules no longer exists.',
            'enforced_by' => ['schedule_api'],
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
    ];

    public static function catalog(): array
    {
        return self::CONSTRAINT_CATALOG;
    }

    public static function allowedDaysRule(string $prefix = ''): string
    {
        return ltrim($prefix.'|in:'.implode(',', self::PERSISTABLE_DAYS), '|');
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

    public static function facultyRequiredUnits(mixed $faculty): int
    {
        $max = (int) ($faculty->max_units ?? 0);
        $deload = (int) ($faculty->deload_units ?? 0);

        return max(0, $max - $deload);
    }

    public static function facultyUnitCeiling(mixed $faculty): int
    {
        return self::facultyRequiredUnits($faculty)
            + (int) ($faculty->overload_units ?? 0);
    }

    public static function facultyBasicLoad(mixed $faculty): int
    {
        return self::facultyRequiredUnits($faculty);
    }

    public static function facultyLoadTier(mixed $faculty, int $units): string
    {
        $basic = self::facultyBasicLoad($faculty);

        if ($units <= $basic) {
            return self::LOAD_TIER_BASIC;
        }

        if ($units <= $basic + (int) ($faculty->overload_units ?? 0)) {
            return self::LOAD_TIER_OVERLOAD;
        }

        return self::LOAD_TIER_PROBONO;
    }

    public static function loadTierLabel(string $tier): string
    {
        return self::LOAD_TIER_LABELS[$tier] ?? $tier;
    }

    public static function openingTime(): string
    {
        self::loadOperatingHours();

        return self::$cachedOpeningTime;
    }

    public static function closingTime(): string
    {
        self::loadOperatingHours();

        return self::$cachedClosingTime;
    }

    public static function fieldDayEndTime(): string
    {
        self::loadOperatingHours();

        return self::$cachedFieldDayEndTime;
    }

    public static function clearTimeCache(): void
    {
        self::$cachedOpeningTime = null;
        self::$cachedClosingTime = null;
        self::$cachedFieldDayEndTime = null;
        self::$cachedStartSlotsByDuration = [];
    }

    public static function labRoomType(?int $departmentId): string
    {
        if ($departmentId === null) {
            return 'laboratory';
        }

        if (! isset(self::$cachedLabRoomTypeByDepartment[$departmentId])) {
            try {
                $value = (string) (Departments::query()->whereKey($departmentId)->value('lab_room_type') ?? 'laboratory');
            } catch (QueryException) {
                return 'laboratory';
            }
            self::$cachedLabRoomTypeByDepartment[$departmentId] = in_array($value, self::LAB_ROOM_TYPES, true) ? $value : 'laboratory';
        }

        return self::$cachedLabRoomTypeByDepartment[$departmentId];
    }

    /**
     * @return list<string>
     */
    public static function labRoomTypes(?int $departmentId): array
    {
        return match (self::labRoomType($departmentId)) {
            'lecture' => ['lecture'],
            'either' => ['laboratory', 'lecture'],
            default => ['laboratory'],
        };
    }

    public static function isLabClassroomFallback(string $roomType, ?int $departmentId): bool
    {
        return $roomType === 'lecture' && self::labRoomType($departmentId) === 'either';
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

    public static function searchDayRank(string $day, string $fromDay): int
    {
        if ($day === $fromDay) {
            return 0;
        }

        $week = count(self::DAYS);
        $offset = (self::dayIndex($day) - self::dayIndex($fromDay) + $week) % $week;

        return in_array($day, self::WEEKDAYS, true) ? $offset : $week + $offset;
    }

    /**
     * @return list<string>
     */
    public static function teachingDays(bool $sundayClassesEnabled): array
    {
        return $sundayClassesEnabled
            ? self::DAYS
            : array_values(array_diff(self::DAYS, ['Sunday']));
    }

    public static function dayIndex(string $day): int
    {
        static $indexes = null;
        $indexes ??= array_flip(self::DAYS);

        if (isset($indexes[$day])) {
            return $indexes[$day];
        }

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

    public static function isValidRowPattern(mixed $pattern): bool
    {
        return self::consecutiveDayCount($pattern) !== null || self::isValidPreferredPattern($pattern);
    }

    public static function consecutivePattern(int $dayCount): string
    {
        return 'consecutive:'.$dayCount;
    }

    public static function consecutiveDayCount(mixed $pattern): ?int
    {
        if (! is_string($pattern) || preg_match(self::CONSECUTIVE_PATTERN_REGEX, $pattern, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public static function weeklyCeilingMeetings(mixed $pattern): int
    {
        return self::consecutiveDayCount($pattern) ?? 1;
    }

    public static function classDurationAllowanceMinutes(int $courseCeilingMinutes, int $longestMeetingMinutes, int $dayMinutes, mixed $pattern): int
    {
        return max($courseCeilingMinutes, min($longestMeetingMinutes, $dayMinutes))
            * self::weeklyCeilingMeetings($pattern);
    }

    public static function maxConsecutiveDays(bool $sundayClassesEnabled): int
    {
        return count(self::teachingDays($sundayClassesEnabled));
    }

    /**
     * @param  list<string>|null  $allowedDays
     * @return list<list<string>>
     */
    public static function consecutiveDayRuns(int $dayCount, bool $sundayClassesEnabled, ?array $allowedDays = null): array
    {
        $days = self::teachingDays($sundayClassesEnabled);
        if ($dayCount < self::MIN_CONSECUTIVE_DAYS || $dayCount > count($days)) {
            return [];
        }

        $runs = [];
        for ($start = 0; $start + $dayCount <= count($days); $start++) {
            $run = array_slice($days, $start, $dayCount);
            if ($allowedDays === null || array_diff($run, $allowedDays) === []) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * @param  list<string>  $days
     */
    public static function isConsecutiveDaySet(array $days): bool
    {
        if (count(array_unique($days)) !== count($days)) {
            return false;
        }

        $indexes = array_map(self::dayIndex(...), $days);
        sort($indexes);

        return $indexes === range($indexes[0] ?? 0, ($indexes[0] ?? 0) + count($indexes) - 1);
    }

    /**
     * @return list<string>|null
     */
    public static function parseMeetingDays(mixed $value): ?array
    {
        $days = is_array($value) ? $value : (is_string($value) && $value !== '' ? explode(',', $value) : []);
        $days = array_values(array_intersect(self::DAYS, array_map(static fn ($day): string => trim((string) $day), $days)));

        return count($days) >= self::MIN_CONSECUTIVE_DAYS ? $days : null;
    }

    /**
     * @param  array{day_count: int, preferred_start_day?: string|null, meeting_days?: list<string>|null}  $rule
     * @param  list<string>|null  $allowedDays
     * @return list<list<string>>
     */
    public static function consecutiveRuleRuns(array $rule, bool $sundayClassesEnabled, ?array $allowedDays = null): array
    {
        $meetingDays = $rule['meeting_days'] ?? null;
        if ($meetingDays !== null) {
            $open = array_diff($meetingDays, self::teachingDays($sundayClassesEnabled)) === []
                && ($allowedDays === null || array_diff($meetingDays, $allowedDays) === []);

            return $open ? [$meetingDays] : [];
        }

        $startDay = $rule['preferred_start_day'] ?? null;

        return array_values(array_filter(
            self::consecutiveDayRuns((int) $rule['day_count'], $sundayClassesEnabled, $allowedDays),
            static fn (array $run): bool => $startDay === null || $run[0] === $startDay,
        ));
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $rules  {course_id, section_id, day_count, preferred_start_day, meeting_days}
     * @return array<int, array{day_count: int, preferred_start_day: string|null, meeting_days: list<string>|null}>
     */
    public static function resolveConsecutiveDayRules(iterable $rules, ?int $sectionId): array
    {
        $courseWide = [];
        $ownSection = [];
        foreach ($rules as $rule) {
            $rule = (array) $rule;
            $courseId = (int) ($rule['course_id'] ?? 0);
            $ruleSectionId = isset($rule['section_id']) ? (int) $rule['section_id'] : null;
            if ($courseId <= 0 || ($ruleSectionId !== null && $ruleSectionId !== $sectionId)) {
                continue;
            }

            $resolved = [
                'day_count' => (int) ($rule['day_count'] ?? 0),
                'preferred_start_day' => isset($rule['preferred_start_day']) && $rule['preferred_start_day'] !== ''
                    ? (string) $rule['preferred_start_day']
                    : null,
                'meeting_days' => self::parseMeetingDays($rule['meeting_days'] ?? null),
            ];
            if ($ruleSectionId === null) {
                $courseWide[$courseId] = $resolved;
            } else {
                $ownSection[$courseId] = $resolved;
            }
        }

        return array_replace($courseWide, $ownSection);
    }

    /**
     * @param  list<int>  $courseIds  Optional filter; every course when empty.
     * @return array<int, array{day_count: int, preferred_start_day: string|null, meeting_days: list<string>|null}>
     */
    public static function consecutiveDayRuleMap(int $departmentId, ?int $sectionId, array $courseIds = []): array
    {
        $rules = DepartmentCourseRules::query($departmentId)
            ->whereNotNull('consecutive_day_count')
            ->when($courseIds !== [], fn ($query) => $query->whereIn('course_id', array_map('intval', $courseIds)))
            ->get(['course_id', 'section_id', 'consecutive_day_count as day_count', 'preferred_start_day', 'meeting_days']);

        return self::resolveConsecutiveDayRules($rules, $sectionId);
    }

    public static function isFixedMeetingPattern(?string $preferredPattern): bool
    {
        return $preferredPattern !== null && array_key_exists($preferredPattern, self::FIXED_MEETING_PATTERNS);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function autoSplitDayPairs(): array
    {
        return array_map(
            static fn (string $pattern): array => self::FIXED_MEETING_PATTERNS[$pattern],
            self::AUTO_SPLIT_PATTERNS,
        );
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

    /**
     * @param  Course|array<string, mixed>  $course
     */
    private static function courseAttribute(Course|array $course, string $key, ?string $legacyKey = null): mixed
    {
        $value = is_array($course) ? ($course[$key] ?? null) : $course->{$key};
        if ($value === null && $legacyKey !== null) {
            $value = is_array($course) ? ($course[$legacyKey] ?? null) : $course->{$legacyKey};
        }

        return $value;
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  list<string>|null  $fieldCourseCodes
     */
    public static function isFieldCourse(Course|array $course, ?int $departmentId = null, ?array $fieldCourseCodes = null): bool
    {
        if (self::courseAttribute($course, 'room_type_required') === 'field') {
            return true;
        }

        $code = self::normalizeCourseCode((string) (self::courseAttribute($course, 'course_code', 'subject_code') ?? ''));

        if ($fieldCourseCodes !== null) {
            return in_array($code, array_map(self::normalizeCourseCode(...), $fieldCourseCodes), true);
        }

        $courseDepartmentId = self::courseAttribute($course, 'department_id');
        $departmentId ??= $courseDepartmentId === null ? null : (int) $courseDepartmentId;

        return isset(self::fieldCourseCodeMap($departmentId)[$code]);
    }

    public static function isCasServiceCourse(Course $course): bool
    {
        $code = strtoupper(trim((string) $course->course_code));
        $normalized = preg_replace('/[^A-Z0-9]/', '', $code) ?? $code;

        return str_starts_with($normalized, 'GEC');
    }

    /** @param Course|array<string, mixed> $course */
    public static function isLaboratoryCourse(Course|array $course): bool
    {
        return (int) (self::courseAttribute($course, 'lab_hours') ?? 0) > 0
            || (string) (self::courseAttribute($course, 'room_type_required') ?? '') === 'laboratory';
    }

    /**
     * @param  array<string, mixed>|Departments|null  $settings
     */
    public static function laboratoryComponentSlots(Course $course, array|Departments|null $settings = null): int
    {
        return self::customLaboratoryDurationSlots($settings)
            ?? max(0, (int) ($course->lab_hours ?? 0)) * self::LABORATORY_SLOTS_PER_UNIT;
    }

    /** @param array<string, mixed>|Departments|null $settings */
    public static function laboratoryComponentMinutes(Course $course, array|Departments|null $settings = null): int
    {
        return self::laboratoryComponentSlots($course, $settings) * self::SLOT_MINUTES;
    }

    /**
     * @param  array<string, mixed>|Course  $course
     */
    public static function lectureComponentSlots(array|Course $course): int
    {
        $lectureUnits = (int) ($course instanceof Course ? ($course->lecture_hours ?? 0) : ($course['lecture_hours'] ?? 0));

        return max(0, $lectureUnits) * self::LECTURE_SLOTS_PER_UNIT;
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  array<string, mixed>|Departments|null  $settings
     */
    public static function courseWeeklyCeilingMinutes(Course|array $course, array|Departments|null $settings = null): int
    {
        $value = static fn (string $key): mixed => is_array($course) ? ($course[$key] ?? 0) : ($course->{$key} ?? 0);

        $singleBlock = self::unitMinutes($value('units'));
        $lectureMinutes = max(0, (int) $value('lecture_hours')) * self::LECTURE_SLOTS_PER_UNIT * self::SLOT_MINUTES;
        $laboratoryMinutes = (int) $value('lab_hours') > 0
            ? (is_array($course)
                ? self::laboratoryComponentSlotsForArray($course, $settings) * self::SLOT_MINUTES
                : self::laboratoryComponentMinutes($course, $settings))
            : 0;

        return max($singleBlock, $lectureMinutes + $laboratoryMinutes);
    }

    /**
     * @param  Course|array<string, mixed>  $course
     */
    public static function isIntegratedSession(Course|array $course, ?string $meetingType, ?string $splitGroupId): bool
    {
        return in_array($meetingType, ['lecture', 'laboratory'], true)
            && trim((string) $splitGroupId) !== ''
            && (int) (self::courseAttribute($course, 'lecture_hours') ?? 0) > 0
            && (int) (self::courseAttribute($course, 'lab_hours') ?? 0) > 0;
    }

    public static function integratedSessionCeilingMinutes(?string $openingTime = null, ?string $closingTime = null): int
    {
        return max(0, self::timeToMinutes($closingTime ?? self::closingTime())
            - self::timeToMinutes($openingTime ?? self::openingTime()));
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  array<string, mixed>|Departments|null  $settings
     */
    public static function laboratoryComponentSlotsForArray(array $course, array|Departments|null $settings = null): int
    {
        return self::customLaboratoryDurationSlots($settings)
            ?? max(0, (int) ($course['lab_hours'] ?? 0)) * self::LABORATORY_SLOTS_PER_UNIT;
    }

    /**
     * @param  array<string, mixed>|Departments|null  $settings
     */
    public static function customLaboratoryDurationSlots(array|Departments|null $settings): ?int
    {
        if ($settings === null) {
            return null;
        }

        $read = $settings instanceof Departments
            ? static fn (string $key): mixed => $settings->{$key}
            : static fn (string $key): mixed => $settings[$key] ?? null;

        if (! (bool) $read('custom_lab_duration_override_enabled')) {
            return null;
        }

        $minutes = match (true) {
            (bool) $read('custom_lab_duration_6_hours_enabled') => 360,
            (bool) $read('custom_lab_duration_5_hours_enabled') => 300,
            (bool) $read('custom_lab_duration_other_enabled') => (int) $read('custom_lab_duration_minutes'),
            default => 0,
        };

        if ($minutes <= 0 || $minutes % self::SLOT_MINUTES !== 0) {
            return null;
        }

        return intdiv($minutes, self::SLOT_MINUTES);
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  list<string>|null  $fieldCourseCodes  see isFieldCourse()
     */
    public static function effectiveRoomType(Course|array $course, ?int $departmentId, ?string $meetingType = null, ?array $fieldCourseCodes = null): string
    {
        if (self::isFieldCourse($course, $departmentId, $fieldCourseCodes)) {
            return 'field';
        }

        if ($meetingType !== null && in_array($meetingType, ['lecture', 'laboratory', 'field'], true)) {
            return $meetingType;
        }

        return self::isLaboratoryCourse($course)
            ? 'laboratory'
            : ((string) (self::courseAttribute($course, 'room_type_required') ?: 'lecture'));
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  list<string>|null  $fieldCourseCodes  see isFieldCourse()
     */
    public static function allowsRoomTbaFallback(Course|array $course, ?int $departmentId, ?string $meetingType = null, ?array $fieldCourseCodes = null): bool
    {
        return self::effectiveRoomType($course, $departmentId, $meetingType, $fieldCourseCodes) === 'laboratory';
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  list<string>|null  $fieldCourseCodes  see isFieldCourse()
     */
    public static function allowsOnlineRoomFallback(Course|array $course, ?int $departmentId, ?string $meetingType = null, ?array $fieldCourseCodes = null): bool
    {
        return self::effectiveRoomType($course, $departmentId, $meetingType, $fieldCourseCodes) === 'lecture'
            && ! self::isFieldCourse($course, $departmentId, $fieldCourseCodes)
            && ($meetingType === 'lecture' || ! self::isLaboratoryCourse($course));
    }

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

    public static function isDelegableCourse(Course $course): bool
    {
        return ! self::isMajorCourse($course);
    }

    /** @param Course|array<string, mixed> $course */
    public static function isMajorCourse(Course|array $course): bool
    {
        return strtolower(trim(
            (string) (self::courseAttribute($course, 'course_category', 'subject_category') ?? '')
        )) === 'major';
    }

    /**
     * @param  array<string, mixed>|Course  $course
     * @param  array<string, mixed>  $departmentSettings
     */
    public static function balancedSplitEligible(array|Course $course, array $departmentSettings): bool
    {
        $isMajor = $course instanceof Course
            ? self::isMajorCourse($course)
            : strtolower(trim((string) ($course['course_category'] ?? $course['subject_category'] ?? ''))) === 'major';

        if (! $isMajor) {
            return true;
        }

        $lectureHours = (int) ($course instanceof Course ? ($course->lecture_hours ?? 0) : ($course['lecture_hours'] ?? 0));
        $labHours = (int) ($course instanceof Course ? ($course->lab_hours ?? 0) : ($course['lab_hours'] ?? 0));

        return $lectureHours > 0 || $labHours > 0;
    }

    public static function balancedSplitSettings(?Departments $department): array
    {
        return [
            'gec_split_schedule_override_enabled' => true,
            'major_lecture_split_schedule_override_enabled' => true,
        ];
    }

    public const HYBRID_SPLIT_MEETING_MINUTES = 90;

    /**
     * @param  array<int|string, mixed>  $deliveryModesByCourseId
     */
    public static function isIntegratedOnSite(array $deliveryModesByCourseId, int $courseId): bool
    {
        return ($deliveryModesByCourseId[$courseId] ?? $deliveryModesByCourseId[(string) $courseId] ?? null) === 'on-site';
    }

    public static function hybridSplitEligible(array|Course $course): bool
    {
        $units = (float) ($course instanceof Course ? ($course->units ?? 0) : ($course['units'] ?? 0));
        $lectureHours = (int) ($course instanceof Course ? ($course->lecture_hours ?? 0) : ($course['lecture_hours'] ?? 0));
        $laboratoryHours = (int) ($course instanceof Course ? ($course->lab_hours ?? 0) : ($course['lab_hours'] ?? 0));

        return self::unitMinutes($units) === 2 * self::HYBRID_SPLIT_MEETING_MINUTES
            && $lectureHours > 0
            && $laboratoryHours === 0;
    }

    public static function hybridSplitMeetingSlots(): int
    {
        return intdiv(self::HYBRID_SPLIT_MEETING_MINUTES, self::SLOT_MINUTES);
    }

    /**
     * @param  Course|array<string, mixed>  $course
     * @param  Rooms|array<string, mixed>  $room
     */
    public static function laboratoryServesLecture(Course|array $course, Rooms|array $room): bool
    {
        $roomValue = static fn (string $key): mixed => is_array($room) ? ($room[$key] ?? null) : $room->{$key};

        return self::isLectureOnlyMajor($course)
            && (string) $roomValue('room_type') === 'laboratory'
            && (bool) $roomValue('allow_lecture_usage');
    }

    /**
     * @param  Course|array<string, mixed>  $course
     */
    public static function isLectureOnlyMajor(Course|array $course): bool
    {
        return self::isMajorCourse($course)
            && (int) (self::courseAttribute($course, 'lecture_hours') ?? 0) > 0
            && (int) (self::courseAttribute($course, 'lab_hours') ?? 0) === 0
            && (string) (self::courseAttribute($course, 'room_type_required') ?? 'lecture') === 'lecture';
    }

    public static function majorTeachingDepartmentId(Course $course, ?int $sectionDepartmentId = null): ?int
    {
        if ($course->teaching_department_id !== null) {
            return (int) $course->teaching_department_id;
        }

        if ($course->department_id !== null) {
            return (int) $course->department_id;
        }

        return $sectionDepartmentId;
    }

    public static function requiredTeachingProgramId(Course $course): ?int
    {
        if ($course->teaching_program_id !== null) {
            return (int) $course->teaching_program_id;
        }

        if ($course->teaching_department_id !== null) {
            return null;
        }

        if (! self::isMajorCourse($course)) {
            return null;
        }

        return $course->program_id === null ? null : (int) $course->program_id;
    }

    public static function majorDelegationRefusal(Course $course, ?Program $program, ?int $teachingDepartmentId = null): ?string
    {
        if (! self::isMajorCourse($course) || $program !== null) {
            return null;
        }

        if ($course->department_id !== null && $teachingDepartmentId === (int) $course->department_id) {
            return 'Within its own college, a major course can only be assigned to a specific program.';
        }

        return null;
    }

    public static function fieldCourseSettingEnabled(?int $departmentId = null): bool
    {
        return self::fieldCourseCodeMap($departmentId) !== [];
    }

    /**
     * @return array<string, true>
     */
    public static function fieldCourseCodeMap(?int $departmentId = null): array
    {
        $bucket = $departmentId === null ? 'shared' : (string) $departmentId;

        if (isset(self::$cachedFieldCourseCodeMap[$bucket])) {
            return self::$cachedFieldCourseCodeMap[$bucket];
        }

        if (! self::courseRulesTableExists()) {
            return self::$cachedFieldCourseCodeMap[$bucket] = [];
        }

        return self::$cachedFieldCourseCodeMap[$bucket] = $departmentId === null
            ? []
            : array_fill_keys(DepartmentCourseRules::fieldCourseCodes($departmentId), true);
    }

    /**
     * @param  list<int>  $courseIds  Optional filter; all pinned courses when empty.
     * @return array<int, string>
     */
    public static function forcedCourseDayMap(int $departmentId, array $courseIds = []): array
    {
        $query = DepartmentCourseRules::query($departmentId)
            ->whereNotNull('forced_day');

        if ($courseIds !== []) {
            $query->whereIn('course_id', array_map('intval', $courseIds));
        }

        return $query->pluck('forced_day', 'course_id')
            ->mapWithKeys(static fn ($day, $courseId): array => [(int) $courseId => (string) $day])
            ->all();
    }

    public static function normalizeCourseCode(string $courseCode): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $courseCode) ?? $courseCode));
    }

    public static function clearFieldCourseCache(): void
    {
        self::$cachedFieldCourseCodeMap = [];
        self::$cachedLabRoomTypeByDepartment = [];
    }

    private static function courseRulesTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable(DepartmentCourseRules::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function loadOperatingHours(): void
    {
        if (self::$cachedOpeningTime !== null && self::$cachedClosingTime !== null && self::$cachedFieldDayEndTime !== null) {
            return;
        }

        $settings = app(TimeslotService::class)->settings();
        self::$cachedOpeningTime = self::normalizeTime($settings->opening_time);
        self::$cachedClosingTime = self::normalizeTime($settings->closing_time);

        $fieldEnd = self::normalizeTime((string) ($settings->field_end_time ?? self::DEFAULT_FIELD_DAY_END_TIME));
        self::$cachedFieldDayEndTime = min(max($fieldEnd, self::$cachedOpeningTime), self::$cachedClosingTime);
    }
}
