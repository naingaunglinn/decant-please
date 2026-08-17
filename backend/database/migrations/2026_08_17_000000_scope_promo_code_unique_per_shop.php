<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy remediation: promo_codes.code was the one tenant-owned natural key
 * the Step 23 composite pass missed — still globally unique, so two shops could not
 * both run "SUMMER2026". Same reasoning as brands/fragrances/townships (findings
 * Q5/A2): the code is namespaced per shop by the /api/v1/{shop} path and the scoped
 * evaluate(), so uniqueness belongs to (shop_id, code).
 *
 * Upgrading cannot collide: rows satisfying the old global unique trivially satisfy
 * the per-shop composite. Rolling back CAN collide once two shops share a code —
 * like the rest of the seam, down() is only free before cross-shop data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['shop_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'code']);
            $table->unique(['code']);
        });
    }
};
