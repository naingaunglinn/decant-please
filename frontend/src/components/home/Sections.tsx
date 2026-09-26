import Link from "next/link";
import type { ReactNode } from "react";
import { Hero } from "@/components/home/Hero";
import { ScrollReveal } from "@/components/home/ScrollReveal";
import { FeaturedRail } from "@/components/home/FeaturedRail";
import { RecentlyViewedRail } from "@/components/catalog/RecentlyViewed";
import { ImagePlate } from "@/components/ui/ImagePlate";
import { Pill } from "@/components/ui/Pill";
import { fullName } from "@/lib/attributes";
import { formatKyat } from "@/lib/format";
import { items, text, type BaseStyle } from "@/lib/design";
import type { CatalogMeta, DeliveryZones, DesignSection, Product } from "@/lib/types";

// Step 46b: one renderer per section type in the design's library (backend
// App\Design\Sections). Text is rendered as text — React escapes it — and a type
// this storefront doesn't know renders nothing, so an old design row never errors.

export interface SectionData {
  base: BaseStyle;
  featured: Product[];
  newest: Product[];
  meta: CatalogMeta | null;
  zones: DeliveryZones | null;
}

const WRAP = "mx-auto max-w-[1280px] px-4 sm:px-6";

function Heading({ base, children }: { base: BaseStyle; children: ReactNode }) {
  return children ? <h2 className={`${base.heading} text-ink-strong`}>{children}</h2> : null;
}

function Block({ className, children }: { className: string; children: ReactNode }) {
  return (
    <ScrollReveal>
      <section className={`${WRAP} ${className}`}>{children}</section>
    </ScrollReveal>
  );
}

