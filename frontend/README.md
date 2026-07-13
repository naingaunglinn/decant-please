# Decant Please! — Frontend

Next.js 16 (App Router, TypeScript, Tailwind v4) storefront — the public, no-login
customer site: browse the decant catalog, check out as a guest, track an order. All data
comes from the Laravel API in [`../backend`](../backend); this app owns no database.

Project-wide context lives in [`../README.md`](../README.md) (system overview),
[`../CLAUDE.md`](../CLAUDE.md) (spec / source of truth), and
[`../DEPLOY.md`](../DEPLOY.md) (production deployment).

**Requirements:** Node.js 24 LTS · a running backend (see its README)

## Setup

```bash
npm install
cp .env.local.example .env.local
npm run dev                 # http://localhost:3000
```

| Script | What it does |
|---|---|
| `npm run dev` | Dev server with hot reload |
| `npm run build` | Production build (also the type-check) |
| `npm start` | Serve the production build |

## Environment variables

Both are inlined at **build time** — changing them requires a rebuild.

| Variable | Purpose |
|---|---|
| `NEXT_PUBLIC_API_URL` | Laravel API base, e.g. `http://localhost:8000/api` — every fetch and the allowed image host derive from it |
| `NEXT_PUBLIC_SITE_URL` | This site's public URL — canonical/OG metadata |

The backend's `FRONTEND_URL` must match this site's origin exactly, or browser-side
checkout/tracking calls fail CORS.

## Routes

| Route | Rendering | What it is |
|---|---|---|
| `/` | static, 60s revalidate | Home — hero, featured rail, how-it-works, category tiles |
| `/shop` | dynamic | Filterable catalog; all filter state lives in the URL query string |
| `/fragrance/{slug}` | dynamic | Fragrance detail + size selector + add to cart (404s on unknown/inactive slugs) |
| `/checkout` | static shell | Cart summary + contact form (cart itself is client-side) |
| `/order/complete?code=…` | dynamic | Tracking code + order summary, survives refresh via the URL |
| `/track` | dynamic | Tracking code + phone → status timeline (the vial fill) |
| `/robots.txt`, `/icon.svg` | static | SEO rules (checkout/order pages disallowed) and favicon |

## File structure

Trimmed to the files you'd look for first. Deployment-relevant paths are marked `←`.

```text
frontend/
├── src/
│   ├── app/                                # ← routes: one folder per URL (App Router)
│   │   ├── layout.tsx                      # nav + footer + cart drawer + OG defaults (NEXT_PUBLIC_SITE_URL)
│   │   ├── globals.css                     # ← the whole design system: Tailwind v4 @theme tokens (mist/pine/…)
│   │   ├── page.tsx                        # /
│   │   ├── shop/page.tsx                   # /shop (+ loading.tsx skeleton)
│   │   ├── fragrance/[slug]/page.tsx       # /fragrance/:slug
│   │   ├── checkout/page.tsx               # /checkout
│   │   ├── order/complete/page.tsx         # /order/complete
│   │   ├── track/page.tsx                  # /track
│   │   ├── robots.ts · icon.svg            # /robots.txt, favicon
│   │   └── error.tsx · not-found.tsx       # branded error/404 pages
│   ├── components/
│   │   ├── ui/                             # primitives: Pill, Button, ImagePlate, QuantityStepper, Skeleton
│   │   ├── layout/                         # Navbar, Footer (social links from /meta), MobileNav, CartButton
│   │   ├── catalog/                        # FragranceCard/Grid, filter bar/sheet/controls, Pagination
│   │   ├── product/                        # SizeSelector, PurchasePanel (add to cart)
│   │   ├── cart/                           # CartDrawer, CartItemRow
│   │   ├── checkout/                       # CheckoutForm/Client, OrderSummaryCard, OrderCompleteClient
│   │   ├── tracking/                       # TrackingForm, TrackClient, StatusTimeline (the vial fill)
│   │   └── home/                           # Hero (GSAP), ScrollReveal, FeaturedRail
│   ├── lib/
│   │   ├── api.ts                          # ← every call to the Laravel API; base URL = NEXT_PUBLIC_API_URL
│   │   ├── cart-context.tsx                # client-side cart (localStorage), drawer state
│   │   ├── types.ts                        # TypeScript mirrors of the API resources
│   │   └── format.ts                       # formatKyat
│   └── hooks/useCart.ts
├── next.config.ts                          # ← allowed image hosts follow NEXT_PUBLIC_API_URL automatically
├── .env.local.example                      # ← template: NEXT_PUBLIC_API_URL, NEXT_PUBLIC_SITE_URL
└── package.json                            # Node 24 LTS, Next.js 16, Tailwind v4, GSAP, Motion
```

## Conventions worth knowing

- **The cart is client-only.** Lines live in `localStorage` (`decant-please.cart.v1`) with
  preview prices; the backend re-derives the authoritative total at checkout, so nothing
  the client stores is trusted.
- **Filters are URLs.** `/shop?gender=female&size=10&sort=price_asc` fully reproduces a
  filtered view — shareable, refreshable, back-button-safe.
- **Design tokens, not ad-hoc colors.** Everything derives from the `@theme` block in
  `globals.css` (mist, ink, pine, rule, …) per the spec in `../CLAUDE.md` §3.
- **Server components fetch; client components animate.** Catalog data is fetched on the
  server (60s revalidation); GSAP/Motion run only in client components, and all motion
  respects `prefers-reduced-motion`.

## Deployment

Vercel with root directory `frontend/`, or `npm run build && npm start` behind Nginx on a
VPS — exact steps in [`../DEPLOY.md`](../DEPLOY.md). Set both env vars for the production
hosts; remote fragrance images are allowed automatically for the `NEXT_PUBLIC_API_URL` host.
