import type {
  Brand,
  CatalogMeta,
  CheckoutItem,
  CheckoutPayload,
  CheckoutResponse,
  Fragrance,
  FragranceFilters,
  OrderStatusResponse,
  Paginated,
  PromoPreview,
} from "./types";

const BASE = `${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8010/api"}/v1`;

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

async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${BASE}${path}`, {
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
  filters: FragranceFilters = {},
): Promise<Paginated<Fragrance>> {
  const params = new URLSearchParams(
    Object.entries(filters).filter(([, value]) => value !== undefined && value !== ""),
  );
  const qs = params.size > 0 ? `?${params}` : "";

  return apiFetch(`/fragrances${qs}`, { next: { revalidate: 60 } });
}

export async function getFragrance(slug: string): Promise<Fragrance | null> {
  const response = await fetch(`${BASE}/fragrances/${encodeURIComponent(slug)}`, {
    headers: { Accept: "application/json" },
    next: { revalidate: 60 },
  });

  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API request failed: ${response.status}`);

  const body = await response.json();
  return body.data;
}

export async function getBrands(): Promise<Brand[]> {
  const body = await apiFetch<{ data: Brand[] }>("/brands", { next: { revalidate: 60 } });
  return body.data;
}

export async function getMeta(): Promise<CatalogMeta> {
  return apiFetch("/meta", { next: { revalidate: 60 } });
}

export async function createOrder(
  payload: CheckoutPayload,
  proof?: File | null,
): Promise<CheckoutResponse> {
  // Online orders attach their transfer slip at checkout → multipart. COD stays JSON.
  if (!proof) {
    return apiFetch("/orders", {
      method: "POST",
      body: JSON.stringify(payload),
      cache: "no-store",
    });
  }

  const form = new FormData();
  form.append("customer_name", payload.customer_name);
  form.append("phone", payload.phone);
  form.append("address", payload.address);
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
  const response = await fetch(`${BASE}/orders`, {
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
  code: string,
  items: CheckoutItem[],
): Promise<PromoPreview> {
  return apiFetch("/orders/validate-promo", {
    method: "POST",
    body: JSON.stringify({ code, items }),
    cache: "no-store",
  });
}

/** Returns null when the code+phone pair doesn't match — the API deliberately
 *  never says which half was wrong. */
export async function trackOrder(
  trackingCode: string,
  phone: string,
): Promise<OrderStatusResponse | null> {
  const params = new URLSearchParams({ tracking_code: trackingCode, phone });
  const response = await fetch(`${BASE}/orders/track?${params}`, {
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
  trackingCode: string,
  phone: string,
  file: File,
): Promise<OrderStatusResponse | null> {
  const form = new FormData();
  form.append("tracking_code", trackingCode);
  form.append("phone", phone);
  form.append("proof", file);

  const response = await fetch(`${BASE}/orders/payment-proof`, {
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
  trackingCode: string,
  phone: string,
): Promise<OrderStatusResponse | null> {
  const response = await fetch(`${BASE}/orders/cancel`, {
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
