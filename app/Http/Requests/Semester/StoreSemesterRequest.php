<?php

namespace App\Http\Requests\Semester;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreSemesterRequest extends FormRequest
{
    /** Access is enforced by the route's middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'semester' => SchedulingPolicy::allowedSemestersRule('required'),
            'academic_year' => 'nullable|string|max:50',
        ];
    }
}
