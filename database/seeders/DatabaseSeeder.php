<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The entry point for `php artisan db:seed` (and `migrate --seed`).
 *
 * Order matters: roles have to exist before the admin account can be given
 * one.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
