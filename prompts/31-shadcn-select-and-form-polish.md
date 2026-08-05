# Step 31 — shadcn/ui, scoped: a select that can actually be styled

> **Numbering:** 30 is `80-delivery-zones-and-fees`. This takes **31**. Branch off a
> `develop` that already contains 30 — this step edits `CheckoutForm.tsx` directly and will
> conflict otherwise.

**Frontend only.** No API change, no migration, no admin change. Follow the current
`CLAUDE.md`, especially **§3 Design language**, which is the binding constraint here.

---

## 0. What the screenshot actually shows — two problems, not one

The checkout region select looks wrong for two unrelated reasons, and only the second one
needs a library.

**Problem 1 — the trigger is missing `appearance-none`.** The current class string is
`min-h-12 w-full rounded-full border border-rule bg-transparent px-5 py-3 text-base`
(`CheckoutForm.tsx`, both selects). With no `appearance-none`, the browser draws **its own
chevron** inside a `rounded-full` pill it knows nothing about. That is the mismatched grey
arrow. It is a two-line CSS fix and it is not a reason to install anything.

**Problem 2 — `<option>` elements cannot be styled. At all.** The popup list in the
screenshot — square corners, OS-blue highlight, system font — is rendered by the browser
chrome, not the page. No amount of CSS reaches it. This is the real problem, it is
unfixable in CSS by design, and it is the only thing in this step that justifies a
dependency.

**Before replacing the native select, know what you are giving up.** That screenshot is
desktop Chrome. On iOS the same markup opens a native wheel picker; on Android, a native
sheet. Both are *better* than a JS popup — larger targets, familiar gestures, no scroll
trapping, zero bundle. For a Myanmar storefront where most traffic is a phone, the native
control is winning on the platform that matters and losing on the one in the screenshot.

So the decision is not "native is broken". It is: a consistent, on-brand control everywhere
is worth ~15KB and a real dependency on the mobile path. **That is a legitimate call, and
this step takes it** — the checkout is where trust is won, an OS-blue list inside an
apothecary-minimal page reads as unfinished, and §3's whole thesis is that type and
restraint do all the work. But take it knowingly, and fix Problem 1 first so the two changes
can be judged separately.

## 1. Decisions locked — build to these

1. **Ship the `appearance-none` fix on its own commit, before any install.** It is the
   majority of the visible defect and it must be separable — if shadcn is reverted later,
   this survives.
2. **shadcn is not adopted wholesale. Three primitives, no more:** `Select`, and the
   `Popover` + `Command` pair only if §3's township list earns a search box. Nothing else.
3. **Do not replace `Button`, `Skeleton`, `Pill`, `QuantityStepper` or `ImagePlate`.** They
   exist, they carry §3's motifs, and shadcn's equivalents carry a different design language.
   Churning them buys nothing and costs the hairline-pill signature.
4. **The copied components get restyled to project tokens, not the other way round.** This is
   the whole point of shadcn's copy-in model and the single most important rule in this step
   — see §3.
5. **`globals.css` keeps its `@theme` block exactly as it is.** Hex values in §3 are fixed
   and shadcn's initializer must not be allowed to rewrite them.
6. **No dark mode.** shadcn's default `init` writes a `.dark` block; the project has never
   had one and §3 does not describe one. Delete it if it lands.
7. **Every interactive control stays ≥16px.** Non-negotiable — see §5.

## 2. The immediate fix (commit one, no dependencies)

Both selects get `appearance-none` plus an own chevron, and the placeholder option gets
`disabled` so it cannot be re-picked once a real choice is made:

```tsx
<div className="relative">
  <select
    className="min-h-12 w-full appearance-none rounded-full border border-rule
               bg-transparent px-5 py-3 pr-12 text-base disabled:opacity-50"
    ...
  >
    <option value="" disabled>Choose your state or region…</option>
    ...
  </select>
  <svg aria-hidden viewBox="0 0 12 8"
       className="pointer-events-none absolute right-5 top-1/2 h-2 w-3 -translate-y-1/2 text-muted">
    <path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.5" />
  </svg>
</div>
```

Note `pr-12` — without it the longest township name runs under the chevron. Extract this as
a small `SelectShell` in `components/ui/` rather than repeating it; the payment-proof file
input and any future select want the same treatment.

## 3. The token rule — restyle the copy, don't import a palette

shadcn's `init` wants to write its own variable layer: `--background`, `--foreground`,
`--primary`, `--border`, `--input`, `--ring`, `--radius`, in OKLCH, under `@theme inline`.
**Do not let it.** Three concrete reasons, in descending severity:

