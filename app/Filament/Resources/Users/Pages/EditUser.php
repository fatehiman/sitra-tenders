<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * "Edit a user". The only fiddly part is the role: it is stored in a
 * separate pivot table rather than as a column, so it has to be read into
 * the form on the way in and written back out separately on the way out.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // UserPolicy::delete() hides this on the admin's own account.
            DeleteAction::make(),
        ];
    }

    /** Runs when the page loads: put the current role into the form. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // first() because a user only ever holds one role here — see the
        // syncRoles call below, which enforces that.
        $data['role'] = $this->record->roles->first()?->name;

        return $data;
    }

    /** Runs on save: write the role, then drop it from the column data. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /*
         * The key can legitimately be MISSING: on your own account the
         * «سطح دسترسی» field is disabled, and a disabled Filament field is
         * not sent back with the saved data at all. That is the whole
         * mechanism stopping an admin from demoting themselves — so treat
         * the absence as "leave the role exactly as it is", never as null
         * (syncRoles([null]) would strip every role from the account).
         */
        if (array_key_exists('role', $data)) {
            $this->record->syncRoles([$data['role']]);
            unset($data['role']);
        }

        return $data;
    }
}
