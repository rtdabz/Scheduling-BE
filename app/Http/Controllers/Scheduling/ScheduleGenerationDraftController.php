<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Concerns\StoresRevisionedDrafts;
use App\Http\Controllers\Controller;
use App\Models\ScheduleGenerationDraft;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auto-saved progress of the Generate Schedule wizard, private to the user who made it.
 * Saving or restoring a draft never generates or writes a timetable; it only remembers the
 * wizard's inputs and which generation run the user was looking at.
 */
class ScheduleGenerationDraftController extends Controller
{
    use StoresRevisionedDrafts;

    private const MAX_PAYLOAD_BYTES = 1_000_000;

    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
        ]);
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return $this->forbidden();
        }

        return $this->readRevisionedDraft(ScheduleGenerationDraft::class, $this->scope($request, $validated));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'revision' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
            'payload.step' => ['required', 'integer', 'between:1,4'],
            'payload.year_level' => ['nullable', 'integer', 'between:1,6'],
            'payload.active_section_id' => ['nullable', 'string', 'max:32'],
            'payload.target_section_ids' => ['nullable', 'array', 'max:500'],
            'payload.target_section_ids.*' => ['string', 'max:32'],
            'payload.configs' => ['present', 'array', 'max:500'],
            'payload.setup_draft' => ['present', 'array'],
            'payload.course_defaults' => ['nullable', 'array'],
            'payload.run_id' => ['nullable', 'string', 'max:64'],
            'payload.saved_at' => ['nullable', 'integer', 'min:0'],
        ]);
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return $this->forbidden();
        }

        $payload = $request->input('payload');
        if (strlen((string) json_encode($payload)) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'This progress is too large to save.'], 422);
        }

        return $this->writeRevisionedDraft(ScheduleGenerationDraft::class, $this->scope($request, $validated), (int) $validated['revision'], $payload);
    }

    /** Clears the draft once the timetable is saved; the revision keeps late saves from reviving it. */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return $this->forbidden();
        }

        return $this->writeRevisionedDraft(ScheduleGenerationDraft::class, $this->scope($request, $validated), (int) $validated['revision'], null);
    }

    /** @return array<string, int> */
    private function scope(Request $request, array $validated): array
    {
        return [
            'user_id' => (int) $request->user()->id,
            'department_id' => (int) $validated['department_id'],
            'semester_id' => (int) $validated['semester_id'],
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
    }
}
