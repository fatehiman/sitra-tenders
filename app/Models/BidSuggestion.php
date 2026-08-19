<?php

namespace App\Models;

use App\Enums\EnvelopeDecision;
use App\Enums\EnvelopeStage;
use App\Enums\PaymentType;
use App\Enums\SuggestionAttachmentType;
use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A user's پیشنهاد (proposal/offer) on a tender.
 *
 * It is built up over a six-step wizard
 * (App\Filament\Resources\Bids\Pages\SubmitSuggestion), and lives in one of
 * two very different states:
 *
 *   پیش‌نویس (draft) — the wizard is in progress. Prices, text and files are
 *                     saved on the SERVER after every step, so closing the
 *                     browser loses nothing. It is not a bid: staff cannot
 *                     see it, and it does not lock the tender.
 *   finalised       — the user passed an SMS code. submitted_at and
 *                     tracking_code are stamped, the row starts locking the
 *                     tender, and nothing about it can be edited again.
 *
 * Two guarantees worth knowing before changing anything here:
 *
 *  1. A unique index on (bid_id, user_id) in the migration means one row per
 *     user per tender, forever — no amount of double-clicking creates two.
 *     A draft therefore REUSES the row of a previously cancelled bid rather
 *     than adding one (see startDraft()).
 *  2. A tender carrying any *finalised*, non-cancelled suggestion is locked
 *     against editing/deleting — enforced in App\Policies\BidPolicy via
 *     Bid::isLocked(). Cancelling is the only way to unlock it.
 */
#[Fillable([
    'bid_id', 'user_id', 'note', 'status', 'submitted_at',
    'terms_accepted', 'payment_type',
    'claims_decrease_addressee', 'claims_decrease_tender_number',
    'claims_decrease_subject', 'claims_decrease_org_name',
])]
class BidSuggestion extends Model
{
    // Rows are written by the methods below rather than by a generic
    // update, so Eloquent's updated_at column would carry no information
    // that submitted_at/cancelled_at do not already carry.
    const UPDATED_AT = null;

    /** How many supporting documents the «توضیحات و پیوست‌ها» step accepts. */
    public const MAX_DOCUMENTS = 10;

    /**
     * What an admin sees instead of a bidder's name before that bidder has
     * won — see bidderNameForAdmin().
     */
    public const MASKED_BIDDER_NAME = 'مخفی شده';

