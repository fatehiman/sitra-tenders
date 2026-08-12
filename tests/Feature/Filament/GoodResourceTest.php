<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Goods\Pages\ListGoods;
use App\Models\Bid;
use App\Models\Good;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class GoodResourceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $mobile = '09120000040'): User
    {
        $staff = User::create([
            'first_name' => 'کارشناس',
            'last_name' => 'تست',
            'mobile' => $mobile,
            'national_id' => '1234567891',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $staff->assignRole(RoleName::Staff->value);

        return $staff;
    }

    private function makeGood(string $code = '83724', string $name = 'پیچ آلن'): Good
    {
        return Good::create([
            'code' => $code,
            'name' => $name,
            'specifications' => 'M8 × 40',
        ]);
    }

    public function test_an_unused_good_can_be_deleted(): void
    {
        $this->seed(RoleSeeder::class);
        $this->actingAs($this->staff());
        $good = $this->makeGood();

        Livewire::test(ListGoods::class)
            ->callAction(TestAction::make('delete')->table($good));

        $this->assertDatabaseMissing('goods', ['id' => $good->id]);
    }

    public function test_deleting_a_good_used_by_a_bid_is_refused_with_an_explanation(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->staff();
        $this->actingAs($staff);

        $good = $this->makeGood();
        $bid = Bid::create([
            'title' => 'مناقصه اتصالات',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);
        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 1000]);

        Livewire::test(ListGoods::class)
            ->callAction(TestAction::make('delete')->table($good))
            ->assertActionHalted(TestAction::make('delete')->table($good))
            ->assertNotified('این کالا قابل حذف نیست');

        $this->assertDatabaseHas('goods', ['id' => $good->id]);
    }
}
