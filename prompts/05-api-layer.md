# Step 5 — Public JSON API (backend) — v2

Work in `backend/`. Follow `CLAUDE.md` v2. The catalog endpoints from v1 stay
**read-only and unauthenticated** exactly as before. v2 adds **two new public
endpoints** so checkout and tracking work without any authentication layer: one
write (place an order), one read (look up status by code). Both need tighter
rate limiting than the catalog reads, because both are open to the public internet
by design.

## 1. Routes (`routes/api.php`, prefix `/api/v1`)

| Method | Path | Purpose | Auth |
|---|---|---|---|
| GET | `/brands` | active brands with active-fragrance counts | none — unchanged |
| GET | `/fragrances` | filterable, sortable, paginated catalog | none — unchanged |
| GET | `/fragrances/{slug}` | single fragrance detail | none — unchanged |
| GET | `/meta` | filter metadata + global min/max price | none — unchanged |
| **POST** | **`/orders`** | **submit a checkout order** | **none — public write, see hardening below** |
| **GET** | **`/orders/track`** | **look up status by tracking code + phone** | **none — public read, see hardening below** |

`/fragrances`, `/fragrances/{slug}`, `/brands`, `/meta` — query parameters, response
shapes, caching, and CORS are all unchanged from v1. Don't re-derive them; carry them
forward as-is.

## 2. POST `/orders` — checkout submission

### Request body
```json
{
  "customer_name": "Su Su",
  "phone": "09-xxxxxxxxx",
  "address": "No. 12, Bahan Township, Yangon",
  "note": "Please call before delivery",
  "items": [
    { "fragrance_id": 1, "size_ml": 10, "quantity": 1 },
    { "fragrance_id": 7, "size_ml": 5,  "quantity": 2 }
  ],
  "website": ""
}
```

`website` is a honeypot field: render it visually hidden in the frontend form (not
`display:none` — use an off-screen technique a screen reader also skips, e.g.
absolute-positioned off-canvas) and reject silently (pretend success, log it, don't
create the order) if it arrives non-empty. This catches basic bots without imposing a
CAPTCHA on real customers; escalate to hCaptcha/Turnstile later only if spam actually
shows up in production.

### Server-side rules — these are the integrity-critical part of this whole step
- Validate `customer_name`, `phone`, `address` required; `items` non-empty array.
- For **each** item: look up the `Fragrance` by `fragrance_id` — must be `is_active`
  (and its `Brand` must be `is_active`); look up the matching `DecantPrice` by
  `size_ml` — must exist and be `in_stock`. **Re-derive `unit_price_mmk` from that
  `DecantPrice` row. Never read a price from the request body.** If any item fails
  this (inactive fragrance, unknown size, out of stock), return a `422` naming which
  item and why — e.g. `"10ml of Allure Homme Sport just sold out — pick another
  size."` — so the frontend can surface something the customer can act on, not a
  generic failure.
- Build the order through the `Order::newFromCheckout()` path from Step 2: sets
  `order_from = website`, `status = awaiting_confirmation`, `decant_date = null`,
  generates `tracking_code`, computes snapshots and `total_mmk` server-side.
- Response: `201` with `{ "tracking_code": "DP7K2M9QXA", "total_mmk": 168000,
  "total_formatted": "168,000 Ks" }` — enough for the Order Complete page to render
  without a second round trip, but no need to echo the full order back.
- Rate limit: something noticeably tighter than the catalog reads — e.g.
  `throttle:10,1` (10/min per IP). This is a small single-decanter business, not a
  high-traffic storefront; err tight and loosen later if it's ever a problem.

## 3. GET `/orders/track` — status lookup

### Query parameters
`tracking_code` (required), `phone` (required — full number, not just last digits;
simpler to implement and the customer has it on hand).

### Behavior
- Exact match on both. If either doesn't match, return the **same** generic `404`
  (`{"message": "We couldn't find an order with that code and phone number."}`) —
  don't reveal which field was wrong, or the endpoint becomes a guessing oracle for
  the correct tracking code by fixing a phone number and brute-forcing codes (or vice
  versa).
- On match, return status, a simple timeline, and an item summary — no need to return
  the full address back to the client:
```json
{
  "tracking_code": "DP7K2M9QXA",
  "status": "decanted",
  "status_label": "Decanted",
  "placed_at": "2026-07-10T09:14:00+06:30",
  "decant_date": "2026-07-13",
  "delivery_date": "2026-07-14",
  "rejection_reason": null,
  "items": [
    { "fragrance_name": "Chanel — Allure Homme Sport (Cologne)", "size_ml": 10, "quantity": 1 }
  ],
  "total_formatted": "168,000 Ks"
}
```
- Rate limit: tighter than catalog reads too — e.g. `throttle:20,1` (20/min per IP).
  This endpoint is a lookup, not a listing — never add a "list my recent orders"
  variant; tracking code + phone is the only way in, by design.

## 4. Config & hygiene — unchanged from v1, still applies
- CORS: frontend origin only.
- Cache `/meta` and `/brands` (`Cache::remember`, ~10 min).
- General catalog rate limit ~120/min, unchanged — the new tighter limits above are
  additional groups, not a replacement.

## 5. Verify

Feature tests covering, in addition to the v1 catalog tests (which still apply
unchanged):
- POST `/orders` with a valid payload creates an `awaiting_confirmation` order with a
  server-derived total that ignores any price the test tries to smuggle in the
  request body,
- POST `/orders` referencing an inactive fragrance, an out-of-stock size, or an
  unknown `fragrance_id`/`size_ml` combination returns a `422` naming the offending
  item,
- POST `/orders` with the honeypot field filled returns a `201`-looking success but
  creates no order,
- GET `/orders/track` with correct code + phone returns the expected shape; with a
  correct code but wrong phone (or vice versa) returns the same generic `404` either
  way,
- both new endpoints respect their tighter rate limits.

Run the suite and report results with 2-3 example `curl` outputs, including one
showing the `422` item-level error and one showing a successful checkout →
tracking-lookup round trip. Frontend is Step 6.
