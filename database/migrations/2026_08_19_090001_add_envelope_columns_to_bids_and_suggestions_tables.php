<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin's two-envelope review — «بازکردن پاکت الف» and «بازکردن پاکت ب».
 *
 * ---------------------------------------------------------------------------
 * Four columns, two on each table, and why the split matters
 * ---------------------------------------------------------------------------
 * A decision the admin clicks is a DRAFT until they finalise the whole
 * envelope, and finalising can never be undone. Those are two different
 * facts, so they live in two different places:
 *
 *   bid_suggestions.envelope_a_decision / envelope_b_decision
 *       one admin's verdict on one offer («تایید» / «رد»), written the
 *       moment the button is clicked so that closing the browser mid-review
 *       loses nothing, and re-writable as often as the admin changes their
 *       mind. On its own it changes NOTHING the bidder can see.
 *
 *   bids.envelope_a_submitted_at / envelope_b_submitted_at
 *       when the admin pressed the irreversible «ثبت نهایی» for that
 *       envelope. This is the stamp that turns the drafts above into real
 *       outcomes: on الف the app writes each suggestion's status (فرم الف or
 *       رد شده), on ب it writes تایید شده / رد شده and texts every bidder.
 *
 * So "has envelope الف been finalised for this tender?" is one nullable
 * timestamp on the tender — which is also exactly what the letter icon on
 * the مناقصات table reads to pick its colour and tooltip
 * (App\Filament\Resources\Bids\Tables\BidsTable).
 *
 * Strings, not MySQL enums, for the decisions — the same reason
 * `bid_suggestions.status` and `payment_type` are strings: another verdict
 * later would be a code change, not a schema change. The allowed values live
 * in App\Enums\EnvelopeDecision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            $table->timestamp('envelope_a_submitted_at')->nullable()->after('expire_at');
            $table->timestamp('envelope_b_submitted_at')->nullable()->after('envelope_a_submitted_at');
        });

        Schema::table('bid_suggestions', function (Blueprint $table): void {
            // 20 chars is generous for 'approved'/'declined' and leaves room
            // for a longer third verdict without another migration.
            $table->string('envelope_a_decision', 20)->nullable()->after('status');
            $table->string('envelope_b_decision', 20)->nullable()->after('envelope_a_decision');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            $table->dropColumn(['envelope_a_submitted_at', 'envelope_b_submitted_at']);
        });

        Schema::table('bid_suggestions', function (Blueprint $table): void {
            $table->dropColumn(['envelope_a_decision', 'envelope_b_decision']);
        });
    }
};
