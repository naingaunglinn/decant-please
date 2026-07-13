# Step 4 — Filament order management (review, fulfillment & scheduling) — v2

Work in `backend/`. Follow `CLAUDE.md` v2. Orders now arrive two ways: **customers
check out on the website** (land as `awaiting_confirmation`, need a decision), and
**the decanter still logs one manually** for a customer who DMs instead (lands
directly as `pending`, since typing it in is the acceptance). Both flow through the
same `OrderResource` from here on.

## 1. OrderResource (navigation group "Sales")

### Form — unchanged from v1, with one addition
Customer section, Schedule section, Items repeater, Financials section all as in v1
(customer_name/phone/address/order_from; decant_date/delivery_date/status; the
`items` repeater with fragrance/size/auto-filled-but-editable unit price/quantity;
delivery_fee/discount/deposit with live total and balance due).

**Addition:** when `status = rejected`, show a read-only `rejection_reason` field.
When `status = awaiting_confirmation`, hide the decant_date/delivery_date inputs from
the regular form — those get set via the Accept action below, not typed directly,
so there's one place the transition happens.

### New: Accept / Reject actions

Two table + header actions, visible only when `status = awaiting_confirmation`:

- **Accept** — opens a small form: `decant_date` (required, default tomorrow),
  `delivery_date` (required, must be ≥ decant_date, default decant_date + 1 day).
  On submit, call `$order->accept($decantDate, $deliveryDate)` (the model method from
  Step 2) rather than setting fields directly. Success notification: "Order accepted
  — added to the decant schedule."
- **Reject** — opens a form: `rejection_reason` (select: Out of stock / Unreachable
  address / Duplicate order / Other, with a free-text field when "Other" is picked).
  On submit, call `$order->reject($reason)`. Success notification: "Order rejected."
  This does **not** delete the order — it stays visible under a "Rejected" tab for
  the decanter's own record-keeping.

Both actions need a confirmation step (Filament's built-in `requiresConfirmation()`)
since they're one-way in practice (a rejected order can still be manually
re-opened by an admin editing status directly, but that's a deliberate override, not
the guided path).

### Table
Same columns as v1 (id, customer_name + phone, order_from badge, items summary,
decant_date, delivery_date, status badge, total with sum summarizer, created_at),
plus:
- A `tracking_code` column (toggleable, monospace, copyable) — useful when a customer
  calls in referencing their code.
- Status badge colors now cover all six states per the enum's `color()` helper.

### List page tabs — reordered
**Needs review** (badge count, `awaiting_confirmation` only — this is now the
**default/first tab**, since new website orders are time-sensitive and shouldn't get
lost among older ones) · All · Today's Decants · Today's Deliveries · Pending ·
Decanted · Delivered · Rejected · Cancelled.

`"Today's Decants"` = `decant_date = today && status not in (cancelled, rejected)` —
unchanged logic, just excluding one more terminal-negative status than before.

## 2. New: Production Schedule page

A dedicated Filament page (not a Resource, not just a filtered table) — the
"automatically generate a schedule" feature from the brief. Order lists tell the
decanter *which orders* are due; this page tells them *what to physically prepare*.

- Date range picker, default: today through the next 7 days.
- For each day in range with at least one order whose `decant_date` falls on it
  (excluding `cancelled`/`rejected`), show:
  - the day's date as a heading,
  - a table grouped by **fragrance + size_ml**, summed `quantity` across that day's
    order items — e.g. "Chanel — Allure Homme Sport, 10ml — × 3" — so the decanter
    knows exactly how many bottles to pull and how many vials to fill, not just a
    list of orders,
  - each group expandable (or linked) to the underlying orders that make it up.
- Empty days show "Nothing to decant" rather than being omitted, so a quiet day still
  reads as confirmed-empty rather than possibly-broken.

Query this with a single eager-loaded pass over `OrderItem` (join `Order` on
`decant_date` in range and status filter, join `Fragrance` for display names) — group
in the query or in PHP after loading, whichever keeps N+1 away; don't loop and query
per fragrance.

## 3. Dashboard widgets (Filament panel dashboard)

1. **Stats overview:** Revenue this month (sum of non-cancelled, non-rejected
   totals), Orders this month, **Awaiting confirmation** (new — should read as
   slightly urgent, e.g. the warning color), Decants due today.
2. **Revenue chart:** last 30 days, daily totals (excluding cancelled/rejected).
3. **Top fragrances table:** top 5 by order-item quantity in last 30 days.
4. **Upcoming decants table:** next 7 days, ordered by decant_date — this can now
   simply link to the Production Schedule page instead of duplicating its logic.

## 4. Guardrails

- Cancelled **and rejected** orders are excluded from all revenue math (rejected
  orders never actually happened, financially).
- Deleting orders: soft-guard with confirmation; order items cascade.
- Fragrances used in orders cannot be hard-deleted (FK restrict from Step 2) — catch
  and show a friendly notification suggesting "deactivate instead."
- An order can only be Accepted or Rejected from `awaiting_confirmation` — don't
  expose those actions on any other status, so the state machine can't be entered
  sideways from the UI (a manual status-field edit is still possible for the rare
  override, which is fine — that's an explicit admin decision, not a guided flow).

## 5. Verify

- Place a checkout-style order (via tinker or the Step 5 endpoint once it exists) and
  confirm it lands in "Needs review" as `awaiting_confirmation` with a generated
  `tracking_code`.
- Accept it: confirm `decant_date`/`delivery_date` get set, status flips to `pending`,
  and it now appears on the Production Schedule for that date.
- Reject a different one: confirm `rejection_reason` is stored and it's excluded from
  revenue widgets.
- Create a manual order via the admin form: confirm it starts at `pending` directly
  (no review step) and requires `decant_date` at creation.
- Change a catalog price after any of the above: confirm existing orders' financials
  are unchanged (snapshot rule still holds).
- Check all tabs, both new actions, the Production Schedule page, and all dashboard
  widgets render correctly with seeded data. Report with a checklist. The public API
  is Step 5.
