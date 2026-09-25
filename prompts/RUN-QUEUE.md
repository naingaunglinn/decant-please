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
| 6b | Step 38b: storefront option picker + variant photo, option filters UI, optional brand in the API contract, size guide, demo clothing seeder | §38 "As built" (split from row 6); issue #107, don't file another | in-progress | 107-storefront-options | |
| 7 | Step 39: template status labels (`decanted` → `prepared`) | §39 | todo | | |
| 8 | Step 40: stock modes (pooled / per variant), Myanmar weight units | §40 + roadmap group 1 | todo | | |
| 9 | Step 41: module toggles, template default modules | §41 + roadmap "default modules" | todo | | |
| 10 | Step 42: design-spec sync (docs) | §42 | todo | | |
| 11 | Self-serve sign-up + automatic `slug.cornerarea.me` | roadmap: shared foundation. The wildcard DNS/Vercel attach is an owner step: document it, don't do it | todo | | |
| 12 | Help button (admin → studio on Viber/Telegram) | roadmap: shared foundation | todo | | |
| 13 | Design system: section library, 3 base designs, `shop_designs`, preset picker, manual editor (no AI) | roadmap: design system | todo | | |
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
| 32 | Remove the step-36b deploy aliases: `/fragrances` routes, the legacy `fragrance_id` + `size_ml` checkout line, the admin "View on site" `/fragrance/` link (→ `/product/`), and the flat perfume keys on the product (`concentration`, `gender`, `notes`…) and `/meta` (`genders`, `concentrations`) — 37b reads `attributes` / `filters` | §36 "As built" (36b) | after-go-live | | |

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
