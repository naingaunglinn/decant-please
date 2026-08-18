import type {
  Brand,
  CatalogMeta,
  CheckoutItem,
  CheckoutPayload,
  CheckoutResponse,
  DeliveryZones,
  Fragrance,
  FragranceFilters,
  OrderStatusResponse,
  Paginated,
  PromoPreview,
} from "./types";

// ADR-0004 (PR-B): one storefront deployment serves every shop, so the shop is a
// per-request fact, not a build-time constant — every helper takes the resolved
// slug explicitly. Server components get it from lib/tenant.ts (the validated
// Host); client components from useTenant() (lib/tenant-context.tsx). There is
// deliberately no module-scope tenant state and no default shop: an unthreaded
// call site is a compile error, never a silent wrong-shop request.
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8010/api";

const base = (shop: string): string => `${API_URL}/v1/${encodeURIComponent(shop)}`;

/** Thrown on 422s so callers can surface per-field / per-item messages. */
export class ApiValidationError extends Error {
  constructor(
    message: string,
    public errors: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiValidationError";
  }
}

async function apiFetch<T>(shop: string, path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${base(shop)}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(init?.body ? { "Content-Type": "application/json" } : {}),
      ...init?.headers,
    },
  });

  if (response.status === 422) {
    const body = await response.json();
    throw new ApiValidationError(body.message ?? "Validation failed.", body.errors ?? {});
  }

  if (!response.ok) {
    throw new Error(`API request failed: ${response.status} ${path}`);
  }

  return response.json();
}

export async function getFragrances(
  shop: string,
  filters: FragranceFilters = {},
): Promise<Paginated<Fragrance>> {
  const params = new URLSearchParams(
    Object.entries(filters).filter(([, value]) => value !== undefined && value !== ""),
  );
  const qs = params.size > 0 ? `?${params}` : "";

  return apiFetch(shop, `/fragrances${qs}`, { next: { revalidate: 60 } });
}

export async function getFragrance(shop: string, slug: string): Promise<Fragrance | null> {
  const response = await fetch(`${base(shop)}/fragrances/${encodeURIComponent(slug)}`, {
    headers: { Accept: "application/json" },
    next: { revalidate: 60 },
  });

  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  const body = await response.json();
  return body.data;
}

export async function getBrands(shop: string): Promise<Brand[]> {
  const body = await apiFetch<{ data: Brand[] }>(shop, "/brands", { next: { revalidate: 60 } });
  return body.data;
}

export async function getMeta(shop: string): Promise<CatalogMeta> {
  return apiFetch(shop, "/meta", { next: { revalidate: 60 } });
}

/** The serviceable delivery tree — fetched once on the checkout page. If this
 *  fails, checkout fails visibly: there is deliberately no free-text fallback,
 *  or an order would land with no zone and no fee. */
export async function getDeliveryZones(shop: string): Promise<DeliveryZones> {
  return apiFetch(shop, "/delivery-zones", { cache: "no-store" });
}

export async function createOrder(
  shop: string,
  payload: CheckoutPayload,
  proof?: File | null,
): Promise<CheckoutResponse> {
  // Online orders attach their transfer slip at checkout → multipart. COD stays JSON.
  if (!proof) {
    return apiFetch(shop, "/orders", {
      method: "POST",
      body: JSON.stringify(payload),
      cache: "no-store",
    });
  }

  const form = new FormData();
  form.append("customer_name", payload.customer_name);
  form.append("phone", payload.phone);
  form.append("delivery_township_id", String(payload.delivery_township_id));
  form.append("address_line", payload.address_line);
  if (payload.address_extra) form.append("address_extra", payload.address_extra);
  if (payload.note) form.append("note", payload.note);
  if (payload.promo_code) form.append("promo_code", payload.promo_code);
  if (payload.payment_method) form.append("payment_method", payload.payment_method);
  if (payload.website) form.append("website", payload.website);
  payload.items.forEach((item, i) => {
    form.append(`items[${i}][fragrance_id]`, String(item.fragrance_id));
    form.append(`items[${i}][size_ml]`, String(item.size_ml));
    form.append(`items[${i}][quantity]`, String(item.quantity));
  });
  form.append("proof", proof);

  // No Content-Type — the browser sets the multipart boundary.
  const response = await fetch(`${base(shop)}/orders`, {
    method: "POST",
    headers: { Accept: "application/json" },
    body: form,
    cache: "no-store",
  });

  if (response.status === 422) {
    const body = await response.json();
    throw new ApiValidationError(body.message ?? "Validation failed.", body.errors ?? {});
  }
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  return response.json();
}

/** Preview a promo against the current cart — server re-derives the subtotal.
 *  Business rejections come back as { valid: false, message } with a 200. */
export async function validatePromo(
  shop: string,
  code: string,
  items: CheckoutItem[],
): Promise<PromoPreview> {
  return apiFetch(shop, "/orders/validate-promo", {
    method: "POST",
    body: JSON.stringify({ code, items }),
    cache: "no-store",
  });
}

/** Returns null when the code+phone pair doesn't match — the API deliberately
 *  never says which half was wrong. */
export async function trackOrder(
  shop: string,
  trackingCode: string,
  phone: string,
): Promise<OrderStatusResponse | null> {
  const params = new URLSearchParams({ tracking_code: trackingCode, phone });
  const response = await fetch(`${base(shop)}/orders/track?${params}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  });

  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  return response.json();
}

/** Uploads the customer's transfer screenshot. Multipart, so Content-Type is left
 *  to the browser (it sets the boundary). Same generic-404 contract as trackOrder;
 *  a 422 (wrong file type/size) throws ApiValidationError. Does NOT mark the order
 *  paid — the decanter confirms that separately. */
export async function uploadPaymentProof(
  shop: string,
  trackingCode: string,
  phone: string,
  file: File,
): Promise<OrderStatusResponse | null> {
  const form = new FormData();
  form.append("tracking_code", trackingCode);
  form.append("phone", phone);
  form.append("proof", file);

  const response = await fetch(`${base(shop)}/orders/payment-proof`, {
    method: "POST",
    headers: { Accept: "application/json" },
    body: form,
    cache: "no-store",
  });

  if (response.status === 404) return null;
  if (response.status === 422) {
    const body = await response.json();
    throw new ApiValidationError(body.message ?? "Validation failed.", body.errors ?? {});
  }
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  return response.json();
}

/** Thrown when a cancel arrives too late — the order is already being prepared. */
export class ApiConflictError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "ApiConflictError";
  }
}

/** Same generic-404 contract as trackOrder; 409 (already accepted) throws
 *  ApiConflictError with the server's customer-facing message. */
export async function cancelOrder(
  shop: string,
  trackingCode: string,
  phone: string,
): Promise<OrderStatusResponse | null> {
  const response = await fetch(`${base(shop)}/orders/cancel`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ tracking_code: trackingCode, phone }),
    cache: "no-store",
  });

  if (response.status === 404) return null;
  if (response.status === 409) {
    const body = await response.json().catch(() => null);
    throw new ApiConflictError(
      body?.message ?? "This order's already being prepared — call to cancel or change it.",
    );
  }
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  return response.json();
}
