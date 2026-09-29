<?php

namespace App\Http\Requests\Room;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'room_code' => ['required', 'string', 'max:255', Rule::unique('rooms', 'room_code')->whereNull('deleted_at')],
            'building' => self::BUILDING_RULE,
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('required|string'),
            'allow_lecture_usage' => 'sometimes|boolean',
            'status' => SchedulingPolicy::allowedRoomStatusesRule('nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'room_code.unique' => 'This room code is already used by another room.',
        ];
    }
}
