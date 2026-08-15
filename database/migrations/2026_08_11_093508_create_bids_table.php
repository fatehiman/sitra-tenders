<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مناقصات — the tenders themselves.
     *
     * Note there is no `status` column: whether a tender is scheduled,
     * active or finished is worked out from the two dates whenever it is
     * needed (Bid::getStatusLabelAttribute), so it can never go stale.
     */
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // longText, because this holds rich-text HTML from the editor,
            // images included — a normal TEXT column would truncate it.
            $table->longText('description')->nullable();
            // Plain Gregorian datetimes. Jalali is a display concern only,
            // which keeps date comparisons trivially correct.
            $table->dateTime('start_at');
            $table->dateTime('expire_at');
            // cascadeOnDelete: removing a user removes their tenders too.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
