<?php

namespace App\Filament\Auth;

use App\Exceptions\OtpThrottledException;
use App\Models\User;
use App\Services\OtpService;
use App\Sms\SmsResult;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * «فراموشی رمز عبور» — reset the password with an SMS code, as a THREE-STEP
 * WIZARD.
 *
 * ---------------------------------------------------------------------------
 * Why this replaces Filament's own password reset entirely
 * ---------------------------------------------------------------------------
 * Filament's flow emails a signed link. This app has NO EMAIL COLUMN at all —
 * accounts are identified by mobile number (see App\Filament\Auth\Login) — so
 * there is nothing to mail a link to. The proof of identity we already trust
 * everywhere else is an SMS code, so this page reuses exactly the machinery
 * registration uses: App\Services\OtpService.
 *
 * The class still EXTENDS Filament's RequestPasswordReset rather than being a
 * page of its own, and that is what buys the whole screen for free — the
 * simple auth layout, RTL, Vazirmatn, the «بازگشت به ورود» link, the
 * per-session rate limiter, and the "forgot password?" link Filament renders
 * under the login form (which only appears when the panel has
 * ->passwordReset(...) configured — see AppPanelProvider).
 *
 * ---------------------------------------------------------------------------
 * The flow
 * ---------------------------------------------------------------------------
 *   Step 1 «شماره موبایل»    — the mobile number of an existing account.
 *                              Pressing «بعدی» texts a 6-digit code.
 *   Step 2 «کد تایید»        — type the code; «بعدی» checks it against the
 *                              hash. «ارسال مجدد کد» re-sends (60s cooldown).
 *   Step 3 «رمز عبور جدید»   — the new password twice. Submitting saves it,
 *                              logs the person in and lands them on مناقصات.
 *
 * Same ordering logic as registration: verify the number BEFORE asking for
 * anything else, so no SMS is paid for on a number that cannot be used and
 * nobody types a password before finding out the code cannot be delivered.
 *
 * ---------------------------------------------------------------------------
 * A note on trust
 * ---------------------------------------------------------------------------
 * A Livewire component's public state round-trips through the browser, so
 * "the wizard says I am on step 3" proves nothing — a visitor can put whatever
 * they like in it. What actually authorises the password change is the
 * database: request() below refuses unless OtpService::verifiedWithin() finds
 * a verified, in-window row for exactly the submitted mobile number. Changing
 * the number after passing step 2 therefore fails closed, because there is no
 * verified row for the new one.
 *
 * ---------------------------------------------------------------------------
 * On telling the visitor "no such account"
 * ---------------------------------------------------------------------------
 * Step 1 says so explicitly, which does let someone check whether a number is
 * registered here. That is a deliberate, narrow trade: the registration form
 * already answers the same question («این شماره موبایل قبلاً ثبت شده است»), so
 * hiding it here would protect nothing while making a stuck user wait for an
 * SMS that is never coming. It also means we never pay for a message to a
 * number that has no account.
 */
