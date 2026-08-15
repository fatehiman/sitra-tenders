<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's پیشنهاد (proposal/offer) on a tender.
 *
 * SCAFFOLD ONLY for now: the UI collects a single free-text note. The real
 * business rules (pricing, per-good breakdown, acceptance) are not built
 * yet. What IS already real is the one-suggestion-per-tender guarantee — a
 * unique index on (bid_id, user_id) in the migration enforces it at the
 * database level, so no amount of double-clicking can create two.
 */
#[Fillable(['bid_id', 'user_id', 'note'])]
class BidSuggestion extends Model
{
    const UPDATED_AT = null;

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
