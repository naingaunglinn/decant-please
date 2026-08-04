---
name: decant-money
description: Money-handling rules for Decant Please — Myanmar Kyat integer arithmetic, server-side price derivation, promo code application, revenue aggregation, and invoice balances. Use this skill whenever a task touches prices, per-size decant pricing, cart totals, checkout, promo codes, order items, the revenue dashboard, CSV export, or packing invoices — including changes that look purely cosmetic, like formatting a price or adding a column to an export, since those are exactly where currency bugs hide.
---

# Money in Decant Please

Every figure in this project is Myanmar Kyat. Getting money wrong here is not a rendering
bug: the order history is the decanter's only financial record, so a wrong number becomes a
wrong receipt handed to a customer and a wrong revenue figure in their books.

## 1. Kyat is a whole-number currency

MMK is stored and calculated as **integer whole Kyat**. There is no minor unit in
circulation — no cents, no ×100 scaling. `65000` means 65,000 Ks, not 650.00 Ks.

This is worth stating explicitly because most money-handling guidance assumes a
two-decimal currency and instructs you to store minor units. Do not apply that reflex here.

- Never introduce `float` or `double` for a money value — not in PHP, not in TypeScript,
  not as a Postgres column type. Integer columns, integer casts, integer arithmetic.
- Display format is thousands separators plus the `Ks` suffix: `65,000 Ks`. Formatting
  belongs at the presentation edge. Never store or transmit a formatted string as a number,
  and never parse one back into a number.
- When a calculation cannot land on an integer (percentage discounts, proportional splits),
  the rounding rule must be explicit and applied once, at a named place — not implicitly by
  a cast somewhere downstream. Find the existing rule before introducing a second one;
  two rounding sites that disagree by 1 Ks will surface as a receipt that doesn't add up.

## 2. Prices come from the server, always

Checkout accepts only `fragrance_id`, `size_ml`, and `quantity`. The server re-derives every
price from the current catalog and writes immutable snapshots onto the order items.

Both halves of that need preserving:

- **Never** widen a checkout or promo endpoint to accept a price, subtotal, discount, or
  total from the client, however much simpler it makes the frontend. Any such field is a way
  to buy a 65,000 Ks decant for 1 Ks.
- **Never** make an order's stored money fields recompute from the live catalog. The
  snapshot exists so a receipt reprinted months later still says what the customer actually
  paid. If a task needs a historical figure, read the snapshot; if it needs today's price,
  read the catalog. Do not blur the two.

When adding a money field to an order, decide explicitly which of those two it is, and
record that decision in the migration or the model.

## 3. Promo codes

Codes are percent or fixed, carry caps, minimums, and usage limits, and are re-validated
atomically at submission after a live preview at checkout.

- The checkout preview is advisory. Validity is whatever the submission-time check says.
  Never store the previewed discount — recompute it inside the same transaction that claims
  the usage.
- Apply in a fixed order: subtotal → discount (percent or fixed) → cap → floor at zero.
  A discount must never exceed the subtotal or drive a total negative.
- A usage claim and the order that caused it are one fact. They belong in one transaction.
  A code showing fewer uses than there are orders naming it is a concurrency bug, not a
  rounding artifact — and it means a limited code can overspend.
- Decide what cancelled and rejected orders do to a claimed usage, and keep that consistent
  with how those statuses are treated in revenue (§4). Leaving it unstated is how a
  ten-use code quietly serves fifteen customers.

## 4. Revenue excludes cancelled and rejected

Cancelled and rejected orders are excluded from all revenue figures and from the production
schedule. That is one rule with many call sites: monthly revenue, orders-by-status, decants
due today, top fragrances, CSV export, and every aggregate added later.

Whenever you write a query that sums or counts money, state which statuses it includes and
match the existing exclusion. A new dashboard card that silently counts a rejected order
inflates the month, and nobody notices until the decanter compares it to their bank.

The lifecycle is `awaiting_confirmation → pending → decanted → delivered`, with `rejected`
and `cancelled` as terminals. If a change introduces a status, updating the exclusion lists
is part of that change, not follow-up work.

## 5. Invoices and balance due

A5 packing invoices emphasise a balance-due figure and bundle a Myanmar-script font so
Burmese names and addresses render.

- An invoice total must equal the stored order snapshot to the Kyat. If the invoice and the
  tracking page can ever disagree, they are reading different sources — fix the source,
  not the template.
- Payment is bank transfer, mobile banking, or cash on delivery, confirmed by hand. There is
  no gateway and no payment record to reconcile against, which is exactly why balance due
  must be arithmetically derivable from the order and never hand-entered.
- Keep the font bundled. A missing glyph on a printed invoice is a delivery failure, not a
  typography nit.

## 6. Cost and margin

v19 (step 28) tracks a reference bottle cost per fragrance and snapshots liquid-only
costs onto order items. The figure is deliberately partial, and four rules keep it honest:

- **Null means unknown, never zero.** Legacy items stay null forever — no backfill; a
  zero-cost line is a 100%-margin lie. Aggregates exclude *and count* unknowns ("on N
  of M orders"), and an order's margin exists only when **every** line has a cost — a
  partial cost sum understates cost silently, so never `SUM(line_cost_mmk)` across
  orders that might be partially costed.
- **The cost rounding rule is CEILING** (`Fragrance::liquidCostMmk()`), deliberately
  opposite in direction to the promo floor: flooring a discount can only make the shop
  keep more, while flooring a cost would understate cost and flatter every margin
  figure. Ceiling errs by at most 1 Ks per vial, conservative. Two rules now exist —
  do not add a third without the same direction-of-safety argument.
- **The delivery fee is on neither side of the margin** — it is courier pass-through,
  *unmeasured, not zero* (FINANCE.md gap 4). Never compute margin off `total_mmk`,
  which contains that fee.
- **"Liquid only" rides the label everywhere the figure surfaces** — vial, label, and
  spillage are not in this number, and omitting them understates small sizes hardest.
  Cost is admin-eyes only: never the public API, the A5 invoice, or the fragrances
  CSV export.
- **Stock purchases are inventory, never an operating expense** (v20): the margin
  already expenses juice as COGS when it pours — expensing the bottle too counts it
  twice. The rule lives in `ExpenseCategory::isOperating()`; the P&L shows stock cash
  below the line, and delivery is subtracted exactly once, inside its own result line.
- **A net figure names its own limits.** "Net operating profit (liquid COGS; expenses
  as entered)" — the label is load-bearing: expenses sum only what was entered, and a
  P&L that looks complete gets believed.

## 7. Before finishing any money change

- Add or extend a test in the backend suite, which already covers domain, admin, invoices,
  and the API. An untested money path is one the next refactor will silently break.
- The suite runs on SQLite while production runs PostgreSQL, and that gap has already hidden
  real bugs (a case-sensitive `LIKE`, a select alias in `ORDER BY`). Anything involving
  aggregation, casting, or SQL ordering gets checked against the real engine:

  ```
  sh backend/scripts/verify-postgres-portability.sh
  ```

- In the change summary, name which money figures the change can affect and why each one
  stays correct. If that list is hard to write, the change is touching more than it should.

## Out of scope

Online payment *gateways*, per-bottle inventory (batch identity, FIFO/weighted-average
COGS — the fragrance-level reference cost and liquid-only margin are in as of v19), and
a multi-decanter marketplace are deliberately excluded from this project. `CLAUDE.md` §8
is the gate — read it before proposing anything that adds a payment rail, a second
seller's money, or a fuller costing model to the schema.
