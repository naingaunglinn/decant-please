"use client";

import Link from "next/link";
import { motion, useReducedMotion } from "motion/react";
import { FragranceCard } from "./FragranceCard";
import type { Fragrance } from "@/lib/types";

export function FragranceGrid({ fragrances }: { fragrances: Fragrance[] }) {
  const reduced = useReducedMotion();

  if (fragrances.length === 0) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-2xl border border-rule px-6 py-16 sm:items-center sm:text-center">
        <p className="max-w-sm text-sm text-muted">
          No fragrances match these filters — try widening your search.
        </p>
        <Link
          href="/shop"
          className="text-xs font-medium uppercase tracking-[0.15em] text-pine underline underline-offset-4"
        >
          Clear all filters
        </Link>
      </div>
    );
  }

  // key on the result-set signature so a filter change re-runs the stagger
  const signature = fragrances.map((f) => f.id).join(",");

  return (
    <motion.ul
      key={signature}
      initial={reduced ? false : "hidden"}
      animate="visible"
      variants={{ visible: { transition: { staggerChildren: 0.04 } } }}
      className="grid grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 lg:grid-cols-3 xl:grid-cols-4"
    >
      {fragrances.map((fragrance) => (
        <motion.li
          key={fragrance.id}
          variants={{
            hidden: { opacity: 0, y: 12 },
            visible: { opacity: 1, y: 0, transition: { duration: 0.35, ease: "easeOut" } },
          }}
        >
          <FragranceCard fragrance={fragrance} />
        </motion.li>
      ))}
    </motion.ul>
  );
}
