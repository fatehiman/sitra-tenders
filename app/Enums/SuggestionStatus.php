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
 *   پیش‌نویس      — the user has opened the bid wizard and saved something,
 *                  but has not finalised it with the SMS code. A draft is a
 *                  real row (it has to be — the whole point is that the
 *                  prices and files survive the browser being closed), but
 *                  it is NOT a bid: see isActive() below.
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
    case Draft = 'draft';
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
            self::Draft => 'پیش‌نویس',
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
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::FormA, self::FormB => 'warning',
            self::Approved => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /**
     * Is this a *live* bid — i.e. one that locks the tender against editing,
     * is visible to staff, and stops the user starting another one?
     *
     * Two cases say no, for opposite reasons:
     *   «لغو شده»   — it WAS a bid and an admin took it back.
     *   «پیش‌نویس»  — it is not a bid yet. Nobody has committed to anything,
     *                 staff must not see half-typed prices, and freezing a
     *                 tender because somebody left a wizard open would let
     *                 any user lock any tender indefinitely.
     *
     * Everything else counts: even a rejected bid is a real, recorded
     * submission on that tender.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Draft], strict: true);
    }

    /**
     * The stored values isActive() rejects — for query scopes, which have to
     * filter in SQL and cannot call a PHP method per row.
     *
     * Derived from the cases rather than hand-listed, so a future case can
     * never be added to isActive() and forgotten here (which would show
     * staff a draft, or let one lock a tender).
     *
     * @return array<int, string>
     */
    public static function inactiveValues(): array
    {
        return array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => ! $case->isActive()),
        );
    }
}
