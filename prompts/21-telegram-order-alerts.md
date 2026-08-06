# Step 21 — Telegram order alerts (admin)

Follow the current `CLAUDE.md`. **Backend only.**

## 0. Why this step exists

Selling to Myanmar decant businesses, the highest-value/lowest-cost win is: the
decanter's phone buzzes the instant a website order comes in, instead of them
refreshing the admin. Telegram is free and needs no gateway. This is Half 1 of the
notifications idea — **admin alerts**.

## 1. The Telegram constraint that shapes the scope

A bot can only message a chat that has pressed **Start** on it; it cannot message
someone by phone number. So:
- **Admin alerts** are trivial — one person (the decanter) presses Start once, we
  store their chat id, the bot pushes forever.
- **Customer alerts** are deliberately **out of scope** — they'd require a per-
  customer opt-in tap, or a paid channel (SMS/Viber). The tracking page stays the
  customer's channel.

## 2. Design — an event layer, not hardcoded sends

`OrderPlaced` event (holds the `Order`) → dispatched from `OrderController::store`
**after** `newFromCheckout` commits (website path only; the honeypot returns before
any order exists, so it never dispatches). A `NotifyAdminOfNewOrder` listener builds
the message and hands it to a `TelegramNotifier` service. Registered explicitly in
`AppServiceProvider` (`Event::listen`) so the wiring is greppable. *(Amended by #52:
Laravel's event auto-discovery also scans `app/Listeners/`, so the explicit wiring
registered the listener twice and every order alerted the admin twice. Discovery is
now disabled — `->withEvents(discover: false)` in `bootstrap/app.php`; note `false`,
not `[]`, which falls back to the default scan path. Consequence: every future
listener must be wired explicitly in `AppServiceProvider`, or it won't run.)*

*(Amended by #59.)* A second event rides the same layer: `PaymentProofUploaded`
(the `Order` + an `isReplacement` flag) → dispatched from `PaymentProofController`
only, after the proof write commits — deliberately **not** from checkout, whose slip
is already reported inside the new-order alert; dispatching from both paths would
double-send the same slip. Its listener, `NotifyAdminOfPaymentProof`, is wired in
`AppServiceProvider` next to the first — mandatory, not stylistic, given discovery
is off.

Adding SMS/Viber later is another listener on the same event — no checkout changes.
This matches the v5 rule: the trigger lives in the API, reusable by a future client.

## 3. `TelegramNotifier` (App\Support)

One `Http::post` to `https://api.telegram.org/bot{token}/sendMessage`, plain text
(no parse_mode — Burmese and Markdown/HTML specials would otherwise need escaping).
Two hard rules, because it runs in the checkout request path:
- **Never throws** — 5s timeout, catch-all to a log, returns bool. A Telegram outage
  must not fail a customer's order.
- **No-op when unconfigured** — blank token/chat id → logs and returns false, so the
  feature is simply off until a decanter sets both.

Config: `config/services.php` `telegram` block ← `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_ADMIN_CHAT_ID`.

## 4. The messages

**New order** — plain text: `🆕 New order #{id}` / `{customer} · {phone}` / items
summary (`{size}ml × {qty} {name}`) / `Total: {kyat}` / `Payment: {method}` (#59) /
`Track: {code}` / the admin order URL (`{APP_URL}/admin/orders/{id}/edit`). For
non-COD the payment line says whether the slip is ` — slip attached` or
` — slip awaited` (checkout attaches the slip before dispatching, so "awaited" is
defensive — the listener doesn't assume its dispatcher). Never `payment_status`:
every order is `unpaid` at OrderPlaced, so it carries no signal.

**Payment slip** (#59) — `🧾 Payment slip uploaded — order #{id}`, or `… slip
replaced …` on a re-upload (the endpoint overwrites, so a customer retrying a blurry
photo doesn't buzz the decanter identically several times) / `{customer} · {phone}` /
`Total: {kyat} · Balance due: {kyat}` / `Track: {code}` / the admin order URL.
**Link only, never the image**: proofs live on the private bucket by design (#47),
and a multi-MB `sendPhoto` upload doesn't fit the notifier's 5s bound.

## 5. Onboarding aid

`php artisan telegram:test` sends a test message to the configured chat and reports
configured/success/failure — so the founder can confirm a shop's bot before the first
real order (getting the chat id: message the bot, or use @userinfobot).

## 6. Tests (`tests/Feature/TelegramAlertTest.php`)

Notifier (no-op unconfigured, posts when configured incl. Burmese text, returns false
& never throws on 500); wiring (checkout dispatches `OrderPlaced`, honeypot doesn't;
a configured checkout sends an alert containing the order details; honeypot/
unconfigured send nothing; a failing Telegram still returns 201 to the customer);
the `telegram:test` command (success configured, clear failure unconfigured).
`Http::fake()` throughout — no real network.

## 7. Not in scope / later

Customer-facing alerts (opt-in or SMS); a queue worker to make the send truly async;
alerts on other transitions (accepted/decanted/delivered) — all clean additions on
the same event layer.
