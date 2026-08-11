<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

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
