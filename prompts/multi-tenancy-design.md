# Multi-tenancy — system design, ADRs, and design-system extension

**Status:** Accepted — amended 30 July 2026 to match the codebase audit
(`prompts/multi-tenancy-findings.md`); build steps written as `prompts/23`–`25`
(Step C deferred until a second client exists)
**Date:** 30 July 2026
**Deciders:** you (studio operator)
**Scope:** one Laravel/Filament backend at `api.cornerarea.me` serving many decant shops, with a
separate Next.js storefront per shop

> Where this belongs: this is a design doc, not a `prompts/NN` build step. The decisions it settles
> get recorded as a `CLAUDE.md` changelog note; the implementation splits into at least three
> numbered prompt files (§9). Don't hand this whole document to Claude Code as one task.

---

## 1. Requirements and constraints

### Functional

| # | Requirement |
|---|---|
| F1 | One admin panel serves every shop; each client sees only their own data |
| F2 | You see all shops and can act on any of them |
| F3 | Each shop has its own storefront on its own domain |
| F4 | Each shop has its own catalog, orders, promo codes, images, payment details |
| F5 | Order alerts: shared studio bot (free tier) or the shop's own bot (paid tier) |
| F6 | Onboarding a new shop takes minutes, not a deployment |

### Non-functional

| # | Constraint | Source |
|---|---|---|
| N1 | Infrastructure stays ~$12/mo until revenue justifies more | $13/mo student credit, 24 months |
| N2 | One Heroku Basic dyno, one `essential-0` Postgres, no Redis | current cost model |
| N3 | Queue is `sync`; a worker dyno is +$7/mo and not affordable yet | `QUEUE_CONNECTION=sync` |
| N4 | Suite runs in-memory SQLite; real-engine checks are a separate script | `verify-postgres-portability.sh` |
| N5 | `main` deploys itself; migrations run unattended in the release phase | `DEPLOY.md` |
| N6 | Solo maintainer, non-technical clients, no mail driver configured | project reality |
| N7 | `api.cornerarea.me` can only point at one Heroku app | Heroku custom-domain limit |

### Load estimate

Small decant shops: order low tens per day each. Ten shops is a few hundred orders/day and a few
thousand catalog reads. A Basic dyno handles this with room to spare. **Scale is not the design
driver — isolation and cost are.** Design accordingly and resist anything justified by throughput.

---

## 2. High-level design

```
  fragnant.com          shop-b.com           shop-c.com
       │                     │                    │
  ┌────▼────┐          ┌────▼────┐          ┌────▼────┐
  │ Vercel  │          │ Vercel  │          │ Vercel  │   N projects, ONE repo,
  │ proj A  │          │ proj B  │          │ proj C  │   differing only in
  └────┬────┘          └────┬────┘          └────┬────┘   NEXT_PUBLIC_SHOP_SLUG
       │                     │                    │
       └──────────┬──────────┴─────────┬──────────┘
                  │                    │
         /api/v1/{shop}/...   (tenant in the path)
                  │
        ┌─────────▼──────────────────────────────────┐
        │  api.cornerarea.me — Heroku, one dyno       │
        │                                             │
        │  ResolveTenant middleware ──► TenantContext │
        │         │                          │        │
        │  ┌──────▼───────┐          ┌───────▼──────┐ │
        │  │ Public API   │          │ Filament     │ │
        │  │ (path slug)  │          │ (tenancy)    │ │
        │  └──────┬───────┘          └───────┬──────┘ │
        │         └──────────┬───────────────┘        │
        │              BelongsToShop global scope     │
        │              (throws if no tenant set)      │
        └─────────────────────┬───────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
   ┌────▼─────┐       ┌───────▼──────┐      ┌───────▼────────┐
   │ Postgres │       │ R2 public    │      │ R2 private     │
   │ shop_id  │       │ {shop}/...   │      │ {shop}/proofs/ │
   └──────────┘       └──────────────┘      └────────────────┘
```

### Request flow, public API

1. `GET /api/v1/fragnant/fragrances`
2. `ResolveTenant` middleware looks up the slug, 404s on unknown or inactive shops, binds the
   `Shop` into a request-scoped `TenantContext` singleton
3. Every query on a tenant-owned model is scoped by the global scope, automatically
4. Response returns only that shop's rows

### Request flow, admin

1. `GET /admin/fragnant/orders`
2. Filament tenancy resolves the shop from the route and checks the user has access to it
3. Same global scope applies — belt *and* braces, not either/or

---

## 3. ADR-001: Tenant resolution strategy

**Status:** Proposed · **Date:** 30 July 2026

