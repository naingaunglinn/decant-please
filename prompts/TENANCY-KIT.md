# Multi-tenancy follow-up kit — how to run it

Six working files plus this one. They are inputs for Claude Code sessions, not code.
Every prompt to paste is in this document.

## 1. Put the files in the repo — DONE (2026-08-17, issue #84)

Placed and renumbered (31 was taken by the shadcn step, 24–26 reserved, 27 taken;
ADR 0001–0003 exist):

| Drafted as | Lives at |
|---|---|
| `31-tenant-isolation-hardening.md` | `prompts/32-tenant-isolation-hardening.md` |
| `32-per-shop-configuration.md` | `prompts/33-per-shop-configuration.md` |
| `33-studio-operability.md` | `prompts/34-studio-operability.md` |
| `0001-storefront-addressing.md` | `docs/adr/0004-storefront-addressing.md` |
| `SKILL.md` | `.claude/skills/decant-tenancy/SKILL.md` |
| `CLAUDE-md-v21-section.md` | repo root (temporary — delete once merged) |
| `00-START-HERE.md` | `prompts/TENANCY-KIT.md` (this file) |

Internal cross-references were renumbered to match; nothing else was edited.

**Placement notes — read before running any session below.** This kit was written from
the READMEs, before the v24/v25 work merged, so calibrate:

