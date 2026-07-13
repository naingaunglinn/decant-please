import { ViewTransition } from "react";
import { Skeleton } from "@/components/ui/Skeleton";

export default function ShopLoading() {
  return (
    <div className="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 md:py-16">
      <header className="mb-10">
        <Skeleton className="h-9 w-40" />
        <Skeleton className="mt-2 h-4 w-56" />
      </header>

      <div className="flex gap-10">
        <div className="hidden w-60 shrink-0 flex-col gap-6 lg:flex">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full" />
          ))}
        </div>

        <ViewTransition exit="slide-down">
          <ul className="grid min-w-0 flex-1 grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <li key={i}>
                <Skeleton className="aspect-square w-full" />
                <Skeleton className="mt-4 h-4 w-2/3" />
                <Skeleton className="mt-2 h-4 w-1/2" />
              </li>
            ))}
          </ul>
        </ViewTransition>
      </div>
    </div>
  );
}
