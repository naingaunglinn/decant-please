import Link from "next/link";
import { ViewTransition } from "react";
import { ImagePlate } from "@/components/ui/ImagePlate";
import { Pill } from "@/components/ui/Pill";
import type { Fragrance } from "@/lib/types";

export function FragranceCard({ fragrance }: { fragrance: Fragrance }) {
  return (
    <Link href={`/fragrance/${fragrance.slug}`} className="group block">
      <ViewTransition name={`fragrance-image-${fragrance.slug}`} share="morph" default="none">
        <div className="transition-transform duration-300 ease-out group-hover:-translate-y-1">
          <ImagePlate src={fragrance.image_url} alt={fragrance.name} />
        </div>
      </ViewTransition>

      <div className="mt-4 flex flex-col items-start gap-2">
        <Pill tone="muted">{fragrance.brand.name}</Pill>
        <h3 className="text-sm font-medium uppercase leading-snug tracking-[0.12em] text-ink group-hover:text-pine">
          {fragrance.name}
          <span className="ml-2 text-muted">{fragrance.concentration_label}</span>
        </h3>
        <p className="text-sm text-pine">
          {fragrance.min_price_formatted
            ? `From ${fragrance.min_price_formatted}`
            : "Out of stock"}
        </p>
      </div>
    </Link>
  );
}
