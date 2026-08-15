<?php

namespace App\Livewire;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Exceptions\OtpThrottledException;
use App\Models\User;
use App\Rules\IranianNationalId;
use App\Services\OtpService;
use App\Sms\SmsResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Single-page registration: fill the whole form, click "ارسال کد تایید"
 * (validates everything first), confirm the 6-digit OTP in a modal — no
 * page navigation, no multi-step wizard. See ARCHITECTURE.md's
 * "Registration + OTP flow" for the full rationale.
 */
class Register extends Component
{
    /*
     * Every `public` property on a Livewire component is automatically kept
     * in sync with the browser: the Blade view binds inputs to them with
     * wire:model, and Livewire ships the values back and forth on each
     * request. That is why the form has no <form action> and no controller.
     */

    public string $first_name = '';

    public string $last_name = '';

    public string $mobile = '';

    public string $national_id = '';

    public string $person_type = 'individual';

    public string $company_name = '';

    public string $company_national_id = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Flipping this to true is what "opens the modal" — no page change. */
    public bool $showOtpModal = false;

    public string $otp_code = '';

    /** Error shown inside the OTP modal (wrong/expired code). */
    public ?string $otpError = null;

    /** Error shown above the form itself (e.g. the SMS failed to send). */
    public ?string $formError = null;

    /**
     * Validation rules for the whole form.
     *
     * Livewire calls this every time `$this->validate()` runs, which is why
     * it is a method and not a static `$rules` property — the company fields
     * are only required when «حقوقی» (company) is the selected person type,
     * and that is only knowable at runtime.
     */
    protected function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Iranian mobile numbers, stored in local format: 09 + 9 digits.
            // `unique:users,mobile` = no other row in `users` may have it.
            'mobile' => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            // کد ملی (individual national ID) IS checksum-validated — see
            // App\Rules\IranianNationalId.
            'national_id' => ['required', new IranianNationalId, 'unique:users,national_id'],
            'person_type' => ['required', 'in:individual,company'],
            // `confirmed` makes Laravel compare it with $password_confirmation.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        // Company («حقوقی») accounts carry two extra fields; individual
        // («حقیقی») accounts must not be asked for them at all.
        if ($this->person_type === PersonType::Company->value) {
            $rules['company_name'] = ['required', 'string', 'max:255'];
            /*
             * شناسه ملی (company national ID) is deliberately NOT checksum
             * validated — `digits:11` means "exactly 11 characters, all of
             * them digits", and nothing more.
             *
             * This is a product decision, not an oversight. The widely
             * circulated شناسه ملی checksum formula rejects a meaningful
             * share of real, currently-issued IDs, so enforcing it locked
             * legitimate companies out of registering. Uniqueness is still
             * enforced, so the column stays a usable key.
             */
            $rules['company_national_id'] = ['required', 'digits:11', 'unique:users,company_national_id'];
        }

