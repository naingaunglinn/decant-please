// verify-design.mjs — step 46b: the storefront renders each shop's published design.
//
//   [DECANT_BASE_URL=http://localhost:3001] [CLOTHING_BASE_URL=http://clothing.decant.localhost:3001]
//   [API_URL=http://localhost:8010/api] node scripts/verify-design.mjs
//
// Proves:
// - Decant Please with nothing published looks as the home page did before 46b:
//   the same copy (hero, both buttons, How it works, the three tiles), the same
//   colours and font, the middle tile dark.
// - The demo clothing shop (DemoClothingShopSeeder) renders whatever design is
//   live in its /meta: the hero copy, the section headings, the background and
//   primary colours on the frame and the buttons, readable text on the primary.
//
// Parity snapshot (one-off, for a before/after comparison of the decant home):
//   SNAPSHOT_OUT=/tmp/before.json node scripts/verify-design.mjs   # on the old code
//   BASELINE=/tmp/before.json node scripts/verify-design.mjs        # on the new code
// Exits non-zero on any failure.

import { readFileSync, writeFileSync } from "node:fs";
import { chromium } from "playwright";

const DECANT = process.env.DECANT_BASE_URL ?? "http://localhost:3001";
const CLOTHING = process.env.CLOTHING_BASE_URL ?? "http://clothing.decant.localhost:3001";
const API = process.env.API_URL ?? "http://localhost:8010/api";
const CLOTHING_SLUG = process.env.CLOTHING_SLUG ?? "demo-clothing";

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

const rgb = (hex) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
};

