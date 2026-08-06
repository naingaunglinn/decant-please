<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost snapshots — IMMUTABLE once written, mirroring unit_price_mmk /
     * line_total_mmk exactly: taken from Fragrance::liquidCostMmk() when the item
     * is created (checkout, manual admin entry, or a line added later), never
     * refreshed from the live reference afterwards — a margin read months later
     * must cost what the pour actually cost.
     *
     * Nullable, and legacy rows stay null forever: null means "cost unknown",
     * excluded and counted by every aggregate, never coalesced to zero. There is
     * deliberately no backfill — a snapshot backfilled from today's reference
     * would be fiction wearing a snapshot's clothes.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('unit_cost_mmk')->nullable()->after('unit_price_mmk');
            $table->unsignedInteger('line_cost_mmk')->nullable()->after('line_total_mmk');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_mmk', 'line_cost_mmk']);
        });
    }
};
