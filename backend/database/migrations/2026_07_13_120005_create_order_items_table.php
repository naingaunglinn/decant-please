<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fragrance_id')->constrained()->restrictOnDelete();
            $table->string('fragrance_name_snapshot');
            $table->unsignedSmallInteger('size_ml');
            $table->unsignedInteger('unit_price_mmk');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('line_total_mmk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
