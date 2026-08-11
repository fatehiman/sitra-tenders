<?php

namespace Database\Seeders;

use App\Enums\PersonType;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-dev-only convenience seeder. The password below is a throwaway —
 * rotate it immediately after first login on any real environment.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['mobile' => '09120000000'],
            [
                'first_name' => 'مدیر',
                'last_name' => 'سامانه',
                'national_id' => '0499370899',
                'person_type' => PersonType::Individual,
                'password' => Hash::make('ChangeMe123!'),
                'mobile_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $admin->assignRole(RoleName::Admin->value);
    }
}
