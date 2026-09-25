<?php

use App\Templates\DecantTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 37 — code-defined templates and template-driven attributes.
 *
 *   products.attributes   jsonb: the template's attribute values, backfilled from
 *                         the five perfume columns (dropped by the next migration)
 *   products.search_text  lowercase brand + name + searchable attributes, what
 *                         `q` searches (never a LIKE into jsonb)
 *   products.template     the product's template key; every existing row is decant
 *   products.category_id  nullable, → this shop's categories, null on delete (a menu
 *                         section going away never deletes or blocks its products)
 *   shop_settings.template  the shop's default template; every existing shop is decant
 *   categories            per-shop menu sections ("tops / dresses") — tenant-owned
 *
 * search_text carries no index: `%q%` can't use a btree, and a btree entry has a
 * size limit a long notes field could hit. Catalogs are hundreds of rows per shop.
 *
 * Data work uses the query builder only: no tenant is set during a migration.
 */
return new class extends Migration
{
    public const PERFUME_COLUMNS = ['concentration', 'gender', 'notes', 'vibes', 'performance'];

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('attributes')->nullable();
            $table->text('search_text')->nullable();
            $table->string('template')->default('decant');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
        });

        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('template')->default('decant');
        });

        $this->backfillProducts();
    }

    public function down(): void
    {
        Schema::table('shop_settings', fn (Blueprint $table) => $table->dropColumn('template'));

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['attributes', 'search_text', 'template', 'category_id']);
        });

        Schema::dropIfExists('categories');
    }

    public function backfillProducts(): void
    {
        $brands = DB::table('brands')->pluck('name', 'id');
        $template = new DecantTemplate;

        DB::table('products')->orderBy('id')->get(['id', 'brand_id', 'name', ...self::PERFUME_COLUMNS])
            ->each(function (object $product) use ($brands, $template) {
                $attributes = self::attributesFrom($product);

                DB::table('products')->where('id', $product->id)->update([
                    'attributes' => json_encode($attributes),
                    'search_text' => $template->searchText($brands[$product->brand_id] ?? null, $product->name, $attributes),
                ]);
            });
    }

    /**
     * The five columns, as they are, keyed by name — nulls kept, so the next
     * migration's down() restores each column exactly. Public for ProductAttributesTest.
     *
     * @return array<string, mixed>
     */
    public static function attributesFrom(object $row): array
    {
        return array_combine(self::PERFUME_COLUMNS, array_map(fn (string $column) => $row->{$column}, self::PERFUME_COLUMNS));
    }
};
