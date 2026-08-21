# Decant Please! — Backend

Laravel 13 application serving two things:

1. the **public JSON API** (`/api/v1/{shop}/*`) that the Next.js storefront in
   [`../frontend`](../frontend) consumes, and
2. the **admin panel** (`/admin`, Filament v5) where the decanter manages the catalog,
   reviews orders, and reads the daily production schedule.

Project-wide context lives in [`../README.md`](../README.md) (system overview),
[`../CLAUDE.md`](../CLAUDE.md) (spec / source of truth), and
[`../DEPLOY.md`](../DEPLOY.md) (production deployment).

**Requirements:** PHP 8.3+ · Composer · PostgreSQL 17+ — or none of those:
`docker compose up` at the repo root runs every step below automatically
(see the [root README](../README.md#getting-started)).

## Setup

```bash
composer install
cp .env.example .env        # set DB_* and ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed  # demo catalog + admin user
php artisan storage:link    # serve uploaded images from /storage
php artisan serve --port=8010   # http://localhost:8010 (8000 is taken by another local project)
```

Admin login: `admin@decantplease.local` / whatever `ADMIN_PASSWORD` was when you seeded.

## Environment variables

Config resolution is **shop settings row → this env default → off** through
`App\Support\ShopConfig` (step 33): the env values below are the **platform
default**, not the live value for a shop that set its own. A blank at both levels
means the feature is off for that shop.

| Variable | Per-shop override | Purpose |
|---|---|---|
| `APP_URL` | — | This app's own URL — image URLs in API responses are built from it |
| `FRONTEND_URL` | `shop_domains` (ADR-0004) | Storefront origin **platform default** — the CORS base (verified `shop_domains` rows merge in per request) and the "View on site" fallback for shops with no verified primary domain |
| `ADMIN_PASSWORD` | — | Read once by `db:seed` to create the admin user |
| `SOCIAL_TIKTOK_URL` / `SOCIAL_FACEBOOK_URL` | `shop_settings.tiktok_url` / `.facebook_url` | Exposed via `/api/v1/{shop}/meta` for the storefront footer; blank = hidden |
| `PAYMENT_KBZPAY_*` / `PAYMENT_WAVE_*` / `PAYMENT_QR_URL` / `PAYMENT_INSTRUCTIONS` | `shop_settings.*` (Payment settings page) | Offline transfer details exposed via `/api/v1/{shop}/meta`; blank fields hidden, whole block null when none set |
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_ADMIN_CHAT_ID` | `shop_settings.bot_token` / `.admin_chat_id` (encrypted) | New-order alerts to the decanter's Telegram (`php artisan telegram:test {shop}` verifies a shop); a shop with no bot token uses this platform bot; blank at both = off |
| `MEDIA_DISK` | — | Disk for uploaded images — `public` locally via `storage:link`, `s3` (Cloudflare R2) in production |
| `PROOFS_DISK` (+ `PROOFS_AWS_*`) | — | **Private** disk for payment-proof screenshots — `local` (`storage/app/private`) by default, a separate no-public-domain R2 bucket in production |
| `DB_*` | — | PostgreSQL connection. `DATABASE_URL` (not `DB_URL`) overrides them all — that's the name Heroku injects |

Production values and hardening (`APP_DEBUG=false`, `SESSION_SECURE_COOKIE`, forced
HTTPS, Heroku config vars, the R2 buckets) are covered in [`../DEPLOY.md`](../DEPLOY.md).

## Routes

**Public API — `/api/v1/{shop}`, JSON, rate-limited** — the shop slug resolves via `ResolveTenant`; unknown or inactive shops get a generic 404

| Method | Route | Purpose | Throttle |
|---|---|---|---|
| GET | `/api/v1/{shop}/fragrances` | Filterable, paginated catalog | 120/min |
| GET | `/api/v1/{shop}/fragrances/{slug}` | Fragrance detail (404 if inactive) | 120/min |
| GET | `/api/v1/{shop}/brands` | Active brands | 120/min |
| GET | `/api/v1/{shop}/meta` | Filter options, price bounds, social links, payment details — all shop-resolved (shop settings → env default → off) | 120/min |
| GET | `/api/v1/{shop}/delivery-zones` | Serviceable townships + fees by region (cached; no courier data) | 120/min |
| POST | `/api/v1/{shop}/orders` | Guest checkout — server re-derives all prices and the delivery fee (structured address since step 30) | 10/min |
| GET | `/api/v1/{shop}/orders/track` | Full receipt by tracking code + phone | 20/min |
| POST | `/api/v1/{shop}/orders/cancel` | Customer cancel while `awaiting_confirmation` (409 after) | 10/min |
| POST | `/api/v1/{shop}/orders/payment-proof` | Customer's transfer screenshot, code + phone gated — stored on the private proofs disk, never marks paid | 10/min |
| POST | `/api/v1/{shop}/orders/validate-promo` | Preview a promo code — nothing persisted; checkout re-validates | 10/min |

Each limit is its own per-shop-per-IP bucket (named limiters in `AppServiceProvider`,
keyed shop + IP since step 32), so heavy catalog browsing can never starve checkout,
tracking, or cancellation — and one shop's traffic can never spend another shop's budget.

**Platform — outside the `{shop}` prefix (ADR-0004)**

| Method | Route | Purpose | Throttle |
|---|---|---|---|
| GET | `/api/v1/_storefront/host/{host}` | Resolves a storefront host to its shop (slug, name, primary host) for the shared storefront deployment; unknown, unverified, and inactive hosts are one generic 404 | 60/min (IP-keyed — no tenant exists yet) |

**Admin panel — tenant-aware since Step 25a: everything below `/admin` requires login,
and every operational URL carries the shop (`/admin/{shop}/…`); the tenant switcher
moves a studio user between shops**

| Route | What it is |
|---|---|
| `/admin/login` | The only public admin route |
| `/admin/{shop}` | Dashboard — stats, revenue chart, top fragrances, upcoming decants |
| `/admin/{shop}/brands` + `/create`, `/{id}/edit` | Brand CRUD |
| `/admin/{shop}/fragrances` + `/create`, `/{id}/edit` | Fragrance CRUD, prices, stock, "View on site" |
| `/admin/{shop}/orders` + `/create`, `/{id}/edit` | Order tabs (Needs review first), accept/reject, CSV export |
| `/admin/{shop}/promo-codes` + `/create`, `/{id}/edit` | Promo code CRUD — caps, minimums, usage limits, dates |
| `/admin/{shop}/production-schedule` | Decant schedule — month calendar; every day clicks through to its worklist |
| `/admin/{shop}/production-schedule/{date}` | One day's aggregated worklist, printable as an A5 bench sheet — strict `Y-m-d` param, 404 otherwise |

**Studio panel — the super-admin home outside any shop (`studio_admin` accounts only)**

| Route | What it is |
|---|---|
| `/studio/login` | Same users table and session guard as `/admin` — one login serves both |
| `/studio/shops` | The shop registry: register a shop (seeds its delivery geography and, by default, creates the owner's shop-confined login), open any shop's panel |
| `/studio/roles` | Filament Shield role/permission management (Step 34) — studio-panel only |
| `/studio/studio-audit-events` | The impersonation audit log (Step 34 PR-2) — read-only, filterable by shop; studio-panel only |

**Impersonation audit (Step 34 PR-2).** A `studio_admin` operating a shop's `/admin`
panel that isn't theirs is *impersonating* it — a privacy event, since it exposes
another decanter's customers, transfer slips, and P&L. Entering a shop's panel logs one
`panel.enter` (deduped per entry/switch). Impersonation is **read-only by default**: a
single model-layer guard (`GuardAndLogImpersonatedWrites` on Eloquent
saving/deleting) refuses tenant-owned writes — including from custom Filament actions a
policy hook would miss — surfacing a Filament notification, not a 500. An explicit,
audited **Take control** (the `/admin` banner) lifts read-only for the current shop only
(it resets on shop switch); each controlled write logs one `write.*` event per persisted
row. It is a **guardrail, not a boundary**: `studio_admin` stays super_admin, and a
controlled write still lands in the current tenant, stamped `shop_id` by
`BelongsToShop` — take-control never crosses a shop boundary (no `withoutTenancy`, no
membership bypass). `studio_audit_events` is platform-owned (no `BelongsToShop`).

**Access & authorization (Step 34).** Roles are **Filament Shield** (spatie/permission,
non-team): `studio_admin` (= Shield super_admin, platform-wide via a `Gate::before`
bypass), `shop_owner` (full control of its own shop's resources), `shop_staff` (a narrow
read set — real staff needs are deferred). Shield answers *"may this user perform this
action?"* via generated model policies (enforced on both panels); the existing tenancy
seam (`BelongsToShop`, `shop_user` membership, `canAccessTenant`, `ResolveTenant`) still
answers *"which shop, and which records."* `is_studio` is transitional — the panel/tenant
readers now use `hasRole('studio_admin')`. **Shop lifecycle** is the `status` enum
(`onboarding → live → suspended → archived`); only `live` is served (others 404), and
`is_active` is a derived read-only accessor for `status === live`.

**Utility**

| Route | What it is |
|---|---|
| `/up` | Health check — point uptime monitors / load-balancer probes here |
| `/storage/{path}` | Uploaded images in local dev (`php artisan storage:link`) — production serves images from Cloudflare R2 instead |
| `/admin/{shop}/orders/{id}/payment-proof` | Streams the order's payment screenshot from the private proofs disk — panel-auth + tenant-scoped, the one way a proof is ever served |
| `/` | Plain Laravel welcome page; the real storefront is the frontend app |

## File structure

Trimmed to the files you'd look for first. Deployment-relevant paths are marked `←`.

```text
backend/
├── app/
│   ├── Console/Commands/                   # FreshStart (decant:fresh-start handover wipe), TelegramTest (telegram:test)
│   ├── Enums/                              # BrandType, Concentration, Gender, OrderSource, OrderStatus, PaymentMethod, PaymentStatus, PromoType
│   ├── Events/ + Listeners/                # OrderPlaced, PaymentProofUploaded → Telegram admin alerts (NotifyAdminOf*)
│   ├── Filament/
│   │   ├── Pages/                          # ProductionSchedule (month calendar), ProductionScheduleDay (printable day worklist), ManagePayment (MMQR settings)
│   │   ├── Resources/                      # Brands/, Fragrances/, Orders/, PromoCodes/ — each: Resource + Schemas/ + Tables/ + Pages/
│   │   └── Widgets/                        # OrderStats, RevenueChart, TopFragrances, UpcomingDecants, LowStock
│   ├── Http/
│   │   ├── Controllers/                    # OrderInvoiceController (A5 PDFs), PaymentProofViewController (streams proofs) — panel-auth'd
│   │   ├── Controllers/Api/                # Brand, Fragrance, Meta, Order (checkout), TrackOrder, CancelOrder, ValidatePromo, PaymentProof, StorefrontHost
│   │   └── Resources/                      # JSON shaping for brands, fragrances, prices, storefront hosts
│   ├── Models/                             # Brand, Fragrance, DecantPrice, Order, OrderItem, PromoCode, Shop, ShopDomain (+ Concerns/HasSlug)
│   │                                       #   Order owns the domain rules: tracking codes, newFromCheckout, accept/reject/cancel
│   │                                       #   PromoCode::evaluate() is the one place promo validity/discounts are decided
│   ├── Providers/
│   │   ├── AppServiceProvider.php          # forces HTTPS in production, N+1 guard, explicit event wiring (auto-discovery is off)
│   │   └── Filament/AdminPanelProvider.php # /admin panel definition (auth, branding, nav groups)
│   └── Support/                            # Money (the one Kyat formatter), CatalogImport (CSV import engine), TelegramNotifier
├── bootstrap/app.php                       # routing + middleware wiring; event auto-discovery disabled (#52)
├── config/cors.php                         # platform CORS defaults (FRONTEND_URL) — verified shop_domains merge in per request (ADR-0004)
├── database/
│   ├── migrations/                         # brands, fragrances, decant_prices, orders, order_items, promo_codes + additive stock/payment columns
│   └── seeders/                            # admin user (ADMIN_PASSWORD) + demo catalog + demo orders
├── public/                                 # ← web root — served by Heroku's nginx buildpack (or artisan serve), never the repo root
│   └── vendor/fullcalendar/                # vendored FullCalendar bundle (MIT) for the schedule calendar — no npm, no build step, ships via git
├── resources/views/filament/               # schedule calendar + printable day-sheet Blade views
├── routes/api.php                          # /api/v1/{shop}/* with per-endpoint throttles
├── storage/                                # local uploads via storage:link — production images/proofs live in Cloudflare R2, not on the dyno
├── tests/Feature/                          # 311 tests: domain, admin, public API, promo, payments, stock, CSV import, Telegram, invoices, schedule, tenant isolation, storefront hosts, shop lifecycle, Shield roles, impersonation audit
├── .env.example                            # ← local template — production configuration lives in Heroku config vars, no .env on the dyno
└── composer.json                           # PHP 8.3+, Laravel 13, Filament v5
```

## Domain rules that live here

- **Order item prices are snapshots.** `Order::newFromCheckout()` accepts only
  `fragrance_id` / `size_ml` / `quantity`, re-derives every price from the current catalog
  (rejecting inactive or out-of-stock items), and freezes name + price onto the order item.
- **Tracking codes** are 10 chars from an ambiguity-free alphabet (no `0/O/1/I`), generated
  on creation. Lookup requires an exact code + phone match; any mismatch returns the same
  generic 404.
- **Website orders** start at `awaiting_confirmation`; manual admin entries start at
  `pending`. `accept()` assigns dates, `reject()` records a reason, and the customer-facing
  `cancel()` backs out — all three guarded so they only fire from `awaiting_confirmation`.
- **Cancelled/rejected orders** are excluded from revenue widgets and the production schedule.
- **Promo codes** are evaluated once in `PromoCode::evaluate()` — preview and checkout share
  it, checkout re-runs it inside the order transaction with the row locked (no double-spend
  of a limited code), and a code that lapsed between preview and submit drops the discount
  instead of blocking the order. `orders.promo_code` is a snapshot; editing `discount_mmk`
  in Filament never touches it.
- **Every tenant-owned model is scoped by the `BelongsToShop` global scope** — decided in
  step 32, not left as a default. The scope **throws** `TenantNotSetException` when no
  tenant is set (never an empty result, never all shops), filters on the qualified
  `shop_id`, and a creating hook stamps ownership from `TenantContext`. Cross-shop writes
  set the context to the target shop (`NationalGeography::seed` is the model); cross-shop
  reads spend a budgeted `TenantContext::withoutTenancy()` — `TenantIsolationTest` caps
  its call sites at 5. New storage objects are prefixed `shops/{id}/…`; objects written
  before step 32 keep their old paths (every reader uses the stored path, so both eras
  serve fine — an accepted limit, not a migration).
- **Storefront hosts resolve through `shop_domains`** (ADR-0004, accepted as amended).
  Normalized lowercase hosts (scheme/path/trailing-dot stripped, port kept), globally
  unique, `verified_at` gate, at most one primary per shop (partial unique index). The
  table is platform-owned and deliberately carries **no** tenant scope — it is what
  *produces* the tenant. Resolution and the CORS allowlist both require a verified row
  on an active shop and fail closed; `Shop::syncDomains()` is the one write path (the
  Studio "Domains" action delegates to it).

## Testing

```bash
php artisan test
```

311 tests / 1,300 assertions on an in-memory SQLite database — 40 of them in
`TenantIsolationTest`, the two-shop isolation suite, and 22 in
`StorefrontHostResolutionTest` (host → shop mapping + dynamic CORS, ADR-0004) — and
your dev Postgres data is never touched. N+1 queries throw outside production
(`Model::preventLazyLoading`).

SQLite isn't Postgres, and the difference bites: it accepts a case-sensitive-`LIKE`
mistake and a select alias inside `ORDER BY`, both of which Postgres rejects, so the
suite can pass over a broken query. `sh scripts/verify-postgres-portability.sh` checks
those against the running stack — reach for it whenever a change is engine-specific.

## Useful artisan commands

```bash
php artisan decant:fresh-start --shop=<slug>   # wipe ONE shop's demo fragrances + orders (and its proof files); keeps brands and admin user; refuses without a shop
php artisan db:seed              # reseed demo data (idempotent admin user)
php artisan telegram:test <slug> # confirm that shop's bot token + chat id (its own, or the platform bot) reach Telegram
php artisan cache:clear          # /api/v1 responses are cached for 10 minutes
```

Running the stack in Docker? Prefix any of these — including `php artisan test` — with
`docker compose exec backend`, from the repo root.
