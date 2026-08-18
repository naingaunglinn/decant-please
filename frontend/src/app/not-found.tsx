// The fail-closed page for hosts that resolve to no shop (ADR-0004): unknown,
// unverified, or the shop is inactive. Deliberately unbranded — there is no
// tenant to brand it with, and it must reveal nothing about which domains or
// shops exist. In-tenant 404s (a dead fragrance link) render the branded
// app/[host]/not-found.tsx instead.
export default function RootNotFound() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-3 px-6 text-center">
      <p className="text-sm font-medium uppercase tracking-[0.2em] text-ink-strong">404</p>
      <p className="text-sm text-muted">There is no storefront at this address.</p>
    </main>
  );
}
