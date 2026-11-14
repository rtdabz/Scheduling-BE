<?php

use App\Services\SystemNotificationAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_notifications')->orderBy('id')->chunkById(500, function ($notifications): void {
            foreach ($notifications as $notification) {
                $metadata = json_decode((string) $notification->metadata, true);
                $metadata = is_array($metadata) ? $metadata : [];
                if (array_key_exists('department_wide', $metadata)) {
                    continue;
                }
                DB::table('system_notifications')->where('id', $notification->id)->update([
                    'metadata' => json_encode(SystemNotificationAudience::metadata(
                        $notification->type,
                        $notification->department_id === null ? null : (int) $notification->department_id,
                        $metadata,
                    )),
                ]);
            }
        });
    }

    public function down(): void {}
};
