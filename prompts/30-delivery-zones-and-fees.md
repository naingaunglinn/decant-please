# Step 30 — Delivery zones: a structured address and a township fee at checkout

> **Numbering:** 24–26 stay reserved by open PR #58's renumber-on-merge and 27 by the local
> harness draft; 28 and 29 are merged. This file takes **30**. Renumber per the CLAUDE.md
> collision rule if any of those land elsewhere.

**Prerequisite: #76 merged** (step 29 — expenses + P&L). This step makes the P&L's
delivery-fees line mean something, and Decision 5 below depends on knowing what #67 is
about to do to `balanceDue()`. Branch off a `develop` containing 29 (WORKFLOW's
branch-ordering rule). Follow the current `CLAUDE.md` and the `decant-money` skill.
**Backend + storefront** — this is the first step since v14 to touch checkout on both sides.

---

## 0. Why this step exists

Today `orders.address` is one free-text blob and `delivery_fee_mmk` defaults to 0, entered
by hand at Accept. The checkout summary says so out loud (`OrderSummaryCard.tsx:186`):
*"Final total is confirmed by the decanter — delivery fee, if any, is agreed when we call
you."* Two costs follow from that:

- **Every order needs the phone call to be priced.** The customer commits without knowing
  the total, and the decanter re-derives the same township fee by hand, order after order.
- **FINANCE.md gap 4 can only ever be measured monthly.** Its own words: *"If the fee is
  flat and courier rates vary by township, delivery is quietly making or losing money that
  no report can show."* Step 29 measured fees-collected minus courier-paid at month level;
  it could not make the fee itself vary correctly, because nothing recorded where the
  parcel was going.

This step makes the destination structured data and the fee derived from it.

## 0.1 What the two couriers actually publish — read before designing anything

The decanter serves customers through **Royal Express** (royalx.net) and **BeeXprss**
(beexprss.com, UMG Logistics). Both sites were surveyed 2026-08-05. They are not
symmetrical, and the asymmetry drives most of this design.

**Neither publishes a rate table.** Both price through an AJAX calculator that returns one
number for one lookup — RoyalX by *From City → To City → Service Type → Weight*, Bee by
*From Region/Township → To Region/Township → Door to Door → Weight*. There is nothing to
seed and no rate API for either.

**Only Bee publishes its coverage, and it publishes it well.** `beexprss.com/townships?region_id=N`
is server-rendered, one page per region, 15 regions. That is an authoritative destination
list — **241 delivery points**. RoyalX publishes only the flat ~250-entry city dropdown on
its calculator; its Region → Township/City cascade exists on `/our-network`, is branch
lookup, and is AJAX with no prices.

**Coverage differs materially, in both directions:**

- **Bee lists nothing at all for Chin, Kachin, Kayah and Rakhine.** RoyalX reaches Myitkyina,
  Bhamo, Hakha and Loikaw. Those four regions are RoyalX-only.
- **Bee reaches ~94 places RoyalX does not list** — every inner Yangon township by name
  (RoyalX collapses them all into one "Yangon" city), plus sub-township points like Thilawa,
  Htaukkyant, Phaunggyi, Shwepoutkan and the Mandalay industrial zones.
- **Neither website lists Dala, Seikgyikanaungto or Cocokyun** — Yangon South, and Dala is
  directly across the river from downtown. (RoyalX's official chart later corrected two of
  these: it does serve Dala and Seikgyikanaungto — see §3. Cocokyun remains a genuine dead
  zone.) An unserved place must be *representable*, not silently missing.

**The two couriers spell the same place differently, and neither uses standard
romanization.** Chanmyathazi is Bee's `ChanMyaTharSi`. Meiktila is `Meikhtila`. Hpa-An is
`Phaan`; Kyaikto is `KyikeHto`; Mottama is `Motetama`; Kungyangon is RoyalX's `Kungyangone`
and Bee's `KunCyanGone`. Both also invent disambiguation suffixes — `MyoThit (SGG)`,
`Minhla-Upper`, `HtanTaPin (HTY)`, `WarTaYar (SPT)` — precisely because Myanmar township
names repeat across regions. **This is the single biggest engineering consequence in the
step:** without one canonical name plus each courier's own spelling stored beside it, the
decanter can never reconcile a Bee statement against a RoyalX statement against their own
order list, and nothing can ever pre-fill an AWB.

