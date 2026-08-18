<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0004 (accepted as amended, #88): the storefront-host → shop mapping the shared
 * storefront deployment resolves against. Platform-owned like `shops` itself — no
 * BelongsToShop trait, because this table is what PRODUCES the tenant, so it must be
 * readable before any tenant context exists. Its FK targets the tenant root, which
 * the no-cross-shop-FK rule permits (nothing here references another shop's rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->index()->constrained()->cascadeOnDelete();
            // Normalized lowercase host, port kept — dev is `localhost:3001`.
            // Globally unique: one host can only ever belong to one shop.
            $table->string('host')->unique();
            $table->boolean('is_primary')->default(false);
            // null = the row never resolves on the storefront and never enters the
            // CORS allowlist. Set from the Studio once DNS actually points here.
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // At most one primary domain per shop, enforced by the engine. Partial
        // indexes exist on both Postgres 17 and the SQLite the suite runs on.
        DB::statement('create unique index shop_domains_one_primary_per_shop on shop_domains (shop_id) where is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_domains');
    }
};
