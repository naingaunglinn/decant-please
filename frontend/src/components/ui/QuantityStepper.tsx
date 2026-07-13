"use client";

interface QuantityStepperProps {
  value: number;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
  compact?: boolean;
  label?: string;
}

export function QuantityStepper({
  value,
  onChange,
  min = 1,
  max = 50,
  compact = false,
  label = "Quantity",
}: QuantityStepperProps) {
  const size = compact ? "size-8" : "size-11";

  return (
    <div
      className="inline-flex items-center rounded-full border border-rule"
      role="group"
      aria-label={label}
    >
      <button
        type="button"
        aria-label={`Decrease ${label.toLowerCase()}`}
        disabled={value <= min}
        onClick={() => onChange(Math.max(min, value - 1))}
        className={`${size} rounded-full text-lg leading-none text-ink transition-colors hover:bg-pine-soft disabled:opacity-30`}
      >
        −
      </button>
      <span className={`${compact ? "min-w-7" : "min-w-9"} text-center text-sm tabular-nums`} aria-live="polite">
        {value}
      </span>
      <button
        type="button"
        aria-label={`Increase ${label.toLowerCase()}`}
        disabled={value >= max}
        onClick={() => onChange(Math.min(max, value + 1))}
        className={`${size} rounded-full text-lg leading-none text-ink transition-colors hover:bg-pine-soft disabled:opacity-30`}
      >
        +
      </button>
    </div>
  );
}
