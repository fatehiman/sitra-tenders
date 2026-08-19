<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The admin's verdict on ONE offer inside ONE envelope — the green «تایید» or
 * the red «رد» button at the bottom of each suggestion on the
 * App\Filament\Resources\Bids\Pages\OpenEnvelope page.
 *
 * Stored in `bid_suggestions.envelope_a_decision` / `envelope_b_decision`.
 * A null column means "not decided yet", which is why there is no third case
 * here: the absence of a value IS the undecided state, so a case for it could
 * only ever disagree with the column.
 *
 * A decision on its own is a DRAFT. Nothing the bidder can see changes until
 * the admin finalises the envelope (`bids.envelope_a_submitted_at` /
 * `envelope_b_submitted_at`) — see that migration.
 *
 * Implementing Filament's HasLabel/HasColor is what lets a badge just say
 * ->badge() and get the Persian word in a sensibly coloured pill.
 */
enum EnvelopeDecision: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => 'تایید',
            self::Declined => 'رد',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Declined => 'danger',
        };
    }
}
