<?php

namespace Tests\Feature;

use App\Enums\Courier;
use App\Filament\Resources\DeliveryZones\Pages\ManageDeliveryZones;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Brand;
use App\Models\DeliveryTownship;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryZoneImport;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    private Fragrance $allure; // 10ml @ 55,000

    protected function setUp(): void
    {
        parent::setUp();

        $chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $this->allure = $chanel->fragrances()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
        ]);
        $this->allure->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000]);
    }

    // ---- The fee is server data ---------------------------------------------

    public function test_fee_derives_from_the_township_and_client_sent_fee_and_address_are_ignored(): void
    {
        $township = $this->serviceableTownship(fee: 2000, name: 'Sanchaung', nameMm: 'စမ်းချောင်း');

        $response = $this->postJson('/api/v1/orders', $this->payload([
            'delivery_township_id' => $township->id,
            'delivery_fee_mmk' => 1,          // smuggled — must be ignored
            'address' => 'smuggled address',  // no longer accepted — must be ignored
        ]))->assertCreated();

        $response->assertJsonPath('delivery_fee_mmk', 2000)
            ->assertJsonPath('delivery_fee_formatted', '2,000 Ks')
            ->assertJsonPath('total_mmk', 57000); // 55,000 items + 2,000 fee

        $order = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
        $this->assertSame(2000, $order->delivery_fee_mmk);
        $this->assertSame(57000, $order->total_mmk);
        $this->assertSame("No. 12, Baho Road\nSanchaung (စမ်းချောင်း), Yangon Region", $order->address);
        $this->assertSame('No. 12, Baho Road', $order->address_line);
        $this->assertSame('Yangon Region', $order->region_snapshot);
        $this->assertSame('Sanchaung', $order->township_snapshot);
        $this->assertSame($township->id, $order->delivery_township_id);
    }

    public function test_unknown_inactive_suspended_and_courierless_townships_are_all_rejected(): void
    {
        $inactive = $this->serviceableTownship(name: 'Hlaing');
        $inactive->update(['is_active' => false]);

        // active but zero courier rows — the Cocokyun shape
        $deadZone = DeliveryTownship::create([
            'region' => 'yangon', 'name' => 'Cocokyun', 'fee_mmk' => 0, 'is_active' => true,
        ]);

        // active but its only route is suspended
        $suspended = DeliveryTownship::create([
            'region' => 'rakhine', 'name' => 'Sittwe', 'fee_mmk' => 3000, 'is_active' => true,
        ]);
        $suspended->couriers()->create([
            'courier' => Courier::RoyalExpress->value, 'courier_name' => 'Sittwe', 'is_available' => false,
        ]);

        foreach ([999999, $inactive->id, $deadZone->id, $suspended->id] as $townshipId) {
            $this->postJson('/api/v1/orders', $this->payload(['delivery_township_id' => $townshipId]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['delivery_township_id']);
        }

        // none of the four fell through to a 0-fee order
        $this->assertSame(0, Order::count());
    }

    public function test_a_zero_fee_township_is_real_free_delivery_not_unset(): void
    {
        $free = $this->serviceableTownship(fee: 0, name: 'Kamayut');

        $this->postJson('/api/v1/orders', $this->payload(['delivery_township_id' => $free->id]))
            ->assertCreated()
            ->assertJsonPath('delivery_fee_mmk', 0)
            ->assertJsonPath('total_mmk', 55000);
    }

    public function test_address_composes_without_the_extra_line_when_blank(): void
    {
        $township = $this->serviceableTownship(name: 'Dagon');

        $code = $this->postJson('/api/v1/orders', $this->payload([
            'delivery_township_id' => $township->id,
            'address_extra' => null,
        ]))->assertCreated()->json('tracking_code');

        $this->assertSame(
            "No. 12, Baho Road\nDagon, Yangon Region", // name_mm unset → no parens
            Order::where('tracking_code', $code)->firstOrFail()->address,
        );
    }

    // ---- Snapshots outlive the rate table -----------------------------------

    public function test_rename_reprice_and_delete_leave_a_placed_order_untouched(): void
    {
        $township = $this->serviceableTownship(fee: 2000, name: 'Insein');

        $code = $this->postJson('/api/v1/orders', $this->payload(['delivery_township_id' => $township->id]))
            ->assertCreated()->json('tracking_code');
        $order = Order::where('tracking_code', $code)->firstOrFail();

        $township->update(['name' => 'Renamed Insein', 'fee_mmk' => 9000]);
        $order->refresh();
        $this->assertSame('Insein', $order->township_snapshot);
        $this->assertSame(2000, $order->delivery_fee_mmk);
        $this->assertSame(57000, $order->total_mmk);

        $township->delete(); // cascades its courier rows, nulls the order FK
        $order->refresh();
        $this->assertNull($order->delivery_township_id);
        $this->assertSame('Insein', $order->township_snapshot);
        $this->assertSame('Yangon Region', $order->region_snapshot);
        $this->assertSame(2000, $order->delivery_fee_mmk);
        $this->assertStringContainsString('Insein', $order->address);
    }

    // ---- Nothing about couriers crosses the public boundary ------------------

    public function test_no_courier_data_crosses_the_public_boundary(): void
    {
        $township = $this->serviceableTownship(fee: 2000, name: 'Sanchaung');
        $township->couriers()->first()->update(['cost_mmk' => 1500]);

        $zones = $this->getJson('/api/v1/delivery-zones')->assertOk();
        foreach (['courier', 'cost', 'Royal', 'Bee', 'district'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $zones->getContent());
        }

        $checkout = $this->postJson('/api/v1/orders', $this->payload(['delivery_township_id' => $township->id]))
            ->assertCreated();
        $this->assertStringNotContainsString('courier', strtolower($checkout->getContent()));

        $order = Order::where('tracking_code', $checkout->json('tracking_code'))->firstOrFail();
        $order->update(['delivery_courier' => Courier::RoyalExpress]);

        $track = $this->getJson('/api/v1/orders/track?tracking_code='.$order->tracking_code.'&phone=09-771234561')
            ->assertOk();
        $this->assertStringNotContainsString('courier', strtolower($track->getContent()));

        // the A5 invoice ships with the parcel — courier choice and cost stay off it
        $order->update(['status' => 'pending']);
        $invoiceHtml = view('pdf.invoice', ['order' => $order->fresh()->loadMissing('items')])->render();
        foreach (['Royal Express', 'BeeXprss', 'royal_express', 'beexprss', '1,500'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $invoiceHtml);
        }

        // the orders CSV export (shared with an accountant) carries no courier column
        $this->actingAsAdmin();
        $download = Livewire::test(ListOrders::class)
            ->callTableAction('exportCsv')
            ->effects['download'] ?? null;
        $this->assertNotNull($download);
        $csv = base64_decode($download['content']);
        foreach (['Courier', 'Royal Express', 'BeeXprss'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $csv);
        }
    }

    // ---- Reference cost stays reference -------------------------------------

    public function test_best_case_margin_is_blank_never_zero_while_costs_are_unknown(): void
    {
        $township = $this->serviceableTownship(fee: 2000, name: 'Bahan');

        // route exists, cost unknown → null, not 0
        $this->assertNull($township->cheapestAvailableCostMmk());
        $this->assertNull($township->bestCaseMarginMmk());

        // a cost on a SUSPENDED route doesn't count as available
        $township->couriers()->create([
            'courier' => Courier::Beexprss->value, 'courier_name' => 'Bahan',
            'cost_mmk' => 900, 'is_available' => false,
        ]);
        $this->assertNull($township->fresh()->cheapestAvailableCostMmk());

        $township->couriers()->where('courier', Courier::RoyalExpress->value)->first()
            ->update(['cost_mmk' => 1500]);
        $fresh = $township->fresh();
        $this->assertSame(1500, $fresh->cheapestAvailableCostMmk());
        $this->assertSame(500, $fresh->bestCaseMarginMmk());
    }

    public function test_courier_options_carry_the_recorded_cost_for_the_decanters_eyes(): void
    {
        $township = $this->serviceableTownship(fee: 2000, name: 'Latha');
        $township->couriers()->first()->update(['cost_mmk' => 1500]);

        $options = DeliveryTownship::courierOptionsFor($township->id);
        $this->assertSame(['royal_express' => 'Royal Express — cost 1,500 Ks'], $options);

        // no township picked (a DM order to anywhere): every courier, plain
        $this->assertSame(
            ['royal_express' => 'Royal Express', 'beexprss' => 'BeeXprss'],
            DeliveryTownship::courierOptionsFor(null),
        );
    }

    public function test_accept_records_the_courier_choice(): void
    {
        $township = $this->serviceableTownship(name: 'Thaketa');
        $code = $this->postJson('/api/v1/orders', $this->payload(['delivery_township_id' => $township->id]))
            ->assertCreated()->json('tracking_code');
        $order = Order::where('tracking_code', $code)->firstOrFail();

        $this->actingAsAdmin();
        Livewire::test(ListOrders::class)->callTableAction('accept', $order, data: [
            'decant_date' => today()->addDay()->toDateString(),
            'delivery_date' => today()->addDays(2)->toDateString(),
            'delivery_courier' => Courier::RoyalExpress->value,
        ]);

        $this->assertSame(Courier::RoyalExpress, $order->refresh()->delivery_courier);
    }

    // ---- The public tree and its cache --------------------------------------

    public function test_delivery_zones_lists_serviceable_rows_only_and_a_save_busts_the_cache(): void
    {
        $served = $this->serviceableTownship(fee: 2000, name: 'Sanchaung', nameMm: 'စမ်းချောင်း');

        $inactive = $this->serviceableTownship(name: 'Hlaing');
        $inactive->update(['is_active' => false]);

        DeliveryTownship::create(['region' => 'yangon', 'name' => 'Cocokyun', 'is_active' => true]);

        $response = $this->getJson('/api/v1/delivery-zones')->assertOk();
        $this->assertSame(
            [['value' => 'yangon', 'label' => 'Yangon Region']],
            collect($response->json('regions'))->map(fn (array $region) => [
                'value' => $region['value'], 'label' => $region['label'],
            ])->all(),
        );
        $this->assertSame(['Sanchaung'], array_column($response->json('regions.0.townships'), 'name'));
        $this->assertSame('Sanchaung (စမ်းချောင်း)', $response->json('regions.0.townships.0.label'));
        $this->assertSame('2,000 Ks', $response->json('regions.0.townships.0.fee_formatted'));

        // a reprice reaches the storefront immediately — the save busts the cache
        $served->update(['fee_mmk' => 2500]);
        $this->getJson('/api/v1/delivery-zones')
            ->assertJsonPath('regions.0.townships.0.fee_mmk', 2500);

        // ...and so does a courier row change, since it flips serviceability
        $served->couriers()->first()->update(['is_available' => false]);
        $this->getJson('/api/v1/delivery-zones')->assertJsonPath('regions', []);
    }

    // ---- Legacy orders keep rendering ---------------------------------------

    public function test_a_legacy_order_with_null_structured_columns_still_renders_everywhere(): void
    {
        $legacy = Order::create([
            'customer_name' => 'Aung Kyaw', 'phone' => '09-771234561',
            'address' => 'Free-text address from a DM, Yangon',
            'order_from' => 'tiktok', 'status' => 'pending', 'total_mmk' => 55000,
        ])->refresh();
        $legacy->items()->create([
            'fragrance_id' => $this->allure->id, 'fragrance_name_snapshot' => 'Chanel Allure Homme Sport',
            'size_ml' => 10, 'unit_price_mmk' => 55000, 'quantity' => 1,
        ]);

        $this->assertNull($legacy->delivery_township_id);
        $this->assertNull($legacy->township_snapshot);

        $html = view('pdf.invoice', ['order' => $legacy->loadMissing('items')])->render();
        $this->assertStringContainsString('Free-text address from a DM', $html);

        $this->getJson('/api/v1/orders/track?tracking_code='.$legacy->tracking_code.'&phone=09-771234561')
            ->assertOk()
            ->assertJsonPath('address', 'Free-text address from a DM, Yangon');
    }

    // ---- Seeder: aliases, reseeds, the demo seam ----------------------------

    public function test_seeder_is_idempotent_and_aliases_reconcile_two_spellings_to_one_row(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $kungyangon = DeliveryTownship::where('name', 'Kungyangone')->firstOrFail();
        $royal = $kungyangon->couriers()->where('courier', Courier::RoyalExpress->value)->firstOrFail();
        $this->assertSame('Kungyangone', $royal->courier_name);

        // Bee spells the same place its own way — one canonical row, two aliases
        $kungyangon->couriers()->create([
            'courier' => Courier::Beexprss->value, 'courier_name' => 'KunCyanGone', 'is_available' => true,
        ]);
        // the decanter corrects RoyalX's spelling by hand
        $royal->update(['courier_name' => 'Kungyangone (YGN)']);
        // a restored city township rides RoyalX's single Yangon destination
        $this->assertSame(
            'Yangon',
            DeliveryTownship::where('name', 'Sanchaung')->firstOrFail()->couriers()->first()->courier_name,
        );

        $before = DeliveryTownship::count();
        $this->seed(DeliveryZoneSeeder::class); // reseed: no duplicates, no clobbering

        $this->assertSame($before, DeliveryTownship::count());
        $kungyangon->refresh()->loadMissing('couriers');
        $this->assertSame(2, $kungyangon->couriers->count());
        $this->assertEqualsCanonicalizing(
            ['Kungyangone (YGN)', 'KunCyanGone'],
            $kungyangon->couriers->pluck('courier_name')->all(),
        );

        // suspended routes seed as rows-with-a-closed-route, not absent rows
        $hakha = DeliveryTownship::where('name', 'Hakha')->firstOrFail();
        $this->assertFalse($hakha->couriers()->first()->is_available);
        $this->assertFalse($hakha->isServiceable());

        // the demo seam: Yangon active at a placeholder so demo checkout works
        $this->assertGreaterThan(0, DeliveryTownship::where('region', 'yangon')->where('is_active', true)->count());
        $this->assertSame(0, DeliveryTownship::where('region', '!=', 'yangon')->where('is_active', true)->count());
    }

    // ---- Admin: bulk actions + import ---------------------------------------

    public function test_bulk_set_fee_and_set_cost_write_through(): void
    {
        $this->actingAsAdmin();

        $first = $this->serviceableTownship(name: 'North Dagon');
        $second = $this->serviceableTownship(name: 'South Dagon');
        $noRoute = DeliveryTownship::create(['region' => 'yangon', 'name' => 'Dawbon', 'is_active' => true]);

        Livewire::test(ManageDeliveryZones::class)
            ->callTableBulkAction('setFee', [$first->id, $second->id, $noRoute->id], data: ['fee_mmk' => 2500])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame([2500, 2500, 2500], [
            $first->refresh()->fee_mmk, $second->refresh()->fee_mmk, $noRoute->refresh()->fee_mmk,
        ]);

        Livewire::test(ManageDeliveryZones::class)
            ->callTableBulkAction('setCost', [$first->id, $noRoute->id], data: [
                'courier' => Courier::Beexprss->value, 'cost_mmk' => 1800,
            ])
            ->assertHasNoTableBulkActionErrors();

        // records the cost — and creates the missing route (alias = township name)
        $this->assertSame(1800, $first->couriers()->where('courier', 'beexprss')->first()->cost_mmk);
        $bee = $noRoute->couriers()->where('courier', 'beexprss')->first();
        $this->assertSame('Dawbon', $bee->courier_name);
        $this->assertTrue($bee->is_available);
        // ...without touching the pre-existing RoyalX route's alias
        $this->assertSame('North Dagon', $first->couriers()->where('courier', 'royal_express')->first()->courier_name);
    }

    public function test_import_round_trips_its_template_updates_from_non_blank_cells_only_and_fails_junk_alone(): void
    {
        // the template imports as-is, Burmese row included, both region spellings parsed
        $import = DeliveryZoneImport::run(DeliveryZoneImport::template());
        $this->assertSame(2, $import->created);
        $this->assertSame([], $import->failures);

        $sanchaung = DeliveryTownship::where('name', 'Sanchaung')->firstOrFail();
        $this->assertSame('စမ်းချောင်း', $sanchaung->name_mm);
        $this->assertSame(2000, $sanchaung->fee_mmk);
        $this->assertFalse($sanchaung->is_active); // an import is never a shipping promise
        $this->assertSame('mandalay', DeliveryTownship::where('name', 'Chanayethazan')->firstOrFail()->region->value);

        // junk rows fail alone with reasons; the good row still lands — and a
        // blank fee cell can't zero the price the template just set
        $import = DeliveryZoneImport::run(
            "region,name,name_mm,fee_mmk\n"
            ."Yangon,-----,,\n"
            ."Bago,Tharyarwaddy2,,1000\n"
            ."Nowhere,Ghost,,1000\n"
            ."yangon region,SANCHAUNG,,\n"
            ."Yangon,Thingangyun,သင်္ဃန်းကျွန်း,3000\n"
        );

        $this->assertSame(1, $import->created);   // Thingangyun
        $this->assertSame(1, $import->updated);   // Sanchaung matched case-insensitively
        $this->assertCount(3, $import->failures); // -----, trailing digit, unknown region
        $this->assertSame(2000, $sanchaung->refresh()->fee_mmk); // blank cell kept the fee

        $failuresCsv = $import->failuresCsv();
        $this->assertStringContainsString('error', $failuresCsv);
        $this->assertStringContainsString('Tharyarwaddy2', $failuresCsv);
        $this->assertStringContainsString('unknown region', $failuresCsv);
    }

    // ---- helpers ------------------------------------------------------------

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'delivery_township_id' => $this->serviceableTownship()->id,
            'address_line' => 'No. 12, Baho Road',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1]],
        ];
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@decantplease.local',
            'password' => 'secret-password',
        ]));
    }
}
