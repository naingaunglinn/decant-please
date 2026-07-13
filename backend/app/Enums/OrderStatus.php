<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum OrderStatus: string implements HasColor, HasLabel
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Pending = 'pending';
    case Decanted = 'decanted';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

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
            self::AwaitingConfirmation => 'Awaiting Confirmation',
            self::Pending => 'Pending',
            self::Decanted => 'Decanted',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'warning',
            self::Pending, self::Decanted => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'gray',
            self::Rejected => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Rejected], true);
    }

    public function isFulfillable(): bool
    {
        return in_array($this, [self::Pending, self::Decanted, self::Delivered], true);
    }
}
