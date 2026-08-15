<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates کدملی (Iranian individual national ID) using its standard
 * checksum algorithm — not just a 10-digit format check.
 *
 * A checksum means the last digit is computed from the preceding nine, so a
 * mistyped digit almost always produces a number that fails arithmetic —
 * catching typos at the form rather than in the database months later.
 *
 * CONTRAST WITH شناسه ملی (the 11-digit company ID): that one is NOT
 * checksum-validated anywhere in this app, only checked for being eleven
 * digits. Its commonly-published formula rejects real, currently-issued IDs
 * and was locking legitimate companies out of registering. The individual
 * algorithm here is reliable, so it stays.
 *
 * A "rule" object is used instead of a regex string so the same logic can be
 * dropped into any validator — Livewire's, Filament's, or a plain request.
 */
class IranianNationalId implements ValidationRule
{
    /**
     * Laravel calls this with the value under test. Calling $fail() marks
     * the field invalid with that message; returning without calling it
     * means the value passed.
     */
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

        // Split "1234567890" into [1,2,3,...]; the last digit is the one
        // the algorithm has to reproduce from the other nine.
        $digits = array_map('intval', str_split($value));
        $checksum = $digits[9];

        // Weighted sum: first digit x10, second x9, ... ninth x2.
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * (10 - $i);
        }

        // The official rule: remainder under 2 is the check digit itself,
        // otherwise the check digit is 11 minus the remainder.
        $remainder = $sum % 11;
        $expected = $remainder < 2 ? $remainder : 11 - $remainder;

        if ($checksum !== $expected) {
            $fail('کد ملی وارد شده معتبر نیست.');
        }
    }
}
