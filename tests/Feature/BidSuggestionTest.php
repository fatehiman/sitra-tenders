<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Enums\SuggestionStatus;
use App\Filament\Resources\Bids\BidResource;
use App\Models\Bid;
use App\Models\BidSuggestion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The پیشنهاد lifecycle: submitting one locks the tender, cancelling one
 * unlocks it, and the status a user sees is derived from the tender's clock.
 *
 * These are the rules a future change is most likely to break silently — a
 * missing ->active() somewhere would leave cancelled bids locking tenders
 * forever, and nobody would notice until an admin could not edit anything.
 */
class BidSuggestionTest extends TestCase
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

    private function makeBid(User $creator, ?string $expireAt = null): Bid
    {
        return Bid::create([
            'title' => 'مناقصه تست',
            'description' => 'x',
            'start_at' => now()->subDay(),
            'expire_at' => $expireAt ?? now()->addDay(),
            'created_by' => $creator->id,
        ]);
    }

    public function test_a_submitted_suggestion_locks_the_tender_for_admin_and_staff(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000020', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000021', '0499370899');
        $bid = $this->makeBid($admin);

        $this->assertFalse($bid->isLocked());
        $this->assertTrue($admin->can('update', $bid));

        $bid->suggestions()->create([
            'user_id' => $user->id,
            'note' => 'پیشنهاد',
            'submitted_at' => now(),
        ]);

        // fresh() — isLocked() would otherwise answer from the relationship
        // loaded before the row existed.
        $bid = $bid->fresh();

        $this->assertTrue($bid->isLocked());
        $this->assertFalse($admin->can('update', $bid));
        $this->assertFalse($admin->can('delete', $bid));

        // And the edit URL itself is refused, not just the button.
        $this->actingAs($admin);
        $this->get(BidResource::getUrl('edit', ['record' => $bid]))->assertForbidden();
    }

    public function test_cancelling_a_suggestion_unlocks_the_tender_and_lets_the_user_bid_again(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000022', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000023', '0499370899');
        $bid = $this->makeBid($admin);

        $suggestion = $bid->suggestions()->create([
            'user_id' => $user->id,
            'note' => 'پیشنهاد اول',
            'submitted_at' => now(),
        ]);

        $suggestion->cancel($admin, 'اشتباه ثبت شده بود');

        $this->assertSame(SuggestionStatus::Cancelled, $suggestion->fresh()->status);
        $this->assertFalse($bid->fresh()->isLocked());
        $this->assertTrue($admin->can('update', $bid->fresh()));

        // Re-bidding reuses the same row — the unique (bid_id, user_id)
        // index means there is nowhere else for it to go. startDraft() is
        // what the wizard calls when the user presses «ارسال پیشنهاد» again;
        // finalize() is the last step of that wizard.
        $draft = BidSuggestion::startDraft($bid, $user);
        $this->assertSame($suggestion->id, $draft->id);
        $this->assertSame(SuggestionStatus::Draft, $draft->status);
        $this->assertNull($draft->cancelled_at);

        $draft->finalize();

        $suggestion = $suggestion->fresh();
        $this->assertSame(SuggestionStatus::Submitted, $suggestion->status);
        $this->assertNull($suggestion->cancelled_at);
        $this->assertSame(1, $bid->suggestions()->count());
        $this->assertTrue($bid->fresh()->isLocked());
    }

    /**
     * A draft must be invisible as a bid — otherwise anyone could freeze any
     * tender indefinitely just by opening the wizard and walking away.
     */
    public function test_a_draft_does_not_lock_the_tender_and_is_hidden_from_staff(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000040', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000041', '0499370899');
        $bid = $this->makeBid($admin);

        $draft = BidSuggestion::startDraft($bid, $user);

        $this->assertSame(SuggestionStatus::Draft, $draft->status);
        $this->assertFalse($draft->status->isActive());
        $this->assertFalse($bid->fresh()->isLocked());
        $this->assertTrue($admin->can('update', $bid->fresh()));
        // activeSuggestions is what the «پیشنهادهای دریافتی» modal reads.
        $this->assertCount(0, $bid->fresh()->activeSuggestions);

        // ...and finalising flips every one of those answers.
        $draft->finalize();

        $this->assertTrue($bid->fresh()->isLocked());
        $this->assertCount(1, $bid->fresh()->activeSuggestions);
    }

    public function test_finalizing_issues_a_unique_eight_digit_tracking_code(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000042', '1234567891');
        $one = $this->makeUser(RoleName::User, '09120000043', '0499370899');
        $two = $this->makeUser(RoleName::User, '09120000044', '0084575948');
        $bid = $this->makeBid($admin);

        // A draft has no code — "has a tracking code" and "was finalised"
        // are meant to be the same question.
        $draft = BidSuggestion::startDraft($bid, $one);
        $this->assertNull($draft->tracking_code);

        $first = $draft->finalize();
        $second = BidSuggestion::startDraft($bid, $two)->finalize();

        $this->assertMatchesRegularExpression('/^\d{8}$/', $first);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * «انصراف» is the OWNER taking their bid back, and it is a real delete —
     * unlike the admin's «لغو», which keeps the row and its reason.
     */
    public function test_a_user_may_withdraw_their_own_bid_only_before_the_deadline(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000045', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000046', '0499370899');

        $open = $this->makeBid($admin);
        $closed = $this->makeBid($admin, expireAt: now()->subHour());

        $onOpen = BidSuggestion::startDraft($open, $user);
        $onOpen->finalize();
        $onClosed = BidSuggestion::startDraft($closed, $user);
        $onClosed->finalize();

        $this->assertTrue($onOpen->fresh()->isWithdrawable());
        $this->assertFalse($onClosed->fresh()->isWithdrawable());

        $onOpen->purge();

        // Gone entirely — not marked «لغو شده» — so the tender is free again
        // and the user may send a fresh bid.
        $this->assertSame(0, $open->suggestions()->count());
        $this->assertFalse($open->fresh()->isLocked());
    }

    public function test_status_label_flips_to_under_review_once_the_tender_expires(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = $this->makeUser(RoleName::Staff, '09120000024', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000025', '0499370899');

        $open = $this->makeBid($staff);
        $closed = $this->makeBid($staff, expireAt: now()->subHour());

        $onOpen = $open->suggestions()->create(['user_id' => $user->id, 'note' => 'x', 'submitted_at' => now()]);
        $onClosed = $closed->suggestions()->create(['user_id' => $user->id, 'note' => 'x', 'submitted_at' => now()]);

        $this->assertSame('ارسال شده', $onOpen->fresh()->getStatusLabel());
        $this->assertSame('دردست بررسی', $onClosed->fresh()->getStatusLabel());

        // The later steps are plain stored values — no clock involved.
        $onClosed->forceFill(['status' => SuggestionStatus::FormA])->save();
        $this->assertSame('فرم الف', $onClosed->fresh()->getStatusLabel());
    }

    /**
     * The مناقصات list renders for both audiences with a live bid present.
     *
     * Cheap, but it is the only thing that exercises the new columns' and
     * actions' closures — a typo in one of them is a 500 on the app's home
     * page for that role, and nothing else here would catch it.
     */
    public function test_the_bids_list_renders_with_a_submitted_suggestion(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000029', '1234567891');
        $user = $this->makeUser(RoleName::User, '09120000030', '0499370899');
        $bid = $this->makeBid($admin);

        // Finalised through the model, so the row carries the things the new
        // columns read: a tracking code and a total.
        $suggestion = BidSuggestion::startDraft($bid, $user);
        $suggestion->forceFill(['note' => 'x', 'total_price' => 1_250_000])->save();
        $trackingCode = $suggestion->finalize();

        $this->actingAs($user);
        $this->get(BidResource::getUrl('index'))
            ->assertSuccessful()
            // The «وضعیت پیشنهاد» column for a tender that is still open.
            ->assertSee('ارسال شده')
            // «کد پیگیری» and «مبلغ پیشنهاد» — the latter Latin-grouped, like
            // every other number in the panel.
            ->assertSee($trackingCode)
            ->assertSee('1,250,000');

        // Switching identity mid-test needs the session cleared first: the
        // cookie from the request above still holds the user's login id, and
        // Filament's auth middleware would bounce the mismatched request to
        // /login rather than render anything.
        $this->flushSession();

        $this->actingAs($admin);
        $this->get(BidResource::getUrl('index'))->assertSuccessful();
    }

    public function test_active_scope_excludes_cancelled_suggestions(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUser(RoleName::Admin, '09120000026', '1234567891');
        $one = $this->makeUser(RoleName::User, '09120000027', '0499370899');
        $two = $this->makeUser(RoleName::User, '09120000028', '0084575948');
        $bid = $this->makeBid($admin);

        $cancelled = $bid->suggestions()->create(['user_id' => $one->id, 'note' => 'x', 'submitted_at' => now()]);
        $live = $bid->suggestions()->create(['user_id' => $two->id, 'note' => 'y', 'submitted_at' => now()]);
        $cancelled->cancel($admin);

        $activeIds = $bid->activeSuggestions()->pluck('id')->all();

        $this->assertSame([$live->id], $activeIds);
    }
}
