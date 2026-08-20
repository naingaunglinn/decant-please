<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Step 34 §2 — Shield roles, as a DATA migration so it runs in Heroku's release
 * phase (and in RefreshDatabase tests) atomically with the schema: there is never
 * a window where policies enforce but `studio_admin` doesn't exist, so existing
 * operators can't be locked out.
 *
 * Non-team: roles/permissions are global (spatie teams stay off). Shield's
 * `shield:generate` produces the same permission records in dev, but that is a
 * manual command; this migration reproduces the deterministic `{Ability}:{Model}`
 * names itself so production/CI never depend on it. All operations are idempotent.
 *
 * `studio_admin` is Shield's super_admin (Gate::before grants every ability), so
 * it carries no explicit permissions. Shop-scoping is unchanged — a shop_owner /
 * shop_staff role is a capability set; WHICH shop is still `shop_user` membership
 * + canAccessTenant() + BelongsToShop.
 */
return new class extends Migration
{
    private const ABILITIES = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    /** Resources a shop owner operates in their own /admin panel. */
    private const OPERATIONAL_MODELS = ['Brand', 'DeliveryTownship', 'Expense', 'Fragrance', 'Order', 'PromoCode'];

    /** Studio-only resources (registry, role management) — studio_admin via super_admin. */
    private const STUDIO_MODELS = ['Shop', 'Role'];

    /** Intentionally narrow (Step 34: real staff needs are deferred) — read-only visibility. */
    private const STAFF_PERMISSIONS = [
        'ViewAny:Order', 'View:Order', 'ViewAny:Fragrance', 'View:Fragrance', 'ViewAny:Brand', 'View:Brand',
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // 1. Ensure every resource permission exists (idempotent; guard-aligned).
        $operational = [];
        foreach (self::OPERATIONAL_MODELS as $model) {
            foreach (self::ABILITIES as $ability) {
                $operational[] = Permission::findOrCreate("{$ability}:{$model}", $guard)->name;
            }
        }
        foreach (self::STUDIO_MODELS as $model) {
            foreach (self::ABILITIES as $ability) {
                Permission::findOrCreate("{$ability}:{$model}", $guard);
            }
        }

        // 2. Roles. studio_admin = super_admin (no explicit permissions needed).
        Role::findOrCreate('studio_admin', $guard);
        $owner = Role::findOrCreate('shop_owner', $guard);
        $staff = Role::findOrCreate('shop_staff', $guard);

        // 3. shop_owner runs its whole shop; shop_staff gets the narrow read set.
        $owner->givePermissionTo($operational);
        $staff->givePermissionTo(self::STAFF_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 4. Backfill existing users (no lockout): studio operators → studio_admin,
        //    shop owners (non-studio with a membership) → shop_owner. In a fresh
        //    test DB there are no users yet, so this is a harmless no-op there.
        User::query()->where('is_studio', true)->get()
            ->each(fn (User $u) => $u->assignRole('studio_admin'));

        User::query()->where('is_studio', false)->whereHas('shops')->get()
            ->each(fn (User $u) => $u->assignRole('shop_owner'));
    }

    public function down(): void
    {
        foreach (['shop_owner', 'shop_staff'] as $name) {
            Role::where('name', $name)->where('guard_name', config('auth.defaults.guard', 'web'))->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
