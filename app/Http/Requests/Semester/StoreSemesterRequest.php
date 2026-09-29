<?php

namespace App\Http\Requests\Semester;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Contracts\Validation\Validator;
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
            // Same shape the edit form enforces: two consecutive years.
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}-\d{4}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $year = $this->input('academic_year');
            if (! is_string($year) || ! preg_match('/^(\d{4})-(\d{4})$/', $year, $parts)) {
                return;
            }

            if ((int) $parts[2] !== (int) $parts[1] + 1) {
                $validator->errors()->add('academic_year', 'The academic year must span two consecutive years, e.g. 2026-2027.');
            }
        });
    }
}