**Bee's list is operational route data, not clean geography.** It contains a literal
`-----` row in Yangon, a `Tharyarwaddy2`, and both `Mudone` and `Mudon` in Mon. It also
mixes administrative townships with village and industrial delivery points. The importer
must tolerate this; the seeded canonical list has already been cleaned of it.

Four consequences, locked:

1. **Never call royalx.net or beexprss.com from this app.** No scraping, no runtime fetch,
   no cached third-party rate lookup. A delivery fee that fails when someone else's site is
   down is worse than a fee typed in once.
2. **Both couriers are the decanter's reference, not the system's source of truth.** The seed
   ships *geography and coverage*; costs come from the decanter's own statements. The code
   must never present a number it invented as either courier's rate.
3. **Region → Township is right, and Bee's own calculator confirms it** — that is exactly the
   cascade Bee prices on. Level 2 means "a destination at least one of our couriers reaches".
4. **A place with no courier is a first-class state**, not an absent row. Dala exists, is
   listed, and is unavailable — which is a better answer for the customer than a township
   that mysteriously isn't in the dropdown.

## 1. Decisions locked — build to these

1. **Region is an enum; township is a table.** §7's enum idiom. Myanmar's 15 states and
   regions plus Naypyidaw Union Territory are constitutionally fixed — an enum means no
   second table, no join on the checkout path, and no `"Yangon"` vs `"Yangon Region"` drift.
   Widening it is a migration-free code change (the `ExpenseCategory` precedent). **A region
   is offered iff it has at least one active township** — derived, so there is no second
   toggle to forget.
2. **`orders.address` stays, and stays canonical.** Purely additive, no backfill (the
   v8/v19 rule: null means unknown). The new structured columns sit *beside* it, and
   `address` keeps holding the composed full address, written once at creation. That is what
   keeps the A5 invoice (`invoice.blade.php:123`), the tracking receipt
   (`TrackOrderController.php:56`), the Filament textarea (`OrderForm.php:43`) and the
   Telegram alert working with **zero changes**. Null structured columns = a legacy order or
   a DM order.
3. **Township name and region are snapshotted onto the order**, exactly as
   `fragrance_name_snapshot` is. The FK is `nullOnDelete`. Renaming a township, repricing it,
   or deleting it must never rewrite where a placed order was going.
4. **The fee is server-derived and never client-sent.** §7's checkout rule, extended
   verbatim to delivery: the client sends `delivery_township_id`, and the server reads
   `fee_mmk` off the active row. A client-supplied `delivery_fee_mmk` is ignored — pinned by
   a test, the same way a client-supplied price already is.
5. **The online prepay amount does NOT change in this step.** v14's Option B stands: online
   = item subtotal − discount, delivery stays cash-to-courier. Three live reasons:
   - the derived fee is still an **estimate** the decanter can override at Accept, and a
     prepaid estimate needs refund/top-up machinery that does not exist;
   - **#67 is already rewriting** `balanceDue()` into a signed figure and is explicitly
     about the promo-exceeds-delivery-fee case — two steps must not edit that arithmetic at
     once;
   - the COD float snapshot (`courier_carrying_mmk`) assumes the cash moves at handoff.

   **The checkout copy must say this explicitly.** Showing a customer a delivery fee and
   then not charging it online is only honest if it is labelled. Revisit after #67.
6. **One customer-facing fee per township; courier is a fulfilment detail.** The customer is
   never asked to pick a courier — they have no basis to, and exposing two prices for the
   same parcel invites a support conversation the decanter does not want. So `fee_mmk` lives
   on the township and is the only number the storefront ever sees. Which courier carries it
   is the decanter's operational choice, informed by coverage and cost.
