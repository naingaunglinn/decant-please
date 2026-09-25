import type { Product, ProductAttribute } from "./types";

// Step 37b: the storefront renders a product from its template's `attributes`,
// placed by each one's `show` — never from the perfume-only flat keys.

/** The attribute shown beside the product's name ("Aventus EDP"), if the template names one. */
export const headlineOf = (product: Product): ProductAttribute | undefined =>
  product.attributes.find((attribute) => attribute.show === "headline");

/** The pill row: the headline and every `pill` attribute, in template order. */
export const pillsOf = (product: Product): ProductAttribute[] =>
  product.attributes.filter((attribute) => attribute.show === "headline" || attribute.show === "pill");

/** Attributes shown as their own section of pills ("Scent notes"). */
export const listsOf = (product: Product): ProductAttribute[] =>
  product.attributes.filter((attribute) => attribute.show === "list");

export const splitList = (value: string): string[] =>
  value.split(",").map((part) => part.trim()).filter(Boolean);

/** Attributes shown as their own titled paragraph ("Size guide", step 38b). */
export const sectionsOf = (product: Product): ProductAttribute[] =>
  product.attributes.filter((attribute) => attribute.show === "section");

/** "Chanel Bleu", or just the name when the product has no brand (step 38b). */
export const fullName = (product: Product): string =>
  product.brand ? `${product.brand.name} ${product.name}` : product.name;

/** The /products and /shop query key for a variant option filter: `option[Size]` (step 38b). */
export const optionKey = (name: string): string => `option[${name}]`;
