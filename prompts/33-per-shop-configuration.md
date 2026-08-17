# Step 33 — Per-shop configuration

> Prerequisite reading: `CLAUDE.md`, `prompts/WORKFLOW.md`, `backend/README.md`
> (the env-var table), and `docs/adr/0004-storefront-addressing.md` for the CORS part.

## Issue draft

**Title:** Per-shop configuration — Telegram, payment settings, social links, storefront origin

**Body:**
Four pieces of configuration are still process-global, which was correct when there was
one decanter and is now wrong:

| Config | Today | Symptom with two shops |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_ADMIN_CHAT_ID` | env | Every shop's order alerts buzz one phone — or, if blank, nobody's |
| MMQR / KBZPay / Wave / instructions | single-row `ShopSetting` (v14) + `PAYMENT_*` env fallback | Shop B's checkout shows shop A's transfer number. **Money moves to the wrong person.** |
| `SOCIAL_TIKTOK_URL` / `SOCIAL_FACEBOOK_URL` | env | Every storefront footer links to one decanter's TikTok |
| `FRONTEND_URL` | env — CORS allowlist *and* admin "View on site" | One storefront origin for all shops |

The payment row is the reason this is P0 and not a cleanup.

---

## Shape

**Resolution order, everywhere, no exceptions:** shop row → env → off.

The env fallback is not legacy debt to remove later; it's the platform default, and it's
the v14 precedent (`/meta` already falls back to `PAYMENT_*` so existing deployments keep
working). A blank at both levels means the feature is off for that shop — the v11 rule
that an unconfigured notifier is a silent no-op stays exactly as it is.

Put the resolution in **one** place — e.g. `Shop::config(string $key)` or a small
`ShopConfig` resolver — not scattered `?? config(...)` calls. One site, so step 34's
"is this shop configured?" panel reads the same truth the API does.

## Work

### 1. `shop_settings` becomes per-shop

- Migration: `shop_id` on `shop_settings`, unique. Backfill the existing single row to
  the first shop (there is exactly one real shop today; assert that in the migration and
  fail loudly rather than guessing if there are more).
- `Shop hasOne ShopSetting`; `ManagePayment` edits the *tenant's* row, resolved from
  the panel tenant, never `first()`.
- MMQR images move to the shop's media prefix (step 32's decision).

### 2. Telegram per shop

- `bot_token` + `admin_chat_id` on the shop settings row. One bot per shop is the simple
  case; a single platform bot with per-shop chat ids is also valid and cheaper for the
  decanters — support both by treating a blank token as "use the platform token".
- `NotifyAdminOfNewOrder` and `NotifyAdminOfPaymentProof` resolve the notifier from
  `$event->order->shop`, not from config. Listeners stay explicitly wired in
  `AppServiceProvider` — event discovery is off and stays off (#52).
- `telegram:test {shop}` — takes a shop argument, refuses without one.
- The 5s bound and swallow-every-error rules are untouched. A shop with a broken token
  must not affect another shop's checkout, which it now can't, because the send is
  per-order.

### 3. Social links per shop

Straight move to the settings row; `/api/v1/{shop}/meta` reads them; blank stays hidden.

### 4. Storefront origin per shop

Depends on ADR-0004:

- **If path-based (option A):** nothing changes but "View on site", which appends the
  shop segment. `config/cors.php` stays a single origin.
- **If subdomain or custom domain (B/C):** CORS becomes a per-request check of the
  `Origin` header against the shops/domains table, not a static list. Cache that lookup —
  it runs on every preflight. "View on site" reads the shop's primary domain.

Do **not** implement the domain table in this step if ADR-0004 is still Proposed. Add the
column the ADR calls for, leave the resolver reading one origin, and note it.

### 5. Cache

Every `/meta` and catalog cache key carries the shop (step 32 covers the general rule).
Saving payment settings busts **that shop's** meta key only.

## Tests

- Two shops with different payment settings: `/api/v1/{A}/meta` and `/{B}/meta` each
  return their own block; a shop with no row falls back to env; a shop with neither
  returns null for the whole block (the v14 rule — and the storefront then offers COD
  only, which is worth asserting end-to-end since it gates the online option).
- Two shops with different chat ids: an order in each sends exactly one message, to the
  right chat. `Http::assertSentCount` — `assertSent` alone passes on a double-send, which
  is how #52 shipped.
- Unconfigured shop: order placement succeeds, zero sends, no exception.
- `phpunit.xml` already pins blank Telegram env (the v17 fix); keep that and add the
  per-shop rows in the test itself so a developer's real `.env` still can't leak in.

## Non-goals

No new settings UI beyond pointing the existing `ManagePayment` page at the tenant row —
the Studio-side "what's configured / what's missing" view is step 34. No notification
channels beyond Telegram. No gateway (§8 holds).

## Docs, same branch

`backend/README.md`'s env table gains a "per-shop override" column, and the routes table
notes that `/meta` is shop-resolved. `DEPLOY.md` needs a line saying the `PAYMENT_*` and
`TELEGRAM_*` config vars are now platform defaults, not the live values.

## Accepted limits to record

- Existing MMQR images and proofs are not re-pathed into shop prefixes.
- A shop that shares the platform bot token shares its rate limit.
