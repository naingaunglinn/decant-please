# Step 8 — Real order confirmation, printable receipts & catalog fundamentals

Work across `backend/` and `frontend/`. Follow `CLAUDE.md` v3. This step is additive
to the already-deployed v2 build — it doesn't redo Steps 1-7, it enriches the
order-complete/tracking experience and adds a handful of e-commerce fundamentals that
were still missing. Written against what's actually in the repo today:
`TrackOrderController`, `OrderController`, `Order` model, `OrderCompleteClient`,
`TrackClient`, `StatusTimeline`, `CheckoutClient` — reference those directly rather
than re-deriving names.

## 1. Backend — richer tracking response + customer cancellation

### 1.1 Expand `GET /orders/track`

Add to the existing response (don't remove anything): `order_number` (formatted as
`"#{$order->id}"`), `customer_name`, `phone`, `address`, `subtotal_mmk`,
`delivery_fee_mmk`, `discount_mmk`, `deposit_mmk` (raw integers — the frontend
already has a Kyat formatter, format there, not twice), and per-item
`unit_price_mmk` + `line_total_mmk` alongside the existing `fragrance_name`/
`size_ml`/`quantity`. Keep `total_formatted` as-is for backward compatibility.

This reverses the earlier "don't return the address" caution from `05-api-layer.md`
— the code+phone pair already gates this whole endpoint, so withholding just the
address protected against nothing while making it impossible to show the customer
their own delivery details back to them, which a receipt needs to do.

### 1.2 New: `POST /orders/cancel`

Same shape as the tracking lookup: body `{ tracking_code, phone }`, same generic
404 on a mismatch. If found:
- `status === awaiting_confirmation` → call a new `$order->cancel()` model method
  (mirror `accept()`/`reject()`'s guard-then-transition pattern), set
  `status = cancelled`, return the updated status payload.
- any other status → `409` with `"This order's already being prepared — call to
  cancel or change it."` A customer can back out before the decanter has committed
  time to it; after that, it's a phone call, not a self-service click, since the
  decanter may have already bought/prepped physical stock for it.

Rate limit it the same as the other public write endpoint (`POST /orders`), not the
looser read limit.

> Implementation note (discovered while building this): Laravel's inline
> `throttle:n,1` keys guests by IP alone — no path — so every route sharing that
> middleware shape shares one counter, and catalog browsing was eating the checkout
> allowance. The build switched all four public limits to named limiters with
> per-endpoint buckets (see `AppServiceProvider`); any future public endpoint should
> follow that pattern, not inline `throttle:n,1`.

## 2. Frontend — one shared receipt view, not two overlapping ones

`OrderCompleteClient` and `TrackClient` currently both render an items list + total
independently, and `OrderCompleteClient`'s content depends entirely on a client-side
`sessionStorage` write from `CheckoutClient` — if that's unavailable (private
browsing, a different device, storage cleared), the confirmation page has nothing to
show but the bare code. Fix this by fetching real data on both pages instead of
caching it on one.

### New shared component (e.g. `OrderReceipt`)
Takes the (now-expanded) tracking response and renders, top to bottom:
- Order number + status pill (reuse the existing `Pill` component and status-to-tone
  mapping already in `TrackClient`)
- `StatusTimeline` — unchanged, already good, just keep reusing it
- **Customer** (name, phone) and **Shipping** (address) as two small labeled blocks
  — the vial-label pill pattern again, not a plain unstyled list
- Itemized list with unit price and line total per row (not just name + qty as today)
- **Payment summary**: subtotal always shown; delivery fee / discount / deposit rows
  only when their raw value is non-zero (they're usually 0 until the decanter
  processes the order — an itemized list of zeroes looks broken, not thorough);
  total always shown; one line underneath echoing the existing footer copy — no
  online payment, arranged by bank transfer/mobile banking/COD after confirmation —
  since a printed receipt won't carry the site's global footer with it
