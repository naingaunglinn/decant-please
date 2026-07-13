"use client";

import { motion, useReducedMotion } from "motion/react";
import { Pill } from "@/components/ui/Pill";
import type { DecantPrice } from "@/lib/types";

interface SizeSelectorProps {
  prices: DecantPrice[];
  selected: number | null;
  onSelect: (sizeMl: number) => void;
}

/** The price list, as a selector — tap a row to pick that size.
 *  The pine-soft highlight slides between rows rather than cutting. */
export function SizeSelector({ prices, selected, onSelect }: SizeSelectorProps) {
  const reduced = useReducedMotion();

  return (
    <div role="radiogroup" aria-label="Decant size" className="overflow-hidden rounded-2xl border border-rule">
      {prices.map((price, index) => {
        const isSelected = selected === price.size_ml;

        return (
          <button
            key={price.size_ml}
            type="button"
            role="radio"
            aria-checked={isSelected}
            disabled={!price.in_stock}
            onClick={() => onSelect(price.size_ml)}
            className={`relative flex min-h-14 w-full items-center justify-between gap-3 px-5 text-left transition-colors ${
              index > 0 ? "border-t border-rule" : ""
            } ${price.in_stock ? "cursor-pointer" : "cursor-not-allowed"}`}
          >
            {isSelected && (
              <motion.span
                layoutId="size-highlight"
                transition={reduced ? { duration: 0 } : { duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                className="absolute inset-0 bg-pine-soft"
                aria-hidden
              />
            )}

            <span className={`relative text-sm font-medium uppercase tracking-[0.15em] ${price.in_stock ? "text-ink" : "text-muted"}`}>
              {price.size_ml}ml
            </span>

            <span className="relative flex items-center gap-3">
              {!price.in_stock && <Pill tone="muted">Sold out</Pill>}
              <span className={`text-sm tabular-nums ${price.in_stock ? "text-pine" : "text-muted line-through"}`}>
                {price.price_formatted}
              </span>
            </span>
          </button>
        );
      })}
    </div>
  );
}
