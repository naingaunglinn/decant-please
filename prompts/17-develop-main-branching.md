# Step 17 — `develop` / `main` branch split

Follow the current `CLAUDE.md` (v7). Repo-wide: git/GitHub configuration plus a
`WORKFLOW.md` rewrite. No application code changes.

## 0. The model

- **`develop`** — where every feature branch merges. This is the new base for the
  per-step loop in `WORKFLOW.md`: branch off it, PR into it.
- **`main`** — production. Only ever updated by a `develop` → `main` pull request.
  Heroku's auto-deploy (`prompts/16-cicd-heroku-github.md`) watches `main` only,
  same as it always would have — the only thing that changes is what's allowed to
  land there directly (nothing, except that one promotion PR).
- **Promotion is manual and deliberate, not part of the per-step loop.** Merging a
  feature into `develop` does not imply it should go to production immediately —
  the decanter decides when `develop` is ready and either opens the `develop` →
  `main` PR themselves or asks for it to be opened, but reviews and merges it by
  hand either way. Same "never auto-merge" rule as everything else in this project,
  arguably more important here since this is the PR that actually reaches
  production traffic.

This is a clean point to do this at — nothing is currently mid-flight on `main`
that would need redirecting (Step 14 merged, nothing else has an open PR yet).

## 1. Create the branch and flip the default

```
git checkout main && git pull
git checkout -b develop
git push -u origin develop
gh repo edit naingaunglinn/decant-please --default-branch develop
```

The last line matters beyond convenience: `gh pr create` without an explicit
`--base` targets the repo's default branch. `WORKFLOW.md`'s own examples always
pass `--base` explicitly (see below), so this isn't strictly load-bearing for this
project's own scripted flow — but it's the correct repo-level setting for a
`develop`-first project regardless, and it's what protects anyone (or anything)
that opens a PR without thinking about the flag.

Confirm this actually is a real `gh repo edit` flag against the installed CLI
version before relying on it (`gh repo edit --help`) — it's documented as of
writing, but this is exactly the kind of CLI-surface detail worth a fast check
rather than blind trust.

## 2. Update the existing CI workflow

`.github/workflows/tests.yml` already exists and is live — Step 16 merged it before
`develop` existed, so it currently triggers on `push`/`pull_request` against `main`
only. Add `develop` to both trigger lists:

```yaml
on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]
```

Read the real current `on:` block before editing rather than assuming it matches
this exactly — apply this as a change to whatever's actually there, in case
anything else about it shifted during Step 16's implementation. **(Amended during
implementation:)** it had shifted — Step 16 shipped `pull_request` with **no**
branches filter (covers PRs into any base, `develop` and the promotion PR
included), so the only change needed was `push: branches: [main]` →
`[main, develop]`; the bare `pull_request` was kept, with a comment saying why.
This doesn't touch what Heroku watches (§0 already covers that — `main` only,
unchanged); it only means `develop` now also gets tested on every push, which is
the actual point of having an integration branch in the first place.

## 3. Rewrite `WORKFLOW.md`

Three surgical edits to the existing loop, plus one new section. Apply these as
edits to the current file, not a wholesale rewrite — everything else in it
(the ground rule, the numbered steps' substance, "why order matters," the
docs-update checklist) is unchanged.

**Step 1 of the loop** — from:
````
1. **Confirm `main` is current.**
   ```
   git checkout main && git pull
   ```
````
to:
````
1. **Confirm `develop` is current.**
   ```
   git checkout develop && git pull
   ```
````

**Step 3 of the loop** — "off the `main` you just pulled" becomes "off the
`develop` you just pulled." The example branch name/command doesn't otherwise
change.

**Step 7 of the loop** — `gh pr create --base main --title ...` becomes
`gh pr create --base develop --title ...`. Everything else about that step (the
`Closes #N` body convention, etc.) is unchanged.

**"Why order matters" section** — this currently talks about branching off the
latest merged `main`; swap those `main` references for `develop`. The underlying
reasoning (branch off what the previous step actually landed, not a stale point)
is unchanged and still applies — it's just `develop` that has to be current now,
not `main`.

**New section, after the loop (before or after "First run of this loop" — your
call, whichever reads better in place):**

````md
## Promoting `develop` to production (`main`)

Separate from the loop above, and not automatic after every merge into `develop`.
The decanter decides when `develop` is ready to ship, then:

```
gh pr create --base main --head develop --title "Promote develop to production" --body "<summary of what's shipping — the merged PRs since the last promotion>"
```

Same rule as every other PR in this project: **never merged by Claude Code.** If
anything this one matters more, since it's what actually reaches production
traffic once Heroku's auto-deploy picks it up.
````

(That's a 4-backtick fence wrapping a 3-backtick one, standard Markdown for nesting
code blocks — not a typo. Copy the inner content between the `md` fence and the
closing 4-backtick line into `WORKFLOW.md` as-is, including its own 3-backtick
block.)

## 4. Optional — branch protection on `main`

Not required for the model above to work (nothing but the promotion PR path
touches `main` if everyone follows `WORKFLOW.md`), but it turns "everyone follows
the convention" into "the convention is enforced" — a direct `git push origin main`
from a local checkout would otherwise still work and quietly bypass everything
here. If wanted:

```
gh api repos/naingaunglinn/decant-please/branches/main/protection \
  --method PUT \
  -f required_status_checks[strict]=true \
  -f required_status_checks[contexts][]=test \
  -f required_status_checks[contexts][]=postgres-portability \
  -f enforce_admins=true \
  -f required_pull_request_reviews=null \
  -f restrictions=null
```

Uses the check names from Step 16 (`test`, `postgres-portability`) — those already
exist and are live, so no ordering concern here, unlike the CI-workflow update in
§2 which does need to land first for `develop`'s own pushes to report anything.
Flagging as optional rather than building it by default: it's a real hardening
step, not something the stated ask requires.

## Verify

- Push a no-op commit to `develop` and confirm `.github/workflows/tests.yml` (with
  §2's edit applied) actually runs against it — check the Actions tab, don't just
  trust the YAML looks right.
- `git branch -a` shows `develop` pushed to `origin`; the GitHub repo's Settings →
  Branches shows `develop` as the default.
- Open a throwaway PR against `develop` and confirm `gh pr create` (no `--base`
  flag) targets `develop` on its own.
- Read back the edited `WORKFLOW.md` loop end-to-end as if running it fresh — every
  `main` reference in the per-step loop should now read `develop`; the only
  remaining `main` references in the whole file should be in the new promotion
  section and anywhere describing what `main` *is* (production), not where feature
  work happens.
- Confirm the "First run of this loop" example at the bottom of the file wasn't
  silently rewritten — that's a historical record of what already happened under
  the old model, not a live template to update.

Report results with a checklist, same as every prior step.
