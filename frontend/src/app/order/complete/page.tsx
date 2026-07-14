import type { Metadata } from "next";
import Link from "next/link";
import { OrderCompleteClient } from "@/components/checkout/OrderCompleteClient";

export const metadata: Metadata = {
  title: "Order placed",
};

export default async function OrderCompletePage({
  searchParams,
}: {
  searchParams: Promise<{ code?: string }>;
}) {
  const { code } = await searchParams;

  return (
    <div className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16 lg:max-w-[640px] xl:max-w-[720px]">
      {code ? (
        <OrderCompleteClient code={code} />
      ) : (
        <div className="flex flex-col items-start gap-6">
          <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong">
            No order to show
          </h1>
          <p className="text-sm text-muted">
            This page needs a tracking code in the address — if you just ordered, head back
            to the shop, or track an existing order instead.
          </p>
          <Link
            href="/track"
            className="text-xs font-medium uppercase tracking-[0.15em] text-pine underline underline-offset-4"
          >
            Track an order
          </Link>
        </div>
      )}
    </div>
  );
}
