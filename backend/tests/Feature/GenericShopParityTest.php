<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShopStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\CourierFloat;
use App\Filament\Widgets\OrderStats;
use App\Models\Brand;
use App\Models\DeliveryTownship;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Shop;
use App\Support\MonthlyPnl;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 35 — the generic-shop refactor's golden master (prompts/35-generic-shop-plan.md).
 *
 * One fixed decant shop, frozen at 2026-03-15 10:00, and every catalog, dashboard and
 * P&L figure it produces, written out as literal Kyat worked by hand in the comments.
 * Steps 36–42 rename fragrances → products, move attributes into jsonb, relabel
 * statuses and generalise stock; each of them keeps this test green. A step may
 * update a field NAME it deliberately renames (a route, a payload key, a relation),
 * never a VALUE. If a figure here moves, the refactor moved money — fix the code,
 * not the literal.
 *
 * The fixture is built here, not from the demo seeders: those are sample content
 * later steps rewrite, and they read today() and random tracking codes. IDs are
 * compared to the fixture's own models, never to literals — Postgres sequences
 * don't roll back between tests, so a literal id is not deterministic.
 *
 * Catalog (bottle cost ÷ bottle volume, CEILING — Product::liquidCostMmk):
 *   Chanel (designer) Allure Homme Sport  5ml 30,000 · 10ml 55,000 · 30ml 150,000 (out of stock)
 *                                         cost 300,000 / 100ml → 5ml 15,000 · 10ml 30,000
 *   Creed (niche)     Aventus             5ml 65,000 · 10ml 120,000
 *                                         cost 100,000 / 30ml → 5ml ⌈16,666.7⌉ = 16,667 · 10ml ⌈33,333.3⌉ = 33,334
 *   Creed (niche)     Love In White       5ml 60,000 — no cost pair, so uncosted
 *   stock: Allure 100ml, Aventus 12ml tracked; Love In White untracked
 *   hidden: Creed Green Irish Tweed (inactive, 5ml 999,000); Old House Ghost (inactive brand, 5ml 1,000)
 *
 * Orders (Sanchaung fee 2,500; promo PARITY10 = 10% capped at 15,000):
 *   A  POST /orders, March · Allure 10ml ×2 + Aventus 5ml ×1 · PARITY10 · accepted, then decanted
 *      (draws Allure 100 − 20 = 80ml, Aventus 12 − 5 = 7ml; a smuggled client price is ignored)
 *      items 110,000 + 65,000 = 175,000; discount min(17,500, 15,000) = 15,000
 *      total 175,000 + 2,500 − 15,000 = 162,500; cost 60,000 + 16,667 = 76,667
 *      margin 175,000 − 15,000 − 76,667 = 83,333; balance 162,500
 *   B  POST /orders, March · Love In White 5ml ×1 + Allure 5ml ×1 · awaiting
 *      items 90,000; total 92,500; partially costed → margin unknown; balance 92,500
 *   C  admin (Facebook), March · Aventus 10ml ×1 · fee 3,000 · discount 5,000 · deposit 20,000 · delivered
 *      total 120,000 + 3,000 − 5,000 = 118,000; cost 33,334
 *      margin 120,000 − 5,000 − 33,334 = 81,666; balance 118,000 − 20,000 = 98,000
 *   D  admin (TikTok), March · Allure 5ml ×1 · no fee · marked paid with 35,000 · pending
 *      total 30,000; cost 15,000; margin 15,000; balance 30,000 − 35,000 = −5,000 (overpaid)
 *   E  POST /orders, March · Aventus 10ml ×1 · cancelled by the customer — counts nowhere
 *   F  checkout, March · Allure 10ml ×1 · rejected — counts nowhere
 *   G  checkout, 2026-02-20 · Aventus 5ml ×2 · awaiting
 *      items 130,000; total 132,500; cost 33,334; margin 96,666; balance 132,500
 *   plus a second shop's March order that must move none of these figures.
 *
 * Payments and couriers (#67/#109) — built only by the tests that use them
 * (seedCourierOrders), in January so no March or February figure above moves.
 * Every step runs through the panel's own action with its default, as a seller taps it:
 *   H  checkout, online · Allure 10ml ×1 · fee 2,500 · total 57,500
 *      Mark paid default (online = items − discount) 55,000 → balance 2,500 (the fee), Paid
 *      handoff default = balance 2,500; settle default min(2,500, 2,500) = 2,500 → balance 0, Paid
 *   I  checkout, COD · Allure 10ml ×2 · fee 2,500 · total 112,500
 *      Mark paid default (COD = items − discount + fee) 112,500 — pre-fill only, never submitted
 *      handoff default = balance 112,500; settled with 100,000 → balance 12,500, still Unpaid
 *   J  checkout, COD · Allure 5ml ×2 · fee 2,500 · total 62,500 · handed off, never settled
 *   Cash with couriers: I + J out = 112,500 + 62,500 = 175,000; after I settles, J's 62,500.
 *   Balance outstanding: 485,500 + I 12,500 + J 62,500 = 560,500 over 6 orders (H is 0).
 *
 * Expenses: March — fees 3,000 (1st) · packaging 12,000 · stock_purchase 300,000 ·
 * delivery 6,000 · other 1,500 · marketing 8,500 (31st). February — other 4,000 (28th).
 * April — fees 9,999 (1st).
 */
class GenericShopParityTest extends TestCase
{
    use RefreshDatabase;

    private Product $allure;

    private Product $aventus;

    private Product $loveInWhite;

    private Brand $chanel;

    private Brand $creed;

    private DeliveryTownship $sanchaung;

    /** @var array<string, Order> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

        $this->seedCatalog();
        $this->seedOrders();
        $this->seedExpenses();
        $this->seedAnotherShop();
    }

    // ---- the public catalog API ----

    public function test_fragrance_list_objects_and_min_prices(): void
    {
        $response = $this->getJson('/api/v1/decant-please/products')->assertOk();

        $response->assertJsonPath('meta.total', 3); // GIT inactive, Ghost's brand inactive

        // default sort: newest first — equal created_at under the frozen clock, so id desc
        $this->assertSame(
            ['Love In White', 'Aventus', 'Allure Homme Sport'],
            array_column($response->json('data'), 'name'),
        );

        $byName = collect($response->json('data'))->keyBy('name');

        $this->assertSame($this->allureObject(), $this->pick($byName['Allure Homme Sport'], $this->allureObject()));
        $this->assertSame($this->aventusObject(), $this->pick($byName['Aventus'], $this->aventusObject()));
        $this->assertSame($this->loveInWhiteObject(), $this->pick($byName['Love In White'], $this->loveInWhiteObject()));
    }

    public function test_fragrance_filters_and_sorts(): void
    {
        $names = fn (string $query): array => array_column(
            $this->getJson("/api/v1/decant-please/products?{$query}")->assertOk()->json('data'), 'name');

        // unsorted filters keep the default newest-first (id desc) order
        $this->assertSame(['Love In White'], $names('gender=female'));
        $this->assertSame(['Love In White', 'Aventus'], $names('type=niche'));
        $this->assertSame(['Allure Homme Sport'], $names('brand=chanel'));
        $this->assertSame(['Aventus', 'Allure Homme Sport'], $names('size=10'));
        $this->assertSame([], $names('size=30'));                        // only out of stock
        $this->assertSame(['Aventus'], $names('min_price=62000'));        // 65,000 / 120,000
        $this->assertSame(['Allure Homme Sport'], $names('max_price=40000')); // 30,000
        $this->assertSame(['Love In White', 'Aventus'], $names('q=creed'));
        $this->assertSame(['Allure Homme Sport'], $names('notes=musk'));
        $this->assertSame(['Allure Homme Sport'], $names('featured=1'));

        // by in-stock min price: 30,000 · 60,000 · 65,000
        $this->assertSame(['Allure Homme Sport', 'Love In White', 'Aventus'], $names('sort=price_asc'));
        $this->assertSame(['Aventus', 'Love In White', 'Allure Homme Sport'], $names('sort=price_desc'));
        $this->assertSame(['Allure Homme Sport', 'Aventus', 'Love In White'], $names('sort=name'));
    }

    public function test_fragrance_detail_object(): void
    {
        $allure = $this->getJson('/api/v1/decant-please/products/chanel-allure-homme-sport')
            ->assertOk()
            ->json('data');

        $this->assertSame($this->allureObject(), $this->pick($allure, $this->allureObject()));

        $this->getJson('/api/v1/decant-please/products/creed-green-irish-tweed')->assertNotFound();
    }

    public function test_meta_filter_options_price_bounds_and_sizes(): void
    {
        $meta = $this->getJson('/api/v1/decant-please/meta')->assertOk()->json();

        // social/payment resolve shop row → env (ShopConfig), so they vary by machine;
        // they are PerShopConfigTest's business, not this baseline's.
        $this->assertSame([
            'brand_types' => [
                ['value' => 'designer', 'label' => 'Designer'],
                ['value' => 'niche', 'label' => 'Niche'],
            ],
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'unisex', 'label' => 'Unisex'],
            ],
            'concentrations' => [
                ['value' => 'edt', 'label' => 'EDT'],
                ['value' => 'edp', 'label' => 'EDP'],
                ['value' => 'parfum', 'label' => 'Parfum'],
                ['value' => 'cologne', 'label' => 'Cologne'],
                ['value' => 'extrait', 'label' => 'Extrait'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            // 30ml exists only out of stock; GIT's 999,000 and Ghost's 1,000 are hidden
            'sizes' => [5, 10],
            'price' => ['min' => 30000, 'max' => 120000],
            'sorts' => ['newest', 'price_asc', 'price_desc', 'name'],
        ], array_intersect_key($meta, array_flip(['brand_types', 'genders', 'concentrations', 'sizes', 'price', 'sorts'])));
    }

    // ---- the orders' own server-derived money ----

    public function test_checkout_and_admin_orders_carry_the_derived_money(): void
    {
        $expected = [
            //      items   discount  fee    total   deposit  line cost  balance
            'A' => [175000, 15000, 2500, 162500, 0, 76667, 162500],
            'B' => [90000, 0, 2500, 92500, 0, null, 92500],
            'C' => [120000, 5000, 3000, 118000, 20000, 33334, 98000],
            'D' => [30000, 0, 0, 30000, 35000, 15000, -5000],
            'E' => [120000, 0, 2500, 122500, 0, 33334, 122500],
            'F' => [55000, 0, 2500, 57500, 0, 30000, 57500],
            'G' => [130000, 0, 2500, 132500, 0, 33334, 132500],
        ];

        foreach ($expected as $key => [$items, $discount, $fee, $total, $deposit, $cost, $balance]) {
            $order = $this->orders[$key]->fresh('items');
            $costed = $order->items->contains(fn ($item) => $item->line_cost_mmk === null)
                ? null
                : (int) $order->items->sum('line_cost_mmk');

            $this->assertSame(
                [$items, $discount, $fee, $total, $deposit, $cost, $balance],
                [(int) $order->items->sum('line_total_mmk'), $order->discount_mmk, $order->delivery_fee_mmk,
                    $order->total_mmk, $order->deposit_mmk, $costed, $order->balanceDue()],
                "order {$key}",
            );
        }

        // A's line snapshots, field by field — the rows step 36 re-keys by variant
        $this->assertSame([
            ['Chanel Allure Homme Sport', 10, 55000, 2, 110000, 30000, 60000],
            ['Creed Aventus', 5, 65000, 1, 65000, 16667, 16667],
        ], $this->orders['A']->items()->orderBy('id')->get()->map(fn ($item) => [
            $item->fragrance_name_snapshot, $item->size_ml, $item->unit_price_mmk, $item->quantity,
            $item->line_total_mmk, $item->unit_cost_mmk, $item->line_cost_mmk,
        ])->all());

        $this->assertSame('PARITY10', $this->orders['A']->promo_code);
        $this->assertSame(['Yangon Region', 'Sanchaung'], [
            $this->orders['A']->region_snapshot, $this->orders['A']->township_snapshot,
        ]);
    }

    public function test_public_tracking_receipt_money(): void
    {
        $receipt = $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $this->orders['A']->tracking_code, 'phone' => '09-771234561',
        ]))->assertOk()->json();

        $this->assertSame(
            [175000, 2500, 15000, 'PARITY10', 0, 162500, '162,500 Ks', 162500],
            [$receipt['subtotal_mmk'], $receipt['delivery_fee_mmk'], $receipt['discount_mmk'], $receipt['promo_code'],
                $receipt['deposit_mmk'], $receipt['total_mmk'], $receipt['total_formatted'], $receipt['balance_due_mmk']],
        );
        $this->assertSame([[10, 2, 55000, 110000], [5, 1, 65000, 65000]], array_map(
            fn (array $item) => [$item['size_ml'], $item['quantity'], $item['unit_price_mmk'], $item['line_total_mmk']],
            $receipt['items'],
        ));

        // D: marked paid with 35,000 against 30,000 — the signed balance reaches the storefront
        $this->assertSame(-5000, $this->getJson('/api/v1/decant-please/orders/track?'.http_build_query([
            'tracking_code' => $this->orders['D']->tracking_code, 'phone' => '09-773216549',
        ]))->assertOk()->json('balance_due_mmk'));
    }

    public function test_decanting_draws_down_tracked_stock(): void
    {
        // A → decanted pours 2 × 10ml Allure and 5ml Aventus; untracked stays untracked
        $this->assertSame(
            [80, 7, null],
            [$this->allure->fresh()->stock_ml, $this->aventus->fresh()->stock_ml, $this->loveInWhite->fresh()->stock_ml],
        );
    }

    // ---- the dashboard ----

    public function test_order_stats_revenue_gross_margin_and_balance(): void
    {
        $stats = collect((fn () => $this->getStats())->call(new OrderStats))
            ->mapWithKeys(fn (Stat $stat) => [(string) $stat->getLabel() => [(string) $stat->getValue(), (string) $stat->getDescription()]]);

        // Σ total_mmk over March, excluding cancelled/rejected (the fee is IN revenue):
        // A 162,500 + B 92,500 + C 118,000 + D 30,000 = 403,000
        $this->assertSame('403,000 Ks', $stats['Revenue this month'][0]);

        // Σ per-order margin over FULLY-costed March orders only (B is partial):
        // A 83,333 + C 81,666 + D 15,000 = 179,999, on 3 of 4
        $this->assertSame('179,999 Ks', $stats['Gross margin (liquid only)'][0]);
        $this->assertStringContainsString('on 3 of 4 orders', $stats['Gross margin (liquid only)'][1]);

        // every March order, cancelled and rejected included: A B C D E F = 6
        $this->assertSame('6', $stats['Orders this month'][0]);

        // all time, positive balances only (D's −5,000 overpayment is excluded, not netted):
        // A 162,500 + B 92,500 + C 98,000 + G 132,500 = 485,500 over 4 orders
        $this->assertSame('485,500 Ks', $stats['Balance outstanding'][0]);
        $this->assertSame('4 order(s) with a balance due', $stats['Balance outstanding'][1]);
    }

    public function test_monthly_pnl_for_march(): void
    {
        $pnl = MonthlyPnl::for(2026, 3);

        // income = Σ(items − discount), never total_mmk (the courier's fee is not sales):
        // A 160,000 + B 90,000 + C 115,000 + D 30,000 = 395,000
        $this->assertSame(395000, $pnl->salesIncomeMmk);
        $this->assertSame(20000, $pnl->discountsGivenMmk);         // A 15,000 + C 5,000
        // COGS over fully-costed orders: A 76,667 + C 33,334 + D 15,000 = 125,001
        $this->assertSame(125001, $pnl->cogsMmk);
        $this->assertSame(3, $pnl->costedOrders);
        $this->assertSame(4, $pnl->totalOrders);
        // 395,000 − 125,001. Deliberately not the dashboard's 179,999: P&L keeps B's
        // uncosted 90,000 of income; the dashboard margin drops the whole order.
        $this->assertSame(269999, $pnl->grossMarginMmk);

        $operating = $pnl->operatingByCategory;
        ksort($operating);
        $this->assertSame(['fees' => 3000, 'marketing' => 8500, 'other' => 1500, 'packaging' => 12000], $operating);
        $this->assertSame(25000, $pnl->operatingTotalMmk);

        $this->assertSame(8000, $pnl->deliveryFeesCollectedMmk);  // A 2,500 + B 2,500 + C 3,000 + D 0
        $this->assertSame(6000, $pnl->courierPaidMmk);            // the delivery expense
        $this->assertSame(2000, $pnl->deliveryResultMmk);
        $this->assertSame(246999, $pnl->netOperatingMmk);          // 269,999 − 25,000 + 2,000
        $this->assertSame(300000, $pnl->stockPurchasesMmk);        // below the line
    }

    public function test_monthly_pnl_for_february(): void
    {
        $pnl = MonthlyPnl::for(2026, 2);

        $this->assertSame(130000, $pnl->salesIncomeMmk);           // G only
        $this->assertSame(0, $pnl->discountsGivenMmk);
        $this->assertSame(33334, $pnl->cogsMmk);
        $this->assertSame(1, $pnl->costedOrders);
        $this->assertSame(1, $pnl->totalOrders);
        $this->assertSame(96666, $pnl->grossMarginMmk);
        $this->assertSame(['other' => 4000], $pnl->operatingByCategory);
        $this->assertSame(4000, $pnl->operatingTotalMmk);
        $this->assertSame(2500, $pnl->deliveryFeesCollectedMmk);
        $this->assertSame(0, $pnl->courierPaidMmk);
        $this->assertSame(2500, $pnl->deliveryResultMmk);
        $this->assertSame(95166, $pnl->netOperatingMmk);           // 96,666 − 4,000 + 2,500
        $this->assertSame(0, $pnl->stockPurchasesMmk);
    }

    // ---- payments and couriers (#67/#109) ----

    public function test_mark_paid_defaults_for_an_online_and_a_cod_order(): void
    {
        $this->seedCourierOrders();

        // online prepays items − discount; COD adds the fee the courier collects
        $this->ordersTable()
            ->mountTableAction('markPaid', $this->orders['H'])
            ->assertTableActionDataSet(['amount_received' => 55000]);
        $this->ordersTable()
            ->mountTableAction('markPaid', $this->orders['I'])
            ->assertTableActionDataSet(['amount_received' => 112500]);
    }

    public function test_an_online_order_paid_then_its_fee_collected_by_the_courier_is_settled_in_full(): void
    {
        $this->seedCourierOrders();
        $h = $this->orders['H'];

        $this->ordersTable()->callTableAction('markPaid', $h); // default 55,000
        $h->refresh();
        $this->assertSame([55000, PaymentStatus::Paid, 2500], [$h->deposit_mmk, $h->payment_status, $h->balanceDue()]);

        $this->ordersTable()->callTableAction('handedToCourier', $h); // default: the 2,500 fee
        $this->assertSame(2500, $h->refresh()->courier_carrying_mmk);

        $this->ordersTable()->callTableAction('courierSettled', $h); // default min(2,500, 2,500)
        $h->refresh();
        $this->assertSame([57500, PaymentStatus::Paid, 0], [$h->deposit_mmk, $h->payment_status, $h->balanceDue()]);
    }

    public function test_a_cod_order_settled_short_keeps_the_remainder_unpaid(): void
    {
        $this->seedCourierOrders();
        $i = $this->orders['I'];

        $this->ordersTable()->callTableAction('handedToCourier', $i); // default: the 112,500 balance
        $this->assertSame(112500, $i->refresh()->courier_carrying_mmk);

        $this->ordersTable()->callTableAction('courierSettled', $i, ['collected_mmk' => 100000]);
        $i->refresh();
        $this->assertSame([100000, PaymentStatus::Unpaid, 12500], [$i->deposit_mmk, $i->payment_status, $i->balanceDue()]);
    }

    public function test_cash_with_couriers_and_balance_outstanding_while_one_order_is_out(): void
    {
        $this->seedCourierOrders();
        $this->travelTo(CarbonImmutable::parse('2026-01-12 09:00:00'));

        $this->ordersTable()->callTableAction('markPaid', $this->orders['H']);
        foreach (['H', 'I', 'J'] as $key) {
            $this->ordersTable()->callTableAction('handedToCourier', $this->orders[$key]);
        }
        $this->ordersTable()->callTableAction('courierSettled', $this->orders['H']);

        // H settled; I 112,500 + J 62,500 still out
        $this->assertSame(['175,000 Ks', '2 order(s) out — oldest handed off 12 Jan'], $this->courierFloat());

        $this->travelTo(CarbonImmutable::parse('2026-01-13 09:00:00'));
        $this->ordersTable()->callTableAction('courierSettled', $this->orders['I'], ['collected_mmk' => 100000]);

        // only J's handoff snapshot is left with a courier
        $this->assertSame(['62,500 Ks', '1 order(s) out — oldest handed off 12 Jan'], $this->courierFloat());

        $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));
        $stats = collect((fn () => $this->getStats())->call(new OrderStats))
            ->mapWithKeys(fn (Stat $stat) => [(string) $stat->getLabel() => [(string) $stat->getValue(), (string) $stat->getDescription()]]);

        // 485,500 + I 12,500 + J 62,500; H is settled to 0 and not counted
        $this->assertSame(['560,500 Ks', '6 order(s) with a balance due'], $stats['Balance outstanding']);

        // January orders move no March figure
        $this->assertSame('403,000 Ks', $stats['Revenue this month'][0]);
        $this->assertSame('179,999 Ks', $stats['Gross margin (liquid only)'][0]);
        $this->assertSame('6', $stats['Orders this month'][0]);
        $this->assertSame(395000, MonthlyPnl::for(2026, 3)->salesIncomeMmk);
    }

    // ---- fixture ----

    private function seedCatalog(): void
    {
        $this->chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $this->creed = Brand::create(['name' => 'Creed', 'type' => 'niche']);
        $oldHouse = Brand::create(['name' => 'Old House', 'type' => 'designer', 'is_active' => false]);

        $this->allure = $this->chanel->products()->create([
            'name' => 'Allure Homme Sport', 'attributes' => [
                'concentration' => 'cologne', 'gender' => 'male', 'notes' => 'Orange, Sea Notes, Musk',
                'vibes' => 'Fresh, Sporty', 'performance' => 'Around 4-6 Hours',
            ],
            'description' => 'A crisp citrus-marine cologne.',
            'is_featured' => true, 'bottle_cost_mmk' => 300000, 'bottle_volume_ml' => 100,
            'stock_ml' => 100,
        ]);
        $this->allure->variants()->createMany([
            ['size_ml' => 5, 'price_mmk' => 30000],
            ['size_ml' => 10, 'price_mmk' => 55000],
            ['size_ml' => 30, 'price_mmk' => 150000, 'in_stock' => false],
        ]);

        $this->aventus = $this->creed->products()->create([
            'name' => 'Aventus', 'attributes' => ['concentration' => 'edp', 'gender' => 'male', 'notes' => 'Pineapple, Birch'],
            'bottle_cost_mmk' => 100000, 'bottle_volume_ml' => 30,
            'stock_ml' => 12,
        ]);
        $this->aventus->variants()->createMany([
            ['size_ml' => 5, 'price_mmk' => 65000],
            ['size_ml' => 10, 'price_mmk' => 120000],
        ]);

        $this->loveInWhite = $this->creed->products()->create([
            'name' => 'Love In White', 'attributes' => ['concentration' => 'edp', 'gender' => 'female'],
        ]);
        $this->loveInWhite->variants()->create(['size_ml' => 5, 'price_mmk' => 60000]);

        $this->creed->products()->create([
            'name' => 'Green Irish Tweed', 'attributes' => ['concentration' => 'edp', 'gender' => 'male'], 'is_active' => false,
        ])->variants()->create(['size_ml' => 5, 'price_mmk' => 999000]);
        $oldHouse->products()->create([
            'name' => 'Ghost', 'attributes' => ['concentration' => 'edt', 'gender' => 'unisex'],
        ])->variants()->create(['size_ml' => 5, 'price_mmk' => 1000]);

        $this->sanchaung = $this->serviceableTownship(fee: 2500, name: 'Sanchaung');

        PromoCode::create([
            'code' => 'parity10', 'type' => 'percent', 'value' => 10, 'max_discount_mmk' => 15000,
        ]);
    }

    private function seedOrders(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-20 12:00:00'));
        $this->orders['G'] = $this->checkout([[$this->aventus, 5, 2]]);
        $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00:00'));

        $this->orders['A'] = $this->postCheckout([[$this->allure, 10, 2], [$this->aventus, 5, 1]], promo: 'PARITY10');
        $this->orders['A']->accept(CarbonImmutable::parse('2026-03-16'), CarbonImmutable::parse('2026-03-18'));
        $this->orders['A']->update(['status' => OrderStatus::Prepared]);

        $this->orders['B'] = $this->postCheckout([[$this->loveInWhite, 5, 1], [$this->allure, 5, 1]]);

        $this->orders['C'] = $this->adminOrder(OrderSource::Facebook, OrderStatus::Delivered,
            [[$this->aventus, 10, 1]], fee: 3000, discount: 5000, deposit: 20000);

        $this->orders['D'] = $this->adminOrder(OrderSource::Tiktok, OrderStatus::Pending, [[$this->allure, 5, 1]]);
        $this->orders['D']->markPaid(35000);

        $this->orders['E'] = $this->postCheckout([[$this->aventus, 10, 1]]);
        $this->orders['E']->cancel();

        $this->orders['F'] = $this->checkout([[$this->allure, 10, 1]]);
        $this->orders['F']->reject('Out of this bottle.');
    }

    /** H, I and J — January checkouts, accepted and decanted, ready for the courier actions. */
    private function seedCourierOrders(): void
    {
        $this->actingAs($this->studioUser());
        $this->travelTo(CarbonImmutable::parse('2026-01-10 12:00:00'));

        foreach ([
            'H' => [PaymentMethod::Online, [[$this->allure, 10, 1]]],
            'I' => [PaymentMethod::Cod, [[$this->allure, 10, 2]]],
            'J' => [PaymentMethod::Cod, [[$this->allure, 5, 2]]],
        ] as $key => [$method, $items]) {
            $order = $this->checkout($items, $method);
            $order->accept(CarbonImmutable::parse('2026-01-11'), CarbonImmutable::parse('2026-01-12'));
            $order->update(['status' => OrderStatus::Prepared]);
            $this->orders[$key] = $order;
        }

        $this->travelTo(CarbonImmutable::parse('2026-01-12 09:00:00'));
    }

    private function ordersTable()
    {
        return Livewire::test(ListOrders::class)->set('activeTab', 'all');
    }

    /** @return array{0: string, 1: string} the float's value and description */
    private function courierFloat(): array
    {
        $stat = (fn () => $this->getStats())->call(new CourierFloat)[0];

        return [(string) $stat->getValue(), (string) $stat->getDescription()];
    }

    private function seedExpenses(): void
    {
        foreach ([
            ['2026-02-28', 'other', 4000],
            ['2026-03-01', 'fees', 3000],
            ['2026-03-02', 'packaging', 12000],
            ['2026-03-05', 'stock_purchase', 300000],
            ['2026-03-10', 'delivery', 6000],
            ['2026-03-20', 'other', 1500],
            ['2026-03-31', 'marketing', 8500],
            ['2026-04-01', 'fees', 9999],
        ] as [$date, $category, $amount]) {
            Expense::create(['spent_on' => $date, 'category' => $category, 'amount_mmk' => $amount]);
        }
    }

    /** A second shop's March sale and expense — isolation says none of it reaches the figures above. */
    private function seedAnotherShop(): void
    {
        $context = app(TenantContext::class);
        $home = $context->get();

        $context->set(Shop::create(['slug' => 'other-shop', 'name' => 'Other Shop', 'status' => ShopStatus::Live]));

        try {

            $fragrance = Brand::create(['name' => 'Chanel', 'type' => 'designer'])->products()->create([
                'name' => 'Allure Homme Sport', 'attributes' => ['concentration' => 'cologne', 'gender' => 'male'],
                'bottle_cost_mmk' => 50000, 'bottle_volume_ml' => 100,
            ]);
            $fragrance->variants()->create(['size_ml' => 5, 'price_mmk' => 7000]);

            Order::newFromCheckout([
                'customer_name' => 'Other Customer', 'phone' => '09-700000001',
                'delivery_township' => $this->serviceableTownship(fee: 1000, name: 'Sanchaung'),
                'address_line' => '1 Other Road',
                'items' => [['variant_id' => $this->variantId($fragrance, 5), 'quantity' => 3]],
            ]);
            Expense::create(['spent_on' => '2026-03-15', 'category' => 'marketing', 'amount_mmk' => 77000]);
        } finally {
            $context->set($home);
        }
    }

    /** @param array<array{0: Product, 1: int, 2: int}> $items */
    private function postCheckout(array $items, ?string $promo = null): Order
    {
        $response = $this->postJson('/api/v1/decant-please/orders', array_filter([
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->sanchaung->id,
            'address_line' => 'No. 12, Baho Road',
            'promo_code' => $promo,
            // smuggled client money — the server derives every figure and must ignore these
            'total_mmk' => 1,
            'delivery_fee_mmk' => 1,
            'items' => array_map(fn (array $item) => $item + ['unit_price_mmk' => 1], $this->itemPayload($items)),
        ]))->assertCreated();

        return Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
    }

    /** @param array<array{0: Product, 1: int, 2: int}> $items */
    private function checkout(array $items, PaymentMethod $method = PaymentMethod::Cod): Order
    {
        return Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-952345672',
            'delivery_township' => $this->sanchaung,
            'address_line' => '88 Strand Road',
            'payment_method' => $method->value,
            'items' => $this->itemPayload($items),
        ]);
    }

    /** The manual DM-order path (OrderSeeder / the admin repeater): a relationship create per line. */
    private function adminOrder(OrderSource $source, OrderStatus $status, array $items, int $fee = 0, int $discount = 0, int $deposit = 0): Order
    {
        $order = Order::create([
            'customer_name' => 'Ei Phyu', 'phone' => '09-773216549',
            'address' => '5 Kabar Aye Pagoda Road, Yankin, Yangon',
            'order_from' => $source, 'status' => $status,
            'prep_date' => '2026-03-14', 'delivery_date' => '2026-03-16',
            'delivery_fee_mmk' => $fee, 'discount_mmk' => $discount, 'deposit_mmk' => $deposit,
        ]);

        foreach ($items as [$fragrance, $size, $quantity]) {
            $order->items()->create([
                'product_id' => $fragrance->id,
                'fragrance_name_snapshot' => $fragrance->brand->name.' '.$fragrance->name,
                'size_ml' => $size,
                'unit_price_mmk' => $fragrance->variants()->where('size_ml', $size)->value('price_mmk'),
                'quantity' => $quantity,
            ]);
        }

        $order->recalculateTotal();

        return $order;
    }

    /** @param array<array{0: Product, 1: int, 2: int}> $items */
    private function itemPayload(array $items): array
    {
        return array_map(fn (array $item) => [
            'variant_id' => $this->variantId($item[0], $item[1]), 'quantity' => $item[2],
        ], $items);
    }

    /**
     * The expected keys, read off the actual object in the expected order. A key
     * a later step adds is ignored, in nested objects and in each element of a
     * list of objects (step 38's variant `options`); a list keeps its length.
     */
    private function pick(array $actual, array $expected): array
    {
        $picked = [];

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);
            $picked[$key] = match (true) {
                ! is_array($value) || ! is_array($actual[$key]) => $actual[$key],
                ! array_is_list($value) => $this->pick($actual[$key], $value),
                $value !== [] && is_array($value[0]) && count($value) === count($actual[$key]) => array_map(
                    fn (array $actualItem, array $expectedItem): array => $this->pick($actualItem, $expectedItem),
                    $actual[$key],
                    $value,
                ),
                default => $actual[$key],
            };
        }

        return $picked;
    }

    private function allureObject(): array
    {
        return [
            'id' => $this->allure->id,
            'name' => 'Allure Homme Sport',
            'slug' => 'chanel-allure-homme-sport',
            'brand' => ['id' => $this->chanel->id, 'name' => 'Chanel', 'slug' => 'chanel', 'type' => 'designer', 'type_label' => 'Designer'],
            'concentration' => 'cologne',
            'concentration_label' => 'Cologne',
            'gender' => 'male',
            'gender_label' => 'Male',
            'notes' => 'Orange, Sea Notes, Musk',
            'vibes' => 'Fresh, Sporty',
            'performance' => 'Around 4-6 Hours',
            'description' => 'A crisp citrus-marine cologne.',
            'image_url' => null,
            'is_featured' => true,
            'min_price_mmk' => 30000,
            'min_price_formatted' => '30,000 Ks',
            'prices' => [
                ['id' => $this->variantId($this->allure, 5), 'label' => '5ml', 'size_ml' => 5, 'price_mmk' => 30000, 'price_formatted' => '30,000 Ks', 'in_stock' => true],
                ['id' => $this->variantId($this->allure, 10), 'label' => '10ml', 'size_ml' => 10, 'price_mmk' => 55000, 'price_formatted' => '55,000 Ks', 'in_stock' => true],
                ['id' => $this->variantId($this->allure, 30), 'label' => '30ml', 'size_ml' => 30, 'price_mmk' => 150000, 'price_formatted' => '150,000 Ks', 'in_stock' => false],
            ],
        ];
    }

    private function aventusObject(): array
    {
        return [
            'id' => $this->aventus->id,
            'slug' => 'creed-aventus',
            'brand' => ['id' => $this->creed->id, 'name' => 'Creed', 'type' => 'niche'],
            'concentration' => 'edp',
            'gender' => 'male',
            'is_featured' => false,
            'min_price_mmk' => 65000,
            'min_price_formatted' => '65,000 Ks',
            'prices' => [
                ['id' => $this->variantId($this->aventus, 5), 'label' => '5ml', 'size_ml' => 5, 'price_mmk' => 65000, 'price_formatted' => '65,000 Ks', 'in_stock' => true],
                ['id' => $this->variantId($this->aventus, 10), 'label' => '10ml', 'size_ml' => 10, 'price_mmk' => 120000, 'price_formatted' => '120,000 Ks', 'in_stock' => true],
            ],
        ];
    }

    private function loveInWhiteObject(): array
    {
        return [
            'id' => $this->loveInWhite->id,
            'slug' => 'creed-love-in-white',
            'gender' => 'female',
            'notes' => null,
            'min_price_mmk' => 60000,
            'min_price_formatted' => '60,000 Ks',
            'prices' => [
                ['id' => $this->variantId($this->loveInWhite, 5), 'label' => '5ml', 'size_ml' => 5, 'price_mmk' => 60000, 'price_formatted' => '60,000 Ks', 'in_stock' => true],
            ],
        ];
    }
}
