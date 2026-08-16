<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a پیشنهاد from "a free-text note" into a real, priced offer built up
 * over a multi-step wizard.
 *
 * Three columns, one per new idea:
 *
 *  - total_price   — the sum of (unit price × requested quantity) across
 *                    every good the user priced. It IS derivable from
 *                    bid_suggestion_items, and it is stored anyway, because
 *                    the tenders table and the admin's «پیشنهادهای دریافتی»
 *                    modal both show it per row: recomputing would mean a
 *                    SUM() sub-query per line. It is only ever written by
 *                    BidSuggestion::recalculateTotal(), never by hand.
 *
 *  - tracking_code — the «کد پیگیری» handed to the user at the end. Eight
 *                    digits, unique, and issued ONLY at finalisation — a
 *                    draft has none, which is exactly what makes "do I have
 *                    a code?" the same question as "did I finish?".
 *
 *  - otp_verified_at — when the user passed the SMS challenge that
 *                    finalised this bid. Audit only: the challenge itself is
 *                    checked against otp_verifications at submit time.
 *
 * See DATABASE.md for the full table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table) {
            /*
             * ریال, whole numbers only — an explicit product decision, so
             * there is no decimal part to round. unsignedBigInteger tops out
             * around 1.8e19, which is far beyond any tender this app will
             * ever see; a signed int would have overflowed at ~2.1 billion
             * ریال, i.e. about 210 million تومان, which is NOT beyond it.
             *
             * Nullable because a draft may legitimately have nothing priced
             * yet, and 0 would read as "they offered nothing".
             */
            $table->unsignedBigInteger('total_price')->nullable()->after('note');

            // Eight digits, stored as a string: leading zeros are part of
            // the code, and nobody ever does arithmetic on it.
            $table->string('tracking_code', 8)->nullable()->unique()->after('total_price');

            $table->timestamp('otp_verified_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table) {
            // The unique index has to go before the column it is built on.
            $table->dropUnique(['tracking_code']);
            $table->dropColumn(['total_price', 'tracking_code', 'otp_verified_at']);
        });
    }
};
