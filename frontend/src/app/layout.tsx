import type { Viewport } from "next";
import "./globals.css";

// The shell only. Everything tenant-aware — metadata, nav, cart, providers —
// lives in app/[host]/layout.tsx (ADR-0004): src/proxy.ts rewrites every public
// host to /{host}/…, so this layout wraps every storefront page plus the
// fail-closed root not-found shown for hosts that resolve to no shop.
export const viewport: Viewport = {
  themeColor: "#F2F8FC",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
