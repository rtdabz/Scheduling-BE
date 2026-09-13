<?php

namespace App\Http\Controllers;

use App\Models\ScheduleHistoryVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'schedule_id' => ['nullable', 'integer'],
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
        $query = ScheduleHistoryVersion::query()
            ->with(['actor:id,name,username,role', 'department:id,department_name,department_code'])
            ->where('source', 'semester_change')
            ->where('action', 'schedule_semester_archived')
            ->when($user?->role !== 'vpaa', fn ($q) => $q->where('department_id', $user->department_id))
            ->when($requestedDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($validated['schedule_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($items) => $items->where('original_schedule_id', $id)))
            ->latest('created_at')->latest('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $data = collect($paginator->items())->map(function (ScheduleHistoryVersion $version): array {
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
                'academic_year' => $version->academic_year,
                'semester' => $version->semester,
                'section_id' => data_get($first?->after_snapshot ?: $first?->before_snapshot, 'section_id'),
                'course_id' => data_get($first?->after_snapshot ?: $first?->before_snapshot, 'course_id'),
                'department_id' => $version->department_id,
                'action' => $version->action,
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
                'actor' => $version->actor ? ['id' => $version->actor->id, 'name' => $version->actor->name, 'username' => $version->actor->username, 'role' => $version->actor->role] : null,
                'created_at' => $version->created_at?->toISOString(),
            ];
        })->values();
        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]]);
    }
}
