# Finance — full scope, corrected

Supersedes `finance-module-spec.md`, whose Phase 1 assumed payments didn't exist. They
partly do. Scope is unchanged: a complete financial surface in the Filament admin.

Not a `prompts/` file. `prompts/` holds numbered single build steps with a collision rule
and a WORKFLOW.md issue; this is the roadmap those steps come from.

§8 has been amended repeatedly (v8 bottle volume, v10 payment confirmation, v13
notifications — verified from the section's git history; v12's proof-privacy and v14's
method/delivery-fee notes live in §0, not §8). The README's out-of-scope list is stale —
treat §8 itself as the source.

---

## Already built — do not rebuild

| Capability | Where |
| --- | --- |
| Itemised pricing, server-derived | `Order.php:121`, `OrderItem.php:15` |
| Immutable price snapshots | `unit_price_mmk`, `line_total_mmk` |
| Promo codes: percent/fixed, caps, minimums, usage limits | `PromoCode.php:60` (floors) |
| Order total incl. delivery fee | `recalculateTotal()`, `Order.php:391` |
| Amount owed | `balanceDue()` |
| Payment proof flag | `has_payment_proof` (v12) |
| Revenue this month, revenue chart | `OrderStats.php:17`, `RevenueChart` |
| Top fragrances (by vials, not money) | `TopFragrances` |
| Stock in ml, low-stock alert | `stock_ml`, `low_stock_threshold_ml` (v8) |
| Bottle cost + liquid-only gross margin | `liquidCostMmk` (ceiling), `unit_cost_mmk`/`line_cost_mmk` snapshots, dashboard stat, CSV columns (step 28) |
| Order CSV export incl. balance due | `OrdersTable.php:126` |
| A5 invoice with balance due | invoice generator |

That is more than a "generic e-commerce financial admin" checklist would credit. The gaps
below are what's genuinely absent.

---

## In flight

