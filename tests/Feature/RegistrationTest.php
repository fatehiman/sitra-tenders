<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Livewire\Register;
use App\Models\User;
use App\Sms\Contracts\SmsGateway;
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
}
