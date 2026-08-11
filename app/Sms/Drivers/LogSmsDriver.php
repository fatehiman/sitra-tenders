<?php

namespace App\Sms\Drivers;

use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local/testing driver — writes the message to the log instead of sending
 * it, so registration/OTP can be exercised without real SMS credentials.
 */
class LogSmsDriver implements SmsGateway
{
    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        Log::info('sms.log_driver.send', [
            'mobile' => $mobile,
            'template' => $templateKey,
            'params' => $params,
        ]);

        return SmsResult::success(referenceId: (string) Str::uuid());
    }
}
