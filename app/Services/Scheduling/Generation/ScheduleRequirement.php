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
        public bool $isSplitComponent = false,
        public ?int $preferredRoomId = null,
        public ?string $requiredDay = null,
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
            isSplitComponent: (bool) ($payload['is_split_component'] ?? false),
            preferredRoomId: isset($payload['preferred_room_id']) ? (int) $payload['preferred_room_id'] : null,
            requiredDay: isset($payload['required_day']) && $payload['required_day'] !== '' ? (string) $payload['required_day'] : null,
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
            'is_split_component' => $this->isSplitComponent,
            'preferred_room_id' => $this->preferredRoomId,
            // Only pinned Integrated components carry a day, so unpinned
            // requirements (and their cache identity) are unchanged.
            ...($this->requiredDay === null ? [] : ['required_day' => $this->requiredDay]),
        ];
    }
}
