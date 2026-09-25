<?php

namespace App\Support;

use App\Enums\BrandType;
use App\Models\Brand;
use App\Templates\Attribute;
use App\Templates\Template;
use App\Templates\Templates;
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
 * - Attribute columns are the shop template's attributes (step 37), by key —
 *   concentration, gender, notes… for decant; a required one must have a column.
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

    private function __construct(
        private readonly bool $updateExisting,
        private readonly Template $template,
    ) {}

    /**
     * @throws InvalidArgumentException when the file itself is unusable
     *                                  (row-level problems land in $failures instead)
     */
    public static function run(string $csv, bool $updateExisting = false): self
    {
        $import = new self($updateExisting, Templates::forShop());
        $rows = self::parseCsv($csv);

        if ($rows === []) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $import->header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows));

        $requiredAttributes = array_map(
            fn (Attribute $attribute): string => $attribute->key,
            array_filter($import->template->attributes(), fn (Attribute $attribute): bool => $attribute->required),
        );

        foreach (['brand', 'name', ...$requiredAttributes] as $required) {
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

        $attributes = [];
        foreach ($this->template->attributes() as $attribute) {
            $attributes[$attribute->key] = self::attributeValue($attribute, $data[$attribute->key] ?? '');
        }

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

        DB::transaction(function () use ($data, $brandName, $name, $attributes, $brandType, $prices) {
            $brand = Brand::query()->whereLike('name', self::likeLiteral($brandName))->first()
                ?? Brand::create(['name' => $brandName, 'type' => $brandType, 'is_active' => true]);

            $existing = $brand->products()->whereLike('name', self::likeLiteral($name))->first();

            if ($existing && ! $this->updateExisting) {
                $this->skipped++;

                return;
            }

            $text = fn (string $column): ?string => ($data[$column] ?? '') === '' ? null : $data[$column];

            if ($existing) {
                // blank cells keep whatever the admin already wrote by hand
                $existing->update([
                    'attributes' => array_merge(
                        $existing->attributes ?? [],
                        array_filter($attributes, fn ($value): bool => $value !== null),
                    ),
                    'description' => $text('description') ?? $existing->description,
                ]);

                foreach ($prices as $sizeMl => $priceMmk) {
                    $price = $existing->variants()->where('size_ml', $sizeMl)->first();

                    $price
                        ? $price->update(['price_mmk' => $priceMmk])
                        : $existing->variants()->create(['size_ml' => $sizeMl, 'price_mmk' => $priceMmk, 'in_stock' => true]);
                }

                $this->updated++;

                return;
            }

            $fragrance = $brand->products()->create([
                'name' => $name,
                'attributes' => $attributes,
                'description' => $text('description'),
                'is_active' => true,
            ]);

            foreach ($prices as $sizeMl => $priceMmk) {
                $fragrance->variants()->create(['size_ml' => $sizeMl, 'price_mmk' => $priceMmk, 'in_stock' => true]);
            }

            $this->created++;
        });
    }

    /**
     * A cell as the attribute stores it: null when blank (a required one fails),
     * a select's option value matched case-insensitively on value or label.
     *
     * @throws InvalidArgumentException
     */
    private static function attributeValue(Attribute $attribute, string $cell): string|int|null
    {
        if ($cell === '') {
            return $attribute->required ? throw new InvalidArgumentException("{$attribute->key} is required.") : null;
        }

        if ($attribute->type === Attribute::NUMBER) {
            return ctype_digit($cell) ? (int) $cell
                : throw new InvalidArgumentException("\"{$cell}\" is not a number ({$attribute->key}).");
        }

        if ($attribute->type === Attribute::TEXT) {
            return $cell;
        }

        foreach ($attribute->options as $value => $label) {
            if (mb_strtolower($cell) === mb_strtolower((string) $value) || mb_strtolower($cell) === mb_strtolower($label)) {
                return (string) $value;
            }
        }

        $labels = array_values($attribute->options);
        $last = array_pop($labels);

        throw new InvalidArgumentException(
            "unknown {$attribute->key} \"{$cell}\" — use ".($labels === [] ? $last : implode(', ', $labels)." or {$last}").'.'
        );
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
