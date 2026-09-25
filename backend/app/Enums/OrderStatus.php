<?php

namespace App\Enums;

use App\Templates\Templates;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum OrderStatus: string implements HasColor, HasLabel
{
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Pending = 'pending';
    case Prepared = 'prepared';
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

    /**
     * The label the current shop's template gives this state — "Decanted" for a
     * decant shop, "Packed" for clothing (step 39). The one resolver: the admin
     * badge and select, the CSV, the invoice and the tracking API all read it.
     */
    public function label(): string
    {
        return Templates::statusLabel($this);
    }

    /**
     * The category-free English word. Templates start from these; nothing else
     * should read them directly — use label().
     */
    public function defaultLabel(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'Awaiting Confirmation',
            self::Pending => 'Pending',
            self::Prepared => 'Prepared',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'warning',
            self::Pending, self::Prepared => 'info',
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
        return in_array($this, [self::Pending, self::Prepared, self::Delivered], true);
    }
}
