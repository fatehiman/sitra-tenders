<?php

namespace App\Models;

use App\Enums\EnvelopeDecision;
use App\Enums\EnvelopeStage;
use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A مناقصه (tender/bid) — the central object of the whole app.
 *
 * Note the class is called `Bid` while the UI everywhere says «مناقصه». The
 * English name is only ever seen by developers; every user-visible label is
 * Persian.
 *
 * `description` holds HTML produced by Filament's rich-text editor. It is
 * always rendered back through Filament's RichContentRenderer rather than
 * with raw {!! !!}, which strips anything the editor itself cannot produce
 * (scripts, event handlers) — see ARCHITECTURE.md.
 *
 * `deposit_amount` is the ودیعه (bid-guarantee deposit) admin sets when
 * publishing the tender — the payment required just to be ALLOWED to bid,
 * shown to the user at the top of the پیشنهاد wizard's «پرداخت» step. It has
 * nothing to do with the price the bidder quotes for the goods themselves
 * (that is `BidSuggestion::$total_price`).
 *
 * Dates are stored as ordinary Gregorian datetimes; Jalali (Shamsi) is a
 * display-only conversion applied when rendering.
 */
#[Fillable(['title', 'description', 'deposit_amount', 'start_at', 'expire_at', 'created_by'])]
class Bid extends Model
{
    protected function casts(): array
    {
        // Turn the raw datetime strings into Carbon objects so the code
        // below can ask them questions like ->isFuture().
        return [
            'start_at' => 'datetime',
            'expire_at' => 'datetime',
            // Whole ریال, same as every other money column in this app —
            // see App\Models\BidSuggestion::$total_price.
            'deposit_amount' => 'integer',
            // When the admin finalised each review envelope. Null means "not
            // finalised yet" — see App\Enums\EnvelopeStage and the envelope
            // methods at the bottom of this class.
            'envelope_a_submitted_at' => 'datetime',
            'envelope_b_submitted_at' => 'datetime',
        ];
    }

    /**
     * Scope applied to the `user` role's view only — started, not yet
     * expired. Admin/staff query the unscoped model to manage the full
     * lifecycle (scheduled/active/expired).
     *
     * A "scope" is a reusable piece of query. Naming it scopeActive() lets
     * the rest of the app write `Bid::active()->get()` instead of repeating
     * both where() clauses (and risking one of them being forgotten, which
     * would expose unpublished tenders).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('start_at', '<=', now())->where('expire_at', '>', now());
    }

    /**
     * Persian status word derived from the two dates — there is no `status`
     * column, because a stored status would need a scheduled job to keep it
     * truthful as time passes. Computing it on read cannot go stale.
     */
    public function getStatusLabelAttribute(): string
    {
        if ($this->start_at->isFuture()) {
            return 'زمان‌بندی‌شده';
        }

        if ($this->expire_at->isPast()) {
            return 'پایان‌یافته';
        }

        return 'فعال';
    }

    /** Who published it — 'created_by' is the foreign-key column name. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Uploaded files: PDFs, Office documents, images, video, audio. */
    public function attachments(): HasMany
    {
        return $this->hasMany(BidAttachment::class);
    }

    /**
     * The «کالاهای مورد نیاز» rows defined at the bottom of the bid form.
     */
    public function goodRequirements(): HasMany
    {
        return $this->hasMany(BidGoodRequirement::class);
    }

    /** Bids submitted by users — one per user per tender (DB-enforced). */
    public function suggestions(): HasMany
    {
        return $this->hasMany(BidSuggestion::class);
    }

    /**
     * The same list minus the cancelled ones — i.e. the bids that still
     * count. This is the relationship every rule below is written against.
     */
    public function activeSuggestions(): HasMany
    {
        return $this->suggestions()->active();
    }

    /**
     * THIS tender's bid by THIS logged-in user, or null.
     *
     * A `hasOne` narrowed to the current session's user id. Defining it as a
     * relationship rather than a helper method is what lets the مناقصات
     * table eager-load it («->with('mySuggestion')»): one extra query for the
     * whole page instead of one per row.
     *
     * Because the id is read at call time, this relationship is meaningless
     * for a guest or in a queued job — it is only ever used from the panel,
     * which is behind auth.
     */
    public function mySuggestion(): HasOne
    {
        return $this->hasOne(BidSuggestion::class)->where('user_id', Auth::id());
    }

    /**
     * Is this tender open for bidding right now?
     *
     * The same two conditions as scopeActive(), asked of one loaded model
     * instead of the query — used by the bid wizard, which has to re-check
     * on the server that the tender it is about to write to is still open
     * (the visible row proves nothing; the page could have been sitting
     * open past the deadline).
     */
    public function isOpen(): bool
    {
        return $this->start_at->isPast() && $this->expire_at->isFuture();
    }

    /**
     * Is this tender frozen because somebody has already bid on it?
     *
     * Once a user submits a bid, the terms they bid against must not change
     * underneath them, so App\Policies\BidPolicy refuses update() and
     * delete() for locked tenders — for admin and staff alike. An admin can
     * unlock one by cancelling the bids («لغو» on the مناقصات table).
     *
     * The relationLoaded() branch keeps the مناقصات table at one query: the
     * table already loads a count, so re-asking the database per row would
     * be pure waste.
     */
    public function isLocked(): bool
    {
        if ($this->relationLoaded('activeSuggestions')) {
            return $this->activeSuggestions->isNotEmpty();
        }

        return $this->activeSuggestions()->exists();
    }

    /*
     * ---- The admin's two-envelope review ----------------------------------
     */

