# Step 13 — Production Heroku hosting + Cloudflare R2 for the backend

You are acting as this project's **Senior DevOps Engineer and Senior Solutions
Architect** for this step. In practice that means two things, not just a label:

- **As DevOps engineer**: prefer idempotent, re-runnable commands over one-shot
  ones; verify every step actually landed before starting the next one; never let
  a secret (API key, DB password, admin password) appear in a commit, a log line,
  or this step's final report; treat cost as a first-class constraint, not an
  afterthought — call out anything that would push spend past what's stated below.
- **As solutions architect**: don't just run commands, explain *why* at every
  decision point where a different choice was plausible, so whoever reads the PR
  or this transcript later understands the tradeoff, not just the outcome.

Work only in `backend/`, `DEPLOY.md`, and real Heroku/Cloudflare infrastructure.
Follow `CLAUDE.md` for anything this step doesn't explicitly change.

## 0. Before you start

- **PR #19/20 must already be merged** (`12-mysql-to-postgresql-migration.md`).
  Confirm `backend/config/database.php`'s `pgsql` block reads
  `env('DATABASE_URL')`, and `backend/.env.example` shows `DB_CONNECTION=pgsql`.
  If either isn't true, stop — nothing below works against a MySQL-shaped app.
- `heroku` CLI must be installed and authenticated (`heroku auth:whoami`). Its
  login flow is browser-based — that part is a human prerequisite, not something
  to attempt from here.
- **The domain is `api.cornerarea.me` — not `api-decant-please.cornerarea.me`,
  not the Heroku app's own name.** This is deliberate: this is the flagship
  project on this domain, so it gets the bare `api.` subdomain; any future
  sample project gets its own `api-{project}.cornerarea.me` instead of competing
  for this one.

## 1. Human checkpoint — Cloudflare R2 (cannot be automated from here)

Nobody without dashboard access can complete this part. Stop here and get these
four values before continuing to section 2:

1. Cloudflare dashboard → R2 Object Storage → Create bucket → `decant-please-images`.
2. Bucket → Settings → note the **S3 API** endpoint.
3. R2 Overview → Manage API Tokens → Create Token → **Object Read & Write**,
   scoped to this bucket only → copy the Access Key ID and Secret Access Key.
   R2 shows the secret exactly once.
4. Same bucket → Settings → Public access → Custom Domains → Connect Domain →
   `images.cornerarea.me`. Wait for it to show **Active**, not just Initializing.

Do not proceed until you have all four. Guessing or stubbing them in produces a
backend that "deploys clean" and then silently can't store an image.

## 2. Code changes — branch and PR, per `WORKFLOW.md`

Run the standard loop: confirm `main` is current, open the issue, branch,
implement, PR. "Implement" for this step means:

- `composer require league/flysystem-aws-s3-v3` in `backend/`.
- Create `backend/Procfile` **and** the nginx include it references. The bare
  `heroku-php-nginx public/` originally shown here serves real files and `/` only, so
  every Laravel route (`/api/v1/*`, `/up`, `/admin`) 404s on Heroku — corrected to route
  through the front controller after the deployed app 404'd everything (found during #24):
  ```
  # backend/Procfile
  web: vendor/bin/heroku-php-nginx -C conf/nginx/laravel.conf public/
  release: php artisan migrate --force
  ```
  ```nginx
  # backend/conf/nginx/laravel.conf — included in Heroku's nginx server { } block
  client_max_body_size 10m;                                   # ~2 MB image uploads clear the proxy
  location / { try_files $uri $uri/ /index.php?$query_string; }
  ```
- Rewrite `DEPLOY.md`'s deployment section to describe what's actually true now
  — Heroku (Basic dyno + `heroku-postgresql:essential-0`), the monorepo
  buildpack, R2 for image storage, `api.cornerarea.me` as the production domain
  — replacing the generic bare-VPS instructions it currently has. Keep the
  existing nightly `pg_dump`-to-S3-style backup guidance rather than deleting
  it; also add a line noting Heroku Postgres takes its own automatic daily
  backups (`heroku pg:backups`) as an independent second safety net — the two
  protect against different failure modes (losing the whole Heroku account vs.
  a bad query), so neither replaces the other.
