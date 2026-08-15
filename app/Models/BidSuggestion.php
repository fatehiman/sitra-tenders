<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's پیشنهاد (proposal/offer) on a tender.
 *
 * The *content* is still a scaffold — one free-text note — because the real
 * business rules (pricing, per-good breakdown, Form الف / Form ب) are not
 * specified yet. The *lifecycle* around it is real: see App\Enums\SuggestionStatus
 * for every step and which of them are still TODO.
 *
 * Two guarantees worth knowing before changing anything here:
 *
 *  1. A unique index on (bid_id, user_id) in the migration means one row per
 *     user per tender, forever — no amount of double-clicking creates two.
 *     Cancelling therefore does not free up a second row; a user who bids
 *     again after a cancellation REUSES this row (see resubmit() below).
 *  2. A tender carrying any non-cancelled suggestion is locked against
 *     editing/deleting — enforced in App\Policies\BidPolicy via
 *     Bid::isLocked(). Cancelling is the only way to unlock it.
 */
#[Fillable(['bid_id', 'user_id', 'note', 'status', 'submitted_at'])]
class BidSuggestion extends Model
{
    // Rows are written by the two methods below rather than by a generic
    // update, so Eloquent's updated_at column would carry no information
    // that submitted_at/cancelled_at do not already carry.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            // Gives us $suggestion->status->isActive() and the Persian label
            // straight off the enum, instead of comparing raw strings.
            'status' => SuggestionStatus::class,
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who pressed «لغو». Null unless the bid was cancelled. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Only the bids that still count — i.e. not cancelled.
     *
     * A "scope" is a reusable piece of query: writing `->active()` in one
     * place beats repeating the where() clause and risking one copy being
     * forgotten (which here would mean a cancelled bid still locking a
     * tender).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', SuggestionStatus::Cancelled->value);
    }

    /**
     * The Persian word shown to the user for this bid's current step.
     *
     * The one piece of logic that is NOT a straight enum label: a submitted
     * bid reads «ارسال شده» while the tender is still open, and flips to
     * «دردست بررسی» the moment the tender expires. That is derived from the
     * clock on every read rather than stored, for the same reason Bid has no
     * `status` column — a stored value would need a scheduled job to stay
     * truthful, and would be wrong in the meantime.
     */
    public function getStatusLabel(): string
    {
        if ($this->status === SuggestionStatus::Submitted && $this->bid?->expire_at?->isPast()) {
            return 'دردست بررسی';
        }

        return $this->status->getLabel();
    }

    /** Badge colour to match getStatusLabel(). */
    public function getStatusColor(): string
    {
        if ($this->status === SuggestionStatus::Submitted && $this->bid?->expire_at?->isPast()) {
            return 'warning';
        }

        return $this->status->getColor();
    }

    /**
     * Cancel this bid: it stops locking the tender and the user may bid again.
     *
     * @param  User  $admin  who pressed the button — recorded for support
     */
    public function cancel(User $admin, ?string $reason = null): void
    {
        $this->forceFill([
            'status' => SuggestionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
            'cancel_reason' => $reason,
        ])->save();
    }

    /**
     * Turn a cancelled bid back into a live one with new content.
     *
     * Used when a user bids again on a tender whose previous bid was
     * cancelled. Because of the unique (bid_id, user_id) index this has to
     * reuse the existing row, which means the previous cancellation's
     * who/when/why is overwritten — an accepted trade for keeping the
     * one-bid-per-tender guarantee in the database rather than in code. If a
     * full audit trail is ever needed, add a separate history table; do not
     * drop that unique index.
     */
    public function resubmit(string $note): void
    {
        $this->forceFill([
            'note' => $note,
            'status' => SuggestionStatus::Submitted,
            'submitted_at' => now(),
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ])->save();
    }
}
