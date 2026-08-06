# Multi-tenancy — codebase findings (the Q1–Q6 audit)

**What this is:** the investigation report that preceded the multi-tenancy build steps —
six questions answered from the codebase and the installed vendor code, no edits made.
It is the reference record for the Filament-tenancy vendor read, the raw-SQL inventory,
and the tenant-owned/global model table; `prompts/multi-tenancy-design.md` was amended to
match these findings (its status header records the resolutions), and `prompts/23`–`25`
build on them.

**Audited tree:** `develop` at `a35fd67` — v14, step 22 (online payment method) merged.
Filament **v5.6.8** as installed in `vendor/`, not as documented. 30 July 2026.

Statements below about what "the doc" lacks or assumes describe the design doc *as it
stood at audit time*; the amendments in the same PR resolve them. One finding (Q6's
default-branch inference) was itself wrong and is corrected inline, marked as such.

---

## 1. `design-tokens.json` vs `globals.css` `@theme`

**No disagreeing values, and neither is generated from the other.** All ten colors and
the font stack are byte-identical between the two files (and match CLAUDE.md §3). There
is no generation step anywhere — `frontend/package.json` has only `dev/build/start/lint`,
nothing imports the JSON — and the JSON's own `description` states the actual contract:
it "mirrors the @theme block… if a value changes, change it in globals.css and here in
the same commit." A hand-maintained mirror held together by convention: in sync today,
structurally able to drift. Step 22 touched neither file.

One scope gap rather than a conflict: `globals.css:38-42` defines motion tokens
(`--duration-exit: 150ms`, `--duration-enter: 210ms`, `--duration-move: 400ms`,
`--slide-y-offset`) on `:root` outside `@theme`, absent from the JSON — so the JSON's
claim to be the portable token copy is already false for motion. Nothing exists in the
JSON that the CSS lacks. (Those `:root` declarations outside `@theme` are also exactly
the override mechanism the per-tenant `ThemeProvider` needs — the pattern already
exists in the codebase.)

## 2. Tracking-code generation

**Random per order *and* globally unique — both, by two mechanisms**
(`Order.php:318-333`): 10 chars from a 32-char alphabet (no `0/O/1/I`) via `random_int`
(CSPRNG), space = 32¹⁰ ≈ **1.13 × 10¹⁵**; an app-level dedup loop (up to 5 attempts,
each checking `self::where('tracking_code', $code)->exists()`, then `RuntimeException`);
and a **global DB unique index** (`create_orders_table.php:17`).

Collision math is negligible (at 100k existing orders, ≈ 9 × 10⁻¹¹ per new code). The
multi-tenant finding is **where the dedup check runs**: it's a plain `self::where(...)`,
so under the proposed tenant global scope it silently narrows to *the current shop's*
codes. Global uniqueness would then rest solely on the DB index — and a cross-shop
collision surfaces as an **unretried `QueryException`** (the loop only retries when
`exists()` returns true), not a graceful retry. The doc's "do both" holds: keep the
index global (generation then needs `withoutTenancy()` or acceptance of
constraint-as-backstop) *and* scope `findByTracking` (`Order.php:287`, `self::query()` —
the global scope covers it automatically). A `(shop_id, code)` composite instead would
make cross-shop duplicate codes possible and safe *only* while every lookup is scoped.

## 3. Filament multi-tenancy — verified in vendor (v5.6.8 installed; `composer.lock` unchanged by step 22)

**Fully supported, and by a stronger mechanism than the design doc assumed — with
sharply defined edges.** API surface (`vendor/.../Panel/Concerns/HasTenancy.php`):
`->tenant(Model::class, slugAttribute:, ownershipRelationship:)`,
`tenantRoutePrefix/Domain`, `tenantMenu/tenantSwitcher`,
`tenantProfile/tenantRegistration`, billing hooks, `resolveTenantUsing()`. Resolution is
the `IdentifyTenant` middleware: fires **only on routes carrying a `{tenant}`
parameter**, requires the user model to implement `HasTenants` (`canAccessTenant()`,
`getTenants()`) — 404 otherwise. The current `User` implements only `FilamentUser`, so
that's a required change.