- **Do not touch `frontend/next.config.ts`.** Its `remotePatterns` still only
  allows images from the API host's `/storage/**` path, and won't know about
  `images.cornerarea.me` after this ships — that's a real, known gap, but it's
  frontend scope, not backend/infra scope. Open a separate follow-up issue for
  it and reference it in this PR's description; don't fix it here.

Labels: `type:infra`, `area:backend`, `area:deploy`.

## 3. Provision Heroku — direct infra commands, not part of the PR

These aren't file changes, so they don't go through git — run them directly
once section 1's values are in hand:

```bash
heroku create decant-please-api
heroku buildpacks:add -a decant-please-api https://github.com/lstoll/heroku-buildpack-monorepo
heroku buildpacks:add -a decant-please-api heroku/php
heroku config:set -a decant-please-api APP_BASE=backend
heroku addons:create -a decant-please-api heroku-postgresql:essential-0
```

Architect's note: the Heroku app name (`decant-please-api`) is only Heroku's
internal identifier and its `*.herokuapp.com` fallback URL — it never has to
match the public custom domain, and doesn't need renaming just because the
domain differs from it.

DevOps note on cost: this is a Basic dyno ($7) + `essential-0` Postgres ($5) =
**$12/month**, inside the $13/month student credit with $1 to spare. No
Key-Value Store add-on, no worker dyno — neither is needed (cache/session/queue
all run on `database`/`sync`), and either one would be pure unused cost.

### Dashboard check — confirm what's billing, before going further

This is the point where real cost starts, so confirm it now rather than after
everything's wired together. Open dashboard.heroku.com and check three things:

- **Apps list** (dashboard home): exactly one app, `decant-please-api` — no
  leftover duplicate from a retried `heroku create`. A duplicate with a dyno
  attached bills even if you never touch it again; delete it immediately if
  one exists.
- **This app → Resources tab**: exactly two rows — a **web** dyno on **Basic**,
  and **Heroku Postgres** on **Essential-0**. Each row shows its own price
  alongside it; confirm it reads Basic ($7/mo) and Essential-0 ($5/mo). If a
  Key-Value Store, a worker dyno, or any tier other than these two shows up,
  stop and remove it before continuing — that's spend nobody asked for.
- **This app → Settings tab → Buildpacks**: exactly two, monorepo buildpack
  first, `heroku/php` second. Wrong order isn't a cost problem, but it is a
  build failure waiting to happen — worth catching here too.

## 4. Config vars

```bash
heroku config:set -a decant-please-api \
  APP_KEY="base64:$(openssl rand -base64 32)" \
  APP_ENV=production \
  APP_DEBUG=false \
  DB_CONNECTION=pgsql \
  APP_URL=https://api.cornerarea.me \
  FRONTEND_URL=https://decant-please.cornerarea.me \
  ADMIN_PASSWORD='<choose a strong password, do not reuse one from elsewhere>' \
  FILESYSTEM_DISK=s3 \
  AWS_ACCESS_KEY_ID='<from section 1>' \
  AWS_SECRET_ACCESS_KEY='<from section 1>' \
  AWS_DEFAULT_REGION=auto \
  AWS_BUCKET=decant-please-images \
  AWS_ENDPOINT='<from section 1>' \
  AWS_USE_PATH_STYLE_ENDPOINT=true \
  AWS_URL=https://images.cornerarea.me
```

`DB_CONNECTION=pgsql` above is **required** and was missing from this list originally.
Without it Laravel uses its `sqlite` default — `DATABASE_URL` only feeds the `pgsql`
*connection*, not the *default* — so the release-phase `migrate` dies with `could not
find driver (Connection: sqlite)` (pdo_pgsql is present on Heroku's PHP, pdo_sqlite is
not). It's the same pin `docker-compose.yml` already carries. Found during #24, after
the first deploy's release phase failed.

