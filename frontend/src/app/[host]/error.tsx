"use client";

import { Button } from "@/components/ui/Button";
import { Pill } from "@/components/ui/Pill";

export default function ErrorPage({ reset }: { error: Error; reset: () => void }) {
  return (
    <div className="mx-auto flex max-w-[480px] flex-col items-start gap-6 px-4 py-24 sm:px-6">
      <Pill tone="danger">Error</Pill>
      <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong">
        Something spilled
      </h1>
      <p className="text-sm leading-relaxed text-muted">
        Not your fault — the page hit a problem while loading. Trying again usually mops it
        up.
      </p>
      <div className="flex gap-3">
        <Button onClick={reset}>Try again</Button>
        <Button href="/" variant="ghost">
          Back home
        </Button>
      </div>
    </div>
  );
}
