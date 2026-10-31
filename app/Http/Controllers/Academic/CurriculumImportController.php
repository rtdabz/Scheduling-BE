<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Services\Curriculum\CurriculumImportConflict;
use App\Services\Curriculum\CurriculumImportInvalid;
use App\Services\Curriculum\CurriculumImportService;
use App\Services\Curriculum\CurriculumImportSettings;
use App\Services\Curriculum\CurriculumImportValidator;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurriculumImportController extends Controller
{
    public function __construct(
        private readonly CurriculumImportSettings $settings,
        private readonly CurriculumImportValidator $validator,
        private readonly CurriculumImportService $imports,
    ) {}

    public function showSettings(Request $request): JsonResponse
    {
        return response()->json($this->settings->payload($this->department($request), $this->canManageSettings($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        // Department-wide policy belongs to the department secretary; curriculum.manage alone does not grant it.
        if (! $this->canManageSettings($request)) {
            return response()->json(['message' => 'Only the department secretary can change curriculum import settings.'], 403);
        }

        $rate = ['required', 'integer', 'min:'.SchedulingPolicy::SLOT_MINUTES, 'max:'.CurriculumImportSettings::MAX_MINUTES_PER_UNIT, 'multiple_of:'.SchedulingPolicy::SLOT_MINUTES];
        $validated = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'lecture_minutes_per_unit' => $rate,
            'laboratory_minutes_per_unit' => $rate,
            'rules' => 'present|array|max:'.CurriculumImportSettings::MAX_RULES,
            'rules.*.match_kind' => 'required|string|in:exact,prefix',
            'rules.*.match_value' => 'required|string|max:'.CurriculumImportSettings::MAX_MATCH_LENGTH,
            'rules.*.category' => 'required|string|in:major,minor',
            'rules.*.enabled' => 'sometimes|boolean',
            'acknowledge_overlaps' => 'sometimes|boolean',
        ]);

        try {
            $department = $this->settings->update($this->department($request), $validated);
        } catch (CurriculumImportConflict $conflict) {
            return response()->json(['message' => $conflict->getMessage()], 409);
        }

        return response()->json($this->settings->payload($department, true));
    }

    public function validateDraft(Request $request): JsonResponse
    {
        $payload = $request->validate(CurriculumImportValidator::rules($request->user(), false), CurriculumImportValidator::messages());

        return response()->json($this->validator->validate($payload, $this->department($request), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate(CurriculumImportValidator::rules($request->user(), true), CurriculumImportValidator::messages());

        try {
            $result = $this->imports->commit($payload, $this->department($request), $request->user());
        } catch (CurriculumImportConflict $conflict) {
            return response()->json(['message' => $conflict->getMessage()], 409);
        } catch (CurriculumImportInvalid $invalid) {
            return response()->json([
                'message' => 'The draft has unresolved issues. Resolve them and validate again.',
                'validation' => $invalid->validation,
            ], 422);
        }

        return response()->json($result['body'], $result['status']);
    }

    private function department(Request $request): Departments
    {
        $user = $request->user();
        abort_if($user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }

    private function canManageSettings(Request $request): bool
    {
        return $request->user()?->role === 'secretary';
    }
}
