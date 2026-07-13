import { FragranceCard } from "@/components/catalog/FragranceCard";
import type { Fragrance } from "@/lib/types";

/** Horizontal scroll on mobile, calm grid on desktop. */
export function FeaturedRail({ fragrances }: { fragrances: Fragrance[] }) {
  if (fragrances.length === 0) return null;

  return (
    <ul className="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6 md:mx-0 md:grid md:grid-cols-4 md:gap-6 md:overflow-visible md:px-0">
      {fragrances.slice(0, 8).map((fragrance) => (
        <li key={fragrance.id} className="w-[68vw] max-w-[280px] shrink-0 snap-start md:w-auto md:max-w-none">
          <FragranceCard fragrance={fragrance} />
        </li>
      ))}
    </ul>
  );
}
