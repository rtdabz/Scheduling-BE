<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

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

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            courseId: (int) ($payload['course_id'] ?? 0),
            componentType: (string) ($payload['component_type'] ?? 'lecture'),
            durationSlots: (int) ($payload['duration_slots'] ?? 0),
            eligibleRoomTypes: array_values(array_map('strval', (array) ($payload['eligible_room_types'] ?? []))),
            allowedDeliveryModes: array_values(array_map('strval', (array) ($payload['allowed_delivery_modes'] ?? []))),
            allowLectureLaboratoryFallback: (bool) ($payload['allow_lecture_laboratory_fallback'] ?? false),
            isSplitComponent: (bool) ($payload['is_split_component'] ?? false),
        );
    }

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
