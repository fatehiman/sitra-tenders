<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a new OTP is requested for a mobile before its resend
 * cooldown has elapsed.
 */
class OtpThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Resend cooldown active for {$retryAfterSeconds}s.");
    }
}
