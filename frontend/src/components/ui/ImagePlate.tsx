"use client";

import Image from "next/image";
import { useState } from "react";

interface ImagePlateProps {
  src: string | null;
  alt: string;
  sizes?: string;
  priority?: boolean;
  className?: string;
}

/** The quiet vial glyph. One definition, so a fragrance with no photo and one
 *  whose photo failed to load are visually identical. */
function VialGlyph() {
  return (
    <div className="absolute inset-0 flex items-center justify-center" aria-hidden>
      <div className="relative h-1/3 w-[12%] min-w-6 overflow-hidden rounded-full border border-muted/40">
        <div className="absolute inset-x-0 bottom-0 h-2/5 bg-pine/20" />
      </div>
    </div>
  );
}

/** Product image on its own contained surface-alt plate. Falls back to a quiet
 *  vial glyph when the catalog has no photo yet, or when the recorded one won't
 *  load — an image_path outliving its file leaves the row pointing at nothing,
 *  and a broken-image icon is a worse answer than the placeholder we already have.
 *
 *  A Client Component only because next/image's onError is a function prop. */
export function ImagePlate({ src, alt, sizes, priority = false, className = "" }: ImagePlateProps) {
  // Remember which src failed, not just that one did: these are reused across
  // client-side navigations, and a bare boolean would suppress the next src too.
  const [failedSrc, setFailedSrc] = useState<string | null>(null);

  return (
    <div className={`relative aspect-square overflow-hidden rounded-2xl bg-surface-alt ${className}`}>
      {src && src !== failedSrc ? (
        <Image
          src={src}
          alt={alt}
          fill
          sizes={sizes ?? "(max-width: 768px) 50vw, 25vw"}
          priority={priority}
          className="object-cover"
          onError={() => setFailedSrc(src)}
        />
      ) : (
        <VialGlyph />
      )}
    </div>
  );
}
