<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 24: the {shop} path segment resolves the tenant. Unknown or inactive shops
 * 404 with the same generic response as tracking — no shop-enumeration oracle
 * beyond what the storefront URL already reveals (ResolveTenant).
 */
class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_known_active_shop_resolves_and_serves(): void
    {
        // The default shop (slug decant-please) is created by the seam migration.
        $this->getJson('/api/v1/decant-please/meta')->assertOk();
    }

    public function test_an_unknown_shop_slug_404s(): void
    {
        $this->getJson('/api/v1/no-such-shop/meta')->assertNotFound();
        $this->getJson('/api/v1/no-such-shop/fragrances')->assertNotFound();
    }

    public function test_an_inactive_shop_404s(): void
    {
        Shop::factory()->inactive()->create(['slug' => 'dormant']);

        $this->getJson('/api/v1/dormant/meta')->assertNotFound();
    }
}
