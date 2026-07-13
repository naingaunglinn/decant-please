# Step 2 — Database schema, models & seeders (backend) — v2

Work only in `backend/`. Follow `CLAUDE.md` v2 domain model exactly. This file is the
full, standalone schema for a fresh build — sections marked **(unchanged)** are
included for completeness even though nothing about them changed from v1.

## 1. Enums (`app/Enums/`)

PHP 8.3 backed string enums, each with a `label(): string` helper:

- `BrandType`: `designer`, `niche` — **(unchanged)**
- `Gender`: `male`, `female`, `unisex` — **(unchanged)**
- `Concentration`: `edt`, `edp`, `parfum`, `cologne`, `extrait`, `other` — **(unchanged)**
- `OrderSource`: `website`, `tiktok`, `facebook`, `other` — **`website` is new**,
  represents a checkout submitted through the Next.js site; the other three are
  orders the decanter typed in manually from a DM
- `OrderStatus`: `awaiting_confirmation`, `pending`, `decanted`, `delivered`,
  `cancelled`, `rejected` — **`awaiting_confirmation` and `rejected` are new.**
  Add a `color(): string` helper for Filament badges:
  `awaiting_confirmation` = warning (amber), `pending` = info, `decanted` = info,
  `delivered` = success, `cancelled` = gray, `rejected` = danger.
  Add an `isTerminal(): bool` helper (`true` for `delivered`, `cancelled`, `rejected`)
  and an `isFulfillable(): bool` helper (`true` for `pending`, `decanted`, `delivered`)
  — the production schedule and revenue widgets should only ever count fulfillable /
  non-terminal-negative statuses (never `cancelled` or `rejected`).

## 2. Migrations

### `brands` — **(unchanged)**
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string, unique | |
| slug | string, unique, indexed | |
| type | string | BrandType, default `designer` |
| logo_path | string nullable | |
| is_active | boolean default true | |
| timestamps | | |

### `fragrances` — **(unchanged)**
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| brand_id | fk → brands, cascadeOnDelete | |
| name | string | |
| slug | string unique, indexed | |
| concentration | string | Concentration enum |
| gender | string, indexed | Gender enum |
| notes | text nullable | "Citrus, Musk, Amber, Orange, Grapefruit" |
| vibes | text nullable | "Modern, Clean, Alluring, Classy" |
| performance | string nullable | "Around 6-8 Hours" |
| description | text nullable | |
| image_path | string nullable | |
| is_active | boolean default true, indexed | |
| is_featured | boolean default false | |
| timestamps | | |

### `decant_prices` — **(unchanged)**
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| fragrance_id | fk → fragrances, cascadeOnDelete | |
| size_ml | unsignedSmallInteger | 5 / 10 / 30 / custom |
| price_mmk | unsignedInteger | whole Kyat |
| in_stock | boolean default true | |
| timestamps | | |
| unique(fragrance_id, size_ml) | | one price per size per fragrance |

