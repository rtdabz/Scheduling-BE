<?php

namespace App\Http\Requests\Room;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_code' => ['sometimes', 'required', 'string', 'max:255', StoreRoomRequest::uniqueRoomCode($this->routeRoomId())],
            'building' => StoreRoomRequest::BUILDING_RULE,
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('sometimes|required|string'),
            'allow_lecture_usage' => 'sometimes|boolean',
            'status' => SchedulingPolicy::allowedRoomStatusesRule('sometimes|nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('room_code')) {
            $this->merge(['room_code' => StoreRoomRequest::normalizeRoomCode($this->input('room_code'))]);
        }
    }

    private function routeRoomId(): ?int
    {
        $room = $this->route('room');

        return $room === null ? null : (int) (is_object($room) ? $room->getKey() : $room);
    }
}
