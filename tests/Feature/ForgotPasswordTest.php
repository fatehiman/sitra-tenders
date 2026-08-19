<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Auth\ForgotPassword;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\OtpService;
use App\Sms\Contracts\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

/**
 * The «فراموشی رمز عبور» wizard (App\Filament\Auth\ForgotPassword).
 *
 * Same Filament testing helpers, and the same caveat, as RegistrationTest:
 * use goToWizardStep($n), never goToNextWizardStep() — the current step lives
 * in Alpine in the browser, so "next" from the PHP component's point of view
 * is always step 1 again (which here would re-send the SMS and trip the
 * resend cooldown).
 *
 * Step numbers: 1 شماره موبایل, 2 کد تایید, 3 رمز عبور جدید.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $mobile = '09121234567'): User
    {
        $user = User::create([
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'mobile' => $mobile,
            'national_id' => '0499370899',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('OldSecret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(RoleName::User->value);

        return $user;
    }

    private function fakeSms(): FakeSmsGateway
    {
        $fake = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fake);

        return $fake;
    }

    /** The whole happy path: number → code → new password → logged in. */
    public function test_a_user_can_reset_their_password_with_an_sms_code(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();
        $user = $this->makeUser();

        // Step 1 — the number alone gets a code sent, to that number.
        $component = Livewire::test(ForgotPassword::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->assertHasNoFormErrors();

        $this->assertSame('09121234567', $sms->lastMobile);
        $this->assertNotNull($sms->lastCode);
        // The password has NOT changed yet — a code being sent is not a reset.
        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));

        // Step 2 — the code.
        $component
            ->fillForm(['otp_code' => $sms->lastCode])
            ->goToWizardStep(3)
            ->assertHasNoFormErrors();

        // Step 3 — the new password, twice.
        $component
            ->fillForm([
                'password' => 'BrandNew123',
                'password_confirmation' => 'BrandNew123',
            ])
            ->call('request')
            ->assertHasNoFormErrors();

        $user = $user->fresh();
        $this->assertTrue(Hash::check('BrandNew123', $user->password));
        $this->assertFalse(Hash::check('OldSecret123', $user->password));

        // Logged straight in, exactly like registration.
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());

        // The challenge is gone, so the same code cannot be replayed.
        $this->assertSame(0, OtpVerification::where('mobile', '09121234567')->count());
    }

    /** A number with no account must not cost an SMS. */
    public function test_an_unknown_mobile_number_is_rejected_before_any_sms_is_sent(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['mobile' => '09129999999'])
            ->goToWizardStep(2)
            ->assertHasFormErrors(['mobile']);

        $this->assertNull($sms->lastCode);
    }

    /** A wrong code changes nothing. */
    public function test_a_wrong_code_does_not_let_the_wizard_continue(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();
        $user = $this->makeUser();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2)
            ->fillForm(['otp_code' => '000000'])
            ->goToWizardStep(3)
            ->assertHasFormErrors(['otp_code']);

        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));
    }

    /**
     * THE security test: the wizard's own state proves nothing.
     *
     * Here the form is filled in as if steps 1 and 2 had been passed — no code
     * was ever verified for this number — and submit is called directly. The
     * only thing standing in the way is OtpService::verifiedWithin(), which
     * reads the database.
     */
    public function test_submitting_without_a_verified_code_cannot_change_the_password(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();
        $user = $this->makeUser();

        Livewire::test(ForgotPassword::class)
            ->fillForm([
                'mobile' => '09121234567',
                'password' => 'Hijacked123',
                'password_confirmation' => 'Hijacked123',
            ])
            ->call('request');

        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));
        $this->assertFalse(Auth::check());
    }

    /**
     * A verification older than the ten-minute window is no longer proof.
     *
     * Same window registration uses (OtpService::REGISTRATION_WINDOW_SECONDS)
     * — the visitor is sent back to step 1 rather than shown an inline error,
     * because their proof of ownership is gone and there is nothing on the page
     * left to correct.
     */
    public function test_an_expired_verification_window_cannot_change_the_password(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();
        $user = $this->makeUser();

        // A verified challenge, stamped longer ago than the window allows.
        OtpVerification::create([
            'mobile' => '09121234567',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinutes(20),
            'verified_at' => now()->subSeconds(OtpService::REGISTRATION_WINDOW_SECONDS + 60),
        ]);

        Livewire::test(ForgotPassword::class)
            ->fillForm([
                'mobile' => '09121234567',
                'password' => 'TooLate123',
                'password_confirmation' => 'TooLate123',
            ])
            ->call('request');

        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));
        $this->assertFalse(Auth::check());
    }

    /** A mismatched confirmation is caught before anything is written. */
    public function test_the_confirmation_field_must_match(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();
        $user = $this->makeUser();

        $component = Livewire::test(ForgotPassword::class)
            ->fillForm(['mobile' => '09121234567'])
            ->goToWizardStep(2);

        $component
            ->fillForm(['otp_code' => $sms->lastCode])
            ->goToWizardStep(3)
            ->fillForm([
                'password' => 'BrandNew123',
                'password_confirmation' => 'SomethingElse123',
            ])
            ->call('request')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));
    }
}
