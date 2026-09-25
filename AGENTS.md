# AGENTS.md — Decant Please!

Always-on rules for any coding agent working in this repo (Codex, Claude Code, Cursor).
**Stable rules only.** Product spec → `PRODUCT.md`. History → `CHANGELOG.md`.
Step specs → `prompts/NN-*.md`. Verification → `VERIFY.md`. Process → `prompts/WORKFLOW.md`.

---

## Principles — simple is timeless

> Read this first. Every spec, issue, and implementation decision is judged against
> these principles. If a request conflicts with them, say so in the PR instead of
> quietly building around them.

**In one line: enterprise-grade reliability, small-shop simplicity.** A seller should
never lose data, money, or an order because of us — and should never have to learn
anything to get that.

Simple is timeless: a simple system is still understandable, fixable, and cheap to run
years from now. Complexity ages; simplicity doesn't.

### P1. Who we build for

A one-person Myanmar social seller who runs the shop from a phone, on a slow or
intermittent connection, and sells through chat (Telegram, Viber, TikTok) with COD or
transfer-slip payments. Every screen, flow, and default is judged against that person —
not against a hypothetical larger business.

### P2. Reliability is never optional

The "enterprise" half. Applies to every shop on every plan; never traded for speed.

- Money is integer Kyat, derived server-side, never trusted from the client. Snapshots
  are written once and never rewritten.
- Personal data (payment proofs, addresses, phones) lives on private storage with no
  public or presigned URL. Backups run nightly.
- Side channels never break the core path: notifications, PDFs, and integrations are
  time-bounded, log their errors, and can't fail or delay an order.
- Anything touching money, stock, or personal data ships with tests; engine-specific
  queries are verified on PostgreSQL, not only SQLite.

### P3. Simple on the surface

The "small shop" half.

- The default is the smallest thing that works. A new feature adds no field, step, or
  menu item for a shop that doesn't use it.
- Extra power is an opt-in module, off unless the shop's template enables it.
- The admin is mobile-first too: the core loop — see order → check slip → mark paid →
  accept — works one-handed on a phone over a slow connection.
- Plain words in the UI, in English and Burmese. No jargon a seller would have to ask
  about.

### P4. Simple underneath

For the one developer who maintains all of it.

- Fewer tables, fewer moving parts, fewer dependencies. JSON columns before normalized
  option tables, until a real query needs more.
- Configuration lives in code (templates, modules) until a real seller needs to edit it
  themselves.
- Fixed state machines with configurable labels — never configurable states.
- Each business rule lives in one domain method; Filament, the API, and the storefront
  call it, never re-derive it.
- No new dependency without the PR stating what it replaces and what it costs on the
  Heroku/Vercel build.

### P5. Every category ships complete

- CornerArea builds every planned category ahead of demand. We don't wait for a seller to
  ask. The categories and their groups live in `prompts/43-cornerarea-roadmap.md`.
- A category is released only when its whole loop works: catalog (template attributes and
  options), ordering end to end, payment, the phone-first admin loop, status labels in
  Burmese and English, three design presets with Burmese sample content, default modules,
  and tests (money, stock or booking; tenant isolation; Postgres portability). Never ship
  half a category.
- Categories release group by group. A finished group doesn't wait for the others.
- Build the smallest version that makes the category complete, and record what was
  deliberately left out (the §8 pattern).
- Unused features are candidates for hiding or removal.

### P6. Before building, answer in the PR

1. Which category, and which seller in it, needs this? What are they doing when they hit
   the problem?
2. What does a shop that doesn't need it see after it ships? (The answer should be
   "nothing new.")
3. Core or module? If core, why does every shop need it?
4. What is the smallest version?
5. Does it touch money, stock, or personal data? Where are the tests?
6. What was deliberately not built?

---

## 1. What this is

A **multi-tenant SaaS** that lets a Myanmar social seller — in **any category** — run a
shop from their phone. One codebase serves many independent shops: each gets a Next.js
storefront on its own domain (browse → guest checkout → track, no login, no payment
gateway) and a Laravel + Filament admin panel scoped to its own data. The studio operator
sees across all shops. Full brief in `PRODUCT.md`; tenancy design in
`prompts/multi-tenancy-design.md`.

