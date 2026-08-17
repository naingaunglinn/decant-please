# CHANGELOG — Decant Please!

Version history, newest first. Moved verbatim out of the root `CLAUDE.md` (see
`docs/adr/0001-agents-md-as-context-root.md`) so it is read on demand rather than
loaded into agent context on every turn.

Durable rules now live in `AGENTS.md`. The product spec lives in `PRODUCT.md`.
Per `prompts/WORKFLOW.md` step 5, new version notes are appended **here**, at the top.

---

## 0. What changed in v26

**v26** runs Step 32 — tenant isolation hardening. The Phase 1 audit walked every scope
seam outside Filament's resource tenancy (all 19 spec surfaces: widgets, custom pages,
panel controllers, public endpoints, promo evaluation, slugs, response cache, limiters,
CSV both ways, storage, commands, seeder, Telegram) and found the query layer already
sound — the `BelongsToShop` global scope built in v23–v25 held on every row. Three
non-query leaks surfaced; all three are closed here:

- **Rate limiters now key shop + IP** (`AppServiceProvider`). Per-IP-only buckets let
  one shop's traffic spend every shop's budget — and Myanmar's carrier NAT puts many
  customers of many shops behind one IP, so that was the normal case, not the edge.
- **New storage objects carry a `shops/{id}/…` prefix** on all six write sites (brand
  logos, fragrance images, the payment QR, and all three payment-proof writers), so
  per-shop archive and delete stay surgical. Objects written earlier keep their
  unprefixed paths — every reader uses the stored path, so both eras serve fine.
  Recorded as an accepted limit, not migrated.
- **`decant:fresh-start` no longer wipes the shared proofs directory.** It ran
  `deleteDirectory('payment-proofs')` — one shop's reset destroyed every shop's proof
  files. It now deletes this shop's proofs by their stored paths (the same pattern its
  fragrance-image cleanup already used), which also covers pre-prefix paths.
- **The scoping idiom is now a written decision** (Phase 2): the global scope stays;
  per-site `whereBelongsTo()` was rejected for this tree (fails open, and the
  PHP-summed money widgets make a missed clause silent). Recorded in `AGENTS.md` §8,
  `backend/README.md`, and the `decant-tenancy` skill; the `withoutTenancy()` budget
  (≤ 5 call sites, 1 used) stays test-enforced.
- **`TenantIsolationTest` grew from 24 to 40 cases** — the Phase 4 gaps: per-shop
  `/brands` + `/meta` content, shared-slug resolution, cancel/proof cross-slug generic
  404s asserted byte-identical, one promo string in two shops over HTTP, a
  shop-confined user 404ing on another shop's panel and file routes, exact per-widget
  figures for all seven dashboard widgets, both production-schedule surfaces, the
  deliberately-uncleared two-shop cache read, fresh-start cross-shop survival (rows
  *and* proof files) plus the no-`--shop` refusal, and pins for the new limiter keys
  and the storage prefix. Suite: 236 tests / 1,099 assertions.
- **Deliberately untouched, per the spec's own row assignments:** `/meta`'s
  `config('app.payment.*')` / `social` env fallbacks and the single global Telegram
  bot/chat (with its `{tenant}`-less admin links) — step 33's shop→env→off resolver
  owns both.

## 0.1 What changed in v25

**v25** turns on the panel's multi-tenancy (Step 25a — the Filament half pulled
forward out of the deferred Step 25) and then hardens the whole seam: an audit of
the branch found one production-breaking route bug, a red suite, and the gaps
below; all are closed here. Step 25b/25c (per-tenant Telegram, theming, the
all-shops dashboard, client logins) stay deferred until a real second client.

- **The studio got its own panel.** Shop management moved out of the tenant URL
  space into a second Filament panel at **`/studio`** (`StudioPanelProvider`, no
  `->tenant()`, emerald where the shop panel is amber): the operator's correct
  objection was that `/admin/decant-please/shops` made the super-admin look owned
  by one shop. `User::canAccessPanel` gates it to `is_studio` accounts (a future
  shop owner's login reaches only `/admin/{their-shop}`); same session guard, so
  one login serves both, cross-linked via the user-menu "Studio" item and a
  per-shop "Open panel" action. `ShopResource` now lives in
  `app/Filament/Studio/Resources`; the 25b cross-shop features land in this panel.
  **Registration now creates the owner's login too** (toggle, default on): a
  NON-studio user attached to the shop via `shop_user` — full control of their
  own shop through membership, 404 on any other shop's URLs, 403 on `/studio`
  (the §8 case ADR-0003 owed "when the pivot lands", now tested). Shop + owner
  commit in one transaction; the password is hand-set (no mail driver, §11) and
  the `role` column stays deferred — an owner IS the shop's whole admin today.
  And the shop panel's topbar brand is now the **current shop's name** (falling
  back to "Decant Please!" on tenant-less pages like login) — the panel belongs
  to the shop you're standing in, not to the platform.
- **Step 25a — Filament tenancy + shop management.** The panel adopts
  `->tenant(Shop::class, slugAttribute: 'slug')`: routes become `/admin/{shop}/…`,
  the switcher renders from `getTenants()`, and `IdentifyTenant` enforces
  `canAccessTenant`. Access model per ADR-0003 (Option C): `is_studio` is the
  studio grant (existing users backfill as studio); the `shop_user` pivot lands
  now but starts empty — client logins wait for the first client who asks, and the
  pivot's `role` column stays deferred with them. A `TenantSet` listener mirrors
  Filament's resolved tenant into `TenantContext` (and into `URL::defaults` for
  `{tenant}` route generation), so the throwing `BelongsToShop` scope stays the one
  isolation floor. `ShopResource` (Studio nav group, studio-only) is the "Register
  a shop" screen; the interim panel middleware is deleted.
- **Invoice/payment-proof routes moved inside the tenant space (critical fix).**
  They were registered via `authenticatedRoutes()`, which mounts OUTSIDE the
  `{tenant}` group — `IdentifyTenant` never ran, nothing set `TenantContext`
  (the interim middleware being gone), and the `{order}` binding threw
  `TenantNotSetException`: a 500 on every invoice/proof open in production, masked
  in tests by the suite's preset context. Now `authenticatedTenantRoutes()`, with
  `IdentifyTenant` ordered before `SubstituteBindings` (bootstrap/app.php), so the
  binding resolves under the tenant scope — same shop streams, a cross-shop id
  404s. Pinned by regression tests that clear the preset context first.
