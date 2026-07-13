export function Skeleton({ className = "" }: { className?: string }) {
  return <div className={`animate-pulse rounded-2xl bg-surface-alt ${className}`} aria-hidden />;
}
