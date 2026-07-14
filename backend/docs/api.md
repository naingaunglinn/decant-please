# Decant Please! — `/api/v1` contract

The complete public API surface: eight endpoints under `https://<api-host>/api/v1`,
all JSON. This is what the Next.js storefront consumes today and what any future
client (the decanter's possible Flutter app) would consume as-is — the API is already
stateless: no cookies, no sessions, no customer accounts, `supports_credentials`
disabled in CORS. For order endpoints, *possession of the tracking code + phone pair
is the authentication*. CORS allows only the `FRONTEND_URL` origin, which constrains
browsers but not native clients.

This file documents **current behavior**, verified against the controllers — if the
code and this file disagree, the code is right and this file needs a PR.

## Conventions every client must follow

**Money is integer Kyat.** All `*_mmk` fields are whole-Kyat integers (never
decimals). Fields ending `*_formatted` carry the display form — `"50,000 Ks"` —
so clients don't re-implement formatting, but the integers are authoritative.

**Prices are never client-supplied.** Checkout and promo preview accept only
`fragrance_id` + `size_ml` + `quantity` per line. The server re-derives every unit
price from the live catalog at that moment and (at checkout) stores immutable
snapshots on the order. A client that caches catalog prices for display must still
expect the server's derived totals to win.

**Tracking is not a guessing oracle.** `/orders/track` and `/orders/cancel` return
the *same* generic 404 whether the code or the phone was wrong. Don't build UI that
tries to distinguish; it can't.

**Validation errors are Laravel-shaped.** Invalid input returns `422` with:

```json
{ "message": "…", "errors": { "field": ["human-ready message"] } }
```

Item-level problems are keyed `items.N` (e.g. `items.0`) with one of:
- `That fragrance is no longer available.` — unknown id, or fragrance/brand deactivated
- `{size}ml of {name} just sold out — pick another size.` — size exists but out of stock

**Rate limits are per-endpoint buckets, keyed by client IP.** Exhausting one bucket
never starves another (a burst of catalog browsing can't block a checkout):

| Bucket | Endpoints | Limit |
|---|---|---|
| `catalog` | `GET /brands`, `/fragrances`, `/fragrances/{slug}`, `/meta` | 120/min |
| `checkout` | `POST /orders` | 10/min |
| `tracking` | `GET /orders/track` | 20/min |
| `cancel` | `POST /orders/cancel` | 10/min |
| `promo` | `POST /orders/validate-promo` | 10/min |

Over the limit: `429` with `{ "message": "Too Many Attempts." }` and a `Retry-After`
header. Clients should back off, not retry-loop.

**Catalog visibility.** Every catalog response is pre-filtered to active fragrances
whose brand is also active. `min_price_mmk` is the lowest **in-stock** decant price;
`null` means every size is sold out (the fragrance still appears — sold out is a
state, not a deletion). `/brands` and `/meta` are server-cached for 10 minutes, so
admin catalog edits can lag there by up to that long.

---

## `GET /brands`

Active brands, ordered by name.

```json
{ "data": [ { "id": 1, "name": "Chanel", "slug": "chanel", "type": "designer",
  "type_label": "Designer", "logo_url": null, "fragrances_count": 4 } ] }
```

`fragrances_count` counts **active** fragrances only. `type` is `designer | niche`.

## `GET /fragrances`

The filterable catalog. All parameters optional:

| Param | Type | Meaning |
|---|---|---|
| `q` | string ≤100 | matches fragrance name or brand name |
| `notes` | string ≤100 | substring match on scent notes |
| `brand` | string | comma-separated brand slugs, e.g. `chanel,creed` |
| `type` | `designer\|niche` | brand type — note: the storefront's URL uses `brand_type`, but the **API param is `type`** |
| `gender` | `male\|female\|unisex` | |
| `size` | int | only fragrances with this size **in stock** |
| `min_price` / `max_price` | int Ks | matched against any in-stock decant price |
| `featured` | bool | `1` = featured only |
| `sort` | `newest\|price_asc\|price_desc\|name` | default `newest`; price sorts push all-sold-out items last |
| `per_page` | int 1–50 | default 12 |
| `page` | int | standard Laravel pagination |

Response is a standard Laravel paginated collection — `data` (array of fragrance
objects, below), `links` (`first/last/prev/next`, filters preserved in the URLs),
`meta` (`current_page`, `last_page`, `per_page`, `total`, …).

**The fragrance object** (same shape in the index and show endpoints):

```json
{
  "id": 20, "name": "Grand Soir", "slug": "maison-francis-kurkdjian-grand-soir",
  "brand": { "id": 8, "name": "Maison Francis Kurkdjian", "slug": "maison-francis-kurkdjian",
             "type": "niche", "type_label": "Niche", "logo_url": null },
  "concentration": "edp", "concentration_label": "EDP",
  "gender": "unisex", "gender_label": "Unisex",
  "notes": "amber, honey, vanilla", "vibes": "warm, evening", "performance": "8h+",
  "description": "…", "image_url": "https://…/storage/fragrances/….jpg",
  "is_featured": true,
  "min_price_mmk": 25000, "min_price_formatted": "25,000 Ks",
  "prices": [ { "size_ml": 5, "price_mmk": 25000, "price_formatted": "25,000 Ks", "in_stock": true } ]
}
```

`notes`, `vibes`, `performance`, `description`, `image_url`, `min_price_*` are all
nullable. `concentration` is `edt|edp|parfum|cologne|extrait|other`.

## `GET /fragrances/{slug}`

`{ "data": { …fragrance object… } }`, or `404` `{ "message": "Fragrance not found." }` —
inactive fragrances and inactive brands 404 exactly like unknown slugs.

## `GET /meta`

Everything a client needs to build filter UI without hardcoding:

```json
{
  "brand_types":     [ { "value": "designer", "label": "Designer" }, … ],
  "genders":         [ { "value": "male", "label": "Male" }, … ],
  "concentrations":  [ { "value": "edt", "label": "EDT" }, … ],
  "sizes": [5, 10, 30],
  "price": { "min": 8000, "max": 120000 },
  "sorts": ["newest", "price_asc", "price_desc", "name"],
  "social": { "tiktok_url": "https://…", "facebook_url": null }
}
```

`sizes` and `price` reflect **in-stock** decants only; `price.min/max` are `null` on
an empty catalog. `social` URLs are `null` when unconfigured.

## `POST /orders` — guest checkout

```json
{
  "customer_name": "Ma Thiri",            // required, ≤255
  "phone": "09-123456789",                // required, ≤30 — becomes the tracking credential
  "address": "…",                         // required, ≤1000
  "note": "call before delivery",         // optional, ≤1000
  "promo_code": "WELCOME10",              // optional, ≤64 — case-insensitive
  "items": [                              // required, 1–20 lines
    { "fragrance_id": 20, "size_ml": 10, "quantity": 2 }   // quantity 1–50
  ]
}
```

There is also an optional `website` field — a **honeypot**. Real clients must omit
it (or send it empty). Any non-empty value makes the server log the attempt, store
nothing, and return a *convincing fake* `201` (random code, zero total), so a bot
can't tell it was caught. Don't ever map a real UI field to `website`.

What the server does, atomically:

1. Re-validates every line against the live catalog (active fragrance + brand,
   size in stock) — failures are `422` with `items.N` messages as above.
2. Re-derives unit prices and stores them as immutable snapshots on the order items.
3. If `promo_code` was sent, re-evaluates it **under a row lock** (usage counted
   exactly once, no double-spend). A code that lapsed since preview does **not**
   fail the order — the discount is dropped and `promo_note` explains it.
4. Creates the order at status `awaiting_confirmation` with a fresh tracking code.

**Response `201`:**

```json
{
  "tracking_code": "9BGQCECV6C",
  "total_mmk": 68400,
  "total_formatted": "68,400 Ks",
  "promo_note": null
}
```

- `tracking_code` — 10 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0/O/1/I).
  Show it to the customer immediately, paired with their phone it is the only way
  back to this order.
- `promo_note` — `null` normally; when a promo lapsed between preview and submit it
  is exactly: `That code was no longer valid, so it wasn't applied — you can still
  place this order without it.`
- `total_mmk` at creation = items subtotal − discount. Delivery fee and deposit are
  0 until the decanter sets them during review — re-fetch via `/orders/track` for
  the authoritative running totals.

## `GET /orders/track?tracking_code=…&phone=…`

Both parameters required (≤32 chars). The code is matched case-insensitively and
trimmed; the phone must match the order exactly as entered at checkout. Any mismatch:

```json
404 { "message": "We couldn't find an order with that code and phone number." }
```

**Response `200` — the receipt payload** (also returned by a successful cancel):

```json
{
  "tracking_code": "9BGQCECV6C",
  "order_number": "#19",
  "status": "awaiting_confirmation",
  "status_label": "Awaiting Confirmation",
  "placed_at": "2026-07-14T08:05:00+06:30",
  "decant_date": null,               // date string once the decanter accepts
  "delivery_date": null,             // date string once scheduled
  "rejection_reason": null,          // string when status = rejected
  "customer_name": "Ma Thiri",
  "phone": "09-123456789",
  "address": "…",
  "items": [
    { "fragrance_name": "Creed — Aventus (EDP)", "size_ml": 10,
      "quantity": 2, "unit_price_mmk": 38000, "line_total_mmk": 76000 }
  ],
  "subtotal_mmk": 76000,
  "delivery_fee_mmk": 0,
  "discount_mmk": 7600,
  "promo_code": "WELCOME10",         // null when no code was used
  "deposit_mmk": 0,
  "total_mmk": 68400,
  "total_formatted": "68,400 Ks"
}
```

`status` is one of `awaiting_confirmation | pending | decanted | delivered |
cancelled | rejected`; `status_label` is its human-ready form. Statuses only move
forward via the decanter's admin actions — plus the one customer-initiated
transition below.

## `POST /orders/cancel`

Body: `{ "tracking_code": "…", "phone": "…" }` — same credentials, same generic 404
on mismatch. Rules:

- Order is `awaiting_confirmation` → cancelled; `200` with the receipt payload
  (now `status: "cancelled"`).
- Any other status → `409` `{ "message": "This order's already being prepared —
  call to cancel or change it." }` — after acceptance, cancellation is a phone
  call, not an API call.

## `POST /orders/validate-promo` — preview only

Body: `{ "code": "WELCOME10", "items": [ …same line shape as checkout… ] }`.
Nothing is persisted or incremented; the subtotal is re-derived server-side
(unavailable items → `422` with `items.N`, same as checkout).

**Response `200`** (both outcomes are 200 — invalidity is data, not an error):

```json
{ "valid": true,  "discount_mmk": 7600, "discount_formatted": "7,600 Ks",
  "new_total_formatted": "68,400 Ks", "message": null }

{ "valid": false, "discount_mmk": 0, "discount_formatted": "0 Ks",
  "new_total_formatted": "76,000 Ks", "message": "We couldn't find that code." }
```

Failure `message` is exactly one of:
- `We couldn't find that code.` — unknown, paused, or outside its date window
  (deliberately indistinguishable)
- `That code has reached its usage limit.`
- `This code needs an order of at least {amount} Ks.`

Discount math (server-side, don't re-implement for anything but display): percent
codes take `floor(subtotal × value / 100)` capped at the code's `max_discount_mmk`;
fixed codes take their value; both are clamped to the subtotal. A preview is
advisory — checkout re-evaluates under lock, so always handle `promo_note`.
