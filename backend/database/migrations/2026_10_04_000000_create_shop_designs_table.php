<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 46a: a shop's storefront designs (App\Design\Designs). Every change is a
 * new row, never an update; shop_settings.published_design_id points at the live
 * one, and null means the template's Clean preset — so every existing shop keeps
 * today's storefront with no backfill. The prompt and token columns are row 14's
 * (the AI editor), added now so it needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_designs', function (Blueprint $table): void {
            $table->id();
            // Cascade: a design is configuration, not money (#112 restricts the money tables).
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->jsonb('config');
            $table->string('source', 16);
            $table->text('prompt')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
        });

        Schema::table('shop_settings', function (Blueprint $table): void {
            // Null on delete, not restrict: both tables cascade from shops, and SQLite
            // checks RESTRICT mid-cascade (the step-36 brand_id lesson).
            $table->foreignId('published_design_id')->nullable()->constrained('shop_designs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_design_id');
        });

        Schema::dropIfExists('shop_designs');
    }
};
