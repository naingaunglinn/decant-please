import type { Metadata } from "next";
import { TrackClient } from "@/components/tracking/TrackClient";
import { tenantPage } from "@/lib/tenant";

export const metadata: Metadata = {
  title: "Track your order",
  description: "Follow your decant from confirmation to delivery with your tracking code and phone number.",
};

export default async function TrackPage({
  params,
  searchParams,
}: {
  params: Promise<{ host: string }>;
  searchParams: Promise<{ code?: string }>;
}) {
  const { host } = await params;
  const { code } = await searchParams;
  // a secondary-domain 308 must keep the prefilled code from shared links
  await tenantPage(host, `/track${code ? `?code=${encodeURIComponent(code)}` : ""}`);

  return (
    <div className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16 lg:max-w-[640px] xl:max-w-[720px]">
      <h1 className="no-print text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong">
        Track your order
      </h1>
      <p className="no-print mt-2 text-sm text-muted">
        Enter the tracking code from your order-complete page, plus the phone number you
        ordered with.
      </p>

      <div className="mt-10">
        <TrackClient initialCode={code} />
      </div>
    </div>
  );
}
