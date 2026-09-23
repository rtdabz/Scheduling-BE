<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account setup links live apart from password_reset_tokens so the two
 * brokers keep their own expiry: a forgot-password request must not replace
 * or shorten a pending invitation, and an invitation must not outlive the
 * reset window through the reset broker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitation_tokens');
    }
};
