import type { Metadata } from "next";
import { CheckoutClient } from "@/components/checkout/CheckoutClient";
import { tenantPage } from "@/lib/tenant";

export const metadata: Metadata = {
  title: "Checkout",
  description: "Place your decant order — no account, no online payment.",
};

// On-demand ISR per host (see the home page's note) — the page shell is static;
// zones, cart, and submission are all client-side.
export function generateStaticParams(): Array<{ host: string }> {
  return [];
}

export default async function CheckoutPage({ params }: { params: Promise<{ host: string }> }) {
  const { host } = await params;
  await tenantPage(host, "/checkout"); // 404 unknown hosts, 308 secondaries

  return (
    <div className="mx-auto max-w-[880px] px-4 py-12 sm:px-6 md:py-16">
      <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong">
        Checkout
      </h1>
      <p className="mt-2 text-sm text-muted">
        We&apos;ll review your order and confirm by phone before anything is decanted.
      </p>

      <div className="mt-10">
        <CheckoutClient />
      </div>
    </div>
  );
}
