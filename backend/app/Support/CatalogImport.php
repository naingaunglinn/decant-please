<?php

namespace App\Support;

use App\Enums\BrandType;
use App\Enums\Concentration;
use App\Enums\Gender;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk catalog onboarding from a price-list CSV — the file a decanter already
 * keeps: one row per fragrance, one price_{N}ml column per decant size (any N,
 * not just 5/10/30; a blank cell means that size isn't offered).
 *
 * Import semantics, chosen for safe re-runs:
 * - Brands are matched by name (case-insensitively) or created; brand_type is
 *   only read when creating, so a typo'd type can't silently mutate a brand.
 * - Fragrances are matched by (brand, name). Existing ones are SKIPPED unless
 *   $updateExisting — so re-uploading the same file after fixing failed rows
 *   never duplicates what already landed.
 * - In update mode, only non-blank cells overwrite (a blank description won't
 *   erase one written by hand), and prices upsert per size — sizes missing
 *   from the CSV are left alone, never deleted.
 * - Each row commits in its own transaction: a bad row fails alone, with a
 *   reason, while every good row still lands. Images can't ride in a CSV, so
 *   they're uploaded per fragrance afterwards — deliberately out of scope.
 *
 * Kept as a plain service (not Filament's ImportAction) to match this repo's
 * existing custom-CSV idiom (exportCsv) and to avoid the queue/notification
 * tables ImportAction drags in — a few hundred rows import fine synchronously.
 */
class CatalogImport
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** The header as it appeared in the file — lets a failures CSV mirror it. */
    public array $header = [];

    /** @var array<array{row: int, message: string, data: array<string, string>}> */
    public array $failures = [];

    private function __construct(private readonly bool $updateExisting) {}

    /**
     * @throws InvalidArgumentException when the file itself is unusable
     *                                  (row-level problems land in $failures instead)
     */
    public static function run(string $csv, bool $updateExisting = false): self
    {
        $import = new self($updateExisting);
        $rows = self::parseCsv($csv);

        if ($rows === []) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $import->header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows));

        foreach (['brand', 'name', 'concentration', 'gender'] as $required) {
            if (! in_array($required, $import->header, true)) {
                throw new InvalidArgumentException(
                    "The file has no \"{$required}\" column — download the template to see the expected format."
                );
            }
        }

        $priceColumns = [];
        foreach ($import->header as $column) {
            if (preg_match('/^price_(\d+)ml$/', $column, $matches)) {
                $priceColumns[$column] = (int) $matches[1];
            }
        }

        if ($priceColumns === []) {
            throw new InvalidArgumentException(
                'The file has no price columns — name them like "price_5ml", "price_10ml", "price_30ml".'
            );
        }

        foreach ($rows as $index => $row) {
            $line = array_pad(array_slice($row, 0, count($import->header)), count($import->header), '');
            $data = array_combine($import->header, array_map(fn ($cell) => trim((string) $cell), $line));

            if (implode('', $data) === '') {
                continue; // a blank line, not a mistake
            }

            $csvLine = $index + 2; // 1-based, after the header row

            try {
                $import->importRow($data, $priceColumns);
            } catch (InvalidArgumentException $e) {
                $import->failures[] = ['row' => $csvLine, 'message' => $e->getMessage(), 'data' => $data];
            }
        }

        return $import;
    }

    /** The example file the "CSV template" action serves — also pins the format in tests. */
    public static function template(): string
    {
        return self::toCsv([
            ['brand', 'brand_type', 'name', 'concentration', 'gender', 'notes', 'vibes', 'performance', 'description', 'price_5ml', 'price_10ml', 'price_30ml'],
            ['Chanel', 'designer', 'Allure Homme Sport', 'cologne', 'male', 'Citrus, Musk, Amber', 'Modern, Clean', 'Around 6-8 Hours', 'A fresh, sporty staple.', '30000', '55000', '120000'],
            ['Creed', 'niche', 'Aventus', 'edp', 'male', 'Pineapple, Birch, Musk', 'Bold, Confident', '8+ hours', 'နာမည်ကြီး niche ရနံ့။', '', '80000', '210000'],
        ]);
    }

    /** The failed rows, as a CSV the decanter can fix and re-upload — the extra
     *  "error" column is not a known header, so a re-import just ignores it. */
    public function failuresCsv(): string
    {
        $rows = [[...$this->header, 'error']];

        foreach ($this->failures as $failure) {
            $rows[] = [...array_map(fn ($column) => $failure['data'][$column] ?? '', $this->header), $failure['message']];
        }

        return self::toCsv($rows);
    }

    private function importRow(array $data, array $priceColumns): void
    {
        $brandName = $data['brand'] ?? '';
        $name = $data['name'] ?? '';

        if ($brandName === '') {
            throw new InvalidArgumentException('brand is required.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('name is required.');
        }

        $concentration = Concentration::tryFrom(strtolower($data['concentration'] ?? ''))
            ?? throw new InvalidArgumentException(
                "unknown concentration \"{$data['concentration']}\" — use EDT, EDP, Parfum, Cologne, Extrait or Other."
            );

        $gender = Gender::tryFrom(strtolower($data['gender'] ?? ''))
            ?? throw new InvalidArgumentException(
                "unknown gender \"{$data['gender']}\" — use male, female or unisex."
            );

        $brandTypeCell = $data['brand_type'] ?? '';
        $brandType = $brandTypeCell === ''
            ? BrandType::Designer
            : (BrandType::tryFrom(strtolower($brandTypeCell))
                ?? throw new InvalidArgumentException(
                    "unknown brand_type \"{$brandTypeCell}\" — use designer or niche (or leave it blank for designer)."
                ));

        $prices = [];
        foreach ($priceColumns as $column => $sizeMl) {
            $cell = $data[$column] ?? '';

            if ($cell === '') {
                continue; // size not offered
            }

            $digits = str_replace([',', ' '], '', $cell); // sellers paste "30,000"

            if (! ctype_digit($digits) || (int) $digits < 1) {
                throw new InvalidArgumentException("\"{$cell}\" is not a price ({$column}) — whole Kyat only, like 30000.");
            }

            $prices[$sizeMl] = (int) $digits;
        }

        if ($prices === []) {
            throw new InvalidArgumentException('every fragrance needs at least one price.');
        }

        DB::transaction(function () use ($data, $brandName, $name, $concentration, $gender, $brandType, $prices) {
            $brand = Brand::query()->whereLike('name', self::likeLiteral($brandName))->first()
                ?? Brand::create(['name' => $brandName, 'type' => $brandType, 'is_active' => true]);

            $existing = $brand->fragrances()->whereLike('name', self::likeLiteral($name))->first();

            if ($existing && ! $this->updateExisting) {
                $this->skipped++;

                return;
            }

            $text = fn (string $column): ?string => ($data[$column] ?? '') === '' ? null : $data[$column];

            if ($existing) {
                $existing->update([
                    'concentration' => $concentration,
                    'gender' => $gender,
                    // blank cells keep whatever the admin already wrote by hand
                    'notes' => $text('notes') ?? $existing->notes,
                    'vibes' => $text('vibes') ?? $existing->vibes,
                    'performance' => $text('performance') ?? $existing->performance,
                    'description' => $text('description') ?? $existing->description,
                ]);

                foreach ($prices as $sizeMl => $priceMmk) {
                    $price = $existing->decantPrices()->where('size_ml', $sizeMl)->first();

                    $price
                        ? $price->update(['price_mmk' => $priceMmk])
                        : $existing->decantPrices()->create(['size_ml' => $sizeMl, 'price_mmk' => $priceMmk, 'in_stock' => true]);
                }

                $this->updated++;

                return;
            }

            $fragrance = $brand->fragrances()->create([
                'name' => $name,
                'concentration' => $concentration,
                'gender' => $gender,
                'notes' => $text('notes'),
                'vibes' => $text('vibes'),
                'performance' => $text('performance'),
                'description' => $text('description'),
                'is_active' => true,
            ]);

            foreach ($prices as $sizeMl => $priceMmk) {
                $fragrance->decantPrices()->create(['size_ml' => $sizeMl, 'price_mmk' => $priceMmk, 'in_stock' => true]);
            }

            $this->created++;
        });
    }

    /** @return array<array<string>> */
    private static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // Excel prepends a BOM

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, escape: '\\')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        // a row of nulls is how fgetcsv reports a blank line
        return array_values(array_filter($rows, fn ($row) => $row !== [null]));
    }

    private static function toCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Exact-match LIKE, so a "%" or "_" in a real name can't act as a wildcard. */
    private static function likeLiteral(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
