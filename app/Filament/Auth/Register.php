<?php

namespace App\Filament\Auth;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Exceptions\OtpThrottledException;
use App\Models\User;
use App\Rules\IranianNationalId;
use App\Services\OtpService;
use App\Sms\SmsResult;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Radio;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Public registration, as a THREE-STEP WIZARD inside the Filament panel.
 *
 * ---------------------------------------------------------------------------
 * Why this is a Filament page and not a hand-written Blade/Livewire page
 * ---------------------------------------------------------------------------
 * It used to be one (App\Livewire\Register + resources/views/livewire/
 * register.blade.php, both now deleted). That page was the only screen in the
 * app built from hand-written Tailwind, which meant it drifted from the panel
 * visually and had to re-solve RTL, fonts, dark mode and validation display
 * by hand. It also had a real bug: its OTP modal sat *outside* the Livewire
 * component's single root element, and Livewire only ever patches the first
 * root element — so the SMS went out and the box asking for the code was
 * silently thrown away before it ever reached the browser.
 *
 * Extending Filament's own Register page gets all of that for free and makes
 * the class impossible to have that bug: there is no hand-written markup left.
 *
 * ---------------------------------------------------------------------------
 * The flow
 * ---------------------------------------------------------------------------
 *   Step 1 «شماره موبایل»   — just the mobile number. Pressing «بعدی» runs
 *                             the field's validation and, only if it passes,
 *                             texts a 6-digit code. Nothing is persisted
 *                             except the hashed code.
 *   Step 2 «کد تایید»       — type the code. «بعدی» checks it against the
 *                             hash; a wrong or expired code keeps you here.
 *                             A «ارسال مجدد کد» button re-sends (60s cooldown).
 *   Step 3 «اطلاعات کاربر»  — everything else: name, کد ملی, نوع شخص, the
 *                             two حقوقی fields, password. Submitting creates
 *                             the account and logs straight in.
 *
 * Verifying the number BEFORE asking for eight more fields is the whole point
 * of the ordering: the old single-page form made people fill in everything
 * and only then discover the SMS could not be delivered.
 *
 * The visitor has ten minutes from passing step 2 to finish step 3
 * (OtpService::REGISTRATION_WINDOW_SECONDS). Past that, submitting drops them
 * back to step 1 with an explanation rather than failing obscurely.
 *
 * ---------------------------------------------------------------------------
 * A note on trust
 * ---------------------------------------------------------------------------
 * A Livewire component's public state round-trips through the browser, so
 * "the wizard says I am on step 3" proves nothing. Every real decision here
 * is re-made on the server from the database:
 *   - register() refuses unless OtpService::verifiedWithin() finds a
 *     verified, in-window row for exactly the submitted mobile number;
 *   - the mobile/کد ملی/شناسه ملی uniqueness rules are re-checked at submit
 *     time, not only when the field was first filled in.
 */
class Register extends BaseRegister
{
    /*
     * ---- The form -------------------------------------------------------
     */

