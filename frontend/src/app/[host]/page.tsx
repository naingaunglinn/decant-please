import type { Metadata } from "next";
import { getDeliveryZonesForDisplay, getMeta, getProducts } from "@/lib/api";
import { tenantPage } from "@/lib/tenant";
import { baseStyle, fallbackDesign, visibleSections } from "@/lib/design";
import { HomeSection, type SectionData } from "@/components/home/Sections";
import type { CatalogMeta } from "@/lib/types";

export const metadata: Metadata = {
  alternates: { canonical: "/" }, // composes with the tenant layout's metadataBase
};

// Empty on purpose: no host is prerendered at build (the domain list lives in the
// database), but per the Next docs an empty generateStaticParams is exactly what
// opts unlisted params into on-demand ISR — first visit renders, then the route
// cache serves it, keyed by the /{host} path, revalidated on the fetches' 60s.
// Without this the page is silently fully dynamic on every request.
export function generateStaticParams(): Array<{ host: string }> {
  return [];
}

// Step 46b: the home page is the shop's design — its section list, top to
// bottom (lib/design.ts, components/home/Sections.tsx). Every fetch is
// optional: an unreachable API drops a section, never the page.
export default async function HomePage({ params }: { params: Promise<{ host: string }> }) {
  const { host } = await params;
  const tenant = await tenantPage(host, "/");

  const meta: CatalogMeta | null = await getMeta(tenant.slug).catch(() => null);
  const design = meta?.design ?? fallbackDesign(tenant.name);
  const sections = visibleSections(design);
  const has = (type: string) => sections.some((section) => section.type === type);

  const [featured, newest, zones] = await Promise.all([
    has("featured") || has("hero") || has("recently_viewed")
      ? getProducts(tenant.slug, { featured: "1", per_page: "8" }).then((page) => page.data).catch(() => [])
      : [],
    has("product_grid")
      ? getProducts(tenant.slug, { sort: "newest", per_page: "8" }).then((page) => page.data).catch(() => [])
      : [],
    has("delivery_fees") ? getDeliveryZonesForDisplay(tenant.slug).catch(() => null) : null,
  ]);

  // A product shows in one rail only: <ViewTransition> names must be unique
  // page-wide (verify-home-rails), and the same card twice is noise anyway.
  const inFeatured = new Set(has("featured") ? featured.map((product) => product.slug) : []);
  const data: SectionData = {
    base: baseStyle(design),
    featured,
    newest: newest.filter((product) => !inFeatured.has(product.slug)),
    meta,
    zones,
  };

  return (
    <>
      {sections.map((section) => (
        <HomeSection key={section.type} section={section} data={data} />
      ))}
    </>
  );
}
