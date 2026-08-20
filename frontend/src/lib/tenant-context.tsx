"use client";

import { createContext, useContext } from "react";

export interface TenantInfo {
  slug: string;
  name: string;
}

const TenantContext = createContext<TenantInfo | null>(null);

/** Set once per request by app/[host]/layout.tsx from the server-resolved host. */
export function TenantProvider({
  tenant,
  children,
}: {
  tenant: TenantInfo;
  children: React.ReactNode;
}) {
  return <TenantContext.Provider value={tenant}>{children}</TenantContext.Provider>;
}

/**
 * The resolved tenant for client components (checkout, tracking, proof upload).
 * Read-only by design: the value is whatever the server resolved from the
 * validated Host (ADR-0004) — never a query param, never storage, never
 * client-writable. The backend scope re-checks everything anyway.
 */
export function useTenant(): TenantInfo {
  const tenant = useContext(TenantContext);

  if (!tenant) {
    throw new Error("useTenant() outside <TenantProvider> — is this component outside app/[host]?");
  }

  return tenant;
}
