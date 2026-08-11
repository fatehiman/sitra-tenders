<?php

namespace App\Sms\Drivers;

use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * msgway.com driver. See E:\www\=providers\sms\msgway\msgway.md for the
 * full integration reference this implements — in particular the two
 * documented gotchas handled below: `params` must be a positional
 * (indexed) array, and a `[code]` placeholder is filled from a reserved
 * top-level `code` field, never from `params`.
 */
class MsgwaySmsDriver implements SmsGateway
{
    /**
     * @param  array<string, int>  $templates  semantic name => msgway template ID
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly array $templates,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function send(string $mobile, string $templateKey, array $params = []): SmsResult
    {
        if (! array_key_exists($templateKey, $this->templates)) {
            throw new InvalidArgumentException("No msgway template configured for [{$templateKey}].");
        }

        // [code] is reserved and read from the TOP LEVEL by msgway — pull it out.
        $code = null;
        if (array_key_exists('code', $params)) {
            $code = (string) $params['code'];
            unset($params['code']);
        }

        $payload = [
            'mobile' => $this->toE164($mobile),
            'method' => 'sms',
            'templateID' => (int) $this->templates[$templateKey],
            'params' => array_values($params), // positional, never an object
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        try {
            $response = Http::withHeaders([
                'apiKey' => $this->apiKey,
                'Content-Type' => 'application/json; charset=utf-8',
            ])->timeout($this->timeoutSeconds)->post("{$this->baseUrl}/send", $payload);
        } catch (Throwable $e) {
            Log::warning('sms.msgway.transport_error', [
                'mobile' => $mobile, 'template' => $templateKey, 'error' => $e->getMessage(),
            ]);

            return SmsResult::failure(errorCode: 'transport', errorMessage: $e->getMessage());
        }

        $body = $response->json() ?? [];

        if (($body['status'] ?? null) === 'success') {
            return SmsResult::success(referenceId: $body['referenceID'] ?? null);
        }

        $error = $body['error'] ?? [];

        Log::warning('sms.msgway.error', [
            'mobile' => $mobile, 'template' => $templateKey,
            'http' => $response->status(), 'body' => $body,
        ]);

        return SmsResult::failure(
            errorCode: isset($error['code']) ? (string) $error['code'] : (string) $response->status(),
            errorMessage: $error['message'] ?? 'unknown error',
            traceId: $error['traceID'] ?? null,
        );
    }

    /**
     * msgway requires E.164; the app stores/validates mobiles as local
     * 09XXXXXXXXX and only converts at this boundary.
     */
    private function toE164(string $mobile): string
    {
        return '+98'.substr($mobile, 1);
    }
}
