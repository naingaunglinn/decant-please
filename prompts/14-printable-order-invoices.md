# Step 14 — Printable order invoices (admin print & download, A5)

Work in `backend/` only — no frontend changes. Follow `CLAUDE.md` v6.

## 1. Why this, and what stays untouched

There's currently no way to produce a document from an order in Filament. Admins
review, accept, decant, and ship entirely through the admin UI, then have nothing to
physically hand over or attach to a parcel.

**This is not the Step 8 receipt.** `OrderReceipt.tsx` on the frontend already gives
customers a printable receipt via `@media print` + the browser's own print dialog —
`CLAUDE.md` v3 deliberately chose that over a PDF dependency, and that choice is
still right for that use case (self-service, one order, on the customer's own
device). This step is a different job: admin-triggered at packing time, needs an
exact physical size every single time regardless of whatever the browser/printer
defaults to, and ideally batches a whole day's parcels in one action. That's enough
difference to justify a real PDF library here even though Step 8 correctly avoided
one. **`OrderReceipt.tsx` is not touched by this step.**

**Deliberately not building in this pass** (flagging as a trade-off, not an
oversight): a QR/barcode on the invoice linking to the tracking page. Nice-to-have,
adds a new package for code generation, and isn't needed for the core ask. Revisit
later if wanted.

## 2. Package

```
composer require barryvdh/laravel-dompdf
```

`v3.1.2` requires `illuminate/support ^9|^10|^11|^12|^13.0` — confirmed compatible
with this project's `laravel/framework ^13.8` and `php ^8.3`. It's pure PHP (no
headless Chrome, no `wkhtmltopdf` binary), so it runs on the existing `heroku/php`
buildpack with nothing new to configure in `Procfile` or `APP_BASE`. This is
specifically why it's preferred over `spatie/laravel-pdf` (Browsershot/Puppeteer) —
that would need its own Chrome buildpack on Heroku, the exact kind of deploy
complexity `DEPLOY.md`'s existing gotchas show real effort went into avoiding.

Publish the config (`php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"`)
since §4 needs to point `font_dir`/`font_cache` somewhere writable.

## 3. Invoice content — `resources/views/pdf/invoice.blade.php`

One Blade view, fed a single `Order` (with `items` eager-loaded). Content mirrors
what Step 8 already validated for the customer receipt — reuse the shape, don't
reinvent it:

- Letterhead ("Decant Please!") and a document heading ("Invoice") — not "Order
  placed" or anything phrased as live, since like the Step 8 receipt this is a
  snapshot, generated fresh on each click, never cached or stored.
