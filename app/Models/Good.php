<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%"));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(GoodDrawing::class);
    }

    public function bidRequirements(): HasMany
    {
        return $this->hasMany(BidGoodRequirement::class);
    }
}
