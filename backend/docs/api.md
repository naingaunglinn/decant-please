# Decant Please! — `/api/v1/{shop}` contract

The complete public API surface: ten endpoints under
`https://<api-host>/api/v1/{shop}`, all JSON, plus one platform endpoint outside it
(`GET /api/v1/_storefront/host/{host}`, below). This is what the Next.js storefront
consumes today and what any future client (the decanter's possible Flutter app) would
consume as-is — the API is already stateless: no cookies, no sessions, no customer
accounts, `supports_credentials` disabled in CORS. For order endpoints, *possession of
the tracking code + phone pair is the authentication*. CORS allows the `FRONTEND_URL`
origins (comma-separated) plus every verified domain of every active shop
(`ShopDomain::corsOrigins()`, cached 60 s). That constrains browsers, not native
clients.

**Tenant in the path (multi-tenancy Step 24).** Every endpoint below is prefixed with
a `{shop}` slug: `GET /api/v1/{shop}/products`, `POST /api/v1/{shop}/orders`, and so
on. The slug selects which shop the request is for; the storefront pins it once
(one storefront serves exactly one shop). Only a **live** shop resolves: an unknown slug,
or a shop that is onboarding, suspended or archived, gets a bare `404` (the framework's
default body, not the tracking message) — it is not a shop-enumeration oracle beyond
what the storefront URL already reveals. Response shapes are unchanged from the
pre-prefix API; only the path gained the segment. The ten are `/brands`, `/products`,
`/products/{slug}`, `/meta`, `/delivery-zones`, and under `/orders`: checkout, `track`,
`cancel`, `payment-proof` and `validate-promo`. `/up` (health) stays unprefixed.

This file documents **current behavior**, verified against the controllers — if the
code and this file disagree, the code is right and this file needs a PR.

## Conventions every client must follow

**Money is integer Kyat.** All `*_mmk` fields are whole-Kyat integers (never
decimals). Fields ending `*_formatted` carry the display form — `"50,000 Ks"` —
so clients don't re-implement formatting, but the integers are authoritative.

**Prices are never client-supplied.** Checkout and promo preview accept only
`variant_id` + `quantity` per line (step 36b; the variant id is `prices[].id` on the
product object). The server re-derives every unit
price from the live catalog at that moment and (at checkout) stores immutable
snapshots on the order. A client that caches catalog prices for display must still
expect the server's derived totals to win.

**Tracking is not a guessing oracle.** `/orders/track`, `/orders/cancel` and
`/orders/payment-proof` return the *same* generic 404 whether the code or the phone was
wrong. Don't build UI that tries to distinguish; it can't.

**Validation errors are Laravel-shaped.** Invalid input returns `422` with:

```json
{ "message": "…", "errors": { "field": ["human-ready message"] } }
```

Item-level problems are keyed `items.N` (e.g. `items.0`) with one of:
- `That fragrance is no longer available.` — unknown id (or another shop's), or product/brand deactivated
- `{label} of {name} just sold out — pick another size.` — the variant exists but is out of stock or archived (`{label}` is e.g. `10ml`)

**Rate limits are per-endpoint buckets, keyed by shop + client IP** (step 32).
Exhausting one bucket never starves another (a burst of catalog browsing can't block a
checkout), and one shop's traffic never spends another shop's bucket:

| Bucket | Endpoints | Limit |
|---|---|---|
| `catalog` | `GET /brands`, `/products`, `/products/{slug}` (and the `/fragrances` aliases), `/meta`, `/delivery-zones` | 120/min |
| `checkout` | `POST /orders` | 10/min |
| `tracking` | `GET /orders/track` | 20/min |
| `cancel` | `POST /orders/cancel` | 10/min |
| `payment-proof` | `POST /orders/payment-proof` | 10/min |
| `promo` | `POST /orders/validate-promo` | 10/min |
| `host-resolve` | `GET /_storefront/host/{host}` (platform, keyed by IP only — no shop yet) | 60/min |

Over the limit: `429` with `{ "message": "Too Many Attempts." }` and a `Retry-After`
header. Clients should back off, not retry-loop.

**Catalog visibility.** Every catalog response is pre-filtered to active products
whose brand, if they have one, is also active (step 38b: a brandless product is
listed; a hidden brand still hides its products). Checkout sells by the same rule
(`Product::scopeSellable`). `min_price_mmk` is the lowest **in-stock** variant price;
`null` means every variant is sold out (the product still appears — sold out is a
state, not a deletion). `/brands` and `/meta` are server-cached for 10 minutes, so
admin catalog edits can lag there by up to that long.

---

## `GET /brands`

Active brands, ordered by name.

```json
{ "data": [ { "id": 1, "name": "Chanel", "slug": "chanel", "type": "designer",
  "type_label": "Designer", "logo_url": null, "fragrances_count": 4 } ] }
```

`fragrances_count` counts the brand's **active** products (the key kept its pre-36
name). `type` is `designer | niche`, or `null` for a brand in a template without brand
types (step 38b: clothing).

## `GET /products`

> **Renamed in step 36b** from `GET /fragrances` (and `/fragrances/{slug}` →
> `/products/{slug}`). The old paths still answer, identically, so a pre-36b client
> keeps working against this API; they are removed after go-live. New clients use
> `/products`.

The filterable catalog. All parameters optional:

| Param | Type | Meaning |
|---|---|---|
| `q` | string ≤100 | substring of the product's search text: brand, name and the template's *searchable* attributes (decant: notes). Case-insensitive; `%` and `_` are literal |
| `{attribute}` | per `/meta` `filters` | the shop template's filterable attributes (step 37). Decant: `gender` (`male\|female\|unisex`, exact) and `notes` (text, matched like `q`) |
| `brand` | string | comma-separated brand slugs, e.g. `chanel,creed` |
| `type` | `designer\|niche` | brand type — note: the storefront's URL uses `brand_type`, but the **API param is `type`** |
| `size` | int | only products with this ml size **in stock** (decant) |
| `option[{name}]` | string ≤50 | step 38, for a template whose variants aren't ml sizes (clothing): `option[Size]=M&option[Color]=Blue` keeps products with **one in-stock variant** matching every picked option (exact). Names per `/meta` `variant_options`; an unknown name is a `422`. A decant shop ignores it |
| `min_price` / `max_price` | int Ks | matched against any in-stock variant price |
| `featured` | bool | `1` = featured only |
| `sort` | `newest\|price_asc\|price_desc\|name` | default `newest`; price sorts push all-sold-out items last |
| `per_page` | int 1–50 | default 12 |
| `page` | int | standard Laravel pagination |

Response is a standard Laravel paginated collection — `data` (array of product
objects, below), `links` (`first/last/prev/next`, filters preserved in the URLs),
`meta` (`current_page`, `last_page`, `per_page`, `total`, …).

**The product object** (same shape in the index and show endpoints):

```json
{
  "id": 20, "name": "Grand Soir", "slug": "maison-francis-kurkdjian-grand-soir",
  "brand": { "id": 8, "name": "Maison Francis Kurkdjian", "slug": "maison-francis-kurkdjian",
             "type": "niche", "type_label": "Niche", "logo_url": null },
  "template": "decant",
  "attributes": [
    { "key": "concentration", "label": "Concentration", "value": "edp", "display": "EDP", "show": "headline" },
    { "key": "gender", "label": "Gender", "value": "unisex", "display": "Unisex", "show": "pill" },
    { "key": "notes", "label": "Scent notes", "value": "amber, honey, vanilla", "display": "amber, honey, vanilla", "show": "list" },
    …
  ],
  "concentration": "edp", "concentration_label": "EDP",
  "gender": "unisex", "gender_label": "Unisex",
  "notes": "amber, honey, vanilla", "vibes": "warm, evening", "performance": "8h+",
  "description": "…", "image_url": "https://…/shops/1/fragrances/….jpg",
  "is_featured": true,
  "min_price_mmk": 25000, "min_price_formatted": "25,000 Ks",
  "prices": [ { "id": 51, "label": "5ml", "options": { "Size": "5ml" }, "image_url": null,
                "size_ml": 5, "price_mmk": 25000,
                "price_formatted": "25,000 Ks", "in_stock": true } ]
}
```

`attributes` (step 37) lists the product's template attributes in display order; an
empty one is left out. `display` is what a customer reads: a select's label, else the
value. `template` is the product's template key. `show` (step 37b) says where a
storefront puts it: `headline` beside the product's name (and in its pill row), `pill`
in the pill row, `list` as its own section, the value split on commas, `section`
(step 38b) as its own titled paragraph with line breaks kept. The template decides:
`Template::headline()` names the headline attribute, `Attribute` `list: true` marks a
list, `section: true` a section (decant: concentration is the headline; notes and
vibes are lists; clothing: `size_guide` is a section).

`brand` is `null` when the product has no brand (step 38b) — a template may make brand
optional (clothing does; decant requires one). Clients must handle both.

The flat `concentration`, `concentration_label`, `gender`, `gender_label`, `notes`,
`vibes`, `performance` keys are read from `attributes` since step 37 and kept for the
pre-37 storefront; they go with the other deploy aliases after go-live. New clients
read `attributes`. `notes`, `vibes`, `performance`, `description`, `image_url`,
`min_price_*` are all nullable. `concentration` is `edt|edp|parfum|cologne|extrait|other`.

Each `prices[]` entry is one **variant**: `id` is what checkout sends as `variant_id`,
`label` is its display name (`"10ml"` for a decant, `"M / Blue"` for clothing).
`options` (step 38) is option name → value in the template's order, and `image_url`
the variant's own photo (a photo per colour) or `null`. `size_ml` is `null` for a
variant that isn't an ml size. Archived variants are left out.
The key is still `prices` (not `variants`) so the shape stayed additive across step 36b.

## `GET /products/{slug}`

`{ "data": { …product object… } }`, or `404` `{ "message": "Fragrance not found." }` —
inactive products and products of inactive brands 404 exactly like unknown slugs.

## `GET /meta`

Everything a client needs to build filter UI without hardcoding:

```json
{
  "filters": [
    { "key": "gender", "label": "Gender", "type": "select",
      "options": [ { "value": "male", "label": "Male" }, … ] },
    { "key": "notes", "label": "Scent notes", "type": "text", "options": [] }
  ],
  "brand_types":     [ { "value": "designer", "label": "Designer" }, … ],
  "genders":         [ { "value": "male", "label": "Male" }, … ],
  "concentrations":  [ { "value": "edt", "label": "EDT" }, … ],
  "sizes": [5, 10, 30],
  "variant_options": [],
  "price": { "min": 8000, "max": 120000 },
  "sorts": ["newest", "price_asc", "price_desc", "name"],
  "social": { "tiktok_url": "https://…", "facebook_url": null },
  "payment": { "kbzpay_name": "…", "kbzpay_number": "…", "wave_name": "…",
               "wave_number": "…", "qr_url": "https://…", "instructions": "…" },
  "modules": ["stock", "cost_margin", "production_schedule", "promo_codes", "expenses"]
}
```

`filters` (step 37) is the shop template's filterable attributes, in order: each is
a `/products` query parameter named by `key`. A `select` lists its options; a `text`
filter is a free-text box. `genders` and `concentrations` are the pre-37 lists, same
values as before.

`brand_types` (step 38b) is empty unless the shop's template uses brand types
(`Template::brandTypes()` — decant only); a storefront shows no brand-type filter then.

`variant_options` (step 38) is empty for decant. For a template whose variants aren't ml
sizes it lists each option name with the values in stock now, in variant display order —
`[{ "name": "Size", "values": ["S", "M", "L"] }, { "name": "Color", "values": ["Blue", "Red"] }]`
— each a `/products` `option[{name}]` filter. A template weighed by the kyatthar (step 40b)
is one of these: its variants are `{"Weight": "25 kyatthar"}`, `{"Weight": "1 viss"}`, so
`option[Weight]=1 viss` filters and the option picker sells them.

`sizes` lists the in-stock **ml** sizes (empty for a template whose variants aren't ml
sizes); `price` spans every active, in-stock variant; `price.min/max` are `null` on an
empty catalog. `social` URLs are `null` when unconfigured. `payment` is the shop's
offline transfer details (not a gateway): only the configured fields are present, and
the whole block is `null` when none are set.

`modules` (step 41) is the shop's enabled optional features, from `stock`,
`cost_margin`, `production_schedule`, `promo_codes`, `expenses`. A shop that never
saved its Features page gets its template's defaults. Without `promo_codes`, a
storefront shows no promo-code box, and the server refuses any code anyway (below).
Additive: a client reading a `/meta` from before step 41 treats a missing list as
all on.

## `GET /delivery-zones`

The whole serviceable delivery tree in one response — fetch once on the checkout
page and filter the township select client-side. Cached server-side (~10 min, busted
the moment the decanter edits a zone). Only townships that are active **and**
currently reachable by a courier appear; nothing about couriers themselves (names,
costs, coverage) ever crosses this endpoint.

```json
{
  "regions": [
    {
      "value": "yangon",
      "label": "Yangon Region",
      "townships": [
        {
          "id": 215,
          "name": "Sanchaung",
          "name_mm": "စမ်းချောင်း",        // null when not recorded
          "label": "Sanchaung (စမ်းချောင်း)", // render this in the select
          "fee_mmk": 2000,                  // 0 = real free delivery
          "fee_formatted": "2,000 Ks"
        }
      ]
    }
  ]
}
```

Regions with no serviceable township are omitted. An empty `regions` array means
the shop hasn't configured delivery yet — fail checkout visibly rather than
falling back to a free-text address.

## `POST /orders` — guest checkout

> **Breaking change (step 30):** the free-text `address` field is no longer
> accepted — the address is structured, and the server composes the canonical
> address string itself. Clients built before this must switch to
> `delivery_township_id` + `address_line` (+ optional `address_extra`).

```json
{
  "customer_name": "Ma Thiri",            // required, ≤255
  "phone": "09-123456789",                // required, ≤30 — becomes the tracking credential
  "delivery_township_id": 215,            // required — an id from GET /delivery-zones
  "address_line": "No. 12, Baho Road",    // required, ≤500 — street / ward / house no.
  "address_extra": "blue gate, 2nd floor",// optional, ≤500 — prints with the address
  "note": "call before delivery",         // optional, ≤1000 — fulfilment note, NOT address
  "promo_code": "WELCOME10",              // optional, ≤64 — case-insensitive
  "payment_method": "cod",                // optional, cod | online — defaults to cod
  "items": [                              // required, 1–20 lines
    { "variant_id": 51, "quantity": 2 }   // quantity 1–50
  ]
}
```

> **Step 36b:** a line names its variant by `variant_id`. Until go-live the legacy
> `{ "fragrance_id": 20, "size_ml": 10, "quantity": 2 }` line is also accepted and
> resolves to the same variant and price — for storefronts deployed before 36b. A line
> with neither gets a `422` on `items.N.variant_id`.

**`online` orders prepay and must attach their transfer slip** as a `proof` file
(jpeg/png/webp, ≤4 MB), so an online checkout is sent as `multipart/form-data`; a COD
checkout may stay JSON. The slip lands on the private proofs disk; the receipt carries
at most the `has_payment_proof` boolean, never a path or URL.

The delivery fee is **never sent by the client** — it is read off the township row
server-side, exactly as unit prices are. A township that is unknown, deactivated,
or currently unreachable fails with a `422` on `delivery_township_id`; it never
silently becomes a 0-fee order.

There is also an optional `website` field — a **honeypot**. Real clients must omit
it (or send it empty). Any non-empty value makes the server log the attempt, store
nothing, and return a *convincing fake* `201` (random code, zero total), so a bot
can't tell it was caught. Don't ever map a real UI field to `website`.

What the server does, atomically:

1. Validates the township is serviceable and reads its fee; re-validates every
   line against the live catalog (variant of this shop, active and in stock; active
   product + brand) —
   failures are `422` with `items.N` messages as above.
2. Re-derives unit prices and stores them as immutable snapshots on the order
   items; snapshots the region/township names and composes the canonical
   `address` (line, then `Township (မြန်မာ), Region`, then the extra line).
3. If `promo_code` was sent, re-evaluates it **under a row lock** (usage counted
   exactly once, no double-spend). A code that lapsed since preview does **not**
   fail the order — the discount is dropped and `promo_note` explains it.
4. Creates the order at status `awaiting_confirmation` with a fresh tracking code.

**Response `201`:**

```json
{
  "tracking_code": "9BGQCECV6C",
  "total_mmk": 70400,
  "total_formatted": "70,400 Ks",
  "delivery_fee_mmk": 2000,
  "delivery_fee_formatted": "2,000 Ks",
  "promo_note": null
}
```

- `tracking_code` — 10 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0/O/1/I).
  Show it to the customer immediately, paired with their phone it is the only way
  back to this order.
