import type { Metadata } from "next";
import Link from "next/link";
import { getFragrances } from "@/lib/api";
import { tenantPage } from "@/lib/tenant";
import { Hero } from "@/components/home/Hero";
import { ScrollReveal } from "@/components/home/ScrollReveal";
import { FeaturedRail } from "@/components/home/FeaturedRail";
import { RecentlyViewedRail } from "@/components/catalog/RecentlyViewed";
import { ImagePlate } from "@/components/ui/ImagePlate";
import { Pill } from "@/components/ui/Pill";
import type { Fragrance } from "@/lib/types";

const STEPS = [
  {
    number: "01",
    title: "Browse",
    line: "Filter the whole catalog by brand, notes, vibe, size and budget.",
  },
  {
    number: "02",
    title: "Checkout",
    line: "No account, no card. We confirm by phone, then you pay by transfer or on delivery.",
  },
  {
    number: "03",
    title: "Track",
    line: "Your code follows the vial from decanting day to your door.",
  },
];

const TILES = [
  { label: "Designer", sub: "The houses you know", href: "/shop?brand_type=designer", dark: false },
  { label: "Niche", sub: "The ones worth hunting", href: "/shop?brand_type=niche", dark: true },
  { label: "Everything", sub: "The full shelf", href: "/shop", dark: false },
];

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

export default async function HomePage({ params }: { params: Promise<{ host: string }> }) {
  const { host } = await params;
  const tenant = await tenantPage(host, "/");

  let featured: Fragrance[] = [];
  try {
    featured = (await getFragrances(tenant.slug, { featured: "1", per_page: "8" })).data;
  } catch {
    // API unreachable (e.g. at build time) — the page still stands without the rail
  }

  const heroFragrance = featured[0] ?? null;

  return (
    <>
      <Hero
        visual={
          heroFragrance ? (
            <Link href={`/fragrance/${heroFragrance.slug}`} className="group block">
              <ImagePlate
                src={heroFragrance.image_url}
                alt={`${heroFragrance.brand.name} ${heroFragrance.name}`}
                sizes="(max-width: 768px) 100vw, 50vw"
                priority
              />
              <div className="mt-4 flex items-center gap-3">
                <Pill tone="muted">{heroFragrance.brand.name}</Pill>
                <span className="text-xs font-medium uppercase tracking-[0.12em] text-ink group-hover:text-pine">
                  {heroFragrance.name}
                </span>
              </div>
            </Link>
          ) : (
            <ImagePlate src={null} alt="" />
          )
        }
      />

      {featured.length > 0 && (
        <ScrollReveal>
          <section className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-16">
            <div className="mb-8 flex items-baseline justify-between gap-4">
              <h2 className="text-xl font-bold uppercase tracking-[0.12em] text-ink-strong sm:text-2xl">
                Featured
              </h2>
              <Link
                href="/shop"
                className="text-xs font-medium uppercase tracking-[0.15em] text-pine underline-offset-4 hover:underline"
              >
                View all
              </Link>
            </div>
            <FeaturedRail fragrances={featured} />
          </section>
        </ScrollReveal>
      )}

      <RecentlyViewedRail exclude={featured.map((f) => f.slug)} />

      <ScrollReveal>
        <section className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-20">
          <h2 className="text-xl font-bold uppercase tracking-[0.12em] text-ink-strong sm:text-2xl">
            How it works
          </h2>
          <ol className="mt-10 grid gap-10 md:grid-cols-3 md:gap-8">
            {STEPS.map((step) => (
              <li key={step.number} className="flex flex-col items-start gap-4">
                <Pill tone="pine">{step.number}</Pill>
                <h3 className="text-sm font-medium uppercase tracking-[0.15em]">{step.title}</h3>
                <p className="max-w-xs text-[15px] leading-[1.7] text-muted">{step.line}</p>
              </li>
            ))}
          </ol>
        </section>
      </ScrollReveal>

      <ScrollReveal>
        <section className="mx-auto max-w-[1280px] px-4 py-12 pb-20 sm:px-6 md:py-20 md:pb-28">
          <div className="grid gap-4 md:grid-cols-3 md:gap-6">
            {TILES.map((tile) => (
              <Link
                key={tile.label}
                href={tile.href}
                className={`group flex h-44 flex-col justify-end rounded-2xl border p-6 transition-colors ${
                  tile.dark
                    ? "border-pine bg-pine text-mist"
                    : "border-rule bg-surface-alt text-ink hover:border-pine/40"
                }`}
              >
                <span className="text-lg font-bold uppercase tracking-[0.15em]">{tile.label}</span>
                <span className={`mt-1 text-sm ${tile.dark ? "text-mist/70" : "text-muted"}`}>
                  {tile.sub}
                </span>
                <span
                  className={`mt-3 text-xs font-medium uppercase tracking-[0.15em] underline-offset-4 group-hover:underline ${
                    tile.dark ? "text-mist" : "text-pine"
                  }`}
                >
                  Shop →
                </span>
              </Link>
            ))}
          </div>
        </section>
      </ScrollReveal>
    </>
  );
}
