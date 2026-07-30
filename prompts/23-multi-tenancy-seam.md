# Step 23 — Multi-tenancy A: the data seam

Follow the current `CLAUDE.md` and `prompts/multi-tenancy-design.md` (ADR-002, §7, §8,
§9 Step A) with `prompts/multi-tenancy-findings.md` as the evidence base. **Backend
only.** Behaviour is unchanged from every user's perspective — one shop before, one
shop after; no UI, no onboarding, no theming, no route changes (that's Step 24).

## 0. Why this step exists

Every table, cache key, and storage path in the system assumes exactly one decanter.
This step installs the tenancy seam — `shops`, `shop_id`, a throwing scope — **now,
while the production dataset is small**, so the later steps are pure addition instead
of a migration against a grown business's financial records. Production is already
live (real catalog, real orders), so the backfill is written as a real migration, not
a disposable seed.

## 1. First commit: the two-shop isolation suite

`tests/Feature/TenantIsolationTest.php`, transcribed from design-doc §8 — **committed
first, before any seam code**, so the branch's history shows the tests drove the
implementation (they fail/error until the seam lands; CI gates the PR head, not each
commit). Every assertion follows the Telegram-double-send lesson: **assert exact
counts, never presence**. The §8 table is the checklist: catalog counts, cross-shop
tracking 404, cross-shop proof route, admin table, every dashboard widget, CSV export,
promo codes, **meta-cache isolation + per-shop busting**, **both shops create brand
"Chanel"**, unscoped-query-throws, and the `withoutTenancy()` escape hatch (works, and
is used in ≤ 5 places repo-wide — grep-assert that). Until Step 24 adds `{shop}` paths,
API-level tests set the tenant via `TenantContext` directly; Step 24 rewrites them
against real paths.

## 2. Schema + backfill (one deploy, unattended)

- `shops` — `id`, `slug` (unique, indexed), `name`, `is_active`, timestamps. Minimal:
  contact/Telegram/theming columns wait for Step 25.
- `shop_id` (FK, indexed) on `brands`, `fragrances`, `decant_prices`, `orders`,
  `order_items`, `promo_codes`, `shop_settings` — per design-doc §7, including the
  denormalised ones (`order_items`, `decant_prices`) and a **unique index on
  `shop_settings.shop_id`** (one settings row per shop).
- **Backfill in-migration** (Heroku's release phase runs `migrate --force` unattended —
  design-doc N5): create the one shop — slug from `SHOP_SLUG` env (add to
  `.env.example`, compose, and Heroku *before* promoting; it must match Step 24's
  `NEXT_PUBLIC_SHOP_SLUG`) — point every existing row at it, then make `shop_id`
  NOT NULL. `down()` reverses column-by-column; note in the PR that rollback is only
  free until operator data lands in the new columns.
- **Slug composites — the highest-priority item:** drop the global uniques on
  `brands.slug` and `fragrances.slug`, add `(shop_id, slug)` composites.
  `HasSlug::generateUniqueSlug()` needs no code change — under the scope its dedup
  loop narrows per-shop, which now *matches* the constraint. (Without this, the second
  shop's `CatalogImport` dies on "Chanel" mid-onboarding — findings Q5.)
- Tracking codes stay **globally** unique (index unchanged); see §4.

## 3. `TenantContext` + `BelongsToShop`

- `TenantContext` (scoped singleton): `set(Shop)`, `get()`, `has()`, `id()`, and
  `withoutTenancy(callable)` — flips a bypass flag, restores it in a `finally`.
- `BelongsToShop` trait on all seven models: a global scope that **throws a dedicated
  `TenantNotSetException` when no tenant is set** (unless bypassing — then it applies
  nothing, deliberately), else filters by **`$model->qualifyColumn('shop_id')`** —
  never a bare column: joins don't apply the joined model's scopes, and an unqualified
  `shop_id` in `TopFragrances`' join is "ambiguous" on Postgres while SQLite stays
  green (ADR-002). Plus a `creating` hook that fills `shop_id` from the context — this
  is what keeps `ShopSetting::current()`'s `firstOrCreate([])` working unchanged.
- The scope's throw is the whole point (ADR-002 Option C): a forgotten filter is a
  500 in CI, not a silent leak.

## 4. The one `withoutTenancy()` in domain code

`Order::generateTrackingCode()` wraps its dedup loop in `withoutTenancy()` with an
inline comment: the `exists()` check must see **every** shop's codes or the retry
never fires and a cross-shop collision becomes an unretried `QueryException` from the
global index (findings Q2). `findByTracking` is untouched — the scope narrows it
per-shop automatically.

## 5. Interim tenant resolution (explicitly temporary)

With no `{shop}` in any URL yet, requests must still get a tenant or everything
throws:

- **API:** a `SetDefaultTenant` middleware on the api group — resolves the sole shop
  by `SHOP_SLUG` (cached) and sets the context *only if unset* (so tests can pre-set).
  **Replaced by `ResolveTenant` in Step 24** — say so in a comment.
- **Panel:** the same context set via a panel middleware registered
  `isPersistent: true`, so Livewire widget-refresh AJAX carries it too (the vendor's
  own tenancy warning — findings Q3). Replaced by Filament tenancy in Step 25.
- Console: nothing implicit — see §7.

## 6. Per-shop caches and storage

- Cache keys become `api.meta.{slug}` / `api.brands.{slug}` (`MetaController`,
  `BrandController` build them from the context). Busting follows: `ShopSetting`'s
  `saved` hook forgets **its own shop's** meta key; `decant:fresh-start` forgets the
  target shop's pair. The v14 payment block makes this real money data — one global
  key would serve shop A's KBZPay/Wave numbers to shop B for 10 minutes (findings Q5).
- New writes go under `{shop-slug}/…` at all three sites: fragrance image uploads,
  checkout's slip store (`{shop}/payment-proofs/…` on the proofs disk), ManagePayment's
  MMQR (`{shop}/payment-qr/…` on the media disk). Serving is unchanged — full paths
  live in the DB.
- **Existing objects:** a one-shot `decant:migrate-storage-prefixes` command (guarded,
  `--dry-run` default) moves current objects under the shop's prefix and rewrites the
  DB path columns (`image_path`, `payment_proof_path`, `payment_qr_path`). Run
  manually post-deploy — a release-phase object move against R2 is the wrong place to
  fail. Until it runs, old unprefixed paths keep serving; note the run in DEPLOY.md's
  promotion notes.

## 7. Console + seeding

- Per ADR-002: `decant:fresh-start` takes `--shop=` and **refuses to run without it**;
  it sets the context, so its Eloquent bulk deletes auto-scope, and its proof-directory
  wipe and cache-busts go per-shop. `telegram:test` stays config-based until Step 25
  (ADR-003).
- Seeders/factories set the context (default shop) before creating tenant-owned rows.

## 8. Real-engine checks

Extend `verify-postgres-portability.sh`: the `(shop_id, slug)` composites and the
TopFragrances join are exactly the SQLite-green/Postgres-broken class the script
exists for. The widget sits behind panel auth, so curl can't reach it — add a small
hidden `decant:probe-postgres` command that runs the TopFragrances subquery and the
production-schedule aggregation under a set tenant and exits non-zero on a
`QueryException`; the script invokes it when it can (docker compose), `SKIP`s
otherwise.

## 9. Tests beyond the suite

`php artisan test` stays green throughout — every existing test runs single-shop and
must pass unmodified except where it constructs tenant-owned rows without context
(fix by setting the default tenant in `TestCase` or per-test, mirroring production's
interim middleware). That "existing tests still pass" is itself the no-behaviour-change
proof.

## 10. Not in scope

Path-prefix routing and `lib/api.ts` (Step 24); Filament tenancy, `HasTenants`,
`shop_user`, shop CRUD, per-tenant Telegram, theming (Step 25); any second shop row
in production.