Do **not** set `DATABASE_URL`, `DB_HOST`, or `DB_PORT` — the Postgres add-on
injects `DATABASE_URL` automatically, and `.env.example`'s `DB_PORT=5442` is a
local-machine-only port workaround with no meaning here.

Verify every var actually landed before deploying — either
`heroku config -a decant-please-api` from the CLI, or **this app → Settings
tab → Config Vars → Reveal Config Vars** in the dashboard for the same list
as a visual check. A typo caught now is a five-second fix; the same typo
caught mid-release-phase is a failed migration on a real customer-facing app.
One note if you use the dashboard view: it's the same secrets rendered in a
browser tab — don't leave it open on a shared screen, and never screenshot it.

## 5. First deploy — direct git push, not GitHub auto-deploy yet

```bash
heroku git:remote -a decant-please-api
git push heroku main
```

Watch the build log for "Monorepo app detected" before the PHP buildpack
output, and confirm `release: php artisan migrate --force` completes with no
errors before treating this as done.

**Dashboard check**: **this app → Activity tab** shows the same build,
progressing to **Succeeded** — click into it to confirm "Monorepo app
detected" appears there too, not just in your terminal history. Once it's
live, **this app → Overview (or Resources) tab** should show the web dyno
with a green "Up" indicator. If it instead reads "Crashed," or keeps cycling
between states, the release phase failed even though the build itself
succeeded — check `heroku logs --tail -a decant-please-api` before going
further, don't assume a successful build means a working app.

Once this manual deploy is confirmed working, wiring up GitHub so every merged
PR deploys automatically (matching how every earlier step in this project
ships) is a one-time human action: Heroku dashboard → app → Deploy tab →
Deployment method → GitHub → connect `naingaunglinn/decant-please` → Enable
Automatic Deploys for `main`. That's an OAuth dashboard flow — flag it as a
pending human step, don't attempt it from here.

## 6. Seed

```bash
heroku run -a decant-please-api php artisan db:seed --force
heroku run -a decant-please-api php artisan decant:fresh-start
```

The first seeds the admin login; the second clears the demo catalog/orders
while keeping the admin account and brand list, per `07-polish-deploy.md`'s own
handover command.

## 7. Custom domain

```bash
heroku domains:add -a decant-please-api api.cornerarea.me
```

Prints a DNS target (`xyz.herokudns.com`). Human action: add a CNAME for `api`
→ that target in Cloudflare, **DNS only** (not proxied) for this first pass —
proxying now adds an SSL-mode interaction to debug for zero benefit at this
traffic level; that's a deliberate later optimization, not something to reach
for today. Then:

```bash
heroku certs:auto -a decant-please-api
```

Confirm it reads `Cert issued` — not `DNS Verified` (still in progress) or
`Failing` — before treating the domain as live.

**Dashboard check**: **this app → Settings tab → Domains** should list
`api.cornerarea.me` with a green "Cert issued" badge next to it — the same
information as the CLI check, just visual. If it's still pending 15–20
minutes after the CNAME was added in Cloudflare, double-check the actual DNS
record there before assuming it'll resolve on its own.

## 8. Verification

- `curl -I https://api.cornerarea.me/api/v1/meta` returns `200` with a valid cert.
- `https://api.cornerarea.me/admin` loads; log in with the seeded credentials.
- Upload one test image through Filament; confirm the resulting URL resolves
  under `https://images.cornerarea.me/`, not a `local`-disk path.
- **Account-level billing check, not just this app**: dashboard → account
  menu → Manage Account → Billing. Confirm **Platform Credits** still shows a
  balance and there's no add-on or app anywhere on the account beyond what
  this step created — this page rolls up the whole account, so it's the one
  place that would catch something unrelated left running elsewhere.

## 9. Final report

State plainly: the Heroku app name and all three live URLs (app default,
`api.cornerarea.me`, `images.cornerarea.me`); confirmed monthly cost ($12,
inside the $13 credit) — confirmed by actually looking at the Resources tab
and Billing page, not just asserted from the plan names; which steps are
still a pending human action (the GitHub auto-deploy dashboard toggle); and
the follow-up issue number for the `next.config.ts` fix, so it doesn't
quietly get forgotten.
