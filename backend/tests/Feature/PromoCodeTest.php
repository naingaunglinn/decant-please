<?php

namespace Tests\Feature;

use App\Enums\PromoType;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Models\Brand;
use App\Models\Fragrance;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromoCodeTest extends TestCase
{
    use RefreshDatabase;

    private Fragrance $allure; // 10ml = 55,000 Ks

    protected function setUp(): void
    {
        parent::setUp();

        $chanel = Brand::create(['name' => 'Chanel', 'type' => 'designer']);
        $this->allure = $chanel->fragrances()->create([
            'name' => 'Allure Homme Sport', 'concentration' => 'cologne', 'gender' => 'male',
        ]);
        $this->allure->decantPrices()->create(['size_ml' => 10, 'price_mmk' => 55000]);
    }

    public function test_preview_validates_and_persists_nothing(): void
    {
        PromoCode::create(['code' => 'SAVE10', 'type' => PromoType::Percent, 'value' => 10]);

        // two items → subtotal 110,000; 10% = 11,000; lowercase input matches
        $this->postJson('/api/v1/orders/validate-promo', [
            'code' => 'save10',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('discount_mmk', 11000)
            ->assertJsonPath('discount_formatted', '11,000 Ks')
            ->assertJsonPath('new_total_formatted', '99,000 Ks')
            ->assertJsonPath('message', null);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, PromoCode::firstOrFail()->times_used);
    }

    public function test_preview_returns_the_specific_reason_not_a_generic_error(): void
    {
        PromoCode::create(['code' => 'MAXED', 'type' => PromoType::Fixed, 'value' => 5000, 'usage_limit' => 3, 'times_used' => 3]);
        PromoCode::create(['code' => 'BIGCART', 'type' => PromoType::Fixed, 'value' => 5000, 'min_order_mmk' => 200000]);
        PromoCode::create(['code' => 'EXPIRED', 'type' => PromoType::Fixed, 'value' => 5000, 'expires_at' => today()->subDay()]);
        PromoCode::create(['code' => 'PAUSED', 'type' => PromoType::Fixed, 'value' => 5000, 'is_active' => false]);

        $preview = fn (string $code) => $this->postJson('/api/v1/orders/validate-promo', [
            'code' => $code,
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('discount_mmk', 0);

        $preview('NOSUCHCODE')->assertJsonPath('message', "We couldn't find that code.");
        $preview('MAXED')->assertJsonPath('message', 'That code has reached its usage limit.');
        $preview('BIGCART')->assertJsonPath('message', 'This code needs an order of at least 200,000 Ks.');
        // expired and deactivated deliberately read as not-found, not as a probe-able state
        $preview('EXPIRED')->assertJsonPath('message', "We couldn't find that code.");
        $preview('PAUSED')->assertJsonPath('message', "We couldn't find that code.");
    }

    public function test_percent_cap_and_subtotal_floor_apply(): void
    {
        PromoCode::create(['code' => 'HALF', 'type' => PromoType::Percent, 'value' => 50, 'max_discount_mmk' => 20000]);
        PromoCode::create(['code' => 'HUGE', 'type' => PromoType::Fixed, 'value' => 999999]);

        // 50% of 110,000 = 55,000 → capped at 20,000
        $this->postJson('/api/v1/orders/validate-promo', [
            'code' => 'HALF',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 2]],
        ])->assertJsonPath('discount_mmk', 20000);

        // fixed amount larger than the cart → clamped to the subtotal, total 0
        $this->postJson('/api/v1/orders/validate-promo', [
            'code' => 'HUGE',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 1]],
        ])->assertJsonPath('discount_mmk', 55000)->assertJsonPath('new_total_formatted', '0 Ks');
    }

    public function test_checkout_applies_promo_and_stamps_the_order(): void
    {
        PromoCode::create(['code' => 'SAVE10', 'type' => PromoType::Percent, 'value' => 10]);

        $response = $this->postJson('/api/v1/orders', $this->payload(['promo_code' => 'save10']))
            ->assertCreated()
            ->assertJsonPath('total_mmk', 99000)
            ->assertJsonPath('promo_note', null);

        $order = Order::where('tracking_code', $response->json('tracking_code'))->firstOrFail();
        $this->assertSame(11000, $order->discount_mmk);
        $this->assertSame('SAVE10', $order->promo_code);
        $this->assertSame(99000, $order->total_mmk);
        $this->assertSame(1, PromoCode::firstOrFail()->times_used);

        // the receipt names the code
        $this->getJson("/api/v1/orders/track?tracking_code={$order->tracking_code}&phone=09-771234561")
            ->assertJsonPath('promo_code', 'SAVE10')
            ->assertJsonPath('discount_mmk', 11000);
    }

    public function test_lapsed_code_drops_discount_but_never_blocks_the_order(): void
    {
        PromoCode::create(['code' => 'LASTONE', 'type' => PromoType::Fixed, 'value' => 5000, 'usage_limit' => 1]);

        // first customer takes the last use
        $this->postJson('/api/v1/orders', $this->payload(['promo_code' => 'LASTONE']))
            ->assertCreated()
            ->assertJsonPath('promo_note', null);

        // second customer previewed earlier, submits after it's exhausted
        $second = $this->postJson('/api/v1/orders', $this->payload(['promo_code' => 'LASTONE']))
            ->assertCreated()
            ->assertJsonPath('promo_note', "That code was no longer valid, so it wasn't applied — you can still place this order without it.");

        $order = Order::where('tracking_code', $second->json('tracking_code'))->firstOrFail();
        $this->assertSame(0, $order->discount_mmk);
        $this->assertNull($order->promo_code);
        $this->assertSame(110000, $order->total_mmk);
        $this->assertSame(1, PromoCode::firstOrFail()->times_used); // exactly one redemption
    }

    public function test_manual_discount_edit_leaves_the_promo_snapshot_alone(): void
    {
        PromoCode::create(['code' => 'SAVE10', 'type' => PromoType::Percent, 'value' => 10]);
        $code = $this->postJson('/api/v1/orders', $this->payload(['promo_code' => 'SAVE10']))->json('tracking_code');

        $order = Order::where('tracking_code', $code)->firstOrFail();
        $order->update(['discount_mmk' => 25000]); // the decanter's after-the-fact adjustment
        $order->recalculateTotal();

        $order->refresh();
        $this->assertSame(25000, $order->discount_mmk);
        $this->assertSame('SAVE10', $order->promo_code); // snapshot untouched
        $this->assertSame(85000, $order->total_mmk);
    }

    public function test_admin_can_list_and_create_promo_codes(): void
    {
        $this->actingAs(User::factory()->create());
        PromoCode::create(['code' => 'SAVE10', 'type' => PromoType::Percent, 'value' => 10, 'max_discount_mmk' => 20000, 'usage_limit' => 5, 'times_used' => 2]);

        Livewire::test(ListPromoCodes::class)
            ->assertOk()
            ->assertSee('SAVE10')
            ->assertSee('10% off (max 20,000 Ks)')
            ->assertSee('2 / 5');

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'newyear25',
                'type' => PromoType::Fixed->value,
                'value' => 2500,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('NEWYEAR25', PromoCode::latest('id')->firstOrFail()->code); // stored uppercase
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Su Su',
            'phone' => '09-771234561',
            'address' => 'No. 12, Bahan Township, Yangon',
            'items' => [['fragrance_id' => $this->allure->id, 'size_ml' => 10, 'quantity' => 2]],
        ];
    }
}
