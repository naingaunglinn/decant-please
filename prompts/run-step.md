# Run one queue item (unattended)

You are running unattended, and nobody will answer questions. Do one item from
`prompts/RUN-QUEUE.md`, open its PR, and stop. `scripts/run-queue.sh` starts a fresh session
for the next item.

## Read first

- `AGENTS.md` (Principles and rules)
- `prompts/43-cornerarea-roadmap.md`, if it exists yet (item 1 creates it)
- `prompts/35-generic-shop-plan.md`
- `prompts/WORKFLOW.md`
- `prompts/RUN-QUEUE.md`

## Overrides for this run (the owner approved these)

- **AGENTS.md §6 "don't implement until the plan is read":** don't wait. Put the plan at the
  top of the PR description instead.
- **WORKFLOW.md "branch off develop after the previous PR merges":** branches stack. Branch
  each item off the previous item's branch, and target the PR's base at that branch. The
  stack's base is `110-drop-is-studio-flag` (PR #113, unmerged): **row 0** branches off it
  and targets it; every later row stacks on the row before it. Nothing branches off `develop`.
- **The go-live hold is build-now / merge-after (the owner's current rule):** open stacked
  PRs now for every `todo` row, **Step 35 onward — do NOT stop the run at Step 35.** Only
  merging waits for the go-live, and merges go #113 → #112 → the queue bottom-up (the owner
  merges, never this run). Rows marked `after-go-live` still wait to be built (skip them, per
  step 4).
- **Decisions the specs don't cover:** pick the option that best fits the Principles (P2
  reliability first, then P3/P4 simplicity). Record it in the PR under "Decisions I made —
  check these", and continue.

## Never do these

Rather than bend these rules, stop the run (see "When to stop the whole run").

- Merge a PR. Push to `main` or `develop`. Force-push. Rebase a branch that was already pushed.
- Touch production: Heroku, Vercel, DNS, production databases, real payment accounts,
  secrets.
- Edit a shipped migration. Rewrite a recorded money value. Weaken a tenancy rule.

## Each run

1. **Orient.** Run `git status`. You should be on the stack's top branch: the branch of the
   last `pr-open` item, or `110-drop-is-studio-flag` (PR #113) before row 0. If the working
   tree has uncommitted changes, or an item is `in-progress`, finish that item instead of
   starting a new one. Before row 0, the runner files are untracked on purpose. That isn't
   unfinished work; row 0 commits them — as its own first commit, before any #112 work,
   noting them in its CHANGELOG entry and naming that commit in its PR description so the
   owner can skip it when reviewing the FK change.

2. **Owner feedback comes first.** Run `gh pr list --state open --label fix`. Take each
   labelled PR in stack order, bottom first:
   - Check out its branch.
   - Address every review comment on it.
   - Run the checks and push.
   - Reply on the PR saying what changed, then remove the `fix` label.

   Then carry the fix up the stack. For each branch above it, in order:
   - `git merge` the branch below. Never rebase or force-push.
   - Resolve conflicts, run the checks, and push.

   Add a line to the queue's log. Feedback counts as this run's work, so stop after it with
   `RUN-STATE: CONTINUE`.

3. **Merged PRs.** When the lowest open stack PR targets `develop` and `develop` has commits
   its branch lacks (a squash-merged stack PR, a go-live hotfix, the DEPLOY.md docs PR),
   merge `develop` into that branch, then carry it up the stack by merge. Never rebase. Never
   touch `110-drop-is-studio-flag`; while #113 is open, the owner updates it themselves.

4. **Pick the item.** Take the first `todo` row, skipping `needs-owner` and `after-go-live`.
   If there is none, print `RUN-STATE: DONE` and stop.

5. **Mark it** `in-progress` in `RUN-QUEUE.md`.

6. **Spec.** If the item's spec is a roadmap section rather than a step file, write the step
   file first and commit it in this branch. Name it `prompts/NN-short-name.md` and give it
   the same shape as the steps in `35-generic-shop-plan.md`: goal, schema, API,
   admin/storefront, tests, risks, deliberately not built.

7. **Issue → branch → implement**, per WORKFLOW.md (the branch name starts with the issue
   number).
   - Keep the PR reviewable: aim for under ~800 changed lines, not counting tests,
     lockfiles and generated files.
   - If the item is bigger, split it. Add the remainder to the queue as a new `todo` row
     right after this one, and do the first part now.

8. **Definition of done** (AGENTS.md §7). Run each check and paste its real output:
   - `composer test`, including `TenantIsolationTest`
   - `npm run typecheck`, `npm run lint` and `npm run build` for frontend changes
   - `verify-postgres-portability.sh` for query changes
   - the step-35 parity test, once it exists
   - every new migration up → down → up on a real Postgres (`docker compose up -d postgres`)
   - `backend/docs/schema.dbml` regenerated from the migrated database whenever a migration
     is added

   Every PR must be green on its own, so the owner can merge any bottom part of the stack.

9. **Independent review.** Spawn the `pr-reviewer` subagent on `git diff <base>...HEAD`. Fix
   every blocking finding, re-run the checks, and list the non-blocking findings in the PR.

10. **Open the PR** with `gh pr create --base <previous item's branch>`. The description
    contains:
    - **Stacked on:** #N (or `#113`)
    - **Review guide:** risk high / medium / low, and the 3–5 places to look first. Money,
      tenancy, migrations and auth are always high risk.
    - **Plan**, **What changed**, **Decisions I made — check these**, **Deliberately not
      built**, and the **P6 answers**
    - **Checks run:** the commands and their results, plus the reviewer's findings (fixed
      or left)
    - **Owner steps:** anything only the owner can do (env vars, DNS, dashboards), or "none"

11. **Update the queue.** In `RUN-QUEUE.md`, set the item to `pr-open` with its branch and PR
    number, and add a one-line log entry. Commit and push that on the same branch. Write the
    branch name to `.run-top-branch` in the repo root (the file is git-ignored locally).

12. **Finish.** Print a 3-line summary. The very last line must be `RUN-STATE: CONTINUE`.

## When to stop the whole run

Stop in any of these cases:
- The item would need anything under "Never do these".
- A spec contradicts a Principle or a decision already recorded in the roadmap.
- Two fix rounds haven't made a failing check pass (AGENTS.md §9).
- A required check can't run at all (for example, there is no Postgres).

When you stop:
1. Mark the item `blocked` in the queue, with the reason.
2. If any of the work is useful, push it as a draft PR.
3. Print `RUN-STATE: BLOCKED` as the very last line.