7. **Coverage and cost are per courier, in a child table** — the `DecantPrice` shape
   (fragrance → many priced sizes), not columns on the township. Two couriers today, and
   a third is a data change rather than a migration. Each child row carries **that courier's
   own spelling** as `courier_name`; that alias is the whole reconciliation story from §0.1
   and is not optional.
8. **Courier costs are reference-only.** They **never** reach `fee_mmk`, `total_mmk`,
   `balanceDue()`, `liquidGrossMarginMmk()`, the P&L, the A5 invoice, the orders CSV, or any
   public response — pinned by a test, on the v19 model where cost never crosses the public
   API. The P&L's courier-paid figure keeps coming **only** from the `delivery` expense
   category; feeding a per-order courier cost in as well would double-count exactly the way
   §0's stock-purchase rule forbids. FINANCE.md gap 4's entry should now read "fees vary by
   township and both couriers' rates are recorded; per-order courier cost still unmeasured".
9. **District is a column, not a third select.** Customers do not know their district, and
   fees are per township, so a third dropdown buys friction and nothing else. But district
   earns its place in the data: it groups the admin table, it makes "set the fee for all of
   Yangon East" one bulk action, and it disambiguates the repeated township names both
   couriers had to invent suffixes for (two Minhlas, several Myothits). Nullable — Bee's
   sub-township delivery points have no district and must not be forced one.
10. **A `fee_mmk` of 0 is a real free-delivery zone.** So 0 can never double as "not priced
    yet", and `is_active` is the only gate. The seeder must not use 0 to mean unset.
11. **Integer Kyat, and no new rounding site.** The fee is a stored integer copied to the
    order — never divided, never derived. The project still has exactly two rounding rules
    (promo floor, cost ceiling).

## 2. Schema

**`Region`** — PHP backed enum with `label()` and `labelMm()`, covering **all 15 top-level
divisions: 7 States, 7 Regions, and the Union Territory.** Myanmar labels these differently
and the distinction is not cosmetic — a Myanmar customer scanning a dropdown expects to read
"Shan State" and "Yangon Region", not a bare "Shan" and "Yangon". So the label carries the
suffix and the enum value does not:

| Value | Label | | Value | Label |
| --- | --- | --- | --- | --- |
| `ayeyarwady` | Ayeyarwady Region | | `mon` | Mon State |
| `bago` | Bago Region | | `naypyidaw` | Naypyidaw Union Territory |
| `chin` | Chin State | | `rakhine` | Rakhine State |
| `kachin` | Kachin State | | `sagaing` | Sagaing Region |
| `kayah` | Kayah State | | `shan` | Shan State |
| `kayin` | Kayin State | | `tanintharyi` | Tanintharyi Region |
| `magway` | Magway Region | | `yangon` | Yangon Region |
| `mandalay` | Mandalay Region | | | |

Implement `HasLabel` so Filament selects and table grouping get it free, like
`ExpenseCategory` does. **Order the enum cases alphabetically by label** — that is the order
the seed file is in and the order both selects render in, so nothing needs a sort at the call
site.

**`Courier`** — PHP backed enum: `royal_express` (Royal Express), `beexprss` (BeeXprss).
`HasLabel` + `HasColor` like the existing enums. Adding a third courier is one case.

**`delivery_townships`** (new):

| Column | Type | Notes |
| --- | --- | --- |
| `region` | string, indexed | ← `Region` backed enum |
| `district` | string, nullable, indexed | admin grouping + disambiguation — Decision 9 |
| `name` | string | canonical English — the stable identity, neither courier's spelling |
| `name_mm` | string, nullable | Burmese, for the option label |
| `fee_mmk` | unsignedInteger, default 0 | what the customer is charged (§7: read the unsigned as documentation) |
| `is_active` | boolean, default true, indexed | the only gate |
| `sort_order` | integer, default 0 | so the decanter's common townships sit at the top |

