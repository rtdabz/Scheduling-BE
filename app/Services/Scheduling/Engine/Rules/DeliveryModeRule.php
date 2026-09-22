<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * delivery_mode, hybrid_mode, hybrid_eligibility, hybrid_component_type,
 * hybrid_component_shape.
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

        return self::hybridShapeMismatch(
            $course,
            (string) ($attempt['mode'] ?? 'on-site'),
            $attempt['meeting_type'] ?? null,
            RuleSupport::durationMinutes((string) ($attempt['start_time'] ?? ''), (string) ($attempt['end_time'] ?? '')),
        );
    }

    /**
     * The hybrid decision for one meeting already marked hybrid, for a course
     * in either form (model or the kernel's snapshot array). The one
     * implementation; the constraint kernel calls it too.
     *
     * Integrated Hybrid's lecture and laboratory lengths are the user's to set
     * in Setup Courses, so only their delivery is fixed here; `class_duration`
     * holds each session to one teaching day. Hybrid Split is a fixed shape and keeps
     * its exact length.
     *
     * @param  Course|array<string, mixed>  $course
     * @return array{rule: string, message: string}|null
     */
    public static function hybridShapeMismatch(Course|array $course, string $mode, ?string $meetingType, int $durationMinutes): ?array
    {
        $value = static fn (string $key): int => (int) (is_array($course) ? ($course[$key] ?? 0) : ($course->{$key} ?? 0));
        $hasLaboratoryComponent = $value('lab_hours') > 0;
        $isHybridSplit = ! $hasLaboratoryComponent && SchedulingPolicy::hybridSplitEligible($course);
        if (! $isHybridSplit && (! SchedulingPolicy::isMajorCourse($course) || $value('lecture_hours') <= 0 || ! $hasLaboratoryComponent)) {
            return [
                'rule' => 'hybrid_eligibility',
                'message' => 'Hybrid scheduling is available only for eligible course configurations.',
            ];
        }

        $expectedMode = $hasLaboratoryComponent
            ? match ($meetingType) {
                'lecture' => 'online',
                'laboratory' => 'on-site',
                default => null,
            }
            : ($meetingType === 'lecture' ? (in_array($mode, ['online', 'on-site'], true) ? $mode : 'on-site') : null);
        if ($expectedMode === null) {
            return [
                'rule' => 'hybrid_component_type',
                'message' => 'Hybrid schedules must identify each meeting as lecture or laboratory.',
            ];
        }

        $wrongLength = $hasLaboratoryComponent
            ? $durationMinutes <= 0
            : $durationMinutes !== SchedulingPolicy::HYBRID_SPLIT_MEETING_MINUTES;
        if ($mode !== $expectedMode || $wrongLength) {
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

}
