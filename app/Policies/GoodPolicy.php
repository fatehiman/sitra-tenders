<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Good;
use App\Models\User;

/**
 * کالاها is an admin/staff catalogue — regular users never see the menu item,
 * they only ever see goods indirectly, as the requirement rows of a bid.
 */
class GoodPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function view(User $user, Good $good): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function update(User $user, Good $good): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    /**
     * "Is this good already cited by a tender?" is deliberately NOT checked
     * here: a false from the policy would silently hide the delete button and
     * leave the operator guessing. The check lives in GoodsTable's delete
     * action instead, which halts with a Persian message naming the tenders,
     * backed by the `restrictOnDelete` FK on `bid_good_requirements`.
     */
    public function delete(User $user, Good $good): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function restore(User $user, Good $good): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function forceDelete(User $user, Good $good): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    private function isStaffOrAdmin(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::Staff->value]);
    }
}
