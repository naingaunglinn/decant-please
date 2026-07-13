import Image from "next/image";

interface ImagePlateProps {
  src: string | null;
  alt: string;
  sizes?: string;
  priority?: boolean;
  className?: string;
}

/** Product image on its own contained surface-alt plate. Falls back to a
 *  quiet vial glyph when the catalog has no photo yet. */
export function ImagePlate({ src, alt, sizes, priority = false, className = "" }: ImagePlateProps) {
  return (
    <div className={`relative aspect-square overflow-hidden rounded-2xl bg-surface-alt ${className}`}>
      {src ? (
        <Image
          src={src}
          alt={alt}
          fill
          sizes={sizes ?? "(max-width: 768px) 50vw, 25vw"}
          priority={priority}
          className="object-cover"
        />
      ) : (
        <div className="absolute inset-0 flex items-center justify-center" aria-hidden>
          <div className="relative h-1/3 w-[12%] min-w-6 overflow-hidden rounded-full border border-muted/40">
            <div className="absolute inset-x-0 bottom-0 h-2/5 bg-pine/20" />
          </div>
        </div>
      )}
    </div>
  );
}
