# Step 44 — Self-serve sign-up + automatic `slug.cornerarea.me`

Roadmap: `prompts/43-cornerarea-roadmap.md`, shared foundation ("Self-serve sign-up: phone
verify; shop created as `onboarding`; `slug.cornerarea.me` added automatically; publish
makes it `live`; wildcard domain attached once"). Issue #137. Queue rows 11 (44a) and 11b
(44b).

**Goal.** A seller opens a shop by themselves, from their phone, in minutes (F6): they sign
up, verify their phone, name the shop and pick its category, fill the catalog and payment
settings, and press Publish. The shop answers on `{slug}.cornerarea.me` from the first
minute it is live. Nobody at the studio has to do anything.

What a shop that is already registered sees after this ships: nothing new, except a
Publish button while it is still `onboarding`.

**Split.** The step is too big for one reviewable PR, so it ships in two:

- **44a (row 11)**: the backend foundation. One registration method, reserved slugs,
  the automatic platform address, and the publish rule. The Studio uses them at once.
- **44b (row 11b)**: the seller-facing half. Sign-up pages in the admin panel, phone
  verification, and the Publish button.

---

## 44a — registration foundation

**One registration method.** `App\Support\ShopRegistration::register()` does, in one
place, everything "register a shop" means. The Studio's "Register a shop" action and 44b's
sign-up both call it, so neither re-derives it (P4):

1. the shop row (`onboarding` unless the Studio picks another status);
2. its category (`Templates::assignToShop`);
3. its owner login (optional for the Studio, always for self-serve): a non-studio user
   attached through `shop_user` with the `shop_owner` role;
4. its platform address: a `shop_domains` row `{slug}.{STOREFRONT_BASE_DOMAIN}`, primary
   and verified (the wildcard DNS and the Vercel wildcard make every subdomain reachable,
   so there is nothing left to verify). Skipped when the env value is blank;
5. its national delivery geography (`NationalGeography::seed`), all inactive at fee 0.

1–4 commit in one transaction. The geography seeds after the commit, as it did before
(it is idempotent, so a retry is safe).

**Slug rules** live in the same class and are enforced inside `register()`, not only in a
form, because the slug becomes a public hostname:

- a DNS label: lowercase letters, digits and dashes, 3–40 characters, no leading, trailing
  or double dash (`xn--` punycode can't be forged);
- not reserved: the platform's own hosts and paths (`api`, `images`, `www`, `admin`,
  `studio`, `app`, `mail`, `help`, `status`, `static`, `cdn`, `assets`, `docs`, `blog`,
  `support`, `cornerarea`, `storefront`, `_storefront`, …) can never become a shop's
  subdomain;
- unique across shops. The platform host must also be free in `shop_domains`.

A rejection is a `ValidationException` keyed `slug`, so a Filament form shows it on the
field.

**Publish.** `Shop::publish(User $actor)` is the only way a seller brings their shop live:

- only from `onboarding`. A suspended or archived shop can't publish itself back
  (`activate()` un-suspends; it stays a Studio power);
- only by a member of that shop, or a studio admin.

**Config.** `config('app.storefront_base_domain')` from `STOREFRONT_BASE_DOMAIN`
(`cornerarea.me` in production, `localhost:3001` locally so a shop answers on
`http://{slug}.localhost:3001`). Blank means no automatic address, and the tests set it
per case. It is a platform value, not a shop's config, so it doesn't go through
`ShopConfig`.

**Schema.** None. `shops`, `users`, `shop_user` and `shop_domains` already hold everything.

**Tests** (`ShopRegistrationTest`):

- registering creates the shop, category, owner, platform address and geography;
- a Studio-registered shop gets its `{slug}.cornerarea.me` address; blank env → none;
- reserved and malformed slugs are refused by `register()` itself, and nothing is written;
- a slug already taken, or a platform host already mapped, fails cleanly;
- an onboarding shop's address doesn't resolve on `/_storefront/host`; after publish it
  does;
- publish: onboarding → live by a member; refused for a non-member, for a suspended or an
  archived shop, and for a live shop (nothing to do);
- the Studio form still registers with and without an owner (existing tests).

**Owner steps** (never done by the run):

- DNS: a wildcard `*.cornerarea.me` CNAME to Vercel (Cloudflare, DNS only).
- Vercel: add `*.cornerarea.me` to the one storefront project (wildcards need Vercel's
  nameservers or the DNS-challenge setup; see Vercel's wildcard-domain docs).
- Heroku: `STOREFRONT_BASE_DOMAIN=cornerarea.me`.

**Deliberately not built in 44a.** Renaming a slug doesn't move the platform address
(ADR-0004 left slug mutability open; the Studio's Domains action edits it by hand). No
backfill: existing shops keep their domain rows as they are.

---

## 44b — seller sign-up, phone verification, Publish

**Sign-up lives in the admin panel**, not in a storefront page or a new public JSON API:
the admin is already the seller's phone surface, and it adds no CORS, API contract or
`types.ts` change. Filament's `->registration()` (the account) and
`->tenantRegistration()` (the shop: name, slug, category), both calling 44a's method.

**Phone verification (decision, check it).** The roadmap says "phone verify" and names no
channel. There is no SMS provider today, and wiring one needs an account and a secret,
which only the owner can create. So:

- a small sender interface with a `log` driver (dev and tests) and a real provider driver
  chosen by the owner (an owner step, env only);
- unconfigured means **sign-up is off**, never "verification skipped" (P2 fails closed);
- a 6-digit code, stored hashed, 10-minute expiry, 5 attempts, resend throttled per phone
  and per IP (a platform-level bucket: no shop exists yet, so no shop belongs in the key);
- `users.phone` + `users.phone_verified_at` (platform table, no `BelongsToShop`);
- Publish requires a verified phone.

**Publish button**: on the dashboard while the shop is `onboarding`, calling
`Shop::publish()`; with a checklist that says what a live shop still lacks (no active
delivery township, no product) without blocking on it.

**Language.** The sign-up and Publish screens in English and Burmese (P3). The rest of the
admin stays English-only for now.

**Tests.** Sign-up end to end with the log driver; unconfigured sender → sign-up refused;
wrong / expired / exhausted codes; the new owner is confined to their own shop (404 on
another shop's panel, 403 on `/studio`); publish refused without a verified phone.

**Deliberately not built.** No email verification or password reset (no mail driver,
design-doc §11); no custom domains self-serve (a Studio step, chargeable); no plans or
trial clock (row 16, needs-owner).

### 44b as built (row 11b)

What changed from the plan above, and why:

- **One page, not `->registration()` + `->tenantRegistration()`.** Filament's two-step
  flow creates the user with a plain `User::create` (skipping `register()`) and opens
  `/admin/new` to every owner as a way to add more shops. Instead `App\Filament\Auth\SignUp`
  (a `Register` subclass on `/admin/register`) asks for the account, the phone + code and
  the shop (name, address, category) on one form, and calls `register()` once. A
  signed-up seller gets exactly one shop; more are a Studio step.
- **The code lives in the cache, not a table** (`PhoneVerification`, `phone-otp:{sha1}`):
  hashed, 10 minutes, 5 wrong tries, then ask again. Checked before registering and used
  up only after the account exists, so a slug error doesn't burn the code. Sends: 3 per
  phone per 15 min, 10 per IP per hour, and none to a phone that already has an account.
- **Myanmar mobiles only**, one spelling `+959…` (`09…`, `959…`, `+95 9…` all collapse),
  so one SIM is one key and one account (`users.phone` unique).
- **The sender:** `CodeSender` interface + `LogCodeSender`. `PHONE_VERIFICATION_DRIVER`
  blank → sign-up off (page 404, no link on the login). `log` works only in `local` and
  `testing`, so production stays off until the owner picks a provider — the real driver
  is queue row 11c (needs-owner), because it needs an account and a secret.
- **Login stays email + password** (Filament's). The phone is the spam gate, not the login.
- **Publish needs a verified phone, in `Shop::publish()`** (the one domain method). A
  Studio-registered owner has no phone; the Studio activates their shop. The dashboard
  button shows only to a member with a verified phone, never to a studio admin (the
  Studio has `activate()`); its confirmation lists what the shop still lacks (products,
  an active delivery township) without blocking.
- **Burmese side by side with English** on the sign-up page and the Publish button — no
  locale switch was built.

## Risks

- A slug is public identity once live; the reserved list must cover every platform host.
  Adding a platform subdomain later means adding it to the list first.
- An abandoned `onboarding` shop keeps its slug. Fine at this scale; a cleanup is a later
  Studio action.
- Self-serve opens the platform to spam shops. Phone verification (44b) is the gate, and
  an `onboarding` shop serves nothing publicly.

## P6 answers

1. Every category's seller, on day one: today the Studio registers each shop by hand.
2. A shop already registered sees nothing new (44b adds a Publish button while
   `onboarding`).
3. Core: every shop is registered somehow; this makes it one method.
4. The smallest version: 44a's one method + publish rule; 44b's pages on Filament's own
   registration.
5. Personal data (the owner's phone, 44b), no money, no stock. Tests listed above.
6. Not built: listed per half.
