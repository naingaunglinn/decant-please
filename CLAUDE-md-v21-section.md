# CLAUDE.md v21 — drop-in sections

Merge these into `CLAUDE.md`. The version header at the top becomes **v21**, the current
`## 0. What changed in v20` is demoted to `## 0.1` in the existing pattern, and this
becomes the new `## 0`.

**Why this is urgent:** §8 currently tells every Claude Code session that multi-tenancy
is out of scope, while `/api/v1/{shop}` and `/studio/shops` are shipped. A source of
truth that contradicts the code is worse than no source of truth — it gets believed, the
same argument v20 made about a P&L missing expenses.

The prose below is a draft in the register the file already uses. Edit freely; the
bracketed parts need facts only you have.

---

## 0. What changed in v21

**v21** documents **multi-tenancy** — steps 23–25a, issues #57/#58 — the §8 exclusion
reversed by explicit ask, in the v8/v13 scoped pattern. What came in is *several shops on
one deployment, each with its own catalog, orders, books, and admin*. What stays out is a
**marketplace**: no cross-shop browsing, no shared cart, no customer accounts, no platform
billing. A decanter's shop is theirs and is reached on its own terms; the platform is
plumbing, not a storefront of its own.

- **A `Shop` at the root of the domain.** Every catalog, order, promo, expense, and
  setting belongs to exactly one shop. The public API is `/api/v1/{shop}/*`, resolved by
  `ResolveTenant`; an unknown or non-live shop returns the same generic 404 as an unknown
  fragrance — the tracking-endpoint principle, applied one level up. Admin is
  `/admin/{shop}/…` with a tenant switcher for studio accounts.
- **A Studio panel above the shops** (`/studio`, `is_studio` accounts) — the shop
  registry, and the only place that reads across tenants. Same users table and session
  guard as `/admin`: one login serves both.
- **Scoping is [global scope / explicit clause — record the step-31 decision]**, because
  Filament's resource-level tenancy does not reach widgets, custom pages, controllers
  registered through `authenticatedRoutes()`, artisan commands, or the response cache —
  and those are exactly where a leak is silent. `TenantIsolationTest` pins all of them;
  a new widget or page that skips the scope fails that file, not production.
- **Configuration resolves shop row → env → off.** The `PAYMENT_*`, `TELEGRAM_*`, and
  `SOCIAL_*` env blocks stay as platform defaults (the v14 fallback precedent), never as
  the live value. A shop with neither has the feature off — the v11 no-op rule, unchanged.
- **Accepted limits:** [media objects written before the shop-prefix change are not
  re-pathed; anything else the audit left]. Storefront addressing is
  `docs/adr/0001-storefront-addressing.md` — **[decided / still Proposed]**.

---

## Addition to §3 (Design language)

Append, after the existing customer-side language:

> **The admin and Studio panels are a second register, deliberately.** The storefront is
> `mist` and pale; the panels are dark — a working surface, not a shopfront, and the
> contrast is the point. They are not exempt from the palette: the Studio's accent is
> derived from `pine` at raised lightness (pine itself is unreadable as an accent on a
> dark surface), registered as a hex ramp on the Filament panel provider and read from
> `design-tokens.json`, which stays the one portable copy of the palette. **No
> Vite-compiled Filament theme** — the Heroku build path has no Node, the same constraint
> that vendored FullCalendar in v17.
>
> The hairline-bordered vial-label motif is customer-side. Panels use Filament's badges
> [confirm: exception granted / restyle to hairline], because a dense operator table is
> not a reference card. Written down here so it stays a decision rather than a drift.

---

## Addition to §4 (Domain model)

Insert **before** Brand, since everything now hangs off it:

> ### Shop — new in v21
>
> - `name`, `slug`, `status`: `onboarding` | `live` | `suspended` | `archived`
>   (replaces the v1 `is_active` boolean, which conflated "not launched", "owner paused",
>   and "we suspended them")
> - `suspended_reason`, `suspended_at`, `suspended_by`
> - `registered_at`, owner relation, settings relation
>
> Every other model in this section gains `shop_id`. **Slugs are unique per shop, not
> globally** — two shops both stocking Sauvage is the normal case. Tracking codes stay
> globally unique, but lookup is shop-scoped anyway: an unscoped lookup returns another
> shop's receipt, including the customer's name, phone, and address.

---

## Addition to §6 (Admin-side features)

> **Studio (v21).** The shop registry: register a shop (seeding its delivery geography
> and the owner's shop-confined login), see each shop's status, owner, and last activity,
> and open any shop's panel. Opening someone else's panel is logged — actor, shop,
> action, subject — and shown as a banner while it lasts, because that panel holds
> another decanter's customer phone numbers, transfer slips, and P&L. Read-only by
> default; taking control is a separate, separately-logged act.

---

## Addition to §7 (Conventions)

> - **Tenant scoping is not optional and not per-author.** Every new model belongs to a
>   shop. Every new query is scoped by [the idiom]. Every new cache key carries the shop.
>   Every new Filament page or widget states, in a comment at the top, how it is scoped —
>   including "deliberately cross-shop, Studio only", which is a legitimate answer given
>   in one place rather than assumed.
> - **Tenant resolution happens in `ResolveTenant` and nowhere else.** Not in a
>   controller, not in a page class. If a new entry point needs a shop, it goes through
>   the same resolver.
> - **Configuration reads through one resolver**, shop → env → off. A bare
>   `config('services.telegram.token')` in new code is a bug.

---

## Amendment to §8 (Out of scope)

Replace the multi-tenant line with:

> - ~~Multi-tenant / multi-decanter marketplace~~ — **amended in v21.** Several shops on
>   one deployment shipped in steps 23–25a; each is a self-contained business with its
>   own catalog, orders, books, and admin. What stays out is the **marketplace**:
>   cross-shop browsing or search, a shared cart, customer accounts (still §8 above),
>   platform-level billing or commission, and any surface that presents the shops to a
>   customer as a directory. A customer arrives at one decanter's storefront from that
>   decanter's own link and never learns the others exist. Reversing *that* is a product
>   change, not a step.
