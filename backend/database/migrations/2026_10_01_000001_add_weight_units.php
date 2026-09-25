<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 40b: pooled stock by weight (kyatthar; a viss is 100 of them, display only).
 *
 * - products.stock_unit: the unit stock_amount, low_stock_threshold and
 *   reference_amount are in, for a pooled product. Every product that was ever
 *   pooled is ml — decant, or any product carrying an ml figure from before step
 *   40 — so those get 'ml'; a per-variant product with no pooled figure keeps null.
 * - order_items.measure: how much of the pooled stock one unit of the line draws,
 *   frozen at write like the price. size_ml is named for ml, and the variant's
 *   measure is live. Backfilled from size_ml, the only amount a line has had.
 *
 * No recorded value moves. down() refuses while a product is weighed or a line
 * has an amount with no ml size, rather than drop what can't be put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('stock_unit', 16)->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('measure')->nullable();
        });

        DB::table('products')
            ->where(fn ($query) => $query
                ->where('template', 'decant')
                ->orWhereNotNull('stock_amount')
                ->orWhereNotNull('reference_cost_mmk')
                ->orWhereNotNull('reference_amount'))
            ->update(['stock_unit' => 'ml']);

        DB::table('order_items')->whereNotNull('size_ml')->update(['measure' => DB::raw('size_ml')]);
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('stock_unit')->where('stock_unit', '!=', 'ml')->exists()
            || DB::table('order_items')->whereNotNull('measure')->whereNull('size_ml')->exists()) {
            throw new RuntimeException('Products or order lines are counted by weight — step 40b can\'t be rolled back without losing them.');
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('measure');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_unit');
        });
    }
};
