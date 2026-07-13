import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { ViewTransition } from "react";
import { getFragrance, getFragrances } from "@/lib/api";
import { ImagePlate } from "@/components/ui/ImagePlate";
import { Pill } from "@/components/ui/Pill";
import { PurchasePanel } from "@/components/product/PurchasePanel";
import { FragranceCard } from "@/components/catalog/FragranceCard";
import { RecordRecentlyViewed } from "@/components/catalog/RecentlyViewed";
import type { Fragrance } from "@/lib/types";

/** Same-brand siblings first; if the brand is thin, top up with same-gender picks. */
async function getRelated(fragrance: Fragrance): Promise<Fragrance[]> {
  try {
    const sameBrand = (
      await getFragrances({ brand: fragrance.brand.slug, per_page: "8" })
    ).data.filter((f) => f.id !== fragrance.id);

    if (sameBrand.length >= 2) return sameBrand.slice(0, 4);

    const sameGender = (
      await getFragrances({ gender: fragrance.gender, per_page: "8" })
    ).data.filter((f) => f.id !== fragrance.id && !sameBrand.some((b) => b.id === f.id));

    return [...sameBrand, ...sameGender].slice(0, 4);
  } catch {
    return []; // the detail page stands without the rail
  }
}

interface PageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const fragrance = await getFragrance(slug);
  // metadata resolves before the loading.tsx shell streams, so throwing here
  // yields a real 404 status instead of a soft 200
  if (!fragrance) notFound();

  return {
    title: `${fragrance.brand.name} ${fragrance.name}`,
    description:
      fragrance.description ??
      `${fragrance.brand.name} ${fragrance.name} (${fragrance.concentration_label}) — decants from ${fragrance.min_price_formatted ?? "—"}.`,
  };
}

const splitList = (value: string | null): string[] =>
  value ? value.split(",").map((part) => part.trim()).filter(Boolean) : [];

export default async function FragrancePage({ params }: PageProps) {
  const { slug } = await params;
  const fragrance = await getFragrance(slug);
  if (!fragrance) notFound();

  const notes = splitList(fragrance.notes);
  const vibes = splitList(fragrance.vibes);
  const related = await getRelated(fragrance);

  return (
    <article className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16">
      <RecordRecentlyViewed slug={fragrance.slug} />
      {/* brand pill → name (concentration in pine) — the reference card, rebuilt */}
      <header className="flex flex-col items-start gap-4">
        <Pill>{fragrance.brand.name}</Pill>
        <div className="w-full rounded-2xl border border-rule px-6 py-5">
          <h1 className="text-[26px] font-bold uppercase leading-tight tracking-[0.1em] text-ink-strong sm:text-[30px]">
            {fragrance.name}{" "}
            <span className="font-medium text-pine">{fragrance.concentration_label}</span>
          </h1>
        </div>
      </header>

      <ViewTransition name={`fragrance-image-${fragrance.slug}`} share="morph" default="none">
        <div className="mt-6">
          <ImagePlate
            src={fragrance.image_url}
            alt={`${fragrance.brand.name} ${fragrance.name}`}
            sizes="(max-width: 768px) 100vw, 480px"
            priority
          />
        </div>
      </ViewTransition>

      <div className="mt-6">
        <PurchasePanel fragrance={fragrance} />
      </div>

      <div className="mt-6 flex flex-wrap gap-2">
        {fragrance.performance && <Pill tone="pine">{fragrance.performance}</Pill>}
        <Pill>{fragrance.gender_label}</Pill>
        <Pill>{fragrance.concentration_label}</Pill>
        <Pill tone="muted">{fragrance.brand.type_label}</Pill>
      </div>

      {notes.length > 0 && (
        <section className="mt-10">
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">Notes</h2>
          <div className="mt-3 flex flex-wrap gap-2">
            {notes.map((note) => (
              <Pill key={note}>{note}</Pill>
            ))}
          </div>
        </section>
      )}

      {vibes.length > 0 && (
        <section className="mt-8">
          <h2 className="text-[11px] font-medium uppercase tracking-[0.18em] text-muted">Vibes</h2>
          <div className="mt-3 flex flex-wrap gap-2">
            {vibes.map((vibe) => (
              <Pill key={vibe} tone="soft">
                {vibe}
              </Pill>
            ))}
          </div>
        </section>
      )}

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
          <div className="mt-5 grid grid-cols-2 gap-4">
            {related.map((item) => (
              <FragranceCard key={item.id} fragrance={item} />
            ))}
          </div>
        </section>
      )}
    </article>
  );
}
