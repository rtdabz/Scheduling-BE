<?php

namespace App\Services\Scheduling;

use App\Models\Sections;
use Illuminate\Support\Collection;

class LaboratoryScheduleRequirementBuilder implements ScheduleRequirementBuilder
{
    public function build(Sections $section, Collection $courses, array $options = []): array
    {
        $requirements = [];
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = $options['delivery_modes_by_course_id'] ?? [];
        $splitIds = array_map('intval', $options['selected_split_session_course_ids'] ?? []);
        $overrideEnabled = (bool) ($section->department?->lecture_lab_schedule_override_enabled ?? false);

        foreach ($courses as $course) {
            $courseId = (int) $course->id;
            $mode = (string) ($deliveryModes[$courseId] ?? $deliveryModes[(string) $courseId] ?? $defaultMode);
            $isLaboratory = SchedulingPolicy::isLaboratoryCourse($course);
            $isMajor = $course->course_category === 'major' || ($course->subject_category ?? null) === 'major';
            $hasLectureAndLaboratory = $overrideEnabled
                && $isMajor
                && in_array($courseId, $splitIds, true)
                && (int) ($course->lecture_hours ?? 0) > 0
                && (int) ($course->lab_hours ?? 0) > 0;

            if ($hasLectureAndLaboratory) {
                $requirements[$courseId] = [
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'lecture',
                        durationSlots: (int) $course->lecture_hours * 2,
                        eligibleRoomTypes: ['online'],
                        allowedDeliveryModes: ['online'],
                        isSplitComponent: true,
                    ))->toArray(),
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'laboratory',
                        durationSlots: (int) $course->lab_hours * 6,
                        eligibleRoomTypes: ['laboratory'],
                        allowedDeliveryModes: ['on-site'],
                        isSplitComponent: true,
                    ))->toArray(),
                ];

                continue;
            }

            $componentType = match (true) {
                $mode === 'online' => 'online',
                SchedulingPolicy::isFieldCourse($course) || $mode === 'field' => 'field',
                $isLaboratory => 'laboratory',
                default => 'lecture',
            };
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                'laboratory' => ['laboratory', 'lecture'],
                default => ['lecture', 'laboratory'],
            };

            $requirements[$courseId] = [
                (new ScheduleRequirement(
                    courseId: $courseId,
                    componentType: $componentType,
                    durationSlots: max(1, (int) round((float) ($course->units ?? 0) * 2)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: [$componentType === 'field' ? 'field' : $mode],
                    allowLectureLaboratoryFallback: $componentType === 'lecture' && $isMajor,
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
