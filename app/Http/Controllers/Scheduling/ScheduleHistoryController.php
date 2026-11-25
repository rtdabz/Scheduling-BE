<?php

namespace App\Http\Controllers\Scheduling;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\User;
use App\Services\Scheduling\History\ApprovedScheduleHistory;
use App\Services\Scheduling\History\ScheduleHistoryExporter;
use App\Services\Scheduling\History\ScheduleHistorySummaries;
use App\Services\Scheduling\History\ScheduleReuseRejected;
use App\Services\Scheduling\History\ScheduleReuseService;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ScheduleHistoryController extends Controller
{
    /** Schedule History lists only officially (VPAA) approved schedules; see ApprovedScheduleHistory. */
    private const TYPES = ApprovedScheduleHistory::TYPES;

    public function __construct(private readonly ScheduleHistorySummaries $summaries) {}

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

        return $this->indexVersions($validated, $page, $perPage, $user, $requestedDepartment, $request);
    }

    /**
     * The list carries only what the table shows. A semester snapshot can hold hundreds of classes, so the
     * per-class rows (`snapshots`) and fallback signatory accounts (`users`) are served by show() when an
     * entry is opened or printed; loading them here made each page several MB.
     */
    private function indexVersions(array $validated, int $page, int $perPage, $user, $requestedDepartment, Request $request): JsonResponse
    {
        $query = ApprovedScheduleHistory::visibleTo(ApprovedScheduleHistory::query(self::TYPES[$validated['type'] ?? ''] ?? null), $user)
            ->with(['department:id,department_name,department_code,logo', 'semesterRecord:id,academic_year,semester'])
            ->when($requestedDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($validated['schedule_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($items) => $items->where('original_schedule_id', $id)))
            ->latest('created_at')->latest('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $versions = collect($paginator->items());
        // Cache only versions whose writes have certainly finished (items are inserted right after the version).
        $settled = $versions->filter(fn (ScheduleHistoryVersion $version) => $version->created_at?->lt(now()->subSeconds(ScheduleHistorySummaries::SETTLE_SECONDS)) ?? false)->pluck('id')->all();
        $summaries = $this->summaries->forVersions($versions->pluck('id')->all(), $settled);
        $scopes = $versions->mapWithKeys(fn ($version) => [(int) $version->id => [
            'department_id' => (int) $version->department_id, 'rows' => $summaries[$version->id]['ownership_rows'] ?? [],
        ]])->all();
        $reuseAccess = app(ScheduleAuthorizationService::class)->programOperationAccessAmong($request, $scopes);
        $canCreate = $user?->hasCapability('schedule.create') === true;

        $data = $versions->map(function (ScheduleHistoryVersion $version) use ($summaries, $reuseAccess, $canCreate): array {
            $summary = $summaries[$version->id] ?? ['count' => 0, 'sections' => 0, 'schedule_id' => null, 'snapshot' => null, 'section_name' => null];

            return $this->summary($version, $summary['count'], $summary['sections'], $summary['schedule_id'], $summary['snapshot'], $summary['section_name'])
                + ['can_reuse' => $canCreate && ($reuseAccess[$version->id] ?? false)];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]]);
    }

    /** GET /schedule-history/{version}: one entry with every class snapshot, for viewing and printing. */
    public function show(int $version, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user?->role !== 'vpaa' && $user?->department_id === null) {
            return response()->json(['message' => 'Your account is not assigned to a department.'], 403);
        }
        $entry = ApprovedScheduleHistory::visibleTo(ApprovedScheduleHistory::query(), $user)
            ->with(['department:id,department_name,department_code,logo', 'semesterRecord:id,academic_year,semester', 'items' => fn ($items) => $this->withoutEmbeddedLogos($items)->orderBy('id')])
            ->find($version);
        if ($entry === null) {
            return response()->json(['message' => 'Only VPAA-approved schedules you can view are available.'], 404);
        }

        $items = $entry->items;
        $first = $items->first();
        $sectionIds = $items->map(fn ($item) => data_get($this->snapshotOf($item), 'section_id') ?? data_get($item->snapshot_metadata, 'section_id'))->filter()->unique()->values();
        // Snapshots name their department by code; resolve each code to its logo URL once (never inline).
        $departmentCodes = $items->map(fn ($item) => data_get($item->snapshot_metadata, 'department_code'))->filter()->unique()->values();
        $logoByCode = $departmentCodes->isEmpty() ? collect() : Departments::withTrashed()
            ->whereIn('department_code', $departmentCodes)
            ->get(['id', 'department_code', 'logo'])
            ->mapWithKeys(fn (Departments $department) => [$department->department_code => $department->logo]);
        $versionLogo = $entry->department?->logo;
        $users = User::query()->where('is_active', true)
            ->where(fn ($q) => $q
                ->where(fn ($dept) => $dept->where('department_id', $entry->department_id)->whereIn('role', ['secretary', 'director', 'program_head', 'dean']))
                ->orWhere('role', 'vpaa'))
            ->latest()
            ->get(['id', 'name', 'role', 'department_id', 'program_id']);

        return response()->json(['data' => [
            ...$this->summary($entry, $items->count(), $sectionIds->count(), $first?->original_schedule_id, $first ? $this->snapshotOf($first) : null, data_get($first?->snapshot_metadata, 'section_name')),
            'snapshots' => $items->map(function ($item) use ($logoByCode, $versionLogo) {
                $metadata = $item->snapshot_metadata ?? [];
                $snapshot = $this->snapshotOf($item);

                return [
                    'id' => $item->id,
                    'schedule_id' => $item->original_schedule_id,
                    'section_id' => data_get($snapshot, 'section_id'),
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
                    'department_logo' => $logoByCode->get($metadata['department_code'] ?? '') ?? $versionLogo,
                    'snapshot' => $snapshot,
                ];
            })->values()->all(),
            // Current accounts, used only when the entry has no recorded `signatories`.
            'users' => $users->values()->all(),
            'can_reuse' => $user?->hasCapability('schedule.create') === true
                && app(ScheduleAuthorizationService::class)->programOperationRowsWritable($request, ApprovedScheduleHistory::rows($entry)->pluck('snapshot')->all(), (int) $entry->department_id),
        ]]);
    }

    /** Fields shared by the list row and the detail payload. */
    private function summary(ScheduleHistoryVersion $version, int $scheduleCount, int $sectionCount, mixed $scheduleId, ?array $firstSnapshot, mixed $firstSectionName): array
    {
        $metadata = $version->change_summary ?? [];
        $departmentName = $version->department?->department_name ?: 'Department';
        $scope = $metadata['history_scope'] ?? ($sectionCount >= 2 ? 'multiple_sections' : 'section');
        $label = in_array($scope, ['entire_semester', 'entire_department_schedule', 'entire_schedule'], true)
            ? $departmentName.' Schedule'
            : ($scope === 'multiple_sections' ? $departmentName.' Department Schedule' : ($firstSectionName ? $departmentName.' · '.$firstSectionName : $departmentName.' Schedule'));

        return [
            'id' => $version->id,
            'group_id' => $metadata['history_group_id'] ?? null,
            'schedule_id' => $scheduleId,
            'schedule_count' => $scheduleCount,
            'section_count' => $sectionCount,
            'schedule_label' => $label,
            'semester_id' => $version->semester_id,
            'academic_year' => $version->academic_year ?? $version->semesterRecord?->academic_year,
            'semester' => $version->semester ?? $version->semesterRecord?->semester,
            'section_id' => data_get($firstSnapshot, 'section_id'),
            'course_id' => data_get($firstSnapshot, 'course_id'),
            'department_id' => $version->department_id,
            'action' => $version->action,
            'rejection_reason' => $metadata['rejection_reason'] ?? null,
            'snapshot' => $firstSnapshot,
            'department' => $version->department ? [
                'id' => $version->department->id,
                'department_name' => $version->department->department_name,
                'department_code' => $version->department->department_code,
                'logo' => $version->department->logo,
            ] : null,
            // The officials recorded when this decision was made; entries without a record fall back to `users` (detail only).
            'signatories' => $version->signatories,
            'actor' => $version->recorded_actor,
            'created_at' => $version->created_at?->toISOString(),
        ];
    }

    private function snapshotOf(?ScheduleHistoryItem $item): ?array
    {
        return ScheduleHistorySummaries::snapshotOf($item);
    }

    /**
     * Older semester archives embedded the department's base64 logo (20–45 KB) in every item's
     * snapshot_metadata. History rows are append-only (enforced by DB triggers), so instead of rewriting them
     * the database drops that key while reading; logos are resolved from department_code instead.
     */
    private function withoutEmbeddedLogos($items)
    {
        $metadata = match ($items->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "JSON_REMOVE(snapshot_metadata, '$.department_logo')",
            'sqlite' => "CASE WHEN json_valid(snapshot_metadata) THEN json_remove(snapshot_metadata, '$.department_logo') ELSE snapshot_metadata END",
            default => null,
        };
        if ($metadata === null) {
            return $items;
        }

        return $items->select(['id', 'history_version_id', 'original_schedule_id', 'before_snapshot', 'after_snapshot', 'created_at', 'updated_at'])
            ->selectRaw("{$metadata} as snapshot_metadata");
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
