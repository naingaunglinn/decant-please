<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
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

    // ---- the is_studio drop migration is rollback-safe (issue #110) ----------

    public function test_rolling_back_the_is_studio_drop_restores_the_flag_from_the_role_only(): void
    {
        // A sibling test used to prove the Step-34 role backfill read is_studio
        // without locking anyone out. That column is gone now, so the deploy-safety
        // guarantee that matters is the reverse: a rollback must rebuild is_studio
        // FROM the studio_admin role, and never hand the role back to a user it was
        // taken from at go-live. RefreshDatabase has already run the drop, so the
        // column is absent here.
        $studio = User::factory()->studio()->create();          // holds studio_admin
        $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'x']);
        $owner->shops()->attach($this->defaultShop());
        $owner->assignRole('shop_owner');

        $this->assertFalse(Schema::hasColumn('users', 'is_studio'));

        $migration = require database_path('migrations/2026_09_24_000000_drop_is_studio_from_users_table.php');

        // Rollback: the column returns, set from the role — studio_admin → true,
        // everyone else → false. Never role-from-flag.
        $migration->down();

        $this->assertTrue(Schema::hasColumn('users', 'is_studio'));
        $this->assertTrue((bool) DB::table('users')->where('id', $studio->id)->value('is_studio'));
        $this->assertFalse((bool) DB::table('users')->where('id', $owner->id)->value('is_studio'));

        // Re-applying the drop is clean.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('users', 'is_studio'));
    }
}
