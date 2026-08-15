<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Admin/staff-created accounts skip the mobile-OTP flow entirely (see
 * ARCHITECTURE.md's "Registration + OTP flow") — mobile_verified_at is
 * stamped immediately and created_by records who made the account.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Carried from the hook below to afterCreate(), same as attachments. */
    protected ?string $roleToAssign = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The role is not a column on `users` — it is a row in the roles
        // pivot table, which can only be written once the user exists.
        $this->roleToAssign = $data['role'];
        unset($data['role']);

        // No OTP for admin-created accounts: an admin typing the number in
        // person is the verification, so mark it verified straight away.
        $data['mobile_verified_at'] = now();
        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // syncRoles replaces all roles with exactly this one, so a user can
        // never accidentally end up holding two.
        $this->record->syncRoles([$this->roleToAssign]);
    }
}
