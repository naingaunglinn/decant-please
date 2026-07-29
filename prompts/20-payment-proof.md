# Step 20 — Payment confirmation + proof

Follow the current `CLAUDE.md`. **Backend only** — the customer-facing Next.js UI is
a follow-on step.

## 0. Why this step exists

To sell Decant Please! to Myanmar decant businesses, the biggest *functional* gap is
payment. Today the receipt shows a `deposit_mmk` figure, but nothing records whether
the decanter has actually been paid — so reconciliation still happens in DMs, screenshot
by screenshot. This step makes payment legible **without** becoming a payment gateway:
money still moves offline (KBZPay/Wave/bank), exactly as §8 requires.

## 1. Schema (`orders`)

- `payment_status` — string, default `'unpaid'`, indexed. Enum `PaymentStatus`
  (`Unpaid`/`Paid`) with Filament label+color, mirroring `OrderStatus`.
- `paid_at` — nullable timestamp; when the decanter confirmed.
- `payment_proof_path` — nullable; the customer's transfer screenshot. *(Amended by
  #47/v12: stored on the private `proofs_disk`, not the media disk as originally built —
  a public-bucket prefix was the wrong home for financial screenshots.)*

Default `unpaid` is deliberate: existing orders read accurately, since none were tracked
before. This is separate from `deposit_mmk` — that's a partial amount; `payment_status`
is the yes/no the seller reconciles. `Order::balanceDue()` = `total_mmk − deposit_mmk`
(never negative).

## 2. Model behaviour, path-independent

`markPaid()` / `markUnpaid()` / `attachPaymentProof()` on `Order`, plus an `unpaid`
scope. Crucially, a **`saving` hook** keeps `paid_at` consistent with `payment_status`
however it's set — the admin form's status select, the row actions, or code — so no
write path can leave a "paid" order with a null `paid_at` (or vice-versa). Deleting an
order (or re-uploading) removes the old proof file; logic lives in the domain layer so a
future Flutter admin reuses it (v5 rule).

## 3. Config — static transfer details

An `app.payment` block in `config/app.php` (mirroring `app.social`), fed by `.env`:
`PAYMENT_KBZPAY_NAME/NUMBER`, `PAYMENT_WAVE_NAME/NUMBER`, `PAYMENT_QR_URL`,
`PAYMENT_INSTRUCTIONS`. A *static* number to transfer to — not a merchant account.
`/api/v1/meta` exposes only the non-blank fields, and the whole `payment` key is `null`
when nothing is set, so the storefront can hide payment instructions entirely.

## 4. Public API

- `/api/v1/meta` → adds the `payment` block (above).
- `POST /api/v1/orders/payment-proof` — `tracking_code` + `phone` + `proof` (image,
  jpeg/png/webp, ≤4MB). Gated by the exact code+phone pair (same generic 404 as
  tracking — no oracle), its own `throttle:payment-proof` bucket. Stores the file,
  replacing any earlier one. **Does not mark paid** — the decanter confirms separately.
  Returns the shared receipt.
- The tracking/cancel receipt gains `payment_status`, `payment_status_label`,
  `has_payment_proof`, `paid_at`, and `balance_due_mmk`.

## 5. Admin (Filament)

- Order form: a **Payment** section (status select + proof image upload/view).
- Orders table: a payment badge column, a payment `SelectFilter`, and per-row
  **Mark paid / Mark unpaid** actions (visible by current status, matching
  accept/reject's style + `refreshEditPage`).
- CSV export: `Payment` + `Balance due (Ks)` columns.
- Dashboard: an **Unpaid orders** stat with the outstanding total (unpaid, non-
  cancelled/rejected: `Σtotal − Σdeposit`).

## 6. Tests (`tests/Feature/PaymentTest.php`)

Model (paid/unpaid toggle + `paid_at`, direct-set sync via the hook, `balanceDue`,
proof-file cleanup on delete); API (meta exposes/hides fields, meta null when unset,
upload happy path stays unpaid, wrong code+phone → 404, non-image rejected, re-upload
replaces file, receipt reports status + balance); admin (mark paid/unpaid, payment
filter, unpaid dashboard stat). Cross-checked on live Postgres (enum + scope round-trip).

## 7. Storefront (Part B — included)

`PaymentPanel` (`components/checkout/PaymentPanel.tsx`) renders on the receipt
(order-complete + tracking, via `OrderReceipt`): payment-status pill, balance due, the
`/meta` transfer details (KBZPay/Wave numbers + QR + instructions, each hidden if
unset), and a screenshot uploader posting to `/orders/payment-proof` (`uploadPaymentProof`
in `lib/api.ts`, multipart). A successful upload swaps the receipt to the returned state
in place; uploading never marks paid. The panel is live-view only — the printed receipt
keeps just a one-line payment state in the summary. Types extended: `CatalogMeta.payment`,
`PaymentInfo`, and the payment fields on `OrderStatusResponse`.
