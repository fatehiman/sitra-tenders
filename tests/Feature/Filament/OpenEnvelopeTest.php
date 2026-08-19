<?php

namespace Tests\Feature\Filament;

use App\Enums\EnvelopeDecision;
use App\Enums\EnvelopeStage;
use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Enums\SuggestionStatus;
use App\Filament\Resources\Bids\BidResource;
use App\Filament\Resources\Bids\Pages\OpenEnvelope;
use App\Models\Bid;
use App\Models\BidSuggestion;
use App\Models\Good;
use App\Models\User;
use App\Sms\Contracts\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

/**
 * The admin's two-envelope review
 * (App\Filament\Resources\Bids\Pages\OpenEnvelope).
 *
 * The page's buttons all delegate to public methods — decide(), previous(),
 * next(), submit() — so the tests drive those with ->call() instead of
 * ->callAction(). That is deliberate: it is the same code path the buttons
 * take, and it keeps the tests independent of where in the schema a button
 * happens to be rendered.
 *
 * One thing the tests therefore do NOT cover: the "I understand this cannot be
 * undone" checkbox, which lives in the «ثبت نهایی» action's own confirmation
 * modal and is a UI gate in front of submit(), not part of it.
 */
class OpenEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function makeUser(RoleName $role, string $mobile, string $nationalId, ?string $company = null): User
    {
        $user = User::create([
            'first_name' => 'کاربر',
            'last_name' => 'شماره '.(++$this->counter),
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'person_type' => $company ? PersonType::Company : PersonType::Individual,
            'company_name' => $company,
            'company_national_id' => $company ? '1234567890'.$this->counter : null,
            'password' => Hash::make('Secret123'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /** An EXPIRED tender asking for 10 of one good. */
    private function makeExpiredBid(User $creator): Bid
    {
        $bid = Bid::create([
            'title' => 'مناقصه پایان‌یافته',
            'description' => 'x',
            'deposit_amount' => 500_000,
            'start_at' => now()->subDays(10),
            'expire_at' => now()->subDay(),
            'created_by' => $creator->id,
        ]);

        $good = Good::create([
            'code' => 'G'.(++$this->counter),
            'name' => 'پیچ آلن',
            'specifications' => 'M8 × 40',
        ]);
        $bid->goodRequirements()->create(['good_id' => $good->id, 'quantity' => 10]);

        return $bid;
    }

    /**
     * A finalised offer on $bid, priced, optionally with an alternative
     * specification for the tender's good.
     */
    private function makeSuggestion(Bid $bid, User $user, int $unitPrice, ?string $spec = null): BidSuggestion
    {
        $requirement = $bid->goodRequirements()->firstOrFail();

        $suggestion = BidSuggestion::startDraft($bid, $user);
        $suggestion->items()->create([
            'bid_good_requirement_id' => $requirement->id,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $requirement->quantity,
        ]);

        if ($spec !== null) {
            $suggestion->specifications()->create([
                'bid_good_requirement_id' => $requirement->id,
                'specifications' => $spec,
            ]);
        }

        $suggestion->recalculateTotal();
        $suggestion->finalize();

        return $suggestion->fresh();
    }

    private function fakeSms(): FakeSmsGateway
    {
        $fake = new FakeSmsGateway;
        $this->app->singleton(SmsGateway::class, fn () => $fake);

        return $fake;
    }

    /**
     * The whole two-envelope path, because each stage depends on the previous
     * one having been finalised.
     */
    public function test_an_admin_reviews_both_envelopes_and_the_winner_is_texted(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000100', '1234567891');
        $winner = $this->makeUser(RoleName::User, '09120000101', '0499370899');
        $loserA = $this->makeUser(RoleName::User, '09120000102', '0084575948');
        $loserB = $this->makeUser(RoleName::User, '09120000103', '0322268140');

        $bid = $this->makeExpiredBid($admin);
        $winning = $this->makeSuggestion($bid, $winner, 1000, 'پیچ آلن استیل ۳۱۶');
        $rejectedInA = $this->makeSuggestion($bid, $loserA, 800);
        $rejectedInB = $this->makeSuggestion($bid, $loserB, 900);

        $this->actingAs($admin);

        /*
         * ---- پاکت الف --------------------------------------------------
         */
        $page = Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])->assertSuccessful();

        // Offers are reviewed in id order, so this is winner, loserA, loserB.
        $page->call('decide', EnvelopeDecision::Approved->value)
            ->call('decide', EnvelopeDecision::Declined->value)
            ->call('decide', EnvelopeDecision::Approved->value);

        // Decisions are stored right away — but they are still DRAFTS: the
        // statuses, and the tender itself, are untouched.
        $this->assertSame(EnvelopeDecision::Approved, $winning->fresh()->envelope_a_decision);
        $this->assertSame(EnvelopeDecision::Declined, $rejectedInA->fresh()->envelope_a_decision);
        $this->assertSame(SuggestionStatus::Submitted, $winning->fresh()->status);
        $this->assertNull($bid->fresh()->envelope_a_submitted_at);
        // And nothing has been texted: all result SMS goes out with پاکت ب.
        $this->assertSame([], $sms->messages);

        $page->call('submit');

        $bid = $bid->fresh();
        $this->assertNotNull($bid->envelope_a_submitted_at);
        $this->assertSame(SuggestionStatus::FormA, $winning->fresh()->status);
        $this->assertSame(SuggestionStatus::Rejected, $rejectedInA->fresh()->status);
        $this->assertSame([], $sms->messages);

        /*
         * ---- پاکت ب ----------------------------------------------------
         */
        $page = Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::B->value,
        ])->assertSuccessful();

        // Opening ب moves the الف-approved offers to «فرم ب» — a "where is my
        // offer" signal for the bidder, not a verdict.
        $this->assertSame(SuggestionStatus::FormB, $winning->fresh()->status);
        // The offer rejected in الف stays rejected and is NOT in this envelope.
        $this->assertSame(SuggestionStatus::Rejected, $rejectedInA->fresh()->status);

        $page->call('decide', EnvelopeDecision::Approved->value)
            ->call('decide', EnvelopeDecision::Declined->value)
            ->call('submit');

        $bid = $bid->fresh();
        $this->assertNotNull($bid->envelope_b_submitted_at);
        $this->assertSame(SuggestionStatus::Approved, $winning->fresh()->status);
        $this->assertSame(SuggestionStatus::Rejected, $rejectedInB->fresh()->status);

        // The winner is a winner, and only now is their name readable.
        $this->assertTrue($winning->fresh()->isWinner());
        $this->assertSame($winner->display_name, $winning->fresh()->bidderNameForAdmin());
        $this->assertSame(
            BidSuggestion::MASKED_BIDDER_NAME,
            $rejectedInB->fresh()->bidderNameForAdmin(),
        );
        $this->assertSame([$winning->id], $bid->winners()->pluck('id')->all());

        /*
         * ---- The SMS ---------------------------------------------------
         */
        $won = $sms->messagesOfTemplate('bid_won');
        $declined = $sms->messagesOfTemplate('bid_declined');

        $this->assertCount(1, $won);
        $this->assertSame($winner->mobile, $won[0]['mobile']);
        // param 1 is the bidder's own name, param 2 the tender's title.
        $this->assertSame(trim("{$winner->first_name} {$winner->last_name}"), $won[0]['params']['name']);
        $this->assertSame($bid->title, $won[0]['params']['tender']);

        // EVERY non-winner hears — including the one rejected back in پاکت الف.
        $this->assertCount(2, $declined);
        $this->assertEqualsCanonicalizing(
            [$loserA->mobile, $loserB->mobile],
            array_column($declined, 'mobile'),
        );

        // Every send is on the bill trail, win or lose.
        $this->assertDatabaseHas('sent_sms_log', ['mobile' => $winner->mobile, 'template' => 'bid_won']);
        $this->assertDatabaseHas('sent_sms_log', ['mobile' => $loserA->mobile, 'template' => 'bid_declined']);
    }

    /** An envelope with an undecided offer cannot be finalised. */
    public function test_an_envelope_cannot_be_finalised_while_an_offer_is_undecided(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000110', '1234567891');
        $userA = $this->makeUser(RoleName::User, '09120000111', '0499370899');
        $userB = $this->makeUser(RoleName::User, '09120000112', '0084575948');

        $bid = $this->makeExpiredBid($admin);
        $this->makeSuggestion($bid, $userA, 1000);
        $this->makeSuggestion($bid, $userB, 1200);

        $this->actingAs($admin);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])
            // Only the first offer is decided.
            ->call('decide', EnvelopeDecision::Approved->value)
            ->call('submit');

        $this->assertNull($bid->fresh()->envelope_a_submitted_at);
    }

    /** پاکت ب cannot be opened before پاکت الف has been finalised. */
    public function test_envelope_b_cannot_be_opened_before_envelope_a_is_finalised(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000120', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000121', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $suggestion = $this->makeSuggestion($bid, $user, 1000);

        $this->actingAs($admin);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::B->value,
        ])
            // mount() refuses and sends the admin back to the list, so there is
            // no screen left to press a button on.
            ->assertRedirect(BidResource::getUrl('index'));

        $this->assertNull($bid->fresh()->envelope_b_submitted_at);
        $this->assertNull($suggestion->fresh()->envelope_b_decision);
    }

    /** A finalised envelope can never be finalised again. */
    public function test_a_finalised_envelope_cannot_be_reopened(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000130', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000131', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $suggestion = $this->makeSuggestion($bid, $user, 1000);

        $this->actingAs($admin);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])
            ->call('decide', EnvelopeDecision::Approved->value)
            ->call('submit');

        $firstSubmittedAt = $bid->fresh()->envelope_a_submitted_at;
        $this->assertNotNull($firstSubmittedAt);

        // Re-opening the same envelope: the page refuses and nothing changes.
        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])->assertRedirect(BidResource::getUrl('index'));

        $this->assertEquals($firstSubmittedAt, $bid->fresh()->envelope_a_submitted_at);
        $this->assertSame(EnvelopeDecision::Approved, $suggestion->fresh()->envelope_a_decision);
    }

    /** A tender still open for bidding has no envelope to open. */
    public function test_an_open_tender_cannot_have_its_envelope_opened(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000140', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000141', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $this->makeSuggestion($bid, $user, 1000);
        // Push the deadline back into the future — bidding is live again.
        $bid->forceFill(['expire_at' => now()->addDay()])->save();

        $this->actingAs($admin);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])->assertRedirect(BidResource::getUrl('index'));

        $this->assertNull($bid->fresh()->envelope_a_submitted_at);
    }

    /** Staff are not admins: the review is admin-only. */
    public function test_staff_cannot_open_an_envelope(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000150', '1234567891');
        $staff = $this->makeUser(RoleName::Staff, '09120000152', '0084575948');
        $user = $this->makeUser(RoleName::User, '09120000151', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $suggestion = $this->makeSuggestion($bid, $user, 1000);

        $this->actingAs($staff);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])->assertRedirect(BidResource::getUrl('index'));

        $this->assertNull($suggestion->fresh()->envelope_a_decision);
        $this->assertNull($bid->fresh()->envelope_a_submitted_at);
    }

    /**
     * پاکت ب with nothing in it is a valid outcome: the tender ends with no
     * winner, and every bidder still hears about it.
     */
    public function test_a_tender_can_end_with_no_winner(): void
    {
        $this->seed(RoleSeeder::class);
        $sms = $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000160', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000161', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $suggestion = $this->makeSuggestion($bid, $user, 1000);

        $this->actingAs($admin);

        // Rejected in الف, so ب contains nothing at all.
        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])
            ->call('decide', EnvelopeDecision::Declined->value)
            ->call('submit');

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::B->value,
        ])
            ->assertSuccessful()
            ->call('submit');

        $bid = $bid->fresh();
        $this->assertNotNull($bid->envelope_b_submitted_at);
        $this->assertTrue($bid->reviewIsFinished());
        $this->assertCount(0, $bid->winners());
        $this->assertSame(SuggestionStatus::Rejected, $suggestion->fresh()->status);
        // The bidder still gets the declined text at پاکت ب time.
        $this->assertCount(1, $sms->messagesOfTemplate('bid_declined'));
    }

    /**
     * The admin can go back and change a verdict before finalising — that is
     * the whole reason the decisions are drafts.
     */
    public function test_an_admin_can_go_back_and_change_a_decision(): void
    {
        $this->seed(RoleSeeder::class);
        $this->fakeSms();

        $admin = $this->makeUser(RoleName::Admin, '09120000170', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000171', '0499370899');

        $bid = $this->makeExpiredBid($admin);
        $suggestion = $this->makeSuggestion($bid, $user, 1000);

        $this->actingAs($admin);

        Livewire::test(OpenEnvelope::class, [
            'record' => $bid->getKey(),
            'stage' => EnvelopeStage::A->value,
        ])
            ->call('decide', EnvelopeDecision::Approved->value)
            ->call('previous')
            ->call('decide', EnvelopeDecision::Declined->value)
            ->call('submit');

        $this->assertSame(EnvelopeDecision::Declined, $suggestion->fresh()->envelope_a_decision);
        $this->assertSame(SuggestionStatus::Rejected, $suggestion->fresh()->status);
    }
}
