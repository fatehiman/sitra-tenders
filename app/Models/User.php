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

/**
 * A person or a company with an account. There is no separate "company"
 * table: a company account is simply a user row whose `company_name` is set.
 *
 * Notice there is NO email column anywhere — this app identifies and
 * authenticates people by mobile number.
 *
 * #[Fillable] lists the columns that may be set in bulk (i.e. via
 * `User::create([...])`). Anything not listed is silently dropped, which is
 * what stops a crafted form post from setting, say, `is_active` or
 * `mobile_verified_at` on itself.
 */
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
// #[Hidden] keeps these out of any array/JSON conversion of the model, so a
// password hash can never leak into a response or a log line by accident.
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    // HasRoles comes from spatie/laravel-permission and adds assignRole(),
    // hasRole(), hasAnyRole() — the whole role system used by the policies.
    use HasFactory, HasRoles, Notifiable;

    /**
     * "Casts" convert raw database values into useful PHP types on read, and
     * back again on write.
     */
    protected function casts(): array
    {
        return [
            // 'individual'/'company' string <-> the PersonType enum.
            'person_type' => PersonType::class,
            // A datetime string <-> a Carbon date object, so code can call
            // things like ->isPast() on it.
            'mobile_verified_at' => 'datetime',
            'is_active' => 'boolean',
            // 'hashed' means: if a plain password is ever assigned, hash it
            // automatically. A second safety net behind Hash::make().
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

    /**
     * Required by Filament's HasName contract — this is the name Filament
     * prints in the top-right user menu.
     *
     * `$this->display_name` (snake_case) resolves to the accessor above;
     * that "getXAttribute" -> "$model->x" mapping is an Eloquent convention.
     */
    public function getFilamentName(): string
    {
        return $this->display_name;
    }

    /**
     * Required by Filament's FilamentUser contract — the last gate before a
     * logged-in account is allowed into the panel at all. Deactivating an
     * account (is_active = false) locks it out without deleting anything.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /*
     * ---- Relationships -------------------------------------------------
     * belongsTo = this row points at one other row (it holds the foreign
     * key). hasMany = other rows point back at this one.
     */

    /** The admin who created this account, if it wasn't a public sign-up. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    /** Tenders this user published (admin/staff only, in practice). */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class, 'created_by');
    }

    /** Suggestions this user submitted on tenders — at most one per tender. */
    public function bidSuggestions(): HasMany
    {
        return $this->hasMany(BidSuggestion::class);
    }
}
