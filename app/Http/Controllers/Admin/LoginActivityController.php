<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthenticationAuditLog;
use App\Models\LoginGeolocation;
use App\Models\User;
use App\Services\AuthenticationAuditService;
use App\Support\UserAgentSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Login Locations and Login History for the Activity Log. Reads the existing authentication
 * audit trail; the only extra data is the prunable location (optional device fix + IP estimate).
 */
class LoginActivityController extends Controller
{
    private const MAX_MAP_POINTS = 2000;

    private const ROLES = ['vpaa', 'dean', 'secretary', 'director', 'program_head'];

    public function locations(Request $request): JsonResponse
    {
        $filtered = $this->filteredQuery($this->validated($request));

        $located = (clone $filtered)
            ->whereHas('geolocation', $this->mappable(...))
            ->with('geolocation')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::MAX_MAP_POINTS + 1)
            ->get();

        $total = (clone $filtered)->count();
        $truncated = $located->count() > self::MAX_MAP_POINTS;
        $points = $located->take(self::MAX_MAP_POINTS);

        return response()->json([
            'data' => $points->map(fn (AuthenticationAuditLog $log) => $this->entry($log))->values(),
            'meta' => [
                'total' => $total,
                'located' => (clone $filtered)->whereHas('geolocation', $this->mappable(...))->count(),
                'truncated' => $truncated,
                'max_points' => self::MAX_MAP_POINTS,
            ] + $this->sharedMeta($filtered),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $this->validated($request, history: true);
        $filtered = $this->filteredQuery($validated);
        $perPage = (int) ($validated['per_page'] ?? 25);

        $page = (clone $filtered)->with('geolocation')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuthenticationAuditLog $log) => $this->entry($log))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ] + $this->sharedMeta($filtered),
        ]);
    }

    /** A single sign-in attempt for the VPAA Login Details page. */
    public function show(int $id): JsonResponse
    {
        $log = AuthenticationAuditLog::query()
            ->whereIn('event', AuthenticationAuditService::LOCATED_EVENTS)
            ->with('geolocation')
            ->findOrFail($id);

        return response()->json([
            'data' => $this->entry($log),
            'meta' => ['retention_days' => LoginGeolocation::retentionDays()],
        ]);
    }

    /** A sign-in can be pinned when it has a device fix or a resolved IP estimate. */
    private function mappable(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('lookup_status', LoginGeolocation::RESOLVED)->orWhereNotNull('device_latitude'));
    }

    private function validated(Request $request, bool $history = false): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', 'in:'.implode(',', self::ROLES)],
            'status' => ['nullable', 'in:succeeded,failed'],
        ] + ($history ? [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ] : []));
    }

    private function filteredQuery(array $filters): Builder
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
        $status = $filters['status'] ?? null;

        return AuthenticationAuditLog::query()
            ->whereIn('event', $status ? [$status === 'failed' ? 'login_failed' : 'login_succeeded'] : AuthenticationAuditService::LOCATED_EVENTS)
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('created_at', '<=', $to))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('subject_user_id', $id))
            // Role as recorded at sign-in time, never the account's current role.
            ->when($filters['role'] ?? null, fn (Builder $q, $role) => $q->where('metadata->_subject->role', $role));
    }

    private function sharedMeta(Builder $filtered): array
    {
        $counts = (clone $filtered)->toBase()
            ->selectRaw("SUM(CASE WHEN event = 'login_failed' THEN 1 ELSE 0 END) as failed, COUNT(DISTINCT subject_user_id) as users")
            ->first();

        $userIds = AuthenticationAuditLog::query()
            ->whereIn('event', AuthenticationAuditService::LOCATED_EVENTS)
            ->whereNotNull('subject_user_id')
            ->distinct()->limit(500)->pluck('subject_user_id');

        return [
            'summary' => ['failed' => (int) ($counts->failed ?? 0), 'users' => (int) ($counts->users ?? 0)],
            'retention_days' => LoginGeolocation::retentionDays(),
            'facets' => [
                'users' => User::withTrashed()->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name', 'username', 'role'])
                    ->map(fn (User $user) => $user->only(['id', 'name', 'username', 'role']))->values(),
            ],
        ];
    }

    private function entry(AuthenticationAuditLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $subject = $metadata['_subject'] ?? null;
        $geo = $log->geolocation;

        return [
            'id' => $log->id,
            'status' => $log->event === 'login_failed' ? 'failed' : 'succeeded',
            'reason' => $metadata['reason'] ?? null,
            'method' => $metadata['method'] ?? null,
            'occurred_at' => $log->created_at?->toISOString(),
            // Event-time identity snapshot; null for attempts against an unknown account.
            'user' => is_array($subject) ? [
                'id' => $subject['id'] ?? $log->subject_user_id,
                'name' => $subject['name'] ?? null,
                'username' => $subject['username'] ?? null,
                'role' => $subject['role'] ?? null,
            ] : null,
            'ip_address' => $log->ip_address,
            'client' => UserAgentSummary::parse($log->user_agent),
            'location' => [
                'status' => $geo?->lookup_status
                    ?? ($log->created_at && $log->created_at->lt(now()->subDays(LoginGeolocation::retentionDays())) ? 'expired' : 'pending'),
                'city' => $geo?->city,
                'region' => $geo?->region,
                'country' => $geo?->country,
                'country_code' => $geo?->country_code,
                'latitude' => $geo?->latitude,
                'longitude' => $geo?->longitude,
                'is_proxy' => $geo?->is_proxy,
                // device = browser Geolocation fix (precise); ip = network estimate (city-level); none = nothing to map.
                'source' => $geo?->source() ?? LoginGeolocation::SOURCE_NONE,
                'device' => $geo?->hasDeviceLocation() ? [
                    'latitude' => $geo->device_latitude,
                    'longitude' => $geo->device_longitude,
                    'accuracy_m' => $geo->device_accuracy_m,
                    'captured_at' => $geo->device_captured_at?->toISOString(),
                ] : null,
            ],
        ];
    }
}
