<?php

namespace App\Http\Requests\Room;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreRoomRequest extends FormRequest
{
    public const BUILDING_RULE = 'nullable|string|max:100';

    /**
     * A room code is one room however it is typed: case and spacing do not
     * make another one ("rm 101" is RM 101).
     */
    public static function uniqueRoomCode(?int $ignoreRoomId = null): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($ignoreRoomId): void {
            $taken = DB::table('rooms')
                ->whereNull('deleted_at')
                ->when($ignoreRoomId !== null, fn ($query) => $query->where('id', '!=', $ignoreRoomId))
                ->whereRaw('LOWER(room_code) = ?', [mb_strtolower((string) $value)])
                ->exists();
            if ($taken) {
                $fail('This room code is already used by another room.');
            }
        };
    }

    /** Leading, trailing and repeated spaces never make a different code. */
    public static function normalizeRoomCode(mixed $code): mixed
    {
        return is_string($code) ? trim((string) preg_replace('/\s+/', ' ', $code)) : $code;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('room_code')) {
            $this->merge(['room_code' => self::normalizeRoomCode($this->input('room_code'))]);
        }
    }

    /** Access is enforced by the route's capability middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_code' => ['required', 'string', 'max:255', self::uniqueRoomCode()],
            'building' => self::BUILDING_RULE,
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('required|string'),
            'allow_lecture_usage' => 'sometimes|boolean',
            'status' => SchedulingPolicy::allowedRoomStatusesRule('nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ];
    }
}
