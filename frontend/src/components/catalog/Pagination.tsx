import Link from "next/link";
import type { PaginationMeta } from "@/lib/types";

interface PaginationProps {
  meta: PaginationMeta;
  searchParams: Record<string, string | undefined>;
}

export function Pagination({ meta, searchParams }: PaginationProps) {
  if (meta.last_page <= 1) return null;

  const pageHref = (page: number) => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(searchParams)) {
      if (value !== undefined && key !== "page") params.set(key, value);
    }
    if (page > 1) params.set("page", String(page));
    const qs = params.toString();
    return qs ? `/shop?${qs}` : "/shop";
  };

  const { current_page: current, last_page: last } = meta;

  return (
    <nav aria-label="Pagination" className="mt-14 flex items-center justify-between border-t border-rule pt-6">
      {current > 1 ? (
        <Link
          href={pageHref(current - 1)}
          className="text-xs font-medium uppercase tracking-[0.15em] text-pine hover:underline underline-offset-4"
        >
          ← Previous
        </Link>
      ) : (
        <span />
      )}

      <span className="text-xs uppercase tracking-[0.18em] text-muted">
        Page {current} of {last}
      </span>

      {current < last ? (
        <Link
          href={pageHref(current + 1)}
          className="text-xs font-medium uppercase tracking-[0.15em] text-pine hover:underline underline-offset-4"
        >
          Next →
        </Link>
      ) : (
        <span />
      )}
    </nav>
  );
}