- **Estimated delivery**: while `delivery_date` is null, "We'll confirm within a few
  hours and let you know the exact date" (adjust the timeframe to whatever the
  decanter actually commits to) — never fabricate a date. Once set, "Estimated
  delivery: {delivery_date, formatted}"
- **Cancel this order** — only rendered while `status === awaiting_confirmation`;
  confirms before calling `POST /orders/cancel`, then re-renders the now-cancelled
  state in place rather than navigating away
- **Print / Save as PDF** button (see §4) and the context-appropriate CTA row —
  Order Complete shows "Track this order" + "Keep browsing"; the Track page (already
  on `/track`) just needs "Search another order"

Both `OrderCompleteClient` and `TrackClient` render this one component; neither
duplicates its markup.

### 2.1 Rewire `CheckoutClient` → `OrderCompleteClient`

`CheckoutClient` should stash `{ code, phone }` only (not the whole item list) right
after a successful order. `OrderCompleteClient`:
1. Reads `code` from the URL (unchanged).
2. Reads `phone` from that small `sessionStorage` entry.
3. If present, calls the existing `trackOrder(code, phone)` immediately and renders
   `OrderReceipt` once it resolves — this is now the *only* path, optimistic caching
   isn't needed since the request is a single fast round trip.
4. If `phone` isn't available (storage cleared, different device), show a compact
   one-field "Confirm your phone number to view your receipt" form instead of a dead
   end — this can be the existing `TrackingForm` pre-filled with `code`.

Test this specifically in a private/incognito window with `sessionStorage` cleared,
since that's the exact condition the current implementation silently degrades under.

## 3. Print / download as a receipt, not a new dependency

No PDF library — a real `@media print` stylesheet plus the browser's native
"Save as PDF" print destination covers both "print" and "download" from the same
button. In `OrderReceipt`'s print styles: hide nav, footer, cart icon, and every
button except none (buttons shouldn't print at all — mark them `.no-print`); show a
letterhead (`DECANT PLEASE!` wordmark + "RECEIPT" + today's date) that only appears
in print output; keep the pine accent for the total line, simplify everything else to
print cleanly on plain paper. The button itself: `<button className="no-print"
onClick={() => window.print()}>Print / Save as PDF</button>`.

## 4. Related fragrances (detail page)

On `/fragrance/[slug]`, below the existing content: a small "You may also like" rail
— same-brand fragrances first (excluding the current one), falling back to
same-gender if the brand has fewer than 2 others. Reuse `FragranceCard` and the
existing `getFragrances()` fetcher; no new API endpoint needed.

## 5. Recently viewed

Client-side only — `localStorage`, last ~6 fragrance slugs visited, a small rail on
`/shop` or the home page (your call). Fetch card data for those slugs on render; drop
any slug that 404s (deactivated since it was viewed).

## 6. Sitemap

Add `frontend/src/app/sitemap.ts` (Next.js's file convention) covering `/`, `/shop`,
and every active fragrance's `/fragrance/[slug]`, sourced from the existing catalog
API. `robots.ts` already exists — point it at the generated sitemap if it doesn't
already reference one.

## 7. Verify

- Feature tests for the expanded tracking response and the new cancel endpoint
  (found + awaiting_confirmation → cancels; found + any other status → 409; not
  found → the same generic 404 tracking already uses).
- Place an order, then open `/order/complete?code=...` in a fresh private window
  (no sessionStorage) — confirm the phone-confirmation fallback appears and, once
  submitted, renders the full receipt.
- Confirm the cancel button appears only pre-confirmation, and that cancelling
  updates the view in place without a reload.
- Print preview (or actual "Save as PDF") on both Order Complete and Track — confirm
  nav/footer/buttons are gone and the layout reads as a real receipt.
- Confirm delivery-fee/discount/deposit rows are absent when zero and present once
  an admin sets them (accept an order, add a delivery fee, re-check the customer's
  tracking view).
- Related fragrances and recently-viewed both render sensibly on a catalog with only
  a handful of items per brand (the seed data). Sitemap.xml is reachable and lists
  every active fragrance.
- Report results with a short checklist, same as every prior step.
