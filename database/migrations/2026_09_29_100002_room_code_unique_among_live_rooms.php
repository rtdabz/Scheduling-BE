<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX `rooms_room_code_unique`');
            DB::statement('CREATE UNIQUE INDEX `rooms_live_room_code_unique` ON `rooms` (`room_code`) WHERE `deleted_at` IS NULL');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `rooms` DROP INDEX `rooms_room_code_unique`');
        DB::statement(
            'ALTER TABLE `rooms` ADD COLUMN `live_room_code` VARCHAR(255) '
            .'GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `room_code`, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `rooms` ADD UNIQUE KEY `rooms_live_room_code_unique` (`live_room_code`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX `rooms_live_room_code_unique`');
            DB::statement('CREATE UNIQUE INDEX `rooms_room_code_unique` ON `rooms` (`room_code`)');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `rooms` DROP INDEX `rooms_live_room_code_unique`');
        DB::statement('ALTER TABLE `rooms` DROP COLUMN `live_room_code`');
        DB::statement('ALTER TABLE `rooms` ADD UNIQUE KEY `rooms_room_code_unique` (`room_code`)');
    }
};
