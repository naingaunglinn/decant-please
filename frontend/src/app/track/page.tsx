import type { Metadata } from "next";
import { TrackClient } from "@/components/tracking/TrackClient";

export const metadata: Metadata = {
  title: "Track your order",
  description: "Follow your decant from confirmation to delivery with your tracking code and phone number.",
};

export default async function TrackPage({
  searchParams,
}: {
  searchParams: Promise<{ code?: string }>;
}) {
  const { code } = await searchParams;

  return (
    <div className="mx-auto max-w-[480px] px-4 py-12 sm:px-6 md:py-16">
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
