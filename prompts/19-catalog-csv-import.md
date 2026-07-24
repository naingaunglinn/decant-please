# Step 19 — Bulk catalog CSV import

Follow the current `CLAUDE.md`. Work in `backend/` only.

## 0. Why this step exists

To sell Decant Please! to other Myanmar decant businesses, the killer friction is
onboarding: a decanter switching from TikTok/Facebook DMs already has 100–300
fragrances in a price list, and hand-entering them one Filament form at a time is
the wall between "interested" and "live". This step turns that price list into
the catalog in one upload.

## 1. The file format — one row per fragrance

The CSV a seller keeps *is* a price list, so the import reads exactly that shape:

```csv
brand,brand_type,name,concentration,gender,notes,vibes,performance,description,price_5ml,price_10ml,price_30ml
Chanel,designer,Allure Homme Sport,cologne,male,"Citrus, Musk, Amber","Modern, Clean",Around 6-8 Hours,A fresh sporty staple.,30000,55000,120000
Creed,niche,Aventus,edp,male,"Pineapple, Birch, Musk",,,,,80000,210000
```

- Required per row: `brand`, `name`, a valid `concentration`, a valid `gender`,
  and **at least one price**. Everything else is optional.
- **Any** `price_{N}ml` column works (`price_50ml` is legal), not just 5/10/30.
  A blank price cell means the size isn't offered — not zero, not an error.
- Enum cells match **case-insensitively** (`EDP` = `edp`; `DESIGNER` = `designer`).
  `brand_type` defaults to `designer` when blank and is only read when the brand
  is first created — a later row can't silently mutate an existing brand.
- Prices tolerate the `30,000` digit-grouping sellers paste from spreadsheets.
- UTF-8 with Burmese text must round-trip, including the BOM Excel prepends.
- Images can't ride in a CSV — uploaded per fragrance afterwards, out of scope.

## 2. Import semantics — safe to re-run, always

- **Brand:** matched by name (case-insensitive — `whereLike`, per the v6
  Postgres rule; escape `%`/`_` so a real name can't act as a wildcard) or
  created with `is_active = true`.
- **Fragrance:** matched by (brand, name). Existing rows are **skipped** by
  default, so re-uploading a file after fixing failures never duplicates what
  already landed. Slugs come from the existing `HasSlug` behaviour.
- **Update mode** (an explicit toggle): overwrites `concentration`/`gender`, but
  text fields only from **non-blank** cells (a blank cell keeps what the admin
  wrote by hand), and **upserts** prices per size — updates the matching size,
  creates missing ones (`in_stock = true`), never deletes a size not in the file.
- **Per-row transactions:** a bad row fails alone, with a specific reason
  (`unknown concentration "edtt" — use EDT, EDP, …`); every good row still lands.
- A structurally unusable file — missing `brand`/`name`/`concentration`/`gender`
  columns, or no `price_*` column at all — is rejected whole, pointing at the
  template.

## 3. Admin UI — two toolbar actions on Fragrances

- **Import CSV** — upload (`storeFiles(false)`, nothing persisted server-side) +
  the "Update existing fragrances" toggle. Ends in one notification:
  *"38 created, 4 skipped (already exist), 2 failed"* — and when rows failed,
  the browser also downloads **`catalog-import-failures.csv`**: the original
  columns plus an `error` column. Unknown headers are ignored on import, so the
  decanter fixes the error column's complaints and re-uploads that same file.
- **CSV template** — downloads a pre-filled example (including a Burmese sample
  row) so nobody guesses column names. `CatalogImport::template()` is the single
  source of that file, and a test imports it, so it can never drift from the
  parser.

## 4. Deliberately NOT Filament's `ImportAction`

Filament ships an import system, and this step doesn't use it, on purpose:

- It requires the queue plus its own `imports`/`failed_import_rows` (and
  notification) tables — real infrastructure for what is, at a few hundred rows,
  a sub-second synchronous loop.
- Its one-Importer-per-model paradigm fights this row shape, which fans out to
  **three** models (brand `firstOrCreate` + fragrance + N decant prices).
- The repo already chose custom CSV actions over Filament's exporter
  (`exportCsv` on Orders). Import follows the same idiom: a plain, testable
  service — `App\Support\CatalogImport` — and thin Filament actions around it.

## 5. Tests (`tests/Feature/CatalogImportTest.php`)

1. Happy path: mixed file → brands created once and reused, fragrances, prices,
   slugs; blank price cell omitted; `60,000` parsed.
2. Re-import skips existing — zero duplicates.
3. Bad rows fail alone with row numbers + reasons; failures CSV mirrors the
   original columns, contains only the failed rows.
4. Enums case-insensitive; `chanel` reuses `Chanel`.
5. Burmese text + Excel BOM round-trip.
6. Update mode: price updated, untouched size kept, new size added; blank cell
   keeps hand-written text.
7. The shipped template imports cleanly (template ↔ parser can't drift).
8. Livewire: the action imports an uploaded file end-to-end; the template
   action downloads.
