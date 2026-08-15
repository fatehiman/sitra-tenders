<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A کالا (item/good) in the shared catalogue that tenders draw from.
 *
 * `code` is کد کالا (unique), `name` is شرح کالا, `specifications` is
 * ابعاد و مشخصات فنی, and the نقشه (technical drawing) files hang off the
 * drawings() relationship.
 *
 * There is deliberately no unit-of-measure column: quantities on a tender
 * are plain integer counts («۱۰۰۰ عدد»).
 */
#[Fillable(['code', 'name', 'specifications', 'created_by'])]
class Good extends Model
{
    /**
     * How a good reads inside the bid form's searchable picker: the Persian
     * description with the code in parentheses, so typing either one finds
     * it — e.g. «پیچ آلن ۸ میل (۸۳۷۲۴)».
     */
    public function getPickerLabelAttribute(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Free-text search across both the Persian description and the code,
     * backing the picker's `getSearchResultsUsing`.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        // The inner closure wraps both conditions in parentheses:
        //   WHERE (name LIKE ... OR code LIKE ...)
        // Without it, the OR would escape and combine with any other WHERE
        // the caller had already added, matching far too many rows.
        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%"));
    }

    /** The admin/staff member who added this good to the catalogue. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** نقشه files (PDF or image) attached to this good. */
    public function drawings(): HasMany
    {
        return $this->hasMany(GoodDrawing::class);
    }

    /**
     * Every «we need N of this» row across all tenders. Used to refuse
     * deleting a good that a tender still cites.
     */
    public function bidRequirements(): HasMany
    {
        return $this->hasMany(BidGoodRequirement::class);
    }
}
