<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 36 — the perfume catalog becomes a generic product + variant catalog.
 * IDs are stable: tables and columns are renamed, never copied.
 *
 *   fragrances            → products
 *   decant_prices         → product_variants (fragrance_id → product_id)
 *   order_items.fragrance_id → product_id
 *
 * New, all backfilled from size_ml so a decant shop reads exactly as before:
 *   product_variants.options   {"Size":"10ml"}
 *   product_variants.measure   10
 *   product_variants.is_active archive, never delete (true for every existing row)
 *   product_variants.position  display order; 0 for every existing row, so ties keep
 *                              sorting by size exactly as before (a new size lands in
 *                              size order too, until a seller reorders — step 38)
 *   order_items.product_variant_id + variant_label_snapshot — which variant a line
 *     sold, matched on (shop, product, size). A line whose size no longer has a
 *     variant keeps a null id and a synthesized "10ml" label.
 *
 * Loosened for categories that aren't perfume (the #115 amendments):
 *   order_items.size_ml nullable, products.brand_id nullable and nullOnDelete
 *   (deleting a brand clears it on its products, never deletes them),
 *   brands.type nullable.
 *
 * Money and snapshots are untouched: unit/line price and cost, size_ml and
 * fragrance_name_snapshot keep every stored value.
 *
 * Postgres keeps constraint, index and sequence names through a table or column
 * rename (fragrances_pkey stays fragrances_pkey on `products`), so later
 * migrations that name them by convention would miss. They are renamed to match
 * the new table and column names here, both ways.
 *
 * Data work uses the query builder only: no tenant is set during a migration, so
 * a tenant-owned model's scope would throw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('fragrances', 'products');
        Schema::rename('decant_prices', 'product_variants');

        Schema::table('product_variants', fn (Blueprint $table) => $table->renameColumn('fragrance_id', 'product_id'));
        Schema::table('order_items', fn (Blueprint $table) => $table->renameColumn('fragrance_id', 'product_id'));

        $this->renameObjects('products', ['fragrances_' => 'products_']);
        $this->renameObjects('product_variants', ['decant_prices_' => 'product_variants_', 'fragrance_id' => 'product_id']);
        $this->renameObjects('order_items', ['fragrance_id' => 'product_id']);
        $this->renameSequence('fragrances_id_seq', 'products_id_seq');
        $this->renameSequence('decant_prices_id_seq', 'product_variants_id_seq');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->jsonb('options')->nullable();
            $table->unsignedInteger('measure')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()
                ->constrained('product_variants')->restrictOnDelete();
            $table->string('variant_label_snapshot')->nullable();
            $table->unsignedSmallInteger('size_ml')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('type')->nullable()->default('designer')->change();
        });

        $this->backfillVariants();
        $this->backfillOrderItems();
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('type')->nullable(false)->default('designer')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable(false)->change();
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn(['product_variant_id', 'variant_label_snapshot']);
            $table->unsignedSmallInteger('size_ml')->nullable(false)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['options', 'measure', 'is_active', 'position']);
        });

        $this->renameSequence('product_variants_id_seq', 'decant_prices_id_seq');
        $this->renameSequence('products_id_seq', 'fragrances_id_seq');
        $this->renameObjects('order_items', ['product_id' => 'fragrance_id']);
        $this->renameObjects('product_variants', ['product_variants_' => 'decant_prices_', 'product_id' => 'fragrance_id']);
        $this->renameObjects('products', ['products_' => 'fragrances_']);

        Schema::table('order_items', fn (Blueprint $table) => $table->renameColumn('product_id', 'fragrance_id'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->renameColumn('product_id', 'fragrance_id'));

        Schema::rename('product_variants', 'decant_prices');
        Schema::rename('products', 'fragrances');
    }

    /**
     * options / measure from size_ml. position stays at its default 0: numbering
     * existing rows 0, 1, 2… would put every size added later (position 0) first.
     * Public, like backfillOrderItems(), so ProductMigrationTest can run it on seeded rows.
     */
    public function backfillVariants(): void
    {
        DB::table('product_variants')->orderBy('id')->get(['id', 'size_ml'])
            ->each(fn (object $variant) => DB::table('product_variants')->where('id', $variant->id)->update([
                'options' => json_encode(['Size' => "{$variant->size_ml}ml"]),
                'measure' => $variant->size_ml,
            ]));
    }

    /**
     * Each line's variant is the one with the same shop, product and size. The
     * label is synthesized from size_ml whether or not a variant still exists, so
     * it reads exactly as the line always has.
     */
    public function backfillOrderItems(): void
    {
        $variants = DB::table('product_variants')->get(['id', 'shop_id', 'product_id', 'size_ml'])
            ->keyBy(fn (object $v): string => "{$v->shop_id}:{$v->product_id}:{$v->size_ml}");

        DB::table('order_items')->orderBy('id')->get(['id', 'shop_id', 'product_id', 'size_ml'])
            ->each(fn (object $item) => DB::table('order_items')->where('id', $item->id)->update([
                'product_variant_id' => $variants->get("{$item->shop_id}:{$item->product_id}:{$item->size_ml}")?->id,
                'variant_label_snapshot' => "{$item->size_ml}ml",
            ]));
    }

    /**
     * Rename a table's constraints and indexes by string replacement. Postgres:
     * constraints (primary, unique, foreign) by RENAME CONSTRAINT, which renames a
     * backing index too; then any plain index. SQLite: named indexes only — its
     * foreign keys are unnamed, and dropForeign finds them by column.
     */
    private function renameObjects(string $table, array $replace): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $constraints = DB::select(
                'select conname from pg_constraint where conrelid = ?::regclass',
                [$table],
            );

            foreach ($constraints as $row) {
                $new = strtr($row->conname, $replace);

                if ($new !== $row->conname) {
                    DB::statement("alter table \"{$table}\" rename constraint \"{$row->conname}\" to \"{$new}\"");
                }
            }
        }

        foreach (Schema::getIndexes($table) as $index) {
            $new = strtr($index['name'], $replace);

            if ($new === $index['name'] || ($index['primary'] && $driver !== 'pgsql')) {
                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("alter index \"{$index['name']}\" rename to \"{$new}\"");
            } else {
                Schema::table($table, fn (Blueprint $t) => $t->renameIndex($index['name'], $new));
            }
        }
    }

    private function renameSequence(string $from, string $to): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter sequence if exists \"{$from}\" rename to \"{$to}\"");
        }
    }
};
