<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\ProductionSchedule;
use App\Filament\Pages\ProductionScheduleDay;
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

    // ---- The shared aggregation (Order::productionScheduleFor) --------------
    // Pinned against the pre-refactor day-card list; both pages read it now.

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

    public function test_cancelled_and_rejected_orders_contribute_nothing_to_the_aggregation(): void
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
    }

    public function test_an_inverted_range_swaps_to_a_single_day(): void
    {
        $this->assertCount(1, $this->scheduleDays('2026-08-10', '2026-08-01'));
    }

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

    // ---- The calendar page --------------------------------------------------

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

    public function test_calendar_feed_clamps_the_window_a_wire_call_can_ask_for(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-01', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-12-25', [[$fragrance, 10, 1]]);

        $events = collect($this->calendarEvents('2026-08-01', '2027-01-01'));

        $this->assertSame(['2026-08-01'], $events->pluck('start')->all());
    }

    public function test_cancelled_and_rejected_orders_contribute_nothing_to_the_calendar(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 5]], OrderStatus::Cancelled);
        $this->orderOn('2026-08-05', [[$fragrance, 10, 5]], OrderStatus::Rejected);

        $this->assertSame([], $this->calendarEvents('2026-08-01', '2026-09-01'));
    }

    public function test_past_days_still_holding_unpoured_vials_flag_overdue(): void
    {
        $this->travelTo('2026-08-10');

        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 2]]); // pending behind us — overdue
        $this->orderOn('2026-08-06', [[$fragrance, 10, 2]], OrderStatus::Delivered); // poured — history
        $this->orderOn('2026-08-20', [[$fragrance, 10, 2]]); // pending ahead — just scheduled

        $events = collect($this->calendarEvents('2026-08-01', '2026-09-01'));

        $this->assertSame(
            ['2026-08-05' => ['ps-overdue'], '2026-08-06' => [], '2026-08-20' => []],
            $events->pluck('classNames', 'start')->all(),
        );
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

    public function test_the_page_is_calendar_only_and_links_days_to_their_worklists(): void
    {
        Livewire::test(ProductionSchedule::class)
            ->assertOk()
            ->assertSeeHtml('id="ps-calendar"')
            ->assertDontSeeHtml('id="ps-from"') // the From/To range inputs are gone
            ->assertDontSee('Nothing to decant'); // and so is the day-card list

        // the vendored bundle + the day-URL template are wired into the full page
        $this->get(ProductionSchedule::getUrl())
            ->assertOk()
            ->assertSee('vendor/fullcalendar/index.global.min.js', false)
            ->assertSee('__DATE__', false);
    }

    // ---- The day detail page (/admin/production-schedule/{date}) -----------

    public function test_day_page_renders_the_grouped_lines(): void
    {
        $fragrance = $this->fragrance();
        $this->orderOn('2026-08-05', [[$fragrance, 10, 1]]);
        $this->orderOn('2026-08-05', [[$fragrance, 10, 2]]);
        $this->orderOn('2026-08-05', [[$this->fragrance('Dior', 'Sauvage'), 10, 5]], OrderStatus::Cancelled);

        Livewire::test(ProductionScheduleDay::class, ['date' => '2026-08-05'])
            ->assertOk()
            ->assertSee(CarbonImmutable::parse('2026-08-05')->format('l, j M Y'))
            ->assertSee('Chanel — Allure Homme Sport')
            ->assertSee('× 3')
            ->assertSee('2 order(s)')
            ->assertDontSee('Dior — Sauvage')
            ->assertSee('@media print', false); // the sheet carries its print styles
    }

    public function test_day_page_shows_a_real_empty_state(): void
    {
        Livewire::test(ProductionScheduleDay::class, ['date' => '2026-08-05'])
            ->assertOk()
            ->assertSee('Nothing to decant')
            ->assertSee('No orders have vials scheduled for this day.');
    }

    public function test_day_page_404s_on_anything_but_a_plain_y_m_d_param(): void
    {
        $invalid = [
            '2026-8-5', // unpadded
            '20260805', // no dashes
            'not-a-date',
            '2026-02-30', // well-formed, not a real date
            '2026-13-01', // no thirteenth month
            '2026-08-05T00:00:00', // datetimes invite timezone math — rejected
        ];

        foreach ($invalid as $bad) {
            $this->get(ProductionScheduleDay::getUrl(['date' => $bad]))
                ->assertNotFound();
        }

        $this->get(ProductionScheduleDay::getUrl(['date' => '2026-08-05']))->assertOk();
    }

    public function test_day_page_total_equals_that_days_calendar_aggregate(): void
    {
        $chanel = $this->fragrance();
        $dior = $this->fragrance('Dior', 'Sauvage');
        $this->orderOn('2026-08-05', [[$chanel, 10, 1]]);
        $this->orderOn('2026-08-05', [[$chanel, 10, 1], [$chanel, 5, 4]]);
        $this->orderOn('2026-08-05', [[$dior, 10, 6]]);

        $dayTotal = $this->dayPage('2026-08-05')->getDay()['groups']->sum('quantity');
        $event = collect($this->calendarEvents('2026-08-01', '2026-09-01'))->firstWhere('start', '2026-08-05');

        $this->assertSame(12, $dayTotal);
        $this->assertSame("{$dayTotal} vials", $event['title']);
    }

    public function test_both_pages_agree_under_utc_and_yangon_timezones(): void
    {
        foreach (['UTC' => '2026-08-05', 'Asia/Yangon' => '2026-08-20'] as $tz => $day) {
            config()->set('app.timezone', $tz);
            date_default_timezone_set($tz);

            $this->orderOn($day, [[$this->fragrance(), 10, 2]]);

            $event = collect($this->calendarEvents('2026-08-01', '2026-09-01'))->firstWhere('start', $day);
            $this->assertNotNull($event, "calendar lost {$day} under {$tz}");
            $this->assertSame('2 vials', $event['title']);

            $page = $this->dayPage($day);
            $this->assertSame($day, $page->getDay()['date']->toDateString(), "detail day shifted under {$tz}");
            $this->assertSame(2, $page->getDay()['groups']->sum('quantity'));

            Livewire::test(ProductionScheduleDay::class, ['date' => $day])
                ->assertSee('Chanel — Allure Homme Sport')
                ->assertSee('× 2');
        }
    }

    public function test_day_page_steps_between_days_and_links_back_to_the_calendar(): void
    {
        $this->assertStringEndsWith(
            '/admin/production-schedule/2026-08-05',
            ProductionScheduleDay::getUrl(['date' => '2026-08-05']),
        );
        // it needs a date, so it must never appear in the sidebar
        $this->assertFalse(ProductionScheduleDay::shouldRegisterNavigation());

        Livewire::test(ProductionScheduleDay::class, ['date' => '2026-08-05'])
            ->assertSeeHtml(ProductionScheduleDay::getUrl(['date' => '2026-08-04']))
            ->assertSeeHtml(ProductionScheduleDay::getUrl(['date' => '2026-08-06']))
            ->assertSeeHtml('href="'.ProductionSchedule::getUrl().'"');
    }

    // ---- helpers ------------------------------------------------------------

    /** The calendar's own read path, exclusive end — what the JS feed calls. */
    private function calendarEvents(string $start, string $endExclusive): array
    {
        return (new ProductionSchedule)->calendarEvents($start, $endExclusive);
    }

    /**
     * The shared domain read. Pin windows end a day past the target day: they
     * were written against the pre-refactor list, whose whereBetween missed a
     * window's final day under SQLite (the date cast stores 'Y-m-d 00:00:00',
     * and SQLite compares it as a string against the bare 'Y-m-d' bound —
     * Postgres's DATE column truncates, so production never saw it). Kept wide
     * so the pins hold on both sides of that refactor; the single-day case has
     * its own regression test now that whereDate compares dates engine-proof.
     */
    private function scheduleDays(string $from, string $to): array
    {
        return Order::productionScheduleFor(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    /** The day page as the route mounts it — validated, reading the shared method. */
    private function dayPage(string $date): ProductionScheduleDay
    {
        $page = new ProductionScheduleDay;
        $page->mount($date);

        return $page;
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
