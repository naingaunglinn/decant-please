import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  const site = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3001";

  return {
    rules: {
      userAgent: "*",
      allow: "/",
      // transactional pages — nothing worth indexing, and /order carries tracking codes
      disallow: ["/checkout", "/order/"],
    },
    sitemap: `${site}/sitemap.xml`,
  };
}
