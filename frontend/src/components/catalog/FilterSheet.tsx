"use client";

import { useEffect, useState } from "react";
import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import { FilterControls, useActiveFilterCount } from "./FilterControls";
import { Button } from "@/components/ui/Button";
import type { Brand, CatalogMeta } from "@/lib/types";

/** Mobile bottom sheet wrapping the same controls as the desktop sidebar. */
export function FilterSheet({ brands, meta }: { brands: Brand[]; meta: CatalogMeta }) {
  const [open, setOpen] = useState(false);
  const reduced = useReducedMotion();
  const activeCount = useActiveFilterCount();

  useEffect(() => {
    document.body.style.overflow = open ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [open]);

  return (
    <div className="lg:hidden">
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="flex min-h-11 items-center gap-2 rounded-full border border-rule px-5 text-xs font-medium uppercase tracking-[0.15em] transition-colors hover:border-pine/40"
      >
        Filter &amp; sort
        {activeCount > 0 && (
          <span className="flex size-5 items-center justify-center rounded-full bg-pine text-[10px] text-mist">
            {activeCount}
          </span>
        )}
      </button>

      <AnimatePresence>
        {open && (
          <div className="fixed inset-0 z-50">
            <motion.button
              aria-label="Close filters"
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.25 }}
              onClick={() => setOpen(false)}
              className="absolute inset-0 bg-ink-strong/30"
            />
            <motion.div
              role="dialog"
              aria-modal="true"
              aria-label="Filter and sort"
              initial={reduced ? { opacity: 0 } : { y: "100%" }}
              animate={reduced ? { opacity: 1 } : { y: 0 }}
              exit={reduced ? { opacity: 0 } : { y: "100%" }}
              transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
              className="absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-t-2xl border-t border-rule bg-mist px-6 pb-8 pt-4"
            >
              <div className="mx-auto mb-6 h-1 w-10 rounded-full bg-rule" aria-hidden />
              <FilterControls brands={brands} meta={meta} />
              <Button className="mt-8 w-full" onClick={() => setOpen(false)}>
                Show results
              </Button>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}
