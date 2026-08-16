<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One priced line of a پیشنهاد: "for the tender's requirement row X, I quote
 * Y ریال each".
 *
 * The row points at a `bid_good_requirements` row rather than straight at a
 * `goods` row on purpose. The quantity being priced belongs to the TENDER's
 * requirement, not to the good, and the same good can be required by many
 * tenders at different quantities — pointing at the good would leave nothing
 * saying which quantity this price was quoted against.
 *
 * A user who does not want to supply a given good simply leaves the box
 * empty, and NO ROW IS WRITTEN. So "priced" and "not priced" is the presence
 * or absence of a row, never a 0 or a null price — the same "absence is the
 * state" idea the پیشنهاد lifecycle already uses for «ارسال نشده».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_suggestion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_suggestion_id')->constrained('bid_suggestions')->cascadeOnDelete();

            /*
             * cascadeOnDelete: if staff remove a good from the tender's
             * requirements, the prices quoted against it are meaningless and
             * go with it. That can only happen while the tender is unlocked,
             * i.e. while nobody has a FINALISED bid on it — a draft does not
             * lock a tender (see App\Enums\SuggestionStatus::isActive()).
             */
            $table->foreignId('bid_good_requirement_id')->constrained('bid_good_requirements')->cascadeOnDelete();

            // ریال per unit, whole numbers (see the total_price note in the
            // previous migration for why unsignedBigInteger and not integer).
            $table->unsignedBigInteger('unit_price');

            /*
             * unit_price × the requirement's quantity, stored rather than
             * computed on read.
             *
             * The quantity lives on the requirement row, which staff can
             * still change while the tender is unlocked. Storing the line
             * total freezes what the user actually saw and agreed to at
             * submit time, instead of silently re-pricing their offer if the
             * requested quantity moves afterwards.
             */
            $table->unsignedBigInteger('total_price');

            $table->timestamps();

            // One price per requirement per bid — the wizard renders exactly
            // one input per requirement, and this is what guarantees a
            // double-submit cannot turn that into two.
            $table->unique(['bid_suggestion_id', 'bid_good_requirement_id'], 'bid_suggestion_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_suggestion_items');
    }
};
