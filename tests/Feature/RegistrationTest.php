<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Register;
use App\Models\User;
use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsResult;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_can_register_after_confirming_otp(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        $component = Livewire::test(Register::class)
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'individual')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->assertSet('showOtpModal', true);

        $this->assertSame('09121234567', $fakeSms->lastMobile);
        $this->assertNotNull($fakeSms->lastCode);
        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);

        $component
            ->set('otp_code', $fakeSms->lastCode)
            ->call('confirmOtp');

        $user = User::where('mobile', '09121234567')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertTrue($user->hasRole(RoleName::User->value));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_otp_code_is_rejected_and_no_user_is_created(): void
    {
        $this->seed(RoleSeeder::class);

        $fakeSms = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fakeSms);

        Livewire::test(Register::class)
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'individual')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->set('otp_code', '000000')
            ->call('confirmOtp')
            ->assertSet('otpError', 'کد تایید نادرست است.');

        $this->assertDatabaseMissing('users', ['mobile' => '09121234567']);
        $this->assertGuest();
    }

    public function test_failed_send_shows_the_provider_reason_and_keeps_the_modal_closed(): void
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
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'individual')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->assertSet('showOtpModal', false)
            ->assertSet('formError', 'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید. (حساب کاربری شما تایید نشده است)');

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

        $this->app->singleton(SmsGateway::class, fn () => new FakeSmsGateway);

        Livewire::test(Register::class)
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'company')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->assertHasErrors(['company_name', 'company_national_id']);
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

        $component = Livewire::test(Register::class)
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'company')
            ->set('company_name', 'شرکت نمونه')
            ->set('company_national_id', '12345678901')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->assertHasNoErrors()
            ->assertSet('showOtpModal', true);

        $component
            ->set('otp_code', $fakeSms->lastCode)
            ->call('confirmOtp');

        $this->assertDatabaseHas('users', [
            'mobile' => '09121234567',
            'company_national_id' => '12345678901',
        ]);
    }

    /** ...but anything that is not exactly 11 digits is still rejected. */
    public function test_company_national_id_must_be_exactly_eleven_digits(): void
    {
        $this->seed(RoleSeeder::class);

        $this->app->singleton(SmsGateway::class, fn () => new FakeSmsGateway);

        Livewire::test(Register::class)
            ->set('first_name', 'علی')
            ->set('last_name', 'رضایی')
            ->set('mobile', '09121234567')
            ->set('national_id', '0499370899')
            ->set('person_type', 'company')
            ->set('company_name', 'شرکت نمونه')
            ->set('company_national_id', '1234567890')
            ->set('password', 'Secret123')
            ->set('password_confirmation', 'Secret123')
            ->call('sendOtp')
            ->assertHasErrors(['company_national_id']);
    }
}