    /**
     * The whole form is a single Wizard component.
     *
     * Filament's base class puts the submit button in the form's *footer*;
     * a wizard needs it inside the last step instead, next to «قبلی». That
     * swap happens in getFormContentComponent() further down.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->mobileStep(),
                    $this->otpStep(),
                    $this->detailsStep(),
                ])
                    // Rendered HTML, not an Action object — Wizard::submitAction()
                    // takes markup so it can drop it straight into the final
                    // step's button row. <x-filament::button> is one of
                    // Filament's own Blade components, so it is styled by the
                    // panel's compiled CSS (hand-written Tailwind utilities
                    // would NOT be — see ARCHITECTURE.md, "Panel CSS has no
                    // Tailwind utilities").
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit">ثبت‌نام</x-filament::button>'
                    )))
                    // Off by default, and it must stay off: skippable() would
                    // let someone jump to step 3 without ever passing the OTP
                    // step, because Wizard::nextStep() only validates the
                    // current step when it is NOT skippable.
                    ->skippable(false),
            ]);
    }

    /**
     * Step 1 — the mobile number, and nothing else.
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
                    // Iranian mobile numbers, stored in local format: 09 + 9
                    // digits. Converted to E.164 only at the SMS gateway.
                    ->rule('regex:/^09\d{9}$/')
                    // Re-checked on submit as well; a number can be taken by
                    // someone else between step 1 and step 3.
                    ->unique(User::class, 'mobile')
                    ->validationMessages([
                        'regex' => 'شماره موبایل باید به شکل ۰۹XXXXXXXXX باشد.',
                        'unique' => 'این شماره موبایل قبلاً ثبت شده است.',
                    ]),
            ])
            /*
             * afterValidation() runs when «بعدی» is pressed, AFTER this step's
             * fields have validated and BEFORE the wizard moves on. That is
             * exactly the right moment to spend money on an SMS: the number
             * is well-formed and not already registered.
             *
             * Throwing here (ValidationException or Halt) cancels the move,
             * so a failed send leaves the visitor on this step with the
             * reason shown under the field.
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
                    // inputmode/autocomplete let phones show a numeric keypad
                    // and offer the code straight from the SMS notification.
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->autocomplete('one-time-code')
                    ->rule('regex:/^\d{6}$/')
                    // Never dehydrated into the data that creates the user —
                    // it has done its job by the time step 3 is submitted.
                    ->dehydrated(false)
                    ->validationMessages([
                        'required' => 'کد تایید را وارد کنید.',
                        'length' => 'کد تایید باید ۶ رقم باشد.',
                        'regex' => 'کد تایید باید ۶ رقم باشد.',
                    ]),
                // A schema-level action button: clicking it calls the closure
                // over Livewire without submitting the form.
                SchemaActions::make([
                    Action::make('resendOtp')
                        ->label('ارسال مجدد کد')
                        ->link()
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Get $get): void {
                            $this->issueOtp($get('mobile'), notify: true);
                        }),
                ]),
            ])
            /*
             * Check the typed code. OtpService::verify() counts failed
             * attempts and stamps verified_at on success — that stamp, not
             * anything in this component, is what register() later trusts.
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
     * Step 3 — everything else about the account.
     */
    private function detailsStep(): Step
    {
        return Step::make('اطلاعات کاربر')
            ->description('حداکثر ۱۰ دقیقه پس از تایید شماره، این مرحله را کامل کنید.')
            ->icon(Heroicon::OutlinedUser)
            ->columns(2)
            ->schema([
                TextInput::make('first_name')
                    ->label('نام')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->label('نام خانوادگی')
                    ->required()
                    ->maxLength(255),
                TextInput::make('national_id')
                    ->label('کد ملی')
                    ->required()
                    ->length(10)
                    // Unlike شناسه ملی below, کد ملی IS checksum-validated.
                    ->rule(new IranianNationalId)
                    ->unique(User::class, 'national_id')
                    ->validationMessages([
                        'unique' => 'این کد ملی قبلاً ثبت شده است.',
                    ]),
                Radio::make('person_type')
                    ->label('نوع شخص')
                    ->required()
                    ->default(PersonType::Individual->value)
                    ->options([
                        PersonType::Individual->value => 'حقیقی',
                        PersonType::Company->value => 'حقوقی',
                    ])
                    ->inline()
                    // .live equivalent: re-render on change so the two
                    // company fields below appear and disappear immediately.
                    ->live(),
                TextInput::make('company_name')
                    ->label('نام شرکت')
                    ->maxLength(255)
                    // A hidden Filament field is removed from the state
                    // entirely, so switching back to حقیقی both hides the
                    // field AND drops whatever was typed in it — no stale
                    // company name can reach the database.
                    ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                    ->required(fn (Get $get): bool => $get('person_type') === PersonType::Company->value),
                TextInput::make('company_national_id')
                    ->label('شناسه ملی')
                    ->length(11)
                    /*
                     * digits:11 and nothing more, ON PURPOSE. The widely
                     * circulated شناسه ملی checksum rejects a meaningful
                     * share of real, currently-issued IDs and locked
                     * legitimate companies out of registering, so there is
                     * deliberately no rule class for it. Do not "fix" this.
                     */
                    ->rule('digits:11')
                    ->unique(User::class, 'company_national_id')
                    ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                    ->required(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                    ->validationMessages([
                        'digits' => 'شناسه ملی باید یک عدد ۱۱ رقمی باشد.',
                        'unique' => 'این شناسه ملی قبلاً ثبت شده است.',
                    ]),
                // Reused straight from Filament's base Register page: these
                // already hash the password on dehydration, compare it with
                // the confirmation field, and apply Laravel's default
                // password policy.
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    /*
     * ---- Sending the code -----------------------------------------------
     */

    /**
     * Text a fresh code to $mobile, translating every failure mode into a
     * message the visitor can act on.
     *
     * Shared by step 1's «بعدی» and step 2's «ارسال مجدد کد», which differ
     * only in where the outcome is shown: the first must block the wizard by
     * throwing (so a failed send does not advance to a step asking for a code
     * that will never arrive), the second is a standalone button and reports
     * through a notification instead.
     */
    private function issueOtp(?string $mobile, bool $notify = false): void
    {
        try {
            $result = app(OtpService::class)->issue((string) $mobile, request()?->ip());
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
            Notification::make()
                ->title('کد تایید دوباره ارسال شد.')
                ->success()
                ->send();
        }
    }

    /**
     * Either throw (blocking the wizard) or pop a notification, depending on
     * which button the visitor pressed. See issueOtp() above.
     */
    private function reportOtpFailure(string $message, bool $notify): void
    {
        if ($notify) {
            Notification::make()
                ->title($message)
                ->danger()
                ->send();

            return;
        }

        throw ValidationException::withMessages(['data.mobile' => $message]);
    }

    /**
     * The SMS provider's own reason, in parentheses.
     *
     * msgway's messages are already Persian and end-user readable («حساب
     * کاربری شما تایید نشده است»); a transport failure gives a raw cURL
     * string instead, which is ugly but still far more actionable than
     * silence — the alternative is a visitor and an operator both staring at
     * "try again" while nothing can possibly succeed. Collapsed to one line
     * and length-capped so it cannot wreck the layout.
     */
    private function providerReason(SmsResult $result): string
    {
        $reason = trim((string) ($result->errorMessage ?: $result->errorCode));

        if ($reason === '') {
            return '';
        }

        return ' ('.Str::limit(preg_replace('/\s+/u', ' ', $reason), 160).')';
    }

    /*
     * ---- Creating the account -------------------------------------------
     */

    /**
     * The final submit.
     *
     * The one thing added on top of Filament's own register() is the
     * ten-minute window check, done BEFORE the parent runs so no transaction
     * is opened for a request that cannot succeed. It reads the database
     * rather than this component's state — see the class docblock.
     *
     * On expiry the visitor is sent back to a fresh step 1 rather than shown
     * an inline error: their proof of ownership is gone, so there is nothing
     * on this page left to correct.
     */
    public function register(): ?RegistrationResponse
    {
        if (! app(OtpService::class)->verifiedWithin((string) ($this->data['mobile'] ?? ''))) {
            Notification::make()
                ->title('مهلت تکمیل ثبت‌نام به پایان رسید.')
                ->body('لطفاً شماره موبایل خود را دوباره وارد کنید و کد تایید جدیدی دریافت کنید.')
                ->danger()
                ->persistent()
                ->send();

            // navigate: false forces a real page load, so the wizard restarts
            // from step 1 with completely empty state.
            $this->redirect(filament()->getRegistrationUrl(), navigate: false);

            return null;
        }

        return parent::register();
    }

    /**
     * Build the `users` row.
     *
     * Overridden because the parent does a bare `User::create($data)`, and
     * three of the columns must NOT come from the form:
     *   - mobile_verified_at — stamped by us, because we just proved it;
     *   - is_active          — public sign-ups are active immediately;
     *   - the `user` role    — only an admin may create staff/admin accounts.
     * ($data['password'] is already hashed: the parent's password component
     * hashes it on dehydration.)
     *
     * Everything here runs inside the transaction the parent opened, so a
     * failure to assign the role rolls the user row back with it — there can
     * never be an account with no role.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $isCompany = ($data['person_type'] ?? null) === PersonType::Company->value;

        $user = $this->getUserModel()::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'mobile' => $data['mobile'],
            'national_id' => $data['national_id'],
            'person_type' => $data['person_type'],
            'company_name' => $isCompany ? $data['company_name'] : null,
            'company_national_id' => $isCompany ? $data['company_national_id'] : null,
            'password' => $data['password'],
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->assignRole(RoleName::User->value);

        // The challenge has served its purpose — delete it so a verified code
        // can never be replayed to create a second account.
        app(OtpService::class)->forget($data['mobile']);

        return $user;
    }

    /**
     * Filament's per-identity registration throttle keys off an email
     * address, which this app does not have. Same limit, keyed by mobile.
     *
     * The parameter keeps the parent's name and signature so the inherited
     * register() keeps calling it; the value it passes is always empty here
     * (there is no `email` key in our form state), so it is ignored.
     */
    protected function isRegisterRateLimited(string $email): bool
    {
        $mobile = (string) ($this->data['mobile'] ?? '');

        if (blank($mobile)) {
            return false;
        }

        $rateLimitingKey = 'filament-register:'.sha1($mobile);

        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 2)) {
            return parent::isRegisterRateLimited($mobile);
        }

        RateLimiter::hit($rateLimitingKey);

        return false;
    }

    /*
     * ---- Chrome ----------------------------------------------------------
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
            ->livewireSubmitHandler('register');
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'ثبت‌نام کاربر جدید';
    }

    public function getTitle(): string|Htmlable
    {
        return 'ثبت‌نام';
    }

    /**
     * Filament sizes an auth page for a short login form; a three-step wizard
     * with a two-column final step needs noticeably more room than that.
     */
    public function getMaxWidth(): ?string
    {
        return '42rem';
    }
}
