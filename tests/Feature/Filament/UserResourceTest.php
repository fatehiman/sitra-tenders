<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'first_name' => 'مدیر',
            'last_name' => 'سامانه',
            'mobile' => '09120000001',
            'national_id' => '0499370899',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole(RoleName::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_staff_user_without_otp(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin);
        $this->get(UserResource::getUrl('create'))->assertOk();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'سارا',
                'last_name' => 'احمدی',
                'mobile' => '09129998877',
                'national_id' => '1234567891',
                'person_type' => 'individual',
                'password' => 'Secret123',
                'role' => 'staff',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = User::where('mobile', '09129998877')->first();

        $this->assertNotNull($staff);
        $this->assertNotNull($staff->mobile_verified_at, 'admin-created accounts skip OTP entirely');
        $this->assertTrue($staff->hasRole(RoleName::Staff->value));
        $this->assertSame($admin->id, $staff->created_by);
    }

    public function test_non_admin_cannot_access_user_resource(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'عادی',
            'mobile' => '09121112233',
            'national_id' => '1234567891',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(RoleName::User->value);

        $this->actingAs($user);
        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }
}
