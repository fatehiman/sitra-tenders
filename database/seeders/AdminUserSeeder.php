<?php

namespace Database\Seeders;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstraps the first admin account.
 *
 * The password is never hard-coded: it comes from `ADMIN_SEED_PASSWORD`, or
 * is generated randomly and printed once if that isn't set. This repository
 * is public, so a literal here would be a published credential for every
 * environment that ever ran `--seed` — which is exactly what happened with
 * the previous `ChangeMe123!` and had to be rotated by hand in production.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // ?: means "use the left side unless it is empty/false".
        $password = env('ADMIN_SEED_PASSWORD') ?: Str::password(20, symbols: false);

        // firstOrCreate: find the row matching the FIRST array, and only if
        // it doesn't exist, create it using both arrays merged. That makes
        // re-running the seeder harmless — it never overwrites a live admin.
        $admin = User::firstOrCreate(
            ['mobile' => '09120000000'],
            [
                'first_name' => 'مدیر',
                'last_name' => 'سامانه',
                'national_id' => '0499370899',
                'person_type' => PersonType::Individual,
                'password' => Hash::make($password),
                'mobile_verified_at' => now(),
                'is_active' => true,
            ]
        );

        // Outside the firstOrCreate so an existing admin that somehow lost
        // its role gets it back. assignRole is idempotent.
        $admin->assignRole(RoleName::Admin->value);

        // Only meaningful when the row was just created — an existing admin
        // keeps whatever password it already had.
        if ($admin->wasRecentlyCreated && ! env('ADMIN_SEED_PASSWORD')) {
            $this->command?->warn("Seeded admin 09120000000 with generated password: {$password}");
            $this->command?->warn('Store it now — it is not written anywhere else.');
        }
    }
}
