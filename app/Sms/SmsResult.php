<?php

namespace App\Sms;

/**
 * The outcome of one send attempt, in a shape every driver can produce.
 *
 * It is a plain value object rather than a bool because a failed send needs
 * to explain itself: the registration page shows the provider's own reason
 * to the visitor, and `traceId` is what the provider's support desk needs.
 *
 * `readonly` means the properties cannot be changed after construction — a
 * result describes something that already happened, so nothing should ever
 * be able to rewrite it in transit.
 */
readonly class SmsResult
{
    public function __construct(
        public bool $ok,
        public ?string $referenceId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $traceId = null,
    ) {}

    /*
     * These two named constructors exist purely for readability at the call
     * site: `SmsResult::failure(...)` says what happened far more clearly
     * than `new SmsResult(false, null, ...)`.
     */

    public static function success(?string $referenceId = null): self
    {
        return new self(ok: true, referenceId: $referenceId);
    }

    public static function failure(?string $errorCode = null, ?string $errorMessage = null, ?string $traceId = null): self
    {
        return new self(ok: false, errorCode: $errorCode, errorMessage: $errorMessage, traceId: $traceId);
    }
}
