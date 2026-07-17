# Step 16 — CI/CD: test-gated auto-deploy from GitHub to Heroku

Follow the current `CLAUDE.md` (v7, since Step 14 has landed). Work in `.github/`
(new) and `backend/` (references only, no app code changes needed).

## 0. What this closes, and what it doesn't touch

- `DEPLOY.md`'s own "Later deploys / auto-deploy" section already names this as the
  plan, in these words: *"To match how every other step in this project ships —
  every merged PR deploying automatically — wire up GitHub auto-deploy once, by
  hand."* This step finally does that, plus adds the one thing a bare GitHub
  auto-deploy doesn't give you for free: a test gate in front of it.
- **Frontend is untouched.** Vercel already deploys on every push via its own git
  integration (`DEPLOY.md` §2) — nothing to change there.
- **(Amended during implementation:)** this originally said `Part of #21`, but the
  infra tracker #21 was already closed when the step ran (it closed with the
  Step 13 deploy). The dashboard half lives in this step's own issue (#34)
  instead — same reasoning though: connecting Heroku's GitHub integration is a
  one-time OAuth dashboard action code alone can't complete, the same category as
  the R2 bucket/token setup #21 tracked.

## 1. GitHub Actions — the "CI" half

New: `.github/workflows/tests.yml`, two jobs (separate jobs report as separate
required checks to GitHub, which §2's Heroku setting reads individually — slightly
better signal than folding both into one job's steps).

**`test`** — the existing suite as-is: `composer install`, `php artisan test`
(SQLite, matches what `composer test` already runs locally).

**`postgres-portability`** — this project already has a documented reason not to
trust the SQLite suite alone: `backend/scripts/verify-postgres-portability.sh`
exists specifically because SQLite is permissive on the two MySQL→Postgres semantic
gaps this project already hit once (case-sensitive `LIKE`, `ORDER BY` alias
resolution). Right now that script only runs when someone remembers to run it
against a local `docker compose up` — meaning it hasn't actually gated anything
since it was written. Wire it in:

- `services: postgres: image: postgres:17` (Actions' native service-container
  syntax — same major version `docker-compose.yml` already pins to match Heroku).
- `shivammathur/setup-php@v2`, PHP 8.3, with `pgsql` in the extensions list.
- Point `DB_CONNECTION=pgsql` / `DB_URL` at the service container, `php artisan
  migrate --force`.
- `php artisan db:seed` — confirmed this calls `CatalogSeeder` (which seeds the
  "Creed" fixture the script's case-sensitivity probe needs) via the default
  `DatabaseSeeder` chain. **Requires `ADMIN_PASSWORD` set in the job's env first** —
  `DatabaseSeeder` throws a `RuntimeException` without it (it refuses to seed the
  admin user otherwise); any placeholder value works, this is CI, not production.
- `php artisan serve --port=8010 &`, then `API=http://localhost:8010/api/v1 sh
  backend/scripts/verify-postgres-portability.sh`, fail the job on its non-zero
  exit.

Deliberately not reusing `docker-compose.yml` wholesale here — building the actual
`backend/Dockerfile` image in CI is slower and duplicates what `setup-php` already
gives you natively as a runner action. That's a small, intentional duplication
between "how CI gets Postgres" and "how local dev gets Postgres," not an oversight.
If keeping one source of truth matters more than a faster job, `docker compose up -d
postgres backend` directly in the Actions job is the alternative.

Trigger on both `push` (so `main` itself reports a status — this is what Heroku
needs to see in §2) and `pull_request` (so a broken PR is visible before merge, not
just discovered at deploy time).

## 2. Heroku — the "CD" half (human, one-time)

The same dashboard flow `DEPLOY.md` already describes, plus one checkbox:

1. Heroku dashboard → `decant-please-api` → **Deploy** tab → Deployment method →
   GitHub → connect → select `naingaunglinn/decant-please`.
2. Enable Automatic Deploys for `main`.
3. **New**: check **"Wait for CI to pass before deploy."** Per Heroku's own current
   docs, this isn't specific to Heroku CI (their separate paid test runner product)
   — it watches the GitHub commit-status API generally, which is exactly what
   Step 1's Actions workflow reports into. No paid add-on, no extra Heroku config:
   the workflow existing and running on `push` *is* the whole requirement.

This is the one piece that's a genuine dashboard-only step — an OAuth consent flow
tied to your specific GitHub/Heroku accounts, same category as the R2 setup already
called out in issue #21. Not automatable from here.

**Migrations**: already handled, nothing to change — `Procfile` already has
`release: php artisan migrate --force`, so the common "GitHub-integration deploys
don't run migrations by default" gotcha doesn't apply to this app.

## 3. The currently-pending Step 14 deploy

Production is still on the pre-invoice build. Once §2 is connected, resist reaching
for `git push heroku main` out of habit — that path still works, but it's the exact
manual step this change exists to remove. Use the same Deploy tab's **manual
deploy** section instead (a dashboard button, not the CLI/git-remote path) to deploy
current `main` once by hand — confirms the new connection actually works before
trusting it to run itself on the next real merge.

## Verify

- Push a no-op commit (or open a small throwaway PR) and confirm the Actions
  workflow runs and reports both `test` and `postgres-portability` back to GitHub
  as separate checks.
- Confirm the Heroku Deploy tab actually reflects those checks rather than sitting
  on "pending" indefinitely — if it does, the status likely isn't reaching Heroku
  because the workflow only ran on `pull_request`, not `push`.
- Break `postgres-portability` on purpose once (temporarily reintroduce a bare
  `LIKE` in the catalog search) and confirm Heroku actually withholds the deploy —
  don't just trust that checking the box did something.
- Use the dashboard's manual deploy to ship the pending Step 14 build, then confirm
  `/admin` shows the print/download invoice actions in **production**, not just
  local/staging.
- Merge one real PR after this lands and confirm the entire chain fires — tests run,
  Heroku sees green, the deploy happens — without anyone running a deploy command
  by hand.

Report results with a checklist, same as every prior step.
