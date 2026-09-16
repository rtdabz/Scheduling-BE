<?php

namespace App\Http\Requests\Room;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public const BUILDING_RULE = 'nullable|string|in:NEE Building,Building 1,Building 2,Building 3,Building 4,Building 5,Building 6';

    /** Access is enforced by the route's capability middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_code' => 'required|string|max:255|unique:rooms,room_code',
            'building' => self::BUILDING_RULE,
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('required|string'),
            'allow_lecture_usage' => 'sometimes|boolean',
            'status' => SchedulingPolicy::allowedRoomStatusesRule('nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
            'max_concurrent_classes' => 'sometimes|integer|min:1|max:20',
        ];
    }
}
