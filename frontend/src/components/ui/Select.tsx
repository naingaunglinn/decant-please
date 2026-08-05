"use client";

import * as SelectPrimitive from "@radix-ui/react-select";
import type { ComponentProps } from "react";

/**
 * A Radix-backed <Select> under this project's design language.
 *
 * Why it exists: a native <select>'s option popup is drawn by the browser chrome
 * (OS-blue list, square corners, system font) and no CSS reaches it — so on the
 * checkout, where §3's apothecary restraint has to hold, the list looked unfinished.
 * Radix gives us a real element to style; type-ahead and keyboard nav come for free.
 *
 * This follows shadcn's copy-in *spirit* — we own the source and restyle it to project
 * tokens — but NOT shadcn's token layer, CLI, `cn()`, or icon library. Every class here
 * is a project token; `globals.css`'s @theme block is untouched. Icons are inline SVG (the
 * same chevron used elsewhere), so `lucide` isn't a dependency. `data-slot` attributes are
 * kept for debugging/future hooks.
 *
 * Hard rule (v5): every control is `text-base` (16px). A smaller size makes iOS Safari
 * zoom the viewport on focus — worst of all on checkout — so no size here is under 16px.
 */

export function Select(props: ComponentProps<typeof SelectPrimitive.Root>) {
  return <SelectPrimitive.Root data-slot="select" {...props} />;
}

export function SelectValue(props: ComponentProps<typeof SelectPrimitive.Value>) {
  return <SelectPrimitive.Value data-slot="select-value" {...props} />;
}

export function SelectTrigger({
  className = "",
  children,
  ...props
}: ComponentProps<typeof SelectPrimitive.Trigger>) {
  return (
    <SelectPrimitive.Trigger
      data-slot="select-trigger"
      className={
        "flex min-h-12 w-full items-center justify-between gap-2 rounded-full border " +
        "border-rule bg-transparent px-5 py-3 text-left text-base text-ink transition-colors " +
        "data-[placeholder]:text-muted disabled:cursor-not-allowed disabled:opacity-50 " +
        "[&>span]:truncate " +
        className
      }
      {...props}
    >
      {children}
      <SelectPrimitive.Icon asChild>
        <svg aria-hidden viewBox="0 0 12 8" className="pointer-events-none h-2 w-3 shrink-0 text-muted">
          <path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.5" />
        </svg>
      </SelectPrimitive.Icon>
    </SelectPrimitive.Trigger>
  );
}

export function SelectContent({
  className = "",
  children,
  position = "popper",
  ...props
}: ComponentProps<typeof SelectPrimitive.Content>) {
  return (
    <SelectPrimitive.Portal>
      <SelectPrimitive.Content
        data-slot="select-content"
        position={position}
        sideOffset={4}
        className={
          "relative z-50 max-h-[var(--radix-select-content-available-height)] " +
          "min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-2xl border " +
          "border-rule bg-mist text-ink shadow-[0_10px_40px_-15px_rgba(1,62,55,0.35)] " +
          className
        }
        {...props}
      >
        <SelectScrollUpButton />
        <SelectPrimitive.Viewport data-slot="select-viewport" className="p-1.5">
          {children}
        </SelectPrimitive.Viewport>
        <SelectScrollDownButton />
      </SelectPrimitive.Content>
    </SelectPrimitive.Portal>
  );
}

export function SelectItem({
  className = "",
  children,
  ...props
}: ComponentProps<typeof SelectPrimitive.Item>) {
  return (
    <SelectPrimitive.Item
      data-slot="select-item"
      className={
        "relative flex w-full cursor-pointer select-none items-center rounded-xl py-2.5 pl-9 pr-3 " +
        "text-base text-ink outline-none data-[highlighted]:bg-pine-soft " +
        "data-[state=checked]:text-pine data-[disabled]:pointer-events-none data-[disabled]:opacity-50 " +
        className
      }
      {...props}
    >
      <span className="absolute left-3 flex h-3.5 w-3.5 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <svg aria-hidden viewBox="0 0 14 14" className="h-3.5 w-3.5 text-pine">
            <path
              d="M11.5 4l-5.5 5.5L3 6.5"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </SelectPrimitive.ItemIndicator>
      </span>
      <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
    </SelectPrimitive.Item>
  );
}

/** Scroll affordances — shown by Radix only when the list overflows its max height. */
function SelectScrollUpButton() {
  return (
    <SelectPrimitive.ScrollUpButton
      data-slot="select-scroll-up"
      className="flex cursor-default items-center justify-center py-1 text-muted"
    >
      <svg aria-hidden viewBox="0 0 12 8" className="h-2 w-3">
        <path d="M1 7l5-5 5 5" fill="none" stroke="currentColor" strokeWidth="1.5" />
      </svg>
    </SelectPrimitive.ScrollUpButton>
  );
}

function SelectScrollDownButton() {
  return (
    <SelectPrimitive.ScrollDownButton
      data-slot="select-scroll-down"
      className="flex cursor-default items-center justify-center py-1 text-muted"
    >
      <svg aria-hidden viewBox="0 0 12 8" className="h-2 w-3">
        <path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.5" />
      </svg>
    </SelectPrimitive.ScrollDownButton>
  );
}
