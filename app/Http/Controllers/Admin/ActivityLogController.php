<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    private const ARCHIVE_EVENTS = ['record_restored', 'record_archived'];

    /** Roles as recorded at event time, plus the two non-account identities. */
    private const ROLE_FILTERS = ['vpaa', 'dean', 'secretary', 'director', 'program_head', 'system', 'unavailable'];

    private const MAX_ACTOR_FACETS = 500;

    /** Archive type => [activity target type, activity category]. */
    private const ARCHIVE_TARGETS = [
        'users' => ['user', 'account_access'],
        'departments' => ['department', 'institutional_setup'],
        'programs' => ['program', 'institutional_setup'],
        'rooms' => ['room', 'institutional_setup'],
        'faculties' => ['instructor', 'institutional_setup'],
        'designations' => ['designation', 'institutional_setup'],
        'courses' => ['course', 'academic_setup'],
        'semesters' => ['semester', 'academic_setup'],
        'schedules' => ['schedule', 'scheduling'],
        'schedule-splits' => ['schedule_split', 'scheduling'],
    ];

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'in:account_access,institutional_setup,academic_setup,scheduling,approval,instructor_assignment,room_request,reports,authentication,schedule_management,conflict_detection,recommendation,review_approval,user_management,schedule_workflow,faculty_assignment'],
            'event' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'status' => ['nullable', 'in:completed,approved,returned,rejected,failed'],
            'role' => ['nullable', 'in:'.implode(',', self::ROLE_FILTERS)],
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

        // Scan every matching source in bounded chunks; never truncate before normalization/filtering.
        $scheduling = SchedulingAuditLog::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            // actor_id is applied in the loop below so the actor facet lists every identity under the other filters.
            ->when($validated['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($validated['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($event, fn ($q, $ev) => $this->applyEventFilter($q, $ev, 'action'))
            ->lazyById(500)
            ->map(fn (SchedulingAuditLog $log) => $this->schedulingEntry($log));

        $authentication = AuthenticationAuditLog::query()
            ->when(($validated['semester_id'] ?? null) !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($event, fn ($q, $ev) => $this->applyEventFilter($q, $ev, 'event'))
            ->lazyById(500)
            ->map(fn (AuthenticationAuditLog $log) => $this->authenticationEntry($log));

        $departmentId = $validated['department_id'] ?? null;
        $semesterId = $validated['semester_id'] ?? null;
        $actorId = $validated['actor_id'] ?? null;
        $role = $validated['role'] ?? null;
        $export = ($validated['export'] ?? null) === 'csv';
        $keep = $export ? 10001 : $page * $perPage;
        $latest = new \SplMinHeap;
        $total = 0;
        $summary = ['approved' => 0, 'returned' => 0, 'rejected' => 0, 'failed' => 0];
        $actorsInResult = [];
        $actorFacets = [];
        foreach ($scheduling->concat($authentication) as $entry) {
            if (($category && ! $this->categoryMatchesAlias($category, $entry['category'], $entry['event']))
                || ($event && ! $this->eventMatchesAlias($event, $entry['event']))
                || ($departmentId && (int) $entry['department_id'] !== (int) $departmentId)
                || ($semesterId && (int) $entry['semester_id'] !== (int) $semesterId)
                || ($status && $entry['status'] !== $status)
                || ($search && ! str_contains($this->searchText($entry), $search))) {
                continue;
            }

            $entryActorId = isset($entry['actor']['id']) ? (int) $entry['actor']['id'] : null;
            if ($entryActorId !== null && (isset($actorFacets[$entryActorId]) || count($actorFacets) < self::MAX_ACTOR_FACETS)) {
                // Recorded identities only: an unavailable snapshot stays null instead of borrowing the live account.
                $previous = $actorFacets[$entryActorId] ?? [];
                $actorFacets[$entryActorId] = ['id' => $entryActorId] + array_map(
                    fn (string $field) => $entry['actor'][$field] ?? $previous[$field] ?? null,
                    ['name' => 'name', 'username' => 'username', 'role' => 'role'],
                );
            }
            if (($actorId && $entryActorId !== (int) $actorId)
                || ($role && $this->recordedRole($entry) !== $role)) {
                continue;
            }

            $total++;
            if (isset($summary[$entry['status']])) {
                $summary[$entry['status']]++;
            }
            if ($entryActorId !== null) {
                $actorsInResult[$entryActorId] = true;
            }
            $latest->insert([$entry['occurred_at']->format('U.u'), $entry['source'], (int) substr(strrchr($entry['id'], ':'), 1), $entry]);
            if ($latest->count() > $keep) {
                $latest->extract();
            }
        }
        $entries = collect(iterator_to_array($latest, false))->reverse()->map(fn (array $row) => $row[3])->values();

        if (($validated['export'] ?? null) === 'csv') {
            abort_if($total > 10000, 422, 'Narrow the filters before exporting more than 10,000 audit entries.');

            $departmentCodes = DB::table('departments')->pluck('department_code', 'id');

            return response()->streamDownload(function () use ($entries, $departmentCodes) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Entry ID', 'Timestamp', 'Category', 'Event', 'Status', 'Actor', 'Username', 'Recorded Role', 'Identity',
                    'Department ID', 'Department Code', 'Semester ID', 'Target', 'Metadata']);
                foreach ($entries as $entry) {
                    $actor = $entry['actor'];
                    fputcsv($output, array_map([$this, 'csvCell'], [
                        $entry['id'],
                        $entry['occurred_at']->toISOString(),
                        $entry['category'],
                        $entry['event'],
                        $entry['status'],
                        $actor['name'] ?? ($entry['actor_type'] === 'system' ? 'System' : ($actor ? 'Actor unavailable (user #'.$actor['id'].')' : 'Actor unavailable')),
                        $actor['username'] ?? '',
                        $this->recordedRole($entry),
                        $actor['provenance'] ?? ($entry['actor_type'] === 'system' ? 'system' : 'unavailable'),
                        $entry['department_id'],
                        $entry['department_id'] ? ($departmentCodes[$entry['department_id']] ?? '') : '',
                        $entry['semester_id'],
                        $entry['target']['type'].':'.($entry['target']['id'] ?? ''),
                        json_encode($entry['metadata'], JSON_UNESCAPED_UNICODE),
                    ]));
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
                'summary' => $summary + ['total' => $total, 'actors' => count($actorsInResult)],
                'facets' => [
                    'actors' => collect($actorFacets)->sortBy(fn (array $actor) => mb_strtolower($actor['name'] ?? "\u{FFFF}{$actor['id']}"))->values(),
                ],
            ],
        ]);
    }

    /** Role recorded at event time; never the account's current role. */
    private function recordedRole(array $entry): string
    {
        if (! empty($entry['actor']['role'])) {
            return (string) $entry['actor']['role'];
        }

        return $entry['actor_type'] === 'system' ? 'system' : 'unavailable';
    }

    /** Lower-cased haystack that also matches readable event/category names ("schedule approved"). */
    private function searchText(array $entry): string
    {
        return mb_strtolower(json_encode($entry, JSON_UNESCAPED_UNICODE)
            .' '.str_replace('_', ' ', $entry['event'].' '.$entry['category'].' '.$entry['target']['type']));
    }

    /** Neutralise spreadsheet formula injection without altering the recorded value beyond a leading quote. */
    private function csvCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function schedulingEntry(SchedulingAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $isSemesterTransition = in_array($log->action, ['semester_activated', 'semester_ended'], true);
        if ($log->history_version_id === null && ! $isSemesterTransition) {
            $metadata['legacy_history'] = true;
        }

        return [
            'id' => 'scheduling:'.$log->id,
            'source' => 'scheduling',
            'category' => $this->archiveCategory($log->action, $metadata) ?? $this->schedulingCategory($log->action),
            'event' => $log->action,
            'status' => $this->status($log->action),
            'occurred_at' => $log->created_at,
            'actor' => $log->recorded_actor,
            'actor_type' => $metadata['_actor_type'] ?? 'unknown',
            'department_id' => $log->department_id,
            'semester_id' => $log->semester_id,
            'target' => ($metadata['target'] ?? null) ?? $this->archiveTarget($log->action, $metadata)
                ?? ($isSemesterTransition
                    ? ['type' => 'semester', 'id' => $log->action === 'semester_activated' ? ($metadata['activated_semester_id'] ?? $log->semester_id) : $log->semester_id]
                    : ['type' => $log->schedule_recommendation_id ? 'schedule_recommendation' : 'schedule_workflow', 'id' => $log->schedule_recommendation_id ?? $log->section_id]),
            'metadata' => $metadata,
        ];
    }

    /** @return array{type: string, id: ?int}|null */
    private function archiveTarget(string $action, array $metadata): ?array
    {
        $target = in_array($action, self::ARCHIVE_EVENTS, true) ? (self::ARCHIVE_TARGETS[$metadata['type'] ?? ''] ?? null) : null;

        return $target === null ? null : ['type' => $target[0], 'id' => isset($metadata['id']) ? (int) $metadata['id'] : null];
    }

    private function archiveCategory(string $action, array $metadata): ?string
    {
        return in_array($action, self::ARCHIVE_EVENTS, true) ? (self::ARCHIVE_TARGETS[$metadata['type'] ?? ''][1] ?? null) : null;
    }

    private function authenticationEntry(AuthenticationAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $subjectSnapshot = $metadata['_subject'] ?? null;
        $isInstitutional = in_array($log->event, ['department_created', 'department_updated', 'program_created', 'program_updated', 'room_created', 'room_updated', 'instructor_created', 'instructor_updated', 'designation_updated'], true);
        $actor = $log->recorded_actor;

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
            'actor' => $actor,
            'actor_type' => $metadata['_actor_type'] ?? ($actor ? 'user' : 'unknown'),
            'department_id' => $subjectSnapshot['department_id'] ?? null,
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
            'room_request_submitted',
            'room_request_cancelled',
            'room_request_revoked',
            'room_grant_ended',
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
            'institution_signatories_updated',
            'semester_activated',
            'semester_ended',
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
