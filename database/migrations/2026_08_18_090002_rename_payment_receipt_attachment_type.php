<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bid_suggestion_attachments')
            ->where('type', 'payment_receipt')
            ->update(['type' => 'bank_guarantee_letter']);
    }

    public function down(): void
    {
        DB::table('bid_suggestion_attachments')
            ->where('type', 'bank_guarantee_letter')
            ->update(['type' => 'payment_receipt']);
    }
};
