# ADR-0002: Deployment topology — reconciling the stated plan with what PR #58 built

**Status:** Accepted — 2026-08-11
**Date:** 2026-08-11
**Deciders:** Naing Aung Linn
**Unblocks:** the multi-tenancy **routing** and **shop-onboarding** steps

> Step files are referenced by name, not number. PR #58 notes that its `23`/`24`/`25`
> collide with develop's existing `23` and will renumber on merge — so
> `*-multi-tenancy-routing.md` is the durable way to point at them.

## Context

Two descriptions of the same system are currently in circulation, and they are inverted
on both axes.

**Stated verbally (2026-08-11):** "one frontend with many backends as multi-tenant."

**Built in PR #58 and decided in `prompts/multi-tenancy-design.md`:**

| Layer | What #58 does |
|---|---|
| Frontend | **N Vercel projects**, one repo, differing only in `NEXT_PUBLIC_SHOP_SLUG` and domain (ADR-004, Option A) |
| Backend | **One** Heroku dyno, **one** `essential-0` Postgres, shared across all shops |
| Isolation | Pooled — `shop_id` column on tenant tables + `BelongsToShop` global scope that throws when no tenant is set |

That is **many frontends, one backend**. The stated plan is **one frontend, many
backends**. The design doc also records an earlier statement of yours — *"multiple
frontends, one admin panel"* — which matches what was built.

So one of three things is true, and which one changes the next three steps of work:

1. You are describing #58 loosely ("one frontend *codebase*, many shop *tenants*"), in
   which case nothing changes and this ADR just gets closed.
2. You have changed your mind about the frontend (one Vercel project resolving tenant
   from the hostname at runtime) — a contained change, ADR-004's Option B.
3. You have changed your mind about the **backend** (one Laravel instance + database per
   shop) — which invalidates most of PR #58.

Only #3 is expensive. It is worth being explicit before the routing step lands on top of
the current seam.

## The backend question is the one that matters

### Option A: Pooled — one instance, one database, `shop_id` everywhere *(what is built)*

| Dimension | Assessment |
|---|---|
| Complexity | Medium — one scope to get right, applied everywhere |
| Cost per shop | ≈ $0 marginal |
| Isolation | Logical. One missed scope leaks. Mitigated by fail-loud + isolation tests |
| Ops burden | One deploy, one migration run, one thing to monitor |
| Blast radius | One bad deploy affects every shop |

**Pros:** Meets N1/N2 (~$12/mo total). F6 — onboarding is a row insert, not a deployment.
`api.cornerarea.me` can point at the one app (N7). Cross-shop studio views (F2) are a
query, not a fan-out. 2,126 lines of it already exist and the isolation test is real.
**Cons:** Isolation is enforced by code, not by infrastructure. A noisy shop shares a dyno
with everyone. A per-shop restore from backup is genuinely hard.

### Option B: Silo — one Laravel instance + one database per shop

| Dimension | Assessment |
|---|---|
| Complexity | Low per instance, high in aggregate (N deploys, N migration runs, N env sets) |
| Cost per shop | ~$12/mo **each** (Basic dyno $7 + `essential-0` Postgres $5) |
| Isolation | Physical. A missed scope cannot leak — there is nothing to leak into |
| Ops burden | N of everything, for a solo maintainer |
| Blast radius | One shop at a time |

**Pros:** The strongest isolation story, and the easiest to explain to a customer.
Per-shop restore is trivial. A shop can be exported and handed over cleanly.
**Cons — and these are decisive here:**

- **Cost inverts the business model.** ~$12/mo of infrastructure per shop against a
  Myanmar decant reseller's willingness to pay. At a plausible 25,000 Ks/mo (~$6) the
  gross margin is *negative* before you do any work. Even at 50,000 Ks/mo it is thin, and
  it does not improve with scale — silo cost is linear by construction.
- **N7:** `api.cornerarea.me` can only point at one Heroku app. Every additional shop
  needs its own API hostname and its own TLS.
- **F6 breaks:** onboarding becomes a deployment, not minutes.
- **F2 breaks:** cross-shop views become N HTTP calls, not a query.
- **N5/N6:** unattended release-phase migrations across N apps, run by one person.
- It discards essentially all of PR #58.

### Option C: Bridge — one instance, one database *per shop* (Postgres schema or DB per tenant)

Middle ground: one dyno, N schemas. Cheaper than B, stronger isolation than A.

**Pros:** No `shop_id` scope to forget. One deploy. Cheaper than a database per app.
**Cons:** Migrations must run N times, and Heroku `essential-0` is one database — you would
be running many *schemas* inside it, which is the same physical failure domain as A with
more moving parts. Cross-shop views (F2) become `UNION`s over N schemas. Laravel tooling
support (`stancl/tenancy`) is good, but it is a second framework's worth of concepts for
a solo maintainer.

## Recommendation

**Keep Option A (pooled).** Not because silo is wrong in general — for a compliance-bound
or enterprise product it is often correct — but because in *this* product's constraints it
loses on the one axis that decides the business: a per-tenant infrastructure cost of ~$12/mo
against a Myanmar small-business price point cannot be made to work, and it is a cost that
never amortises.

