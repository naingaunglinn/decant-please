// Issue #17 regression check: a fragrance whose image_path outlives its file must
// fall back to the same vial glyph a photo-less fragrance shows, not the browser's
// broken-image icon.
//
//   [BASE_URL=http://localhost:3001] [API_URL=http://localhost:8010/api] node scripts/verify-image-fallback.mjs
//
// Picks its own subjects: asks the API for the catalog, then probes each recorded
// image_url to find one that really loads and one that doesn't. That keeps the check
// honest as the catalog changes, and it reports SKIP rather than passing vacuously
// when the data can't exercise a case. Exits non-zero on any failure.

import { chromium } from "playwright";

const BASE = process.env.BASE_URL ?? "http://localhost:3001";
const API = process.env.API_URL ?? "http://localhost:8010/api";

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

/** One fragrance whose recorded image loads, one whose doesn't — from live data.
 *  Walks every catalog page: 50 is the API's documented per_page ceiling, and the
 *  two subjects can sit anywhere in a catalog that outgrows one page. */
async function pickSubjects() {
  let loads = null;
  let missing = null;

  for (let page = 1, lastPage = 1; page <= lastPage; page++) {
    const response = await fetch(`${API}/v1/fragrances?per_page=50&page=${page}`);
    if (!response.ok) throw new Error(`catalog fetch failed: HTTP ${response.status}`);
    const body = await response.json();
    lastPage = body.meta?.last_page ?? 1;

    for (const fragrance of body.data) {
      if (!fragrance.image_url) continue;
      const probe = await fetch(fragrance.image_url, { method: "HEAD" });
      if (probe.ok) loads ??= fragrance.slug;
      else missing ??= fragrance.slug;
      if (loads && missing) return { loads, missing };
    }
  }
  return { loads, missing };
}

const { loads, missing } = await pickSubjects();
console.log(`subjects: image loads = ${loads ?? "(none found)"} | image missing = ${missing ?? "(none found)"}\n`);

const browser = await chromium.launch();
const page = await browser.newPage();

// The failing case — the whole point of the issue.
if (missing) {
  await page.goto(`${BASE}/fragrance/${missing}`);
  const plate = page.getByRole("main").locator("article").first().locator("div.aspect-square").first();
  await plate.waitFor();

  // onError fires only after the optimizer request fails, so wait for the glyph
  // rather than sampling the DOM before React has swapped it in.
  await check(
    `[${missing}] vial glyph replaces the image that won't load`,
    () => plate.locator("div[aria-hidden]").first().waitFor({ timeout: 15000 }).then(() => true),
    (v) => v === true,
  );
  await check(
    `[${missing}] no <img> left behind to render a broken icon`,
    () => plate.locator("img").count(),
    (v) => v === 0,
  );
} else {
  console.log("SKIP  no fragrance with a missing image file — fallback case not exercised");
}

// The working case — proves the fallback didn't just swallow every image.
if (loads) {
  await page.goto(`${BASE}/fragrance/${loads}`);
  const image = page.getByRole("main").locator("article").first().locator("img").first();
  await image.waitFor();
  await check(
    `[${loads}] a real photo still loads through /_next/image`,
    () => image.evaluate((el) => el.complete && el.naturalWidth > 0),
    (v) => v === true,
  );
} else {
  console.log("SKIP  no fragrance with a working image file — regression case not exercised");
}

await browser.close();
console.log(failures === 0 ? "\nall checks passed" : `\n${failures} check(s) failed`);
process.exit(failures === 0 ? 0 : 1);
