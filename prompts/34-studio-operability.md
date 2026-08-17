# Step 34 — Studio: lifecycle, accountability, and the registry

> Prerequisite: steps 32 and 33 merged. This step adds surface; the two before it make
> that surface safe to add.

## Issue draft

**Title:** Studio panel — shop lifecycle, roles, impersonation audit, registry table

**Body:**
`/studio/shops` today is a registry that can register a shop and open its panel. Two
shops is the largest number where that's enough. This step gives the platform operator
the four things they'll need at five shops: a state model richer than a boolean, a role
model richer than `is_studio`, a record of who opened whose panel, and a table that says
whether a shop is alive.

---

## 1. Shop lifecycle replaces `is_active`

`is_active` is doing three jobs: "the API 404s", "the owner paused", "we suspended them".
Split it:

```
onboarding → live → suspended → archived
```

- `onboarding` — registered, no catalog or no payment settings yet. Storefront closed.
- `live` — normal.
- `suspended` — with a required reason and an actor. Admin panel read-only for the owner
  (not locked out — their order history is their financial record); storefront shows a
  closed state.
- `archived` — soft, retention window, then the export-and-delete path.

**One decision to make explicitly:** what a suspended shop's storefront returns. A `404`
matches the unknown-shop response and leaks nothing, but a customer holding a link gets
a lie. A `503` with a "temporarily closed" page is honest and — since shops are public
storefronts, not secrets — discloses nothing worth protecting. Default to the closed
page; write the reasoning down either way.

Keep `is_active` as a derived accessor for one release if anything reads it, or remove it
and fix the call sites. Don't leave both as writable state.

## 2. Roles beyond `is_studio`

Before a second staff login exists: `studio_admin` | `shop_owner` | `shop_staff`, with
policies. `is_studio` becomes `role === studio_admin`. Do this now — retrofitting roles
after a shop has three logins is a migration with opinions in it.

## 3. Impersonation audit

"Open panel" hands a studio user another decanter's customer phone numbers, bank-transfer
screenshots, expenses, and P&L. That's a privacy event and should leave a record.

- `studio_audit_events`: actor, shop, action, subject type/id, ip, user agent, timestamp.
- Log the panel entry itself, and every write performed while the session is impersonating.
- A persistent banner in the shop panel while impersonating — the operator should never
  be unsure whose data is on screen.
- **Read-only impersonation as the default**, with an explicit "take control" step that
  logs separately. Most support questions are answered by looking.
- Surface the log as a Studio page, filterable by shop. Owners seeing their own shop's
  entries is the right end state; not required in this step.

## 4. The registry table

From a review of the current screen:

| Change | Why |
|---|---|
| Status pill (Live / Onboarding / Suspended / Archived) replaces the `Active` check icon | The icon shows the same thing for both rows, can't carry a reason, and encodes state in color alone — which fails for colorblind users |
| Add **Owner** | The registry's most-asked question, currently unanswerable from this page |
| Add **Last activity** (last order) and **Orders this month** | Whether a shop is alive is the point of a registry; "Registered" is the least actionable column and currently the only signal besides the name |
| Make the row navigable; keep an icon action | "Open panel" repeated as text in every row is a lot of ink for the primary action |
| Overflow menu per row | Suspend, export, view audit log, open owner's settings |
| Filters: status (default "not archived"), needs-attention | The empty toolbar is fine at 2 shops and useless at 30 |
| Define the zero state | First run should say "Register your first shop", not show an empty table |

Also add a **shop detail page** — the row is not enough. Owner and contact, configuration
completeness from step 33's resolver (payment set? Telegram wired? social? domain?),
counts, storage used, recent audit entries.

## 5. Studio design tokens

**Constraint, do not violate:** the Heroku build path has no Node. That's why FullCalendar
is vendored and `saade/filament-fullcalendar` was rejected in v17. So **no Vite-compiled
Filament custom theme.** Register colors in PHP on the panel provider — hex ramps, no
build step.

- The current emerald is Filament's default primary, which reads as unbranded.
- `pine #013E37` is fixed by §3 but is too dark to serve as an accent on a near-black
  surface. Generate a ramp at pine's hue with raised lightness and use the 400/500 steps
  as the Studio primary. Same hue family, legible on dark.
- Read the ramp from `design-tokens.json` (v5 made it the portable copy of the palette)
  so storefront, admin, and Studio share one source rather than three.
- Metadata chips: §3's motif is a **hairline-bordered vial label**, not a filled badge.
  The slug chips are filled badges today. Either restyle them or write the admin/Studio
  exception into §3 — but don't leave it undecided.
- Check contrast against the dark surface at 4.5:1 for the muted labels ("Per page") and
  the green link text at 14px. Green-on-near-black at small sizes is the risk.
- If a dark Studio is a deliberate third register — control room vs. the mist storefront
  — say so in §3. Right now it reads as a Filament default rather than a choice.

## 6. Sidebar

One item doesn't need groups; four will. Group now: **Registry** (Shops), **Operations**
(Audit log, Support search), **Platform** (Stats). Support search — tracking code or
phone across all shops — is a natural next step and deliberately not in this one; it is
a cross-tenant read and needs its own audit entry per query.

## Non-goals

Billing, plans, quotas, per-shop feature flags, cross-shop support search, per-shop data
export. Each is a clean addition on what this step builds; none belongs in this diff.

## Tests

- Every lifecycle transition, including that a suspended shop's API and panel behave as
  decided, and that an archived shop disappears from the default registry filter.
- A shop-confined user cannot reach `/studio/*` at all.
- Opening a shop panel as studio writes exactly one audit event; a write while
  impersonating writes exactly one more.
- Read-only impersonation refuses writes.

## Docs, same branch

`backend/README.md`'s Studio routes table, `CLAUDE.md` §3 (Studio register) and §6
(Studio panel features).