export function HomeSection({ section, data }: { section: DesignSection; data: SectionData }) {
  const { props } = section;
  const { base } = data;

  switch (section.type) {
    case "announcement":
      return text(props, "text") ? (
        <div className="bg-pine px-4 py-2 text-center text-xs font-medium text-[color:var(--on-primary)]">
          {text(props, "text")}
        </div>
      ) : null;

    case "hero": {
      const image = text(props, "image");
      const product = data.featured[0] ?? null;

      return (
        <Hero
          title={text(props, "title")}
          subtitle={text(props, "subtitle")}
          button={text(props, "button")}
          trackButton={text(props, "track_button")}
          titleClass={base.heroTitle}
          visual={
            image ? (
              <ImagePlate src={image} alt="" sizes="(max-width: 768px) 100vw, 50vw" priority className={base.imageRadius} />
            ) : product ? (
              <Link href={`/product/${product.slug}`} className="group block">
                <ImagePlate
                  src={product.image_url}
                  alt={fullName(product)}
                  sizes="(max-width: 768px) 100vw, 50vw"
                  priority
                  className={base.imageRadius}
                />
                <div className="mt-4 flex items-center gap-3">
                  {product.brand && <Pill tone="muted">{product.brand.name}</Pill>}
                  <span className="text-xs font-medium uppercase tracking-[0.12em] text-ink group-hover:text-pine">
                    {product.name}
                  </span>
                </div>
              </Link>
            ) : (
              <ImagePlate src={null} alt="" className={base.imageRadius} />
            )
          }
        />
      );
    }

    case "featured":
    case "product_grid": {
      const products = section.type === "featured" ? data.featured : data.newest;
      if (products.length === 0) return null;

      return (
        <Block className="py-12 md:py-16">
          <div className="mb-8 flex items-baseline justify-between gap-4">
            <Heading base={base}>{text(props, "title")}</Heading>
            <Link
              href={section.type === "featured" ? "/shop" : "/shop?sort=newest"}
              className="text-xs font-medium uppercase tracking-[0.15em] text-pine underline-offset-4 hover:underline"
            >
              View all
            </Link>
          </div>
          <FeaturedRail fragrances={products} />
        </Block>
      );
    }

    case "recently_viewed":
      return <RecentlyViewedRail exclude={[...data.featured, ...data.newest].map((f) => f.slug)} />;

    case "steps":
      return (
        <Block className="py-12 md:py-20">
          <Heading base={base}>{text(props, "title")}</Heading>
          <ol className="mt-10 grid gap-10 md:grid-cols-3 md:gap-8">
            {items(props).map((step, i) => (
              <li key={i} className="flex flex-col items-start gap-4">
                <Pill tone="pine">{String(i + 1).padStart(2, "0")}</Pill>
                <h3 className="text-sm font-medium uppercase tracking-[0.15em]">{step.title}</h3>
                <p className="max-w-xs text-[15px] leading-[1.7] text-muted">{step.text}</p>
              </li>
            ))}
          </ol>
        </Block>
      );

    case "tiles": {
      // Today's rule: the middle tile is the dark one; brand-type tiles only
      // where the template has brand types (step 38b: decant). /meta
      // unreachable → keep them, as the page always did.
      const brandTyped = data.meta ? data.meta.brand_types.length > 0 : true;
      const tiles = items(props)
        .map((tile, i) => ({ label: tile.label ?? "", text: tile.text ?? "", link: tile.link ?? "", dark: i === 1 }))
        .filter((tile) => tile.link && (brandTyped || !tile.link.includes("brand_type")));
      if (tiles.length === 0) return null;

      return (
        <Block className="py-12 pb-20 md:py-20 md:pb-28">
          <div className="grid gap-4 md:grid-cols-3 md:gap-6">
            {tiles.map((tile, i) => (
              <Link
                key={i}
                href={tile.link}
                className={`group flex h-44 flex-col justify-end ${base.radius} border p-6 transition-colors ${
                  tile.dark
                    ? "border-pine bg-pine text-[color:var(--on-primary)]"
                    : "border-rule bg-surface-alt text-ink hover:border-pine/40"
                }`}
              >
                <span className="text-lg font-bold uppercase tracking-[0.15em]">{tile.label}</span>
                <span className={`mt-1 text-sm ${tile.dark ? "text-(color:--on-primary)/70" : "text-muted"}`}>{tile.text}</span>
                <span
                  className={`mt-3 text-xs font-medium uppercase tracking-[0.15em] underline-offset-4 group-hover:underline ${
                    tile.dark ? "" : "text-pine"
                  }`}
                >
                  Shop →
                </span>
              </Link>
            ))}
          </div>
        </Block>
      );
    }

    case "about": {
      const image = text(props, "image");

      return (
        <Block className={`grid items-center gap-10 py-12 md:py-20 ${image ? "md:grid-cols-2" : ""}`}>
          {image && <ImagePlate src={image} alt="" sizes="(max-width: 768px) 100vw, 50vw" className={base.imageRadius} />}
          <div className="flex max-w-2xl flex-col gap-5">
            <Heading base={base}>{text(props, "title")}</Heading>
            <p className="whitespace-pre-line text-[15px] leading-[1.8] text-muted">{text(props, "text")}</p>
          </div>
        </Block>
      );
    }

    case "contact": {
      const social = [
        { label: "Facebook", href: data.meta?.social.facebook_url },
        { label: "TikTok", href: data.meta?.social.tiktok_url },
      ].filter((link): link is { label: string; href: string } => typeof link.href === "string" && link.href !== "");
      if (!text(props, "title") && !text(props, "text") && social.length === 0) return null;

      return (
        <Block className="flex flex-col gap-5 py-12 md:py-16">
          <Heading base={base}>{text(props, "title")}</Heading>
          {text(props, "text") && (
            <p className="max-w-2xl whitespace-pre-line text-[15px] leading-[1.7] text-muted">{text(props, "text")}</p>
          )}
          {social.length > 0 && (
            <div className="flex flex-wrap gap-3">
              {social.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex min-h-11 items-center rounded-full border border-pine px-6 text-xs font-medium uppercase tracking-[0.15em] text-pine hover:bg-pine-soft"
                >
                  {link.label}
                </a>
              ))}
            </div>
          )}
        </Block>
      );
    }

    case "location": {
      const address = text(props, "address");
      const map = text(props, "map_link");
      // The validator allows only https maps hosts on write; check the scheme again on render.
      const mapHref = map.startsWith("https://") ? map : "";
      if (!address && !mapHref) return null;

      return (
        <Block className="flex flex-col gap-5 py-12 md:py-16">
          <Heading base={base}>{text(props, "title")}</Heading>
          {address && <p className="max-w-xl whitespace-pre-line text-[15px] leading-[1.7] text-ink">{address}</p>}
          {mapHref && (
            <a
              href={mapHref}
              target="_blank"
              rel="noopener noreferrer"
              className="text-xs font-medium uppercase tracking-[0.15em] text-pine underline underline-offset-4"
            >
              Open in Maps →
            </a>
          )}
        </Block>
      );
    }

    case "delivery_fees": {
      const regions = (data.zones?.regions ?? [])
        .map((region) => {
          const fees = region.townships.map((township) => township.fee_mmk);
          return { label: region.label, min: Math.min(...fees), max: Math.max(...fees) };
        })
        .filter((region) => Number.isFinite(region.min));
      if (regions.length === 0) return null;

      return (
        <Block className="py-12 md:py-16">
          <Heading base={base}>{text(props, "title")}</Heading>
          <ul className="mt-8 divide-y divide-rule border-y border-rule">
            {regions.map((region) => (
              <li key={region.label} className="flex items-baseline justify-between gap-4 py-3 text-[15px]">
                <span className="text-ink">{region.label}</span>
                <span className="whitespace-nowrap text-muted">
                  {region.min === region.max
                    ? formatKyat(region.min)
                    : `${formatKyat(region.min)} – ${formatKyat(region.max)}`}
                </span>
              </li>
            ))}
          </ul>
        </Block>
      );
    }

    case "order_tracking":
      return (
        <Block className="py-12 md:py-16">
          <Link
            href="/track"
            className={`group flex flex-col gap-2 ${base.radius} border border-rule bg-surface-alt p-6 hover:border-pine/40`}
          >
            <Heading base={base}>{text(props, "title")}</Heading>
            {text(props, "text") && <span className="text-[15px] text-muted">{text(props, "text")}</span>}
            <span className="mt-2 text-xs font-medium uppercase tracking-[0.15em] text-pine group-hover:underline">
              Track →
            </span>
          </Link>
        </Block>
      );

    // category_nav: the shop's categories have no admin screen or public API
    // yet (group 3), so no shop has any — the section hides, as it does for a
    // shop without categories.
    default:
      return null;
  }
}
