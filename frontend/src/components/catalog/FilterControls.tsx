"use client";

import { startTransition, useEffect, useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import type { Brand, CatalogMeta } from "@/lib/types";

/** URL params owned by the filter UI (page resets whenever one changes). */
const FILTER_KEYS = ["q", "notes", "brand", "brand_type", "gender", "size", "min_price", "max_price", "sort"] as const;

export function useActiveFilterCount(): number {
  const searchParams = useSearchParams();
  return FILTER_KEYS.filter((key) => searchParams.has(key) && key !== "sort").length;
}

interface FilterControlsProps {
  brands: Brand[];
  meta: CatalogMeta;
}

export function FilterControls({ brands, meta }: FilterControlsProps) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const setParams = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams.toString());
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === "") next.delete(key);
      else next.set(key, value);
    }
    next.delete("page");
    startTransition(() => {
      router.replace(`${pathname}?${next.toString()}`, { scroll: false });
    });
  };

  const selectedBrands = useMemo(
    () => new Set((searchParams.get("brand") ?? "").split(",").filter(Boolean)),
    [searchParams],
  );

  const toggleBrand = (slug: string) => {
    const next = new Set(selectedBrands);
    if (next.has(slug)) next.delete(slug);
    else next.add(slug);
    setParams({ brand: [...next].join(",") || null });
  };

  const toggleValue = (key: string, value: string) => {
    setParams({ [key]: searchParams.get(key) === value ? null : value });
  };

  const activeCount = FILTER_KEYS.filter((key) => searchParams.has(key) && key !== "sort").length;

  return (
    <div className="flex flex-col gap-8">
      <DebouncedInput
        label="Search"
        placeholder="Fragrance or brand…"
        value={searchParams.get("q") ?? ""}
        onCommit={(value) => setParams({ q: value || null })}
      />

      <DebouncedInput
        label="Scent notes"
        placeholder="e.g. vanilla, musk…"
        value={searchParams.get("notes") ?? ""}
        onCommit={(value) => setParams({ notes: value || null })}
      />

      <FilterGroup label="Brand">
        <ul className="flex flex-col gap-2">
          {brands.map((brand) => (
            <li key={brand.slug}>
              <label className="flex min-h-8 cursor-pointer items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  checked={selectedBrands.has(brand.slug)}
                  onChange={() => toggleBrand(brand.slug)}
                  className="size-4 accent-pine"
                />
                <span className="flex-1">{brand.name}</span>
                <span className="text-xs tabular-nums text-muted">{brand.fragrances_count}</span>
              </label>
            </li>
          ))}
        </ul>
      </FilterGroup>

      <FilterGroup label="Brand type">
        <PillToggleRow
          options={meta.brand_types}
          selected={searchParams.get("brand_type")}
          onToggle={(value) => toggleValue("brand_type", value)}
        />
      </FilterGroup>

      <FilterGroup label="Gender">
        <PillToggleRow
          options={meta.genders}
          selected={searchParams.get("gender")}
          onToggle={(value) => toggleValue("gender", value)}
        />
      </FilterGroup>

      <FilterGroup label="Size">
        <PillToggleRow
          options={meta.sizes.map((size) => ({ value: String(size), label: `${size}ml` }))}
          selected={searchParams.get("size")}
          onToggle={(value) => toggleValue("size", value)}
        />
      </FilterGroup>

      <FilterGroup label="Price (Ks)">
        <div className="flex items-center gap-2">
          <DebouncedInput
            label="Min price"
            hideLabel
            type="number"
            placeholder={meta.price.min !== null ? String(meta.price.min) : "Min"}
            value={searchParams.get("min_price") ?? ""}
            onCommit={(value) => setParams({ min_price: value || null })}
          />
          <span className="text-muted" aria-hidden>–</span>
          <DebouncedInput
            label="Max price"
            hideLabel
            type="number"
            placeholder={meta.price.max !== null ? String(meta.price.max) : "Max"}
            value={searchParams.get("max_price") ?? ""}
            onCommit={(value) => setParams({ max_price: value || null })}
          />
        </div>
      </FilterGroup>

      <FilterGroup label="Sort">
        <select
          value={searchParams.get("sort") ?? "newest"}
          onChange={(event) => setParams({ sort: event.target.value === "newest" ? null : event.target.value })}
          className="w-full rounded-full border border-rule bg-transparent px-4 py-2.5 text-base"
        >
          <option value="newest">Newest first</option>
          <option value="price_asc">Price — low to high</option>
          <option value="price_desc">Price — high to low</option>
          <option value="name">Name A–Z</option>
        </select>
      </FilterGroup>

      {activeCount > 0 && (
        <button
          type="button"
          onClick={() => startTransition(() => router.replace(pathname, { scroll: false }))}
          className="self-start text-xs font-medium uppercase tracking-[0.15em] text-pine underline underline-offset-4"
        >
          Clear all ({activeCount})
        </button>
      )}
    </div>
  );
}

function FilterGroup({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <fieldset>
      <legend className="mb-3 text-[11px] font-medium uppercase tracking-[0.18em] text-muted">
        {label}
      </legend>
      {children}
    </fieldset>
  );
}

function PillToggleRow({
  options,
  selected,
  onToggle,
}: {
  options: { value: string; label: string }[];
  selected: string | null;
  onToggle: (value: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2">
      {options.map((option) => {
        const isActive = selected === option.value;
        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={isActive}
            onClick={() => onToggle(option.value)}
            className={`min-h-9 rounded-full border px-4 text-[11px] font-medium uppercase tracking-[0.15em] transition-colors ${
              isActive
                ? "border-pine bg-pine-soft text-pine"
                : "border-rule text-ink hover:border-pine/40"
            }`}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

function DebouncedInput({
  label,
  hideLabel = false,
  value,
  onCommit,
  type = "text",
  placeholder,
}: {
  label: string;
  hideLabel?: boolean;
  value: string;
  onCommit: (value: string) => void;
  type?: string;
  placeholder?: string;
}) {
  const [draft, setDraft] = useState(value);

  // keep in sync when the URL changes from elsewhere (e.g. clear all)
  useEffect(() => setDraft(value), [value]);

  useEffect(() => {
    if (draft === value) return;
    const handle = setTimeout(() => onCommit(draft.trim()), 350);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft]);

  return (
    <label className="block w-full">
      <span
        className={
          hideLabel
            ? "sr-only"
            : "mb-3 block text-[11px] font-medium uppercase tracking-[0.18em] text-muted"
        }
      >
        {label}
      </span>
      <input
        type={type}
        inputMode={type === "number" ? "numeric" : undefined}
        value={draft}
        placeholder={placeholder}
        onChange={(event) => setDraft(event.target.value)}
        className="w-full rounded-full border border-rule bg-transparent px-4 py-2.5 text-base placeholder:text-muted/70"
      />
    </label>
  );
}
