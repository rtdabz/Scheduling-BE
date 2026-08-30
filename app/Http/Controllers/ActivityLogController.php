<?php

namespace App\Http\Controllers;

use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
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
            'category' => ['nullable', 'in:authentication,user_management,scheduling,schedule_workflow,faculty_assignment'],
            'event' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'export' => ['nullable', 'in:csv'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;
        $category = $validated['category'] ?? null;
        $search = isset($validated['search']) ? mb_strtolower(trim($validated['search'])) : null;

        // Keep the merge bounded. The two audit tables are intentionally kept
        // separate, so filtering is done in SQL and only a bounded recent window
        // is hydrated before the final cross-source sort.
        $candidateLimit = min(10000, max(500, ($page * $perPage) + $perPage));

        $scheduling = SchedulingAuditLog::query()
            ->with(['user:id,name,username,role', 'recommendation:id,department_id,term_id,section_id'])
            ->when(in_array($category, ['authentication', 'user_management'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($validated['actor_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($validated['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['term_id'] ?? null, fn ($q, $id) => $q->where('term_id', $id))
            ->when($validated['event'] ?? null, fn ($q, $event) => $q->where('action', $event))
            ->when(in_array($category, ['scheduling', 'schedule_workflow', 'faculty_assignment'], true), function ($q) use ($category) {
                if ($category === 'scheduling') {
                    return $q->where('action', 'like', 'recommendation_%');
                }
                if ($category === 'faculty_assignment') {
                    return $q->where('action', 'like', 'instructor_%');
                }
                return $q->where('action', 'not like', 'recommendation_%')
                    ->where('action', 'not like', 'instructor_%');
            })
            ->latest('created_at')->latest('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn (SchedulingAuditLog $log) => $this->schedulingEntry($log));

        $authentication = AuthenticationAuditLog::query()
            ->with(['actor:id,name,username,role,department_id', 'subject:id,name,username,role,department_id'])
            ->when(in_array($category, ['scheduling', 'schedule_workflow', 'faculty_assignment'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when(($validated['term_id'] ?? null) !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($validated['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_user_id', $id))
            ->when($validated['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when(in_array($category, ['authentication', 'user_management'], true), function ($q) use ($category) {
                return $category === 'user_management'
                    ? $q->whereIn('event', ['user_created', 'user_updated', 'user_deleted'])
                    : $q->whereNotIn('event', ['user_created', 'user_updated', 'user_deleted']);
            })
            ->latest('created_at')->latest('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn (AuthenticationAuditLog $log) => $this->authenticationEntry($log));

        $departmentId = $validated['department_id'] ?? null;
        $termId = $validated['term_id'] ?? null;
        $entries = $scheduling->concat($authentication)
            ->filter(fn (array $entry) => (! $category || $entry['category'] === $category)
                && (! $departmentId || (int) $entry['department_id'] === (int) $departmentId)
                && (! $termId || (int) $entry['term_id'] === (int) $termId)
                && (! $search || str_contains(mb_strtolower(json_encode($entry)), $search)))
            ->sortByDesc(fn (array $entry) => $entry['occurred_at']->getTimestamp().'|'.$entry['id'])
            ->values();

        $total = $entries->count();

        if (($validated['export'] ?? null) === 'csv') {
            abort_if($total > 10000, 422, 'Narrow the filters before exporting more than 10,000 audit entries.');
            return response()->streamDownload(function () use ($entries) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Timestamp', 'Category', 'Event', 'Actor', 'Role', 'Department ID', 'Term ID', 'Target', 'Metadata']);
                foreach ($entries as $entry) {
                    fputcsv($output, [
                        $entry['occurred_at']->toISOString(),
                        $entry['category'],
                        $entry['event'],
                        $entry['actor']['name'] ?? 'System',
                        $entry['actor']['role'] ?? 'system',
                        $entry['department_id'],
                        $entry['term_id'],
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
            'occurred_at' => $log->created_at,
            'actor' => $this->user($log->user),
            'department_id' => $log->department_id,
            'term_id' => $log->term_id,
            'target' => ['type' => $log->schedule_recommendation_id ? 'schedule_recommendation' : 'schedule_workflow', 'id' => $log->schedule_recommendation_id ?? $log->section_id],
            'metadata' => $metadata,
        ];
    }

    private function authenticationEntry(AuthenticationAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $subjectSnapshot = $metadata['_subject'] ?? null;
        $isUserManagement = in_array($log->event, ['user_created', 'user_updated', 'user_deleted'], true);
        $actor = $log->actor ?? ($isUserManagement ? null : $log->subject);

        return [
            'id' => 'authentication:'.$log->id,
            'source' => 'authentication',
            'category' => $isUserManagement ? 'user_management' : 'authentication',
            'event' => $log->event,
            'occurred_at' => $log->created_at,
            'actor' => $this->user($actor),
            'department_id' => $log->subject?->department_id ?? $subjectSnapshot['department_id'] ?? null,
            'term_id' => null,
            'target' => ['type' => 'user', 'id' => $log->subject_user_id ?? $subjectSnapshot['id'] ?? null],
            'metadata' => array_filter(array_merge($metadata, ['ip_address' => $log->ip_address, 'user_agent' => $log->user_agent])),
        ];
    }

    private function user($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'role' => $user->role] : null;
    }

    private function schedulingCategory(string $action): string
    {
        return str_starts_with($action, 'recommendation_') ? 'scheduling'
            : (str_starts_with($action, 'instructor_') ? 'faculty_assignment' : 'schedule_workflow');
    }
}
