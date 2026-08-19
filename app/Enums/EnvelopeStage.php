<?php

namespace App\Enums;

/**
 * Which of the two envelopes the admin is opening.
 *
 * The value travels in the URL — /bids/{id}/envelope/{stage} — so it is
 * deliberately short and ASCII ('a' / 'b'), while everything shown to the
 * admin says «الف» / «ب».
 *
 * The two stages differ in three ways, and every one of them is answered by
 * a method here rather than by an `if` sprinkled around
 * App\Filament\Resources\Bids\Pages\OpenEnvelope:
 *
 *   الف — every submitted offer is reviewed, and PRICES ARE NOT SHOWN AT
 *         ALL. It is the technical envelope: goods, the specifications the
 *         bidder can supply, the attachments, the ودیعه payment.
 *   ب   — only the offers approved in الف come back, this time WITH unit
 *         prices and totals. It is the financial envelope.
 */
enum EnvelopeStage: string
{
    case A = 'a';
    case B = 'b';

    /** «الف» or «ب» — the Persian letter used in every label and tooltip. */
    public function letter(): string
    {
        return match ($this) {
            self::A => 'الف',
            self::B => 'ب',
        };
    }

    /** e.g. «بازکردن پاکت الف» — the row action's tooltip. */
    public function openLabel(): string
    {
        return "بازکردن پاکت {$this->letter()}";
    }

    /** Does this stage show the bidder's prices? Only ب does. */
    public function showsPrices(): bool
    {
        return $this === self::B;
    }

    /**
     * The `bid_suggestions` column this stage's verdicts are stored in.
     *
     * Returning the column NAME (rather than switching on the stage at each
     * call site) keeps the two stages' storage a single fact stated once.
     */
    public function decisionColumn(): string
    {
        return match ($this) {
            self::A => 'envelope_a_decision',
            self::B => 'envelope_b_decision',
        };
    }

    /** The `bids` column stamped when this stage is finalised. */
    public function submittedAtColumn(): string
    {
        return match ($this) {
            self::A => 'envelope_a_submitted_at',
            self::B => 'envelope_b_submitted_at',
        };
    }
}
