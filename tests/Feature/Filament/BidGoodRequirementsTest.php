<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Bids\Pages\CreateBid;
use App\Filament\Resources\Bids\Pages\EditBid;
use App\Filament\Resources\Bids\Pages\ListBids;
use App\Models\Bid;
use App\Models\Good;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class BidGoodRequirementsTest extends TestCase
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
            'specifications' => 'M8 × 40',
        ]);
    }

    public function test_staff_can_create_a_bid_with_good_requirement_rows(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000030', '1234567891');
        $screw = $this->makeGood('83724', 'پیچ آلن');
        $nut = $this->makeGood('91055', 'مهره خروسی');

        $this->actingAs($staff);

        Livewire::test(CreateBid::class)
            ->fillForm([
                'title' => 'مناقصه اتصالات',
                'description' => '<p>شرح</p>',
                'deposit_amount' => 1_000_000,
                'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'expire_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'goodRequirements' => [
                    ['good_id' => $screw->id, 'quantity' => 1000],
                    ['good_id' => $nut->id, 'quantity' => 250],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $bid = Bid::where('title', 'مناقصه اتصالات')->firstOrFail();

        $this->assertSame($staff->id, $bid->created_by);
        $this->assertEqualsCanonicalizing(
            [[$screw->id, 1000], [$nut->id, 250]],
            $bid->goodRequirements->map(fn ($r) => [$r->good_id, $r->quantity])->all(),
        );
    }

    public function test_editing_a_bid_loads_and_rewrites_its_requirement_rows(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000034', '1234567891');
        $screw = $this->makeGood('83724', 'پیچ آلن');
        $nut = $this->makeGood('91055', 'مهره خروسی');

        $bid = Bid::create([
            'title' => 'مناقصه اتصالات',
            'description' => '<p>شرح</p>',
            'deposit_amount' => 1_000_000,
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);
        $bid->goodRequirements()->create(['good_id' => $screw->id, 'quantity' => 1000]);

        $this->actingAs($staff);

        $component = Livewire::test(EditBid::class, ['record' => $bid->getKey()]);

        // The existing row round-trips out of the relationship...
        $loaded = collect($component->get('data')['goodRequirements'])->values();
        $this->assertCount(1, $loaded);
        $this->assertSame($screw->id, (int) $loaded[0]['good_id']);
        $this->assertSame(1000, (int) $loaded[0]['quantity']);

        // ...and replacing it deletes the old row rather than orphaning it.
        $component
            ->fillForm([
                'goodRequirements' => [
                    ['good_id' => $nut->id, 'quantity' => 250],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            [[$nut->id, 250]],
            $bid->goodRequirements()->get()->map(fn ($r) => [$r->good_id, $r->quantity])->all(),
        );
    }

    public function test_the_detail_and_goods_modals_open_for_a_regular_user(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000031', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000032', '0499370899');
        $good = $this->makeGood('83724', 'پیچ آلن');

        $bid = Bid::create([
            'title' => 'مناقصه فعال',
            'description' => '<p>شرح مناقصه</p>',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);
        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 1000]);

        $this->actingAs($user);

        // Mounting builds and renders each modal's schema, so this exercises
        // the infolist entries — including the Tiptap re-render of the
        // description and the per-drawing links — end to end.
        Livewire::test(ListBids::class)
            ->mountAction(TestAction::make('viewDetails')->table($bid))
            ->assertMountedActionModalSee('شرح مناقصه')
            ->unmountAction()
            ->mountAction(TestAction::make('viewGoods')->table($bid))
            ->assertMountedActionModalSee('پیچ آلن')
            ->assertMountedActionModalSee('83724')
            ->assertMountedActionModalSee('1,000');
    }

    public function test_the_detail_modal_strips_markup_the_editor_could_not_have_produced(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000033', '1234567891');

        $bid = Bid::create([
            'title' => 'مناقصه فعال',
            'description' => '<p>شرح مناقصه</p><script>alert(1)</script>',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $staff->id,
        ]);

        $this->actingAs($staff);

        // RichContentRenderer re-serialises through Tiptap's allowed node/mark
        // set, so editor-foreign markup never survives into the modal.
        Livewire::test(ListBids::class)
            ->mountAction(TestAction::make('viewDetails')->table($bid))
            ->assertMountedActionModalSee('شرح مناقصه')
            ->assertMountedActionModalDontSeeHtml('<script>');
    }
}
