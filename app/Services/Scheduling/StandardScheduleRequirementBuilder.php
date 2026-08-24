<?php

namespace App\Services\Scheduling;

use App\Models\Sections;
use Illuminate\Support\Collection;

class StandardScheduleRequirementBuilder implements ScheduleRequirementBuilder
{
    public function build(Sections $section, Collection $courses, array $options = []): array
    {
        $requirements = [];
        $defaultMode = (string) ($options['mode'] ?? 'on-site');
        $deliveryModes = $options['delivery_modes_by_course_id'] ?? [];

        foreach ($courses as $course) {
            $mode = (string) ($deliveryModes[(int) $course->id] ?? $deliveryModes[(string) $course->id] ?? $defaultMode);
            $componentType = match (true) {
                $mode === 'online' => 'online',
                SchedulingPolicy::isFieldCourse($course) || $mode === 'field' => 'field',
                SchedulingPolicy::isLaboratoryCourse($course) => 'laboratory',
                default => 'lecture',
            };
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                'laboratory' => ['laboratory'],
                default => ['lecture'],
            };
            $isExplicitMode = array_key_exists((int) $course->id, $deliveryModes)
                || array_key_exists((string) $course->id, $deliveryModes);
            $allowedModes = match ($componentType) {
                'field' => ['field'],
                'laboratory' => ['on-site'],
                'online' => ['online'],
                default => $isExplicitMode ? [$mode] : ['on-site', 'online'],
            };

            $requirements[(int) $course->id] = [
                (new ScheduleRequirement(
                    courseId: (int) $course->id,
                    componentType: $componentType,
                    durationSlots: max(1, (int) round((float) ($course->units ?? 0) * 2)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: $allowedModes,
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
