<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Exceptions\ReadOnlyImpersonationException;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Livewire\ImpersonationBanner;
use App\Models\Brand;
use App\Models\Shop;
use App\Models\StudioAuditEvent;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Filament\Events\TenantSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 34 §3 — impersonation audit. "Impersonation" = a studio_admin operating a shop
 * panel that isn't theirs. Read-only by default; an explicit, audited take-control
 * lifts it; every panel entry and every controlled write is recorded. None of it is a
 * tenant boundary — BelongsToShop still isolates records (proven below).
 */
class ImpersonationAuditTest extends TestCase
{
    use RefreshDatabase;

    /** A studio operator (studio_admin, Shield super_admin) who belongs to no shop. */
    private function studioOperator(): User
    {
        return User::factory()->create(['is_studio' => true]);
    }

    private function foreignShop(string $slug): Shop
    {
        return Shop::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' Scents']);
    }

    /** Simulate entering a shop's /admin panel: auth + tenant + Filament's TenantSet. */
    private function enter(User $operator, Shop $shop): void
    {
        $this->actingAs($operator);
        app(TenantContext::class)->set($shop);
        event(new TenantSet($shop, $operator));
    }

    /**
     * Seed a tenant-owned record into a shop WITHOUT impersonation — called before any
     * enter()/actingAs(), so there is no authenticated operator and the guard is
     * inactive. The tenant is set so BelongsToShop stamps shop_id.
     */
    private function seedBrandIn(Shop $shop, string $name): Brand
    {
        app(TenantContext::class)->set($shop);

        return Brand::create(['name' => $name, 'type' => 'designer']);
    }

    private function assertBlocked(callable $write): void
    {
        try {
            $write();
            $this->fail('Expected the write to be blocked by read-only impersonation.');
        } catch (ReadOnlyImpersonationException) {
            // expected
        }
    }

    // ---- panel entry --------------------------------------------------------

    public function test_entering_a_foreign_shop_logs_exactly_one_panel_enter(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');

        $this->enter($op, $shop);

        $events = StudioAuditEvent::where('action', AuditAction::PanelEnter)->get();
        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->actor->is($op));
        $this->assertSame($shop->id, $events->first()->shop_id);
    }

    public function test_repeated_tenant_set_events_do_not_duplicate_the_entry(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');

        $this->enter($op, $shop);
        event(new TenantSet($shop, $op));
        event(new TenantSet($shop, $op));

        $this->assertSame(1, StudioAuditEvent::where('action', AuditAction::PanelEnter)->count());
    }

    public function test_switching_shops_logs_one_new_entry(): void
    {
        $op = $this->studioOperator();

        $this->enter($op, $this->foreignShop('alpha'));
        $this->enter($op, $this->foreignShop('beta'));

        $this->assertSame(2, StudioAuditEvent::where('action', AuditAction::PanelEnter)->count());
    }

    public function test_an_owner_in_their_own_shop_logs_no_impersonation_event(): void
    {
        $shop = $this->foreignShop('alpha');
        $owner = User::factory()->create(['is_studio' => false]);
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');

        $this->enter($owner, $shop);

        $this->assertSame(0, StudioAuditEvent::count());
    }

    // ---- read-only by default ----------------------------------------------

    public function test_impersonation_is_read_only_by_default(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');

        $this->enter($op, $shop);

        $imp = app(Impersonation::class);
        $this->assertTrue($imp->active());
        $this->assertTrue($imp->isReadOnly());
        $this->assertFalse($imp->hasControl());
    }

    public function test_read_only_blocks_create_update_and_delete(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $existing = $this->seedBrandIn($shop, 'Existing');

        $this->enter($op, $shop);

        // create
        $this->assertBlocked(fn () => Brand::create(['name' => 'New', 'type' => 'designer']));
        $this->assertSame(1, Brand::count());

        // update
        $this->assertBlocked(fn () => $existing->update(['name' => 'Renamed']));
        $this->assertSame('Existing', $existing->fresh()->name);

        // delete
        $this->assertBlocked(fn () => $existing->delete());
        $this->assertTrue(Brand::whereKey($existing->getKey())->exists());

        // nothing was audited as a write (the writes never happened)
        $this->assertSame(0, StudioAuditEvent::whereIn('action', ['write.created', 'write.updated', 'write.deleted'])->count());
    }

    public function test_read_only_write_via_a_filament_action_notifies_instead_of_500(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $this->enter($op, $shop);

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Blocked', 'type' => 'designer'])
            ->call('create')
            ->assertNotified();

        $this->assertSame(0, Brand::count());
    }

    // ---- take control -------------------------------------------------------

    public function test_take_control_enables_writes_and_logs_exactly_one_event(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $this->enter($op, $shop);

        Livewire::test(ImpersonationBanner::class)->call('takeControl');

        $this->assertTrue(app(Impersonation::class)->hasControl());
        $this->assertSame(1, StudioAuditEvent::where('action', AuditAction::TakeControl)->count());

        // writes now succeed
        $brand = Brand::create(['name' => 'Allowed', 'type' => 'designer']);
        $this->assertTrue($brand->exists);
    }

    public function test_take_control_is_scoped_to_the_current_shop_and_resets_on_switch(): void
    {
        $op = $this->studioOperator();
        $shopA = $this->foreignShop('alpha');
        $shopB = $this->foreignShop('beta');

        $this->enter($op, $shopA);
        app(Impersonation::class)->takeControl();
        $this->assertTrue(app(Impersonation::class)->hasControl());

        // switch to B → control does NOT carry over
        $this->enter($op, $shopB);
        $this->assertFalse(app(Impersonation::class)->hasControl());
        $this->assertTrue(app(Impersonation::class)->isReadOnly());
        $this->assertBlocked(fn () => Brand::create(['name' => 'InB', 'type' => 'designer']));
    }

    // ---- write audit --------------------------------------------------------

    public function test_each_persisted_row_write_is_audited_once(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $b1 = $this->seedBrandIn($shop, 'One');
        $b2 = $this->seedBrandIn($shop, 'Two');
        $b3 = $this->seedBrandIn($shop, 'Three');

        $this->enter($op, $shop);
        app(Impersonation::class)->takeControl();

        // update three rows → three write.updated events (bulk = N, not one)
        foreach ([$b1, $b2, $b3] as $brand) {
            $brand->update(['name' => $brand->name.' (edited)']);
        }

        $this->assertSame(3, StudioAuditEvent::where('action', AuditAction::WriteUpdated)->count());
    }

    public function test_a_controlled_delete_logs_one_write_deleted_event(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $brand = $this->seedBrandIn($shop, 'Doomed');

        $this->enter($op, $shop);
        app(Impersonation::class)->takeControl();

        $brand->delete();

        $events = StudioAuditEvent::where('action', AuditAction::WriteDeleted)
            ->where('subject_type', Brand::class)
            ->get();
        $this->assertCount(1, $events);
        $this->assertSame($brand->getKey(), $events->first()->subject_id);
    }

    public function test_a_write_audit_captures_actor_shop_action_subject_ip_and_user_agent(): void
    {
        $op = $this->studioOperator();
        $shop = $this->foreignShop('alpha');
        $this->enter($op, $shop);
        app(Impersonation::class)->takeControl();
        request()->headers->set('User-Agent', 'AuditProbe/9.9');

        $brand = Brand::create(['name' => 'Recorded', 'type' => 'designer']);

        $event = StudioAuditEvent::where('action', AuditAction::WriteCreated)->firstOrFail();
        $this->assertSame($op->id, $event->actor_id);
        $this->assertSame($shop->id, $event->shop_id);
        $this->assertSame(AuditAction::WriteCreated, $event->action);
        $this->assertSame(Brand::class, $event->subject_type);
        $this->assertSame($brand->id, $event->subject_id);
        $this->assertNotNull($event->ip_address);
        $this->assertSame('AuditProbe/9.9', $event->user_agent);
    }

    // ---- isolation stays intact --------------------------------------------

    public function test_super_admin_impersonation_does_not_widen_tenant_row_visibility(): void
    {
        $op = $this->studioOperator();
        $shopA = $this->foreignShop('alpha');
        $this->seedBrandIn($shopA, 'A-brand');
        $this->seedBrandIn($this->foreignShop('beta'), 'B-brand');

        $this->enter($op, $shopA);

        // In shop A's context the super-admin sees only shop A's row — BelongsToShop,
        // not authorization, decides the row set.
        $this->assertSame(1, Brand::count());
        $this->assertSame('A-brand', Brand::first()->name);
    }

    public function test_a_controlled_write_lands_in_the_viewed_shop_never_another(): void
    {
        $op = $this->studioOperator();
        $shopX = $this->foreignShop('xray');
        $shopY = $this->foreignShop('yankee');

        $this->enter($op, $shopX);
        app(Impersonation::class)->takeControl();

        $brand = Brand::create(['name' => 'Made in X', 'type' => 'designer']);

        // Stamped to X (the current tenant) by BelongsToShop — never Y.
        $this->assertSame($shopX->id, $brand->shop_id);
        $this->assertNotSame($shopY->id, $brand->shop_id);
        $inY = app(TenantContext::class)->withoutTenancy(
            fn () => Brand::where('name', 'Made in X')->where('shop_id', $shopY->id)->exists()
        );
        $this->assertFalse($inY);
    }

    // ---- Studio audit resource access --------------------------------------

    public function test_studio_admin_can_reach_the_audit_resource(): void
    {
        $this->actingAs($this->studioUser());

        $this->get('/studio/studio-audit-events')->assertSuccessful();
    }

    public function test_shop_owner_cannot_reach_the_audit_resource(): void
    {
        $shop = $this->foreignShop('alpha');
        $owner = User::factory()->create(['is_studio' => false]);
        $owner->shops()->attach($shop);
        $owner->assignRole('shop_owner');
        $this->actingAs($owner);

        $this->get('/studio/studio-audit-events')->assertForbidden();
    }

    public function test_shop_staff_cannot_reach_the_audit_resource(): void
    {
        $shop = $this->foreignShop('alpha');
        $staff = User::factory()->create(['is_studio' => false]);
        $staff->shops()->attach($shop);
        $staff->assignRole('shop_staff');
        $this->actingAs($staff);

        $this->get('/studio/studio-audit-events')->assertForbidden();
    }

    public function test_the_audit_resource_is_unavailable_from_the_admin_panel(): void
    {
        $this->actingAs($this->studioUser());
        $shop = Shop::where('slug', config('app.shop_slug'))->firstOrFail();

        $this->get("/admin/{$shop->slug}/studio-audit-events")->assertNotFound();
    }
}
