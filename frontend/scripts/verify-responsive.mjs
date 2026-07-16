// Step 11 §5 matrix check: drives every storefront flow at 375/768/1024/1440 in a
// real browser and asserts the Part A fixes (container widths, 16px inputs, touch
// targets, grid steps) plus the §0 "already good" patterns (mobile nav, filter
// sheet/sidebar split, cart drawer cap, sticky add-to-cart, checkout order swap).
//
//   [BASE_URL=http://localhost:3001] [ENGINE=chromium|webkit] node scripts/verify-responsive.mjs
//
// ENGINE=webkit re-runs everything in real WebKit — the closest local proxy for
// iOS Safari's "inputs under 16px zoom on focus" rule (the zoom itself only
// reproduces on an actual iOS device; what we assert is the 16px mechanism).
// Screenshots land in OUT_DIR (default <tmpdir>/decant-responsive). Exits non-zero
// on any failure.

import { mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { chromium, webkit } from "playwright";

const BASE = process.env.BASE_URL ?? "http://localhost:3001";
const ENGINE = process.env.ENGINE ?? "chromium";
const OUT = process.env.OUT_DIR ?? join(tmpdir(), "decant-responsive");
mkdirSync(OUT, { recursive: true });

const WIDTHS = [375, 768, 1024, 1440];
// the three-tier container system: the 480px "reference card" tier now widens at lg/xl
const CONTAINER = { 375: "480px", 768: "480px", 1024: "640px", 1440: "720px" };
const SHOP_COLS = { 375: 2, 768: 3, 1024: 3, 1440: 4 };
const RELATED_COLS = { 375: 2, 768: 2, 1024: 3, 1440: 3 };
const IMG_SIZES = "(max-width: 768px) 100vw, (max-width: 1023px) 480px, (max-width: 1279px) 640px, 720px";

let failed = false;
function report(width, name, ok, detail = "") {
  console.log(`${ok ? "PASS" : "FAIL"}  [${ENGINE} ${width}] ${name}${ok || !detail ? "" : ` — ${detail}`}`);
  if (!ok) failed = true;
}

const browser = await { chromium, webkit }[ENGINE].launch();
const page = await browser.newPage();

// Computed styles can apply a beat after load on the dev server — poll instead of
// sampling once, and report the single final value (no double-evaluation races).
async function check(width, name, getValue, predicate, timeout = 5000) {
  const start = Date.now();
  let value = await getValue();
  while (!predicate(value) && Date.now() - start < timeout) {
    await page.waitForTimeout(150);
    value = await getValue();
  }
  report(width, name, predicate(value), `got ${value}`);
}

const fontSize = (loc) => loc.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
const maxWidth = (loc) => loc.evaluate((el) => getComputedStyle(el).maxWidth);
const gridCols = (loc) => loc.evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(" ").length);
const heightOf = async (loc) => (await loc.boundingBox())?.height ?? 0;

// one PDP slug for the whole run — prefer a fragrance with an uploaded image so
// the image checks actually run (cards without one render the vial placeholder);
// walk the shop pages to find one, falling back to the very first card
await page.goto(`${BASE}/shop`);
const cards = page.locator('main a[href^="/fragrance/"]');
await cards.first().waitFor();
let pdpPath = await cards.first().getAttribute("href");
for (let p = 1; p <= 5; p++) {
  if (p > 1) {
    await page.goto(`${BASE}/shop?page=${p}`);
    await cards.first().waitFor({ timeout: 4000 }).catch(() => {});
    if ((await cards.count()) === 0) break; // past the last page
  }
  // waitFor, not a one-shot count — the grid can still be committing cards
  const withImage = cards.filter({ has: page.locator("img") }).first();
  if (await withImage.waitFor({ timeout: 2500 }).then(() => true).catch(() => false)) {
    pdpPath = await withImage.getAttribute("href");
    break;
  }
}

