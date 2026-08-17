# backend/AGENTS.md — Laravel 13 + Filament 5

Scoped rules for the API and admin panel. Root rules in `../AGENTS.md` still apply.

## Stack

PHP 8.3+ · Laravel 13 · Filament 5 · Postgres 17 (SQLite in-memory for tests) ·
dompdf for invoices · Pint for formatting. Versions are pinned; do not upgrade as a
side effect of feature work.

## Layering

```
routes/api.php  →  Http/Controllers/Api/*  →  FormRequest validation
                                           →  service / action (business rules)
                                           →  Model + Enum
                                           →  Http/Resources/*  (JSON shaping)
```

- Controllers stay thin. If a controller method is longer than about 30 lines, the
  business rule belongs in a service.
- Validation lives in a FormRequest or an inline `$request->validate()`, never scattered
  through the method body.
- Every JSON response goes through an API Resource. The Resource shape **is** the
  contract with `frontend/src/lib/types.ts` — change one, change the other in the same PR.
- Enums are PHP backed enums in `app/Enums/`. Never a bare string literal in a
  controller, migration, or Filament schema.

## Tenancy — read this before touching any model or query

This is a multi-tenant SaaS. Every shop's data lives in one Postgres database, separated
by `shop_id` and an Eloquent global scope. There is no second database to fall back on if
the scope is missed, so **the scope is the isolation boundary**.

**The seam** (do not modify without saying so first):

| File | Role |
|---|---|
| `app/Support/TenantContext.php` | request-scoped singleton — the one place "which shop is this?" lives |
| `app/Models/Concerns/BelongsToShop.php` | the global scope + a `creating` hook that fills `shop_id` |
| `app/Exceptions/TenantNotSetException.php` | thrown when a tenant query runs with no tenant set |
| `app/Http/Middleware/ResolveTenant.php` | API side: resolves `/api/v1/{shop}` to a shop, generic 404 otherwise |
| `app/Listeners/SyncTenantContextFromFilament.php` | panel side: mirrors Filament's resolved tenant (`IdentifyTenant` on `/admin/{shop}`) into `TenantContext` + URL defaults |
| `bootstrap/app.php` middleware priority | orders `IdentifyTenant` before `SubstituteBindings`, so panel route-model bindings resolve under the tenant scope |

**Rules**

1. **Fail loud, never silent.** With no tenant set, `BelongsToShop` throws rather than
   returning every shop's rows. If you find yourself catching that exception to make a
   test pass, the test is telling you a real thing.
2. **Qualify the column.** Always `orders.shop_id`, never a bare `shop_id`. Joins do not
   apply the joined model's scopes — `TopFragrances` joins `orders` + `order_items`, and
   a bare column there is *ambiguous* on Postgres while SQLite silently tolerates it.
   This is the same SQLite-hides-Postgres trap as the `LIKE`/`ORDER BY` one below.
3. **`withoutTenancy()` is budgeted.** Its legitimate uses are enumerated in
   `prompts/multi-tenancy-design.md` §8: tracking-code dedup, the backfill migration,
   cross-shop studio views. `TenantIsolationTest` asserts the list. Adding a call site is
   an architecture decision — propose it, don't just write it.
4. **`Shop` itself is not tenant-owned.** It carries no `shop_id` and uses no trait; it is
   what everything else scopes *to*.
5. **There is no global tenant data — not even geography.** `delivery_townships` and
   `delivery_township_couriers` both carry `shop_id` and both use the trait; the unique is
   `(shop_id, region, name)`. The national township CSV is *copied into each shop* at
   onboarding. Township identity is genuinely national, so a global reference table with a
   `(shop_id, township_id)` pricing overlay is the more normalized model — and it was
   **rejected deliberately** (design-doc §7): the overlay forks the seam's one mechanism
   into two, adds a read-time join everywhere, and rewrites v21's shipped admin UI,
   checkout lookup, and public API to buy normalization of ~228 rows. Do not reintroduce
   it, and do not "fix" the duplication.
6. **Tracking codes are globally unique** across shops even though lookup is shop-scoped.

**Adding a new model — decide tenancy first, before writing the migration**

- Does a row belong to exactly one shop? → `shop_id` column (indexed, FK to `shops`) +
  `use BelongsToShop` + an isolation test.
- Does it look like reference data every shop reads identically? → **it still gets
  `shop_id`.** That case has come up once (delivery townships) and was resolved by
  duplicating per shop, not by going global. One mechanism, no exceptions. If you think you
  have found a genuine exception, stop and propose it — do not implement it.
- Is it *both* (shared row, per-shop attributes)? → that is the overlay pattern, and it is
  rejected (rule 5). Duplicate the row per shop instead.

**No foreign key crosses a shop boundary. No exceptions.** A tenant-owned row may
reference another row of the same shop — never another shop's row, and there is no
global-reference carve-out, because rule 5 means there is no global tenant data to carve
out. An order's `delivery_township_id` points at that shop's own township row. This is not
only a correctness rule: it is what keeps "export one shop" a clean operation, and
therefore what keeps the option of moving a large shop to its own instance affordable
(ADR-0002, Ramp 5). Breaking it is expensive in a way that will not show up in any test.

**A natural key that was unique is probably now unique-per-shop.** A slug, a promo code,
a brand name — two shops must both be able to have "Chanel". Check every `unique()` in
your migration against that.

