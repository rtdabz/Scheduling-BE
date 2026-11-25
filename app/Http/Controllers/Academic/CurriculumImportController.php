<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Services\Curriculum\CurriculumImportConflict;
use App\Services\Curriculum\CurriculumImportInvalid;
use App\Services\Curriculum\CurriculumImportService;
use App\Services\Curriculum\CurriculumImportSettings;
use App\Services\Curriculum\CurriculumImportTemplate;
use App\Services\Curriculum\CurriculumImportValidator;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CurriculumImportController extends Controller
{
    public function __construct(
        private readonly CurriculumImportSettings $settings,
        private readonly CurriculumImportValidator $validator,
        private readonly CurriculumImportService $imports,
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    public function showSettings(Request $request): JsonResponse
    {
        return response()->json($this->settings->payload($this->department($request), $this->canManageSettings($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        // Department-wide policy belongs to the department office (Secretary or Director); curriculum.manage alone does not grant it.
        if (! $this->canManageSettings($request)) {
            return response()->json(['message' => 'Only the department Secretary or Director can change curriculum import settings.'], 403);
        }

        // 0 is allowed, e.g. a department without laboratories: that component then gets no class time.
        $rate = ['required', 'integer', 'min:0', 'max:'.CurriculumImportSettings::MAX_MINUTES_PER_UNIT, 'multiple_of:'.SchedulingPolicy::SLOT_MINUTES];
        $validated = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'lecture_minutes_per_unit' => $rate,
            'laboratory_minutes_per_unit' => $rate,
            'rules' => 'present|array|max:'.CurriculumImportSettings::MAX_RULES,
            'rules.*.match_value' => 'required|string|max:'.CurriculumImportSettings::MAX_MATCH_LENGTH,
            'rules.*.category' => 'required|string|in:major,minor',
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
        if ($refused = $this->headManagedTargetRefusal($request, $payload)) {
            return $refused;
        }

        return response()->json($this->validator->validate($payload, $this->department($request), $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate(CurriculumImportValidator::rules($request->user(), true), CurriculumImportValidator::messages());
        if ($refused = $this->headManagedTargetRefusal($request, $payload)) {
            return $refused;
        }

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

    /** The blank curriculum workbook, with the TCC logo, municipal seal, and the user's department logo. */
    public function template(Request $request, CurriculumImportTemplate $template): Response
    {
        $department = $this->department($request);
        $code = preg_replace('/[^A-Za-z0-9-]+/', '', (string) $department->department_code);
        $filename = 'curriculum-import-template'.($code !== '' ? '-'.strtolower($code) : '').'.xlsx';

        return response($template->build($department), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function department(Request $request): Departments
    {
        $user = $request->user();
        abort_if($user->department_id === null, 422, 'Your account is not assigned to a department.');

        return Departments::query()->findOrFail((int) $user->department_id);
    }

    /**
     * The department office may not import a curriculum into a program that has a Program Head.
     * An office import must identify an unheaded program whenever the department has a Program Head.
     *
     * @param  array<string, mixed>  $payload
     */
    private function headManagedTargetRefusal(Request $request, array $payload): ?JsonResponse
    {
        if (($payload['metadata']['program_id'] ?? null) === null
            && $this->authorization->headManagedProgramIds($request) !== []) {
            return response()->json(['message' => 'Choose a program without an active Program Head. Department-wide imports are restricted while any program has a Program Head.'], 403);
        }

        return $this->authorization->officeMayManageProgram($request, $payload['metadata']['program_id'] ?? null)
            ? null
            : response()->json(['message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE], 403);
    }

    private function canManageSettings(Request $request): bool
    {
        return $request->user()?->isSecretaryOrDirector() === true;
    }
}