for (const width of WIDTHS) {
  try {
    await page.setViewportSize({ width, height: 900 });
    const isPhone = width < 640; // tailwind sm
    const hasSidebar = width >= 1024; // tailwind lg

    // -- home: nav split ----------------------------------------------------
    await page.goto(`${BASE}/`);
    await check(width, "hamburger only below sm",
      () => page.getByRole("button", { name: "Open menu" }).isVisible(), (v) => v === isPhone);
    await check(width, "desktop nav links only above sm",
      () => page.locator("header").getByRole("link", { name: "Shop", exact: true }).isVisible(), (v) => v === !isPhone);

    // -- shop: grid steps, filter sheet/sidebar split, pagination targets ----
    await page.goto(`${BASE}/shop`);
    const grid = page.locator("main ul.grid").first();
    await grid.waitFor();
    await check(width, `shop grid has ${SHOP_COLS[width]} columns`, () => gridCols(grid), (v) => v === SHOP_COLS[width]);

    const sheetTrigger = page.getByRole("button", { name: /filter & sort/i });
    await check(width, "filter sheet trigger only below lg", () => sheetTrigger.isVisible(), (v) => v === !hasSidebar);
    if (hasSidebar) {
      const sidebarSearch = page.getByLabel("Search").first();
      await check(width, "sidebar filter input is 16px", () => fontSize(sidebarSearch), (v) => v === 16);
    } else {
      await sheetTrigger.click();
      const sheet = page.getByRole("dialog", { name: "Filter and sort" });
      await sheet.waitFor();
      await check(width, "filter sheet opens; its input is 16px",
        () => fontSize(sheet.getByLabel("Search").first()), (v) => v === 16);
      await sheet.getByRole("button", { name: "Show results" }).click();
      await sheet.waitFor({ state: "hidden" });
      report(width, "filter sheet closes", true);
    }

    const nextLink = page.getByRole("link", { name: /next/i });
    if ((await nextLink.count()) > 0) {
      await check(width, "pagination link ≥44px tall", () => heightOf(nextLink), (v) => v >= 44);
    } else {
      console.log(`SKIP  [${ENGINE} ${width}] pagination not present (single page)`);
    }
    if (width === 768) await page.screenshot({ path: join(OUT, `${ENGINE}-768-shop.png`) });

    // -- PDP: widened container, image sizes hint, related grid, sticky bar --
    await page.goto(`${BASE}${pdpPath}`);
    const article = page.getByRole("main").locator("article").first();
    await article.waitFor();
    await check(width, `PDP container max-width ${CONTAINER[width]}`, () => maxWidth(article), (v) => v === CONTAINER[width]);

    const pdpImg = article.locator("img").first();
    if ((await pdpImg.count()) > 0) {
      await check(width, "PDP image sizes hint matches container tiers",
        () => pdpImg.getAttribute("sizes"), (v) => v === IMG_SIZES);
      // catches the optimizer rejecting localhost upstreams (issue #13) — a
      // blocked /_next/image response leaves naturalWidth at 0
      await check(width, "PDP image actually loads through /_next/image",
        () => pdpImg.evaluate((el) => el.complete && el.naturalWidth > 0), (v) => v === true);
    } else {
      console.log(`SKIP  [${ENGINE} ${width}] PDP has no image`);
    }

    const related = page
      .locator("section", { has: page.getByRole("heading", { name: /you may also like/i }) })
      .locator("div.grid");
    if ((await related.count()) > 0) {
      await check(width, `related grid has ${RELATED_COLS[width]} columns`, () => gridCols(related), (v) => v === RELATED_COLS[width]);
    } else {
      console.log(`SKIP  [${ENGINE} ${width}] no related rail on this fragrance`);
    }

    const addBtn = page.getByRole("button", { name: /^add to cart/i }).first();
    if (isPhone && (await addBtn.count()) > 0) {
      const sticky = page
        .locator("div.fixed.bottom-0")
        .filter({ has: page.getByRole("button", { name: /^add to cart$/i }) });
      await check(width, "sticky bar absent while main button in view", () => sticky.count(), (v) => v === 0, 1000);
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await check(width, "sticky add-to-cart appears after scrolling past the button",
        () => sticky.isVisible(), (v) => v === true);
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    if (width === 1440) await page.screenshot({ path: join(OUT, `${ENGINE}-1440-pdp.png`), fullPage: true });

    // -- cart drawer + checkout: the two extremes only (375 phone, 1440 desktop)
    if (width === 375 || width === 1440) {
      await addBtn.click();
      const drawer = page.getByRole("dialog", { name: "Cart" });
      try {
        await drawer.waitFor({ timeout: 2000 });
      } catch {
        await page.getByRole("button", { name: /open cart/i }).click();
        await drawer.waitFor();
      }
      const expected = width === 375 ? 375 : 384; // w-full capped by max-w-sm (24rem)
      await check(width, `cart drawer width ${expected}px`,
        async () => (await drawer.boundingBox())?.width ?? 0, (v) => Math.abs(v - expected) <= 1);
      await check(width, "cart Remove ≥44px tall",
        () => heightOf(drawer.getByRole("button", { name: "Remove" }).first()), (v) => v >= 44);
      await drawer.getByRole("button", { name: "Close cart" }).click();
      await drawer.waitFor({ state: "hidden" });

      await page.goto(`${BASE}/checkout`);
      const nameInput = page.getByLabel(/your name/i);
      await nameInput.waitFor();
      const promoInput = page.getByLabel("Promo code");
      for (const [label, loc] of [
        ["name", nameInput],
        ["phone", page.getByLabel(/^phone/i)], // its wrapping label includes the hint text
        ["address", page.getByLabel(/delivery address/i)],
        ["note", page.getByLabel(/note \(optional\)/i)],
        ["promo", promoInput],
      ]) {
        await check(width, `checkout ${label} input is 16px`, () => fontSize(loc), (v) => v === 16);
      }
      const nameBox = await nameInput.boundingBox();
      const promoBox = await promoInput.boundingBox();
      if (width === 375) {
        report(width, "summary card sits above the form on mobile", promoBox.y < nameBox.y,
          `summary y=${promoBox.y}, form y=${nameBox.y}`);
        await page.screenshot({ path: join(OUT, `${ENGINE}-375-checkout.png`) });
      } else {
        report(width, "summary card sits beside the form on desktop", promoBox.x > nameBox.x + 200,
          `summary x=${promoBox.x}, form x=${nameBox.x}`);
      }
    }

    // -- track + order/complete: same widened wrapper, 16px inputs -----------
    await page.goto(`${BASE}/track`);
    const trackWrap = page.getByRole("main").locator("div").first();
    await check(width, `track container max-width ${CONTAINER[width]}`, () => maxWidth(trackWrap), (v) => v === CONTAINER[width]);
    if (isPhone) {
      for (const [label, loc] of [
        ["code", page.getByLabel(/tracking code/i)],
        ["phone", page.getByLabel(/phone number you ordered with/i)],
      ]) {
        await check(width, `track ${label} input is 16px`, () => fontSize(loc), (v) => v === 16);
      }
    }

    await page.goto(`${BASE}/order/complete`); // no code → the empty state, same wrapper
    const completeWrap = page.getByRole("main").locator("div").first();
    await check(width, `order-complete container max-width ${CONTAINER[width]}`, () => maxWidth(completeWrap), (v) => v === CONTAINER[width]);
  } catch (error) {
    report(width, `width run crashed: ${String(error.message ?? error).split("\n")[0]}`, false);
  }
}

await browser.close();
console.log(failed ? "\nresult: FAILED" : "\nresult: all checks passed");
process.exit(failed ? 1 : 0);
