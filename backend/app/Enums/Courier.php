<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The couriers the decanter ships through. A fulfilment detail, admin-eyes
 * only: nothing about a courier — name, alias, cost, coverage — ever crosses
 * the public API (the v19 cost rule extended to supplier data). Adding a
 * third courier is one case here plus rate rows in the admin, no migration.
 */
enum Courier: string implements HasColor, HasLabel
{
    case RoyalExpress = 'royal_express';
    case Beexprss = 'beexprss';

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    /**
     * @return string|array<string>|null
     */
    public function getColor(): string|array|null
    {
        return $this->color();
    }

    public function label(): string
    {
        return match ($this) {
            self::RoyalExpress => 'Royal Express',
            self::Beexprss => 'BeeXprss',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RoyalExpress => 'danger',
            self::Beexprss => 'warning',
        };
    }
}
