<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fragrance_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('size_ml');
            $table->unsignedInteger('price_mmk');
            $table->boolean('in_stock')->default(true);
            $table->timestamps();

            $table->unique(['fragrance_id', 'size_ml']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decant_prices');
    }
};
