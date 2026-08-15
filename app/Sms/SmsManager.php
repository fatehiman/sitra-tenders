<?php

namespace App\Sms;

use App\Sms\Contracts\SmsGateway;
use App\Sms\Drivers\LogSmsDriver;
use App\Sms\Drivers\MsgwaySmsDriver;
use Illuminate\Support\Manager;

/**
 * Picks which SMS provider actually sends a message, based on config.
 *
 * This is Laravel's "Manager" pattern (the same one behind Mail, Cache and
 * Queue). The rest of the app only ever talks to the SmsGateway interface,
 * so switching providers — or swapping in the log driver during local
 * development — is a change to SMS_DRIVER in .env, and nothing else.
 *
 * The naming is a convention, not magic you have to wire up: asking for the
 * driver named 'msgway' makes Manager call createMsgwayDriver().
 */
class SmsManager extends Manager implements SmsGateway
{
    /** Which driver to use when nobody asks for a specific one. */
    public function getDefaultDriver(): string
    {
        return $this->config->get('sms.default', 'log');
    }

    /** SMS_DRIVER=log — writes to the log file, sends nothing. */
    protected function createLogDriver(): LogSmsDriver
    {
        return new LogSmsDriver;
    }

    /** SMS_DRIVER=msgway — the real provider used in production. */
    protected function createMsgwayDriver(): MsgwaySmsDriver
    {
        $config = $this->config->get('sms.drivers.msgway', []);

        return new MsgwaySmsDriver(
            apiKey: (string) ($config['api_key'] ?? ''),
            baseUrl: $config['base_url'] ?? 'https://api.msgway.com',
            templates: $config['templates'] ?? [],
        );
    }

    /**
     * Hand the call to whichever driver is configured. This method is the
     * only reason SmsManager implements SmsGateway itself — it lets callers
     * type-hint the interface and never think about drivers at all.
     */
    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        return $this->driver()->send($mobile, $templateKey, $params);
    }
}
