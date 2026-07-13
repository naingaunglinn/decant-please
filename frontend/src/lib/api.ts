import type {
  Brand,
  CatalogMeta,
  CheckoutPayload,
  CheckoutResponse,
  Fragrance,
  FragranceFilters,
  OrderStatusResponse,
  Paginated,
} from "./types";

const BASE = `${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api"}/v1`;

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

export async function createOrder(payload: CheckoutPayload): Promise<CheckoutResponse> {
  return apiFetch("/orders", {
    method: "POST",
    body: JSON.stringify(payload),
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
