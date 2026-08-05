# CLAUDE.md — Decant Please! (v22)

> This file is project memory for Claude Code. Read it fully before doing any task.
> Every implementation decision must be consistent with this document.

## 0. What changed in v22

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
| Database | PostgreSQL | **17** | Was MySQL 8 through v5. Heroku has no first-party MySQL, and the paid add-ons that provide it aren't credit-eligible — see §0's v6 note and `12-mysql-to-postgresql-migration.md`. |
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

**Component library note (v22):** the storefront's own primitives (`Button`, `Pill`,
`QuantityStepper`, `ImagePlate`, `Skeleton`) carry these tokens directly. The one library
component is the **`Select`** (checkout region/township and the shop sort), which wraps
`@radix-ui/react-select` for a stylable option list — but restyled entirely to the tokens
above (`pine-soft` highlight, `pine` selected, hairline `rule` border, `rounded-full`
trigger). shadcn's own token/OKLCH layer was **not** adopted and the `@theme` block is
unchanged. There are no native `<select>` elements left in the storefront. Any copied-in
component must be patched to **≥16px (`text-base`)** on every control — below 16px, iOS
Safari zooms the viewport on focus (the v5 rule).

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
- `delivery_township_id` (nullable FK, `nullOnDelete`), `region_snapshot`,
  `township_snapshot`, `address_line`, `address_extra` — **new in v21.**
  Checkout writes them and composes `address` from them once; snapshots follow
  the `fragrance_name_snapshot` rule (copied at write, never recomputed — a
  township rename/reprice/delete leaves placed orders untouched). Null on
  legacy and DM orders, where `address` stays whatever was typed.
- `delivery_courier` — **new in v21.** Nullable `Courier` enum value recorded at
  Accept (or on the form): who actually carried it. A snapshot; no cost copied.
- `order_from`: `website` \| `tiktok` \| `facebook` \| `other` — **`website` is new**;
  the other three remain for orders the decanter still logs manually from a DM
- `tracking_code` — **new.** Unique random alphanumeric (~10 chars), generated on
  creation, shown to the customer at checkout and used (with phone) to look up status
- `decant_date` — **now nullable.** Set by the decanter when they accept an order, not
  by the customer. Still required at creation time for manually-entered orders (the
  decanter is accepting it by typing it in).
- `delivery_date` — unchanged, nullable
- `status`: `awaiting_confirmation` \| `pending` \| `decanted` \| `delivered` \|
  `cancelled` \| `rejected` — **`awaiting_confirmation` and `rejected` are new in v2.**
  Website checkouts start at `awaiting_confirmation`; manual admin entries start at
  `pending` (the decanter already accepted it by entering it). **v3:** `cancelled` can
  now also be reached by the customer themselves, not just an admin override — see
  `08-order-confirmation-and-polish.md`. A cancel is only allowed while a website
  order is still `awaiting_confirmation`; once accepted, cancelling means calling the
  decanter, same as it always has.
- `rejection_reason` — **new.** Nullable string, set when status becomes `rejected`.
- `id` — the existing primary key, now also doing double duty as a human-readable
  **order number** on the receipt (`Order #{id}`) — no new column, just a display
  convention introduced in v3.
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
5. **Checkout** — cart summary + contact form (name, phone, optional note) and,
   since v21, a **structured address**: region → township selects (serviceable
   townships only, fee shown as its own summary line the moment one is picked),
   a street line, an optional extra line. No payment fields. Submits to a
   public write endpoint; the delivery fee is derived server-side from the
   township, never sent by the client.
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
   **v17:** the page is the month calendar — one aggregate vial-count chip per
   day, overdue flagged distinctly — and every day clicks through to
   `/admin/production-schedule/{date}`, the per-day worklist and printable (A5)
   bench sheet. See §0.
5. Dashboard widgets: revenue this month, orders by status, **awaiting confirmation**
   count, decants due today, top fragrances, **low stock — reorder soon** (v8),
   **gross margin (liquid only)** (v19 — fully-costed orders only, with the
   exclusions and N-of-M coverage named in its description), **discount cost by
   code** (#75 — from the order snapshots, never `times_used`), and **cash with
   couriers** (#77 — the COD float, snapshotted at handoff, immune to markPaid
   timing and the payment-method conflation).
   **Decant stock (v8):** per-fragrance total-ml stock, opt-in and warn-only —
   drawn down when an order is decanted, surfaced on the fragrance table + low-stock
   widget, never touching the manual `in_stock` toggle. See §0.
6. **Printable A5 invoices (v7)** — print/download per order (fulfillable statuses
   only) and a bulk PDF for the filtered view, one order per page, with an
   emphasized balance-due figure. Never cached; Burmese-safe via bundled Padauk.
7. **Expenses & monthly P&L (v20)** — category'd expense entry (Finance group,
   seconds per row) and a Profit & loss page: income from line snapshots −
   discounts, liquid COGS with coverage, operating expenses as entered, a
   delivery result line, an honestly-labelled net; stock purchases below the
   line — inventory, never expensed (they become COGS as poured).
