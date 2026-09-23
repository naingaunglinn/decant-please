# 35–42 — Generic-shop refactor (Decant = first template)

Umbrella plan for turning the perfume-decant domain into a generic, multi-category shop
platform. **Decant becomes the first *template*, not the whole product.** One section per
step; one issue per step; one PR per step. Judged against the Principles (`AGENTS.md` §
Principles P1–P6) throughout.

> **Numbering.** The refactor is **steps 35–42** — `prompts/30-`…`34-*.md` are already
> shipped (highest shipped step = 34). Step *numbers* (35–42) are **not** issue numbers;
> branches use the issue number (next is ~#104+).
>
> **Why one umbrella file for eight steps** (P4, fewer files): the steps are tightly
> coupled renames/derivations; a single plan keeps the sequence legible. Each step still
> gets its own issue, branch, and PR.

---

## Progress (source of truth for resuming)

- [ ] **Pre-35 — #67 first** (correct money before the baseline) — *see Sequencing below*
- [ ] **35** — Baseline parity test
- [ ] **36** — Product + Variant model
- [ ] **37** — Templates + attributes
- [ ] **38** — Clothing template
- [ ] **⛔ GATE — real-seller trial** (stop; owner decides 39–41 order & scope)
- [ ] **39** — Status labels (template-driven; `decanted→prepared`)
- [ ] **40** — Stock modes (`per_variant` / `pooled`)
- [ ] **41** — Module toggles
- [ ] **42** — Design-spec sync (docs)

Issues: 35–38 created with this plan (#103's PR); **39–42 issues are deferred** until after
the step-38 gate, because their order and scope are decided then.

---

## Sequencing & gates (owner's amendments)

1. **#67 runs before step 35.** The parity baseline must be recorded on *correct* money
   figures. **Finish #67's remaining backend work first** (capture amount-received into
   `deposit_mmk`, signed `balanceDue()`, `discount ≤ subtotal` validation, `OrderStats`
   outstanding). **If that is blocked on the promotion sequence** #67 defines (two branches,
   two `develop→main` promotions — the executor checks #67/#70 + promotion state at the
   start of Phase 3): step 35's PR must **state exactly which figures #67 will change**
   (`deposit_mmk` capture defaults, `balance_due_mmk` sign, the "Balance outstanding" stat)
   and a **one-time, explicit re-baseline is allowed in #67's PR** — the only sanctioned
   time the recorded values move.
2. **Gate after step 38.** Steps 35–38 make the product fit a new category (P5: removes an
   onboarding blocker). **Stop after 38** so the owner can trial it with a real seller before
   building 39–41; the owner decides their order and scope then. Step 42 (spec sync) depends
   on 39–41 and runs last.

## Cross-cutting invariants (every step)

- **Never rewrite order snapshots or money figures.** Money stays integer Kyat. A rename may
  change a *field name*; it must never move a recorded *value*. The **step-35 parity test is
  the guard** for this, not a thing later steps "update."
- **Tenancy unchanged.** Renamed tables keep `BelongsToShop` + `shop_id`; `TenantIsolationTest`
  stays green. No new tenant-owned *table* is created (renames + jsonb columns + `shop_settings`
  columns), so **no new isolation test is required** — but the existing suite that names
  `fragrances`/`decant_prices` must be updated in the same PR.
- **Portability.** Any step adding/altering a query with `LIKE`/`ORDER BY`/alias runs
  `backend/scripts/verify-postgres-portability.sh`. Search uses the denormalized `search_text`
  column — **never `ilike` into jsonb** (step 37).
- **Contract lockstep.** An API Resource shape change lands with `frontend/src/lib/types.ts`
  in the same PR (`backend/AGENTS.md`). Table/route renames deploy lockstep (as step 24 did).

## Branching (deliberate override of `WORKFLOW.md`)

Branches **stack**, with **#67 at the bottom** (owner's amendment 1): the
`67-payment-received-capture` branch is cut off fresh `develop`, and **step 35 branches off
`67-payment-received-capture`**; each later step off the **previous step's branch**; each PR
targets the branch below it. The owner merges them in order. I never merge, never touch
`main`. Commits/PRs carry the owner's name alone.

## Before each PR opens (Definition of done)

`php artisan test` (incl. `TenantIsolationTest`) · `npm run build` (+ `typecheck`/`lint`, since
CI gates backend only) · `verify-postgres-portability.sh` (when queries change) · the **step-35
parity test** · docs updated in the same branch (`CHANGELOG.md`, `backend/docs/api.md`,
`prompts/README.md`) · the **P6 checklist answered in the PR description**.

---

## Step 35 — Baseline parity test

