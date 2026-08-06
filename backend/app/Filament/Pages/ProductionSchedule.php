<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\Order;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnitEnum;

class ProductionSchedule extends Page
{
    protected string $view = 'filament.pages.production-schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    /**
     * FullCalendar's event feed: one all-day entry per day with work, titled
     * with that day's total vial count. Dates in and out are plain Y-m-d
     * strings, never timezone-bearing datetimes — Myanmar is UTC+6:30, and any
     * tz conversion on a half-hour offset shifts day cells, a bug invisible
     * from a UTC test. $end arrives exclusive, as FullCalendar sends it.
     *
     * @return array<int, array{start: string, title: string, allDay: bool, classNames: array<int, string>}>
     */
    public function calendarEvents(string $start, string $end): array
    {
        $from = CarbonImmutable::parse($start);
        // exclusive → inclusive; a dayGrid month spans at most 42 cells, so
        // clamp what a wire call could ask for
        $to = CarbonImmutable::parse($end)->subDay()->min($from->addDays(42));

        $events = [];

        foreach (Order::productionScheduleFor($from, $to) as $day) {
            $vials = $day['groups']->sum('quantity');

            if ($vials === 0) {
                continue;
            }

            $events[] = [
                'start' => $day['date']->toDateString(),
                'title' => $vials.' '.Str::plural('vial', $vials),
                'allDay' => true,
                // Overdue is not history: a past day whose vials aren't all
                // poured yet stays visually distinct from scheduled work.
                'classNames' => $this->isOverdue($day) ? ['ps-overdue'] : [],
            ];
        }

        return $events;
    }

    /**
     * A day is overdue when it's behind us and any of its vials are still
     * unpoured — an order not yet decanted or delivered. Fully-poured past
     * days render as plain history.
     *
     * @param  array{date: CarbonImmutable, groups: Collection}  $day
     */
    private function isOverdue(array $day): bool
    {
        if ($day['date']->gte(today())) {
            return false;
        }

        return $day['groups']->contains(
            fn (array $group): bool => $group['orders']->contains(
                fn (Order $order): bool => ! in_array($order->status, [OrderStatus::Decanted, OrderStatus::Delivered], true),
            ),
        );
    }
}