**Decant is the first template, not the whole product.** The system is being generalized
so one platform fits perfume decants, clothing, bakery pre-orders, cosmetics, and more —
each category is a *template* (attributes, variants, status labels, default modules)
defined in code. The generic-shop refactor is planned in `prompts/35-generic-shop-plan.md`
(steps 35–42), and the categories, groups and build order beyond it live in
`prompts/43-cornerarea-roadmap.md`; **multi-tenancy is already built** (steps 23–25 in
#58, plus 32–34) and the refactor builds on that seam.

Older docs describe this as a single-decanter perfume tool. They are superseded.

## 2. Repo map

```
backend/              Laravel 13 + Filament 5 (PHP 8.3, Postgres 17)
  AGENTS.md           API/service/Filament/test conventions  ← read before backend work
  app/Http/Controllers/Api/   public JSON API (routes/api.php, /api/v1/{shop})
  app/Filament/       admin panel resources, pages, widgets
  tests/Feature/      the real suite (22 files)
  scripts/            verify-postgres-portability.sh
frontend/             Next.js 16 App Router + Tailwind v4 (TypeScript)
  AGENTS.md           UI conventions, tokens, acceptance checks  ← read before frontend work
  src/lib/types.ts    the API contract mirror — the seam between the two halves
  scripts/            browser-evidence verify-*.mjs
prompts/              numbered step specs + WORKFLOW.md (issue→branch→PR loop)
.claude/skills/       reusable workflows (decant-money is project-critical)
```

## 3. Commands

Run from the directory named. **Never claim work is done without running the relevant row.**

| What | Where | Command |
|---|---|---|
| Backend suite | `backend/` | `composer test` |
| Tenant isolation | `backend/` | `php artisan test --filter=TenantIsolationTest` |
| One backend test | `backend/` | `php artisan test --filter=PromoCodeTest` |
| Backend format | `backend/` | `./vendor/bin/pint` |
| Postgres portability | `backend/` | `sh scripts/verify-postgres-portability.sh` (base defaults to `…/api/v1/decant-please` — the API base must include the `{shop}` segment) |
| Frontend typecheck | `frontend/` | `npm run typecheck` |
| Frontend lint | `frontend/` | `npm run lint` |
| Frontend build | `frontend/` | `npm run build` |
| Browser evidence | `frontend/` | `npm run verify` (see `VERIFY.md` for individual checks) |
| Full stack up | repo root | `docker compose up` |

Fixed local ports — do not change, other projects own the neighbours:
API `8010`, storefront `3001`, Postgres `5442`.

## 4. Non-negotiable rules

These are invariants, not preferences. Breaking one is a bug even if tests pass.

0. **Nothing crosses a shop boundary.** This outranks every other rule here — a money bug
   costs one shop money, an isolation bug shows one customer another shop's orders.
   - Every tenant-owned model uses `BelongsToShop`. Its scope **throws
     `TenantNotSetException`** when no tenant is set; it never returns all shops.
   - Compare `shop_id` **qualified** (`orders.shop_id`) — joins do not apply the joined
     model's scopes, and a bare `shop_id` is ambiguous on Postgres while SQLite tolerates it.
   - `withoutTenancy()` is a budgeted escape hatch. Every call site is enumerated in
     `prompts/multi-tenancy-design.md` §8 and asserted in `TenantIsolationTest`. Adding
     one is a design decision — say so and wait, do not just add it.
   - A new tenant-owned table ships with its isolation test in the same PR. No exceptions.
1. **Money is integer Myanmar Kyat.** Never floats, never decimals. Display `50,000 Ks`.
2. **The server derives every price.** The client sends only `variant_id`,
   `quantity`, `delivery_township_id`. A client-sent price, total, or delivery fee is
   ignored — never trusted, never persisted.
3. **Snapshots are frozen at write.** `fragrance_name_snapshot`, `unit_price_mmk`,
   `region_snapshot`, `township_snapshot`, `delivery_courier` are copied once at order
   creation and never recomputed. Renaming or repricing a catalog row must not move a
   placed order's numbers.
4. **Tracking lookup is not an oracle.** A wrong `tracking_code` and a wrong `phone`
   return the identical generic "not found".
5. **No payment gateway, no customer accounts, no customer-facing notifications.**
   See `PRODUCT.md` § Non-goals before adding anything in these areas.
6. **Every list endpoint is paginated. Every query is eager-loaded** (no N+1).
7. **Claude/Codex never merges a PR.** Open it and stop. See `prompts/WORKFLOW.md`.
8. **Private data has no public or presigned URL** (P2). Payment proofs — and any object
   on private storage — are served **only** through the panel's authenticated streaming
   route (`PaymentProofViewController`): never a public disk, never a temporary/presigned
   URL. The public API carries at most a `has_payment_proof` boolean, so losing a URL
   leaks nothing. (Lifted from the vN history; enforced since v14/v20.)
9. **Panel routes that must sit behind tenant auth go through `authenticatedRoutes()`**
   (`authenticatedTenantRoutes()` on the tenant panel), **never `routes()`**. A `routes()`
   closure sits outside the panel's auth + tenancy middleware — that misregistration once
   streamed one shop's invoice under another shop's URL (§0). New panel routes for
   invoices/proofs/exports belong in the authenticated group.

## 5. Working boundaries

State the envelope before editing. Default envelope for any task:

**CAN TOUCH** — the feature directory named in the task, its tests, and its own types.
**DO NOT TOUCH without saying so first and why:**

- `backend/database/migrations/**` (existing files — add a new migration, never edit a shipped one)
- `backend/app/Enums/**` (a value change ripples into stored rows)
- `backend/app/Support/TenantContext.php`, `app/Models/Concerns/BelongsToShop.php`,
  `app/Http/Middleware/*Tenant*` — the tenancy seam. Changing it changes every model at once.
- `backend/config/**`, `docker-compose.yml`, `.github/workflows/**`
- `frontend/src/app/globals.css` `@theme` block and `design-tokens.json`
- `frontend/src/lib/types.ts` — only alongside the matching API Resource change
- `frontend/src/components/layout/**`, `frontend/src/components/ui/**` (shared primitives)
- Anything under `prompts/` other than the step file you were asked to work from

## 6. Before and after editing

**Before writing code**, return: root cause or approach, the files you will touch,
the API contract if one changes, the implementation sequence, and the verification plan.
Do not start implementing until that plan has been read. Prefer one vertical slice
end-to-end over a broad partial change across many files.

**After editing**, return: commands actually executed with their real output,
a diff summary, what you deliberately did **not** change, and remaining risks.
"It should work" is not evidence. See `VERIFY.md`.

## 7. Definition of done

A change is done when **all** of these hold, and you have said so with evidence:

- [ ] `composer test` passes (backend changes), **including `TenantIsolationTest`**
- [ ] a new tenant-owned model has `BelongsToShop` **and** its own isolation test
- [ ] `npm run typecheck` and `npm run lint` pass (frontend changes)
- [ ] The matching `frontend/scripts/verify-*.mjs` passes, if the change is visual or flow-level
- [ ] `scripts/verify-postgres-portability.sh` passes, if the change adds a query with
      `LIKE`, `ORDER BY`, or a select alias — SQLite hides Postgres breakage here
- [ ] No unrelated module was modified
- [ ] Docs updated in the same branch: `CHANGELOG.md`, the step file if the spec was
      wrong, and `README.md`'s step table
- [ ] Any PR that adds a migration also updates `backend/docs/schema.dbml` (the schema
      diagram is regenerated from the migrated database, not edited by hand)

## 8. Conventions

- **Laravel:** PHP backed enums; API Resources for JSON shaping; controllers thin,
  validation in FormRequests, business rules in services. Details in `backend/AGENTS.md`.
- **Next.js:** App Router, server components for catalog fetching, Tailwind v4 `@theme`
  in CSS (there is no `tailwind.config.ts` and there must not be), cart state client-only.
  Details in `frontend/AGENTS.md`.
- **Event listeners are wired explicitly; discovery is off.** `bootstrap/app.php` sets
  `->withEvents(discover: false)` and every listener is registered by hand in
  `AppServiceProvider`. A new event/listener that isn't wired there simply never fires —
  add it explicitly, don't rely on auto-discovery.
- **Date windows use `whereDate`; dates cross the wire as bare `Y-m-d` strings.** Report
  and dashboard date filters use `whereDate` (`MonthlyPnl`, `OrderStats`, `Order`,
  `ListOrders`), not string ranges or raw casts. The API emits **and** accepts dates as
  bare `Y-m-d` (`prep_date`, `delivery_date`), never ISO datetimes — the storefront and
  the contract in `frontend/src/lib/types.ts` depend on that.
- **Tests never reach a real Telegram bot.** `phpunit.xml` pins
  `TELEGRAM_BOT_TOKEN`/`TELEGRAM_ADMIN_CHAT_ID` blank with `force="true"`; keep them blank
  so the suite exercises the "unconfigured" path and never sends.
- **Stack versions are fixed** — Laravel 13, Filament 5, PHP 8.3+, Postgres 17,
  Next.js 16, Node 24 LTS, TypeScript 5, Tailwind 4. Do not substitute or "upgrade"
  as a side effect of another task.
- **Next.js 16 is not the Next.js in your training data.** Read
  `node_modules/next/dist/docs/` before writing App Router code.
- Keep it simple and readable. Solo maintainer, non-technical customers, ~$12/mo of
  infrastructure. Reject anything justified by throughput — **isolation and cost are the
  design drivers, scale is not.** Simple is a constraint here, not a stylistic preference.
- **Tenant scoping is the `BelongsToShop` global scope — decided, not a default**
  (step 32, Phase 2). Explicit per-site `whereBelongsTo($shop)` was considered and
  rejected for this tree: it fails open, and this codebase makes forgetting both easy
  and invisible — money figures are summed in PHP over fetched rows (OrderStats,
  DiscountCost, CourierFloat, MonthlyPnl), so a missed clause doesn't crash, it
  inflates a dashboard number with other shops' orders; and the suite runs SQLite,
  where an unqualified `shop_id` join is green yet ambiguous on Postgres. The scope
  closes all of that by construction: every query on every surface Filament's tenancy
  does not reach (widgets, custom pages, panel controllers, the API, commands) is
  scoped whether or not its author thought about tenancy — including inside
  `whereHas` and correlated subselects, where a hand-written clause has to be
  remembered per subquery.
  - The scope **throws** (`TenantNotSetException`) when no tenant is set — never an
    empty result, never all shops. The 500 names the wiring bug; an empty page hides
    it. This is how the `authenticatedRoutes()` invoice misregistration was caught —
    the explicit-clause idiom would have streamed a cross-shop bank slip instead.
  - Cross-shop **writes** never bypass. Set the context to the target shop and let
    the creating hook stamp `shop_id` (`NationalGeography::seed` is the model); a
    bypassed create leaves `shop_id` null and dies on the NOT NULL.
  - Cross-shop **reads** go through `TenantContext::withoutTenancy()` — never
    `withoutGlobalScope`. One spelling, finally-restored, and metered:
    `TenantIsolationTest` caps `->withoutTenancy(` call sites in `app/` at 5. The
    ledger (design-doc §8): tracking-code dedup (built), the backfill migration
    (done), the studio's cross-shop views (step 34). Exceeding the cap means raising
    it and the ledger in the same PR — a reviewed act, never a side effect.
  - Platform-owned tables (`shops`, `users`, step 34's `studio_audit_events`) do not
    carry the trait. That is why `/studio` needs zero bypasses today — keep it true:
    a new Studio surface either reads platform tables, runs per-shop under a set
    context, or spends a budgeted `withoutTenancy()`.
  - Filament's `->tenant()` scopes Resources only. It is navigation, not isolation —
    never rely on panel tenancy for a query Filament doesn't own.
- **Tenancy reaches past queries** (step 32): cache keys carry the shop; rate-limiter
  buckets key shop + IP; storage objects are written under `shops/{id}/…`. A tenant is
  set only by the two established entry points — `ResolveTenant` on the API, Filament's
  tenancy mirrored into `TenantContext` by `SyncTenantContextFromFilament` on the
  panels — never ad hoc in a controller or page. A new Filament page or widget states
  its scoping in a top-of-class comment; "deliberately cross-shop, Studio only" is a
  legitimate answer, written once. Per-shop config resolution (shop row → env → off) is
  step 33's resolver; until it lands, a *new* bare `config('services.telegram.*')` /
  `config('app.payment.*')` read is a bug — the env blocks are platform defaults, never
  a shop's live value.
- **One backend serves every shop.** One bad deploy affects all of them, so the
  `develop` → `main` promotion gate matters more under pooling, not less. Never merge a PR
  yourself, and never skip verification because a change "looks small".

## 9. When the direction is wrong

If two correction rounds have not fixed a problem, stop editing. Report the violated
assumption, propose a spec or `PRODUCT.md` amendment, and wait. Do not keep patching
the implementation against a wrong direction — correct the context instead.
