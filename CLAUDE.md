# CLAUDE.md — Decant Please! (v2)

> This file is project memory for Claude Code. Read it fully before doing any task.
> Every implementation decision must be consistent with this document.

## 0. What changed in v2

The original spec was DM-only: browse on the website, order by DMing TikTok/Facebook,
decanter transcribes the order into the admin panel. **v2 adds a real, self-service
checkout** — the customer places the order on the site itself, no DM required. This
touches the design system, the customer-facing feature set, the order model, and the
admin workflow. It does **not** touch the underlying brand/fragrance/decant-price
catalog, which is unchanged from v1.

Files updated for v2: this file, `02-database-schema.md`, `04-order-management.md`,
`05-api-layer.md`, `06-frontend-nextjs.md`. Files `01-understand-system.md`,
`03-admin-filament.md`, and `07-polish-deploy.md` are unchanged — the scaffold, the
Brand/Fragrance CRUD, and the deploy checklist all still hold as written.

If this is a fresh build, just run the steps in order using the v2 files. If you
already built v1, see "Applying v2 to an existing build" at the bottom of
`02-database-schema.md` before re-running anything — schema changes should land as
new migrations, not edits to old ones.

## 1. What this project is

**Decant Please!** is a web system for perfume **decanters** in Myanmar.

A *decanter* buys full perfume bottles (e.g. Chanel Allure Homme Sport Cologne) and
sells small portions ("decants") in 5ml / 10ml / 30ml vials. Historically, decanters
run their businesses entirely on TikTok and Facebook, and customers DM page after page
asking "Hey, do you have X fragrance? I want a 10ml decant" and usually hear "No."

**Decant Please! fixes discovery *and* ordering:**

- **Customer side (Next.js, public, no login):** a browsable, filterable catalog of
  the decanter's available fragrances with decant sizes, prices, scent notes, vibes,
  longevity, and gender — then a real guest checkout, an order-complete page with a
  tracking code, and a tracking page. No accounts, no payment gateway — checkout
  collects the order and contact details; the decanter confirms payment the way small
  Myanmar sellers do today (bank transfer / mobile banking / cash on delivery),
  outside this system.
- **Admin side (Laravel + Filament, login required):** the decanter manages the
  fragrance catalog (full CRUD), reviews and **accepts or rejects** incoming
  checkout orders, can still log an order manually for a customer who DMs instead of
  using the site, and gets an auto-generated daily decant production schedule.

## 2. Tech stack (fixed — do not substitute; versions verified current as of July 2026)

| Layer | Choice | Version | Notes |
|---|---|---|---|
| Backend framework | Laravel | **13.x** | Zero breaking changes from 12; requires PHP 8.3+ |
| Admin panel | Filament | **v5.x** | Functionally identical to v4 — v5 exists only to support Livewire v4. Either works; pin v5 for a new build. |
| Language | PHP | **8.3+** | Laravel 13 floor |
| Database | MySQL | **8.0+** | |
| Frontend framework | Next.js | **16.x** | App Router, Turbopack default, `proxy.ts` not `middleware.ts` |
| Runtime | Node.js | **24 LTS** | Next.js 16 requires 20.9+ minimum; use the current LTS |
| Language | TypeScript | **5.x** | |
| Styling | Tailwind CSS | **v4.x** | CSS-first config — `@import "tailwindcss"` + an `@theme` block in `globals.css`, no `tailwind.config.ts` needed |
| Scroll / timeline animation | GSAP | latest | 100% free incl. ScrollTrigger, SplitText — no license gate |
| Component / state animation | Motion | latest | Formerly "Framer Motion" — package is `motion`, import from `motion/react`, not `framer-motion` |
| Repo layout | monorepo | — | `backend/` (Laravel) and `frontend/` (Next.js) |
| Currency | Myanmar Kyat | — | Displayed as `50,000 Ks` (integer, comma-separated, suffix `Ks`). Never decimals. |

## 3. Design language (customer side) — v2

Premium, minimalist, apothecary-adjacent. Comparable in restraint to Le Labo, Byredo,
Diptyque, Aesop, Jo Malone — but built from this project's own tokens, not a copy of
any of them.

