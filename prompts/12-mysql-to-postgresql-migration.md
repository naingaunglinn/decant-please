# Step 12 — Migrate the database engine from MySQL to PostgreSQL

Work only in `backend/`, the root `docker-compose.yml`, `CLAUDE.md`, `README.md`,
and `DEPLOY.md`. Follow `CLAUDE.md` for every convention this step doesn't
explicitly change. This step swaps one thing — the database engine — and touches
nothing in the domain model, migration column *names*, API, or admin panel. If
you find yourself editing anything under `app/Http`, `app/Filament`, or
`frontend/`, stop — that's out of scope here.

## How this plugs into WORKFLOW.md (the roles you asked for)

Don't run this file standalone. Invoke it exactly like steps 8–11:

```
Follow prompts/WORKFLOW.md for prompts/12-mysql-to-postgresql-migration.md
```

`WORKFLOW.md` already owns the full issue → branch → implement → docs → PR loop —
this file is a spec, not a second copy of that process. Two additions specific to
this step, since the `WORKFLOW.md` template doesn't set labels or reviewer focus
by default:

- **Issue and PR labels:** `type:migration`, `area:backend`,
  `breaking-change:local-dev` (the docker-compose volume rename in section 2 wipes
  local Postgres data on next `up` — a real breaking change for anyone else on the
  project, zero impact on anything already deployed, since nothing is deployed yet).
- **Reviewer's role:** whoever reviews this PR should read section 3 before
  anything else in the diff. It's the one place this step makes a judgment call
  instead of a mechanical rename, and it's easy to approve without noticing it's
  there.

## 0. Why (for the issue body and PR description)

Heroku — the chosen host — has no first-party MySQL. Its only credit-eligible,
natively-supported database is Postgres; MySQL exists on Heroku solely as paid
third-party add-ons (JawsDB, ClearDB), which the GitHub Student credit explicitly
excludes. Postgres is the only way to keep the database inside the free
student-credit budget.

## 1. Backend configuration

