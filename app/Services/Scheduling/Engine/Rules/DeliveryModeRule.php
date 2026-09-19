<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * delivery_mode, hybrid_mode, hybrid_eligibility, hybrid_component_type,
 * hybrid_component_shape, major_sunday_mode_constraint.
 *
 * Whether the chosen delivery (on-site, online, field, hybrid) is allowed for
 * this meeting. Which room that delivery needs is RoomTypeRule's question.
 * There is no cap on how many online or field courses a section takes; the
 * solver balances online delivery through its department room-fairness targets.
 */
final class DeliveryModeRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * delivery_mode, hybrid_mode: the request names a real mode, and field
     * delivery is never hybrid.
     *
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function modeInput(array $attempt): array
    {
        $violations = [];

        if (isset($attempt['mode']) && ! SchedulingPolicy::isValidDeliveryMode((string) $attempt['mode'])) {
            $violations[] = [
                'rule' => 'delivery_mode',
                'message' => "Unsupported delivery mode '{$attempt['mode']}'.",
            ];
        }

        if (($attempt['mode'] ?? null) === 'field' && ! empty($attempt['is_hybrid'])) {
            $violations[] = [
                'rule' => 'hybrid_mode',
                'message' => 'Field schedules cannot be marked as hybrid.',
            ];
        }

        return $violations;
    }

    /**
     * hybrid_eligibility, hybrid_component_type, hybrid_component_shape: a
     * hybrid meeting is an online lecture or an on-site laboratory of the
     * Generator's length, for a major with both, when the department allows it.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function hybridShape(array $attempt): ?array
    {
        if (! (bool) ($attempt['is_hybrid'] ?? false)) {
            return null;
        }

        $courseId = RuleSupport::courseId($attempt);
        $sectionId = (int) ($attempt['section_id'] ?? 0);
        $course = $courseId > 0 ? $this->lookups->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
        $section = $sectionId > 0
            ? $this->lookups->remember('section:'.$sectionId, fn () => Sections::with('department')->find($sectionId))
            : null;
        if ($course === null || $section === null) {
            return null;
        }

        $hasLaboratoryComponent = (int) ($course->lab_hours ?? 0) > 0;
        $isHybridSplit = ! $hasLaboratoryComponent && SchedulingPolicy::hybridSplitEligible($course);
        if (! $isHybridSplit && (! SchedulingPolicy::isMajorCourse($course)
            || (int) ($course->lecture_hours ?? 0) <= 0
            || ! $hasLaboratoryComponent)) {
            return [
                'rule' => 'hybrid_eligibility',
                'message' => 'Hybrid scheduling is available only for eligible course configurations.',
            ];
        }

        $meetingType = $attempt['meeting_type'] ?? null;
        $expected = $hasLaboratoryComponent
            ? match ($meetingType) {
                'lecture' => ['mode' => 'online', 'minutes' => SchedulingPolicy::lectureComponentSlots($course) * SchedulingPolicy::SLOT_MINUTES],
                'laboratory' => ['mode' => 'on-site', 'minutes' => SchedulingPolicy::laboratoryComponentMinutes($course, $section->department)],
                default => null,
            }
            : ($meetingType === 'lecture'
                ? ['mode' => in_array(($attempt['mode'] ?? 'on-site'), ['online', 'on-site'], true) ? $attempt['mode'] : 'on-site', 'minutes' => SchedulingPolicy::HYBRID_SPLIT_MEETING_MINUTES]
                : null);
        if ($expected === null) {
            return [
                'rule' => 'hybrid_component_type',
                'message' => 'Hybrid schedules must identify each meeting as lecture or laboratory.',
            ];
        }

        $durationMinutes = RuleSupport::durationMinutes((string) ($attempt['start_time'] ?? ''), (string) ($attempt['end_time'] ?? ''));
        // Integrated Hybrid's lecture and laboratory lengths are the user's
        // to set in Setup Courses, so only their delivery is fixed here; the
        // week's total stays capped by `class_duration`. Hybrid Split is a
        // fixed shape and keeps its exact length.
        $wrongLength = $hasLaboratoryComponent
            ? $durationMinutes <= 0
            : $durationMinutes !== $expected['minutes'];
        if (($attempt['mode'] ?? 'on-site') !== $expected['mode'] || $wrongLength) {
            return [
                'rule' => 'hybrid_component_shape',
                'message' => match (true) {
                    ! $hasLaboratoryComponent => 'Each Hybrid Split meeting must last the fixed Hybrid Split length.',
                    $meetingType === 'lecture' => 'The Integrated Hybrid lecture must be online.',
                    default => 'The Integrated Hybrid laboratory must be on-site.',
                },
            ];
        }

        return null;
    }

    /**
     * major_sunday_mode_constraint: a major meeting on Sunday must be online
     * while the department's Sunday-online setting is on. NSTP, field and minor
     * courses have their own day limits in MeetingDayRule.
     *
     * @return array<string, mixed>|null
     */
    public function sundayMajor(string $day, AttemptRecords $records): ?array
    {
        $course = $records->course;
        $departmentId = $records->departmentId();

        if ($day !== 'Sunday'
            || $records->mode === 'online'
            || SchedulingPolicy::isNstpCourse($course)
            || SchedulingPolicy::isFieldCourse($course, $departmentId)
            || strtolower((string) ($course->course_category ?? 'major')) === 'minor') {
            return null;
        }

        $sundayOnlineOnlyEnabled = (bool) $this->lookups->remember(
            'sundayOnlineOnly:'.$departmentId,
            fn () => Departments::query()->whereKey($departmentId)->value('sunday_online_only_enabled') ?? true,
        );

        return $sundayOnlineOnlyEnabled ? [
            'rule' => 'major_sunday_mode_constraint',
            'message' => 'Major courses scheduled on Sunday must use online delivery mode.',
        ] : null;
    }
}
