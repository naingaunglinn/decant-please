{{-- Admin packing invoice (A5), generated fresh on every request — never cached or
     stored, so a reprint always reflects the order as it is right now.

     Fed either a single $order (row/header actions, the invoice route) or a
     collection as $orders (bulk download) — the bulk case renders the same block
     once per order with a page break between. Callers eager-load `items`. --}}
@use('App\Support\Money')
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Order> $orders */
    $orders = collect($orders ?? [$order]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $orders->count() === 1 ? 'Invoice — Order #'.$orders->first()->id : 'Invoices — '.now()->format('j M Y') }}</title>
<style>
    /* Padauk is deliberately the ONLY font-family on the page: it covers Latin
       and Myanmar in one file, so a line mixing English and Burmese never relies
       on dompdf falling back per-character between fonts (it doesn't, reliably).
       dompdf's bundled DejaVu has no Myanmar glyphs at all — free-text names and
       addresses typed in Burmese would render as tofu boxes without this.
       (Noto Sans Myanmar was the original pick, but current builds are
       script-only — no Latin — which is why it's Padauk.)

       Known limit, accepted: dompdf does no complex-script shaping, so Burmese
       renders every glyph with correct above/below stacking but without visual
       reordering — ေ appears after its consonant and medial ြ beside rather
       than around its base. Legible on a packing slip (and the admin UI always
       shows the properly-shaped text); if that ever stops being good enough,
       the revisit path is mPDF's OTL engine, not a font swap. */
    @font-face {
        font-family: 'Padauk';
        font-style: normal;
        font-weight: normal;
        src: url('file://{{ resource_path('fonts/Padauk-Regular.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Padauk';
        font-style: normal;
        font-weight: bold;
        src: url('file://{{ resource_path('fonts/Padauk-Bold.ttf') }}') format('truetype');
    }

    @page { margin: 11mm 12mm; }

    /* dompdf CSS: no flexbox, no grid — tables and plain blocks only. The roomy
       line-height is load-bearing: Myanmar stacks marks above and below the
       baseline, and tighter leading clips them. */
    body { margin: 0; font-family: 'Padauk'; font-size: 9pt; line-height: 1.45; color: #111111; }
    p { margin: 0; }

    .letterhead { font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: 3px; }
    .doc-title { margin: 1.5mm 0 0; font-size: 15pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
    .muted { color: #666666; }
    .meta { font-size: 9pt; }
    .tracking { letter-spacing: 2px; font-weight: bold; }

    .label {
        font-size: 7pt; font-weight: bold; text-transform: uppercase;
        letter-spacing: 1.5px; color: #666666; margin-bottom: 1mm;
    }

    table { width: 100%; border-collapse: collapse; }

    .blocks { margin-top: 4mm; }
    .blocks td { vertical-align: top; }
    .blocks .block { width: 48%; border: 1px solid #d9d9d9; padding: 2mm 2.5mm; }
    .blocks .gap { width: 4%; }

    .items { margin-top: 4mm; }
    .items th {
        font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px;
        color: #666666; text-align: left; padding: 0 1.5mm 1mm 0;
        border-bottom: 1px solid #111111;
    }
    .items td { padding: 1mm 1.5mm 1mm 0; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
    .items .num, .summary .num { text-align: right; white-space: nowrap; }
    .items th.num { padding-right: 0; }
    .items td.num { padding-right: 0; }

    .summary { margin-top: 3mm; }
    .summary td { padding: 0.5mm 0; }
    .summary .row-label {
        font-size: 7.5pt; font-weight: bold; text-transform: uppercase;
        letter-spacing: 1.5px; color: #666666;
    }
    .summary tr.total td { border-top: 1px solid #111111; padding-top: 1.5mm; font-weight: bold; }
    .summary tr.total .row-label { color: #111111; }
    .summary tr.balance td { padding-top: 1mm; font-weight: bold; font-size: 12pt; }
    .summary tr.balance .row-label { font-size: 8.5pt; color: #111111; letter-spacing: 2px; }

    .note { margin-top: 3.5mm; padding-top: 2.5mm; border-top: 1px solid #d9d9d9; font-size: 8pt; color: #666666; }
</style>
</head>
<body>
@foreach ($orders as $order)
    @php
        $subtotal = (int) $order->items->sum('line_total_mmk');
        $balanceDue = max(0, $order->total_mmk - $order->deposit_mmk);
    @endphp
    <div @if (! $loop->last) style="page-break-after: always;" @endif>
        <p class="letterhead">Decant Please!</p>
        <h1 class="doc-title">Invoice</h1>

        <p class="meta" style="margin-top: 3mm;">Order #{{ $order->id }} · <span class="tracking">{{ $order->tracking_code }}</span></p>
        <p class="meta muted">Placed {{ $order->created_at->format('j M Y') }}</p>
        @unless ($order->created_at->isToday())
            <p class="meta muted">Printed on {{ now()->format('j M Y') }}</p>
        @endunless
        <p class="meta" style="margin-top: 1.5mm;">Status at time of printing: <strong>{{ $order->status->label() }}</strong></p>

        <table class="blocks">
            <tr>
                <td class="block">
                    <p class="label">Customer</p>
                    <p>{{ $order->customer_name }}</p>
                    <p class="muted">{{ $order->phone }}</p>
                </td>
                <td class="gap"></td>
                <td class="block">
                    <p class="label">Shipping</p>
                    <p>{{ $order->address }}</p>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Size</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->fragrance_name_snapshot }}</td>
                        <td class="num">{{ $item->size_ml }}ml</td>
                        <td class="num">× {{ $item->quantity }}</td>
                        <td class="num">{{ Money::kyat($item->unit_price_mmk) }}</td>
                        <td class="num">{{ Money::kyat($item->line_total_mmk) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Zero rows stay hidden until the decanter sets them — same rule as the
             customer receipt. Balance due always shows: it's the number whoever
             hands over the parcel actually needs. --}}
        <table class="summary">
            <tr>
                <td class="row-label">Subtotal</td>
                <td class="num">{{ Money::kyat($subtotal) }}</td>
            </tr>
            @if ($order->delivery_fee_mmk !== 0)
                <tr>
                    <td class="row-label">Delivery fee</td>
                    <td class="num">{{ Money::kyat($order->delivery_fee_mmk) }}</td>
                </tr>
            @endif
            @if ($order->discount_mmk !== 0)
                <tr>
                    <td class="row-label">{{ $order->promo_code ? "Discount ({$order->promo_code})" : 'Discount' }}</td>
                    <td class="num">−{{ Money::kyat($order->discount_mmk) }}</td>
                </tr>
            @endif
            <tr class="total">
                <td class="row-label">Total</td>
                <td class="num">{{ Money::kyat($order->total_mmk) }}</td>
            </tr>
            {{-- Deposit sits between Total and Balance due — it reduces what's
                 collected at handover, not the total, and the printed column
                 should add up exactly as read. --}}
            @if ($order->deposit_mmk !== 0)
                <tr>
                    <td class="row-label">Deposit received</td>
                    <td class="num">−{{ Money::kyat($order->deposit_mmk) }}</td>
                </tr>
            @endif
            <tr class="balance">
                <td class="row-label">Balance due</td>
                <td class="num">{{ Money::kyat($balanceDue) }}</td>
            </tr>
        </table>

        <p class="note">No online payment is taken — payment is arranged by bank transfer, mobile banking, or cash on delivery once the order is confirmed.</p>
    </div>
@endforeach
</body>
</html>
