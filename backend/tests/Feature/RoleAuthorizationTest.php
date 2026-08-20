<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Step 34 §2 — Shield authorization. Roles are global capabilities; WHICH shop is
 * still membership + canAccessTenant + BelongsToShop. Shield answers "may this
 * user perform this action?"; it never widens the tenant-scoped record set.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function defaultShop(): Shop
    {
        return Shop::where('slug', config('app.shop_slug'))->firstOrFail();
    }

    /** A member of the default (tenant) shop, holding the given capability role. */
    private function operator(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => "{$role}@example.test",
            'password' => 'secret-password',
            'is_studio' => false,
        ]);
        $user->shops()->attach($this->defaultShop());
        $user->assignRole($role);

        return $user;
    }

    // ---- studio_admin (Shield super_admin) ---------------------------------

    public function test_studio_admin_can_reach_the_studio_panel(): void
    {
        $this->actingAs($this->studioUser());

        $this->get('/studio/shops')->assertSuccessful();
    }

    public function test_studio_admin_has_super_admin_behavior_across_resources(): void
    {
        $studio = $this->studioUser();

        // No explicit permissions on the role — every ability resolves via the
        // Gate::before bypass.
        $this->assertTrue($studio->can('Create:Order'));
        $this->assertTrue($studio->can('DeleteAny:Fragrance'));
        $this->assertTrue($studio->can('Create:Shop'));
    }

    // ---- shop_owner --------------------------------------------------------

    public function test_shop_owner_can_operate_its_shops_resources(): void
    {
        $this->actingAs($this->operator('shop_owner'));

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Chanel', 'type' => 'designer'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('brands', ['name' => 'Chanel']);
    }

    public function test_shop_owner_cannot_reach_the_studio_panel(): void
    {
        $this->actingAs($this->operator('shop_owner'));

        $this->get('/studio/shops')->assertForbidden();
    }

    // ---- shop_staff (intentionally narrow) ---------------------------------

    public function test_shop_staff_can_view_but_not_create(): void
    {
        Brand::create(['name' => 'Dior', 'type' => 'designer']);
        $staff = $this->operator('shop_staff');
        $this->actingAs($staff);

        // has ViewAny:Brand
        Livewire::test(ListBrands::class)->assertOk();

        // lacks Create:Brand
        $this->assertFalse($staff->can('Create:Brand'));
        $this->assertFalse($staff->can('Delete:Brand'));
    }

    public function test_shop_staff_cannot_reach_the_studio_panel(): void
    {
        $this->actingAs($this->operator('shop_staff'));

        $this->get('/studio/shops')->assertForbidden();
    }

    // ---- authorization never widens tenant visibility ----------------------

    public function test_super_admin_authorization_does_not_bypass_belongs_to_shop(): void
    {
        // A brand in the current (default) shop, and one in another shop.
        $mine = Brand::create(['name' => 'Chanel', 'type' => 'designer']);

        $other = Shop::factory()->create(['slug' => 'other-shop']);
        app(TenantContext::class)->set($other);
        Brand::create(['name' => 'Creed', 'type' => 'niche']);
        app(TenantContext::class)->set($this->defaultShop());

        $this->actingAs($this->studioUser()); // super_admin — may do anything

        // …yet the table shows only the current shop's row: the policy authorizes
        // the action, BelongsToShop still scopes the records.
        Livewire::test(ListBrands::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCountTableRecords(1);
    }

    // ---- membership still controls tenant access ---------------------------

    public function test_a_shop_owner_of_one_shop_cannot_enter_another(): void
    {
        $this->operator('shop_owner'); // member of the default shop
        $other = Shop::factory()->create(['slug' => 'other-shop']);

        $this->actingAs(User::where('email', 'shop_owner@example.test')->firstOrFail());

        // canAccessTenant fails for a non-member shop → IdentifyTenant 404s.
        $this->get("/admin/{$other->slug}")->assertNotFound();
    }

    // ---- migration backfill (deploy-safe, idempotent, no lockout) -----------

    public function test_the_role_migration_backfills_existing_users_idempotently(): void
    {
        // Bare users via User::create bypass the factory's is_studio → role hook,
        // reproducing pre-migration production rows.
        $studio = User::create(['name' => 'S', 'email' => 's@x.test', 'password' => 'x', 'is_studio' => true]);
        $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'x', 'is_studio' => false]);
        $owner->shops()->attach($this->defaultShop());

        $this->assertFalse($studio->hasRole('studio_admin'));
        $this->assertFalse($owner->hasRole('shop_owner'));

        $migration = require database_path('migrations/2026_08_22_000000_seed_shield_roles.php');
        $migration->up();
        $migration->up(); // idempotent — running twice must not duplicate

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($studio->fresh()->hasRole('studio_admin'), 'studio operator must not be locked out');
        $this->assertTrue($owner->fresh()->hasRole('shop_owner'));
        $this->assertCount(1, $studio->fresh()->roles);
        $this->assertCount(1, $owner->fresh()->roles);
    }
}