The part that revises ADR-002: Filament v5 doesn't merely filter panel queries.
`Panel.php:88-89` registers, per tenant-scoped resource, a **genuine Eloquent global
scope** (`{panelId}_tenancy`) on the resource's *model* (`whereBelongsTo($tenant)` /
`whereMorphedTo` / `whereHas` fallback), plus a `creating` observer that auto-fills the
tenant. So inside the panel, any Eloquent query on a resource-backed model — widgets
included — gets scoped.

What it does **NOT** scope (from vendor code and its own embedded security comments):

- **Anything outside an active panel + resolved tenant** — the scope closure silently
  no-ops (returns all rows) when `Filament::getCurrentPanel() !== $panel` or no tenant
  is set: the public API, artisan, queues, tests, and "queries in earlier middleware or
  service providers" (vendor comment). Exactly ADR-002 Option B's silent-leak default,
  built in.
- **Models with no tenant-scoped Resource** — registration is per-resource. This app has
  Resources for Brand, Fragrance, Order, PromoCode only, so **`OrderItem`,
  `DecantPrice`, and now `ShopSetting` never receive Filament's scope**, even inside
  the panel. Custom pages (`ProductionSchedule`, the new `ManagePayment`) run whatever
  queries they like with no Filament scoping of their own.
- **`unique()`/`exists()` validation rules** — bypass global scopes; vendor ships
  `scopedUnique()`/`scopedExists()`. (Still zero such rules in `app/` — step 22's
  `Rule::requiredIf` is not a DB rule — so future-only.)
- **`DB::table()`, joined tables, storage paths, cache keys** — no model, no scope.
  Resources can also opt out via `scopeToTenant(false)`, and
  `Resource::getEloquentQuery()` actively strips the scope for opted-out resources.
  Livewire AJAX needs the tenant middleware `isPersistent: true` per the vendor comment,
  or widget-refresh requests run un-identified.

Vendor's literal words: *"Filament does not guarantee multi-tenant security; it is your
responsibility to implement correctly."* Belt-and-braces survives contact with the
source.

## 4. Eloquent models — tenant-owned or global (8 as of v14)

| Model | Marking | Notes |
|---|---|---|
| `Brand` | tenant-owned | On §7's `shop_id` list; has a Resource → Filament-scopable |
| `Fragrance` | tenant-owned | Same |
| `DecantPrice` | tenant-owned — **transitively only** | **Not on §7's `shop_id` list at audit time**, no Resource → neither mechanism ever scopes it directly; safety hangs on `whereHas('fragrance')` chains |
| `Order` | tenant-owned | `shop_id` list; Resource |
| `OrderItem` | tenant-owned | No Resource → Filament never scopes it; the doc's denormalised `shop_id` is what makes it directly scopable — keep it |
| `PromoCode` | tenant-owned | `shop_id` list; Resource |
| `ShopSetting` **(new, v14)** | tenant-owned in substance | Payment details — the very columns §7 originally folded into `shops`. Today a `firstOrCreate([])` singleton (`ShopSetting::current()`), **tenant-blind**: under multi-tenancy it either dissolves into `shops` columns or needs `shop_id` + a scoped `current()` — note a bare global scope can't parameterize `firstOrCreate([])`'s create side; the trait's auto-fill hook has to. *(Resolved since: keep the table, add `shop_id` + a unique index — see the design doc §7.)* |
| `User` | global | Cross-shop via the proposed `shop_user` pivot; must gain `HasTenants` |

(`Concerns/HasSlug` is a trait, not a model — but see the slug finding under Q5.)

## 5. Tenant-owned queries a global scope would NOT cover

