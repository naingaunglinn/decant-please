# Step 28 — Bottle cost and liquid-only gross margin

> **Numbering:** verified 2026-08-04. `23` is committed (the calendar). Open PR #58's specs
> are authored as 23–25 and renumber to **24–26** when it merges (it now merges second —
> the CLAUDE.md v17 §0 collision rule); the local harness draft (authored as 22, the same
> collision) lands at **27**. This file takes **28**. If any of those land elsewhere,
> renumber per the same rule. Check `prompts/README.md` before committing.

Follow the current `CLAUDE.md` (v18 at drafting) and the `decant-money` skill. **Backend
only** — domain layer + Filament panel; nothing customer-facing changes, and §6 pins that.
`FINANCE.md` is the roadmap this implements (its "In flight" entry); its Decision 1 is
resolved and locked below, its Decision 2 is deliberately left open and untouched (§7).

## 0. Why this step exists

Every order stores what the customer pays, to the Kyat, immutably. Nothing anywhere stores
what the juice cost, so margin is invisible — the decanter prices by feel and reads revenue
as if it were profit. §8 excluded "cost/margin accounting" until explicitly asked; this
step is that ask, executed as a scoped reversal in the v8/v13 pattern: a reference cost and
one honest margin figure come in, real accounting (expenses, ledgers, tax) stays out.

## 1. Decisions locked — build to these, do not re-open them

1. **Cost is liquid only.** `bottle_cost_mmk × size_ml / bottle_volume_ml`, ceiling
   division, one named derivation site: `Fragrance::liquidCostMmk()`. The name is the
   contract: vial, label, and spillage are not in this number. (They understate small-size
   cost hardest — roughly 11% on a 5ml versus under 3% on a 30ml, `FINANCE.md` Decision 1 —
   which is why the *label* carries the limit everywhere the figure surfaces.)
2. **The stat is "Gross margin (liquid only)", never "Profit".** Its description names the
   exclusions — vial, label, spillage, delivery — and the coverage. The name alone is not
   enough.
3. **Delivery fee is excluded from both sides** of the margin, and delivery margin is
   documented as *unmeasured, not zero* (`FINANCE.md` gap 4): `delivery_fee_mmk` records
   what the customer was charged, nothing records what the courier was paid, and
   `total_mmk` includes the fee (`recalculateTotal()`, `Order.php:391`) — so a margin
   computed off `total_mmk` would book courier cash as margin. Compute from line items and
   the discount, never from `total_mmk`.
4. **Legacy order items stay null permanently.** No backfill command, ever — a snapshot
   backfilled from today's reference cost is fiction wearing a snapshot's clothes. Null is
   **excluded and counted**, never coalesced to zero: a zero-cost line is a 100%-margin lie.

## 2. Schema

