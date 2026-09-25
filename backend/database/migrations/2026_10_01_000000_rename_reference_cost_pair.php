<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 40b: the pooled reference purchase is not always a bottle of ml. Renames
 * only — every stored value untouched: bottle_cost_mmk → reference_cost_mmk,
 * bottle_volume_ml → reference_amount (in the product's stock_unit, the next
 * migration). Decant's admin still calls them "Bottle cost" and "Bottle size".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('bottle_cost_mmk', 'reference_cost_mmk');
            $table->renameColumn('bottle_volume_ml', 'reference_amount');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('reference_cost_mmk', 'bottle_cost_mmk');
            $table->renameColumn('reference_amount', 'bottle_volume_ml');
        });
    }
};
