import Link from "next/link";
import { getMeta } from "@/lib/api";

export async function Footer() {
  let social: { tiktok_url: string | null; facebook_url: string | null } = {
    tiktok_url: null,
    facebook_url: null,
  };
  try {
    social = (await getMeta()).social ?? social; // ?? guards a stale cached /meta from before this field existed
  } catch {
    // API unreachable (e.g. at build time) — footer stands without the links
  }

  const socialLinks = [
    { label: "TikTok", href: social.tiktok_url },
    { label: "Facebook", href: social.facebook_url },
  ].filter((link): link is { label: string; href: string } => Boolean(link.href));

  return (
    <footer className="mt-auto border-t border-rule">
      <div className="mx-auto flex max-w-[1280px] flex-col gap-8 px-4 py-14 sm:px-6 md:flex-row md:items-start md:justify-between">
        <div className="max-w-xs">
          <p className="text-sm font-bold uppercase tracking-[0.2em] text-ink-strong">
            Decant Please!
          </p>
          <p className="mt-3 text-sm text-muted">
            Authentic fragrance, decanted by hand in Yangon. 5ml, 10ml and 30ml vials,
            delivered across Myanmar.
          </p>
        </div>

        <nav className="flex gap-10 text-xs font-medium uppercase tracking-[0.15em]">
          <div className="flex flex-col gap-3">
            <Link href="/shop" className="transition-colors hover:text-pine">Shop</Link>
            <Link href="/track" className="transition-colors hover:text-pine">Track order</Link>
          </div>
          {socialLinks.length > 0 && (
            <div className="flex flex-col gap-3">
              {socialLinks.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="transition-colors hover:text-pine"
                >
                  {link.label}
                </a>
              ))}
            </div>
          )}
        </nav>

        <p className="max-w-xs text-xs leading-relaxed text-muted">
          No online payment — we confirm every order first, then arrange bank transfer,
          mobile banking or cash on delivery.
        </p>
      </div>
    </footer>
  );
}
