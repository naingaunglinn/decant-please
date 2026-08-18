import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CartProvider } from "@/lib/cart-context";
import { TenantProvider } from "@/lib/tenant-context";
import { originForHost, resolveTenant } from "@/lib/tenant";
import { Navbar } from "@/components/layout/Navbar";
import { Footer } from "@/components/layout/Footer";
import { CartDrawer } from "@/components/cart/CartDrawer";

// ADR-0004: the tenant frame. The [host] segment is the proxy-validated public
// host — resolving it here (cached, 60s) brands the whole tree and fails closed
// on hosts that map to no shop. The secondary→primary 308 lives in each PAGE
// (via lib/tenant.ts tenantPage) — a layout cannot know the current path, and
// the redirect must stay a static, cacheable response.

interface HostParams {
  params: Promise<{ host: string }>;
}

export async function generateMetadata({ params }: HostParams): Promise<Metadata> {
  const { host } = await params;
  const tenant = await resolveTenant(host);
  if (!tenant) return { title: "Not found" };

  return {
    // URL-shaped metadata (canonical, OG) always composes against the VERIFIED
    // PRIMARY domain — on secondary domains too, which is exactly what
    // consolidates search onto the primary. Pages set their own relative
    // `alternates.canonical`; metadataBase applies per segment, so it can't
    // live only here.
    metadataBase: new URL(originForHost(tenant.primary_host)),
    title: {
      default: `${tenant.name} — Perfume decants, delivered across Myanmar`,
      template: `%s — ${tenant.name}`,
    },
    description:
      "Authentic designer and niche fragrances, hand-decanted into 5ml, 10ml and 30ml vials. Browse, order and track — no account needed.",
    openGraph: {
      siteName: tenant.name,
      type: "website",
      locale: "en_US",
    },
  };
}

export default async function HostLayout({
  children,
  params,
}: HostParams & { children: React.ReactNode }) {
  const { host } = await params;
  const tenant = await resolveTenant(host);

  // Unknown, unverified, or inactive → the unbranded root not-found. Never a
  // default shop, never a fallback tenant.
  if (!tenant) notFound();

  return (
    <TenantProvider tenant={{ slug: tenant.slug, name: tenant.name }}>
      <CartProvider>
        <Navbar name={tenant.name} />
        <main className="flex-1">{children}</main>
        <Footer shop={tenant.slug} name={tenant.name} />
        <CartDrawer />
      </CartProvider>
    </TenantProvider>
  );
}