### Context

One Heroku app must serve many shops. The API needs to know which shop a request belongs to,
before any query runs. Three mechanisms are available.

### Options

#### Option A — Path prefix: `/api/v1/{shop}/fragrances`

| Dimension | Assessment |
|---|---|
| Complexity | Low — a route group prefix and one middleware |
| Cost | $0 — no DNS, no certificates |
| Debuggability | High — the tenant is in every log line and every stack trace |
| Team familiarity | High — ordinary Laravel routing |
| Client-facing | Invisible; customers never see API URLs |

**Pros:** no DNS work; works identically from Vercel previews, `localhost`, and curl; the tenant is
visible in Heroku router logs, which matters when you're debugging by log alone.
**Cons:** every existing public route changes shape; frontend `lib/api.ts` needs a base-path change;
breaks any bookmarked API URL (none exist outside your own repo).

#### Option B — Subdomain: `fragnant.api.cornerarea.me`

| Dimension | Assessment |
|---|---|
| Complexity | Medium-high — wildcard DNS, wildcard ACM cert, Cloudflare proxy config |
| Cost | $0 in fees, real cost in setup and failure modes |
| Debuggability | Medium — host header is present but less prominent than the path |
| Team familiarity | Medium — new territory for this project |
| Client-facing | Invisible |

**Pros:** existing route paths unchanged; feels "cleaner" architecturally.
**Cons:** Heroku wildcard domains need an ACM wildcard cert, and every certificate issuance lands in
public Certificate Transparency logs — you already rejected `admin.cornerarea.me` on exactly this
reasoning. A wildcard cert is worse: it advertises the pattern. Onboarding a shop becomes a DNS
change, contradicting F6.

#### Option C — Origin header from the storefront

| Dimension | Assessment |
|---|---|
| Complexity | Medium — mapping table plus CORS interaction |
| Cost | $0 |
| Debuggability | Low — tenant is implicit, invisible in most logs |
| Team familiarity | Low |
| Client-facing | Invisible |

**Pros:** zero URL changes anywhere.
**Cons:** Vercel preview deployments have unpredictable origins, so previews break or need
wildcarding, which weakens the CORS allowlist that `FRONTEND_URL` currently enforces. `curl` has no
origin, so every manual test needs a fake header. Tenant becomes invisible when debugging.

### Trade-off analysis

B and C both make the tenant *implicit*. Your accumulated failure catalogue is almost entirely
about implicit things failing silently — `DATABASE_URL`, `DB_CONNECTION`, `PROOFS_DISK`, event
auto-discovery. A tenant that isn't visible in the URL is the same bug waiting to happen. You chose
explicit `Event::listen` over auto-discovery for greppability; this is the same call one layer up.

### Decision

**Option A — path prefix.** Tenant slug as the first segment after `/api/v1`.

### Consequences

- **Easier:** onboarding is a database row; local dev needs no `/etc/hosts` entries; every log line
  names the tenant; `curl` works with no ceremony.
- **Harder:** a one-time breaking change to every public route and to `lib/api.ts`.
- **Revisit if:** you ever sell a white-label API to a client's own developers, where a branded
  hostname has commercial value.

### Action items

1. [ ] Route group: `Route::prefix('v1/{shop}')` with `ResolveTenant` bound
2. [ ] `NEXT_PUBLIC_SHOP_SLUG` in the frontend, folded into the `lib/api.ts` base URL
3. [ ] Keep `/api/v1/meta` shape identical — only the path changes, so the storefront's contract holds

---

## 4. ADR-002: Isolation enforcement — fail loudly, not silently

**Status:** Proposed · **Date:** 30 July 2026

### Context

This is the decision that matters. A missing tenant filter does not throw — it returns another
shop's customer names, phone numbers, and addresses. The suite runs SQLite with one shop and stays
green throughout. It is structurally the same blind spot as the Postgres one, with materially worse
consequences: a cross-tenant leak is a breach you have to disclose to a client, not a bug you patch.

### Options

#### Option A — Filament tenancy alone

Filament's built-in tenancy scopes resources by relationship and handles the admin UX.

**Pros:** least code; tenant switcher and scoping come free.
**Cons:** covers only the panel. The public API, artisan commands, CSV export, invoice PDFs, and
dashboard widgets are all outside it. Filament tenancy does not scope custom queries you write
yourself — and the dashboard widgets, the production schedule, and the CSV export are all custom
queries. Verify against installed vendor rather than trusting v3 docs; you have been bitten by a
vendor default before.

#### Option B — Global scope alone

