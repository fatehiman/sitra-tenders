<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rows of "we need N of good X" attached to a bid.
     *
     * `good_id` restricts on delete on purpose: a good that is already cited
     * by a published tender must not be deletable, so tender history stays
     * intact (GoodsTable surfaces this as a Persian error before the DB ever
     * has to reject it).
     */
    public function up(): void
    {
        Schema::create('bid_good_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('bids')->cascadeOnDelete();
            $table->foreignId('good_id')->constrained('goods')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['bid_id', 'good_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_good_requirements');
    }
};
