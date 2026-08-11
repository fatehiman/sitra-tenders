<?php

namespace Tests\Fakes;

use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;

/**
 * Captures the OTP code a test just triggered, since the real flow only
 * ever persists its hash — tests need the plaintext to drive confirmOtp().
 */
class FakeSmsGateway implements SmsGateway
{
    public ?string $lastCode = null;

    public ?string $lastMobile = null;

    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        $this->lastMobile = $mobile;
        $this->lastCode = $params['code'] ?? null;

        return SmsResult::success('test-reference');
    }
}
