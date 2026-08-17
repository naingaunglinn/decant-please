# ADR-0004: How a shop's storefront is addressed

**Status:** Proposed
**Date:** 2026-08-17
**Deciders:** repo owner
**Supersedes:** nothing (drafted externally as "0001"; renumbered on placement — 0001–0003 exist)
**Must be reconciled with:** `prompts/multi-tenancy-design.md` ADR-004 (storefront
topology), which is *accepted* and chose N Vercel projects — one per shop, addressed by
its own domain via `NEXT_PUBLIC_SHOP_SLUG` — precisely to keep theming build-time and
CORS static. This proposal's premise ("one Next.js app, subdomain via Host header") is
that decision's rejected Option B revisited; the session-5 pressure-test should argue
them against each other, not treat this page as green-field.

## Context

Steps 23–25a made the API and admin panel tenant-aware: `/api/v1/{shop}/*` resolves
through `ResolveTenant`, and the Studio registry can register a shop. Registering a shop
implies giving its decanter a URL to put in their TikTok bio — and that part isn't
decided.

The constraints that shape it:

- **One Next.js app on Vercel**, with a single `NEXT_PUBLIC_API_URL` and a single
  `NEXT_PUBLIC_SITE_URL` used for canonical and OG metadata.
- **`config/cors.php` allows exactly one origin**, `FRONTEND_URL`, which is also what the
  admin's "View on site" links point at.
- The customers are Myanmar buyers arriving from a TikTok or Facebook link. They will
  not type the URL. Shareability and looking legitimate matter; memorability matters less.
- **No customer accounts and no cookies carrying identity** on the storefront — cart is
  `localStorage`. This removes the usual cookie-scoping argument against a shared origin.
- The decanters already have their own brand identities on social. "Your shop, on our
  platform" is a different pitch from "a page on someone else's site", and the pitch is
  the product here.
- Ops budget is one person. Anything requiring per-shop manual DNS work caps how many
  shops can be onboarded in an afternoon.

## Decision

**Subdomain per shop (`{shop}.decantplease.com`), resolved through a `shop_domains`
table rather than the `shops.slug` column**, so a custom domain per shop is later a row
in that table and not a re-architecture.

`ResolveTenant` becomes the single site that turns *either* a path segment *or* a `Host`
header into a shop. The API keeps its `/api/v1/{shop}/*` shape untouched — this decision
is about the storefront's public face, and the API's explicit slug segment is a feature,
not a thing to hide behind a header.

## Options considered

### Option A — path-based, `decantplease.com/{shop}`

| Dimension | Assessment |
|---|---|
| Ops | None. No DNS, no certificates, one Vercel project as-is. |
| CORS | Unchanged — one origin forever. |
| Time to ship | Hours. |
| Decanter's brand | Weak. Reads as a directory listing. |
| SEO | Shops share a domain's authority — helps a new shop, means one shop's spam hurts the others. |
| Slug changes | Cheap; one redirect rule. |

**Pros:** cheapest by a wide margin; the storefront's routing already speaks in path
segments; zero new failure modes.
**Cons:** the platform is visible in every URL a decanter shares, which is exactly the
opposite of the pitch; no path to a custom domain without redoing this later.

### Option B — subdomain, `{shop}.decantplease.com`

| Dimension | Assessment |
|---|---|
| Ops | Wildcard DNS record + wildcard certificate, configured once. |
| CORS | Becomes a per-request check of `Origin` against the domains table. |
| Time to ship | Days. |
| Decanter's brand | Good — reads as *their* shop. |
| SEO | Each shop is its own site; authority isn't shared in either direction. |
| Slug changes | Need a redirect; the slug is now public identity, not an internal key. |

**Pros:** the brand story works; wildcard setup is one-time, so onboarding stays
self-service; a clean seam toward custom domains.
**Cons:** the slug becomes load-bearing and public — renaming it breaks shared links;
CORS and canonical URLs both become dynamic; local dev needs a wildcard host entry.

### Option C — custom domain per shop

| Dimension | Assessment |
|---|---|
| Ops | Per-shop domain verification and certificate issuance; Vercel Domains API. |
| CORS | Dynamic, same as B. |
| Time to ship | Weeks, and permanently more support load. |
| Decanter's brand | Strongest — the platform is invisible. |
| SEO | Fully independent. |
| Slug changes | Irrelevant; the domain is identity. |

**Pros:** what a decanter with an established brand actually wants.
**Cons:** each onboarding gains a DNS conversation with someone who may not own their
domain yet; certificate failures become a support queue; it is the wrong first move for
a platform with two shops.

## Trade-off analysis

A and C are the honest endpoints of the same axis: A optimizes for the operator's time,
C for the decanter's brand. B is not a compromise between them so much as a way of
**deferring the choice cheaply** — a wildcard certificate is configured once and never
touched again, and if a decanter later wants their own domain, the domains table already
exists to hold it.

The thing that makes B safe is putting resolution in a table from day one. Resolving from
`shops.slug` would work identically today and would make option C a migration; a
`shop_domains` row with a `primary` flag makes it an insert.

The argument for A is real and shouldn't be dismissed: with two shops, the platform has
no idea yet whether decanters care about the URL. If the next two onboardings say they
don't, A was correct and B was premature. The tiebreaker is that B's cost is a one-time
DNS setup rather than ongoing work, and that migrating A → B later means breaking every
link already shared into TikTok comments — a link that dies is worse than one that looks
generic.

## Consequences

**Easier**

- Onboarding a shop stays self-service: register in Studio, the subdomain resolves.
- Per-shop sitemap, robots, canonical, and OG metadata all key off the request host.
- Custom domains become additive.

**Harder**

- `config/cors.php` stops being a static allowlist. The origin check queries the domains
  table on every preflight, so it needs caching, and a cache bug becomes a "checkout is
  broken for one shop" incident.
- `NEXT_PUBLIC_SITE_URL` can no longer be a build-time constant; canonical/OG metadata
  must be derived per request.
- Local dev needs `*.decant.localhost` or equivalent, and the fixed-port convention in §7
  needs a line about it.
- Slug edits need a redirect path. Consider making the slug immutable after first order,
  with a Studio-only override that writes the redirect.

**To revisit**

- If three or more decanters ask for their own domain, promote C from "later" to next.
- If shops share a domain's SEO in a way that turns out to matter (either direction),
  this decision is the lever.

## Action items

1. [ ] `shop_domains` table — shop, host, `is_primary`, verified-at.
2. [ ] `ResolveTenant` accepts host *or* path segment; one resolution site, cached.
3. [ ] Dynamic CORS origin check against the table, with a cache and a test that a
       non-shop origin is rejected.
4. [ ] Per-request canonical / OG / sitemap host on the frontend.
5. [ ] Wildcard DNS + certificate; document in `DEPLOY.md`.
6. [ ] Local dev wildcard host, documented alongside the fixed ports in §7.
7. [ ] Decide slug mutability; implement the redirect if mutable.
