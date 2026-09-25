<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 44b: a self-serve seller signs up with a verified phone. `users` is a
 * platform table (no shop_id, no BelongsToShop). Unique, so one SIM verifies one
 * account — the phone is the gate against spam shops. Nullable: studio accounts
 * and Studio-registered owners have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // the index before the column — SQLite errors on dropping an indexed column
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
