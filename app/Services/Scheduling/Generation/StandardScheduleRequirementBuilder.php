<?php

namespace App\Services\Scheduling\Generation;

use App\Models\Sections;
use App\Services\Scheduling\Support\SchedulingPolicy;
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
            // A standard department has no laboratory components at all: both
            // the preflight and ValidateGenerationConfiguration reject a
            // laboratory course here before a requirement is ever built. This
            // builder therefore knows only lecture, field and online, and says
            // so rather than carrying a laboratory branch that contradicts the
            // profile it exists to enforce.
            $componentType = match (true) {
                $mode === 'online' => 'online',
                SchedulingPolicy::isFieldCourse($course, (int) $section->department_id) || $mode === 'field' => 'field',
                default => 'lecture',
            };
            $isExplicitMode = array_key_exists((int) $course->id, $deliveryModes)
                || array_key_exists((string) $course->id, $deliveryModes);
            $allowedModes = match ($componentType) {
                'field' => ['field'],
                'online' => ['online'],
                default => $isExplicitMode ? [$mode] : ['on-site', 'online'],
            };
            $roomTypes = match ($componentType) {
                'online' => ['online'],
                'field' => ['field'],
                default => in_array('online', $allowedModes, true)
                    ? ['lecture', 'online']
                    : ['lecture'],
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
