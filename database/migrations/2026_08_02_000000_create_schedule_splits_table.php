<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')
                ->constrained('schedules')
                ->onDelete('cascade')
                ->unique();
            $table->uuid('split_group_id')->nullable()->index();
            $table->enum('meeting_type', ['lecture', 'laboratory'])->nullable();
            $table->unsignedTinyInteger('meeting_index')->default(1);
            $table->timestamps();
        });

        if (Schema::hasColumn('schedules', 'split_group_id')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropColumn(['split_group_id', 'meeting_type', 'meeting_index']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->uuid('split_group_id')->nullable()->after('preferred_pattern')->index();
            $table->enum('meeting_type', ['lecture', 'laboratory'])->nullable()->after('split_group_id');
            $table->unsignedTinyInteger('meeting_index')->default(1)->after('meeting_type');
        });

        Schema::dropIfExists('schedule_splits');
    }
};
