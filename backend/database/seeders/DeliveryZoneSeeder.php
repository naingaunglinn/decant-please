<?php

namespace Database\Seeders;

use App\Enums\Courier;
use App\Enums\Region;
use App\Models\DeliveryTownship;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds delivery geography from Royal Express's official coverage chart
 * ("Last Updated 1/8/2026" — committed as database/data/preview.webp; the
 * chart is perishable, so re-check it when the decanter next reviews fees).
 *
 * - delivery-townships.csv → township + an OPEN RoyalX route row.
 * - delivery-townships-suspended.csv → township + a CLOSED RoyalX route row
 *   (is_available false): suspended is a state that reverses, unserved is not.
 * - Every row lands inactive at fee 0 — the seed is geography, not a shipping
 *   promise, and the seeder must never invent a fee (a number the code
 *   guessed gets believed). The decanter activates and prices per township.
 * - Idempotent by (region, name) firstOrCreate: re-seeding never duplicates a
 *   row, never resets a fee or a courier edit the decanter has made.
 *
 * The one demo exception: Yangon activates at a placeholder fee so a fresh
 * `docker compose up` can complete a checkout — the same seam the demo
 * catalog sits behind. `decant:fresh-start` resets every zone to inactive/0.
 */
class DeliveryZoneSeeder extends Seeder
{
    private const DEMO_YANGON_FEE_MMK = 2000;

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

    public function run(): void
    {
        $this->seedFile(database_path('data/delivery-townships.csv'), available: true);
        $this->seedFile(database_path('data/delivery-townships-suspended.csv'), available: false);

        $this->activateYangonForDemo();

        Cache::forget('api.delivery-zones');
    }

    private function seedFile(string $path, bool $available): void
    {
        foreach ($this->rows($path) as $row) {
            [$regionCell, $name, $nameMm, $fee] = array_pad($row, 4, '');

            $region = Region::fromLoose($regionCell);
            $name = trim($name);

            if (! $region || $name === '') {
                $this->command?->warn("delivery zone seed: skipped a row with region \"{$regionCell}\", name \"{$name}\"");

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
    private function rows(string $path): iterable
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

    private function activateYangonForDemo(): void
    {
        // Demo only in effect: touches nothing the decanter has already
        // activated or priced, and decant:fresh-start resets every zone to
        // inactive at 0 — so real installs go live with no invented fee.
        DeliveryTownship::query()
            ->where('region', Region::Yangon->value)
            ->where('is_active', false)
            ->where('fee_mmk', 0)
            ->update(['is_active' => true, 'fee_mmk' => self::DEMO_YANGON_FEE_MMK]);
    }
}
