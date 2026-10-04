<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_year');
            $table->enum('semester', ['1st', '2nd', 'summer']);
            $table->boolean('is_active')->default(false)->index('terms_is_active_index');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `semesters` ADD COLUMN `semester_key` VARCHAR(300) '
            ."GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, CONCAT(`academic_year`, '|', `semester`), NULL)) VIRTUAL"
        );
        DB::statement('ALTER TABLE `semesters` ADD UNIQUE KEY `semesters_live_semester_key_unique` (`semester_key`)');

        DB::statement(
            'ALTER TABLE `semesters` ADD COLUMN `active_key` TINYINT '
            .'GENERATED ALWAYS AS (IF(`is_active` = 1 AND `deleted_at` IS NULL, 1, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `semesters` ADD UNIQUE KEY `semesters_single_active_unique` (`active_key`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
