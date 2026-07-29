<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $revenue = (int) Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('total_mmk');

        // Money owed: unpaid orders still in play (not cancelled/rejected).
        $unpaid = Order::query()
            ->unpaid()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected]);
        $unpaidCount = $unpaid->clone()->count();
        $owed = max(0, (int) $unpaid->clone()->sum('total_mmk') - (int) $unpaid->clone()->sum('deposit_mmk'));

        return [
            Stat::make('Revenue this month', Money::kyat($revenue))
                ->description('Excludes cancelled & rejected'),
            Stat::make('Orders this month', Order::where('created_at', '>=', now()->startOfMonth())->count()),
            Stat::make('Unpaid orders', $unpaidCount)
                ->description(Money::kyat($owed).' outstanding')
                ->color($unpaidCount > 0 ? 'warning' : 'gray'),
            Stat::make('Awaiting confirmation', Order::where('status', OrderStatus::AwaitingConfirmation)->count())
                ->description('Needs review')
                ->color('warning'),
            Stat::make('Decants due today', Order::query()
                ->whereDate('decant_date', today())
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
                ->count()),
        ];
    }
}
