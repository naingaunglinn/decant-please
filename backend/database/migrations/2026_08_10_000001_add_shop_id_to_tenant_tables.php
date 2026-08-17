<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy Step A (prompts/23-multi-tenancy-seam.md §2). Installs the shop_id
 * seam on every tenant-owned table and backfills the single existing shop, so the
 * later routing/onboarding steps are pure addition. Backfill runs in-migration
 * because Heroku's release phase runs `migrate --force` unattended (design-doc N5)
 * and production is already live — this is a migration against small-but-real data,
 * not a disposable seed. Rollback stops being free the moment operator data lands in
 * a second shop's columns.
 */
return new class extends Migration
{
    /** Every tenant-owned table (design-doc §7 + findings A1–A2). */
    private array $tables = [
        'brands', 'fragrances', 'decant_prices', 'orders', 'order_items',
        'promo_codes', 'shop_settings', 'expenses', 'delivery_townships',
        'delivery_township_couriers',
    ];

    public function up(): void
    {
        // The one shop that owns all existing rows. Slug from SHOP_SLUG so it matches
        // Step 24's NEXT_PUBLIC_SHOP_SLUG; idempotent so a re-run can't double-create.
        $slug = env('SHOP_SLUG', 'decant-please');
        $shopId = DB::table('shops')->where('slug', $slug)->value('id')
            ?? DB::table('shops')->insertGetId([
                'slug' => $slug,
                'name' => env('APP_NAME', 'Decant Please!'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // Add nullable shop_id + FK, backfill to the one shop, then enforce NOT NULL.
        // Split into three passes so the backfill sits between "column exists" and
        // "column required" — the ordering the not-null needs.
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        foreach ($this->tables as $t) {
            DB::table($t)->whereNull('shop_id')->update(['shop_id' => $shopId]);
        }

        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('shop_id')->nullable(false)->change();
            });
        }

        // Slug/name/zone uniques become shop-scoped composites. Every decant shop
        // sells "Chanel" and ships to "Bahan", so the old global uniques would kill
        // the second shop's CatalogImport / zone seed mid-onboarding (findings Q5/A2).
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['slug']);
            $table->unique(['shop_id', 'name']);
            $table->unique(['shop_id', 'slug']);
        });

        Schema::table('fragrances', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['shop_id', 'slug']);
        });

        Schema::table('delivery_townships', function (Blueprint $table) {
            $table->dropUnique('township_region_name_unique');
            $table->unique(['shop_id', 'region', 'name'], 'township_shop_region_name_unique');
        });

        // One settings row per shop — the v14 singleton, now per-tenant (design-doc §7).
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        // Drop composite uniques (they reference shop_id) before the column goes.
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'name']);
            $table->dropUnique(['shop_id', 'slug']);
        });
        Schema::table('fragrances', fn (Blueprint $table) => $table->dropUnique(['shop_id', 'slug']));
        Schema::table('delivery_townships', fn (Blueprint $table) => $table->dropUnique('township_shop_region_name_unique'));
        Schema::table('shop_settings', fn (Blueprint $table) => $table->dropUnique(['shop_id']));

        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shop_id');
            });
        }

        // Restore the global uniques this migration replaced.
        Schema::table('brands', function (Blueprint $table) {
            $table->unique('name');
            $table->unique('slug');
        });
        Schema::table('fragrances', fn (Blueprint $table) => $table->unique('slug'));
        Schema::table('delivery_townships', fn (Blueprint $table) => $table->unique(['region', 'name'], 'township_region_name_unique'));
    }
};
