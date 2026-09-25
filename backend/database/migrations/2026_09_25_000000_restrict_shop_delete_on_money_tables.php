<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money records outlive a shop delete (issue #112). Step A installed every tenant
 * `shop_id` FK as cascadeOnDelete, so deleting a `shops` row would silently destroy
 * that shop's orders, order lines and expenses. That was safe only by policy (shops
 * are archived, never hard-deleted). This makes the database guarantee it: a shop
 * that still holds financial history cannot be deleted at all — it can only be
 * archived.
 *
 * Why these three tables and not the rest:
 *   - orders       deposit / fees / discount / total / courier float
 *   - order_items  frozen unit/line price and cost snapshots
 *   - expenses     amount_mmk, feeds the P&L
 * Catalog and config (brands, fragrances, decant_prices, promo_codes, shop_settings,
 * delivery_*) are regenerable configuration, not financial records, so they stay
 * cascade — a shop with no money history still deletes cleanly. shop_user /
 * shop_domains stay cascade (membership and routing), studio_audit_events stays
 * SET NULL (audit rows outlive their shop).
 *
 * Same default constraint name both ways, so up and down are a symmetric swap.
 * down() is a plain revert to cascade; it deletes nothing.
 */
return new class extends Migration
{
    private array $tables = ['orders', 'order_items', 'expenses'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['shop_id']);
                $table->foreign('shop_id')->references('id')->on('shops')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['shop_id']);
                $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            });
        }
    }
};
