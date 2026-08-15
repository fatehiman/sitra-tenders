<?php

namespace App\Services;

use App\Exceptions\OtpThrottledException;
use App\Models\OtpVerification;
use App\Models\SentSmsLog;
use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;
use Illuminate\Support\Facades\Hash;

/**
 * Registration-mobile OTP challenges. Keyed by mobile only — the `users`
 * row doesn't exist until the code is verified (see ARCHITECTURE.md's
 * "Registration + OTP flow").
 */
class OtpService
{
    /** How long a code stays valid: 2 minutes. */
    private const TTL_SECONDS = 120;

    /** Wrong guesses allowed before the code is dead. 6 digits = 1,000,000
     *  possibilities, so 5 tries make brute force hopeless. */
    private const MAX_ATTEMPTS = 5;

    /** Minimum gap between two sends to the same number. Each SMS costs
     *  money, so this is a spend limit as much as an abuse limit. */
    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * The SmsGateway is injected rather than constructed here, so tests can
     * hand in a fake that records the code instead of texting it. See
     * AppServiceProvider for where the real one is bound.
     */
    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Generate, store (hashed), and send a fresh 6-digit code for $mobile.
     *
     * Returns the gateway's own result rather than a bool so the caller can
     * show the provider's reason (e.g. msgway's «حساب کاربری شما تایید نشده
     * است») instead of a generic "sending failed" — an unexplained failure
     * here is indistinguishable from a bug to whoever is operating the site.
     *
     * @throws OtpThrottledException if the previous code for this mobile
     *                               was issued less than the resend cooldown ago
     */
    public function issue(string $mobile, ?string $ip = null): SmsResult
    {
        $previous = OtpVerification::where('mobile', $mobile)->latest('id')->first();

        if ($previous && $previous->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            throw new OtpThrottledException(
                self::RESEND_COOLDOWN_SECONDS - $previous->created_at->diffInSeconds(now())
            );
        }

        // random_int() is the cryptographically secure generator. rand() and
        // mt_rand() are predictable from previous outputs and must not be
        // used for anything security-related.
        $code = (string) random_int(100000, 999999);

        // Only ever one live code per number — issuing a new one invalidates
        // whatever came before, so an old SMS can't be replayed later.
        OtpVerification::where('mobile', $mobile)->delete();

        OtpVerification::create([
            'mobile' => $mobile,
            // Stored hashed, exactly like a password: whoever can read the
            // database still cannot read the code.
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'ip_address' => $ip,
        ]);

        // 'otp' is a semantic name, not a provider template ID — the mapping
        // to msgway's numeric template lives in config/sms.php.
        $result = $this->sms->send($mobile, 'otp', ['code' => $code]);

        // Record the attempt either way. A failure row with its trace_id is
        // exactly what the provider's support desk will ask for.
        SentSmsLog::create([
            'mobile' => $mobile,
            'purpose' => 'otp_registration',
            'provider' => config('sms.default'),
            'template' => 'otp',
            'status' => $result->ok ? 'sent' : 'failed',
            'reference_id' => $result->referenceId,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'trace_id' => $result->traceId,
        ]);

        return $result;
    }

    /**
     * Check a code the visitor typed against the stored hash.
     *
     * Returns a status string rather than throwing, because every one of
     * these outcomes is a normal thing a user can do, not an error — and
     * each needs its own Persian message in the modal.
     *
     * @return 'ok'|'not_found'|'expired'|'too_many_attempts'|'invalid'
     */
    public function verify(string $mobile, string $code): string
    {
        $otp = OtpVerification::where('mobile', $mobile)->latest('id')->first();

        if (! $otp) {
            return 'not_found';
        }

        if ($otp->expires_at->isPast()) {
            return 'expired';
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return 'too_many_attempts';
        }

        // The hash cannot be reversed, so we hash the typed code the same
        // way and compare the results. Every failure costs one attempt.
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return 'invalid';
        }

        $otp->update(['verified_at' => now()]);

        return 'ok';
    }

    /**
     * Delete the challenge once it has done its job. Called after the user
     * row is successfully created, so a verified code can never be reused.
     */
    public function forget(string $mobile): void
    {
        OtpVerification::where('mobile', $mobile)->delete();
    }
}