/** The decant home's copy and key computed styles, for the before/after comparison. */
async function snapshot(page) {
  return page.evaluate(() => {
    const style = (el) => {
      if (!el) return null;
      const s = getComputedStyle(el);
      return { color: s.color, background: s.backgroundColor, font: s.fontFamily.split(",")[0], radius: s.borderRadius, size: s.fontSize };
    };
    const main = document.querySelector("main");
    const tiles = [...main.querySelectorAll('a[href^="/shop"]')].filter((a) => a.querySelector("span"));
    return {
      text: main.innerText.replace(/\s+/g, " ").trim(),
      page: style(main.parentElement),
      header: style(document.querySelector("header")),
      h1: style(main.querySelector("h1")),
      h2: style(main.querySelector("h2")),
      button: style(main.querySelector('a[href="/shop"]')),
      ghost: style(main.querySelector('a[href="/track"]')),
      tiles: tiles.map(style),
    };
  });
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce" });
const page = await context.newPage();
const consoleErrors = [];
page.on("console", (message) => message.type() === "error" && consoleErrors.push(message.text()));

// ---- decant: nothing published → today's home ----

await page.goto(`${DECANT}/`, { waitUntil: "networkidle" });
const before = await snapshot(page);

if (process.env.SNAPSHOT_OUT) {
  writeFileSync(process.env.SNAPSHOT_OUT, JSON.stringify(before, null, 2));
  console.log(`snapshot written to ${process.env.SNAPSHOT_OUT}`);
  await browser.close();
  process.exit(0);
}

const decantMeta = await fetch(`${API}/v1/decant-please/meta`).then((r) => r.json());
await check("decant /meta serves a design", () => decantMeta.design?.base, (v) => typeof v === "string");

if (decantMeta.design?.preset === "decant.clean") {
  await check("decant hero headline", () => page.locator("main h1").innerText(), (v) => v === "Great perfume, five millilitres at a time.");
  await check("decant hero buttons", () => page.locator(".hero-cta a").allInnerTexts(), (v) => v.join("|").toLowerCase() === "browse the shop|track an order");
  await check("decant How it works: 01–03", () => page.locator("main ol li").allInnerTexts(), (v) => v.length === 3 && v[0].startsWith("01") && v[2].toLowerCase().includes("track"));
  await check("decant three tiles, the middle one dark", () => page.evaluate(() => {
    const tiles = [...document.querySelectorAll("main a.h-44")];
    return tiles.map((t) => `${t.querySelector("span").innerText}:${getComputedStyle(t).backgroundColor}`);
  }), (v) => v.length === 3 && v[1] === `NICHE:${rgb("#013e37")}` && !v[0].includes(rgb("#013e37")));
  await check("decant page background is mist", () => before.page.background, (v) => v === rgb("#f2f8fc"));
  await check("decant button is pine with mist text", () => before.button, (v) => v.background === rgb("#013e37") && v.color === rgb("#f2f8fc"));
  await check("decant font is today's", () => before.h1.font, (v) => v.includes("Helvetica Neue"));
  await check("decant muted text keeps its hand-tuned colour", () => page.locator(".hero-sub").evaluate((el) => getComputedStyle(el).color), (v) => v === rgb("#63707a"));
} else {
  console.log(`SKIP  decant parity: a design (${decantMeta.design?.preset}) is published`);
}

if (process.env.BASELINE) {
  const baseline = JSON.parse(readFileSync(process.env.BASELINE, "utf8"));
  for (const key of Object.keys(baseline)) {
    await check(`parity with before: ${key}`, () => before[key], (v) => JSON.stringify(v) === JSON.stringify(baseline[key]));
  }
}

// ---- clothing: renders its live design ----

const meta = await fetch(`${API}/v1/${CLOTHING_SLUG}/meta`).then((r) => r.json());
const design = meta.design;
const { background, primary, primary_text } = design.theme.colors;
const on = design.sections.filter((s) => s.on);
const hero = on.find((s) => s.type === "hero");

await page.goto(`${CLOTHING}/`, { waitUntil: "networkidle" });
console.log(`      clothing live design: ${design.preset ?? design.base}`);

await check("clothing frame background is the design's", () => page.locator("main").evaluate((el) => getComputedStyle(el.parentElement).backgroundColor), (v) => v === rgb(background));
if (hero) {
  await check("clothing hero title from the design", () => page.locator("main h1").innerText(), (v) => v === hero.props.title);
  await check("clothing Shop button: primary fill, primary_text text", () => page.locator(".hero-cta a").first().evaluate((el) => {
    const s = getComputedStyle(el);
    return `${s.backgroundColor}|${s.color}`;
  }), (v) => v === `${rgb(primary)}|${rgb(primary_text)}`);
}
for (const section of on) {
  const title = section.props?.title;
  // featured / product_grid / delivery_fees hide with no data; category_nav hides until categories exist
  if (!title || ["featured", "product_grid", "delivery_fees", "category_nav", "hero"].includes(section.type)) continue;
  await check(`clothing section "${section.type}" shows its title`, () => page.getByText(title, { exact: true }).first().isVisible(), (v) => v === true);
}
const fees = on.find((s) => s.type === "delivery_fees");
if (fees) {
  const zones = await fetch(`${API}/v1/${CLOTHING_SLUG}/delivery-zones`).then((r) => r.json());
  const priced = (zones.regions ?? []).filter((region) => region.townships.length > 0);
  if (priced.length > 0) {
    await check("clothing delivery fees: one row per region, in Ks", () => page.getByText(fees.props.title, { exact: true }).locator("xpath=following-sibling::ul[1]/li").allInnerTexts(), (v) =>
      v.length === priced.length && v.every((row) => /\d{1,3}(,\d{3})* Ks/.test(row)) && !v.some((row) => /\.\d/.test(row)));
  } else {
    console.log("SKIP  clothing delivery fees: the shop has no priced zones");
  }
}
const announcement = on.find((s) => s.type === "announcement");
if (announcement) {
  await check("clothing announcement bar shows", () => page.getByText(announcement.props.text, { exact: true }).isVisible(), (v) => v === true);
}
await check("clothing: no perfume copy on the home page", () => page.locator("main").innerText(), (v) => !/perfume|decant/i.test(v));

await check("no console errors", () => consoleErrors, (v) => v.length === 0);

await browser.close();
console.log(failures === 0 ? "\nAll design checks passed." : `\n${failures} design check(s) FAILED.`);
process.exit(failures === 0 ? 0 : 1);
