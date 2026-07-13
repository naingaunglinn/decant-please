"use client";

import { useEffect, useState } from "react";
import { motion, useReducedMotion } from "motion/react";
import { Button } from "@/components/ui/Button";
import { StatusTimeline } from "@/components/tracking/StatusTimeline";

interface LastOrder {
  code: string;
  total_formatted: string;
  placed_at: string;
  items: { name: string; size_ml: number; quantity: number }[];
}

export function OrderCompleteClient({ code }: { code: string }) {
  const reduced = useReducedMotion();
  const [lastOrder, setLastOrder] = useState<LastOrder | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    try {
      const stored = window.sessionStorage.getItem("decant-please.last-order");
      if (stored) {
        const parsed: LastOrder = JSON.parse(stored);
        if (parsed.code === code) setLastOrder(parsed);
      }
    } catch {
      // no summary available — the code alone is enough
    }
  }, [code]);

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
      <p className="mt-2 max-w-md text-sm leading-relaxed text-muted">
        We&apos;ll review it and confirm by phone. Keep this code — with your phone number,
        it&apos;s how you track everything.
      </p>

      {/* the tracking code, in one large vial-label */}
      <button
        type="button"
        onClick={copyCode}
        aria-label={`Copy tracking code ${code}`}
        className="mt-8 flex w-full items-center justify-between gap-4 rounded-2xl border border-pine/30 px-6 py-5 text-left transition-colors hover:bg-pine-soft"
      >
        <span className="font-mono text-xl tracking-[0.25em] text-pine sm:text-2xl">{code}</span>
        <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
          {copied ? "Copied" : "Tap to copy"}
        </span>
      </button>

      {lastOrder && (
        <div className="mt-8 rounded-2xl border border-rule px-6 py-5">
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
            Order summary
          </h2>
          <ul className="mt-3 flex flex-col gap-2">
            {lastOrder.items.map((item, index) => (
              <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
                <span>{item.name}</span>
                <span className="whitespace-nowrap text-muted">
                  {item.size_ml}ml × {item.quantity}
                </span>
              </li>
            ))}
          </ul>
          <div className="mt-4 flex items-baseline justify-between border-t border-rule pt-3">
            <span className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">Total</span>
            <span className="text-sm font-medium">{lastOrder.total_formatted}</span>
          </div>
        </div>
      )}

      <div className="mt-10 border-t border-rule pt-8">
        <StatusTimeline status="awaiting_confirmation" placedAt={lastOrder?.placed_at ?? null} />
      </div>

      <div className="mt-10 flex flex-wrap gap-3">
        <Button href={`/track?code=${encodeURIComponent(code)}`}>Track this order</Button>
        <Button href="/shop" variant="ghost">
          Keep browsing
        </Button>
      </div>
    </motion.div>
  );
}
