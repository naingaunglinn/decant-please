<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Renders one order's packing invoice as an inline A5 PDF, so the admin can
 * open it in a new tab and hit print. Registered inside the Filament panel's
 * authenticated routes (see AdminPanelProvider) — the panel's own auth
 * middleware guards it, never this controller by hand.
 *
 * Always generated fresh: a status change, an edited fee, or a corrected
 * address between two prints must always show. Nothing is cached or stored.
 */
class OrderInvoiceController extends Controller
{
    public function __invoke(Order $order): Response
    {
        // Same gate as the actions that link here: an order that isn't
        // fulfillable has no committed schedule or guaranteed pricing to
        // put on paper, so the URL 404s rather than inventing a document.
        abort_unless($order->status->isFulfillable(), 404);

        $order->loadMissing('items');

        return Pdf::loadView('pdf.invoice', ['order' => $order])
            ->setPaper('a5')
            ->stream("invoice-{$order->tracking_code}.pdf"); // inline, not attachment
    }
}
