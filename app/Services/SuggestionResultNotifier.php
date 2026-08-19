<?php

namespace App\Services;

use App\Models\BidSuggestion;
use App\Models\SentSmsLog;
use App\Sms\Contracts\SmsGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Texts every bidder the outcome of a tender, once the admin finalises
 * «پاکت ب».
 *
 * ---------------------------------------------------------------------------
 * Two templates, one moment
 * ---------------------------------------------------------------------------
 *   bid_won      (msgway 23572) — "you won" — to each approved bidder;
 *   bid_declined (msgway 23573) — "not accepted" — to every OTHER live
 *                                 bidder on the tender, including the ones
 *                                 rejected back in پاکت الف. They were told
 *                                 nothing at that point on purpose: until ب
 *                                 is finalised the tender has no result, and
 *                                 one message per bidder per tender is
 *                                 kinder (and cheaper) than two.
 *
 * Both templates take the same two positional parameters — the bidder's name
 * + family, then the tender's title. The mapping of semantic name to msgway
 * template ID lives in config/sms.php and nowhere else.
 *
 * ---------------------------------------------------------------------------
 * A failed text must never undo a decision
 * ---------------------------------------------------------------------------
 * This runs AFTER Bid::finalizeEnvelope() has committed, and it swallows
 * every failure into the log and the `sent_sms_log` table instead of
 * throwing. The alternative — letting a provider outage bubble up — would
 * either roll back an irreversible review the admin has just confirmed, or
 * leave them staring at an error with no idea whether it was saved.
 */
class SuggestionResultNotifier
{
    /** `sent_sms_log.purpose` values — the counterpart of OtpService's. */
    public const PURPOSE_WON = 'bid_won';

    public const PURPOSE_DECLINED = 'bid_declined';

    /**
     * The SmsGateway is injected rather than constructed here, so tests can
     * hand in a fake that records the message instead of sending it. See
     * AppServiceProvider for where the real one is bound.
     */
    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Send one message per bidder for a tender whose پاکت ب has just been
     * finalised.
     *
     * @param  Collection<int, BidSuggestion>|iterable<BidSuggestion>  $suggestions  the offers that were decided
     * @return int how many messages the provider accepted
     */
    public function notifyAll(iterable $suggestions): int
    {
        $sent = 0;

        foreach ($suggestions as $suggestion) {
            if ($this->notify($suggestion)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Text one bidder, and record the attempt either way.
     *
     * The win/lose decision is read from the suggestion itself
     * (BidSuggestion::isWinner(), which requires پاکت ب to be finalised), not
     * passed in as a flag — so this cannot be called with the wrong template
     * for the row it was handed.
     */
    public function notify(BidSuggestion $suggestion): bool
    {
        $user = $suggestion->user;

        // No account (a deleted user) or no number means there is nobody to
        // text. Not an error worth stopping the loop for.
        if (! $user || blank($user->mobile)) {
            return false;
        }

        $won = $suggestion->isWinner();
        $template = $won ? 'bid_won' : 'bid_declined';

        // The person's own name, NOT display_name: the templates address a
        // human («جناب آقای/سرکار خانم ...»), so a company account's bidder
        // should still be greeted by their name rather than the company's.
        $params = [
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'tender' => (string) $suggestion->bid?->title,
        ];

        try {
            $result = $this->sms->send($user->mobile, $template, $params);
        } catch (Throwable $e) {
            // A missing template ID in config throws (see MsgwaySmsDriver).
            // Log it and move on to the next bidder — the review is already
            // committed and the remaining bidders still deserve their text.
            Log::warning('sms.bid_result.exception', [
                'suggestion' => $suggestion->id,
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Same log row shape the OTP sends write, so the provider's support
        // desk can be given a trace id for any message on the bill.
        SentSmsLog::create([
            'mobile' => $user->mobile,
            'purpose' => $won ? self::PURPOSE_WON : self::PURPOSE_DECLINED,
            'provider' => config('sms.default'),
            'template' => $template,
            'status' => $result->ok ? 'sent' : 'failed',
            'reference_id' => $result->referenceId,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'trace_id' => $result->traceId,
        ]);

        return $result->ok;
    }
}
