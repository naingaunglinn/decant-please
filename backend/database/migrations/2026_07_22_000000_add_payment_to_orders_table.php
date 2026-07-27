<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment tracking — the Myanmar offline flow made legible, NOT a gateway.
     * Payment still happens outside the system (KBZPay/Wave/bank transfer); this
     * just records whether the decanter has confirmed it, and keeps the customer's
     * transfer screenshot with the order instead of scattered across DMs.
     *
     * - payment_status: 'unpaid' (default, so every existing order reads as unpaid
     *   — accurate, since none were tracked before) or 'paid'.
     * - paid_at: when the decanter confirmed it; null while unpaid.
     * - payment_proof_path: the customer-uploaded transfer screenshot on the media
     *   disk (public locally, R2 in prod) — same store as fragrance images.
     *
     * Deposit stays separate: deposit_mmk is a partial-amount figure on the receipt;
     * this is the yes/no "has the decanter been paid" the seller actually reconciles.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')->index()->after('total_mmk');
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->string('payment_proof_path')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_at', 'payment_proof_path']);
        });
    }
};