The honest cost of Option A is that isolation is a code property. That cost is already
being paid down well: `BelongsToShop` throws instead of returning everything, `shop_id` is
qualified for joins, `withoutTenancy()` restores state in a `finally`, and
`TenantIsolationTest` covers scoped reads, same-key coexistence, cross-shop ID resolution,
throw-on-no-context, and per-shop cache keys. That is a stronger position than most
production pooled systems start from.

**If isolation later needs to be physical for a specific customer**, the pooled model does
not prevent it — one large shop can be lifted to its own instance while the rest stay
pooled. That option stays open. The reverse (starting siloed and pooling later) means
merging N databases with colliding primary keys, which is far worse.

## Decision

**Option A (pooled): one backend, many storefronts.** Confirmed 2026-08-11. Reading #1 was
correct — "many backends" was loose phrasing for "many shop tenants", not an instance per
shop. PR #58's seam stands as built; Steps 24 and 25 proceed as specced.

Larger infrastructure comes later, deliberately, along the ramps below — not by reversing
this decision.

**Say it as "one backend, many storefronts."** The inverted phrasing has now entered the
record twice. Fixing the shorthand is cheaper than re-litigating the architecture a third
time.

## Consequences

- The routing and shop-onboarding steps proceed as specced.
- The isolation invariant becomes rule **#0** in `AGENTS.md` — above money, because a
  money bug costs one shop money and an isolation bug shows one shop's customer another
  shop's orders.
- One bad backend deploy affects every shop. The `develop` → `main` promotion gate and the
  two-job CI matter *more* under pooling, not less.
- Per-shop backup/restore needs a documented procedure before the first paying customer.

## Exit ramps — how "bigger infrastructure later" stays cheap

Pooling does not lock you in, but it stays reversible only if a few properties are
preserved deliberately. Take the ramps in this order; each is independent of the next.

| # | Ramp | Trigger | Code change |
|---|---|---|---|
| 1 | Bigger dyno / bigger Postgres | p95 latency climbs, or connection limit hit | **none** — config only |
| 2 | Worker dyno, queue off `sync` | any request blocked on Telegram, PDF, or CSV work | small — dispatch instead of call |
| 3 | Redis for cache and sessions | cache invalidation across dynos gets wrong | small — cache keys are already per-shop |
| 4 | Read replica | reporting queries slow the storefront | medium — a read connection |
| 5 | Lift one large shop to its own instance | one shop's volume dominates, or a customer contractually requires physical isolation | medium — needs the export path below |

**Ramp 2 is the one to watch, and it is pooled-specific.** The queue is `sync` today. Under
one shop, a slow synchronous Telegram call or PDF render blocked *that shop's* request.
Under pooling it occupies a worker on the shared dyno, so one shop's bulk CSV import
degrades **every** shop's storefront. That is the most likely first real incident, and it
is a $7/mo fix — not an architecture problem.

**What to preserve so Ramp 5 stays a weekend, not a rewrite**

These are already true. Keeping them true is the whole cost of holding the option open:

- Every tenant-owned table keeps its `shop_id`. No exceptions, including tables that feel
  obviously single-shop at the time.
- **No foreign key crosses a shop boundary**, except into genuinely global reference data
  (`delivery_townships`). This is what makes "select everything for shop N" a clean,
  self-consistent export. It is the single most important property on this list.
- Storage stays prefixed by shop (`{shop}/...` in R2, public and private).
- Cache keys, Telegram credentials, and any future third-party credential stay per-shop.
- The `withoutTenancy()` budget stays enumerated in design-doc §8. Every call site is a
  cross-shop coupling, and every one of them is something that must be reasoned about
  when a shop moves out. Growth in that list is the leading indicator that Ramp 5 is
  getting more expensive.

Lifting a shop *out* is easy (its IDs come with it and cannot collide in an empty
instance). Merging a shop back *in* is hard (primary keys collide). So the direction of
travel is one-way — which is fine, because it is the direction you would actually go.

**Worth building before the first paying customer:** a `shop:export` artisan command that
dumps one shop's rows. Not because you need it yet, but because writing it is how you find
out whether the no-cross-shop-FK property actually holds. It is also the answer to "what
happens to my data if I leave", which a customer will ask.

## Action items

1. [x] Confirm which of the three readings is correct — reading #1, pooled, confirmed
2. [x] Close as **Accepted**; use "one backend, many storefronts" as the shorthand
3. [ ] Move `prompts/multi-tenancy-design.md`'s ADR-001…004 into `docs/adr/` — architecture
       decisions with an indefinite lifetime, currently at line 102 of a 698-line file in a
       prompts folder
4. [ ] Resolve the Vercel Production Branch question ADR-004 flags as unverified, record it
       in `DEPLOY.md`, before adding a second project
5. [ ] Add plan/trial columns to `shops` and decide what an expired shop's storefront serves
6. [ ] Add a `no cross-shop foreign key` line to the migration checklist in
       `backend/AGENTS.md` — it is now load-bearing for Ramp 5, not just for correctness
7. [ ] Budget Ramp 2 (worker dyno, $7/mo) as the first infrastructure spend after revenue,
       ahead of a bigger dyno
