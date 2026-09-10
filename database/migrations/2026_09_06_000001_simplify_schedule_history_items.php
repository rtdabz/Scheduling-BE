<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REDUNDANT_COLUMNS = ['section_id', 'course_id', 'faculty_id', 'room_id'];

    public function up(): void
    {
        if (! Schema::hasTable('schedule_history_items')) {
            return;
        }

        Schema::table('schedule_history_items', function (Blueprint $table): void {
            foreach (self::REDUNDANT_COLUMNS as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn(self::REDUNDANT_COLUMNS);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule_history_items')) {
            return;
        }

        Schema::table('schedule_history_items', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
        });
    }
};
