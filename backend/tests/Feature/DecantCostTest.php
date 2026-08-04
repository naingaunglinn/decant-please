<?php

namespace Tests\Feature;

use App\Filament\Resources\Fragrances\Pages\EditFragrance;
use App\Filament\Widgets\OrderStats;
use App\Http\Controllers\Api\TrackOrderController;
use App\Http\Resources\FragranceResource;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DecantCostTest extends TestCase
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

    // ---- the derivation ----

    public function test_liquid_cost_divides_exactly_and_ceils_the_remainder(): void
    {
        $exact = $this->fragrance(cost: 300000, volume: 100);
        $this->assertSame(30000, $exact->liquidCostMmk(10)); // exact division

        // 100,000 × 5 / 30 = 16,666.67 — CEILING, never floor: a floored cost flatters margin
        $remainder = $this->fragrance(cost: 100000, volume: 30, name: 'Layton');
        $this->assertSame(16667, $remainder->liquidCostMmk(5));

        $perMl = $this->fragrance(cost: 2500, volume: 1, name: 'PerMl');
        $this->assertSame(25000, $perMl->liquidCostMmk(10)); // volume = 1
    }

    public function test_liquid_cost_is_null_unless_both_reference_fields_are_set(): void
    {
        $this->assertNull($this->fragrance(cost: null, volume: null)->liquidCostMmk(10));
        $this->assertNull($this->fragrance(cost: 300000, volume: null, name: 'CostOnly')->liquidCostMmk(10));
        $this->assertNull($this->fragrance(cost: null, volume: 100, name: 'VolumeOnly')->liquidCostMmk(10));
    }

    public function test_cost_pair_is_both_or_neither_on_the_fragrance_form(): void
    {
        $fragrance = $this->fragrance(cost: null, volume: null);

        Livewire::test(EditFragrance::class, ['record' => $fragrance->getRouteKey()])
            ->fillForm(['bottle_cost_mmk' => 300000])
            ->call('save')
            ->assertHasFormErrors(['bottle_volume_ml']);
    }

    // ---- the snapshot ----

    public function test_checkout_snapshots_unit_and_line_cost(): void
    {
        $fragrance = $this->fragrance(cost: 300000, volume: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 2);

        $item = $order->items->first();
        $this->assertSame(30000, $item->unit_cost_mmk);
        $this->assertSame(60000, $item->line_cost_mmk);
    }

    public function test_admin_created_items_snapshot_too(): void
    {
        $fragrance = $this->fragrance(cost: 300000, volume: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 1);

        // The admin repeater path is a relationship create — the same model hook.
        $item = $order->items()->create([
            'fragrance_id' => $fragrance->id,
            'fragrance_name_snapshot' => 'Creed Aventus',
            'size_ml' => 5,
            'unit_price_mmk' => 50000,
            'quantity' => 1,
        ]);

        $this->assertSame(15000, $item->unit_cost_mmk); // 300,000 × 5 / 100
    }

    public function test_resaving_never_refreshes_the_snapshot(): void
    {
        $fragrance = $this->fragrance(cost: 300000, volume: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 1);
        $item = $order->items->first();

        $fragrance->update(['bottle_cost_mmk' => 999000]); // rebuy at a new price

        $item->update(['quantity' => 3]);
        $item->refresh();

        $this->assertSame(30000, $item->unit_cost_mmk); // a snapshot, not a cache
        $this->assertSame(90000, $item->line_cost_mmk); // re-derived from the STORED unit cost
    }

    public function test_legacy_items_stay_null_through_unrelated_saves(): void
    {
        $fragrance = $this->fragrance(cost: null, volume: null);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 1);
        $item = $order->items->first();

        $this->assertNull($item->unit_cost_mmk);
        $this->assertNull($item->line_cost_mmk);

        // Costing the fragrance later must not retro-fill the old item.
        $fragrance->update(['bottle_cost_mmk' => 300000, 'bottle_volume_ml' => 100]);
        $item->update(['quantity' => 2]);
        $item->refresh();

        $this->assertNull($item->unit_cost_mmk);
        $this->assertNull($item->line_cost_mmk); // null, never 0
    }

    // ---- the margin ----

    public function test_margin_is_items_minus_discount_minus_cost_and_ignores_delivery_fee(): void
    {
        $fragrance = $this->fragrance(cost: 300000, volume: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 2); // 180,000 items, 60,000 cost

        $order->update(['discount_mmk' => 20000]);
        $order->recalculateTotal();
        $this->assertSame(100000, $order->refresh()->liquidGrossMarginMmk());

        // The fee moves total_mmk but neither side of the margin.
        $order->update(['delivery_fee_mmk' => 5000]);
        $order->recalculateTotal();
        $this->assertSame(100000, $order->refresh()->liquidGrossMarginMmk());
    }

    public function test_partially_costed_order_reports_unknown(): void
    {
        $costed = $this->fragrance(cost: 300000, volume: 100);
        $uncosted = $this->fragrance(cost: null, volume: null, brand: 'Dior', name: 'Sauvage');

        $order = Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [
                ['fragrance_id' => $costed->id, 'size_ml' => 10, 'quantity' => 1],
                ['fragrance_id' => $uncosted->id, 'size_ml' => 10, 'quantity' => 1],
            ],
        ]);

        $this->assertNull($order->liquidGrossMarginMmk());
    }

    // ---- the dashboard stat ----

    public function test_stat_sums_fully_costed_orders_only_and_counts_coverage(): void
    {
        $costed = $this->fragrance(cost: 300000, volume: 100);
        $uncosted = $this->fragrance(cost: null, volume: null, brand: 'Dior', name: 'Sauvage');

        $this->checkout($costed, sizeMl: 10, quantity: 2);   // margin 120,000 (180,000 − 60,000)
        $this->checkout($uncosted, sizeMl: 10, quantity: 1); // unknown — counted in M, not N

        $cancelled = $this->checkout($costed, sizeMl: 5, quantity: 1); // would add 35,000
        $cancelled->cancel();

        Livewire::test(OrderStats::class)
            ->assertSee('Gross margin (liquid only)')
            ->assertSee('120,000 Ks')
            ->assertSee('on 1 of 2 orders')  // cancelled absent from M; the partial is in M, not N
            ->assertDontSee('155,000 Ks');   // the sum if the cancelled order leaked in
    }

    // ---- never leaks ----

    public function test_cost_never_crosses_the_public_api_or_the_invoice(): void
    {
        $fragrance = $this->fragrance(cost: 300000, volume: 100);
        $order = $this->checkout($fragrance, sizeMl: 10, quantity: 1);
        $order->accept(today()->addDay(), today()->addDays(2));

        $receipt = json_encode(TrackOrderController::receipt($order->fresh()->load('items.fragrance.brand')));
        $this->assertStringNotContainsString('cost', strtolower($receipt));

        $resource = json_encode(FragranceResource::make($fragrance->load('brand', 'decantPrices'))->resolve());
        $this->assertStringNotContainsString('cost', strtolower($resource));
        $this->assertStringNotContainsString('bottle', strtolower($resource));

        $invoice = view('pdf.invoice', ['orders' => collect([$order->fresh()->load('items')])])->render();
        $this->assertStringNotContainsString('cost', strtolower($invoice));
    }

    // ---- helpers ----

    private function fragrance(
        ?int $cost,
        ?int $volume,
        string $brand = 'Creed',
        string $name = 'Aventus',
    ): Fragrance {
        $brandModel = Brand::firstOrCreate(['name' => $brand], ['type' => 'niche']);

        $fragrance = $brandModel->fragrances()->create([
            'name' => $name,
            'concentration' => 'edp',
            'gender' => 'male',
            'bottle_cost_mmk' => $cost,
            'bottle_volume_ml' => $volume,
        ]);

        $fragrance->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 90000, 'in_stock' => true]);
        $fragrance->decantPrices()->create(['size_ml' => 5, 'price_mmk' => 50000, 'in_stock' => true]);

        return $fragrance;
    }

    private function checkout(Fragrance $fragrance, int $sizeMl, int $quantity): Order
    {
        return Order::newFromCheckout([
            'customer_name' => 'Aung Kyaw',
            'phone' => '09-771234561',
            'address' => 'Sanchaung, Yangon',
            'items' => [[
                'fragrance_id' => $fragrance->id,
                'size_ml' => $sizeMl,
                'quantity' => $quantity,
            ]],
        ]);
    }
}
