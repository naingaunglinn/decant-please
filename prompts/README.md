# Decant Please! — Claude Code Prompt Pack (v5)

A step-by-step prompt pack to build **Decant Please!**, a perfume decant catalog,
checkout, and order-management system for Myanmar decanters, using **Next.js**
(customer storefront) and **Laravel + Filament** (admin panel & API).

## What's new

v1 → v2 added self-service checkout in place of DM-only ordering. v3 turned the
order-complete page into a real receipt and added a few catalog fundamentals. v4
adds a Burmese/English toggle and promo codes. v5 is a responsive/mobile pass plus
Flutter-ready foundations (a written API contract and portable design tokens).
Full history in `00-CLAUDE.md` §0.

## How to use this pack

1. Create your project root folder, e.g. `decant-please/`, and open Claude Code inside it.
2. Copy `00-CLAUDE.md` into the project root as `CLAUDE.md`. Claude Code automatically
   reads `CLAUDE.md` at the start of every session, so it will always understand your
   system before touching code.
3. Run the prompts **in order**, one per session (or one per task in the same session):

   | Step | File | What it builds | Status |
   |------|------|----------------|--------|
   | 0 | `00-CLAUDE.md` | Project memory / system understanding (place as `CLAUDE.md`) | built |
   | 1 | `01-understand-system.md` | Kickoff — restate & confirm the system, scaffold folders | built |
   | 2 | `02-database-schema.md` | Laravel migrations, models, relationships, seeders | built |
   | 3 | `03-admin-filament.md` | Filament admin panel — Brand & Fragrance CRUD, decant prices | built |
   | 4 | `04-order-management.md` | Order review (Accept/Reject), production schedule, manual entry | built |
   | 5 | `05-api-layer.md` | Public catalog API + checkout & tracking endpoints | built |
   | 6 | `06-frontend-nextjs.md` | Next.js storefront — catalog, cart, checkout, tracking, design system | built |
   | 7 | `07-polish-deploy.md` | Seeding real data, performance, SEO, deployment notes | built |
   | 8 | `08-order-confirmation-and-polish.md` | Real receipt, printable/PDF, customer cancellation, related fragrances, sitemap | built |
   | 10 | `10-promo-codes.md` | Promo/discount codes at checkout | built |
   | 9 | `09-burmese-language-toggle.md` | Burmese/English toggle for the customer site | spec ready — do last, translates 08 & 10's new strings too |
   | 11 | `11-responsive-and-mobile-foundations.md` | Responsive pass (wider PDP/receipt pages, 16px inputs, touch targets) + API contract & design tokens | built |
   | 12 | `12-mysql-to-postgresql-migration.md` | Swap MySQL 8 for PostgreSQL 17 (Heroku has no first-party MySQL) | built |
   | 13 | `13-production-heroku-cloudflare-setup.md` | Deploy backend to Heroku + Cloudflare R2 image storage | built |
   | 14 | `14-printable-order-invoices.md` | Admin print & download A5 order invoices (single + daily batch PDF) | built |
   | 16 | `16-cicd-heroku-github.md` | CI/CD — test-gated auto-deploy from GitHub to Heroku | built (CI); Heroku GitHub connect is a one-time dashboard step |
   | 17 | `17-develop-main-branching.md` | `develop`/`main` branch split — feature PRs land on `develop`, production ships via a manual promotion PR | built |
   | 18 | `18-decant-bottle-stock.md` | Real-time decant stock per physical bottle — auto `in_stock`, accept-time pour with availability guard | built |

4. For each step, paste the whole file content as your prompt (or tell Claude Code:
   `Read 02-database-schema.md and implement it`, if you keep the files in a `prompts/`
   folder inside the repo — recommended).
5. After each step, review, run the app, and commit before moving to the next step.

## From Step 8 onward: issue → branch → PR per step

Steps 8+ follow a standard process instead of a plain "read the file and implement
it" prompt — one GitHub issue and one reviewable PR per step, nothing merged without
a human looking at it first. The process is written once in `prompts/WORKFLOW.md` and
reused for every future step, not just 8-10.



## Tips

- Keep the `prompts/` folder in the repo. Claude Code can re-read any step later
  ("re-check step 04 requirements — did we miss anything?").
- If Claude Code drifts from the spec, say: **"Re-read CLAUDE.md and follow the
  Decant Please! spec."**
- If you already built v1 and are layering v2 in, say so explicitly when you hand
  Claude Code the updated files — e.g. "we already have v1 running; apply v2 as an
  addition, not a rebuild" — so it reaches for new migrations instead of editing old
  ones.
- Suggested repo layout (monorepo):

```
decant-please/
├── CLAUDE.md
├── prompts/            ← these files
├── backend/            ← Laravel + Filament
└── frontend/            ← Next.js
```
