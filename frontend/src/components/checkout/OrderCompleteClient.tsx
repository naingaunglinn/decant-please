"use client";

import { useEffect, useState } from "react";
import { motion, useReducedMotion } from "motion/react";
import { OrderReceipt } from "@/components/tracking/OrderReceipt";
import { TrackingForm } from "@/components/tracking/TrackingForm";
import { Skeleton } from "@/components/ui/Skeleton";
import { trackOrder } from "@/lib/api";
import type { OrderStatusResponse } from "@/lib/types";

type Phase = "loading" | "ready" | "needs-phone";

export function OrderCompleteClient({ code }: { code: string }) {
  const reduced = useReducedMotion();
  const [order, setOrder] = useState<OrderStatusResponse | null>(null);
  const [phase, setPhase] = useState<Phase>("loading");
  const [lookupFailed, setLookupFailed] = useState(false);
  const [pending, setPending] = useState(false);
  const [copied, setCopied] = useState(false);
  const [promoNote, setPromoNote] = useState<string | null>(null);

  const fetchReceipt = async (phone: string): Promise<boolean> => {
    try {
      const result = await trackOrder(code, phone);
      if (result) {
        setOrder(result);
        setPhase("ready");
        try {
          window.sessionStorage.setItem(
            "decant-please.receipt",
            JSON.stringify({ code, phone }),
          );
        } catch {
          // storage unavailable — the receipt still renders this visit
        }
        return true;
      }
    } catch {
      // network trouble — fall through to the phone form, which doubles as retry
    }
    return false;
  };

  // the happy path: checkout stashed {code, phone}, so the receipt loads itself
  useEffect(() => {
    let phone: string | null = null;
    try {
      const stored = window.sessionStorage.getItem("decant-please.receipt");
      if (stored) {
        const parsed: { code?: string; phone?: string; promo_note?: string | null } =
          JSON.parse(stored);
        if (parsed.code === code && parsed.phone) phone = parsed.phone;
        if (parsed.code === code && parsed.promo_note) setPromoNote(parsed.promo_note);
      }
    } catch {
      // fall through to the phone form
    }

    if (!phone) {
      setPhase("needs-phone");
      return;
    }

    fetchReceipt(phone).then((ok) => {
      if (!ok) setPhase("needs-phone");
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code]);

  // fallback path: private window, cleared storage, or another device
  const confirmPhone = async (_code: string, phone: string) => {
    setPending(true);
    setLookupFailed(false);
    const ok = await fetchReceipt(phone);
    if (!ok) setLookupFailed(true);
    setPending(false);
  };

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // clipboard unavailable — the code is visible either way
    }
  };

  return (
    <motion.div
      initial={reduced ? false : { opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
    >
      <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong sm:text-[32px]">
        Order placed
      </h1>
      <p className="no-print mt-2 max-w-md text-sm leading-relaxed text-muted">
        We&apos;ll review it and confirm by phone. Keep this code — with your phone number,
        it&apos;s how you track everything.
      </p>

      {/* the tracking code, in one large vial-label */}
      <button
        type="button"
        onClick={copyCode}
        aria-label={`Copy tracking code ${code}`}
        className="no-print mt-8 flex w-full items-center justify-between gap-4 rounded-2xl border border-pine/30 px-6 py-5 text-left transition-colors hover:bg-pine-soft"
      >
        <span className="font-mono text-xl tracking-[0.25em] text-pine sm:text-2xl">{code}</span>
        <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          {copied ? "Copied" : "Tap to copy"}
        </span>
      </button>

      {promoNote && (
        <p
          role="status"
          className="no-print mt-6 rounded-2xl border border-status-pending/40 px-5 py-4 text-sm leading-relaxed text-status-pending"
        >
          {promoNote}
        </p>
      )}

      <div className="mt-10 border-t border-rule pt-8">
        {phase === "loading" && (
          <div className="flex flex-col gap-4" aria-label="Loading your receipt">
            <Skeleton className="h-5 w-2/3" />
            <Skeleton className="h-40 w-full" />
            <Skeleton className="h-24 w-full" />
          </div>
        )}

        {phase === "ready" && order && (
          <OrderReceipt key={order.tracking_code} order={order} context="complete" />
        )}

        {phase === "needs-phone" && (
          <div>
            <h2 className="text-sm font-medium uppercase tracking-[0.15em]">
              Confirm your phone number to view your receipt
            </h2>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-muted">
              The full receipt is tied to the phone number you ordered with — enter it once
              and everything appears.
            </p>
            {lookupFailed && (
              <p role="alert" className="mt-4 rounded-2xl border border-rule px-5 py-4 text-sm text-muted">
                That phone number doesn&apos;t match this order&apos;s code — double-check it,
                or try again in a moment if your connection dropped.
              </p>
            )}
            <div className="mt-6">
              <TrackingForm initialCode={code} pending={pending} onSubmit={confirmPhone} />
            </div>
          </div>
        )}
      </div>
    </motion.div>
  );
}
