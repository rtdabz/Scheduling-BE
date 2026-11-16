<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A receiving department may hand a delegated class it cannot staff back to the
     * originating department. The mark applies while it names the course's current
     * teaching department; the class then follows the offering department's own rules.
     * Who returned it is recorded in scheduling_audit_logs.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->foreignId('delegation_returned_from_department_id')->nullable()->after('faculty_assignment_done')
                ->constrained('departments')->nullOnDelete();
            $table->timestamp('delegation_returned_at')->nullable()->after('delegation_returned_from_department_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delegation_returned_from_department_id');
            $table->dropColumn('delegation_returned_at');
        });
    }
};
