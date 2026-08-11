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

    protected ?string $roleToAssign = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->roleToAssign = $data['role'];
        unset($data['role']);

        $data['mobile_verified_at'] = now();
        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncRoles([$this->roleToAssign]);
    }
}
