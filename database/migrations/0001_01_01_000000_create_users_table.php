<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A migration is a versioned description of a schema change. Running
     * `php artisan migrate` applies the ones that have not run yet, so every
     * environment ends up with an identical database without anyone
     * clicking around in a GUI.
     *
     * The class is anonymous (`return new class ...`) because migration
     * files are loaded by filename, so they need no class name of their own.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();  // auto-incrementing primary key
            $table->string('first_name');
            $table->string('last_name');
            // NOTE: no email column anywhere — the mobile number IS the
            // login identifier, hence unique.
            $table->string('mobile', 11)->unique();
            // کدملی — one account per national ID.
            $table->string('national_id', 10)->unique();
            $table->enum('person_type', ['individual', 'company'])->default('individual');
            // Nullable because individual («حقیقی») accounts have neither.
            // company_name being set is also what makes the app display and
            // treat the account as a company (see User::getDisplayName).
            $table->string('company_name')->nullable();
            // شناسه ملی. Format only — 11 digits, no checksum enforced.
            $table->string('company_national_id', 11)->nullable();
            $table->string('password'); // always a bcrypt hash, never plain
            // Null until an SMS code has been confirmed. Admin-created
            // accounts get it stamped immediately.
            $table->timestamp('mobile_verified_at')->nullable();
            // Which admin created this account; null for public sign-ups.
            // nullOnDelete: if that admin is later removed, keep this row
            // and just blank the reference.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Lets an account be locked out without deleting its history.
            $table->boolean('is_active')->default(true);
            $table->rememberToken();  // for "remember me" logins
            $table->timestamps();     // created_at + updated_at
        });

        // Laravel's own session storage (SESSION_DRIVER=database).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations — what `php artisan migrate:rollback` runs.
     * Every up() should have a down() that undoes exactly it.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
