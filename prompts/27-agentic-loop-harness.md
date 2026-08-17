# Step 27 — Agentic loop harness

> Numbered into the slot `prompts/README.md` reserved for this draft (27; it was
> drafted as 22, which the online-payment step holds). Invoke it the usual way:
> `Follow prompts/WORKFLOW.md for prompts/27-agentic-loop-harness.md`

## Why this step exists

This repo already has a harness. `CLAUDE.md` is the instruction file, `prompts/NN-*.md`
plus `prompts/README.md`'s status table is the feature list, `WORKFLOW.md` is the
scope-and-handoff constraint, `DEPLOY.md` is the runbook, and `CLAUDE.md`'s gotchas
section is an accumulated failure catalogue. What's missing is the layer above: work
still starts only when a human types a prompt.

This step adds that layer — scheduled checks, goal-bounded runs, and an independent
review pass — **without creating a second source of truth** and without letting any
automated process near production.

## Non-negotiable boundaries

Read these before writing anything. They constrain every deliverable below.

1. **No loop touches `main`.** Merging to `main` triggers the Heroku release phase and
   `migrate --force` against the decanter's order history. Once real paid-status or
   stock figures exist, the `down()` methods on the recent migrations are data loss,
   not an undo. The `develop` → `main` promotion PR stays a human click, forever.
2. **Never self-merge.** The existing `WORKFLOW.md` rule applies to every loop, with no
   exception for "the checks were green."
3. **`CLAUDE.md` stays the single source of truth.** Do **not** create
   `feature_list.json` or `claude-progress.md`. If `AGENTS.md` is wanted for cross-tool
   compatibility, it is at most five lines pointing at `CLAUDE.md` — never a copy. This
   repo has already shipped a bug where two files disagreed about the tech stack
   (stale Laravel 11 / Filament v3 pins in `01` and `03`); the rule that resolved it —
   `CLAUDE.md` wins — must not be undermined by adding another instruction file.
4. **Deterministic checks go in scripts, not agents.** If a shell script can assert it,
   a script asserts it. Agents triage failures; they do not perform the checking. A
   stopping condition must be machine-checkable, never a model's opinion that things
   look fine.
5. **Do not touch the nightly `pg_dump` cron.** `/loop` expires after 7 days and fires
   with jitter (up to 30 minutes past schedule). Backups stay on real cron.
6. **No new dependencies**, backend or frontend.

## Deliverables

### 1. `prompts/LOOPS.md` — the loop catalogue

A short document, in the same voice as `WORKFLOW.md`. For each loop, state: trigger,
goal, how it is verified, stopping condition, blast radius, and what it may not touch.
Three loops, no more:

- **Goal-bounded implementation run** (manual trigger, `develop` only). The pattern for
  working a single `WORKFLOW.md` item to completion without per-turn prompting. Document
  the condition template, including a turn bound and the requirement that the proof be
  command output present in the transcript — the `/goal` evaluator reads the
  conversation, it does not run commands or read files, so a condition it can only take
  on trust is not a condition. One item per goal; a goal that batches six items fights
  the one-issue-one-branch-one-PR rule.
- **Scheduled production check** (cron, read-only). Deliverable 3 + 4 below. Its output
  is pass/fail; an agent is involved only when it fails.
- **Promotion review** (manual, before any `develop` → `main` PR). Deliverable 2 below.

State explicitly that an auto-merge or auto-deploy loop is out of scope and why
(boundary 1).

### 2. `.claude/skills/promotion-review/SKILL.md` — the independent reviewer

A skill for a review pass that runs **separately from the session that wrote the code**,
because the author of a change is the worst judge of it. Its output is findings only —
the reviewer may not edit code.

Its core is this project's failure catalogue, drawn from `CLAUDE.md`'s gotchas section
and `DEPLOY.md`. Each class needs the concrete instance so the pattern is recognisable,
not abstract:

- **Silent config fallback.** `DB_URL` vs `DATABASE_URL` failed silently. A missing
  `DB_CONNECTION` fell back to sqlite silently. `PROOFS_DISK` falls back to `local`
  silently. Rule: flag any `env()` default that is correct in dev and wrong in
  production, and require a boot-time assertion rather than a checklist line.