- Much of step 32's audit table is already closed on `develop`: the throwing
  `BelongsToShop` global scope (the Phase-2 idiom decision effectively made, and
  stricter than the kit's option A — it throws rather than failing empty),
  `TenantIsolationTest` (25 cases), per-shop `ShopSetting`, per-shop cache keys and
  busting, `(shop_id, slug)`/`(shop_id, code)` composites, `decant:fresh-start --shop`,
  tenant-scoped invoice/proof routes, context-pinned seeders. Rule §3 below applies:
  expect many "already scoped, here's the line" rows. Genuinely open rows include
  shop+IP rate-limiter keys, `{shop}/` storage prefixes (deferred as 25c), and the
  cache-replay test case.
- Step 33's Telegram/social/config-resolver work is real and open (per-tenant Telegram
  is also specced as 25b / design-doc ADR-003 — reconcile, don't duplicate).
- `docs/adr/0004-storefront-addressing.md` conflicts with the *accepted* ADR-004 in
  `prompts/multi-tenancy-design.md` (N Vercel projects, one domain each). The ADR's
  header carries the pointer; session 5 must argue the two against each other.
- `CLAUDE.md` is now a one-line pointer at `AGENTS.md`, and version notes live in
  `CHANGELOG.md` (currently v25) — session 4's "merge into CLAUDE.md as v21" must be
  retargeted: rules → `AGENTS.md`, the version note → `CHANGELOG.md` as the next v,
  design-language/domain sections → wherever §3/§4 landed after the ADR-0001 split.

## 2. Session prompts, in order

One prompt file per session. `/clear` between them — a session that has been reading
`app/Filament` for an hour gives worse audits than a fresh one.

> **Run state (2026-08-18):** sessions 1–4 are done — 1 and 2 chat-only
> (audit/decision), 3 and 4 as one branch (PR #87, v26; session 4 rode along and was
> retargeted exactly as the §1 placement note prescribes — much of its README/feature
> list was already closed by the v25 doc pass). `CLAUDE-md-v21-section.md` is merged
> and deleted. Next: session 5 (ADR-0004 stays Proposed until then).

### Session 1 — audit (read-only, no branch, no issue)

```
Read CLAUDE.md fully, then prompts/WORKFLOW.md, then
prompts/32-tenant-isolation-hardening.md.

Do Phase 1 only. Do not edit any file. Do not create a branch or an issue.

Fill in the audit table from the prompt: one row per surface, with file:line, what
scoping it has today, and a verdict of scoped / unscoped / accidental. Where a row is
already correct, say so and quote the line that makes it correct — I want the list of
what's fine as much as the list of what isn't.

End with the rows that need fixing, ordered by blast radius. Then stop.
```

### Session 2 — decide the scoping idiom

```
Here is your audit: [paste it, or point at the file if you saved it].

Do Phase 2 of prompts/32-tenant-isolation-hardening.md. Recommend global scope vs
explicit clause for this codebase specifically — not in general. Name every Studio query
that would need to bypass a global scope, since that's the cost side of the argument.

No code. Give me the decision and the reasoning written in CLAUDE.md's register, ready
to paste into §7. Then stop.
```

### Session 3 — implement 32

```
Read CLAUDE.md, prompts/WORKFLOW.md, prompts/32-tenant-isolation-hardening.md, and
.claude/skills/decant-tenancy/SKILL.md.

We chose [the idiom from session 2]. Open the issue and branch per WORKFLOW.md, then
implement Phase 3 and Phase 4.

Fix only the rows the audit marked unscoped or accidental. No refactors, no renames, no
new features riding along.

Write tests/Feature/TenantIsolationTest.php with every case in Phase 4. Case 10 (the
cache case) only works if the cache is not cleared between the two requests — write it
deliberately and comment why.

Run: docker compose exec backend php artisan test

Then docs in the same branch: backend/README.md, and merge the §7 block from
CLAUDE-md-v21-section.md into CLAUDE.md. Open the PR and stop. Do not merge.
```

### Session 4 — the v21 docs

Can ride along with session 3's PR if the diff is small; give it its own if not.

```
Merge CLAUDE-md-v21-section.md into CLAUDE.md: promote the current §0 to §0.1 in the
existing pattern, add the new §0, the §3 / §4 / §6 / §7 additions, and the §8 amendment.

Fill the bracketed slots from what actually shipped — don't leave a bracket in the file.
Fill the same slots in .claude/skills/decant-tenancy/SKILL.md.

Then update the root README: its API table has no {shop}, its feature list has no Studio,
and its out-of-scope list still says multi-decanter marketplace.

Delete CLAUDE-md-v21-section.md from the repo root when done.
```

### Session 5 — pressure-test the ADR before implementing it

```
Read docs/adr/0004-storefront-addressing.md.

Argue against the decision. What specifically breaks in this repo if we go subdomain
first — name the files. What does option A actually cost us at 20 shops, in concrete
terms rather than brand vibes. Is there a fourth option we didn't consider?

Don't edit the file. I'll decide, then you set the Status line.
```

### Session 6 — implement 33

```
Read CLAUDE.md, prompts/WORKFLOW.md, prompts/33-per-shop-configuration.md,
.claude/skills/decant-tenancy/SKILL.md.

Before anything else, check two things and report: is ShopSetting still a single row, and
do the Telegram listeners read the token from config rather than from the order's shop?

Then issue → branch → implement. Tests before docs. Stop at the PR.

ADR-0004 is [decided as option B / still Proposed] — [implement the CORS change / add the
column the ADR calls for and leave the resolver reading one origin].
```

### Session 7 — split 34 before implementing it

34 is three PRs, not one. Don't let it land as one diff.

```
Read prompts/34-studio-operability.md and split it into three sequenced issues:
(1) lifecycle + roles, (2) impersonation audit, (3) registry table + Studio tokens.

For each: title, body, the acceptance criteria from the prompt that belong to it, and
what it depends on. Don't open them yet — show me the three first.
```

## 3. The one rule for every session

**These prompts were written from your READMEs, not from your code.** Nothing in them is
a confirmed bug. Every one of them opens with an audit for that reason. If Claude Code
comes back with "step 25a already scoped the widgets, here's the line," that's the step
working — accept the smaller diff and move on.

## 4. Verify, every time

```bash
docker compose exec backend php artisan test
sh backend/scripts/verify-postgres-portability.sh   # anything touching LIKE / ORDER BY
docker compose exec frontend npm run build
```

Tenancy scoping is Eloquent `where` clauses, which SQLite and Postgres agree on. The
blind spot opens the moment a scoped query also does a case-insensitive search — the v6
rule still applies.
