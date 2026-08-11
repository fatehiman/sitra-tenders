<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates شناسه ملی (Iranian legal-entity/company national ID) using its
 * standard 11-digit checksum algorithm.
 *
 * NOTE: unlike the individual کدملی checksum (well-documented, verified),
 * this company checksum formula is reproduced from commonly-cited
 * community references and has not been independently verified against an
 * authoritative source in this codebase — sanity-check against a batch of
 * known-valid شناسه ملی values before relying on it to hard-reject
 * registrations (see PLAN.md open items).
 */
class IranianCompanyNationalId implements ValidationRule
{
    private const COEFFICIENTS = [29, 27, 23, 19, 17, 29, 27, 23, 19];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (! preg_match('/^\d{11}$/', $value)) {
            $fail('شناسه ملی باید یک عدد ۱۱ رقمی باشد.');

            return;
        }

        $digits = array_map('intval', str_split($value));

        $firstTwoSum = $digits[0] + $digits[1];
        $firstTwoSum = $firstTwoSum > 9 ? $firstTwoSum % 10 : $firstTwoSum;

        $sequence = [$firstTwoSum, ...array_slice($digits, 2, 8)];

        $sum = 0;
        foreach ($sequence as $i => $digit) {
            $sum += $digit * self::COEFFICIENTS[$i];
        }

        $remainder = $sum % 11;
        $expected = $remainder === 10 ? 0 : $remainder;

        if ($digits[10] !== $expected) {
            $fail('شناسه ملی وارد شده معتبر نیست.');
        }
    }
}
