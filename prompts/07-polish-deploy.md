# Step 7 — Polish, real data & deployment

Final pass across `backend/` and `frontend/`. Follow `CLAUDE.md`.

## 1. Cross-cutting review

- Re-read `CLAUDE.md` top to bottom and audit the whole codebase against it.
  List anything missing or off-spec (filters, snapshot pricing, Kyat formatting,
  white-minimalist look, DM-only ordering) and fix it.
- N+1 audit: enable `Model::preventLazyLoading()` in non-production and click
  through admin + hit every API endpoint; fix any lazy-load exceptions.
- Empty states everywhere (no fragrances match filters, no orders today, etc.).

## 2. Quality-of-life for the decanter

- Filament: add a **"View on site"** link/action on FragranceResource rows opening
  `FRONTEND_URL/fragrance/{slug}` (env-configured) in a new tab.
- Orders CSV export action (respecting current filters) — decanters love a monthly
  spreadsheet: columns = date, customer, phone, source, items summary, decant date,
  delivery date, status, total.
- Settings approach for the decanter's TikTok/Facebook page URLs: simplest viable —
  backend `.env` exposed via `/api/v1/meta` (frontend then doesn't need its own env
  for these). Refactor Step 6 buttons to read from `/meta` with env fallback.

## 3. Production hardening

- Backend: `APP_DEBUG=false` checklist, forced HTTPS URLs, queue not required (keep
  sync), image validation limits, Filament panel restricted to authenticated users
  only, sensible session/cookie config.
- API: confirm rate limiting + CORS locked to the production frontend origin.
- Frontend: production `NEXT_PUBLIC_API_URL`, remote image domain for the prod
  backend host, favicon + simple wordmark logo, `robots.txt`, basic OG defaults.

## 4. Deployment docs (write `DEPLOY.md`)

Document the simplest sensible setup, with exact commands:
- **Backend:** a VPS (or Laravel Forge-style flow): PHP-FPM + Nginx, MySQL,
  `composer install --no-dev`, migrations, `storage:link`, cron for `schedule:run`
  (even if unused now), env template.
- **Frontend:** Vercel (recommended) or Node on the same VPS; required env vars.
- Local dev quickstart recap (two terminals).
- Backup note: nightly `mysqldump` of `decant_please` — order history is the
  decanter's financial record.

## 5. Handover seed

- Replace obviously fake seeder data paths: add an artisan command
  `php artisan decant:fresh-start` that wipes demo fragrances/orders but keeps the
  admin user and brand list, so the decanter can start entering real inventory.

## 6. Final report

Deliver: what was audited/fixed, test suite results, a 10-line "how the decanter
uses this daily" walkthrough (morning: check Today's Decants tab → decant → mark
Decanted → deliveries → mark Delivered → new DMs → enter orders), and the deploy
checklist status.
