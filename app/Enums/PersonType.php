<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * حقیقی (a real person) vs حقوقی (a legal entity / company).
 *
 * The string values are what land in the database's `person_type` column;
 * the Persian labels below are what humans see. Keeping the two apart means
 * the UI wording can change without a data migration.
 *
 * Implementing Filament's HasLabel is what lets a form or table just say
 * `->options(PersonType::class)` and get the Persian text automatically.
 */
enum PersonType: string implements HasLabel
{
    case Individual = 'individual';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'حقیقی',
            self::Company => 'حقوقی',
        };
    }
}