Raw-SQL inventory on the audited tree: three `DB::transaction` wrappers (plumbing only —
every inner query is Eloquent, covered), two `orderByRaw(... NULLS LAST)` sorts on
scoped Fragrance queries, and **one genuine hand-built join**:

**`TopFragrances.php:22-28`** — correlated subquery
`OrderItem::query()->selectRaw(...)->join('orders', ...)`. Three separate problems:
(a) base is `OrderItem` — unscoped unless OrderItem itself gets the trait; (b) **joins
never apply the joined model's global scopes**, so the `orders` side stays raw
regardless; (c) the **ambiguity landmine** — once both tables carry `shop_id`, an
unqualified `where('shop_id', …)` from the scope makes this widget throw "column
reference is ambiguous" *on Postgres only* (SQLite tolerates it). The `BelongsToShop`
scope must use `qualifyColumn()`, and this widget belongs in
`verify-postgres-portability.sh`.

**Covered, for the record** (Eloquent on scope-bearing models, provided tenant context
is set during panel/Livewire requests): OrderStats, RevenueChart, UpcomingDecants,
LowStock; CSV export and bulk invoice PDF (both build on
`getFilteredSortedTableQuery()` → the scoped resource base query); invoice +
payment-proof controllers (implicit route-model binding goes through global scopes — the
*app* scope is what closes the doc's "highest-severity line"; Filament's own coverage of
those custom `authenticatedRoutes()` bindings depends on the `{tenant}` param and
middleware ordering — verify at implementation); `ProductionSchedule` (needs OrderItem's
own scope; its `whereHas('order')` does apply Order's scope — relationship subqueries,
unlike joins, honor them); checkout's `currentPriceFor` (a client-sent `fragrance_id` is
neutralized by the scoped `whereHas('fragrance')`); `PromoCode::evaluate`;
`CatalogImport`; the new `Order::stockShortfalls()` (relationship loads only) and the
`payment_method` column/filter.

**Layers no Eloquent scope will ever touch — same isolation class:**

- **Global cache keys** — `Cache::remember('api.meta', 600)` and `'api.brands'`. **v14
  raised the stakes**: `api.meta` now caches the DB-backed payment block (numbers + MMQR
  URL), and the new bust hook (`ShopSetting.php:22`, `saved → Cache::forget('api.meta')`)
  targets the single global key. Per-shop keys and per-shop busting both needed.
