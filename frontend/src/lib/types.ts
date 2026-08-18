export interface Brand {
  id: number;
  name: string;
  slug: string;
  type: "designer" | "niche";
  type_label: string;
  logo_url: string | null;
  fragrances_count?: number;
}

export interface DecantPrice {
  size_ml: number;
  price_mmk: number;
  price_formatted: string;
  in_stock: boolean;
}

export interface Fragrance {
  id: number;
  name: string;
  slug: string;
  brand: Brand;
  concentration: string;
  concentration_label: string;
  gender: "male" | "female" | "unisex";
  gender_label: string;
  notes: string | null;
  vibes: string | null;
  performance: string | null;
  description: string | null;
  image_url: string | null;
  is_featured: boolean;
  min_price_mmk: number | null;
  min_price_formatted: string | null;
  prices: DecantPrice[];
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
}

/** Static offline-transfer details the decanter configures — any field may be
 *  absent, and the whole object is null when nothing is set. Not a gateway. */
export interface PaymentInfo {
  kbzpay_name?: string;
  kbzpay_number?: string;
  wave_name?: string;
  wave_number?: string;
  qr_url?: string;
  instructions?: string;
}

export interface CatalogMeta {
  brand_types: { value: string; label: string }[];
  genders: { value: string; label: string }[];
  concentrations: { value: string; label: string }[];
  sizes: number[];
  price: { min: number | null; max: number | null };
  sorts: string[];
  social: { tiktok_url: string | null; facebook_url: string | null };
  payment: PaymentInfo | null;
}

export interface FragranceFilters {
  q?: string;
  notes?: string;
  brand?: string; // comma-separated slugs
  type?: string;
  gender?: string;
  size?: string;
  min_price?: string;
  max_price?: string;
  featured?: string;
  sort?: string;
  page?: string;
  per_page?: string;
}

export interface CheckoutItem {
  fragrance_id: number;
  size_ml: number;
  quantity: number;
}

/** One township the shop delivers to — the fee is display-only here; the
 *  server re-derives it from the id at submission. */
export interface DeliveryTownshipOption {
  id: number;
  name: string;
  name_mm: string | null;
  label: string;
  fee_mmk: number;
  fee_formatted: string;
}

export interface DeliveryRegion {
  value: string;
  label: string;
  townships: DeliveryTownshipOption[];
}

/** The whole serviceable tree in one response — the township select filters
 *  client-side, no request between the two selects. */
export interface DeliveryZones {
  regions: DeliveryRegion[];
}

export interface CheckoutPayload {
  customer_name: string;
  phone: string;
  /** The address is structured: township drives the delivery fee server-side. */
  delivery_township_id: number;
  address_line: string;
  address_extra?: string;
  note?: string;
  promo_code?: string;
  payment_method?: PaymentMethod; // defaults to cod server-side
  website?: string; // honeypot — always empty for humans
  items: CheckoutItem[];
}

export interface CheckoutResponse {
  tracking_code: string;
  total_mmk: number;
  total_formatted: string;
  /** What was actually charged for delivery — cash to the courier. */
  delivery_fee_mmk: number;
  delivery_fee_formatted: string;
  /** Set only when a promo lapsed between preview and submission. */
  promo_note: string | null;
}

export interface PromoPreview {
  valid: boolean;
  discount_mmk: number;
  discount_formatted: string;
  new_total_formatted: string;
  message: string | null;
}

export type OrderStatus =
  | "awaiting_confirmation"
  | "pending"
  | "decanted"
  | "delivered"
  | "cancelled"
  | "rejected";

export type PaymentStatus = "unpaid" | "paid";

export type PaymentMethod = "cod" | "online";

export interface OrderStatusResponse {
  tracking_code: string;
  order_number: string;
  status: OrderStatus;
  status_label: string;
  placed_at: string;
  decant_date: string | null;
  delivery_date: string | null;
  rejection_reason: string | null;
  customer_name: string;
  phone: string;
  address: string;
  items: {
    fragrance_name: string;
    size_ml: number;
    quantity: number;
    unit_price_mmk: number;
    line_total_mmk: number;
  }[];
  subtotal_mmk: number;
  delivery_fee_mmk: number;
  discount_mmk: number;
  promo_code: string | null;
  deposit_mmk: number;
  total_mmk: number;
  total_formatted: string;
  balance_due_mmk: number;
  payment_status: PaymentStatus;
  payment_status_label: string;
  payment_method: PaymentMethod;
  payment_method_label: string;
  has_payment_proof: boolean;
  paid_at: string | null;
}

/** GET /api/v1/_storefront/host/{host} — the shared storefront deployment's
 *  host → shop handshake (ADR-0004; wrapped as `{ data: StorefrontHost }`).
 *  Mirrors the backend's StorefrontHostResource. Unused until PR-B's proxy layer
 *  lands; typed now because the Resource ships now (same-PR rule). */
export interface StorefrontHost {
  slug: string;
  name: string;
  host: string;
  is_primary: boolean;
  primary_host: string;
}

/** A cart line as stored client-side. Prices here are previews only —
 *  the server re-derives the authoritative total at checkout. */
export interface CartLine {
  fragranceId: number;
  sizeMl: number;
  quantity: number;
  name: string;
  brandName: string;
  slug: string;
  priceMmk: number;
  imageUrl: string | null;
}
