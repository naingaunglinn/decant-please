<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PromoType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percent',
            self::Fixed => 'Fixed amount',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
