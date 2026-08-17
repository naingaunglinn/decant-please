# ADR-0003: Admin access model for Filament tenancy

**Status:** Accepted — Option C, amended as built (v25; see "As built" below)
**Date:** 2026-08-11 · **Built:** 2026-08-17
**Deciders:** Naing Aung Linn
**Informs:** `prompts/*-multi-tenancy-shop-onboarding.md` (whose panel half became
Step 25a and is built; the client-facing halves stay gated on a second client)

> Step files are referenced by name, not number (ADR-0002's convention — the numbers
> collide with develop's and renumber on merge).

## Context

When the shop-onboarding step runs, the panel adopts Filament tenancy and someone has to
decide what "a user may enter shop X" is backed by. The design doc §7 specs a `shop_user`
pivot ("you belong to all shops, a client to one", with a `role` column). The commercial
model (`PRODUCT.md`) says the 3-month trial works differently: the studio operator runs
the shops — clients bring a catalog and a Telegram chat, not a login. Today there is one
admin user; `User` implements only `FilamentUser`, `canAccessPanel()` returns `true`
unconditionally, and `users` carries no role or membership column.

## What findings §3 constrains — and what it deliberately does not

1. **`HasTenants` is required either way, but it is an interface, not a schema.**
   Filament's `IdentifyTenant` middleware requires `User implements HasTenants`
   (`canAccessTenant()`, `getTenants()`) and 404s otherwise. Nothing in the vendor code
   cares whether those two methods read a pivot, a flag, or a constant. The options below
   differ only in what backs the same two methods — which is what makes deferring the
   pivot cheap.
2. **This is not the isolation decision.** Filament's tenancy scope silently no-ops
   outside an active panel with a resolved tenant, covers only resource-backed models
   (`OrderItem`, `DecantPrice`, `ShopSetting`, and every custom page are outside it), and
   the vendor disclaims multi-tenant security outright. The throwing `BelongsToShop`
   scope stays the floor under every option — so this choice is decided on operational
   and commercial grounds, not security ones. No option below changes what can leak.
3. **Denial is the default.** A user failing `canAccessTenant()` gets a 404 from the
   middleware. Under B/C, a stray or future client account can reach nothing until it is
   explicitly granted something.
4. **Option-independent edges.** `isPersistent: true` on the tenancy middleware,
   `scopedUnique()` for any future unique rules, and the resource-backed-only scope all
   arrive with Filament tenancy whichever option is chosen. They belong to the step, not
   to this decision.

## Options

### Option A — `shop_user` pivot now (as specced in design doc §7)

| Dimension | Assessment |
|---|---|
| Complexity now | Medium — pivot table + `role`, attach-on-onboard, client credential handling |
| Ops during trial | Every onboarding must attach the studio user; client passwords are hand-set (no mail driver — N6, §11) for logins the trial model says nobody uses |
| Cost to migrate away | Trivial before any client login is issued; **effectively one-way after** — a login granted cannot be socially retracted |
| Trial tier | Pure bookkeeping: N pivot rows asserting "studio sees all", a fact a flag states once |
| Paid tier | Ready for client logins on day one |

**Pros:** the end-state schema from the start; no second migration later.
**Cons:** builds account plumbing for clients who don't log in during trial, against a
stack with no mail driver (password resets are manual — §11). Its studio grant is
fragile: "studio" is expressed as membership in *every* shop, so one forgotten attach
during onboarding silently hides that shop from the switcher — a drift class the seam
otherwise avoids (one mechanism, one place).

### Option B — `is_studio` flag, no client accounts during the trial

| Dimension | Assessment |
|---|---|
| Complexity now | Low — one boolean column, ~10 lines in `User` |
| Ops during trial | None beyond today; no client credentials exist at all |
| Cost to migrate away | One **additive** pivot migration + a second branch in two methods; no backfill if the flag remains the studio grant |
| Trial tier | Exact fit — the operator operates |
| Paid tier | Holds only until a paying client asks to see their own panel — a when, not an if |

**Pros:** matches the trial reality; nothing to phish, reset, or explain; `getTenants()`
is `Shop::all()` (`Shop` carries no tenant scope — rule 4 — so no escape hatch needed).
**Cons:** if it ossifies, every client interaction routes through the operator forever —
§11's "third client → it becomes your evenings" force. The paid pitch ("genuinely more
isolated", own bot) sits oddly beside "but only I can open your admin panel."

### Option C — flag now, pivot when a client first asks for a login

| Dimension | Assessment |
|---|---|
| Complexity now | Same as B, plus writing the two methods in composed form from day one |
| Ops during trial | Same as B |
| Cost to migrate away | None — the pivot is C's planned second half, not a reversal; it lands additively and starts **empty** (studio access never moves onto it) |
| Trial tier | B's fit |
| Paid tier | A's capability, granted per client at the moment it becomes real |

**Pros:** B's cost today with A's end state preserved. The composed contract —
`canAccessTenant()` = `is_studio || membership`, `getTenants()` = `is_studio ?
Shop::all() : $this->shops` — is written once; the pivot's arrival adds a branch's
backing, not an interface change. Keeping `is_studio` as the permanent studio grant
also fixes A's fragility: studio visibility never depends on N attach rows.
**Cons:** two schema moments instead of one; the `role` column question is deferred
(rightly — there is no second real role until a client has staff).

## Recommendation

**Option C.** The design doc already applied this exact rule to the whole step — "building
this now is abstracting for clients you don't have" (§9) — and A-during-trial is that
abstraction in miniature: membership rows and credential ops for logins the trial model
says don't exist. B alone discards the paid tier's obvious ask. C is B's bill with A's
door held open, and findings §3 is what makes the door cheap: Filament binds to two
methods, not to a table.

The one discipline C demands: write `canAccessTenant()`/`getTenants()` in the composed
form on day one, and spec the pivot migration (A's shape, `role` included but unused)
in the same PR that ships the flag — so the second half is an execution, not a design
session under a waiting client.

## Consequences

- The shop-onboarding step's scope line ("`shop_user` pivot… studio user belongs to all
  shops") amends to "flag now, pivot on first client ask"; design doc §7's `shop_user`
  annotation and findings §4's `User` row ("cross-shop via the proposed `shop_user`
  pivot") gain a pointer to this ADR. Doc edits on acceptance — nothing is built.
- `is_studio` is an access grant, never a tier marker. Trial/plan state lives on `shops`
  (`PRODUCT.md` open question 2); conflating them would couple billing to authorization.
- The onboarding runbook never contains an "attach studio user to the new shop" step —
  one fewer thing to forget per shop (F6's "minutes, not a deployment").
- When the pivot lands: client passwords are still hand-set until a mail driver exists
  (§11) — for one requesting client that is fine; an invite flow stays unbuilt.
- The two-shop isolation suite (design doc §8) gains one case at that point: a client
  user of shop A requesting shop B's panel URL gets the 404, asserted as a count of
  zero B rows rendered.

## As built (v25 — Step 25a)

Option C, with two conscious deviations from the action items:

- **The pivot table shipped now, empty**, in the same migration as `is_studio`
  (`2026_08_11_000000_add_tenant_membership.php`) rather than waiting for the
  first client request. The C-critical property survives: no membership row
  exists, no client credential exists, and studio access never depends on the
  pivot. Landing the empty table early costs nothing and removes the second
  schema moment.
- **No `role` column.** The recommendation's "A's shape, `role` included but
  unused" lost to this ADR's own cons note — "there is no second real role until
  a client has staff." The column arrives with the first client login that needs
  it, an additive change.

The composed contract is exactly as specced: `canAccessTenant()` =
`is_studio || membership`, `getTenants()` = `is_studio ? all : own`
(`User.php`), existing users backfilled studio.

**Second amendment (same v25):** the pivot's "first client ask" arrived as a
product requirement — shop registration in the studio panel now creates the
owner's login by default (non-studio user + one membership row; shop and owner
commit in one transaction). Passwords remain hand-set (§11 — no mail driver,
no invite/reset flow), `role` remains deferred, and the §8 panel-URL-404 case
for a confined owner is now in the suite (`ShopManagementTest`) along with the
studio-panel 403.

## Action items

1. [x] Decide A/B/C — Option C accepted
2. [x] Amended: the shop-onboarding step's scope (now `25a` as built, pointing
       here), design doc §7's `shop_user` annotation, and findings §4's `User` row
3. [x] Shipped: `is_studio` + composed `HasTenants` (deviations recorded above)
