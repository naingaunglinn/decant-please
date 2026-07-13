import type { NextConfig } from "next";

// images live on the Laravel host — follow NEXT_PUBLIC_API_URL so prod needs no code change
const apiOrigin = new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").origin;

const nextConfig: NextConfig = {
  experimental: {
    viewTransition: true,
  },
  images: {
    remotePatterns: [
      new URL(`${apiOrigin}/storage/**`),
      new URL("http://localhost:8000/storage/**"),
      new URL("http://127.0.0.1:8000/storage/**"),
      new URL("http://localhost:8010/storage/**"),
      new URL("http://127.0.0.1:8010/storage/**"),
    ],
  },
};

export default nextConfig;
