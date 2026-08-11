<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Bid;
use App\Models\User;

/**
 * The مناقصات list is visible to every role (users see only active bids —
 * enforced in BidsTable, not here). Only admin/staff can create/edit/delete.
 */
class BidPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Bid $bid): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function update(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function delete(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function restore(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function forceDelete(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    private function isStaffOrAdmin(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::Staff->value]);
    }
}