An Eloquent global scope on tenant-owned models, reading a request-scoped `TenantContext`.

**Pros:** covers every code path — API, admin, artisan, export — from one place.
**Cons:** the classic failure is a scope that returns everything when no tenant is set. That is a
silent cross-tenant leak with a helpful-looking default.

#### Option C — Both, with a throwing scope

Global scope as the floor; Filament tenancy for the panel UX. Critically: **when no tenant is set,
the scope throws rather than returning unscoped rows.** Unscoped access requires an explicit,
greppable escape hatch (`Shop::withoutTenancy(fn () => ...)`) used only where genuinely needed —
your cross-shop views, and the migration that backfills existing rows.

| Dimension | A | B | C |
|---|---|---|---|
| Coverage | Panel only | All paths | All paths |
| Failure mode | Silent leak outside panel | Silent leak if context unset | Loud exception |
| Complexity | Low | Low | Medium |
| Greppability of exceptions | n/a | n/a | High — one method name |

### Decision

**Option C.** The throwing default is the whole point: it converts failure class #5 from
"silently serves the wrong shop's data" into "500s in CI on the first test that forgets."

### Consequences

- **Easier:** you can write a new query without remembering to scope it — forgetting throws.
- **Harder:** every legitimately cross-shop query needs the explicit wrapper, including your own
  studio dashboard and `decant:fresh-start`.
- **Watch for:** artisan commands and queued work run with no HTTP request, so `TenantContext` is
  unset and every tenant-owned query throws. Commands take a `--shop` option and set the context
  explicitly. `decant:fresh-start` **must** take `--shop` and refuse to run without it.
- **Watch for, second (added after the audit):** the scope's predicate **must qualify its
  column** — `$builder->where($model->qualifyColumn('shop_id'), …)`, never a bare
  `where('shop_id', …)`. Joins never apply the joined model's global scopes, and once two joined
  tables both carry `shop_id` (order_items × orders in the TopFragrances widget), an unqualified
  column is "column reference is ambiguous" **on Postgres only** — SQLite stays green, so the
  suite structurally cannot catch it. That widget's query goes into
  `verify-postgres-portability.sh`.
- **Revisit if:** you ever move to per-tenant databases, which would make the scope redundant.

### Action items

1. [ ] `TenantContext` singleton — `set()`, `get()`, `has()`, `withoutTenancy()`
2. [ ] `BelongsToShop` trait: `shop_id` auto-fill on create, global scope using
       `qualifyColumn('shop_id')`, throw when `!has()`
3. [ ] `ResolveTenant` middleware for the API; Filament tenancy for the panel
4. [ ] `--shop` on every artisan command touching tenant data; `fresh-start` refuses without it
5. [ ] Two-shop isolation suite (§8) — written with this, not after
6. [ ] TopFragrances (the one hand-built join in the repo) probed by `verify-postgres-portability.sh`

---

## 5. ADR-003: Per-tenant Telegram credentials

**Status:** Proposed · **Date:** 30 July 2026

### Context

`TELEGRAM_BOT_TOKEN` and `TELEGRAM_ADMIN_CHAT_ID` are currently Heroku config vars — one value for
one shop. With many shops in one app, config vars can no longer hold this. The product model is
already decided: shared studio bot on the free tier, the shop's own bot on the paid tier.

### Decision

Move both to columns on `shops`. Resolution order in `TelegramNotifier`:

1. `shop.telegram_bot_token` if set (paid tier — isolated per client)
2. otherwise the studio token from config (free tier — shared `cornerareabot`)
3. `shop.telegram_chat_id` always comes from the shop; no fallback

Keep the existing no-op-when-unconfigured and never-throw behaviour exactly as it is.

### Consequences

- **Easier:** tier upgrade is one field in the admin, no deploy.
- **Harder:** client bot tokens now live in Postgres, which means they live in your nightly
  `pg_dump`. Use the `encrypted` cast, and treat those dump files with the same care that moved
  payment proofs off the public bucket — same class of data, same reasoning.
- **Watch for:** the shared token can post to every free-tier shop's channel. `/revoke` in
  BotFather is the incident response and it breaks every free-tier shop at once; document that in
  `DEPLOY.md` before you need it.
- **Note:** the paid tier is genuinely more isolated, not just more branded. That is a real thing to
  say in the pitch.

### Action items

1. [ ] `shops.telegram_bot_token` (nullable, `encrypted`), `shops.telegram_chat_id` (nullable)
2. [ ] `TelegramNotifier` takes a `Shop`, not config
3. [ ] `telegram:test --shop=` and `telegram:chat-id --shop=`
4. [ ] Test: shop with own token uses it; shop without falls back to studio; neither set → no-op

