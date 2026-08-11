<?php

namespace App\Models;

use App\Enums\PersonType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'first_name',
    'last_name',
    'mobile',
    'national_id',
    'person_type',
    'company_name',
    'company_national_id',
    'password',
    'mobile_verified_at',
    'created_by',
    'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'mobile_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Company accounts display their company name everywhere in the app;
     * personal accounts display name + family. `company_name` is only ever
     * set when person_type is company, so checking it alone is sufficient.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->company_name ?: trim("{$this->first_name} {$this->last_name}");
    }

    public function getFilamentName(): string
    {
        return $this->display_name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class, 'created_by');
    }

    public function bidSuggestions(): HasMany
    {
        return $this->hasMany(BidSuggestion::class);
    }
}
