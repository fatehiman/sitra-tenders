<?php

namespace App\Services;

use App\Exceptions\OtpThrottledException;
use App\Models\OtpVerification;
use App\Models\SentSmsLog;
use App\Sms\Contracts\SmsGateway;
use Illuminate\Support\Facades\Hash;

/**
 * Registration-mobile OTP challenges. Keyed by mobile only — the `users`
 * row doesn't exist until the code is verified (see ARCHITECTURE.md's
 * "Registration + OTP flow").
 */
class OtpService
{
    private const TTL_SECONDS = 120;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Generate, store (hashed), and send a fresh 6-digit code for $mobile.
     *
     * @throws OtpThrottledException if the previous code for this mobile
     *         was issued less than the resend cooldown ago
     */
    public function issue(string $mobile, ?string $ip = null): bool
    {
        $previous = OtpVerification::where('mobile', $mobile)->latest('id')->first();

        if ($previous && $previous->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            throw new OtpThrottledException(
                self::RESEND_COOLDOWN_SECONDS - $previous->created_at->diffInSeconds(now())
            );
        }

        $code = (string) random_int(100000, 999999);

        OtpVerification::where('mobile', $mobile)->delete();

        OtpVerification::create([
            'mobile' => $mobile,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'ip_address' => $ip,
        ]);

        $result = $this->sms->send($mobile, 'otp', ['code' => $code]);

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

        return $result->ok;
    }

    /**
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

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return 'invalid';
        }

        $otp->update(['verified_at' => now()]);

        return 'ok';
    }

    public function forget(string $mobile): void
    {
        OtpVerification::where('mobile', $mobile)->delete();
    }
}
