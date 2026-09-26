"use client";

import { useEffect, useRef, type ReactNode } from "react";
import gsap from "gsap";
import { SplitText } from "gsap/SplitText";
import { Button } from "@/components/ui/Button";

interface HeroProps {
  title: string;
  subtitle: string;
  button: string;
  trackButton: string;
  /** The base design's headline scale (lib/design.ts). */
  titleClass: string;
  visual: ReactNode;
}

/** The design's hero section (step 46b): its text comes from the shop's design. */
export function Hero({ title, subtitle, button, trackButton, titleClass, visual }: HeroProps) {
  const scope = useRef<HTMLElement>(null);

  useEffect(() => {
    const matcher = gsap.matchMedia(scope);

    matcher.add("(prefers-reduced-motion: no-preference)", () => {
      gsap.registerPlugin(SplitText);

      const split = new SplitText(".hero-headline", { type: "lines" });
      const timeline = gsap
        .timeline()
        .from(split.lines, {
          y: 32,
          opacity: 0,
          duration: 0.85,
          stagger: 0.12,
          ease: "power3.out",
        })
        .from(
          [".hero-sub", ".hero-cta"],
          { y: 14, opacity: 0, duration: 0.55, stagger: 0.1, ease: "power2.out" },
          "-=0.45",
        )
        .from(".hero-visual", { opacity: 0, scale: 0.985, duration: 0.9, ease: "power2.out" }, 0.15);

      return () => {
        timeline.kill();
        split.revert();
      };
    });

    return () => matcher.revert();
  }, []);

  return (
    <section
      ref={scope}
      className="mx-auto grid max-w-[1280px] items-center gap-12 px-4 py-16 sm:px-6 md:grid-cols-2 md:py-28"
    >
      <div className="flex max-w-xl flex-col items-start gap-7">
        {title && <h1 className={`hero-headline ${titleClass} text-ink-strong`}>{title}</h1>}
        {subtitle && <p className="hero-sub max-w-md text-[15px] leading-[1.7] text-muted">{subtitle}</p>}
        {(button || trackButton) && (
          <div className="hero-cta flex flex-wrap items-center gap-4">
            {button && <Button href="/shop">{button}</Button>}
            {trackButton && (
              <Button href="/track" variant="ghost">
                {trackButton}
              </Button>
            )}
          </div>
        )}
      </div>

      <div className="hero-visual">{visual}</div>
    </section>
  );
}
