<?php

namespace App\Http\Controllers;

use App\Models\ScheduleHistory;
use App\Models\Faculty;
use App\Models\Rooms;
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

        $query = ScheduleHistory::query()
            ->with(['actor:id,name,username,role', 'department:id,department_name,department_code', 'section:id,section_name', 'course:id,course_code,course_name,course_category,units,lecture_hours,lab_hours'])
            ->when($user?->role !== 'vpaa', fn ($q) => $q->where('department_id', $user->department_id))
            ->when($requestedDepartment, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['term_id'] ?? null, fn ($q, $id) => $q->where('term_id', $id))
            ->when($validated['schedule_id'] ?? null, fn ($q, $id) => $q->where('schedule_id', $id))
            ->latest('created_at')->latest('id');

        // Paginate before hydrating snapshots and relationships. The previous
        // implementation loaded the complete history table, then sliced it in
        // PHP, which made memory and response time grow with all historical rows.
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $items = collect($paginator->items());
        $facultyNames = Faculty::query()
            ->whereIn('id', $items->map(fn (ScheduleHistory $item) => data_get($item->snapshot, 'faculty_id'))->filter()->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Faculty $faculty) => [$faculty->id => trim($faculty->first_name.' '.$faculty->last_name)]);
        $roomNames = Rooms::query()
            ->whereIn('id', $items->map(fn (ScheduleHistory $item) => data_get($item->snapshot, 'room_id'))->filter()->unique())
            ->pluck('room_code', 'id');
        $grouped = $items->groupBy(function (ScheduleHistory $item): string {
            $groupId = data_get($item->changes, 'history_group_id');
            if ($groupId) {
                return 'workflow:'.$groupId;
            }

            // History rows created before workflow group IDs were introduced
            // can still be safely grouped by the same bulk action timestamp.
            if (! in_array($item->action, ['created', 'updated', 'deleted'], true)) {
                return implode(':', [
                    'legacy-workflow', $item->action, $item->actor_user_id,
                    $item->department_id, $item->term_id,
                    $item->created_at?->format('Y-m-d H:i:s'),
                ]);
            }

            return 'history:'.$item->id;
        })->map(function ($group) use ($facultyNames, $roomNames): array {
            /** @var ScheduleHistory $first */
            $first = $group->sortByDesc('created_at')->first();
            $sectionIds = $group->pluck('section_id')->filter()->unique()->values()->all();
            $metadata = $first->changes ?? [];
            $departmentName = $first->department?->department_name ?: 'Department';
            $scope = $metadata['history_scope'] ?? (count($sectionIds) >= 2 ? 'multiple_sections' : 'section');
            $label = match ($scope) {
                'entire_schedule' => $departmentName.' Schedule',
                'multiple_sections' => $departmentName.' Department Schedule',
                default => $first->section?->section_name
                    ? $departmentName.' · '.$first->section->section_name
                    : $departmentName.' Schedule',
            };

            return [
                'id' => $first->id,
                'group_id' => data_get($metadata, 'history_group_id'),
                'schedule_id' => $first->schedule_id,
                'schedule_count' => $group->count(),
                'section_count' => count($sectionIds),
                'schedule_label' => $label,
                'term_id' => $first->term_id,
                'section_id' => $first->section_id,
                'course_id' => $first->course_id,
                'department_id' => $first->department_id,
                'action' => $first->action,
                'snapshot' => $first->snapshot,
                'snapshots' => $group->values()->map(fn (ScheduleHistory $item) => [
                    'id' => $item->id,
                    'schedule_id' => $item->schedule_id,
                    'section_id' => $item->section_id,
                    'section_name' => $item->section?->section_name,
                    'course_code' => $item->course?->course_code,
                    'course_name' => $item->course?->course_name,
                    'course_category' => $item->course?->course_category,
                    'units' => $item->course?->units,
                    'lecture_hours' => $item->course?->lecture_hours,
                    'lab_hours' => $item->course?->lab_hours,
                    'faculty_name' => $facultyNames->get(data_get($item->snapshot, 'faculty_id')),
                    'room_name' => $roomNames->get(data_get($item->snapshot, 'room_id')),
                    'snapshot' => $item->snapshot,
                ])->all(),
                'actor' => $first->actor ? ['id' => $first->actor->id, 'name' => $first->actor->name, 'username' => $first->actor->username, 'role' => $first->actor->role] : null,
                'created_at' => $first->created_at?->toISOString(),
            ];
        })->sortByDesc('created_at')->values();
        $data = $grouped->values();
        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
