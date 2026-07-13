"use client";

import { CartItemRow } from "@/components/cart/CartItemRow";
import { useCart, cartLineKey } from "@/hooks/useCart";
import { formatKyat } from "@/lib/format";

export function OrderSummaryCard({ lineErrors }: { lineErrors: Record<number, string> }) {
  const { lines, subtotal } = useCart();

  return (
    <aside className="rounded-2xl border border-rule px-6 py-5 md:sticky md:top-24">
      <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
        Your order
      </h2>

      <ul className="mt-4">
        {lines.map((line, index) => (
          <CartItemRow key={cartLineKey(line)} line={line} error={lineErrors[index]} />
        ))}
      </ul>

      <div className="mt-4 flex items-baseline justify-between">
        <span className="text-xs uppercase tracking-[0.18em] text-muted">Subtotal</span>
        <span className="text-lg font-medium tabular-nums">{formatKyat(subtotal)}</span>
      </div>
      <p className="mt-1 text-xs leading-relaxed text-muted">
        Final total is confirmed by the decanter — delivery fee, if any, is agreed when we
        call you.
      </p>
    </aside>
  );
}
