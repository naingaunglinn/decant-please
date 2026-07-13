"use client";

import { useEffect, useRef, useState } from "react";
import { motion } from "motion/react";
import { SizeSelector } from "./SizeSelector";
import { QuantityStepper } from "@/components/ui/QuantityStepper";
import { Button } from "@/components/ui/Button";
import { useCart } from "@/hooks/useCart";
import { formatKyat } from "@/lib/format";
import type { Fragrance } from "@/lib/types";

export function PurchasePanel({ fragrance }: { fragrance: Fragrance }) {
  const firstInStock = fragrance.prices.find((p) => p.in_stock)?.size_ml ?? null;
  const [selected, setSelected] = useState<number | null>(firstInStock);
  const [quantity, setQuantity] = useState(1);
  const { add } = useCart();

  // sticky mobile bar once the main button scrolls out of view
  const buttonRef = useRef<HTMLDivElement>(null);
  const [showSticky, setShowSticky] = useState(false);

  useEffect(() => {
    const target = buttonRef.current;
    if (!target) return;
    const observer = new IntersectionObserver(
      ([entry]) => setShowSticky(!entry.isIntersecting && entry.boundingClientRect.top < 0),
      { threshold: 0 },
    );
    observer.observe(target);
    return () => observer.disconnect();
  }, []);

  const selectedPrice = fragrance.prices.find((p) => p.size_ml === selected);
  const allSoldOut = firstInStock === null;

  const addToCart = () => {
    if (!selectedPrice) return;
    add(
      {
        fragranceId: fragrance.id,
        sizeMl: selectedPrice.size_ml,
        name: fragrance.name,
        brandName: fragrance.brand.name,
        slug: fragrance.slug,
        priceMmk: selectedPrice.price_mmk,
        imageUrl: fragrance.image_url,
      },
      quantity,
    );
    setQuantity(1);
  };

  return (
    <div className="flex flex-col gap-5">
      <SizeSelector prices={fragrance.prices} selected={selected} onSelect={setSelected} />

      {allSoldOut ? (
        <p className="rounded-2xl border border-rule px-5 py-4 text-sm text-muted">
          Every size is sold out right now — check back soon, bottles restock often.
        </p>
      ) : (
        <div ref={buttonRef} className="flex items-center gap-4">
          <QuantityStepper value={quantity} onChange={setQuantity} />
          <motion.div whileTap={{ scale: 0.97 }} className="flex-1">
            <Button className="w-full" onClick={addToCart} disabled={!selectedPrice}>
              Add to cart
              {selectedPrice && ` — ${formatKyat(selectedPrice.price_mmk * quantity)}`}
            </Button>
          </motion.div>
        </div>
      )}

      {/* sticky mobile price bar */}
      {showSticky && selectedPrice && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-rule bg-mist/95 px-4 py-3 backdrop-blur md:hidden">
          <div className="mx-auto flex max-w-[480px] items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="truncate text-xs font-medium uppercase tracking-[0.12em]">{fragrance.name}</p>
              <p className="text-sm text-pine">
                {selectedPrice.size_ml}ml · {formatKyat(selectedPrice.price_mmk * quantity)}
              </p>
            </div>
            <Button onClick={addToCart}>Add to cart</Button>
          </div>
        </div>
      )}
    </div>
  );
}
