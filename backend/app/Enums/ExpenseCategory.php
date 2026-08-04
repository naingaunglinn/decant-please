<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum ExpenseCategory: string implements HasColor, HasLabel
{
    case StockPurchase = 'stock_purchase';
    case Packaging = 'packaging';
    case Delivery = 'delivery';
    case Marketing = 'marketing';
    case Fees = 'fees';
    case Other = 'other';

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
            self::StockPurchase => 'Stock purchase (bottles)',
            self::Packaging => 'Vials, labels & packaging',
            self::Delivery => 'Courier & delivery paid',
            self::Marketing => 'Marketing & boosting',
            self::Fees => 'Fees & subscriptions',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::StockPurchase => 'info',
            self::Delivery => 'warning',
            self::Marketing => 'blue',
            default => 'gray',
        };
    }

    /**
     * The no-double-count rule, in one named place: bottle purchases are
     * inventory, never an expense — v19's margin already expenses that juice
     * as COGS when it pours, so a P&L that expensed the purchase too would
     * count every bottle twice. Stock purchases surface below the line.
     */
    public function isOperating(): bool
    {
        return $this !== self::StockPurchase;
    }
}
