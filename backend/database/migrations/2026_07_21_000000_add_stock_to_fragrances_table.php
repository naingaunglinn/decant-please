<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Total-ml stock tracking, opt-in per fragrance. `stock_ml` is nullable and
     * left null on every existing row — null means "not tracked", so nothing in
     * the seeded/real catalog suddenly reads as low or out of stock when this
     * lands. A fragrance only joins the system once the decanter enters a figure.
     *
     * `unsignedInteger` is documentation here, not a constraint: Laravel's
     * Postgres grammar accepts it but drops the unsignedness (CLAUDE.md §7). The
     * column is only ever server-derived (the decant drawdown) or admin-entered,
     * and every write clamps at 0, so no negative value can reach it.
     */
    public function up(): void
    {
        Schema::table('fragrances', function (Blueprint $table) {
            $table->unsignedInteger('stock_ml')->nullable()->after('is_featured');
            $table->unsignedInteger('low_stock_threshold_ml')->default(30)->after('stock_ml');
        });
    }

    public function down(): void
    {
        Schema::table('fragrances', function (Blueprint $table) {
            $table->dropColumn(['stock_ml', 'low_stock_threshold_ml']);
        });
    }
};
