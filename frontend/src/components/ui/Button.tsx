import Link from "next/link";
import type { ButtonHTMLAttributes, ReactNode } from "react";

type Variant = "solid" | "outline" | "ghost";

const VARIANTS: Record<Variant, string> = {
  solid: "bg-pine text-mist hover:bg-pine/90 border border-pine",
  outline: "border border-pine text-pine hover:bg-pine-soft",
  ghost: "text-pine hover:bg-pine-soft border border-transparent",
};

const BASE =
  "inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-7 text-xs font-medium uppercase tracking-[0.15em] transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-40";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  href?: string;
  children: ReactNode;
}

export function Button({ variant = "solid", href, className = "", children, ...rest }: ButtonProps) {
  const classes = `${BASE} ${VARIANTS[variant]} ${className}`;

  if (href) {
    return (
      <Link href={href} className={classes}>
        {children}
      </Link>
    );
  }

  return (
    <button className={classes} {...rest}>
      {children}
    </button>
  );
}
