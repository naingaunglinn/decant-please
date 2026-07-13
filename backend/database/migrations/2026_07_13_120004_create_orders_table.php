<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');
            $table->string('phone');
            $table->text('address');
            $table->string('order_from')->default('website')->index();
            $table->string('tracking_code')->unique();
            $table->date('decant_date')->nullable()->index();
            $table->date('delivery_date')->nullable()->index();
            $table->string('status')->default('awaiting_confirmation')->index();
            $table->string('rejection_reason')->nullable();
            $table->unsignedInteger('deposit_mmk')->default(0);
            $table->unsignedInteger('delivery_fee_mmk')->default(0);
            $table->unsignedInteger('discount_mmk')->default(0);
            $table->unsignedInteger('total_mmk')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