class ForgotPassword extends RequestPasswordReset
{
    /*
     * ---- The form ---------------------------------------------------------
     */

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->mobileStep(),
                    $this->otpStep(),
                    $this->passwordStep(),
                ])
                    // Rendered HTML rather than an Action object, because
                    // Wizard::submitAction() drops markup straight into the
                    // final step's button row. <x-filament::button> is one of
                    // Filament's own Blade components, so the panel's compiled
                    // CSS styles it — hand-written Tailwind would not be (see
                    // ARCHITECTURE.md, "Panel CSS has no Tailwind utilities").
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit">ثبت رمز عبور جدید</x-filament::button>'
                    )))
                    // Must stay off: skippable() would let someone jump
                    // straight to step 3 without ever passing the OTP step,
                    // because Wizard::nextStep() only validates the current
                    // step when it is NOT skippable.
                    ->skippable(false)
                    /*
                     * Enter means «بعدی», not «ثبت رمز عبور جدید» — the exact
                     * fix the registration and bid wizards both needed, for
                     * the identical reason: Enter in a text input submits the
                     * surrounding <form>, whose handler is the LAST step's
                     * action. Without this, typing a mobile number and pressing
                     * Enter would run a password reset with no code entered.
                     */
                    ->extraAttributes([
                        'x-on:keydown.enter' => 'if (! isLastStep()) { $event.preventDefault(); requestNextStep() }',
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Step 1 — which account, and nothing else.
     */
    private function mobileStep(): Step
    {
        return Step::make('شماره موبایل')
            ->description('کد تایید به این شماره پیامک می‌شود.')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->schema([
                TextInput::make('mobile')
                    ->label('شماره موبایل')
                    ->tel()
                    ->required()
                    ->autofocus()
                    ->placeholder('09XXXXXXXXX')
                    // Iranian mobile numbers in local format: 09 + 9 digits.
                    // Converted to E.164 only at the SMS gateway.
                    ->rule('regex:/^09\d{9}$/')
                    // The mirror image of registration's ->unique(): here the
                    // number MUST already belong to an account.
                    ->exists(User::class, 'mobile')
                    ->validationMessages([
                        'regex' => 'شماره موبایل باید به شکل ۰۹XXXXXXXXX باشد.',
                        'exists' => 'کاربری با این شماره موبایل یافت نشد.',
                    ]),
            ])
            /*
             * afterValidation() runs when «بعدی» is pressed, AFTER this step's
             * field has validated and BEFORE the wizard moves on — which is
             * exactly the right moment to spend money on an SMS: the number is
             * well-formed and belongs to a real account.
             */
            ->afterValidation(function (Get $get): void {
                $this->issueOtp($get('mobile'));
            });
    }

    /**
     * Step 2 — the 6-digit code.
     */
    private function otpStep(): Step
    {
        return Step::make('کد تایید')
            ->description('کد ۶ رقمی پیامک‌شده را وارد کنید.')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->schema([
                TextInput::make('otp_code')
                    ->label('کد تایید')
                    ->required()
                    ->length(6)
                    // Lets phones show a numeric keypad and offer the code
                    // straight from the SMS notification.
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->autocomplete('one-time-code')
                    ->rule('regex:/^\d{6}$/')
                    // Never dehydrated: it has no business being in the data
                    // the password change reads.
                    ->dehydrated(false)
                    ->validationMessages([
                        'required' => 'کد تایید را وارد کنید.',
                        'length' => 'کد تایید باید ۶ رقم باشد.',
                        'regex' => 'کد تایید باید ۶ رقم باشد.',
                    ]),
                // A schema-level action: clicking it calls the closure over
                // Livewire without submitting the form.
                SchemaActions::make([
                    Action::make('resendOtp')
                        ->label('ارسال مجدد کد')
                        ->link()
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(fn (Get $get) => $this->issueOtp($get('mobile'), notify: true)),
                ]),
            ])
            /*
             * OtpService::verify() counts failed attempts and stamps
             * verified_at on success — that stamp, not anything in this
             * component, is what request() later trusts.
             */
            ->afterValidation(function (Get $get): void {
                $status = app(OtpService::class)->verify($get('mobile'), (string) $get('otp_code'));

                if ($status === 'ok') {
                    return;
                }

                throw ValidationException::withMessages([
                    'data.otp_code' => match ($status) {
                        'expired' => 'کد تایید منقضی شده است. کد جدید درخواست کنید.',
                        'too_many_attempts' => 'تعداد تلاش‌های مجاز به پایان رسیده است. کد جدید درخواست کنید.',
                        'not_found' => 'کدی برای این شماره یافت نشد. دوباره درخواست دهید.',
                        default => 'کد تایید نادرست است.',
                    },
                ]);
            });
    }

    /**
     * Step 3 — the new password, twice.
     *
     * PasswordRule::default() is Laravel's own configured password policy (see
     * AppServiceProvider), the same one registration applies, so the two
     * screens can never disagree about what a valid password is.
     */
    private function passwordStep(): Step
    {
        return Step::make('رمز عبور جدید')
            ->description('حداکثر ۱۰ دقیقه پس از تایید شماره، این مرحله را کامل کنید.')
            ->icon(Heroicon::OutlinedKey)
            ->schema([
                TextInput::make('password')
                    ->label('رمز عبور جدید')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->rule(PasswordRule::default())
                    // ->same() compares against the other field's raw value.
                    // It has to be this way round (and not ->confirmed()),
                    // because the confirmation field below is never dehydrated.
                    ->same('password_confirmation')
                    ->validationAttribute('رمز عبور'),
                TextInput::make('password_confirmation')
                    ->label('تکرار رمز عبور جدید')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    // Nothing stores this field — it exists only to be
                    // compared with the one above.
                    ->dehydrated(false),
            ]);
    }

    /*
     * ---- Sending the code -------------------------------------------------
     */

    /**
     * Text a fresh code to $mobile, translating every failure mode into a
     * message the visitor can act on.
     *
     * A copy of the same method on App\Filament\Auth\Register, purpose label
     * aside — see there for why the two callers need different failure
     * behaviour ($notify).
     */
    private function issueOtp(?string $mobile, bool $notify = false): void
    {
        try {
            $result = app(OtpService::class)->issue(
                (string) $mobile,
                request()?->ip(),
                OtpService::PURPOSE_PASSWORD_RESET,
            );
        } catch (OtpThrottledException $e) {
            $this->reportOtpFailure(
                "لطفاً {$e->retryAfterSeconds} ثانیه دیگر دوباره تلاش کنید.",
                $notify,
            );

            return;
        }

        if (! $result->ok) {
            $this->reportOtpFailure(
                'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.'.$this->providerReason($result),
                $notify,
            );

            return;
        }

        if ($notify) {
            Notification::make()->title('کد تایید دوباره ارسال شد.')->success()->send();
        }
    }

    /**
     * Either throw (blocking the wizard) or pop a notification, depending on
     * which button the visitor pressed. See issueOtp() above.
     */
    private function reportOtpFailure(string $message, bool $notify): void
    {
        if ($notify) {
            Notification::make()->title($message)->danger()->send();

            return;
        }

        throw ValidationException::withMessages(['data.mobile' => $message]);
    }

    /** The SMS provider's own reason, collapsed to one line and capped. */
    private function providerReason(SmsResult $result): string
    {
        $reason = trim((string) ($result->errorMessage ?: $result->errorCode));

        if ($reason === '') {
            return '';
        }

        return ' ('.Str::limit(preg_replace('/\s+/u', ' ', $reason), 160).')';
    }

    /*
     * ---- Saving the new password ------------------------------------------
     */

    /**
     * The final submit.
     *
     * Named request() because that is the method the parent's form content
     * component submits to (see getFormContentComponent() below); it no longer
     * "requests" anything, it performs the reset.
     *
     * Three gates, in this order:
     *   1. the per-session rate limiter the parent already provides;
     *   2. OtpService::verifiedWithin() — the real authorisation, read from
     *      the database, never from this component's state;
     *   3. the account still existing and being allowed into the panel.
     */
    public function request(): void
    {
        try {
            // Filament's own throttle for this page. Two submits per minute is
            // plenty for typing one password twice, and it is what stops this
            // page being used to hammer the OTP verification.
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        // Validates every step's fields and hands back the values.
        $data = $this->form->getState();
        $mobile = (string) ($data['mobile'] ?? '');

        if (! app(OtpService::class)->verifiedWithin($mobile)) {
            Notification::make()
                ->title('مهلت تغییر رمز عبور به پایان رسید.')
                ->body('لطفاً شماره موبایل خود را دوباره وارد کنید و کد تایید جدیدی دریافت کنید.')
                ->danger()
                ->persistent()
                ->send();

            // navigate: false forces a real page load, so the wizard restarts
            // from step 1 with completely empty state.
            $this->redirect(filament()->getRequestPasswordResetUrl(), navigate: false);

            return;
        }

        $user = User::where('mobile', $mobile)->first();

        // The account could have been deleted or deactivated between step 1
        // and now. canAccessPanel() is the same gate login goes through, so a
        // deactivated account cannot use this page as a way in.
        if (! $user || ! $user->canAccessPanel(filament()->getCurrentOrDefaultPanel())) {
            Notification::make()
                ->title('این حساب کاربری در دسترس نیست.')
                ->body('برای پیگیری با پشتیبانی تماس بگیرید.')
                ->danger()
                ->send();

            return;
        }

        $this->savePassword($user, (string) $data['password']);

        // The challenge has done its job — delete it so the same code can
        // never be replayed to reset the password again.
        app(OtpService::class)->forget($mobile);

        Notification::make()
            ->title('رمز عبور شما تغییر کرد.')
            ->success()
            ->send();

        /*
         * Log the person straight in, exactly as registration does — they have
         * just proved they hold the phone AND set the password, so sending them
         * to a login form to type it again would be pure friction.
         *
         * session()->forget('url.intended') for the same reason Register does
         * it: "intended" is whatever protected URL the auth middleware happened
         * to stash earlier, which may be a page this role cannot even open.
         */
        Auth::login($user);
        session()->regenerate();
        session()->forget('url.intended');

        $this->redirect(filament()->getUrl(), navigate: false);
    }

    /**
     * Write the new password.
     *
     * #[SensitiveParameter] keeps the raw value out of stack traces. Hashing
     * happens here rather than on the field's dehydration so that the plain
     * value never has to survive past this method.
     */
    private function savePassword(User $user, #[SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }

    /*
     * ---- Chrome -----------------------------------------------------------
     */

    /**
     * Same as the parent's, minus the footer holding the submit button — the
     * wizard renders its own submit button inside the last step (see form()).
     * Leaving the parent's footer in place would show two of them, one of
     * which submits from any step.
     */
    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('request');
    }

    public function getTitle(): string|Htmlable
    {
        return 'فراموشی رمز عبور';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'بازیابی رمز عبور';
    }

    /**
     * Filament sizes an auth page for a short form; a three-step wizard needs
     * noticeably more room than that. Same value the registration wizard uses.
     */
    public function getMaxWidth(): ?string
    {
        return '42rem';
    }
}