- **`--muted` collides.** The project defines `--color-muted: #63707A`, a *slate text colour*
  used as `text-muted` in at least the checkout hints and placeholders. shadcn defines
  `--muted` as a *background* with `--muted-foreground` as its text pair. Both generate
  `muted` utilities under Tailwind v4's `@theme`. Whichever wins, something silently changes
  colour, and it will not be obvious which.
- §3 fixes every hex and says so in the spec — "hex values are fixed — do not substitute".
  An OKLCH conversion of a different palette is a substitution.
- The project has no `--radius` concept. It has `rounded-full` for inputs and pills and
  `rounded-2xl` for textareas, chosen deliberately. shadcn's `rounded-md` default is the
  generic-SaaS look §3's calibration warns about.

**So: run the CLI, take the component files, then delete every class string shadcn shipped
and rewrite it in project tokens.** Concretely, `SelectTrigger` becomes the same class string
as §2's fix; `SelectContent` becomes `rounded-2xl border border-rule bg-mist` with the
project's own shadow; `SelectItem` highlight becomes `bg-pine-soft` and selected becomes
`text-pine`; focus ring is already handled globally by `:focus-visible` in `globals.css`.

Two things make this cheap. shadcn's current components ship a **`data-slot` attribute on
every primitive**, so global rules can be written once in `globals.css`
(`[data-slot="select-item"][data-highlighted] { background: var(--color-pine-soft); }`)
instead of chased through files. And the components no longer use `forwardRef`, so the files
are plain and short to edit.

If a `cn()` helper lands with `clsx` + `tailwind-merge`, keep it — it is 2KB and the codebase
will want it. If `class-variance-authority` arrives only for `Select`, drop it; the existing
`Button.tsx` shows the project's own variant idiom already.

## 4. Install mechanics for this exact stack

The frontend is **Next 16.2.10, React 19.2.4, Tailwind v4 via `@tailwindcss/postcss`, no
`tailwind.config.js`, five runtime dependencies.** shadcn supports this combination —
Tailwind v4 and React 19 are fully supported, `forwardRef` is gone, and components carry
`data-slot`. Notes specific to here:

- There is **no `components.json`** (an `init` would create one), but the `@/` path alias
  **already exists** — `tsconfig.json` maps `@/*` → `./src/*` and the codebase imports
  through it (`@/components/ui/Button`, `@/lib/api`). So nothing needs to add it; if `init`
  offers to, decline — the mapping is already correct. (As built, this step skips the shadcn
  CLI and `components.json` altogether: a half-configured `components.json` with no CLI in the
  loop is worse than none, so the one Radix primitive is authored directly — see §4a below.)
- **npm + React 19**: the CLI prompts for a peer-dependency flag on npm. Answer it rather
  than forcing `--legacy-peer-deps` into the Dockerfile, which would hide future conflicts.
- **`init` will offer to rewrite `globals.css`. Decline, or revert it in the same commit** —
  Decision 5. Diff that file deliberately before committing; it is the one place this step
  can do quiet damage.
- The Docker dev stack installs from `package.json`; new deps mean a rebuild, so state that
  in the PR body for anyone pulling the branch.

Expected additions: `@radix-ui/react-select`, `lucide-react` (or drop it and reuse the §2
inline SVG — one fewer dependency and the chevron already matches), plus `clsx` and
`tailwind-merge` if `cn()` is taken.

### 4a. As built — the CLI was skipped, one dependency added

The end state §3 asks for ("delete every class string shadcn shipped and rewrite it in
project tokens") is reached by authoring the one Radix primitive **directly** rather than
running the shadcn CLI. Concretely:

- **No `shadcn init`, no `components.json`, no `cn()`/`clsx`/`tailwind-merge`, no
  `lucide-react`.** A `components.json` with no CLI in the loop is a half-configured
  landmine (§3's token-layer warning); skipping it is cleaner. `cn()` isn't needed because
  `SelectShell` owns only its right padding (chevron clearance) and consumers pass
  additive-only classes, so no Tailwind conflict arises — the same plain-concatenation idiom
  `Button.tsx` already uses. The chevron/check icons are the §2 inline SVG, so `lucide` is
  dropped too.
- **Exactly one runtime dependency lands: `@radix-ui/react-select`.** `Select.tsx` is a thin,
  `data-slot`-carrying wrapper over it — shadcn's *behaviour and copy-in spirit* (we own and
  restyle the source), authored against the package's real types, not shadcn's exact file.
- **`SelectContent` gets one light, pine-tinted shadow.** §3 said "`bg-mist` with the
  project's own shadow", but the codebase in fact uses **no `shadow-*` anywhere** (the cart
  drawer floats on `border-l border-rule bg-mist` alone, behind a scrim). A dropdown has no
  scrim and floats over live content, so it needs elevation the border can't give; this is
  the one place a shadow is introduced, kept soft and pine-tinted so it stays quiet. The §7
  "no shadow heavier than elsewhere" check reads against that: elsewhere is zero, so the bar
  is "as light as possible", which this meets.
