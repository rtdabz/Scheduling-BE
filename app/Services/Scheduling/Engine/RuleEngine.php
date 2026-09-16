<?php

namespace App\Services\Scheduling\Engine;

use App\Models\Sections;
use App\Services\Scheduling\Department\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\Engine\Rules\AttemptRecords;
use App\Services\Scheduling\Engine\Rules\ClassDurationRule;
use App\Services\Scheduling\Engine\Rules\CurriculumPlacementRule;
use App\Services\Scheduling\Engine\Rules\DeliveryModeRule;
use App\Services\Scheduling\Engine\Rules\DepartmentAssignmentRule;
use App\Services\Scheduling\Engine\Rules\InstructorAvailabilityRule;
use App\Services\Scheduling\Engine\Rules\InstructorConflictRule;
use App\Services\Scheduling\Engine\Rules\MeetingDayRule;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Engine\Rules\OperatingHoursRule;
use App\Services\Scheduling\Engine\Rules\ReferenceIntegrityRule;
use App\Services\Scheduling\Engine\Rules\RoomAvailabilityRule;
use App\Services\Scheduling\Engine\Rules\RoomTypeRule;
use App\Services\Scheduling\Engine\Rules\RuleLookupCache;
use App\Services\Scheduling\Engine\Rules\SectionConflictRule;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;

/**
 * Validates one schedule attempt (a single meeting about to be saved) against
 * every hard scheduling rule, and linked meeting groups against the rules that
 * need the whole group.
 *
 * The rules themselves live in Engine/Rules, one class per concern; this class
 * decides the order they run in and how their findings combine. A violation is
 * `['rule' => id, 'message' => text, ...context]`; rule ids are registered in
 * SchedulingPolicy::CONSTRAINT_CATALOG and are relied on by the UI.
 */
class RuleEngine
{
    private readonly RuleLookupCache $lookups;

    private readonly ReferenceIntegrityRule $references;

    private readonly CurriculumPlacementRule $curriculum;

    private readonly DepartmentAssignmentRule $departments;

    private readonly InstructorAvailabilityRule $instructorAvailability;

    private readonly InstructorConflictRule $instructorConflicts;

    private readonly SectionConflictRule $sectionConflicts;

    private readonly RoomAvailabilityRule $roomAvailability;

    private readonly RoomTypeRule $roomTypes;

    private readonly OperatingHoursRule $operatingHours;

    private readonly MeetingDayRule $meetingDays;

    private readonly DeliveryModeRule $deliveryModes;

    private readonly ClassDurationRule $classDuration;

    private readonly MeetingGroupRule $meetingGroups;

