<?php

namespace App\Enums;

/**
 * Which slot of the پیشنهاد wizard a file was uploaded into.
 *
 * All three kinds live in the same `bid_suggestion_attachments` table (see
 * that migration for why); this is the column that tells them apart, and
 * the only place the string values are written down.
 */
enum SuggestionAttachmentType: string
{
    /** Step 4 «توضیحات و پیوست‌ها» — supporting documents, up to ten of them. */
    case Document = 'document';

    /**
     * Step 2 «پرداخت» — the ضمانت‌نامه بانکی upload, when the user picks
     * «بارگذاری ضمانت‌نامه بانکی» as their payment method. At most one file.
     */
    case BankGuaranteeLetter = 'bank_guarantee_letter';

    /**
     * Step 2 «پرداخت» — the OPTIONAL scan/photo the user may attach to the
     * «نامه کسر از مطالبات» letter, when that is their chosen payment
     * method.
     */
    case ClaimsDecreaseAttachment = 'claims_decrease_attachment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Document => 'پیوست‌ها',
            self::BankGuaranteeLetter => 'ضمانت‌نامه بانکی',
            self::ClaimsDecreaseAttachment => 'پیوست نامه کسر از مطالبات',
        };
    }
}
