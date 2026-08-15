<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pending SMS verification challenges.
     *
     * Deliberately NOT linked to `users` by a foreign key: during
     * registration the user does not exist yet, and only exists at all once
     * the code here has been confirmed.
     */
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            // Indexed because every lookup is "the latest row for this
            // number" — without an index that scans the whole table.
            $table->string('mobile', 11)->index();
            // The six-digit code, hashed like a password. Never stored raw.
            $table->string('code_hash');
            // Wrong guesses so far; capped at 5 by OtpService.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            // 45 characters is the longest possible IPv6 address text.
            $table->string('ip_address', 45)->nullable();
            // created_at only — these rows are never meaningfully updated.
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};
