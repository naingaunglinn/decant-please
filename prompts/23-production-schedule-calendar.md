# Step 23 — Production schedule: calendar view

> **Numbering:** PR #58 is open and claims 23–25 for the multi-tenancy specs. If you merge
> that first, this becomes 26 (and the harness step moves to 27). Check `prompts/README.md`
> before committing.

Follow the current `CLAUDE.md`. **Backend only** — this is a Filament panel page.

## 0. Why this step exists

The production schedule is the decanter's daily worklist, and it currently renders as a
stacked list of day cards. It answers *"what do I decant today"* correctly and *"how busy
is next week"* not at all — so delivery dates get committed without seeing the load they
land on. It also reads as unfinished, which matters: this panel is the product being sold
to other Myanmar decant shops.

A calendar fixes the overview and would ruin the worklist if it replaced it. So this step
**adds** a view, it does not swap one out.

## 1. Approach — decide and report before writing code

Two candidates. Report which you're taking and why, before implementing.

**A. Embed FullCalendar (MIT) directly in the existing Blade page.** A JSON endpoint or
Livewire property feeds it; the dark styling is written by hand to match the panel.

**B. `saade/filament-fullcalendar`.** Supports Filament 5.x, MIT, well maintained.

The deciding factors, in order:

1. **B requires a Filament custom theme**, which is compiled by Vite. The backend app has
   no Node in its Heroku build path (monorepo buildpack + `heroku/php`; the frontend builds
   on Vercel). Either the compiled CSS gets committed, or `heroku/nodejs` is added ahead of
   `heroku/php` — and buildpack ordering on that app has already caused one production-only
   failure. Establish which, concretely, before choosing B.
2. **B's main feature is unusable here.** It's built around a model with `starts_at`/`ends_at`
   plus Filament Actions for create/edit/delete. This page aggregates order items by decant
   date; there is nothing to create or edit — dates are set when an order is accepted.
3. A is the same reasoning that produced `@media print` for invoices instead of a PDF
   dependency.

If B wins on real effort after accounting for (1), take it — but it is the project's first
third-party Filament plugin, so §7 applies.

## 2. Data — one query method, not two

Extract the existing per-day aggregation into a single method (`Order::productionScheduleFor(
$from, $to)` or equivalent in the domain layer, per the v5 rule). Both views call it. Do not
let the calendar grow its own copy of the grouping logic.

This is not tidiness. `ProductionSchedule` is a custom Filament page, so Filament's tenancy
scoping will never touch it (see `prompts/multi-tenancy-findings.md` Q5) — whenever Step A
lands, this query has to be scoped by hand. One method means one place.

Cancelled and rejected orders stay excluded, as today.

## 3. The two views *(amended after the first review round — this is as built)*

**Calendar (`/admin/production-schedule`).** Month grid, the page's only content — the
From/To inputs and the stacked day cards are gone. Each day carries **one aggregate
entry** — total vials for that day, e.g. `12 vials` — not one entry per fragrance/size. A
month cell holds about two chips before truncating, and a busy day has six or more line
items, so per-item entries would render as `+5 more` and show less than the worklist does.
A past day still holding unpoured vials (an order not yet decanted or delivered) renders
its entry in the overdue style — overdue is not history. Every day cell is clickable,
chips and empty days alike: both `dateClick` and `eventClick` navigate to that day's
detail page.

**Day detail (`/admin/production-schedule/{date}`).** The worklist for one day — the same
aggregated fragrance/size lines the day cards used to show, fed by the §2 method called
with `($date, $date)`, never a second query. The route param is a plain `Y-m-d` string,
strictly validated: any other shape (unpadded, no dashes, a datetime, an impossible date
like `2026-02-30`) 404s — no lenient parse, no timezone conversion (Yangon is UTC+6:30).
Prev/next day stepping, a link back to the calendar, a real empty state for quiet days,
and `@media print` A5 styles matching the invoice conventions — this page is what gets
printed and taken to the bench. `shouldRegisterNavigation()` is false: it requires a
date, so it must not appear in the sidebar.

## 4. Dates — all-day, plain strings, no timezones

Every calendar entry is an **all-day event with a plain `YYYY-MM-DD` string**. No datetimes,
no timezone-bearing values, no `toIso8601String()`.

Myanmar is UTC+6:30. A half-hour offset against a UTC app timezone shifts day boundaries and
puts decants in the wrong cell — a bug that reproduces only for real users in Yangon and
never in a UTC test. The current list sidesteps this by formatting server-side; the calendar
must not reintroduce it.

## 5. Not in scope

- Dragging a day to reassign decant dates. Dates change through order Accept, which runs
  stock checks — a calendar drag would bypass them.
- Any change to the list's content, the order lifecycle, or the Accept flow.
- FullCalendar premium plugins (`resourceTimeline`, `adaptive`). They need a paid licence key
  and the free `dayGrid` covers this.
- Multi-tenancy. §2 makes Step A cheaper; it does not do Step A's work.

## 6. Tests

- The aggregation method returns the same per-day totals the list renders today (pin the
  existing behaviour before refactoring it).
- A day with several fragrance/size combinations produces exactly **one** calendar entry, and
  its count equals the sum of vials. Assert the count, not merely that an entry exists —
  `assertSent`-shaped existence checks are how #52 shipped.
- Cancelled and rejected orders contribute nothing to either view.
- A decant date renders on the same calendar day under both `UTC` and `Asia/Yangon` app
  timezones.
- The calendar page renders with the calendar present — and nothing of the old list.
- The day page renders for a date with vials and for one without; an invalid date param
  404s; the day total equals that day's calendar aggregate; and both pages agree under
  both timezones above.

## 7. Docs

- `prompts/README.md` — row for this step.
- `CLAUDE.md` — changelog note. If approach B is taken, it is a deliberate exception to the
  "stack is fixed" rule and to this project's standing preference against new dependencies:
  record it in the style of the v13 amendment, naming what the plugin gives that hand-rolling
  would not.
- `backend/README.md` — the panel page list, and any new asset/build step in the deploy path.
- `DEPLOY.md` — **only if** approach B changes how assets reach the dyno. That is a deploy
  change and belongs in the runbook, not just a commit message.

## 8. Verification before opening the PR

- `php artisan test` exits 0.
- The list view was byte-for-byte equivalent through the round-1 refactor; the round-2
  amendment then moved that content onto the day page, where the retargeted pins hold.
- If B: a clean checkout with no local `node_modules` still produces a working panel through
  the actual Heroku build path — or the PR states plainly that it does not and what must
  change.
- Screenshot the month view with at least one busy day, so the truncation question is settled
  by looking rather than by argument.

Open the PR against `develop`. Stop before merging.
