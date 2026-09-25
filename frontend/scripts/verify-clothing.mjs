// verify-clothing.mjs — step 38b: a clothing shop on the storefront, end to end.
//
// Runs against the demo clothing shop (DemoClothingShopSeeder), served on its own
// host. Seed it once, from the repo root:
//
//   docker compose exec backend php artisan db:seed --class=DemoClothingShopSeeder --force
//   [BASE_URL=http://clothing.decant.localhost:3001] [API_URL=http://localhost:8010/api] node scripts/verify-clothing.mjs
//
// Proves: the shop's filters render from /meta (Size + Color option groups, no
// perfume groups) and an option filter reaches the API; the product page picks Size
// then Color (colours follow the size, a sold-out one can't be picked), the price
// follows the pick, a brandless product shows no brand, the size guide shows; the
// cart line carries the variant label; checkout accepts the stored variant_id and
// the server derives the price. Plus: the decant shop's product page still uses
// the ml size list. Exits non-zero on any failure.

import { chromium } from "playwright";

const BASE = process.env.BASE_URL ?? "http://clothing.decant.localhost:3001";
const DECANT_BASE = process.env.DECANT_BASE_URL ?? "http://localhost:3001";
const API = process.env.API_URL ?? "http://localhost:8010/api";
const SHOP = process.env.SHOP_SLUG ?? "demo-clothing";

let failures = 0;

