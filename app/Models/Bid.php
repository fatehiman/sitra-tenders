<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

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
 * Dates are stored as ordinary Gregorian datetimes; Jalali (Shamsi) is a
 * display-only conversion applied when rendering.
 */
#[Fillable(['title', 'description', 'start_at', 'expire_at', 'created_by'])]
class Bid extends Model
{
    protected function casts(): array
    {
        // Turn the raw datetime strings into Carbon objects so the code
        // below can ask them questions like ->isFuture().
        return [
            'start_at' => 'datetime',
            'expire_at' => 'datetime',
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
}
