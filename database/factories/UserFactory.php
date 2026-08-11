<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'mobile' => fake()->unique()->numerify('09#########'),
            'national_id' => self::validNationalId(),
            'person_type' => PersonType::Individual,
            'password' => static::$password ??= Hash::make('password'),
            'mobile_verified_at' => now(),
            'is_active' => true,
        ];
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'person_type' => PersonType::Company,
            'company_name' => fake()->company(),
            'company_national_id' => fake()->numerify('##########0'),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'mobile_verified_at' => null,
        ]);
    }

    /**
     * A random 10-digit national ID that passes the کدملی checksum used by
     * App\Rules\IranianNationalId — so seeded/test users are valid, not
     * just shaped like the right format.
     */
    private static function validNationalId(): string
    {
        $digits = array_map(fn () => random_int(0, 9), range(1, 9));

        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += $digit * (10 - $i);
        }

        $remainder = $sum % 11;
        $checksum = $remainder < 2 ? $remainder : 11 - $remainder;

        return implode('', $digits).$checksum;
    }
}