**Goal.** A golden-master test that seeds the demo catalog + a representative set of orders and
records (a) the public catalog/detail/`/meta` API responses and (b) dashboard **revenue, gross
margin, and monthly P&L**. Every later step keeps it green; a step may update field *names* it
intentionally renames, **never values**.

**Schema / migrations.** None.

**API changes.** None (reads existing `/products`-to-be endpoints as they are today:
`/fragrances`, `/fragrances/{slug}`, `/meta`).

**Admin / storefront.** None.

**Tests.** New `tests/Feature/GenericShopParityTest.php`: deterministic seed (fixed ids/dates —
no `now()`/random), under a set tenant. Assert **explicit expected values** (prefer literals
over stored snapshots for money) for: the fragrance object + `min_price_mmk`, `/meta`
filter options + `price`/`sizes`, `OrderStats` revenue + gross margin, and
`MonthlyPnl::for($y,$m)` (sales income, COGS, gross margin, delivery result, net operating).
Reuse the patterns in `PublicApiTest`, `DecantCostTest`, `ExpensePnlTest`.

**Risks.** Determinism (freeze seed data + dates). Must assert money as values so a later
rename can't silently move a figure.

**Deliberately not built.** No storefront pixel snapshots (that's `frontend/scripts/verify-*.mjs`);
no exhaustive per-widget coverage beyond revenue/margin/P&L.

## Step 36 — Product + Variant model

**Goal.** Rename the perfume-specific model to a generic product/variant model, **IDs stable**,
with checkout keyed by variant. Server still derives every price.

**Schema / migrations** (new migrations only; never edit shipped ones):
- `Schema::rename('fragrances','products')`, `Schema::rename('decant_prices','product_variants')`.
- `product_variants`: add `options` jsonb (e.g. `{"Size":"10ml"}`), nullable `measure` int
  (10 for 10ml); keep `price_mmk`, `in_stock`, `size_ml` (legacy + measure source).
- `order_items`: `renameColumn fragrance_id → product_id` (keep FK `restrictOnDelete`); add
  nullable `product_variant_id` (FK → `product_variants`, same shop) + `variant_label_snapshot`;
  **backfill** both from `size_ml` for legacy rows (match product + size → variant; synthesize
  `"10ml"` label). `size_ml` and `fragrance_name_snapshot` **stay** (frozen snapshots).
- Preserve `BelongsToShop`, `shop_id`, `unique(shop_id, slug)` on products.

**API changes.** Routes `/fragrances`→`/products`, `/fragrances/{slug}`→`/products/{slug}`
(same `catalog` throttle). `FragranceController→ProductController`,
`FragranceResource→ProductResource`, `DecantPriceResource→ProductVariantResource`,
`BrandResource` stays. Checkout payload `items[].{fragrance_id,size_ml}` → `items[].variant_id`;
update the **inline** validation in `OrderController::store` (no FormRequest exists) and the
derivation in `Order::newFromCheckout` / `currentPriceFor` to resolve by `variant_id` (variant
carries product + price + measure) — **price still re-derived server-side**. Update
`backend/docs/api.md` (and fix its two stale spots: `/meta` `payment` block, tracking payment
fields).

**Admin / storefront.** Filament `Resources/Fragrances/ → Resources/Products/` (dir + classes +
form/table). Storefront `[host]/fragrance/[slug]/ → [host]/product/[slug]/` **with a redirect**
from the old path (keep the `[host]` tenant segment). `types.ts` `Fragrance→Product`,
`DecantPrice→ProductVariant`, `CheckoutItem` `{variant_id, quantity}`; `api.ts` fetchers;
`FilterControls` stays `/meta`-driven.

**Tests.** **Update the existing suite** (`TenantIsolationTest`, `PublicApiTest`,
`AdminCatalogTest`, `DecantStockTest`, `DecantCostTest`, …) for the new names/payload. No new
isolation test (renames). Parity (35) green with field-name updates only.

**Risks.** Breaks much of the 28-file suite — update in-PR. FK/rename correctness on **Postgres**
(migrate a real pgsql + portability). Legacy `product_variant_id`/`variant_label_snapshot`
backfill must be exact (snapshots untouched). Lockstep API+frontend deploy (step-24 promotion
note applies).

**Deliberately not built.** Multi-option variants (that's step 38); `options` holds a single
`{"Size":"10ml"}` for decant.

## Step 37 — Templates + attributes

**Goal.** Introduce **code-defined templates**; move catalog attributes into jsonb; build
Filament forms, storefront filters, and `/meta` from the template.

**Schema / migrations.** `products.attributes` jsonb (nullable) + `products.search_text` text
(indexed), maintained on `saving`. Data-migrate `concentration, gender, notes, vibes,
performance` into `attributes`, then drop those columns in a follow-up migration. `description`
stays a **core column**. **No `_mm` columns exist** (only `delivery_townships.name_mm`) — nothing
to migrate there; templates may mark an attribute `translatable` for future i18n. **Brand type
stays on `brands`** — not moved into attributes; the template decides whether "brand" is a
concept for a category.

**Templates.** `App\Templates\` classes (base + `DecantTemplate`). A template defines: attributes
(name; type text/select/number; `filterable`; `searchable`; `translatable`), variant option
names (Decant: `"Size"`), **status labels** (Decanted/Packed/Baked — consumed by step 39), and
default modules (step 41). Not DB-editable (P4).

**API changes.** `/meta` (inline in `MetaController`, cached `api.meta.{shop}`) returns **filter
definitions from the template** instead of hardcoded enum lists. `ProductResource` returns shaped
`attributes`. **Search** uses `search_text` (`LOWER(...) LIKE`), **never jsonb `ilike`**. Update
`backend/docs/api.md`.

**Admin / storefront.** Filament product form fields generated from the template; storefront
`FilterControls` stays `/meta`-driven, now with template options. `search_text` kept fresh via a
model hook.

**Tests.** Parity (35) green — `/meta` + filter *values* unchanged for the decant template;
attribute round-trip; `search_text` search (**portability mandatory** — new `LIKE`); filterable/
searchable honor the template. Update `PublicApiTest`/`AdminCatalogTest` for the attribute shape.

**Risks.** jsonb + search (use `search_text`, not jsonb `ilike`); the 5-column data migration must
be lossless (parity guards values); template-driven Filament forms are the trickiest UI.

**Deliberately not built.** No DB template editor (code only); no per-attribute i18n *data*
(flag only); brand stays normalized.

## Step 38 — Clothing template

**Goal.** Prove 36–37 with a real second category: **Size + Color** variants and a **material**
attribute. If the design doesn't fit, **fix 36–37 here**, don't work around it.

**Schema / migrations.** None new (uses `products.attributes` + `product_variants.options`).
Optionally a demo clothing shop seeder.

**Templates.** `App\Templates\ClothingTemplate` — attributes (material: select; brand optional),
variant option names (`"Size"`,`"Color"`), status labels, default modules. Variant options like
`{"Size":"M","Color":"Blue"}`.

**API / admin / storefront.** Forms/filters/`/meta` render from the clothing template; multi-option
variants flow through checkout by `variant_id`.

**Tests.** A clothing shop seeds, lists, filters by material/size/color, checks out by variant;
the decant shop's parity (35) stays green.

**Risks.** This is the proof step — surface any 36–37 gap (multi-option variants, attribute types)
and fix it upstream.

**Deliberately not built.** Only two templates (decant, clothing); bakery/cosmetics later.

> **⛔ GATE — stop here.** Owner trials the product with a real seller before 39–41.

## Step 39 — Status labels (template-driven)  *(post-gate; order/scope may change)*

**Goal.** Fixed states, **template-supplied labels**; rename `decanted→prepared`,
`decant_date→prep_date`.

**Schema / migrations.** Authorized exception to the `app/Enums/**` guard:
`OrderStatus::Decanted='decanted'` → `Prepared='prepared'`, with a **data migration** updating
existing `orders.status='decanted'`→`'prepared'`. `renameColumn orders.decant_date → prep_date`
(indexed date).

**Domain / labels.** Labels move from the enum's hardcoded `label()` to a **template resolver**
(status → label per the shop's template: Decanted/Packed/Baked). **One** resolver, called by the
admin badge, CSV, invoice, API `status_label`, and the frontend timeline (P4: one domain method).

**Blast radius (one lockstep PR).** `OrderStatus.php` (value + label source); `Order::booted()`
draw-down trigger (`status===Prepared`) + `isFulfillable()`; every reader of `decant_date`
(`OrderForm`, `OrdersTable`, `UpcomingDecants`, `OrderStats` "decants due today",
`ProductionSchedule`, the `whereDate` filters); `TrackOrderController::receipt`
(`status`/`status_label`/`prep_date`) + `types.ts` `OrderStatus` + `StatusTimeline.tsx`
`POSITIVE_ORDER`/label; `invoice.blade.php:111`; CSV export.

**Tests.** Parity (35): **money figures byte-identical** — the machine-value string is the
intended rename (allowed), the values are not. Status-transition, timeline, invoice-label-per-
template tests.

**Risks.** Enum-value + data migration; API contract change (frontend lockstep); parity is the
guard that P&L/revenue don't move.

**Deliberately not built.** States stay **fixed** — never configurable (P4); only labels vary.

## Step 40 — Stock modes  *(post-gate)*

**Goal.** Two stock modes, generalizing today's `stock_ml`.

**Schema / migrations.** `per_variant`: `product_variants.stock_qty` int null + `unit_cost_mmk`
int null (per-variant COGS). `pooled`: product `stock_amount` int + `stock_unit` string — today's
`stock_ml` becomes `stock_amount`/`stock_unit='ml'`. Draw-down: pooled draws `stock_amount` by
`measure × quantity`; per_variant draws `stock_qty` by quantity.

**COGS.** Pooled keeps the **ceiling division** (`Fragrance::liquidCostMmk` generalized:
`ceil(stock_cost/stock_amount) × measure`); per_variant uses variant `unit_cost_mmk`.
`order_items.unit_cost_mmk`/`line_cost_mmk` snapshots are unchanged. **Fold in #41's atomic
design** — `lockForUpdate` + all-or-nothing shortfall check before any decrement — into the
pooled path.

**Admin.** `LowStock` widget handles both modes (per-variant qty or pooled amount vs threshold).

**Tests.** Money/stock — **mandatory**; portability (aggregation). Both modes' draw-down, COGS,
low-stock; parity green.

**Risks.** Integer ceiling division; atomic decrement under lock (pgsql, not SQLite).

**Deliberately not built.** Per-bottle identity / batch / FIFO / weighted-average (NON-GOALS;
#40/#41 parked). Stock mode is per-product/template, not arbitrary per-variant.

## Step 41 — Module toggles  *(post-gate)*

**Goal.** Opt-in modules; a shop that disables one sees **nothing new** (P3).

**Schema / migrations.** `shop_settings` gains `template` (string key) + `modules` (jsonb enabled
set). Defaults from the template; overridable.

**Modules.** `production_schedule, stock, cost_margin, promo_codes, expenses`. Disabled → hidden
from Filament nav, widgets, and API responses (e.g. `/meta` omits a disabled module's fields).

**Admin.** A settings surface to toggle modules; nav/widget registration reads `modules`.

**Tests.** A module-off shop: nav/widget/API absence asserted; defaults from template; the core
order path is never broken by a disabled module (P2).

**Risks.** Disabling a module must not break the order path; `modules` rides `shop_settings`
(already `BelongsToShop`).

**Deliberately not built.** No per-user module permissions (Shield handles auth); modules are
per-shop.

## Step 42 — Design-spec sync (docs)  *(post-gate, after 39–41)*

**Goal.** Bring the tenancy/design docs to the new schema (this is the reconciled "update #58's
spec" — #58 is merged, its living spec is the design doc).

**Changes.** `prompts/multi-tenancy-design.md` (products/product_variants names, the §8
`withoutTenancy` ledger if any new cross-shop read appeared, template/module notes);
`prompts/multi-tenancy-findings.md` / `TENANCY-KIT.md` as needed; a final `backend/docs/api.md`
pass.

**Tests.** Docs only.

**Risks.** None (code); keep the §8 ledger accurate.

**Deliberately not built.** No new ADR unless a boundary changed.

---

## P6 answers (the refactor as a whole)

1. **Which seller?** Onboarding a non-perfume seller (clothing/bakery). Today the model is
   perfume-decant-only, so a clothing seller can't be onboarded at all — this removes that
   onboarding blocker (P5).
2. **What does a shop that doesn't need it see?** A decant shop sees **nothing new** — same
   catalog, filters, checkout, and dashboard *values* (the parity test guarantees it); only
   internal names generalize.
3. **Core or module?** The product/variant/template model is **core** (every shop needs a
   catalog). Stock modes and the toggles are **modules** (P3).
4. **Smallest version?** Two templates (decant + clothing) prove the model; jsonb attributes
   before normalized option tables (P4); templates in code, not a DB editor.
5. **Money / stock / personal data?** Money: 36/39/40 touch price derivation, snapshots, P&L —
   guarded by the parity test, existing money tests, and **#67 landing first**. Stock: 40 —
   dedicated tests + portability. Personal data: **unchanged** (proofs stay private, no URL).
6. **Deliberately not built:** per-bottle identity / FIFO (#40/#41 parked); DB-editable
   templates; configurable *states*; bakery/cosmetics templates; a permanent API alias for the
   old routes beyond the storefront redirect.

## Deferred / open

- **#67 status + promotion state** — the executor confirms at the start of Phase 3 and applies
  the re-baseline fallback if #67's backend half is promotion-blocked.
- **39–41 order & scope** — decided by the owner after the step-38 real-seller trial.
- **"Brand" as a concept** — per-template (a bakery has none); decided inside steps 37–38.
- **PRODUCT.md** — domain framing reframed in #103's docs PR; further per-feature updates land
  with the steps that change scope.
