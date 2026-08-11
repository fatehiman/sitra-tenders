<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sent_sms_log', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 11);
            $table->string('purpose');
            $table->string('provider');
            $table->string('template');
            $table->enum('status', ['sent', 'failed']);
            $table->string('reference_id')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sent_sms_log');
    }
};
