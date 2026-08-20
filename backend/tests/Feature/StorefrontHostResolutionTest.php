<?php

namespace Tests\Feature;

use App\Filament\Studio\Resources\Shops\Pages\ManageShops;
use App\Models\Shop;
use App\Models\ShopDomain;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-0004 PR-A: the storefront-host → shop mapping. shop_domains is platform-owned
 * (no BelongsToShop — it is what produces the tenant), so nothing in this file
 * spends the withoutTenancy() budget. The resolve endpoint mirrors the tracking
 * lookup's discipline: unknown, unverified, and inactive-shop hosts are
 * byte-identical generic 404s — no enumeration oracle.
 */
class StorefrontHostResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithDomain(
        string $slug = 'client-a',
        string $host = 'client-a.com',
        bool $active = true,
        bool $verified = true,
        bool $primary = true,
    ): Shop {
        $shop = Shop::create([
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'status' => $active ? 'live' : 'onboarding',
        ]);

        $shop->domains()->create([
            'host' => $host,
            'is_primary' => $primary,
            'verified_at' => $verified ? now() : null,
        ]);

        return $shop;
    }

    private function actingAsStudioOnStudioPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('studio'));
        $this->actingAs($this->studioUser());
    }

    public function test_a_known_primary_domain_resolves_to_its_shop(): void
    {
        $this->shopWithDomain();

        $this->getJson('/api/v1/_storefront/host/client-a.com')
            ->assertOk()
            ->assertExactJson(['data' => [
                'slug' => 'client-a',
                'name' => 'Client A',
                'host' => 'client-a.com',
                'is_primary' => true,
                'primary_host' => 'client-a.com',
            ]]);
    }

    public function test_a_secondary_domain_resolves_and_names_the_primary(): void
    {
        $shop = $this->shopWithDomain();
        $shop->domains()->create(['host' => 'www.client-a.com', 'is_primary' => false, 'verified_at' => now()]);

        $this->getJson('/api/v1/_storefront/host/www.client-a.com')
            ->assertOk()
            ->assertJsonPath('data.slug', 'client-a')
            ->assertJsonPath('data.is_primary', false)
            ->assertJsonPath('data.primary_host', 'client-a.com');
    }

    public function test_primary_host_falls_back_to_the_requested_host_when_the_primary_is_unverified(): void
    {
        // A redirect target must never be a dead domain: if the flagged primary
        // is not itself verified, the requesting host is its own canonical.
        $shop = $this->shopWithDomain(host: 'client-a.com', verified: false);
        $shop->domains()->create(['host' => 'live.client-a.com', 'is_primary' => false, 'verified_at' => now()]);

        $this->getJson('/api/v1/_storefront/host/live.client-a.com')
            ->assertOk()
            ->assertJsonPath('data.primary_host', 'live.client-a.com');
    }

    public function test_unknown_unverified_and_inactive_hosts_are_the_same_generic_404(): void
    {
        // The guarantee is a production-shaped identical body. Debug bodies carry
        // an exception trace whose frames include the CALLER's line, so they can
        // never be byte-identical across three call sites — turn debug off and
        // compare what production actually serves.
        config(['app.debug' => false]);

        $this->shopWithDomain(slug: 'client-a', host: 'unverified.example', verified: false);
        $this->shopWithDomain(slug: 'client-b', host: 'inactive.example', active: false);

        $unknown = $this->getJson('/api/v1/_storefront/host/nobody.example');
        $unverified = $this->getJson('/api/v1/_storefront/host/unverified.example');
        $inactive = $this->getJson('/api/v1/_storefront/host/inactive.example');

        $unknown->assertNotFound();
        $unverified->assertNotFound();
        $inactive->assertNotFound();
        $this->assertSame($unknown->json(), $unverified->json());
        $this->assertSame($unknown->json(), $inactive->json());
    }

    public function test_resolution_lowercases_the_requested_host_and_keeps_the_port(): void
    {
        $this->shopWithDomain(host: 'localhost:3001');

        $this->getJson('/api/v1/_storefront/host/LOCALHOST:3001')
            ->assertOk()
            ->assertJsonPath('data.host', 'localhost:3001');
    }

    public function test_host_normalization_rules(): void
    {
        $this->assertSame('client-a.com', ShopDomain::normalizeHost('HTTPS://Client-A.COM/shop'));
        $this->assertSame('client-a.com', ShopDomain::normalizeHost('client-a.com.'));
        $this->assertSame('localhost:3001', ShopDomain::normalizeHost(' LOCALHOST:3001 '));
        // www is never stripped — an alias is its own explicit row, secondary to the apex
        $this->assertSame('www.client-a.com', ShopDomain::normalizeHost('www.Client-A.com'));
    }

    public function test_the_model_normalizes_the_host_on_save(): void
    {
        $shop = Shop::create(['slug' => 'client-a', 'name' => 'Client A', 'status' => 'live']);
        $domain = $shop->domains()->create([
            'host' => 'HTTPS://Client-A.COM/',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->assertSame('client-a.com', $domain->fresh()->host);
    }

    public function test_a_host_can_only_belong_to_one_shop(): void
    {
        $this->shopWithDomain();
        $other = Shop::create(['slug' => 'client-b', 'name' => 'Client B', 'status' => 'live']);

        $this->expectException(UniqueConstraintViolationException::class);

        // normalization collides it into the row client-a already owns
        $other->domains()->create(['host' => 'CLIENT-A.com', 'is_primary' => true, 'verified_at' => now()]);
    }

    public function test_a_shop_can_hold_only_one_primary_domain(): void
    {
        $shop = $this->shopWithDomain();

        $this->expectException(UniqueConstraintViolationException::class);

        $shop->domains()->create(['host' => 'second.example', 'is_primary' => true, 'verified_at' => now()]);
    }

    public function test_secondary_domains_do_not_trip_the_primary_constraint(): void
    {
        $shop = $this->shopWithDomain();
        $shop->domains()->create(['host' => 'www.client-a.com', 'is_primary' => false, 'verified_at' => now()]);
        $shop->domains()->create(['host' => 'old.client-a.com', 'is_primary' => false, 'verified_at' => null]);

        $this->assertSame(3, $shop->domains()->count());
    }

    public function test_a_verified_shop_domain_becomes_an_allowed_cors_origin(): void
    {
        $this->shopWithDomain();

        $this->getJson('/api/v1/decant-please/brands', ['Origin' => 'https://client-a.com'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://client-a.com');
    }

    public function test_a_preflight_from_a_shop_domain_is_accepted(): void
    {
        $this->shopWithDomain();

        $this->options('/api/v1/decant-please/orders', [], [
            'Origin' => 'https://client-a.com',
            'Access-Control-Request-Method' => 'POST',
        ])
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://client-a.com');
    }

    public function test_unregistered_unverified_and_inactive_origins_are_never_granted_cors(): void
    {
        // A verified control domain alongside the env default puts the allowlist
        // in multi-origin mode, where php-cors matches per request (its
        // single-origin mode emits the one configured origin unconditionally and
        // lets the browser enforce the mismatch — correct, but it would make a
        // header-missing assertion meaningless here).
        $this->shopWithDomain();
        $this->shopWithDomain(slug: 'client-b', host: 'unverified.example', verified: false);
        $this->shopWithDomain(slug: 'client-c', host: 'inactive.example', active: false);

        $this->getJson('/api/v1/decant-please/brands', ['Origin' => 'https://client-a.com'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://client-a.com');

        foreach (['https://evil.example', 'https://unverified.example', 'https://inactive.example'] as $origin) {
            $this->getJson('/api/v1/decant-please/brands', ['Origin' => $origin])
                ->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    public function test_the_env_frontend_url_platform_default_still_passes_cors(): void
    {
        $this->getJson('/api/v1/decant-please/brands', ['Origin' => 'http://localhost:3001'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001');
    }

    public function test_the_cors_allowlist_picks_up_a_new_domain_within_the_process(): void
    {
        // Two requests in one process, cache deliberately NOT cleared between them —
        // the model's saved-hook bust plus the per-request config merge is what
        // must make the second request pass (the step-32 cache lesson). Before the
        // row exists the origin must not be GRANTED (php-cors's single-origin mode
        // still emits the env default unconditionally, so absent-or-different is
        // the right claim, not absent).
        $before = $this->getJson('/api/v1/decant-please/brands', ['Origin' => 'https://client-a.com']);
        $this->assertNotSame('https://client-a.com', $before->headers->get('Access-Control-Allow-Origin'));

        $this->shopWithDomain();

        $this->getJson('/api/v1/decant-please/brands', ['Origin' => 'https://client-a.com'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://client-a.com');
    }

    public function test_the_origin_scheme_rule(): void
    {
        $this->assertSame('http://localhost:3001', ShopDomain::originFor('localhost:3001'));
        $this->assertSame('http://client-a.decant.localhost:3001', ShopDomain::originFor('client-a.decant.localhost:3001'));
        $this->assertSame('http://127.0.0.1:3001', ShopDomain::originFor('127.0.0.1:3001'));
        $this->assertSame('https://client-a.com', ShopDomain::originFor('client-a.com'));
    }

    public function test_the_host_resolve_limiter_keys_by_ip_without_a_tenant(): void
    {
        // This endpoint runs BEFORE any tenant exists, so the per-shop limiter
        // helper would throw here — its bucket is deliberately IP-only.
        $limiter = RateLimiter::limiter('host-resolve');
        $this->assertNotNull($limiter);

        $limit = $limiter(Request::create('/api/v1/_storefront/host/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

        $this->assertSame('host-resolve|203.0.113.9', $limit->key);
    }

    public function test_storefront_url_prefers_the_verified_primary_domain(): void
    {
        $shop = $this->shopWithDomain();
        $this->assertSame('https://client-a.com', $shop->storefrontUrl());

        $local = $this->shopWithDomain(slug: 'client-b', host: 'localhost:3001');
        $this->assertSame('http://localhost:3001', $local->storefrontUrl());

        // an unverified primary is not a storefront yet — fall back to the platform default
        $pending = $this->shopWithDomain(slug: 'client-c', host: 'pending.example', verified: false);
        $this->assertSame(rtrim(config('app.frontend_url'), '/'), $pending->storefrontUrl());
    }

    public function test_the_studio_manages_a_shops_domains_through_the_table_action(): void
    {
        $shop = Shop::create(['slug' => 'client-a', 'name' => 'Client A', 'status' => 'live']);
        $this->actingAsStudioOnStudioPanel();

        Livewire::test(ManageShops::class)
            ->callTableAction('domains', $shop, data: ['domains' => [
                ['host' => 'HTTPS://Client-A.COM/', 'is_primary' => true, 'verified' => true],
                ['host' => 'www.client-a.com', 'is_primary' => false, 'verified' => false],
            ]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            [['client-a.com', true, true], ['www.client-a.com', false, false]],
            $shop->domains()->orderByDesc('is_primary')->orderBy('host')->get()
                ->map(fn (ShopDomain $domain): array => [
                    $domain->host,
                    $domain->is_primary,
                    $domain->verified_at !== null,
                ])->all(),
        );
    }

    public function test_sync_domains_is_replace_set_a_dropped_row_is_deleted(): void
    {
        // Direct model-level call: Livewire's test helper MERGES action data over
        // the fillForm state, so a shorter set can't be expressed through
        // callTableAction — the action is a one-line delegate to this method,
        // which is where the semantics live.
        $shop = $this->shopWithDomain();
        $shop->domains()->create(['host' => 'www.client-a.com', 'is_primary' => false, 'verified_at' => now()]);

        $shop->syncDomains([
            ['host' => 'client-a.com', 'is_primary' => true, 'verified' => true],
        ]);

        $this->assertSame(['client-a.com'], $shop->domains()->pluck('host')->all());
    }

    public function test_sync_domains_promotes_the_first_row_when_none_is_primary(): void
    {
        $shop = Shop::create(['slug' => 'client-a', 'name' => 'Client A', 'status' => 'live']);

        $shop->syncDomains([
            ['host' => 'client-a.com', 'is_primary' => false, 'verified' => true],
            ['host' => 'www.client-a.com', 'is_primary' => false, 'verified' => true],
        ]);

        $this->assertSame(
            ['client-a.com'],
            $shop->domains()->where('is_primary', true)->pluck('host')->all(),
        );
    }

    public function test_sync_domains_cannot_take_a_host_owned_by_another_shop(): void
    {
        $this->shopWithDomain(); // client-a owns client-a.com
        $other = Shop::create(['slug' => 'client-b', 'name' => 'Client B', 'status' => 'live']);

        try {
            $other->syncDomains([
                ['host' => 'client-a.com', 'is_primary' => true, 'verified' => true],
            ]);
            $this->fail('Expected the cross-shop host to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('domains', $exception->errors());
        }

        $this->assertSame(0, $other->domains()->count());
        $this->assertSame(
            'client-a',
            ShopDomain::query()->with('shop')->where('host', 'client-a.com')->first()->shop->slug,
        );
    }
}