    protected function casts(): array
    {
        return [
            // Gives us $suggestion->status->isActive() and the Persian label
            // straight off the enum, instead of comparing raw strings.
            'status' => SuggestionStatus::class,
            'submitted_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_price' => 'integer',
            'terms_accepted' => 'boolean',
            // Null until the user picks one on the «پرداخت» step.
            'payment_type' => PaymentType::class,
            // The admin's per-envelope verdicts. Null = not decided yet, and
            // a decision is only a DRAFT until the tender's matching
            // `envelope_?_submitted_at` is stamped — see App\Enums\EnvelopeDecision.
            'envelope_a_decision' => EnvelopeDecision::class,
            'envelope_b_decision' => EnvelopeDecision::class,
        ];
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who pressed «لغو». Null unless the bid was cancelled. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The priced lines — one per good the user quoted a price for. */
    public function items(): HasMany
    {
        return $this->hasMany(BidSuggestionItem::class);
    }

    /** Every uploaded file, of both types. Filter with ->ofType(...). */
    public function attachments(): HasMany
    {
        return $this->hasMany(BidSuggestionAttachment::class);
    }

    /**
     * The «مشخصات فنی قابل تامین» answers — one row per good the bidder
     * offered a DIFFERENT technical specification for.
     *
     * A good the bidder is happy to supply exactly as specified has no row
     * here at all (see BidSuggestionSpecification and its migration), so this
     * relation is usually much shorter than the tender's goods list.
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(BidSuggestionSpecification::class);
    }

    /**
     * Only the bids that still count — i.e. neither cancelled nor a draft.
     *
     * A "scope" is a reusable piece of query: writing `->active()` in one
     * place beats repeating the where() clause and risking one copy being
     * forgotten (which here would mean a cancelled bid still locking a
     * tender, or a half-finished draft being shown to staff).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', SuggestionStatus::inactiveValues());
    }

    /** Is this row still being built in the wizard? */
    public function isDraft(): bool
    {
        return $this->status === SuggestionStatus::Draft;
    }

    /**
     * The Persian word shown to the user for this bid's current step.
     *
     * The one piece of logic that is NOT a straight enum label: a submitted
     * bid reads «ارسال شده» while the tender is still open, and flips to
     * «دردست بررسی» the moment the tender expires. That is derived from the
     * clock on every read rather than stored, for the same reason Bid has no
     * `status` column — a stored value would need a scheduled job to stay
     * truthful, and would be wrong in the meantime.
     */
    public function getStatusLabel(): string
    {
        if ($this->status === SuggestionStatus::Submitted && $this->bid?->expire_at?->isPast()) {
            return 'دردست بررسی';
        }

        return $this->status->getLabel();
    }

    /** Badge colour to match getStatusLabel(). */
    public function getStatusColor(): string
    {
        if ($this->status === SuggestionStatus::Submitted && $this->bid?->expire_at?->isPast()) {
            return 'warning';
        }

        return $this->status->getColor();
    }

    /*
     * ---- Building a bid ---------------------------------------------------
     */

    /**
     * Get this user's draft for this tender, creating one if they have none.
     *
     * Because of the unique (bid_id, user_id) index there is only ever one
     * row per user per tender, so a user who bids again after an admin
     * cancelled their previous offer REUSES that row — which means the
     * cancellation's who/when/why is cleared here. That is the same accepted
     * trade the old resubmit() made, and the reason is unchanged: keeping
     * "one bid per tender" in the database beats keeping an audit trail in
     * the same table. If a full history is ever required, add a history
     * table; do not drop the unique index.
     *
     * A row that is already a live bid is returned untouched — the caller
     * (the wizard page) refuses to open in that case, and quietly wiping a
     * submitted offer here would be the worst possible way to find out.
     */
    public static function startDraft(Bid $bid, User $user): self
    {
        $suggestion = static::firstOrNew([
            'bid_id' => $bid->id,
            'user_id' => $user->id,
        ]);

        if ($suggestion->exists && $suggestion->status->isActive()) {
            return $suggestion;
        }

        $suggestion->forceFill([
            'status' => SuggestionStatus::Draft,
            'submitted_at' => null,
            'otp_verified_at' => null,
            'tracking_code' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
            // A re-used row can carry verdicts from the offer that was
            // cancelled. They belong to that offer, not to this new one, so
            // they are cleared here — otherwise a fresh bid would arrive at
            // the admin's envelope screens pre-decided.
            'envelope_a_decision' => null,
            'envelope_b_decision' => null,
        ])->save();

        return $suggestion;
    }

    /**
     * Recompute and store total_price from the saved lines.
     *
     * Called after every draft save. The value is stored rather than summed
     * on read because the tenders table and the admin's «پیشنهادهای دریافتی»
     * modal both show it per row — see the migration.
     */
    public function recalculateTotal(): void
    {
        $this->forceFill([
            'total_price' => (int) $this->items()->sum('total_price'),
        ])->save();
    }

    /**
     * Turn the draft into a real, submitted bid.
     *
     * The 8-digit «کد پیگیری» is issued HERE and nowhere else, which is what
     * makes "has a tracking code" and "was finalised" the same fact.
     *
     * @return string the tracking code, to show the user
     */
    public function finalize(): string
    {
        $this->forceFill([
            'status' => SuggestionStatus::Submitted,
            'submitted_at' => now(),
            'otp_verified_at' => now(),
            'tracking_code' => static::generateTrackingCode(),
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ])->save();

        return $this->tracking_code;
    }

    /**
     * An unused 8-digit code.
     *
     * random_int() is the cryptographically secure generator — a code that
     * could be guessed from previous ones would let someone look up other
     * people's bids by their «کد پیگیری». str_pad keeps it exactly eight
     * characters, so 42 becomes 00000042 rather than a shorter code.
     *
     * The loop handles the (astronomically unlikely) collision; the unique
     * index on the column is the real guarantee if two requests ever race.
     */
    public static function generateTrackingCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
        } while (static::where('tracking_code', $code)->exists());

