import type { CSSProperties } from "react";
import type { DesignSection, StoreDesign } from "./types";

// Step 46b: the storefront side of a shop's design. A design is data (/meta
// `design`); this file turns its theme into CSS variables on the tenant
// wrapper, and holds what each base design and font look like. The @theme
// block in globals.css stays the default palette — the wrapper only overrides
// the variables Tailwind's utilities already read (bg-mist → var(--color-mist)).

/** Today's palette, the @theme values. Decant's Clean preset uses exactly these. */
const DEFAULT_COLORS = {
  background: "#f2f8fc",
  text: "#212121",
  primary: "#013e37",
  primary_text: "#f2f8fc",
};

// System stacks only: no web font to download on a slow connection. Each ends
// in the Burmese faces phones ship (Android: Noto Sans Myanmar; Windows:
// Myanmar Text; Padauk on Linux). `modern` starts with today's stack.
const BURMESE = '"Noto Sans Myanmar", "Myanmar Text", Padauk';
const FONTS: Record<string, string> = {
  modern: `'Helvetica Neue', Helvetica, Arial, ${BURMESE}, sans-serif`,
  classic: `Georgia, 'Times New Roman', "Noto Serif Myanmar", ${BURMESE}, serif`,
  friendly: `ui-rounded, 'SF Pro Rounded', 'Arial Rounded MT Bold', ${BURMESE}, sans-serif`,
};

/** What a base design sets that colours and a font don't. Clean is today's look. */
export interface BaseStyle {
  heroTitle: string;
  heading: string;
  /** Tiles and cards. */
  radius: string;
  /** Overrides ImagePlate's own corners (important, so it wins whatever the CSS order). */
  imageRadius: string;
}

const BASES: Record<string, BaseStyle> = {
  clean: {
    heroTitle: "text-[40px] font-bold leading-[1.06] tracking-tight sm:text-[56px]",
    heading: "text-xl font-bold uppercase tracking-[0.12em] sm:text-2xl",
    radius: "rounded-2xl",
    imageRadius: "",
  },
  bold: {
    heroTitle: "text-[44px] font-black leading-[1.02] tracking-tight sm:text-[68px]",
    heading: "text-2xl font-black uppercase sm:text-3xl",
    radius: "rounded-md",
    imageRadius: "rounded-md!",
  },
  warm: {
    heroTitle: "text-[38px] font-semibold leading-[1.2] sm:text-[52px]",
    heading: "text-2xl font-semibold sm:text-[28px]",
    radius: "rounded-[28px]",
    imageRadius: "rounded-[28px]!",
  },
};

export function baseStyle(design: StoreDesign): BaseStyle {
  return BASES[design.base] ?? BASES.clean;
}

const HEX = /^#[0-9a-f]{6}$/i;

/**
 * The wrapper's CSS variables. The four colours map onto today's tokens, plus
 * `--on-primary` for text on a primary fill (buttons, badges). The softer tokens
 * (muted text, rules, tints) are mixed from the four — except for today's
 * palette, whose hand-tuned values stay exactly as they are.
 */
export function themeStyle(design: StoreDesign | null): CSSProperties {
  const colors = design?.theme?.colors;
  const font = FONTS[design?.theme?.font ?? ""] ?? FONTS.modern;

  if (!colors || !Object.values(colors).every((c) => HEX.test(c))) {
    return { "--font-sans": FONTS.modern, "--on-primary": "var(--color-mist)" } as CSSProperties;
  }

  const same = (Object.keys(DEFAULT_COLORS) as (keyof typeof DEFAULT_COLORS)[]).every(
    (key) => colors[key].toLowerCase() === DEFAULT_COLORS[key],
  );
  const { background, text, primary, primary_text } = colors;

  return {
    "--font-sans": font,
    "--color-mist": background,
    "--color-ink": text,
    "--color-pine": primary,
    "--on-primary": primary_text,
    ...(same
      ? {}
      : {
          "--color-ink-strong": text,
          "--color-muted": `color-mix(in srgb, ${text} 72%, ${background})`,
          "--color-rule": `color-mix(in srgb, ${text} 16%, ${background})`,
          "--color-surface-alt": `color-mix(in srgb, ${text} 6%, ${background})`,
          "--color-pine-soft": `color-mix(in srgb, ${primary} 12%, ${background})`,
        }),
  } as CSSProperties;
}

/** What a shop renders when /meta is unreachable or predates 46b: a plain
 *  hero with the shop's name and today's palette — never another shop's copy. */
export function fallbackDesign(shopName: string): StoreDesign {
  return {
    version: 1,
    base: "clean",
    theme: { colors: DEFAULT_COLORS, font: "modern" },
    sections: [
      { type: "hero", on: true, props: { title: shopName, subtitle: "", button: "Browse the shop", track_button: "Track an order", image: null } },
      { type: "featured", on: true, props: { title: "Featured" } },
      { type: "recently_viewed", on: true, props: {} },
    ],
  };
}

/** A string prop, or "" — an older row may lack a prop a newer renderer reads. */
export function text(props: Record<string, unknown>, key: string): string {
  const value = props[key];
  return typeof value === "string" ? value : "";
}

/** A list-of-records prop (steps, tiles), string fields only. */
export function items(props: Record<string, unknown>): Record<string, string>[] {
  const value = props.items;
  if (!Array.isArray(value)) return [];

  return value
    .filter((item): item is Record<string, unknown> => typeof item === "object" && item !== null)
    .map((item) => Object.fromEntries(Object.entries(item).map(([k, v]) => [k, typeof v === "string" ? v : ""])));
}

/** The sections to render: switched on, well-formed, first of each type. */
export function visibleSections(design: StoreDesign): DesignSection[] {
  const seen = new Set<string>();

  return (Array.isArray(design.sections) ? design.sections : [])
    .filter((section) => {
      if (!section || typeof section.type !== "string" || section.on !== true || seen.has(section.type)) return false;
      seen.add(section.type);
      return true;
    })
    .map((section) => ({
      ...section,
      props: typeof section.props === "object" && section.props !== null ? section.props : {},
    }));
}
