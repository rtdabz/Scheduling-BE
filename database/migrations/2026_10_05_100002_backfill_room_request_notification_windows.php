<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $windowsByRequest = DB::table('room_request_windows')
            ->orderBy('day')
            ->orderBy('start_time')
            ->get(['room_request_id', 'day', 'start_time', 'end_time'])
            ->groupBy('room_request_id')
            ->map(fn ($rows) => $rows->map(fn ($row): array => [
                'day' => (string) $row->day,
                'start_time' => (string) $row->start_time,
                'end_time' => (string) $row->end_time,
            ])->values()->all());

        DB::table('system_notifications')
            ->where('type', 'like', 'room\_request\_%')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($notifications) use ($windowsByRequest): void {
                foreach ($notifications as $notification) {
                    $metadata = json_decode((string) $notification->metadata, true);
                    if (! is_array($metadata) || isset($metadata['windows'])) {
                        continue;
                    }
                    $windows = $windowsByRequest->get((int) ($metadata['room_request_id'] ?? 0));
                    if (! $windows) {
                        continue;
                    }
                    $metadata['windows'] = $windows;
                    DB::table('system_notifications')
                        ->where('id', $notification->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }

    public function down(): void
    {
    }
};