Two migrations. Non-negativity is application-layer (§7 — Postgres silently drops
`unsignedInteger`'s constraint); enforce it in the forms. Record the snapshot-versus-live
decision in each migration's doc comment, per the `decant-money` skill §2.

- `fragrances`: `bottle_cost_mmk` (nullable integer — what one bottle costs) and
  `bottle_volume_ml` (nullable integer ≥ 1 — the pack size the decanter buys). **Live
  reference values**, updated by hand on a rebuy. Both-or-neither, validated in the form.
  Independent of the `stock_ml` opt-in: cost tracking and volume tracking are separate
  switches, and either works without the other.
- `order_items`: `unit_cost_mmk` and `line_cost_mmk` (nullable integers). **Immutable
  snapshots**, mirroring `unit_price_mmk`/`line_total_mmk` exactly.

This does not cross §8's bottle-volume-inventory line: `bottle_volume_ml` is a static
attribute of the pack the decanter buys — a denominator, one value per fragrance, no rows,
no `remaining_ml`, nothing that moves on drawdown. Branch `40-decant-bottle-stock` stays
the per-bottle reference; it contains no cost fields (verified — its `bottles` table is
`total_ml`/`remaining_ml`/`opened_at`/`is_active`).

## 3. Derivation — one named site, one new rounding rule

`Fragrance::liquidCostMmk(int $sizeMl): ?int`. Null unless both reference fields are set.
Otherwise pure-integer ceiling division — no float ever touches the value:

```php
intdiv($this->bottle_cost_mmk * $sizeMl + $this->bottle_volume_ml - 1, $this->bottle_volume_ml)
```

This is deliberately the project's **second** rounding rule, beside `PromoCode.php:60`'s
floor, and the comment at the site must say why the directions differ: flooring a discount
can only make the shop keep more; flooring a cost would understate cost and flatter every
margin figure. Ceiling overstates cost by at most 1 Ks per vial — margin errs conservative.

## 4. The snapshot — cost behaves exactly like price

Price today: checkout writes `unit_price_mmk` server-side (`Order.php:121`), the admin form
auto-fills it on fragrance pick (`OrderForm.php:256`), and `OrderItem::saving` derives
`line_total_mmk` (`OrderItem.php:15`). Cost mirrors all three:

- A **creating-only** fill in `OrderItem` (guard: `! $item->exists`): if `unit_cost_mmk` is
  null at creation, snapshot `fragrance->liquidCostMmk(size_ml)`. One site covers checkout,
  manual admin orders, and a line added to an old order later (which correctly gets
  *today's* cost — the pour happens now). A re-save must never refresh it — that is the
  skill's §2 immutability, and the difference between a snapshot and a cache.
- The same `saving` hook derives `line_cost_mmk = unit_cost_mmk × quantity`,
  **null-propagating** — a quantity edit re-derives from the *stored* unit cost, and a null
  unit cost yields a null line cost, never 0.
- The items repeater shows `unit_cost_mmk` beside `unit_price_mmk`, auto-filled on
  fragrance pick like price is, hand-correctable (the `discount_mmk` precedent; the missing
  audit trail on such edits is `FINANCE.md` gap 7, out of scope here).

Accepted limit, documented in the CLAUDE.md note (v8's accepted-limits register): a rebuy
between order creation and the pour isn't reflected in that order's cost.

## 5. Where margin surfaces — and the aggregation trap

`Order::liquidGrossMarginMmk(): ?int` in the domain layer (the v8 placement rule — logic in
the model, not Filament):

```
Σ line_total_mmk − discount_mmk − Σ line_cost_mmk        // delivery fee on neither side
```

**Null unless every line has a cost.** A partially-costed order reports unknown — a partial
cost sum understates cost silently.

- **`OrderStats`**: "Gross margin (liquid only)" beside "Revenue this month" — same window
  (`created_at` ≥ start of month) and the same status exclusion as its neighbour
  (`OrderStats.php:17`), *including* its counting of `awaiting_confirmation`; consistency
  between adjacent stats beats relitigating the window. The description carries: excludes
  vial, label, spillage & delivery — on **N of M** orders (N = fully-costed, M = all orders
  in the window passing the status filter).
- **The trap, spelled out:** the month figure is the sum of `liquidGrossMarginMmk()` over
  fully-costed orders only. A naive `SUM(line_cost_mmk)` would silently include the
  non-null lines of partially-costed orders and overstate margin. Do not write that query.
- **CSV export** (`OrdersTable.php:126`): `Cost (Ks)` and `Gross margin (Ks)` columns after
  `Balance due (Ks)` — blank when unknown, never 0.
- **`FragranceForm`**: a Cost section (the pair) beside the stock fields.
  **`FragrancesTable`**: a cost-per-ml column, toggleable, hidden by default.
- **`OrderForm`**: the order's margin — or "unknown (n lines uncosted)" — in the financial
  section, admin-eyes only.

## 6. Never leaks

Cost never crosses the public API — not the tracking payload (`TrackOrderController`), not
the catalog resources, not `/meta` — and never prints on the A5 invoice, which is handed to
the customer. Pin both with tests in the v12 style (the `has_payment_proof`-only precedent).

## 7. Deliberately not in this step

- **Per-line discount allocation** (`FINANCE.md` Decision 2) — margin is order-level here,
  where the whole discount subtracts cleanly. Margin-by-size (gap 6) waits on that decision
  being made *on its own*; nothing in this step may settle it implicitly.
- Consumables fields (vial/label/spillage) — a future decision, and it changes the stat's
  label when made.
- Per-bottle tracking, batch identity, FIFO/weighted-average COGS (§8, unchanged).
- Accept-modal margin, a `TopFragrances` margin column, a `RevenueChart` margin series, a
  `LowStock` reorder value — natural follow-ups, all out.
- Any backfill (locked, §1.4).

## 8. Tests

- `liquidCostMmk`: exact division, ceiling on remainder, `volume = 1`, null when either
  reference field is unset; the both-or-neither form validation.
- Snapshot written on checkout and on admin create; a re-save of an existing item does
  **not** refresh it after the reference cost changes; a quantity edit re-derives
  `line_cost_mmk` from the stored unit cost.
- Legacy items: null stays null through unrelated saves.
- `liquidGrossMarginMmk`: a fully-costed order with a discount; a partially-costed order →
  null; the delivery fee moves neither side.
- The month stat: excludes cancelled and rejected (assert their absence explicitly — the
  `FINANCE.md` testing rule), counts a partially-costed order in M but not N, and its sum
  ignores that order's non-null lines entirely.
- CSV: blank cost/margin cells for unknown, never `0`.
- No-leak pins: the tracking payload, the catalog resource, and the invoice HTML contain no
  cost field.
- `sh backend/scripts/verify-postgres-portability.sh` — aggregation is exactly where the
  SQLite/Postgres gap has already hidden two real bugs.

## 9. Docs — same branch, not a follow-up (WORKFLOW step 5)

- **`CLAUDE.md` → v19**: changelog note; §6.5 gains the stat; the **§8 v8-bullet
  amendment** — in scope now: reference-cost, liquid-only margin visibility (this step);
  still out: per-bottle tracking, batch identity, FIFO/weighted-average COGS, consumables
  costing (undecided), expenses/ledger/tax; `in_stock` stays manual. The **§0.1-v8
  narrowing** — branch 40 is the reference for per-bottle/batch needs *only*; cost/margin
  landed fragrance-level, and the branch contains no cost fields.
- **`decant-money` skill**: null-never-zero; the ceiling rule and why its direction differs
  from the promo floor; fully-costed-orders-only margin; the delivery-fee exclusion
  (unmeasured, not zero); its Out-of-scope line inherits §8's redraw.
- **`FINANCE.md`**: move "Cost and margin" from In flight to built; Decision 1 stays
  recorded as resolved.
- **`prompts/README.md`** — row for this step. Root **`README.md`** — step-table row,
  flipped to built.

## 10. Verification before the PR

- `php artisan test` exits 0; the portability script has been run for the new aggregates.
- The PR body names which money figures the change can affect (the new ones only) and why
  every existing figure — `total_mmk`, `balanceDue()`, revenue, owed, invoice totals, every
  public payload — is provably untouched: no existing column, hook, or formula is edited.
- PR against `develop`. Stop before merging.
