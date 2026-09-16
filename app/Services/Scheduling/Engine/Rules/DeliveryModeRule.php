<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * delivery_mode, hybrid_mode, hybrid_eligibility, hybrid_component_type,
 * hybrid_component_shape, major_sunday_mode_constraint, section_online_limit.
 *
 * Whether the chosen delivery (on-site, online, field, hybrid) is allowed for
 * this meeting. Which room that delivery needs is RoomTypeRule's question.
 */
final class DeliveryModeRule
{
    /** The most distinct online courses one section may take. */
    private const SECTION_ONLINE_COURSE_LIMIT = 5;

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

        if (! $section->department?->lecture_lab_schedule_override_enabled
            || ! SchedulingPolicy::isMajorCourse($course)
            || (int) ($course->lecture_hours ?? 0) <= 0
            || (int) ($course->lab_hours ?? 0) <= 0) {
            return [
                'rule' => 'hybrid_eligibility',
                'message' => 'Hybrid scheduling is available only for major courses with both lecture and laboratory hours when the department setting is enabled.',
            ];
        }

        $meetingType = $attempt['meeting_type'] ?? null;
        $expected = match ($meetingType) {
            'lecture' => ['mode' => 'online', 'minutes' => (int) $course->lecture_hours * 60],
            'laboratory' => ['mode' => 'on-site', 'minutes' => SchedulingPolicy::laboratoryComponentMinutes($course, $section->department)],
            default => null,
        };
        if ($expected === null) {
            return [
                'rule' => 'hybrid_component_type',
                'message' => 'Hybrid schedules must identify each meeting as lecture or laboratory.',
            ];
        }

        $durationMinutes = RuleSupport::durationMinutes((string) ($attempt['start_time'] ?? ''), (string) ($attempt['end_time'] ?? ''));
        if (($attempt['mode'] ?? 'on-site') !== $expected['mode'] || $durationMinutes !== $expected['minutes']) {
            return [
                'rule' => 'hybrid_component_shape',
                'message' => $meetingType === 'lecture'
                    ? 'The Hybrid lecture must be online and use the Generator lecture duration.'
                    : 'The Hybrid laboratory must be on-site and use the Generator laboratory duration.',
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

    /**
     * section_online_limit: a section may take at most five distinct online
     * courses. Moving a meeting that is already online adds nothing.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function sectionOnlineLimit(array $attempt): ?array
    {
        if ((string) ($attempt['mode'] ?? 'on-site') !== 'online') {
            return null;
        }

        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        if ($ignoreIds !== [] && Schedule::whereIn('id', $ignoreIds)->where('mode', 'online')->exists()) {
            return null;
        }

        $onlineCourses = Schedule::where('section_id', (int) $attempt['section_id'])
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('mode', 'online')
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->distinct('course_id')
            ->count('course_id');

        if ($onlineCourses < self::SECTION_ONLINE_COURSE_LIMIT) {
            return null;
        }

        return [
            'rule' => 'section_online_limit',
            'message' => 'A section cannot have more than 5 online classes.',
        ];
    }
}
