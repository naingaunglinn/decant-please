<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CourierFloat extends StatsOverviewWidget
{
    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        // The float: cash physically with couriers — handed off, not yet settled.
        // Sums the HANDOFF SNAPSHOT (courier_carrying_mmk), never a live balance:
        // marking an order paid must not shrink the float before the cash arrives.
        // Never filtered by payment_method (the FINANCE.md gap-3 hazard — online
        // orders generate courier cash too). §4: cancelled and rejected never count.
        $out = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->whereNotNull('handed_to_courier_at')
            ->whereNull('courier_settled_at')
            ->get(['courier_carrying_mmk', 'handed_to_courier_at']);

        return [
            Stat::make('Cash with couriers', Money::kyat((int) $out->sum('courier_carrying_mmk')))
                ->description($out->isEmpty()
                    ? 'Nothing outstanding'
                    : $out->count().' order(s) out — oldest handed off '.$out->min('handed_to_courier_at')->format('j M'))
                ->color($out->isEmpty() ? 'gray' : 'warning'),
        ];
    }
}