**Nothing.** Cost and margin (prompt 28, issue #66) is built — see Already built. The next
phase starts at gap 1, behind #67's two-promotion sequence.

---

## Real gaps, in build order

### 1. Payment detail (extend, not build)

More shipped here than this doc first credited — verified against the migrations and enums
(step 22, `prompts/22-online-payment-method.md`), not the specs:

- `orders.payment_method` (`cod` | `online`, default `cod` — v14): how the customer **chose
  to pay at checkout**. An online order is born with its slip (required at checkout, private
  disk since v12) and prepays the item subtotal only — delivery fee stays cash-to-courier.
- **Caveat — `cod` is three populations wearing one value:** a customer who actively chose
  COD (website, post-v14); an order predating v14 (the migration backfilled `cod`); and a
  manual/DM order where the admin form pre-fills COD and nobody picked (`OrderForm.php`
  pairs `required()` with `default()`, so required never fires). Every method-based report
  inherits the conflation. It decomposes only partially: `order_from = website` created
  after the v14 promotion is a genuine choice; manual sources are presumed-defaulted;
  pre-v14 is backfill, separable only by `created_at` against the promotion date. The admin
  form should drop the silent default (keep required) so a manual order forces an explicit
  pick and the third population stops growing.
- `orders.payment_status` (`unpaid` | `paid`) + `paid_at` (v10): the decanter's
  confirmation, hook-synced whichever write path flips it.
- `shop_settings` (v14, single row): MMQR image + KBZPay/Wave names and numbers +
  instructions, served via `/meta` with `PAYMENT_*` env fallback.

What is genuinely still missing:

- the settlement **rail** — `online` doesn't record whether money arrived by KBZPay, Wave,
  or bank; the slip image knows, the schema doesn't
- the **value date** — when the money actually moved; `paid_at` is when the decanter
  confirmed, and an online slip predates confirmation by design
- **reference** — bank txn ref or sending phone number
- **who** confirmed it (one admin today; multi-tenancy makes this real)
- **multiple** payments per order — `deposit_mmk` is a single hand-entered figure
- **refunds** as records rather than as an edited-down figure

The extend-or-table decision stands: if a table, `amount_mmk` always positive with a `type`
enum (`payment` / `refund`), so "received this month" and "refunds this month" stay separate
figures rather than one netted number.

Rules that hold either way: payment state stays independent of the fulfilment lifecycle — a
`delivered` order can be unpaid, an `awaiting_confirmation` order can be prepaid (that is
exactly what a v14 online order is). Overpayment is stored and flagged, not clamped: #67
makes `balanceDue()` signed and surfaces a negative display-only (invoice prints Collect: 0
plus an overpaid line). The would-be negative-balance population — pre-fix online orders
asked for the *undiscounted* subtotal, where the promo discount exceeds the delivery fee —
was **verified empty in production on 2026-08-04** (zero online orders exist); if #67
deploys before the first one, that class never materializes as backlog. Refund handling and
refund records stay this gap. Cancelling an order with payments creates a visible refund
obligation.

§8: the payments bullet was amended by **v10**, not v12, and not since — the section's git
history shows v2 created it and v8, v10, v13 amended it; step 22 shipped with no §8 edit,
which holds, since no gateway came in. Recording payment detail extends v10's formalisation
— no amendment needed. The bullet is one line stale about v14's method choice; fold that
catch-up into prompt 28's planned v19 §8 edit rather than a docs-only PR.

### 2. Receivables aging

Owed exists as a total. Aged doesn't. Bucket by days since delivery (0–7, 8–14, 15–30, 30+),
surface the oldest unpaid order. That's the one worth a phone call, and a single total can't
tell you which it is.

### 3. COD float

Cash collected by a courier that hasn't reached the decanter. On a cash-on-delivery model
this is a real asset sitting outside the business and it is currently invisible. Minimum
version: mark an order handed to a courier with a date; float is the sum not yet settled.
No courier entity needed for v1.

No generic e-commerce template will give you this. It matters more here than most of what
one would.

Hazard (gap 1's caveat applies): `payment_method = cod` conflates chose-COD with pre-v14
backfill and silently-defaulted manual orders — and online orders generate courier cash
too, since the delivery fee is collected in cash regardless of method. Scope float by the
handed-to-courier marking and what the courier actually carries, never by `payment_method`
alone: a method filter both sweeps in the backfill populations and misses the online-order
fee cash.

### 4. Delivery margin (measurement gap)

`delivery_fee_mmk` records what the customer was charged. Nothing records what the courier
was paid. If the fee is flat and courier rates vary by township, delivery is quietly making
or losing money that no report can show. Excluded from profit per the v14 pass-through
reading — but document it as **unmeasured, not zero**, and revisit if the decanter says the
fee isn't actually break-even.

### 5. Discount cost report — built (#74)

A dashboard widget: this month's discounts by code, hand-edited discounts as their own
row, summed from the **order snapshots** — never `times_used`, which counts claims
including orders later cancelled. §4 exclusions asserted by test.

### 6. Margin by size and by fragrance

Depends on gap 6's prerequisite: allocating the order-level discount across lines. Allocate
proportionally by line revenue with a deterministic remainder, or the parts won't sum to the
whole. The alternative — computing per-line margin gross of discount — gives you two margin
numbers that don't reconcile with the order-level "Gross margin (liquid only)" stat. Decide
which is authoritative before building either.

Margin by size (5ml vs 10ml vs 30ml) is the highest-value report in this whole document.
It's also the one most distorted if cost omits consumables — see Decisions.

### 7. Audit trail on money edits

The admin hand-edits `discount_mmk`, and will hand-edit `unit_cost_mmk`. Nothing records who
changed a money figure, from what, when. Without it a changed number is unexplainable.

### 8. Period lock

Nothing stops last month's revenue changing after the decanter has reported it. Lock closed
periods, or require a reason recorded in the audit trail.

---

## Decisions outstanding

**Resolved 2026-08-04 — locked for prompt 28, do not re-open:**

1. **Cost excludes consumables: liquid only.** The derivation prices juice alone
   (`bottle_cost_mmk × size_ml / bottle_volume_ml`, ceiling division), and the naming
   carries the limit: `liquidCostMmk`, stat labelled "Gross margin (liquid only)" with the
   exclusions named in its description. The distortion stands as the reason the label
   matters — omitting vial, label, and spillage understates small-size cost far harder than
   large (roughly 11% on a 5ml versus under 3% on a 30ml), which skews precisely the size
   comparison in gap 6. Adding consumable fields later is a new decision, and it changes
   the stat's label.

**Open — blocks gap 6 (margin by size), deliberately NOT settled by prompt 28. No margin
step may settle this implicitly:**

2. **Per-line discount allocation** — proportional-with-remainder, or gross-of-discount.
   Prompt 28 computes margin at order level only, where the whole discount subtracts
   cleanly; the moment margin splits by line or by size, this decision is load-bearing.
   Decide it on its own, first.

**Needs the decanter, not code:**

3. Commercial tax registration. Retrofitting tax onto historical orders is painful; make the
   call consciously and record it in §8 even if the answer is "not registered."
4. Are bottles ever bought on credit? If yes, supplier payables becomes real.
5. Partial refunds on a delivered order, or only full refunds on cancellation?

---

## The fork: operational finance or real books

Everything above is **operational finance** — orders, payments, costs, margin, receivables.
It stops short of expenses, capital, drawings, and tax.

Adding an expenses table and a true P&L roughly doubles the build and adds a permanent daily
discipline: the P&L is only as true as the decanter's willingness to enter every expense, and
one that silently omits last month's packaging order is worse than none, because it gets
believed. Recommend keeping books outside the app, fed by a clean per-line-item export.

Revisit only if expense entry will genuinely happen daily.

---

## Deliberately out

Payment **gateway** and card/wallet processing — the exclusion is automated rails and
settlement, not the customer choosing to prepay: v14 shipped customer-chosen online prepay
by manual MMQR/transfer with the slip required at checkout, and that stays. Also out:
chargebacks, settlement and payouts, multi-currency, customer wallets and loyalty,
per-bottle tracking and batch identity, FIFO/weighted-average COGS, automated tax
calculation. Each is either excluded by §8 or presupposes infrastructure this shop doesn't
have.

---

## Testing, every phase

Aggregation-heavy work is exactly where the SQLite-versus-Postgres test gap bites, and it has
already hidden two real bugs. Every report query gets
`sh backend/scripts/verify-postgres-portability.sh`, plus an explicit test that cancelled and
rejected orders are absent from any sum or count. Cost must never leak to the public API or
the A5 invoice — pin that with a test, per the v12 precedent.