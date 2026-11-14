<?php

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Models\ScheduleHistoryVersion;
use App\Models\User;
use App\Services\Scheduling\History\ApprovedScheduleHistory;
use App\Services\Scheduling\History\ScheduleHistoryExporter;
use App\Services\Scheduling\History\ScheduleReuseRejected;
use App\Services\Scheduling\History\ScheduleReuseService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ScheduleHistoryController extends Controller
{
    /** Schedule History lists only officially (VPAA) approved schedules; see ApprovedScheduleHistory. */
    private const TYPES = ApprovedScheduleHistory::TYPES;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'schedule_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', Rule::in(array_keys(self::TYPES))],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $user = $request->user();
        $requestedDepartment = $validated['department_id'] ?? null;
        if ($user?->role !== 'vpaa' && $user?->department_id === null) {
            return response()->json(['message' => 'Your account is not assigned to a department.'], 403);
        }
        if ($user?->role !== 'vpaa' && $requestedDepartment !== null && (int) $requestedDepartment !== (int) $user->department_id) {
            return response()->json(['message' => 'You can only view history for your department.'], 403);
        }

        return $this->indexVersions($validated, $page, $perPage, $user, $requestedDepartment);
    }

    private function indexVersions(array $validated, int $page, int $perPage, $user, $requestedDepartment): JsonResponse
    {
        $query = ApprovedScheduleHistory::visibleTo(ApprovedScheduleHistory::query(self::TYPES[$validated['type'] ?? ''] ?? null), $user)
            ->with(['department:id,department_name,department_code,logo', 'semesterRecord:id,academic_year,semester', 'items'])
            ->when($requestedDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($validated['schedule_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($items) => $items->where('original_schedule_id', $id)))
            ->latest('created_at')->latest('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $departmentIds = collect($paginator->items())->pluck('department_id')->filter()->unique()->values();
        $signatories = User::query()->where('is_active', true)
            ->where(fn ($q) => $q
                ->where(fn ($dept) => $dept->whereIn('department_id', $departmentIds)->whereIn('role', ['secretary', 'director', 'program_head', 'dean']))
                ->orWhere('role', 'vpaa'))
            ->latest()
            ->get(['id', 'name', 'role', 'department_id', 'program_id']);
        $data = collect($paginator->items())->map(function (ScheduleHistoryVersion $version) use ($signatories): array {
            $items = $version->items;
            $first = $items->first();
            $metadata = $version->change_summary ?? [];
            $departmentName = $version->department?->department_name ?: 'Department';
            $sectionIds = $items->map(fn ($item) => data_get($item->after_snapshot ?: $item->before_snapshot, 'section_id') ?? data_get($item->snapshot_metadata, 'section_id'))->filter()->unique()->values();
            $scope = $metadata['history_scope'] ?? ($sectionIds->count() >= 2 ? 'multiple_sections' : 'section');
            $firstMetadata = $first?->snapshot_metadata ?? [];
            $label = in_array($scope, ['entire_semester', 'entire_department_schedule', 'entire_schedule'], true)
                ? $departmentName.' Schedule'
                : ($scope === 'multiple_sections' ? $departmentName.' Department Schedule' : (($firstMetadata['section_name'] ?? null) ? $departmentName.' · '.$firstMetadata['section_name'] : $departmentName.' Schedule'));

            return [
                'id' => $version->id,
                'group_id' => $metadata['history_group_id'] ?? null,
                'schedule_id' => $first?->original_schedule_id,
                'schedule_count' => $items->count(),
                'section_count' => $sectionIds->count(),
                'schedule_label' => $label,
                'semester_id' => $version->semester_id,
                'academic_year' => $version->academic_year ?? $version->semesterRecord?->academic_year,
                'semester' => $version->semester ?? $version->semesterRecord?->semester,
                'section_id' => data_get($first?->after_snapshot ?: $first?->before_snapshot, 'section_id'),
                'course_id' => data_get($first?->after_snapshot ?: $first?->before_snapshot, 'course_id'),
                'department_id' => $version->department_id,
                'action' => $version->action,
                'rejection_reason' => $metadata['rejection_reason'] ?? null,
                'snapshot' => $first?->after_snapshot ?: $first?->before_snapshot,
                'snapshots' => $items->map(function ($item) {
                    $metadata = $item->snapshot_metadata ?? [];

                    return [
                        'id' => $item->id,
                        'schedule_id' => $item->original_schedule_id,
                        'section_id' => data_get($item->after_snapshot ?: $item->before_snapshot, 'section_id'),
                        'section_name' => $metadata['section_name'] ?? null,
                        'section_year_level' => $metadata['section_year_level'] ?? null,
                        'section_semester' => $metadata['section_semester'] ?? null,
                        'course_code' => $metadata['course_code'] ?? null,
                        'course_name' => $metadata['course_name'] ?? null,
                        'course_category' => $metadata['course_category'] ?? null,
                        'units' => $metadata['units'] ?? null,
                        'lecture_hours' => $metadata['lecture_hours'] ?? null,
                        'lab_hours' => $metadata['lab_hours'] ?? null,
                        'faculty_name' => $metadata['faculty_name'] ?? null,
                        'room_name' => $metadata['room_name'] ?? null,
                        'department_name' => $metadata['department_name'] ?? null,
                        'department_code' => $metadata['department_code'] ?? null,
                        'department_logo' => $metadata['department_logo'] ?? null,
                        'snapshot' => $item->after_snapshot ?: $item->before_snapshot,
                    ];
                })->values()->all(),
                'department' => $version->department ? [
                    'id' => $version->department->id,
                    'department_name' => $version->department->department_name,
                    'department_code' => $version->department->department_code,
                    'logo' => $version->department->logo,
                ] : null,
                // The officials recorded when this decision was made; entries without a record fall back to `users`.
                'signatories' => $version->signatories,
                'users' => $signatories
                    ->filter(fn (User $user) => $user->role === 'vpaa' || (int) $user->department_id === (int) $version->department_id)
                    ->values()->all(),
                'actor' => $version->recorded_actor,
                'created_at' => $version->created_at?->toISOString(),
            ];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]]);
    }

    /** POST /schedule-history/{version}/reuse: create a new draft schedule in the active semester. */
    public function reuse(int $version, Request $request, ScheduleReuseService $reuse): JsonResponse
    {
        $entry = ApprovedScheduleHistory::find($version, $request->user());
        if ($entry === null) {
            return response()->json(['message' => 'Only VPAA-approved schedules from your department can be reused.'], 404);
        }

        try {
            $result = $reuse->reuse($entry, $request);
        } catch (ScheduleReuseRejected $rejected) {
            return response()->json(['message' => $rejected->getMessage(), 'issues' => $rejected->issues()], $rejected->status());
        } catch (ScheduleConflictException $conflict) {
            return response()->json($conflict->payload(), 409);
        }
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        $count = $result['schedules']->count();
        $sections = $result['section_count'];

        return response()->json([
            'message' => sprintf(
                'Created %d draft class%s for %d section%s in the %s semester, A.Y. %s. Review them in the Schedule Builder, then submit for approval.',
                $count, $count === 1 ? '' : 'es', $sections, $sections === 1 ? '' : 's',
                $result['semester']->semester, $result['semester']->academic_year,
            ),
            'semester_id' => (int) $result['semester']->id,
            'section_count' => $sections,
            'schedule_ids' => $result['schedules']->modelKeys(),
            'schedules' => $result['schedules']->values(),
        ], 201);
    }

    /** GET /schedule-history/{version}/export: download a readable JSON snapshot of one approved schedule. */
    public function export(int $version, Request $request, ScheduleHistoryExporter $exporter): Response
    {
        $entry = ApprovedScheduleHistory::find($version, $request->user());
        if ($entry === null) {
            return response()->json(['message' => 'Only VPAA-approved schedules you can view can be exported.'], 404);
        }
        $json = json_encode(
            $exporter->export($entry, $request->user()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename($entry).'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }
}
