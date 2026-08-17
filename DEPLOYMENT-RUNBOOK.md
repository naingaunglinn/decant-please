# Deploying Decant Please! — Production Runbook

The full **Step 13** scenario, in order, every command — plus the six fixes we only found by
breaking it in production. Backend on **Heroku**, images on **Cloudflare R2**, storefront on
**Vercel**. Follow it top to bottom for a cold start.

> **Scope: the single-shop production cold start** — standing the whole platform up from
> nothing (`DEPLOY.md` is the maintained copy; this is the battle-tested walkthrough).
> It is **not** the per-shop onboarding runbook the multi-tenancy design owes (new shop
> row → Vercel project + `NEXT_PUBLIC_SHOP_SLUG` → Telegram chat id → zone activation);
> that arrives with Step 25b, once a second client exists.

- **Cost:** Basic dyno ($7/mo) + Postgres `essential-0` ($5/mo) = **$12/mo**, inside the $13/mo GitHub Student credit.
- **Time:** ~45 min the first time.

| What | URL |
|---|---|
| API + admin | `https://api.cornerarea.me` |
| Storefront | `https://decant-please.cornerarea.me` |
| Uploaded images (R2) | `https://images.cornerarea.me` |

> **🔒 Secrets are placeholders.** Every `<…>` below (R2 keys, admin password) is a placeholder.
> Real values live in the Cloudflare dashboard and in `heroku config` — never paste them into a
> file, commit, or screenshot. Read a live one back with
> `heroku config:get NAME -a decant-please-api`.

> **`DEPLOY.md` (repo root) is the maintained copy.** This file is a standalone snapshot you asked
> for — keep it, move it, or delete it. When a step here and `DEPLOY.md` disagree, trust `DEPLOY.md`.

---

## Phase 00 — Prerequisites

- The **Heroku CLI**, logged in. `heroku login` is browser-based — a human step, not scriptable.
- A **Cloudflare** account with `cornerarea.me` on Cloudflare DNS (R2 + the custom domains need it).
- The **GitHub Student** pack applied to Heroku, for the monthly credit.
- This monorepo (`backend/` + `frontend/`) pushed to GitHub, on `main`.

```bash
heroku login
heroku auth:whoami
```

---

## Phase 01 — Cloudflare R2 (dashboard only)

A Heroku dyno's disk is ephemeral, so uploaded images live in R2 and are served over a custom
domain. None of this is scriptable from the CLI — do it in the dashboard and collect the values
you'll paste in Phase 03.

1. **Create bucket** → name it `decant-please-images`.
2. Bucket → **Settings** → note the **S3 API endpoint** (`https://<account-id>.r2.cloudflarestorage.com`).
3. R2 → **Manage API Tokens** → Create → **Object Read & Write**, scoped to this bucket → copy the
   **Access Key ID** and **Secret Access Key** (shown once).
4. Bucket → Settings → **Public access → Custom Domains** → Connect `images.cornerarea.me` → wait
   until it reads **Active**.
