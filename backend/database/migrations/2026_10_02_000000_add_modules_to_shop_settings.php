<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 41: shop_settings.modules — the shop's enabled optional features
 * (App\Support\Modules). Null means "the shop template's defaults", so every
 * existing shop keeps exactly what it sees today with no backfill; the admin's
 * Features page stores the full enabled set once the seller saves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->jsonb('modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', fn (Blueprint $table) => $table->dropColumn('modules'));
    }
};
