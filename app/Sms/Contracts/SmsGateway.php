<?php

namespace App\Sms\Contracts;

use App\Sms\SmsResult;

interface SmsGateway
{
    /**
     * Send a message built from a semantic template key (mapped to a
     * provider-specific template ID in config, never hard-coded at the
     * call site — so swapping providers or templates is a config change).
     *
     * @param  string  $mobile  local format, e.g. 09XXXXXXXXX
     * @param  string  $templateKey  semantic name, e.g. 'otp'
     * @param  array<string, string>  $params  named placeholder values; a
     *         `code` key is pulled out and sent however the driver's
     *         provider expects a verification code to be sent (e.g. as a
     *         reserved top-level field for msgway)
     */
    public function send(string $mobile, string $templateKey, array $params = []): SmsResult;
}
