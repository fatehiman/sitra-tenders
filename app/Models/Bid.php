<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'start_at', 'expire_at', 'created_by'])]
class Bid extends Model
{
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'expire_at' => 'datetime',
        ];
    }

    /**
     * Scope applied to the `user` role's view only — started, not yet
     * expired. Admin/staff query the unscoped model to manage the full
     * lifecycle (scheduled/active/expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('start_at', '<=', now())->where('expire_at', '>', now());
    }

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BidAttachment::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(BidSuggestion::class);
    }
}
