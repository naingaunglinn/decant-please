# Step 11 — Responsive design pass + Flutter-ready foundations

Work mostly in `frontend/`, plus three small additive `backend/`-adjacent docs in
Part B. Follow `CLAUDE.md` v4. This step was scoped against the actual repo (current
`main`, past Steps 8 and 10 merged) rather than written generically — every gap below
names the real file and class involved, and every "already good" item in §0 is
something to verify still works, not rebuild from scratch.

**Two parts, different urgency.** Part A (§1-5) is real, verified UI work — do this
now. Part B is the decanter's own "maybe later" Flutter plan — nothing in it builds
Flutter, it just stops today's decisions from making that harder later, the same way
Step 10's promo columns were additive without touching `discount_mmk`. If you only
have time for one part, do Part A.

## 0. What this app already gets right — verify, don't rebuild

Several genuinely good responsive patterns already exist. Re-test these at the
matrix in §5; don't spend time redoing them:

- `Navbar` / `MobileNav` — hamburger below `sm`, horizontal links above it.
- `FilterBar` (sticky desktop sidebar, `hidden lg:block`) and `FilterSheet` (mobile
  bottom sheet, `lg:hidden`) both wrap the same `FilterControls` — the shared
  component is correctly breakpoint-agnostic because its two parents handle the
  split. Don't add responsive classes *inside* `FilterControls` itself.
- `CartDrawer`'s `w-full max-w-sm` — already the correct pattern (full-width on a
  phone, capped on anything wider).
- `PurchasePanel`'s sticky mobile add-to-cart bar (`IntersectionObserver` + `md:hidden`)
  once the main button scrolls out of view — a real, deliberate mobile commerce
  pattern, not an accident.
- `CheckoutClient`'s `grid md:grid-cols-[1fr_minmax(280px,380px)]` with
  `order-1`/`order-2` swapping the summary card *above* the form on mobile — a
  customer sees what they're paying before they type their address. Keep this order
  swap; it's easy to lose by accident if the grid gets touched for other reasons.
- Touch targets on real controls are already sized correctly: `SizeSelector` rows are
  `min-h-14`, icon buttons (`MobileNav` toggle, `CartDrawer`/`FilterSheet` close) are
  `size-11`. §3 below is about the places that *don't* follow this convention yet.

## 1. The 480px "reference card" never widens past mobile

`fragrance/[slug]/page.tsx`, `order/complete/page.tsx`, and `track/page.tsx` all use
the identical wrapper:

```tsx
<div className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16">
```

That's a real, consistent three-tier container system across the site — 480px for
these single-focus "reference card" pages, 880px for checkout
(`grid md:grid-cols-[1fr_minmax(280px,380px)]` — see §0), 1280px for the shop grid —
not three unrelated bugs. But the 480px tier never gets a `lg:`/`xl:` bump, which
means on a tablet or laptop the fragrance detail page — the highest-stakes page in
the app, the one page whose entire job is convincing someone to buy — shows a
postage-stamp-sized product photo in the middle of a mostly-empty `mist` screen.
Same story on `order/complete` and `track`, lower stakes but still worth fixing once,
consistently.

**Do this (the default — matches the existing restrained, editorial brand register
rather than introducing a new layout shape):**

```tsx
<div className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16 lg:max-w-[640px] xl:max-w-[720px]">
```

Applied identically to all three files, same as the original class string was
identical across all three. Two follow-on effects that have to travel with this
change, not get left stale:

- The PDP's `ImagePlate` currently has `sizes="(max-width: 768px) 100vw, 480px"` —
  once the container can be 640-720px wide, that hint under-sizes the image
  Next/Image actually fetches. Update it to match, e.g.
  `sizes="(max-width: 768px) 100vw, (max-width: 1024px) 640px, 720px"`.
