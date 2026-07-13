"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Pill } from "@/components/ui/Pill";
import { StatusTimeline } from "./StatusTimeline";
import { cancelOrder, ApiConflictError } from "@/lib/api";
import { formatKyat } from "@/lib/format";
import type { OrderStatusResponse } from "@/lib/types";

interface OrderReceiptProps {
  order: OrderStatusResponse;
  /** Which page hosts the receipt — decides the CTA row. */
  context: "complete" | "track";
  onSearchAgain?: () => void;
}

function formatDate(value: string | null): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

function SummaryRow({
  label,
  amount,
  strong = false,
}: {
  label: string;
  amount: string;
  strong?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span
        className={`text-[11px] font-medium uppercase tracking-[0.18em] ${strong ? "text-ink-strong" : "text-muted"}`}
      >
        {label}
      </span>
      <span className={strong ? "text-base font-bold text-pine" : "text-sm"}>{amount}</span>
    </div>
  );
}

/** The one receipt — order-complete and tracking both render this, on screen
 *  and in print (the print stylesheet strips the site chrome around it). */
export function OrderReceipt({ order: initial, context, onSearchAgain }: OrderReceiptProps) {
  // local copy so a cancel can update the view in place without a reload
  const [order, setOrder] = useState(initial);
  const [confirmingCancel, setConfirmingCancel] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState<string | null>(null);

  const negative = order.status === "rejected" || order.status === "cancelled";
  const deliveryLine =
    order.status === "delivered" || negative
      ? null
      : order.delivery_date
        ? `Estimated delivery: ${formatDate(order.delivery_date)}`
        : "We'll confirm within a few hours and let you know the exact delivery date.";

  const cancel = async () => {
    setCancelling(true);
    setCancelError(null);
    try {
      const updated = await cancelOrder(order.tracking_code, order.phone);
      if (updated) setOrder(updated);
      else setCancelError("We couldn't find this order anymore — refresh and try again.");
    } catch (error) {
      setCancelError(
        error instanceof ApiConflictError
          ? error.message
          : "Something went wrong — give it a moment and try again.",
      );
    } finally {
      setCancelling(false);
      setConfirmingCancel(false);
    }
  };

  return (
    <div>
      {/* letterhead — print output only, since the printed page has no site chrome */}
      <div className="print-only mb-8 border-b border-rule pb-6">
        <p className="text-lg font-bold uppercase tracking-[0.2em] text-ink-strong">
          Decant Please!
        </p>
        <p className="mt-1 text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          Receipt — {new Date().toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-bold uppercase tracking-[0.12em] text-ink-strong">
          Order {order.order_number}
        </h2>
        <Pill tone="pine" className="font-mono">
          {order.tracking_code}
        </Pill>
        <Pill tone={negative ? "danger" : "pending"}>{order.status_label}</Pill>
      </div>

      <div className="mt-8">
        <StatusTimeline
          status={order.status}
          placedAt={order.placed_at}
          decantDate={order.decant_date}
          deliveryDate={order.delivery_date}
          rejectionReason={order.rejection_reason}
        />
      </div>

      {/* customer + shipping — the vial-label pattern, not a bare list */}
      <div className="mt-10 grid gap-4 sm:grid-cols-2">
        <div className="rounded-2xl border border-rule px-5 py-4">
          <Pill tone="muted">Customer</Pill>
          <p className="mt-3 text-sm font-medium">{order.customer_name}</p>
          <p className="mt-1 text-sm text-muted">{order.phone}</p>
        </div>
        <div className="rounded-2xl border border-rule px-5 py-4">
          <Pill tone="muted">Shipping</Pill>
          <p className="mt-3 text-sm leading-relaxed">{order.address}</p>
        </div>
      </div>

      {/* itemized list */}
      <div className="mt-4 rounded-2xl border border-rule px-5 py-4">
        <Pill tone="muted">Items</Pill>
        <ul className="mt-4 flex flex-col gap-3">
          {order.items.map((item, index) => (
            <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
              <div>
                <p>{item.fragrance_name}</p>
                <p className="mt-0.5 text-muted">
                  {item.size_ml}ml · {formatKyat(item.unit_price_mmk)} × {item.quantity}
                </p>
              </div>
              <span className="whitespace-nowrap font-medium">
                {formatKyat(item.line_total_mmk)}
              </span>
            </li>
          ))}
        </ul>

        {/* payment summary — zero rows stay hidden until the decanter sets them */}
        <div className="mt-5 flex flex-col gap-2 border-t border-rule pt-4">
          <SummaryRow label="Subtotal" amount={formatKyat(order.subtotal_mmk)} />
          {order.delivery_fee_mmk !== 0 && (
            <SummaryRow label="Delivery fee" amount={formatKyat(order.delivery_fee_mmk)} />
          )}
          {order.discount_mmk !== 0 && (
            <SummaryRow
              label={order.promo_code ? `Discount (${order.promo_code})` : "Discount"}
              amount={`−${formatKyat(order.discount_mmk)}`}
            />
          )}
          {order.deposit_mmk !== 0 && (
            <SummaryRow label="Deposit received" amount={formatKyat(order.deposit_mmk)} />
          )}
          <SummaryRow label="Total" amount={order.total_formatted} strong />
        </div>

        <p className="mt-4 border-t border-rule pt-4 text-xs leading-relaxed text-muted">
          No online payment — we confirm every order first, then arrange bank transfer, mobile
          banking or cash on delivery.
        </p>
      </div>

      {deliveryLine && <p className="mt-6 text-sm text-muted">{deliveryLine}</p>}

      {/* self-cancellation — only until the decanter commits time to it */}
      {order.status === "awaiting_confirmation" && (
        <div className="no-print mt-8 rounded-2xl border border-rule px-5 py-4">
          <p className="text-sm text-muted">
            Changed your mind? You can cancel until we confirm the order — after that, just
            call us.
          </p>
          {cancelError && (
            <p role="alert" className="mt-3 text-sm text-status-danger">
              {cancelError}
            </p>
          )}
          <div className="mt-4 flex flex-wrap gap-3">
            {confirmingCancel ? (
              <>
                <Button variant="outline" onClick={cancel} disabled={cancelling}>
                  {cancelling ? "Cancelling…" : "Yes, cancel this order"}
                </Button>
                <Button variant="ghost" onClick={() => setConfirmingCancel(false)} disabled={cancelling}>
                  Keep it
                </Button>
              </>
            ) : (
              <Button variant="outline" onClick={() => setConfirmingCancel(true)}>
                Cancel this order
              </Button>
            )}
          </div>
        </div>
      )}

      {/* CTA row — never printed */}
      <div className="no-print mt-10 flex flex-wrap gap-3">
        {context === "complete" && (
          <>
            <Button href={`/track?code=${encodeURIComponent(order.tracking_code)}`}>
              Track this order
            </Button>
            <Button href="/shop" variant="ghost">
              Keep browsing
            </Button>
          </>
        )}
        {context === "track" && onSearchAgain && (
          <Button variant="ghost" onClick={onSearchAgain}>
            Search another order
          </Button>
        )}
        <Button variant="outline" onClick={() => window.print()}>
          Print / Save as PDF
        </Button>
      </div>
    </div>
  );
}
