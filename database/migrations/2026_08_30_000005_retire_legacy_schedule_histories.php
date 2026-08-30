<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('schedule_histories');
    }

    public function down(): void
    {
        // Legacy history cannot be reconstructed after version/item migration.
    }
};