- `backend/.env.example`: `DB_CONNECTION=mysql` → `pgsql`; `DB_PORT=3306` → `5442`
  (**not** 5432 — see section 2's port note). Also `DB_USERNAME=root` → `postgres`;
  this bullet originally missed it, but leaving MySQL's superuser name in a Postgres
  example breaks the "Running without Docker" path for the same reason the compose
  service needed it changed.
- `backend/config/database.php`, `pgsql` connection block: change
  `'url' => env('DB_URL')` to `'url' => env('DATABASE_URL')`. This is the line
  that matters most — Heroku injects its connection string as `DATABASE_URL`, not
  `DB_URL`, and without this fix the auto-provisioned Postgres credentials are
  silently ignored on deploy. The same block's `'sslmode' => env('DB_SSLMODE',
  'prefer')` is already correct — no change needed there.
- `backend/Dockerfile`: replace `pdo_mysql` with `pdo_pgsql` in the
  `docker-php-ext-install` line, **and** add `libpq-dev` to the `apt-get install`
  line above it. `pdo_pgsql` needs `libpq-dev` to build; `pdo_mysql` didn't need an
  equivalent system package, which is exactly why this is easy to miss.

## 2. Local dev — `docker-compose.yml`

Replace the `mysql` service with a `postgres` service:

- Image: `postgres:17` — Heroku Postgres currently defaults new databases to
  major version 17; keep local dev on the same major version as production.
- Env: `POSTGRES_PASSWORD`, `POSTGRES_DB` (replacing `MYSQL_ROOT_PASSWORD`,
  `MYSQL_DATABASE`); set `POSTGRES_USER` explicitly rather than relying on the
  image's default.
- Port: **`5442:5432`**, not `5432:5432` as this bullet originally said. Another project
  on the dev machine already publishes Postgres on 5432 — exactly the collision CLAUDE.md
  §7 exists for (the API is 8010 and the storefront 3001 for the same reason). Inside the
  compose network it stays a plain 5432; only the host mapping shifts.
- Healthcheck: keep the same discipline as the existing comment explains — a real
  query, not just a bare readiness ping, because a first-boot socket-only server
  can report ready before it actually is. `pg_isready -U postgres -h 127.0.0.1`
  with the existing retry loop is the direct equivalent; a literal `psql -c
  "SELECT 1"` also works if you want to match the old pattern exactly.
- Volume: rename `decant_mysql_data` → `decant_postgres_data`. This intentionally
  resets local dev data on the next `up` — call it out plainly in the PR
  description; it's expected, not a bug.
- Update the `backend` service's environment block: **`DB_CONNECTION: pgsql`**,
  `DB_HOST: postgres`, `DB_PORT: "5432"`, `DB_USERNAME: postgres` (Postgres's default
  superuser is named `postgres`, not `root`), `DB_PASSWORD` matching whatever
  `POSTGRES_PASSWORD` is set to.

  `DB_CONNECTION` was missing from this list originally, and omitting it breaks every
  developer who already has a `backend/.env` — that file still says `DB_CONNECTION=mysql`,
  compose doesn't override it, and Laravel then drives a Postgres server with the MySQL
  driver. It doesn't fail loudly: the bootstrap's connection probe just times out and
  reports the database unreachable while the healthy Postgres sits right there. A fresh
  clone works fine, which is what makes it easy to miss.

## 3. Schema portability review — the semantic gaps

> **Corrected during implementation (#19).** This section originally called the
> unsigned-integer issue below "the one real semantic gap" and claimed the grep at the
> end "comes back empty". Both were wrong, and the grep's pattern is too narrow to have
> found what was actually there — see section 3a. Left in place, corrected, rather than
> silently rewritten, because the *shape* of the mistake is the lesson: a portability
> review that greps for raw-SQL helpers misses ordinary query-builder calls whose
> **semantics** differ per engine, and the test suite can't catch them either.

Postgres has no native unsigned integer type. Laravel's Postgres grammar accepts
`unsignedInteger()` / `unsignedSmallInteger()` without erroring, but it silently
drops the "unsigned" guarantee: on MySQL these columns reject negative values at
the database level, on Postgres they won't. This project uses unsigned integers
deliberately for every money and quantity column (`CLAUDE.md` §7: "money stored as
unsigned integers") — `deposit_mmk`, `delivery_fee_mmk`, `discount_mmk`,
`total_mmk`, `price_mmk`, `unit_price_mmk`, `line_total_mmk`, `value`,
`max_discount_mmk`, `min_order_mmk`, `usage_limit`, `times_used`, and the jobs
table's internal counters.

Pick one of these and document the choice — don't leave it implicit:

- **(a) Accept it as an application-layer guarantee only.** It very likely already
  is one, given checkout re-derives prices server-side and Filament is the only
  other write path. Reword the CLAUDE.md convention in section 4 accordingly.
- **(b) Add explicit `CHECK (column >= 0)` constraints** in the Postgres
  migrations to keep the same DB-level guarantee MySQL gave you. More faithful to
  the original intent, more migration surface area.

Recommend **(a)** for this pass: smaller, more reviewable diff, and every column
in that list is either server-derived or admin-entered, never raw client input.
Name the trade-off explicitly in the PR description so the reviewer is deciding
it, not discovering it later.

Before calling this section done, confirm there's nothing else MySQL-specific to
catch:

```
grep -rn "DB::raw\|whereRaw\|selectRaw\|orderByRaw\|havingRaw\|groupByRaw\|->enum(\|COLLATE" backend/app backend/database/migrations
```

`orderByRaw`/`havingRaw`/`groupByRaw` were missing from this pattern originally, which
is how section 3a's sort bug got through. It does **not** come back empty:
`TopFragrances.php`'s `selectRaw('COALESCE(SUM(...), 0)')` is a hit, and it's fine —
`COALESCE`/`SUM` are standard SQL. Read the hits; don't expect zero.

## 3a. The two gaps this review originally missed

Both were found by grepping wider than the pattern above and verified against a real
`postgres:17`. **Neither is a raw-SQL call** — which is why a raw-SQL grep can't find
this class of problem — and neither lives in the schema, so a *schema* portability
review was always going to miss them.

- **`LIKE` is case-sensitive on Postgres, case-insensitive on MySQL.** The three catalog
  search sites in `FragranceController` used a bare `where(..., 'like', ...)`, so
  `q=creed` matched nothing (`'Creed' LIKE '%creed%'` → false). Fixed with Laravel's
  `whereLike($col, $val)`, which defaults to `caseSensitive: false` and emits `ilike` on
  Postgres, `like` elsewhere — no raw SQL, no engine branching in app code.
- **Postgres resolves a select alias in `ORDER BY` only as a bare name.** `price_asc`/
  `price_desc` ordered by `min_price IS NULL, min_price ASC`, where `min_price` is a
  `withMin()` alias. Putting it inside an expression raised `column "min_price" does not
  exist` → HTTP 500. Fixed with `min_price ASC NULLS LAST`, which needs no prefix column
  and works on both Postgres and SQLite.

Both fixes are in `app/Http`, which section 0 declares out of scope. That boundary
assumed nothing there was MySQL-specific; it was false, so the fixes landed here rather
than shipping a knowingly-broken app. **If you are re-running this spec, treat section
0's scope line as conditional on section 3a coming back clean.**

### Why section 5's test check cannot be trusted here

The suite runs on in-memory SQLite, which — like MySQL and unlike Postgres — has a
case-insensitive `LIKE` and tolerates an alias inside `ORDER BY`. Both bugs above pass
47/47. Verified: against the unfixed controller on a real Postgres stack, `q=creed`
returned 0 results and `sort=price_asc` returned HTTP 500, while `php artisan test`
reported all green. **"Same 47 tests, same 329 assertions" is necessary but proves
nothing about engine semantics.** `backend/scripts/verify-postgres-portability.sh`
drives the running stack and is the check that actually discriminates.

## 4. Documentation — same branch, per WORKFLOW.md step 5

- **`CLAUDE.md`**: §2's tech stack table is marked "fixed — do not substitute";
  change the `Database` row from `MySQL 8.0+` to `PostgreSQL 17`, and add a short
  note in the existing "v2/v3/v4/v5 adds..." changelog style explaining the
  switch and pointing at this prompt file. Also update §7's "money stored as
  unsigned integers" line per whichever path you picked in section 3, and the
  "local dev ports" paragraph's "runs MySQL + both apps" line.
- **Root `README.md`**: the "Tech stack" table's `Backend` row reads
  `Laravel 13 · PHP 8.3+ · MySQL 8` — update to `PostgreSQL 17`.
- **`backend/README.md`**: three spots — the Requirements line (`MySQL 8.0+` →
  `PostgreSQL 17+`), the env var table's `DB_*` row (`MySQL connection` →
  `PostgreSQL connection`), and the test-suite note about dev data (reword "your
  dev MySQL data" → "your dev Postgres data").
- **`DEPLOY.md`**: requirements line (`pdo_mysql` → `pdo_pgsql`, `MySQL 8.0+` →
  `PostgreSQL 17+`), and the nightly backup command. Replace
  `mysqldump --single-transaction decant_please | gzip > ...` with
  `pg_dump decant_please | gzip > ...` — drop `--single-transaction` entirely
  rather than hunting for a Postgres equivalent flag; `pg_dump` is already
  transaction-consistent by default.

## 5. Verification before opening the PR

- `docker compose up --build` (forces the Dockerfile rebuild) — confirm
  migrations run clean against the new Postgres container from an empty volume.
- `docker compose exec backend php artisan test` — this still runs against
  in-memory SQLite, so it should be completely unaffected: same 47 tests, same
  329 assertions. If anything fails here, that's a MySQL assumption this review
  missed, not a Postgres problem to route around.
- Manually exercise a handful of `/api/v1` endpoints and click through the admin
  panel (brand/fragrance CRUD, one manual order entry) against the new local
  Postgres database.

## 6. Explicitly out of scope for this step

Heroku's `Procfile`, buildpack config, and moving image storage off local disk to
Cloudflare R2 are a separate step — don't fold them in here even if it's tempting
to "just finish deployment while I'm in here." This PR is the engine swap, full
stop.

## 7. Final report

State plainly: which of the two paths in section 3 you took and why, the
re-run grep from section 3 showing nothing left over, and the test suite result
(pass count, unchanged from before this step). Flag anything that surprised you.
