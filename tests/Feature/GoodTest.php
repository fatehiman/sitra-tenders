<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Goods\GoodResource;
use App\Models\Bid;
use App\Models\Good;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoodTest extends TestCase
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

    private function makeGood(string $code, string $name): Good
    {
        return Good::create([
            'code' => $code,
            'name' => $name,
            'specifications' => 'M8 × 40 — فولاد گالوانیزه',
        ]);
    }

    private function makeBid(User $creator): Bid
    {
        return Bid::create([
            'title' => 'مناقصه تست',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $creator->id,
        ]);
    }

    public function test_the_picker_label_carries_the_code_in_parentheses(): void
    {
        $good = $this->makeGood('83724', 'پیچ آلن');

        $this->assertSame('پیچ آلن (83724)', $good->picker_label);
    }

    public function test_search_scope_matches_both_name_and_code(): void
    {
        $screw = $this->makeGood('83724', 'پیچ آلن');
        $nut = $this->makeGood('91055', 'مهره خروسی');

        $this->assertSame([$screw->id], Good::search('پیچ')->pluck('id')->all());
        $this->assertSame([$screw->id], Good::search('83724')->pluck('id')->all());
        $this->assertSame([$nut->id], Good::search('91055')->pluck('id')->all());
        $this->assertEmpty(Good::search('واشر')->pluck('id')->all());
    }

    public function test_good_code_is_unique(): void
    {
        $this->makeGood('83724', 'پیچ آلن');

        $this->expectException(QueryException::class);
        $this->makeGood('83724', 'پیچ دیگر');
    }

    public function test_a_good_cannot_be_listed_twice_on_one_bid(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000020', '1234567891');
        $bid = $this->makeBid($staff);
        $good = $this->makeGood('83724', 'پیچ آلن');

        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 1000]);

        $this->expectException(QueryException::class);
        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 5]);
    }

    public function test_a_good_used_by_a_bid_cannot_be_deleted(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000021', '1234567891');
        $bid = $this->makeBid($staff);
        $good = $this->makeGood('83724', 'پیچ آلن');

        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 1000]);

        $this->expectException(QueryException::class);
        $good->delete();
    }

    public function test_deleting_a_bid_removes_its_requirement_rows_but_not_the_goods(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000022', '1234567891');
        $bid = $this->makeBid($staff);
        $good = $this->makeGood('83724', 'پیچ آلن');

        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 1000]);
        $bid->delete();

        $this->assertDatabaseCount('bid_good_requirements', 0);
        $this->assertDatabaseHas('goods', ['id' => $good->id]);
    }

    public function test_staff_can_access_the_goods_resource(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000023', '1234567891');

        $this->actingAs($staff);
        $this->get(GoodResource::getUrl('index'))->assertOk();
        $this->get(GoodResource::getUrl('create'))->assertOk();
    }

    public function test_user_role_cannot_access_the_goods_resource(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUser(RoleName::User, '09120000024', '0499370899');

        $this->actingAs($user);
        $this->get(GoodResource::getUrl('index'))->assertForbidden();
        $this->get(GoodResource::getUrl('create'))->assertForbidden();
    }
}
