import type { Metadata } from "next";
import { CheckoutClient } from "@/components/checkout/CheckoutClient";

export const metadata: Metadata = {
  title: "Checkout",
  description: "Place your decant order — no account, no online payment.",
};

export default function CheckoutPage() {
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
