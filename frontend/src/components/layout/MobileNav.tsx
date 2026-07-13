"use client";

import Link from "next/link";
import { useState } from "react";
import { AnimatePresence, motion, useReducedMotion } from "motion/react";

interface MobileNavProps {
  links: { href: string; label: string }[];
}

export function MobileNav({ links }: MobileNavProps) {
  const [open, setOpen] = useState(false);
  const reduced = useReducedMotion();

  return (
    <div className="sm:hidden">
      <button
        type="button"
        aria-label={open ? "Close menu" : "Open menu"}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="flex size-11 items-center justify-center rounded-full transition-colors hover:bg-pine-soft"
      >
        <svg width="18" height="12" viewBox="0 0 18 12" fill="none" aria-hidden>
          <path
            d={open ? "M2 1l14 10M16 1L2 11" : "M1 1h16M1 6h16M1 11h16"}
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
          />
        </svg>
      </button>

      <AnimatePresence>
        {open && (
          <motion.div
            initial={reduced ? false : { opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={reduced ? { opacity: 0 } : { opacity: 0, y: -8 }}
            transition={{ duration: 0.2, ease: "easeOut" }}
            className="absolute inset-x-0 top-16 border-b border-rule bg-mist px-4 py-4"
          >
            <ul className="flex flex-col gap-1">
              {links.map((link) => (
                <li key={link.href}>
                  <Link
                    href={link.href}
                    onClick={() => setOpen(false)}
                    className="block rounded-full px-4 py-3 text-xs font-medium uppercase tracking-[0.15em] text-ink transition-colors hover:bg-pine-soft"
                  >
                    {link.label}
                  </Link>
                </li>
              ))}
            </ul>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
