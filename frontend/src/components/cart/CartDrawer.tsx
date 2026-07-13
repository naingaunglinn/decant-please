"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import { Button } from "@/components/ui/Button";
import { CartItemRow } from "./CartItemRow";
import { useCart, cartLineKey } from "@/hooks/useCart";
import { formatKyat } from "@/lib/format";

export function CartDrawer() {
  const { lines, subtotal, isOpen, closeCart } = useCart();
  const reduced = useReducedMotion();
  const pathname = usePathname();

  // close when navigating (e.g. tapping a line item's link)
  useEffect(() => closeCart(), [pathname, closeCart]);

  useEffect(() => {
    if (!isOpen) return;
    const onKey = (event: KeyboardEvent) => event.key === "Escape" && closeCart();
    window.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [isOpen, closeCart]);

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-50">
          <motion.button
            aria-label="Close cart"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.25 }}
            onClick={closeCart}
            className="absolute inset-0 bg-ink-strong/30"
          />
          <motion.aside
            role="dialog"
            aria-modal="true"
            aria-label="Cart"
            initial={reduced ? { opacity: 0 } : { x: "100%" }}
            animate={reduced ? { opacity: 1 } : { x: 0 }}
            exit={reduced ? { opacity: 0 } : { x: "100%" }}
            transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
            className="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col border-l border-rule bg-mist"
          >
            <div className="flex items-center justify-between border-b border-rule px-6 py-5">
              <h2 className="text-xs font-medium uppercase tracking-[0.2em]">Your cart</h2>
              <button
                type="button"
                onClick={closeCart}
                aria-label="Close cart"
                className="flex size-11 items-center justify-center rounded-full transition-colors hover:bg-pine-soft"
              >
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
                  <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                </svg>
              </button>
            </div>

            {lines.length === 0 ? (
              <div className="flex flex-1 flex-col items-center justify-center gap-6 px-6 text-center">
                <p className="text-sm text-muted">
                  Your cart is empty — the shop is one tap away.
                </p>
                <Button href="/shop" variant="outline">
                  Browse fragrances
                </Button>
              </div>
            ) : (
              <>
                <ul className="flex-1 overflow-y-auto px-6 py-5">
                  {lines.map((line) => (
                    <CartItemRow key={cartLineKey(line)} line={line} />
                  ))}
                </ul>

                <div className="border-t border-rule px-6 py-5">
                  <div className="flex items-baseline justify-between">
                    <span className="text-xs uppercase tracking-[0.18em] text-muted">Subtotal</span>
                    <span className="text-lg font-medium tabular-nums">{formatKyat(subtotal)}</span>
                  </div>
                  <p className="mt-1 text-xs text-muted">
                    Delivery arranged after we confirm your order.
                  </p>
                  <Button href="/checkout" className="mt-4 w-full">
                    Proceed to checkout
                  </Button>
                </div>
              </>
            )}
          </motion.aside>
        </div>
      )}
    </AnimatePresence>
  );
}
