<?php

namespace App\Filament\Pages;

use App\Support\MonthlyPnl;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * One month's P&L — see App\Support\MonthlyPnl for every line's definition
 * and prompts/29 for the decisions (accrual-lite by order month; stock
 * purchases below the line; delivery in its own result line; every figure
 * labeled with its coverage).
 */
class ProfitAndLoss extends Page
{
    protected string $view = 'filament.pages.profit-and-loss';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Profit & loss';

    public int $year;

    public int $month;

    public function mount(): void
    {
        $this->year = today()->year;
        $this->month = today()->month;
    }

    public function previousMonth(): void
    {
        $moved = CarbonImmutable::create($this->year, $this->month, 1)->subMonth();
        $this->year = $moved->year;
        $this->month = $moved->month;
    }

    public function nextMonth(): void
    {
        $moved = CarbonImmutable::create($this->year, $this->month, 1)->addMonth();
        $this->year = $moved->year;
        $this->month = $moved->month;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'pnl' => MonthlyPnl::for($this->year, $this->month),
            'label' => CarbonImmutable::create($this->year, $this->month, 1)->format('F Y'),
        ];
    }
}