8. **Delivery zones (v21)** — a Settings resource over the township rate table:
   region-grouped, courier-coverage pills (suspended and no-courier states
   distinct), reference costs with a blank-never-0 best-case margin, bulk
   set-fee/set-cost/activate, CSV import + template. Courier data is
   admin-eyes only; the Accept modal records who carries each parcel.
9. Everything remains notes + financials + fulfillment only — no messaging, no
   customer portal, no payment processing.

## 7. Conventions

- **Local dev ports are fixed:** API `http://localhost:8010`, storefront
  `http://localhost:3001` — 3000, 3010, 8000, and 8001 belong to other projects
  running on this dev machine — as does **5432**, which is why the compose Postgres
  publishes **5442**. `docker compose up` at the repo root runs Postgres + both apps
  on those ports (see `docker-compose.yml`); keep every URL, env example, and doc
  consistent with them.
- Laravel: enums via PHP backed enums; money stored as integer Kyat, never decimals —
  non-negativity is an **application-layer** guarantee, not a database one. It was a
  database one under MySQL's unsigned integers; Postgres has no unsigned type, and
  Laravel's Postgres grammar accepts `unsignedInteger()` while silently dropping the
  constraint. The migrations still say `unsignedInteger()` (harmless, and it keeps the
  intent legible), but read it as documentation. Every such column is server-derived or
  admin-entered — checkout re-derives prices server-side and Filament is the only other
  write path — so no raw client input reaches one. If that ever stops being true, add
  `CHECK (column >= 0)` rather than trusting the column type.
- API resources for JSON shaping; eager-load to avoid N+1.
- **Checkout-specific:** the server re-derives `unit_price_mmk` and validates
  `is_active`/`in_stock` from the current catalog at submission time — the client
  only ever sends `fragrance_id`, `size_ml`, and `quantity`. Never trust a
  client-submitted price. **v21 extends this verbatim to the delivery fee:** the
  client sends `delivery_township_id`; the server reads `fee_mmk` off the
  serviceable row and ignores any client-sent fee or free-text `address`.
- **Tracking lookup** requires an exact `tracking_code` + `phone` match; a mismatch on
  either returns the same generic "not found," so the endpoint isn't a guessing
  oracle for either field.
- Next.js: App Router, server components for catalog fetching, TypeScript types
  mirroring API resources, Tailwind v4 (`@theme`, no JS config) unless asked
  otherwise. Cart state client-side only.
- Slugs auto-generated; images stored via Laravel `storage` and served publicly.
- All list endpoints paginated.
- Keep code simple and readable — this is a small business tool, not enterprise SaaS.
- **Process:** every step ships through the loop in `prompts/WORKFLOW.md` — issue →
  branch (named by issue number, off fresh `develop`) → implement → docs in the same
  branch → PR into `develop` → **stop**. PRs are never merged by Claude Code, and
  `main` changes only through the promotion PR described there.
- Your Claude Code environment already has `frontend-design`, the `vercel-*` skills,
  and `web-design-guidelines` active — consult those for implementation-level
  Next.js/Vercel patterns (view transitions, composition, React best practices)
  rather than re-deriving them; this file governs product/design decisions, those
  skills govern how you write the code.

## 8. Out of scope (do NOT build unless explicitly asked)

- Online payment gateway / card processing (KBZPay, WavePay, Stripe, etc.) — payment
  confirmation stays a manual, offline step for the decanter. **v10** formalises that
  manual step (paid/unpaid status, a transfer-screenshot upload, configurable transfer
  details) but adds **no** gateway: money still moves outside the system.
- Customer accounts / login on the customer side
- Chat/messaging features
- Multi-tenant / multi-decanter marketplace (single decanter for v1/v2)
- Inventory tracking of bottle *volumes* per physical bottle. **v8 added total-ml
  stock per fragrance** (warn-only, opt-in — see §0) as a deliberate scoped
  reversal, **v19 added reference-cost, liquid-only margin visibility** (step
  28), and **v20 added expenses + a monthly net P&L** (step 29 — the FINANCE.md
  fork, chosen deliberately with its truth-discipline caveat accepted); what
  stays out of scope is *per-bottle* tracking, batch identity, FIFO/weighted-
  average COGS, consumables costing (vial, label, spillage — undecided, and
  deciding it changes the stat's label), double-entry books, a balance sheet,
  budgets, drawings/capital, and tax automation. The customer-facing `in_stock`
  flag stays manual — v8's stock warns, it never flips it.
- **Customer-facing** notifications — email, SMS, or messaging apps. *Amended in
  v13: admin-side alerts are no longer excluded — v11 (step 21, `prompts/21`)
  shipped Telegram order alerts to the decanter.* The boundary sits where
  Telegram's constraint puts it: a bot can only message a chat that has pressed
  **Start** on it, so alerting the one decanter is free and automatic, while
  alerting customers would need a per-customer opt-in tap or a paid channel
  (SMS/Viber). Customer-facing notifications stay out; the tracking page
  (code + phone) remains the customer's channel. Further admin-side alerts
  (accepted/decanted/delivered) are clean additions on the v11 event layer, not
  redesigns.
