"use client";

import { useCart } from "@/hooks/useCart";

export function CartButton() {
  const { count, openCart, hydrated } = useCart();

  return (
    <button
      type="button"
      onClick={openCart}
      aria-label={`Open cart${hydrated && count > 0 ? ` (${count} items)` : ""}`}
      className="relative flex size-11 items-center justify-center rounded-full transition-colors hover:bg-pine-soft"
    >
      {/* a vial, not a trolley */}
      <svg width="18" height="22" viewBox="0 0 18 22" fill="none" aria-hidden>
        <rect x="5.5" y="0.75" width="7" height="3" rx="1" stroke="currentColor" strokeWidth="1.5" />
        <rect x="3.75" y="5.75" width="10.5" height="15.5" rx="4" stroke="currentColor" strokeWidth="1.5" />
        <path d="M4.5 13.5h9v4.5a3.75 3.75 0 0 1-3.75 3.75h-1.5a3.75 3.75 0 0 1-3.75-3.75z" fill="currentColor" opacity="0.25" />
      </svg>
      {hydrated && count > 0 && (
        <span className="absolute -right-0.5 -top-0.5 flex size-5 items-center justify-center rounded-full bg-pine text-[10px] font-medium text-mist">
          {count > 9 ? "9+" : count}
        </span>
      )}
    </button>
  );
}
