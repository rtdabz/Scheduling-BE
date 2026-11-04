<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SystemNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers in-app notifications for an action that has already been committed.
 *
 * The action's response never depends on this job. Each attempt writes all of its
 * notifications in one transaction, so a failed attempt leaves nothing behind and a
 * retry cannot duplicate them. Exhausted attempts land in `failed_jobs`, where
 * `php artisan queue:retry` redelivers them.
 */
class DeliverSystemNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  list<array{user_ids: list<int>, type: string, title: string, message: string, actor_id: ?int, department_id: ?int, semester_id: ?int, remarks: ?string, metadata: array<string, mixed>}>  $deliveries
     */
    public function __construct(public readonly array $deliveries) {}

    public function handle(SystemNotificationService $notifications): void
    {
        DB::transaction(function () use ($notifications): void {
            foreach ($this->deliveries as $delivery) {
                $notifications->createForUsers(
                    User::query()->whereIn('id', $delivery['user_ids'])->get(),
                    $delivery['type'],
                    $delivery['title'],
                    $delivery['message'],
                    $delivery['actor_id'] === null ? null : User::query()->find($delivery['actor_id']),
                    $delivery['department_id'],
                    $delivery['semester_id'],
                    $delivery['remarks'],
                    $delivery['metadata'],
                );
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('System notifications could not be delivered; retry them from failed_jobs.', [
            'types' => array_values(array_unique(array_column($this->deliveries, 'type'))),
            'exception' => $exception->getMessage(),
        ]);
    }
}