- **Every tenant-owned natural key is per-shop now.** `promo_codes.code` was the
  one the Step 23 composite pass missed — it becomes `(shop_id, code)`, and the
  promo/brand forms validate via `scopedUnique()` (through the tenant-scoped
  query) instead of raw-table `unique()`: a same-shop duplicate is a form error;
  another shop's "Chanel"/"SUMMER26" is none of our business.
  `delivery_township_couriers` keeps `(delivery_township_id, courier)`
  **deliberately** — township ids are already per-shop, so the pair can't collide
  across shops; a test asserts it rather than assuming it.
- **Registering a shop seeds its delivery geography.** The national-chart copy
  moved from `DeliveryZoneSeeder` into `App\Support\NationalGeography`, and
  ManageShops' create action runs it for the new shop — all inactive at fee 0,
  idempotent, never clobbering an activation or a price the decanter set. Without
  it a new shop had zero townships and nothing offerable at checkout.
- **`decant:probe-postgres` built** (Step 23 §8 owed it): a hidden command running
  the TopFragrances join (shared via `TopFragrances::rankingQuery()` so probe and
  widget can't drift), the production-schedule aggregation, and the seam's unique
  indexes — tracking_code asserted deliberately global — against the connected
  engine. `verify-postgres-portability.sh` invokes it (local php in CI, the
  compose backend locally, SKIP otherwise).
- **The §8 isolation table is now asserted at the real surface**, not just the
  model layer: per-shop catalog counts over `/api/v1/{shop}`, cross-shop tracking
  with byte-identical generic 404s, cross-shop invoice/proof, admin order-table
  exact counts, dashboard stats, CSV export bytes, cross-shop promo redemption,
  two-shop P&L, delivery-zones content + per-shop cache busting, B's township in
  A's checkout → 422, and shop registration through the actual Filament action.
  Panel tests authenticate as a studio user (`TestCase::studioUser()`) —
  `IdentifyTenant` 404s anyone else, which is what had turned three
  ProductionSchedule tests red.
- Housekeeping that had drifted: `verify-image-fallback.mjs` gains the `{shop}`
  segment; `AGENTS.md`'s portability command matches the script's shop-aware
  base; `NEXT_PUBLIC_SHOP_SLUG` documented in the frontend env example and
  DEPLOY.md; stale `SetDefaultTenant` comments corrected; README/API tables show
  `/api/v1/{shop}` paths; ADR-0003 accepted with its as-built amendments.

## 0.1 What changed in v24

**v24** *builds* the multi-tenancy seam and routing that v23 specified (issue #57,
`prompts/23-multi-tenancy-seam.md` + `24-multi-tenancy-routing.md`). The backend is now
tenant-aware; behaviour is unchanged for the single existing shop.

- **Step 23 — the seam.** A `Shop` model + `shops` table; `shop_id` on all ten
  tenant-owned tables, backfilled to the one existing shop in-migration;
  `brands.name`/`.slug`, `fragrances.slug`, and `delivery_townships(region,name)` become
  shop-scoped composite uniques (so a second shop can sell "Chanel" and ship to "Bahan").
  `TenantContext` + `BelongsToShop` install a global scope that **throws
  `TenantNotSetException`** when no tenant is set (never a silent all-shops read), qualifies
  `shop_id` for the TopFragrances join, and auto-fills `shop_id` on create. Tracking codes
  stay globally unique via the one budgeted `withoutTenancy()` dedup. Per-shop cache keys
  (`api.meta`/`brands`/`delivery-zones` → `.{slug}`) and busts; `decant:fresh-start --shop`
  (now also clears the shop's expenses). `TenantIsolationTest` asserts exact-count isolation
  across catalog, tracking, expenses, zones, both-shops-"Chanel", unscoped-throws, and
  per-shop cache busting.
- **Step 24 — the shop in the path.** All nine public endpoints move under
  `/api/v1/{shop}/…`; `ResolveTenant` binds the tenant from the slug and 404s (generic) on
  unknown/inactive — no shop-enumeration oracle beyond the URL. The interim default-tenant
  middleware is dropped on the API side (the panel keeps its own until Step 25). Storefront
  `lib/api.ts` folds `NEXT_PUBLIC_SHOP_SLUG` into its base URL; CORS accepts a
  comma-separated `FRONTEND_URL`. Response shapes unchanged — only the path gained the
  segment. `verify-postgres-portability.sh` and its CI step target `/api/v1/{shop}`.
- **Deferred, deliberately:** `{shop}/` storage-path prefixes (inert until a second shop
  exists — collision is impossible with one shop) and the fail-on-unset guard for
  `NEXT_PUBLIC_SHOP_SLUG` (until each Vercel storefront provisions it). Step 25 (Filament
  tenancy, shop CRUD, per-tenant Telegram, theming) stays deferred until a real second client.
- Both CI jobs exercise the tenant paths: the SQLite suite (incl. `TenantIsolationTest` and
  `ResolveTenantTest`) and the Postgres portability check.

## 0.1 What changed in v23

**v23** brings the multi-tenancy **spec** (issue #57) onto current develop — docs
only, nothing built. The design lives in `prompts/multi-tenancy-design.md` (audit
evidence in `prompts/multi-tenancy-findings.md`); build steps are
`prompts/23-multi-tenancy-seam.md`, `24-multi-tenancy-routing.md`, and
`25-multi-tenancy-shop-onboarding.md` (step 25 waits for a second client).

- **The audit (taken at v14) was refreshed against v22.** The finance + delivery-zones
  layer since (v19 cost/margin, v20 expenses/P&L, v21 delivery zones) added tenant-owned
  tables — `expenses` and the delivery-zone pair get `shop_id` (geography duplicated per
  shop, the decided model); cost columns ride existing tenant models; a new
  `api.delivery-zones` cache key and the 9th public endpoint become per-shop; and the
  custom Finance pages are covered by the throwing app scope, not Filament's. Captured in
  the findings addendum (A1–A6) and design §7/§8; the seam (Step 23) now lists ten
  tenant tables. Step numbers 23–25 still collide with develop's 23/28–31 and will
  renumber on merge.
- **§8's blanket multi-tenant exclusion is split** (design-doc §12's axis): **product
  scope** unchanged — no marketplace, one shop per storefront, no customer sees two
  shops — while **infrastructure scope** (one deployment serving many shops) is no
  longer excluded.

## 0.1 What changed in v22

**v22** is a **frontend-only** finish pass on the checkout region/township selects
(Step 31, `prompts/31-shadcn-select-and-form-polish.md`, issue #82) — no API,
migration, or admin change. Step 30's selects were right in behaviour but wrong in
finish: a native `<option>` popup is drawn by the browser (OS-blue list, square
corners, system font) and no CSS reaches it, which reads as unfinished inside §3's
apothecary restraint — and the trigger lacked `appearance-none`, so the browser drew
its own chevron inside the `rounded-full` pill.

- **One dropdown for all three selects; the chevron fix shipped first.** The visible half
  of the defect — a missing `appearance-none`, so the browser drew its own chevron inside
  the `rounded-full` pill — was fixed first on its **own no-dependency commit** (an interim
  `SelectShell` native wrapper) so it stayed separable from the library. All three selects
  (checkout region + township, and the shop **sort**, which had the identical defect) then
  landed on the Radix `Select` below; a **review round** moved the sort over too, so once
  nothing used `SelectShell` it was **removed**. Net: the storefront has **no native
  `<select>` left**, and the appearance-none fix lives on only in git history.
- **One Radix primitive, project-styled — not shadcn's token layer.** The checkout
  region/township selects **and the shop sort** become a Radix `Select`
  (`@radix-ui/react-select`, the **one** runtime dependency added). Authored in shadcn's **copy-in spirit** (we own and restyle the
  source) but **without** its CLI, `components.json`, OKLCH token layer,
  `cn()`/`clsx`/`tailwind-merge`, or `lucide` — a half-configured `components.json` with
  no CLI in the loop is a landmine and §3's hex palette is fixed. `globals.css`'s
  `@theme` block stays **byte-identical**. Highlight is `pine-soft`, selected is `pine`,
  icons are the inline SVG chevron; the popup carries the **only shadow in the codebase**
  (soft, pine-tinted) because a dropdown floats over live content with no scrim and a
  hairline alone can't lift it — every other surface here separates by border + tone.
  See `prompts/31 §4a`.
- **≥16px is a hard rule any copied-in component must meet.** v5 locked `text-base`
  (16px) on real controls because iOS Safari zooms the viewport when a sub-16px control
  takes focus — worst on checkout. shadcn primitives default to `text-sm` (14px); the
  trigger, value, and items here are all `text-base`, and "zero `text-sm` in the Select"
  is a check every future copy-in must pass. This is also why the project's own
  `Button`/`Pill`/`Input`/`Textarea`/`Label`/`Skeleton` were **not** replaced — they
  already satisfy the rule and carry §3's motifs.
- Radix's built-in type-ahead covers Yangon's ~45 townships, so no Combobox (`Popover` +
  `Command`) was pulled — a third dependency and a second interaction model this list
  doesn't need. No dark mode. No motion beyond Radix's open/close, kept instant (the
  popup isn't animated), so `prefers-reduced-motion` is honoured by construction.

## 0.1 What changed in v21

**v21** makes the delivery destination structured data and derives the fee from
it (Step 30, `prompts/30-delivery-zones-and-fees.md`, issue #80) — the first
step since v14 to touch checkout on both sides, and the step that makes the
P&L's delivery-fees line mean something:

- **A `Region` enum (15 states/regions + Naypyidaw) + `delivery_townships`
  table**, seeded from **Royal Express's official coverage chart** ("Last
  Updated 1/8/2026", committed as `backend/database/data/preview.webp` beside
  the two CSVs — the chart is perishable; re-check it when fees are reviewed).
  196 in-service + 32 suspended destinations, plus Yangon's city townships
  restored (the chart prices the whole city as one destination; customers pick
  a township, so the seed carries all 45 + the chart's sub-township points).
  **Every row seeds inactive at fee 0** — the seed is geography, not a shipping
  promise, and the seeder never invents a fee. The demo path alone activates
  Yangon at a placeholder; `decant:fresh-start` resets every zone to inactive/0.
- **Per-courier coverage in a child table** (`delivery_township_couriers`, the
  DecantPrice shape): Royal Express and BeeXprss as an enum, each row carrying
  **that courier's own spelling** (the reconciliation alias — both couriers
  romanise the same townships differently), an optional **reference cost**
  (never in any money figure — the P&L's courier-paid line stays the `delivery`
  expense category alone, the §0-v20 no-double-count rule), and `is_available`
  (suspended ≠ unserved: only one reverses). Serviceable = active + an open
  route, one definition (`scopeServiceable`). **Nothing about couriers crosses
  the public API** — the v19 cost rule extended to supplier data, pinned by test.
- **Checkout collects a structured address**: region → township selects (from
  `GET /api/v1/delivery-zones`, cached, serviceable rows only), a street line,
  an optional extra line. The fee is read off the township row server-side —
  a client-sent fee is ignored, the §7 price-trust rule extended verbatim.
  `orders.address` stays canonical, composed once at creation
  (`Order::composeAddress()`, smallest-to-largest, Burmese township name
  included for the rider) — so the invoice, receipt, Telegram alert, and admin
  textarea needed zero changes; region/township are snapshotted like
  `fragrance_name_snapshot`; legacy orders keep null structured columns.
  **Breaking API change, deliberately clean** (the storefront is the only
  client): `POST /orders` now takes `delivery_township_id` + `address_line`
  (+`address_extra`) and no longer accepts `address`. **The online prepay
  amount does not change** — the fee stays cash-to-courier (v14's Option B;
  #67 owns the balance arithmetic), and the checkout copy says so out loud.
- **Admin: Delivery zones** (Settings group) — region-grouped table with
  courier-coverage pills, cheapest recorded cost and a blank-never-0 best-case
  margin, bulk set-fee / set-cost / activate (a district filter + one action
  prices an area), CSV import + template on the v9 `CatalogImport` idiom. The
  order form gains an optional township pick that pre-fills the editable fee;
  **Accept** offers the courier choice with each courier's recorded cost as
  helper text, snapshotted to `orders.delivery_courier` — no cost ever copied.
- **Post-promotion obligation:** production starts with every zone inactive, so
  the decanter must price + activate townships (bulk actions) as part of going
  live, or checkout has nothing to offer.

## 0.1 What changed in v20

**v20** adds **expenses and a monthly net P&L** (Step 29,
`prompts/29-expenses-and-net-pnl.md`, issue #76) — the FINANCE.md fork, taken
deliberately: the decanter chose in-app books with the caveat presented that a
P&L missing expenses is worse than none, because it gets believed. The design
carries that discipline instead of hiding it:

- **An `expenses` table + ExpenseCategory'd CRUD** (Finance nav group, entry in
  seconds — date, category, integer-Kyat amount, note). The category enum owns
  the one accounting rule that matters most here: `stock_purchase` is
  **inventory, never an operating expense** — v19's margin already expenses that
  juice as COGS when it pours, so expensing the bottle too would count it twice
  (`ExpenseCategory::isOperating()`). Stock cash surfaces below the line.
- **A Profit & loss page** (Finance group), one month at a time with prev/next
  stepping: sales income from line snapshots − discounts (never `total_mmk`,
  which holds the courier's fee), liquid COGS with N-of-M coverage, gross
  margin, operating expenses **as entered** (delivery excluded — it lives in its
  own line, one subtraction in one place), a **delivery result** line (fees
  collected − courier paid) that finally measures FINANCE.md gap 4 at month
  level, and a net labelled with its own limits: "Net operating profit (liquid
  COGS; expenses as entered)".
- Accrual-lite by order-created month, matching every existing figure; §4
  exclusions everywhere, asserted by test; month boundaries via date columns
  and half-open ranges, no timezone math (the v17 lesson).

## 0.1 What changed in v19

**v19** adds **bottle cost and a liquid-only gross margin** (Step 28,
`prompts/28-cost-and-margin-tracking.md`, issue #66) — §8's "cost/margin
accounting" exclusion, reversed by explicit ask in the v8/v13 scoped pattern: a
reference cost and one honest margin figure come in; per-bottle tracking, batch
identity, FIFO/weighted-average COGS, and real accounting stay out.

- **A reference pair on `fragrances`** — `bottle_cost_mmk` + `bottle_volume_ml`,
  nullable, both-or-neither, independent of the `stock_ml` opt-in — feeds one
  derivation site, `Fragrance::liquidCostMmk()`: pure-integer **ceiling**
  division, deliberately the project's second rounding rule beside the promo
  floor, because the directions of safety differ — flooring a cost would flatter
  every margin figure. Liquid only: vial, label, and spillage are not in the
  number, and every label that shows it says so.
- **Order items snapshot cost exactly as they snapshot price** —
  `unit_cost_mmk`/`line_cost_mmk`, written once at creation (one creating-only
  model hook covers checkout, manual admin entry, and late-added lines), never
  refreshed; legacy rows stay null forever, no backfill — null means unknown,
  excluded and counted, never coalesced to zero.
- **`Order::liquidGrossMarginMmk()`** — Σ line_total − discount − Σ line_cost,
  the delivery fee on neither side (courier pass-through, unmeasured not zero) —
  is null unless *every* line is costed. The dashboard's **"Gross margin (liquid
  only)"** stat (same window and status exclusions as its revenue neighbour)
  sums it over fully-costed orders only, in PHP: a SQL `SUM(line_cost_mmk)`
  would count partially-costed orders' non-null lines and overstate margin. The
  CSV export gains blank-not-zero cost/margin columns; cost never crosses the
  public API, the A5 invoice, or the fragrances CSV export — pinned by tests.
- Accepted limit (v8's register): a rebuy between order creation and the pour
  isn't reflected in that order's cost.

## 0.1 What changed in v18

**v18** vendors two advisory **business-finance skills** into `.claude/skills/`
(issue #64) — agent guidance only; no app code, config, or behavior changes.
`accounting` (bookkeeping setup, chart of accounts, weekly reconciliation, P&L
review) and `finances` (unit economics, margin, cash-flow modeling) are copied —
not symlinked — from `whawkinsiv/claude-code-skills`, pinned by hash in
`skills-lock.json` (the vendored-asset precedent: Padauk, FullCalendar). Chosen
after content review over higher-ranked registry hits, which turned out to be
institutional equity research or robo-advisor scaffolding — wrong domain for a
one-person decant shop. Both speak in US/SaaS examples (Stripe, QuickBooks, USD,
MRR): they are advice for the decanter's *books*, and none of it licenses code
changes — integer-Kyat rules, server-side pricing, and §8's no-gateway line stay
governed by the `decant-money` skill and this file.

## 0.1 What changed in v17

**v17** rebuilds the production schedule around a **month calendar** (Step 23,
`prompts/23-production-schedule-calendar.md`, issue #61) — the overview the
day-card list never provided, so delivery dates stop being committed blind to the
week they land on. After a review round, the calendar is the page's only content;
the worklist moved to a per-day detail page, `/admin/production-schedule/{date}`,
which is also the printable bench sheet. (Step numbering: the open #58
multi-tenancy PR also claims 23–25 for its spec files; the two collide only in
name, and whichever merges second renumbers.)

- **Approach A of the spec, deliberately.** FullCalendar v6 (MIT) is **vendored as
  a committed static asset** (`backend/public/vendor/fullcalendar/`, the
  Padauk-font precedent) and embedded in the existing Blade page — **not**
  `saade/filament-fullcalendar`, which would need a Vite-compiled Filament custom
  theme in a Heroku build path that has no Node (monorepo + `heroku/php`
  buildpacks, whose ordering already caused one production-only failure), to buy
  event-CRUD features this page cannot use (dates change only through order
  Accept). Deploy path unchanged; `DEPLOY.md` untouched.
- **One aggregation, one place.** The per-day grouping moved off the page class
  into `Order::productionScheduleFor($from, $to)`; the calendar's event feed and
  the day page (called with `$date, $date`) both read it. When multi-tenancy's
  seam step lands, shop scoping happens there once — a custom Filament page sits
  outside Filament's tenancy scoping. The move also made the date window engine-proof (`whereDate`):
  the old `whereBetween` silently missed a window's last day under SQLite (the
  test engine compares the date cast's stored `Y-m-d 00:00:00` textually) while
  Postgres's DATE column truncates — the v6 lesson pointing the other way.
- **One chip per day, all-day, plain strings.** A busy day renders a single
  `12 vials` aggregate (a month cell truncates past ~2 chips, so per-line entries
  would show less than the worklist does); a past day still holding unpoured
  vials renders it in the overdue style — overdue is not history. Every date
  crossing the wire is a bare `Y-m-d` string: Myanmar is UTC+6:30, and any
  timezone-bearing value can shift a day cell — pinned by tests that run the
  feed under both UTC and Asia/Yangon.
- **The worklist is a page per day.** Clicking any day — chip or empty cell —
  opens `/admin/production-schedule/{date}`: the old day card's grouped lines
  plus prev/next stepping, a real empty state, and `@media print` A5 styles
  matching the invoice conventions (this sheet goes to the decant bench). The
  `{date}` param is a strictly-validated plain `Y-m-d`; anything else 404s,
  because a lenient parse would invite datetime/timezone math. Not in the
  sidebar (`shouldRegisterNavigation()` false) — it needs a date.
- `phpunit.xml` now pins **blank Telegram env**: a real bot token in a developer's
  `.env` was inherited by the suite and failed the four "unconfigured" alert
  tests. Same `env`+`server` pairing (and reason) as the DB overrides.

## 0.1 What changed in v16

**v16** widens step 21's Telegram layer (issue #59): the decanter now sees **how the
customer chose to pay** the moment an order lands, and gets buzzed when a transfer
slip arrives later. (v15 is the multi-tenancy spec on the open #57 branch, landing
separately — the number is skipped here deliberately, not lost.)

- **The new-order alert names the payment method.** One added line — `Payment: Cash
  on delivery`, or `Payment: Online transfer — slip attached|awaited`. "Attached"
  is the normal online case (a v14 checkout slip rides in with the order, and the
  proof is attached before `OrderPlaced` dispatches); "awaited" is defensive — the
  listener doesn't assume its dispatcher. Deliberately **no `payment_status`**:
  every order is `unpaid` at placement, so it carries no signal there.
- **A second event on the same layer.** `PaymentProofUploaded` (the order + an
  `isReplacement` flag), dispatched from the standalone proof endpoint only, after
  the write commits — never from checkout, whose slip the new-order alert already
  reports (dispatching from both would double-send, the #52 lesson). Its listener
  `NotifyAdminOfPaymentProof` is wired explicitly in `AppServiceProvider` —
  required, since event discovery is off.
- **The slip message** carries order number, customer, total + balance due, track
  code, and the admin order URL — and distinguishes a **first upload** ("slip
  uploaded") from a **replacement** ("slip replaced"): the endpoint overwrites, so
  a customer retrying a blurry photo shouldn't buzz identically several times.
  **Link only, never the image**: proofs are private by design (#47), and a 4MB
  multipart doesn't fit the notifier's 5s bound.
- Same two hard rules as v11: never throws, no-op when unconfigured — a Telegram
  outage can't fail a slip upload. Tests assert send **counts**
  (`Http::assertSentCount`), not just content — `assertSent` alone passes on a
  double-send, which is exactly how #52 shipped.

## 0.1 What changed in v14

**v14** adds a **payment-method choice at checkout** (COD vs online prepay) and a
**decanter-managed MMQR/payment settings** admin page (Step 22). Still no gateway —
payment stays offline; this just lets the customer *choose* to prepay and gives the
decanter a place to put their QR. Builds on v10's payment-proof + v12's private-proof
bucket (#47).

- **The method, chosen at ordering time.** A `payment_method` enum (`cod` | `online`)
  on `orders`, defaulting `cod` (existing + manual/DM orders read as cash-on-delivery).
  Checkout takes it (`in:cod,online`, defaults cod); the receipt/tracking response
  returns it so the storefront knows which UI to show.
- **Online = pay + attach slip *at checkout*.** The customer picks Online, sees the
  **MMQR + amount (cart subtotal) on the checkout page**, pays, and **uploads their slip
  to place the order** — the slip is required, so `POST /orders` becomes multipart and
  the order is *born with its proof* on the private disk. This is deliberate (chosen
  over "pay on the order-complete page"): it means **an online order can never be
  unpaid-with-no-slip** — no ghost orders, no auto-expire needed. The admin's
  **Needs-review** shows the method + a "slip uploaded" line; the decanter checks the
  slip → **Mark paid** → **Accept**. COD skips all this: one-tap place order, a calm
  "pay cash on delivery" note. If the shop has no payment settings configured, the
  Online option is unavailable (COD only). **Delivery fee stays cash-to-courier**, off
  the online amount (the "Option B" simplification), so online = the item subtotal.
- **A stock check surfaces at Accept.** `Order::stockShortfalls()` compares each tracked
  fragrance's `stock_ml` against what the order needs; the **Accept** modal shows any
  shortfall (*"needs 10ml but only 8ml in stock"*) alongside the unpaid-online reminder —
  soft warnings, **never a block**. So a shortfall is caught before the decant bench (and
  before a prepaid order is committed), not discovered at the pour.
- **MMQR/payment settings in the admin, not .env.** A single-row `ShopSetting` model +
  a Filament **Payment settings** page (Settings nav group) where the decanter uploads
  their **MMQR** (public media disk) and sets KBZPay/Wave numbers + instructions.
  `/api/v1/meta` now reads these from the DB, **falling back to the `PAYMENT_*` env**
  so existing deployments keep working; saving busts the meta cache. The page mirrors
  Filament's own `EditProfile` form-page pattern (`content()` embeds the `form` schema).

## 0.1 What changed in v13

**v13** is a docs-only alignment (issue #50) — no code, config, or behavior changes.

- **§8 amended, deliberately.** Like v6's change to §2's "fixed" stack table and
  v8's scoped stock reversal, this edits a section that exists to say "do NOT
  build": §8's blanket "email notifications out of scope" predated step 21, which
  shipped **admin-side Telegram order alerts** (v11, `prompts/21`). §8 now draws
  the boundary where Telegram's own constraint puts it — admin alerts in scope and
  shipped; customer-facing notifications still out, because a bot can only message
  a chat that has pressed Start on it, so reaching customers would need a
  per-customer opt-in tap or a paid channel. The tracking page remains the
  customer's channel.
- The root, backend, and frontend READMEs caught up with v7–v12: the
  Heroku/Vercel/R2 deployment reality (they still described a bare-VPS DEPLOY.md),
  the payment/stock/CSV-import/Telegram features, the payment-proof endpoint, the
  full env-var tables, and the real test count.

## 0.1 What changed in v12

**v12** moves payment-proof screenshots off the public image bucket (issue #47) — an
infra correction to v10, no feature change and no API change.

- **A private proofs disk, never the media disk.** Proofs write to
  `config('filesystems.proofs_disk')` (`PROOFS_DISK`): locally the stock `local` disk
  (`storage/app/private` — its serve route demands a signed URL nothing generates), in
  production `s3-proofs`, a **second R2 bucket with no custom domain, no url, no public
  access, and no CORS policy**. v10 had put proofs on the media disk, where the
  `images.cornerarea.me` domain made every prefix public — unguessable filenames, but a
  transfer screenshot (names, numbers, amounts) shouldn't be one leaked URL from public.
- **Served to the admin only, streamed.** The one way a proof is ever served is
  `/admin/orders/{order}/payment-proof` — registered through the panel's
  `authenticatedRoutes()` (the v7 invoice idiom, so it follows the panel path) and
  streamed by `PaymentProofViewController`. The order form's upload preview and its
  "Open full size" hint action both point at that route via `getUploadedFileUsing` —
  overridden deliberately, because Filament's default preview mints a *presigned
  temporary URL* for a private s3 disk, and no presigned or public proof URL may exist.
- **Customer contract unchanged.** `POST /api/v1/orders/payment-proof` is identical
  from the client's side; public responses still expose only `has_payment_proof`,
  never the stored path (now pinned by a test).
- **No orphans.** Replacing or clearing a proof deletes the old object via a model
  `updated` hook (covers the admin form, which writes the column without a controller);
  order deletion already cleaned up; `decant:fresh-start` now wipes `payment-proofs/`
  itself, since its bulk delete fires no model events.
- Local files under `storage/app/public/payment-proofs` from before this change are
  not migrated — production never had any (v10/v11 hadn't been promoted).

## 0.1 What changed in v11

**v11** adds **Telegram order alerts to the decanter** (Step 21) — the first
notification channel, admin-side only. (v9 = catalog CSV import, v10 = payment
confirmation; sibling feature branches, all landing on `develop`.)

- **Admin only, by design.** A website checkout pushes a Telegram message to the
  decanter's phone. Customer-facing alerts are deliberately out of scope: a bot can
  only message a chat that pressed Start on it, so cold-messaging a customer by phone
  is impossible — that would need SMS (paid) or a per-customer opt-in. The admin is
  one person who presses Start once, so it's free and automatic. The tracking page
  remains the customer's channel.
- **An event layer, not hardcoded calls.** Checkout dispatches an `OrderPlaced`
  event; a `NotifyAdminOfNewOrder` listener turns it into a message via a
  `TelegramNotifier` service. Adding SMS/Viber later = another listener on the same
  event, no checkout changes. Dispatched only on the website checkout path (manual
  admin orders don't self-notify); the honeypot path never dispatches.
  **Amended by #52:** event auto-discovery is disabled
  (`->withEvents(discover: false)` in `bootstrap/app.php`) — it had registered the
  explicitly-wired listener a second time, double-sending every alert. All listeners
  are wired explicitly in `AppServiceProvider` (`Event::listen`); a listener class
  that isn't wired there does not run.
- **Dependency-free.** One `Http::post` to the Bot API — no composer package on the
  Heroku buildpack. Config is `services.telegram` (`TELEGRAM_BOT_TOKEN`,
  `TELEGRAM_ADMIN_CHAT_ID`); both blank = feature off (no-op).
- **Never breaks checkout.** `TelegramNotifier` is bounded (5s timeout) and swallows
  every error to a log — a slow or failing Telegram can't delay or fail a customer's
  order. Runs synchronously (queue is `sync`); a queue worker would make it truly
  async later. `php artisan telegram:test` verifies a shop's bot setup during
  onboarding.
## 0.1 What changed in v10

**v10** adds **payment confirmation + proof** (Step 20). Payment is still the manual,
offline Myanmar flow — this is emphatically **not** a payment gateway (§8 still holds).
It just makes that flow legible inside the system instead of scattered across DMs.
(v9 is the catalog CSV import, a sibling feature branch; both land on `develop`.)

- **Paid/unpaid on every order.** A `payment_status` (unpaid → paid) + `paid_at` on
  `orders`, defaulting unpaid (so existing orders read accurately — none were tracked
  before). A model `saving` hook keeps `paid_at` in sync however the status changes —
  the admin form's select, the Mark paid/unpaid actions, or the API. This is separate
  from the pre-existing `deposit_mmk` (a partial-amount figure); payment_status is the
  yes/no the decanter actually reconciles, and `balanceDue()` = total − deposit.
- **Static payment details, config-driven.** KBZPay/Wave name+number, an optional QR
  URL, and free-text instructions live in `.env` (an `app.payment` block, mirroring
  `app.social`) and surface through `/api/v1/meta` — only non-blank fields, the whole
  block null if none set. A *static* number to transfer to, no merchant account.
- **Customer proof upload.** `POST /api/v1/orders/payment-proof` takes the transfer
  screenshot, gated by the same exact `tracking_code` + `phone` pair as tracking/cancel
  (same generic 404 on mismatch — no guessing oracle) and its own throttle bucket.
  Uploading does **not** mark paid — the decanter still eyeballs it and confirms. Files
  live on the media disk (`payment-proofs/`, public locally / R2 in prod), replaced on
  re-upload and deleted with the order. **Superseded in v12:** proofs now live on a
  private proofs disk, never the media disk — see §0.
- **Admin.** A Payment section on the order form (status select + proof view/upload),
  a payment badge column + filter, per-row **Mark paid / Mark unpaid** actions, an
  **Unpaid orders** dashboard stat with the outstanding total, and Payment + Balance-due
  columns in the CSV export. The tracking receipt gained `payment_status` and
  `balance_due_mmk`.
- **Storefront (Part B, included).** The receipt (order-complete + tracking) gained a
  `PaymentPanel`: it shows the balance and the configured transfer details from `/meta`,
  takes the customer's screenshot via the upload endpoint, and reflects paid/unpaid —
  live view only, never printed (a printed receipt keeps just a one-line payment state).
  All rules stay in the Laravel API (v5 rule); the storefront only renders — so a future
  Flutter client reuses the same endpoints.
## 0.1 What changed in v9

**v9** adds admin-side **bulk catalog CSV import** (Step 19) and nothing else.
(v8, below, is the separately-built total-ml decant stock feature; both are now
on `develop`.)

- **Why:** onboarding. A decanter switching from DMs has 100–300 fragrances;
  hand-entering them one Filament form at a time is the wall between "interested"
  and "live". The CSV is the price list they already keep.
- **Shape:** one row per fragrance; `brand`, `brand_type`, `name`,
  `concentration`, `gender`, the four text fields, and any number of
  `price_{N}ml` columns (any N — a blank cell means that size isn't offered).
  Enum cells match case-insensitively; prices tolerate `30,000` digit grouping;
  UTF-8 Burmese text and Excel's BOM both survive.
- **Idempotent by default:** brands match by name (case-insensitive, `whereLike`
  per the v6 Postgres rule, wildcards escaped) or are created; fragrances match
  by (brand, name) and existing ones are **skipped**, so re-uploading a fixed
  file never duplicates what already landed. An opt-in **update mode** overwrites
  fields from non-blank cells only (a blank cell can't erase hand-written text)
  and upserts prices per size — it never deletes a size.
- **Failure model:** each row commits in its own transaction and fails alone
  with a specific reason; the failed rows come back as a **failures CSV** —
  original columns plus an `error` column (unknown headers are ignored on
  import, so the fixed file re-uploads as-is). A structurally unusable file
  (missing required columns, no `price_*` column) is rejected whole.
- **Deliberately NOT Filament's `ImportAction`:** that drags in queue/notification
  infrastructure tables and a per-model importer that fights this three-model row
  (brand + fragrance + N prices). The repo's own idiom is custom CSV actions
  (`exportCsv`); import follows it — `App\Support\CatalogImport` (a plain,
  synchronous, testable service) + two toolbar actions on Fragrances:
  **Import CSV** and **CSV template** (the template ships a Burmese sample row
  and is pinned by a test that imports it).
- **Images are out of scope** — a CSV can't carry them; they're uploaded per
  fragrance afterwards, exactly as today.

## 0.1 What changed in v8

**v8** adds admin-side **decant stock tracking by total millilitres**, and nothing
else. This is a deliberate, *scoped* reversal of one §8 exclusion — bottle-volume
inventory — chosen after weighing it against the simplicity the tool is built on:

- **Total ml, not per-bottle.** Each fragrance gets a single running `stock_ml` on
  the `fragrances` table (plus `low_stock_threshold_ml`). Same-fragrance juice is
  fungible for decanting, so one total is stock-accurate; a `bottles` table would
  add rows and UX for information the decanter doesn't need to act on. ("Add bottle"
  in the form is just a `+ml` convenience on the total, not a stored entity.)
- **Opt-in per fragrance.** `stock_ml` is nullable and null on every existing row.
  Null means "not tracked" — such fragrances are skipped by the drawdown and never
  appear in the low-stock panel, so nothing in the seeded/real catalog changes
  behaviour the moment this lands.
- **Warn-only, never blocking.** The drawdown clamps at 0 and surfaces a shortfall on
  a new **Low stock** dashboard widget (and a Stock column on the fragrance table);
  it does **not** flip the customer-facing `in_stock` toggles, which stay manual. A
  decant that exceeds stock still goes through — the decanter reorders, they don't
  get blocked mid-fulfilment.
- **Drawn down at `→ decanted`, not at accept.** Stock drops when the vials are
  physically filled (the Order status transition into `Decanted`), matching the
  real act, via an `updated` model event → `Order::drawDownDecantStock()` →
  `Fragrance::drawDownStock()`. Logic lives in the domain layer, not Filament or the
  client, so a future Flutter admin reuses it (the v5 constraint).
- Known accepted limits: a manual order *created* directly at `decanted`/`delivered`
  isn't drawn down (real manual orders start `pending`); and moving an order out of
  and back into `decanted` pours twice (a rare admin correction, left un-guarded to
  avoid a "already decremented" flag). Both are fine for a single-decanter tool.

There is a separate, fuller **per-bottle** implementation on branch
`40-decant-bottle-stock` (its own `bottles` table, auto-`in_stock`, drawdown at
accept-time). v8 deliberately did **not** use it — it reverses the three choices
above. If per-bottle tracking or batch identity ever become real needs, that branch
is the reference, not this; cost/margin landed fragrance-level in v19 — the branch
contains no cost fields.

Files new/changed in v8: this section and §6/§8 below; migration
`…add_stock_to_fragrances_table`; `Fragrance` + `Order` models; `FragranceForm`,
`FragrancesTable`, and a new `LowStock` widget; `DecantStockTest`.

## 0.1 What changed in v7 (for reference)

**v7** adds admin-side **printable A5 order invoices** (Step 14) and nothing else:
- Two per-order actions (print inline in a new tab, download) plus a bulk
  "Download invoices (PDF)" that follows the active tab/filters — one A5 page per
  order. All gated to fulfillable statuses (`pending`/`decanted`/`delivered`);
  generated fresh on every request, never cached or stored.
- The PDF library is `barryvdh/laravel-dompdf` — pure PHP, so nothing new on the
  Heroku buildpack. This is a deliberate contrast with the Step 8 customer receipt,
  which stays browser-print (`OrderReceipt.tsx`, untouched): the admin document
  needs an exact physical size and batching; the customer one doesn't.
- **Burmese free text** (names/addresses) renders via a bundled repo-asset font,
  `backend/resources/fonts/Padauk-{Regular,Bold}.ttf` — the *only* font-family on
  the invoice. Padauk, not Noto Sans Myanmar: current Noto Myanmar builds carry no
  Latin glyphs, so as a sole font they'd tofu every English label. Known accepted
  limit: dompdf does no complex-script shaping — Myanmar glyphs and above/below
  stacking are correct, but `ေ`/medial-`ြ` visual reordering isn't; mPDF's OTL
  engine is the revisit path if that ever stops being acceptable.
- The inline-print route is registered through the panel's `authenticatedRoutes()`
  (NOT `routes()`, which Filament registers *outside* auth, next to login) — see
  `AdminPanelProvider`.

## 0.1 What changed in v2, then v3, then v4, then v5, then v6 (for reference)

**v6** swaps the database engine from MySQL 8 to **PostgreSQL 17**, and nothing else:
- Heroku is the chosen host and has no first-party MySQL. Its only credit-eligible,
  natively-supported database is Postgres; MySQL there is paid third-party add-ons
  (JawsDB, ClearDB) that the GitHub Student credit excludes. So §2's "fixed — do not
  substitute" table changes for the first time, deliberately. See
  `12-mysql-to-postgresql-migration.md`.
- The domain model, column names, API shape, and admin panel are untouched. What did
  change beyond configuration is **two MySQL-specific behaviours in the catalog query**
  that Postgres does not share, both fixed in the same step:
  - `LIKE` is case-insensitive on MySQL and case-sensitive on Postgres, so the catalog
    search now uses `whereLike(caseSensitive: false)` — `ilike` on Postgres, `like`
    elsewhere. A bare `LIKE` would have made search silently return nothing.
  - Postgres resolves a select alias in `ORDER BY` only as a bare name, so the price
    sort's old `min_price IS NULL, min_price ASC` (fine on MySQL) raised
    "column min_price does not exist". It's `min_price ASC NULLS LAST` now.
  - **Neither is catchable by `php artisan test`**, which runs on SQLite — permissive on
    both counts, so the suite stays green while the app is broken. `backend/scripts/
    verify-postgres-portability.sh` drives the running stack instead. Any future MySQL→
    Postgres-style semantic gap needs the same treatment: a check on the real engine.
- Local dev's Postgres publishes host port **5442**, not 5432 — another project on the
  dev machine already holds 5432, the same reason the API is 8010 (see §7).

## 0.1 What changed in v2, then v3, then v4, then v5 (for reference)

**v5** is a responsive/mobile pass plus groundwork for a possible native client:
- The 480px "reference card" pages (fragrance detail, order-complete, track) now
  widen at `lg`/`xl` (640/720px); real form inputs are 16px at every breakpoint
  (below 16px, iOS Safari zooms the viewport on focus — worst on checkout);
  Pagination and the cart's Remove got the app's standard ~44px touch targets; the
  shop grid gained its missing `md` column step. See
  `11-responsive-and-mobile-foundations.md`.
- **A Flutter mobile client is a stated future plan (not scheduled, not started).**
  Because of this: keep all customer-facing business logic — pricing, promo
  evaluation, order-status rules — in the Laravel API, never client-side-only in
  Next.js, so a second client can reuse it without re-deriving the rules. This is a
  constraint on *where logic lives* going forward, not a request to start mobile work.
- For that future client (and useful today regardless): `backend/docs/api.md` is the
  written contract for the 8 public endpoints, and `design-tokens.json` at the repo
  root is the portable copy of §3's palette — translate those, not the CSS.

Files new in v5: `11-responsive-and-mobile-foundations.md`, `backend/docs/api.md`,
`design-tokens.json`.

**v4** adds two independent features on top of v3's receipt/polish work:
- A **Burmese/English toggle** for the customer site — UI chrome only by default
  (nav, buttons, labels, the Step-8 receipt), with an optional additive schema change
  (`notes_mm`/`vibes_mm`/`performance_mm`/`description_mm` on `fragrances`) if the
  decanter wants bilingual catalog text too. No URL-based locale routing — a
  deliberate simplicity trade-off against full per-language SEO, flagged as such
  rather than silently skipped. See `09-burmese-language-toggle.md`.
- **Promo/discount codes at checkout** — a new `promo_codes` table, live validation
  before the customer commits (a preview endpoint, re-validated atomically at actual
  submission), and a `promo_code` snapshot column on `orders` so the receipt can name
  which code was used. The decanter's existing ability to hand-edit `discount_mmk` on
  any order in Filament is unchanged — promo codes just supply the *initial* value
  and a record of why. See `10-promo-codes.md`.

Files new in v4: this section, `09-burmese-language-toggle.md`,
`10-promo-codes.md`. Nothing from v3 or earlier changed to make room for these —
both are purely additive.

## 0.1 What changed in v2, then v3 (for reference)


**v2** added the self-service checkout described below (cart, checkout, order-complete,
tracking, admin accept/reject, production schedule) in place of v1's DM-only flow.

**v3** makes the post-checkout experience actually feel like a receipt instead of a
stub, and adds a small set of e-commerce fundamentals that were still missing:
- The order-complete and tracking views now show the same full detail — order
  number, customer info, shipping address, a real itemized payment breakdown, and
  delivery-date messaging that's honest about what isn't confirmed yet — instead of
  order-complete depending on a fragile client-side cache of the checkout response.
- `GET /orders/track` now also returns `order_number`, `customer_name`, `phone`,
  `address`, and per-item/summary pricing. Earlier versions of this spec said not to
  return the address — that was overly cautious: the code+phone pair already gates
  the whole endpoint, so withholding just the address protected against nothing
  while breaking the ordinary "confirm your delivery address" UX a receipt needs.
- A customer can now cancel their own order while it's still `awaiting_confirmation`,
  using the same code+phone the tracking page already asks for.
- The order-complete/tracking view is printable (a real print stylesheet, not a new
  PDF dependency — "Save as PDF" in the browser's print dialog covers "download").
- A handful of standard catalog fundamentals: related fragrances on the detail page,
  a recently-viewed rail, and a generated sitemap.

Files updated for v3: this file and the new `08-order-confirmation-and-polish.md`.
`02` through `07` are otherwise unchanged — see `08` for the full brief; it's additive
to what's already built, not a redo of any earlier step.

## 0.1 What changed in v2 (for reference)

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

