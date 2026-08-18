# ADR-0004: How a shop's storefront is addressed

**Status:** Accepted — as amended, 2026-08-18 (issue #88)
**Date:** proposed 2026-08-17 (subdomain-first draft); amended and accepted 2026-08-18
after the tenancy-kit session-5 pressure-test and the owner's decision
**Deciders:** repo owner
**Supersedes:** `prompts/multi-tenancy-design.md` §6 ADR-004 (N Vercel projects, one per
shop, build-time `NEXT_PUBLIC_SHOP_SLUG`). This is the "contained frontend change"
ADR-0002 anticipated as its reading #2 — taken deliberately. ADR-0002's **backend**
decision (pooled: one instance, one database, `BelongsToShop`) is untouched, and its
exit ramps stand.
**History:** drafted externally as "0001"; renumbered on placement (0001–0003 exist).
The original draft proposed subdomains resolved from a domains table; the pressure-test
showed its real blast radius (below) and the owner chose a fourth option the draft had
not considered, recorded here as the decision.

## Context

Steps 23–25a made the API and admin panel tenant-aware: `/api/v1/{shop}/*` resolves
through `ResolveTenant`, and the Studio registry can register a shop. Registering a shop
implies giving its decanter a URL to put in their TikTok bio — and that part wasn't
decided. The constraints:

- The decanters already have their own brand identities on social. **"Your shop, on our
  platform" is a different pitch from "a page on someone else's site"** — a custom
  domain is part of the core business model (paid tier), not a future nicety.
- Customers arrive from TikTok/Facebook links on Myanmar mobile connections.
  Shareability, legitimacy, and page speed matter; memorability doesn't.
- Ops budget is one person; infrastructure stays near $12/mo. Onboarding must stay
  "minutes, not a deployment" (F6).
- No customer accounts, no identity cookies — cart is `localStorage`, so the usual
  cookie-scoping argument against shared infrastructure doesn't apply.

What the session-5 pressure-test added to the original draft:

- The **incumbent** (N Vercel projects, one per shop) was missing from the draft's own
  options table. At 20 shops it costs: 20 builds per push and per promotion, 20
  hand-set `NEXT_PUBLIC_SHOP_SLUG`s where one typo serves another shop's catalog under
  the wrong domain (an isolation failure no test can see), 20 Production-Branch
  settings, and onboarding that is literally a deployment.
- Any *single-deployment* design that resolves the tenant from the request host and
  keys nothing else re-creates the step-32 cache leak one layer up: the Next.js Full
  Route Cache keys by pathname, so `/fragrance/sauvage` rendered for shop A would be
  served under shop B's domain — no attacker required, and invisible to both
  `TenantIsolationTest` and the browser-evidence scripts.
- Making every route host-aware via request headers turns the whole storefront
  dynamically rendered — a function invocation per page view instead of cached HTML.
- Vercel preview deployments arrive on `*.vercel.app` hosts that map to no shop — the
  same failure that killed origin-based tenancy for the API (design-doc ADR-001,
  option C).

## Decision

**One centralized admin, one shared storefront deployment, N tenants, N domains.**

```
Admin (Filament, unchanged)          Storefront (one Next.js deployment)
admin host, /admin/{shop}, /studio     client-a.com   client-b.com   {c}.decantplease.com
            │                                 │              │              │
            └────────── API / DB ◄────────────┴──────────────┴──────────────┘
                                          Host → shop_domains → tenant
                                          internal rewrite → /{tenant-key}/…
```

- **Every shop is addressed by its own public domain.** Custom domains
  (`client-a.com`) are first-class; platform subdomains (`{shop}.decantplease.com`)
  are supported by the **same mechanism** — the two are commercial tiers
  (trial/free vs paid), not different architectures.
- **The incoming `Host`, validated against the `shop_domains` table, is the only
  authoritative tenant signal on the storefront.** Not a client-supplied slug, query
  parameter, cookie, or `localStorage` value. One domain maps to one shop; a shop may
  hold several domains with one marked primary.
- **The request is rewritten internally to a tenant-keyed route** (`/{tenant-key}/…`,
  the `[shop]` segment). The browser URL never shows it — `client-a.com/fragrance/x`
  stays `client-a.com/fragrance/x`. The internal segment exists to make every cache
  key carry the tenant *by construction*: `/{a}/fragrance/sauvage` and
  `/{b}/fragrance/sauvage` can never share a rendered-HTML cache entry. (Whether the
  segment value is the shop slug or the validated host is an implementation choice —
  both satisfy the invariant; the host variant keeps the rewrite I/O-free. The
  implementation plan decides and records it.)
- **Performance is a first-class constraint: only the rewrite layer runs
  per-request.** Pages keep today's static/ISR + 60s data-cache profile; tenant
  resolution must not convert the storefront to global dynamic rendering. Anything
  genuinely request-bound is isolated to the smallest possible layer.
- **Fail closed.** An unknown host — including `*.vercel.app` previews and local dev
  hosts — resolves only through an explicit mapping (a seeded/dev row or a deliberate
  preview mapping) or returns a 404. There is no fallback tenant, ever; a preview must
  never silently serve a production shop.
- **The backend scope remains the security boundary.** The API stays
  `/api/v1/{shop}/*` through `ResolveTenant`, and `BelongsToShop` keeps enforcing
  isolation regardless of what the frontend resolves. Frontend routing is a caching
  and addressing mechanism, never the isolation mechanism.
- **Per-tenant SEO derives from the tenant's primary domain** — canonical, Open
  Graph, sitemap, robots — never from a global `NEXT_PUBLIC_SITE_URL`. Secondary
  domains redirect (308) to the primary.
- **No per-tenant Vercel projects.** The N-projects model is decommissioned once this
  ships; a per-tenant project returns only if a future requirement (per-tenant build
  isolation, a contractual fork) justifies it explicitly.

## Options considered

| | Option | Fate |
|---|---|---|
| A | Path-based `decantplease.com/{shop}` | Rejected — one origin/robots/sitemap makes the SERP a de-facto cross-shop directory (the NON-GOALS' most load-bearing exclusion), shops enumerable by path, shared browser state, and the exit breaks every shared link, ×N shops |
| B | Subdomain-first, host resolved at runtime (the original draft) | Amended — right direction, but as drafted it fused public addressing with internal keying: route-cache leak or full dynamic rendering, broken previews, and it deferred custom domains that the business model requires now |
| C | Custom domain per shop, one Vercel project each (the incumbent, design-doc §6) | Superseded — 20 builds per push, hand-drawn isolation boundaries in a dashboard, onboarding = a deployment (breaks F6) |
| **D** | **One deployment; validated Host → tenant; internal tenant-keyed rewrite; custom domains and subdomains through one mechanism** | **Accepted** |

D is B's public face on A's internal mechanics: the customer sees their own domain (C's
brand story), the app keys every route and cache entry by an explicit tenant segment
(A's cache-safety and the repo's explicit-tenant idiom, ADR-001), and there is one
build, one project, one thing to watch (B's ops story).

## What D costs, honestly

- Every storefront route moves under the internal tenant segment — a mechanical but
  wide frontend migration, plus a `proxy.ts` that must exclude static assets and must
  not do per-request I/O.
- CORS stops being a static allowlist: the API must accept N shop origins, resolved
  from `shop_domains` with a short cache. A cache bug here is a "checkout broken for
  one shop" incident — it ships with its own test.
- On the subdomain tier the slug becomes public identity — renaming breaks shared
  links. Slug immutability (or a redirect path) must be decided during implementation.
- Each custom domain still has to be attached to the one Vercel project for TLS — a
  small per-paying-shop ops step (dashboard or Domains API), bounded and chargeable;
  the wildcard for subdomains is attached once.
- What the platform apex `decantplease.com` itself serves is now a real question
  (marketing page? redirect?) — deliberately left open, tracked below.

## Consequences

**Easier:** onboarding stays self-service (register in Studio, map a domain — F6
holds); per-shop sitemap/robots/canonical key off data, not env; one build per push at
any shop count; previews and local dev work through explicit host rows; custom domains
are an insert plus a Vercel domain-attach, not a re-architecture; the cache-leak class
is closed structurally, and testable.

**Harder:** dynamic CORS (cached, tested); the frontend route migration touches every
page; `NEXT_PUBLIC_SHOP_SLUG`/`NEXT_PUBLIC_SITE_URL` are retired from the storefront
(build-time per-shop config dies with the N-projects model); `DEPLOY.md`'s frontend
half is rewritten.

## Action items (the implementation backlog — sequenced plan lives with issue #88)

1. [ ] `shop_domains` migration + model: `shop_id`, `host` (unique), `is_primary`,
       `verified_at`. Platform-owned — **no** `BelongsToShop` (resolution runs before
       any tenant context exists), same footing as `shops`/`users` per AGENTS §8.
2. [ ] Public storefront-host resolution endpoint outside the `{shop}` route group
       (slug, shop name, primary host), throttled, generic 404 — no enumeration oracle.
3. [ ] Dynamic CORS from `shop_domains` with a short cache; a test that a non-shop
       origin is rejected and each mapped origin is accepted.
4. [ ] `proxy.ts`: host → internal tenant-keyed rewrite. I/O-free, static matcher
       excluding `_next`/assets while still covering `robots.txt`/`sitemap.xml`.
5. [ ] Route migration under the tenant segment; a cached (≤60s) resolver in `lib/`
       feeding pages, `generateMetadata`, and the API path — pages stay static/ISR.
6. [ ] Per-tenant metadata/sitemap/robots from the resolve payload's primary domain;
       nested metadata conventions (or route handlers) under the tenant segment;
       secondary → primary 308 at the tenant layout seam.
7. [ ] Preview/dev host policy: seed `localhost:3001` → the dev shop; preview hosts
       map only by deliberate row/env; everything else 404s. Never a fallback tenant.
8. [ ] Tests: backend resolution + CORS suites; a browser-evidence script asserting
       the step-32 cache case end-to-end — same pathname, two hosts, two different
       tenants' HTML; unknown host fails closed.
9. [ ] Studio: manage a shop's domains (registry integration belongs to step 34).
10. [ ] `DEPLOY.md`: single-project domain runbook (wildcard once, custom per paying
        shop), decommission the N-projects instructions; resolve slug mutability; decide
        what the platform apex serves.
