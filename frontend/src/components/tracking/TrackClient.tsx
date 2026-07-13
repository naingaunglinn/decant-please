"use client";

import { useState } from "react";
import { TrackingForm } from "./TrackingForm";
import { StatusTimeline } from "./StatusTimeline";
import { Pill } from "@/components/ui/Pill";
import { trackOrder } from "@/lib/api";
import type { OrderStatusResponse } from "@/lib/types";

export function TrackClient({ initialCode }: { initialCode?: string }) {
  const [result, setResult] = useState<OrderStatusResponse | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [failed, setFailed] = useState(false);
  const [pending, setPending] = useState(false);

  const lookUp = async (code: string, phone: string) => {
    setPending(true);
    setNotFound(false);
    setFailed(false);
    try {
      const order = await trackOrder(code, phone);
      setResult(order);
      setNotFound(order === null);
    } catch {
      setResult(null);
      setFailed(true);
    } finally {
      setPending(false);
    }
  };

  return (
    <div className="flex flex-col gap-10">
      <TrackingForm initialCode={initialCode} pending={pending} onSubmit={lookUp} />

      {notFound && (
        <p role="alert" className="rounded-2xl border border-rule px-5 py-4 text-sm text-muted">
          We couldn&apos;t find an order with that code and phone number. Double-check both —
          the code is on your order-complete page.
        </p>
      )}

      {failed && (
        <p role="alert" className="rounded-2xl border border-status-danger/30 px-5 py-4 text-sm text-status-danger">
          Something went wrong reaching the shop — give it a moment and try again.
        </p>
      )}

      {result && (
        <section aria-live="polite" className="border-t border-rule pt-8">
          <div className="mb-8 flex flex-wrap items-center gap-3">
            <Pill tone="pine" className="font-mono">{result.tracking_code}</Pill>
            <Pill tone={result.status === "rejected" || result.status === "cancelled" ? "danger" : "pending"}>
              {result.status_label}
            </Pill>
          </div>

          <StatusTimeline
            status={result.status}
            placedAt={result.placed_at}
            decantDate={result.decant_date}
            deliveryDate={result.delivery_date}
            rejectionReason={result.rejection_reason}
          />

          <div className="mt-10 rounded-2xl border border-rule px-5 py-4">
            <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
              In this order
            </h2>
            <ul className="mt-3 flex flex-col gap-2">
              {result.items.map((item, index) => (
                <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
                  <span>{item.fragrance_name}</span>
                  <span className="whitespace-nowrap text-muted">
                    {item.size_ml}ml × {item.quantity}
                  </span>
                </li>
              ))}
            </ul>
            <div className="mt-4 flex items-baseline justify-between border-t border-rule pt-3">
              <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">Total</span>
              <span className="text-sm font-medium">{result.total_formatted}</span>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