- `promo_note` — `null` normally; when a promo lapsed between preview and submit it
  is exactly: `That code was no longer valid, so it wasn't applied — you can still
  place this order without it.`
- `total_mmk` at creation = items subtotal + delivery fee − discount. The fee is
  the township's at the moment of ordering (the decanter can still adjust it
  during review — e.g. an unusually large parcel); deposit starts at 0. Re-fetch
  via `/orders/track` for the authoritative running totals.

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
  "status_labels": {                 // every state in the shop's words (step 39)
    "awaiting_confirmation": "Awaiting Confirmation", "pending": "Pending",
    "prepared": "Decanted",          // clothing: "Packed"
    "delivered": "Delivered", "cancelled": "Cancelled", "rejected": "Rejected"
  },
  "placed_at": "2026-07-14T08:05:00+06:30",
  "prep_date": null,                 // date string once the seller accepts
  "decant_date": null,               // deploy alias of prep_date — removed after go-live (RUN-QUEUE row 32)
  "delivery_date": null,             // date string once scheduled
  "rejection_reason": null,          // string when status = rejected
  "customer_name": "Ma Thiri",
  "phone": "09-123456789",
  "address": "…",
  "items": [                         // fragrance_name: the product as it reads now (below)
    { "fragrance_name": "Creed — Aventus (EDP)", "size_ml": 10, "variant_label": "10ml",
      "quantity": 2, "unit_price_mmk": 38000, "line_total_mmk": 76000 }
  ],
  "subtotal_mmk": 76000,
  "delivery_fee_mmk": 0,
  "discount_mmk": 7600,
  "promo_code": "WELCOME10",         // null when no code was used
  "deposit_mmk": 0,
  "total_mmk": 68400,
  "total_formatted": "68,400 Ks",
  "balance_due_mmk": 68400           // SIGNED — negative means overpaid (see below)
}
```

**Known defect (#134):** an item's `fragrance_name` is composed from the **current**
product row (brand — name, plus the concentration where the template has one), not from
the order line's frozen name snapshot, so renaming a product renames it on old receipts.
That breaks the snapshot rule and is queued to be fixed; clients should not rely on it.
Its prices, `size_ml` and `variant_label` are the line's own and never move.

`balance_due_mmk` is the one money field that can be **negative** (#67): it is
`Σ line_total − discount + delivery_fee − deposit`, derived from the line snapshots so a
hand-edited discount can't distort it. A negative value means the customer **overpaid** —
render an overpaid state, not a debt; `0` means settled. (This receipt also carries
`payment_status`, `payment_method`, `has_payment_proof`, and `paid_at` from the payment
steps — see below.)

The payment fields on the receipt:

```json
{
  "payment_status": "unpaid",          // unpaid | paid
  "payment_status_label": "Unpaid",
  "payment_method": "cod",             // cod | online
  "payment_method_label": "Cash on delivery",
  "has_payment_proof": false,          // a boolean only — the slip itself is never exposed
  "paid_at": null                      // ISO datetime once the decanter marks it paid
}
```

`status` is one of `awaiting_confirmation | pending | prepared | delivered |
cancelled | rejected` (`prepared` was `decanted` before step 39). The states are the
same for every shop; the words are the shop template's: `status_label` is the current
state's, `status_labels` every state's, so a timeline can name the steps not reached
yet. Statuses only move
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

## `POST /orders/payment-proof`

The customer attaches (or replaces) the screenshot of their offline transfer after
checkout. `multipart/form-data`: `tracking_code` and `phone` (≤32 each, the same
credentials and the same generic 404 as tracking), and `proof` (jpeg/png/webp image,
≤4 MB). Returns `200` with the receipt payload (`has_payment_proof: true`). A new upload
replaces the order's earlier slip, and the old object is deleted.

Uploading a slip does **not** mark the order paid — the seller checks it and marks it
paid in the admin. The server doesn't check the order's status here.

## `POST /orders/validate-promo` — preview only

A shop with promo codes off (step 41, `/meta` `modules`) answers every code as
unknown (`valid: false`, "We couldn't find that code."), and checkout places the
order at full price with `promo_note` set, exactly as for a lapsed code.

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

---

## Platform: `GET /api/v1/_storefront/host/{host}`

Not under `{shop}`: this is how the shared storefront deployment (ADR-0004) learns which
shop a request's host belongs to, so no tenant exists yet. Returns the shop for a
**verified** domain of an **active** shop:

```json
{ "data": { "slug": "decant-please", "name": "Decant Please!",
  "host": "www.example.com", "is_primary": false, "primary_host": "example.com" } }
```

`primary_host` is the shop's verified primary domain (or `host` itself when it has none),
so a storefront can redirect to it. An unknown, unverified or inactive-shop host gets the
same bare `404`. Bucket `host-resolve`, 60/min per IP.
