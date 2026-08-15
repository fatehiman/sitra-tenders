<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Bid;
use App\Models\User;

/**
 * The مناقصات list is visible to every role (users see only active bids —
 * enforced in BidsTable, not here). Only admin/staff can create/edit/delete.
 *
 * A "policy" answers yes/no questions about who may do what to a model.
 * Laravel finds this class by name (Bid -> BidPolicy) and Filament calls it
 * automatically, which is why a user with no permission simply never sees
 * the button — the check is not something each page has to remember.
 *
 * The method names are fixed by Laravel: viewAny is the list screen, view a
 * single record, and so on.
 */
class BidPolicy
{
    /** Everyone may open the tender list. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Everyone may open a single tender they can already see in the list. */
    public function view(User $user, Bid $bid): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    /**
     * Staff/admin — UNLESS somebody has already bid on this tender.
     *
     * A user bids against a specific title, description, deadline and goods
     * list; letting those change afterwards would silently rewrite the terms
     * of a submitted offer. So the tender freezes on first bid, for admins
     * too. The way out is the «لغو» action on the مناقصات table (admin only),
     * which cancels the bids and unlocks the tender.
     *
     * Putting the rule here rather than only on the Edit button means the
     * /bids/{id}/edit URL is blocked as well — Filament hides the button
     * *because* of this method, not the other way round. BidsTable shows a
     * lock icon in the button's place so the absence is explained rather
     * than mysterious.
     */
    public function update(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user) && ! $bid->isLocked();
    }

    /** Same lock as update() — deleting would take the bids down with it. */
    public function delete(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user) && ! $bid->isLocked();
    }

    // restore/forceDelete only apply to soft-deleted records. Bids are not
    // soft-deleted today, but the methods are here so the answer is already
    // correct if soft deletes are switched on later.
    public function restore(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function forceDelete(User $user, Bid $bid): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    /** One place to change if the role rules for tenders ever move. */
    private function isStaffOrAdmin(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::Staff->value]);
    }
}
