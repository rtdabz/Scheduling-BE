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
                default => 'lecture',
            };
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                default => ['lecture'],
            };

            $requirements[(int) $course->id] = [
                (new ScheduleRequirement(
                    courseId: (int) $course->id,
                    componentType: $componentType,
                    durationSlots: max(1, (int) round((float) ($course->units ?? 0) * 2)),
                    eligibleRoomTypes: $roomTypes,
                    allowedDeliveryModes: [$componentType === 'field' ? 'field' : $mode],
                ))->toArray(),
            ];
        }

        return $requirements;
    }
}
