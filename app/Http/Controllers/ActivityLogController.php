<?php

namespace App\Http\Controllers;

use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'in:account_access,institutional_setup,academic_setup,scheduling,approval,instructor_assignment,room_request,reports,authentication,schedule_management,conflict_detection,recommendation,review_approval,user_management,schedule_workflow,faculty_assignment'],
            'event' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'status' => ['nullable', 'in:completed,approved,returned,rejected,failed'],
            'search' => ['nullable', 'string', 'max:100'],
            'export' => ['nullable', 'in:csv'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;
        $category = $validated['category'] ?? null;
        $event = $validated['event'] ?? null;
        $status = $validated['status'] ?? null;
        $search = isset($validated['search']) ? mb_strtolower(trim($validated['search'])) : null;

        // Keep the merge bounded. The two audit tables are intentionally kept
        // separate, so filtering is done in SQL and only a bounded recent window
        // is hydrated before the final cross-source sort.
        $candidateLimit = min(10000, max(500, ($page * $perPage) + $perPage));

        $scheduling = SchedulingAuditLog::query()
            ->with('user:id,name,username,role')
            ->when(in_array($category, ['account_access', 'authentication'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($validated['actor_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($validated['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($event, fn ($q, $ev) => $this->applyEventFilter($q, $ev, 'action'))
            ->latest('created_at')->latest('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn (SchedulingAuditLog $log) => $this->schedulingEntry($log));

        $authentication = AuthenticationAuditLog::query()
            ->with(['actor:id,name,username,role,department_id', 'subject:id,name,username,role,department_id'])
            ->when(in_array($category, ['academic_setup', 'scheduling', 'approval', 'instructor_assignment', 'room_request', 'reports', 'schedule_management', 'conflict_detection', 'recommendation', 'review_approval', 'schedule_workflow', 'faculty_assignment'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when(($validated['semester_id'] ?? null) !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($validated['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($event, fn ($q, $ev) => $this->applyEventFilter($q, $ev, 'event'))
            ->when(in_array($category, ['account_access', 'institutional_setup', 'authentication', 'user_management'], true), function ($q) use ($category) {
                $institutionalEvents = ['department_created', 'department_updated', 'program_created', 'program_updated', 'room_created', 'room_updated', 'instructor_created', 'instructor_updated', 'designation_updated'];
                if ($category === 'account_access' || $category === 'authentication') {
                    return $q->whereNotIn('event', $institutionalEvents);
                }
                if ($category === 'institutional_setup') {
                    return $q->whereIn('event', $institutionalEvents);
                }
                return $q;
            })
            ->latest('created_at')->latest('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn (AuthenticationAuditLog $log) => $this->authenticationEntry($log));

        $departmentId = $validated['department_id'] ?? null;
        $semesterId = $validated['semester_id'] ?? null;
        $entries = $scheduling->concat($authentication)
            ->filter(fn (array $entry) => (! $category || $entry['category'] === $category || $this->categoryMatchesAlias($category, $entry['category'], $entry['event']))
                && (! $event || $entry['event'] === $event || $this->eventMatchesAlias($event, $entry['event']))
                && (! $departmentId || (int) $entry['department_id'] === (int) $departmentId)
                && (! $semesterId || (int) $entry['semester_id'] === (int) $semesterId)
                && (! $status || $entry['status'] === $status)
                && (! $search || str_contains(mb_strtolower(json_encode($entry)), $search)))
            ->sortByDesc(fn (array $entry) => $entry['occurred_at']->getTimestamp().'|'.$entry['id'])
            ->values();

        $total = $entries->count();

        if (($validated['export'] ?? null) === 'csv') {
            abort_if($total > 10000, 422, 'Narrow the filters before exporting more than 10,000 audit entries.');
            return response()->streamDownload(function () use ($entries) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Timestamp', 'Category', 'Event', 'Status', 'Actor', 'Role', 'Department ID', 'Semester ID', 'Target', 'Metadata']);
                foreach ($entries as $entry) {
                    fputcsv($output, [
                        $entry['occurred_at']->toISOString(),
                        $entry['category'],
                        $entry['event'],
                        $entry['status'],
                        $entry['actor']['name'] ?? 'System',
                        $entry['actor']['role'] ?? 'system',
                        $entry['department_id'],
                        $entry['semester_id'],
                        $entry['target']['type'].':'.($entry['target']['id'] ?? ''),
                        json_encode($entry['metadata']),
                    ]);
                }
                fclose($output);
            }, 'vpaa-activity-log-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
        }

        $data = $entries->slice(($page - 1) * $perPage, $perPage)->map(function (array $entry) {
            $entry['occurred_at'] = $entry['occurred_at']->toISOString();
            return $entry;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    private function schedulingEntry(SchedulingAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        if ($log->history_version_id === null) {
            $metadata['legacy_history'] = true;
        }
        return [
            'id' => 'scheduling:'.$log->id,
            'source' => 'scheduling',
            'category' => $this->schedulingCategory($log->action),
            'event' => $log->action,
            'status' => $this->status($log->action),
            'occurred_at' => $log->created_at,
            'actor' => $this->user($log->user),
            'department_id' => $log->department_id,
            'semester_id' => $log->semester_id,
            'target' => ['type' => $log->schedule_recommendation_id ? 'schedule_recommendation' : 'schedule_workflow', 'id' => $log->schedule_recommendation_id ?? $log->section_id],
            'metadata' => $metadata,
        ];
    }

    private function authenticationEntry(AuthenticationAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $subjectSnapshot = $metadata['_subject'] ?? null;
        $isUserManagement = in_array($log->event, ['user_created', 'user_updated', 'user_deactivated', 'user_deleted'], true);
        $isInstitutional = in_array($log->event, ['department_created', 'department_updated', 'program_created', 'program_updated', 'room_created', 'room_updated', 'instructor_created', 'instructor_updated', 'designation_updated'], true);
        $actor = $log->actor ?? (($isUserManagement || $isInstitutional) ? null : $log->subject);

        $category = 'account_access';
        if ($isInstitutional) {
            $category = 'institutional_setup';
        }

        return [
            'id' => 'authentication:'.$log->id,
            'source' => 'authentication',
            'category' => $category,
            'event' => $log->event,
            'status' => $this->status($log->event),
            'occurred_at' => $log->created_at,
            'actor' => $this->user($actor),
            'department_id' => $log->subject?->department_id ?? $subjectSnapshot['department_id'] ?? null,
            'semester_id' => null,
            'target' => ['type' => 'user', 'id' => $log->subject_user_id ?? $subjectSnapshot['id'] ?? null],
            'metadata' => array_filter(array_merge($metadata, ['ip_address' => $log->ip_address, 'user_agent' => $log->user_agent])),
        ];
    }

    private function status(string $event): string
    {
        return match (true) {
            $event === 'login_failed' => 'failed',
            $event === 'schedule_rejected' || $event === 'room_request_rejected' || $event === 'recommendation_rejected' => 'rejected',
            str_starts_with($event, 'schedule_returned') => 'returned',
            str_starts_with($event, 'schedule_approved') || $event === 'room_request_approved' => 'approved',
            default => 'completed',
        };
    }

    private function user($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'role' => $user->role] : null;
    }

    private function schedulingCategory(string $action): string
    {
        if (in_array($action, [
            'instructor_assigned',
            'instructor_reassigned',
            'cross_department_assigned',
            'pro_bono_overridden',
            'instructor_assignment_released',
        ], true)) {
            return 'instructor_assignment';
        }

        if (in_array($action, [
            'room_requested',
            'room_request_approved',
            'room_request_rejected',
        ], true)) {
            return 'room_request';
        }

        if (in_array($action, [
            'schedule_submitted',
            'schedule_reviewed',
            'schedule_returned',
            'schedule_returned_by_dean',
            'schedule_returned_by_vpaa',
            'schedule_approved',
            'schedule_approved_by_dean',
            'schedule_approved_by_vpaa',
            'schedule_rejected',
            'schedule_unlocked',
            'schedule_withdrawn',
        ], true)) {
            return 'approval';
        }

        if (str_ends_with($action, '_report_generated') || str_starts_with($action, 'report_') || str_contains($action, '_report_')) {
            return 'reports';
        }

        if (in_array($action, [
            'curriculum_created',
            'curriculum_updated',
            'course_created',
            'course_updated',
            'section_created',
            'section_updated',
            'semester_created',
            'semester_updated',
            'settings_updated',
            'semester_activated',
            'schedule_semester_archived',
        ], true)) {
            return 'academic_setup';
        }

        if (in_array($action, [
            'department_created',
            'department_updated',
            'program_created',
            'program_updated',
            'room_created',
            'room_updated',
            'instructor_created',
            'instructor_updated',
            'designation_updated',
            'faculty_created',
            'faculty_updated',
        ], true)) {
            return 'institutional_setup';
        }

        return 'scheduling';
    }

    private function categoryMatchesAlias(?string $requestedCategory, string $entryCategory, ?string $entryEvent = null): bool
    {
        if (! $requestedCategory) {
            return true;
        }
        if ($requestedCategory === $entryCategory) {
            return true;
        }

        $legacyToNew = [
            'authentication' => ['account_access'],
            'user_management' => ['account_access', 'institutional_setup'],
            'schedule_management' => ['scheduling', 'academic_setup'],
            'conflict_detection' => ['scheduling'],
            'recommendation' => ['scheduling'],
            'review_approval' => ['approval'],
            'schedule_workflow' => ['approval'],
            'faculty_assignment' => ['instructor_assignment'],
            // Reversed mapping:
            'account_access' => ['authentication', 'user_management'],
            'institutional_setup' => ['user_management'],
            'academic_setup' => ['schedule_management'],
            'scheduling' => ['schedule_management', 'conflict_detection', 'recommendation'],
            'approval' => ['review_approval', 'schedule_workflow'],
            'instructor_assignment' => ['faculty_assignment'],
        ];

        return in_array($entryCategory, $legacyToNew[$requestedCategory] ?? [], true);
    }

    private function applyEventFilter(Builder $query, string $event, string $column = 'action'): Builder
    {
        $aliases = [
            'schedule_deleted' => ['schedule_deleted', 'schedule_batch_deleted'],
            'recommendation_generated' => ['recommendation_generated', 'schedule_auto_generated'],
            'conflict_recommendation_viewed' => ['conflict_recommendation_viewed', 'recommendation_reviewed'],
            'recommendation_accepted' => ['recommendation_accepted', 'recommendation_applied'],
            'schedule_returned' => ['schedule_returned', 'schedule_returned_by_dean', 'schedule_returned_by_vpaa'],
            'schedule_approved' => ['schedule_approved', 'schedule_approved_by_dean', 'schedule_approved_by_vpaa'],
            'schedule_override' => ['schedule_override', 'max_units_overridden'],
        ];

        if (isset($aliases[$event])) {
            return $query->whereIn($column, $aliases[$event]);
        }

        return $query->where($column, $event);
    }

    private function eventMatchesAlias(?string $filterEvent, string $entryEvent): bool
    {
        if (! $filterEvent) {
            return true;
        }
        if ($filterEvent === $entryEvent) {
            return true;
        }

        $aliases = [
            'schedule_deleted' => ['schedule_deleted', 'schedule_batch_deleted'],
            'recommendation_generated' => ['recommendation_generated', 'schedule_auto_generated'],
            'conflict_recommendation_viewed' => ['conflict_recommendation_viewed', 'recommendation_reviewed'],
            'recommendation_accepted' => ['recommendation_accepted', 'recommendation_applied'],
            'schedule_returned' => ['schedule_returned', 'schedule_returned_by_dean', 'schedule_returned_by_vpaa'],
            'schedule_approved' => ['schedule_approved', 'schedule_approved_by_dean', 'schedule_approved_by_vpaa'],
            'schedule_override' => ['schedule_override', 'max_units_overridden'],
        ];

        return in_array($entryEvent, $aliases[$filterEvent] ?? [], true);
    }
}