---

## 6. ADR-004: Storefront topology

**Status:** Proposed · **Date:** 30 July 2026

### Context

F3 wants a storefront per shop on its own domain. You said "multiple frontends, one admin panel."
The question is whether that means multiple Vercel *projects* or one project serving many tenants.

### Options

#### Option A — N Vercel projects, one repo, differing only in env vars

Each project builds `frontend/`, with its own `NEXT_PUBLIC_SHOP_SLUG` and its own domain.

**Pros:** theming resolves at build time, so zero runtime cost and no flash of unstyled content;
one client's build failure doesn't affect another; projects are unlimited on Vercel Pro.
**Cons:** a push to `develop` rebuilds every project; N builds to watch; a bad frontend commit
breaks every shop at once anyway (correlated failure survives this choice).

#### Option B — One project, tenant from the hostname at runtime

**Pros:** one build, one deployment to watch.
**Cons:** theme must be fetched at request time; needs middleware host→slug mapping; a runaway
build breaks everyone with no isolation whatsoever.

### Decision

**Option A**, and note that the cost is N Vercel builds per push — acceptable at ten shops, worth
revisiting at fifty.

> **Superseded (2026-08-18, issue #88):** `docs/adr/0004-storefront-addressing.md` is
> accepted as amended and replaces this decision. The storefront becomes **one**
> deployment resolving the tenant from the validated `Host` (via a `shop_domains`
> table) and rewriting internally to a tenant-keyed route — Option B's runtime
> resolution married to Option A's explicit tenant keys, with custom domains
> first-class. The revisit trigger in §11 ("ten shops: N builds per push") is thereby
> retired. The backend topology (ADR-0002: pooled) is unchanged.

### Consequences

- Vercel Pro at $20/mo becomes mandatory rather than deferred once any client pays. Real cost model
  is roughly $32/mo against $13 credit. This should be priced into the paid tier explicitly.
- One dyno serving every shop means one bad backend deploy breaks every shop simultaneously. This
  is the real trade: per-client cost down, correlated failure up. It makes the `develop` → `main`
  promotion gate more important, not less.
- Set each project's Production Branch deliberately. **This is unresolved today for the one project
  you have** — check it (dashboard → Settings → Git → Production Branch, or `vercel project
  inspect`) and record it in `DEPLOY.md` before adding a second. Evidence, corrected by the audit:
  the original "Vercel defaults to the repo's default branch, which is `main`" reasoning was wrong
  — **this repo's default branch is `develop`**. Likely-`main` still holds, on different grounds:
  Vercel captured the Production Branch at import time (step 13, before step 17 created the
  `develop`/`main` split, when `main` was the only branch), and the Vercel bot labelled the
  `develop`-head deployment on promotion PR #54 as **Preview**, which rules `develop` out. Still
  unverified until read from the dashboard.

---

## 7. Data model

### New

```
shops
  id, slug (unique, indexed), name, is_active   -- Step A
  contact_phone, contact_address                -- Step C
  telegram_bot_token (nullable, encrypted), telegram_chat_id (nullable)  -- Step C (ADR-003)
  brand_primary, brand_accent, logo_path        -- Step C, see §10
  created_at, updated_at

shop_user  (pivot — access model decided in docs/adr/0003-admin-access-model.md:
  Option C, is_studio flag as the studio grant; pivot shipped empty with Step 25a,
  role column deferred until a client has staff)
  shop_id, user_id
```

Payment details do **not** move onto `shops` — amended after the audit. `shop_settings` shipped
in v14 and works; it keeps its own table and becomes per-shop instead (below). Telegram
credentials still land on `shops` (ADR-003): those are studio-managed onboarding config, where
payment details are decanter-managed through the existing ManagePayment page — a deliberate split
of homes, not an accident.

### Gets `shop_id`

`brands`, `fragrances`, `decant_prices`, `orders`, `order_items`, `promo_codes`,
`shop_settings` — everything `decant:fresh-start` touches. `order_items` gets a denormalised
`shop_id`: it makes the isolation test trivial and removes a join from every scoped query.
`decant_prices` (added after the audit) gets one for the same reasons plus a sharper one: it has
no Filament Resource, so without its own column neither scoping mechanism ever protects it
directly and a throwing scope has nothing to throw from — its safety would hang entirely on
`whereHas('fragrance')` chains. `shop_settings` (reversed after the audit — the earlier draft
folded it into `shops`): keep the v14 table, add `shop_id` **with a unique index** so it stays
one-row-per-shop. `ShopSetting::current()`'s `firstOrCreate([])` then works unchanged under the
trait: the global scope narrows the find side, and the trait's `creating` auto-fill parameterises
the create side — a bare scope can't do that second half; the hook has to.

**Added by the v18–v22 finance + delivery-zones layer (findings addendum A1–A3):**

- `expenses` (v20) — a decanter's operating ledger, tenant-owned like `orders`; `shop_id`
  + trait, and it joins the `decant:fresh-start` per-shop reset (which today skips it — A6).
  `ExpenseResource` auto-scopes once the trait lands.
- `delivery_townships` + `delivery_township_couriers` (v21) — **plain `shop_id` on both,
  geography duplicated per shop.** The township identity is national (the `(region, name)`
  unique index carries no shop dimension; geography is seeded from committed CSVs), and only
  `fee_mmk`/`is_active`/`sort_order` + the whole courier child are per-shop — so a
  global-geography reference table with a `(shop_id, township_id)` **pricing overlay** is the
  more normalized model. It is **rejected deliberately**: the overlay forks the seam's one
  mechanism (a class of tenant tables that skip the trait, plus a read-time join), rewrites
  v21's shipped admin UI / checkout lookup / public API, and saves only the duplication of
  ~228 reference rows per shop — which onboarding seeds anyway, all inactive at fee 0. So both
  tables get `shop_id`; the uniques become `(shop_id, region, name)` and
  `(shop_id, delivery_township_id, courier)`. `delivery_township_couriers` has no Resource
  (edited through the township's `Repeater` + a bulk action), so its `shop_id` + trait is the
  only thing that scopes it. Same simplicity call as `ShopSetting` above and the v8 total-ml
  stock decision.
- Cost/finance **columns** — `bottle_cost_mmk`/`bottle_volume_ml` on `fragrances`,
  `unit_cost_mmk`/`line_cost_mmk` on `order_items`, COD-float + delivery snapshots on `orders`
  — ride models that are already on this list, so they inherit the scope for free. They are
  **admin-only supplier/cost data and must never reach the public API** (confirmed absent from
  `app/Http`); the tenant scope is not what protects them, but it must not accidentally expose
  them either. The one integrity watch is `orders.delivery_township_id` pointing at another
  shop's township — closed by the checkout re-validation note below.

### Existing designs that need attention

**Tracking codes — decided.** Lookup is code + phone with a generic 404. Codes stay **globally
unique**: the DB unique index keeps its current global form, and `generateTrackingCode()`'s dedup
loop wraps in `withoutTenancy()` so its `exists()` check keeps seeing every shop's codes and the
retry still works. (Under a tenant scope that check silently narrows per-shop, and a cross-shop
collision would then surface as an *unretried* `QueryException` from the index instead of a
retry — audit Q2.) `findByTracking` needs no change beyond the scope itself: the global scope
narrows it per-shop, and the path slug + generic 404 do the rest. This is one of the few
justified `withoutTenancy()` uses (§8's budget).

**Cache keys — Step A, not later.** `Cache::remember('api.meta', 600)` and `'api.brands'` are
single global keys, and since v14 `api.meta` holds the **DB-backed payment block**. Left alone,
that serves one shop's KBZPay and Wave numbers (and MMQR) to another shop's storefront for up to
ten minutes — and no Eloquent scope will ever touch a cache key. Keys become per-shop
(`api.meta.{slug}`, `api.brands.{slug}`) and busting becomes per-shop with them: `ShopSetting`'s
`saved` hook currently forgets the one global key, and `decant:fresh-start` forgets both.
**v21 added a third global key, `api.delivery-zones`** (`DeliveryZoneController`), with the same
hazard and **four** bust sites — the `saved`/`deleted` hooks on both `DeliveryTownship` and
`DeliveryTownshipCourier`, `DeliveryZoneSeeder`, and `decant:fresh-start` (findings A5). It
becomes `api.delivery-zones.{slug}` and every one of the four busts keys per shop.

**Slugs — the highest-priority Step A item.** `brands.slug` and `fragrances.slug` are globally
unique, and `HasSlug::generateUniqueSlug()` dedups with a plain `exists()` loop — the same shape
as tracking codes but with a *guaranteed* collision: every decant shop sells Chanel, so the
second client's `CatalogImport` passes the tenant-narrowed check and dies on the global unique
index mid-onboarding. That blocks F6 outright. Unlike tracking codes there is no reason for
global uniqueness — slugs are namespaced per shop by the path prefix — so both indexes become
`(shop_id, slug)` composites, and the slug-dedup loop is then *correct* precisely because it
runs scoped.

**Payment proofs.** The authenticated streaming route must verify the order belongs to the
requesting tenant, not merely that the user is authenticated. Otherwise an incrementing order id in
that URL walks other shops' bank screenshots. This is the highest-severity single line in the whole
migration.

**Delivery zones — public read + checkout write (v21, findings A5).** Two surfaces the audit
missed because they postdate it. The public `GET /api/v1/delivery-zones` (the **ninth** public
endpoint — Step 24's "eight" is now nine) must return only the current shop's serviceable
townships and cache per-shop; the `{shop}` path prefix carries the tenant, and
`api.delivery-zones.{slug}` carries the cache. Checkout already re-derives the fee server-side —
`DeliveryTownship::serviceable()->find($id)`, 422 on miss, fee read off the row not the client,
the same discipline as `currentPriceFor()`. That `find()` is shop-blind today; once
`DeliveryTownship` carries the trait and the api request sets context, the scoped `find()`
returns null for another shop's township id → 422, closing the cross-shop routing/pricing hole
**with no new code**. The one integrity note is `orders.delivery_township_id`: a per-order FK into
a now-scoped table, so an order can only ever point at its own shop's township.

**Custom Finance pages — covered by the app scope, which is the point (findings A4).**
`ProfitAndLoss` and the two production-schedule pages are custom Filament `Page`s, so *Filament's*
tenancy scope never reaches them. But their data runs through Eloquent on trait-bearing models —
`MonthlyPnl` over `Order`/`Expense`, `Order::productionScheduleFor()` over `OrderItem` — so once
those models carry `BelongsToShop` and the panel request has a `TenantContext` (the interim panel
middleware sets it, Step 23 §5), the **throwing app scope covers all three with no per-page code**.
This is exactly why ADR-002 keeps the app scope as the floor rather than trusting Filament alone.
The finance widgets (`DiscountCost`, `CourierFloat`, `OrderStats`' margin) are plain `Order`
queries summed in PHP → auto-covered; only `TopFragrances`' hand-built join still needs its
`orders.shop_id`/`order_items.shop_id` qualified (ADR-002, unchanged).

### Storage layout

Follow the convention you already established for the shared bucket — prefix by tenant:

```
images.cornerarea.me/{shop}/fragrances/...       (public bucket, shop-prefixed)
images.cornerarea.me/{shop}/payment-qr/...       (public bucket — v14's MMQR upload, same convention)
payment-proofs bucket: {shop}/payment-proofs/... (private, no custom domain)
```

Nothing scopes a storage path, so the prefix is enforced in code at every write site — the audit
counts three (fragrance image uploads, checkout's slip store, ManagePayment's MMQR upload) plus
`decant:fresh-start`'s directory wipe, which becomes per-shop.

---

## 8. Isolation test strategy

This is not optional and it is not a follow-up. It ships with the seam.

| Test | Asserts |
|---|---|
| Two shops, catalog | `GET /api/v1/a/fragrances` returns only A's rows, and the count is exact |
| Two shops, tracking | A's code + phone against shop B's path returns the generic 404 |
| Two shops, proof route | Admin of A requesting B's proof id gets 403/404, not bytes |
| Two shops, admin table | Filament order list for A excludes every B row |
| Two shops, dashboard | Every widget stat counts only A — these are custom queries, unscoped by Filament |
| Two shops, CSV export | Export from A's panel contains zero B rows |
| Two shops, promo codes | A's code is not redeemable in B's checkout |
| Two shops, meta cache | B's `/meta` never serves A's payment details — not even inside A's 10-minute cache window; busting A's settings busts only A's key |
| Two shops, slugs | Both shops create brand "Chanel": both succeed, and each shop's catalog shows exactly one |
| Two shops, P&L + expenses | A's Profit & loss counts only A's orders **and** A's expenses; an expense entered in B never moves A's net (custom page → app scope, findings A4) |
| Two shops, delivery zones | `GET /api/v1/a/delivery-zones` returns only A's active/serviceable townships at A's fees; editing B's township busts only B's `api.delivery-zones` key |
| Two shops, checkout township | A checkout under A's path with **B's** `delivery_township_id` gets a 422 (scoped `serviceable()->find()` returns null) — never routed to B's township or priced by B's fee |
| Unscoped access throws | A tenant-owned query with no `TenantContext` raises, and does not return all rows |
| Escape hatch works | `withoutTenancy()` returns cross-shop rows, and is used in ≤ 5 places repo-wide |

Note the pattern from the Telegram double-send: **assert counts, not presence.** "Returns A's rows"
passes while also returning B's. Every row above says *only*, and the test must check the count.

Extend `verify-postgres-portability.sh` too — the `(shop_id, slug)` composite uniques and the
TopFragrances join (an unqualified `shop_id` is ambiguous on Postgres, green on SQLite) behave
differently on the real engine, and SQLite will not tell you.

The `withoutTenancy()` ledger so far: tracking-code generation (§7), the Step A backfill
migration, and the studio's cross-shop views when Step C builds them. Anything beyond that list
needs its inline justification reviewed.

---

## 9. Migration sequencing

Three prompt files, in this order, each its own issue and PR — written as
`prompts/23-multi-tenancy-seam.md`, `prompts/24-multi-tenancy-routing.md`, and
`prompts/25-multi-tenancy-shop-onboarding.md`.

**Step A — the seam (do now; the window is already closing).** `shops` table, `shop_id` columns,
`TenantContext`, `BelongsToShop`, the throwing scope, the `(shop_id, slug)` composites, per-shop
cache keys and busting, `{shop}/` storage prefixes, `--shop` on commands — and the §8 isolation
suite **as the branch's first commit**, so the diff shows the tests drove the seam. One shop row
is created and backfilled in-migration (Heroku's release phase runs unattended — N5); no UI, no
onboarding, no theming. Behaviour is unchanged from a user's perspective. **Honest correction to
the earlier "while data is disposable": production is already live** — a real catalog, real
orders, Telegram alerts firing — so treat the backfill as a migration against small-but-real
data, not a free-to-botch seed: the storage re-prefixing needs a real object-move pass, and
rollback stops being free the moment any operator-entered value lands in a new column.

**Step B — routing (do now, same window).** Path prefix, `ResolveTenant`, `NEXT_PUBLIC_SHOP_SLUG`,
`lib/api.ts`. Breaking change to public routes, cheapest while you're the only consumer.

**Step C — the tenant-facing feature (defer until a second client exists).** Filament tenancy and
switcher, shop CRUD, per-tenant Telegram fields, theming (§10), onboarding runbook. Pure addition,
no data risk, and no demand for it yet. Building this now is abstracting for clients you don't have.

Roll back Step A by reverting the merge *only* before the first real order lands. After that, the
`down()` drops `shop_id` and the escape hatches, and you're editing live data by hand.

---

## 10. Design system — extending for per-tenant theming

### Current state audit

| Category | Where it lives | Issue |
|---|---|---|
| Colors | `design-tokens.json` (root) **and** `frontend/src/app/globals.css` `@theme` | Two sources for one fact — the recurring failure in this repo. Audited: nothing generates the JSON; it's a hand-maintained mirror (its own `description` says so), in sync on colors today |
| Typography | `globals.css` — single Helvetica stack | Consistent; no issue |
| Spacing / radius | `globals.css` | Hairline borders are a named idiom; good |
| Motion | `globals.css` `:root` duration tokens + component-level GSAP/Motion | Tokenised in CSS only (`--duration-*` on `:root`, *outside* `@theme`) and absent from the JSON — the mirror is already inaccurate here; the vial-fill timeline itself is component-level |
| Semantic naming | `mist`, `pine`, near-black | Strong, memorable, brand-specific |

**Priority action before any theming work — the audit resolved the question:** nothing is
generated from anything; the JSON is a convention-maintained mirror of `globals.css` that has
already drifted (motion tokens exist only in the CSS). Either generate `design-tokens.json` from
`globals.css` in a build step, or delete it and translate from `globals.css` directly — the v5
"portable copy" intent survives either way. Do not keep hand-mirroring: per-tenant overrides on
top of a driftable mirror multiplies the problem by N.

### The design decision that protects your margin

If every client can theme everything, each onboarding becomes a bespoke design engagement and the
margin multi-tenancy exists to protect disappears. **Constrain what varies, explicitly.**

#### Themable — the allowlist

| Token | Type | Constraint |
|---|---|---|
| `--brand-primary` | color | Must pass AA against `--surface` |
| `--brand-accent` | color | Must pass AA as button fill under white label text |
| `--logo` | image | Fixed aspect ratio, transparent PNG/SVG |
| `--shop-name` | text | Length-capped for header layout |

#### Not themable — by design, and stated in the client contract

Type family and scale, spacing scale, border radius, hairline border treatment, the vial-fill
timeline motion, layout and component structure, the pill-shaped metadata idiom.

That list *is* the product boundary of the paid tier. "Custom design" is a different, priced
engagement — not a checkbox.

### Proposed component change: `ThemeProvider`

#### Problem

Brand tokens must vary per shop; everything else must not.

#### Existing patterns

| Related | Similarity | Why not enough |
|---|---|---|
| `globals.css` `@theme` | Already defines tokens as CSS custom properties | Build-time constant; can't vary per shop |
| `PaymentPanel` reading `/meta` | Precedent for per-shop config from the API | Config, not presentation |

#### Design

Tailwind v4's `@theme` already emits CSS custom properties, so the override surface exists.
Structural tokens stay in `@theme`. The four brand tokens are re-declared on `:root` from a
server-rendered inline style in the root layout, fetched server-side so there is **no flash of
default colors** — a client seeing your `pine` green for 200ms before their own brand is exactly
the wrong first impression. This is not a new pattern for the codebase: `globals.css` already
layers the motion duration tokens on `:root` *outside* `@theme` — per-shop brand tokens are the
same mechanism, server-rendered instead of static.

| Property | Type | Default | Description |
|---|---|---|---|
| `shop` | `Shop` | — | Fetched server-side in the root layout from `/meta` |
| `fallback` | `Theme` | Decant Please defaults | Used when a shop has set no brand tokens |

#### States

| State | Behavior |
|---|---|
| Shop with full theme | Brand tokens applied inline; structural tokens untouched |
| Shop with partial theme | Unset tokens fall back individually, not all-or-nothing |
| `/meta` unreachable at build | Build fails loudly — do not ship a storefront themed as another brand |

### Accessibility — validate at entry, not at render

Contrast must be checked **when the client sets the color in the admin**, not when a customer loads
the page. A Filament validation rule computing WCAG AA contrast for `brand_primary` against the
fixed surface color, and for `brand_accent` under white label text, rejecting the save with a
readable message. Client-side there is nothing to check and nothing to fix.

This also means your fixed `mist` surface is doing real work: because the background is not
themable, contrast has exactly two pairings to validate rather than a matrix.

### Open questions for you

- ~~Is `design-tokens.json` generated from `globals.css`, the reverse, or is it stale?~~
  Answered by the audit: neither is generated; it's a hand-maintained mirror, already stale for
  motion tokens. Generate it or delete it (priority action above) before theming work starts.
- Does a client get to change the logo *and* the wordmark, or is the wordmark always their shop
  name in your typeface?
- Is `mist` genuinely fixed, or is background color the one more token worth allowing? Allowing it
  quadruples the contrast matrix and I'd hold the line.

---

## 11. What to revisit as this grows

| Trigger | Revisit |
|---|---|
| First paying client | Vercel Pro mandatory; re-price the tiers against ~$32/mo |
| Third client | Onboarding becomes a runbook, or it becomes your evenings |
| One shop's traffic dominates | Row-level scoping stops being enough; consider a separate dyno for them |
| A client asks for data export/deletion | Per-tenant export and hard-delete paths, which the schema should already permit |
| Ten shops | N Vercel builds per push starts to hurt; reconsider ADR-004 |
| A client demands real design control | Per-tenant frontend fork, priced as such — not a token allowlist expansion |
| Password resets requested | You need a mail driver; until then you set passwords by hand |

---

## 12. `CLAUDE.md` §8 amendment

§8 currently excludes "multi-decanter marketplace." That exclusion **still holds** — no customer
ever sees two shops in one storefront. Multi-tenancy is infrastructure and invisible to customers.

Split §8 along that axis: **product scope** (what a customer can see and do — unchanged) versus
**infrastructure scope** (how many shops one deployment serves — now many). Adding a third bullet
exception instead invites a future session to read shared infrastructure as a §8 violation, which
is the same class of confusion the v13 notification amendment just fixed.

*Recorded: CLAUDE.md v15 (the PR that landed this amendment) carries the split.*

---

## 13. Failure class #5, for the promotion-review skill

Add to the catalogue, in the same form as the others:

> **Cross-tenant leakage.** A missing `shop_id` filter does not throw — it returns another shop's
> customers. The suite runs one shop on SQLite and stays green. Rule: any new query, widget,
> export, or route touching tenant-owned data needs a two-shop test asserting exact counts, and any
> use of `withoutTenancy()` needs an inline comment justifying it.

*Landed: the class now sits in the failure-class list of the agentic-loop-harness spec (the
promotion-review skill draws its catalogue from there). It arrives as the seventh class in that
list — the "#5" in this section's title counts ADR-001's implicit-failure examples
(`DATABASE_URL`, `DB_CONNECTION`, `PROOFS_DISK`, event auto-discovery), not the catalogue's own
ordering.*
