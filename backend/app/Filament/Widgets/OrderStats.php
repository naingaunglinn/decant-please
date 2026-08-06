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

        // Gross margin (liquid only) — same window and status exclusion as the
        // revenue stat beside it (awaiting_confirmation included, matching it).
        // Summed in PHP over the month's orders (the RevenueChart precedent):
        // the figure is Σ per-order margin over FULLY-costed orders only — a SQL
        // SUM(line_cost_mmk) would silently include the non-null lines of
        // partially-costed orders and overstate the margin. Do not write that query.
        $monthOrders = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->where('created_at', '>=', now()->startOfMonth())
            ->with('items')
            ->get();

        $orderMargins = $monthOrders
            ->map(fn (Order $order): ?int => $order->liquidGrossMarginMmk())
            ->filter(fn (?int $margin): bool => $margin !== null);

        return [
            Stat::make('Revenue this month', Money::kyat($revenue))
                ->description('Excludes cancelled & rejected'),
            Stat::make('Gross margin (liquid only)', Money::kyat((int) $orderMargins->sum()))
                ->description("Excludes vial, label, spillage & delivery — on {$orderMargins->count()} of {$monthOrders->count()} orders"),
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
