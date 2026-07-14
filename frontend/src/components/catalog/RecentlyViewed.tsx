"use client";

import { useEffect, useState } from "react";
import { FragranceCard } from "./FragranceCard";
import { getFragrance } from "@/lib/api";
import type { Fragrance } from "@/lib/types";

const KEY = "decant-please.recently-viewed.v1";
const LIMIT = 6;

function readSlugs(): string[] {
  try {
    const raw = window.localStorage.getItem(KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((s): s is string => typeof s === "string") : [];
  } catch {
    return [];
  }
}

function writeSlugs(slugs: string[]): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(slugs.slice(0, LIMIT)));
  } catch {
    // storage unavailable — recently-viewed just stays empty
  }
}

/** Drop-in for the fragrance detail page: records the visit, renders nothing. */
export function RecordRecentlyViewed({ slug }: { slug: string }) {
  useEffect(() => {
    writeSlugs([slug, ...readSlugs().filter((s) => s !== slug)]);
  }, [slug]);

  return null;
}

/** The home-page rail. Renders nothing until there's something to show. */
export function RecentlyViewedRail({ exclude = [] }: { exclude?: string[] }) {
  const [fragrances, setFragrances] = useState<Fragrance[]>([]);

  useEffect(() => {
    const slugs = readSlugs();
    if (slugs.length === 0) return;

    let stale = false;
    Promise.all(slugs.map((slug) => getFragrance(slug).catch(() => null))).then((results) => {
      if (stale) return;
      const alive = results.filter((f): f is Fragrance => f !== null);
      setFragrances(alive);
      // prune anything deactivated since it was viewed
      if (alive.length !== slugs.length) writeSlugs(alive.map((f) => f.slug));
    });

    return () => {
      stale = true;
    };
  }, []);

  // <ViewTransition> names must be unique page-wide, and the same card twice on
  // one screen is noise anyway — skip fragrances another rail already shows.
  const excluded = new Set(exclude);
  const visible = fragrances.filter((f) => !excluded.has(f.slug));

  if (visible.length === 0) return null;

  return (
    <section className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-16">
      <h2 className="text-xl font-bold uppercase tracking-[0.12em] text-ink-strong sm:text-2xl">
        Recently viewed
      </h2>
      <ul className="-mx-4 mt-8 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6 md:mx-0 md:grid md:grid-cols-4 md:gap-6 md:overflow-visible md:px-0">
        {visible.map((fragrance) => (
          <li
            key={fragrance.id}
            className="w-[68vw] max-w-[280px] shrink-0 snap-start md:w-auto md:max-w-none"
          >
            <FragranceCard fragrance={fragrance} />
          </li>
        ))}
      </ul>
    </section>
  );
}
