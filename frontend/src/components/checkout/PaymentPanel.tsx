"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Pill } from "@/components/ui/Pill";
import { getMeta, uploadPaymentProof, ApiValidationError } from "@/lib/api";
import { useTenant } from "@/lib/tenant-context";
import { formatKyat } from "@/lib/format";
import type { OrderStatusResponse, PaymentInfo } from "@/lib/types";

interface PaymentPanelProps {
  order: OrderStatusResponse;
  onOrderUpdate: (order: OrderStatusResponse) => void;
}

/** One "transfer to" line — hidden entirely if the decanter didn't set that channel. */
function TransferTarget({ label, name, number }: { label: string; name?: string; number?: string }) {
  if (!number) return null;
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">{label}</span>
      <span className="text-right text-sm">
        <span className="font-mono tracking-[0.1em] text-ink-strong">{number}</span>
        {name && <span className="block text-muted">{name}</span>}
      </span>
    </div>
  );
}

/** Offline-payment surface on the receipt: shows the balance and where to send it,
 *  takes the customer's transfer screenshot, and reflects the decanter's paid/unpaid
 *  confirmation. Live view only — never printed. Uploading proof never marks the
 *  order paid; that stays the decanter's manual call. */
export function PaymentPanel({ order, onOrderUpdate }: PaymentPanelProps) {
  const { slug: shop } = useTenant();
  const [payment, setPayment] = useState<PaymentInfo | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const paid = order.payment_status === "paid";
  // A negative balance is the API saying "overpaid" (#67's signed contract).
  // Tolerated here before the backend ever emits one, so the storefront ships first.
  const overpaid = order.balance_due_mmk < 0;

  useEffect(() => {
    let active = true;
    getMeta(shop)
      .then((meta) => {
        if (active) setPayment(meta.payment);
      })
      .catch(() => {
        // payment details are optional — the upload still works without them
      });
    return () => {
      active = false;
    };
  }, [shop]);

  const upload = async () => {
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      const updated = await uploadPaymentProof(shop, order.tracking_code, order.phone, file);
      if (updated) {
        onOrderUpdate(updated);
        setFile(null);
      } else {
        setError("We couldn't match this order — refresh the page and try again.");
      }
    } catch (e) {
      setError(
        e instanceof ApiValidationError
          ? (Object.values(e.errors)[0]?.[0] ?? e.message)
          : "Upload didn't go through — check your connection and try again.",
      );
    } finally {
      setUploading(false);
    }
  };

  const hasDetails =
    payment &&
    (payment.kbzpay_number || payment.wave_number || payment.qr_url || payment.instructions);

  return (
    <div className="no-print mt-4 rounded-2xl border border-rule px-5 py-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Pill tone="muted">Payment</Pill>
        <Pill tone={paid ? "pine" : "pending"}>{order.payment_status_label}</Pill>
      </div>

      {paid ? (
        <p className="mt-4 text-sm leading-relaxed text-pine">
          {overpaid
            ? `Payment confirmed — you've overpaid by ${formatKyat(-order.balance_due_mmk)}; we'll sort the difference out with you.`
            : "Payment confirmed — thank you! Nothing more to do here."}
        </p>
      ) : (
        <>
          <div className="mt-4 flex items-baseline justify-between gap-4">
            <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
              {overpaid ? "Overpaid" : "Balance due"}
            </span>
            <span className="text-base font-bold text-pine">
              {formatKyat(Math.abs(order.balance_due_mmk))}
            </span>
          </div>

          {/* never ask for another transfer when the shop owes them */}
          {hasDetails && !overpaid && (
            <div className="mt-4 flex flex-col gap-3 border-t border-rule pt-4">
              <p className="text-xs leading-relaxed text-muted">
                Transfer the balance, then upload your screenshot below — we confirm every
                payment by hand.
              </p>
              <TransferTarget label="KBZPay" name={payment?.kbzpay_name} number={payment?.kbzpay_number} />
              <TransferTarget label="Wave" name={payment?.wave_name} number={payment?.wave_number} />
              {payment?.qr_url && (
                // a seller-configured URL on an arbitrary host — a plain img avoids
                // next/image's remotePatterns allow-list for a low-stakes QR
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={payment.qr_url}
                  alt="Payment QR code"
                  className="mt-1 h-40 w-40 self-center rounded-xl border border-rule object-contain"
                />
              )}
              {payment?.instructions && (
                <p className="text-xs leading-relaxed text-muted">{payment.instructions}</p>
              )}
            </div>
          )}

          <div className="mt-4 border-t border-rule pt-4">
            {order.has_payment_proof && (
              <p className="mb-3 text-sm leading-relaxed text-pine">
                ✓ Screenshot received — we&apos;ll confirm your payment shortly. Sent the wrong
                one? Upload again to replace it.
              </p>
            )}
            {error && (
              <p role="alert" className="mb-3 text-sm text-status-danger">
                {error}
              </p>
            )}
            <label className="flex flex-col gap-2 text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
              {order.has_payment_proof ? "Replace screenshot" : "Transfer screenshot"}
              <input
                type="file"
                accept="image/*"
                onChange={(e) => {
                  setFile(e.target.files?.[0] ?? null);
                  setError(null);
                }}
                className="block w-full text-sm text-ink file:mr-4 file:min-h-11 file:cursor-pointer file:rounded-full file:border file:border-pine file:bg-transparent file:px-5 file:text-xs file:font-medium file:uppercase file:tracking-[0.15em] file:text-pine hover:file:bg-pine-soft"
              />
            </label>
            <div className="mt-4">
              <Button variant="outline" onClick={upload} disabled={!file || uploading}>
                {uploading
                  ? "Uploading…"
                  : order.has_payment_proof
                    ? "Replace screenshot"
                    : "Upload screenshot"}
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