5. **CORS Policy** (Settings → CORS Policy) → paste the JSON below → Save. Required for the admin's
   image previews (see gotcha #6); the storefront needs nothing.

```json
[
  {
    "AllowedOrigins": ["https://api.cornerarea.me"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

> **Why object-scoped, not admin.** The token only needs to read/write objects. That means it
> **can't** set the CORS policy over the S3 API (`PutBucketCors` → `AccessDenied`) — which is why
> the CORS step is a manual dashboard action, not a command.

---

## Phase 02 — Create the Heroku app

```bash
heroku create decant-please-api
heroku buildpacks:add -a decant-please-api https://github.com/lstoll/heroku-buildpack-monorepo
heroku buildpacks:add -a decant-please-api heroku/php
heroku config:set -a decant-please-api APP_BASE=backend
heroku addons:create -a decant-please-api heroku-postgresql:essential-0
```

> **⚠ Buildpack order matters.** The **monorepo buildpack must be first**. Told by
> `APP_BASE=backend`, it relocates `backend/` to the app root so `heroku/php` then finds
> `composer.json` and the `Procfile`. Reversed, the build fails.

> **Postgres wiring.** The add-on injects `DATABASE_URL`, which the `pgsql` connection reads
> directly — **do not** set `DATABASE_URL`, `DB_HOST`, or `DB_PORT` by hand. You **do** still need
> `DB_CONNECTION=pgsql` (Phase 03): `DATABASE_URL` only feeds that connection; the *default* is
> sqlite, and without the pin the release-phase migrate dies with `could not find driver (sqlite)`.

---

## Phase 03 — Config vars

One command. Swap every `<…>` for the real value (R2 values from Phase 01; pick a strong admin
password you don't reuse). `APP_KEY` is generated inline.

```bash
heroku config:set -a decant-please-api \
  APP_KEY="base64:$(openssl rand -base64 32)" \
  APP_ENV=production \
  APP_DEBUG=false \
  DB_CONNECTION=pgsql \
  SESSION_SECURE_COOKIE=true \
  APP_URL=https://api.cornerarea.me \
  FRONTEND_URL=https://decant-please.cornerarea.me \
  ADMIN_PASSWORD='<strong admin password>' \
  FILESYSTEM_DISK=s3 \
  MEDIA_DISK=s3 \
  AWS_ACCESS_KEY_ID='<R2 Access Key ID>' \
  AWS_SECRET_ACCESS_KEY='<R2 Secret Access Key>' \
  AWS_DEFAULT_REGION=auto \
  AWS_BUCKET=decant-please-images \
  AWS_ENDPOINT='<R2 S3 API endpoint>' \
  AWS_USE_PATH_STYLE_ENDPOINT=true \
  AWS_URL=https://images.cornerarea.me
```

- `MEDIA_DISK=s3` is what actually routes uploaded images to R2 — every image code path reads it.
  (`FILESYSTEM_DISK=s3` only sets Laravel's *default* disk.)
- `AWS_DEFAULT_REGION=auto` and `AWS_USE_PATH_STYLE_ENDPOINT=true` are R2's requirements, not typos.
- `AWS_URL` is the public custom domain — it's baked into the image URLs the API returns.

Confirm the whole set landed before deploying:

```bash
heroku config -a decant-please-api
```

---

## Phase 04 — What's already in the repo (no action)

These aren't commands — they're the code-side pieces a working deploy needs, already committed from
earlier fixes. Listed so a fresh app on this codebase Just Works, and so you know what to preserve.

- `backend/Procfile` — `web: vendor/bin/heroku-php-nginx -C conf/nginx/laravel.conf public/` and
  `release: php artisan migrate --force`.
- `backend/conf/nginx/laravel.conf` — routes clean URLs through the front controller **and** sets
  `client_max_body_size 10m`.
- `backend/public/.user.ini` — PHP `upload_max_filesize=8M` / `post_max_size=10M` (read only by
  Heroku's php-fpm).
- **Trust proxy** config (`bootstrap/app.php`) so per-IP rate limiting sees the real client, not
  Heroku's router.
- `league/flysystem-aws-s3-v3` in `composer.json`/lock — the R2 driver.
- The config-driven **media disk** + the `AdminPanelProvider::boot()` temp-disk pin (gotchas #4, #5).
- **Postgres-safe catalog queries** (case-insensitive search, `NULLS LAST` sort) — guarded by
  `backend/scripts/verify-postgres-portability.sh`.

> **If you change catalog queries.** SQLite (the test DB) is permissive where Postgres isn't.
> `php artisan test` can stay green while the live app is broken — run
> `verify-postgres-portability.sh` against the running stack, not just the suite.

---

## Phase 05 — First deploy

```bash
heroku git:remote -a decant-please-api
git push heroku main
```

- Watch for **"Monorepo app detected"** before the PHP buildpack output.
- The `release:` phase runs `migrate --force` automatically. Confirm it finishes clean and the web
  dyno reads **Up**.
- If it cycles Up/Crashed, the release phase failed even though the build passed →
  `heroku logs --tail -a decant-please-api`.

---

## Phase 06 — Seed the admin login

```bash
heroku run -a decant-please-api php artisan db:seed --force
heroku run -a decant-please-api php artisan decant:fresh-start
```

The first creates the admin user from `ADMIN_PASSWORD`; the second clears the demo catalog/orders
while keeping the admin account and brand list, ready for real inventory. Log in at `/admin` as
`admin@decantplease.local`.

---

## Phase 07 — Custom domain + TLS

```bash
heroku domains:add -a decant-please-api api.cornerarea.me
```

This prints a DNS target (`xyz.herokudns.com`). In Cloudflare, add a **CNAME** for `api` → that
target, **DNS only** (grey cloud) for this first pass — proxying just adds an SSL-mode interaction
to debug for no benefit at this traffic level. Heroku's ACM provisions the TLS cert automatically
once DNS resolves.

---

## Phase 08 — Verify (the load-bearing part)

"It deployed" ≠ "images persist." Run all of these — the last three catch a broken R2 wiring.

**App is up & talking to Postgres:**

```bash
heroku ps -a decant-please-api
curl -I https://api.cornerarea.me/api/v1/decant-please/meta      # expect HTTP 200 (the {shop} segment — Step 24)
```

**Images really land in R2 (write + public serve):**

```bash
heroku run -a decant-please-api "php artisan tinker --execute=\"echo Storage::disk(config('filesystems.media_disk'))->put('healthcheck/probe.txt','ok','public') ? Storage::disk(config('filesystems.media_disk'))->url('healthcheck/probe.txt') : 'FAILED';\""

# prints https://images.cornerarea.me/healthcheck/probe.txt — then:
curl -I https://images.cornerarea.me/healthcheck/probe.txt   # HTTP 200 = R2 serves it publicly
```

> **⚠ Wrap the whole tinker command in quotes** (as above). Unquoted, the Heroku CLI parses
> `--execute` as its own flag and prints its help instead of running anything.

**Persistence — the real test:**

```bash
heroku ps:restart -a decant-please-api
# reload the image URL — still 200 = it's on R2, not the dyno's ephemeral disk
```

Finally, upload an image in `/admin` and confirm **no console CORS error** on save/preview — that
proves the temp-disk pin (upload) *and* the R2 CORS policy (preview) are both in place.

---

## Phase 09 — Frontend on Vercel

1. Import the repo in Vercel, set **Root Directory** to `frontend/`.
2. Add three environment variables:

```
NEXT_PUBLIC_API_URL    = https://api.cornerarea.me/api
NEXT_PUBLIC_SITE_URL   = https://decant-please.cornerarea.me
NEXT_PUBLIC_IMAGE_URL  = https://images.cornerarea.me
```

3. Deploy, then point `decant-please.cornerarea.me` at Vercel. Make sure the backend's
   `FRONTEND_URL` matches it exactly (that's the CORS allowlist *and* the admin "View on site" links).

> **⚠ `NEXT_PUBLIC_*` are baked at build time.** Setting `NEXT_PUBLIC_IMAGE_URL` only takes effect
> on the **next build** — trigger a redeploy after adding it, or R2 image URLs get rejected by the
> image optimizer (a `400`) and catalog photos won't render.

---

## Lessons paid for in production (the six that weren't in the prompt)

Each was a live bug after a clean-looking deploy. All fixed in the repo now — this is the "why," so
a future you recognizes the symptom fast.

| # | PR | Symptom | Fix |
|---|----|---------|-----|
| 1 | #24 | **Everything 404'd** — the bare PHP buildpack doesn't route Laravel's clean URLs. | Procfile `-C conf/nginx/laravel.conf` sends `/api/*`, `/admin`, `/up` through the front controller. |
| 2 | — | **Migrate: "no sqlite driver"** — `DATABASE_URL` feeds only the `pgsql` connection; the default is sqlite. | Pin `DB_CONNECTION=pgsql` in config vars. |
| 3 | #26 | **Rate limiting keyed everyone the same** — behind Heroku's router every request looked like one IP. | Trust the proxy in `bootstrap/app.php` so per-IP throttles see the real client. |
| 4 | #29 | **Images vanished on restart** — every uploader hardcoded `->disk('public')` (ephemeral local disk); `FILESYSTEM_DISK` never reached them. | A config-driven `MEDIA_DISK` at all seven image sites. |
| 5 | #29 | **Upload blocked by CORS (a PUT)** — Livewire's temp disk defaulted to R2, so the browser PUT straight to R2. | Pin the temp disk to `local` in `AdminPanelProvider`; Laravel writes to R2 server-side. |
| 6 | #31 | **Saved-image preview blocked (a GET)** — Filament's uploader fetches the saved file from R2 client-side. | The R2 bucket CORS policy in Phase 01. (`fetchFileInformation(false)` does not help — server-side only.) |

**Also worth knowing:** fragrance images run ~2 MB, right at PHP's default 2M upload ceiling —
that's why `public/.user.ini` (8M) and nginx `client_max_body_size 10m` both exist. And the Postgres
`LIKE`/`ORDER BY` differences from Step 12 are handled in the catalog queries; keep them
Postgres-safe.
