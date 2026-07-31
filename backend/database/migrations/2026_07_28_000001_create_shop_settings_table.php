<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single-row store for the decanter's own payment details, so they can set
     * their MMQR + KBZPay/Wave numbers from the admin instead of editing .env.
     * The QR image lives on the public media disk (customers scan it); the numbers
     * and instructions are shown alongside it at checkout. All nullable — an
     * unconfigured shop simply shows no payment block (MetaController hides it).
     */
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->string('kbzpay_name')->nullable();
            $table->string('kbzpay_number')->nullable();
            $table->string('wave_name')->nullable();
            $table->string('wave_number')->nullable();
            $table->string('payment_qr_path')->nullable(); // MMQR image on the media disk
            $table->text('payment_instructions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
