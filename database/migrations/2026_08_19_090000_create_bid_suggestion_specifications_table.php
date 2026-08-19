<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bidder says they can actually supply, per good — the new
 * «مشخصات فنی قابل تامین» step of the پیشنهاد wizard.
 *
 * ---------------------------------------------------------------------------
 * Why a table of its own, and not a column on `bid_suggestion_items`
 * ---------------------------------------------------------------------------
 * An items row means exactly one thing today: "I quoted a price for this
 * good" (see that migration — an empty price box writes NO row). The
 * specifications step is answered independently of the price step: a bidder
 * can note an alternative specification for a good and then decide not to
 * price it, or price a good and accept the employer's specification as-is.
 * Hanging the text off the items row would have forced `unit_price` to
 * become nullable and broken that "row means priced" rule for every reader
 * of it.
 *
 * ---------------------------------------------------------------------------
 * Absence is the answer «مشخصات کارفرما را میپذیرم»
 * ---------------------------------------------------------------------------
 * The textbox is empty by default, and an empty box means "I accept the
 * employer's technical specification for this good". So NO ROW IS WRITTEN
 * when the box is left empty — a row exists only when the bidder typed a
 * different specification. That makes "did this bidder change the spec of
 * good X?" the presence of a row, which is exactly the question the admin's
 * پاکت الف screen asks when it decides whether to put the ⚠ icon next to a
 * specification. Same "absence is the state" idea `bid_suggestion_items`
 * and the «ارسال نشده» suggestion status already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_suggestion_specifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bid_suggestion_id')->constrained('bid_suggestions')->cascadeOnDelete();

            /*
             * Points at the tender's requirement row rather than straight at
             * the good, for the same reason `bid_suggestion_items` does: the
             * specification is offered against THIS tender's request for that
             * good, and the same good can be requested by many tenders.
             *
             * cascadeOnDelete: if staff remove a good from the tender's
             * requirements, an alternative specification quoted against it is
             * meaningless and goes with it.
             */
            $table->foreignId('bid_good_requirement_id')->constrained('bid_good_requirements')->cascadeOnDelete();

            // The bidder's own wording. Free text, not compared to anything —
            // a human reads it. Never null: a null/empty answer is stored as
            // the absence of the row (see the docblock).
            $table->text('specifications');

            $table->timestamps();

            // One answer per good per bid — the step renders exactly one box
            // per requirement, and this is what guarantees a double-submit
            // cannot turn that into two rows.
            $table->unique(
                ['bid_suggestion_id', 'bid_good_requirement_id'],
                'bid_suggestion_specifications_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_suggestion_specifications');
    }
};
