<?php

namespace App\Http\Requests\Room;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    /** Access is enforced by the route's capability middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('rooms')->ignore($this->route('room'))],
            'building' => StoreRoomRequest::BUILDING_RULE,
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('sometimes|required|string'),
            'allow_lecture_usage' => 'sometimes|boolean',
            'status' => SchedulingPolicy::allowedRoomStatusesRule('sometimes|nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ];
    }
}