- **SQLite-green, Postgres-broken.** Case-sensitive `LIKE`; `ORDER BY` alias
  resolution; `varchar(255)` limits that SQLite ignores entirely. Rule: any new query or
  column-length assumption needs a check against the real engine, not the suite.
- **Public-bucket leakage.** An R2 custom domain makes a bucket public bucket-wide;
  prefixes provide no isolation. Rule: any new upload path must name its bucket and
  state public or private explicitly.
- **Model events bypassed.** Bulk `update()` / `delete()` fire no model events, so
  cleanup hooks silently don't run. Rule: any hook that deletes a stored file needs a
  bulk-path counterpart.
- **Overridden vendor defaults.** Behaviour that depends on overriding a Filament or
  Livewire default (e.g. forcing a preview away from a presigned URL) can revert on a
  point release without breaking anything visibly. Rule: every such override needs a
  regression test asserting the override, not the feature.
- **Rollback stops being free.** Once the decanter enters one real value into a
  newly-added column, "revert the merge" is data loss. Flag any migration whose
  `down()` drops a column that will hold operator-entered data.
- **Cross-tenant leakage.** A missing `shop_id` filter does not throw — it returns
  another shop's customers. The suite runs one shop on SQLite and stays green. Rule:
  any new query, widget, export, or route touching tenant-owned data needs a two-shop
  test asserting exact counts, and any use of `withoutTenancy()` needs an inline
  comment justifying it.

Also require the reviewer to check that CI actually ran on the exact head commit, and to
distinguish "the suite is green" from "this was exercised against Postgres" — the
`postgres-portability` job exists precisely because those are different claims.

### 3. `backend/scripts/verify-production.sh` — deterministic post-deploy check

Match the style, output, and exit conventions of the existing
`verify-postgres-portability.sh`. Every check must work **with no secrets and no
credentials**, so it can run anywhere:

- `GET https://api.cornerarea.me/up` returns 200.
- `GET /api/v1/meta` returns 200 and parses as JSON.
- `GET /api/v1/fragrances` returns 200; take the first image URL, assert its host is
  `images.cornerarea.me` and that it returns 200. This exercises the live R2 read path.
- An unauthenticated `GET` on the admin payment-proof route (any order id — panel auth
  middleware runs before the controller, so a nonexistent id is fine) redirects to the
  Filament login and returns no image bytes.
- Assert no public hostname is configured for the proofs bucket at all: the proofs disk
  must have no `url` key, and no proof path may be reachable on
  `images.cornerarea.me`.

Exit non-zero on any failure and print which check failed and what it got. Silence on
success.

### 4. `.github/workflows/production-check.yml`

Runs deliverable 3 on a daily schedule plus `workflow_dispatch`. It must fail loudly —
a red run, not a warning annotation. Do not add it to the `tests.yml` gate; a production
outage should not block an unrelated PR from merging.

### 5. Documentation, in the same branch

- `CLAUDE.md` — changelog note.
- `prompts/README.md` — status row for this step, and rows for any earlier steps still
  missing from the table.
- `WORKFLOW.md` — a short "Loops" section stating boundaries 1 and 2, so the rule lives
  where the workflow lives and not only in `LOOPS.md`.

## Explicitly out of scope

- Any change to the `tests.yml` gate, Heroku auto-deploy settings, or branch protection.
- Any auto-merge or auto-promote mechanism.
- The outstanding hardening items — CSV import length validation, the Filament
  preview-URL regression test, the proofs-disk boot assertion, `throw => true` on the
  proofs disk, extending `verify-postgres-portability.sh` to the new endpoints. Each is
  its own issue through the normal loop. Do not fold them in here.

## Verification before opening the PR

- `sh backend/scripts/verify-production.sh` passes when run manually against live
  production, and fails in an obvious way when given a deliberately wrong URL.
- The workflow file parses, and its schedule matches what `LOOPS.md` claims.
- `php artisan test` still exits 0 — this step changes no application behaviour.
- No `feature_list.json`, no `claude-progress.md`, and `AGENTS.md` (if present) is a
  pointer of five lines or fewer.
- Docs updated in this branch, not a follow-up.

This step needs no dashboard steps and no new secrets. If you conclude otherwise, stop
and say so before creating anything.

Open the PR against `develop`. Stop before merging.
