"use client";

import Image from "next/image";
import Link from "next/link";
import { QuantityStepper } from "@/components/ui/QuantityStepper";
import { Pill } from "@/components/ui/Pill";
import { useCart, cartLineKey } from "@/hooks/useCart";
import { formatKyat } from "@/lib/format";
import type { CartLine } from "@/lib/types";

export function CartItemRow({ line, error }: { line: CartLine; error?: string }) {
  const { updateQuantity, remove } = useCart();
  const key = cartLineKey(line);

  return (
    <li className="border-b border-rule py-5 first:pt-0">
      <div className="flex gap-4">
        <div className="relative size-16 shrink-0 overflow-hidden rounded-xl bg-surface-alt">
          {line.imageUrl && (
            <Image src={line.imageUrl} alt="" fill sizes="64px" className="object-cover" />
          )}
        </div>

        <div className="min-w-0 flex-1">
          <p className="text-[11px] uppercase tracking-[0.18em] text-muted">{line.brandName}</p>
          <Link
            href={`/fragrance/${line.slug}`}
            className="mt-0.5 block truncate text-sm font-medium uppercase tracking-[0.1em] text-ink hover:text-pine"
          >
            {line.name}
          </Link>
          <div className="mt-2 flex items-center gap-2">
            <Pill tone="muted">{line.sizeMl}ml</Pill>
            <span className="text-sm text-pine">{formatKyat(line.priceMmk)}</span>
          </div>
        </div>
      </div>

      <div className="mt-3 flex items-center justify-between">
        <QuantityStepper
          compact
          value={line.quantity}
          onChange={(quantity) => updateQuantity(key, quantity)}
          label={`Quantity of ${line.name} ${line.sizeMl}ml`}
        />
        <div className="flex items-center gap-4">
          <span className="text-sm font-medium tabular-nums">
            {formatKyat(line.priceMmk * line.quantity)}
          </span>
          <button
            type="button"
            onClick={() => remove(key)}
            className="inline-flex min-h-11 items-center text-xs uppercase tracking-[0.15em] text-muted underline-offset-4 transition-colors hover:text-status-danger hover:underline"
          >
            Remove
          </button>
        </div>
      </div>

      {error && (
        <p role="alert" className="mt-2 rounded-xl bg-status-danger/10 px-3 py-2 text-xs text-status-danger">
          {error}
        </p>
      )}
    </li>
  );
}
