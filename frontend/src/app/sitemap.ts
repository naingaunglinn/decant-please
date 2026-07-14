import type { MetadataRoute } from "next";
import { getFragrances } from "@/lib/api";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const site = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3001";

  const entries: MetadataRoute.Sitemap = [
    { url: site, changeFrequency: "weekly", priority: 1 },
    { url: `${site}/shop`, changeFrequency: "daily", priority: 0.9 },
  ];

  try {
    let page = 1;
    let lastPage = 1;
    do {
      const result = await getFragrances({ per_page: "50", page: String(page) });
      lastPage = result.meta.last_page;
      for (const fragrance of result.data) {
        entries.push({
          url: `${site}/fragrance/${fragrance.slug}`,
          changeFrequency: "weekly",
          priority: 0.7,
        });
      }
      page++;
    } while (page <= lastPage && page <= 20); // ponytail: 1000-fragrance ceiling, plenty for one decanter

  } catch {
    // API unreachable (e.g. cold build) — ship the static pages at least
  }

  return entries;
}
