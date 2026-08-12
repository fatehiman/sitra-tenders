<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_password_from_the_environment_when_one_is_set(): void
    {
        $this->seed(RoleSeeder::class);
        putenv('ADMIN_SEED_PASSWORD=SeededFromEnv123');

        try {
            $this->seed(AdminUserSeeder::class);
        } finally {
            putenv('ADMIN_SEED_PASSWORD');
        }

        $admin = User::where('mobile', '09120000000')->firstOrFail();

        $this->assertTrue($admin->hasRole(RoleName::Admin->value));
        $this->assertTrue(Hash::check('SeededFromEnv123', $admin->password));
    }

    public function test_it_generates_a_random_password_rather_than_a_published_literal(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('mobile', '09120000000')->firstOrFail();

        // The repository is public — no literal in the seeder may ever be a
        // working credential on a freshly seeded environment.
        $this->assertFalse(Hash::check('ChangeMe123!', $admin->password));
    }
}
