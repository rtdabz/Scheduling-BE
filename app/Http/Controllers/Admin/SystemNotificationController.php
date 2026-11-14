<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use App\Support\ActorIdentity;
use App\Support\LiveUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 10), 1), 50);

        $notifications = SystemNotification::query()
            ->with(['department:id,department_name,department_code', 'academicSemester:id,semester,academic_year'])
            ->visibleTo($request->user())
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->each(fn (SystemNotification $notification) => $notification->setAttribute('actor', ActorIdentity::recorded($notification->metadata, $notification->actor_id)));

        return response()->json([
            'data' => $notifications,
            'unread_count' => SystemNotification::query()
                ->visibleTo($request->user())
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markAsRead(Request $request, SystemNotification $notification): JsonResponse
    {
        if (! SystemNotification::query()->visibleTo($request->user())->whereKey($notification->id)->exists()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
            app(LiveUpdates::class)->notifyUser((int) $request->user()->id);
        }

        return response()->json($notification->fresh());
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        SystemNotification::query()
            ->visibleTo($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        app(LiveUpdates::class)->notifyUser((int) $request->user()->id);

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
