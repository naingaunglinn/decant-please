// Verifies the home page never mounts two <ViewTransition> cards with the same
// name (issue #9): a fragrance that is both featured and recently viewed must
// render only in the featured rail, while the recently-viewed rail keeps
// showing non-featured items. Run against a DEV server — the duplicate-name
// check relies on React's development-only console error.
//
//   [BASE_URL=http://localhost:3001] node scripts/verify-home-rails.mjs
//
// Exits non-zero on failure; exits 2 if the catalog lacks the data it needs
// (at least two featured + one non-featured active fragrance).

import { chromium } from "playwright";

const BASE = process.env.BASE_URL ?? "http://localhost:3001";
const STORAGE_KEY = "decant-please.recently-viewed.v1";

let failed = false;
function check(name, ok, detail = "") {
  console.log(`${ok ? "PASS" : "FAIL"}  ${name}${ok || !detail ? "" : ` — ${detail}`}`);
  if (!ok) failed = true;
}

const slugOf = (href) => href.split("/").pop();

const browser = await chromium.launch();
const page = await browser.newPage(); // fresh context — empty localStorage

const consoleErrors = [];
page.on("console", (msg) => {
  if (msg.type() === "error") consoleErrors.push(msg.text());
});

// 1. Scrape the featured rail (server-rendered; the rail slices to 8, same as the fetch).
await page.goto(`${BASE}/`);
const featuredSection = page.locator("section", {
  has: page.getByRole("heading", { name: "Featured", exact: true }),
});
await featuredSection.waitFor();
const featuredSlugs = (
  await featuredSection.locator('a[href^="/fragrance/"]').evaluateAll((as) => as.map((a) => a.getAttribute("href")))
).map(slugOf);

// featured[0] is also the hero visual (a second, transition-less link), so test with featured[1].
if (featuredSlugs.length < 2) {
  console.log(`SKIP  need at least 2 featured fragrances, found ${featuredSlugs.length}`);
  await browser.close();
  process.exit(2);
}
const target = featuredSlugs[1];

// 2. Find a non-featured fragrance on /shop — proves the rail itself still works.
await page.goto(`${BASE}/shop`);
await page.locator('main a[href^="/fragrance/"]').first().waitFor(); // grid streams in behind loading.tsx
const shopSlugs = (
  await page.locator('main a[href^="/fragrance/"]').evaluateAll((as) => as.map((a) => a.getAttribute("href")))
).map(slugOf);
const nonFeatured = shopSlugs.find((s) => !featuredSlugs.includes(s));
if (!nonFeatured) {
  console.log("SKIP  every fragrance on /shop page 1 is featured — nothing to test the rail with");
  await browser.close();
  process.exit(2);
}

// 3. Visit both detail pages so RecordRecentlyViewed stores them.
for (const slug of [target, nonFeatured]) {
  await page.goto(`${BASE}/fragrance/${slug}`);
  await page.waitForFunction(
    ([key, s]) => (window.localStorage.getItem(key) ?? "").includes(s),
    [STORAGE_KEY, slug],
  );
}

// 4. Back home: the recently-viewed rail mounts alongside the featured rail.
consoleErrors.length = 0;
await page.goto(`${BASE}/`);
const rvSection = page.locator("section", {
  has: page.getByRole("heading", { name: "Recently viewed", exact: true }),
});
await rvSection.waitFor(); // appears only once the rail has cards
await page.waitForTimeout(600); // let React finish committing before reading console output

const dupErrors = consoleErrors.filter((t) => t.includes("ViewTransition"));
check("no duplicate-ViewTransition console errors", dupErrors.length === 0, dupErrors[0]);

check(
  `featured+recently-viewed fragrance "${target}" renders exactly once`,
  (await page.locator(`a[href="/fragrance/${target}"]`).count()) === 1,
  `count=${await page.locator(`a[href="/fragrance/${target}"]`).count()}`,
);
check(
  "recently-viewed rail does not repeat the featured card",
  (await rvSection.locator(`a[href="/fragrance/${target}"]`).count()) === 0,
);
check(
  `rail still shows non-featured "${nonFeatured}"`,
  (await rvSection.locator(`a[href="/fragrance/${nonFeatured}"]`).count()) === 1,
);

await browser.close();
if (failed) {
  console.log("\nresult: FAILED");
  process.exit(1);
}
console.log("\nresult: all checks passed");
