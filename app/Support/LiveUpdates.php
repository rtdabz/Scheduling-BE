<?php

namespace App\Support;

use App\Events\LiveDataChanged;
use App\Events\UserNotificationsChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects what a request (or queued job) changed and announces it once.
 *
 * Writes only record topic names here; nothing is sent until flush() runs at
 * the end of the request or job. A generation run that saves hundreds of
 * meetings therefore still produces a single small broadcast, and the payload
 * carries topic names only -- clients refetch through the normal, authorised
 * API, so a broadcast can never leak another department's records.
 *
 * Topics mirror the frontend's cache groups (wicars-ui/src/lib/cacheGroups.ts).
 */
class LiveUpdates
{
    public const TOPICS = [
        'schedules', 'approvals', 'assignments', 'sections', 'rooms', 'faculty',
        'courses', 'curriculum', 'departments', 'users', 'settings',
    ];

    /**
     * Backend ApiCache groups -> topics. Writes made through the query builder
     * fire no model events but do bump these groups, so both sources are used.
     */
    private const CACHE_GROUP_TOPICS = [
        'initial.data' => ['schedules'],
        'initial.data.schedules' => ['schedules'],
        'initial.data.schedule_submissions' => ['approvals'],
        'initial.data.users' => ['users'],
        'initial.data.departments' => ['departments'],
        'initial.data.faculties' => ['faculty'],
        'courses.index' => ['courses'],
        'curriculum.index' => ['curriculum'],
        'departments.index' => ['departments'],
        'faculty.index' => ['faculty'],
        'faculties.index' => ['faculty'],
        'instructor_assignments.index' => ['assignments'],
        'rooms.index' => ['rooms'],
        'sections.index' => ['sections'],
        'semesters.active' => ['settings'],
        'semesters.index' => ['settings'],
        'institution.settings' => ['settings'],
    ];

    /** Skip broadcasting for this long after the WebSocket server is unreachable. */
    private const OUTAGE_BACKOFF_SECONDS = 30;

    private const OUTAGE_CACHE_KEY = 'live-updates:unreachable';

    /** @var array<string, true> */
    private array $topics = [];

    /** @var array<int, true> */
    private array $notifiedUsers = [];

    private bool $paused = false;

    public function touch(string ...$topics): void
    {
        if ($this->paused) {
            return;
        }

        foreach ($topics as $topic) {
            $this->topics[$topic] = true;
        }
    }

    public function touchCacheGroup(string $group): void
    {
        $this->touch(...(self::CACHE_GROUP_TOPICS[$group] ?? []));
    }

    public function notifyUser(int $userId): void
    {
        if (! $this->paused && $userId > 0) {
            $this->notifiedUsers[$userId] = true;
        }
    }

    public function hasPending(): bool
    {
        return $this->topics !== [] || $this->notifiedUsers !== [];
    }

    /**
     * Broadcast everything recorded so far, then reset.
     *
     * Never throws: a missing or stopped WebSocket server must not turn a
     * successful write into a failed request. Clients reconcile on reconnect.
     */
    public function flush(?string $exceptSocketId = null): void
    {
        if (! $this->hasPending()) {
            return;
        }

        $topics = array_keys($this->topics);
        $userIds = array_keys($this->notifiedUsers);
        $this->topics = [];
        $this->notifiedUsers = [];

        $connection = (string) config('broadcasting.default');
        if ($connection === '' || $connection === 'null' || Cache::has(self::OUTAGE_CACHE_KEY)) {
            return;
        }

        try {
            if ($topics !== []) {
                $event = new LiveDataChanged($topics);
                $event->socket = $exceptSocketId;
                event($event);
            }

            foreach ($userIds as $userId) {
                event(new UserNotificationsChanged($userId));
            }
        } catch (Throwable $e) {
            Cache::put(self::OUTAGE_CACHE_KEY, true, self::OUTAGE_BACKOFF_SECONDS);
            Log::notice('live_updates_unavailable', ['error' => $e->getMessage()]);
        }
    }

    /** Run $callback without recording anything (seeders, bulk maintenance). */
    public function withoutRecording(callable $callback): mixed
    {
        $previous = $this->paused;
        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $previous;
        }
    }
}
