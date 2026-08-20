# Deploying Decant Please!

Two apps, deployed separately: `backend/` (Laravel + Filament admin + JSON API) on
**Heroku**, and `frontend/` (Next.js storefront) on **Vercel**. The frontend only ever
talks to the backend over `https://api.cornerarea.me/api/v1/{shop}/*` — the shop slug is
the first path segment since multi-tenancy Step 24. One shared storefront deployment
serves every shop: it resolves the tenant from the request host against the backend's
`shop_domains` table (ADR-0004) and uses that shop's slug for the API path, so there is
no per-shop Vercel project and no `NEXT_PUBLIC_SHOP_SLUG`. Domain onboarding is in §2.

Production domains:

| What | URL |
|---|---|
| API + admin | `https://api.cornerarea.me` |
| Storefront | `https://decant-please.cornerarea.me` |
| Uploaded images (Cloudflare R2) | `https://images.cornerarea.me` |

---

## 1. Backend — Heroku (Basic dyno + Heroku Postgres + Cloudflare R2)

The backend runs on Heroku: one Basic web dyno serving `vendor/bin/heroku-php-nginx
public/`, a `heroku-postgresql:essential-0` database, and **Cloudflare R2** (S3-compatible)
for uploaded fragrance images — a Heroku dyno's filesystem is ephemeral, so images cannot
live on it. The monorepo lives in one repo, so a monorepo buildpack points Heroku at the
`backend/` subdirectory.

To run any of this you need the `heroku` CLI, authenticated (`heroku auth:whoami`), and the
four Cloudflare R2 values from the next subsection. The `heroku login` flow is browser-based
— a human prerequisite, not something to script.

### Cost

Basic dyno ($7/mo) + `essential-0` Postgres ($5/mo) = **$12/mo**, inside the $13/mo GitHub
Student credit with $1 to spare. No Key–Value Store add-on and no worker dyno — cache,
session, and queue all run on `database`/`sync` (see the config vars below), so neither is
needed and either one would be pure unused spend. Confirm what's actually billing on the
app's **Resources** tab, not just from the plan names.

### One-time: Cloudflare R2 (dashboard only — cannot be scripted from the CLI)

