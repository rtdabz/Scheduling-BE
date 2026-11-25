<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Room requests follow program ownership: a Program Head requests rooms for their own program, and
 * the department office for programs without a Program Head (or for the department as a whole,
 * `requesting_program_id` null). Lending decisions stay with the room-owning department's office,
 * and grants stay department-wide. Reverses 2026_10_04_100001_remove_program_head_room_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->preservingSqliteTriggers(function (): void {
            Schema::table('room_requests', function (Blueprint $table): void {
                $table->foreignId('requesting_program_id')->nullable()->after('requesting_department_id')
                    ->constrained('programs')->nullOnDelete();
            });
        });

        $permission = Permission::query()->firstOrCreate(['name' => 'room.request', 'guard_name' => 'api']);
        Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first()
            ?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where(['name' => 'program_head', 'guard_name' => 'api'])->first()
            ?->revokePermissionTo('room.request');
        User::query()->where('role', 'program_head')->each(function (User $user): void {
            if ($user->hasDirectPermission('room.request')) {
                $user->revokePermissionTo('room.request');
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->preservingSqliteTriggers(function (): void {
            Schema::table('room_requests', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('requesting_program_id');
            });
        });
    }

    /**
     * SQLite rebuilds the table to change its foreign keys, which drops its triggers, including
     * the guards that keep recorded approval decisions (2026_11_14_000000). Restore them after.
     */
    private function preservingSqliteTriggers(callable $change): void
    {
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'room_requests')->get(['name', 'sql'])
            : collect();

        $change();

        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
