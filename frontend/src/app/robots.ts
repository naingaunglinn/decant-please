import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      // transactional pages — nothing worth indexing, and /order carries tracking codes
      disallow: ["/checkout", "/order/"],
    },
  };
}
