<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // stored uppercase, matched case-insensitively
            $table->string('type'); // percent | fixed
            $table->unsignedInteger('value'); // percent: 1-100; fixed: whole Kyat
            $table->unsignedInteger('max_discount_mmk')->nullable(); // caps a percent code; ignored for fixed
            $table->unsignedInteger('min_order_mmk')->nullable();
            $table->unsignedInteger('usage_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('times_used')->default(0);
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
