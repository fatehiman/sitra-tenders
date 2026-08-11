<?php

namespace App\Livewire;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Exceptions\OtpThrottledException;
use App\Models\User;
use App\Rules\IranianCompanyNationalId;
use App\Rules\IranianNationalId;
use App\Services\OtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Single-page registration: fill the whole form, click "ارسال کد تایید"
 * (validates everything first), confirm the 6-digit OTP in a modal — no
 * page navigation, no multi-step wizard. See ARCHITECTURE.md's
 * "Registration + OTP flow" for the full rationale.
 */
class Register extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $mobile = '';

    public string $national_id = '';

    public string $person_type = 'individual';

    public string $company_name = '';

    public string $company_national_id = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $showOtpModal = false;

    public string $otp_code = '';

    public ?string $otpError = null;

    public ?string $formError = null;

    protected function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            'national_id' => ['required', new IranianNationalId, 'unique:users,national_id'],
            'person_type' => ['required', 'in:individual,company'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if ($this->person_type === PersonType::Company->value) {
            $rules['company_name'] = ['required', 'string', 'max:255'];
            $rules['company_national_id'] = ['required', new IranianCompanyNationalId, 'unique:users,company_national_id'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'mobile.regex' => 'شماره موبایل باید به شکل ۰۹XXXXXXXXX باشد.',
            'mobile.unique' => 'این شماره موبایل قبلاً ثبت شده است.',
            'national_id.unique' => 'این کد ملی قبلاً ثبت شده است.',
            'company_national_id.unique' => 'این شناسه ملی قبلاً ثبت شده است.',
            'password.confirmed' => 'رمز عبور و تکرار آن یکسان نیستند.',
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.',
        ];
    }

    public function updatedPersonType(): void
    {
        if ($this->person_type !== PersonType::Company->value) {
            $this->company_name = '';
            $this->company_national_id = '';
        }
    }

    public function sendOtp(): void
    {
        $this->formError = null;
        $this->validate();

        try {
            $sent = app(OtpService::class)->issue($this->mobile, request()?->ip());
        } catch (OtpThrottledException $e) {
            $this->formError = "لطفاً {$e->retryAfterSeconds} ثانیه دیگر دوباره تلاش کنید.";

            return;
        }

        if (! $sent) {
            $this->formError = 'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.';

            return;
        }

        $this->otp_code = '';
        $this->otpError = null;
        $this->showOtpModal = true;
    }

    public function resendOtp(): void
    {
        $this->sendOtp();
    }

    public function closeOtpModal(): void
    {
        $this->showOtpModal = false;
    }

    public function confirmOtp(): void
    {
        $this->otpError = null;

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

        $user = DB::transaction(function () {
            $user = User::create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'mobile' => $this->mobile,
                'national_id' => $this->national_id,
                'person_type' => $this->person_type,
                'company_name' => $this->person_type === PersonType::Company->value ? $this->company_name : null,
                'company_national_id' => $this->person_type === PersonType::Company->value ? $this->company_national_id : null,
                'password' => Hash::make($this->password),
                'mobile_verified_at' => now(),
                'is_active' => true,
            ]);

            $user->assignRole(RoleName::User->value);

            return $user;
        });

        app(OtpService::class)->forget($this->mobile);

        Auth::login($user);
        session()->regenerate();

        $this->redirect('/', navigate: false);
    }

    public function render()
    {
        return view('livewire.register');
    }
}
