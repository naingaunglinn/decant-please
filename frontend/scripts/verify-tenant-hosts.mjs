// verify-tenant-hosts.mjs — ADR-0004 PR-B: multi-tenant host routing evidence.
//
// Proves, over real HTTP with explicit Host headers, that one storefront
// deployment serves each tenant its own pages — including the exact step-32
// failure class: the SAME pathname under two hosts must never share a rendered
// HTML cache entry (tested in both request orders, twice each).
//
//   BASE_URL=http://localhost:3001 node scripts/verify-tenant-hosts.mjs
//
// Route-cache behavior only exists in a production build — run against
// `next start` for the real cache proof (`next dev` still proves correctness):
//
//   docker compose run --rm frontend sh -c \
//     'npm run build && (npx next start -p 3002 &) && BASE_URL=http://localhost:3002 node scripts/verify-tenant-hosts.mjs'
//
// Fixtures (idempotent; run once, from the repo root):
//
//   docker compose exec backend php artisan tinker --execute="
//     \$ctx = app(App\Support\TenantContext::class);
//     \$b = App\Models\Shop::firstOrCreate(['slug' => 'verify-b'], ['name' => 'Verify B Decants', 'is_active' => true]);
//     \$b->syncDomains([
//       ['host' => 'verify-b.decant.localhost:3001', 'is_primary' => true, 'verified' => true],
//       ['host' => 'www.verify-b.decant.localhost:3001', 'is_primary' => false, 'verified' => true],
//     ]);
//     foreach ([['decant-please', 55000], ['verify-b', 66000]] as [\$slug, \$price]) {
//       \$ctx->set(App\Models\Shop::where('slug', \$slug)->firstOrFail());
//       \$brand = App\Models\Brand::firstOrCreate(['name' => 'Verify Brand'], ['type' => 'niche', 'is_active' => true]);
//       \$frag = App\Models\Fragrance::firstOrCreate(['name' => 'Cache Probe'], ['brand_id' => \$brand->id, 'concentration' => 'edp', 'gender' => 'unisex', 'is_active' => true]);
//       \$frag->decantPrices()->firstOrCreate(['size_ml' => 10], ['price_mmk' => \$price, 'in_stock' => true]);
//     }
//     echo 'fixtures ok';"
//
// The two tenants' display names come from the resolve endpoint itself, so the
// leak markers can never drift from what the pages actually render.

import http from "node:http";
import https from "node:https";

const BASE_URL = process.env.BASE_URL ?? "http://localhost:3001";
const API_URL = process.env.API_URL ?? "http://localhost:8010/api";
const HOST_A = process.env.HOST_A ?? "localhost:3001";
const HOST_B = process.env.HOST_B ?? "verify-b.decant.localhost:3001";
const HOST_B_SECONDARY = process.env.HOST_B_SECONDARY ?? `www.${HOST_B}`;

let failures = 0;
const ok = (label) => console.log(`PASS  ${label}`);
const fail = (label, detail) => {
  failures++;
  console.error(`FAIL  ${label}${detail ? ` — ${detail}` : ""}`);
};

/** Raw request with an explicit Host header; never follows redirects. */
function request(base, path, host) {
  // plain concatenation — new URL(path, base) would drop a base path like /api
  const target = new URL(base + path);
  const lib = target.protocol === "https:" ? https : http;
  return new Promise((resolve, reject) => {
    const req = lib.request(
      {
        hostname: target.hostname,
        port: target.port,
        path: target.pathname + target.search,
        method: "GET",
        headers: host ? { Host: host } : {},
      },
      (res) => {
        let body = "";
        res.setEncoding("utf8");
        res.on("data", (chunk) => (body += chunk));
        res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, body }));
      },
    );
    req.on("error", reject);
    req.end();
  });
}

