# RUN-QUEUE — CornerArea build run

Source of truth for the unattended run (`prompts/run-step.md`). The run does one item per
session, in order. Each item's PR is stacked on the previous item's branch.

Statuses:
- `todo`
- `in-progress`
- `pr-open`
- `needs-owner`: skipped until the owner decides
- `after-go-live`: skipped until the owner flips it to `todo`
- `blocked`: the whole run stops

Owner: to request changes on a PR, leave review comments and add the label `fix`. The next
run handles them first. Merge stack PRs bottom-up with a merge commit, not a squash.

| # | Item | Spec | Status | Branch | PR |
|---|---|---|---|---|---|
| 0 | Commit the runner files; #112: money-table FKs cascade → restrict (orders, order_items, expenses `shop_id`) | issue #112 | pr-open | 112-money-fk-restrict | #114 |
| 1 | Record the CornerArea scope | `prompts/queue-01-scope-docs.md` | pr-open | 115-cornerarea-scope-docs | #117 |
| 2 | Invoice and print letterheads use the shop's name, not "Decant Please!" | roadmap: shared foundation (letterhead bug); issue #116 already filed, don't file another | pr-open | 116-shop-name-letterheads | #118 |
| 3 | Step 35: baseline parity test | `35-generic-shop-plan.md` §35 (stack from here, not from the #67 branch; #67 is merged) | pr-open | 104-parity-baseline | #119 |
| 4 | Step 36a: product + variant schema, models, admin (API contract unchanged) | §36, as amended by item 1 | pr-open | 105-product-variant-model | #120 |
| 4b | Step 36b: `/products` API, checkout by `variant_id`, storefront `/product/[slug]` + redirect, `types.ts`, `api.md` | §36 "As built" (split from row 4); issue #105, don't file another | pr-open | 105-products-api | #121 |
| 5 | Step 37: templates, attributes, `products.template`, per-shop categories | §37, as amended | pr-open | 106-templates-attributes | #122 |
| 5b | Step 37b: storefront renders from `/meta` `filters` and product `attributes` (FilterControls, product page, cards) | §37 "As built" (split from row 5); issue #106, don't file another | pr-open | 106-storefront-attributes | #123 |
| 6 | Step 38a: clothing template, variant options + photos in the admin, option filters (API additive) | §38, as amended | pr-open | 107-clothing-template | #124 |
| 6b | Step 38b: storefront option picker + variant photo, option filters UI, optional brand in the API contract, size guide, demo clothing seeder | §38 "As built" (split from row 6); issue #107, don't file another | pr-open | 107-storefront-options | #125 |
| 7 | Step 39: template status labels (`decanted` → `prepared`) | §39 | pr-open | 126-status-labels | #127 |
| 8 | Step 40: stock modes (pooled / per variant), Myanmar weight units | §40 + roadmap group 1 | pr-open | 128-stock-modes | #129 |
| 8b | Step 40b: Myanmar weight units — pooled stock by weight (kyatthar / viss, 1 viss = 100 kyatthar): `products.stock_unit`, a frozen per-line amount on `order_items` (not `size_ml`), weight-measured variants in the admin, the reference-cost pair renamed off `_ml`, and a guard on switching a pooled product between units; tested with a test-only weight template (the produce template itself is row 15) | §40 "As built" (split from row 8); issue #128, don't file another | pr-open | 128-weight-units | #130 |
| 9 | Step 41: module toggles, template default modules | §41 + roadmap "default modules" | pr-open | 131-module-toggles | #132 |
| 10 | Step 42: design-spec sync (docs) | §42 | pr-open | 133-design-spec-sync | #135 |
| 10b | Fix #134 (found in step 42): the tracking receipt's item name from `fragrance_name_snapshot`, not the live product (snapshot rule); `decant:fresh-start` deletes variant photos; the payment-proof endpoint's status rule (check it, or correct the comment); generic wording for the "fragrance" error strings | issue #134, don't file another | pr-open | 134-snapshot-receipt-fixes | #136 |
| 11 | Step 44a: shop registration foundation — one `ShopRegistration::register()` (Studio + sign-up), reserved slugs, automatic `slug.cornerarea.me` address, `Shop::publish()` | `prompts/44-self-serve-signup.md` §44a (roadmap: shared foundation). The wildcard DNS/Vercel attach is an owner step: document it, don't do it | pr-open | 137-self-serve-signup | #138 |
| 11b | Step 44b: seller sign-up in the admin panel (account + create-your-shop), phone verification (sender interface, fails closed when unconfigured), Publish button, English + Burmese | §44b (split from row 11); issue #137, don't file another | pr-open | 137-seller-signup | #139 |
| 11c | Phone-code provider driver for sign-up (a `CodeSender` for the owner's chosen SMS / Telegram Gateway account) plus a platform-wide send cap (SMS pumping); production sign-up stays off until it exists | §44b "As built"; the provider and its account are the owner's decision | needs-owner | | |
| 12 | Help button (admin → studio on Viber/Telegram) | roadmap: shared foundation; spec `prompts/45-help-button.md` (issue #140) | pr-open | 140-help-button | #141 |
| 13 | Design system: section library, 3 base designs, `shop_designs`, preset picker, manual editor (no AI) | roadmap: design system; spec `prompts/46-design-system.md` (issue #142) | pr-open | 142-design-system | #143 |
| 13b | Step 46b: the storefront renders the published design — design in the API + `types.ts`, theme as CSS variables on a wrapper (not `@theme`), home page from the section list, decant Clean parity with today's home, Design page joins the menu | `prompts/46-design-system.md` §Split (split from row 13); issue #142, don't file another | pr-open | 142-storefront-design | #144 |
| 13c | Step 46c: manual design editor — colours (contrast rule in words), font, section order + on/off, section text, decoration image upload to `shops/{id}/design/`; save = new row, publish = live | `prompts/46-design-system.md` §Split (split from row 13); issue #142, don't file another | todo | | |
| 14 | AI design editor + per-shop quota | roadmap: design system. API key from env (owner step); tests mock the API | todo | | |
| 15 | Group 1: 11 templates + presets + Burmese sample content | roadmap: group 1 | todo | | |
| 16 | Plans (free / paid) | free-plan limits undecided | needs-owner | | |
| 17 | Group 2a: pre-order dates, cutoff, daily capacity, pickup, deposit rule | roadmap: group 2 | todo | | |
| 18 | Group 2b: order-line note, buyer reference image, gift recipient | roadmap: group 2 | todo | | |
| 19 | Group 2c: group 2 templates + presets | roadmap: group 2 | todo | | |
| 20 | Group 3a: priced modifiers + menu categories | roadmap: group 3 | todo | | |
| 21 | Group 3b: delivery / pickup / dine-in, opening hours, closed state | roadmap: group 3 | todo | | |
| 22 | Group 3c: staff accounts (`shop_user.role` owner / staff) | roadmap: group 3 | todo | | |
| 23 | Group 3d: dine-in table QR, waiter quick-order, per-table bill | roadmap: group 3 | todo | | |
| 24 | Group 3e: counter screen | roadmap: group 3 | todo | | |
| 25 | Group 3f: group 3 templates + presets | roadmap: group 3 | todo | | |
| 26 | Group 4a: services, service providers, working hours | roadmap: group 4 | todo | | |
| 27 | Group 4b: slots, appointments, no-double-booking constraint | roadmap: group 4 | todo | | |
| 28 | Group 4c: booking storefront + deep-link confirmation | roadmap: group 4 | todo | | |
| 29 | Group 4d: per-provider day calendar | roadmap: group 4 | todo | | |
| 30 | Group 4e: group 4 templates + presets | roadmap: group 4 | todo | | |
| 31 | CornerArea brand rename in code | roadmap: build order 1 | after-go-live | | |
| 32 | Remove the step-36b deploy aliases: `/fragrances` routes, the legacy `fragrance_id` + `size_ml` checkout line, the admin "View on site" `/fragrance/` link (→ `/product/`), and the flat perfume keys on the product (`concentration`, `gender`, `notes`…) and `/meta` (`genders`, `concentrations`) — 37b reads `attributes` / `filters`; the tracking receipt's `decant_date` alias and the storefront's `"decanted"` status tolerance (make `prep_date` / `status_labels` required in `types.ts`) | §36 "As built" (36b), §39 "As built" | after-go-live | | |

## Log

(one line per run: date · item · what happened · PR)
2026-09-25 · 0 · runner files committed (2b132c3); #112 money-table shop_id FKs cascade → restrict, verified up/down/up on Postgres, 352 tests green · #114
2026-09-25 · 1 · CornerArea scope recorded (docs only): P5 replaced, roadmap 43, ADR-0005, steps 36–38 amended; letterhead bug filed as #116 (row 2) · #117
2026-09-25 · 2 · letterheads (invoice + day sheet) print the shop's name, two-shop tests, 354 tests green · #118
2026-09-25 · 3 · step 35 parity baseline: GenericShopParityTest (10 tests, literal Kyat, second-shop leak guard); step-40 COGS formula corrected in the plan; 364 tests green · #119
2026-09-25 · 4 · step 36 split: 36a built (products/product_variants schema + backfill, models, admin, archived variants, Shield permission rename; API byte-identical); Postgres up/down/up money hash identical; 376 tests; row 4b added for the API/storefront half; found pre-existing EditOrder township TypeError (noted in PR) · #120
2026-09-25 · fix #118 · owner review (parity fixture) applied on #119's branch: courier + payment-method money paths (Mark-paid defaults, online fee settle → 0 Paid, COD short settle → 12,500 Unpaid, courier float, balance outstanding 560,500), existing figures unchanged; merged up into #120; 380 tests · #119
2026-09-25 · fix #114, #117 · #114: settings.json deny list tightened (+refspec, refs/heads/main|develop pushes, docker compose down); #117: plan says a brand with products can't be deleted (archive it) and step 37 rebuilds search_text on brand rename; code on #120: products.brand_id NO ACTION (not RESTRICT — SQLite checks it mid shop-cascade), admin delete/bulk delete refuse with a notice, 4 new tests; merged up the stack; 383 tests green on SQLite and on Postgres · #120
2026-09-25 · 4b · step 36b: /products API + aliases, checkout by variant_id (legacy pair kept to go-live), storefront /product/[slug] + 308 redirect, cart v2; reviewer blocker (deploy order) fixed → API first under maintenance; row 32 added (remove aliases after go-live); 386 tests · #121
2026-09-25 · 5 · step 37 split: 37a built (App\Templates + DecantTemplate, products.attributes jsonb + search_text + template, shop_settings.template, per-shop categories + isolation test, template-driven admin/import/filters, /meta filters; API additive, flat keys kept to go-live); 5 columns dropped with lossless down (hashes identical on Postgres and SQLite); row 5b added for the storefront; 401 tests on SQLite and Postgres · #122
2026-09-25 · 5b · step 37b: storefront filters, product page and cards render from /meta filters + attributes; attributes gain `show` (Template::headline, Attribute list); decant pages unchanged but pill order/tones and "Scent notes" heading; brand_type reserved; 402 tests; lint 4 + verify sticky-375 failures pre-existing on base · #123
2026-09-25 · 6 · step 38 split: 38a built (ClothingTemplate, Template::measure(), variant_photos migration size_ml nullable + image_path, admin Size + Color variants with photos, clothing order lines pick a variant, variantLabel() on every admin surface, option[] filter, /meta variant_options, studio category pick); Postgres up/down/up hashes identical; reviewer: no blockers, day-sheet order + option trim fixed; 418 tests on SQLite and Postgres · #124
2026-09-25 · 6b · step 38b: storefront Size → Color picker + variant photo, option filters, optional brand (`brand: null`, Product::scopeSellable replaces 4 whereHas sites), brand types decant-only, size guide (`show: section`), DemoClothingShopSeeder + verify-clothing (16/16); reviewer: no blockers, 4 should-fix fixed; 428 tests on SQLite and Postgres · #125
2026-09-25 · 7 · step 39: `decanted` → `prepared`, `decant_date` → `prep_date` (migration, Postgres up/down/up hash identical), status labels from the shop template via one resolver (`Templates::statusLabel`, memo per shop id), `prepDateLabel`/`prepDateHelp`, tracking `status_labels` + `decant_date` alias (added to row 32); reviewer blocker (deploy note) fixed → maintenance on + rollback order; 434 tests on SQLite and Postgres; verify-clothing 17/17 · #127
2026-09-25 · 8 · step 40 split: 40a built (Template::stockMode — decant pooled, else per variant; stock_ml → stock_amount rename; product_variants.stock_qty + unit_cost_mmk; draw-down in one transaction under lockForUpdate, warn-only kept; cost by mode via OrderItem::currentUnitCost; LowStock/table/form both modes); COGS denominator stays the reference bottle (spec wording corrected); Postgres up/down/up exact; reviewer blocker (deploy note) fixed + 6 should-fix; row 8b added for weight units; 445 tests on Postgres (444 + 1 pg-only skip on SQLite) · #129
2026-09-25 · 8b · step 40b: Myanmar weight units — products.stock_unit (ml / kyatthar, viss display only), reference pair renamed off _ml (own commit), frozen order_items.measure (draw-down, shortfall, cost), weighed variants reuse product_variants.measure, unit-switch guard (stock, cost, sized variants, measured lines); API shape unchanged (weights served as options); Postgres up/down/up hashes identical; reviewer blockers fixed (admin line edit re-freezes measure, Duplicate copies measure + pre-existing min_in_stock_price alias bug fixed); 457 tests on Postgres (456 + 1 skip on SQLite) · #130
2026-09-25 · 9 · step 41: module toggles — shop_settings.modules (null = template defaults), App\Support\Modules resolver (once() per shop id, flushed on save), Features page, pages/resources/widgets/fields gated, /meta modules, promo codes refused in PromoCode::evaluate (order at full price), storefront hides the promo box; clothing defaults gain stock + cost, lose the schedule; Postgres up/down/up; reviewer: no blockers, 4 should-fix fixed (reorder line on create with stock off); 469 tests on Postgres · #132
2026-09-25 · 10 · step 42 (docs only): tenancy design §2/§7/§8 synced (renames, categories, templates + modules on shop_settings, shops/{id}/ storage as built, withoutTenancy ledger 2 of 5 in app/, none added by 35–41), findings name map, api.md pass (payment-proof + host endpoints, checkout payment_method/proof, shop + IP limits); reviewer blocker ("repo-wide") fixed; 4 pre-existing issues filed as #134 → row 10b; 468 tests + 1 skip · #135
2026-09-25 · 10b · #134 fixed: receipt fragrance_name from the frozen snapshot (drops live "Brand — Name (Conc)"), fresh-start deletes variant photos (two-shop test), payment-proof 409 on paid/cancelled/rejected (after the generic 404, before store; storefront shows it + reloads), generic item/product error strings; reviewer: no blockers, 3 should-fix fixed; 474 tests on Postgres · #136
2026-09-25 · 11 · step 44 split: 44a built (ShopRegistration::register() shared by Studio + sign-up, DNS-label + reserved slugs, automatic verified {slug}.{STOREFRONT_BASE_DOMAIN} address, Shop::publish() onboarding → live only, atomic, CORS bust); no migration; reviewer: no blockers, 6 should-fix fixed; row 11b added (sign-up pages + phone OTP — channel is an owner decision); 502 tests on Postgres · #138
2026-09-25 · 11b · step 44b: one sign-up page (/admin/register: account + phone code + shop through register()), PhoneVerification in the cache (hashed, 10 min, 5 atomic tries, per-phone + per-IP throttle) failing closed (log driver local/testing only), users.phone + phone_verified_at (Postgres up/down/up), Publish button + verified-phone rule in Shop::publish(); reviewer: no blockers, 6 should-fix fixed; row 11c added (provider driver, needs-owner); 525 tests on Postgres · #139
2026-09-25 · 12 · step 45: Help button — admin top bar + login/sign-up deep links to the studio's Telegram (t.me ?text=) / Viber (viber://chat, same tab), StudioHelp builder, platform env SUPPORT_* (blank or malformed = hidden, never throws), hidden for studio admins; no migration/API change; reviewer: no blockers, 5 non-blocking fixed; 535 tests on Postgres · #141
2026-09-25 · 13 · step 46 split: 46a built (shop_designs append-only + published_design_id null-on-delete, 13-section library, DesignConfig validator with WCAG 4.5:1 + on-shop links + own-prefix images, 6 presets as JSON — decant Clean = today's home, Burmese elsewhere, Designs publish/undo, Design page out of the menu until 46b); Postgres up/down/up; reviewer: no blockers, 5 should-fix + 6 nits fixed (map/tile link tricks, bidi, image types); rows 13b + 13c added; 606 tests on Postgres · #143
2026-09-26 · 13b · step 46b built: /meta `design` (image paths as URLs, null never 500s, versioned cache key api.meta.v2), theme as CSS variables on the tenant wrapper (@theme untouched; --on-primary for text on primary), home page from the section list, decant parity exact in the browser (verify-design.mjs before/after), Design page in the menu; reviewer: 1 blocker (api.md) + 5 should-fix fixed, 3 contrast risks left for 46c; 610 tests on Postgres · #144
