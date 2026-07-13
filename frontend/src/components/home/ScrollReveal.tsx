"use client";

import { useEffect, useRef, type ReactNode } from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

/** Fade/rise a section as it enters the viewport. Home page only —
 *  the shop grid must be readable immediately. */
export function ScrollReveal({ children, className = "" }: { children: ReactNode; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const element = ref.current;
    if (!element) return;

    const matcher = gsap.matchMedia();

    matcher.add("(prefers-reduced-motion: no-preference)", () => {
      gsap.registerPlugin(ScrollTrigger);

      const tween = gsap.from(element, {
        y: 28,
        opacity: 0,
        duration: 0.8,
        ease: "power2.out",
        scrollTrigger: { trigger: element, start: "top 85%", once: true },
      });

      return () => {
        tween.scrollTrigger?.kill();
        tween.kill();
      };
    });

    return () => matcher.revert();
  }, []);

  return (
    <div ref={ref} className={className}>
      {children}
    </div>
  );
}
