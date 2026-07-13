# Decant Please! — Backend

Laravel 13 application serving two things:

1. the **public JSON API** (`/api/v1/*`) that the Next.js storefront in
   [`../frontend`](../frontend) consumes, and
2. the **admin panel** (`/admin`, Filament v5) where the decanter manages the catalog,
   reviews orders, and reads the daily production schedule.

Project-wide context lives in [`../README.md`](../README.md) (system overview),
[`../CLAUDE.md`](../CLAUDE.md) (spec / source of truth), and
[`../DEPLOY.md`](../DEPLOY.md) (production deployment).

**Requirements:** PHP 8.3+ · Composer · MySQL 8.0+

## Setup

```bash
composer install
cp .env.example .env        # set DB_* and ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed  # demo catalog + admin user
php artisan storage:link    # serve uploaded images from /storage
php artisan serve           # http://localhost:8000
```

Admin login: `admin@decantplease.local` / whatever `ADMIN_PASSWORD` was when you seeded.

## Environment variables

| Variable | Purpose |
|---|---|
| `APP_URL` | This app's own URL — image URLs in API responses are built from it |
| `FRONTEND_URL` | Storefront origin — the CORS allowlist **and** admin "View on site" links |
| `ADMIN_PASSWORD` | Read once by `db:seed` to create the admin user |
| `SOCIAL_TIKTOK_URL` / `SOCIAL_FACEBOOK_URL` | Exposed via `/api/v1/meta` for the storefront footer; blank = hidden |
| `DB_*` | MySQL connection |

Production values and hardening (`APP_DEBUG=false`, `SESSION_SECURE_COOKIE`, config
caching) are covered in [`../DEPLOY.md`](../DEPLOY.md).

## Routes

**Public API — `/api/v1`, JSON, rate-limited**

| Method | Route | Purpose | Throttle |
|---|---|---|---|
| GET | `/api/v1/fragrances` | Filterable, paginated catalog | 120/min |
| GET | `/api/v1/fragrances/{slug}` | Fragrance detail (404 if inactive) | 120/min |
| GET | `/api/v1/brands` | Active brands | 120/min |
| GET | `/api/v1/meta` | Filter options, price bounds, social links | 120/min |
| POST | `/api/v1/orders` | Guest checkout — server re-derives all prices | 10/min |
| GET | `/api/v1/orders/track` | Full receipt by tracking code + phone | 20/min |
| POST | `/api/v1/orders/cancel` | Customer cancel while `awaiting_confirmation` (409 after) | 10/min |
| POST | `/api/v1/orders/validate-promo` | Preview a promo code — nothing persisted; checkout re-validates | 10/min |

Each limit is its own per-IP bucket (named limiters in `AppServiceProvider`), so heavy
catalog browsing can never starve checkout, tracking, or cancellation.

**Admin panel — everything below `/admin` requires login**

| Route | What it is |
|---|---|
| `/admin/login` | The only public admin route |
| `/admin` | Dashboard — stats, revenue chart, top fragrances, upcoming decants |
| `/admin/brands` + `/create`, `/{id}/edit` | Brand CRUD |
| `/admin/fragrances` + `/create`, `/{id}/edit` | Fragrance CRUD, prices, stock, "View on site" |
| `/admin/orders` + `/create`, `/{id}/edit` | Order tabs (Needs review first), accept/reject, CSV export |
| `/admin/promo-codes` + `/create`, `/{id}/edit` | Promo code CRUD — caps, minimums, usage limits, dates |
| `/admin/production-schedule` | Aggregated daily decant schedule |

**Utility**

| Route | What it is |
|---|---|
| `/up` | Health check — point uptime monitors / load-balancer probes here |
| `/storage/{path}` | Uploaded images — requires `php artisan storage:link` on every deploy target |
| `/` | Plain Laravel welcome page; the real storefront is the frontend app |

## File structure

Trimmed to the files you'd look for first. Deployment-relevant paths are marked `←`.

```text
backend/
├── app/
│   ├── Console/Commands/FreshStart.php     # php artisan decant:fresh-start (handover wipe)
│   ├── Enums/                              # BrandType, Concentration, Gender, OrderSource, OrderStatus, PromoType
│   ├── Filament/
│   │   ├── Pages/ProductionSchedule.php    # /admin/production-schedule (+ Blade view in resources/)
│   │   ├── Resources/                      # Brands/, Fragrances/, Orders/, PromoCodes/ — each: Resource + Schemas/ + Tables/ + Pages/
│   │   └── Widgets/                        # OrderStats, RevenueChart, TopFragrances, UpcomingDecants
│   ├── Http/
│   │   ├── Controllers/Api/                # Brand, Fragrance, Meta, Order (checkout), TrackOrder, CancelOrder
│   │   └── Resources/                      # JSON shaping for brands, fragrances, prices
│   ├── Models/                             # Brand, Fragrance, DecantPrice, Order, OrderItem, PromoCode (+ Concerns/HasSlug)
│   │                                       #   Order owns the domain rules: tracking codes, newFromCheckout, accept/reject/cancel
│   │                                       #   PromoCode::evaluate() is the one place promo validity/discounts are decided
│   ├── Providers/
│   │   ├── AppServiceProvider.php          # forces HTTPS in production, N+1 guard outside production
│   │   └── Filament/AdminPanelProvider.php # /admin panel definition (auth, branding, nav groups)
│   └── Support/Money.php                   # the one Kyat formatter — all money display goes through it
├── bootstrap/app.php                       # routing + middleware wiring (api: routes/api.php)
├── config/cors.php                         # allowlist = FRONTEND_URL           ← must match storefront origin
├── database/
│   ├── migrations/                         # brands, fragrances, decant_prices, orders, order_items
│   └── seeders/                            # admin user (ADMIN_PASSWORD) + demo catalog + demo orders
├── public/                                 # ← web root — point Nginx/PHP-FPM here, never at the repo root
├── resources/views/filament/               # production schedule Blade view
├── routes/api.php                          # /api/v1/* with per-endpoint throttles
├── storage/                                # ← must be writable (www-data); app/public = uploaded images (back this up)
├── tests/Feature/                          # 39 tests: domain, admin catalog, admin orders, public API, fresh-start
├── .env.example                            # ← template for the production .env
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

## Testing

```bash
php artisan test
```

39 tests / 245 assertions on an in-memory SQLite database — your dev MySQL data is never
touched. N+1 queries throw outside production (`Model::preventLazyLoading`).

## Useful artisan commands

```bash
php artisan decant:fresh-start   # wipe demo fragrances + orders; keep brands and admin user
php artisan db:seed              # reseed demo data (idempotent admin user)
php artisan cache:clear          # /api/v1 responses are cached for 10 minutes
```
