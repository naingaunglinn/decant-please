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

        // Balance outstanding: Σ positive per-order balances (Σ line_total − discount +
        // fee − deposit) over orders still in play. Keyed off balance > 0, NOT
        // payment_status — a "paid" order with a partial deposit still owes, and an
        // unpaid order fully deposited owes nothing (#67). The item subtotal comes from a
        // withSum correlated subquery (portable, and shop-scoped like the outer query, so
        // no shop's lines enter another's total); positives are summed in PHP because a
        // positive-only sum can't be one portable aggregate over that subquery. Overpaid
        // (negative) balances are excluded, never netted against what is owed.
        $outstandingBalances = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->withSum('items as items_total_mmk', 'line_total_mmk')
            ->get()
            ->map(fn (Order $order): int => Order::balanceDueFrom(
                (int) ($order->items_total_mmk ?? 0),
                $order->discount_mmk,
                $order->delivery_fee_mmk,
                $order->deposit_mmk,
            ))
            ->filter(fn (int $balance): bool => $balance > 0);

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
            Stat::make('Balance outstanding', Money::kyat((int) $outstandingBalances->sum()))
                ->description($outstandingBalances->count().' order(s) with a balance due')
                ->color($outstandingBalances->isNotEmpty() ? 'warning' : 'gray'),
            Stat::make('Awaiting confirmation', Order::where('status', OrderStatus::AwaitingConfirmation)->count())
                ->description('Needs review')
                ->color('warning'),
            Stat::make('Decants due today', Order::query()
                ->whereDate('prep_date', today())
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
                ->count()),
        ];
    }
}
