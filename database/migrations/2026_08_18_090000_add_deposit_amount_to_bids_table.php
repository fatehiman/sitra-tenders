<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ودیعه (bid-guarantee deposit) amount to a tender.
 *
 * This is the money a bidder must pay/guarantee just to be ALLOWED to bid —
 * separate from, and unrelated to, the price they quote for the goods
 * themselves (that lives on `bid_suggestions.total_price`). Admin sets it
 * once when publishing the tender; every bidder sees the same figure.
 *
 * Nullable, same as every other money column in this app that predates a
 * value existing for it (see `bid_suggestions.total_price`) — a tender
 * created before this column existed simply has no ودیعه requirement shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            // unsignedBigInteger, not integer: matches every other whole-ریال
            // money column in this app (see bid_suggestions.total_price) — a
            // signed int overflows at ~2.1 billion ریال.
            $table->unsignedBigInteger('deposit_amount')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            $table->dropColumn('deposit_amount');
        });
    }
};
