<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Place name (city, region, country) reverse-geocoded from the device fix, so the Activity Log can
     * name where the user actually was instead of the ISP gateway city behind the IP estimate.
     */
    public function up(): void
    {
        Schema::table('login_geolocations', function (Blueprint $table): void {
            $table->string('device_city', 120)->nullable()->after('device_captured_at');
            $table->string('device_region', 120)->nullable()->after('device_city');
            $table->string('device_country', 120)->nullable()->after('device_region');
        });
    }

    public function down(): void
    {
        Schema::table('login_geolocations', function (Blueprint $table): void {
            $table->dropColumn(['device_city', 'device_region', 'device_country']);
        });
    }
};
