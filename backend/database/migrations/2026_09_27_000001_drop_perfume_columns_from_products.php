<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 37, the follow-up: the five perfume columns now live in products.attributes
 * (previous migration), so the columns go. down() puts them back from attributes,
 * NOT NULL and the gender index included, so a rollback reads exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex(['gender']));

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['concentration', 'gender', 'notes', 'vibes', 'performance']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('concentration')->nullable();
            $table->string('gender')->nullable();
            $table->text('notes')->nullable();
            $table->text('vibes')->nullable();
            $table->string('performance')->nullable();
        });

        DB::table('products')->orderBy('id')->get(['id', 'attributes'])
            ->each(function (object $product) {
                $attributes = json_decode($product->attributes ?? '{}', true) ?: [];

                DB::table('products')->where('id', $product->id)->update([
                    // A product created after step 37 without these two gets the
                    // decant defaults the NOT NULL needs, never a failed rollback.
                    'concentration' => $attributes['concentration'] ?? 'other',
                    'gender' => $attributes['gender'] ?? 'unisex',
                    'notes' => $attributes['notes'] ?? null,
                    'vibes' => $attributes['vibes'] ?? null,
                    'performance' => $attributes['performance'] ?? null,
                ]);
            });

        Schema::table('products', function (Blueprint $table) {
            $table->string('concentration')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
            $table->index('gender');
        });
    }
};
