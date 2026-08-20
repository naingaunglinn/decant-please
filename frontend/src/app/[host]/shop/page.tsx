import type { Metadata } from "next";
import { Suspense, ViewTransition } from "react";
import { getBrands, getFragrances, getMeta } from "@/lib/api";
import { tenantPage } from "@/lib/tenant";
import { Skeleton } from "@/components/ui/Skeleton";
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
type Filters = Record<string, string | undefined>;

const first = (value: string | string[] | undefined): string | undefined =>
  Array.isArray(value) ? value[0] : value;

// The catalog data streams inside a <Suspense> below, NOT a segment-level
// loading.tsx. A loading.tsx wraps the whole page — including the tenantPage()
// redirect — in a Suspense boundary, and once that boundary flushes its skeleton
// with a committed 200, a redirect thrown afterwards can no longer set a 308 and
// degrades to a client-side <meta refresh> (a secondary domain served the catalog
// on a 200 instead of 308ing to its primary). Keeping the redirect above the
// boundary makes it a real 308; the skeleton still shows while the data loads.
function ShopResultsSkeleton() {
  return (
    <>
      <header className="mb-10">
        <Skeleton className="h-9 w-40" />
        <Skeleton className="mt-2 h-4 w-56" />
      </header>

      <div className="flex gap-10">
        <div className="hidden w-60 shrink-0 flex-col gap-6 lg:flex">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full" />
          ))}
        </div>
        <ViewTransition exit="slide-down">
          <ul className="grid min-w-0 flex-1 grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <li key={i}>
                <Skeleton className="aspect-square w-full" />
                <Skeleton className="mt-4 h-4 w-2/3" />
                <Skeleton className="mt-2 h-4 w-1/2" />
              </li>
            ))}
          </ul>
        </ViewTransition>
      </div>
    </>
  );
}

async function ShopResults({
  shop,
  filters,
  flat,
}: {
  shop: string;
  filters: Filters;
  flat: Filters;
}) {
  const [fragrances, brands, meta] = await Promise.all([
    getFragrances(shop, filters),
    getBrands(shop),
    getMeta(shop),
  ]);

  return (
    <>
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
    </>
  );
}

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

  const flat: Filters = {
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

  // The tenant gate runs before the <Suspense> boundary, so a secondary-domain
  // 308 (query preserved) fires as a real redirect, not a meta refresh.
  const query = new URLSearchParams(
    Object.entries(flat).filter((entry): entry is [string, string] => entry[1] !== undefined),
  ).toString();
  const tenant = await tenantPage(host, `/shop${query ? `?${query}` : ""}`);

  return (
    <div className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-16">
      <Suspense fallback={<ShopResultsSkeleton />}>
        <ShopResults shop={tenant.slug} filters={filters} flat={flat} />
      </Suspense>
    </div>
  );
}
