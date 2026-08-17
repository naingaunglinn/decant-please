---
name: decant-tenancy
description: Tenancy invariants for Decant Please!. Consult whenever writing a query, a Filament page or widget, a cache key, a route, an artisan command, or any configuration read. Every model belongs to a shop; this file says what that obliges.
---

# decant-tenancy

The `decant-money` precedent: one file, standing rules, consulted before writing code
rather than re-derived per step. `CLAUDE.md` governs product decisions; this governs the
one invariant that every step can silently break.

**The invariant:** a shop's data is visible only under that shop, and configuration
resolves per shop. Everything below is a consequence.

## Before writing a query

Every model belongs to exactly one shop. Scoping is [record the step-32 idiom here].

- A Filament **Resource** inherits panel tenancy. A **widget**, **custom page**,
  **controller registered via `authenticatedRoutes()`**, **artisan command**, **seeder**,
  or **job** does not. If what you're writing is one of those, scope it explicitly and
  say so in a comment at the top of the class.
- "Deliberately cross-shop, Studio only" is a valid answer. Write it in that comment,
  once, rather than leaving the reader to infer it.
- Aggregations that sum in PHP over fetched rows (the v19 margin stat, the v20 P&L)
  inherit whatever scope the *fetch* had. Check the fetch, not the sum.

## Before writing a cache key

The key carries the shop. An unkeyed catalog or `/meta` cache serves one shop's data
under another's slug, with no attacker and no unusual request — the cheapest possible
leak. Busting on save busts that shop's key only.

## Before writing a public lookup

Tracking codes are globally unique; that is not the same as safe. `tracking_code` +
`phone` + **shop** is the gate. A mismatch on any of the three returns the same generic
404 — the endpoint is not an oracle for the code, the phone, or the existence of a shop.

Promo codes are scoped too: the same code string can exist in two shops and must
evaluate against the requesting one. `PromoCode::evaluate()` stays the one decision site;
the *lookup* feeding it is what needs the clause.

## Before reading configuration

Resolution is **shop row → env → off**, through the one resolver. A bare
`config('services.telegram.*')` or `config('app.payment.*')` in new code is a bug. The
env blocks are platform defaults; they are never the live value for a shop. Blank at both
levels means the feature is off for that shop, silently — the v11 rule, unchanged.

## Before writing a file to a disk

Objects are prefixed by shop. Proofs go to the private proofs disk and are served only by
the streaming route, which checks the tenant as well as the session — auth is not
authorization for *this* shop.

## Before writing a destructive command

Anything that deletes across a table takes `--shop=` and refuses without it. Bulk deletes
fire no model events, so the associated files need explicit cleanup (the v12 lesson).

## Before opening a PR

- `TenantIsolationTest` covers the surface you touched, or gains a case that does.
- New unique constraints are composite with `shop_id` — slugs are unique per shop, not
  globally.
- Rate limiters key on shop plus IP, so one shop's traffic can't spend another's budget.
- If the change touches `LIKE` or an alias in `ORDER BY`, run
  `sh backend/scripts/verify-postgres-portability.sh` — SQLite passes things Postgres
  rejects (the v6 lesson), and the isolation suite runs on SQLite.

## What this file does not license

Cross-shop customer-facing surfaces. No shared cart, no directory, no search across
shops. See §8 — the marketplace stayed out when multi-tenancy came in, deliberately.
