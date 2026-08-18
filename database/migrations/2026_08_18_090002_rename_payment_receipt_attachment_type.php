<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 «رسید پرداخت» no longer exists — it is replaced by the new step 2
 * «پرداخت», whose bank-guarantee option stores its file under the new
 * `bank_guarantee_letter` attachment type (see App\Enums\SuggestionAttachmentType).
 * The `payment_receipt` case is being removed from that enum entirely, so
 * any row still carrying the old value would fail to cast on read the
 * moment this migration's sibling PHP change ships.
 *
 * This is a real, deployed app (sitra.ir) — a handful of finalised bids may
 * already carry a `payment_receipt` file from before this feature. Rather
 * than lose or orphan them, they are relabelled as the closest surviving
 * type (a bank guarantee upload is what «رسید پرداخت / ضمانت‌نامه بانکی»
 * always was in practice) so they keep showing up wherever attachments of
 * that type are listed.
 *
 * `type` was `varchar(20)`, which fit every value that existed at the time
 * (`document`, `payment_receipt`) but not `bank_guarantee_letter` (21 chars)
 * or `claims_decrease_attachment` (26 chars) — widened to `varchar(30)`
 * here, before the rename, so the UPDATE below does not truncate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_suggestion_attachments', function (Blueprint $table): void {
            $table->string('type', 30)->default('document')->change();
        });

        DB::table('bid_suggestion_attachments')
            ->where('type', 'payment_receipt')
            ->update(['type' => 'bank_guarantee_letter']);
    }

    public function down(): void
    {
        DB::table('bid_suggestion_attachments')
            ->where('type', 'bank_guarantee_letter')
            ->update(['type' => 'payment_receipt']);

        Schema::table('bid_suggestion_attachments', function (Blueprint $table): void {
            $table->string('type', 20)->default('document')->change();
        });
    }
};
