import { originForHost, resolveTenant } from "@/lib/tenant";

// Per-tenant robots (ADR-0004): the public request for client-a.com/robots.txt
// arrives here as /{host}/robots.txt via the proxy rewrite. A route handler, not
// the robots.ts file convention — the convention is root-only and host-blind.
export const revalidate = 3600;

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ host: string }> },
): Promise<Response> {
  const { host } = await params;
  const tenant = await resolveTenant(host);
  if (!tenant) return new Response("Not Found", { status: 404 });

  // Same rules as the pre-ADR-0004 robots.ts: transactional pages stay out of
  // the index (/order carries tracking codes). The sitemap reference stays on
  // THIS host — each domain advertises its own.
  const body = [
    "User-Agent: *",
    "Allow: /",
    "Disallow: /checkout",
    "Disallow: /order/",
    "",
    `Sitemap: ${originForHost(tenant.host)}/sitemap.xml`,
    "",
  ].join("\n");

  return new Response(body, { headers: { "Content-Type": "text/plain" } });
}