- The PDP's "You may also like" rail (`grid grid-cols-2 gap-4`, no breakpoint at all)
  will look sparse — two huge cards — once its container is wider. Give it a
  breakpoint too, e.g. `sm:grid-cols-2 lg:grid-cols-3`; adjust if that looks cramped
  once you can actually see it rendered.

**The alternative, if you'd rather have it instead:** a real two-column desktop PDP
layout (`lg:grid lg:grid-cols-2`, image left, details+purchase right) — closer to a
conventional product page, a bigger layout change, and it doesn't extend to
`order/complete`/`track` the same way since neither has a hero image to put opposite
the text. If this is what's wanted, say so explicitly and treat it as scoped to the
PDP only; `order/complete` and `track` still just get the width bump above.

## 2. Form inputs are 14px, which triggers iOS Safari's auto-zoom on focus

`CheckoutForm`'s name/phone/address/note fields, `TrackingForm`'s code/phone fields,
and `FilterControls`' search/notes/min-price/max-price/sort fields are all
`text-sm` (14px). Any real `<input>`/`<textarea>`/`<select>` under 16px makes iOS
Safari zoom the whole viewport in when it's focused — jarring anywhere, and this
specific bug lands on checkout, the one flow this project cares most about nobody
abandoning partway through.

Fix: `text-base` on every one of those elements, at every breakpoint — **don't**
add a `sm:text-sm` step-down to claw back the denser look above a certain width. The
zoom trigger is about the device and browser handling the focus event, not the
viewport width, so a width-keyed override can silently reintroduce the exact same
bug on a landscape phone whose viewport happens to cross the `sm` boundary while
still being an iOS touchscreen. If the extra 2px bothers anyone visually once it's
live, that's a deliberate follow-up call, not a same-step "fix."

Leave button, pill, and label text sizes alone — this is real form inputs only.

## 3. A few tappable elements don't follow the app's own touch-target convention

`Pagination`'s Previous/Next links and `CartItemRow`'s Remove button are plain inline
text with no minimum height — inconsistent with the `min-h-11`/`size-11`/`min-h-14`
convention §0 already confirmed is used everywhere else something is tappable. Give
each an explicit ~44px hit area (e.g. `inline-flex min-h-11 items-center`, or
negative-margin hit-slop if bulking up the visible text would throw off the
surrounding layout) without changing how they read visually.

## 4. Grid breakpoints skip a step

`FragranceGrid` goes `grid-cols-2` straight to `lg:grid-cols-3` (1024px) — an iPad
portrait viewport (768px, `md`) still only gets 2 columns despite the extra room.
Add `md:grid-cols-3` (bump `xl:grid-cols-4` stays as-is), or leave a one-line comment
explaining why 2 is deliberate at that width if you decide it is. This is small; do
it as part of the same pass rather than a separate step.

## 5. Test matrix — this is the actual acceptance bar for Part A

Check every flow below at each width, not just the ones you changed — §0's list is
in scope too:

| Width | Represents | Check |
|---|---|---|
| 375px | small phone | Home, Shop + open/close `FilterSheet`, PDP + sticky add-to-cart bar appearing/disappearing on scroll, `CartDrawer`, Checkout (focus every field), `order/complete` incl. print preview, Track |
| 768px | tablet portrait | Same list — this is where §1 and §4's gaps actually show up |
| 1024px | tablet landscape / small laptop | `FilterBar` sidebar appears, `lg:` PDP width takes effect |
| 1440px | desktop | Full layout, verify the widened PDP/receipt containers don't look oddly narrow *or* stretch awkwardly |

**Important:** the §2 zoom-on-focus bug does not reproduce in Chrome DevTools'
device toolbar — that's a Chromium viewport simulation, not real WebKit focus
behavior. Verify it on an actual iOS device, the iOS Simulator, or a real-device
cloud (BrowserStack or similar); confirming it only in Chrome's responsive mode is
not confirming it's fixed.

## Part B — Flutter-ready foundations (optional, low-cost, prep only)

