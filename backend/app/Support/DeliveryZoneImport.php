<?php

namespace App\Support;

use App\Enums\Region;
use App\Models\DeliveryTownship;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk township import — the same wide shape as the committed seed file
 * (`region,name,name_mm,fee_mmk`), for loading a courier's published
 * destination list or repricing many rows at once.
 *
 * Semantics, following CatalogImport (the v9 idiom) exactly where they map:
 * - Townships match by (region, name) — region parsed loosely ("Yangon",
 *   "Yangon Region"), name case-insensitively (whereLike, wildcards escaped,
 *   the v6 Postgres rule) — or are created, inactive: an import is geography,
 *   never a shipping promise, so activation stays a deliberate admin act.
 * - On an existing row, only non-blank cells overwrite: a blank fee cell
 *   can't zero a priced township, a blank name_mm can't erase Burmese the
 *   decanter confirmed by hand.
 * - Each row commits alone and fails alone with a reason; the failures come
 *   back as a CSV whose extra `error` column re-imports harmlessly (unknown
 *   headers are ignored). Courier route data never rides in this file — that
 *   is per-township relation editing or the set-cost bulk action.
 * - Published courier lists carry junk (blank rows, `-----`, `Tharyarwaddy2`
 *   duplicate markers): those rows fail alone with a reason, never the file.
 */
class DeliveryZoneImport
{
    public int $created = 0;

    public int $updated = 0;

    /** The header as it appeared in the file — lets the failures CSV mirror it. */
    public array $header = [];

    /** @var array<array{row: int, message: string, data: array<string, string>}> */
    public array $failures = [];

    private function __construct() {}

    /**
     * @throws InvalidArgumentException when the file itself is unusable
     *                                  (row-level problems land in $failures instead)
     */
    public static function run(string $csv): self
    {
        $import = new self;
        $rows = self::parseCsv($csv);

        if ($rows === []) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $import->header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows));

        foreach (['region', 'name'] as $required) {
            if (! in_array($required, $import->header, true)) {
                throw new InvalidArgumentException(
                    "The file has no \"{$required}\" column — download the template to see the expected format."
                );
            }
        }

        foreach ($rows as $index => $row) {
            $line = array_pad(array_slice($row, 0, count($import->header)), count($import->header), '');
            $data = array_combine($import->header, array_map(fn ($cell) => trim((string) $cell), $line));

            if (implode('', $data) === '') {
                continue; // a blank line, not a mistake
            }

            $csvLine = $index + 2; // 1-based, after the header row

            try {
                $import->importRow($data);
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
            ['region', 'name', 'name_mm', 'fee_mmk'],
            ['Yangon', 'Sanchaung', 'စမ်းချောင်း', '2000'],
            ['Mandalay Region', 'Chanayethazan', 'ချမ်းအေးသာစံ', '3500'],
        ]);
    }

    /** The failed rows, as a CSV to fix and re-upload — the extra "error"
     *  column is not a known header, so a re-import just ignores it. */
    public function failuresCsv(): string
    {
        $rows = [[...$this->header, 'error']];

        foreach ($this->failures as $failure) {
            $rows[] = [...array_map(fn ($column) => $failure['data'][$column] ?? '', $this->header), $failure['message']];
        }

        return self::toCsv($rows);
    }

    private function importRow(array $data): void
    {
        $name = $data['name'] ?? '';

        if ($name === '') {
            throw new InvalidArgumentException('name is required.');
        }

        // Bee's published list mixes in separator rows and duplicate markers.
        if (! preg_match('/\p{L}/u', $name)) {
            throw new InvalidArgumentException("\"{$name}\" is not a township name.");
        }

        if (preg_match('/\d$/', $name)) {
            throw new InvalidArgumentException(
                "\"{$name}\" ends in a digit — that's how courier lists mark a duplicated row; rename it to the real township."
            );
        }

        $region = Region::fromLoose($data['region'] ?? '')
            ?? throw new InvalidArgumentException(
                "unknown region \"{$data['region']}\" — use one of Myanmar's 15 states/regions, like \"Yangon\" or \"Mon State\"."
            );

        $feeCell = $data['fee_mmk'] ?? '';
        $fee = null;

        if ($feeCell !== '') {
            $digits = str_replace([',', ' '], '', $feeCell); // sellers paste "3,000"

            if (! ctype_digit($digits)) {
                throw new InvalidArgumentException("\"{$feeCell}\" is not a fee (fee_mmk) — whole Kyat only, like 2000. 0 means free delivery.");
            }

            $fee = (int) $digits;
        }

        $nameMm = ($data['name_mm'] ?? '') === '' ? null : $data['name_mm'];

        DB::transaction(function () use ($region, $name, $nameMm, $fee) {
            $existing = DeliveryTownship::query()
                ->where('region', $region->value)
                ->whereLike('name', self::likeLiteral($name), caseSensitive: false)
                ->first();

            if ($existing) {
                // non-blank cells only — a blank never erases hand-entered data
                $existing->update(array_filter([
                    'name_mm' => $nameMm,
                    'fee_mmk' => $fee,
                ], fn ($value) => $value !== null));

                $this->updated++;

                return;
            }

            DeliveryTownship::create([
                'region' => $region->value,
                'name' => $name,
                'name_mm' => $nameMm,
                'fee_mmk' => $fee ?? 0,
                'is_active' => false,
            ]);

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
