<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The decanter's expenses — the FINANCE.md fork, taken deliberately
     * (2026-08-04) with its caveat accepted: a P&L is only as true as the
     * willingness to enter every expense, so every P&L figure labels itself
     * "as entered". Integer Kyat; category is an ExpenseCategory backed enum.
     *
     * stock_purchase rows are inventory, not expenses (the no-double-count
     * rule on the enum): v19's margin already expenses juice as COGS when it
     * pours. Plain DATE for spent_on — no timezone math (the v17 lesson).
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on')->index();
            $table->string('category');
            $table->unsignedInteger('amount_mmk');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
