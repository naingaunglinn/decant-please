# Deploying Decant Please!

Two apps, deployed separately: `backend/` (Laravel + Filament admin + JSON API) on
**Heroku**, and `frontend/` (Next.js storefront) on **Vercel**. The frontend only ever
talks to the backend over `https://api.cornerarea.me/api/v1/*`.

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

Uploaded images live in an R2 bucket served over a custom domain. From the Cloudflare
dashboard, collect four values before touching Heroku:

1. R2 Object Storage → Create bucket → **`decant-please-images`**.
2. Bucket → Settings → note the **S3 API endpoint**.
3. R2 → Manage API Tokens → Create Token → **Object Read & Write**, scoped to this bucket
   only → copy the **Access Key ID** and **Secret Access Key** (R2 shows the secret once).
4. Bucket → Settings → Public access → Custom Domains → Connect Domain →
   **`images.cornerarea.me`**; wait until it reads **Active**, not just Initializing.

Guessing or stubbing these produces a backend that deploys clean and then silently can't
store an image — don't proceed to config vars without all four.

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

### Config vars

```bash
heroku config:set -a decant-please-api \
  APP_KEY="base64:$(openssl rand -base64 32)" \
  APP_ENV=production \
  APP_DEBUG=false \
  APP_URL=https://api.cornerarea.me \
  FRONTEND_URL=https://decant-please.cornerarea.me \
  ADMIN_PASSWORD='<strong password, not reused from elsewhere>' \
  FILESYSTEM_DISK=s3 \
  AWS_ACCESS_KEY_ID='<R2 Access Key ID>' \
  AWS_SECRET_ACCESS_KEY='<R2 Secret Access Key>' \
  AWS_DEFAULT_REGION=auto \
  AWS_BUCKET=decant-please-images \
  AWS_ENDPOINT='<R2 S3 API endpoint>' \
  AWS_USE_PATH_STYLE_ENDPOINT=true \
  AWS_URL=https://images.cornerarea.me
```

`FILESYSTEM_DISK=s3` points Laravel's default disk at the pre-wired `s3` block in
`config/filesystems.php`, which reads every `AWS_*` var above; `AWS_URL` is the public R2
domain baked into image URLs. Verify the whole set landed — `heroku config -a
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

There is no `storage:link`, no Nginx server block, and no `chown` step here — the buildpack
provides nginx + PHP-FPM, and images live in R2 rather than on the dyno.

### Image upload size

Fragrance images run ~2 MB, right at PHP's default `upload_max_filesize` (2M) — the same
edge the bare-VPS guide handled by raising Nginx's `client_max_body_size` to 5m. On Heroku
the upload transits nginx **and** php-fpm, so both layers need headroom:

- **PHP** is handled in-repo by `backend/public/.user.ini` (`upload_max_filesize=8M`,
  `post_max_size=10M`) — Heroku's php-fpm reads it automatically; it's a no-op locally.
- **Nginx** — if an upload returns **413** at the proxy, the buildpack's default
  `client_max_body_size` is too low. Add a config partial and point the web process at it:
  ```nginx
  # backend/conf/nginx/uploads.conf
  client_max_body_size 10m;
  ```
  ```procfile
  # backend/Procfile — web line becomes:
  web: vendor/bin/heroku-php-nginx -C conf/nginx/uploads.conf public/
  ```
  Left out of the default Procfile because the buildpack's default may already clear a 2 MB
  image; add it only if a real upload 413s, then redeploy.

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

### Later deploys / auto-deploy

`git push heroku main` redeploys manually; the release phase re-runs migrations on its own,
so there's no separate `migrate` step. To match how every other step in this project ships
— every merged PR deploying automatically — wire up GitHub auto-deploy once, by hand:
Heroku dashboard → app → **Deploy** tab → Deployment method → GitHub → connect
`naingaunglinn/decant-please` → **Enable Automatic Deploys** for `main`. That's an OAuth
dashboard flow, not a CLI step.

### Production notes

- `APP_ENV=production` switches on forced-HTTPS URL generation (see `AppServiceProvider`)
  and switches off the dev-only lazy-loading guard.
- The admin panel is auth-only (Filament login); there is no public registration route
  anywhere.
- Config vars are read from the environment on every boot — there is no `.env` file on the
  dyno and no `config:cache` to re-run after a change. Setting a config var restarts the
  dyno with the new value.

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

1. Import the repo in Vercel, set **Root Directory** to `frontend/`.
2. Environment variables:

   | Variable | Value |
   |---|---|
   | `NEXT_PUBLIC_API_URL` | `https://api.cornerarea.me/api` |
   | `NEXT_PUBLIC_SITE_URL` | `https://decant-please.cornerarea.me` |

3. Deploy, then point the storefront domain (`decant-please.cornerarea.me`) at Vercel. Make
   sure the backend's `FRONTEND_URL` config var matches it exactly, scheme included — that's
   the CORS allowlist **and** the admin "View on site" links.

> **Known gap — fragrance images from R2.** `next.config.ts`'s image `remotePatterns` only
> allows the API host's `/storage/**` path (plus localhost). Once the backend serves images
> from `images.cornerarea.me` (R2), Next.js's image optimizer will reject them and catalog
> photos will 400 on the storefront. This is deliberately out of scope for the backend
> deploy step that introduces R2 — tracked in **#22**. Don't treat storefront images as done
> until it's fixed.

**Alternative — Node on a VPS:** `npm ci && npm run build`, then `npm start` (port 3000)
under systemd or pm2 with an Nginx `proxy_pass` + TLS in front. Same two env vars, in
`frontend/.env.local`.

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

Locally, images still use the `local`/`public` disk and `storage:link` — R2 is production
only, selected by `FILESYSTEM_DISK=s3` there and left unset (so `local`) here.

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
- [ ] API routing works past `/`: `curl -I https://api.cornerarea.me/api/v1/meta` returns
      `200` — a 404 here while `/` serves means the buildpack needs a custom nginx conf (`-C`)
- [ ] `FILESYSTEM_DISK=s3` + R2 vars set — upload a fragrance image in `/admin` and confirm
      its URL resolves under `https://images.cornerarea.me/`, not a `local`-disk path (a 413
      means the upload limits need raising — see "Image upload size")
- [ ] Storefront image gap (#22) resolved before relying on catalog photos in production
- [ ] `api.cornerarea.me` shows **Cert issued** (`heroku certs:auto`)
- [ ] Both backup layers working: `heroku pg:backups` produces a capture **and** the
      off-Heroku `pg_dump` cron produces a file
- [ ] Place one test order end-to-end: checkout → tracking page → accept in admin →
      production schedule shows it → CSV export contains it
- [ ] `heroku run … php artisan decant:fresh-start` run once real inventory entry begins
- [ ] Account-level check: dashboard → Billing shows Platform Credits intact and no add-on
      or app on the account beyond this one
