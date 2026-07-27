<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';

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
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Paid => 'success',
        };
    }
}
