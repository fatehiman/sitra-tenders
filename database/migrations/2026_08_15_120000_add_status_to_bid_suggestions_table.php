<?php

use App\Enums\SuggestionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a پیشنهاد a lifecycle: when it was sent, which step it is at, and
 * whether an admin has cancelled it.
 *
 * See App\Enums\SuggestionStatus for what the steps mean and which of them
 * are still TODO (the admin review screens are future work).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table) {
            /*
             * Stored as a plain string rather than a MySQL ENUM: adding a
             * value to a PHP enum is then a code change, not a schema change
             * — and more review steps are known to be coming.
             */
            $table->string('status', 20)
                ->default(SuggestionStatus::Submitted->value)
                ->after('note')
                ->index();

            /*
             * The moment the user pressed «ثبت پیشنهاد» — shown in the
             * مناقصات table under the «ارسال پیشنهاد» column.
             *
             * Deliberately NOT created_at. An admin can cancel a bid, after
             * which the user may bid again on the same tender; that reuses
             * the same row (the unique (bid_id, user_id) index means there
             * can only ever be one), so created_at would keep pointing at
             * the first, cancelled attempt while the user is looking at a
             * brand-new bid.
             */
            $table->timestamp('submitted_at')->nullable()->after('status');

            // Who cancelled it, when, and why — filled only by the admin's
            // «لغو» action, and cleared again if the user re-bids.
            $table->timestamp('cancelled_at')->nullable()->after('submitted_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
        });

        // Existing rows predate submitted_at, and every one of them was
        // created by the user pressing submit — so created_at is the right
        // value for them.
        DB::table('bid_suggestions')->whereNull('submitted_at')->update([
            'submitted_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bid_suggestions', function (Blueprint $table) {
            // dropConstrainedForeignId drops the FK *and* the column; the
            // plain dropColumn below would fail while the FK still exists.
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['status', 'submitted_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