- **Filesystem writes with shared prefixes** — now **three** sites: `FreshStart.php:42`
  `deleteDirectory('payment-proofs')` (wipes every shop's proofs); **new in v14:**
  checkout itself stores slips (`OrderController`: `store('payment-proofs',
  proofs_disk)`) and `ManagePayment` uploads MMQR images to `payment-qr/` on the public
  media disk (URL exposed via `qrUrl()`). All need the doc's `{shop}/…` layout enforced
  in code.
- **`ShopSetting::current()` in the public API path** — `firstOrCreate([])` inside the
  meta cache closure returns *the first row globally*; scope-covered as a query, but its
  create side and singleton semantics are tenant-blind (see Q4).
- **Config reads as tenant data** — v14 half-fixed payment (DB with `PAYMENT_*` env
  fallback — the fallback itself is per-app and must not leak into other shops' meta);
  social links and Telegram creds are still pure config (ADR-003 covers Telegram).
- **Console context** — FreshStart's Eloquent bulk deletes are scope-covered, which
  under the throwing scope means they throw without a tenant: the doc's mandatory
  `--shop` confirmed; seeders/factories same.

**Standing finding the doc lacked — slugs block F6.** `brands.slug` and
`fragrances.slug` are globally unique (`…120001:14`, `…120002:15`), and
`HasSlug::generateUniqueSlug()` dedups via a plain `static::where('slug')->exists()`
loop — identical shape to tracking codes but with a *guaranteed* collision: the second
shop to carry "Chanel" passes the tenant-narrowed check, then dies on the global unique
index with an unhandled `QueryException`, likely mid-`CatalogImport` during onboarding.
Slugs are namespaced per shop by the path prefix anyway — both indexes should become
`(shop_id, slug)` composites in Step A, alongside the tracking-code decision.

## 6. Vercel Production Branch

**Not discoverable from the repo.** No `vercel.json` (root or `frontend/`), no
`.vercel/` directory, no Vercel CLI on this machine. DEPLOY.md §2 stops at "Import the
repo… Root Directory `frontend/`… Deploy" — the Production Branch setting is never
mentioned repo-wide.

> **Correction.** The audit originally inferred "likely `main`" from "Vercel defaults
> the Production Branch to the repo's default branch, and this repo's default is
> `main`." That premise is wrong: **this repo's default branch is `develop`**
> (`gh repo view --json defaultBranchRef`). The likely-`main` conclusion still holds,
> on different evidence: (1) Vercel captures the Production Branch **at import time**,
> and the project was imported at step 13 — before step 17 created the `develop`/`main`
> split, when `main` was the only branch; (2) on promotion PR #54, the Vercel bot
> labelled the deployment of the `develop` head as **Preview** (the
> `…-git-develop-….vercel.app` branch alias), which rules `develop` out as the
> Production Branch — a `develop` push would otherwise have produced a Production
> deployment.

Still inference, not confirmation: the setting exists only in the dashboard
(Settings → Git → Production Branch) or `vercel project inspect`. If it *were*
`develop`, the storefront would ship pre-promotion — and the unpromoted step-22
frontend changes on develop make that distinction live, not academic. Worth checking
and writing into DEPLOY.md before a second project multiplies the ambiguity — ADR-004's
note stands.

---

## Where each finding landed

| Finding | Resolution (recorded in the design doc) |
|---|---|
| Q1/Q5 mirror + motion tokens | §10: generate `design-tokens.json` from `globals.css` or delete it; `:root`-outside-`@theme` is the ThemeProvider mechanism |
| Q2 dedup narrows under scope | §7: keep the global unique index; wrap the dedup loop in `withoutTenancy()` — one justified escape hatch |
| Q3 Filament edges | ADR-002 unchanged in conclusion (belt and braces), corrected in mechanism; `User` gains `HasTenants` in Step C |
| Q4 DecantPrice / ShopSetting gaps | §7: both get `shop_id` (ShopSetting keeps its table + unique index on `shop_id`) |
| Q5 join + ambiguity | ADR-002: `qualifyColumn()` required; TopFragrances added to `verify-postgres-portability.sh` |
| Q5 cache keys | §7: per-shop keys + per-shop busting, in Step A |
| Q5 storage prefixes | §7: `{shop}/…` on all three write sites, in Step A |
| Q5 slugs | §7: `(shop_id, slug)` composites — highest-priority Step A item |
| Q6 Production Branch | ADR-004: evidence corrected, still flagged unverified |
| A1–A6 (below) | §7 `shop_id` list + delivery-zones decision; §7 cache keys; §8 isolation rows; Step 23 §2/§6/§7 |

---

## v18–v22 addendum — finance + delivery-zones surfaces

**Why this exists:** the Q1–Q6 audit above was taken at `a35fd67` (v14). The finance +
delivery-zones layer landed since (v19 cost/margin #66, v20 expenses/P&L #76, v21
delivery zones #80; v18/#64 and v22/#82 add no tenant data). This addendum re-runs the
"what new surface would a tenant scope miss" question against **`develop` at `eff5c91`
— v22**, on branch `57-multi-tenancy-spec` after the develop sync. Same method, same
"assert counts not presence" discipline. `shop_id` still exists nowhere in the tree —
all of this reads/writes globally today.

### A1. `expenses` — a clean new tenant-owned table
`create_expenses_table.php:21-28`: `spent_on` (date, indexed), `category`
(→`ExpenseCategory`), `amount_mmk`, `note`. **No FK, no unique index.** One decanter's
operating ledger; `MonthlyPnl` reads it per month (`MonthlyPnl.php:74-79`).
**Resolution:** `shop_id` + `BelongsToShop`, exactly like `orders`. Backed by
`ExpenseResource` (`ExpenseResource.php:18`, model `Expense`) → Filament auto-scopes the
CRUD once the trait lands. On §7's `shop_id` list; on `decant:fresh-start`'s per-shop
reset (which today doesn't touch it — A6).

### A2. Delivery zones — geography is global, price/activation is per-shop (decided)
The crux question — is township data intrinsically the same across shops? — resolves
**yes**, on three pieces of evidence:
- **Identity has no shop dimension.** `delivery_townships` uniques on
  `(region, name)` (`create_delivery_zones_tables.php:31`); the child uniques on
  `(delivery_township_id, courier)` (`:49`). Both say "one row per Myanmar township,
  globally."
- **Geography is seeded from committed national CSVs**, not shop input:
  `DeliveryZoneSeeder.php:54-55` loads `database/data/delivery-townships*.csv` (Royal
  Express's coverage chart); the migration calls it "geography, not a shipping promise"
  (`create_delivery_zones_tables.php:14`). Every seeded row is `is_active=false,
  fee_mmk=0` (`DeliveryZoneSeeder.php:78`) — geography carries no price.
- **The per-shop columns are named as such:** `fee_mmk` "What the customer is charged",
  `is_active` "Offered at checkout" (`DeliveryZoneForm.php:44,52`); the whole
  `delivery_township_couriers` row (`courier_name` alias, `cost_mmk` reference,
  `is_available`) is the shop's own supplier relationship.

So geography = `region, district, name, name_mm` (global); per-shop = `fee_mmk,
is_active, sort_order` + all courier rows.

**Decision — plain `shop_id` on both tables (geography duplicated per shop), NOT a
global-geography + overlay table.** `delivery_townships` and
`delivery_township_couriers` each get `shop_id`; the uniques become
`(shop_id, region, name)` and `(shop_id, delivery_township_id, courier)`; the national
seed is copied into each shop (all inactive/fee 0) at onboarding. **Rejected: keeping
`delivery_townships` global-reference and moving `fee_mmk`/`is_active` into a
`(shop_id, township_id)` overlay table.** The overlay is the more normalized model, but
it forks the seam's one-mechanism design (a class of tenant tables that *don't* get the
trait, plus a join on every read), rewrites v21's just-shipped admin UI / checkout
lookup / public API, and buys nothing at this scale — duplicating ~228 geography rows
per shop is what onboarding seeds anyway. Consistent with the doc's own precedents
(`ShopSetting` kept its table + `shop_id`; `decant_prices` got a denormalized `shop_id`;
v8 chose total-ml over a `bottles` table). `DeliveryTownshipCourier` has **no Resource**
(edited via a `Repeater()->relationship()` + a bulk action — `DeliveryZoneForm.php:53`,
`DeliveryZonesTable.php:165`), so Filament never scopes it directly; its `shop_id` +
trait (or scope via the parent) is what protects it.

### A3. New columns ride existing tenant models — cost stays admin-only
`fragrances.bottle_cost_mmk/bottle_volume_ml` (`add_cost_to_fragrances_table.php:24-25`),
`order_items.unit_cost_mmk/line_cost_mmk` (`add_cost_to_order_items_table.php:24-25`),
`orders` COD-float (`handed_to_courier_at, courier_carrying_mmk, courier_settled_at`)
and delivery columns (`delivery_township_id` FK, `region_snapshot, township_snapshot,
address_line, address_extra, delivery_courier`). All on already-tenant-owned models →
covered by their scope for free. **Cost/supplier columns never cross the public API** —
a grep of `app/Http` for any cost column returns nothing; they appear only in the admin
CSV export, form placeholders, and the fragrance table. The one integrity watch is
`orders.delivery_township_id` pointing at another shop's township — closed by A5.

### A4. Custom Finance pages are covered by the *app* scope, not Filament's — which is the point
`ProfitAndLoss` (`ProfitAndLoss.php:18`) and `ProductionScheduleDay`/`ProductionSchedule`
are custom `Page`s, so **Filament's** tenancy scope never reaches them (findings Q3). But
their data funnels through Eloquent on trait-bearing models: `MonthlyPnl` queries
`Order::query()` + `Expense::query()` (`MonthlyPnl.php:48,74`); the schedule pages both
call `Order::productionScheduleFor()`, which runs `OrderItem::query()->whereHas('order')`
(`Order.php:449`). Once those models carry `BelongsToShop` **and** the panel request has
a `TenantContext` (Step 23 §5's persistent panel middleware sets it), the throwing app
scope covers all three with **no per-page code** — this is exactly why ADR-002 chose a
throwing app scope over Filament-only. `Order::productionScheduleFor`'s own docblock
already anticipates it (`Order.php:434-436`). The finance widgets `DiscountCost`,
`CourierFloat`, and `OrderStats`' margin stat are plain `Order::query()` summed in PHP
(`DiscountCost.php:29`, `CourierFloat.php:22`, `OrderStats.php:35`) → auto-covered.
`TopFragrances` is unchanged by this layer but remains the one raw-join hazard (Q5): its
inner `OrderItem`→`orders` join (`TopFragrances.php:22-27`) needs `orders.shop_id` /
`order_items.shop_id` **qualified**, since a bare `shop_id` is ambiguous on Postgres.

### A5. One new global cache key + one new public endpoint, both shop-blind
- **`api.delivery-zones`** (TTL 600), built in `DeliveryZoneController.php:28`, busted at
  **four** sites: `DeliveryTownship` + `DeliveryTownshipCourier` `saved`/`deleted` hooks
  (`DeliveryTownship.php:29`, `DeliveryTownshipCourier.php:26`), `DeliveryZoneSeeder.php:59`,
  `FreshStart.php:63`. Same class as `api.meta` — one global key serves shop A's fees to
  shop B. **Resolution:** `api.delivery-zones.{slug}`, all four busts keyed per shop.
- **`GET /api/v1/delivery-zones`** (`routes/api.php:20`) — the **9th** public endpoint,
  missed by Step 24's "eight endpoints". Returns only `serviceable()` townships with
  `fee_mmk`; **no courier/cost leak** (controller docblock + field list confirm —
  `DeliveryZoneController.php:20-23,44-57`). Under tenancy it must return only the current
  shop's rows and cache per-shop; the `{shop}` prefix (Step 24) carries it.
- **Checkout township re-validation is already server-side** — `newFromCheckout` does
  `DeliveryTownship::query()->serviceable()->find($id)` and 422s on miss, reading the fee
  off the row not the client (`OrderController` / `Order.php:154`), mirroring the
  `fragrance_id` price re-derivation. **Gap:** that `find()` is shop-blind today; once
  `DeliveryTownship` carries the trait + the api request sets context, the scoped `find()`
  returns null for another shop's township → 422, closing it with no new code.

### A6. Console + seeders touch the new tables shop-blind
`FreshStart` bulk-resets **every** township (`DeliveryTownship::query()->update([...])`,
`FreshStart.php:56`) and busts the global zones cache (`:63`), but **does not touch
`expenses`** — a per-shop reset that wipes orders/fragrances/promos yet leaves that shop's
expense ledger is inconsistent. Step 23 §7's `--shop` reset must scope the township update
and **add expenses**. `DeliveryZoneSeeder` (wired in `DatabaseSeeder.php:31`) and
`OrderSeeder` (resolves a township via `firstOrFail`, `OrderSeeder.php:97`) both write the
new tables and must set the default-shop context like every other seeder.
