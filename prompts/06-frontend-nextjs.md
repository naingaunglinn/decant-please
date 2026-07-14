# Step 6 — Next.js storefront: catalog, cart, checkout & tracking — v2

Work in `frontend/`. Consume the Step-5 API (`NEXT_PUBLIC_API_URL`). This file fully
replaces v1's Step 6 — build against this version, not the original.

## 1. Design system

### Color — Tailwind v4 CSS-first config

Tailwind v4 configures in CSS, not `tailwind.config.ts`. In `src/app/globals.css`:

```css
@import "tailwindcss";

@theme {
  --color-mist: #F2F8FC;
  --color-ink: #212121;
  --color-ink-strong: #000000;
  --color-pine: #013E37;
  --color-pine-soft: #E7ECE3;
  --color-rule: #D6E4EB;
  --color-surface-alt: #E3EEF4;
  --color-muted: #63707A;
  --color-status-pending: #B08D57;
  --color-status-danger: #8C4A34;

  --font-sans: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}

body {
  background: var(--color-mist);
  color: var(--color-ink);
  font-family: var(--font-sans);
}
```

Every `--color-*` var in `@theme` becomes a Tailwind utility automatically —
`bg-mist`, `text-pine`, `border-rule`, etc. No JS config file needed. Don't
reintroduce `tailwind.config.ts` unless a plugin genuinely requires it.

Background moved from an earlier warm cream (`#FEFDDF`) to this cool pale blue —
against `pine`, cream read sleepy; this reads crisp instead. `rule`, `surface-alt`,
and `muted` are recalculated to match (cool blue-gray / cool slate instead of warm
khaki / warm gray); `pine`, `pine-soft`, and the two status colors are unaffected
since they're derived from the accent, not the background.

### Typography
Helvetica isn't a licensable web font — use the system stack above, which renders
true Helvetica Neue on Mac/iOS and metrically-identical Arial elsewhere. One family
throughout. Hierarchy comes from:
- **Scale:** display 40-56px (home hero only), h1 28-32px, h2 20-24px, body 15-16px,
  label/caption 11-12px.
- **Tracking:** wide (`tracking-[0.15em]` to `tracking-[0.2em]`) + uppercase for
  brand names, fragrance names, and pill labels, matching the reference cards. Normal
  tracking for body copy and paragraph text.
- **Weight:** 700 for the fragrance-name headline treatment only; 500 for pill labels
  and buttons; 400 for body copy and captions. Don't introduce 600.

### The vial-label pill — signature component
Every atomic piece of metadata (brand, size, status, an attribute) renders inside a
thin hairline-bordered rounded container — `border border-rule rounded-full` at
minimum, scaling up to `rounded-2xl` for boxed groups like the price list. This is
the one visual idea every other component derives from; don't introduce a second,
different "badge" style elsewhere in the app.

### Spacing
Generous. Section padding `py-20` to `py-32` on desktop, `py-12` to `py-16` on
mobile. Body line-height `1.6`-`1.7`. Max content width `~1280px` for the shop grid,
narrower (`~480px`) for the detail page and checkout, matching the reference card's
centered, narrow feel.

## 2. Pages (App Router)

### `/` — Home
- Hero: one thesis statement (not a stat-and-gradient template), a single featured
  fragrance or brand image, one clear CTA into `/shop`.
- Featured fragrances rail (horizontal scroll on mobile, grid on desktop).
- **"How it works"** — Browse → Checkout → Track, three steps, numbered (this is a
  genuine sequence, so numbering is earned here, unlike most sections).
- Category entry tiles (Designer / Niche, or Male / Female / Unisex — pick whichever
  the decanter's catalog skews toward) linking into `/shop` with a preset filter.
- Footer.

### `/shop` — Category / Collection
Same filter set and URL-query-string-driven state as v1: brand (multi-select), brand
type, gender, size, price range + sort, notes search, free-text search, "clear all,"
active-filter count. Server components fetch using `searchParams`; a client
`FilterBar` updates the URL via `router.replace`. Grid of `FragranceCard` (2 cols
mobile → 4 desktop). Pagination from API meta. Skeleton loading states, not spinners.
Nav category tiles from Home link here with a preset query string
(`/shop?brand_type=niche`, `/shop?gender=male`) rather than separate routes — one
page, driven entirely by `searchParams`.

### `/fragrance/[slug]` — Detail
Same structure as v1's reference-card layout: brand pill → name pill (concentration
in `pine`) → image → decant price list box → performance/notes/gender pill row.
**New:** the price list rows are now a `SizeSelector` (tap a row to select that size;
selected row gets a `bg-pine-soft` fill, matching the mockup shown in chat), a
quantity stepper, and an **Add to cart** button that adds the selected
fragrance + size + quantity to the cart context and opens the cart drawer.

