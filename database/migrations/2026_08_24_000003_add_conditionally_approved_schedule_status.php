<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE schedules MODIFY status ENUM('draft','completed','submitted','approved_by_dean','conditionally_approved','rejected_by_dean','approved','faculty_assignment','finalized','rejected','revision') NOT NULL DEFAULT 'draft'");
        }
    }
    public function down(): void {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE schedules MODIFY status ENUM('draft','completed','submitted','approved_by_dean','rejected_by_dean','approved','faculty_assignment','finalized','rejected','revision') NOT NULL DEFAULT 'draft'");
        }
    }
};
