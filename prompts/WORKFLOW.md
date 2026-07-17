# WORKFLOW.md — issue → branch → implement → docs → PR

The standard process for turning a numbered prompt file into a reviewable pull
request. Used from Step 8 onward, and every step after that — this file doesn't get
retired once 8/9/10 are done, it's the process going forward.

**Ground rule: one prompt file, one issue, one branch, one PR. Never merged by Claude
Code — every PR stops and waits for human review.**

Feature work lives on `develop` (branch off it, PR into it). `main` is production —
it changes only through the promotion PR described at the bottom of this file.

## Why order matters

Branch every step off `develop` **after** the previous step's PR is merged, not off
whatever `develop` happened to be when you started. Two concrete reasons, not just
process for its own sake:
- A language/i18n step needs to wrap every string that exists by the time it runs —
  if it branches before an earlier step's new UI strings are merged in, it misses
  them and needs a follow-up patch.
- Two steps that touch the same component (e.g. the checkout summary card) will
  conflict with each other if both branch from the same stale point instead of one
  building on the other's already-merged work.

If a batch of steps has a dependency like this, say so before starting, the same way
`08 → 10 → 09` was reordered from the original `08 → 09 → 10` request — 09 needs to
translate what 08 and 10 add, so it runs last.

## The loop — repeat once per prompt file

1. **Confirm `develop` is current.**
   ```
   git checkout develop && git pull
   ```

2. **Create the issue.** Title = a concise, professional description of the work —
   not the prompt file's internal "Step N" heading verbatim. E.g. "Rebuild order
   confirmation as a real, printable receipt", not "Step 8: Real order confirmation,
   printable receipts & catalog fundamentals" — the step/file reference belongs in
   the body's spec pointer, not the title. Body = a short summary (3-6 bullets of
   what's actually being built, not the whole file) plus a pointer to the source of
   truth:
   ```
   gh issue create --title "Rebuild order confirmation as a real, printable receipt" --body "$(cat <<'EOF'
   - Rich order-complete/tracking receipt (order number, customer, shipping, itemized payment)
   - Customer self-cancellation while awaiting_confirmation
   - Printable / save-as-PDF receipt, no new dependency
   - Related fragrances + recently-viewed rail + sitemap

   Full spec: prompts/08-order-confirmation-and-polish.md
   EOF
   )"
   ```
   Note the issue number it returns — you need it for the branch name next, and
   again in steps 4 and 7.

3. **Branch**, name starting with the issue number from step 2, off the `develop`
   you just pulled:
   ```
   git checkout -b 12-order-confirmation-and-polish
   ```

4. **Implement.** Read `CLAUDE.md` and the specific `prompts/0N-*.md` file, build it —
   same as every step before this one. Reference the issue number in commits if it's
   natural to (`git commit -m "feat: order receipt + cancellation (#12)"`), but don't
   force it into every single commit.

5. **Update the docs in the same branch, not a follow-up PR:**
   - `CLAUDE.md` — a short changelog note if this step changes something the project
     memory should reflect (same pattern as the existing "What changed in vN"
     sections). Bump the version marker in the title if it's a meaningful addition.
   - The prompt file itself — if implementation revealed the spec was wrong,
     ambiguous, or worth amending, fix it in place rather than leaving a stale
     instruction for whoever reads it next (same discipline as the `01`/`03`
     version-pin fix earlier in this project).
   - `README.md`'s step table — flip this step's status to **built**.

6. **Push the branch.**
   ```
   git push -u origin 12-order-confirmation-and-polish
   ```

7. **Open the PR for this issue and branch:**
   ```
   gh pr create --base develop --title "Rebuild order confirmation as a real, printable receipt" --body "Closes #12

   <short summary of what's in this PR, notable decisions, anything that needs a close look>"
   ```

8. **Stop.** Report the PR URL and a short summary. Do **not** run `gh pr merge`, do
   not enable auto-merge, do not merge via any other route. The PR waits for a human.

9. Once the human has reviewed and merged: go back to step 1 for the next prompt
   file. If they instead asked for changes, address them on the same branch and push
   again — the PR updates in place, no new issue or branch needed for a review round.

## Promoting `develop` to production (`main`)

Separate from the loop above, and not automatic after every merge into `develop`.
The decanter decides when `develop` is ready to ship, then:

```
gh pr create --base main --head develop --title "Promote develop to production" --body "<summary of what's shipping — the merged PRs since the last promotion>"
```

Same rule as every other PR in this project: **never merged by Claude Code.** If
anything this one matters more, since it's what actually reaches production
traffic once Heroku's auto-deploy picks it up.

## First run of this loop

```
Follow prompts/WORKFLOW.md for prompts/08-order-confirmation-and-polish.md.
```

Then, once that PR is merged:

```
Follow prompts/WORKFLOW.md for prompts/10-promo-codes.md.
```