Unique on `(region, name)`. Timestamps.

**`delivery_township_couriers`** (new — the `DecantPrice` shape):

| Column | Type | Notes |
| --- | --- | --- |
| `delivery_township_id` | FK, cascadeOnDelete | |
| `courier` | string | ← `Courier` backed enum |
| `courier_name` | string | **that courier's own spelling** — the reconciliation key |
| `cost_mmk` | unsignedInteger, nullable | reference only — Decision 8. Null = unknown, never 0 |

Unique on `(delivery_township_id, courier)`. Timestamps.

**Serviceability is derived, never stored twice.** A township is deliverable iff it is active
*and* has at least one courier row that is currently available (§3's suspended rule).
`DeliveryTownship::isServiceable()` and a `scopeServiceable()` — one definition, used by the
API, the admin badge, and the tests. A dead zone (Cocokyun in the seed — the chart corrected
Dala and Seikgyikanaungto to served, see §3) is a row with zero courier children, and that
is exactly how it must read.

**`orders`** also gains `delivery_courier` (nullable string ← `Courier`), set by the decanter
at Accept to record who actually carried it. It is a snapshot like every other order field —
no FK to the courier's rate row, no cost copied (Decision 8).

**`orders`** (additive):

| Column | Type | Notes |
| --- | --- | --- |
| `delivery_township_id` | nullable FK → `delivery_townships`, `nullOnDelete` | |
| `region_snapshot` | string, nullable | |
| `township_snapshot` | string, nullable | |
| `address_line` | text, nullable | street / ward / house no. |
| `address_extra` | text, nullable | the customer's "anything the three fields miss" |

`address` is untouched.

**One composition site**, `Order::composeAddress()` — smallest to largest, the way a
Myanmar address is written and the way the invoice already prints one:

```
{address_line}
{township_snapshot}, {region_snapshot}
{address_extra}        ← the line exists only when the field is filled
```

Called from the checkout path only. Filament writes `address` directly and must keep being
able to (a DM address is whatever the customer typed in a DM).

## 3. Data — the seed, and how the rest gets in

- `backend/database/data/delivery-townships.csv`, **committed** — the vendored-asset
  precedent (Padauk, FullCalendar). Four columns, nothing else: `region,name,name_mm,fee_mmk`.
  Sorted region A-Z then township A-Z, matching the `Region` enum's case order, so the seeder
  inserts in display order and `sort_order` stays at its default.
- **`preview.webp` beside the CSVs is the chart itself**, committed as the seed's provenance:
  the transcription source, its "Last Updated 1/8/2026" date, and the suspended-route marks
  all live in that image. It is why the CSVs alone were not the full list the checkout
  dropdown needs — the chart collapses Yangon City to one destination — and the Yangon
  restore below is the recorded resolution.

- **Source: Royal Express's official coverage chart, "Last Updated 1/8/2026."** That chart —
  not the website dropdown — is the authoritative list. It is published in Burmese, groups
  destinations under ပြည်နယ် (State) and တိုင်း (Region) exactly as Decision 1's enum does,
  and marks each destination's **service status**, which no web page exposes.
- **228 destinations: 196 in service, 32 temporarily suspended.** The suspended set ships as a
  second file, `delivery-townships-suspended.csv`, same four columns — not merged and not
  discarded, because "suspended" is a state that reverses. Seed the in-service file; import
  the other when a route reopens.
- **`name_mm` is filled on every row.** The chart is Burmese-native, so this is transcription
  rather than transliteration — a decisive improvement over the 169-of-326 coverage the web
  dropdown allowed. English romanisations are cross-checked against royalx.net's own price
  calculator, which publishes English (Burmese) pairs for most of the same places.
- **`fee_mmk` is 0 on every row and the seeder must not invent a fee.** A number the code
  guessed gets believed — the discipline step 29 accepted for expenses.
- **Seven destinations are air freight** (a plane icon on the chart): Myitkyina, Kalay,
  Tachileik, Kengtung, Dawei, Myeik, Kawthoung. Expect a materially different rate and lead
  time; price them individually rather than sweeping them into a bulk action.
- **The chart carries a date, so treat it as perishable.** Rakhine's nine destinations and
  eight of Kachin's nine are suspended today; that is a route status, not geography. Re-check
  the chart when the decanter next reviews fees, and note the date in the seeder's comment
  so the next reader knows what vintage the file is.

**One finding, decided and applied before seeding.** The chart lists **ရန်ကုန် (Yangon) as a
single destination** — the whole city, one rate. RoyalX does not price Yangon's 45 townships
separately, so it cannot serve the original request (North Dagon priced apart from South
Dagon) on its own; intra-Yangon differentiation comes from BeeXprss (which publishes all its
Yangon points) or the decanter's own rider. The seed therefore **restores Yangon's 45
townships** — customers pick a township, never a bare "Yangon": the transcribed CSV's 17
Yangon rows (city + outer towns) grew to 48 — all 45 administrative townships plus the
chart's three sub-township delivery points (Aphyauk, Htaukkyant, Okkan) — and the ambiguous
single `Yangon` row was dropped. The 31 restored city townships carry **`courier_name:
"Yangon"`** on their RoyalX row — on RoyalX's books they are all one Yangon parcel, which is
exactly what the alias column is for — and share whatever fee the decanter sets per row.
Cocokyun ships with **no courier row at all**: the one genuine dead zone in the seed (boat
or plane only), and the fixture §8's serviceability tests lean on. Note also that the chart
corrects an earlier reading: RoyalX **does** serve Dala and Seikgyikanaungto, which its
website dropdown omitted.
- **Every row ships `is_active=false`.** The seed is geography, not a shipping promise — the
  decanter activates a township when they have priced it and know a courier reaches it. This
  is why `is_active` must be the sole gate (Decision 10): with every row present, an inactive
  row is the normal state, not an error.
- **The demo path — and only the demo path — activates Yangon at a visible placeholder fee**,
  so a fresh `docker compose up` can still complete a checkout. Same seam the demo catalog
  already sits behind: real installs and `decant:fresh-start` leave every zone inactive at 0.
  Tests build their own zone fixtures and must not depend on the demo rows.
- **Suspended is not the same as unserved, and the schema must not flatten them.** Rakhine's
  nine destinations and most of Kachin's are suspended — RoyalX has the route and has closed
  it. Represent that as a township row with a courier child row that is currently unavailable,
  not as a township with no courier at all. Both read as "cannot ship here today"; only one
  reverses with a route reopening, and conflating them loses the fee the decanter already
  negotiated. A `is_available` boolean on the courier row is enough — do not build a status
  enum for two states.
- **`name_mm` is filled on every row, from two legitimate sources and no third**: chart rows
  are transcription (the chart is Burmese-native), and the 32 restored Yangon rows carry
  their official administrative names — ballot-and-map spellings, not romanization guesses.
  (This supersedes an earlier web-dropdown-era rule that would have left city townships
  blank; the discipline it protected — never *guess* orthography — still stands for any
  future row that has neither source. Blank is legible; wrong is not.)
- Option labels render `Name (မြန်မာ)` when `name_mm` is set and `Name` when it is not —
  matching RoyalX's own selects. Step 09's language toggle was never built, so this is one
  label, not i18n; nothing here is a reason to start i18n.
- `DeliveryZoneSeeder`, **idempotent by `(region, name)` upsert** so re-seeding never
  duplicates and never resets a fee the decanter has already entered. Register in
  `DatabaseSeeder`.
- **`decant:fresh-start` keeps zones** — they are configuration, like brands and the admin
  login, not demo data. Extend `FreshStartTest`.

## 4. Admin

**Nav group: `Settings`, resource label "Delivery zones".** One resource does not earn its
own top-level group beside Catalog / Sales / Finance, and a rate table *is* configuration.
If the decanter still wants a standalone **Delivery** menu, it is one string in
`AdminPanelProvider::navigationGroups()` plus one on the resource — do that only if asked.

**A single-page resource**, `ManageDeliveryZones`, on the `ManageExpenses` pattern (list +
modal create/edit — no separate create/edit pages for a table this flat).

- Table grouped by region, district as a secondary column; name with `name_mm` as its
  description; `fee_mmk`; a **coverage** column rendering one small pill per serving courier
  (and a distinct "No courier" state for the dead zones — §0.1's fourth consequence); the cheapest recorded
  cost; a **display-only** best-case margin (`fee − min(cost)`, **blank when every cost is
  null** — blank, never 0, the v19 rule); inline `is_active`.
- Filters: region, district, active, **and "served by"** (a courier), plus a **"No courier"**
  filter — the decanter needs to find the gaps deliberately, not stumble on them.
- Courier rows edit through a **relation manager** on the township (the `DecantPrice` idiom):
  courier, their spelling, cost. Pre-filled `courier_name` from the seed; editable, because
  couriers rename their own routes.
- **The bulk actions are the point of this page**, not an extra: *Set fee for selected*, *Set
  cost for selected → for one chosen courier*, and activate/deactivate selected. A district
  filter plus one bulk action prices a whole district in two clicks.
- **CSV import + template** on the toolbar, following the v9 idiom exactly — a plain,
  synchronous, testable `App\Support\DeliveryZoneImport`, **not** Filament's `ImportAction`
  (same reasoning as `CatalogImport`: no queue/notification tables for a one-person tool).
  The importer takes the same wide shape as the seed file, upserts by `(region, name)`, and
  must **tolerate the junk in Bee's published list** — blank rows, `-----`, trailing digits
  on a duplicated name — by failing that row alone with a reason, per v9's failure model.

**Order form** (`OrderForm.php`): a region select and a township select in the Contact
section, **both optional**. Picking a township sets `delivery_fee_mmk` live and leaves it
editable — same freedom the decanter already has over `discount_mmk` (v4). `address` stays a
plain required textarea. Do not force the cascade on manual entry; a DM order that cannot
be saved because the customer's township is ambiguous is a regression.

A **`delivery_courier` select** goes in the Schedule section, optional, its options limited to
the couriers that actually serve the chosen township — with each one's recorded cost as the
option's helper text, so the decanter picks while looking at the number. The **Accept** modal
shows the same choice alongside the existing stock-shortfall and unpaid-online warnings: that
is the moment the decanter decides who carries the parcel.

## 5. API

**New: `GET /api/v1/delivery-zones`** — catalog throttle (120/min), cached 600s under
`api.delivery-zones`, **cache busted on township save and delete — and on courier-row save
and delete too**, because a courier row appearing or losing `is_available` flips
serviceability, which is this response's filter (the v14 ShopSetting precedent, both
models). Active rows only, grouped by region:

```json
{ "regions": [ { "value": "yangon", "label": "Yangon Region",
  "townships": [ { "id": 12, "name": "North Dagon", "name_mm": null,
                   "label": "North Dagon", "fee_mmk": 2000, "fee_formatted": "2,000 Ks" } ] } ] }
```

The whole serviceable tree in one response, so the township select filters client-side — no
request between the two selects, no spinner mid-form. **Deliberately not folded into
`/meta`:** `/meta` is fetched by every catalog page, and a few hundred zone rows on every
shop view is payload for nothing.

**Nothing about couriers crosses this boundary** — not names, not aliases, not costs, not
which one serves the township. The customer sees a destination and a fee. Serviceability is
applied as a filter (`scopeServiceable()`), so the three dead zones simply never appear in the
dropdown; `district` is omitted too, since it drives no customer decision. Pin all of this
with a test — it is the v19 rule (cost never crosses the public API) extended to a second
kind of supplier data.

**`POST /orders`** — validation changes:

- `delivery_township_id`: `required|integer` + exists **and is active** (a custom rule or an
  explicit query; an inactive zone must fail loudly, never fall through to a 0 fee).
- `address_line`: `required|string|max:500`.
- `address_extra`: `nullable|string|max:500`.
- **`address` is no longer accepted from the client** — it is derived. This is a breaking
  change to the documented contract; the storefront is the only client today (the Flutter
  plan is unstarted), so make the clean break and write it up in `backend/docs/api.md`
  rather than carrying a dual path.
- Response gains `delivery_fee_mmk` + `delivery_fee_formatted`, so order-complete shows what
  was actually charged rather than re-deriving it.
- The honeypot path is unchanged and still persists nothing.

**`GET /orders/track`** already returns `delivery_fee_mmk` (the v3 receipt itemisation) and
`address` — verified against `TrackOrderController::receipt()`; no change needed there.

## 6. Storefront

`CheckoutForm.tsx` — replace the single Delivery address textarea with four fields, in this
order, matching the decanter's mockup:

1. **Region** — select. `State / Region`.
2. **Township** — select, disabled until a region is chosen, options filtered client-side.
3. **Address** — textarea. Label the content it wants: street, ward, house number.
4. **Anything else about the address** — textarea, optional.

**Field 4 is not the existing "Note (optional)" field.** Keep both, and label them so the
next reader cannot merge them: `address_extra` is part of the address and prints on the
invoice; `note` is a fulfilment instruction ("call before delivery") and does not. If they
collapse into one field, the invoice either loses address detail or grows a delivery
instruction.

`OrderSummaryCard.tsx` — the fee becomes its own line the moment a township is picked,
display-only, computed from the fetched tree exactly as the subtotal is today (**the server
re-derives at submit**; §7). Line 186's copy must be **rewritten, not patched**: the fee is
no longer "agreed when we call you", it is known — and per Decision 5 it is **paid in cash
to the courier**, not included in an online prepayment. Say both.

Design constraints that already exist and still apply: real inputs ≥16px at every
breakpoint (v5 — below 16px iOS Safari zooms the viewport on focus, worst on checkout),
~44px touch targets, `rounded-full` + `border-rule` on the selects so they match the
existing inputs, the fee in a hairline pill (§3's motif), native `<select>` — per-region
filtering keeps every list short enough that a custom combobox would be new UI for nothing.

`lib/types.ts` + `lib/api.ts` gain `DeliveryZones` and `getDeliveryZones()`. Fetch once on
the checkout page. If the fetch fails, the form must fail visibly — never silently fall back
to a free-text address, or an order lands with no zone and no fee.

## 7. Deliberately not in this step

- **No royalx.net call of any kind** — see §0.1.
- No weight or size tiers. A decant is grams; the parcel is always the smallest tier.
- No free-delivery promo type. `PromoType` stays `percent` | `fixed`, and a promo still
  discounts the item subtotal only.
- **No customer-facing courier choice** — Decision 6. The customer picks a destination, not
  a logistics provider.
- No per-order courier **cost** snapshot and no delivery-margin report — Decision 8; gap 4's
  per-order half stays open. The obvious next step once `delivery_courier` has been recorded
  for a month is an expected-vs-actual reconciliation (Σ recorded cost against the `delivery`
  expense category), with the expense staying authoritative for net profit. Do not build it
  here.
- **No automatic courier selection.** Cheapest-serving-courier is shown, never applied — a
  courier choice depends on speed, reliability and whose pickup already comes that day, none
  of which is in this data.
- No lead-time or delivery-date estimation, though Bee publishes the tiers (1–2 days
  same-city, 3–4 transit, 4–5 via Myanmar Post) and that is where it would come from.
- No change to the online prepay amount — Decision 5; revisit after #67.
- **No third select for district** — Decision 9. It is stored and used in the admin only.
- No AWB creation, label printing, or courier booking integration.
- No address autocomplete, geocoding, map picker, or saved addresses (§8: no accounts).
- No delivery-date estimation per zone.

## 8. Tests — new `tests/Feature/DeliveryZoneTest.php`

- The fee is derived server-side from the township; a client-sent `delivery_fee_mmk` is
  **ignored** (the price-trust test, extended).
- An inactive township is rejected; an unknown id is rejected. Neither silently yields 0.
- `address` composes correctly with and without `address_extra`; `region_snapshot` and
  `township_snapshot` are written.
- Renaming *or* repricing a township does not change a placed order's snapshot or its
  `delivery_fee_mmk`; deleting it nulls the FK and leaves both intact.
- `total_mmk` = Σ line totals + fee − discount, with a real fee in place.
- An active township with `fee_mmk` 0 is honoured as free delivery, not as unset.
- **A township with zero courier rows is not serviceable**: absent from `/delivery-zones`,
  and rejected by `POST /orders` with a clear message even if `is_active` is true. Cocokyun
  is the seeded example (tests build their own fixture row).
- **No courier data crosses the public boundary**: no courier name, alias, cost, or
  serving-courier list in `/delivery-zones`, the `POST /orders` response, `/orders/track`, the
  A5 invoice, or the orders CSV export.
- `courier_name` aliases survive a reseed and are independently editable — assert that
  RoyalX's `Kungyangone` and Bee's `KunCyanGone` both resolve to the one canonical
  Kungyangon row.
- The best-case margin column is **blank, not 0**, when every serving courier's cost is null.
- Deleting a township cascades its courier rows away but leaves placed orders' snapshots and
  `delivery_courier` intact.
- The P&L's delivery-fees-collected line picks up derived fees, and courier-paid still comes
  only from the `delivery` expense category — assert no double count (extend
  `ExpensePnlTest`).
- `/delivery-zones` returns active rows only, and a save busts the cache.
- **A legacy order** with all structured columns null still renders the invoice and the
  tracking receipt (this is the regression that matters most).
- `FreshStartTest`: zones survive `decant:fresh-start`.
- Admin (Livewire): the bulk set-fee action writes; the CSV import round-trips its own
  template, including a Burmese `name_mm` row.

Frontend: `npm run build` type-checks and builds.

## 9. Docs — same branch (WORKFLOW step 5)

- **`CLAUDE.md`** → **v21**. A §0 changelog section. §4 Order: the five new columns and the
  snapshot rule. §5 item 5: checkout now collects a structured address. §6: the Delivery
  zones admin surface. §7: extend the never-trust-the-client line to the delivery fee.
- **`FINANCE.md`** gap 4: fees now vary by township; per-order courier cost still unmeasured.
- **`backend/docs/api.md`**: the new endpoint, and the `POST /orders` breaking change called
  out as breaking.
- **`README.md`** (root, backend, frontend): feature lines, the new endpoint row in the
  public-API table.
- **`prompts/README.md`** step table → this step **built**.

## 10. Verification before the PR

```
docker compose exec backend php artisan test
sh backend/scripts/verify-postgres-portability.sh
docker compose exec frontend npm run build
```

The portability script is **not optional here**: this step adds an indexed string column, a
group-by on it, and a likely case-insensitive name lookup in the CSV import. SQLite tolerates
all three where Postgres may not — the v6 lesson, and the reason `whereLike(caseSensitive:
false)` exists. Any name matching in the importer follows that same rule, wildcards escaped.

Then, by hand: place one real checkout end to end, and confirm the A5 invoice prints the
composed address with a Burmese `name_mm` township. Padauk still does no complex-script
shaping (v7's accepted limit) — stacking is correct, `ေ` and medial `ြ` reordering is not.

## 11. Note for the multi-tenancy seam (PR #58)

`delivery_townships` is **shop-scoped data** — two decanters using two couriers have two
different rate tables, and `Order`'s snapshot columns are what keep history correct across a
reprice either way. If #58 lands first, this table needs the tenant column and scope; if this
lands first, add it to #58's seam list. Do not build tenancy here.
