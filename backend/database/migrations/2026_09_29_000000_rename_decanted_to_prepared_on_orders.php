<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 39: the order state after "pending" is "prepared" for every category —
 * decanted, packed, baked are its labels, and the shop's template supplies them.
 *
 * - orders.status 'decanted' → 'prepared' (a plain string column; no check constraint).
 * - orders.decant_date → prep_date. The index keeps its old name
 *   (orders_decant_date_index) — harmless, and renaming it buys nothing.
 *
 * DB::table, not Eloquent: the tenant scope throws in a migration, and this touches
 * every shop's rows by design. Only the status string and the column name move — no
 * money, date or snapshot value changes. down() reverses both exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('decant_date', 'prep_date');
        });

        DB::table('orders')->where('status', 'decanted')->update(['status' => 'prepared']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'prepared')->update(['status' => 'decanted']);

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('prep_date', 'decant_date');
        });
    }
};
