# CHANGELOG — Decant Please!

Version history, newest first. Moved verbatim out of the root `CLAUDE.md` (see
`docs/adr/0001-agents-md-as-context-root.md`) so it is read on demand rather than
loaded into agent context on every turn.

Durable rules now live in `AGENTS.md`. The product spec lives in `PRODUCT.md`.
Per `prompts/WORKFLOW.md` step 5, new version notes are appended **here**, at the top.

---

## 0. What changed in v51

**v51** fixes the four pre-existing defects step 42 found (**#134**, RUN-QUEUE row 10b).
No migration.

- **The receipt names an item as it sold.** `TrackOrderController::receipt()` reads the
  line's `fragrance_name_snapshot` (`Brand Name`, frozen at checkout) instead of composing
  `Brand — Name (Concentration)` from the live product. Renaming a product or its brand no
  longer rewrites an old receipt (AGENTS.md §4 rule 3). The receipt string loses the
  em dash and the concentration suffix; it now matches the admin and the Telegram alert.
  The `fragrance_name` key is unchanged.
- **`decant:fresh-start` deletes variant photos** (`product_variants.image_path`, step 38)
  from the media disk by stored path, like product images. Scoped to the reset shop; the
  two-shop fresh-start isolation test now covers the photos too.
- **The payment-proof upload checks the order.** `Order::acceptsPaymentProof()` refuses a
  paid, cancelled or rejected order with a `409`, before anything is stored. A replacement
  would delete the slip the seller confirmed against. Delivered-but-unpaid still accepts
  a slip. The wrong-credentials 404 is unchanged and comes first. The storefront shows the
  409's message instead of "check your connection" (`uploadPaymentProof` throws
  `ApiConflictError`).
- **Generic wording**: `That item is no longer available.` and `Product not found.`
  replace the two "fragrance" strings a clothing shop's customers could see.
- `backend/docs/api.md` updated: the snapshot rule for `fragrance_name`, the payment-proof
  `409`, and the two strings.

## 0. What changed in v50

