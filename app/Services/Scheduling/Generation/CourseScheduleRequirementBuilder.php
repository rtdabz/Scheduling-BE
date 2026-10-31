<?php

namespace App\Services\Scheduling\Generation;

use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

class CourseScheduleRequirementBuilder implements ScheduleRequirementBuilder
{
    public function build(Sections $section, Collection $courses, array $options = []): array
    {
        $requirements = [];
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = $options['delivery_modes_by_course_id'] ?? [];
        $splitIds = array_map('intval', $options['selected_split_session_course_ids'] ?? []);

        $curriculumId = $section->curriculum_id === null ? null : (int) $section->curriculum_id;
        foreach ($courses as $course) {
            $course = SchedulingPolicy::placedCourse($course, $curriculumId);
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
                $lectureOnSite = SchedulingPolicy::isIntegratedOnSite($deliveryModes, $courseId);
                $requirements[$courseId] = [
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'lecture',
                        durationSlots: SchedulingPolicy::lectureComponentSlots($course),
                        eligibleRoomTypes: $lectureOnSite ? ['lecture'] : ['online'],
                        allowedDeliveryModes: $lectureOnSite ? ['on-site'] : ['online'],
                        isSplitComponent: true,
                    ))->toArray(),
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'laboratory',
                        durationSlots: SchedulingPolicy::laboratoryComponentSlots($course),
                        eligibleRoomTypes: SchedulingPolicy::labRoomTypes((int) $section->department_id),
                        allowedDeliveryModes: ['on-site'],
                        isSplitComponent: true,
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
            $lectureLabFallback = $componentType === 'lecture' && SchedulingPolicy::isLectureOnlyMajor($course);
            $lectureRoomTypes = $lectureLabFallback ? ['lecture', 'laboratory'] : ['lecture'];
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                'laboratory' => SchedulingPolicy::labRoomTypes((int) $section->department_id),
                default => in_array('online', $allowedModes, true)
                    ? [...$lectureRoomTypes, 'online']
                    : $lectureRoomTypes,
            };

            $requirements[$courseId] = [
                (new ScheduleRequirement(
                    courseId: $courseId,
                    componentType: $componentType,
                    durationSlots: max(1, SchedulingPolicy::courseDurationSlots($course)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: $allowedModes,
                    allowLectureLaboratoryFallback: $lectureLabFallback,
                    preferredRoomId: $componentType === 'online' ? null : $preferredRoomId,
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
