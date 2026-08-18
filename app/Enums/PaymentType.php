<?php

namespace App\Enums;

/**
 * The three ways a bidder may pay/guarantee the tender's ودیعه (bid
 * deposit) in step 2 «پرداخت» of the پیشنهاد wizard.
 *
 * A string, not a MySQL enum — same reason `SuggestionStatus` is one: a
 * fourth method later is a code change, not a schema change.
 */
enum PaymentType: string
{
    /**
     * Pay through a real payment gateway. The link is a PLACEHOLDER today
     * (no gateway is wired up yet) — see
     * App\Filament\Resources\Bids\Pages\SubmitSuggestion::paymentStep().
     * Choosing this option does not block moving on to the next step; once
     * a real gateway exists this is where polling for the payment result
     * will be added.
     */
    case Electronic = 'electronic';

    /** Upload a scanned/photographed ضمانت‌نامه بانکی — mandatory file. */
    case BankGuarantee = 'bank_guarantee';

    /**
     * Fill in the «نامه کسر از مطالبات» letter asking the buyer to deduct
     * the deposit from money already owed to the bidder.
     */
    case ClaimsDecrease = 'claims_decrease';

    public function getLabel(): string
    {
        return match ($this) {
            self::Electronic => 'پرداخت الکترونیک',
            self::BankGuarantee => 'بارگذاری ضمانت‌نامه بانکی',
            self::ClaimsDecrease => 'نامه کسر از مطالبات',
        };
    }
}
