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
        $overrideEnabled = (bool) ($section->department?->lecture_lab_schedule_override_enabled ?? false);

        foreach ($courses as $course) {
            $courseId = (int) $course->id;
            $mode = (string) ($deliveryModes[$courseId] ?? $deliveryModes[(string) $courseId] ?? $defaultMode);
            $isLaboratory = SchedulingPolicy::isLaboratoryCourse($course);
            $isMajor = SchedulingPolicy::isMajorCourse($course);
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
                        durationSlots: (int) $course->lecture_hours * SchedulingPolicy::LECTURE_SLOTS_PER_UNIT,
                        eligibleRoomTypes: ['online'],
                        allowedDeliveryModes: ['online'],
                        isSplitComponent: true,
                    ))->toArray(),
                    (new ScheduleRequirement(
                        courseId: $courseId,
                        componentType: 'laboratory',
                        durationSlots: SchedulingPolicy::laboratoryComponentSlots($course, $section->department),
                        eligibleRoomTypes: ['laboratory'],
                        allowedDeliveryModes: ['on-site'],
                        isSplitComponent: true,
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

            $requirements[$courseId] = [
                (new ScheduleRequirement(
                    courseId: $courseId,
                    componentType: $componentType,
                    durationSlots: max(1, (int) round((float) ($course->units ?? 0) * 2)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: $allowedModes,
                    allowLectureLaboratoryFallback: $componentType === 'lecture' && $isMajor,
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
