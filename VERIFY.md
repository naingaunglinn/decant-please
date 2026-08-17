# VERIFY.md — how work gets proven here

The rule: **ask for evidence, not confidence.** "It should work correctly" is not a
report. This file defines what counts as proof, so an agent can self-correct against a
harness instead of waiting for a human to spot the bug.

---

## The loop

```
change → run the tier(s) below → failure? investigate root cause → fix → rerun
                                       (repeat until green, without changing expected behaviour)
                              → green? report commands, output, diff, risks
```

If two rounds do not clear a failure, stop and report the violated assumption. Do not
keep editing. See `AGENTS.md` § 9.

## Tiers — pick by what you changed

| You changed… | Run | Why |
|---|---|---|
| **Any model, query, or migration** | `php artisan test --filter=TenantIsolationTest` | the isolation boundary is one global scope — this is the only thing standing between two shops' data |
| **Added a tenant-owned table** | the above **plus a new isolation test you wrote** | a table with no isolation test is untested where it matters most |
| Any backend code | `composer test` | Feature tests, in-memory SQLite |
| A query with `LIKE`, `ORDER BY`, or a select alias | `scripts/verify-postgres-portability.sh` | SQLite hides real Postgres breakage — this repo shipped that bug once already |
| An API Resource shape | `composer test` **and** `npm run typecheck` | the Resource and `lib/types.ts` are one contract |
| Any frontend code | `npm run typecheck` + `npm run lint` | |
| Anything visual or flow-level | the matching `verify-*.mjs` | a green build says nothing about a correct UI |
| A shared primitive (`components/ui/**`, `layout/**`) | `verify-responsive.mjs` at minimum | blast radius is every surface |
| A migration | `php artisan migrate:fresh --seed` then `composer test` | seeders feed the browser checks |
| Money anywhere — including "just formatting" | `composer test` + the money-touching Feature tests, named individually | currency bugs hide in cosmetic changes |

## Known-red baseline — read before you "fix" a failure

`npm run lint` currently exits **1** on four pre-existing `react-hooks/set-state-in-effect`
errors:

```
src/components/catalog/FilterControls.tsx:229
src/components/checkout/OrderCompleteClient.tsx:53
src/components/checkout/OrderSummaryCard.tsx:86
src/lib/cart-context.tsx:46
```

They predate this environment. The Definition of Done says lint passes, so **the bar until
they are fixed is: no *new* lint errors, and these four unchanged.** Do not "fix" them as a
side effect of unrelated work — `cart-context.tsx` is the cart's state seam and
`OrderSummaryCard` touches money display. Clearing them is its own task with its own
verification, and it is a good first candidate to run through the full loop.

Update this section to say "clean" the day they are gone. A known-red baseline that nobody
prunes becomes a permanently ignored check.

## Running the browser checks

They drive real Chromium against a running stack, so bring the stack up first:

```bash
docker compose up                 # or: composer dev (backend) + npm run dev (frontend)
# API on 8010, storefront on 3001
cd frontend && npm run verify
```

Notes that will otherwise cost you an hour:

- `verify-home-rails.mjs` needs a **dev** server — its duplicate-name assertion reads a
  React development-only console error and will pass vacuously against a prod build.
- `verify-image-fallback.mjs` picks its own subjects from the live catalog and reports
  **SKIP** rather than passing when the data can't exercise a case. A SKIP is not a pass.
- `verify-print.mjs` needs a real order: `CODE=... PHONE=... node scripts/verify-print.mjs`.
- `ENGINE=webkit` re-runs the responsive matrix in WebKit — the closest local proxy for
  iOS Safari's focus-zoom rule.
- Screenshots land in `OUT_DIR` (defaults under the system temp dir). Say where they are.

## Report format

End every task with this. It is the artifact a human reviews instead of reading the diff.

```markdown
## What changed
<one paragraph, plus the file list>

## Commands executed
$ composer test
  ... 87 passed (312 assertions)
$ cd frontend && npm run typecheck
  ... no errors
$ BASE_URL=http://localhost:3001 node scripts/verify-responsive.mjs
  ... 24 checks passed — screenshots in /tmp/decant-responsive

## Deliberately not changed
- <thing you were tempted to refactor and did not, and why>

## Remaining risks
- <what is still unproven, and what would prove it>
```

Paste real output. A summary of output you did not run is a fabrication, and it is worse
than saying "I could not run this because the API was not up."

## The isolation question — ask it on every backend diff

Before anything else, for each model, query, or migration in the diff:

1. Is this model tenant-owned? If yes, does it `use BelongsToShop`?
2. Is `shop_id` compared **qualified** everywhere, including inside joins and raw SQL?
3. Did a `unique()` constraint just make a value unique across *all* shops that should
   only be unique *within* one?
4. Did anything new call `withoutTenancy()`? If so, is it in the design-doc §8 budget?
5. Does a cache key, storage path, or Telegram credential include the shop?

A green suite does not answer these. SQLite tolerates the ambiguous-column mistake that
Postgres rejects, and a query written with no tenant in context in a *test* can pass
while leaking in production.

## Review order for the human

1. **Does anything cross a shop boundary?** (the five questions above)
2. Does it satisfy the spec in `PRODUCT.md` / the step file?
3. Did verification actually pass — real commands, real output?
4. Were the boundaries in `AGENTS.md` § 5 respected?
5. Is the diff safe — nothing unrelated, no shipped migration edited, no token invented?

Only then read the code line by line.

## CI is the backstop, not the harness

`.github/workflows/tests.yml` runs `composer test` and the Postgres portability check on
every push to `main`/`develop` and on every PR. It is deliberately two named jobs so
Heroku's deploy gate reads two statuses.

CI does **not** run the browser checks. That is the gap this file exists to close: those
run locally, by you, before the PR — otherwise no one ever runs them.
