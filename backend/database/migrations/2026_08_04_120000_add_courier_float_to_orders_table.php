<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * COD float: cash a courier has collected but not yet handed over — a real
     * asset outside the business, previously invisible.
     *
     * courier_carrying_mmk is a SNAPSHOT taken at handoff (default: the balance
     * due at that moment, editable in the action) — deliberately not derived
     * live: marking the order paid must not shrink the float before the cash
     * physically arrives, and summing recorded snapshots sidesteps the
     * payment_method conflation entirely (FINANCE.md gap 3 hazard). Plain DATE
     * columns, no timezones (the v17 lesson).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('handed_to_courier_at')->nullable()->after('delivery_date');
            $table->unsignedInteger('courier_carrying_mmk')->nullable()->after('handed_to_courier_at');
            $table->date('courier_settled_at')->nullable()->after('courier_carrying_mmk');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['handed_to_courier_at', 'courier_carrying_mmk', 'courier_settled_at']);
        });
    }
};
