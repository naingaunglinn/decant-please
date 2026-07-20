# Step 18 — Real-time decant stock, tracked per bottle

Follow the current `CLAUDE.md`. Work in `backend/` only.

## 0. What's actually there today

`decant_prices.in_stock` is a boolean the decanter flips by hand via a `Toggle` on
the Fragrance form. It gates what customers can order (`FragranceController`,
`Order::currentPriceFor`, `Fragrance::minPrice`, `MetaController` all filter on
it) — but nothing ever *sets* it automatically. `OrderItem`'s `saving()` hook only
computes `line_total_mmk`; placing, accepting, or fulfilling an order has zero
effect on stock. The toggle is a memory aid, not a system.

This step replaces "the decanter remembers to click a switch" with "the system
knows how much is physically left in the bottle currently being poured from,"
matching the selling point verbatim: *ဘူးကြီးထဲမှာ 50ml ပဲ ကျန်တော့တာ၊ 10ml Decant
၅ ဘူး ဝယ်လိုက်ရင် Stock အလိုလို ကျဆွားမယ်* — buy five 10ml decants from a bottle
with 50ml left, the remaining volume drops on its own.

## 1. New table: `bottles`

```php
Schema::create('bottles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fragrance_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('total_ml');
    $table->unsignedInteger('remaining_ml');
    $table->date('opened_at');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

One active bottle per fragrance at a time — enforced in application logic, not a
DB constraint (deactivating the previous active bottle is one line wherever a new
one is created, and a partial unique index adds Postgres-specific syntax for
marginal benefit here). `Bottle belongsTo Fragrance`; `Fragrance hasMany Bottle`
plus an `activeBottle(): HasOne` (`where('is_active', true)`) for the common case.

## 2. Automatic `in_stock`, not a manual toggle

Add `Fragrance::syncStockFromBottle(): void` (or similar) — for each of the
fragrance's `decantPrices`, set `in_stock = $activeBottle && $activeBottle->remaining_ml >= $price->size_ml`.
Call this every time `remaining_ml` changes (right after the accept-time decrement
in §3, and after logging a new bottle in §4).

**Graceful migration, this matters**: a fragrance with **no** active bottle should
be left completely alone — whatever `in_stock` already holds keeps working exactly
as it does today. This sync only ever engages once a bottle has actually been
logged for that fragrance. Existing catalog data (seeded or real) has no bottles
yet, and nothing here should make every fragrance suddenly read as out of stock
the moment this deploys.

Remove the manual `Toggle::make('in_stock')` from `FragranceForm` (or make it a
disabled/read-only display of the computed value) — leaving it editable would let
an admin's manual click get silently overwritten the next time stock changes,
which is a worse experience than the toggle being gone entirely.
**(Amended during implementation:)** removing it outright would contradict the
graceful-migration rule two paragraphs up — an *untracked* fragrance's `in_stock`
is still manual and needs the toggle to be editable. So it's conditional: disabled
(with a "managed automatically" hint) only once the fragrance has an active
bottle, still a normal toggle before that. `EditFragrance::afterSave()` also
resyncs, so a size added to a tracked fragrance is computed immediately instead
of sitting at the column default until the next pour.

## 3. Decrement at `accept()`, with a real availability guard

`Order::accept()` currently just sets dates and flips status. Add, inside the same
save:

- For each `OrderItem` on the order, if its fragrance has an active bottle, check
  `remaining_ml >= size_ml * quantity` for *all* items **before** committing any
  decrement — if any item fails, throw (a `LogicException` matching the existing
  style, or a `ValidationException` if you want the message to reach Filament's
  form the same way checkout's `unavailableItemMessage` does) with a specific
  message: fragrance name, how much is left, how much this order needs. Don't
  decrement some items and then fail on a later one — check everything first,
  then apply.
- If all items pass, decrement each fragrance's active bottle's `remaining_ml` by
  `size_ml * quantity`, then call `syncStockFromBottle()` for each affected
  fragrance.
- Items whose fragrance has no active bottle are skipped entirely (per §2's
  migration rule) — accepting still works exactly as it does today for anything
  not yet using this system.
- Wrap this in the same transaction as the rest of `accept()` — mirror
  `newFromCheckout`'s `DB::transaction()` pattern already established in this
  model.

**(Amended during implementation:)** it's a `ValidationException`, but Filament
can't render an error keyed `items` in an accept modal whose only fields are two
dates — so `acceptAction()` catches it and surfaces the message as a persistent
danger notification instead. Also worth knowing: `accept()` is the *only* pour
point, so manual order entry (which starts at `pending`) and the order edit
form's raw status select bypass the decrement entirely. That's consistent with
this spec's scope, flagged here so it reads as a decision, not a hole.

This is a real, user-facing behavior change worth being deliberate about: an
`acceptAction()` that always succeeded today can now legitimately fail with a
clear reason. That's the point — it's the same protection `PromoCode::evaluate()`
already gives against double-spending a limited-use code, applied to physical
stock instead of a promo counter. Consider `lockForUpdate()` on the bottle row
while checking-and-decrementing, the same defensive pattern already used there,
even though a single-admin panel makes a real race unlikely today.

## 4. Admin UI

- **On the Fragrance edit page**: a `BottlesRelationManager` (or equivalent) —
  list past bottles (total, remaining, opened date, active/not), with a **"Log new
  bottle"** action taking `total_ml` and `opened_at`. That action: deactivates the
  fragrance's current active bottle (if any), creates the new one with
  `remaining_ml = total_ml`, and calls `syncStockFromBottle()`.
- **On `FragrancesTable`**: add a stock column next to the existing
  `min_in_stock_price` one — something like `42 / 100 ml` for a fragrance with an
  active bottle, and a clear "No bottle logged" state for one without, so the
  gap from §2 is visible to the admin, not silent.

## 5. Human step after this deploys — not automatable

Every existing fragrance has no bottle at all. Someone has to walk the real
catalog and log each active fragrance's actual current remaining volume by hand —
this is real-world knowledge only the decanter has, not something a migration can
backfill or Claude Code can guess. Until a fragrance gets its first bottle logged,
it keeps behaving exactly as it does today (§2's migration rule) — this can happen
gradually, fragrance by fragrance, rather than all at once before the feature is
usable at all.

## 6. Deliberately not building now

- **Which bottle an `OrderItem` was poured from** (a `bottle_id` FK on
  `order_items`) — a real audit-trail idea, genuinely useful for reordering/margin
  questions later, but not needed to solve "stock doesn't update automatically."
  Flagging as a trade-off, not an oversight.
- **Low-stock alerts** (a dashboard widget or notification when a bottle drops
  below some threshold) — a natural complement to this, but a separate, smaller
  step once this lands and the numbers are actually flowing.
- **Cross-checking the Production Schedule page against bottle stock** (warning
  if today's scheduled decants exceed what's physically on hand) — valuable, but
  its own step; this one is scoped to making the number correct and automatic, not
  to every place that number could usefully appear.

## Guardrails

- Nothing here changes `reject()` or `cancel()` — both are still only reachable
  from `AwaitingConfirmation`, which is *before* `accept()` ever touches stock, so
  neither needs to restore anything. Don't add a restore path that has no code
  path to trigger it.
- A fragrance with no active bottle must never be treated as "0 remaining" —
  that's a different state from "not tracked yet," and conflating them is exactly
  the bug that would make this migration land badly.

## Verify

- Log a bottle for a real fragrance, accept an order that uses less than what's
  left, confirm `remaining_ml` drops by exactly `size_ml * quantity` and
  `in_stock` recomputes correctly for every size on that fragrance (including
  sizes now too big for what's left).
- Try to accept an order that needs more than `remaining_ml` covers — confirm it
  fails with a specific, readable message and that *nothing* was decremented
  (check a sibling item on a different, well-stocked fragrance in the same order
  wasn't partially applied).
- Confirm a fragrance with no bottle logged behaves identically to production
  today — accept an order for it, nothing about `in_stock` changes unexpectedly.
- Log a second bottle for a fragrance that already has an active one — confirm
  the first deactivates and the new one's `remaining_ml` starts fresh at its own
  `total_ml`, not added to whatever was left of the old one.
- Check `FragrancesTable`'s new stock column renders sensibly for both states
  (has a bottle / doesn't yet).

Report results with a checklist, same as every prior step.
