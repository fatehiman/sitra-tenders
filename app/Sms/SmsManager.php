<?php

namespace App\Sms;

use App\Sms\Contracts\SmsGateway;
use App\Sms\Drivers\LogSmsDriver;
use App\Sms\Drivers\MsgwaySmsDriver;
use Illuminate\Support\Manager;

class SmsManager extends Manager implements SmsGateway
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('sms.default', 'log');
    }

    protected function createLogDriver(): LogSmsDriver
    {
        return new LogSmsDriver;
    }

    protected function createMsgwayDriver(): MsgwaySmsDriver
    {
        $config = $this->config->get('sms.drivers.msgway', []);

        return new MsgwaySmsDriver(
            apiKey: (string) ($config['api_key'] ?? ''),
            baseUrl: $config['base_url'] ?? 'https://api.msgway.com',
            templates: $config['templates'] ?? [],
        );
    }

    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        return $this->driver()->send($mobile, $templateKey, $params);
    }
}
