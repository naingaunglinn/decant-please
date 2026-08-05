import type { ReactNode, SelectHTMLAttributes } from "react";

/**
 * A native <select> that obeys the design system. The browser's own chevron —
 * which it draws inside our `rounded-full` pill with no knowledge of it — is removed
 * with `appearance-none` and replaced by an on-brand one. `pr-12` keeps the longest
 * option clear of that chevron.
 *
 * SelectShell owns the *right* padding (for the chevron) and nothing else about size:
 * consumers set their own left/vertical padding via `className` (e.g. `pl-5 py-3`), so
 * there is never a Tailwind padding conflict and no `cn()`/merge helper is needed.
 *
 * This is the §2 "fix Problem 1" primitive: an on-brand native select, no dependency.
 * The checkout region/township selects moved on to a Radix-backed `Select` (see
 * `Select.tsx`); the shop's sort dropdown stays native and keeps using this.
 */
interface SelectShellProps extends SelectHTMLAttributes<HTMLSelectElement> {
  children: ReactNode;
}

export function SelectShell({ className = "", children, ...rest }: SelectShellProps) {
  return (
    <div className="relative">
      <select
        className={`w-full appearance-none rounded-full border border-rule bg-transparent pr-12 text-base disabled:opacity-50 ${className}`}
        {...rest}
      >
        {children}
      </select>
      <svg
        aria-hidden
        viewBox="0 0 12 8"
        className="pointer-events-none absolute right-5 top-1/2 h-2 w-3 -translate-y-1/2 text-muted"
      >
        <path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" strokeWidth="1.5" />
      </svg>
    </div>
  );
}
