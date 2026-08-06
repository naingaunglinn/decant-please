<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class DiscountCost extends Widget
{
    protected static ?int $sort = 6;

    protected string $view = 'filament.widgets.discount-cost';

    /**
     * This month's discounts grouped by code — read from the ORDER snapshots
     * (promo_code, discount_mmk), the historical record of what was actually
     * given away, never promo_codes.times_used (which counts claims, including
     * orders later cancelled). Same window and status exclusion as the revenue
     * stat (§4: cancelled and rejected never count in a money figure);
     * hand-edited discounts (no code) get their own row. Grouped in PHP —
     * a month of orders is tiny (the RevenueChart precedent).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('discount_mmk', '>', 0)
            ->get(['promo_code', 'discount_mmk'])
            ->groupBy(fn (Order $order): string => $order->promo_code ?? '')
            ->map(fn (Collection $orders, string $code): array => [
                'code' => $code !== '' ? $code : null,
                'orders' => $orders->count(),
                'given_mmk' => (int) $orders->sum('discount_mmk'),
            ])
            ->sortByDesc('given_mmk')
            ->values();

        return [
            'rows' => $rows,
            'total' => (int) $rows->sum('given_mmk'),
        ];
    }
}
