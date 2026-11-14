<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approximate location for a login audit event. User, IP, user agent, event and time
     * stay on authentication_audit_logs (append-only); this table only adds the lookup
     * result so it can be pruned on its own retention schedule.
     */
    public function up(): void
    {
        Schema::create('login_geolocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authentication_audit_log_id')->unique()->constrained('authentication_audit_logs')->cascadeOnDelete();
            $table->string('lookup_status', 20); // resolved | private | unavailable
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 7, 2)->nullable();
            $table->decimal('longitude', 7, 2)->nullable();
            $table->boolean('is_proxy')->nullable();
            $table->string('provider', 30)->nullable();
            $table->timestamps();
            $table->index(['lookup_status', 'created_at'], 'login_geolocations_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_geolocations');
    }
};
