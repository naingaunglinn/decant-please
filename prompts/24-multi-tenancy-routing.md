# Step 24 — Multi-tenancy B: path-prefix routing

Follow the current `CLAUDE.md` and `prompts/multi-tenancy-design.md` (ADR-001, §9
Step B). **Backend + storefront.** Requires Step 23 merged. This is the one-time
breaking change to the public URL shape — cheapest now, while our own storefront is
the only consumer.

## 0. Why this step exists

Step 23 resolves the tenant implicitly (a middleware pinning the sole shop). ADR-001
chose the explicit form: the tenant in the path, visible in every log line, every
stack trace, and every `curl` — the same greppability call as `Event::listen` over
auto-discovery. This step makes the URL the tenant resolver and deletes the interim
middleware.

## 1. Backend

- Route group becomes `Route::prefix('v1/{shop}')` around **all nine public
  endpoints** (`routes/api.php`) — catalog reads, checkout, track, cancel,
  payment-proof, validate-promo, and **`delivery-zones`** (v21's addition — the audit
  and this step's earlier draft said "eight"; the delivery-zones read must be prefixed
  too, or the storefront's township selects break under a tenant). `/up` stays unprefixed
  (Heroku health check).
- `ResolveTenant` middleware on the group: looks up the slug, **404s on unknown or
  inactive shops** (same generic-404 discipline as tracking — no shop-enumeration
  oracle beyond what the storefront URL already reveals), binds the `Shop` into
  `TenantContext`. Replaces and deletes Step 23's `SetDefaultTenant` on the API side
  (the panel keeps its interim middleware until Step 25).
- Response shapes are **byte-identical** — only the path changes (ADR-001 action 3).
  Throttle buckets unchanged (they're per-IP, name-keyed).
- `SHOP_SLUG` stays: seeding and the panel's interim context still use it.

## 2. Storefront

- `lib/api.ts:14` — fold `NEXT_PUBLIC_SHOP_SLUG` into `BASE`:
  `${API_URL}/v1/${SHOP_SLUG}`. That single constant is the whole client change;
  every helper already routes through it. Fail the build loudly if the var is unset
  (a storefront pointed at no shop is a misconfiguration, not a fallback case —
  same rule as design-doc §10's theming states).
- Env plumbing: `.env.example`, compose, Vercel project settings, `DEPLOY.md`.
- `FRONTEND_URL`/CORS: generalise the allowlist to accept a comma-separated list of
  origins (still one value today) — shop #2's domain must not require a config-shape
  change later.

## 3. Contract + docs

`backend/docs/api.md` is the written contract (v5 rule — a future Flutter client
builds from it): every path gains `{shop}`, with one paragraph on resolution and the
404 behaviour. Update `DEPLOY.md`'s Vercel section with the env var — and record the
**Production Branch** while in that dashboard (ADR-004: believed `main`, still
unverified; this step's promotion is the natural moment to finally read it).

## 4. Rollout — one lockstep promotion

Old unprefixed URLs 404 after this deploys; there is no grace shim (sole consumer is
our own storefront). Backend and frontend therefore promote **together**: set
`NEXT_PUBLIC_SHOP_SLUG` in Vercel first (it's inert until the code arrives), then one
develop→main promotion carrying both halves. State this in the PR body so the human
promoting knows it's a lockstep release.

## 5. Tests

- `ResolveTenant`: known slug resolves; unknown slug 404; **inactive shop 404**;
  context is bound before controllers run.
- Rewrite Step 23's context-set API tests against real `/{shop}/…` paths — the §8
  cross-shop rows finally test the true surface: A's tracking code + phone under B's
  path → generic 404; A's promo under B's checkout → invalid; two-shop catalog counts
  per path.
- Meta/receipt shape-parity: same JSON as before the prefix (pin a snapshot-ish
  assertion so the Flutter contract holds).
- Frontend: build fails without `NEXT_PUBLIC_SHOP_SLUG`; `BASE` composes correctly.

## 6. Not in scope

Filament tenancy and the `/admin/{tenant}` URL space, shop CRUD, per-tenant Telegram,
theming, any second shop (Step 25); subdomain or Origin-based resolution (rejected in
ADR-001).