### Cart — a drawer, not a page
Global, accessible from a cart icon in the nav (item-count badge). Client-side only:
React context + `localStorage` persistence, no server round trip until checkout.
Line items with quantity controls and remove; subtotal shown for the customer's
convenience only — **the server always re-derives the authoritative total at
checkout** (see `05-api-layer.md`), so don't treat the client subtotal as anything
more than a preview. "Proceed to checkout" → `/checkout`.

### `/checkout` — Order / Checkout
Cart summary (editable quantities) + contact form: `customer_name`, `phone`,
`address`, optional `note`. No payment fields — this project doesn't take payment
online (see `CLAUDE.md` §8). Include the hidden honeypot field from
`05-api-layer.md`. On submit, `POST /orders`; on success, redirect to
`/order/complete?code={tracking_code}`; on a `422` naming a specific item, surface
that item's error inline next to it, not as a generic banner.

### `/order/complete` — Order Complete
Reads `?code=` from the URL (so refresh/bookmark/share all still work). Shows the
tracking code prominently inside a large vial-label pill, an order summary, and a
"Track this order" link into `/track?code={code}`. Keep the celebratory note here
tasteful and quiet — a clean reveal, not confetti; that would fight the premium,
restrained direction the rest of the site is going for.

### `/track` — Order Tracking
A form (tracking code + phone) when no query params are present; if `?code=` is
present, pre-fill it. Results view shows the `StatusTimeline` component (see below)
and an item summary. No login, ever — this page's only security boundary is the
code+phone pair the API already enforces.

### Not found / error
Minimal branded 404 and error states, consistent with the rest of the design system
(mist background, pine accent, no default Next.js styling left showing).

## 3. Components

| Component | Notes |
|---|---|
| `Pill` | the base vial-label primitive; every badge in the app is one of these |
| `FragranceCard` | catalog grid item |
| `FilterBar` / `FilterSheet` | desktop sidebar / mobile bottom sheet |
| `SizeSelector` | the price-list-as-selector on the detail page |
| `CartDrawer`, `CartItemRow` | global cart |
| `CheckoutForm`, `OrderSummaryCard` | checkout page |
| `StatusTimeline` | shared between Order Complete and Track — the vial-fill animation lives here |
| `Navbar`, `Footer`, `MobileNav` | layout |

## 4. Data layer

`src/lib/api.ts` — typed fetchers, extending v1's `getFragrances`, `getFragrance`,
`getBrands`, `getMeta` with:
```ts
createOrder(payload: CheckoutPayload): Promise<{ tracking_code: string; total_mmk: number; total_formatted: string }>
trackOrder(trackingCode: string, phone: string): Promise<OrderStatusResponse>
```
`src/lib/cart-context.tsx` — React context + `localStorage` persistence (never
browser storage inside a Claude-generated *artifact* — this is a real app the user
runs themselves, so normal browser APIs are fine here). Keys items by
`fragrance_id` + `size_ml`; exposes `add`, `updateQuantity`, `remove`, `clear`,
computed subtotal.
`src/lib/format.ts` — `formatKyat(n)` → `"50,000 Ks"`, unchanged from v1.
`src/lib/types.ts` — TypeScript types mirroring every API resource, including the
new checkout/tracking shapes from `05-api-layer.md`.

Catalog fetch: `cache: "no-store"` or short revalidate (~60s), unchanged from v1.
Checkout and tracking calls are always `cache: "no-store"` — they're never cacheable.

## 5. Animation — GSAP and Motion, each doing what it's good at

Don't reach for both libraries to do the same job — that's bundle weight for nothing.

**GSAP** (scroll-linked and timeline work — ScrollTrigger and SplitText are both free
for commercial use, no license gate):
- Home hero: `SplitText` stagger-reveal on the headline on load.
- Section-by-section fade/rise as content enters the viewport on scroll
  (`ScrollTrigger`), used on Home only — don't apply this to the Shop grid, where
  content needs to be visible immediately for browsing and scanning speed matters
  more than entrance choreography.

**Motion** (component state — install `motion`, import from `motion/react`, *not*
`framer-motion`, which is the deprecated old name of the same project):
- Cart drawer: `AnimatePresence` slide-in/out + backdrop fade.
- Add-to-cart: a small button press + toast confirmation.
- Shop grid: stagger fade when the result set changes (filter/sort/page change) via
  `layout` transitions.
- `SizeSelector`: the selected-row highlight slides between rows rather than
  cutting.
- `StatusTimeline`: the signature moment — the fill animates like liquid rising
  through the vial as status advances. This is the one place worth spending
  animation budget; keep everything else quiet by comparison.

