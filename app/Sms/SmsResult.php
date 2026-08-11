<?php

namespace App\Sms;

readonly class SmsResult
{
    public function __construct(
        public bool $ok,
        public ?string $referenceId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $traceId = null,
    ) {}

    public static function success(?string $referenceId = null): self
    {
        return new self(ok: true, referenceId: $referenceId);
    }

    public static function failure(?string $errorCode = null, ?string $errorMessage = null, ?string $traceId = null): self
    {
        return new self(ok: false, errorCode: $errorCode, errorMessage: $errorMessage, traceId: $traceId);
    }
}
