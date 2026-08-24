<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'scheduling_audit_created_id_index');
            $table->index(['action', 'created_at'], 'scheduling_audit_action_created_index');
            $table->index(['department_id', 'term_id', 'created_at'], 'scheduling_audit_scope_created_index');
        });

        Schema::table('authentication_audit_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'authentication_audit_created_id_index');
            $table->index(['event', 'created_at'], 'authentication_audit_event_created_index');
            $table->index(['actor_user_id', 'created_at'], 'authentication_audit_actor_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('scheduling_audit_created_id_index');
            $table->dropIndex('scheduling_audit_action_created_index');
            $table->dropIndex('scheduling_audit_scope_created_index');
        });

        Schema::table('authentication_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('authentication_audit_created_id_index');
            $table->dropIndex('authentication_audit_event_created_index');
            $table->dropIndex('authentication_audit_actor_created_index');
        });
    }
};
