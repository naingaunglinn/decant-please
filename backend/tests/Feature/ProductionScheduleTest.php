<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\ProductionSchedule;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));
    }

    // ---- The list's aggregation, pinned before the refactor -----------------

    public function test_same_fragrance_and_size_aggregate_into_one_line_across_orders(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-08-05', [[$fragrance, 10, 2]]);

        $days = $this->scheduleDays('2026-08-05', '2026-08-06');

        $this->assertCount(2, $days);
        $groups = $days[0]['groups'];
        $this->assertCount(1, $groups);
        $this->assertSame('Chanel — Allure Homme Sport', $groups[0]['label']);
        $this->assertSame(10, $groups[0]['size_ml']);
        $this->assertSame(3, $groups[0]['quantity']); // 1 + 2 vials, one line
        $this->assertCount(2, $groups[0]['orders']);
    }

    public function test_each_size_is_its_own_production_line(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1], [$fragrance, 5, 4]]);

        $groups = $this->scheduleDays('2026-08-05', '2026-08-06')[0]['groups'];

        $this->assertCount(2, $groups);
        $this->assertSame([5, 10], $groups->pluck('size_ml')->all()); // size asc within a label
        $this->assertSame([4, 1], $groups->pluck('quantity')->all());
    }

    public function test_days_without_work_are_kept_confirmed_empty(): void
    {
        $this->orderOn('2026-08-06', [[$this->fragrance(), 10, 1]]);

        $days = $this->scheduleDays('2026-08-05', '2026-08-07');

        $this->assertCount(3, $days);
        $this->assertSame(
            ['2026-08-05', '2026-08-06', '2026-08-07'],
            array_map(fn ($day) => $day['date']->toDateString(), $days),
        );
        $this->assertTrue($days[0]['groups']->isEmpty());
        $this->assertFalse($days[1]['groups']->isEmpty());
        $this->assertTrue($days[2]['groups']->isEmpty());
    }

    public function test_cancelled_and_rejected_orders_contribute_nothing_to_the_list(): void
    {
        $kept = $this->fragrance();
        $dropped = $this->fragrance('Dior', 'Sauvage');
        $this->orderOn('2026-08-05', [[$kept, 10, 2]]);
        $this->orderOn('2026-08-05', [[$dropped, 10, 5]], OrderStatus::Cancelled);
        $this->orderOn('2026-08-05', [[$dropped, 10, 5]], OrderStatus::Rejected);

        $groups = $this->scheduleDays('2026-08-05', '2026-08-06')[0]['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('Chanel — Allure Homme Sport', $groups[0]['label']);
        $this->assertSame(2, $groups[0]['quantity']);

        Livewire::test(ProductionSchedule::class)
            ->set('from', '2026-08-05')
            ->set('to', '2026-08-06')
            ->assertSee('Chanel — Allure Homme Sport')
            ->assertDontSee('Dior — Sauvage');
    }

    public function test_range_swaps_when_inverted_and_caps_at_31_days(): void
    {
        $this->assertCount(1, $this->scheduleDays('2026-08-10', '2026-08-01'));
        $this->assertCount(32, $this->scheduleDays('2026-08-01', '2026-12-31')); // from + 31 days
    }

    // ---- The extracted method (Order::productionScheduleFor) ----------------

    public function test_a_single_day_window_includes_that_day(): void
    {
        // Regression for the SQLite artifact scheduleDays() documents: with
        // whereDate, the window's last day counts on every engine.
        $this->orderOn('2026-08-05', [[$this->fragrance(), 10, 2]]);

        $days = Order::productionScheduleFor(
            CarbonImmutable::parse('2026-08-05'),
            CarbonImmutable::parse('2026-08-05'),
        );

        $this->assertCount(1, $days);
        $this->assertSame(2, $days[0]['groups'][0]['quantity']);
    }

    public function test_the_page_reads_through_the_domain_method(): void
    {
        // Delegation pin: the list must never grow its own copy of the grouping.
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-08-05', [[$fragrance, 5, 4]]);

        $flatten = fn (array $days) => collect($days)
            ->map(fn ($day) => [$day['date']->toDateString(), $day['groups']->toArray()])
            ->all();

        $this->assertEquals(
            $flatten(Order::productionScheduleFor(
                CarbonImmutable::parse('2026-08-04'),
                CarbonImmutable::parse('2026-08-06'),
            )),
            $flatten($this->scheduleDays('2026-08-04', '2026-08-06')),
        );
    }

    public function test_list_renders_the_grouped_lines(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-08-05', [[$fragrance, 10, 2]]);

        Livewire::test(ProductionSchedule::class)
            ->set('from', '2026-08-05')
            ->set('to', '2026-08-06')
            ->assertOk()
            ->assertSee('Chanel — Allure Homme Sport')
            ->assertSee('× 3')
            ->assertSee('2 order(s)');
    }

    // ---- The calendar feed --------------------------------------------------

    public function test_a_busy_day_produces_exactly_one_calendar_entry_with_the_vial_sum(): void
    {
        $chanel = $this->fragrance();
        $dior = $this->fragrance('Dior', 'Sauvage');
        $this->orderOn('2026-08-05', [[$chanel, 10, 1]]);
        $this->orderOn('2026-08-05', [[$chanel, 10, 1], [$chanel, 5, 4]]);
        $this->orderOn('2026-08-05', [[$dior, 10, 6]]);

        $events = collect($this->calendarEvents('2026-08-01', '2026-09-01'));

        // four fragrance/size lines, ONE entry — and the count is asserted,
        // not merely that an entry exists (existence checks are how #52 shipped)
        $this->assertSame(1, $events->count());
        $this->assertSame('2026-08-05', $events[0]['start']);
        $this->assertSame('12 vials', $events[0]['title']); // 1+1+4+6
        $this->assertTrue($events[0]['allDay']);
    }

    public function test_a_single_vial_day_reads_singular(): void
    {
        $this->orderOn('2026-08-05', [[$this->fragrance(), 10, 1]]);

        $this->assertSame('1 vial', $this->calendarEvents('2026-08-01', '2026-09-01')[0]['title']);
    }

    public function test_calendar_window_end_is_exclusive_as_fullcalendar_sends_it(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-08-06', [[$fragrance, 10, 1]]);

        $events = collect($this->calendarEvents('2026-08-01', '2026-08-06'));

        $this->assertSame(['2026-08-05'], $events->pluck('start')->all());
    }

    public function test_cancelled_and_rejected_orders_contribute_nothing_to_the_calendar(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 5]], OrderStatus::Cancelled);
        $this->orderOn('2026-08-05', [[$fragrance, 10, 5]], OrderStatus::Rejected);

        $this->assertSame([], $this->calendarEvents('2026-08-01', '2026-09-01'));
    }

    public function test_calendar_days_stay_put_under_utc_and_yangon_timezones(): void
    {
        // Myanmar is UTC+6:30 — if any tz conversion sneaks into the feed, a
        // half-hour offset shifts the day cell. Write and read under both zones.
        $fragrance = $this->fragrance();
        $written = [];

        foreach (['UTC' => '2026-08-05', 'Asia/Yangon' => '2026-08-20'] as $tz => $day) {
            config()->set('app.timezone', $tz);
            date_default_timezone_set($tz);

            $this->orderOn($day, [[$fragrance, 10, 2]]);
            $written[] = $day;

            $events = collect($this->calendarEvents('2026-08-01', '2026-09-01'));

            // every day written so far renders on exactly its own cell
            $this->assertSame($written, $events->pluck('start')->all(), "day shifted under {$tz}");
        }
    }

    public function test_reveal_day_focuses_the_list_on_that_day(): void
    {
        $this->orderOn('2026-08-05', [[$this->fragrance(), 10, 2]]);

        Livewire::test(ProductionSchedule::class)
            ->call('revealDay', '2026-08-05')
            ->assertSet('from', '2026-08-05')
            ->assertSet('to', '2026-08-05')
            ->assertSee(CarbonImmutable::parse('2026-08-05')->format('l, j M Y'));
    }

    public function test_the_page_serves_calendar_and_list_together(): void
    {
        Livewire::test(ProductionSchedule::class)
            ->assertOk()
            ->assertSeeHtml('id="ps-calendar"')
            ->assertSee('Nothing to decant'); // the list is still there

        // the vendored bundle is wired into the full page (assets hoist to the layout)
        $this->get(ProductionSchedule::getUrl())
            ->assertOk()
            ->assertSee('vendor/fullcalendar/index.global.min.js', false);
    }

    // ---- helpers ------------------------------------------------------------

    /** The calendar's own read path, exclusive end — what the JS feed calls. */
    private function calendarEvents(string $start, string $endExclusive): array
    {
        return (new ProductionSchedule)->calendarEvents($start, $endExclusive);
    }

    /**
     * The list's own read path — what the blade calls. Pin windows end a day
     * past the target day: the pre-refactor whereBetween missed a window's
     * final day under SQLite (the date cast stores 'Y-m-d 00:00:00', and SQLite
     * compares it as a string against the bare 'Y-m-d' bound — Postgres's DATE
     * column truncates, so production never saw it). Kept wide so these pins
     * hold on both sides of the refactor; the single-day case gets its own
     * regression test once the extracted method compares dates engine-proof.
     */
    private function scheduleDays(string $from, string $to): array
    {
        $page = new ProductionSchedule;
        $page->from = $from;
        $page->to = $to;

        return $page->getDays();
    }

    private function fragrance(string $brandName = 'Chanel', string $name = 'Allure Homme Sport'): Fragrance
    {
        $brand = Brand::firstOrCreate(['name' => $brandName], ['type' => 'designer']);

        return $brand->fragrances()
            ->firstOrCreate(['name' => $name], ['concentration' => 'cologne', 'gender' => 'male'])
            ->loadMissing('brand');
    }

    /** @param  array<array{0: Fragrance, 1: int, 2: int}>  $lines  [fragrance, size_ml, quantity] */
    private function orderOn(?string $decantDate, array $lines, OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::create([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'order_from' => 'website',
            'status' => $status,
            'decant_date' => $decantDate,
        ]);

        foreach ($lines as [$fragrance, $sizeMl, $quantity]) {
            $order->items()->create([
                'fragrance_id' => $fragrance->id,
                'fragrance_name_snapshot' => $fragrance->brand->name.' '.$fragrance->name,
                'size_ml' => $sizeMl,
                'unit_price_mmk' => 55000,
                'quantity' => $quantity,
            ]);
        }

        return $order;
    }
}