**Every tenant-owned model needs an isolation test** in `TenantIsolationTest` (or its own
file, same shape). The existing suite is the template — it asserts scoped reads, same-key
coexistence across shops, cross-shop IDs failing to resolve, throw-on-no-context, and
per-shop cache keys.

## Money rules (see also `.claude/skills/decant-money`)

- Integer Kyat columns only. No `decimal`, no `float`, no cents.
- The server re-derives `unit_price_mmk` from the live catalog at checkout and validates
  `is_active` / `in_stock`. The client's numbers are read-only inputs to a lookup, never
  a value to persist.
- `delivery_fee_mmk` comes from the serviceable township row, never from the request.
- Non-negativity is an **application-layer** guarantee. Postgres has no unsigned type and
  Laravel's Postgres grammar silently drops `unsignedInteger()`'s constraint. Read those
  migration calls as documentation. If a column ever becomes reachable by raw client
  input, add `CHECK (column >= 0)` rather than trusting the type.

## Postgres portability — the trap this repo has already hit

Tests run on **SQLite**; production runs on **Postgres 17**. SQLite tolerates two things
Postgres rejects, and both have shipped as bugs here before (issue #19):

1. `LIKE` is case-**insensitive** on SQLite and MySQL, case-**sensitive** on Postgres.
   Use `ILIKE` or `whereRaw('LOWER(col) LIKE ?')` for any user-facing search.
2. Postgres will not accept a `SELECT` alias inside an `ORDER BY` expression.

If your change adds or edits a query with `LIKE`, `ORDER BY`, or an alias, a green
`composer test` is **not** enough. Run:

```bash
php artisan serve --port=8010 &
API=http://localhost:8010/api/v1 sh scripts/verify-postgres-portability.sh
```

## Migrations

- Never edit a migration that has shipped. Add a new one.
- Name it by what it does: `2026_08_04_130000_create_expenses_table.php`.
- Adding a column that participates in an order total? It needs a snapshot decision
  written into the PR description: is it frozen at write, or live?
- Update the matching factory/seeder so `php artisan db:seed` still produces a catalog
  the verify scripts can exercise.

## Filament v5

- One directory per resource: `Filament/Resources/{Thing}/` containing
  `{Thing}Resource.php`, `Pages/`, `Schemas/{Thing}Form.php`, `Tables/{Thing}sTable.php`.
  Follow the existing shape exactly — do not flatten it.
- Custom pages go in `Filament/Pages/`, widgets in `Filament/Widgets/`.
- **Two panels.** `Filament/Resources|Pages|Widgets` belong to the tenant panel
  (`/admin/{shop}`, AdminPanelProvider); `Filament/Studio/Resources` belongs to the
  studio panel (`/studio`, StudioPanelProvider — no tenancy, `is_studio` users only).
  Anything cross-shop by nature (the shop registry, future all-shops views) lives in
  Studio; anything a single shop operates lives in the tenant panel. Never register
  a tenant-owned model's resource in Studio without the design saying so.
- Widgets that show money must state their coverage honestly in the description
  (e.g. "fully-costed orders only, N of M") rather than quietly under-reporting.
- The admin is used on a phone. Tables need sensible mobile column priorities.

## Tests

`tests/Feature/` is the real suite — 22 files, and it is the primary feedback signal
for an agent working here. Write the test in the same PR as the behaviour.

Something needs a Feature test when it:
- adds or changes an API endpoint, its validation, or its response shape
- touches money: pricing, promo codes, totals, margin, P&L, courier float
- changes an order status transition or who is allowed to trigger it
- adds a Filament action with a side effect (Accept, Reject, markPaid, CSV import)

Naming follows the existing files: `PromoCodeTest`, `DeliveryZoneTest`, `PublicApiTest`.
Run one with `php artisan test --filter=DeliveryZoneTest`.

## Public API surface — `/api/v1/{shop}`

Every path below sits under `/api/v1/{shop}` (multi-tenancy Step 24): `ResolveTenant`
turns the slug into the tenant, and an unknown or inactive shop is a generic 404.
Reads are throttled as one bucket; each public write has its own bucket so one cannot
starve another. Keep that separation when adding an endpoint.

| Method | Path (under `/api/v1/{shop}`) | Throttle |
|---|---|---|
| GET | `/brands`, `/fragrances`, `/fragrances/{slug}`, `/meta`, `/delivery-zones` | `catalog` |
| POST | `/orders` | `checkout` |
| GET | `/orders/track` | `tracking` |
| POST | `/orders/cancel` | `cancel` |
| POST | `/orders/payment-proof` | `payment-proof` |
| POST | `/orders/validate-promo` | `promo` |

Tracking and cancel must return the same generic failure for a wrong code and a wrong
phone — never leak which field was right.

## Do not touch without saying so first

- the tenancy seam: `app/Support/TenantContext.php`, `app/Models/Concerns/BelongsToShop.php`,
  `app/Exceptions/TenantNotSetException.php`, the tenant middleware
- shipped migrations, `app/Enums/**`, `config/**`
- `.github/workflows/tests.yml`
- another feature's Filament resource while working on yours
- `routes/api.php` throttle groups

## Commands

```bash
composer test                                    # full suite
php artisan test --filter=SomeTest               # one file
php artisan test --filter=TenantIsolationTest    # the isolation boundary
./vendor/bin/pint                                # format
php artisan migrate:fresh --seed                 # rebuild local data
API=http://localhost:8010/api/v1 sh scripts/verify-postgres-portability.sh
```
