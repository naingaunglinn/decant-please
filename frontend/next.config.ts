import type { NextConfig } from "next";

// images live on the Laravel host — follow NEXT_PUBLIC_API_URL so prod needs no code change
const apiUrl = new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8010/api");

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
    ],
  },
};

export default nextConfig;