        return $rules;
    }

    /**
     * Persian replacements for Laravel's default English validation
     * messages. The array key is "<field>.<rule>".
     */
    protected function messages(): array
    {
        return [
            'mobile.regex' => 'شماره موبایل باید به شکل ۰۹XXXXXXXXX باشد.',
            'mobile.unique' => 'این شماره موبایل قبلاً ثبت شده است.',
            'national_id.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'company_national_id.digits' => 'شناسه ملی باید یک عدد ۱۱ رقمی باشد.',
            'company_national_id.unique' => 'این شناسه ملی قبلاً ثبت شده است.',
            'password.confirmed' => 'رمز عبور و تکرار آن یکسان نیستند.',
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.',
        ];
    }

    /**
     * Livewire "lifecycle hook": automatically called whenever the
     * $person_type property changes (the radio buttons use wire:model.live).
     *
     * Clearing the company fields on the way back to «حقیقی» matters because
     * the inputs are hidden, not removed — without this, values typed before
     * switching would still be sitting in the component state.
     */
    public function updatedPersonType(): void
    {
        if ($this->person_type !== PersonType::Company->value) {
            $this->company_name = '';
            $this->company_national_id = '';
        }
    }

    /**
     * Step 1 of registration: validate everything, then text a 6-digit code.
     *
     * The full form is validated BEFORE the SMS goes out on purpose — an SMS
     * costs money on every send, so there is no point paying for one when
     * the registration could not have succeeded anyway. No `users` row is
     * created at this point; nothing is persisted except the hashed code.
     */
    public function sendOtp(): void
    {
        $this->formError = null;
        // Throws a validation exception on failure, which Livewire catches
        // and turns into the @error(...) messages in the Blade view.
        $this->validate();

        try {
            $result = app(OtpService::class)->issue($this->mobile, request()?->ip());
        } catch (OtpThrottledException $e) {
            $this->formError = "لطفاً {$e->retryAfterSeconds} ثانیه دیگر دوباره تلاش کنید.";

            return;
        }

        if (! $result->ok) {
            $this->formError = 'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.'
                .$this->providerReason($result);

            return;
        }

        // The code is on its way — open the modal to collect it.
        $this->otp_code = '';
        $this->otpError = null;
        $this->showOtpModal = true;
    }

    /**
     * The SMS provider's own reason, in parentheses, appended to the generic
     * failure line. msgway's messages are already Persian and end-user
     * readable («حساب کاربری شما تایید نشده است»); a transport failure gives
     * a raw cURL string instead, which is ugly but still far more actionable
     * than silence — the alternative is a visitor and an operator both
     * staring at "try again" while nothing can possibly succeed. Collapsed
     * to one line and length-capped so it can't wreck the layout.
     */
    private function providerReason(SmsResult $result): string
    {
        $reason = trim((string) ($result->errorMessage ?: $result->errorCode));

        if ($reason === '') {
            return '';
        }

        return ' ('.Str::limit(preg_replace('/\s+/u', ' ', $reason), 160).')';
    }

    /**
     * "ارسال مجدد کد" — identical to the first send. OtpService enforces a
     * 60-second cooldown, so spamming this button is harmless.
     */
    public function resendOtp(): void
    {
        $this->sendOtp();
    }

    public function closeOtpModal(): void
    {
        $this->showOtpModal = false;
    }

    /**
     * Step 2 of registration: check the code, then actually create the user.
     *
     * This is the only place a `users` row is ever created by the public
     * flow, so an abandoned or failed registration leaves no partial record
     * behind — there is nothing to clean up.
     */
    public function confirmOtp(): void
    {
        $this->otpError = null;

        // Cheap shape check first, so an obviously-wrong entry doesn't burn
        // one of the five allowed verification attempts.
        if (! preg_match('/^\d{6}$/', $this->otp_code)) {
            $this->otpError = 'کد تایید باید ۶ رقم باشد.';

            return;
        }

        $status = app(OtpService::class)->verify($this->mobile, $this->otp_code);

        if ($status !== 'ok') {
            $this->otpError = match ($status) {
                'expired' => 'کد تایید منقضی شده است. کد جدید درخواست کنید.',
                'too_many_attempts' => 'تعداد تلاش‌های مجاز به پایان رسیده است. کد جدید درخواست کنید.',
                'not_found' => 'کدی برای این شماره یافت نشد. دوباره درخواست دهید.',
                default => 'کد تایید نادرست است.',
            };

            return;
        }

        // Re-validate before writing: form state could in principle be
        // stale (e.g. someone else took the mobile/national ID) between
        // "send OTP" and "confirm OTP".
        $this->validate();

        // DB::transaction rolls everything back if any statement inside
        // throws — so we can never end up with a user row that has no role.
        $user = DB::transaction(function () {
            $user = User::create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'mobile' => $this->mobile,
                'national_id' => $this->national_id,
                'person_type' => $this->person_type,
                'company_name' => $this->person_type === PersonType::Company->value ? $this->company_name : null,
                'company_national_id' => $this->person_type === PersonType::Company->value ? $this->company_national_id : null,
                // Never store a raw password: Hash::make() applies bcrypt.
                'password' => Hash::make($this->password),
                // We just proved they control this number, so stamp it now.
                'mobile_verified_at' => now(),
                'is_active' => true,
            ]);

            // Public sign-ups always get the plain `user` role. Only an
            // admin can create `staff` or `admin` accounts (see UserForm).
            $user->assignRole(RoleName::User->value);

            return $user;
        });

        // The code has served its purpose — delete it so it can't be reused.
        app(OtpService::class)->forget($this->mobile);

        Auth::login($user);
        // Issues a new session ID, which prevents "session fixation" (an
        // attacker who knew the pre-login session ID hijacking the account).
        session()->regenerate();

        // navigate: false forces a real full-page load rather than a
        // Livewire SPA-style swap, so the panel boots with the fresh session.
        $this->redirect('/', navigate: false);
    }

    public function render()
    {
        return view('livewire.register');
    }
}
