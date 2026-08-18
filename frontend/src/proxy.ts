import { NextResponse, type NextRequest } from "next/server";

/**
 * ADR-0004: the deterministic host → internal-path rewrite, and nothing else.
 * `client-a.com/fragrance/x` renders `app/[host]/…` as `/client-a.com/fragrance/x`;
 * the browser URL never changes, and the internal segment gives every rendered
 * page a cache key that carries the tenant by construction — the step-32 unkeyed-
 * cache lesson, applied to the route cache.
 *
 * Deliberately I/O-free: no database, no API calls, no shared mutable state.
 * Whether a host actually maps to a shop is decided in the tenant layer
 * (lib/tenant.ts → the PR-A resolution endpoint); an unknown host rewrites like
 * any other and fails closed to a 404 there. Authorization never lives here —
 * the backend scope is the security boundary.
 *
 * Loop-safe: a rewrite does not re-enter the proxy, and a request aimed directly
 * at an internal path (`/client-a.com/shop`) gets prefixed again into a route
 * that doesn't exist — the internal space is unreachable from outside.
 */
export function proxy(request: NextRequest): NextResponse {
  const host = (request.headers.get("host") ?? "").toLowerCase();

  // No Host header (nothing real sends this) → let it fall through to the root
  // tree, which owns nothing but the fail-closed not-found page.
  if (host === "") {
    return NextResponse.next();
  }

  const { pathname, search } = request.nextUrl;

  // The host goes into the path RAW: dots and the :port are legal in a path
  // segment, and percent-encoding here reaches the [host] param still encoded
  // (layouts don't decode it), which would break resolution. Real Host values
  // are hostname[:port] only; anything stranger simply fails to resolve.
  return NextResponse.rewrite(new URL(`/${host}${pathname}${search}`, request.url));
}

export const config = {
  // Everything except Next internals and the app icon goes through the rewrite.
  // robots.txt and sitemap.xml stay INCLUDED deliberately — they are per-tenant
  // (app/[host]/robots.txt and app/[host]/sitemap.xml route handlers).
  matcher: ["/((?!_next/|icon\\.svg).*)"],
};
