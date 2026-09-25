<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 38: a clothing variant is "M / Blue", not a millilitre size.
 *
 * - product_variants.image_path: a photo per colour, stored under shops/{id}/…
 *   like product images. Nullable — decant variants have none.
 * - product_variants.size_ml becomes nullable: a clothing variant has no ml. Every
 *   existing (decant) value is untouched; order_items.size_ml went nullable in 36a.
 *   The (product_id, size_ml) unique index can't catch a repeated Size + Color (nulls
 *   are distinct); the admin form's duplicate rule does.
 *
 * down() refuses while a variant without a size exists, rather than invent an ml
 * value for it or delete it — either would lose data a placed order may point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('size_ml')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('product_variants')->whereNull('size_ml')->exists()) {
            throw new RuntimeException('Variants without a size (clothing) exist — remove them before rolling back step 38.');
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('size_ml')->nullable(false)->change();
            $table->dropColumn('image_path');
        });
    }
};
