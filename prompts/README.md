# Decant Please! — Claude Code Prompt Pack (v2)

A step-by-step prompt pack to build **Decant Please!**, a perfume decant catalog,
checkout, and order-management system for Myanmar decanters, using **Next.js**
(customer storefront) and **Laravel + Filament** (admin panel & API).

## What's new in v2

v1 was DM-only: browse the site, order by DMing TikTok/Facebook, decanter transcribes
the order manually. **v2 adds a real self-service checkout** — cart, checkout form,
order-complete page with a tracking code, and a tracking page — plus an admin
Accept/Reject review step and an auto-generated daily decant production schedule.
The brand/fragrance/decant-price catalog is unchanged; the design system, the order
model, and the customer-facing feature set are not. See `00-CLAUDE.md` §0 for the
full summary and `02-database-schema.md`'s "Applying v2 to an existing build" section
if you already ran v1.

## How to use this pack

1. Create your project root folder, e.g. `decant-please/`, and open Claude Code inside it.
2. Copy `00-CLAUDE.md` into the project root as `CLAUDE.md`. Claude Code automatically
   reads `CLAUDE.md` at the start of every session, so it will always understand your
   system before touching code.
3. Run the prompts **in order**, one per session (or one per task in the same session):

   | Step | File | What it builds | v2 status |
   |------|------|----------------|-----------|
   | 0 | `00-CLAUDE.md` | Project memory / system understanding (place as `CLAUDE.md`) | **updated** |
   | 1 | `01-understand-system.md` | Kickoff prompt — restate & confirm the system, scaffold folders | unchanged |
   | 2 | `02-database-schema.md` | Laravel migrations, models, relationships, seeders | **updated** |
   | 3 | `03-admin-filament.md` | Filament admin panel — Brand & Fragrance CRUD, decant prices | unchanged |
   | 4 | `04-order-management.md` | Order review (Accept/Reject), production schedule, manual entry | **updated** |
   | 5 | `05-api-layer.md` | Public read-only catalog API **+ checkout & tracking endpoints** | **updated** |
   | 6 | `06-frontend-nextjs.md` | Next.js storefront — catalog, cart, checkout, tracking, new design system | **updated** |
   | 7 | `07-polish-deploy.md` | Seeding real data, performance, SEO, deployment notes | unchanged |

4. For each step, paste the whole file content as your prompt (or tell Claude Code:
   `Read 02-database-schema.md and implement it`, if you keep the files in a `prompts/`
   folder inside the repo — recommended).
5. After each step, review, run the app, and commit before moving to the next step.

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
