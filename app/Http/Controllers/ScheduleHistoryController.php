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
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
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
            ->with(['actor:id,name,username,role', 'department:id,department_name,department_code', 'items.section:id,section_name', 'items.course:id,course_code,course_name,course_category,units,lecture_hours,lab_hours'])
            ->when($user?->role !== 'vpaa', fn ($q) => $q->where('department_id', $user->department_id))
            ->when($requestedDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['term_id'] ?? null, fn ($q, $id) => $q->where('term_id', $id))
            ->when($validated['schedule_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($items) => $items->where('original_schedule_id', $id)))
            ->latest('created_at')->latest('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $data = collect($paginator->items())->map(function (ScheduleHistoryVersion $version): array {
            $items = $version->items;
            $first = $items->first();
            $metadata = $version->change_summary ?? [];
            $departmentName = $version->department?->department_name ?: 'Department';
            $sectionIds = $items->pluck('section_id')->filter()->unique()->values();
            $scope = $metadata['history_scope'] ?? ($sectionIds->count() >= 2 ? 'multiple_sections' : 'section');
            $label = $scope === 'entire_schedule' ? $departmentName.' Schedule' : ($scope === 'multiple_sections' ? $departmentName.' Department Schedule' : ($first?->section?->section_name ? $departmentName.' · '.$first->section->section_name : $departmentName.' Schedule'));
            return [
                'id' => $version->id,
                'group_id' => $metadata['history_group_id'] ?? null,
                'schedule_id' => $first?->original_schedule_id,
                'schedule_count' => $items->count(),
                'section_count' => $sectionIds->count(),
                'schedule_label' => $label,
                'term_id' => $version->term_id,
                'section_id' => $first?->section_id,
                'course_id' => $first?->course_id,
                'department_id' => $version->department_id,
                'action' => $version->action,
                'snapshot' => $first?->after_snapshot ?: $first?->before_snapshot,
                'snapshots' => $items->map(fn ($item) => [
                    'id' => $item->id,
                    'schedule_id' => $item->original_schedule_id,
                    'section_id' => $item->section_id,
                    'section_name' => $item->section?->section_name,
                    'course_code' => $item->course?->course_code,
                    'course_name' => $item->course?->course_name,
                    'course_category' => $item->course?->course_category,
                    'units' => $item->course?->units,
                    'lecture_hours' => $item->course?->lecture_hours,
                    'lab_hours' => $item->course?->lab_hours,
                    'snapshot' => $item->after_snapshot ?: $item->before_snapshot,
                ])->values()->all(),
                'actor' => $version->actor ? ['id' => $version->actor->id, 'name' => $version->actor->name, 'username' => $version->actor->username, 'role' => $version->actor->role] : null,
                'created_at' => $version->created_at?->toISOString(),
            ];
        })->values();
        return response()->json(['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $paginator->total(), 'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]]);
    }
}
