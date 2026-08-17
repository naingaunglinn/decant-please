# Step 25 — Multi-tenancy C: the tenant-facing feature

Follow the current `CLAUDE.md` and `prompts/multi-tenancy-design.md` (ADR-003,
ADR-004, §7, §10, §11) plus `docs/adr/0003-admin-access-model.md` (the access
model). Requires Steps 23 + 24 merged.

**This step split.** The original gate ("do not build until a second client
exists") assumed the whole step was client-facing. The panel half turned out to be
operator-facing — the studio registering and switching shops needs no client — so
it was pulled forward as **25a** and is built (v25). The genuinely client-facing
halves stay gated as **25b/25c**.

## 25a — Filament tenancy + shop management (BUILT, v25)

As built; recorded here so the spec matches the tree:

- **Filament tenancy** (vendor-verified v5.6.8 — findings Q3):
  `->tenant(Shop::class, slugAttribute: 'slug')`; panel routes become
  `/admin/{shop}/…`; the switcher renders from `getTenants()`; `IdentifyTenant`
  enforces `canAccessTenant` and is ordered **before `SubstituteBindings`**
  (bootstrap/app.php) so route-model bindings resolve under the tenant scope.
  The topbar brand is the current shop's name (platform name only on tenant-less
  pages like login). The interim `SetDefaultTenant` panel middleware is deleted.
- **Access model = ADR-0003 Option C, amended as built:** `User implements
  HasTenants` in the composed form (`is_studio || membership`); existing users
  backfilled as studio. The `shop_user` pivot shipped **now but empty** (the ADR
  said on-first-ask; landing the empty table early was judged harmless), and its
  `role` column stays deferred with client logins — the ADR's own cons note
  ("no second real role until a client has staff") won over its action item.
- **The app scope remains the floor.** A `TenantSet` listener
  (`SyncTenantContextFromFilament`) mirrors the resolved tenant into
  `TenantContext` and into `URL::defaults(['tenant' => …])`; Filament's own
  per-resource scope is belt-and-braces on top (ADR-002 unchanged).
- **Shop CRUD lives in its own studio panel** (`/studio`, `StudioPanelProvider`) —
  the super-admin home that is not inside any shop, added after the operator's
  correct objection that `/admin/{shop}/shops` made the admin look owned by a
  shop. No `->tenant()`; entry gated by `User::canAccessPanel` (`is_studio` only)
  with `ShopResource::canAccess` as belt-and-braces; same session guard as the
  tenant panel, cross-linked both ways (user-menu "Studio" item ↔ per-row "Open
  panel"). `ShopResource` (`app/Filament/Studio/Resources`) keeps
  `$isScopedToTenant = false` — Shop is the tenant root. "Register a shop" does
  three things in one flow: the shop row; **the owner's login** (toggle, default
  on — a non-studio user attached via `shop_user`, full control of their shop and
  nothing else; shop + owner commit in one transaction, password hand-set per
  §11); and **the national delivery geography** via `App\Support\NationalGeography`
  (all inactive, fee 0, idempotent — findings A2). A settings row appears lazily
  via `ShopSetting::current()`. Zone activation and pricing are the owner's
  go-live steps, per the runbook. The §8 owner-confinement case is tested (other
  shop's panel URL → 404, `/studio` → 403). The 25b studio features (all-shops
  dashboard, per-tenant Telegram, theming) land in this panel.
- **Invoice/payment-proof routes** register via `authenticatedTenantRoutes()` —
  auth-guarded AND tenant-resolved, so the `{order}` binding is tenant-scoped and
  a cross-shop id 404s (`TenantIsolationTest` pins it, preset-context cleared).

## 25b — deferred until a second client exists

- **Per-tenant Telegram** per ADR-003: `telegram_bot_token` (encrypted cast) /
  `telegram_chat_id` on `shops`; `TelegramNotifier` takes a `Shop`; `telegram:test
  --shop=`; free tier = studio bot, paid tier = own bot.
- **Theming** per §10: the four-token allowlist on `shops`, `ThemeProvider`
  (server-rendered `:root` overrides — the motion-token pattern that already exists
  in `globals.css`), WCAG-AA contrast validated **at save time** in Filament, and
  the design-tokens.json generate-or-delete decision executed first.
- **Studio cross-shop views**: the all-shops dashboard, wrapped in `withoutTenancy()`
  with inline justification (it's on the §8 ledger).
- The per-shop onboarding **runbook**: shop row (25a's screen), admin/owner user,
  Vercel project + domain + `NEXT_PUBLIC_SHOP_SLUG`, `telegram:chat-id`, zone
  activation — F6 says minutes, not a deployment. A shop with no active township
  has nothing to offer at checkout, so zone activation is part of go-live.
- **Client logins beyond registration**: creation-at-registration is built (25a);
  still owed here are attaching/detaching users on an *existing* shop, password
  resets/invites (blocked on a mail driver — §11), and the `role` column, decided
  when a client has staff. The §8 panel-URL-404 confinement case is already in
  the suite.

## 25c — storage prefixes, before shop #2 uploads anything

Deferred from Step 23 as inert-while-single-shop (CHANGELOG v24): `{shop}/`
prefixes at the three write sites (fragrance images, checkout slips, MMQR),
`decant:fresh-start`'s proofs wipe going per-shop, and the one-shot
`decant:migrate-storage-prefixes` command (guarded, `--dry-run` default) for
existing objects. **Must land before a second shop's first upload** — collision is
impossible with one shop and guaranteed embarrassing with two.

## Open questions to settle with the second client, not before

- Wordmark: does a client get a logo *and* a wordmark, or always their name in the
  house typeface? (§10)
- Is `mist` genuinely fixed, or is background the one extra themable token? Holding
  the line keeps the contrast matrix at two pairings. (§10)
- Free-vs-paid tier boundaries: own bot, own domain — priced against the real
  ~$32/mo cost model (ADR-004).
- Does shop #2 share the R2 buckets (prefix isolation, as designed) or warrant its
  own? Revisit against whatever their data-sensitivity expectations are.
