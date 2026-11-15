<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Concerns\StoresRevisionedDrafts;
use App\Http\Controllers\Controller;
use App\Models\InstructorAssignmentDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auto-saved progress of the Assign Instructors list. Drafts are private to the user who
 * made them and scoped to their own department. A `context` keeps separate lists apart:
 * "schedule" (Schedule Management, the default) and "cross_department:{offering dept id}"
 * (Cross-Department Assignments, one list per offering department). Saving a draft assigns nothing: the
 * queued instructors are applied only when the user confirms, through the normal
 * assignment endpoints and their rules.
 */
class InstructorAssignmentDraftController extends Controller
{
    use StoresRevisionedDrafts;

    private const CONTEXT_RULES = ['nullable', 'string', 'max:40', 'regex:/^(schedule|cross_department:[1-9][0-9]{0,9})$/'];

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'context' => self::CONTEXT_RULES,
        ]);
        $scope = $this->scope($request, (int) $validated['semester_id'], $validated['context'] ?? null);

        return $scope === null ? $this->noDepartment() : $this->readRevisionedDraft(InstructorAssignmentDraft::class, $scope);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'context' => self::CONTEXT_RULES,
            'revision' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
            'payload.step' => ['required', 'integer', 'between:1,3'],
            'payload.faculty_id' => ['nullable', 'string', 'max:32'],
            'payload.year_level' => ['nullable', 'string', 'max:4'],
            'payload.course_id' => ['nullable', 'string', 'max:32'],
            'payload.assignments' => ['present', 'array', 'max:2000'],
            'payload.assignments.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'payload.assignments.*.faculty_id' => ['required', 'string', 'max:32'],
            'payload.assignments.*.schedule_ids' => ['required', 'array', 'min:1', 'max:50'],
            'payload.assignments.*.schedule_ids.*' => ['integer', 'min:1'],
        ]);

        $scope = $this->scope($request, (int) $validated['semester_id'], $validated['context'] ?? null);
        if ($scope === null) {
            return $this->noDepartment();
        }

        $payload = [
            'step' => (int) $validated['payload']['step'],
            'faculty_id' => $validated['payload']['faculty_id'] ?? null,
            'year_level' => $validated['payload']['year_level'] ?? null,
            'course_id' => $validated['payload']['course_id'] ?? null,
            'assignments' => array_map(static fn (array $assignment): array => [
                'key' => $assignment['key'],
                'faculty_id' => $assignment['faculty_id'],
                'schedule_ids' => array_values(array_unique(array_map('intval', $assignment['schedule_ids']))),
            ], $validated['payload']['assignments']),
        ];

        return $this->writeRevisionedDraft(InstructorAssignmentDraft::class, $scope, (int) $validated['revision'], $payload);
    }

    /** Clears the draft (after the list is saved) while keeping its revision, so late saves stay refused. */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => ['required', 'integer', 'exists:semesters,id'],
            'context' => self::CONTEXT_RULES,
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        $scope = $this->scope($request, (int) $validated['semester_id'], $validated['context'] ?? null);

        return $scope === null
            ? $this->noDepartment()
            : $this->writeRevisionedDraft(InstructorAssignmentDraft::class, $scope, (int) $validated['revision'], null);
    }

    /** @return array<string, int|string>|null */
    private function scope(Request $request, int $semesterId, ?string $context): ?array
    {
        $departmentId = $request->user()?->department_id;

        return $departmentId === null ? null : [
            'user_id' => (int) $request->user()->id,
            'department_id' => (int) $departmentId,
            'semester_id' => $semesterId,
            'context' => $context ?? 'schedule',
        ];
    }

    private function noDepartment(): JsonResponse
    {
        return response()->json(['message' => 'Your account must belong to a department.'], 422);
    }
}
