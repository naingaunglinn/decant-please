"use client";

import { useEffect, useState, type FormEvent } from "react";
import { CartItemRow } from "@/components/cart/CartItemRow";
import { Pill } from "@/components/ui/Pill";
import { useCart, cartLineKey } from "@/hooks/useCart";
import { validatePromo, ApiValidationError } from "@/lib/api";
import { formatKyat } from "@/lib/format";
import type { CheckoutItem } from "@/lib/types";

interface AppliedPromo {
  code: string;
  discount_mmk: number;
  discount_formatted: string;
  new_total_formatted: string;
}

interface OrderSummaryCardProps {
  lineErrors: Record<number, string>;
  /** Reports the applied code up so CheckoutClient can send it with the order. */
  onPromoChange: (code: string | null) => void;
}

export function OrderSummaryCard({ lineErrors, onPromoChange }: OrderSummaryCardProps) {
  const { lines, subtotal } = useCart();
  const [input, setInput] = useState("");
  const [applied, setApplied] = useState<AppliedPromo | null>(null);
  const [promoError, setPromoError] = useState<string | null>(null);
  const [checking, setChecking] = useState(false);

  const cartItems = (): CheckoutItem[] =>
    lines.map((line) => ({
      fragrance_id: line.fragranceId,
      size_ml: line.sizeMl,
      quantity: line.quantity,
    }));

  const apply = async (event: FormEvent) => {
    event.preventDefault();
    const code = input.trim();
    if (!code || checking) return;

    setChecking(true);
    setPromoError(null);
    try {
      const preview = await validatePromo(code, cartItems());
      if (preview.valid) {
        setApplied({
          code: code.toUpperCase(),
          discount_mmk: preview.discount_mmk,
          discount_formatted: preview.discount_formatted,
          new_total_formatted: preview.new_total_formatted,
        });
        setInput("");
        onPromoChange(code.toUpperCase());
      } else {
        // the backend says exactly what's wrong — show that, not a generic line
        setPromoError(preview.message ?? "That code can't be applied.");
      }
    } catch (error) {
      setPromoError(
        error instanceof ApiValidationError
          ? "One of your items just changed availability — review your cart first."
          : "We couldn't check that code just now — try again in a moment.",
      );
    } finally {
      setChecking(false);
    }
  };

  const remove = () => {
    setApplied(null);
    setPromoError(null);
    onPromoChange(null);
  };

  // cart edited after a code was applied → re-preview so the shown discount stays honest
  const linesSignature = lines.map((line) => `${cartLineKey(line)}:${line.quantity}`).join("|");
  useEffect(() => {
    if (!applied) return;
    if (lines.length === 0) {
      remove();
      return;
    }
    let stale = false;
    validatePromo(applied.code, cartItems())
      .then((preview) => {
        if (stale) return;
        if (preview.valid) {
          setApplied((current) =>
            current && {
              ...current,
              discount_mmk: preview.discount_mmk,
              discount_formatted: preview.discount_formatted,
              new_total_formatted: preview.new_total_formatted,
            },
          );
        } else {
          remove();
          setPromoError(preview.message ?? "That code no longer applies to your cart.");
        }
      })
      .catch(() => {
        // network hiccup — keep the code; checkout re-validates authoritatively anyway
      });
    return () => {
      stale = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [linesSignature]);

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

      {/* promo code — preview only; the server re-validates at submission */}
      {applied ? (
        <div className="mt-3 flex items-baseline justify-between gap-3 rounded-xl bg-pine-soft px-4 py-3">
          <span className="flex items-center gap-2 text-xs">
            <Pill tone="pine" className="font-mono">{applied.code}</Pill>
            <span className="text-pine">applied</span>
          </span>
          <span className="flex items-center gap-3">
            <span className="text-sm font-medium tabular-nums text-pine">
              −{applied.discount_formatted}
            </span>
            <button
              type="button"
              onClick={remove}
              aria-label={`Remove promo code ${applied.code}`}
              className="text-xs uppercase tracking-[0.15em] text-muted underline-offset-4 hover:underline"
            >
              Remove
            </button>
          </span>
        </div>
      ) : (
        <form onSubmit={apply} className="mt-3 flex gap-2">
          <input
            value={input}
            onChange={(event) => setInput(event.target.value.toUpperCase())}
            placeholder="Promo code"
            aria-label="Promo code"
            autoComplete="off"
            className="min-w-0 flex-1 rounded-full border border-rule bg-transparent px-4 py-2 font-mono text-sm uppercase tracking-[0.15em] placeholder:font-sans placeholder:normal-case placeholder:tracking-normal placeholder:text-muted/70"
          />
          <button
            type="submit"
            disabled={checking || input.trim() === ""}
            className="rounded-full border border-pine px-4 py-2 text-xs font-medium uppercase tracking-[0.15em] text-pine transition-colors hover:bg-pine-soft disabled:cursor-not-allowed disabled:opacity-40"
          >
            {checking ? "…" : "Apply"}
          </button>
        </form>
      )}
      {promoError && (
        <p role="alert" className="mt-2 text-xs leading-relaxed text-status-danger">
          {promoError}
        </p>
      )}

      {applied && (
        <div className="mt-3 flex items-baseline justify-between">
          <span className="text-xs uppercase tracking-[0.18em] text-muted">After discount</span>
          <span className="text-lg font-medium tabular-nums">{applied.new_total_formatted}</span>
        </div>
      )}

      <p className="mt-1 text-xs leading-relaxed text-muted">
        Final total is confirmed by the decanter — delivery fee, if any, is agreed when we
        call you.
      </p>
    </aside>
  );
}
