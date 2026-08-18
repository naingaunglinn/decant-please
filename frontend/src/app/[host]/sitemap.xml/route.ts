import { getFragrances } from "@/lib/api";
import { originForHost, resolveTenant } from "@/lib/tenant";

// Per-tenant sitemap (ADR-0004): client-a.com/sitemap.xml arrives here as
// /{host}/sitemap.xml via the proxy rewrite and contains ONLY that tenant's
// URLs. A route handler rather than the sitemap.ts convention — the convention
// can't read the [host] param, and tenant isolation outranks convention.
export const revalidate = 3600;

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ host: string }> },
): Promise<Response> {
  const { host } = await params;
  const tenant = await resolveTenant(host);
  if (!tenant) return new Response("Not Found", { status: 404 });

  // URLs build on the verified PRIMARY origin — a secondary domain's sitemap
  // advertises the canonical addresses, mirroring the pages' canonical tags.
  const origin = originForHost(tenant.primary_host);
  const entries: string[] = [urlTag(origin, "weekly", 1), urlTag(`${origin}/shop`, "daily", 0.9)];

  try {
    let page = 1;
    let lastPage = 1;
    do {
      const result = await getFragrances(tenant.slug, { per_page: "50", page: String(page) });
      lastPage = result.meta.last_page;
      for (const fragrance of result.data) {
        entries.push(urlTag(`${origin}/fragrance/${fragrance.slug}`, "weekly", 0.7));
      }
      page++;
    } while (page <= lastPage && page <= 20); // ponytail: 1000-fragrance ceiling, plenty for one decanter
  } catch {
    // API unreachable — ship the static pages at least (same stance as the old sitemap.ts)
  }

  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${entries.join("\n")}\n</urlset>\n`;

  return new Response(xml, { headers: { "Content-Type": "application/xml" } });
}

function urlTag(loc: string, changefreq: string, priority: number): string {
  return `  <url><loc>${loc}</loc><changefreq>${changefreq}</changefreq><priority>${priority}</priority></url>`;
}
