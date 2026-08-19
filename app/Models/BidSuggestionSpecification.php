<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer from the پیشنهاد wizard's «مشخصات فنی قابل تامین» step: "for
 * this good, I can supply THESE specifications instead".
 *
 * A row exists only when the bidder typed something. An empty box means «مشخصات
 * کارفرما را میپذیرم» — I accept the employer's specification — and writes
 * nothing at all, so "the bidder changed this good's specification" is simply
 * "a row exists" (see the migration for the full reasoning).
 */
#[Fillable(['bid_suggestion_id', 'bid_good_requirement_id', 'specifications'])]
class BidSuggestionSpecification extends Model
{
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(BidSuggestion::class, 'bid_suggestion_id');
    }

    /**
     * The tender's requirement row this answer was given against — which is
     * where the good, and through it the employer's own «ابعاد و مشخصات فنی»,
     * comes from. Nothing about the good is copied onto this row.
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(BidGoodRequirement::class, 'bid_good_requirement_id');
    }
}
