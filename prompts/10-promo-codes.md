# Step 10 — Promo / discount codes at checkout

Work across `backend/` and `frontend/`. Follow `CLAUDE.md` v4. `orders.discount_mmk`
already exists but today it's admin-only, applied by the decanter after the fact in
Filament. This step lets a *customer* trigger a discount themselves at checkout via a
code, without touching the decanter's existing ability to adjust `discount_mmk`
manually on any order afterward — that stays exactly as it is.

## 1. Migrations

### `promo_codes`
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| code | string, unique, indexed | stored uppercase, matched case-insensitively |
| type | string | `percent` \| `fixed` (backed enum, like the rest of the project) |
| value | unsignedInteger | percent: 1-100; fixed: whole Kyat |
| max_discount_mmk | unsignedInteger nullable | caps a `percent` code's payout on a large cart; ignored for `fixed` |
| min_order_mmk | unsignedInteger nullable | subtotal must meet this to qualify |
| usage_limit | unsignedInteger nullable | null = unlimited |
| times_used | unsignedInteger default 0 | incremented atomically on redemption, see §3 |
| starts_at | date nullable | |
| expires_at | date nullable | |
| is_active | boolean default true | |
| timestamps | | |

### `orders` — one additive column
| column | type | notes |
|---|---|---|
| promo_code | string nullable | snapshot of which code was used, if any — for the receipt and the decanter's own records; editing `discount_mmk` later doesn't touch this, same snapshot spirit as `unit_price_mmk` on order items |

One code per order — no stacking. Keep the rule set flat; don't add customer-specific
codes, first-order-only logic, or per-fragrance restrictions unless asked.

## 2. `PromoCode` model

- `label(): string` on the type enum ("percent", "fixed") for Filament badges.
- `scope active()` — `is_active` and within `starts_at`/`expires_at` if set.
- A single shared validation method both the preview endpoint and checkout go
  through — don't duplicate this logic in two places:

```php
/**
 * @return array{valid: bool, discount_mmk: int, message: ?string}
 */
public static function evaluate(string $code, int $subtotalMmk): array
{
    $promo = self::query()->active()->whereRaw('UPPER(code) = ?', [Str::upper(trim($code))])->first();

    if (! $promo) {
        return ['valid' => false, 'discount_mmk' => 0, 'message' => "We couldn't find that code."];
    }
    if ($promo->usage_limit !== null && $promo->times_used >= $promo->usage_limit) {
        return ['valid' => false, 'discount_mmk' => 0, 'message' => 'That code has reached its usage limit.'];
    }
    if ($promo->min_order_mmk && $subtotalMmk < $promo->min_order_mmk) {
        return ['valid' => false, 'discount_mmk' => 0, 'message' => "This code needs an order of at least " . Money::kyat($promo->min_order_mmk) . "."];
    }

    $discount = $promo->type === PromoType::Percent
        ? (int) floor($subtotalMmk * $promo->value / 100)
        : $promo->value;

    if ($promo->type === PromoType::Percent && $promo->max_discount_mmk) {
        $discount = min($discount, $promo->max_discount_mmk);
    }

    return ['valid' => true, 'discount_mmk' => min($discount, $subtotalMmk), 'message' => null];
}
```

(Sketch, not gospel — adapt to match the project's actual enum/import conventions,
but keep the single-evaluate-method shape so preview and checkout can't disagree.)

## 3. Two endpoints, same evaluation, different side effects

### `POST /orders/validate-promo` — preview only, nothing persisted
Body: `{ code, items: [{ fragrance_id, size_ml, quantity }] }`. Re-derive the subtotal
server-side from current catalog prices (same pattern as checkout — never trust a
client-sent subtotal), call `PromoCode::evaluate()`, return
`{ valid, discount_mmk, discount_formatted, new_total_formatted, message }`. This is
what powers the checkout page's "Apply" button and live-updates the total *before*
the customer commits to placing the order. Same rate-limit tier as the other public
write-ish endpoints.

### `POST /orders` (existing checkout endpoint) — add an optional `promo_code` field
Inside `Order::newFromCheckout`'s existing `DB::transaction`, if `promo_code` is
present: re-run `PromoCode::evaluate()` against the freshly-computed subtotal
(**don't** trust whatever the preview call returned — a limited-use code could be
exhausted by someone else in between), and if still valid, `lockForUpdate()` the
`PromoCode` row, increment `times_used`, and set the order's `discount_mmk` and
`promo_code` snapshot. If the code stopped being valid between preview and submit
(exhausted, expired), don't fail the whole order — drop the discount, set
`discount_mmk = 0`, leave `promo_code` null, and say so in the response
(`"promo_note": "That code was no longer valid, so it wasn't applied — you can still place this order without it."`)
rather than blocking a real order over a coupon that just ran out.

## 4. Frontend — lives on `OrderSummaryCard`

Add a code input + "Apply" button directly under the existing Subtotal line in
`OrderSummaryCard` (the component already shown to you — `useCart()`'s `subtotal`,
`formatKyat`). On Apply, call `validate-promo` with the current cart lines; on
success show a dismissible "Promo code SAVE10 applied — −10,000 Ks" row between
Subtotal and the existing "final total confirmed by decanter" note, and pass the
code along with the final `createOrder()` call in `CheckoutClient`. On failure, show
the returned message inline under the input — don't invent a generic "invalid code"
string when the backend already says exactly what's wrong. If checkout's response
comes back with `promo_note` set (code lapsed between preview and submit), surface
that on the Order Complete page rather than silently dropping it.

Extend `OrderReceipt` (from Step 8) to show the applied promo code by name in the
payment summary when `orders.promo_code` is set — not just a bare "Discount" line.

## 5. Filament — new `PromoCodeResource`

New nav group **Marketing** (or fold into **Sales**, your call). Table: code
(monospace), type + value formatted together ("10% off" / "10,000 Ks off"),
times_used / usage_limit as a fraction, is_active toggle, expires_at. Form: the
columns above, straightforward selects/inputs, no repeaters needed. Bulk
activate/deactivate, same pattern as `FragranceResource`.

## 6. Verify

- A valid code previews correctly (discount + new total) without creating an order.
- The same code applied at final submission produces an order with matching
  `discount_mmk` and a stamped `promo_code`.
- `usage_limit` is enforced under concurrency — two near-simultaneous redemptions of
  a code with one use left result in exactly one success (test this deliberately,
  it's the one place a race condition would actually bite).
- An expired/exhausted/below-minimum code returns the specific reason, not a generic
  error, at both the preview and checkout endpoints.
- A code that lapses between preview and submit still lets the order through, minus
  the discount, with `promo_note` explaining why.
- Editing `discount_mmk` manually on an order in Filament afterward still works
  exactly as before and doesn't touch `promo_code`.
- Report results with a checklist, same as every prior step.
