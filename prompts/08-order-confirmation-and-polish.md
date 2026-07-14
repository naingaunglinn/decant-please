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
  confirks before calling `POST /orders/cancel`, then re-renders the now-cancelled
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

## 3. Print / download as a receipt — a real document, not the live view with chrome hidden

**Corrected — the original version of this section only said to hide nav/footer/
buttons for print, which produces a printed live-tracking-page rather than a receipt.
Concretely wrong in what shipped: the interactive status timeline prints as-is, so a
PDF saved the moment an order is placed permanently shows "AWAITING CONFIRMATION" and
"We'll confirm shortly" — accurate for the ten seconds after checkout, wrong for every
time that saved file is opened again after. A receipt is a snapshot of a completed
transaction; anything phrased as "shortly," or a progress track with future steps
sitting empty, promises live updates a static document can't deliver.**

Still no PDF library — `@media print` plus the browser's native "Save as PDF"
destination is still the right mechanism. What changes is that print gets its own
content, not the live view with some elements hidden:

- Add a `.print-only` block inside `OrderReceipt` — `display: none` on screen,
  `display: block` only inside `@media print` (mirror image of `.no-print`, which
  stays for nav/footer/cart icon/every button). This block is the actual receipt:
  - **Heading is the document type, not the live-page heading** — "RECEIPT," not
    "Order placed." `DECANT PLEASE!` as the letterhead above it.
  - Order number + tracking code, the date the order was placed, and — only if it
    reads distinctly from that — a "Printed on {today}" line. Don't show today's date
    twice under two different labels when they're the same day.
  - **Status as one static fact, not the interactive timeline:** "Status at time of
    printing: {current status label}." The live `StatusTimeline` component itself
    gets `.no-print` — it does not appear in the printed output in any form.
  - Customer and shipping blocks, itemized list, payment summary — these parts of
    the existing live view were fine, carry them into the print-only block as-is.
  - Payment-method note, reworded to hold up over time: "No online payment is taken —
    payment is arranged by bank transfer, mobile banking, or cash on delivery once
    the order is confirmed." Not "we'll confirm within a few hours" — true right
    after checkout, meaningless read back a week later.
- The print button stays as it was: `<button className="no-print"
  onClick={() => window.print()}>Print / Save as PDF</button>`.
- Keep the pine accent on the total line in print; simplify everything else (no
  interactive states, no hover styles) to print cleanly on plain paper.


## 4. Related fragrances (detail page)

On `/fragrance/[slug]`, below the existing content: a small "You may also like" rail
— same-brand fragrances first (excluding the current one), falling back to
same-gender if the brand has fewer than 2 others. Reuse `FragranceCard` and the
existing `getFragrances()` fetcher; no new API endpoint needed.

## 5. Recently viewed

Client-side only — `localStorage`, last ~6 fragrance slugs visited, a small rail on
`/shop` or the home page (your call). Fetch card data for those slugs on render; drop
any slug that 404s (deactivated since it was viewed). If the page already shows some
of those fragrances elsewhere (the home page's featured rail), skip them in this rail —
`<ViewTransition>` names must be unique page-wide, and the same card twice on one
screen is noise anyway.

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
  nav/footer/buttons are gone, the interactive status timeline is *not* in the
  printed output at all, a single static "Status at time of printing" line is, and
  the heading reads "Receipt," not "Order placed."
- Confirm delivery-fee/discount/deposit rows are absent when zero and present once
  an admin sets them (accept an order, add a delivery fee, re-check the customer's
  tracking view).
- Related fragrances and recently-viewed both render sensibly on a catalog with only
  a handful of items per brand (the seed data). Sitemap.xml is reachable and lists
  every active fragrance.
- Report results with a short checklist, same as every prior step.
