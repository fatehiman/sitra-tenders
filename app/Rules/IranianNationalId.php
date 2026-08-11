<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates کدملی (Iranian individual national ID) using its standard
 * checksum algorithm — not just a 10-digit format check.
 */
class IranianNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (! preg_match('/^\d{10}$/', $value)) {
            $fail('کد ملی باید یک عدد ۱۰ رقمی باشد.');

            return;
        }

        // All-repeated-digit numbers (e.g. 1111111111) pass the checksum
        // below by construction but are never real national IDs.
        if (preg_match('/^(\d)\1{9}$/', $value)) {
            $fail('کد ملی وارد شده معتبر نیست.');

            return;
        }

        $digits = array_map('intval', str_split($value));
        $checksum = $digits[9];

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $expected = $remainder < 2 ? $remainder : 11 - $remainder;

        if ($checksum !== $expected) {
            $fail('کد ملی وارد شده معتبر نیست.');
        }
    }
}
