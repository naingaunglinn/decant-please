<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * One day's worklist — the printable bench sheet the calendar clicks into.
 * Reached only by URL (a calendar day, prev/next stepping); it needs a date,
 * so it registers nothing in the sidebar.
 */
class ProductionScheduleDay extends Page
{
    protected string $view = 'filament.pages.production-schedule-day';

    protected static ?string $slug = 'production-schedule/{date}';

    public string $date = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(string $date): void
    {
        // The route param is a plain Y-m-d string or the page doesn't exist.
        // Deliberately no lenient parse: a datetime-shaped param would invite
        // timezone math, and Myanmar's UTC+6:30 shifts a date under any tz
        // conversion — the same rule the calendar feed follows.
        abort_unless(static::parseDay($date) instanceof CarbonImmutable, 404);

        $this->date = $date;
    }

    public function getTitle(): string|Htmlable
    {
        $day = $this->day();

        return ($day->isToday() ? 'Today — ' : '').$day->format('l, j M Y');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ProductionSchedule::getUrl() => 'Production schedule',
            $this->day()->format('j M Y'),
        ];
    }

    /**
     * The day's aggregated lines — the same domain method the calendar feed
     * reads, called for a one-day window. No second copy of the grouping.
     *
     * @return array{date: CarbonImmutable, groups: Collection}
     */
    public function getDay(): array
    {
        return Order::productionScheduleFor($this->day(), $this->day())[0];
    }

    public function previousDayUrl(): string
    {
        return static::getUrl(['date' => $this->day()->subDay()->toDateString()]);
    }

    public function nextDayUrl(): string
    {
        return static::getUrl(['date' => $this->day()->addDay()->toDateString()]);
    }

    public function calendarUrl(): string
    {
        return ProductionSchedule::getUrl();
    }

    private function day(): CarbonImmutable
    {
        return static::parseDay($this->date);
    }

    /**
     * Strict Y-m-d or null: shape-checked, then round-tripped so a well-formed
     * impossible date (2026-02-30 rolls over when parsed) is rejected too.
     */
    private static function parseDay(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $day->toDateString() === $date ? $day : null;
    }
}