async function check(label, read, ok) {
  let value;
  try {
    value = await read();
  } catch (error) {
    value = `threw: ${error.message}`;
  }
  const passed = ok(value);
  if (!passed) failures++;
  console.log(`${passed ? "PASS " : "FAIL "} ${label}${passed ? "" : `  — got ${JSON.stringify(value)}`}`);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const consoleErrors = [];
page.on("console", (message) => {
  if (message.type() === "error") consoleErrors.push(message.text());
});

// -- the shop: filters from /meta
await page.goto(`${BASE}/shop`, { waitUntil: "networkidle" });
const filters = page.locator("aside, fieldset").first();
await filters.waitFor();
const legends = await page.locator("fieldset legend").allInnerTexts();
await check("option filter groups Size and Color render", () => legends.map((l) => l.toLowerCase()),
  (v) => v.includes("size") && v.includes("color"));
await check("no perfume-only group (Brand type) and no ml size pills", async () => ({
  brandType: legends.some((l) => /brand type/i.test(l)),
  ml: await page.getByRole("button", { name: /^\d+ml$/i }).count(),
}), (v) => v.brandType === false && v.ml === 0);

await page.locator("fieldset", { hasText: /^color/i }).getByRole("button", { name: "Maroon" }).first().click();
await check("the pill writes option[Color]=Maroon to the URL", () =>
  page.waitForFunction(() => new URLSearchParams(location.search).get("option[Color]") === "Maroon", null, { timeout: 10000 }).then(() => true),
  (v) => v === true);
await check("?option[Color]=Maroon narrows the grid to the longyi", async () => {
  await page.getByRole("heading", { name: /silk blouse/i }).waitFor({ state: "detached", timeout: 10000 });
  return page.locator("main h3").allInnerTexts();
}, (v) => v.length === 1 && /cotton longyi/i.test(v[0]));

// -- the product page: pick Size, then Color
await page.goto(`${BASE}/product/linen-shirt`, { waitUntil: "networkidle" });
const article = page.getByRole("main").locator("article").first();
await article.waitFor();
const sizes = article.getByRole("radiogroup", { name: "Size" });
const colors = article.getByRole("radiogroup", { name: "Color" });
await check("a picker per option, no ml size list", async () => ({
  size: await sizes.count(), color: await colors.count(),
  decant: await article.getByRole("radiogroup", { name: "Decant size" }).count(),
}), (v) => v.size === 1 && v.color === 1 && v.decant === 0);
await check("brandless product: no brand pill above the name", () => article.locator("header").locator("span").count(), (v) => v === 0);
await check("size guide section shows the seller's measurements",
  () => article.locator("section", { has: page.getByRole("heading", { name: "Size guide" }) }).innerText(),
  (v) => /M — chest 36 in/.test(v));

await check("starts on the first in-stock variant (S / White)", async () => ({
  size: await sizes.getByRole("radio", { checked: true }).innerText(),
  color: await colors.getByRole("radio", { checked: true }).innerText(),
}), (v) => /^s$/i.test(v.size) && /^white$/i.test(v.color));
await check("under S only White is offered", () => colors.getByRole("radio").allInnerTexts(),
  (v) => v.length === 1 && /white/i.test(v[0]));

await sizes.getByRole("radio", { name: "M" }).click();
await colors.getByRole("radio", { name: "Blue" }).click();
await check("M / Blue picked, button shows its price (19,000 Ks)",
  () => article.getByRole("button", { name: /^add to cart/i }).first().innerText(),
  (v) => /19,000 Ks/i.test(v)); // the button is uppercase

await sizes.getByRole("radio", { name: "L" }).waitFor();
await check("L (only in sold-out Blue) can't be picked", () => sizes.getByRole("radio", { name: "L" }).isDisabled(), (v) => v === true);

// -- cart line
await article.getByRole("button", { name: /^add to cart/i }).first().click();
const stored = await page.evaluate(() => JSON.parse(window.localStorage.getItem("decant-please.cart.v2") ?? "[]"));
await check("cart line: M / Blue, no brand, 19,000 Ks preview", () => stored.map((l) => [l.label, l.brandName, l.priceMmk]),
  (v) => v.length === 1 && v[0][0] === "M / Blue" && v[0][1] === null && v[0][2] === 19000);
const drawer = page.getByRole("dialog", { name: "Cart" });
try {
  await drawer.waitFor({ timeout: 2000 });
} catch {
  await page.getByRole("button", { name: /open cart/i }).click();
  await drawer.waitFor();
}
await check("cart drawer shows the variant label", () => drawer.innerText(), (v) => /M \/ Blue/i.test(v));

// -- checkout by the stored variant_id (the API; the checkout form itself is verify-responsive's)
const zones = await (await fetch(`${API}/v1/${SHOP}/delivery-zones`)).json();
const township = zones.regions?.flatMap((r) => r.townships)?.[0];
const order = await fetch(`${API}/v1/${SHOP}/orders`, {
  method: "POST",
  headers: { "Content-Type": "application/json", Accept: "application/json" },
  body: JSON.stringify({
    customer_name: "Verify Clothing",
    phone: "09-771234561",
    delivery_township_id: township?.id,
    address_line: "No. 1, Verify Road",
    items: [{ variant_id: stored[0]?.variantId, quantity: 2 }],
  }),
});
const placed = await order.json();
await check("checkout by variant_id: 2 × 19,000 + delivery, derived server-side",
  () => ({ status: order.status, total: placed.total_mmk, fee: placed.delivery_fee_mmk }),
  (v) => v.status === 201 && v.total === 38000 + v.fee);

// -- tracking: the timeline names the "prepared" step in the shop's words (step 39)
await page.goto(`${BASE}/track`, { waitUntil: "networkidle" });
await page.getByLabel(/tracking code/i).fill(placed.tracking_code ?? "");
await page.getByLabel(/phone/i).fill("09-771234561");
await page.getByRole("button", { name: /track this order/i }).click();
await check("tracking timeline says Packed, not Decanted",
  async () => {
    await page.locator("main ol li").first().waitFor();
    return page.locator("main ol").innerText();
  },
  (v) => /packed/i.test(v) && !/decant/i.test(v));

// -- decant unchanged
await page.goto(`${DECANT_BASE}/shop`, { waitUntil: "networkidle" });
const firstCard = page.getByRole("main").locator("a[href^='/product/']").first();
await firstCard.waitFor();
await firstCard.click();
await page.waitForURL(/\/product\//);
await check("decant product page still uses the ml size list",
  () => page.getByRole("radiogroup", { name: "Decant size" }).count(), (v) => v === 1);

await check("no console errors", () => consoleErrors, (v) => v.length === 0);

await browser.close();
console.log(failures === 0 ? "\nall clothing checks passed" : `\n${failures} check(s) failed`);
process.exit(failures === 0 ? 0 : 1);
