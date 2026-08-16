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

    /**
     * `$user` is the admin doing the deleting; `$model` is the account being
     * deleted. Deletion is PERMANENT (there is no soft delete on this
     * model), so two accounts are off limits:
     *
     *   - your own — the fastest possible way to lock everyone out of the
     *     panel;
     *   - any other admin — an explicit requirement: admins delete staff and
     *     regular users, never each other. Demote the account first if it
     *     really has to go.
     *
     * Not encoded here: "this account published tenders". That check lives
     * in UsersTable's delete action, for the same reason GoodPolicy::delete()
     * leaves the in-use check to GoodsTable — a false here would silently
     * hide the button and leave the operator guessing, where a notification
     * can name the tenders standing in the way.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->hasRole(RoleName::Admin->value)
            && $user->isNot($model)
            && ! $model->isAdmin();
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