- Order number (`#{$order->id}`, matching the frontend's existing format) and
  `tracking_code`, the date placed, and **status at time of printing** as a static
  line — not the live timeline. If the order is later re-decanted/re-shipped and the
  admin reprints, it should show whatever the status is *then*, not a stale cached
  render — this is a reason to generate on every request, not a reason to add
  caching.
- Customer block (`customer_name`, `phone`) and Shipping block (`address`).
- Itemized list from `$order->items`: `fragrance_name_snapshot`, `size_ml`,
  `quantity`, `unit_price_mmk`, `line_total_mmk` per row — these are already
  snapshotted on `OrderItem` at order-creation time, so no join back to `Fragrance`/
  `DecantPrice` is needed and nothing here is affected by later catalog price
  changes.
- Payment summary, same conditional-row rule Step 8 established: subtotal always;
  `delivery_fee_mmk`, `discount_mmk` (naming `promo_code` if set), `deposit_mmk` only
  when non-zero; `total_mmk` always.
- **New — not in the Step 8 receipt:** a **Balance due** row —
  `max(0, $order->total_mmk - $order->deposit_mmk)` — visually distinct (bold/larger)
  since this is the number whoever hands over the parcel actually needs. The
  customer-facing receipt doesn't need this emphasized; this document does.
  **(Amended during implementation:)** the deposit row goes **between Total and
  Balance due**, not above Total as on the Step 8 receipt — deposit isn't part of
  `total_mmk` (total = items + fee − discount), and a printed column must add up
  exactly as read: Subtotal + fee − discount = Total; Total − deposit = Balance due.
- Footer note, same wording Step 8 uses: "No online payment — bank transfer, mobile
  banking, or cash on delivery."
- No product images. Step 8's printed receipt has none either — keep it text-only.

**dompdf's CSS support is limited** — no flexbox, no grid. Lay this out with tables
or floats/inline-block, the way email-template HTML is usually written. Reaching for
`display: flex` here will silently not do what it does in a browser.

## 4. Burmese script — a real gotcha, not a hypothetical

`customer_name`, `address`, and `notes` are free text, and this is a Myanmar
business — customers will type these in Burmese script. dompdf's bundled default
font (DejaVu) has no Myanmar glyph coverage, so without action these fields render
as tofu boxes on the one document whose entire job is getting a parcel to the right
address.

- Bundle a Myanmar Unicode font as a repo asset — `backend/resources/fonts/
  Padauk-{Regular,Bold}.ttf` (SIL, OFL; ship `OFL.txt` alongside). **Not R2** —
  this is a static app asset that must ship with every `git push heroku main`, not
  user content. **(Amended during implementation:)** this originally said Noto Sans
  Myanmar, on the belief it covers Latin too. It doesn't anymore — current Noto
  Myanmar builds (notofonts.github.io and Google Fonts) are Myanmar-script-only,
  no Latin letters, verified by parsing the TTF cmap — so as a sole font it would
  tofu every English label. Padauk carries full Latin + Myanmar in one file. Bundle
  **both weights**: dompdf doesn't synthesize bold, so a bold row silently renders
  regular without a real bold face.
- Reference it via `@font-face` in the Blade view's `<style>` block and make it the
  **only** font-family on the page, not a fallback after DejaVu — one font that
  covers both scripts sidesteps any question about whether dompdf reliably falls
  back per-character within one text run for a line that mixes English and Burmese
  (it doesn't).
- **Known, accepted limit (found during implementation):** dompdf performs no
  complex-text shaping (no GSUB/GPOS). With Padauk, every Myanmar glyph renders and
  above/below marks stack correctly — no tofu — but visual reordering doesn't
  happen: `ေ` (U+1031) draws after its consonant and medial `ြ` beside rather than
  around its base. Legible on a packing slip, and the admin UI always shows the
  properly-shaped text; if that ever stops being good enough, the revisit path is
  mPDF's OTL engine (mind its GPL license), not a font swap.
- **Test this explicitly**: create or edit an order with a real Burmese-script name
  and address, generate the invoice, and confirm it renders — don't take "the seed
  data looks fine" as sufficient, since seed data is almost certainly English-only.

## 5. Paper size

```php
Pdf::loadView('pdf.invoice', ['order' => $order])->setPaper('a5');
```

`a5` is a built-in dompdf preset (148 × 210mm / 419.53 × 595.28pt), portrait by
default — no manual step for the admin, unlike relying on a browser print dialog's
paper-size picker.

## 6. Actions — same pattern as `acceptAction()` / `rejectAction()`

Two new static methods on `OrderResource`, gated identically:

```php
->visible(fn (Order $record): bool => $record->status->isFulfillable())
```

`isFulfillable()` already exists on `OrderStatus` and covers pending/decanted/
delivered — exactly the range that has anything worth invoicing. An order still
`awaiting_confirmation` (or rejected/cancelled) has no committed schedule or
guaranteed pricing to hand a customer.

Unlike `acceptAction()`/`rejectAction()`, neither of these changes the record's
state, so the `refreshEditPage()` after-hook those two use doesn't apply here — skip
it.

- **`downloadInvoiceAction()`** — copy `OrdersTable`'s existing `exportCsv` toolbar
  action's mechanism almost verbatim: a Livewire `->action()` closure returning
  `response()->streamDownload(...)`, swapping CSV-writing for
  `Pdf::loadView(...)->output()` bytes, `Content-Type: application/pdf`, filename
  `invoice-{$record->tracking_code}.pdf`. `Heroicon::OutlinedArrowDownTray` (already
  in use for the CSV export, confirmed correct for the installed Filament version).

- **`printInvoiceAction()`** — opening a PDF inline in a new tab so it's immediately
  ready for the admin to hit Ctrl+P isn't really what the Livewire action-closure
  mechanism is built for. This wants a real route, registered inside the panel so it
  inherits Filament's own auth middleware rather than duplicating it by hand:

  ```php
  ->authenticatedRoutes(fn () => Route::get('/orders/{order}/invoice', OrderInvoiceController::class)
      ->name('orders.invoice'))
  ```

  in `AdminPanelProvider::panel()`, rendering the same Blade view with
  `Content-Disposition: inline` (dompdf's `->stream()`), and the action itself:

  ```php
  Action::make('printInvoice')
      ->url(fn (Order $record) => route('filament.admin.orders.invoice', $record))
      ->openUrlInNewTab()
  ```

  **(Amended during implementation — this snippet originally said `->routes()`,
  which is a security trap:)** verified against the installed Filament v5 vendor
  source (`vendor/filament/filament/routes/web.php`), `Panel::routes()` closures are
  registered *outside* the auth middleware, alongside login/password-reset;
  **`Panel::authenticatedRoutes()`** is the one wrapped in
  `Route::middleware($panel->getAuthMiddleware())`. With plain `->routes()` the
  invoice URL would render for logged-out visitors — exactly what §8's guardrail
  forbids. Both take a `?Closure` (receiving the `Panel`), and route names get the
  `filament.{panelId}.` prefix, so `->name('orders.invoice')` resolves as
  `filament.admin.orders.invoice`. `Heroicon::OutlinedPrinter` and
  `Heroicon::OutlinedArrowDownTray` are the verified case names; the route handler
  should also `abort_unless($order->status->isFulfillable(), 404)` — the action
  gates visibility, but the URL is typeable.

Add both to `EditOrder::getHeaderActions()` (alongside accept/reject/delete) and to
`OrdersTable`'s `recordActions()` (alongside accept/reject/edit/delete) — the same
two places accept/reject already live.

## 7. Bulk: "Download invoices (PDF)"

New entry in `OrdersTable`'s `toolbarActions()`, next to `exportCsv`, same
`$livewire->getFilteredSortedTableQuery()` pattern so it respects whatever tab is
active — filtering to **Today's Deliveries** and clicking this produces one PDF
covering that whole batch. One order per physical page via
`page-break-after: always` between rendered invoice blocks (dompdf respects this CSS
property). Filename `invoices-{today's date}.pdf`, matching the existing
`orders-{date}.csv` convention. Defensively re-filter to `isFulfillable()` orders
even though the visible tabs shouldn't normally include anything else — a bulk
export is a worse place to discover an edge case than a single-row action.

## 8. Guardrails

- Never cache or persist a generated PDF — regenerate fresh on every click, so a
  status change, an edited delivery fee, or a corrected address between two prints
  of the same order is always reflected. This is the same "a document is a snapshot,
  not a live view" principle Step 8 already established for the customer receipt,
  applied here too.
- The new route from §6 must actually sit behind admin auth — a request without a
  valid admin session should redirect to login, not render the PDF. Test this
  specifically; it's a new URL and new URLs are exactly what the admin-panel-
  security discussion elsewhere in this project is worried about.
- No product images on the invoice (matches Step 8's printed receipt: text only).

## 9. Verify

- Generate an invoice for an order in each of `pending`/`decanted`/`delivered` —
  confirm the actions render; confirm they're absent for `awaiting_confirmation`,
  `rejected`, and `cancelled`.
- Open the downloaded/printed PDF in an actual PDF viewer and confirm it reports
  true A5 dimensions (148 × 210mm), not a scaled-to-fit Letter/A4 page.
- Create or edit an order with a Burmese-script `customer_name` and `address` —
  confirm both render correctly with no tofu boxes.
- Confirm the numbers match the Filament edit page: balance due = total − deposit,
  and the conditional summary rows (delivery fee/discount/deposit) appear only when
  non-zero.
- Filter the orders table to Today's Deliveries, trigger the bulk download, and
  confirm one PDF comes back with one order per page in the right order.
- Hit the new invoice route while logged out (or in an incognito window) and
  confirm it redirects to the admin login instead of returning a PDF.
- Change an order's delivery fee, then re-print the same order's invoice — confirm
  the new figure shows up (proves nothing is being cached).

Report results with a checklist, same as every prior step.