**v50** is step 42 (**#133**), the last step of the generic-shop refactor. It is docs only:
no code, no migration, no API change. The tenancy docs and the API contract now describe
the code after steps 35–41.

- **`prompts/multi-tenancy-design.md`**: the status header records the renames
  (`fragrances` → `products`, `decant_prices` → `product_variants`). §7 records what the
  refactor added: `categories` is the one new tenant-owned table, templates and modules are
  one `shop_settings` column each, and the per-shop `once()` memos add no cache key. The
  storage layout lists the real `shops/{id}/…` prefixes. §8 gains test rows for categories,
  variant checkout, template words and modules. The `withoutTenancy()` ledger shows 2 of
  the 5 allowed call sites in `app/`; steps 35–41 added none.
- **`prompts/multi-tenancy-findings.md`**: one note maps the audit's old names to the new
  ones. The audit body keeps its original names. `TENANCY-KIT.md` is unchanged.
- **`backend/docs/api.md`**:
  - Now lists ten `{shop}` endpoints. `POST /orders/payment-proof` was missing.
  - Adds the platform host-resolve endpoint.
  - CORS includes verified shop domains, and rate limits are keyed by shop + IP.
  - Documents checkout's `payment_method` + `proof` fields.
  - Catalog wording is generic.
  - States that the receipt's item name comes from the current product.
- **Found, not fixed**, filed as #134 and queued as RUN-QUEUE row 10b:
  - The tracking receipt's item name is read live, not from the name snapshot.
  - `decant:fresh-start` leaves variant photos behind.
  - The payment-proof endpoint doesn't check order status.
  - The customer-facing "fragrance" error strings reach a clothing shop.

## 0. What changed in v49

**v49** is step 41 (**#131**). Each shop turns optional features on and off. A decant
shop that never touches it sees exactly what it saw before. A clothing shop loses the
production schedule page and the "Upcoming" panel by default (roadmap: decant only). The
only clothing shop today is the demo seeder's.

- **Five modules**, keys in `App\Support\Modules`: `stock`, `cost_margin`,
  `production_schedule`, `promo_codes`, `expenses`. Delivery zones stay core (checkout
  needs a township).
- **Migration** `2026_10_02_000000_add_modules_to_shop_settings` adds
  `shop_settings.modules` (jsonb, null). Null means the shop template's defaults
  (`Template::defaultModules()`), so no backfill. Up → down → up on Postgres 17.
- **Defaults**: decant, all five. Clothing, all but the production schedule.
- **Profit & loss follows `expenses` only.** Its COGS and gross-margin rows stay with cost
  off: net profit subtracts COGS, and hiding a line the total includes would leave numbers
  that don't add up.
- **Stock off still sets the reorder line** on a new product (hidden field, the mode's
  default: 30 pooled, 2 pieces per variant), so turning stock on later doesn't flag every
  size at once.
- **Features page** (admin → Settings) with plain-word descriptions. Saving stores the full
  enabled set and reloads, so the menu changes at once.
- **Off hides, never writes.** Pages and resources refuse their URL, widgets drop off the
  dashboard, and stock and cost fields leave the product and order forms. Stock still
  draws down and each order line still freezes its cost, so turning a module back on shows
  true numbers.
- **Promo codes off is enforced on the server**: `PromoCode::evaluate()` answers every
  code as not found. Checkout still places the order, at full price. `/meta` gains
  `modules`, and the storefront checkout hides the promo box without `promo_codes`.
- Tests: `ModuleTogglesTest` (12). It covers defaults, two shops in one request, the
  Features page, the exact admin menu before and after, and typed URLs refused. It checks
  that hidden fields keep their numbers in both stock modes, and that a new product still
  gets its reorder line. It also covers `/meta`, promo refusal with a full-price checkout,
  and the core order loop with every module off. The weighed test template gets group 1's
  defaults.

**Deploy:** migration first (additive, nullable). The API change is additive, and the
storefront treats a missing `modules` as all on, so either half can go first. The
storefront's `/meta` fetch revalidates every 60 seconds, so the promo box can lag a toggle by up
to a minute; the server refuses codes regardless. Rollback:
the migration's `down()` drops the column; existing shops were on template defaults
anyway.

## 0. What changed in v48

**v48** is step 40b (**#128**). Pooled stock can be counted by weight, in the Myanmar units
a produce seller uses: kyatthar, and viss (1 viss = 100 kyatthar). A decant shop sees
nothing new. The produce template itself is RUN-QUEUE row 15; this step is tested with a
test-only weighed template.

- **One base unit per product, whole numbers.** `products.stock_unit` is `ml` or
  `kyatthar`. A viss is display only, so a 25-kyatthar pack and a 1-viss pack draw from the
  same running total with no conversion. `App\Support\StockUnit::format()` is the one
  place an amount becomes words: "30ml", "25 kyatthar", "1 viss 50 kyatthar".
- **Migrations.** `2026_10_01_000000_rename_reference_cost_pair` renames only:
  `bottle_cost_mmk` → `reference_cost_mmk`, `bottle_volume_ml` → `reference_amount`
  (`Product::liquidCostMmk()` → `pooledCostMmk()`, `addBottle()` → `addStock()`).
  `2026_10_01_000001_add_weight_units` adds `products.stock_unit` (backfilled `ml` for
  decant and for any product with an ml figure) and `order_items.measure` (backfilled
  from `size_ml`). `down()` refuses while anything is weighed. Up → down → up on Postgres
  17: the order-line, order and product money hashes are identical in all three states.
- **A frozen per-line amount.** `order_items.measure` is how much of the pooled stock one
  unit of the line draws, stamped once in `OrderItem`'s creating hook from `size_ml` or the
  variant's `measure`. Draw-down, the Accept shortfall and the cost snapshot read it, so
  re-weighing a pack later never moves a placed order.
- **Weighed variants** use the existing `product_variants.measure` (whole kyatthar,
  `size_ml` null) and label themselves: measure 150 → `{"Weight": "1 viss 50 kyatthar"}`.
  Cost is the same ceiling rule over the reference pair (100,000 Ks for 300 kyatthar →
  8,334 Ks for 25).
- **The unit guard** (`Product` saving hook). A pooled product takes its template's unit.
  A switch to a template in another unit is refused while anything is counted in the old
  one: the stock, the reference cost, a variant's size, or any order line with a frozen
  amount. Otherwise 500 ml would read as 500 kyatthar, and a "10ml" variant or an accepted
  order's 10ml would draw 10 kyatthar. A per-variant template keeps the unit.
- **Admin.** For a weighed template the product form asks for stock, "Reorder at" and the
  purchase (cost + amount) in kyatthar, with a "100 kyatthar = 1 viss" hint, and one
  weight per variant. The decant form keeps its bottle words. The product list, low-stock
  panel and Accept warning print amounts through `StockUnit`, so decant still reads
  "30ml".
- **API.** No shape change. A weighed template's variants are served as options
  (`/meta` `variant_options`: `Weight`; `?option[Weight]=1 viss`), so the 38b option
  picker handles them with no storefront change. `types.ts` is unchanged.
- **Admin order edits re-freeze the amount.** Changing a line's size or pack in the admin
  (10ml → 30ml) stamps its new `measure` with its new label, so the draw-down takes 30.
  **Duplicate** copies a variant's `measure`, and no longer fails on the product list's
  `min_in_stock_price` alias (it was copied into the insert; Duplicate failed for every
  product before this).
- **Tests.** `WeightUnitsTest` (12): viss formatting, the unit and variant labels, draw-down
  by the frozen weight, ceiling cost, shortfall and low stock in viss, the guard (stock, ml
  sizes, and an untracked product already on an order), an admin line edit (ml and
  weight), Duplicate, the API and checkout, the admin form, and the migration round trip
  and refusal. 456 tests pass on SQLite (+1 Postgres-only skip) and 457 on Postgres 17.
  The parity test is unchanged and green.
- **Deploy (maintenance on, like 37a, 39 and 40a).** The first migration renames two
  columns the running code reads and writes. In the window before the new dynos serve, an
  old checkout would snapshot a null cost onto a real order, and the admin's product save
  would fail. So turn Heroku maintenance on before the `main` promotion, and off once the
  release is out. The API is unchanged, so the storefront has no deploy order.
  - **Rollback:** with maintenance on again (the serving code reads the new columns),
    run `php artisan migrate:rollback --step=2` **before** rolling back the code.
    `down()` refuses once a product is counted by weight or a weighed line exists.
    Those can't be expressed in the old schema, so there is no rollback past them short of
    removing those products.

## 0. What changed in v47

**v47** is step 40a (**#128**). Stock is counted the way the product's category counts it.
A decant shop sees nothing new. A clothing shop can now count pieces per size and colour
and record what each one costs.

- **Two stock modes, chosen by the template** (`Template::stockMode()`): `pooled` for
  decant (one running ml total per fragrance), `per_variant` for everything else by
  default (clothing). The mode is an explicit template choice, not derived from
  `measure()`: pet food sold by weight still counts bags.
- **Migration** `2026_09_30_000000_add_stock_modes`: `products.stock_ml` → `stock_amount`
  and `low_stock_threshold_ml` → `low_stock_threshold` (renames only), plus
  `product_variants.stock_qty` and `unit_cost_mmk` (nullable: untracked, unknown). A
  per-variant product's reorder line goes from the ml default 30 to 2 pieces; no variant
  was counted before, so no flag changes. `down()` refuses while a variant carries a count
  or a cost, and puts those reorder lines back to 30. Up → down → up on Postgres 17: products, variants and order-line money hashes
  identical except that reorder line.
- **Draw-down** (`Order::drawDownStock()`, on → Prepared): pooled by size × quantity per
  product, per variant by quantity. One transaction; every affected row is locked
  (`lockForUpdate`, products then variants, id order) before any is written, so two orders
  prepared at once can't lose an update to each other. Still warn-only: clamps at zero, never blocks.
- **Cost snapshot** (`OrderItem::currentUnitCost()`, the one rule the line snapshot and the
  admin order form share): pooled costs its share of the reference bottle (the ceiling
  rule, unchanged: 100,000 Ks / 30ml at 5ml is still 16,667); per variant reads the
  variant's `unit_cost_mmk`. Neither falls back to the other; unknown stays null.
- **Admin**: a clothing product's form counts ("In stock", pcs) and costs each option in
  its row, with one "Reorder at" (pcs, default 2). The decant form is unchanged. The
  product list shows pieces for per-variant products (red, with the low options in the
  tooltip). The low-stock panel lists both modes ("M / Blue: 1"), each product in its own
  mode, in the shop's word for a product ("Fragrance", as before). The Accept warning names
  the variant and drops the "ml" for pieces.
- **API**: unchanged. `stock_qty` and `unit_cost_mmk` stay admin-only.
- **Tests**: `StockModesTest` (11): per-variant draw-down, clamp, a mixed decant + clothing
  order, the per-variant cost snapshot (frozen, null when unknown; through the storefront
  checkout too), the pooled cost
  ignoring a stray variant cost, shortfalls, the low-stock panel in two shops, the admin
  form, the migration round trip on seeded rows in two shops, and (Postgres only) the
  `FOR UPDATE` locks. Parity: names only, no values. 445 tests pass on Postgres 17; on SQLite
  444 pass and the Postgres-only lock test skips.
- **Deploy (maintenance on, like 37a and 39).** The migration renames two columns the
  running code reads (`stock_ml`, `low_stock_threshold_ml`), and Heroku's release phase
  migrates while the old dynos still serve. In that window old code 500s on the products
  list and the low-stock panel. Worse, an order moved to Decanted there commits its status
  and then fails the draw-down, so its stock is never taken off. So turn Heroku
  maintenance on before the `main` promotion, and off once the release is out. The API is
  unchanged, so the storefront has no deploy order.
  - **Rollback:** run `php artisan migrate:rollback --step=1` **before** rolling back the
    code. `down()` refuses once a seller has counted or costed a variant. To roll back
    anyway, clear those first (`UPDATE product_variants SET stock_qty = NULL,
    unit_cost_mmk = NULL;`), which loses those counts and costs: order lines keep their
    cost snapshots.
  - **Recovery**, if an order was moved to Decanted mid-release: take its ml off the
    fragrance's Remaining by hand in the product form.
- **Not built**: Myanmar weight units (RUN-QUEUE row 8b); a per-variant count reaching
  zero doesn't hide the variant (the `in_stock` toggle stays manual, as for decant);
  "liquid only" margin wording for clothing; a lock on the order row itself (the same
  order saved to Prepared twice at once can still draw twice, as before step 40).

## 0. What changed in v46

**v46** is step 39 (**#126**). The order states are the same for every shop, and the shop's
category supplies their words. The state after "pending" is now `prepared`: a decant shop
still reads "Decanted", a clothing shop reads "Packed". No money value moves.

- **Migration** `2026_09_29_000000_rename_decanted_to_prepared_on_orders`: `orders.status`
  `decanted` → `prepared`, and `orders.decant_date` → `prep_date`. The index keeps its old
  name. `down()` reverses both. Up → down → up on Postgres 17 leaves the orders money and
  date hash identical.
- **One resolver**: `Templates::statusLabel()`. `OrderStatus::label()` calls it, so the
  admin badge, status select, order tabs, CSV, invoice and tracking API all use the shop's
  words. Templates override `preparedLabel()`, and the enum keeps a category-free
  `defaultLabel()`. With no tenant (a console command) the resolver returns the
  category-free word. Labels are memoised per shop id for the request, so an orders list
  doesn't read `shop_settings` once per badge.
- **Date label**: `Template::prepDateLabel()` ("Decant date" / "Packing date") names the
  date on the order form, accept modal, orders table, upcoming widget and CSV. A decant
  seller's admin reads the same words as before.
- **Tracking API** (additive, plus a rename): `status: "prepared"`, `status_labels` (every
  state in the shop's words), `prep_date`, and a `decant_date` deploy alias. The storefront
  timeline takes its third step's name from `status_labels.prepared`. It accepts both
  `"prepared"` and `"decanted"`, so either deploy order works. The alias and that tolerance
  are added to RUN-QUEUE row 32. The caption "Decanting {date}" is now "On the schedule for
  {date}".
- **Tests**: `StatusLabelTest` (6), covering two shops' labels, no-tenant fallback,
  tracking, invoice, orders tab and date column, and the migration round trip on seeded
  rows in two shops. The parity test changes names only, no values. 434 tests pass on
  SQLite and on Postgres 17.
- **Browser evidence**: `verify-clothing.mjs` gains a tracking check: a clothing order's
  timeline says "Packed", never "Decanted" (17 checks).
- **Deploy (maintenance on, like 37a).** The migration renames a column the running code
  reads, and Heroku's release phase migrates while the old dynos still serve. Old code
  would 500 on `decant_date`, and an order moved to Decanted in that window would store
  `decanted`, which the new enum can't load. So turn Heroku maintenance on before the
  `main` promotion, and off once the release is out. The API deploys first. An old
  storefront shows a `prepared` order's timeline unfilled until the storefront deploys.
  That affects words only: no money, and no broken page.
  - **Rollback:** run `php artisan migrate:rollback --step=1` **before** rolling back the
    code. A code-only rollback can't load any `prepared` row.
  - **Recovery**, if a `decanted` row was written mid-release anyway:
    `UPDATE orders SET status = 'prepared' WHERE status = 'decanted';`
- **Not built**: Burmese labels (no locale mechanism yet; row 15's presets), and the other
  decant words in the admin ("Today's Decants", "Decants due today", "Upcoming decants").

## 0. What changed in v45

**v45** is the second half of step 38 (**#107**): a clothing shop on the storefront. A
buyer picks Size, then Color, sees the colour's photo, reads the size guide and checks
out. A product may now have no brand. A decant shop's pages are unchanged.

- **Optional brand** (`brand: null` in the API contract). `Template::brandRequired()`
  (true by default, false for clothing) makes the admin brand field optional. The
  "sellable" rule — an active product whose brand, *if it has one*, is active — now
  lives in one place, `Product::scopeSellable()` / `isSellable()`. `/products`, `/meta`
  and checkout (`Order::currentVariantFor`, `unavailableItemMessage`) call it. Before
  this, all four used `whereHas('brand')`, which hid every brandless product and refused
  to sell it. The order snapshot of a brandless line is the bare name ("Linen Shirt").
- **Brand types are a decant concept**: `Template::brandTypes()` (decant only). `/meta`
  `brand_types` is empty for other templates. The storefront then shows no "Brand type"
  filter, Designer/Niche pill or Designer/Niche home tiles, and drops a stray
  `?brand_type=`. The admin hides the brand's type field, column and filters as well.
- **Size guide**: a clothing `size_guide` text attribute with the new `Attribute`
  `section: true` → `show: "section"`: a titled paragraph on the product page, line
  breaks kept. Per product, no migration. Not filterable, not searched.
- **Storefront**:
  - `OptionPicker` (Size then Color) for variants that aren't ml sizes. Colours follow
    the picked size. A sold-out value is struck through and can't be picked. A pick
    selects the closest in-stock variant. Decant keeps the ml `SizeSelector` untouched.
  - The picked variant's photo replaces the product photo. The image moved into
    `PurchasePanel`, with the same `ViewTransition` name.
  - The cart line carries the variant's label ("M / Blue"), its photo and a nullable
    `brandName`.
  - The filters render `variant_options` as `option[{name}]` groups. The Brand, Brand
    type and ml Size groups show only when they have something to pick.
  - Brandless products render with no brand pill (card, hero, product page, cart).
    `fullName()` gives "Brand Name" or just the name.
- **Demo clothing shop**: `DemoClothingShopSeeder` (not in `DatabaseSeeder`; run with
  `--class`; it refuses to run in production). It seeds "Thida Closet" at `clothing.decant.localhost:3001` with 3 products
  (brandless and branded), Size + Color variants, one sold-out combination and a size
  guide. It is idempotent and writes under the demo shop's own context.
- **`next.config.ts`**: `allowedDevOrigins: ["*.decant.localhost"]`. Under `next dev`,
  a second local shop's pages never hydrated. This setting has no effect in production.
- **Browser evidence**: `scripts/verify-clothing.mjs` (16 checks), added to `VERIFY.md`
  and `frontend/AGENTS.md`.
- **Tests**: `ClothingStorefrontTest` (10). It includes a two-shop test for a brandless
  product (not listed, 404, checkout refused) and the sold-out and inactive messages.
  428 tests pass on SQLite and on Postgres 17. The parity test is unchanged and green.
- **Deploy**: API first, as usual. The old storefront still reads `brand.name`, so a
  brandless product would break its cards. Only a clothing shop can have one, and no
  clothing shop is live, so the order is safe.

## 0. What changed in v44

**v44** is the first half of step 38 (**#107**): the clothing template. A clothing shop can
now be registered, stocked in the admin with Size + Color variants and a photo per colour,
and sold through the API by `variant_id`. 38b (RUN-QUEUE row 6b) puts it on the storefront.
A decant shop sees nothing new; the API only gains keys.

- **`ClothingTemplate`** (`app/Templates/`): material and "For" (women/men/unisex/kids)
  selects, both filterable; variant options Size + Color; a photo per variant. Status
  labels ("Packed" for `decanted`) and default modules are data until steps 39 and 41.
- **`Template::measure()`** — `'ml'` for decant, null for clothing. The ml size field,
  the Stock and Cost sections, the ml table columns and size filter, and the CSV import
  show only for an ml template. The CSV import also refuses a non-ml shop on the server.
- **Migration:** `product_variants.image_path` (nullable) and `product_variants.size_ml`
  nullable (it was still NOT NULL — a step-36 gap). Existing values untouched; up → down →
  up on Postgres 17 keeps identical hashes of every variant and order line. `down()`
  refuses while a variant without a size exists.
- **Admin product form:** a clothing product's variants are one text field per option,
  a price, in-stock, selling, and an optional photo (stored under `shops/{id}/variants/`).
  The same Size + Color twice is refused. Rows drag into order (`position`). Options are
  trimmed and kept in the template's order by `ProductVariant`, so the label always
  reads "M / Blue". Duplicating a product copies options and photos.
- **Admin order lines:** a clothing line picks its variant ("Option") instead of typing
  ml; picking one fills the price. Before this, saving any clothing order in the admin
  failed on the required ml size. Decant lines are unchanged.
- **One variant label everywhere:** the invoice, the production-schedule day sheet, the
  order list tooltip and CSV, upcoming decants and the Telegram alert print
  `OrderItem::variantLabel()` (the frozen `variant_label_snapshot`) instead of
  "{size_ml}ml" — the same text for decant. The schedule groups by it, so M / Blue and
  L / Red are separate lines, listed in the seller's variant order (S, M, L), not A–Z.
- **API (additive):** `prices[].options` and `prices[].image_url`; `/products`
  `option[{name}]=` (one in-stock variant must match every picked option, exact JSON-path
  equality); `/meta` `variant_options` (empty for decant); `/orders/track`
  `items[].variant_label`, which the receipt now shows. `types.ts` mirrors all of it, and
  `size_ml` is `number | null`.
- **Studio:** "Register a shop" picks the category (`Templates::assignToShop`, written
  under the new shop's context and restored after, like `NationalGeography::seed` — no
  new `withoutTenancy()`). Changing it later is not built.
- **Tests:** `ClothingTemplateTest` (16). The parity test's `pick()` now ignores added
  keys inside list elements too (the new variant keys); no expected value moved.
  418 tests pass on SQLite and on Postgres 17.
- **Deploy:** API first, as since 36b. The old storefront ignores the new keys.

## 0. What changed in v43

**v43** is the second half of step 37 (**#106**). The storefront renders from the template
instead of the perfume-only keys, so a clothing shop (step 38) gets its own filters and
product details from its template. (Page copy such as "Shop decants" is still decant's —
the template's words come later.) A decant shop sees the same pages, with four
small differences listed below.

- **Filters from `/meta` `filters`.** `FilterControls` renders each text filter as a box
  and each select filter as a pill row, labelled by the template ("Scent notes",
  "Gender"). The active-filter count and "Clear all" include the template's keys. The
  shop page sends `/products` only the core keys plus the template's filter keys, so it
  now awaits `/meta` (60s-cached) before `/products` instead of fetching them together.
  A secondary domain's 308 and the pagination links keep the whole query.
- **Product page and cards from `attributes`.** Each attribute gains `show`
  (`headline` / `pill` / `list`), set by the template: `Template::headline()` (decant:
  concentration) and a new `Attribute` `list` flag (decant: notes, vibes). The name
  carries the headline, the pill row carries headline + pills, and each list gets its
  own section. "You may also like" tops up by the template's first select filter the
  product has (decant: gender, as before); if that fails, the same-brand picks still show.
  `brand_type` is now a reserved attribute key (it is the storefront's URL name for `type`).
- **Visible decant differences:** the pill row follows template order (EDP · Male ·
  Around 8-10 Hours, was Around 8-10 Hours · Male · EDP); performance is no longer
  pine-toned and vibes are no longer soft-toned (one pill style for all attributes);
  the "Notes" heading reads "Scent notes"; the notes filter box has no example
  placeholder.
- The storefront reads none of the flat perfume keys any more (`concentration_label`,
  `gender`, `notes`, `/meta` `genders`…); they are marked deprecated in `types.ts` and go
  after go-live (RUN-QUEUE row 32). `ProductFilters` takes any template filter key.
- **Deploy:** API first, as since 36b. A storefront on a cached pre-37b product response
  (no `show`, up to 60s) shows every attribute as a plain pill without the headline.
  Nothing breaks.

## 0. What changed in v42

**v42** is the first half of step 37 (**#106**). Catalog attributes move into code-defined
templates. A decant shop sees nothing new in the admin, except that the attribute fields
share one "Details" section and the gender badge is gray. The storefront code is unchanged
and the API only gains keys, but search is wider: `q` also matches scent notes, and the
"Scent notes" box also matches brand and name. 37b, the next queue row, moves the
storefront onto the new keys.

- **Templates** (`app/Templates/`): `Template`, `Attribute` (select / text / number, with
  `required`, `filterable`, `searchable`, `translatable`), `DecantTemplate`, and the
  `Templates` registry. A template names its attributes, variant options, product nouns,
  status labels (read by step 39) and default modules (read by step 41). Templates live in
  code only; nothing about them can be edited in the database (P4).
- **Two migrations.**
  - The first adds `products.attributes` (jsonb), `products.search_text`,
    `products.template`, `products.category_id`, `shop_settings.template` (default
    `decant`) and a per-shop `categories` table. It backfills `attributes` and
    `search_text` from the five perfume columns.
  - The second drops `concentration`, `gender`, `notes`, `vibes` and `performance`. Its
    `down()` restores them, NOT NULL and the gender index included.
  - On Postgres 17, up → down → up keeps an identical hash of the five values (as
    columns, then as attributes) and of the order-line money.
- **Deploy (maintenance on, like 36b).** The second migration drops columns the running
  36b code reads, and Heroku's release phase migrates while the old dynos still serve.
  Turn maintenance on before the promotion and off once the release is out. To roll back,
  run `php artisan migrate:rollback --step=2` **before** rolling back the code; a
  code-only rollback leaves the columns gone. `/meta` may serve its cached pre-37 payload
  (without `filters`) for up to 10 minutes; nothing reads `filters` until 37b.
- **Product rules** (`Product::booted`):
  - A new product takes the shop's default template.
  - A template outside the shop's group is refused.
  - A category must be this shop's own.
  - `search_text` rebuilds when the name, brand, attributes or template change.
  - Renaming a brand rebuilds its products' `search_text` (`Brand::booted`).
- **API (additive).**
  - `/products` items gain `template` and `attributes`: `[{key, label, value, display}]`
    in template order, with empty attributes left out. The flat perfume keys are read from
    `attributes` and are kept until go-live (queue row 32).
  - `/meta` gains `filters`, the template's filterable attributes. `genders` and
    `concentrations` are unchanged.
  - `q` and the `notes` filter search `search_text` (lowercase in PHP, `%` and `_`
    literal), never a LIKE into jsonb. `gender` is an exact match on
    `attributes->gender`.
- **Admin.** Product form fields, table badge columns and filters come from the template.
  The "Fragrance(s)" label comes from the template's product nouns. CSV import reads the
  template's attribute columns, and the file format is unchanged.
- **Tests.**
  - New: `ProductTemplateTest` (14 tests).
  - New: a `categories` isolation test.
  - The suite's product fixtures now write `attributes`.
  - Parity changed fixtures only; every recorded value is the same.
  - 401 tests pass on SQLite and on Postgres 17.
  - Both migrations were also run up → down → up on SQLite, and the five values
    hash the same at every step.

## 0. What changed in v41

**v41** is the second half of step 36 (**#105**): the public contract catches up with the
products + variants schema. A decant shop and its customers see nothing new; the one
visible change is the address bar, which now says `/product/…`.

- **API.** `GET /products` and `/products/{slug}` (`ProductController`, `ProductResource`,
  `ProductVariantResource`). Each `prices[]` entry gains `id` (the variant id) and `label`
  (`"10ml"`). The key stays `prices`, so the change is additive and a pre-36b
  storefront keeps working against the new API.
- **Checkout by variant.** `POST /orders` and `/orders/validate-promo` take
  `items[].{variant_id, quantity}`. `Order::currentVariantFor()` is the one lookup: the
  variant must be this shop's, active and in stock, with an active product and brand.
  The price is still re-derived on the server.
- **Deploy window (until go-live).** The old `/fragrances` routes still answer (same
  controller), and the legacy `fragrance_id` + `size_ml` line still resolves to the same
  variant (`variant_id` wins if both are sent). A queue row removes both after go-live.
  This covers an old storefront on the new API only. The new storefront needs the new
  API, so **the API deploys first**. Vercel deploys on push and Heroku only after CI, so
  keep Heroku maintenance mode on from before the `main` promotion until the release is
  out and `/products` answers.
- **Storefront.** `types.ts`: `Fragrance → Product`, `DecantPrice → ProductVariant`,
  `CheckoutItem {variant_id, quantity}`. The detail route is `app/[host]/product/[slug]`,
  and `/fragrance/{slug}` 308-redirects to it (`next.config.ts` `redirects`, which run
  before the proxy, so the host is kept). Cart lines are keyed by variant. The cart
  storage key is now `v2`, so a cart saved before the deploy is not carried over.
- **Docs.** `api.md` (the new paths and payload, plus the two stale spots: the `/meta`
  `payment` block and the tracking receipt's payment fields), the READMEs, `AGENTS.md`
  rule 2 and the `decant-money` skill now name `variant_id`.
- **Tests.** The suite now checks out by `variant_id`. New tests cover the legacy pair,
  the `/fragrances` aliases, and another shop's variant id being refused at checkout and
  promo preview (`TenantIsolationTest`). Parity changed names only, plus the new `id` and
  `label` on each price row; every value is the same.

## 0. What changed in v40

**v40** is the first half of step 36 (**#105**): the catalog becomes products + variants
underneath, and a decant shop sees nothing new. The public API, the checkout payload and the
storefront are unchanged; that half (36b) is the next queue row.

- **Two migrations.** `fragrances → products`, `decant_prices → product_variants`
  (`fragrance_id → product_id`), `order_items.fragrance_id → product_id`. IDs are stable.
  - New `product_variants` columns: `options` (`{"Size":"10ml"}`) and `measure`, both
    backfilled from `size_ml`, plus `is_active` (true for every row) and `position`.
    `position` is 0 for every existing row, and ties sort by size. Numbering existing
    rows by size would have listed any size added later first, which the storefront
    shows as-is.
  - New `order_items` columns: `product_variant_id` (FK, **restrict**) and
    `variant_label_snapshot`. Backfill matches each line on shop + product + size; a
    size with no variant keeps a null id and a synthesized `"7ml"` label.
  - `order_items.size_ml`, `products.brand_id` and `brands.type` are now nullable.
    Deleting a brand that still has products is refused (owner review on #117;
    `products.brand_id` is `NO ACTION`): archive it with `is_active` instead. A brand with
    no products still deletes. A shop delete still cascades a brand with products (tested
    on SQLite and Postgres). `NO ACTION`, not `RESTRICT`: SQLite checks `RESTRICT`
    mid-cascade and would refuse the shop delete.
  - Postgres keeps constraint, index and sequence names through a rename, so the migration
    renames them to the new table and column names, and back on rollback.
  - The Shield permissions `{Ability}:Fragrance` are renamed to `{Ability}:Product` in
    place, so every role keeps its grants. If a `:Product` row already exists, the
    grants move onto it.
  - Money and snapshots are untouched. A hash of every order line's price, cost, quantity,
    size and name snapshot is identical before `up`, after `up`, after `down`, and after
    `up` again on Postgres 17.
- **Models.** `Product` (`variants()`, `activeVariants()`), `ProductVariant` (`label()`),
  and `OrderItem::product()`/`variant()`. Every new order line records its variant and
  label once, when it is created, through checkout, the admin form or a seeder.
- **Archived, never deleted.** An archived variant (`is_active` off) disappears from the
  catalog, the min price, the size filter and `/meta`. Checkout and the promo preview
  refuse it on the server. Placed orders still show it. The admin's size repeater only
  offers delete on unsaved rows, and a "Selling" toggle archives a size.
- **Admin.** `Resources/Fragrances/` is now `Resources/Products/`. The URL
  (`/admin/{shop}/fragrances`) and the "Fragrances" label are unchanged. Deleting a brand
  that has fragrances (one or in bulk) keeps it and tells the seller to deactivate it.
- **Fix: editing an order no longer re-snapshots its lines.** The order form's save used to
  rewrite every line's name snapshot on any edit, even an address fix. Renaming a
  product or brand would then have changed the name printed on old invoices. A line is now re-snapshotted
  only when the admin changes its product or size (rule 3).
- **Tests.** New `ProductVariantTest` (9) and `ProductMigrationTest` (the backfill on
  seeded rows of two shops). `RoleAuthorizationTest` gains a permission-rename test,
  including the case where the target row already exists. `AdminOrdersTest` gains a test
  that an order edit keeps its line snapshots.
  The suite is updated for the new names. The parity test changed class and relation names
  only, never a value. 376 tests.
- `phpunit.xml` raises the test `memory_limit` to 512M. The suite was already peaking at
  125 of PHP's 128 MB, and the new tests tipped it over.

## 0. What changed in v39

**v39** records the generic-shop refactor's parity baseline (**#104**, step 35). One new
test file. No migration, no API change, no app code, no dependency.

- **`tests/Feature/GenericShopParityTest.php`** freezes a decant shop at 2026-03-15 and
  asserts, as hand-worked literal Kyat: the `/fragrances` list and detail objects with
  `min_price_mmk` and every price, `/meta`'s filter options, sizes and price bounds, each
  order's server-derived money (items, discount, fee, total, deposit, liquid cost, signed
  balance), the dashboard's revenue, gross margin ("on 3 of 4"), order count and balance
  outstanding, and `MonthlyPnl` for March and February, every field. It also pins the
  `/fragrances` filters and sorts, the public tracking receipt's money, and the `stock_ml`
  draw-down when an order is decanted.
- The fixture covers each money rule: the ceiling cost division, a capped percent promo
  through `POST /orders`, a township fee, fully-, partially- and un-costed orders, a
  cancelled and a rejected order, an overpaid order, last month's order, expenses in every
  category across three months, and a second shop whose sale must move nothing.
- **Payments and couriers (#67/#109, owner review):** four more tests add January orders
  and run the panel's own actions with their defaults. They cover the Mark-paid default
  (online 55,000, COD 112,500), an online order marked paid and then settled with the fee
  the courier collected (balance 0, Paid, not in "Balance outstanding"), and a COD order
  settled short (12,500 left, still Unpaid). They also check "Cash with couriers" with two
  orders out (175,000) and then one (62,500), and "Balance outstanding" rising to 560,500.
  Every March and February figure above is unchanged.
- Steps 36–42 keep it green. A step may rename a field it deliberately renames, never a
  value. It can't guard a *data* migration (the fixture is built after migrations), so the
  step plan now asks each backfilling step to test its own migration.
- Checked by mutation: switching the cost rounding from ceiling to floor fails 4 of its 10
  tests.
- **Spec fix (step 40):** the plan's pooled-COGS formula `ceil(cost/amount) × measure`
  rounded per unit and would have moved money (16,667 → 16,670 Ks for a 5ml pour of a
  100,000 Ks / 30ml bottle). It now reads `ceil(cost × measure / amount)`, today's rule.

## 0. What changed in v38

**v38** fixes the letterhead bug (**#116**): the admin's printed documents named the
wrong business once a second shop existed. No migration, no API change, no dependency.

- **`pdf/invoice.blade.php`** prints the order's own shop name (`$order->shop->name`)
  instead of "Decant Please!". The view eager-loads `shop` once for the whole bulk PDF
  (`preventLazyLoading` is on), so a 50-order download is still one extra query.
- **`production-schedule-day.blade.php`** prints the current shop's name, read through a
  new `ProductionScheduleDay::getShopName()` from `TenantContext`. No fallback name: a
  missing tenant fails loudly instead of printing another business's name.
- **Tests**: `OrderInvoiceTest` renders single and bulk invoices across two shops and
  asserts each carries only its own shop's name; `ProductionScheduleTest` asserts the
  day sheet's letterhead is the current shop. Both fail on the old views.
- Not changed: the panels' brand names (`AdminPanelProvider` falls back to
  "Decant Please!" only on the tenant-less login page; the studio's brand is the
  platform's) and `telegram:test`'s "Decant Please! test message" (platform branding sent
  to each shop's own chat) — those move with the CornerArea rename (queue row 31, after
  the go-live).

## 0. What changed in v37

**v37** records the CornerArea scope in the repo (**#115**). Docs only — no code, no
migration, no dependency, and no brand rename in code (that is its own PR after the
go-live).

- **`AGENTS.md` P5 replaced**: "Features follow real sellers" → "Every category ships
  complete". CornerArea builds every planned category ahead of demand and releases a
  category only when its whole loop works. P6 question 1 now asks which category and which
  seller in it; §1 points to the roadmap.
- **New `prompts/43-cornerarea-roadmap.md`**: the owner's twelve scope decisions, 23
  categories in 4 groups (ship products, pre-order, food & drink, services & booking), the
  "complete" checklist, shared foundation, per-group needs, status labels, default modules,
  the design system, and the build order.
- **New `docs/adr/0005-storefront-design-config.md`**: per-shop design is a validated
  config the AI edits inside one section library (option B), not per-seller code or
  deployments (option A).
- **`PRODUCT.md`**: names the platform CornerArea (Decant Please is shop #1) and its four
  groups; fixes the stale "separate storefront deployment per shop domain" (ADR-0004 made
  it one); the end customer "buys from a shop"; non-goals clarify deep links, exclude
  per-seller code/deployments, and park licensed goods; open question 3 (theming) resolved,
  2 notes free-plan limits are undecided.
- **`prompts/35-generic-shop-plan.md` amended** from the catalog review: step 36 —
  nullable `order_items.size_ml`, `product_variants.is_active` + `position`,
  `order_items.product_variant_id` restricts delete, nullable `products.brand_id` /
  `brands.type`; step 37 — `products.template`, `shop_settings.template` (moved from 41),
  per-shop `categories` with its isolation test; step 38 — `product_variants.image_path`.
  The step-38 gate is now a review stop. "Brand as a concept" resolved: optional per
  template. Owner review: `products.brand_id` **restricts** delete (a brand with products
  is archived via `brands.is_active`; one without still deletes), and step 37 rebuilds
  `search_text` for a brand's products when the brand is renamed.
- The letterhead bug is filed as **#116** (not fixed here; queue row 2).

## 0.1 What changed in v36

**v36** makes the database refuse to delete a shop that holds money records (**#112**).
Touches money-record integrity: one migration, no code path, no dependency.

- **`orders.shop_id`, `order_items.shop_id` and `expenses.shop_id` are now `RESTRICT`**
  (were `CASCADE`). Before, deleting a `shops` row would have silently destroyed that
  shop's orders, order lines and expenses. That was safe only because nothing
  hard-deletes shops (archived is the soft delete). Now the database guarantees it: a
  shop with financial history can only be archived.
- **Catalog and config stay `CASCADE`** (brands, fragrances, decant_prices, promo_codes,
  shop_settings, delivery_*). They are regenerable configuration, not financial records,
  so a shop with no money history still deletes cleanly. `shop_user`/`shop_domains` stay
  cascade and `studio_audit_events` stays `SET NULL`.
- **Migration is a symmetric swap.** It uses the same constraint names in both
  directions, and `down()` reverts to cascade without deleting anything. Verified
  up→down→up on Postgres 17. `ShopDeleteProtectionTest` asserts the FK rule on all
  three tables, that a shop with an order or an expense is refused, and that a shop
  without either still deletes and cascades its catalog.
- **`backend/docs/schema.dbml`**: the three `Ref` lines are now `restrict`, and the
  "#112 follow-up" note now describes the guarantee. Every `Ref` was checked against the
  migrated Postgres.
- **Tooling (separate commit):** the unattended queue runner (`prompts/run-step.md`,
  `prompts/RUN-QUEUE.md`, `scripts/run-queue.sh`, the `pr-reviewer` agent and the
  project `.claude/settings.json` permission allowlist). No app code. Owner review
  tightened the deny list: `+refspec` force pushes, `…:refs/heads/main|develop` pushes,
  and `docker compose down`.

## 0.1 What changed in v35

**v35** drops the transitional `users.is_studio` column (**#110**): the `studio_admin`
role is the only source of truth for platform access. Touches personal-data access
authorization; one migration, no dependency.

- **`User::isStudioAdmin()` is the single reader** (P4). The three `HasTenants` methods
  already read the role; this PR routes the last two UI readers through it too — the
  `/admin` "Studio" menu link and `ShopResource::canAccess`. `$fillable`/`$casts` and the
  `ManageShops` owner-create no longer mention the column.
- **The drop migration is rollback-safe.** `up()` drops the column (index first, for
  SQLite); `down()` re-adds it and sets `is_studio = true` **from the `studio_admin`
  role**, never the reverse — a user whose role was removed at go-live is not re-granted
  access by a rollback. Verified up→down→up on Postgres 17.
- **Deploy-safe as one PR.** No runtime query filters on `is_studio`, and
  `preventAccessingMissingAttributes()` is off in production, so every old-code read during
  the release window is `(bool) null` → access **denied** (fail-closed), never a throw.
- **`Shop::suspend()` gained its authorization guard** — throws unless the actor
  `isStudioAdmin()`, before the reason check. The schema's "only a studio_admin can
  suspend" is now enforced in the one domain method, not left to a future UI.
- **Seeders/factory/tests** assign the role instead of the flag (`UserFactory::studio()`).
  The historical role-backfill test (which re-ran the now-uncallable `is_studio` query) is
  replaced by one asserting the rollback re-derivation.
- **`backend/docs/schema.dbml`** added — the whole schema as DBML, regenerated from the
  migrated Postgres. AGENTS.md now requires any migration PR to update it.
- **Deliberately not built:** locking suspended/archived members out of `/admin`, and
  restrict-delete FKs on the money tables — each its own follow-up issue.

## 0.1 What changed in v34

**v34** fixes **#67** — record the amount received at payment confirmation so balance due
is real (backend half; the storefront's signed/overpaid tolerance shipped in #70). Touches
money; no schema change, no dependency.

- **Mark paid captures "Amount received"** into `deposit_mmk`. The default is derived from
  the line snapshots, never `total_mmk`: online = `Σ line_total − discount`; COD adds the
  delivery fee. Editable in both directions (the pre-fill `max(existing, default)` is a
  convenience, not a floor); `markUnpaid()` leaves it.
- **`balanceDue()` is now one signed arithmetic**, shared verbatim by the model, the A5
  invoice, the order-form preview, and the mark-paid default via
  `Order::balanceDueFrom(items, discount, fee, deposit)`. Negative = overpaid (the invoice
  prints "Overpaid by"; the tracking receipt carries a signed `balance_due_mmk`). Computed
  from line snapshots, so it never inherits `total_mmk`'s clamp; `total_mmk` /
  `recalculateTotal()` are untouched (revenue reads them).
- **The order form rejects a discount that exceeds the item subtotal** (re-checked on save
  against the live lines), closing the only writer that could — the promo path already caps.
- **Dashboard "Balance outstanding"** replaces the payment-status-based "Unpaid orders":
  Σ positive per-order balances (item subtotal via a portable, shop-scoped `withSum`) over
  orders in play; overpaid and cancelled/rejected excluded, never netted.
- **A settled courier delivery now credits what it collected** (found in review — a gap in
  #67's spec the balance-based stat exposed). `settleCourier()` takes the amount collected
  from the customer — a "Collected from customer" field on the Settle action, defaulting to
  the carried balance, editable, `0` for a failed delivery — and adds it to `deposit_mmk`, so
  a delivered order's fee (online) or full amount (COD) stops standing as a perpetual balance
  and inflating "Balance outstanding". A clearing settle marks an Unpaid order paid (the
  courier's cash is the payment); a partial leaves it Unpaid; an over-collection reads as
  overpaid. A repeat settle throws (a domain guard, like the handoff guard), so a
  double-submit can't double-credit.
- **Reconciled to the current repo** (the issue predates tenancy and #71/#75): the stat is
  shop-scoped and pinned in `TenantIsolationTest`; test fixtures that faked `total_mmk`
  without items now carry a real line, since `balanceDue()` is item-based.
- **Suite 347 tests / 1,421 assertions** — new `PaymentReceivedTest` (capture defaults,
  downward correction, prefill guard, discount rule, signed balance, invoice overpaid,
  outstanding stat, signed receipt, and the courier-settle credit: online/COD clear,
  failed-delivery no-op, partial remainder, over-collection, double-settle guard) plus a
  tenancy case. Postgres-portability green.

## 0.1 What changed in v33

**v33** is a **docs-only** foundation for the generic-shop refactor — Decant becomes the
first *template*, not the whole product. No code, no migrations, no dependency change.

- **Principles charter (P1–P6)** added to the top of `AGENTS.md` — enterprise-grade
  reliability, small-shop simplicity. Every spec/issue/PR is now judged against it, and
  **P6 is a PR checklist** authors answer before building.
- **Guidance reframed generic.** `AGENTS.md §1` **and** `PRODUCT.md` now describe a
  multi-category platform for Myanmar social sellers (perfume decants, clothing, bakery,
  cosmetics) with Decant as the first template. Every tenancy/money invariant is unchanged;
  §1 also corrects the record — multi-tenancy is **already built** (steps 23–25, 32–34),
  not planned.
- **Six live rules lifted** out of these version notes into `AGENTS.md`'s durable
  sections, so they load every turn instead of living only in history: private data has no
  public/presigned URL, and panel routes go through `authenticatedRoutes()` (§4);
  explicit listeners / discovery off, `whereDate` windows + bare `Y-m-d` on the wire, and
  `phpunit.xml` Telegram blanked (§8).
- **Plan of record:** `prompts/35-generic-shop-plan.md` (steps **35–42** — 30–34 are
  already shipped, so the range moved up) + a `prompts/README.md` step-table entry. Baked-in
  sequencing: **#67 (correct money) runs before the parity baseline (35)**, a **gate after
  step 38** for a real-seller trial before 39–41, and **#40/#41 parked under P5**.
- History stays in `CHANGELOG.md` (no `HISTORY.md`) — the §0 history was already
  externalized here (ADR-0001), which is exactly the on-demand outcome the refactor prompt
  asked for.

## 0.1 What changed in v32

**v32** is Step 34 **PR-3: the Studio registry redesign + shop detail page + sidebar
groups** — the last of the three Step-34 PRs. Studio design tokens / the pine-ramp
theme are deferred to a separate PR-3b. No migrations, no dependencies, no frontend,
and no tenancy/Shield/PR-1/PR-2 change.

- **Registry, redesigned.** The `Active` check icon becomes a **ShopStatus pill**
  (Live/Onboarding/Suspended/Archived — a reason-carrying, colour-blind-safe state).
  New columns: **Owner** (the member holding `shop_owner`, deterministic by id — `—`
  when none), **Last activity** (last order), **Orders this month**. Rows are
  **navigable** to a new detail page; `Domains` + `Open panel` move into an overflow
  action group. **Filters:** a multi-select **status** filter defaulting to the live
  states (archived hidden by default, revealable), and a **needs-attention** filter.
  A real **zero state** ("Register your first shop") replaces the empty table.
- **Needs attention (Option A).** `onboarding OR suspended OR payment not configured`;
  archived is never flagged. "Payment configured" = at least one payment field
  resolves through **ShopConfig** (the `/meta` payment rule); Telegram, social, and a
  verified domain are shown but optional (blank is a valid "off", §33). No
  order-review / catalog / domain attention rules were invented.
- **Shop detail page (`ViewShop`).** Read-only: owner + email, configuration status
  (payment / Telegram / social / verified domain — each via its established rule:
  `ShopConfig::forShop`, `TelegramNotifier::isConfiguredForShop`, `domains`), activity
  counts, and this shop's recent impersonation-audit entries. Config resolution goes
  through the **blessed `ShopConfig::forShop` set-context** (no `withoutTenancy`, no
  duplicated resolution). Studio-only (owners get 403).
- **Sidebar groups.** **Registry** (Shops) and **Operations** (Audit log) — the two
  that have pages. No Platform/Stats/support-search placeholders (§6 non-goals).
- **One budgeted cross-shop read.** Owner resolves through platform tables; per-shop
  order figures are the single metered `TenantContext::withoutTenancy()` in
  `StudioShopStats` (design §8 ledger: "the studio's cross-shop views"). It is
  correlated by `shop_id` (GROUP BY), so no shop's rows enter another's totals, and it
  uses portable `SUM(CASE …)`. The app's `->withoutTenancy(` count is now **2** — well
  within the ≤5 ledger cap, which a test in `StudioRegistryTest` (and the existing
  `TenantIsolationTest`) pins.
- **Suite 313 → 324 / 1,337 assertions.** New `StudioRegistryTest` (11): status pill +
  owner + orders columns, deterministic/absent owner, per-shop aggregate (no leak),
  last activity, archived-hidden-by-default + reveal, needs-attention, zero state,
  detail-page completeness, studio-only access, and the withoutTenancy budget. All
  prior tenancy-isolation, lifecycle, role, and impersonation-audit tests stay green.
- **Deferred (PR-3b):** the pine-ramp Studio theme / `design-tokens.json` work.

## 0.1 What changed in v31

**v31** is Step 34 **PR-2: impersonation audit** — the accountability layer over the
cross-shop access PR-1 formalised. It builds on PR-1's Shield roles and changes no
tenancy boundary. PR-3 (registry redesign, Studio tokens, sidebar) is still deferred.

- **Impersonation = a studio operator in a foreign shop's panel.** A `studio_admin`
  has no shop membership, so any shop whose `/admin` panel they open is someone else's
  — that is the impersonation, and "Open panel" hands them its customers, transfer
  slips, expenses, and P&L. Detection: `studio_admin` + `TenantContext` set + not a
  member, **and** an actual panel entry (session-recorded on Filament's `TenantSet`) —
  so the public API, console, and test data-setup, which never enter a panel, are
  never mislabelled and write freely.
- **`studio_audit_events`** — a **platform-owned, append-only** table (no
  `BelongsToShop`, like `shops`/`users`): actor, shop, action, morph subject, IP, user
  agent, metadata, `created_at` only. Read cross-shop on `/studio` with no
  `withoutTenancy()`.
- **Panel entry logs one `panel.enter`,** deduped per entry/switch (a repeat in the
  same shop doesn't re-log; a switch does). A normal owner in their own shop logs
  nothing.
- **Read-only by default, enforced at the model layer.** A single Eloquent
  saving/deleting guard refuses tenant-owned writes while impersonating read-only —
  deliberately at the model layer because custom Filament actions (order accept/reject,
  mark paid, ManagePayment save, bulk fee/cost) mutate without a CRUD ability a
  Gate/policy hook would see. Refusal is a `ReadOnlyImpersonationException` (a Filament
  `Halt` subclass) after a notification — validated to cancel the write and surface as
  a notification, never a 500, even inside a custom `->action()` closure.
- **Take control is explicit and audited.** An `/admin` banner (rendered only while
  impersonating) names the shop and read-only/controlling state; "Take control" logs
  one `take_control` and lifts read-only **for the current shop only** — it resets to
  read-only on a shop switch by construction (a single session key holds the controlled
  shop). Each controlled write logs one `write.{created,updated,deleted}` **per
  persisted row** (a 5-row bulk edit → 5 events), each naming its subject.
- **Guardrail, not a tenant boundary — Rule 0 intact.** `studio_admin` stays Shield
  super_admin; the `TakeControl` permission is a nominal Shield marker (super_admin
  bypasses it). A controlled write still executes in the current `TenantContext` and is
  stamped `shop_id` by `BelongsToShop` = the shop being viewed. A test proves a write
  while controlling shop X lands in X, never Y; another proves super-admin
  impersonation does **not** widen the `BelongsToShop` row set. No `withoutTenancy`, no
  `scopeToTenant`, no teams, no membership bypass. `TenantContext`, `BelongsToShop`,
  `ResolveTenant`, `shop_user`, `ShopDomain`/CORS, ADR-0004, and the frontend are
  untouched; `is_studio` is left transitional.
- **Studio audit page** — a read-only `StudioAuditEventResource` on `/studio` only
  (filterable by shop/action), gated by `canAccess(studio_admin)` + the studio-panel
  gate; owners/staff can't reach it and it has no `/admin` route.
- **Suite 295 → 313 / 1,303 assertions.** New `ImpersonationAuditTest` (18): entry
  dedup + switch + owner-zero, read-only blocks create/update/delete, Filament-action
  notification (not 500), take-control enable/log/scope/reset, one write event per row,
  full field capture, isolation-not-widened, controlled-write-stays-in-shop, and audit
  access control. Existing tenant-isolation tests stay green.
- **Deliberately out of scope (PR-3):** the registry-table redesign, the shop detail
  page, the pine-ramp Studio theme, and sidebar grouping.

## 0.1 What changed in v30

**v30** starts Step 34 (studio operability) with **PR-1: shop lifecycle + Shield
roles**. Impersonation/audit (PR-2) and the registry redesign/tokens/sidebar (PR-3)
are deferred. Two prerequisites (Steps 32, 33) were already merged.

- **Shop lifecycle replaces `is_active`.** A `ShopStatus` enum
  (`onboarding → live → suspended → archived`) plus `suspended_reason` /
  `suspended_at` / `suspended_by`. `scopeActive()` now means `status = live` — the
  one seam every `->active()` caller goes through, so `ResolveTenant` and the
  storefront host resolver serve only live shops (the rest keep the existing
  generic 404) with a one-line change. `is_active` is dropped as a column and kept
  as a **derived read-only accessor** (`status === live`), so there is no second
  writable state. `suspend()` requires a reason + actor; `activate()`/`archive()`
  are the other transitions. Backfill (confirmed against the data, issue #96):
  `is_active true → live`, `false → onboarding` — the repo does not prove `false`
  meant "suspended" (PRODUCT.md open-Q2), and `onboarding` is the lossless
  non-serving default; every observed shop was `true → live`.
- **Authorization is Filament Shield 4.x** (`spatie/laravel-permission`, **non-team**;
  ADR-0003's Step-34 amendment). Global roles `studio_admin` (= Shield super_admin,
  platform-wide via a `Gate::before` bypass — `define_via_gate` on), `shop_owner`
  (full control of its shop's resources), `shop_staff` (a deliberately narrow read
  set). Shield answers *"may this user perform this action?"* through generated,
  authorization-only model policies (verified: no query scoping); the tenancy seam
  (`BelongsToShop`, `shop_user` membership, `canAccessTenant`, `ResolveTenant`) still
  answers *"which shop, and which records"* — **untouched**. No Spatie teams, no
  `scopeToTenant()`, no `users.role`/`shop_user.role`.
- **`is_studio` is transitional.** Its three readers (`canAccessPanel`, `getTenants`,
  `canAccessTenant`) now use `hasRole('studio_admin')`; the column stays this release
  (dropping it is a later follow-up). Shield's role UI is on **`/studio` only**; the
  `/admin` panel gets no Shield UI but the generated policies still enforce there.
- **Deploy-safe, no lockout.** Roles are created and backfilled
  (`is_studio → studio_admin`, membership owners → `shop_owner`) in a **data
  migration** — atomic with Heroku's release-phase `migrate`, so policies never
  enforce before `studio_admin` exists. Idempotent; guard aligned to `web`. The
  studio-operator seed + new owner registrations assign their roles too.
- **Suite 273 → 295 / 1,261 assertions.** New `ShopLifecycleTest` and
  `RoleAuthorizationTest` (studio_admin super_admin, shop_owner CRUD, shop_staff
  narrow, `/studio` denied to non-studio_admin, super_admin authorization not
  widening the `BelongsToShop` row set, membership still gating tenant access, the
  migration backfill's idempotency + no-lockout). Existing panel tests keep passing
  under deny-by-default because studio operators are super_admin; `TestCase` forgets
  the spatie cache each test (a RefreshDatabase interaction) and gives its operator
  the `studio_admin` role.
- **Deliberately out of scope (PR-2/PR-3):** `studio_audit_events`/impersonation, the
  registry-table redesign (status pills, owner/activity columns, filters, detail
  page), the pine-ramp Studio theme, sidebar grouping, and a dedicated suspended
  "temporarily closed" storefront page.

## 0.1 What changed in v29

**v29** runs Step 33 — per-shop configuration. Two config groups were still
process-global env (correct for one decanter, wrong for many); the audit found the
other two (payment, storefront origin) already per-shop from the seam + ADR-0004, so
this step is scoped to Telegram + social and centralises resolution.

- **One resolver, `App\Support\ShopConfig`** — every value resolves **shop settings
  row → platform env default → off**, replacing scattered inline `?: config(...)`.
  `get()` reads the current tenant; `forShop($shop, …)` reads an explicit shop by
  switching the tenant context around the read (the blessed *set-the-context*
  pattern via `TenantContext`'s public API — **not** a `withoutTenancy()` bypass, so
  the ≤5 budget is untouched, still 1). Payment `/meta` resolution now routes through
  it with **identical** behaviour.
- **`shop_settings` gains `bot_token`, `admin_chat_id` (both `encrypted` cast, TEXT
  columns), `tiktok_url`, `facebook_url`.** No backfill — env stays the platform
  default, so existing deployments keep working with these blank. The table was
  already per-shop (seam migration's `shop_id` + `unique`).
- **Telegram is per shop.** `NotifyAdminOfNewOrder` / `NotifyAdminOfPaymentProof`
  resolve the notifier from **`$event->order->shop`** (eager-loaded — lazy-loading is
  guarded), so each shop's alerts use its own bot token + chat id, or the shared
  platform bot when its token is blank; unconfigured at both levels is a no-op. The
  5s bound + swallow-all rules are unchanged, and a broken token in one shop can't
  touch another's checkout. The alert's admin link is now **tenant-aware**:
  `/admin/{shop}/orders/{id}/edit`.
- **`telegram:test {shop}`** now requires a shop slug and tests that shop's resolved
  config. **`/meta` social** reads the shop row (env fallback preserved).
- **`ManagePayment` gains "Telegram order alerts" + "Social links" sections** — it
  already edits the tenant's `ShopSetting` row. The bot token is a revealable
  password field that is **not** echoed back and is **preserved when left blank**
  (dehydrated only when filled); the chat id and socials prefill and save normally.
- **Stale "singleton" docblocks corrected** on `ShopSetting` and `ManagePayment` (the
  row has been per-shop since the seam; only the comments lagged). Behaviour
  unchanged elsewhere.
- **Suite 258 → 273 / 1,210 assertions.** New `PerShopConfigTest` (per-shop
  resolution, meta social/payment isolation + env fallback, Telegram A→A / B→B, the
  alert following the order's shop over the ambient context, platform-bot fallback,
  unconfigured no-op, tenant-aware URL, `telegram:test`); `TenantIsolationTest`
  gains per-shop settings + encrypted-at-rest coverage; `PaymentMethodTest` gains the
  ManagePayment save/read + blank-token-preserves case; `TelegramAlertTest` updated
  for the `{shop}` argument and tenant-aware URL. **Deliberately untouched:** the
  tenancy seam, ADR-0004 host routing / CORS / `shop_domains`, existing
  `shop_id`/`unique`/`BelongsToShop`/payment-isolation work.

## 0.1 What changed in v28

**v28** completes ADR-0004: **PR-B, the storefront cutover** (#92). One Next.js
deployment now serves every shop on its own domain — custom domains and platform
subdomains through the same mechanism — with the tenant slug never appearing in a
public URL. The N-Vercel-projects model is decommissioned in `DEPLOY.md`.

- **`src/proxy.ts`** — the deterministic Host → internal `/{host}/…` rewrite, and
  nothing else: no I/O, no authorization (the backend scope stays the boundary), a
  static matcher that excludes `_next`/the icon but deliberately covers `robots.txt`
  and `sitemap.xml`. The internal segment exists so every rendered page's cache key
  carries the tenant **by construction** — the step-32 unkeyed-cache lesson applied
  to the route cache. Found the hard way and kept as comments: the `[host]` param can
  arrive percent-encoded in layouts, so the rewrite uses the raw host and the
  resolver decodes defensively.
- **The route tree moved under `app/[host]/`** (public paths unchanged). The root
  layout is a bare shell plus an unbranded fail-closed 404 (no tenant to brand, and
  it must reveal nothing); the tenant layout resolves the host through PR-A's
  endpoint — `React.cache()` per render, 60s data cache across renders, null → 404,
  **no default-shop fallback** — and provides `{slug, name}` to the tree. Per-page
  `tenantPage(host, ownPath)` 308s secondary domains to the verified primary with
  the path and query preserved (a layout can't know the path; the proxy must stay
  I/O-free — so the page, which statically knows its own path, owns the redirect).
- **`lib/api.ts` is tenant-parameterized**: every helper takes the resolved slug —
  server callers thread it, client components read `useTenant()` from the
  server-provided context. `NEXT_PUBLIC_SHOP_SLUG` and `NEXT_PUBLIC_SITE_URL` are
  retired; metadata (metadataBase, canonical, OG, the shop's own name in titles,
  navbar, footer, printed receipts) derives from the shop row and its verified
  primary domain. Per-tenant `robots.txt` and `sitemap.xml` are route handlers under
  `[host]` (the file conventions are host-blind), each domain referencing only its
  own URLs.
- **Performance held, measured not assumed.** The Next docs' rule: without
  `generateStaticParams`, unlisted dynamic params are fully dynamic — so home, PDP,
  and checkout export an **empty** `generateStaticParams`, the documented opt-in to
  on-demand ISR. A production-build run shows `MISS MISS HIT HIT` with correct
  per-tenant content on the cached hits; `/shop`, track, and order-complete keep
  their dynamic/searchParams behavior, checkout/track fetches stay `no-store`. Only
  the proxy plus one 60s-cached resolve run per request beyond what ran before.
- **`verify-tenant-hosts.mjs`** — the mandatory evidence, 14 checks against a
  production build with explicit Host headers: each host serves its own storefront;
  the same pathname under two hosts in both orders (twice) never leaks; repeats come
  from the route cache per-tenant; unknown hosts 404 unbranded; secondary → 308 →
  primary with query, loop-free; per-host robots and sitemaps. Fixtures (a second
  `verify-b` shop on `verify-b.decant.localhost:3001` + a same-slug probe fragrance
  in both shops) are idempotent and documented in the script header.
- **Suite truth-ups while proving the above:** `verify-responsive.mjs`'s checkout
  matcher still said "Delivery address" — step 30 renamed the label to "Address", so
  the check had been dead since then (proven identical on clean `develop`); fixed.
  The 375 sticky-add-to-cart check fails on `develop` too — data-dependent since the
  step-32 session's `fresh-start` left the dev demo catalog too short to scroll;
  recorded in `VERIFY.md`'s known-red baseline, not chased. Dev delivery zones
  reseeded (`DeliveryZoneSeeder` is idempotent; full `db:seed` is not — CatalogSeeder
  is empty-DB-only). Backend suite untouched: 258 / 1,158; typecheck clean; lint
  still exactly the four pre-existing errors.

## 0.1 What changed in v27

**v27** starts ADR-0004 — storefront addressing, accepted as amended (#88/#89): one
shared storefront deployment, every tenant on its own public domain, the validated
`Host` resolved through a platform table and rewritten internally to a tenant-keyed
route. This version ships **PR-A, the backend seam** (#90); the frontend cutover
(proxy, tenant routes, per-tenant SEO) is PR-B and has not landed — nothing consumes
the new endpoint yet.

- **`shop_domains`** — the durable host → shop mapping. Platform-owned like `shops`
  itself (no `BelongsToShop`: this table is what *produces* the tenant), normalized
  lowercase hosts (scheme/path/trailing-dot stripped, port kept — dev is
  `localhost:3001`), globally unique, a `verified_at` gate, and at most one primary
  per shop enforced by a partial unique index (valid on Postgres 17 **and** the
  SQLite the suite runs on). `www.x.com` is its own explicit row — normalization
  never guesses aliases.
- **`GET /api/v1/_storefront/host/{host}`** — the storefront's tenant handshake
  (slug, shop name, requested + primary host). Registered before and outside the
  `{shop}` group; its `host-resolve` bucket is IP-keyed, deliberately — the per-shop
  limiter key would throw here, no tenant exists yet. Unknown, unverified, and
  inactive-shop hosts are one byte-identical generic 404 (the test pins the
  production body — debug bodies differ by caller trace and can never be identical).
- **CORS is dynamic now, at one seam.** `HandleCors` re-reads `config('cors')`
  inside `handle()` on every request, so `MergeShopDomainCorsOrigins` (prepended in
  `bootstrap/app.php`) merges every verified-and-active shop domain — 60s cache,
  busted by the model's write hooks — over the env `FRONTEND_URL` platform defaults,
  and only on `cors.paths` requests (a DB-less health check never touches the
  table). Verified working under `php artisan config:cache` against the running
  Postgres stack. A dead end recorded so nobody repeats it: rebinding `CorsService`
  does nothing — the framework never binds it, and the middleware overwrites its
  options from config each request.
- **The Studio manages domains.** A "Domains" action on the shop registry
  (replace-set modal), with the rules in `Shop::syncDomains()`: exactly one primary
  (promoted when none is flagged), verification stamps preserved on re-saves,
  per-model deletes so the CORS cache busts fire, and a cross-shop host grab mapped
  to a validation error. The registry shows each shop's primary domain, and "View on
  site" now prefers the shop's verified primary domain over the `FRONTEND_URL`
  fallback.
- The seeder maps `localhost:3001 → decant-please` (verified, primary), so local dev
  and every existing verify script resolve with no env var. Suite: **258 tests /
  1,158 assertions** — `StorefrontHostResolutionTest` adds 22; `TenantIsolationTest`
  stays at 40 with its `withoutTenancy()` budget untouched (the new table is
  unscoped by design). Frontend `types.ts` mirrors the new Resource
  (`StorefrontHost`); typecheck green — the 4 `react-hooks` lint errors pre-exist on
  `develop`, untouched here. `backend/AGENTS.md`'s portability-script example gains
  the `{shop}` segment its own base requires (running it without one produces seven
  phantom FAILs).

## 0.1 What changed in v26

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
- **The kit's doc payload is merged and the carrier deleted** (kit session 4, riding
  this branch). `CLAUDE-md-v21-section.md` — placed at repo root by #84, drafted
  pre-v24 against the monolithic `CLAUDE.md` — is folded into today's split per the
  kit's own retargeting note: the conventions block → `AGENTS.md` §8 (the scoping
  decision plus a new "tenancy reaches past queries" bullet), the domain fact it added
  → `PRODUCT.md` RULES (per-shop natural keys named explicitly), the version note →
  this entry; `PRODUCT.md`'s NON-GOALS already carried the v23 marketplace split, so
  the §8 amendment had nothing left to do there. Root `README.md` reconciled in the
  same pass: `{shop}` named in the API table itself, the stray `/api/v1/meta`,
  236-test lines, `--shop` on fresh-start, the panels-as-second-register design note,
  a layout row pointing at `PRODUCT.md`/`AGENTS.md`, and an out-of-scope list that now
  excludes the *marketplace* rather than multi-tenancy. Draft claims describing
  unshipped work were dropped, not merged: the `shops` status enum, impersonation
  audit, and registry columns stay step 34's; the config resolver stays step 33's; the
  panels' palette is stock Filament amber/emerald, not a pine-derived ramp.
  `docs/adr/0004-storefront-addressing.md` stays **Proposed** — the session-5
  pressure-test hasn't run.

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

