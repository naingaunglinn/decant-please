// Verifies the printed receipt is a static document, not the live view
// (corrected step-8 §3). Drives the real app in headless Chromium:
// order-complete phone-confirm fallback → live receipt → print emulation.
//
//   CODE=ABC123XYZ0 PHONE=09-... [BASE_URL=http://localhost:3000] node scripts/verify-print.mjs
//
// Writes screen/print screenshots + the actual Save-as-PDF output to OUT_DIR
// (default: <os tmpdir>/decant-print-check) and exits non-zero on any failure.

import { mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { chromium } from "playwright";

const BASE = process.env.BASE_URL ?? "http://localhost:3000";
const CODE = process.env.CODE;
const PHONE = process.env.PHONE;
const OUT = process.env.OUT_DIR ?? join(tmpdir(), "decant-print-check");

if (!CODE || !PHONE) {
  console.error("Usage: CODE=... PHONE=... [BASE_URL=...] node scripts/verify-print.mjs");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 800, height: 1400 } })).newPage();

const results = [];
const check = async (name, expected) => {
  const ok = await expected();
  results.push(ok);
  console.log(`${ok ? "PASS" : "FAIL"}  ${name}`);
};
const hidden = (locator) => async () => !(await locator.isVisible());

// fresh browser context = no sessionStorage — the private-window fallback path
await page.goto(`${BASE}/order/complete?code=${encodeURIComponent(CODE)}`);
await page.getByText("Confirm your phone number").waitFor();
console.log("fallback: phone-confirmation form shown (no sessionStorage)");
await page.getByLabel(/phone number you ordered with/i).fill(PHONE);
await page.getByRole("button", { name: /track this order/i }).click();
await page.getByRole("heading", { name: /^order #/i }).waitFor();
console.log("fallback: full receipt rendered after phone confirm");
await page.screenshot({ path: join(OUT, "complete-screen.png"), fullPage: true });

// what the printer sees
await page.emulateMedia({ media: "print" });
await check("print: RECEIPT heading visible", () =>
  page.getByRole("heading", { name: "Receipt", exact: true }).isVisible());
await check("print: letterhead visible", () =>
  page.getByRole("main").getByText("Decant Please!", { exact: true }).isVisible());
await check("print: one static status line", () =>
  page.getByText("Status at time of printing:").isVisible());
await check("print: timeless payment note", () =>
  page.getByText(/No online payment is taken/).isVisible());
await check("print: 'Order placed' heading gone", hidden(
  page.getByRole("heading", { name: "Order placed" })));
await check("print: interactive timeline gone", hidden(
  page.getByText("Submitted", { exact: true })));
await check("print: 'We'll confirm shortly' gone", hidden(
  page.getByText(/confirm shortly/)));
await check("print: cancel button gone", hidden(
  page.getByRole("button", { name: /cancel this order/i })));
await check("print: print button gone", hidden(
  page.getByRole("button", { name: /print \/ save as pdf/i })));
await check("print: site nav/footer gone", hidden(page.locator("header")));
const placedText = await page.getByText(/^Placed /).innerText(); // e.g. "Placed 14 Jul 2026"
const todayText = new Date().toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
const placedToday = placedText === `Placed ${todayText}`;
await check("print: 'Printed on' shown only when it differs from the placed date", async () =>
  ((await page.getByText(/^Printed on/).count()) > 0) === !placedToday);
await page.screenshot({ path: join(OUT, "complete-print.png"), fullPage: true });
await page.pdf({ path: join(OUT, "receipt.pdf"), format: "A4" }); // the real Save-as-PDF output

// back on screen, the live view is what renders — not the document
await page.emulateMedia({ media: "screen" });
await check("screen: live receipt visible", () =>
  page.getByRole("heading", { name: /^order #/i }).isVisible());
await check("screen: RECEIPT document hidden", hidden(
  page.getByRole("heading", { name: "Receipt", exact: true })));

// same guarantees on /track
await page.goto(`${BASE}/track`);
await page.getByLabel(/tracking code/i).fill(CODE);
await page.getByLabel(/phone number you ordered with/i).fill(PHONE);
await page.getByRole("button", { name: /track this order/i }).click();
await page.getByRole("heading", { name: /^order #/i }).waitFor();
await page.emulateMedia({ media: "print" });
await check("track print: RECEIPT heading visible", () =>
  page.getByRole("heading", { name: "Receipt", exact: true }).isVisible());
await check("track print: page heading gone", hidden(
  page.getByRole("heading", { name: "Track your order" })));
await check("track print: timeline gone", hidden(
  page.getByText("Submitted", { exact: true })));
await page.screenshot({ path: join(OUT, "track-print.png"), fullPage: true });

await browser.close();
console.log(`\nscreenshots + PDF in ${OUT}`);
if (results.includes(false)) process.exit(1);
console.log("all checks passed");
