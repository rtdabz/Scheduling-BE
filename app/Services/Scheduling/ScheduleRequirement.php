<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

final readonly class ScheduleRequirement
{
    /**
     * @param  list<string>  $eligibleRoomTypes
     * @param  list<string>  $allowedDeliveryModes
     */
    public function __construct(
        public int $courseId,
        public string $componentType,
        public int $durationSlots,
        public array $eligibleRoomTypes,
        public array $allowedDeliveryModes,
        public bool $allowLectureLaboratoryFallback = false,
        public bool $isSplitComponent = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'course_id' => $this->courseId,
            'component_type' => $this->componentType,
            'duration_slots' => $this->durationSlots,
            'eligible_room_types' => $this->eligibleRoomTypes,
            'allowed_delivery_modes' => $this->allowedDeliveryModes,
            'allow_lecture_laboratory_fallback' => $this->allowLectureLaboratoryFallback,
            'is_split_component' => $this->isSplitComponent,
        ];
    }
}
