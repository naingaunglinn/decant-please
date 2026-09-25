# Step 45 — Help button: admin → studio on Telegram / Viber

Roadmap: `prompts/43-cornerarea-roadmap.md`, shared foundation ("Help button: admin →
studio on Viber/Telegram, in Burmese"). Issue #140. Queue row 12.

**Goal.** A seller who is stuck — can't find a setting, can't log in, a slip looks wrong —
reaches the studio in one tap from the admin, on the chat app they already use. Today they
have to know the studio's number from somewhere else. Self-serve sign-up (step 44) means
new sellers no longer meet the studio before they start, so the admin has to carry the way
to reach us.

What a shop sees after this ships: one **Help · အကူအညီ** button in the top bar. Nothing
else changes. While the studio hasn't set its handles, nobody sees anything.

---

## Design

**Deep links, not a chat feature.** The button opens the seller's own Telegram or Viber
on a chat with the studio. The seller writes and sends the message; the platform sends
nothing, stores nothing and receives nothing. That's the same line `PRODUCT.md` § Non-goals
draws for the customer's booking deep link.

**Where it shows:**

- the top bar of every `/admin/{shop}` page (the `USER_MENU_BEFORE` render hook), for shop
  members. Studio admins don't see it, because the studio doesn't message itself;
- under the login form and the sign-up form. A seller who can't log in has no other way to
  reach us (there is no password reset: no mail driver, design-doc §11), and sign-up is
  where a new seller gets stuck on the phone code.

The `/studio` panel doesn't get the button.

**The dropdown** lists the channels that are set up (Telegram, Viber). Inside a shop it
also shows the shop's name and slug, so the seller can tell us which shop they mean.
The link pre-fills a first message that names the shop (Telegram `?text=`, Viber
`&draft=`). A client that ignores the pre-fill still opens the right chat.

**Config — a platform value, not a shop's.** The studio's contact is the same for every
shop, so it is env, not `ShopConfig`, like `storefront_base_domain`:

```
SUPPORT_TELEGRAM_USERNAME=cornerarea_help   # t.me/<username>; leading @ tolerated
SUPPORT_VIBER_NUMBER=09xxxxxxxxx            # a Myanmar mobile, any spelling
```

`config('services.support.telegram_username')` and
`config('services.support.viber_number')`. This is a new block, not
`services.telegram.*`: that block is a shop's order-alert bot token and chat id, not the
studio's public handle.

**Fails quiet (P2: a side channel never breaks the core path).** A blank or malformed
value hides that channel. With both hidden, the button isn't rendered at all. A bad env
value never becomes a 500 on an admin page.

- Telegram username: 5–32 letters, digits or underscores, starting with a letter
  (Telegram's rule), leading `@` stripped. Link: `https://t.me/{username}?text=…`.
- Viber number: normalized by `PhoneVerification::normalize()` (one Myanmar-mobile rule in
  one place, P4). Link: `viber://chat?number=%2B959…&draft=…`. It opens in the same tab,
  because a custom scheme opened in a new tab leaves an empty tab on a phone.

**One class.** `App\Support\StudioHelp::links(?Shop $shop)` builds the list. The panel
hook and the auth hooks only render it.

## Schema

None.

## API

None. The storefront and `types.ts` are unchanged.

## Admin

- `AdminPanelProvider`: three render hooks (`USER_MENU_BEFORE`, `AUTH_LOGIN_FORM_AFTER`,
  `AUTH_REGISTER_FORM_AFTER`) rendering `filament/help-button.blade.php` and
  `filament/help-links.blade.php`.
- Labels in English and Burmese side by side, as on the sign-up page (44b).

## Tests (`HelpButtonTest`)

- Nothing configured: the dashboard renders with no help markup.
- Each channel on its own: only that link renders. Both: both render.
- A malformed username or number hides that channel. With both malformed, there's no button.
- The link names the current shop (URL-encoded, including a Burmese shop name). Shop B's
  panel never carries shop A's name or slug.
- A studio admin sees no button.
- The login and sign-up pages show the links without a shop and still return 200.

## Risks

- A deep link needs the app installed. On a desktop without Telegram or Viber, the link
  opens the web page (`t.me`) or nothing (`viber://`). The seller is phone-first (P1), so
  that's acceptable.
- Pre-fill support differs between clients. The dropdown shows the shop's name and
  slug, so the seller can say which shop even when the pre-fill doesn't appear.

## Deliberately not built

- No in-app chat, ticket list or support inbox. The studio answers in its own Telegram or
  Viber.
- No per-shop help contact. A shop's own staff isn't a help desk; the studio is.
- No studio-editable handles in `/studio`. Configuration stays in code/env until the
  studio actually changes its number (P4).
- No Facebook Messenger or TikTok. The roadmap names Viber and Telegram.
- No logging of help taps. The platform never sees the conversation.

## P6 answers

1. Every seller, in every category, once self-serve sign-up opens. They're stuck in the
   admin, on their phone, and don't know how to reach the studio.
2. With the env blank, nothing new. With it set, one Help button in the top bar.
3. Core: every seller can get stuck. There's no module toggle; the platform turns it on
   and off with env.
4. Two deep links from env, rendered by one class and two small views.
5. No money, no stock. The studio's own public handle is the only data, and the
   platform sends and stores nothing. Tenancy: the link names only the current shop,
   which is tested.
6. Not built: listed above.
