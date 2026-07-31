<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * How the customer intends to pay. Chosen at checkout (default COD) and, for
 * manual admin orders, defaulting to COD too. `online` means a prepaid transfer
 * the customer makes before the order is confirmed (KBZPay/Wave/MMQR + screenshot);
 * `cod` means cash to the delivery person on arrival.
 */
enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cod = 'cod';
    case Online = 'online';

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
            self::Cod => 'Cash on delivery',
            self::Online => 'Online transfer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cod => 'gray',
            self::Online => 'info',
        };
    }
}
