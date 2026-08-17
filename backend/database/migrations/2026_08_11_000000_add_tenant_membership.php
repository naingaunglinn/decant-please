<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy Step 25a: who may operate which shop. A `shop_user` pivot records a
 * shop owner's membership; an `is_studio` flag marks the founder accounts that see
 * every shop (the studio operator, AGENTS.md §1). Filament tenancy reads both via the
 * User's HasTenants implementation.
 *
 * Existing users are all the decanter's own admin today (§8), so they backfill as
 * studio accounts — they must not lose access when tenancy turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['shop_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_studio')->default(false)->index();
        });

        // Every current user is the studio founder — keep their all-shops access.
        DB::table('users')->update(['is_studio' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_studio');
        });

        Schema::dropIfExists('shop_user');
    }
};
