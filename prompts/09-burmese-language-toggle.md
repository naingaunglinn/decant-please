# Step 9 — Burmese / English language toggle (customer site)

Work in `frontend/`, plus one small optional `backend/` addition for bilingual
catalog text. Follow `CLAUDE.md` v4. Admin panel is untouched — the decanter already
operates it in whatever language they're comfortable with; this is customer-facing
only.

## 1. Scope — two tiers, don't blur them

**Tier A (build this): UI chrome.** Every string Claude Code itself wrote — nav,
buttons, form labels, status names, empty states, error messages, footer, home page
copy. Fully translated, toggle-able, no admin work required from the decanter.

**Tier B (optional, additive): catalog text.** Fragrance `notes`, `vibes`,
`performance`, `description`. Brand and fragrance *names* are proper nouns and stay
as typed regardless of locale (a Burmese-reading customer still sees "Chanel Allure
Homme Sport," not a translation of it) — only the descriptive scent-profile text is a
candidate for translation, and only if the decanter chooses to fill it in.

Don't conflate the two: Tier A is required for the toggle to mean anything; Tier B is
a nice-to-have the decanter can grow into over time without it blocking anything.

## 2. Approach — no new dependency, no URL-based routing

Given the size of the string set (one language pair, no pluralization complexity to
speak of), a small hand-rolled dictionary is simpler to reason about here than pulling
in `next-intl` or similar — consistent with `CLAUDE.md`'s "keep it simple" stance.

- `frontend/src/lib/i18n/en.ts`, `frontend/src/lib/i18n/mm.ts` — flat, nested-by-section
  key/value dictionaries (mirror each other's keys exactly; a missing `mm` key should
  fall back to the `en` value rather than rendering blank or crashing).
- `frontend/src/lib/i18n/index.ts` — `type Locale = "en" | "mm"`, a `t(dict, key)`
  helper, and a `LocaleProvider` client context exposing `{ locale, setLocale }`.
- Persist via a plain cookie (`decant-please-locale`), not `localStorage` — a Server
  Component needs to read it too (for the initial server-rendered HTML to already be
  in the right language, no flash of English-then-Burmese). Read it in
  `app/layout.tsx` (server component, via `next/headers` `cookies()`), pass the
  initial locale into `LocaleProvider`; `setLocale` writes the cookie and triggers a
  refresh.
- Toggle UI: a small two-state pill in the `Navbar` — "EN / MM" (or the Burmese
  autonym), same visual language as everything else, not a flag icon dropdown.

**Deliberately not doing URL-based locale routing** (`/en/shop` vs `/mm/shop`) — it's
the more correct choice for SEO (each language gets its own indexable, hreflang-linked
URL) but roughly doubles the routing surface, touches the sitemap from Step 8, and
adds middleware. Flagging this as a known trade-off, not an oversight — say so if you
want the fuller URL-based version instead; it's a bigger, separable piece of work.

## 3. What needs a key

Every static string currently in: `Navbar`, `Footer`, the home page sections (hero,
how-it-works, category tiles), `FilterBar`/`FilterSheet` labels and the sort options,
empty/loading states on `/shop`, the fragrance detail page's pill labels
(Performance/Notes/Gender) and `Add to cart`, the `CartDrawer`, `CheckoutForm`'s field
labels and validation messages, `OrderSummaryCard`, the `OrderReceipt` component from
Step 8 (status labels, payment-summary labels, the cancel confirmation, the print
button), and `not-found`/`error` pages.

**Important caveat on the Burmese text itself:** draft `mm.ts` as a genuine
first-pass translation, but flag it clearly in your status report as
**needing native-speaker review before it ships** — get this in front of the
decanter for a read-through pass. Machine-drafted Burmese for real customer-facing
copy is a starting point, not a final answer; don't present it as production-ready.

## 4. Tier B — optional bilingual catalog fields

If you're doing this part: add nullable `notes_mm`, `vibes_mm`, `performance_mm`,
`description_mm` columns to `fragrances` (new migration, additive — don't touch the
existing English columns). Add matching optional fields to `FragranceResource`'s
form, grouped under a collapsed "Burmese (optional)" section so the existing form
isn't cluttered for a decanter who skips this. `FragranceResource` (API) includes
these when present; the frontend renders the `_mm` value when the current locale is
`mm` **and** that field is non-empty, otherwise falls back to the English value —
never a blank pill because a translation hasn't been entered yet.

## 5. Verify

- Toggle switches every Tier-A string instantly, persists across a reload (cookie),
  and the server-rendered HTML is already in the right language on first paint — no
  English flash before it switches.
- A missing `mm` key anywhere falls back to English rather than rendering empty or
  throwing.
- If you built Tier B: a fragrance with no `_mm` fields filled in still reads
  correctly in Burmese mode (falls back to English notes/vibes/performance).
- Report which strings you're least confident on translation-wise, so the decanter
  knows where to focus their review pass first.
