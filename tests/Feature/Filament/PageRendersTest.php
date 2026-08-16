<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Filament\Resources\Bids\BidResource;
use App\Filament\Resources\Goods\GoodResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Bid;
use App\Models\Good;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Real, authenticated HTTP requests against every page in the panel.
 *
 * Why bother when there are already Livewire::test() tests? Because
 * Livewire::test() constructs the component directly and skips the app's
 * route middleware, and because a Blade-level mistake — a component view
 * that does not exist, a Blade component whose attribute changed — only
 * shows up when the page is actually rendered. Two of the changes these
 * assertions guard were exactly that shape: swapping the date pickers for
 * the Jalali package's own Blade view, and rendering the registration
 * wizard's submit button through <x-filament::button>.
 *
 * assertSuccessful() is doing real work here: without it a 500 from a
 * fluent-chain crash would go unnoticed until someone opened the page.
 */
class PageRendersTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(RoleName $role): User
    {
        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'تست',
            'mobile' => '09121110000',
            'national_id' => '0499370899',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /** The registration wizard renders for a guest. */
    public function test_registration_page_renders(): void
    {
        $this->seed(RoleSeeder::class);

        $this->get(filament()->getRegistrationUrl())
            ->assertSuccessful()
            // The three step headings prove the Wizard itself rendered,
            // not just the page shell around it.
            ->assertSee('شماره موبایل')
            ->assertSee('کد تایید')
            ->assertSee('اطلاعات کاربر')
            /*
             * Enter must mean «بعدی» on every step but the last. Without
             * this handler the key submits the form, which runs register()
             * — the final step's action — so pressing Enter after typing a
             * mobile number threw the visitor back to step 1 with an error.
             * Pinned here because it is a single attribute that a future
             * edit to the Wizard chain could drop without any test noticing.
             */
            ->assertSee('requestNextStep()', escape: false);
    }

    /** /login still points people at it, via Filament's own route name. */
    public function test_login_page_links_to_registration(): void
    {
        $this->seed(RoleSeeder::class);

        $this->get(filament()->getLoginUrl())
            ->assertSuccessful()
            ->assertSee(filament()->getRegistrationUrl(), escape: false);
    }

    /**
     * The bid edit form: both Jalali date pickers, and the two Sections
     * stacked rather than side by side.
     */
    public function test_bid_edit_page_renders_with_jalali_pickers(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin);

        $bid = Bid::create([
            'title' => 'مناقصه تست',
            'description' => '<p>شرح</p>',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(BidResource::getUrl('edit', ['record' => $bid]))
            ->assertSuccessful()
            // The Jalali picker replaces the field's Blade view with its own
            // Alpine component; if that view were missing or the macro were
            // not registered, the page would 500 rather than reach here.
            ->assertSee('jalaliDateTimePicker', escape: false);
    }

    /**
     * The panel's layout width settings actually reach the rendered page.
     *
     * Filament emits sidebarWidth() as a CSS custom property on the layout
     * element; asserting on it here is what proves the change took effect
     * without opening a browser, and pins the value against an accidental
     * revert to Filament's 20rem default.
     */
    public function test_panel_layout_width_is_applied(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin);

        $this->actingAs($admin)
            ->get(BidResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('--sidebar-width: 15.625rem', escape: false);
    }

    /**
     * The «ارسال پیشنهاد» wizard, over real HTTP.
     *
     * This one earns its keep more than most: the page is a hand-registered
     * resource route with its own Blade view and a five-step wizard built
     * from a table repeater, an SMS step and two file uploads. Any of those
     * being wired up wrongly is a 500 the Livewire::test() suite would not
     * see, because that helper skips the route and the layout entirely.
     */
    public function test_bid_suggestion_wizard_page_renders_for_a_user(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin);

        // A second account, because the admin above may not bid.
        $bidder = User::create([
            'first_name' => 'پیشنهاد',
            'last_name' => 'دهنده',
            'mobile' => '09121110001',
            'national_id' => '0084575948',
            'person_type' => PersonType::Individual,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $bidder->assignRole(RoleName::User->value);

        $bid = Bid::create([
            'title' => 'مناقصه تست',
            'description' => '<p>شرح</p>',
            'start_at' => now()->subDay(),
            'expire_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);

        $good = Good::create(['code' => 'G-9', 'name' => 'پیچ آلن', 'specifications' => 'M8']);
        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 4]);

        $this->actingAs($bidder)
            ->get(BidResource::getUrl('suggest', ['record' => $bid]))
            ->assertSuccessful()
            // The five step headings prove the Wizard itself rendered.
            ->assertSee('قیمت کالاها')
            ->assertSee('توضیحات و پیوست‌ها')
            ->assertSee('رسید پرداخت')
            ->assertSee('تایید نهایی')
            ->assertSee('کد تایید')
            // The tender's goods reached the price table.
            ->assertSee('پیچ آلن')
            // Same Enter-key guard as the registration wizard — without it,
            // pressing Enter while typing a price would try to finalise the
            // bid, because the form's submit handler is the LAST step's.
            ->assertSee('requestNextStep()', escape: false);
    }

    /** The three list pages, each of which now renders a Jalali column. */
    public function test_list_pages_render_for_an_admin(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin);

        Good::create([
            'code' => 'G-1',
            'name' => 'پیچ',
            'specifications' => 'M8',
        ]);

        $this->actingAs($admin)
            ->get(BidResource::getUrl('index'))
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get(GoodResource::getUrl('index'))
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get(UserResource::getUrl('index'))
            ->assertSuccessful();
    }
}
