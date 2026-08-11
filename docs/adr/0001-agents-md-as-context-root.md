# ADR-0001: AGENTS.md as the tool-neutral context root

**Status:** Proposed
**Date:** 2026-08-11
**Deciders:** Naing Aung Linn (repo owner)

## Context

The repo's always-on agent context is a single root `CLAUDE.md`: 893 lines, ~8,600 words.
Of those, **594 lines (67%) are changelog** — nineteen "What changed in vN" sections from
v2 through v22. The durable material (what the project is, stack, design language, domain
model, feature list, conventions, out-of-scope) starts at line 600.

Three consequences follow:

1. **Every agent turn pays for the changelog.** It is loaded into context on every task,
   whether or not history is relevant, competing for attention with the rules that matter.
   Superseded statements ("changed in v8", later revised in v19) sit alongside current
   ones with no marker for which is live.
2. **The file is Claude-specific.** `frontend/` has already diverged: it uses
   `AGENTS.md` as the real file with `CLAUDE.md` as a one-line `@AGENTS.md` import.
   The root does the opposite. Codex, Cursor, and future tools read `AGENTS.md`.
3. **`backend/` has no scoped context at all** — despite being the larger half, holding
   all 20 test files, the Filament conventions, and the Postgres portability trap that
   has already shipped as a production bug once.

The forces: this project is genuinely well documented and that discipline should be kept,
not thrown away. The problem is placement, not volume. The team is one person, so any
process that requires maintaining two copies of anything will rot.

## Decision

Make `AGENTS.md` the canonical always-on context root, hierarchically, and reduce
`CLAUDE.md` to a one-line import — matching what `frontend/` already does.

```
AGENTS.md            durable rules only, ~140 lines      (new)
CLAUDE.md            @AGENTS.md                          (was 893 lines)
CHANGELOG.md         the v2–v22 history, verbatim        (new, moved not deleted)
PRODUCT.md           WHO/WHY/WHAT/RULES/NON-GOALS/DONE   (new, extracted)
VERIFY.md            what counts as proof                (new)
backend/AGENTS.md    Laravel/Filament/test conventions   (new)
frontend/AGENTS.md   extended below the vendored block   (extend)
```

Nothing is deleted. The changelog moves to a file that is read on demand rather than on
every turn.

## Options Considered

### Option A: Keep `CLAUDE.md` canonical, add an `AGENTS.md` symlink

| Dimension | Assessment |
|---|---|
| Complexity | Low |
| Cost | Near zero |
| Scalability | Poor — the size problem is untouched |
| Team familiarity | High — no change to habits |

**Pros:** One file, no migration, other tools can read it via the symlink.
**Cons:** Does not fix the 67% changelog problem, which is the actual cost. Symlinks are
fragile on Windows checkouts and some CI runners. Contradicts `frontend/`'s existing
inverted convention, so the repo stays internally inconsistent.

### Option B: `AGENTS.md` canonical, `CLAUDE.md` a one-line import *(chosen)*

| Dimension | Assessment |
|---|---|
| Complexity | Low — a one-time split, then normal editing |
| Cost | One afternoon of extraction |
| Scalability | Good — nested `AGENTS.md` files scope naturally as the repo grows |
| Team familiarity | High — `frontend/` already works this way |

**Pros:** Tool-neutral. Matches the existing frontend convention, so the repo becomes
consistent rather than more divided. Cuts always-on context by roughly 85% without losing
a word of history. Directory-scoped files mean a backend task never loads UI token rules.
**Cons:** More files to keep current. A stale pointer in `AGENTS.md` to a file that moved
is a new failure mode. Requires the discipline of writing changelog entries to
`CHANGELOG.md` instead of the top of the context file — a habit change.

### Option C: Generate `CLAUDE.md` and `AGENTS.md` from shared fragments at commit time

| Dimension | Assessment |
|---|---|
| Complexity | High — a build step, a hook, a new failure mode |
| Cost | Ongoing maintenance of tooling that produces documentation |
| Scalability | Good in principle |
| Team familiarity | Low — nothing else in this repo is generated |

**Pros:** No duplication by construction; each tool gets a tailored file.
**Cons:** Wildly disproportionate for a one-person project. A pre-commit hook that
rewrites documentation is a thing to debug on a Friday night. Option B gets ~95% of the
benefit for none of the machinery.

## Trade-off Analysis

The core trade-off is **fewer files vs. less loaded context**. Option A optimises for
file count; Option B optimises for what actually reaches the model on each turn. For an
agentic workflow the second matters far more — attention is the scarce resource, and a
model reading nineteen superseded changelog entries before it reaches "money is integer
Kyat" is measurably worse at respecting the rule that matters.

The secondary trade-off is **one big file vs. several scoped ones**. Several scoped files
risk drift. That risk is real but bounded here, because the split is along a natural
seam: `PRODUCT.md` changes when the product changes, `AGENTS.md` when the process
changes, `backend/`/`frontend/` files when a stack convention changes. These rarely move
together, which is precisely why bundling them was costly.

Option C is rejected on proportionality, not principle.

## Consequences

**Easier**
- A backend task loads Laravel conventions and never sees Tailwind tokens.
- Codex, Cursor, or any future tool works without a second copy of the rules.
- "Where does this go?" has an answer, so documentation updates stop being a judgement call.
- The `DONE` criteria become quotable in a prompt because they live in one short section.

**Harder**
- Five files to keep current instead of one.
- A cross-cutting change (e.g. a new order status) touches `PRODUCT.md`, `backend/AGENTS.md`,
  and possibly `frontend/AGENTS.md` — where it previously touched one file in three places.

**To revisit**
- If `backend/AGENTS.md` passes ~200 lines, split Filament conventions into their own file.
- If a third client surface appears, reconsider whether `PRODUCT.md` should be per-surface.
- Once MCP or subagents are added (see `docs/agentic-environment.md`), revisit whether
  boundaries belong in prose or in tool-level permission config.

## Action Items

1. [ ] Create `CHANGELOG.md` from `CLAUDE.md` lines 6–599, newest first, unchanged text
2. [ ] Create root `AGENTS.md` from the durable §1–§8 material
3. [ ] Replace `CLAUDE.md` with `@AGENTS.md`
4. [ ] Extract `PRODUCT.md` from §1, §5, §6, §8 + the domain model
5. [ ] Write `backend/AGENTS.md`; extend `frontend/AGENTS.md` **below** the
       `<!-- END:nextjs-agent-rules -->` marker (the block above it is regenerated on upgrade)
6. [ ] Add `typecheck`, `test`, `verify` scripts to `frontend/package.json`
7. [ ] Add step 4a "plan before implementing" to `prompts/WORKFLOW.md`
8. [ ] Amend WORKFLOW step 5 to write changelog entries to `CHANGELOG.md`, not `CLAUDE.md`
9. [ ] Run one existing prompt file end-to-end through the new environment and compare