    public function __construct(?DepartmentResourceSlotLimitService $resourceLimits = null)
    {
        // One lookup cache per engine: a batch save validates many meetings that
        // share a semester, section and room, and should fetch each only once.
        // The engine is resolved per request, so the cache cannot go stale.
        $this->lookups = $lookups = new RuleLookupCache;

        $this->references = new ReferenceIntegrityRule($lookups);
        $this->curriculum = new CurriculumPlacementRule($lookups);
        $this->departments = new DepartmentAssignmentRule($lookups);
        $this->instructorAvailability = new InstructorAvailabilityRule;
        $this->instructorConflicts = new InstructorConflictRule;
        $this->sectionConflicts = new SectionConflictRule;
        $this->roomAvailability = new RoomAvailabilityRule($lookups, $resourceLimits ?? new DepartmentResourceSlotLimitService);
        $this->roomTypes = new RoomTypeRule($lookups);
        $this->operatingHours = new OperatingHoursRule($lookups);
        $this->meetingDays = new MeetingDayRule($lookups);
        $this->deliveryModes = new DeliveryModeRule($lookups);
        $this->classDuration = new ClassDurationRule($lookups);
        $this->meetingGroups = new MeetingGroupRule($lookups);
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function validate(array $attempt): array
    {
        $violations = $this->requiredFields($attempt);
        if ($violations !== []) {
            return $violations;
        }

        $day = (string) $attempt['day'];
        $startTime = (string) $attempt['start_time'];
        $endTime = (string) $attempt['end_time'];

        $violations = array_values(array_filter([
            $this->meetingDays->validDay($day),
            ...$this->deliveryModes->modeInput($attempt),
            $this->operatingHours->slotGrid($startTime, $endTime),
        ]));

        ['violations' => $recordViolations, 'records' => $records] = $this->recordRules($attempt);
        $violations = [...$violations, ...$recordViolations];

        // A missing course or room is already reported by subject_exists or
        // room_exists; the room-type rule would only repeat the same error.
        $hasMissingRecord = collect($recordViolations)->contains(
            static fn (array $violation): bool => in_array($violation['rule'], ['subject_exists', 'room_exists'], true),
        );

        $violations = [
            ...$violations,
            ...array_filter([
                $this->deliveryModes->hybridShape($attempt),
                $this->roomAvailability->booking($attempt),
                $this->instructorConflicts->check($attempt),
            ]),
            ...$this->sectionConflicts->check($attempt),
            ...array_filter([
                $hasMissingRecord ? null : $this->roomTypeFor($attempt),
                $this->meetingDays->preferredPattern($day, $attempt['preferred_pattern'] ?? null),
                $this->operatingHours->withinOperatingHours($startTime, $endTime),
                $this->roomAvailability->onlineCapacity($attempt),
                $this->deliveryModes->sectionOnlineLimit($attempt),
                $records === null ? null : $this->classDuration->check($attempt, $records),
            ]),
        ];

        // An instructor conflict someone already chose to override does not come
        // back on the next save of the same meeting.
        return FacultyConflictOverride::withoutStanding($attempt, array_values($violations));
    }

    /**
     * The rules that need the attempt's records to exist first.
     *
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function checkRelationalIntegrity(array $attempt): array
    {
        return $this->recordRules($attempt)['violations'];
    }

    /**
     * room_type_match on its own, for callers choosing between rooms.
     *
     * @return array<string, mixed>|null
     */
    public function checkRoomTypeMatch(
        int $courseId,
        ?int $roomId,
        string $deliveryMode = 'on-site',
        ?string $meetingType = null,
        ?int $departmentId = null,
    ): ?array {
        return $this->roomTypes->check($courseId, $roomId, $deliveryMode, $meetingType, $departmentId);
    }

    /**
     * Validate linked meeting shapes that cannot be judged one row at a time.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function validateConfiguredMeetingGroups(array $operations): array
    {
        return $this->meetingGroups->check($operations);
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    private function requiredFields(array $attempt): array
    {
        $violations = [];

        foreach (['semester_id', 'section_id', 'day', 'start_time', 'end_time'] as $field) {
            if (! array_key_exists($field, $attempt) || $attempt[$field] === null || $attempt[$field] === '') {
                $violations[] = [
                    'rule' => 'required_field',
                    'message' => "Schedule attempt is missing required field '{$field}'.",
                ];
            }
        }

        if (! isset($attempt['course_id']) && ! isset($attempt['subject_id'])) {
            $violations[] = [
                'rule' => 'required_field',
                'message' => "Schedule attempt is missing required field 'course_id'.",
            ];
        }

        return $violations;
    }

    /**
     * Resolves the attempt's records, then runs every rule that reads them.
     * When a record is missing only that is reported, and no records are returned.
     *
     * @param  array<string, mixed>  $attempt
     * @return array{violations: list<array<string, mixed>>, records: AttemptRecords|null}
     */
    private function recordRules(array $attempt): array
    {
        ['violations' => $violations, 'records' => $records] = $this->references->check($attempt);
        if ($records === null) {
            return ['violations' => $violations, 'records' => null];
        }

        $day = (string) ($attempt['day'] ?? '');

        return [
            'violations' => [
                ...$violations,
                ...$this->curriculum->check($records),
                ...$this->departments->check($attempt, $records),
                ...$this->instructorAvailability->check($attempt, $records),
                ...array_values(array_filter([
                    $this->roomAvailability->status($records),
                    $this->meetingDays->courseCategoryDay($day, $records),
                    $this->deliveryModes->sundayMajor($day, $records),
                    $this->operatingHours->fieldEveningWindow($attempt, $records),
                    $this->meetingDays->forcedDay($day, $records),
                ])),
            ],
            'records' => $records,
        ];
    }

    /** @param array<string, mixed> $attempt */
    private function roomTypeFor(array $attempt): ?array
    {
        $section = $this->lookups->remember('section:'.$attempt['section_id'], fn () => Sections::find($attempt['section_id']));

        return $this->roomTypes->check(
            (int) ($attempt['course_id'] ?? $attempt['subject_id']),
            isset($attempt['room_id']) ? (int) $attempt['room_id'] : null,
            (string) ($attempt['mode'] ?? 'on-site'),
            $attempt['meeting_type'] ?? null,
            $section?->department_id === null ? null : (int) $section->department_id,
        );
    }
}
