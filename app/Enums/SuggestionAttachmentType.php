<?php

namespace App\Enums;

/**
 * Which slot of the پیشنهاد wizard a file was uploaded into.
 *
 * Both kinds live in the same `bid_suggestion_attachments` table (see that
 * migration for why); this is the column that tells them apart, and the only
 * place the string values are written down.
 */
enum SuggestionAttachmentType: string
{
    /** Step 2 — supporting documents, up to ten of them. */
    case Document = 'document';

    /** Step 3 — the رسید پرداخت / ضمانت‌نامه بانکی, at least one required. */
    case PaymentReceipt = 'payment_receipt';

    public function getLabel(): string
    {
        return match ($this) {
            self::Document => 'پیوست‌ها',
            self::PaymentReceipt => 'رسید پرداخت / ضمانت‌نامه بانکی',
        };
    }
}
