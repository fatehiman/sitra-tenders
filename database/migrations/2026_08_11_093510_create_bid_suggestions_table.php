<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * پیشنهادها — a user's offer on a tender. Scaffold only for now: a
     * single free-text note until the real business rules are specified.
     */
    public function up(): void
    {
        Schema::create('bid_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('bids')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            // "One suggestion per tender per user", enforced by the database
            // itself. The UI also hides the button once one exists, but this
            // is the guarantee that survives double-clicks and race
            // conditions the UI check cannot catch.
            $table->unique(['bid_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_suggestions');
    }
};