### `orders` — **changed**
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| customer_name | string | |
| phone | string | |
| address | text | |
| order_from | string, indexed | OrderSource — default `website` |
| **tracking_code** | **string, unique, indexed** | **new.** ~10-char random alphanumeric (Laravel's `Str::random()` filtered to unambiguous characters — avoid `0/O/1/I`), generated in a model `creating` hook. Retry-on-collision loop (extremely unlikely, but the unique constraint should never be allowed to throw a raw DB exception up to the customer). |
| decant_date | **date, nullable**, indexed | **now nullable.** The day the decanter must physically decant this order. Set by the decanter on Accept for website orders; still required at creation time for manual admin entries (form-level requirement, not a DB one, since the column itself must allow null). |
| delivery_date | date nullable, indexed | unchanged |
| status | string, indexed, default `awaiting_confirmation` | OrderStatus |
| **rejection_reason** | **string nullable** | **new.** Set when status becomes `rejected`. |
| deposit_mmk | unsignedInteger default 0 | unchanged |
| delivery_fee_mmk | unsignedInteger default 0 | unchanged |
| discount_mmk | unsignedInteger default 0 | unchanged |
| total_mmk | unsignedInteger default 0 | unchanged — items + delivery − discount |
| notes | text nullable | unchanged |
| timestamps | | |

### `order_items` — **(unchanged)**
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| order_id | fk → orders, cascadeOnDelete | |
| fragrance_id | fk → fragrances, **restrictOnDelete** | keep history intact |
| fragrance_name_snapshot | string | brand + name at order time |
| size_ml | unsignedSmallInteger | |
| unit_price_mmk | unsignedInteger | **snapshot**, editable by admin, never trusted from checkout client |
| quantity | unsignedSmallInteger default 1 | |
| line_total_mmk | unsignedInteger | unit × qty |
| timestamps | | |

## 3. Models & relationships

- `Brand`, `Fragrance`, `DecantPrice` — **(unchanged)**, see v1 for full detail
  (auto-slug, `minPrice()`, `active()` scope, ordered `decantPrices` relation).
- `Order` hasMany `OrderItem`; casts enums + dates.
  - `recalculateTotal(): void` — unchanged: sum(line totals) + delivery_fee − discount,
    floored at 0.
  - **New:** a `creating` hook that generates `tracking_code` if not already set (loop
    on unique-constraint collision, cap retries at ~5 and throw a clear exception if
    that's ever exhausted — it shouldn't be, but fail loud rather than silently
    duplicate).
  - **New:** a `newFromCheckout(array $data): self` static helper (or an
    `OrderService`/action class, your call) that is the *only* path website orders go
    through — it re-derives every price server-side, sets `order_from = website`,
    `status = awaiting_confirmation`, leaves `decant_date` null. Keep this distinct
    from the Filament manual-entry path, which sets `status = pending` and requires
    `decant_date` at the form level.
  - **New:** an `accept(Carbon $decantDate, ?Carbon $deliveryDate): void` and a
    `reject(string $reason): void` method encapsulating the status transition —
    call these from the Filament actions in Step 4 rather than setting `status`
    directly, so the transition rules live in one place.
- `OrderItem` — unchanged: belongsTo `Order` and `Fragrance`; on saving, compute
  `line_total_mmk = unit_price_mmk * quantity`.

`FormatsKyat` / `Money` helper — unchanged: `kyat(int $amount): string` → `"90,000 Ks"`.

## 4. Factories & seeders

Same as v1 (admin user, ~8 brands, ~20 fragrances including the Chanel Allure Homme
Sport reference example, 2-3 decant prices each), with these additions:

- Seed orders across a **mix of `order_from` values including `website`**, not just
  `tiktok`/`facebook`/`other`.
- Seed a handful of orders in `awaiting_confirmation` (so the admin "Needs review" tab
  has something to show on first login) and at least one in `rejected` (with a
  `rejection_reason`) so the status badge palette is visible end to end.
- Every seeded order gets a `tracking_code` via the model hook — don't hardcode these.

## 5. Verify

Run `php artisan migrate:fresh --seed`, then via `php artisan tinker` or a small test,
confirm:
- a fragrance returns its prices sorted by size; `minPrice()` works,
- `Order::recalculateTotal()` produces correct totals,
- **new:** creating an order without an explicit `tracking_code` generates a unique
  one; creating a second order can't collide (simulate by forcing a retry if you want
  to be thorough, but don't over-engineer this check),
- **new:** `accept()` sets `pending` + both dates; `reject()` sets `rejected` +
  `rejection_reason` and leaves `decant_date` untouched (null, if it was never set).

Report results. Filament changes are Step 3 (unchanged) and Step 4 (updated below).

---

## Applying v2 to an existing (already-migrated) build

If you already ran v1's migrations and have real or seeded data you don't want to
lose, do **not** edit the original `orders` migration file. Instead:

1. `php artisan make:migration add_v2_fields_to_orders_table --table=orders` — add
   `tracking_code` (nullable at first, backfill, then add the unique index),
   `rejection_reason`, and alter `decant_date` to nullable.
2. Backfill `tracking_code` for existing rows in the migration itself (loop existing
   orders, generate + save) before adding the unique constraint, or the constraint
   will fail on existing null/duplicate rows.
3. Update the `OrderStatus` enum in code — existing rows already have valid old
   values (`pending`, `decanted`, `delivered`, `cancelled`), which remain valid; you're
   only adding two new cases, not remapping existing ones.
4. Backfill `order_from` is not required — `tiktok`/`facebook`/`other` remain valid
   enum cases alongside the new `website`.