        return $code;
    }

    /*
     * ---- The admin's two-envelope review ----------------------------------
     */

    /**
     * The bidder's alternative specification for one of the tender's goods,
     * or null if they accepted the employer's wording for it.
     *
     * Takes a `bid_good_requirements` id. Reads the LOADED collection rather
     * than querying, because both screens that ask this (the admin's پاکت الف
     * page and «مشاهده پیشنهاد») ask it once per good — a query per row would
     * be the classic N+1.
     */
    public function suppliableSpecificationFor(int $requirementId): ?string
    {
        return $this->specifications
            ->firstWhere('bid_good_requirement_id', $requirementId)
            ?->specifications;
    }

    /** This suggestion's verdict in one envelope, or null if undecided. */
    public function decisionFor(EnvelopeStage $stage): ?EnvelopeDecision
    {
        return $this->{$stage->decisionColumn()};
    }

    /**
     * Record (or change) the admin's verdict on this offer for one envelope.
     *
     * Written to the database immediately — deliberately. It is still only a
     * draft, because nothing reads these columns until the tender's envelope
     * is finalised, and an admin reviewing thirty offers must be able to
     * close the browser and come back without losing the first twenty-nine.
     */
    public function recordDecision(EnvelopeStage $stage, EnvelopeDecision $decision): void
    {
        $this->forceFill([$stage->decisionColumn() => $decision])->save();
    }

    /** Did this offer get through the technical envelope (پاکت الف)? */
    public function passedEnvelopeA(): bool
    {
        return $this->envelope_a_decision === EnvelopeDecision::Approved;
    }

    /**
     * Did this bidder WIN the tender?
     *
     * Both halves are required: an approval in پاکت ب only counts once that
     * envelope has been finalised (`bids.envelope_b_submitted_at`), because
     * until then it is a draft the admin can still change. This is also the
     * one condition that unmasks the bidder's identity for the admin — see
     * bidderNameForAdmin().
     */
    public function isWinner(): bool
    {
        return $this->envelope_b_decision === EnvelopeDecision::Approved
            && $this->bid?->envelope_b_submitted_at !== null;
    }

    /**
     * The name an ADMIN is allowed to see for this bidder.
     *
     * The requirement is explicit: while a tender is being reviewed, whoever
     * is reviewing it must not be able to tell whose offer they are reading —
     * so every screen an admin sees a suggestion on shows «مخفی شده» instead
     * of the name, company name or mobile number. Only the WINNERS are
     * unmasked, once پاکت ب has been finalised.
     *
     * Admins can of course still manage accounts in «کاربران»; what is hidden
     * is the LINK between an account and an offer, which is the thing that
     * would let a review be biased.
     */
    public function bidderNameForAdmin(): string
    {
        return $this->isWinner()
            ? ($this->user?->display_name ?? self::MASKED_BIDDER_NAME)
            : self::MASKED_BIDDER_NAME;
    }

    /*
     * ---- Taking a bid back ------------------------------------------------
     */

    /**
     * Cancel this bid: it stops locking the tender and the user may bid again.
     *
     * @param  User  $admin  who pressed the button — recorded for support
     */
    public function cancel(User $admin, ?string $reason = null): void
    {
        $this->forceFill([
            'status' => SuggestionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
            'cancel_reason' => $reason,
        ])->save();
    }

    /**
     * Delete this bid and everything hanging off it, files included.
     *
     * This is what the OWNER's «انصراف» button does, and it is a real
     * delete, not a status change — the requirement is explicit that a user
     * cancelling their own bid removes it permanently. (The admin's «لغو» is
     * a different thing and still only marks the row, because there the
     * point is to keep a record of who cancelled whose bid and why.)
     *
     * The items and attachment ROWS would go by themselves — both foreign
     * keys are cascadeOnDelete — but a database cascade cannot delete the
     * FILES those rows point at, and it does not fire model events either.
     * So the disk is cleaned up explicitly here, before the row goes.
     */
    public function purge(): void
    {
        foreach ($this->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        $this->delete();
    }

    /**
     * May the person who sent this bid take it back right now?
     *
     * Only while the tender is still open. Once the deadline passes the
     * offers are being compared against each other, and letting a bidder
     * withdraw after seeing the field close would be a different product.
     * An admin's «لغو» has no such limit — that is a correction, not a
     * withdrawal.
     */
    public function isWithdrawable(): bool
    {
        return $this->status->isActive()
            && $this->status !== SuggestionStatus::Cancelled
            && (bool) $this->bid?->expire_at?->isFuture();
    }

    /*
     * ---- Reading the files ------------------------------------------------
     */

    /** The «توضیحات و پیوست‌ها» step's supporting documents. */
    public function documents()
    {
        return $this->attachments->where('type', SuggestionAttachmentType::Document);
    }

    /** The «پرداخت» step's ضمانت‌نامه بانکی upload — at most one file. */
    public function bankGuaranteeFile()
    {
        return $this->attachments->where('type', SuggestionAttachmentType::BankGuaranteeLetter);
    }

    /** The «پرداخت» step's optional نامه کسر از مطالبات attachment. */
    public function claimsDecreaseAttachment()
    {
        return $this->attachments->where('type', SuggestionAttachmentType::ClaimsDecreaseAttachment);
    }
}
