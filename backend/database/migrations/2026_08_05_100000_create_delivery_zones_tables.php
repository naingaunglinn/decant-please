<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery zones (step 30): the destination becomes structured data and the
     * fee derives from it. Region is a PHP enum (App\Enums\Region), stored as a
     * string; townships are rows; courier coverage/cost is a child table in the
     * DecantPrice shape. Orders gain a nullable township FK plus snapshot
     * columns — legacy orders keep them all null (null means unknown, the
     * v8/v19 rule; no backfill). Per §7, read unsignedInteger as documentation:
     * Postgres drops the constraint, and non-negativity is application-layer.
     */
    public function up(): void
    {
        Schema::create('delivery_townships', function (Blueprint $table) {
            $table->id();
            $table->string('region')->index();
            $table->string('district')->nullable()->index();
            $table->string('name');
            $table->string('name_mm')->nullable();
            $table->unsignedInteger('fee_mmk')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['region', 'name'], 'township_region_name_unique');
        });

        Schema::create('delivery_township_couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_township_id')->constrained()->cascadeOnDelete();
            $table->string('courier');
            // That courier's own spelling of this destination — the alias that
            // lets a Bee statement reconcile against a RoyalX statement against
            // the order list. Never shown to customers.
            $table->string('courier_name');
            // Reference only: never enters fee_mmk, totals, margins, or the P&L.
            // Null = unknown, never 0.
            $table->unsignedInteger('cost_mmk')->nullable();
            // Suspended (route closed, fee kept) is not unserved (no row at all).
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['delivery_township_id', 'courier'], 'township_courier_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            // nullOnDelete + snapshots: deleting or renaming a township must
            // never rewrite where a placed order was going.
            $table->foreignId('delivery_township_id')->nullable()
                ->constrained('delivery_townships')->nullOnDelete();
            $table->string('region_snapshot')->nullable();
            $table->string('township_snapshot')->nullable();
            $table->text('address_line')->nullable();
            $table->text('address_extra')->nullable();
            // Who actually carried it, recorded at Accept — a snapshot value,
            // deliberately no FK to a rate row and no cost copied.
            $table->string('delivery_courier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_township_id');
            $table->dropColumn(['region_snapshot', 'township_snapshot', 'address_line', 'address_extra', 'delivery_courier']);
        });

        Schema::dropIfExists('delivery_township_couriers');
        Schema::dropIfExists('delivery_townships');
    }
};
