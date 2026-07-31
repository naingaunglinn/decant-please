<?php

namespace App\Filament\Pages;

use App\Models\Order;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
     * @return array<int, array{date: CarbonImmutable, groups: \Illuminate\Support\Collection}>
     */
    public function getDays(): array
    {
        $from = CarbonImmutable::parse($this->from ?: today());
        $to = CarbonImmutable::parse($this->to ?: today());

        // ponytail: hard cap at 31 days — a wider window is a reporting tool, not a schedule
        $to = $to->min($from->addDays(31));

        return Order::productionScheduleFor($from, $to);
    }
}
