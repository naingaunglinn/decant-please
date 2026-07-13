import Link from "next/link";
import { CartButton } from "./CartButton";
import { MobileNav } from "./MobileNav";

const LINKS = [
  { href: "/shop", label: "Shop" },
  { href: "/track", label: "Track order" },
];

export function Navbar() {
  return (
    <header className="sticky top-0 z-40 border-b border-rule bg-mist/85 backdrop-blur">
      <nav className="mx-auto flex h-16 max-w-[1280px] items-center justify-between gap-4 px-4 sm:px-6">
        <div className="flex items-center gap-2">
          <MobileNav links={LINKS} />
          <Link
            href="/"
            className="text-sm font-bold uppercase tracking-[0.2em] text-ink-strong"
          >
            Decant Please!
          </Link>
        </div>

        <div className="flex items-center gap-1 sm:gap-6">
          <div className="hidden items-center gap-6 sm:flex">
            {LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="text-xs font-medium uppercase tracking-[0.15em] text-ink transition-colors hover:text-pine"
              >
                {link.label}
              </Link>
            ))}
          </div>
          <CartButton />
        </div>
      </nav>
    </header>
  );
}
