import { Button } from "@/components/ui/Button";
import { Pill } from "@/components/ui/Pill";

export default function NotFound() {
  return (
    <div className="mx-auto flex max-w-[480px] flex-col items-start gap-6 px-4 py-24 sm:px-6">
      <Pill tone="muted">404</Pill>
      <h1 className="text-[28px] font-bold uppercase tracking-[0.12em] text-ink-strong">
        Evaporated
      </h1>
      <p className="text-sm leading-relaxed text-muted">
        Whatever was here isn&apos;t anymore — maybe the fragrance was retired, or the link
        lost a character on its way to you.
      </p>
      <div className="flex gap-3">
        <Button href="/shop">Browse the shop</Button>
        <Button href="/" variant="ghost">
          Back home
        </Button>
      </div>
    </div>
  );
}
