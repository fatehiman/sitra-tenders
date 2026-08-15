<?php

namespace Database\Factories;

use App\Enums\PersonType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
/**
 * Builds throwaway User records for tests, e.g. `User::factory()->create()`.
 *
 * Nothing here runs in production — it exists so tests can say "a company
 * user exists" in one line instead of listing every column.
 */
class UserFactory extends Factory
{
    // Hashing is deliberately slow (that is the point of bcrypt), so the
    // hash is computed once and reused across every user a test creates.
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

    /**
     * A «حقوقی» (company) user: `User::factory()->company()->create()`.
     *
     * A "state" is a named set of overrides layered on top of definition().
     * The شناسه ملی is just eleven random digits, which is all the app
     * validates — no checksum is applied to it anywhere.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'person_type' => PersonType::Company,
            'company_name' => fake()->company(),
            'company_national_id' => fake()->numerify('###########'),
        ]);
    }

    /** A user whose mobile has not been confirmed yet. */
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
        // Nine random digits, then compute the tenth so the whole number
        // satisfies the checksum — the same arithmetic the rule performs.
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
