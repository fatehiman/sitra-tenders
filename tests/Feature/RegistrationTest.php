<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Filament\Auth\Register;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\OtpService;
use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

/**
 * Covers the three-step registration wizard (App\Filament\Auth\Register).
 *
 * Filament's testing helpers used here:
 *   fillForm([...])       set form fields — they all live under `data`, and
 *                         the call MERGES, so each step can fill its own.
 *   goToWizardStep($n)    press «بعدی» to reach step $n: validates step
 *                         $n - 1 and runs its afterValidation() hook, which
 *                         is where this wizard sends and checks the OTP.
 *   call('register')      submit the last step.
 *
 * Use goToWizardStep(), NOT goToNextWizardStep(). The wizard's current step
 * lives in Alpine in the browser, not on the PHP component — every request
 * rebuilds the schema with the index back at 0 — so goToNextWizardStep()
 * would re-run step 1 forever (and, here, re-send the SMS and trip the
 * resend cooldown). goToWizardStep() derives the index from the argument.
 * For the same reason, do not assert the current step after a FAILED
 * transition: Wizard::nextStep() bumps its index before validating.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** The valid, non-company half of the form — step 3's fields. */
    private const DETAILS = [
        'first_name' => 'علی',
        'last_name' => 'رضایی',
        'national_id' => '0499370899',
        'person_type' => 'individual',
        'password' => 'Secret123',
        'passwordConfirmation' => 'Secret123',
    ];

    public function test_individual_can_register_after_confirming_otp(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        // Step 1 — the number alone is enough to get a code sent.
        $component = Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(2);

        $this->assertSame('09121234567', $fakeSms->lastMobile);
        $this->assertNotNull($fakeSms->lastCode);
        // Nothing is persisted before the code is confirmed AND the rest of
        // the form is submitted — an abandoned wizard leaves no user row.
        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);

        // Step 2 — the code.
        $component
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(3);

        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);

        // Step 3 — the rest, then submit.
        $component
            ->fillForm(self::DETAILS)
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::where('mobile', '09121234567')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole(RoleName::User->value));
        $this->assertAuthenticatedAs($user);
        // The challenge is deleted on success so it can never be replayed.
        $this->assertSame(0, OtpVerification::where('mobile', '09121234567')->count());
    }

    public function test_wrong_otp_code_is_rejected_and_no_user_is_created(): void
    {
        $this->seed(RoleSeeder::class);

        $this->app->singleton(SmsGateway::class, fn () => new FakeSmsGateway);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => '000000'])
            ->goToWizardStep(3)
            ->assertHasFormErrors(['otp_code']);

        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);
        $this->assertGuest();
    }

    /**
     * The heart of the reordering: no SMS is paid for until the number
     * itself is usable. The old single-page form validated everything at
     * once, so this could not be tested in isolation.
     */
    public function test_a_malformed_mobile_sends_no_sms(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '12345'])
            ->goToWizardStep(2)
            ->assertHasFormErrors(['mobile']);

        $this->assertNull($fakeSms->lastCode);
    }

    public function test_an_already_registered_mobile_sends_no_sms(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        User::factory()->create(['mobile' => '09121234567']);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->assertHasFormErrors(['mobile']);

        $this->assertNull($fakeSms->lastCode);
    }

    public function test_failed_send_shows_the_provider_reason(): void
    {
        $this->seed(RoleSeeder::class);

        // The real msgway failure this reproduces: an account-level block
        // that no retry can clear, previously reported as a bare
        // "try again" with the reason visible only in sent_sms_log.
        $this->app->singleton(SmsGateway::class, fn () => new class implements SmsGateway
        {
            public function send(string $mobile, string $templateKey, array $params = []): SmsResult
            {
                return SmsResult::failure(
                    errorCode: '200101020',
                    errorMessage: 'حساب کاربری شما تایید نشده است',
                    traceId: 'W8d15WYZqVhvNud',
                );
            }
        });

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->assertHasFormErrors([
                'mobile' => 'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید. (حساب کاربری شما تایید نشده است)',
            ]);

        $this->assertDatabaseHas('sent_sms_log', [
            'mobile' => '09121234567',
            'status' => 'failed',
            'error_code' => '200101020',
            'trace_id' => 'W8d15WYZqVhvNud',
        ]);
        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);
    }

    public function test_company_registration_requires_company_fields(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->fillForm([...self::DETAILS, 'person_type' => 'company'])
            ->call('register')
            ->assertHasFormErrors(['company_name', 'company_national_id']);

        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);
    }

    /**
     * شناسه ملی is a format check only — any 11-digit number is accepted.
     *
     * '12345678901' fails the commonly-published شناسه ملی checksum, which
     * is exactly the point: that checksum rejected real, currently-issued
     * IDs, so it was removed. This test exists to stop someone
     * well-meaningly adding it back.
     */
    public function test_company_national_id_accepts_any_eleven_digit_number(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->fillForm([
                ...self::DETAILS,
                'person_type' => 'company',
                'company_name' => 'شرکت نمونه',
                'company_national_id' => '12345678901',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'mobile' => '09121234567',
            'company_national_id' => '12345678901',
        ]);
    }

    /** ...but anything that is not exactly 11 digits is still rejected. */
    public function test_company_national_id_must_be_exactly_eleven_digits(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->fillForm([
                ...self::DETAILS,
                'person_type' => 'company',
                'company_name' => 'شرکت نمونه',
                'company_national_id' => '1234567890',
            ])
            ->call('register')
            ->assertHasFormErrors(['company_national_id']);
    }

    /**
     * The ten-minute window, and the reason it is enforced from the database
     * rather than from the wizard's own state.
     *
     * This test deliberately does NOT touch the component's properties — it
     * ages the `otp_verifications` row instead, which is exactly what a
     * visitor who wandered off mid-form would produce. A tampered client
     * cannot get past this, because the client has no say in it.
     */
    public function test_registration_expires_ten_minutes_after_the_code_is_confirmed(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        $component = Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->assertWizardCurrentStep(3);

        // Push the verification just past the window.
        OtpVerification::where('mobile', '09121234567')->update([
            'verified_at' => now()->subSeconds(OtpService::REGISTRATION_WINDOW_SECONDS + 1),
        ]);

        $component
            ->fillForm(self::DETAILS)
            ->call('register')
            // Back to a clean step 1 rather than an inline error: the proof
            // of ownership is gone, so there is nothing here left to fix.
            ->assertRedirect(filament()->getRegistrationUrl());

        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);
        $this->assertGuest();
    }

    /**
     * Changing the mobile number after passing the OTP step must fail: the
     * verified row is keyed by the number that was actually proved.
     */
    public function test_swapping_the_mobile_after_verification_is_rejected(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => $fakeSms->lastCode])
            ->goToWizardStep(3)
            ->fillForm([...self::DETAILS, 'mobile' => '09129999999'])
            ->call('register')
            ->assertRedirect(filament()->getRegistrationUrl());

        $this->assertDatabaseMissing('users', ['mobile' => '09129999999']);
        $this->assertGuest();
    }
}