Not building Flutter now — this is the decanter's stated future plan, not a request
to start it. Good news, verified against the actual backend rather than assumed:
the API is already in solid shape for a future native client to hit directly. All 8
`/api/v1` endpoints (`brands`, `fragrances` index/show, `meta`, `orders` store,
`orders/track`, `orders/cancel`, `orders/validate-promo`) are already stateless —
`config/cors.php` has `supports_credentials => false`, and there's no customer login
at all in this project to begin with, so there's no cookie/session model to migrate
away from. None of that needs to change. What's actually missing is a written
contract and a portable copy of the design tokens — both worth having on their own
merits (this backend currently has zero API documentation beyond the prompt files
themselves), and exactly what a future Flutter effort would need first anyway.

### B1. A written contract for the `/api/v1` surface

Not a real tooling investment for 8 endpoints — no annotation library needed. A
hand-written `backend/docs/api.md` (prose + examples reads better here than a formal
OpenAPI YAML for a set this small, but either is fine, your call) covering, per
endpoint: request shape, response shape, status codes, and — this is the part worth
being careful about — the specific conventions this project already leans on that a
second client would need to copy exactly, not reinvent:

- Tracking's identical generic 404 on a code mismatch vs. a phone mismatch (not an
  oracle for guessing either field).
- Checkout's per-line snapshot pricing — the client sends `fragrance_id`, `size_ml`,
  `quantity` only, never a price; the server derives and re-validates everything.
- The promo `promo_note` soft-failure shape from Step 10 (a code that lapses between
  preview and submit doesn't fail the order, it drops the discount and explains why).
- Per-endpoint rate-limit buckets (`throttle:catalog`/`checkout`/`tracking`/`cancel`/`promo`)
  and what each returns at its limit.

### B2. A portable copy of the design tokens

`CLAUDE.md` §3's color table and `globals.css`'s `@theme` block are the only places
the design system lives today, and both are Tailwind/CSS-specific — fine for
Next.js, opaque to a future Flutter `ThemeData`. Add `design-tokens.json` at the repo
root, same token names and hex values as `globals.css` (`mist`, `ink`, `ink-strong`,
`pine`, `pine-soft`, `rule`, `surface-alt`, `muted`, `status-pending`,
`status-danger`), so a future build translates one canonical file instead of
re-transcribing a markdown table by hand and risking drift between the two.

### B3. One flag in `CLAUDE.md` itself

A short new note — version-marker it the same way v2/v3/v4 are marked, since this is
a real addition to project memory, not just a code change:

> A Flutter mobile client is a stated future plan (not scheduled, not started).
> Because of this: keep all customer-facing business logic — pricing, promo
> evaluation, order-status rules — in the Laravel API, never client-side-only in
> Next.js, so a second client can reuse it without re-deriving the rules. This is a
> constraint on *where logic lives* going forward, not a request to start mobile work.

This is the part that actually matters most if Part B otherwise gets skipped: it
stops a future session (with or without this file in front of it) from casually
adding a Next.js Server Action that quietly embeds a rule the API doesn't also
expose — the same failure mode `PromoCode::evaluate()`'s single-shared-method
approach in Step 10 was already written to avoid on the backend side.

## Verify

- [ ] Every gap in §1-4 is fixed in the specific files named, not a broader rewrite.
- [ ] Everything in §0 still works at every width in §5's matrix.
- [ ] The §2 fix is confirmed on real WebKit (device, Simulator, or device cloud),
      not just Chrome DevTools.
- [ ] The PDP's `sizes` attribute and related-fragrances grid were updated alongside
      the container-width change in §1, not left stale.
- [ ] Report which §1 option you took (width bump vs. two-column PDP) — same
      discipline as every prior step's real decisions.
- [ ] If Part B was done: `backend/docs/api.md` matches the endpoints' actual current
      behavior (not aspirational), `design-tokens.json`'s values match `globals.css`
      exactly, and `CLAUDE.md` has the new note with a bumped version marker.
