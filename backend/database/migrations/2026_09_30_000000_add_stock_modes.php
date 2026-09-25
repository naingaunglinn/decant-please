<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 40: two stock modes, chosen by the product's template (Template::stockMode()).
 *
 * - Pooled (decant): one running amount per product, drawn down by each line's
 *   size × quantity. products.stock_ml → stock_amount and low_stock_threshold_ml →
 *   low_stock_threshold — renames only, every stored value untouched. The unit is ml
 *   today; weight units (kyatthar / viss) and a stored unit arrive in step 40b.
 * - Per variant (clothing): product_variants.stock_qty (pieces; null = not tracked)
 *   and unit_cost_mmk (what one costs the seller; null = unknown, never 0), which
 *   the order line snapshots as its cost.
 *
 * A per-variant product's reorder line was the ml default (30), which in pieces
 * would flag every size the moment the seller starts counting. No variant is
 * tracked before this migration, so it is set to 2 for those products — a setting,
 * not a recorded figure.
 *
 * down() refuses while any variant carries stock or a cost, rather than drop what
 * the seller entered, and puts those reorder lines back to 30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('stock_ml', 'stock_amount');
            $table->renameColumn('low_stock_threshold_ml', 'low_stock_threshold');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('stock_qty')->nullable();
            $table->unsignedInteger('unit_cost_mmk')->nullable();
        });

        DB::table('products')->where('template', '!=', 'decant')->update(['low_stock_threshold' => 2]);
    }

    public function down(): void
    {
        if (DB::table('product_variants')->whereNotNull('stock_qty')->orWhereNotNull('unit_cost_mmk')->exists()) {
            throw new RuntimeException('Variants carry stock or a cost — clear them before rolling back step 40.');
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['stock_qty', 'unit_cost_mmk']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('stock_amount', 'stock_ml');
            $table->renameColumn('low_stock_threshold', 'low_stock_threshold_ml');
        });

        DB::table('products')->where('template', '!=', 'decant')->update(['low_stock_threshold_ml' => 30]);
    }
};
