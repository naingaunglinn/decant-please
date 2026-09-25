import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getMeta, getProduct, getProducts } from "@/lib/api";
import { tenantPage } from "@/lib/tenant";
import { fullName, headlineOf, listsOf, pillsOf, sectionsOf, splitList } from "@/lib/attributes";
import { Pill } from "@/components/ui/Pill";
import { PurchasePanel } from "@/components/product/PurchasePanel";
import { FragranceCard } from "@/components/catalog/FragranceCard";
import { RecordRecentlyViewed } from "@/components/catalog/RecentlyViewed";
import type { CatalogMeta, Product } from "@/lib/types";

/** Same-brand siblings first; if the brand is thin (or there is none), top up with products sharing its
 *  first select filter (decant: gender). Only a /meta filter key is sent — the API
 *  ignores any other attribute, which would top up with unrelated products. */
async function getRelated(shop: string, fragrance: Product, meta: CatalogMeta | null): Promise<Product[]> {
  let sameBrand: Product[] = [];
  try {
    // a brandless product (step 38b) has no siblings by brand — straight to the top-up
    if (fragrance.brand) {
      sameBrand = (
        await getProducts(shop, { brand: fragrance.brand.slug, per_page: "8" })
      ).data.filter((f) => f.id !== fragrance.id);
    }

    if (sameBrand.length >= 2) return sameBrand.slice(0, 4);

    if (!meta) return sameBrand;
    const shared = meta.filters
      .filter((filter) => filter.type === "select")
      .map((filter) => fragrance.attributes.find((attribute) => attribute.key === filter.key))
      .find((attribute) => attribute !== undefined);
    if (!shared) return sameBrand;

    const sameAttribute = (
      await getProducts(shop, { [shared.key]: String(shared.value), per_page: "8" })
    ).data.filter((f) => f.id !== fragrance.id && !sameBrand.some((b) => b.id === f.id));

    return [...sameBrand, ...sameAttribute].slice(0, 4);
  } catch {
    return sameBrand; // the detail page stands with whatever the rail already has
  }
}

interface PageProps {
  params: Promise<{ host: string; slug: string }>;
}

// Empty on purpose — on-demand ISR per {host}/{slug} path (see the home page's
// note): the tenant is in the cache key by construction, revalidate rides the
// 60s catalog fetches.
export function generateStaticParams(): Array<{ host: string; slug: string }> {
  return [];
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { host, slug } = await params;
  // tenantPage here too: metadata resolves first, so the secondary-domain 308
  // fires before any body work; resolveTenant is cached, the page's call is free
  const tenant = await tenantPage(host, `/product/${slug}`);
  const fragrance = await getProduct(tenant.slug, slug);
  // metadata resolves before the loading.tsx shell streams, so throwing here
  // yields a real 404 status instead of a soft 200
  if (!fragrance) notFound();
  const headline = headlineOf(fragrance);

  return {
    title: fullName(fragrance),
    description:
      fragrance.description ??
      `${fullName(fragrance)}${headline ? ` (${headline.display})` : ""} — ${fragrance.template === "decant" ? "decants " : ""}from ${fragrance.min_price_formatted ?? "—"}.`,
    alternates: { canonical: `/product/${slug}` },
  };
}

export default async function ProductPage({ params }: PageProps) {
  const { host, slug } = await params;
  const tenant = await tenantPage(host, `/product/${slug}`);
  const fragrance = await getProduct(tenant.slug, slug);
  if (!fragrance) notFound();

  const headline = headlineOf(fragrance);
  const lists = listsOf(fragrance)
    .map((attribute) => ({ ...attribute, items: splitList(attribute.display) }))
    .filter((attribute) => attribute.items.length > 0);
  // /meta is the shop-level context: related top-up filters, and whether brand types
  // mean anything here (decant only, step 38b). Cached; the page stands without it.
  const meta = await getMeta(tenant.slug).catch(() => null);
  const related = await getRelated(tenant.slug, fragrance, meta);
  // if /meta is unreachable, a typed brand keeps its pill — decant's page as it always was
  const showBrandType = Boolean(fragrance.brand?.type_label) && (meta?.brand_types.length ?? 1) > 0;

  return (
    <article className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16 lg:max-w-[640px] xl:max-w-[720px]">
      <RecordRecentlyViewed slug={fragrance.slug} />
      {/* brand pill → name (the template's headline attribute in pine) — the reference card, rebuilt */}
      <header className="flex flex-col items-start gap-4">
        {fragrance.brand && <Pill>{fragrance.brand.name}</Pill>}
        <div className="w-full rounded-2xl border border-rule px-6 py-5">
          <h1 className="text-[26px] font-bold uppercase leading-tight tracking-[0.1em] text-ink-strong sm:text-[30px]">
            {fragrance.name}
            {headline && <span className="font-medium text-pine"> {headline.display}</span>}
          </h1>
        </div>
      </header>

      <PurchasePanel fragrance={fragrance} />

      <div className="mt-6 flex flex-wrap gap-2">
        {pillsOf(fragrance).map((attribute) => (
          <Pill key={attribute.key}>{attribute.display}</Pill>
        ))}
        {showBrandType && <Pill tone="muted">{fragrance.brand?.type_label}</Pill>}
      </div>

      {lists.map((attribute, index) => (
        <section key={attribute.key} className={index === 0 ? "mt-10" : "mt-8"}>
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
            {attribute.label}
          </h2>
          <div className="mt-3 flex flex-wrap gap-2">
            {attribute.items.map((item, index) => (
              <Pill key={`${index}-${item}`}>{item}</Pill>
            ))}
          </div>
        </section>
      ))}

      {sectionsOf(fragrance).map((attribute) => (
        <section key={attribute.key} className="mt-10">
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
            {attribute.label}
          </h2>
          <p className="mt-3 whitespace-pre-line rounded-2xl border border-rule px-5 py-4 text-[15px] leading-[1.7] text-ink">
            {attribute.display}
          </p>
        </section>
      ))}

      {fragrance.description && (
        <section className="mt-10 border-t border-rule pt-8">
          <p className="text-[15px] leading-[1.7] text-ink">{fragrance.description}</p>
        </section>
      )}

      {related.length > 0 && (
        <section className="mt-12 border-t border-rule pt-10">
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
            You may also like
          </h2>
          <div className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-3">
            {related.map((item) => (
              <FragranceCard key={item.id} fragrance={item} />
            ))}
          </div>
        </section>
      )}
    </article>
  );
}