async function waitForServer() {
  for (let attempt = 0; attempt < 40; attempt++) {
    try {
      await request(BASE_URL, "/", HOST_A);
      return;
    } catch {
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  throw new Error(`No server answering at ${BASE_URL}`);
}

async function resolveHost(host) {
  const res = await request(API_URL, `/v1/_storefront/host/${encodeURIComponent(host)}`);
  return res.status === 200 ? JSON.parse(res.body).data : null;
}

function assertTenantPage(label, res, mustContain, mustNotContain) {
  if (res.status !== 200) return fail(label, `HTTP ${res.status}`);
  if (!res.body.includes(mustContain)) return fail(label, `missing "${mustContain}"`);
  if (res.body.includes(mustNotContain)) return fail(label, `LEAK — contains "${mustNotContain}"`);
  ok(label);
}

await waitForServer();

// ---- preconditions: both tenants + the secondary must resolve -----------------
const tenantA = await resolveHost(HOST_A);
const tenantB = await resolveHost(HOST_B);
const secondary = await resolveHost(HOST_B_SECONDARY);
if (!tenantA || !tenantB || !secondary) {
  console.error(
    `Fixtures missing (A=${!!tenantA} B=${!!tenantB} secondary=${!!secondary}) — ` +
      "run the tinker snippet in this script's header, with the stack up.",
  );
  process.exit(2);
}
const NAME_A = tenantA.name;
const NAME_B = tenantB.name;
console.log(`tenants: "${NAME_A}" @ ${HOST_A} · "${NAME_B}" @ ${HOST_B} (+ ${HOST_B_SECONDARY})`);

// The step-32 case needs one slug that exists in BOTH catalogs — discover it
// from the API rather than assuming what HasSlug generated for the fixture.
async function catalogSlugs(shop) {
  const res = await request(API_URL, `/v1/${shop}/fragrances?per_page=50`); // 50 is the API's cap
  return res.status === 200 ? JSON.parse(res.body).data.map((f) => f.slug) : [];
}
const slugsA = await catalogSlugs(tenantA.slug);
const slugsB = await catalogSlugs(tenantB.slug);
const sharedSlug = slugsA.find((slug) => slugsB.includes(slug));
if (!sharedSlug) {
  console.error("No fragrance slug shared by both tenants — run the fixture snippet in this script's header.");
  process.exit(2);
}
const PROBE_PATH = `/fragrance/${sharedSlug}`;
console.log(`probe path: ${PROBE_PATH}`);

// ---- 1+2: each host serves its own storefront --------------------------------
assertTenantPage(`A home is ${NAME_A}`, await request(BASE_URL, "/", HOST_A), NAME_A, NAME_B);
assertTenantPage(`B home is ${NAME_B}`, await request(BASE_URL, "/", HOST_B), NAME_B, NAME_A);

// ---- 3+4: THE step-32 case — same pathname, two hosts, both orders, repeated --
const cacheStates = [];
for (const [host, own, other] of [
  [HOST_A, NAME_A, NAME_B], // A → B → A → B: covers A-then-B and B-then-A,
  [HOST_B, NAME_B, NAME_A], // and each host again after the other was cached
  [HOST_A, NAME_A, NAME_B],
  [HOST_B, NAME_B, NAME_A],
]) {
  const res = await request(BASE_URL, PROBE_PATH, host);
  assertTenantPage(`${PROBE_PATH} on ${host} is ${own}`, res, own, other);
  cacheStates.push(`${host}:${res.headers["x-nextjs-cache"] ?? "-"}`);
}
console.log(`      route-cache states: ${cacheStates.join("  ")}`);
{
  // In a production build the repeat hits should come FROM the cache — proving
  // the isolation above held even on cached responses. `next dev` has no route
  // cache; note it rather than fail, so the dev smoke run stays useful.
  const [, , thirdA, fourthB] = cacheStates.map((s) => s.split(":").pop());
  if (thirdA === "HIT" && fourthB === "HIT") ok("repeat requests served from the route cache, per-tenant");
  else console.log("      (no route-cache HIT observed — expected under `next dev`; run the `next start` form for the cache proof)");
}

// ---- 5: unknown host fails closed --------------------------------------------
{
  const res = await request(BASE_URL, "/", "unknown-shop.example");
  if (res.status !== 404) fail("unknown host → 404", `HTTP ${res.status}`);
  else if (res.body.includes(NAME_A) || res.body.includes(NAME_B))
    fail("unknown host → 404", "404 body leaks a tenant name");
  else ok("unknown host → 404, unbranded");
}

// ---- 6+7: EVERY public route 308s a secondary domain to the primary, query
// preserved, no loop on the primary. One route per row — a single-route check is
// what let /shop (a loading.tsx Suspense boundary swallowed its redirect into a
// meta-refresh) escape the first pass.
for (const path of [
  "/",
  "/shop",
  "/shop?brand_type=niche&sort=price_asc",
  PROBE_PATH, // /fragrance/{shared slug}
  "/checkout",
  "/track?code=PROBE12345",
  "/order/complete?code=PROBE12345",
]) {
  const res = await request(BASE_URL, path, HOST_B_SECONDARY);
  const expected = `http://${tenantB.primary_host}${path}`;
  if (res.status !== 308) fail(`secondary ${path} → 308`, `HTTP ${res.status}, expected 308`);
  else if (res.headers.location !== expected)
    fail(`secondary ${path} → 308`, `Location ${res.headers.location}, expected ${expected}`);
  else ok(`secondary ${path} → 308 → verified primary (path + query preserved)`);

  // the primary host must NOT redirect — proves the target is a dead end, no loop
  const primary = await request(BASE_URL, path, HOST_B);
  if (primary.status >= 300 && primary.status < 400)
    fail(`primary ${path} no loop`, `HTTP ${primary.status} — primary should not redirect`);
  else ok(`primary ${path} serves ${primary.status} — no redirect loop`);
}

// ---- 8: per-tenant robots.txt -------------------------------------------------
for (const [host, origin, otherOrigin] of [
  // compare scheme-prefixed ORIGINS, not bare hosts — HOST_A ("localhost:3001")
  // is a literal substring of HOST_B ("verify-b.decant.localhost:3001")
  [HOST_A, `http://${HOST_A}`, `http://${HOST_B}`],
  [HOST_B, `http://${HOST_B}`, `http://${HOST_A}`],
]) {
  const res = await request(BASE_URL, "/robots.txt", host);
  const label = `robots.txt on ${host} references its own sitemap`;
  if (res.status !== 200) fail(label, `HTTP ${res.status}`);
  else if (!res.body.includes(`Sitemap: ${origin}/sitemap.xml`)) fail(label, "wrong sitemap host");
  else if (res.body.includes(otherOrigin)) fail(label, "references the other tenant's origin");
  else ok(label);
}

// ---- 9: per-tenant sitemap.xml ------------------------------------------------
for (const [host, tenant, otherOrigin] of [
  [HOST_A, tenantA, `http://${HOST_B}`],
  [HOST_B, tenantB, `http://${HOST_A}`],
]) {
  const res = await request(BASE_URL, "/sitemap.xml", host);
  const origin = `http://${tenant.primary_host}`;
  const label = `sitemap.xml on ${host} lists only its own URLs`;
  if (res.status !== 200) fail(label, `HTTP ${res.status}`);
  else if (!res.body.includes(`<loc>${origin}${PROBE_PATH}</loc>`)) fail(label, "missing own probe URL");
  else if (res.body.includes(otherOrigin)) fail(label, "LEAK — contains the other tenant's origin");
  else ok(label);
}

console.log(failures === 0 ? "\nall tenant-host checks passed" : `\n${failures} check(s) failed`);
process.exit(failures === 0 ? 0 : 1);
