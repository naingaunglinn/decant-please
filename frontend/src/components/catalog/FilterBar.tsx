"use client";

import { FilterControls } from "./FilterControls";
import type { Brand, CatalogMeta } from "@/lib/types";

/** Desktop sidebar. Mobile gets the same controls in a bottom sheet. */
export function FilterBar({ brands, meta }: { brands: Brand[]; meta: CatalogMeta }) {
  return (
    <aside className="sticky top-24 hidden max-h-[calc(100dvh-7rem)] w-60 shrink-0 overflow-y-auto pb-10 pr-2 lg:block">
      <FilterControls brands={brands} meta={meta} />
    </aside>
  );
}
