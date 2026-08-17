<?php

namespace App\Support;

use App\Enums\Courier;
use App\Enums\Region;
use App\Models\DeliveryTownship;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * Copies the national delivery geography (Royal Express's committed coverage
 * chart, database/data/delivery-townships*.csv) into ONE shop — every row
 * inactive at fee 0, because geography is not a shipping promise and onboarding
 * must never invent a fee (design-doc §7 / findings A2). The decanter activates
 * and prices their townships as part of go-live.
 *
 * Two callers, one mechanism: DeliveryZoneSeeder (the default shop on a fresh
 * install) and shop registration in the panel (ManageShops' CreateAction). It is
 * deliberately NOT a Shop model event — TestCase and the isolation suite create
 * shops constantly, and ~260 geography rows per test-shop would drown the suite;
 * registration through the panel IS the onboarding flow (F6).
 *
 * Idempotent per shop: townships match by (region, name) and courier rows by
 * (township, courier) under the tenant scope, so a re-run duplicates nothing and
 * never resets a fee, an activation, or a courier edit the decanter has made.
 */
class NationalGeography
{
    /**
     * Yangon City townships restored into the seed (the chart prices the whole
     * city as one ရန်ကုန် destination): on RoyalX's books each of these is a
     * "Yangon" parcel, so their route rows carry that as the courier's own
     * spelling — the reconciliation alias doing exactly its job.
     */
    private const YANGON_CITY_ALIAS = [
        'Ahlone', 'Bahan', 'Botataung', 'Dagon', 'Dagon Seikkan', 'Dawbon',
        'East Dagon', 'Hlaing', 'Hlaingthaya', 'Insein', 'Kamayut', 'Kyauktada',
        'Kyimyindaing', 'Lanmadaw', 'Latha', 'Mayangon', 'Mingala Taungnyunt',
        'Mingaladon', 'North Dagon', 'North Okkalapa', 'Pabedan', 'Pazundaung',
        'Sanchaung', 'Seikkan', 'Shwepyitha', 'South Dagon', 'South Okkalapa',
        'Tamwe', 'Thaketa', 'Thingangyun', 'Yankin',
    ];

    /** Genuinely unserved (boat or plane only) — a township row with no courier
     *  row at all, which is exactly how a dead zone must read. */
    private const NO_COURIER = ['Cocokyun'];

    /** @param  (callable(string): void)|null  $warn  receives skipped-row messages */
    public static function seed(Shop $shop, ?callable $warn = null): void
    {
        $context = app(TenantContext::class);
        $previous = $context->get();

        // Not withoutTenancy(): the rows must be CREATED as $shop's, and the
        // trait's creating hook fills shop_id from the context — so the context
        // becomes $shop for the duration, restored even on a throw.
        $context->set($shop);

        try {
            self::seedFile(database_path('data/delivery-townships.csv'), available: true, warn: $warn);
            self::seedFile(database_path('data/delivery-townships-suspended.csv'), available: false, warn: $warn);

            Cache::forget('api.delivery-zones.'.$shop->slug);
        } finally {
            $context->set($previous);
        }
    }

    private static function seedFile(string $path, bool $available, ?callable $warn): void
    {
        foreach (self::rows($path) as $row) {
            [$regionCell, $name, $nameMm, $fee] = array_pad($row, 4, '');

            $region = Region::fromLoose($regionCell);
            $name = trim($name);

            if (! $region || $name === '') {
                $warn && $warn("delivery zone seed: skipped a row with region \"{$regionCell}\", name \"{$name}\"");

                continue;
            }

            $township = DeliveryTownship::firstOrCreate(
                ['region' => $region->value, 'name' => $name],
                ['name_mm' => trim($nameMm) ?: null, 'fee_mmk' => (int) $fee, 'is_active' => false],
            );

            if (in_array($name, self::NO_COURIER, true)) {
                continue;
            }

            $township->couriers()->firstOrCreate(
                ['courier' => Courier::RoyalExpress->value],
                [
                    'courier_name' => in_array($name, self::YANGON_CITY_ALIAS, true) ? 'Yangon' : $name,
                    'is_available' => $available,
                ],
            );
        }
    }

    /** @return iterable<array<string>> data rows, header dropped, BOM/CRLF tolerated */
    private static function rows(string $path): iterable
    {
        $stream = fopen($path, 'r');
        $header = true;

        while (($row = fgetcsv($stream, escape: '\\')) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }

            $row = array_map(fn ($cell) => trim((string) $cell, " \t\r\n\u{FEFF}"), $row);

            if ($header) {
                $header = false;

                continue;
            }

            yield $row;
        }

        fclose($stream);
    }
}
