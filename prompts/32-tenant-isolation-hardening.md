# Step 32 — Tenant isolation hardening

> Prerequisite reading: `CLAUDE.md` (all of it), `prompts/WORKFLOW.md`, `backend/README.md`.
> This step corrects and pins what steps 23–25a built. It adds **no** user-facing feature.

## Issue draft

**Title:** Tenant isolation — audit every scope seam, fix what leaks, pin with a test

**Body:**
Step 25a made the panel and API tenant-aware. This step verifies that scoping actually
holds on the surfaces that sit *outside* Filament's resource-level tenancy — widgets,
custom pages, panel-registered controllers, the public lookup endpoints, the response
cache, and the artisan commands — and adds `TenantIsolationTest` so a future step can't
silently un-scope one of them.

Two shops exist in production-shaped data. Nothing below is known to be broken; this is
an audit that ends in a fix list.

---

## Phase 1 — Audit (no code changes)

Produce this table before touching anything. One row per surface, file:line cited,
and a verdict of **scoped** / **unscoped** / **scoped but by accident** (e.g. it works
only because a relationship happens to be loaded from a scoped parent).

| Surface | Where | Question it must answer |
|---|---|---|
| Dashboard widgets | `app/Filament/Widgets/{OrderStats,RevenueChart,TopFragrances,UpcomingDecants,LowStock}` | Do these query models directly? A widget is not a Resource and does not inherit resource tenancy. |
| Gross-margin / discount / courier-cash stats (v19, #75, #77) | same directory | These sum in PHP over fetched rows — is the fetch scoped? |
| Production schedule | `app/Filament/Pages/ProductionSchedule`, `ProductionScheduleDay`, and `Order::productionScheduleFor()` | v17 moved the aggregation into the model *specifically* so tenancy could land in one place. Did it? |
| Payment settings page | `app/Filament/Pages/ManagePayment` | Single-row `ShopSetting` (v14) is a per-shop row now, or this page edits every shop's settings at once. (Fixing this is step 33; report it here.) |
| Expenses + P&L (v20) | Finance group resources/pages | Same question as the widgets, plus: does the month query filter by shop before summing? |
| Invoice controller | `app/Http/Controllers/OrderInvoiceController` | Registered via `authenticatedRoutes()`. Authenticated ≠ authorized for *this* shop. Does it verify `order->shop_id` against the resolved tenant? |
| Payment-proof stream | `app/Http/Controllers/PaymentProofViewController` | Same. This one streams a customer's bank slip — the #47/#12 file this whole design exists to protect. |
| Public lookup endpoints | `app/Http/Controllers/Api/{TrackOrder,CancelOrder,PaymentProof}` | The gate is exact `tracking_code` + `phone`. Codes are globally unique, so an unscoped lookup means shop B's URL returns shop A's receipt — name, phone, address. Add the shop clause; keep the same generic 404 (no oracle). |
| Promo evaluation | `PromoCode::evaluate()` | One decision site by design. Is the *lookup* feeding it shop-scoped? A code from A must not discount at B. |
| Catalog endpoints | `app/Http/Controllers/Api/{Fragrance,Brand,Meta}` | Slug resolution: `/{shop}/fragrances/{slug}` must resolve *within* the shop. |
| Slug uniqueness | `app/Models/Concerns/HasSlug` | Unique per shop now, not globally — otherwise two shops can't both stock Sauvage. Migration: composite unique `(shop_id, slug)`. |
| Response cache | wherever the 10-minute API cache and the meta-cache bust live | Every key must carry the shop. An unkeyed key serves A's catalog under B's slug — the highest-severity leak on this list because it needs no attacker. |
| Rate limiters | `AppServiceProvider` named limiters, `routes/api.php` | Per-IP buckets are shared across shops today. Key on shop+IP so one shop's traffic can't spend another's budget. |
| CSV import | `app/Support/CatalogImport` | Rows must be created against the importing tenant. Brand matching by name must not match another shop's brand. |
| CSV export | Orders + fragrances export actions | Scoped to the tenant, not just to the active tab/filters. |
| Media + proof paths | `MEDIA_DISK` / `PROOFS_DISK` writers | Prefix objects by shop (`shops/{id}/…`) so archive and delete are surgical later. Existing objects are not migrated — record that as an accepted limit. |
| `decant:fresh-start` | `app/Console/Commands/FreshStart` | A global wipe in a multi-shop database. Needs `--shop=`, and should refuse to run without it. |
| Seeder | `database/seeders/` | Seeds a shop, or seeds into whichever shop happens to be first? |
| Telegram listeners | `NotifyAdminOfNewOrder`, `NotifyAdminOfPaymentProof` | Report only — step 33 fixes it. |

## Phase 2 — Decide the idiom (one decision, write it down)

Pick **one** and apply it everywhere:

- **A: `BelongsToShop` trait + Eloquent global scope.** Safe by default; every new query
  is scoped whether or not the author thought about it. Cost: the Studio panel needs
  cross-shop reads, so every Studio query must `withoutGlobalScope(ShopScope::class)`
  explicitly — and a forgotten bypass fails *closed* (empty page), which is the right
  direction to fail.
- **B: explicit `whereBelongsTo($shop)` at each site.** Nothing hidden, greppable, no
  surprises in the Studio panel. Cost: fails *open* — a new widget written next year
  that forgets the clause leaks, and nothing catches it but the test in Phase 4.

Recommendation: **A**, precisely because of the failure direction. Record the choice and
the reasoning in the PR body and in the §7 addition (see `CLAUDE-md-v21-section.md`).

> **Decided as run (v26): A, as already built.** Steps 23–25a had implemented the global
> scope before this step ran, with two deviations from the sketch above that were kept
> deliberately: the scope **throws** `TenantNotSetException` instead of returning empty
> (the 500 names the wiring bug), and the bypass is the context-level
> `TenantContext::withoutTenancy()` (finally-restored, budget-counted) rather than
> per-query `withoutGlobalScope`. The decision block lives in `AGENTS.md` §8
> (`CLAUDE.md` being a pointer since ADR-0001); the kit's carrier file
> `CLAUDE-md-v21-section.md` sat at repo root from #84 until kit session 4 merged its
> remaining sections into `AGENTS.md` / `PRODUCT.md` / `CHANGELOG.md` and deleted it.

Filament's own tenancy still handles Resources; the global scope is the belt for the
widgets, pages, controllers, and commands that Filament's tenancy does not reach.

## Phase 3 — Fix

Only the rows the audit marked unscoped or accidental. Smallest diff that closes each.
No refactors riding along, no renames, no new features.

## Phase 4 — `tests/Feature/TenantIsolationTest.php`

> **As run (v26):** the file had existed since step 23 with 24 cases; this step extended
> it to 40, adding the cases below it lacked (2, 4, 6, 8, most of 7, the case-10 cache
> read, case 11) plus pins for the two Phase 3 fixes (per-shop limiter keys, the
> `shops/{id}/` storage prefix).

The point of this file is that it fails loudly when a *future* step un-scopes something.
Seed two shops, each with its own brand, fragrance (deliberately the **same slug** in
both), promo code (same code string in both), and orders.

Cases:

1. Every public catalog endpoint under shop A's slug returns zero rows belonging to B.
2. The shared fragrance slug resolves to A's row under A's slug, B's row under B's.
3. A's `tracking_code` + its correct phone, requested under **B's** slug → the same
   generic 404 as an unknown code. Assert the response body is byte-identical to the
   unknown-code response — a different shape is an existence oracle.
4. Cancel and payment-proof endpoints: same cross-slug case, same 404.
5. The shared promo code string evaluates against the requesting shop's row only, and a
   code that exists in A but not B is rejected at B.
6. A shop-confined user of A hitting any `/admin/{B}/…` route → 403/404, including
   `/admin/{B}/orders/{A-order-id}/payment-proof` and the invoice routes.
7. Each dashboard widget, rendered as A, reports numbers derived only from A's orders.
   Assert an exact figure, not "not empty".
8. The production-schedule feed and `{date}` day page as A contain none of B's vials.
9. CSV export as A has exactly A's row count.
10. **Cache:** request A's catalog, then B's, in the same test process, and assert B's
    payload is B's. This is the case that catches an unkeyed cache key, and it only
    works if the cache isn't cleared between the two requests — write it deliberately.
11. `decant:fresh-start --shop=A` leaves B's fragrances, orders, and payment proofs
    untouched; without `--shop` the command aborts.

Follow the existing suite's conventions: `Http::assertSentCount` where Telegram is
involved (the #52 lesson), `Model::preventLazyLoading` stays on.

## Non-goals

No UI changes. No new Studio surface (step 34). No per-shop config migration (step 33).
No performance work — if the global scope adds a `where` to a hot query, note it and
move on.

## Docs, same branch

- `backend/README.md` — the scoping idiom, one paragraph under "Domain rules that live here".
- `CLAUDE.md` — the §7 convention from `CLAUDE-md-v21-section.md`.
- New accepted limits (unmigrated media paths, anything the audit found and deliberately
  left) go in the v21 §0 section, in the register style v8 established.

## Acceptance

- Audit table in the PR body, including the rows that were already correct.
- `php artisan test` green, with the new file's count named in the README's test line.
- `sh backend/scripts/verify-postgres-portability.sh` clean if any touched query uses
  `LIKE` or an alias in `ORDER BY`.
