<?php

namespace App\Console\Commands;

use App\Filament\Widgets\TopFragrances;
use App\Models\Order;
use App\Models\Shop;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy Step 23 §8: runs, against the engine actually connected, the
 * tenant-scoped work the SQLite suite structurally cannot vet — the
 * TopFragrances join (an unqualified shop_id is "column reference is ambiguous"
 * on Postgres only) with its select-alias ORDER BY, the production-schedule
 * aggregation, and the seam's unique indexes (the (shop_id, …) composites, and
 * tracking_code staying deliberately GLOBAL — findings Q2). Exits non-zero on
 * the first failure. Hidden: it exists for verify-postgres-portability.sh and
 * CI, not for the operator's command list.
 */
class ProbePostgres extends Command
{
    protected $signature = 'decant:probe-postgres {--shop= : Slug of the shop to probe under (defaults to SHOP_SLUG)}';

    protected $description = 'Run the tenant-scoped joins, alias sorts and unique-index checks SQLite cannot vet';

    protected $hidden = true;

    /**
     * The seam's unique indexes, as [table, exact column list]. A missing row
     * here is a migration path that diverged from the design; tracking_code is
     * asserted single-column on purpose — it must never become per-shop.
     */
    private const UNIQUE_INDEXES = [
        ['brands', ['shop_id', 'name']],
        ['brands', ['shop_id', 'slug']],
        ['fragrances', ['shop_id', 'slug']],
        ['promo_codes', ['shop_id', 'code']],
        ['delivery_townships', ['shop_id', 'region', 'name']],
        ['shop_settings', ['shop_id']],
        ['orders', ['tracking_code']],
    ];

    public function handle(): int
    {
        $slug = $this->option('shop') ?? config('app.shop_slug');
        $shop = Shop::query()->where('slug', $slug)->first();

        if (! $shop) {
            $this->error("probe: no shop with slug [{$slug}]");

            return self::FAILURE;
        }

        app(TenantContext::class)->set($shop);
        $driver = DB::connection()->getDriverName();

        try {
            $ranked = TopFragrances::rankingQuery()->get();
            $scheduleDays = Order::productionScheduleFor(
                CarbonImmutable::today()->subDays(30),
                CarbonImmutable::today(),
            );
        } catch (QueryException $e) {
            $this->error("probe: a tenant-scoped query failed on {$driver}: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach (self::UNIQUE_INDEXES as [$table, $columns]) {
            if (! $this->uniqueIndexExists($table, $columns)) {
                $this->error(sprintf('probe: missing unique index on %s(%s)', $table, implode(', ', $columns)));

                return self::FAILURE;
            }
        }

        $this->info(sprintf(
            'probe: ok on %s — TopFragrances ranked %d, schedule spanned %d day(s), %d unique indexes verified',
            $driver,
            $ranked->count(),
            count($scheduleDays),
            count(self::UNIQUE_INDEXES),
        ));

        return self::SUCCESS;
    }

    /** @param  list<string>  $columns */
    private function uniqueIndexExists(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['unique'] ?? false)
                && array_map('strtolower', $index['columns']) === $columns,
        );
    }
}
