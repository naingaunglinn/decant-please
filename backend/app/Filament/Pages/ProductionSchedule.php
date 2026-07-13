<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class ProductionSchedule extends Page
{
    protected string $view = 'filament.pages.production-schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->toDateString();
        $this->to = today()->addDays(7)->toDateString();
    }

    /**
     * One eager-loaded pass over the window's order items, grouped per day into
     * fragrance+size production lines. Days without work are kept (confirmed-empty).
     *
     * @return array<int, array{date: CarbonImmutable, groups: Collection}>
     */
    public function getDays(): array
    {
        $from = CarbonImmutable::parse($this->from ?: today());
        $to = CarbonImmutable::parse($this->to ?: today());

        if ($to->lessThan($from)) {
            $to = $from;
        }

        // ponytail: hard cap at 31 days — a wider window is a reporting tool, not a schedule
        $to = $to->min($from->addDays(31));

        $items = OrderItem::query()
            ->whereHas('order', fn ($query) => $query
                ->whereBetween('decant_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected]))
            ->with(['order', 'fragrance.brand'])
            ->get();

        $byDay = $items->groupBy(fn (OrderItem $item) => $item->order->decant_date->toDateString());

        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $groups = ($byDay->get($day->toDateString()) ?? collect())
                ->groupBy(fn (OrderItem $item) => "{$item->fragrance_id}:{$item->size_ml}")
                ->map(function (Collection $group): array {
                    $first = $group->first();

                    return [
                        'label' => "{$first->fragrance->brand->name} — {$first->fragrance->name}",
                        'size_ml' => $first->size_ml,
                        'quantity' => $group->sum('quantity'),
                        'orders' => $group->map(fn (OrderItem $item) => $item->order)->unique('id')->values(),
                    ];
                })
                ->sortBy([['label', 'asc'], ['size_ml', 'asc']])
                ->values();

            $days[] = ['date' => $day, 'groups' => $groups];
        }

        return $days;
    }
}
