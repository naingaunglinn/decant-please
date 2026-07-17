import type { NextConfig } from "next";

// In local dev, images live on the Laravel host (follows NEXT_PUBLIC_API_URL, so no
// code change per env). In production they're served from Cloudflare R2 — set
// NEXT_PUBLIC_IMAGE_URL to that public host (e.g. https://images.cornerarea.me) and it
// joins the allow-list below alongside the API host.
const apiUrl = new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8010/api");
const imageUrl = process.env.NEXT_PUBLIC_IMAGE_URL;

const nextConfig: NextConfig = {
  experimental: {
    viewTransition: true,
  },
  images: {
    // Next 16's optimizer refuses upstreams that resolve to loopback/private IPs
    // even when remotePatterns allows them. Local dev's API *is* localhost, so
    // allow exactly that case; a public API host keeps the SSRF protection.
    dangerouslyAllowLocalIP: ["localhost", "127.0.0.1"].includes(apiUrl.hostname),
    remotePatterns: [
      new URL(`${apiUrl.origin}/storage/**`),
      new URL("http://localhost:8010/storage/**"),
      new URL("http://127.0.0.1:8010/storage/**"),
      // Production R2 image host — kept alongside the API-host pattern above so the
      // backend flip to R2 URLs and this allow-list don't have to deploy in lockstep.
      ...(imageUrl ? [new URL(`${new URL(imageUrl).origin}/**`)] : []),
    ],
  },
};

export default nextConfig;
