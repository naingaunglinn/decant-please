"use client";

import { useState } from "react";
import { TrackingForm } from "./TrackingForm";
import { OrderReceipt } from "./OrderReceipt";
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
      {!result && <TrackingForm initialCode={initialCode} pending={pending} onSubmit={lookUp} />}

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
        <section aria-live="polite">
          <OrderReceipt
            key={result.tracking_code}
            order={result}
            context="track"
            onSearchAgain={() => {
              setResult(null);
              window.scrollTo({ top: 0, behavior: "smooth" });
            }}
          />
        </section>
      )}
    </div>
  );
}