Uploaded images live in an R2 bucket served over a custom domain; payment-proof
screenshots live in a **second, fully private bucket** (no custom domain — see "Payment
proofs" below). From the Cloudflare dashboard, collect six values before touching Heroku:

1. R2 Object Storage → Create bucket → **`decant-please-images`**.
2. Bucket → Settings → note the **S3 API endpoint** (account-level — the same endpoint
   serves both buckets).
3. R2 → Manage API Tokens → Create Token → **Object Read & Write**, scoped to this bucket
   only → copy the **Access Key ID** and **Secret Access Key** (R2 shows the secret once).
4. Bucket → Settings → Public access → Custom Domains → Connect Domain →
   **`images.cornerarea.me`**; wait until it reads **Active**, not just Initializing.
5. R2 Object Storage → Create bucket → **`decant-please-payment-proofs`** — payment-proof
   screenshots. Do **not** connect a custom domain and do **not** enable public access:
   a transfer screenshot carries names, numbers, and amounts, and Laravel streams these
   objects to the admin itself.
6. R2 → Manage API Tokens → Create Token → **Object Read & Write**, scoped to
   `decant-please-payment-proofs` only → copy its **Access Key ID** and **Secret Access Key**.
   (A second token, not a reuse: the images token from step 3 is scoped to its own
   bucket and can't reach this one — which is the least-privilege setup we want.)

Guessing or stubbing these produces a backend that deploys clean and then silently can't
store an image — don't proceed to config vars without all six.

### Create the app and add-ons

```bash
heroku create decant-please-api
heroku buildpacks:add -a decant-please-api https://github.com/lstoll/heroku-buildpack-monorepo
heroku buildpacks:add -a decant-please-api heroku/php
heroku config:set -a decant-please-api APP_BASE=backend
heroku addons:create -a decant-please-api heroku-postgresql:essential-0
```

Buildpack **order matters**: the monorepo buildpack runs first and, told by
`APP_BASE=backend`, relocates `backend/` to the app root so `heroku/php` then finds
`composer.json` and the `Procfile` where it expects them. Reversed, the build fails.

The Heroku app name `decant-please-api` is only Heroku's internal identifier and its
`*.herokuapp.com` fallback URL — it never has to match the public `api.cornerarea.me`
domain, and doesn't need renaming just because they differ.

The Postgres add-on injects its connection string as **`DATABASE_URL`**, which the `pgsql`
connection in `config/database.php` reads directly. So **do not** set `DATABASE_URL`,
`DB_HOST`, or `DB_PORT` by hand — `.env.example`'s `DB_PORT=5442` is a local-machine port
workaround with no meaning here.

You **do** still need `DB_CONNECTION=pgsql` (in the config vars below) — easy to conflate
with the above and skip. `DATABASE_URL` only feeds the `pgsql` *connection*; the *default*
connection is `env('DB_CONNECTION', 'sqlite')`, so without the pin Laravel uses sqlite and
the release-phase `migrate` dies with `could not find driver (Connection: sqlite)`.

### Config vars

```bash
heroku config:set -a decant-please-api \
  APP_KEY="base64:$(openssl rand -base64 32)" \
  APP_ENV=production \
  APP_DEBUG=false \
  DB_CONNECTION=pgsql \
  SESSION_SECURE_COOKIE=true \
  APP_URL=https://api.cornerarea.me \
  FRONTEND_URL=https://decant-please.cornerarea.me \
  SHOP_SLUG=decant-please \
  ADMIN_PASSWORD='<strong password, not reused from elsewhere>' \
  FILESYSTEM_DISK=s3 \
  MEDIA_DISK=s3 \
  AWS_ACCESS_KEY_ID='<R2 Access Key ID>' \
  AWS_SECRET_ACCESS_KEY='<R2 Secret Access Key>' \
  AWS_DEFAULT_REGION=auto \
  AWS_BUCKET=decant-please-images \
  AWS_ENDPOINT='<R2 S3 API endpoint>' \
  AWS_USE_PATH_STYLE_ENDPOINT=true \
  AWS_URL=https://images.cornerarea.me \
  PROOFS_DISK=s3-proofs \
  PROOFS_AWS_BUCKET=decant-please-payment-proofs \
  PROOFS_AWS_ACCESS_KEY_ID='<proofs-token Access Key ID>' \
  PROOFS_AWS_SECRET_ACCESS_KEY='<proofs-token Secret Access Key>'
```

`MEDIA_DISK=s3` is what routes uploaded fragrance/brand images to the R2-backed `s3` block in
`config/filesystems.php` — every image code path reads it (the API resources, the Filament
FileUpload/ImageColumn components, `decant:fresh-start`). `FILESYSTEM_DISK=s3` sets Laravel's
default disk; that `s3` block reads every `AWS_*` var above, and `AWS_URL` is the public R2
domain baked into the image URLs the API returns. The upload itself is pinned to the `local`
temp disk in code (`AdminPanelProvider`), so the browser posts to Laravel and Laravel writes to
R2 server-side — **so the upload needs no R2 CORS policy** (admin *previews* of saved images do —
see "Admin image uploads" below). `PROOFS_DISK=s3-proofs` does the same routing job for
payment-proof screenshots, pointing them at the second, **private** bucket: the `s3-proofs`
disk reads the `PROOFS_AWS_*` vars (endpoint and region fall back to the shared `AWS_*`
values — R2's S3 endpoint is account-level) and deliberately has no URL var at all —
proofs are only ever served by the panel's authenticated streaming route (see "Payment
proofs" below). Verify the whole set landed — `heroku config -a
decant-please-api`, or the dashboard's **Settings → Config Vars → Reveal** — before
deploying. A typo caught now is a five-second fix; the same typo caught mid-release is a
failed migration on a live app. (If you reveal them in the dashboard, that's real secrets in
a browser tab — don't screenshot it or leave it open on a shared screen.)

### First deploy

```bash
heroku git:remote -a decant-please-api
git push heroku main
```

Watch the build log for **"Monorepo app detected"** before the PHP buildpack output. The
`release: php artisan migrate --force` line in `backend/Procfile` runs migrations
automatically after every build — confirm it completes with no errors and the web dyno
reads **Up**, not Crashed. If it cycles between states the release phase failed even though
the build succeeded: `heroku logs --tail -a decant-please-api`.

There is no `storage:link` and no `chown` step here — the buildpack provides nginx + PHP-FPM,
and images live in R2 rather than on the dyno. The Procfile's `-C conf/nginx/laravel.conf`
supplies the one required nginx tweak: routing Laravel's clean URLs (`/api/v1/*`, `/admin`,
`/up`) through the front controller, which the bare buildpack config otherwise 404s.

### Image upload size

Fragrance images run ~2 MB, right at PHP's default `upload_max_filesize` (2M) — the same
edge the bare-VPS guide handled by raising Nginx's `client_max_body_size` to 5m. On Heroku
the upload transits nginx **and** php-fpm, so both layers need headroom:

- **PHP** is handled in-repo by `backend/public/.user.ini` (`upload_max_filesize=8M`,
  `post_max_size=10M`) — Heroku's php-fpm reads it automatically; it's a no-op locally.
- **Nginx** — `backend/conf/nginx/laravel.conf` (the same include that fixes routing, wired
  via the Procfile's `-C`) sets `client_max_body_size 10m;`, so a 2 MB upload clears the proxy
  instead of 413-ing before it reaches PHP.

### Admin image uploads

Uploading a fragrance/brand image in `/admin` goes **through** Laravel, not straight to R2.
Livewire's temporary-upload disk is pinned to `local` in `AdminPanelProvider::boot()`, so the
browser POSTs the file to Laravel's own endpoint (same origin); Filament then writes the
finished file to the media disk (`MEDIA_DISK=s3` → R2) server-side. Two consequences:

- **Uploads need no R2 CORS policy** — pinning the temp disk to `local` removes the browser
  `PUT` to `…r2.cloudflarestorage.com` that a default (`s3`) temp disk would make. **But admin
  *previews* do:** Filament renders a saved image by having FilePond `GET` it from R2
  client-side (a cross-origin fetch from `https://api.cornerarea.me`), so R2 must return CORS
  headers or the browser blocks it with `No 'Access-Control-Allow-Origin' header`.
  `->fetchFileInformation(false)` does **not** fix it (that only skips server-side size/type
  calls; the FilePond GET is unconditional). Add this bucket CORS policy in the Cloudflare
  dashboard (**R2 → decant-please-images → Settings → CORS Policy**) — it can't be set over the
  S3 API with the object-scoped token used for uploads (`PutBucketCors` → AccessDenied):
  ```json
  [{"AllowedOrigins":["https://api.cornerarea.me"],"AllowedMethods":["GET","HEAD"],"AllowedHeaders":["*"],"ExposeHeaders":["ETag"],"MaxAgeSeconds":3600}]
  ```
  The storefront needs nothing here — `next/image` fetches server-side and `<img>` isn't
  CORS-gated; scope the origins to the admin.
- **Assumes a single web dyno.** The temp file lives on the dyno's ephemeral disk between the
  upload and the form submit, which is fine when both hit the same dyno. If you scale the web
  process past one dyno, switch the temp disk to `s3` and add an R2 bucket CORS policy for
  `https://api.cornerarea.me`, or use shared temp storage.

### Payment proofs — private bucket, no CORS policy

Payment-proof screenshots live in `decant-please-payment-proofs` (`PROOFS_DISK=s3-proofs`), which —
unlike the images bucket — needs **no CORS policy at all**, because the browser never talks
to it in either direction:

- **Customer upload** POSTs the screenshot to the Laravel API (`/api/v1/{shop}/orders/payment-proof`),
  and Laravel writes it to R2 server-side.
- **Admin upload** rides the same `local` temp-disk flow as images (see "Admin image
  uploads" above); the finished file is written to the proofs bucket server-side.
- **Admin viewing** — the order form's preview thumbnail and its "Open full size" link —
  fetches `/admin/orders/{id}/payment-proof`, an authenticated route inside the Filament
  panel that streams the object from R2 server-side. Same origin, guarded by the panel's
  own session auth. This is what forces a CORS policy on the *images* bucket (FilePond
  GETs saved images from R2 directly) and is exactly the hop the proofs bucket never makes.

Nothing generates a presigned or public URL for a proof, the bucket has no custom domain,
and the stored path never appears in any public API response — the tracking receipt only
carries a `has_payment_proof` boolean. Losing a URL therefore leaks nothing: without an
admin session every path 404s or redirects to the panel login.

### Seed the admin login

```bash
heroku run -a decant-please-api php artisan db:seed --force
heroku run -a decant-please-api php artisan decant:fresh-start   # keeps admin + brands, clears demo catalog/orders
```

The first creates the admin user from `ADMIN_PASSWORD`; the second clears the demo
catalog/orders while keeping the admin account and brand list, ready for real inventory.

### Custom domain + TLS

```bash
heroku domains:add -a decant-please-api api.cornerarea.me
```

This prints a DNS target (`xyz.herokudns.com`). Human action in Cloudflare: add a **CNAME**
for `api` → that target, **DNS only** (grey cloud, not proxied) for this first pass —
proxying now only adds an SSL-mode interaction to debug for no benefit at this traffic
level; that's a deliberate later optimization. Then:

```bash
heroku certs:auto -a decant-please-api   # wait until it reads "Cert issued"
```

`Cert issued`, not `DNS Verified` (still in progress) or `Failing`, is the signal the domain
is live. If it's still pending 15–20 minutes after the CNAME went in, re-check the actual DNS
record in Cloudflare before assuming it'll resolve on its own.

### Later deploys — auto-deploy, gated on CI

Every merge to `main` deploys itself once the one-time GitHub connection below is made.
The gate: `.github/workflows/tests.yml` runs two checks on every push to `main` (and on
every PR) — `test`, the SQLite suite exactly as `composer test` runs it, and
`postgres-portability`, which migrates and seeds a real Postgres 17, boots the API, and
runs `backend/scripts/verify-postgres-portability.sh` against it. The second exists
because a green SQLite suite structurally can't catch Postgres-only breakage
(case-sensitive `LIKE`, `ORDER BY` alias resolution) — this project shipped both once.
Heroku deploys only after both report green.

One-time dashboard setup (an OAuth flow tied to your accounts — not a CLI step):

1. Heroku dashboard → `decant-please-api` → **Deploy** tab → Deployment method →
   GitHub → connect `naingaunglinn/decant-please`.
2. **Enable Automatic Deploys** for `main`.
3. Check **"Wait for CI to pass before deploy"** — per Heroku's docs this watches
   GitHub's commit-status API, which is exactly where the Actions workflow reports.
   No Heroku CI add-on, nothing to pay for: the workflow running on `push` is the
   whole requirement.

Then prove the connection with the same tab's **manual deploy** button once — that also
ships whatever `main` currently holds. From there, merging the `develop` → `main`
**promotion PR** *is* the deploy: tests run, Heroku sees green, the release phase re-runs
migrations on its own. Day-to-day feature PRs merge into `develop` and deploy nothing —
`main` only moves when the decanter deliberately promotes (see `prompts/WORKFLOW.md`,
"Promoting `develop` to production").

`git push heroku main` still works and remains the break-glass fallback if GitHub or
Actions is down — but it bypasses the CI gate, so it's no longer the routine path.

### Production notes

- `APP_ENV=production` switches on forced-HTTPS URL generation (see `AppServiceProvider`)
  and switches off the dev-only lazy-loading guard.
- The admin panel is auth-only (Filament login); there is no public registration route
  anywhere.
- Config vars are read from the environment on every boot — there is no `.env` file on the
  dyno and no `config:cache` to re-run after a change. Setting a config var restarts the
  dyno with the new value.
- **`PAYMENT_*`, `SOCIAL_*`, and `TELEGRAM_*` config vars are platform *defaults*, not a
  shop's live values (step 33).** Each shop's own payment/Telegram/social settings live on
  its `shop_settings` row (set from the Payment settings page) and win over these; a shop
  falls back to the env value only where its own is blank (e.g. many shops sharing one
  platform Telegram bot, each with its own chat id). Verify a shop's Telegram wiring with
  `php artisan telegram:test <shop-slug>`. Telegram credentials on the row are encrypted at
  rest.

### Backups — not optional

Order history is the decanter's financial record. Run **two independent layers**, because
they protect against different failure modes and neither replaces the other:

1. **Heroku-managed backups** — quick recovery from a bad query or a dropped table, without
   leaving the Heroku account. Take one on demand with `heroku pg:backups:capture -a
   decant-please-api`; where the plan supports scheduling, pin a daily one with
   `heroku pg:backups:schedule DATABASE_URL --at '01:00 Asia/Yangon' -a decant-please-api`.
   List and download them with `heroku pg:backups -a decant-please-api`.
2. **An off-Heroku nightly dump** — the insurance layer 1 can't provide: losing the whole
   Heroku account. Run it from any machine with the CLI (a home server's crontab — *not* the
   dyno, whose filesystem is ephemeral and has no cron):

   ```cron
   30 18 * * * pg_dump "$(heroku config:get DATABASE_URL -a decant-please-api)" | gzip > /var/backups/decant_please-$(date +\%F).sql.gz
   40 18 * * * find /var/backups -name 'decant_please-*.sql.gz' -mtime +14 -delete
   ```

   (18:30 UTC = 01:00 Myanmar time.) `pg_dump` is transaction-consistent by default — no
   `--single-transaction` flag needed. Copy at least one dump somewhere off that machine too.

Uploaded images are in Cloudflare R2, not on the dyno, so R2's own durability is their
backup story; enable object versioning on the bucket if you want point-in-time recovery of a
replaced image.

---

## 2. Frontend — Vercel (recommended)

**One project serves every shop** (ADR-0004): the storefront resolves the tenant from
the request Host against the backend's `shop_domains` table — there is no per-shop
project, no per-shop build, and no per-shop env var. Do **not** create a second Vercel
project for a new shop.

1. Import the repo in Vercel, set **Root Directory** to `frontend/`.
2. Environment variables (the only two):

   | Variable | Value |
   |---|---|
   | `NEXT_PUBLIC_API_URL` | `https://api.cornerarea.me/api` |
   | `NEXT_PUBLIC_IMAGE_URL` | `https://images.cornerarea.me` |

3. Deploy once. Every shop after that is domains, not deployments.

**Onboarding a shop's domain** (the whole flow — Admin Portal → storefront):

1. Register the shop in the **Studio** (`/studio/shops`) if it doesn't exist.
2. Studio → the shop's **Domains** action: add its host(s) — a platform subdomain
   (`shop.decantplease-style.com`) and/or the client's own custom domain. Exactly one
   is **primary** (canonical for SEO; secondaries 308 to it); leave new rows
   **unverified**.
3. **Vercel → the one project → Settings → Domains**: attach the same host. Vercel
   shows the DNS record the client must set (CNAME/A) and issues TLS automatically
   once DNS points here. A platform wildcard (e.g. `*.yourplatform.com`) is attached
   once and covers every subdomain-tier shop.
4. When DNS actually resolves and Vercel shows the domain active, flip the row to
   **Verified** in the Studio. `verified_at` is an administrative gate, not DNS
   proof — the storefront serves the host and CORS admits its origin only from this
   moment, so don't set it early.
5. Nothing to deploy: resolution is live data (60s cache), and the shop's storefront,
   sitemap, robots, and canonical URLs all follow its primary domain.

The backend's `FRONTEND_URL` config var stays as the **platform default** origin only —
per-shop origins come from the verified `shop_domains` rows automatically (dynamic CORS,
PR-A). "View on site" links use each shop's verified primary domain, falling back to
`FRONTEND_URL`.

> **Preview deployments are fail-closed by design.** `*.vercel.app` hosts map to no
> `shop_domains` row, so previews render the unbranded 404 — a preview can never leak a
> production shop. To smoke-test a preview against real rendering, add its exact
> `<deployment>.vercel.app` host as an **unverified-then-verified** row on a throwaway
> shop, and delete the row after; never map a preview host to a client's shop.

> **Production Branch — still unverified (ADR-004).** The project's Production Branch is
> *believed* to be `main` (captured at import, pre-`develop`-split; the Vercel bot labelled
> a `develop`-head deployment "Preview" on PR #54) but has never been read from the
> dashboard. Check **Settings → Git → Production Branch** on the next dashboard visit and
> replace this note with the confirmed value — under a single shared project it gates
> every shop at once.

> **Fragrance images from R2 (#22).** `next.config.ts` allows images from the host in
> `NEXT_PUBLIC_IMAGE_URL` (alongside the API host and localhost). Set
> `NEXT_PUBLIC_IMAGE_URL=https://images.cornerarea.me` on Vercel — without it, the image
> optimizer rejects R2 URLs and catalog photos 400 on the storefront. It's additive to the
> API-host pattern, so set it **before** the backend starts emitting R2 URLs and there's no
> broken window.

**Alternative — Node on a VPS:** `npm ci && npm run build`, then `npm start` (port 3000)
under systemd or pm2 with an Nginx `proxy_pass` + TLS in front (Nginx must forward the
original `Host` header — it's the tenant). Same two env vars, in `frontend/.env.local`.

---

## 3. Local dev quickstart (two terminals)

Or one terminal and no local toolchains: `docker compose up` at the repo root does all of
this for you — see the [root README](README.md#getting-started).

```bash
# terminal 1 — backend  (PostgreSQL running; see backend/.env for credentials)
cd backend
composer install
cp .env.example .env && php artisan key:generate   # first time only; set ADMIN_PASSWORD
php artisan migrate --seed                          # first time only
php artisan storage:link                            # first time only; serves uploaded images locally
php artisan serve --port=8010                       # http://localhost:8010, admin at /admin

# terminal 2 — frontend
cd frontend
npm install
cp .env.local.example .env.local                    # first time only
npm run dev -- -p 3001                              # http://localhost:3001
```

Admin login: `admin@decantplease.local` / whatever `ADMIN_PASSWORD` was when you seeded.

Locally, images still use the `public` disk and `storage:link` — R2 is production only,
selected by `MEDIA_DISK=s3` there and left unset (so `public`) here.

---

## 4. Handover to the decanter

When the real catalog is ready to go in, wipe the demo data but keep the admin user and the
brand list:

```bash
heroku run -a decant-please-api php artisan decant:fresh-start   # or, locally: php artisan decant:fresh-start
```

It asks for confirmation, then deletes all orders and all fragrances (including their prices
and uploaded images). Brands stay, so entering real inventory starts at "add fragrance", not
from zero.

## 5. Pre-launch checklist

- [ ] Config vars set and verified (`heroku config`): `APP_ENV=production`, `APP_DEBUG=false`,
      `APP_KEY` present
- [ ] HTTPS on both domains; `SESSION_SECURE_COOKIE=true`
- [ ] `FRONTEND_URL` = exact storefront origin `https://decant-please.cornerarea.me` (CORS +
      admin "View on site" links)
- [ ] `ADMIN_PASSWORD` strong; admin login verified at `https://api.cornerarea.me/admin`
- [ ] API routing works past `/`: `curl -I https://api.cornerarea.me/api/v1/decant-please/meta`
      returns `200` — a 404 here while `/` serves means the buildpack needs a custom nginx
      conf (`-C`); a 404 on this exact path with `/` fine can also mean the `{shop}` slug
      doesn't match a `shops` row (`SHOP_SLUG`)
- [ ] `MEDIA_DISK=s3` (+ `FILESYSTEM_DISK=s3` + R2 vars) set — upload a fragrance image in
      `/admin`, confirm its URL resolves under `https://images.cornerarea.me/` (not a
      `local`-disk path), then `heroku ps:restart` and reload it to prove it's served from R2,
      not the dyno's ephemeral disk (a 413 on upload means the limits need raising — see
      "Image upload size")
- [ ] Proofs bucket private: `PROOFS_DISK=s3-proofs` + `PROOFS_AWS_*` vars set — upload a
      payment proof from the tracking page, open it on the order in `/admin` (it streams
      from `/admin/orders/{id}/payment-proof`), and confirm in Cloudflare that
      `decant-please-payment-proofs` has **no** custom domain and public access reads **Disabled**
- [ ] `NEXT_PUBLIC_IMAGE_URL=https://images.cornerarea.me` set on Vercel so the storefront's
      image optimizer accepts R2 URLs (#22)
- [ ] `api.cornerarea.me` shows **Cert issued** (`heroku certs:auto`)
- [ ] Both backup layers working: `heroku pg:backups` produces a capture **and** the
      off-Heroku `pg_dump` cron produces a file
- [ ] Place one test order end-to-end: checkout → tracking page → accept in admin →
      production schedule shows it → CSV export contains it
- [ ] `heroku run … php artisan decant:fresh-start` run once real inventory entry begins
- [ ] Account-level check: dashboard → Billing shows Platform Credits intact and no add-on
      or app on the account beyond this one
