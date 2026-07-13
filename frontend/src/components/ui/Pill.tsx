import type { HTMLAttributes } from "react";

type PillTone = "default" | "pine" | "soft" | "muted" | "pending" | "danger";

const TONES: Record<PillTone, string> = {
  default: "border-rule text-ink",
  pine: "border-pine/30 text-pine",
  soft: "border-transparent bg-pine-soft text-pine",
  muted: "border-rule text-muted",
  pending: "border-status-pending/40 text-status-pending",
  danger: "border-status-danger/40 text-status-danger",
};

interface PillProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: PillTone;
}

/** The vial-label: every atomic piece of metadata in the app lives in one of these. */
export function Pill({ tone = "default", className = "", children, ...rest }: PillProps) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-medium uppercase tracking-[0.18em] leading-relaxed whitespace-nowrap ${TONES[tone]} ${className}`}
      {...rest}
    >
      {children}
    </span>
  );
}
