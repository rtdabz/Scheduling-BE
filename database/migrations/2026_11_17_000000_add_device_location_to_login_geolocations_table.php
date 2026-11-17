<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional browser (Geolocation API) fix sent with a sign-in, kept beside the IP estimate so it
     * shares the same prunable retention window. All columns nullable: existing rows are unchanged.
     */
    public function up(): void
    {
        Schema::table('login_geolocations', function (Blueprint $table): void {
            $table->decimal('device_latitude', 9, 6)->nullable()->after('provider');
            $table->decimal('device_longitude', 9, 6)->nullable()->after('device_latitude');
            $table->unsignedInteger('device_accuracy_m')->nullable()->after('device_longitude');
            $table->timestamp('device_captured_at')->nullable()->after('device_accuracy_m');
        });
    }

    public function down(): void
    {
        Schema::table('login_geolocations', function (Blueprint $table): void {
            $table->dropColumn(['device_latitude', 'device_longitude', 'device_accuracy_m', 'device_captured_at']);
        });
    }
};
