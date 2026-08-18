<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wizard grew two new steps — «شرایط مناقصه» and «پرداخت» — and both
 * need somewhere to store what the user did on them:
 *
 *  - `terms_accepted`: did the user tick «شرایط مناقصه را خواندم و موافق
 *    هستم»? A plain boolean, not a timestamp — this app does not need to
 *    prove WHEN it was accepted, only that it was, before the bid could be
 *    finalised (see App\Filament\Resources\Bids\Pages\SubmitSuggestion).
 *  - `payment_type`: which of the three ودیعه payment methods the user
 *    picked (`App\Enums\PaymentType`). A string, not a MySQL enum, for the
 *    same reason `bid_suggestions.status` is one — adding a fourth method
 *    later is a code change, not a schema change.
 *  - the four `claims_decrease_*` columns hold the «نامه کسر از مطالبات»
 *    letter's fill-in-the-blank fields, used only when `payment_type` is
 *    `claims_decrease`. `claims_decrease_org_name` is NOT typed by the
 *    user — it is a snapshot of the account's display name, written by the
 *    server on every draft save, never trusted from the browser (the same
 *    reason quantities are re-read from `bid_good_requirements` rather than
 *    the repeater row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table): void {
            $table->boolean('terms_accepted')->default(false)->after('note');
            $table->string('payment_type', 20)->nullable()->after('terms_accepted');
            $table->string('claims_decrease_addressee', 255)->nullable()->after('payment_type');
            $table->string('claims_decrease_tender_number', 255)->nullable()->after('claims_decrease_addressee');
            $table->string('claims_decrease_subject', 255)->nullable()->after('claims_decrease_tender_number');
            $table->string('claims_decrease_org_name', 255)->nullable()->after('claims_decrease_subject');
        });
    }

    public function down(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table): void {
            $table->dropColumn([
                'terms_accepted',
                'payment_type',
                'claims_decrease_addressee',
                'claims_decrease_tender_number',
                'claims_decrease_subject',
                'claims_decrease_org_name',
            ]);
        });
    }
};
