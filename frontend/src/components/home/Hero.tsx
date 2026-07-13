"use client";

import { useEffect, useRef, type ReactNode } from "react";
import gsap from "gsap";
import { SplitText } from "gsap/SplitText";
import { Button } from "@/components/ui/Button";

export function Hero({ visual }: { visual: ReactNode }) {
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
        <h1 className="hero-headline text-[40px] font-bold leading-[1.06] tracking-tight text-ink-strong sm:text-[56px]">
          Great perfume, five millilitres at a time.
        </h1>
        <p className="hero-sub max-w-md text-[15px] leading-[1.7] text-muted">
          Authentic designer and niche bottles, hand-decanted into 5ml, 10ml and 30ml vials
          in Yangon — so you can wear the real thing before you commit to it.
        </p>
        <div className="hero-cta flex flex-wrap items-center gap-4">
          <Button href="/shop">Browse the shop</Button>
          <Button href="/track" variant="ghost">
            Track an order
          </Button>
        </div>
      </div>

      <div className="hero-visual">{visual}</div>
    </section>
  );
}
