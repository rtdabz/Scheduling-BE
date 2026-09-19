<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online and field classes are no longer capped.
 *
 * Neither is a room: an online class occupies no space, and the field is open
 * ground any number of sections can share. The department limits were already
 * unlimited by default (2026_09_10_000001); they are now removed outright, along
 * with each room's max_concurrent_classes, which only ever mattered for field
 * and online rooms -- a lecture or laboratory room always holds one class.
 *
 * field_evening_schedule_enabled goes too: the institution-wide field end time
 * (schedule_settings.field_end_time) replaces it. Set it to the closing time to
 * allow evening field classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['online_slot_limit', 'field_slot_limit', 'field_evening_schedule_enabled']);
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('max_concurrent_classes');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('online_slot_limit')->nullable()->default(null);
            $table->unsignedSmallInteger('field_slot_limit')->nullable()->default(null);
            $table->boolean('field_evening_schedule_enabled')->default(false);
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_concurrent_classes')->default(1)->after('status');
        });
    }
};
