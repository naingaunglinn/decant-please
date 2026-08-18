import type { Metadata } from "next";
import { ViewTransition } from "react";
import { getBrands, getFragrances, getMeta } from "@/lib/api";
import { tenantPage } from "@/lib/tenant";
import { FragranceGrid } from "@/components/catalog/FragranceGrid";
import { FilterBar } from "@/components/catalog/FilterBar";
import { FilterSheet } from "@/components/catalog/FilterSheet";
import { Pagination } from "@/components/catalog/Pagination";

export const metadata: Metadata = {
  title: "Shop decants",
  description:
    "Browse every fragrance we decant — filter by brand, scent notes, gender, size and budget.",
  alternates: { canonical: "/shop" }, // filters deliberately excluded from the canonical
};

type SearchParams = { [key: string]: string | string[] | undefined };

const first = (value: string | string[] | undefined): string | undefined =>
  Array.isArray(value) ? value[0] : value;

export default async function ShopPage({
  params: routeParams,
  searchParams,
}: {
  params: Promise<{ host: string }>;
  searchParams: Promise<SearchParams>;
}) {
  const { host } = await routeParams;
  const params = await searchParams;

  const filters = {
    q: first(params.q),
    notes: first(params.notes),
    brand: first(params.brand),
    type: first(params.brand_type),
    gender: first(params.gender),
    size: first(params.size),
    min_price: first(params.min_price),
    max_price: first(params.max_price),
    sort: first(params.sort),
    page: first(params.page),
    per_page: "12",
  };

  const flat: Record<string, string | undefined> = {
    q: filters.q,
    notes: filters.notes,
    brand: filters.brand,
    brand_type: first(params.brand_type),
    gender: filters.gender,
    size: filters.size,
    min_price: filters.min_price,
    max_price: filters.max_price,
    sort: filters.sort,
    page: filters.page,
  };

  // a secondary-domain 308 keeps the whole filtered view, not just /shop
  const query = new URLSearchParams(
    Object.entries(flat).filter((entry): entry is [string, string] => entry[1] !== undefined),
  ).toString();
  const tenant = await tenantPage(host, `/shop${query ? `?${query}` : ""}`);

  const [fragrances, brands, meta] = await Promise.all([
    getFragrances(tenant.slug, filters),
    getBrands(tenant.slug),
    getMeta(tenant.slug),
  ]);

  return (
    <div className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-16">
      <header className="mb-10 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong sm:text-[32px]">
            Shop
          </h1>
          <p className="mt-1 text-sm text-muted">
            {fragrances.meta.total} fragrance{fragrances.meta.total === 1 ? "" : "s"}, decanted to order
          </p>
        </div>
        <FilterSheet brands={brands} meta={meta} />
      </header>

      <div className="flex gap-10">
        <FilterBar brands={brands} meta={meta} />

        <div className="min-w-0 flex-1">
          <ViewTransition enter="slide-up" default="none">
            <div>
              <FragranceGrid fragrances={fragrances.data} />
              <Pagination meta={fragrances.meta} searchParams={flat} />
            </div>
          </ViewTransition>
        </div>
      </div>
    </div>
  );
}
