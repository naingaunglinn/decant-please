import { cache } from "react";
import { notFound, permanentRedirect } from "next/navigation";
import type { StorefrontHost } from "./types";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8010/api";

/** Mirrors backend ShopDomain::originFor — local hosts are plain http, real ones https. */
export function originForHost(host: string): string {
  const isLocal =
    host.startsWith("localhost") || host.startsWith("127.0.0.1") || host.includes(".localhost");
  return `${isLocal ? "http" : "https"}://${host}`;
}

/**
 * ADR-0004: the one tenant-resolution site on the storefront. src/proxy.ts rewrote
 * the public URL to /{host}/…; this turns that host into shop identity through
 * PR-A's platform endpoint — the backend stays the authority on which domains
 * exist. React.cache() dedupes within a render; `next.revalidate` shares the
 * answer across renders for 60s, so resolution costs one backend call per host
 * per minute, not per request. Unknown, unverified, and inactive hosts resolve to
 * null — callers fail closed. There is deliberately no default-shop fallback.
 */
export const resolveTenant = cache(async (raw: string): Promise<StorefrontHost | null> => {
  // Belt + braces for param decoding differences between layouts, pages, and
  // route handlers: accept the [host] param encoded or raw.
  let host = raw;
  try {
    host = decodeURIComponent(raw);
  } catch {
    // malformed percent-sequence — resolve the raw string, which will 404
  }
  // encodeURIComponent is load-bearing beyond correctness: PHP's built-in dev
  // server (artisan serve) treats a RAW dotted last segment as a static-file
  // request and 404s it before Laravel runs; the %3A-encoded form routes fine,
  // and local hosts always carry a :port. Production nginx has no such quirk.
  const response = await fetch(
    `${API}/v1/_storefront/host/${encodeURIComponent(host.toLowerCase())}`,
    { headers: { Accept: "application/json" }, next: { revalidate: 60 } },
  );

  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`Tenant resolution failed: ${response.status}`);

  return (await response.json()).data;
});

/**
 * The per-page tenant gate: resolve or 404, and permanently redirect a secondary
 * domain to the same path on the verified primary. Pages call this rather than
 * the layout because only the page statically knows its own public path — which
 * keeps the redirect a real, cacheable 308 instead of proxy I/O (forbidden) or
 * request-time path sniffing (would force dynamic rendering). Loop-safe: the
 * primary host never matches the condition, and the target is always primary.
 */
export async function tenantPage(host: string, path: string): Promise<StorefrontHost> {
  const tenant = await resolveTenant(host);
  if (!tenant) notFound();

  if (tenant.host !== tenant.primary_host) {
    permanentRedirect(`${originForHost(tenant.primary_host)}${path}`);
  }

  return tenant;
}
