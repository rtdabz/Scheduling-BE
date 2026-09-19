<?php

namespace App\Services\Scheduling\Generation;

use App\Models\Sections;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

class LaboratoryScheduleRequirementBuilder implements ScheduleRequirementBuilder
{
    public function build(Sections $section, Collection $courses, array $options = []): array
    {
        $requirements = [];
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = $options['delivery_modes_by_course_id'] ?? [];
        $splitIds = array_map('intval', $options['selected_split_session_course_ids'] ?? []);

        foreach ($courses as $course) {
            $courseId = (int) $course->id;
            $mode = (string) ($deliveryModes[$courseId] ?? $deliveryModes[(string) $courseId] ?? $defaultMode);
            $isLaboratory = SchedulingPolicy::isLaboratoryCourse($course);
            $isMajor = SchedulingPolicy::isMajorCourse($course);
            $hasLectureAndLaboratory = $isMajor
                && in_array($courseId, $splitIds, true)
                && (int) ($course->lecture_hours ?? 0) > 0
                && (int) ($course->lab_hours ?? 0) > 0;

            $preferredRoomId = CourseSetupOverrides::preferredRoomId($options, $courseId);

            if ($hasLectureAndLaboratory) {
                // Integrated: two separate sessions, each either the length
                // chosen in Setup Courses or the course's own. On-site keeps
                // the lecture face-to-face; Hybrid moves it online.
                $lectureOnSite = SchedulingPolicy::isIntegratedOnSite($deliveryModes, $courseId);
                $lectureSlots = CourseSetupOverrides::componentSlots($options, $courseId, 'lecture');
                $laboratorySlots = CourseSetupOverrides::componentSlots($options, $courseId, 'laboratory');
                $requirements[$courseId] = [
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'lecture',
                        durationSlots: $lectureSlots ?? SchedulingPolicy::lectureComponentSlots($course),
                        eligibleRoomTypes: $lectureOnSite ? ['lecture'] : ['online'],
                        allowedDeliveryModes: $lectureOnSite ? ['on-site'] : ['online'],
                        isSplitComponent: true,
                        customDuration: $lectureSlots !== null,
                    ))->toArray(),
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'laboratory',
                        durationSlots: $laboratorySlots ?? SchedulingPolicy::laboratoryComponentSlots($course, $section->department),
                        eligibleRoomTypes: ['laboratory'],
                        allowedDeliveryModes: ['on-site'],
                        isSplitComponent: true,
                        customDuration: $laboratorySlots !== null,
                        // Preferred Room names the laboratory, the scarcer room.
                        preferredRoomId: $preferredRoomId,
                    ))->toArray(),
                ];

                continue;
            }

            $componentType = match (true) {
                $mode === 'online' => 'online',
                SchedulingPolicy::isFieldCourse($course, (int) $section->department_id) || $mode === 'field' => 'field',
                $isLaboratory => 'laboratory',
                default => 'lecture',
            };
            $isExplicitMode = array_key_exists($courseId, $deliveryModes)
                || array_key_exists((string) $courseId, $deliveryModes);
            $allowedModes = match ($componentType) {
                'field' => ['field'],
                'laboratory' => ['on-site'],
                'online' => ['online'],
                default => $isExplicitMode ? [$mode] : ['on-site', 'online'],
            };
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                // A laboratory component may only use a laboratory room.
                // RuleEngine::checkRoomTypeMatch rejects a lecture room for it at
                // save time, so offering one here would only produce previews
                // that cannot be saved.
                'laboratory' => ['laboratory'],
                default => in_array('online', $allowedModes, true)
                    ? ['lecture', 'laboratory', 'online']
                    : ['lecture', 'laboratory'],
            };

            $customSlots = CourseSetupOverrides::durationSlots($options, $courseId);

            $requirements[$courseId] = [
                (new ScheduleRequirement(
                    courseId: $courseId,
                    componentType: $componentType,
                    durationSlots: $customSlots ?? max(1, (int) round((float) ($course->units ?? 0) * 2)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: $allowedModes,
                    allowLectureLaboratoryFallback: $componentType === 'lecture' && $isMajor,
                    customDuration: $customSlots !== null,
                    preferredRoomId: $componentType === 'online' ? null : $preferredRoomId,
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