**Page transitions:** use Next.js App Router's native View Transitions support for
catalog → detail navigation (your Claude Code environment already has a
`vercel-react-view-transitions` skill — follow its patterns here) rather than
layering Motion's `AnimatePresence` on top for the same transition; use Motion for
transitions *within* a page, View Transitions for transitions *between* pages.

**Respect `prefers-reduced-motion`** everywhere — GSAP's `matchMedia()` and Motion's
`useReducedMotion()` both make this a few lines, not a redesign. Keep micro-
interactions in the 150-400ms range and hero reveals under ~1.2s.

## 6. Folder structure

```
frontend/
├── src/
│   ├── app/
│   │   ├── layout.tsx
│   │   ├── globals.css              # @theme block lives here
│   │   ├── page.tsx                 # Home
│   │   ├── shop/page.tsx            # Category / Collection
│   │   ├── fragrance/[slug]/page.tsx
│   │   ├── checkout/page.tsx
│   │   ├── order/complete/page.tsx
│   │   ├── track/page.tsx
│   │   ├── not-found.tsx
│   │   └── error.tsx
│   ├── components/
│   │   ├── ui/          # Pill, Button, Skeleton
│   │   ├── catalog/     # FragranceCard, FilterBar, FilterSheet
│   │   ├── product/     # SizeSelector, DecantPriceList
│   │   ├── cart/        # CartDrawer, CartItemRow
│   │   ├── checkout/    # CheckoutForm, OrderSummaryCard
│   │   ├── tracking/    # TrackingForm, StatusTimeline
│   │   └── layout/      # Navbar, Footer, MobileNav
│   ├── lib/
│   │   ├── api.ts
│   │   ├── cart-context.tsx
│   │   ├── format.ts
│   │   └── types.ts
│   └── hooks/
│       └── useCart.ts
├── next.config.js
└── .env.local.example
```

## 7. UX practices to hold to throughout

- Verb-first, plain-English copy on every control: "Add to cart," "Place order,"
  "Track this order" — never "Submit." A button's label stays the same word through
  its whole flow (the button that says "Place order" produces a page that says
  "Order placed," not "Success!").
- Empty states are an invitation, not an apology: "No fragrances match these
  filters — try widening your search," with the clear-filters action right there,
  not "No results found."
- Errors say what happened and what to do next, in the interface's voice, never a
  raw exception string — this matters especially for the checkout 422 responses from
  Step 5, which are written to be shown directly.
- Mobile tap targets ≥44px. Skeleton loaders, not spinners, for the catalog grid and
  detail page image.
- Sticky add-to-cart / price bar on mobile product pages once the primary button
  scrolls out of view.
- The mist/ink and mist/pine color pairs both clear WCAG AAA on their own (roughly
  15:1 and 11:1), so don't dilute contrast by screening text over the product image —
  keep text on solid `mist` or `surface-alt`, image behind stays in its own contained
  plate.

## 8. What makes this read as premium rather than templated

- Oversized product photography with real negative space around it — don't crop
  tight to save vertical space.
- Restrained copy: a single evocative line per section, not paragraphs of marketing
  copy. Let the product and the whitespace do the talking.
- `pine` stays sparing — buttons, the vial-fill, active states. It never becomes a
  large background fill outside those cases.
- Slow, confident motion (per §5) over snappy/gamified feedback — no bouncy
  easings, no confetti, no celebratory sound effects.
- Minimal top-level navigation — Shop, Track order, and the logo is close to enough.
- No pop-ups, no upsell modals, no "X people viewing this" urgency banners — those
  read as mass-market, which is the opposite of the Le Labo/Byredo/Aesop/Diptyque/
  Jo Malone register this brief is aiming for.

## 9. SEO & polish — unchanged from v1
Per-page `generateMetadata`; `next/image` remote pattern for the Laravel storage
host; no layout shift on images (fixed aspect ratios); semantic HTML throughout.

## 10. Verify

- Run backend (`:8010`, seeded) + frontend (`:3001`) together.
- Every filter on `/shop` changes the URL; sharing a filtered URL reproduces the
  state (unchanged from v1).
- Add multiple fragrances/sizes to the cart, refresh the page, confirm the cart
  survives (localStorage persistence working).
- Complete a checkout end to end: cart → checkout form → submit → land on
  `/order/complete?code=...` with the right tracking code and total.
- Deliberately trigger a 422 (e.g. pick an out-of-stock size if your seed data has
  one) and confirm the error renders inline and names the item.
- Take the tracking code from the step above to `/track`, confirm the status
  timeline renders correctly, and confirm a wrong phone number with the right code
  returns the same "not found" message as a wrong code (no field-level leak).
- Mobile pass: cart drawer, filter sheet, and checkout form all usable one-handed.
- Report with screenshots-in-words + any TODOs. Step 7 (polish/deploy) is unchanged
  from v1 and still applies as written.
