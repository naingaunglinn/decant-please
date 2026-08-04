<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The decanter's reference purchase cost — LIVE values, not snapshots: what one
     * bottle costs today and the pack size it's bought in, updated by hand on a
     * rebuy. (Order items carry their own immutable cost snapshots — see the
     * order_items cost migration.) Nullable pair = cost not tracked, the stock_ml
     * opt-in pattern — and independent of it: either switch works without the other.
     *
     * Liquid only, by decision: vial, label, and spillage are not in this number.
     *
     * unsignedInteger reads as documentation — Postgres silently drops the
     * constraint (CLAUDE.md §7); non-negativity is enforced in FragranceForm.
     */
    public function up(): void
    {
        Schema::table('fragrances', function (Blueprint $table) {
            $table->unsignedInteger('bottle_cost_mmk')->nullable()->after('low_stock_threshold_ml');
            $table->unsignedInteger('bottle_volume_ml')->nullable()->after('bottle_cost_mmk');
        });
    }

    public function down(): void
    {
        Schema::table('fragrances', function (Blueprint $table) {
            $table->dropColumn(['bottle_cost_mmk', 'bottle_volume_ml']);
        });
    }
};
