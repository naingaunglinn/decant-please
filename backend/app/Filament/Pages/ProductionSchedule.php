<?php

namespace App\Filament\Pages;

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

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->from = today()->toDateString();
        $this->to = today()->addDays(7)->toDateString();
    }

    /**
     * The day-card list's data: the domain aggregation over the picked window.
     *
     * @return array<int, array{date: CarbonImmutable, groups: Collection}>
     */
    public function getDays(): array
    {
        $from = CarbonImmutable::parse($this->from ?: today());
        $to = CarbonImmutable::parse($this->to ?: today());

        // ponytail: hard cap at 31 days — a wider window is a reporting tool, not a schedule
        $to = $to->min($from->addDays(31));

        return Order::productionScheduleFor($from, $to);
    }

    /**
     * FullCalendar's event feed: one all-day entry per day with work, titled
     * with that day's total vial count. Dates in and out are plain Y-m-d
     * strings, never timezone-bearing datetimes — Myanmar is UTC+6:30, and any
     * tz conversion on a half-hour offset shifts day cells, a bug invisible
     * from a UTC test. $end arrives exclusive, as FullCalendar sends it.
     *
     * @return array<int, array{start: string, title: string, allDay: bool}>
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

            if ($vials > 0) {
                $events[] = [
                    'start' => $day['date']->toDateString(),
                    'title' => $vials.' '.Str::plural('vial', $vials),
                    'allDay' => true,
                ];
            }
        }

        return $events;
    }

    /** Calendar day-click, when the clicked day's card isn't already in the
     *  list window: focus the list on that single day. */
    public function revealDay(string $date): void
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        $this->from = $day;
        $this->to = $day;
    }
}