    /**
     * Has the admin finalised this envelope for this tender?
     *
     * "Finalised" is the irreversible step: the per-offer verdicts stop being
     * drafts and become the bidders' real statuses. Everything else about the
     * review — which offers are approved so far, how far through the list the
     * admin got — is answered by the suggestions themselves.
     */
    public function envelopeIsSubmitted(EnvelopeStage $stage): bool
    {
        return $this->{$stage->submittedAtColumn()} !== null;
    }

    /**
     * May the admin open this envelope right now?
     *
     * The rules, in order:
     *   - the tender must have EXPIRED. Reviewing offers while more can still
     *     arrive would be reviewing half a field;
     *   - it must carry at least one live offer, or there is nothing to open;
     *   - this envelope must not already be finalised — finalising cannot be
     *     undone;
     *   - پاکت ب additionally requires پاکت الف to be finalised first: ب only
     *     ever shows the offers that got through الف.
     */
    public function envelopeIsOpenable(EnvelopeStage $stage): bool
    {
        if (! $this->expire_at->isPast() || $this->activeSuggestions->isEmpty()) {
            return false;
        }

        if ($this->envelopeIsSubmitted($stage)) {
            return false;
        }

        return $stage === EnvelopeStage::A
            ? true
            : $this->envelopeIsSubmitted(EnvelopeStage::A);
    }

    /** Both envelopes finalised — the tender is over and the winners are known. */
    public function reviewIsFinished(): bool
    {
        return $this->envelopeIsSubmitted(EnvelopeStage::B);
    }

    /**
     * The offers the admin reviews in one envelope, in a stable order.
     *
     * پاکت الف is every live offer. پاکت ب is only those approved in الف —
     * the whole point of the two-envelope process is that the offers rejected
     * on technical grounds never have their prices read at all.
     *
     * Ordered by id (i.e. by the order the drafts were started) rather than
     * by amount or name: any order derived from the CONTENT of the offers
     * would nudge the reviewer, and one derived from the bidder would defeat
     * the anonymity the whole review is built around.
     *
     * @return Collection<int, BidSuggestion>
     */
    public function envelopeSuggestions(EnvelopeStage $stage)
    {
        $query = $this->activeSuggestions()
            ->with(['items.requirement.good', 'specifications', 'attachments', 'user'])
            ->orderBy('id');

        if ($stage === EnvelopeStage::B) {
            $query->where('envelope_a_decision', EnvelopeDecision::Approved->value);
        }

        return $query->get();
    }

    /**
     * Turn one envelope's draft verdicts into the bidders' real statuses, and
     * stamp the tender so it can never be done again.
     *
     * پاکت الف: approved offers move to «فرم الف» (through to the financial
     *           envelope), declined ones to «رد شده».
     * پاکت ب:   approved offers move to «تایید شده» — those bidders have won —
     *           and declined ones to «رد شده».
     *
     * Everything happens inside one transaction: a half-finalised envelope
     * (some statuses written, the tender not stamped) would be reviewable
     * again with some of its bidders already told the outcome.
     *
     * The SMS messages are NOT sent from here on purpose — see
     * App\Services\SuggestionResultNotifier and the caller
     * (App\Filament\Resources\Bids\Pages\OpenEnvelope::submit()): a texting
     * failure must never roll back a decision that has already been made.
     *
     * @return Collection<int, BidSuggestion> the
     *                                        offers this call decided, for the caller to notify
     */
    public function finalizeEnvelope(EnvelopeStage $stage)
    {
        $suggestions = $this->envelopeSuggestions($stage);

        DB::transaction(function () use ($stage, $suggestions): void {
            foreach ($suggestions as $suggestion) {
                $approved = $suggestion->decisionFor($stage) === EnvelopeDecision::Approved;

                $suggestion->forceFill([
                    'status' => match (true) {
                        $stage === EnvelopeStage::A && $approved => SuggestionStatus::FormA,
                        $stage === EnvelopeStage::B && $approved => SuggestionStatus::Approved,
                        default => SuggestionStatus::Rejected,
                    },
                ])->save();
            }

            $this->forceFill([$stage->submittedAtColumn() => now()])->save();
        });

        return $suggestions;
    }

    /**
     * Move the offers that got through پاکت الف to «فرم ب» — i.e. "the
     * financial envelope is being read now".
     *
     * Called when the admin OPENS پاکت ب, not when they submit it. It writes
     * no verdict, only the step the offer is at, which is what the bidder's
     * «وضعیت» column shows them. Without this the status ladder would jump
     * from «فرم الف» straight to «تایید شده»/«رد شده» and the «فرم ب» rung
     * would never be used.
     */
    public function markEnvelopeBInProgress(): void
    {
        $this->activeSuggestions()
            ->where('envelope_a_decision', EnvelopeDecision::Approved->value)
            ->where('status', SuggestionStatus::FormA->value)
            ->update(['status' => SuggestionStatus::FormB->value]);
    }

    /**
     * The bidders who won, once پاکت ب is finalised — an empty collection
     * before that, because an approval in an unfinalised envelope is still a
     * draft the admin can change (see BidSuggestion::isWinner()).
     *
     * @return Collection<int, BidSuggestion>
     */
    public function winners()
    {
        if (! $this->reviewIsFinished()) {
            return $this->suggestions()->whereRaw('1 = 0')->get();
        }

        return $this->activeSuggestions()
            ->where('envelope_b_decision', EnvelopeDecision::Approved->value)
            ->with(['user', 'items.requirement.good'])
            ->orderBy('id')
            ->get();
    }
}
