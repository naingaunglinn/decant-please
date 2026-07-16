# Deploying Decant Please!

Two apps, deployed separately: `backend/` (Laravel + Filament admin + JSON API) and
`frontend/` (Next.js storefront). The frontend only ever talks to the backend over
`https://<api-host>/api/v1/*`.

---

## 1. Backend — a small VPS with PHP-FPM + Nginx + PostgreSQL

Works the same on a bare VPS, Laravel Forge, or Ploi. Requirements: **PHP 8.3+**
(with `pdo_pgsql`, `mbstring`, `intl`, `gd`), **PostgreSQL 17+**, **Composer**, Nginx.

### First deploy

```bash
# as your deploy user, e.g. in /var/www
git clone <repo-url> decant-please
cd decant-please/backend

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
# now edit .env — see the template below

php artisan migrate --force
php artisan db:seed --force          # seeds the admin user + demo catalog
php artisan storage:link             # public disk → public/storage (images)

php artisan config:cache
php artisan route:cache
php artisan view:cache

# writable dirs
chown -R www-data:www-data storage bootstrap/cache
```

### Production `.env` (the lines that matter)

```env
APP_ENV=production
APP_DEBUG=false                      # never true in production
APP_URL=https://api.decantplease.example
FRONTEND_URL=https://decantplease.example   # CORS + "View on site" links

ADMIN_PASSWORD=<strong-password>     # read once by db:seed for admin@decantplease.local

SOCIAL_TIKTOK_URL=https://www.tiktok.com/@yourpage    # storefront footer; blank = hidden
SOCIAL_FACEBOOK_URL=https://www.facebook.com/yourpage

DB_DATABASE=decant_please
DB_USERNAME=decant
DB_PASSWORD=<db-password>            # not root in production

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true           # admin runs over HTTPS only
QUEUE_CONNECTION=sync                # nothing queues today — no worker needed
CACHE_STORE=database
```

Notes:

- `APP_ENV=production` also switches on forced-HTTPS URL generation (see
  `AppServiceProvider`) and switches off the dev-only lazy-loading guard.
- After any later `.env` change, re-run `php artisan config:cache`.
- The admin panel is already auth-only (Filament login). There is no public
  registration route anywhere.

### Nginx (standard Laravel server block)

Root at `backend/public`, `try_files $uri $uri/ /index.php?$query_string;`, PHP-FPM
socket for `\.php$`. Add `client_max_body_size 5m;` so 2 MB image uploads clear the
proxy. Put TLS on with certbot/Let's Encrypt.

### Cron

```cron
* * * * * cd /var/www/decant-please/backend && php artisan schedule:run >> /dev/null 2>&1
```

Nothing is scheduled yet, but with the cron in place, future scheduled jobs (e.g. the
backup below) just work.

### Backups — not optional

Order history is the decanter's financial record. Nightly dump, kept two weeks:

```cron
30 18 * * * pg_dump decant_please | gzip > /var/backups/decant_please-$(date +\%F).sql.gz
40 18 * * * find /var/backups -name 'decant_please-*.sql.gz' -mtime +14 -delete
```

(18:30 UTC = 01:00 Myanmar time.) Also back up `backend/storage/app/public/` —
that's every uploaded fragrance image. Copy at least one dump off the server.

### Later deploys

```bash
cd backend && git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## 2. Frontend — Vercel (recommended)

1. Import the repo in Vercel, set **Root Directory** to `frontend/`.
2. Environment variables:

   | Variable | Value |
   |---|---|
   | `NEXT_PUBLIC_API_URL` | `https://api.decantplease.example/api` |
   | `NEXT_PUBLIC_SITE_URL` | `https://decantplease.example` |

3. Deploy. Remote images are allowed automatically for the `NEXT_PUBLIC_API_URL`
   host (see `next.config.ts`), so fragrance photos work with no extra config.
4. Point the storefront domain at Vercel; make sure the backend `.env`
   `FRONTEND_URL` matches it exactly (scheme included) — that's the CORS allowlist.

**Alternative — Node on the same VPS:** `npm ci && npm run build`, then run
`npm start` (port 3000) under systemd or pm2, and put an Nginx `proxy_pass`
server block with TLS in front. Same two env vars, in `frontend/.env.local`.

---

## 3. Local dev quickstart (two terminals)

Or one terminal and no local toolchains: `docker compose up` at the repo root does all
of this for you — see the [root README](README.md#getting-started).

```bash
# terminal 1 — backend  (PostgreSQL running; see backend/.env for credentials)
cd backend
composer install
cp .env.example .env && php artisan key:generate   # first time only; set ADMIN_PASSWORD
php artisan migrate --seed                          # first time only
php artisan storage:link                            # first time only; serves uploaded images
php artisan serve --port=8010                       # http://localhost:8010, admin at /admin

# terminal 2 — frontend
cd frontend
npm install
cp .env.local.example .env.local                    # first time only
npm run dev -- -p 3001                              # http://localhost:3001
```

Admin login: `admin@decantplease.local` / whatever `ADMIN_PASSWORD` was when you seeded.

---

## 4. Handover to the decanter

When the real catalog is ready to go in, wipe the demo data but keep the admin user
and the brand list:

```bash
php artisan decant:fresh-start
```

It asks for confirmation, then deletes all orders and all fragrances (including
their prices and uploaded images). Brands stay, so entering real inventory starts
at "add fragrance", not from zero.

## 5. Pre-launch checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `config:cache` run
- [ ] HTTPS on both domains; `SESSION_SECURE_COOKIE=true`
- [ ] `FRONTEND_URL` = exact storefront origin (CORS + admin "View on site" links)
- [ ] `ADMIN_PASSWORD` strong, admin login verified at `/admin`
- [ ] `storage:link` run — upload a fragrance image and see it on the storefront
- [ ] Nightly `pg_dump` cron in place and produces a file
- [ ] Place one test order end-to-end: checkout → tracking page → accept in admin →
      production schedule shows it → CSV export contains it
- [ ] `php artisan decant:fresh-start` run once real inventory entry begins
