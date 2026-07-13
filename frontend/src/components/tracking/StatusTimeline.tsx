"use client";

import { motion, useReducedMotion } from "motion/react";
import { Pill } from "@/components/ui/Pill";
import type { OrderStatus } from "@/lib/types";

interface StatusTimelineProps {
  status: OrderStatus;
  placedAt?: string | null;
  decantDate?: string | null;
  deliveryDate?: string | null;
  rejectionReason?: string | null;
}

const POSITIVE_ORDER: OrderStatus[] = ["awaiting_confirmation", "pending", "decanted", "delivered"];

function formatDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

/** The vial fill — the one deliberate motion moment in the whole app.
 *  The pine liquid pours downward through the tube as the order advances,
 *  the way a decant pours from bottle to vial. */
export function StatusTimeline({
  status,
  placedAt,
  decantDate,
  deliveryDate,
  rejectionReason,
}: StatusTimelineProps) {
  const reduced = useReducedMotion();

  const negative = status === "rejected" || status === "cancelled";
  const reached = negative ? 0 : POSITIVE_ORDER.indexOf(status);
  const fillPercent = reached <= 0 ? 9 : (reached / 3) * 100;

  const steps = [
    {
      label: "Submitted",
      caption: formatDate(placedAt) ?? "Order received",
    },
    {
      label: "Confirmed",
      caption:
        reached >= 1
          ? decantDate
            ? `Decanting ${formatDate(decantDate)}`
            : "On the schedule"
          : negative
            ? null
            : "We'll confirm shortly",
    },
    {
      label: "Decanted",
      caption: reached >= 2 ? formatDate(decantDate) : null,
    },
    {
      label: "Delivered",
      caption:
        reached >= 3
          ? formatDate(deliveryDate)
          : !negative && deliveryDate
            ? `Expected ${formatDate(deliveryDate)}`
            : null,
    },
  ];

  return (
    <div>
      <div className="relative">
        {/* the vial */}
        <div
          className="absolute bottom-2 left-[7px] top-1 w-2 overflow-hidden rounded-full border border-rule bg-white/60"
          aria-hidden
        >
          <motion.div
            initial={reduced ? { height: `${fillPercent}%` } : { height: "4%" }}
            animate={{ height: `${fillPercent}%` }}
            transition={{ duration: 1.4, ease: [0.22, 1, 0.36, 1], delay: 0.2 }}
            className={`w-full rounded-full ${negative ? "bg-muted/50" : "bg-pine"}`}
          />
        </div>

        <ol className="flex flex-col">
          {steps.map((step, index) => {
            const done = index <= reached && !(negative && index > 0);
            const isCurrent = index === reached && !negative;

            return (
              <li key={step.label} className="relative pb-9 pl-10 last:pb-0">
                <span
                  aria-hidden
                  className={`absolute left-[3px] top-1 size-4 rounded-full border-2 transition-colors ${
                    done ? "border-pine bg-pine" : "border-rule bg-mist"
                  } ${negative && index === 0 ? "border-muted bg-muted" : ""}`}
                />
                <div className="flex flex-wrap items-center gap-2">
                  <span
                    className={`text-xs font-medium uppercase tracking-[0.18em] ${
                      done ? "text-ink" : "text-muted"
                    }`}
                  >
                    {step.label}
                  </span>
                  {isCurrent && <Pill tone="soft">Now</Pill>}
                </div>
                {step.caption && <p className="mt-1 text-sm text-muted">{step.caption}</p>}
              </li>
            );
          })}
        </ol>
      </div>

      {negative && (
        <div className="mt-6 rounded-2xl border border-status-danger/30 px-5 py-4">
          <Pill tone="danger">{status === "rejected" ? "Rejected" : "Cancelled"}</Pill>
          <p className="mt-3 text-sm leading-relaxed text-ink">
            {rejectionReason ??
              "This order won't be fulfilled. Reach out on our socials if you think that's a mistake."}
          </p>
        </div>
      )}
    </div>
  );
}
