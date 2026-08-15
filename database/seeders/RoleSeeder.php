<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates the `roles` rows the whole permission system depends on.
 *
 * This must run before any user can be assigned a role, which is why
 * DatabaseSeeder calls it first and every test that registers a user seeds
 * it explicitly.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Driven by the enum, so adding a fourth role is a one-line change
        // in RoleName and a re-run of this seeder — no code edits here.
        foreach (RoleName::cases() as $role) {
            // findOrCreate makes this safe to run repeatedly: existing roles
            // are left alone rather than duplicated.
            // 'web' is the guard name — which authentication system the role
            // applies to. This app only has the standard web guard.
            Role::findOrCreate($role->value, 'web');
        }
    }
}
