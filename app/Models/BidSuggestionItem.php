<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line of a پیشنهاد: the user's ریال-per-unit quote against one
 * of the tender's «کالاهای مورد نیاز» rows.
 *
 * A line only exists if the user typed a price. Leaving the box empty means
 * "I am not supplying this good" and writes nothing — see the migration.
 *
 * Both money columns are whole ریال. There is no currency column and no
 * decimal part, by explicit product decision.
 */
#[Fillable(['bid_suggestion_id', 'bid_good_requirement_id', 'unit_price', 'total_price'])]
class BidSuggestionItem extends Model
{
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(BidSuggestion::class, 'bid_suggestion_id');
    }

    /**
     * The tender's requirement row this price was quoted against — which is
     * where the quantity, and through it the good itself, comes from.
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(BidGoodRequirement::class, 'bid_good_requirement_id');
    }
}
