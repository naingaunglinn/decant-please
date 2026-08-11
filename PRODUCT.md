# PRODUCT.md — Decant Please!

The product source of truth. An agent reads this to answer *what should exist and why*.
`AGENTS.md` answers *how to work here*. Step specs live in `prompts/NN-*.md`.
Multi-tenancy design and its ADRs live in `prompts/multi-tenancy-design.md`.

---

## What this is

A **multi-tenant SaaS** for perfume decant resellers in Myanmar. One codebase serves
many independent shops. Each shop gets a storefront on its own domain and an admin panel
scoped to its own data; the studio operator sees across all of them.

**The shorthand is "one backend, many storefronts"** — one Laravel instance and one
database serving every shop, with a separate storefront deployment per shop domain.
Writing it the other way round has caused confusion twice; don't.

Formerly a single-decanter tool. That is no longer true, and any statement in an older
doc describing it as "a one-person business tool" or listing multi-tenancy as out of
scope is superseded by this file.

## WHO — three roles, not one

| Role | Who | Uses |
|---|---|---|
| **Studio operator** | you | cross-shop views, onboarding a new shop, billing |
| **Shop owner** (the paying customer) | a decant reseller in Myanmar. Not technical. Runs the business on TikTok/Facebook today. Phone-first. | Filament admin, scoped to their shop |
| **End customer** | buys decants from a shop. No account, mobile-first. | that shop's storefront |

The product is sold to the **shop owner**. Their end customers are the reason it's worth
paying for, but they are not the buyer — every product decision resolves in favour of the
shop owner's day when the two conflict.

## WHY

Decant resellers lose orders in DMs: customers can't see what's in stock, and the seller
re-answers the same question all day. Existing e-commerce platforms don't fit — no
Myanmar payment rails, no decant-sized pricing model, no Kyat-native money handling, and
a monthly price that doesn't work at Myanmar small-business scale.

## WHAT

Per shop, the feature set is what already exists — storefront (browse → guest checkout →
track) and Filament admin (catalog, order accept/reject, production schedule, invoices,
expenses/P&L, delivery zones). See `README.md` for the current built list.

**What multi-tenancy adds:**

| # | Requirement |
|---|---|
| F1 | One admin panel serves every shop; each shop owner sees only their own data |
| F2 | The studio operator sees all shops and can act on any of them |
| F3 | Each shop has its own storefront on its own domain |
| F4 | Each shop has its own catalog, orders, promo codes, images, payment details |
| F5 | Order alerts: shared studio Telegram bot (free tier) or the shop's own bot (paid tier) |
| F6 | Onboarding a new shop takes minutes, not a deployment |

## RULES

**Tenancy — the invariant that outranks everything else**

- Every tenant-owned model uses `BelongsToShop`. Its global scope **throws
  `TenantNotSetException`** when no tenant is set — it never silently returns all shops.
- `shop_id` is always compared **qualified** (`orders.shop_id`), because joins do not
  apply the joined model's scopes.
- `TenantContext::withoutTenancy()` is a **budgeted** escape hatch. Every call site is
  listed in the design doc §8 and asserted in `TenantIsolationTest`. Adding one is a
  design decision, not an implementation detail.
- Geography (townships) is global; **price and activation are per-shop**.
- Tracking codes stay globally unique across shops, even though lookup is shop-scoped.

**Everything else, unchanged**

- Stack is fixed: Laravel 13 · Filament 5 · PHP 8.3 · Postgres 17 · Next.js 16 ·
  Node 24 · TypeScript 5 · Tailwind 4.
- Currency is integer Myanmar Kyat, displayed `50,000 Ks`.
- Prices, totals, and delivery fees are derived server-side at submission, never sent by
  the client. Order line values and address snapshots are frozen at write.
- Every control is ≥16px — below that, iOS Safari zooms on focus.
- Local ports: API 8010, storefront 3001, Postgres 5442.

**Cost constraints are a product rule here, not an ops detail**

Infrastructure stays near **$12/mo total** (one Heroku Basic dyno, one `essential-0`
Postgres, no Redis, `sync` queue) until revenue justifies more. Any design justified by
throughput should be rejected — ten shops at low tens of orders a day is a few hundred
orders a day. **Isolation and cost are the design drivers. Scale is not.**

## Commercial model

- **3-month free trial**, then paid. Trial users are asked for a review during the free
  period.
- Free tier uses the shared studio Telegram bot; paid tier can use the shop's own bot (F5).

> **This is not purely marketing.** A trial with an end date is *system state*. Today
> `shops` carries only `slug`, `name`, `is_active` — so "trial expired", "suspended for
> non-payment", and "owner closed the shop temporarily" are all the same boolean, and
> nothing knows when a trial started. Before the first paying customer, `shops` needs a
> plan/trial concept and a decision about what an expired shop's storefront does
> (404? read-only? a notice?). See open questions below.

## NON-GOALS — do not build unless explicitly asked

- Payment gateway or card processing **for end customers**. Payment confirmation between
  shop and customer stays manual and offline.
- End-customer accounts or login on the storefront.
- Chat or messaging features.
- A **marketplace — product scope.** No customer-visible multi-shop anything: a storefront
  shows exactly one shop's catalog, and **no customer ever sees two shops**. No shared
  shopfront, no cross-shop search, no shared cart.
  *Infrastructure scope is a separate axis and is not excluded* — one deployment serving
  many shops is the whole design (ADR-0002). Do not read shared multi-shop infrastructure
  as violating this bullet, and do not read this bullet as permission to put two shops in
  front of one customer. (This is `CLAUDE.md` §8's v23 split, carried forward verbatim in
  intent — it is the single most load-bearing sentence in this file.)
- Per-bottle volume tracking, batch identity, FIFO/weighted-average COGS, consumables
  costing, double-entry books, balance sheet, budgets, tax automation.
- End-customer notifications (email/SMS/messaging). Shop-owner Telegram alerts are in
  scope and shipped; the tracking page is the customer's only channel.
- Anything justified by scale rather than by isolation or cost.

## DONE

A feature is done when the storefront half has browser evidence, the backend half has
tests, neither trusts the other's numbers, and **nothing leaks across shops**.
Per `VERIFY.md`:

- `composer test` green, **including `TenantIsolationTest`**
- any new tenant-owned model has an isolation test proving two shops can hold rows with
  the same natural key without colliding or leaking
- `npm run typecheck` and `npm run lint` green
- the relevant `verify-*.mjs` browser check green, with screenshots
- Postgres portability check green if the change touches queries
- loading / empty / error states render, at 375px and 1440px, no console errors

## Open questions

1. ~~Storefront topology~~ — **resolved 2026-08-11**: one backend, many storefronts
   (pooled). See `docs/adr/0002-topology-reconciliation.md`, which also records the exit
   ramps for larger infrastructure later.
2. **Plan and trial state on `shops`** — what columns, and what an expired shop serves.
3. **Per-shop theming** — design-doc §10 proposes a `ThemeProvider`; the token set is
   currently fixed and shared. How much can a shop change without breaking the design
   language it's paying for?
4. **Per-shop backup and restore.** Under pooling there is one database. A shop asking
   "what happens to my data if I leave" needs an answer before the first paying customer —
   and writing the export is how you verify no foreign key crosses a shop boundary.
