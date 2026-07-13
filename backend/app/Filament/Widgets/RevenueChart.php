<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    protected ?string $heading = 'Revenue — last 30 days';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $start = today()->subDays(29);

        // grouped in PHP so the date math is driver-agnostic; 30 days of orders is tiny
        $totalsByDay = Order::query()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'total_mmk'])
            ->groupBy(fn (Order $order) => $order->created_at->toDateString())
            ->map(fn ($orders) => (int) $orders->sum('total_mmk'));

        $labels = [];
        $data = [];

        for ($day = $start->toImmutable(); $day->lte(today()); $day = $day->addDay()) {
            $labels[] = $day->format('j M');
            $data[] = $totalsByDay->get($day->toDateString(), 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (Ks)',
                    'data' => $data,
                    'fill' => 'start',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
