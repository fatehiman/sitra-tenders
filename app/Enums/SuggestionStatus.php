<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a user's پیشنهاد (bid/offer) currently sits in its lifecycle.
 *
 * The string values are what land in `bid_suggestions.status`; the Persian
 * labels are what humans see, so the wording can change without a data
 * migration.
 *
 * ---------------------------------------------------------------------------
 * The full lifecycle, as specified
 * ---------------------------------------------------------------------------
 *   ارسال نشده   — the user has not bid on this tender yet. This is NOT a
 *                  case below, because there is no row to hold it: "no row
 *                  exists" IS the state. BidsTable renders it as the fallback
 *                  when a tender has no suggestion from the current user.
 *   ارسال شده    — Submitted, and the tender has not expired yet. The user
 *                  can still see it; nobody reviews it before the deadline.
 *   دردست بررسی  — ALSO the Submitted case, but the tender's expire_at has
 *                  passed, so it is now waiting for review. Derived from the
 *                  clock, not stored — see BidSuggestion::getStatusLabel().
 *   فرم الف      — TODO(future): stamped when an admin opens Form A for this
 *                  suggestion. The admin review screens are not built yet;
 *                  the requirement is explicit that they come later, so the
 *                  case exists and nothing sets it today.
 *   فرم ب        — TODO(future): stamped when an admin opens Form B.
 *   تایید شده    — TODO(future): the admin accepted the bid.
 *   رد شده       — TODO(future): the admin rejected it, at whichever step.
 *   لغو شده      — an admin cancelled the bid (see BidsTable's «لغو» action).
 *                  This is the one non-linear transition: it can happen at
 *                  any point, it unlocks the tender for editing again, and it
 *                  lets the user submit a fresh bid.
 *
 * Implementing Filament's HasLabel/HasColor is what lets a table column just
 * say ->badge() and get the Persian text in a sensibly coloured pill.
 */
enum SuggestionStatus: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case FormA = 'form_a';
    case FormB = 'form_b';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            // Note: for Submitted this is only half the story — once the
            // tender has expired the same stored value is DISPLAYED as
            // «دردست بررسی». BidSuggestion::getStatusLabel() applies that.
            self::Submitted => 'ارسال شده',
            self::FormA => 'فرم الف',
            self::FormB => 'فرم ب',
            self::Approved => 'تایید شده',
            self::Rejected => 'رد شده',
            self::Cancelled => 'لغو شده',
        };
    }

    /** Badge colour, using Filament's own palette names. */
    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::FormA, self::FormB => 'warning',
            self::Approved => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /**
     * Is this a *live* bid — i.e. one that locks the tender against editing
     * and stops the user submitting another one?
     *
     * Everything except «لغو شده» counts: even a rejected bid is a real,
     * recorded submission on that tender.
     */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