**Design reference:** the decanter's "Website design" Pinterest board. The pins that
actually matter for this project are the skincare/fragrance e-commerce ones — Aesop
Skincare, The Ordinary, sage, Klur, IKKAR, and the two perfume "About Us"/case-study
pins — not the architecture, furniture, CBD, resume, or brand-strategy-deck pins also
on that board, which are a different register. What those relevant pins share: a lot
of quiet pale space, clinical rather than decorative product photography, type doing
almost all the work, and one dark or saturated section used sparingly for contrast
(Aesop's dark panels, the moody perfume "About Us" pages). That's the register this
spec is building toward — restrained and a little clinical, not warm-and-cozy.

**Color** (hex values are fixed — do not substitute):

| Token | Hex | Use |
|---|---|---|
| `mist` | `#F2F8FC` | page background — swapped from an earlier warm cream (`#FEFDDF`); paired with `pine`, the cool tone reads crisp rather than sleepy |
| `ink` | `#212121` | body text |
| `ink-strong` | `#000000` | headlines, high-emphasis numerals |
| `pine` | `#013E37` | buttons, active states, links, prices, icons |
| `pine-soft` | `#E7ECE3` | selected-row fills, subtle hover backgrounds — derived from `pine`, not `mist`, so it didn't need to move |
| `rule` | `#D6E4EB` | hairline borders on pills, cards, dividers — recalculated as a cool blue-gray to sit correctly against `mist` (was a warm khaki against the old cream) |
| `surface-alt` | `#E3EEF4` | section backgrounds, image plates — recalculated as a slightly deeper cool tone off `mist` |
| `muted` | `#63707A` | captions, secondary labels — recalculated as a cool slate gray so it doesn't read warm against `mist` |
| `status-pending` | `#B08D57` | "awaiting confirmation" badge — unchanged; a semantic amber, not derived from the background |
| `status-danger` | `#8C4A34` | "rejected" / "cancelled" badge — unchanged, same reasoning |

Implement as a Tailwind v4 `@theme` block (CSS custom properties), not a JS config —
see `06-frontend-nextjs.md` for the exact block.

**Type:** Helvetica, per the brief. Helvetica itself isn't a licensable web font, so
use the system stack `'Helvetica Neue', Helvetica, Arial, sans-serif` — this renders
true Helvetica Neue on Mac/iOS and the metrically-identical Arial elsewhere, with zero
font-loading cost or flash of unstyled text. One family throughout; hierarchy comes
from weight, tracking, and scale, not from mixing fonts. Uppercase, wide-tracked
labels for brand/fragrance names and pill text, matching the reference cards.

**Signature motif:** every atomic piece of metadata (brand, concentration, size,
status) lives inside a thin hairline-bordered pill — an apothecary vial label, not a
generic badge. The one deliberate motion moment is the order-status timeline: it
fills like liquid rising in a vial as the order moves from Submitted through
Delivered. Everything else stays quiet.

**Layout:** generous whitespace, mobile-first, near-black text on `mist`, pine used
sparingly (never as a large fill except buttons and the vial-fill status track).

## 4. Domain model (source of truth)

### Brand — unchanged from v1
- `name`, `slug`, `type`: `designer` \| `niche`, `logo` (optional), `is_active`.

### Fragrance — unchanged from v1
- `brand_id`, `name`, `slug`
- `concentration`: `EDT` \| `EDP` \| `Parfum` \| `Cologne` \| `Extrait` \| `Other`
- `gender`: `male` \| `female` \| `unisex`
- `notes`, `vibes`, `performance`, `description`, `image`, `is_active`, `is_featured`

### DecantPrice — unchanged from v1
- `fragrance_id`, `size_ml` (5, 10, 30, or custom), `price_mmk`, `in_stock`

### Order — **changed in v2**
- `customer_name`, `phone`, `address`
- `order_from`: `website` \| `tiktok` \| `facebook` \| `other` — **`website` is new**;
  the other three remain for orders the decanter still logs manually from a DM
- `tracking_code` — **new.** Unique random alphanumeric (~10 chars), generated on
  creation, shown to the customer at checkout and used (with phone) to look up status
- `decant_date` — **now nullable.** Set by the decanter when they accept an order, not
  by the customer. Still required at creation time for manually-entered orders (the
  decanter is accepting it by typing it in).
- `delivery_date` — unchanged, nullable
- `status`: `awaiting_confirmation` \| `pending` \| `decanted` \| `delivered` \|
  `cancelled` \| `rejected` — **`awaiting_confirmation` and `rejected` are new.**
  Website checkouts start at `awaiting_confirmation`; manual admin entries start at
  `pending` (the decanter already accepted it by entering it).
- `rejection_reason` — **new.** Nullable string, set when status becomes `rejected`.
- `deposit_mmk`, `delivery_fee_mmk`, `discount_mmk`, `notes`, `total_mmk` — unchanged

### OrderItem — unchanged from v1
- `order_id`, `fragrance_id`, `size_ml`, `unit_price_mmk` (snapshot), `quantity`,
  `line_total_mmk`, `fragrance_name_snapshot`

**Key rule, still true:** order item prices are **snapshots**, taken server-side at
the moment of order creation — never a live reference, and never trusted from the
client on checkout (see `05-api-layer.md`).

## 5. Customer-side features (Next.js) — v2

1. **Home** — brand intro, featured fragrances, a genuinely-sequential "how it
   works" (Browse → Checkout → Track), category entry points.
2. **Shop/collection** — the full filterable catalog grid from v1, unchanged:
   brand, brand type, gender, size, price range + sort, notes search, free-text
   search, all URL-query-string driven.
3. **Fragrance detail** — the reference-card layout (brand pill, name pill with
   concentration in `pine`, image, decant price list, performance/notes/gender
   pills), plus a size selector and an **Add to cart** button.
4. **Cart** — a slide-in drawer, not a separate page. Client-side only
   (React context + `localStorage`), guest, no account. Quantity per line, remove
   line, subtotal for display only (server re-derives the real total at checkout).
5. **Checkout** — cart summary + contact form (name, phone, address, optional
   note). No payment fields. Submits to a new public write endpoint.
6. **Order complete** — shows the tracking code prominently, order summary, and a
   link to the tracking page. URL carries the code so it survives a refresh.
7. **Track order** — form (tracking code + phone) → status timeline. No login.
8. No "Order via DM" buttons on this build — checkout replaces that flow for the
   customer-facing site. `order_from` still supports DM-sourced orders on the admin
   side for a decanter who gets one anyway.

## 6. Admin-side features (Filament) — v2

1. Auth, Brand CRUD, Fragrance CRUD — unchanged from v1.
2. **Order review:** a "Needs review" tab (default/first tab, badge count) for
   `awaiting_confirmation` orders, with **Accept** (assign decant_date + delivery_date,
   status → `pending`) and **Reject** (reason, status → `rejected`) actions.
3. **Manual order entry** — unchanged from v1, still available for DM-sourced orders;
   starts at `pending` since the decanter is accepting it by entering it.
4. **Production schedule** — a dedicated page (not just a filtered order list)
   showing, per upcoming day, which fragrances + sizes need decanting and in what
   quantity, aggregated across all non-cancelled/non-rejected orders due that day.
   This is the "automatically generate a schedule" requirement from the brief.
5. Dashboard widgets: revenue this month, orders by status, **awaiting confirmation**
   count, decants due today, top fragrances.
6. Everything remains notes + financials + fulfillment only — no messaging, no
   customer portal, no payment processing.

## 7. Conventions

- Laravel: enums via PHP backed enums; money stored as unsigned integers (Kyat);
  API resources for JSON shaping; eager-load to avoid N+1.
- **Checkout-specific:** the server re-derives `unit_price_mmk` and validates
  `is_active`/`in_stock` from the current catalog at submission time — the client
  only ever sends `fragrance_id`, `size_ml`, and `quantity`. Never trust a
  client-submitted price.
- **Tracking lookup** requires an exact `tracking_code` + `phone` match; a mismatch on
  either returns the same generic "not found," so the endpoint isn't a guessing
  oracle for either field.
- Next.js: App Router, server components for catalog fetching, TypeScript types
  mirroring API resources, Tailwind v4 (`@theme`, no JS config) unless asked
  otherwise. Cart state client-side only.
- Slugs auto-generated; images stored via Laravel `storage` and served publicly.
- All list endpoints paginated.
- Keep code simple and readable — this is a small business tool, not enterprise SaaS.
- Your Claude Code environment already has `frontend-design`, the `vercel-*` skills,
  and `web-design-guidelines` active — consult those for implementation-level
  Next.js/Vercel patterns (view transitions, composition, React best practices)
  rather than re-deriving them; this file governs product/design decisions, those
  skills govern how you write the code.

## 8. Out of scope (do NOT build unless explicitly asked)

- Online payment gateway / card processing (KBZPay, WavePay, Stripe, etc.) — payment
  confirmation stays a manual, offline step for the decanter
- Customer accounts / login on the customer side
- Chat/messaging features
- Multi-tenant / multi-decanter marketplace (single decanter for v1/v2)
- Inventory tracking of bottle volumes (only the `in_stock` flag)
- Email notifications (nothing in the brief asks for them; tracking is code + phone
  only). Flag it if you want order-confirmation emails or SMS later — that's a
  clean addition on top of this schema, not a redesign of it.
