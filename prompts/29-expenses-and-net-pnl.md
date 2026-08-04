# Step 29 — Expenses and a monthly net P&L

> **Numbering:** 28 is on the open #71 branch (cost/margin); 24–26 stay reserved by open
> PR #58's renumber-on-merge and 27 by the local harness draft. This file takes **29**.
> Renumber per the CLAUDE.md collision rule if any of those land elsewhere.

**Prerequisite: #71 merged.** This step amends the same §8 bullet v19 rewrites and builds
on `liquidGrossMarginMmk()`; branch only off a `develop` that contains both (WORKFLOW's
branch-ordering rule). Follow the current `CLAUDE.md` and the `decant-money` skill.
**Backend only** — Filament panel + domain layer; nothing customer-facing.

## 0. Why this step exists — a chosen reversal

FINANCE.md's fork recommended keeping books outside the app, and the reason was never
effort: **a P&L missing expenses is worse than none, because it gets believed.** The
decanter has now chosen in-app books with that caveat presented (2026-08-04). So this
step builds expenses + a monthly net P&L — and carries the truth-discipline into the
design: every figure labels its coverage, and nothing silently pretends completeness.

## 1. Decisions locked — build to these

1. **Accrual-lite, one month at a time.** Income and COGS bucket by the order's
   `created_at` month (matching every existing figure); expenses bucket by their own
   `spent_on` date. No cash-vs-accrual toggle.
2. **Stock purchases are inventory, never an expense.** #71's margin already subtracts
   liquid COGS as vials pour; expensing bottle purchases too would count every bottle
   twice. The `stock_purchase` category is recorded for cash visibility but sits
   **below the line** on the P&L ("Cash into stock — becomes COGS as it pours"), and is
   excluded from operating expenses and from net profit. This rule goes in the
   decant-money skill.
3. **Coverage rides every subtotal.** COGS shows the fully-costed N-of-M exactly as the
   margin stat does; the expense block carries "as entered — a missing expense inflates
   net" verbatim. Net profit's label is honest: **"Net operating profit (liquid COGS;
   expenses as entered)"**.
4. **Integer Kyat throughout** (`amount_mmk` unsignedInteger-as-documentation, §7);
   no new rounding sites — every P&L line is a sum of stored integers.

## 2. Schema — one table, one enum

- `expenses`: `spent_on` (date), `category` (string ← `ExpenseCategory` backed enum),
  `amount_mmk` (integer, min 1 in the form), `note` (nullable string). Timestamps.
  No attachments/receipt uploads in this step.
- `ExpenseCategory` (PHP backed enum, §7): `stock_purchase` (Stock purchase — bottles),
  `packaging` (Vials, labels & packaging), `delivery` (Courier & delivery paid),
  `marketing` (Marketing & boosting), `fees` (Fees & subscriptions), `other` (Other).
  Labels + colors like the existing enums. If the decanter outgrows these, widening the
  enum is a migration-free code change — do not build custom categories now.

## 3. The P&L — one page, one month, honest lines

A Filament page **Profit & loss** in the **Finance** nav group (works with or without
#73's declared order), month picker with prev/next (the day-page stepping precedent),
defaulting to the current month. Lines, all §4-status-scoped (cancelled/rejected never
count) and computed in the domain layer (a `MonthlyPnl` support class or methods on a
small value object — not in the page class):

```
Sales income            Σ line_total − Σ discount   (orders created in month; = total − fee per order)
− COGS (liquid)         Σ line_cost over FULLY-costed orders    [coverage: on N of M orders]
= Gross margin (liquid only)
− Operating expenses    Σ expenses by category, stock_purchase EXCLUDED   [as entered]
+ Delivery result       fees collected (Σ delivery_fee_mmk) − courier paid (delivery category)
= Net operating profit (liquid COGS; expenses as entered)
——— below the line ———
Cash into stock         Σ stock_purchase            (inventory — becomes COGS as it pours)
Discounts given         Σ discount                  (already netted from income; shown for #75 continuity)
```

- **The delivery line closes FINANCE.md gap 4 at month level**: fees collected vs courier
  costs paid finally makes delivery margin *measured* — record that in FINANCE.md.
- **The COGS trap from #71 applies**: sum per-order `liquidGrossMarginMmk()` components
  over fully-costed orders only; never a bare `SUM(line_cost_mmk)`.
- Sales income is computed from line snapshots and discounts, **never `total_mmk`**
  (it contains the delivery fee — the #67/#71 rule).
- Expense months with no entries show the block as `0 Ks — nothing entered`, not blank:
  on a P&L, an empty expense month is a *claim*, and it should look like one.

## 4. Expense entry — CRUD that takes seconds

`ExpenseResource` in the Finance group: table (date desc, category badge, amount,
note), create/edit modals — `spent_on` defaults today, category required, amount
required min 1, note optional. Filters: category, this-month/last-month. A month-total
footer using the existing `Sum` summarizer pattern. No delete protection needed —
expenses reference nothing.

## 5. Deliberately not in this step

- Receipt/photo attachments on expenses; recurring expenses; budgets vs actuals.
- Tax (commercial tax stays FINANCE.md's decanter-question #3), drawings/capital,
  balance sheet — this is a P&L, not double-entry books.
- Consumables in COGS (vial/label/spillage — still Decision 1's future change).
- Per-line discount allocation (Decision 2 stays open; income nets the order-level sum).
- CSV export of expenses (add on ask; the P&L is the report).

## 6. Tests

- Enum round-trip + form validation (amount ≥ 1, category required, date required).
- P&L math on a seeded month: income from snapshots (a discounted order proves income =
  items − discount, not total); COGS coverage N-of-M with a partially-costed order
  contributing to M only; **stock_purchase excluded from operating expenses and net,
  present below the line** — the no-double-count pin; delivery result = fees collected −
  delivery-category expenses; net ties out to the Kyat.
- Month boundaries: an expense on the 1st and an order created at 23:59 on the last day
  land in the right month (date columns, no timezone math — the v17 lesson).
- §4: cancelled/rejected orders absent from income and COGS (assert absence).
- Cost never leaks: the P&L page is panel-auth'd (route test), and nothing new touches
  the public API.
- `sh backend/scripts/verify-postgres-portability.sh` — month bucketing + sums.

## 7. Docs — same branch (WORKFLOW step 5)

- **`CLAUDE.md` → v20**: §0 note (this file's §0 reasoning, condensed); **§8 amendment
  on top of v19's bullet** — expenses + net P&L move in scope as an explicitly-chosen
  reversal; still out: double-entry books, balance sheet, tax automation, budgets.
  §6 admin list gains the P&L page + expense CRUD.
- **`FINANCE.md`**: rewrite the fork section — the fork was taken, date and caveat
  recorded; gap 4 flips to *measured at month level* by the delivery line; the
  no-double-count rule recorded under Decisions.
- **`decant-money` skill §6**: add the stock-purchase/COGS no-double-count rule and the
  "expenses as entered" labeling rule.
- **`prompts/README.md`** row; root README admin-features bullet.

## 8. Verification before the PR

- `php artisan test` exits 0; portability script run.
- PR body: money figures affected — the new P&L lines and expense sums only; name why
  revenue, margin stat, totals, balances, and every public payload are untouched.
- PR against `develop`. Stop before merging.
