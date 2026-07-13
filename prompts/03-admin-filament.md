# Step 3 — Filament admin: Brand & Fragrance CRUD

Work in `backend/` on the Filament v5 panel at `/admin`. Follow `CLAUDE.md`.
Goal: the decanter can fully manage the catalog without touching code.

## 1. Panel setup

- Panel brand name: **"Decant Please!"**; keep Filament's clean default look
  (white/minimal fits the product).
- Navigation groups: **Catalog** (Brands, Fragrances) and **Sales** (Orders — Step 4).

## 2. BrandResource

- Table: logo (circular image), name, type badge (Designer/Niche), fragrances count,
  is_active toggle-able column, updated_at.
- Filters: type, is_active. Searchable by name. Default sort: name.
- Form: name (required, live slug preview), type (select from enum), logo
  (image upload → `public` disk, `brands/` dir, image editor enabled, max ~2MB),
  is_active toggle.
- Slug is auto-generated — show it as disabled/hint text, don't make the user type it.
- Deleting a brand warns that its fragrances will be deleted too (cascade).

## 3. FragranceResource (the heart of the admin)

### Table
- Columns: image (square thumb), brand name, name, concentration badge,
  gender badge (male=blue, female=rose, unisex=gray), "From price" (min in-stock
  decant price formatted as Kyat, e.g. `From 50,000 Ks`), sizes summary
  (e.g. `5ml · 10ml · 30ml`), is_active toggle column, is_featured icon column.
- Filters: brand (searchable select), brand type (via relationship), concentration,
  gender, is_active, is_featured, "has size" (5ml/10ml/30ml).
- Search: fragrance name + brand name. Default sort: newest first.

### Form (two-column layout)
Left section — Identity:
- brand (searchable select with inline "create brand" option)
- name (required)
- concentration (select)
- gender (select)
- image (upload → `public` disk, `fragrances/` dir, image editor, 1:1 crop hint)

Right section — Scent profile:
- notes (textarea, helper: `Comma separated — e.g. Citrus, Musk, Amber, Orange, Grapefruit`)
- vibes (textarea, helper: `e.g. Modern, Clean, Alluring, Classy`)
- performance (text, helper: `e.g. Around 6-8 Hours`)
- description (textarea, optional)
- is_active, is_featured toggles

Bottom section — **Decant prices (Repeater on the `decantPrices` relationship):**
- size_ml (numeric, suffix "ml", quick-select suggestions 5 / 10 / 30)
- price_mmk (numeric, suffix "Ks", thousands separator mask)
- in_stock toggle (default on)
- min 1 row; prevent duplicate sizes within the repeater (validation);
  default rows for a new fragrance: 5ml, 10ml, 30ml (empty prices).

### Extras
- Replicate (duplicate) action — decanters often add similar flankers.
- Bulk actions: activate / deactivate.
- `getGlobalSearch` support: find fragrances from the top search bar.

## 4. Storage

- Run/ensure `php artisan storage:link`; confirm uploaded images are web-accessible
  at `/storage/...` (the API and Next.js will need full URLs later).

## 5. Verify

- Boot the panel, log in with the seeded admin, and confirm:
  - creating a brand + fragrance with 3 decant prices works end-to-end,
  - duplicate size in the repeater is rejected,
  - "From price" column matches the cheapest in-stock price,
  - image upload displays in the table.
- Report with a short checklist. Order management is Step 4 — don't start it yet.

**Version note:** if this file and `CLAUDE.md`'s tech stack table ever disagree on a
version number, `CLAUDE.md` wins — flag it and confirm before proceeding, don't just
silently pick one.
