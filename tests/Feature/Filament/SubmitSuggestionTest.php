<?php

namespace Tests\Feature\Filament;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Enums\SuggestionAttachmentType;
use App\Enums\SuggestionStatus;
use App\Filament\Resources\Bids\Pages\SubmitSuggestion;
use App\Models\Bid;
use App\Models\BidGoodRequirement;
use App\Models\BidSuggestion;
use App\Models\Good;
use App\Models\User;
use App\Sms\Contracts\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

/**
 * The «ارسال پیشنهاد» wizard (App\Filament\Resources\Bids\Pages\SubmitSuggestion).
 *
 * Filament testing helpers used here, same as RegistrationTest:
 *   fillForm([...])     set form fields — they all live under `data`, and the
 *                       call MERGES, so each step fills only its own.
 *   goToWizardStep($n)  press «بعدی» to reach step $n: validates step $n - 1
 *                       and runs its afterValidation() hook — which in this
 *                       wizard is where the draft is saved and, on step 4,
 *                       where the SMS goes out.
 *
 * Use goToWizardStep(), NOT goToNextWizardStep(): the wizard's current step
 * lives in Alpine in the browser, so the PHP component always rebuilds with
 * the index at 0 and "next" would re-run step 1 forever.
 */
class SubmitSuggestionTest extends TestCase
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

    /** Counter keeping کد کالا unique when a test builds two tenders. */
    private int $goodCounter = 0;

    /** A tender asking for 10 of one good and 5 of another. */
    private function makeBidWithGoods(User $creator, ?string $expireAt = null): Bid
    {
        $bid = Bid::create([
            'title' => 'مناقصه تست',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => $expireAt ?? now()->addDay(),
            'created_by' => $creator->id,
        ]);

        foreach ([['پیچ آلن', 10], ['مهره خروسی', 5]] as [$name, $quantity]) {
            $good = Good::create([
                'code' => 'G'.(++$this->goodCounter),
                'name' => $name,
                'specifications' => 'M8 × 40',
            ]);
            $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => $quantity]);
        }

        return $bid;
    }

    /** @return array<int, BidGoodRequirement> */
    private function requirements(Bid $bid): array
    {
        return $bid->goodRequirements()->orderBy('id')->get()->all();
    }

    /**
     * The repeater key of the row for a given requirement.
     *
     * Filament re-keys repeater items on hydration, so the keys the page
     * fills in are NOT the ones that come back — tests have to find a row by
     * its `requirement_id` field, exactly as the page itself does.
     */
    private function itemKey(Testable $component, BidGoodRequirement $requirement): string
    {
        foreach ((array) $component->get('data.items') as $key => $row) {
            if ((int) ($row['requirement_id'] ?? 0) === $requirement->id) {
                return (string) $key;
            }
        }

        $this->fail("No repeater row found for requirement {$requirement->id}.");
    }

    private function fakeSms(): FakeSmsGateway
    {
        $fake = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fake);

        return $fake;
    }

    /**
     * The whole happy path in one test, because every step of this wizard
     * depends on the one before it having been saved — splitting it up would
     * mean re-driving the earlier steps in each piece anyway.
     */
    public function test_a_user_can_price_goods_attach_files_and_finalize_with_an_sms_code(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $sms = $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000050', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000051', '0499370899');
        $bid = $this->makeBidWithGoods($admin);
        [$screws, $nuts] = $this->requirements($bid);

        $this->actingAs($user);

        $component = Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()]);

        // Step 1 — price the first good only.
        $component
            ->fillForm(['items.'.$this->itemKey($component, $screws).'.unit_price' => 2000])
            ->goToWizardStep(2)
            ->assertHasNoErrors();

        // The draft exists already, and its total came from the DATABASE's
        // quantity (10), not from anything the browser sent.
        $draft = BidSuggestion::where('bid_id', $bid->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(SuggestionStatus::Draft, $draft->status);
        $this->assertSame(20000, $draft->total_price);
        $this->assertSame(1, $draft->items()->count());

        // Step 2 — text and a supporting document.
        $component
            ->fillForm([
                'note' => 'پیشنهاد ما',
                'documents' => [UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf')],
            ])
            ->goToWizardStep(3)
            ->assertHasNoErrors();

        // Step 3 — the receipt.
        $component
            ->fillForm(['receipts' => [UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')]])
            ->goToWizardStep(4)
            ->assertHasNoErrors();

        // Step 4's «بعدی» is what spends money on an SMS — and it went to
        // the logged-in account's own number, not to anything on the form.
        $component->goToWizardStep(5)->assertHasNoErrors();
        $this->assertNotNull($sms->lastCode);
        $this->assertSame('09120000051', $sms->lastMobile);

        $component
            ->fillForm(['otp_code' => $sms->lastCode])
            ->call('finalize')
            ->assertHasNoErrors();

        $draft = $draft->fresh();

        $this->assertSame(SuggestionStatus::Submitted, $draft->status);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $draft->tracking_code);
        $this->assertNotNull($draft->submitted_at);
        $this->assertSame('پیشنهاد ما', $draft->note);
        $this->assertSame(20000, $draft->total_price);
        $this->assertCount(1, $draft->documents());
        $this->assertCount(1, $draft->receipts());

        // The good the user left blank has no line at all — "not priced" is
        // the absence of a row, never a zero.
        $this->assertSame(0, $draft->items()->where('bid_good_requirement_id', $nuts->id)->count());
    }

    /**
     * The draft is on the server, not in the browser: a brand-new component
     * instance must come back with everything already typed.
     */
    public function test_reopening_the_wizard_restores_a_saved_draft(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000052', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000053', '0499370899');
        $bid = $this->makeBidWithGoods($admin);
        [$screws] = $this->requirements($bid);

        $this->actingAs($user);

        $component = Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()]);

        $component
            ->fillForm([
                'items.'.$this->itemKey($component, $screws).'.unit_price' => 750,
                'note' => 'یادداشت پیش‌نویس',
            ])
            // The header button, rather than a step transition — both go
            // through saveDraft().
            ->callAction('saveDraft');

        $reopened = Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()]);

        $reopened->assertFormSet([
            'items.'.$this->itemKey($reopened, $screws).'.unit_price' => 750,
            'note' => 'یادداشت پیش‌نویس',
        ]);
    }

    /**
     * A line total must be computed from the tender's stored quantity. If it
     * were taken from the repeater row, a crafted request could quote 1 ریال
     * a unit and still report a winning-sized total (or a losing-sized one).
     */
    public function test_a_tampered_quantity_cannot_change_the_stored_total(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000054', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000055', '0499370899');
        $bid = $this->makeBidWithGoods($admin);
        [$screws] = $this->requirements($bid);

        $this->actingAs($user);

        $component = Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()]);
        $key = $this->itemKey($component, $screws);

        $component
            ->fillForm([
                'items.'.$key.'.unit_price' => 100,
                // Not a field in the schema — this is what a crafted request
                // would smuggle in alongside the price.
                'items.'.$key.'.quantity' => 9999,
            ])
            ->callAction('saveDraft');

        $draft = BidSuggestion::where('bid_id', $bid->id)->where('user_id', $user->id)->firstOrFail();

        // 100 × 10 (the requirement's real quantity), not 100 × 9999.
        $this->assertSame(1000, $draft->total_price);
    }

    /**
     * A price quoted against some other tender's requirement is dropped, not
     * stored — otherwise a crafted row could attach a line to a bid the user
     * is not even looking at.
     */
    public function test_a_price_for_another_tenders_requirement_is_ignored(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000056', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000057', '0499370899');
        $mine = $this->makeBidWithGoods($admin);
        $other = $this->makeBidWithGoods($admin);
        [$foreign] = $this->requirements($other);

        $this->actingAs($user);

        Livewire::test(SubmitSuggestion::class, ['record' => $mine->getKey()])
            ->fillForm([
                // A whole extra row, carrying another tender's requirement id.
                'items.smuggled.requirement_id' => $foreign->id,
                'items.smuggled.unit_price' => 5000,
            ])
            ->callAction('saveDraft');

        $draft = BidSuggestion::where('bid_id', $mine->id)->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(0, $draft->items()->count());
        $this->assertSame(0, (int) $draft->total_price);
    }

    /**
     * No SMS is sent — and no bid is finalised — until the two mandatory
     * pieces are actually there. Checking BEFORE the send matters: msgway
     * bills per accepted message.
     */
    public function test_an_incomplete_bid_cannot_reach_the_sms_step(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $sms = $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000058', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000059', '0499370899');
        $bid = $this->makeBidWithGoods($admin);
        [$screws] = $this->requirements($bid);

        $this->actingAs($user);

        // Priced, but no receipt uploaded.
        $component = Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()]);

        $component
            ->fillForm(['items.'.$this->itemKey($component, $screws).'.unit_price' => 300])
            ->goToWizardStep(5);

        $this->assertNull($sms->lastCode);

        $draft = BidSuggestion::where('bid_id', $bid->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(SuggestionStatus::Draft, $draft->status);
        $this->assertNull($draft->tracking_code);
    }

    /**
     * The deadline is re-checked on the server. A page left open past the
     * expiry must not be able to write a bid.
     */
    public function test_the_wizard_refuses_an_expired_tender(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000060', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000061', '0499370899');
        $bid = $this->makeBidWithGoods($admin, expireAt: now()->subHour());

        $this->actingAs($user);

        Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()])
            ->assertRedirect();

        // ...and nothing was created on the way out.
        $this->assertSame(0, $bid->suggestions()->count());
    }

    /** Staff publish tenders; they do not bid on them. */
    public function test_staff_cannot_open_the_bid_wizard(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000062', '1234567891');
        $staff = $this->makeUser(RoleName::Staff, '09120000063', '0499370899');
        $bid = $this->makeBidWithGoods($admin);

        $this->actingAs($staff);

        Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()])
            ->assertRedirect();
    }

    /**
     * The uploaded files are real rows with their own type, so the two
     * lists in the «مشاهده پیشنهاد» modal can be told apart.
     */
    public function test_documents_and_receipts_are_stored_with_distinct_types(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000064', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000065', '0499370899');
        $bid = $this->makeBidWithGoods($admin);

        $this->actingAs($user);

        Livewire::test(SubmitSuggestion::class, ['record' => $bid->getKey()])
            ->fillForm([
                'documents' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
                'receipts' => [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')],
            ])
            ->callAction('saveDraft');

        $draft = BidSuggestion::where('bid_id', $bid->id)->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(1, $draft->attachments()->where('type', SuggestionAttachmentType::Document->value)->count());
        $this->assertSame(1, $draft->attachments()->where('type', SuggestionAttachmentType::PaymentReceipt->value)->count());
    }
}