- **Follow-up (review round) — the shop sort joined the Radix `Select`, and `SelectShell`
  was removed.** §2's `SelectShell` shipped as planned (the appearance-none commit) and
  briefly carried all three native selects. A review-round decision then moved the shop
  **sort** onto the same Radix dropdown for one consistent control, which left `SelectShell`
  with no consumer — so it was deleted. The storefront now has **no native `<select>`**; the
  appearance-none fix survives as its own commit in history. §2 above describes that
  intermediate state, not the final file layout.

## 5. The iOS trap — the one thing most likely to regress

**shadcn's `SelectTrigger`, `Input` and `Label` default to `text-sm` — 14px.** Every input on
this checkout is currently `text-base` (16px) and that is deliberate: v5 locked ≥16px on real
inputs because **iOS Safari zooms the viewport when a control under 16px takes focus**, and
the worst place for that is checkout. A straight `npx shadcn add select` would silently
reintroduce it, and it will not show up in desktop review, in `npm run build`, or in any test
currently in the repo.

Rewrite `text-sm` → `text-base` in every copied component, and keep the ≥44px target
(`min-h-12` is 48px and already correct). This is also the reason **not** to pull shadcn's
`Input`, `Textarea` or `Label`: the project's current ones are three utility classes each,
they already satisfy the rule, and replacing them trades a correct control for one that needs
patching.

## 6. Deliberately not in this step

- No `Input`, `Textarea`, `Label`, `Button`, `Card`, `Dialog`, `Skeleton` — Decision 3.
- No dark mode, no theme switcher, no `next-themes`.
- No admin-side change. Filament is a separate design language and does not share this stack.
- No design-language change. §3's palette, Helvetica stack, hairline pills and `rounded-full`
  inputs are unchanged; this step makes one control obey them, it does not renegotiate them.
- **No Combobox unless the township list actually needs one.** Radix `Select` has built-in
  type-ahead — typing jumps to the match — which is usually enough at Yangon's ~45 townships.
  Add `Popover` + `Command` (`cmdk`) as a follow-up only if real use says otherwise; it is a
  third dependency and a second interaction model on the same form.
- No motion work. `gsap` and `motion` are already present and this step adds no animation
  beyond Radix's own open/close, which must respect the existing
  `prefers-reduced-motion` block in `globals.css`.

## 7. Verification

There is no test suite on the frontend beyond `npm run build`, so this step is verified by
hand and the checklist is the deliverable:

```
docker compose exec frontend npm run build
```

Then, on the checkout page:

- **Real iPhone or iOS Simulator, Safari.** Focus each field in turn. **The viewport must not
  zoom** — this is §5 and it is the one that ships broken otherwise.
- Keyboard only: Tab to the region select, open with Enter/Space, move with arrows, type
  "Yan" and confirm type-ahead jumps, Escape closes and returns focus to the trigger.
- The chosen township's fee still appears in `OrderSummaryCard` — the select swap must not
  break `onTownshipChange`.
- Region select disabled while zones load; township select disabled until a region is picked;
  both still show their placeholder copy.
- A region with no active townships still renders the empty state added in step 30 (the
  screenshot shows only Ayeyarwady and Yangon active, so this path is live right now).
- `prefers-reduced-motion: reduce` — the popup must not animate.
- Compare against §3: `border-rule` hairline, `pine-soft` highlight, Helvetica throughout, no
  stray `rounded-md`, no shadow heavier than the project uses elsewhere.

## 8. Docs — same branch (WORKFLOW step 5)

- **`CLAUDE.md`** → **v22**. A §0 changelog line. §3: a short paragraph recording that the
  storefront now uses Radix behaviour under project styling for one control, that shadcn's
  token layer was deliberately **not** adopted, and that ≥16px on inputs is a hard rule any
  copied component must be patched to meet.
- **`frontend/README.md`**: the new dependencies, and the one-line reason each is there.
- **`prompts/README.md`** step table → this step built.
