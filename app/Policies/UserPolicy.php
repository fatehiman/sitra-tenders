<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

/**
 * The UserResource (list/create/edit all registered users, create staff
 * without OTP) is admin-only per the explicit requirement.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value) && $user->isNot($model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
