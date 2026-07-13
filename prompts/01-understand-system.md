# Step 1 — Understand the system & scaffold the monorepo

Read `CLAUDE.md` in the project root carefully. Before writing any code, prove you
understand the system by answering these in your own words (briefly):

1. Who is the "decanter" and what real-world problem does Decant Please! solve for
   Myanmar customers who currently DM TikTok/Facebook pages one by one?
2. Orders can now arrive two ways — a website checkout or a manual admin entry for a
   customer who DMs instead. Explain both paths, and why a website order starts
   `awaiting_confirmation` while a manually-entered one starts `pending` directly.
3. What is the purpose of `decant_date` vs `delivery_date` on an order?
4. Why must order item prices be snapshots instead of references to catalog prices?
5. What filters must the customer catalog support?

Wait for my confirmation of your summary before proceeding. After I confirm:

## Scaffold the monorepo

Create this structure:

```
decant-please/
├── CLAUDE.md            (already present — do not modify)
├── prompts/             (already present — do not modify)
├── backend/             ← fresh Laravel 13 app
└── frontend/            ← fresh Next.js 16 app (App Router, TypeScript, Tailwind v4)
```

Tasks:

1. `backend/`: create a new Laravel 13 project (PHP 8.3+). Configure `.env.example`
   for MySQL with database name `decant_please`. Install **Filament v5** and create
   an admin panel at `/admin` with a seeded admin user
   (`admin@decantplease.local` / password from `.env`, never hard-coded).
2. `frontend/`: create a new Next.js 16 app (Node 24+) with TypeScript,
   Tailwind CSS v4, ESLint, App Router, `src/` directory. Add `.env.local.example`
   with `NEXT_PUBLIC_API_URL=http://localhost:8000/api`. Also run
   `npm install gsap motion` now — Step 6 needs both; nothing to configure yet,
   just get them installed.
3. Add a root `README.md` with local dev instructions:
   - backend: `php artisan serve` (port 8000), `php artisan migrate --seed`
   - frontend: `npm run dev` (port 3000)
4. Add sensible root `.gitignore` covering both apps.
5. Verify both apps boot (`php artisan about`, `npm run build` or dev server check)
   and report results.

Do **not** create any domain models, migrations, or pages yet — that's Step 2+.
Stop after scaffolding and give me a short status report.

**Version note:** every version above must match `CLAUDE.md`'s tech stack table —
that table is the source of truth if the two ever disagree again.
