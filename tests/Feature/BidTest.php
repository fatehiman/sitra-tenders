<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Bids\BidResource;
use App\Models\Bid;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BidTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(RoleName $role, string $mobile, string $nationalId): User
    {
        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'تست',
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    public function test_active_scope_only_returns_started_and_unexpired_bids(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000010', '1234567891');

        $scheduled = Bid::create([
            'title' => 'زمان‌بندی‌شده',
            'description' => 'x',
            'start_at' => now()->addDay(),
            'expire_at' => now()->addDays(2),
            'created_by' => $staff->id,
        ]);

        $active = Bid::create([
            'title' => 'فعال',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);

        $expired = Bid::create([
            'title' => 'پایان‌یافته',
            'description' => 'x',
            'start_at' => now()->subDays(2),
            'expire_at' => now()->subDay(),
            'created_by' => $staff->id,
        ]);

        $activeIds = Bid::active()->pluck('id')->all();

        $this->assertContains($active->id, $activeIds);
        $this->assertNotContains($scheduled->id, $activeIds);
        $this->assertNotContains($expired->id, $activeIds);
    }

    public function test_a_user_can_only_suggest_once_per_bid(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000011', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000012', '0499370899');

        $bid = Bid::create([
            'title' => 'فعال',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);

        $bid->suggestions()->create(['user_id' => $user->id, 'note' => 'پیشنهاد اول']);

        $this->expectException(QueryException::class);
        $bid->suggestions()->create(['user_id' => $user->id, 'note' => 'پیشنهاد دوم']);
    }

    public function test_staff_can_access_bid_create(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000013', '1234567891');

        $this->actingAs($staff);
        $this->get(BidResource::getUrl('create'))->assertOk();
    }

    public function test_user_role_cannot_create_bids(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUser(RoleName::User, '09120000014', '0499370899');

        $this->actingAs($user);
        $this->get(BidResource::getUrl('create'))->assertForbidden();
    }
}
