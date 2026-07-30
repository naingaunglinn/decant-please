# Step 22 — Payment method choice + MMQR settings

Follow the current `CLAUDE.md`. Backend + storefront.

## 0. Why this step exists

Selling to Myanmar decant shops, the seller's biggest risk is "confirmed, then the
customer ghosts." This step lets the customer **choose COD or online prepay at
checkout**, and for online they **pay (MMQR scan) + upload their slip before the order
is confirmed** — so the decanter verifies payment before committing to decant. It also
gives the decanter a home for their **MMQR + KBZPay/Wave details** in the admin, instead
of editing `.env`. Still no gateway — money moves offline (v10 holds).

## 1. Payment method

- `PaymentMethod` enum (`cod` | `online`), label/color, mirroring `PaymentStatus`.
- Migration: `orders.payment_method` string, default `'cod'` — existing rows and manual
  admin entries read as cash-on-delivery.
- Order model: fillable + cast; `newFromCheckout` stores it (default cod).
- `POST /orders` validates `payment_method` (`nullable|in:cod,online`, defaults cod).
- The tracking/receipt payload returns `payment_method` + `payment_method_label`.

## 2. The flows

- **COD:** checkout → `awaiting_confirmation` → decanter Accepts → pay cash on delivery.
  The receipt shows a calm "pay cash on delivery" note (no pay panel).
- **Online:** checkout(Online) → order-complete page shows the **MMQR + amount + slip
  upload** (the v10 PaymentPanel) → customer prepays → decanter checks the slip in
  Needs-review → Mark paid → Accept. **Delivery fee is NOT in the online amount** — the
  courier collects it in cash separately (Option B), so online = the item subtotal.

## 3. Admin

- Order form: a `payment_method` select (default cod for manual orders).
- Orders table: a `payment_method` badge column with a **"slip uploaded / awaiting
  slip"** description (the Needs-review signal), plus a method filter.
- `acceptAction`: a **soft** modal reminder if an online order is still Unpaid — check
  the slip and Mark paid first. Never a hard block (the decanter stays in control).

## 4. MMQR / payment settings (admin-managed, not .env)

- `shop_settings` single-row table + `ShopSetting` model (`current()` singleton;
  `saved` busts the `api.meta` cache). Fields: kbzpay_name/number, wave_name/number,
  `payment_qr_path` (MMQR on the **public media disk**), payment_instructions.
- Filament **ManagePayment** page (Settings nav group): a form with the MMQR upload +
  numbers + instructions. Mirrors Filament's `EditProfile` form-page pattern —
  `form(Schema)` with `statePath('data')`, `content()` embeds `EmbeddedSchema::make('form')`
  in a `Form` with a Save action, blade renders `{{ $this->content }}`.
- `MetaController::payment()` reads `ShopSetting::current()` first, **falling back to the
  `PAYMENT_*` env** per field, so existing deployments are unaffected. The MMQR image is
  served as a public URL; a fully-blank shop hides the payment block (null).

## 5. Storefront

- `CheckoutForm`: a COD/Online selector (`MethodOption` radio cards, **default COD**),
  method-aware note, `payment_method` threaded into the checkout payload.
- `OrderReceipt`: PaymentPanel (pay-now) only for **online**; a COD note otherwise.
- Types: `PaymentMethod`, `CheckoutPayload.payment_method`, `OrderStatusResponse`
  payment_method + label.

## 6. Tests

`PaymentMethodTest`: checkout defaults to cod / stores online / rejects unknown; receipt
reports the method; ShopSetting singleton; `/meta` serves DB settings over env + cache
bust; the ManagePayment page saves. Verified on SQLite + cross-checked on live Postgres
(new column + table + singleton + meta read).
