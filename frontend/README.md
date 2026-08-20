# Decant Please! — Frontend

Next.js 16 (App Router, TypeScript, Tailwind v4) storefront — the public, no-login
customer site: browse the decant catalog, check out as a guest, track an order. All data
comes from the Laravel API in [`../backend`](../backend); this app owns no database.

**One deployment serves every shop** (ADR-0004): the customer's domain — a custom domain
or a platform subdomain — is the tenant. `src/proxy.ts` rewrites each request internally
to `/{host}/…` (never visible in the browser), the `[host]` layout resolves that host
against the backend's `shop_domains` table (cached 60s, fail-closed 404 for unknown or
unverified hosts — never a default shop), and every page, cache entry, sitemap, and
canonical URL is tenant-specific from there. The backend's `BelongsToShop` scope remains
the security boundary — this routing is addressing and cache isolation, not authorization.

Project-wide context lives in [`../README.md`](../README.md) (system overview),
[`../CLAUDE.md`](../CLAUDE.md) (spec / source of truth), and
[`../DEPLOY.md`](../DEPLOY.md) (production deployment).

**Requirements:** Node.js 24 LTS · a running backend (see its README) — or neither:
`docker compose up` at the repo root runs every step below automatically
(see the [root README](../README.md#getting-started)).

## Setup

```bash
npm install
cp .env.local.example .env.local
npm run dev -- -p 3001      # http://localhost:3001 (3000/3010 are taken by other local projects)
```

| Script | What it does |
|---|---|
| `npm run dev` | Dev server with hot reload |
| `npm run build` | Production build (also the type-check) |
| `npm start` | Serve the production build |

## Environment variables

All are inlined at **build time** — changing them requires a rebuild.

| Variable | Purpose |
|---|---|
| `NEXT_PUBLIC_API_URL` | Laravel API base, e.g. `http://localhost:8010/api` — every fetch and the allowed image host derive from it |
| `NEXT_PUBLIC_IMAGE_URL` | Production only — the Cloudflare R2 public image host, added to the image-optimizer allow-list; leave unset locally (dev images come from the API host) |

There is deliberately **no per-shop variable** and no site-URL variable (ADR-0004):
the tenant comes from the request Host, and canonical/OG/sitemap URLs derive from each
shop's verified primary domain. Locally, the seeder maps `localhost:3001 →
decant-please`, so the dev server just works. CORS is per-shop too — the backend
allowlists every verified `shop_domains` row (plus the `FRONTEND_URL` platform default).

## Routes

Public paths are unchanged; internally every one of them lives under `app/[host]/`
(the proxy prefixes the request Host), so each shop's pages cache separately.

| Route | Rendering | What it is |
|---|---|---|
| `/` | ISR per host, 60s revalidate | Home — hero, featured rail, how-it-works, category tiles |
| `/shop` | dynamic | Filterable catalog; all filter state lives in the URL query string |
| `/fragrance/{slug}` | ISR per host+slug, 60s | Fragrance detail + size selector + add to cart (404s on unknown/inactive slugs) |
| `/checkout` | ISR per host (static shell) | Cart summary + promo code + contact form with region → township selects — the delivery fee line comes from `/delivery-zones` and is re-derived server-side at submit (cart itself is client-side) |
| `/order/complete?code=…` | dynamic | Full receipt (fetched by code + phone), printable, survives refresh via the URL |
| `/track` | dynamic | Tracking code + phone → the same receipt, with the vial-fill timeline |
| `/sitemap.xml` | route handler, 1h revalidate | **This tenant's** home, shop, and active fragrances, on its primary domain |
| `/robots.txt` | route handler, 1h revalidate | SEO rules (checkout/order pages disallowed) + this host's own sitemap reference |
| `/icon.svg` | static | Favicon (excluded from the proxy rewrite) |

## File structure

Trimmed to the files you'd look for first. Deployment-relevant paths are marked `←`.

```text
frontend/
├── src/
│   ├── proxy.ts                            # ← ADR-0004: Host → internal /{host}/… rewrite; I/O-free, and never the security boundary
│   ├── app/                                # ← routes: one folder per URL (App Router)
│   │   ├── layout.tsx                      # the bare html/body shell — everything tenant-aware lives below [host]
│   │   ├── not-found.tsx                   # unbranded fail-closed 404 for hosts that map to no shop
│   │   ├── globals.css                     # ← the whole design system: Tailwind v4 @theme tokens (mist/pine/…)
│   │   ├── icon.svg                        # favicon (proxy-excluded)
│   │   └── [host]/                         # ← the tenant tree — the segment IS the validated public host
│   │       ├── layout.tsx                  # resolves host → shop (cached), tenant metadata/OG, nav + footer + cart, TenantProvider
│   │       ├── page.tsx                    # /
│   │       ├── shop/page.tsx               # /shop (+ loading.tsx skeleton)
│   │       ├── fragrance/[slug]/page.tsx   # /fragrance/:slug
│   │       ├── checkout/page.tsx           # /checkout
│   │       ├── order/complete/page.tsx     # /order/complete
│   │       ├── track/page.tsx              # /track
│   │       ├── robots.txt/route.ts · sitemap.xml/route.ts # per-tenant SEO files
│   │       └── error.tsx · not-found.tsx   # branded (in-tenant) error/404 pages
│   ├── components/
│   │   ├── ui/                             # primitives: Pill, Button, ImagePlate, QuantityStepper, Skeleton; Select (Radix, restyled to tokens)
│   │   ├── layout/                         # Navbar, Footer (social links from /meta), MobileNav, CartButton
│   │   ├── catalog/                        # FragranceCard/Grid, filters, Pagination, RecentlyViewed
│   │   ├── product/                        # SizeSelector, PurchasePanel (add to cart)
│   │   ├── cart/                           # CartDrawer, CartItemRow
│   │   ├── checkout/                       # CheckoutForm/Client, OrderSummaryCard, OrderCompleteClient, PaymentPanel (balance due + transfer details + proof upload)
│   │   ├── tracking/                       # TrackingForm, TrackClient, OrderReceipt (printable), StatusTimeline
│   │   └── home/                           # Hero (GSAP), ScrollReveal, FeaturedRail
│   ├── lib/
│   │   ├── api.ts                          # ← every call to the Laravel API; tenant-parameterized — each helper takes the resolved shop slug
│   │   ├── tenant.ts                       # resolveTenant (host → shop via the PR-A endpoint, cached), tenantPage (404 / secondary→primary 308 gate)
│   │   ├── tenant-context.tsx              # TenantProvider/useTenant — the server-resolved tenant for client components
│   │   ├── cart-context.tsx                # client-side cart (localStorage), drawer state
│   │   ├── types.ts                        # TypeScript mirrors of the API resources
│   │   └── format.ts                       # formatKyat
│   └── hooks/useCart.ts
├── next.config.ts                          # ← allowed image hosts: the NEXT_PUBLIC_API_URL host + NEXT_PUBLIC_IMAGE_URL (R2) in production
├── .env.local.example                      # ← template: NEXT_PUBLIC_API_URL, NEXT_PUBLIC_IMAGE_URL (no per-shop vars — the Host is the tenant)
└── package.json                            # Node 24 LTS, Next.js 16, Tailwind v4, GSAP, Motion, @radix-ui/react-select
```

## Conventions worth knowing

- **The Host is the tenant** (ADR-0004). Server pages get the shop through
  `tenantPage(host, path)` — which also 404s unknown hosts and 308s a secondary domain
  to its verified primary — and thread the slug into `lib/api.ts` explicitly. Client
  components read `useTenant()`. Never a build-time shop, never module-scope tenant
  state, never a client-supplied slug. The empty `generateStaticParams` exports on
  home/PDP/checkout are load-bearing: per the Next docs they are what opts unlisted
  params into on-demand ISR — removing one silently makes that route fully dynamic.
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
- **One library UI primitive, restyled.** The `Select` (checkout region/township and the
  shop sort) wraps `@radix-ui/react-select` — the only non-motion runtime dependency — so
  the option list can be styled (native `<option>` popups can't be). It's restyled entirely
  to the `globals.css` tokens; shadcn's token layer was **not** adopted, and the `@theme`
  block is unchanged. Any copied-in component must use `text-base` (≥16px) on every control,
  or iOS Safari zooms the viewport on focus. There are no native `<select>` elements left;
  everything else (`Button`, `Pill`, `QuantityStepper`, …) is the project's own.

## Deployment

Vercel with root directory `frontend/`, or `npm run build && npm start` behind Nginx on a
VPS — exact steps in [`../DEPLOY.md`](../DEPLOY.md). Set all three env vars for the
production hosts: fragrance images are allowed for the `NEXT_PUBLIC_API_URL` host
automatically, and `NEXT_PUBLIC_IMAGE_URL` extends that allow-list to the R2 image domain
(without it, the image optimizer rejects R2 URLs and catalog photos 400).
