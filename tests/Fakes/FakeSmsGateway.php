<?php

namespace Tests\Fakes;

use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;

/**
 * Captures the OTP code a test just triggered, since the real flow only
 * ever persists its hash — tests need the plaintext to drive confirmOtp().
 *
 * It also keeps every message it was handed, which is what the tender-result
 * tests read: those messages carry no code, so the interesting part is which
 * template went to which number with which parameters.
 */
class FakeSmsGateway implements SmsGateway
{
    public ?string $lastCode = null;

    public ?string $lastMobile = null;

    /**
     * Every send, in order: ['mobile' => ..., 'template' => ..., 'params' => [...]].
     *
     * @var array<int, array{mobile: string, template: string, params: array<string, string>}>
     */
    public array $messages = [];

    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        $this->lastMobile = $mobile;
        $this->lastCode = $params['code'] ?? null;
        $this->messages[] = ['mobile' => $mobile, 'template' => $templateKey, 'params' => $params];

        return SmsResult::success('test-reference');
    }

    /**
     * The messages sent with one template key — e.g. all the 'bid_won' texts.
     *
     * @return array<int, array{mobile: string, template: string, params: array<string, string>}>
     */
    public function messagesOfTemplate(string $templateKey): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (array $message): bool => $message['template'] === $templateKey,
        ));
    }
}
