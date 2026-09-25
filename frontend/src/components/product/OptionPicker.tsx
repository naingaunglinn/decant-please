"use client";

import type { ProductVariant } from "@/lib/types";

interface OptionPickerProps {
  variants: ProductVariant[];
  selected: ProductVariant | undefined;
  onSelect: (variantId: number) => void;
}

/** Option names across the variants, in the order the API sends them (the template's). */
const optionNames = (variants: ProductVariant[]): string[] => [
  ...new Set(variants.flatMap((variant) => Object.keys(variant.options))),
];

/** Every earlier axis matches the current pick: Color's choices follow the picked Size. */
const matchesEarlier = (variant: ProductVariant, names: string[], axis: number, picks: Record<string, string>) =>
  names.slice(0, axis).every((name) => variant.options[name] === picks[name]);

/**
 * The step-38b picker for variants that are option values, not ml sizes: one row of
 * pills per option, in template order (Size, then Color). A later row lists only
 * what exists for the earlier picks; a sold-out value stays visible but can't be
 * picked. Picking a value selects the closest in-stock variant — keeping the later
 * picks when that combination exists — so the selection is always something to buy.
 */
export function OptionPicker({ variants, selected, onSelect }: OptionPickerProps) {
  const names = optionNames(variants);
  const picks = selected?.options ?? {};

  const pick = (axis: number, value: string) => {
    const candidates = variants.filter(
      (variant) =>
        variant.in_stock &&
        variant.options[names[axis]] === value &&
        matchesEarlier(variant, names, axis, picks),
    );
    const keepsLater = candidates.find((variant) =>
      names.slice(axis + 1).every((name) => variant.options[name] === picks[name]),
    );
    const next = keepsLater ?? candidates[0];
    if (next) onSelect(next.id);
  };

  return (
    <div className="flex flex-col gap-5">
      {names.map((name, axis) => {
        const reachable = variants.filter((variant) => matchesEarlier(variant, names, axis, picks));
        const values = [
          ...new Set(reachable.map((variant) => variant.options[name]).filter((value): value is string => Boolean(value))),
        ];
        if (values.length === 0) return null;

        return (
          <div key={name}>
            <p className="mb-3 text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
              {name}
              {picks[name] && <span className="text-ink"> · {picks[name]}</span>}
            </p>
            <div role="radiogroup" aria-label={name} className="flex flex-wrap gap-2">
              {values.map((value) => {
                const isSelected = picks[name] === value;
                const available = reachable.some(
                  (variant) => variant.options[name] === value && variant.in_stock,
                );

                return (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={isSelected}
                    disabled={!available}
                    onClick={() => pick(axis, value)}
                    className={`min-h-11 min-w-11 rounded-full border px-4 text-sm font-medium uppercase tracking-[0.12em] transition-colors ${
                      isSelected
                        ? "border-pine bg-pine-soft text-pine"
                        : available
                          ? "cursor-pointer border-rule text-ink hover:border-pine/40"
                          : "cursor-not-allowed border-rule text-muted line-through"
                    }`}
                  >
                    {value}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
