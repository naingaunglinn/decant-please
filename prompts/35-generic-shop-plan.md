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

- [x] **Pre-35 — #67 first** (correct money before the baseline) — merged before the baseline was recorded
- [x] **35** — Baseline parity test (#104, `GenericShopParityTest`)
- [x] **36** — Product + Variant model — split in two (#105): **36a built** (v40: schema, models, admin; API unchanged), **36b built** (v41: API contract + storefront)
- [x] **37** — Templates + attributes — split in two (#106): **37a built** (v42: templates, attributes jsonb, search_text, categories, admin; API additive), **37b built** (v43: storefront renders from `attributes` / `filters`)
- [x] **38** — Clothing template — split in two (#107): **38a built** (v44: clothing template, variant options + photos in the admin, option filters; API additive), **38b built** (v45: storefront option picker + variant photo, option filters, optional brand, size guide, demo clothing shop)
- [ ] **⏸ Review stop** (owner reviews 35–38; then 39–41 continue — no real-seller wait)
- [x] **39** — Status labels (template-driven; `decanted→prepared`) — **built** (v46, #126)
- [x] **40** — Stock modes (`per_variant` / `pooled`) — split in two (#128): **40a built** (v47: both modes, draw-down under lock, per-variant cost, low stock), **40b built** (v48: Myanmar weight units — `stock_unit`, frozen line `measure`, the unit guard)
- [x] **41** — Module toggles — **built** (v49, #131)
- [x] **42** — Design-spec sync (docs) — **built** (v50, #133)

Issues: 35–38 created with this plan (#103's PR); 39–42 issues are opened as each step starts
(the unattended run in `prompts/RUN-QUEUE.md`).

> **Amended 2026-09-25 (#115, the catalog review + the CornerArea scope).** Steps 36–38 gained
> the columns the category roadmap (`prompts/43-cornerarea-roadmap.md`) needs; the step-38
> gate is now a review stop, not a real-seller trial (`AGENTS.md` P5 as replaced). Amended
> lines are marked **(amended)**.

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
2. **Review stop after step 38 (amended).** Steps 35–38 make the product fit a new category.
   The owner reviews after 38, then 39–41 continue; the build no longer waits for a real
   seller (`AGENTS.md` P5: every category ships complete). Step 42 (spec sync) depends on
   39–41 and runs last.

## Cross-cutting invariants (every step)

- **Never rewrite order snapshots or money figures.** Money stays integer Kyat. A rename may
  change a *field name*; it must never move a recorded *value*. The **step-35 parity test is
  the guard** for this, not a thing later steps "update."
- **Tenancy unchanged.** Renamed tables keep `BelongsToShop` + `shop_id`; `TenantIsolationTest`
  stays green. The only new tenant-owned table is step 37's per-shop `categories`
  **(amended)**, which ships with its own isolation test in the same PR; everything else is
  renames + jsonb columns + `shop_settings` columns. The existing suite that names
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

**As built (#104).** The fixture is written inside the test, not taken from the demo seeders:
the seeders are sample content later steps rewrite, and they read `today()` and random
tracking codes. The clock is frozen at 2026-03-15 10:00 (`travelTo`); ids are compared to the
fixture's own models, never literals (Postgres sequences don't roll back). `/meta`'s
`social`/`payment` blocks are left out (they resolve through env). Beyond the spec, it also
pins each order's derived money (items, discount, fee, total, deposit, cost, signed balance)
and the "Balance outstanding" stat (since #67 had landed), the `/fragrances` filters and sorts,
the public tracking receipt's money, a smuggled client price being ignored, and the `stock_ml`
draw-down on → Decanted (step 40 rewrites it). `delivery_courier` is not pinned: it is set when
an order is handed to a courier, not when it is placed.

**Risks.** Determinism (freeze seed data + dates). Must assert money as values so a later
rename can't silently move a figure. **The fixture is created after migrations run**, so
parity cannot catch a bad *data* migration — a step that backfills or moves stored values
(36's `product_variant_id` backfill, 37's attribute move, 39's status rename, 40's
`stock_ml` move) tests that migration on its own seeded rows.

**Deliberately not built.** No storefront pixel snapshots (that's `frontend/scripts/verify-*.mjs`);
no exhaustive per-widget coverage beyond revenue/margin/P&L.

## Step 36 — Product + Variant model

**Goal.** Rename the perfume-specific model to a generic product/variant model, **IDs stable**,
with checkout keyed by variant. Server still derives every price.

**Schema / migrations** (new migrations only; never edit shipped ones):
- `Schema::rename('fragrances','products')`, `Schema::rename('decant_prices','product_variants')`.
- `product_variants`: add `options` jsonb (e.g. `{"Size":"10ml"}`), nullable `measure` int
  (10 for 10ml); keep `price_mmk`, `in_stock`, `size_ml` (legacy + measure source).
- **(amended)** `product_variants` gains `is_active` boolean (default true): a size or colour
  is archived, never deleted. Archived variants drop out of the storefront and checkout but
  stay on placed orders.
- **(amended)** `product_variants` gains `position` int (not null, default 0) for display order
  (S, M, L, XL isn't alphabetical). Backfill existing variants by `size_ml` ascending.
- `order_items`: `renameColumn fragrance_id → product_id` (keep FK `restrictOnDelete`); add
  nullable `product_variant_id` (FK → `product_variants`, same shop) + `variant_label_snapshot`;
  **backfill** both from `size_ml` for legacy rows (match product + size → variant; synthesize
  `"10ml"` label). `size_ml` and `fragrance_name_snapshot` **stay** (frozen snapshots).
  **(amended)** `order_items.product_variant_id` **restricts delete** (the variant is
  archived via `is_active`, never deleted, so a placed order never loses its line's variant).
- **(amended)** `order_items.size_ml` becomes **nullable** — a clothing line has no ml.
  Existing values are untouched.
- **(amended)** `products.brand_id` and `brands.type` become **nullable** — brand is optional
  per template (a bakery has none). **(amended, #117 review)** `products.brand_id` moves from
  `cascadeOnDelete` to **`restrictOnDelete`**: a brand that still has products can't be
  deleted — it is archived with the existing `brands.is_active`, like products and variants.
  A brand with no products still deletes. (Not `nullOnDelete`: a database-level null bypasses
  the product `saving` hook, so step 37's `search_text` would keep the deleted brand's name,
  and the seller would silently lose the brand on every product. A cascade would also hit the
  `order_items.product_id` restrict.)
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
form/table). **(amended)** Variants are archived with an `is_active` toggle, never removed:
the variant repeater must not delete child rows on save (a variant on a placed order would
hit the restrict). Storefront `[host]/fragrance/[slug]/ → [host]/product/[slug]/` **with a redirect**
from the old path (keep the `[host]` tenant segment). `types.ts` `Fragrance→Product`,
`DecantPrice→ProductVariant`, `CheckoutItem` `{variant_id, quantity}`; `api.ts` fetchers;
`FilterControls` stays `/meta`-driven.

**Tests.** **Update the existing suite** (`TenantIsolationTest`, `PublicApiTest`,
`AdminCatalogTest`, `DecantStockTest`, `DecantCostTest`, …) for the new names/payload.
**(amended)** An archived variant is refused at checkout on the server (money path), is hidden
from the catalog, and still shows on placed orders; a variant on an order can't be deleted.
**(amended, #117 review)** A brand with products can't be deleted (archive it instead); a brand
with no products still deletes. No new isolation test (renames). Parity (35) green with field-name updates only.

**Risks.** Breaks much of the 28-file suite — update in-PR. FK/rename correctness on **Postgres**
(migrate a real pgsql + portability). Legacy `product_variant_id`/`variant_label_snapshot`
backfill must be exact (snapshots untouched). Lockstep API+frontend deploy (step-24 promotion
note applies).

**Deliberately not built.** Multi-option variants (that's step 38); `options` holds a single
`{"Size":"10ml"}` for decant.

**As built — split in two (#105).** The whole step is well over one reviewable PR, and the
spec's contract rule (a Resource change lands with `types.ts`) gives the seam:

- **36a (v40): schema, models, admin — the public API is byte-identical.** Both migrations,
  `Product`/`ProductVariant` (relations `variants()`, `activeVariants()`, `product()`,
  `OrderItem::variant()`), the Filament `Resources/Products/` rename, archived variants, the
  brand delete rule (after the #117 review a brand with products can't be deleted — it is
  archived, and the admin's delete says so instead of failing). **Deviation:** the FK is
  `NO ACTION`, not `RESTRICT`. Both refuse deleting a brand with products, but SQLite checks
  `RESTRICT` mid-cascade, so a shop delete would trip over it. Also renamed, beyond the
  spec: `product_variants.fragrance_id → product_id` (the variant's own FK), and the Shield permissions `{Ability}:Fragrance →
  {Ability}:Product` (a data migration; role grants follow the row, so nobody loses the
  catalog). Postgres keeps constraint/index/sequence names through a rename, so the
  migration renames them to match (both ways). Every order line created from now on stamps
  `product_variant_id` + `variant_label_snapshot` once, in `OrderItem`'s creating hook.
  **Deviation:** `position` is backfilled as 0, not by size. Ties sort by `size_ml`, which
  gives the same order today. Numbering by size would list any size added later
  (position 0) first. Step 38's drag-to-reorder writes real positions. The admin URL stays
  `/admin/{shop}/fragrances` and the label stays "Fragrances" until step 37's template
  supplies them.
- **36b (v41): the contract.** `/products` routes, `ProductController`,
  `ProductResource`/`ProductVariantResource`, checkout by `items[].variant_id`, `types.ts`,
  the storefront `/product/[slug]` route with the redirect, and the `api.md` fixes.
  **Deviations, for deploy safety** (Heroku and Vercel don't deploy atomically, and old
  carts live in customers' browsers):
  - The product object keeps the `prices` key. Each entry only gains `id` and `label`.
  - `/fragrances` and `/fragrances/{slug}` stay as aliases.
  - Checkout still resolves a legacy `fragrance_id` + `size_ml` line.

  Both alias paths go after go-live (a RUN-QUEUE row). They protect an old storefront on
  the new API, not the reverse, so the API deploys first: Heroku maintenance mode stays on
  from before the `main` promotion until the release is out. The storefront cart moved to a `v2`
  storage key, so a pre-deploy cart is dropped rather than migrated. Component names
  (`FragranceCard`, `FragranceGrid`) and the tracking receipt's `fragrance_name`/`size_ml`
  are unchanged. Step 37's template decides the storefront's words.

Brandless products (possible once a brand is deleted) are hidden from the storefront and
refused at checkout until 36b/37 make brand optional in the API contract; the admin still
lists them.

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

**(amended)** Also in this step:
- `products.template` (string key, not null): set per product, copied from the shop's default
  on create. A shop may mix templates only **within its own group** (clothes + bags: yes; cafe
  drinks + shipped coffee beans: no — see the roadmap's groups). Backfill existing products to
  `decant`.
- `shop_settings.template` (the shop's default template key) **moves here from step 41** —
  step 38 already needs it. Backfill existing shops to `decant`.
- A per-shop **`categories`** table (menu sections, "tops / dresses"): `shop_id` +
  `BelongsToShop`, `name`, `position`, `unique(shop_id, name)`; `products.category_id`
  nullable FK (→ `categories`, same shop, **`nullOnDelete`** — deleting a menu section never
  deletes or blocks its products). A new tenant table, so **its isolation test ships in the
  same PR**.

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
model hook. **(amended, #117 review)** When a brand's name changes, rebuild `search_text` for its
products (a `Brand` `saved` hook when `name` is dirty) — the product hook alone never sees a
brand rename.

**Tests.** Parity (35) green — `/meta` + filter *values* unchanged for the decant template;
attribute round-trip; `search_text` search (**portability mandatory** — new `LIKE`); filterable/
searchable honor the template. Update `PublicApiTest`/`AdminCatalogTest` for the attribute shape.
**(amended)** `categories` isolation test (two shops, same category name, no leak); a product
takes the shop's default template on create; a template outside the shop's group is refused.
**(amended, #117 review)** Renaming a brand rebuilds its products' `search_text`: search finds
them by the new name, not the old one.

**Risks.** jsonb + search (use `search_text`, not jsonb `ilike`); the 5-column data migration must
be lossless (parity guards values); template-driven Filament forms are the trickiest UI.

**Deliberately not built.** No DB template editor (code only); no per-attribute i18n *data*
(flag only); brand stays normalized.

**As built — split in two (#106).** Like step 36, the whole step is over one reviewable
PR. The seam is the storefront.

- **37a (v42): backend, with an additive contract.** Everything above except the
  storefront.
  - Templates: `app/Templates/` — `Template`, `Attribute`, `DecantTemplate`, `Templates`.
  - Both migrations (add + backfill, then drop the five columns).
  - `search_text`, the brand-rename rebuild, `products.template` / `shop_settings.template`,
    and `categories` with its isolation test.
  - The template-driven admin form, table and CSV import.
  - `/meta` `filters`, and `attributes` + `template` on the product.

  Decisions and deviations:
  - **The API is additive, not replaced.** `/meta` keeps `genders`/`concentrations` and
    the product keeps its flat perfume keys (now read from `attributes`), so the pre-37
    storefront keeps working on the new API. They go with the other aliases after go-live
    (RUN-QUEUE row 32).
  - **`Attribute` gained `required`** (concentration and gender were NOT NULL columns) and
    `long` (Textarea vs TextInput). It is enforced in the admin form and the CSV import,
    not by the model — like `description`.
  - **`notes` filters through `search_text`**, because a text filter may not LIKE into
    jsonb. So `?notes=chanel` also matches the brand, and `q` now matches notes too, since
    notes are searchable. `q` also matches across brand + name ("chanel bleu").
  - **`search_text` has no index.** `%q%` can't use a btree, and a btree entry has a
    size limit a long notes field could hit. It is lowercased in PHP (`mb_strtolower`),
    because SQLite's `LOWER()` folds ASCII only. `%` and `_` are escaped.
  - **Filters come from the shop's default template**, not per product. A mixed-template
    shop (step 38 onward) filters by its default's attributes.
  - The column is named `attributes`, as specified. Inside `Product`, `$this->attributes`
    is Eloquent's raw array, so the model reads values through `attr()` / `attrDisplay()`.
  - The template picker in the admin waits for step 38. Until then group 1 has one
    template, so there is nothing to pick.
  - No admin screen for `categories` yet: menu categories are RUN-QUEUE row 20, and P3
    says a decant shop sees nothing new.
- **37b (v43): the storefront.** `FilterControls` renders from `/meta` `filters`, and the
  product page and cards render from `attributes` instead of the flat keys.
  - **Added beyond the spec:** each product attribute carries `show`
    (`headline` / `pill` / `list`). The storefront can't place an attribute from
    `key/label/value/display` alone without guessing, and guessing "the first select is
    the headline" breaks when a clothing template's first select is Material. So the
    template says it: `Template::headline()` (default none; decant: concentration) and
    `Attribute` `list: true` (decant: notes, vibes). `list` is separate from `long`,
    which stays an admin textarea hint.
  - The shop page awaits `/meta` before `/products` and forwards only the template's
    filter keys (plus the core ones), so a stray query param never reaches the API.
  - Related products top up by the first select *filter* the product has, not any
    select: the API ignores a non-filterable key.
  - Decant looks the same except pill order (template order), pill tones (one style)
    and the "Notes" heading ("Scent notes").

## Step 38 — Clothing template

**Goal.** Prove 36–37 with a real second category: **Size + Color** variants and a **material**
attribute. If the design doesn't fit, **fix 36–37 here**, don't work around it.

**Schema / migrations.** **(amended)** `product_variants.image_path` (nullable string): a photo
per colour, stored under the shop's `shops/{id}/…` prefix like product images. Otherwise uses
`products.attributes` + `product_variants.options`. Optionally a demo clothing shop seeder.

**Templates.** `App\Templates\ClothingTemplate` — attributes (material: select; brand optional),
variant option names (`"Size"`,`"Color"`), status labels, default modules. Variant options like
`{"Size":"M","Color":"Blue"}`.

**API / admin / storefront.** Forms/filters/`/meta` render from the clothing template; multi-option
variants flow through checkout by `variant_id`.

**Tests.** A clothing shop seeds, lists, filters by material/size/color, checks out by variant;
the decant shop's parity (35) stays green. **(amended)** A variant photo is stored under the
shop's `shops/{id}/…` prefix.

**Risks.** This is the proof step — surface any 36–37 gap (multi-option variants, attribute types)
and fix it upstream.

**Deliberately not built.** Only two templates (decant, clothing); bakery/cosmetics later.

**As built — split in two (#107).** Like 36 and 37, the step is over one reviewable PR.
The seam is the customer: 38a lets a clothing shop exist and sell through the API; 38b
shows it on the storefront.

- **38a (v44): backend, admin, studio — the API is additive.**
  - `ClothingTemplate` (material and "For" selects, both filterable; Size + Color).
  - `Template::measure()`: `'ml'` for decant, null otherwise. Every ml-only piece hangs
    off it — the ml size field, the Stock and Cost sections, the ml columns and size
    filter, the CSV import — so a decant shop sees nothing new (P3). Also
    `variantPhotos()`, `variantsHeading()` and `name()`.
  - **Gap fixed upstream (36):** `product_variants.size_ml` was still NOT NULL; the
    migration makes it nullable and adds `image_path`. `down()` refuses while a
    sizeless variant exists rather than invent a size.
  - Admin: one text field per option, distinct Size + Color per product (case- and
    space-insensitive), drag-to-reorder into `position`, a photo per variant under
    `shops/{id}/variants/`. `ProductVariant` trims options and keeps the template's
    order, so a label always reads "M / Blue". Duplicating a product copies options and
    photos.
  - **Admin order lines:** a clothing line picks a variant ("Option") instead of
    typing ml. Without this, saving any clothing order in the admin failed on the
    required ml size.
  - Every "{size_ml}ml" an admin reads (invoice, day sheet, order list and CSV,
    upcoming decants, Telegram) now prints `OrderItem::variantLabel()` — the frozen
    label, identical for decant. The production schedule groups by it too, so M / Blue
    and L / Red stay two lines.
  - API, additive: `prices[].options` and `image_url`; `/products`
    `option[{name}]=` (one in-stock variant must match every picked option);
    `/meta` `variant_options` (empty for decant); tracking `items[].variant_label`, and
    the receipt shows it.
  - Studio "Register a shop" picks the category (`Templates::assignToShop`, written
    under the new shop's context — no `withoutTenancy()`).
  - Decisions: no per-product template picker yet (it would show on the decant form;
    a mixing shop arrives with row 15); brand stays required in the clothing form
    until 38b makes it optional in the API contract (the storefront still hides
    brandless products); `statusLabels()` and `defaultModules()` for clothing are data
    until steps 39 and 41 read them.
- **38b (v45, RUN-QUEUE row 6b): the storefront.** Option picker (Size then Color) with the
  variant photo, option filter UI from `variant_options`, optional brand in the API
  contract (`brand: null`), a size guide, and a demo clothing shop seeder for the
  browser checks.

  Decisions and deviations:
  - **Optional brand is a template flag** (`Template::brandRequired()`; clothing false).
    The rule "active, and the brand active if there is one" is `Product::scopeSellable()`,
    shared by `/products`, `/meta` and checkout. Before, all four sites used
    `whereHas('brand')` and hid brandless products.
  - **Brand types are decant's** (`Template::brandTypes()`). `/meta` `brand_types` is
    empty otherwise, so clothing gets no Designer/Niche filter, pill or home tile. The
    admin hides the brand's type there too. A new brand still stores the column's
    default (`designer`), but no clothing surface shows it.
  - **The CSV import still requires a brand.** It only runs for ml (decant) shops since
    38a, where a brand is required anyway.
  - **The size guide is a per-product text attribute** (`size_guide`, `Attribute`
    `section: true` → `show: "section"`), not a shop setting. It needs no migration, and
    fit differs by garment. A shop-wide size chart can come with the design system's
    size-guide section (RUN-QUEUE row 13).
  - **The picker is chosen per product**: any variant with `size_ml: null` gets the
    option picker; ml variants keep the size list, so decant is unchanged. A later
    option lists only the values that exist for the earlier picks.
  - **Filter groups hide when empty** (Brand, Brand type, ml Size), which never happens
    for decant.
  - `next.config.ts` gains a dev-only `allowedDevOrigins` for `*.decant.localhost`, so the
    demo shop hydrates under `next dev`.
  - Not built: shop-wide copy is still decant's ("Shop decants", "Fragrance or brand…",
    the footer blurb). The template's own words and Burmese sample content come with the
    design system and group-1 presets (rows 13 and 15). Also not built: colour swatches
    (pills and the variant photo instead; a raw colour would need a token decision) and
    a per-product template picker.

> **⏸ Review stop (amended).** The owner reviews 35–38 here; then 39–41 continue. It no longer
> waits for a real-seller trial (`AGENTS.md` P5 as replaced by #115).

## Step 39 — Status labels (template-driven)  *(after the review stop)*

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

**As built (#126, v46).** One PR (backend, migration and the storefront timeline together;
under the size budget, so no split).

- Migration `2026_09_29_000000`: `orders.status` `decanted` → `prepared` (DB::table, every
  shop's rows) and `decant_date` → `prep_date`. The index keeps its name
  `orders_decant_date_index`. `down()` reverses both; on Postgres the orders money/date hash
  is identical up → down → up. `StatusLabelTest` round-trips it on seeded rows in two shops.
- **The one resolver is `Templates::statusLabel()`**; `OrderStatus::label()` (and so the
  Filament badge, status select and tabs, CSV, invoice and the tracking API) calls it. The
  enum keeps a category-free `defaultLabel()`, which templates start from and never the
  resolver (no recursion). `Template::statusLabels()` is concrete now; a template overrides
  `preparedLabel()` (decant "Decanted", clothing "Packed").
- No tenant set (a console command): the category-free word, not a throw. A label is not
  shop data, so the fallback can't leak.
- Memoised per shop id for the request with `once()` — the orders list would otherwise read
  `shop_settings` once per badge (rule 6). `once()` keys on the closure's *captured*
  variables, so the closure captures `$shopId`; keyed by the Shop object it could hand one
  shop another's labels after GC. The two-shop test caught the uncaptured version.
- `Template::prepDateLabel()` (decant "Decant date", clothing "Packing date", default
  "Prep date") names the date on the order form, accept modal, orders table, upcoming
  widget and CSV header — without it the rename would have shown a decant seller
  "Prep date". `prepDateHelp()` does the same for the hint under it.
- Tracking API: `status_labels` (every state, additive), `prep_date`, and a `decant_date`
  deploy alias. The storefront timeline names the third step from `status_labels.prepared`
  and accepts both `"prepared"` and `"decanted"`, so either deploy order works; the alias
  and the tolerance leave with RUN-QUEUE row 32. The "Decanting {date}" caption became
  "On the schedule for {date}" (category-free).
- Not built: Burmese labels (no locale mechanism exists yet; they arrive with the group-1
  presets, RUN-QUEUE row 15, from the roadmap's status-label table); decant words
  elsewhere in the admin ("Today's Decants" tab, "Decants due today" stat, "Upcoming
  decants" widget, accept-toast copy) — step 41's module toggles hide the production
  schedule for clothing, and the rest comes with the template copy in rows 13/15; the
  `UpcomingDecants` class name and the index name.

## Step 40 — Stock modes  *(after the review stop)*

**Goal.** Two stock modes, generalizing today's `stock_ml`.

**Schema / migrations.** `per_variant`: `product_variants.stock_qty` int null + `unit_cost_mmk`
int null (per-variant COGS). `pooled`: product `stock_amount` int + `stock_unit` string — today's
`stock_ml` becomes `stock_amount`/`stock_unit='ml'`. Draw-down: pooled draws `stock_amount` by
`measure × quantity`; per_variant draws `stock_qty` by quantity.

**COGS.** Pooled keeps the **ceiling division** (`Fragrance::liquidCostMmk` generalized:
`ceil(stock_cost × measure / stock_amount)` — multiply first, round once. **(corrected #104)**
The earlier `ceil(stock_cost/stock_amount) × measure` rounds per unit and would move money:
100,000 Ks / 30ml at 5ml is 16,667 today, 16,670 under that formula; the parity test pins
16,667); per_variant uses variant `unit_cost_mmk`.
`order_items.unit_cost_mmk`/`line_cost_mmk` snapshots are unchanged. **Fold in #41's atomic
design** — `lockForUpdate` + all-or-nothing shortfall check before any decrement — into the
pooled path.

**Admin.** `LowStock` widget handles both modes (per-variant qty or pooled amount vs threshold).

**Tests.** Money/stock — **mandatory**; portability (aggregation). Both modes' draw-down, COGS,
low-stock; parity green.

**Risks.** Integer ceiling division; atomic decrement under lock (pgsql, not SQLite).

**Deliberately not built.** Per-bottle identity / batch / FIFO / weighted-average (NON-GOALS;
#40/#41 parked). Stock mode is per-product/template, not arbitrary per-variant.

**As built (#128).** Split in two, like 36–38: **40a** (v47) builds both modes for ml and
pieces; **40b** (RUN-QUEUE row 8b) adds Myanmar weight units.

- **Mode is a template method**, `Template::stockMode()`: `per_variant` by default,
  `pooled` for decant. It isn't inferred from `measure()`: the roadmap's pet supplies sell
  by weight but count bags.
- **No `stock_unit` column in 40a.** The only pooled unit is the template's `measure()`
  (ml). 40b owns the unit: it adds `products.stock_unit`, or refuses a template switch
  that changes it, because otherwise 500 ml would read as 500 kyatthar after a switch.
  40b also needs a frozen per-line amount on `order_items`: `size_ml` is named for ml, and
  `variant.measure` is live, not a snapshot.
- **The COGS denominator is the reference bottle, not the running stock.** The formula
  above says `stock_amount`, but the running amount falls with every draw-down, so unit
  cost would rise as the bottle empties. Pooled cost stays `liquidCostMmk()` over
  `bottle_cost_mmk` / `bottle_volume_ml` (the parity figure 16,667 holds). The pair keeps
  its names until 40b, which renames it off `_ml` for weight.
- **#41's atomic design, folded in as locking, not blocking.** `Order::drawDownStock()`
  runs in its own transaction (a plain `save()` opens none) and locks every affected
  product and variant row (products then variants, id order) before writing any.
  All-or-nothing refusal on a shortfall was not built: the draw-down runs at Prepared,
  when the vials are already filled, and the decant rule is warn-only (tested). Accept is
  where a shortfall is flagged, now per variant too.
- **Cost by mode, never a fallback chain**: `OrderItem::currentUnitCost()` — pooled reads
  the bottle pair, per variant reads `product_variants.unit_cost_mmk`. The line snapshot
  now matches the variant before it costs the line.
- **One reorder line per product** (`low_stock_threshold`): ml for pooled, pieces for
  each variant of a per-variant product. The migration sets existing per-variant products
  to 2 (the ml default 30 in pieces would flag every size at once); none was counted yet.
- `Product::scopeLowStock()` checks each product's mode (`Templates::pooledKeys()`), as
  `isLowStock()` does, so a count left from the other mode after a template switch
  doesn't flag. `LowStock` orders NULL pooled amounts last explicitly (SQLite and Postgres sort NULLs
  differently) and qualifies both sides of the variant comparison inside `whereHas`.
- Not built: a count reaching zero doesn't flip `in_stock` (manual, as for decant);
  checkout doesn't reserve or refuse by count; "liquid only" margin wording stays on the
  clothing order's margin (template copy, rows 13/15); per-variant reorder lines.

**40b as built (v48, RUN-QUEUE row 8b).**

- **One base unit per product, integer.** `products.stock_unit` is `ml` or `kyatthar`,
  set from the template's `measure()` when the product is pooled. A viss (100 kyatthar)
  is display only — never stored — so packs of 25 kyatthar and 1 viss draw from one total
  with no conversion. `App\Support\StockUnit::format()` is the one place an amount
  becomes words. Admin words are English (the admin has no Burmese yet); the Burmese
  unit names come with the produce template's sample content (row 15).
- **The pair is `reference_cost_mmk` / `reference_amount`** (was `bottle_cost_mmk` /
  `bottle_volume_ml`), in its own rename-only migration; `pooledCostMmk()` keeps the
  ceiling rule. Decant's admin still says "Bottle cost" / "Bottle size".
- **Frozen line amount: `order_items.measure`**, stamped once in `OrderItem`'s creating
  hook from `size_ml` or the variant's `measure`; draw-down, shortfall and cost read it.
  Backfilled from `size_ml`. The admin order form re-stamps it when an edit changes the
  line's size or pack. No unit column on the line: the guard below means a product's
  unit can't change once any line has an amount.
- **Weighed variants reuse `product_variants.measure`** (whole kyatthar; `size_ml` null)
  and label themselves from it under the template's first option (`Weight`). The API
  serves them as options — `measure() !== 'ml'` is the "options, not ml sizes" test — so
  the 38b picker sells them unchanged; no contract change.
- **The guard** (in `Product`'s saving hook, so admin, import and studio all hit it):
  switching to a template in another unit is refused while the product has stock, a
  reference cost, a variant with a size, or any order line with a frozen amount —
  including an untracked product on an accepted, not-yet-prepared order. Clear the numbers, or add a new product.
  A per-variant template keeps the unit. The reorder line is a setting and carries over.
- Not built: sub-kyatthar weights (mat, pe); grams / kilograms; converting stock between
  units; the produce template and its storefront copy (row 15).

## Step 41 — Module toggles  *(after the review stop)*

**Goal.** Opt-in modules; a shop that disables one sees **nothing new** (P3).

**Schema / migrations.** `shop_settings` gains `modules` (jsonb enabled set). Defaults from the
template; overridable. **(amended)** `shop_settings.template` moved to step 37.

**Modules.** `production_schedule, stock, cost_margin, promo_codes, expenses` (later groups
add theirs; see the default-modules table in `prompts/43-cornerarea-roadmap.md`). Disabled → hidden
from Filament nav, widgets, and API responses (e.g. `/meta` omits a disabled module's fields).

**Admin.** A settings surface to toggle modules; nav/widget registration reads `modules`.

**Tests.** A module-off shop: nav/widget/API absence asserted; defaults from template; the core
order path is never broken by a disabled module (P2).

**Risks.** Disabling a module must not break the order path; `modules` rides `shop_settings`
(already `BelongsToShop`).

**Deliberately not built.** No per-user module permissions (Shield handles auth); modules are
per-shop.

**As built (v49, #131).**

- **Keys** live in `App\Support\Modules` (constants, not an enum under `app/Enums`):
  `stock`, `cost_margin`, `production_schedule`, `promo_codes`, `expenses`. Stored keys,
  never renamed. `DecantTemplate`'s unread `cost` became `cost_margin`.
- **`shop_settings.modules` jsonb, null = the shop template's defaults.** No backfill:
  every decant shop keeps exactly today's screens; a clothing shop (today only the demo
  seeder's) loses the production schedule by default. The Features page (Settings) saves
  the full enabled set; a saved set doesn't pick up a module a template later turns on
  by default. Unknown keys are dropped on read and write.
- **One resolver**, `Modules::on()` / `enabled()`, memoised per shop id with `once()`;
  `ShopSetting`'s saved hook flushes it (and the status-label memo) with the `/meta`
  cache. No tenant (console): every module.
- **Defaults** follow the roadmap's group-1 column: decant all five; clothing drops the
  production schedule but keeps stock and cost (per-variant, step 40 — the pre-40
  comment that left them out was stale).
- **Off hides screens, never writes.** Pages and resources refuse their URL
  (`canAccess`, Shield still applies), widgets drop (`canView`), and form fields and
  columns are left out. Draw-down still runs on any counted product, and each order line
  still freezes its cost (the admin line's cost field is hidden but still saved), so
  turning a module back on shows true numbers. With stock off a new product still takes
  its mode's reorder line (a hidden field: 30 pooled, 2 per variant).
- **Surfaces**: production schedule → both schedule pages, "Upcoming decants";
  stock → low-stock panel, product stock sections and column, the Accept shortfall
  warning; cost & margin → product cost section, variant cost, Cost/ml column, the
  order line's cost field, the order and dashboard margin; promo codes → the resource,
  the discount widget, and `PromoCode::evaluate()` (one place — preview and checkout
  both answer "not found", the order goes through at full price); expenses → the
  resource and Profit & loss.
- **API**: `/meta` gains `modules` (additive; `types.ts` optional). The storefront
  checkout hides the promo box without `promo_codes`, and shows it when the list is
  missing.
- Not built: delivery zones as a module (checkout needs a township — off would break
  the order path; groups 3/4 add the no-delivery path); hiding P&L's COGS and margin rows with cost off (net
  subtracts COGS — a hidden line would leave a total that doesn't add up; P&L follows
  `expenses` only); hiding `prep_date` (Accept and
  the order form need it; only the calendar is the module); the orders CSV keeps its
  cost/margin columns (a fixed export shape); Burmese labels on the Features page (the
  admin is English-only today).

## Step 42 — Design-spec sync (docs)  *(after 39–41)*

**Goal.** Bring the tenancy/design docs to the new schema (this is the reconciled "update #58's
spec" — #58 is merged, its living spec is the design doc).

**Changes.** `prompts/multi-tenancy-design.md` (products/product_variants names, the §8
`withoutTenancy` ledger if any new cross-shop read appeared, template/module notes);
`prompts/multi-tenancy-findings.md` / `TENANCY-KIT.md` as needed; a final `backend/docs/api.md`
pass.

**Tests.** Docs only.

**Risks.** None (code); keep the §8 ledger accurate.

**Deliberately not built.** No new ADR unless a boundary changed.

**As built (v50, #133).** Docs only; no boundary changed, so no ADR.

- **`multi-tenancy-design.md`**: a dated amendment line in the status header (the rename
  map). §2's request flow names `/products`. §7 gains a "generic-shop refactor" block: the
  renames kept every row's `shop_id`, `categories` is the one new tenant-owned table,
  templates and modules are one `shop_settings` column each, the `once()` memos are keyed
  per shop id (no new cache key), and there is no new bypass. §7's storage layout records
  the `shops/{id}/…` prefixes as built (seven write sites). §8 renames the catalog row,
  adds rows for categories, variant checkout, template words and modules, and records the
  ledger as it stands: 2 of the 5 allowed call sites in `app/` (tracking-code dedup,
  `StudioShopStats`). None were added by 35–41. The ADRs (§3–§6) are left as decided.
- **`multi-tenancy-findings.md`**: a point-in-time audit, so the body keeps its names; one
  note at the top maps them to the new ones.
- **`TENANCY-KIT.md`**: unchanged. It is the session runbook for steps 32–34, all done, and
  nothing in it names the renamed tables.
- **`backend/docs/api.md`**: ten `{shop}` endpoints, not nine (`POST /orders/payment-proof`
  was never documented), plus the platform `GET /_storefront/host/{host}`. CORS includes
  verified shop domains. Rate limits are keyed by shop + IP, and the `payment-proof` and
  `host-resolve` buckets are listed. Checkout's `payment_method` and `proof` (multipart
  for online) are documented. Catalog wording says variant/product, not decant/fragrance.
  The receipt's `fragrance_name` is documented as it behaves: read from the current
  product, not the line's name snapshot.
- Found, not fixed (outside a docs step), filed as #134 (RUN-QUEUE row 10b): the receipt
  name above; `decant:fresh-start`
  leaves variant photos on the public disk; the payment-proof endpoint's comment says it
  only makes sense before the order is settled, but it doesn't check the status; and the
  customer-facing "fragrance" error strings reach a clothing shop. **All four fixed in
  v51 (#134)**: the receipt reads the snapshot, fresh-start deletes variant photos,
  payment-proof refuses a paid, cancelled or rejected order (409), and the strings say
  item/product.

---

## P6 answers (the refactor as a whole)

1. **Which category, and which seller?** Every category after decant, starting with clothing:
   a clothing seller listing a top in S/M/L × two colours. Today the model is
   perfume-decant-only, so no non-decant category can be built at all — this is the catalog
   every category in `prompts/43-cornerarea-roadmap.md` stands on (P5, as amended).
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
- ~~39–41 order & scope~~ — **resolved (#115)**: the step-38 gate is a review stop; 39–41 run
  in order after it (`prompts/RUN-QUEUE.md`).
- ~~"Brand" as a concept~~ — **resolved (#115)**: brand is optional per template.
  `products.brand_id` and `brands.type` are nullable (step 36); the template decides whether
  the form shows a brand.
- **PRODUCT.md** — domain framing reframed in #103's docs PR; further per-feature updates land
  with the steps that change scope.
