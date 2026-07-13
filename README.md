# Decant Please!

**A catalog + ordering system for perfume decanters in Myanmar.**

A decanter buys full bottles (Chanel, Dior, Creed…) and sells them on in 5ml / 10ml / 30ml
vials. That business traditionally lives in TikTok and Facebook DMs — customers message page
after page asking *"do you have X?"* and usually hear *"no."* Decant Please! replaces that with
a browsable storefront and a real guest checkout, while the decanter runs everything from one
admin panel.

No customer accounts. No payment gateway. Payment stays what it already is in Myanmar —
bank transfer, mobile banking, or cash on delivery, confirmed by the decanter.

---

## Repository layout

| Path | What it is |
|---|---|
| `backend/` | Laravel 13 — JSON API + [Filament v5](https://filamentphp.com) admin panel at `/admin` |
| `frontend/` | Next.js 16 (App Router, TypeScript, Tailwind v4) — public storefront |
| `CLAUDE.md` | Project spec and source of truth for every product/design decision |
| `DEPLOY.md` | Production deployment guide (VPS backend + Vercel frontend, backups) |
| `prompts/` | The step-by-step build prompts this project was built from |

## Features

**Storefront (public, no login)**

- Filterable catalog — brand, brand type, gender, size, price range, scent-note and free-text
  search, sorting; all URL-driven, so filtered views are shareable
- Fragrance detail pages with decant sizes/prices, notes, vibes, longevity
- Cart drawer (client-side, survives refresh via `localStorage`)
- Guest checkout — name, phone, address; no card, no account
- Order-complete page with a tracking code, and a tracking page (code + phone) showing a
  status timeline that fills like liquid rising in a vial

**Admin panel (`/admin`, login required)**

- Brand & fragrance CRUD with image upload, per-size pricing and stock toggles
- **Needs review** inbox for website orders — accept (assign decant/delivery dates) or
  reject (with a reason the customer sees when tracking)
- Manual order entry for customers who still order by DM
- **Production schedule** — per-day, aggregated view of which fragrances/sizes to decant
  and how many vials, across all upcoming orders
- Dashboard: monthly revenue, orders by status, decants due today, top fragrances
- CSV export of orders, respecting the current tab/filters/sort
- "View on site" jump from any fragrance row to its public page

## Order lifecycle

```mermaid
stateDiagram-v2
    [*] --> awaiting_confirmation: website checkout
    [*] --> pending: manual entry (DM order)
    awaiting_confirmation --> pending: accepted — dates assigned
    awaiting_confirmation --> rejected: rejected — with reason
    pending --> decanted: vials filled
    decanted --> delivered: handed to customer
    pending --> cancelled
    decanted --> cancelled
```

Cancelled and rejected orders are excluded from all revenue figures and from the
production schedule.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 · PHP 8.3+ · MySQL 8 |
| Admin | Filament v5 |
| Frontend | Next.js 16 · React · TypeScript 5 |
| Styling | Tailwind CSS v4 (CSS-first `@theme` tokens) |
| Animation | GSAP (scroll/hero) · Motion (drawers, timeline) |
| Currency | Myanmar Kyat, integer only — `65,000 Ks` |

## Getting started

### Prerequisites

- PHP 8.3+ and Composer
- Node.js 24 LTS
- MySQL 8.0+ — or run it in Docker:

```bash
docker run -d --name decant-mysql \
  -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=decant_please \
  -p 3306:3306 -v decant_mysql_data:/var/lib/mysql mysql:8
```

### 1. Backend — http://localhost:8000

```bash
cd backend
composer install
cp .env.example .env        # set DB_* and ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed  # demo catalog + admin user
php artisan serve
```

Admin: **http://localhost:8000/admin** — `admin@decantplease.local` /
whatever `ADMIN_PASSWORD` was when you seeded.

### 2. Frontend — http://localhost:3000

```bash
cd frontend
npm install
cp .env.local.example .env.local
npm run dev
```

## Configuration

**Backend `.env`**

| Variable | Purpose |
|---|---|
| `FRONTEND_URL` | Storefront origin — CORS allowlist **and** admin "View on site" links |
| `ADMIN_PASSWORD` | Read once by the seeder for the admin login |
| `SOCIAL_TIKTOK_URL` / `SOCIAL_FACEBOOK_URL` | Shown as storefront footer links; blank = hidden |

**Frontend `.env.local`**

| Variable | Purpose |
|---|---|
| `NEXT_PUBLIC_API_URL` | Laravel API base, e.g. `http://localhost:8000/api` |
| `NEXT_PUBLIC_SITE_URL` | Public site URL — canonical/OG metadata |

## Public API

All endpoints are under `/api/v1`, JSON, paginated where applicable.

| Method | Endpoint | Purpose | Throttle |
|---|---|---|---|
| GET | `/fragrances` | Filterable catalog | 120/min |
| GET | `/fragrances/{slug}` | Fragrance detail | 120/min |
| GET | `/brands` | Active brands | 120/min |
| GET | `/meta` | Filter options, price bounds, social links | 120/min |
| POST | `/orders` | Guest checkout | 10/min |
| GET | `/orders/track` | Status by tracking code + phone | 20/min |

Guarantees worth knowing:

- **Prices are never trusted from the client.** Checkout receives only
  `fragrance_id`, `size_ml`, `quantity`; the server re-derives every price from the current
  catalog and stores immutable snapshots on the order items.
- **Tracking is not a guessing oracle.** Lookup requires an exact code + phone match;
  a mismatch on either returns the same generic 404.
- Checkout carries a honeypot field; bots get a convincing fake response and nothing is stored.

## Testing

```bash
cd backend && php artisan test   # 39 tests — domain, admin (Livewire), full API
cd frontend && npm run build     # type-checks and builds the storefront
```

Tests run on an in-memory SQLite database and never touch your dev data.
N+1 queries throw in dev/test (`Model::preventLazyLoading`), silently allowed in production.

## Deployment

See **[DEPLOY.md](DEPLOY.md)** — exact commands for a PHP-FPM + Nginx + MySQL VPS,
Vercel setup for the frontend, the production `.env` template, and the nightly
`mysqldump` backup cron (order history is the decanter's financial record).

When the demo data has served its purpose:

```bash
php artisan decant:fresh-start   # wipes demo fragrances + orders; keeps brands and the admin login
```

## Design

Premium-minimalist, apothecary-adjacent — pale `mist` background, near-black text, deep
`pine` green used sparingly, one Helvetica-stack family throughout. Every piece of metadata
lives in a thin hairline-bordered pill (a vial label, not a badge), and the one deliberate
motion moment is the tracking timeline filling like a vial. Tokens live in
`frontend/src/app/globals.css`; the full design language is specified in `CLAUDE.md`.

## Deliberately out of scope

Online payments, customer accounts, chat, multi-decanter marketplace, bottle-volume
inventory, email/SMS notifications. See `CLAUDE.md` §8 before adding any of these.
