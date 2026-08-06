# Step 25 — Multi-tenancy C: the tenant-facing feature

> **Do not build this until a second client exists.** Steps 23–24 make the system
> multi-tenant; this step makes it *operable* for more than one tenant — and building
> it with zero demand is abstracting for clients we don't have (design-doc §9). This
> spec is deliberately thin: scope and open questions only. Detail written now would
> be stale by the time a second client signs; re-spec against the then-current tree
> before starting.

Follow the current `CLAUDE.md` and `prompts/multi-tenancy-design.md` (ADR-003,
ADR-004, §7, §10, §11). Requires Steps 23 + 24 merged.

## Scope, when the time comes

- **Filament tenancy** (verified available in vendor, v5.6.8 — findings Q3):
  `->tenant(Shop::class)`, `User implements HasTenants`, the `shop_user` pivot
  (studio user belongs to all shops, a client to one), tenant switcher; replaces
  Step 23's interim panel middleware. Mind the vendor's own edges — the tenancy
  middleware `isPersistent: true`, `scopedUnique()` for any future unique rules, and
  the fact that Filament's scope covers only resource-backed models; the app scope
  from Step 23 remains the floor ("Filament does not guarantee multi-tenant
  security" — vendor comment).
- **Shop CRUD** (studio-only) + the onboarding runbook: shop row, admin user, Vercel
  project + domain + `NEXT_PUBLIC_SHOP_SLUG`, `telegram:chat-id` — F6 says minutes,
  not a deployment. **Now also, per the v18–v22 layer:** copy the national delivery
  geography into the new shop (all inactive, fee 0 — the Step 23 seed), then the
  decanter activates + prices their townships and (optionally) sets bottle costs; a shop
  with no active township has nothing to offer at checkout, so zone activation is part
  of go-live, not a later nicety.
- **Per-tenant Telegram** per ADR-003: `telegram_bot_token` (encrypted cast) /
  `telegram_chat_id` on `shops`; `TelegramNotifier` takes a `Shop`; `telegram:test
  --shop=`; free tier = studio bot, paid tier = own bot.
- **Theming** per §10: the four-token allowlist on `shops`, `ThemeProvider`
  (server-rendered `:root` overrides — the motion-token pattern that already exists
  in `globals.css`), WCAG-AA contrast validated **at save time** in Filament, and
  the design-tokens.json generate-or-delete decision executed first.
- **Studio cross-shop views**: the all-shops dashboard, wrapped in `withoutTenancy()`
  with inline justification (it's on the §8 ledger).

## Open questions to settle with the second client, not before

- Wordmark: does a client get a logo *and* a wordmark, or always their name in the
  house typeface? (§10)
- Is `mist` genuinely fixed, or is background the one extra themable token? Holding
  the line keeps the contrast matrix at two pairings. (§10)
- Free-vs-paid tier boundaries: own bot, own domain — priced against the real
  ~$32/mo cost model (ADR-004).
- Does shop #2 share the R2 buckets (prefix isolation, as designed) or warrant its
  own? Revisit against whatever their data-sensitivity expectations are.
