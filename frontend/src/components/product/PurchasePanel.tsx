"use client";

import { useEffect, useRef, useState, ViewTransition } from "react";
import { motion } from "motion/react";
import { OptionPicker } from "./OptionPicker";
import { SizeSelector } from "./SizeSelector";
import { ImagePlate } from "@/components/ui/ImagePlate";
import { QuantityStepper } from "@/components/ui/QuantityStepper";
import { Button } from "@/components/ui/Button";
import { useCart } from "@/hooks/useCart";
import { fullName } from "@/lib/attributes";
import { formatKyat } from "@/lib/format";
import type { Product } from "@/lib/types";

/** The product image and everything that buys it. The image lives here, not in the
 *  page, because the picked variant's own photo replaces it (a photo per colour). */
export function PurchasePanel({ fragrance }: { fragrance: Product }) {
  const firstInStock = fragrance.prices.find((p) => p.in_stock)?.id ?? null;
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

  const selectedPrice = fragrance.prices.find((p) => p.id === selected);
  const allSoldOut = firstInStock === null;
  // Decant variants are ml sizes (the size list); anything else is picked by option (step 38b).
  const byOption = fragrance.prices.some((p) => p.size_ml === null);
  const imageUrl = selectedPrice?.image_url ?? fragrance.image_url;

  const addToCart = () => {
    if (!selectedPrice) return;
    add(
      {
        variantId: selectedPrice.id,
        label: selectedPrice.label,
        name: fragrance.name,
        brandName: fragrance.brand?.name ?? null,
        slug: fragrance.slug,
        priceMmk: selectedPrice.price_mmk,
        imageUrl,
      },
      quantity,
    );
    setQuantity(1);
  };

  return (
    <>
      <ViewTransition name={`fragrance-image-${fragrance.slug}`} share="morph" default="none">
        <div className="mt-6">
          <ImagePlate
            key={imageUrl}
            src={imageUrl}
            alt={fullName(fragrance)}
            sizes="(max-width: 768px) 100vw, (max-width: 1023px) 480px, (max-width: 1279px) 640px, 720px"
            priority
          />
        </div>
      </ViewTransition>

      <div className="mt-6 flex flex-col gap-5">
        {byOption ? (
          <OptionPicker variants={fragrance.prices} selected={selectedPrice} onSelect={setSelected} />
        ) : (
          <SizeSelector prices={fragrance.prices} selected={selected} onSelect={setSelected} />
        )}

        {allSoldOut ? (
          <p className="rounded-2xl border border-rule px-5 py-4 text-sm text-muted">
            {byOption
              ? "Sold out right now — check back soon."
              : "Every size is sold out right now — check back soon, bottles restock often."}
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
                  {selectedPrice.label} · {formatKyat(selectedPrice.price_mmk * quantity)}
                </p>
              </div>
              <Button onClick={addToCart}>Add to cart</Button>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
