# 43 — CornerArea roadmap

A roadmap, not a step spec. Each phase gets its own step file (`prompts/NN-*.md`) when it
starts, shaped like the steps in `35-generic-shop-plan.md`. The unattended build order lives
in `prompts/RUN-QUEUE.md`.

**CornerArea** is the platform; Decant Please is shop #1. CornerArea ships every planned
category complete, ahead of demand (`AGENTS.md` P5). Recorded 2026-09-25 (#115) from the
owner's scope decisions (`prompts/queue-01-scope-docs.md`). Don't reopen them in a step PR;
propose an amendment to this file instead.

---

## Decisions (recorded, not open)

1. **P5 is replaced**: every category ships complete. The step-38 gate in
   `35-generic-shop-plan.md` is a review stop; 39–41 continue after it.
2. **23 categories in 4 groups**, grouped by order flow (below).
3. **Template per product.** `products.template` is set per product and copied from the
   shop's default (`shop_settings.template`) on create. A shop may mix templates only within
   its own group (clothes + bags: yes; cafe drinks + shipped coffee beans: no). Shopify
   (product category) and Square (item type) both attach the type per product.
4. **Storefront design, option B** (`docs/adr/0005-storefront-design-config.md`). One
   storefront deployment. Each shop picks one of three presets for its category, then edits
   colours, fonts, section order, section on/off, text and images, by form or by AI chat.
   The AI edits a validated design config (JSON) inside the section library. It never
   writes code; there are no per-seller codebases or Vercel projects (ADR-0004 stands). A
   request the section library can't serve goes to support and is logged; that log is the
   section backlog.
5. **Seller images**, both uploaded by the seller: decoration images (banner, backgrounds)
   in the design editor; product images (the garment, the cake) in the admin. Compressed on
   the phone before upload.
6. **Buyer reference image** ("make my cake like this"): allowed on products whose template
   enables it. Private storage like payment proofs, no public or presigned URL (AGENTS.md
   rule 8).
7. **Gift recipient**: an order can carry a recipient name, phone and address different from
   the buyer's, plus a gift message. The courier contacts the recipient. Tracking lookup
   stays on the buyer's phone (rule 4 unchanged).
8. **Dine-in ordering, two ways, in group 3's first release**: a table QR the customer scans
   to self-order, and a waiter quick-order screen. Both land on one counter screen with the
   table number. Repeat orders at one table combine into one bill. Waiters need staff
   accounts that can enter orders but can't see money, so `shop_user.role` (owner | staff)
   is in group 3's scope.
9. **Booking confirmation**: the customer taps a Viber or Telegram deep link to message the
   shop. Facebook Messenger and TikTok come later. The platform sends nothing, so the
   end-customer-notifications non-goal is unchanged.
10. **Release per group.** A category ships only when it passes the checklist below. Groups
    release one at a time, in the build order below.
11. **Parked: licensed goods** such as medicine. When reopened: the seller uploads a licence,
    the studio admin approves it, the category closes automatically when the licence
    expires, and the legal rules for selling medicine online are checked first.
12. **Undecided: free-plan limits and paid pricing** (`PRODUCT.md` open question 2).

## "Complete" checklist

A category is released only when all of these work (P5):

- [ ] Catalog: template attributes and variant options
- [ ] Ordering end to end
- [ ] Payment
- [ ] The phone-first admin loop (see order → check slip → mark paid → accept)
- [ ] Status labels in Burmese and English
- [ ] Three design presets with Burmese sample content
- [ ] Default modules
- [ ] Tests: money, stock or booking; tenant isolation; Postgres portability

## Shared foundation

| Piece | Status |
|---|---|
| Multi-tenancy, payments (COD, KBZPay, Wave, MMQR, slip, deposit), delivery zones + courier float, promo codes, expenses and P&L, studio take-control | Built |
| Generic catalog | Steps 35–38 |
| Per-shop `categories` | Step 37 (amended) |
| Self-serve sign-up: phone verify; shop created as `onboarding`; `slug.cornerarea.me` added automatically; publish makes it `live`; wildcard domain attached once | New |
| Help button: admin → studio on Viber/Telegram, in Burmese | New |
| Design system (below) | New |
| Plans | New; free plan undecided |
| Letterhead bug (#116): `pdf/invoice.blade.php` and `production-schedule-day.blade.php` hard-code "Decant Please!", so a second shop's invoices would print it. Must use the shop's name. | Built (v38, queue row 2); merge before a second shop goes live |

## Group 1: ship products

Uses the existing flow: order → slip/COD → pack → courier → delivered.

| Category | Attributes | Variant options | Stock |
|---|---|---|---|
| Decant | brand, concentration, gender, notes, vibes, performance | Size (ml) | pooled ml |
| Clothing | brand (optional), material, gender/age group | Size + Colour | per variant; photo per colour; size guide |
| Cosmetics & skincare | brand, origin country, skin type, expiry | Shade or Size | per variant |
| Bags, shoes & accessories | brand, material | Colour (+ Size for shoes) | per variant |
| Phone & electronics accessories | brand, compatible models (searchable), warranty (months) | Model or Colour | per variant |
| Baby & kids | brand, age range | Size | per variant |
| Home & kitchen | material, dimensions | Colour / Size | per variant |
| Books & stationery | author, publisher, language | none | per variant |
| Pet supplies | animal, brand | Size / weight | per variant |
| Local produce (lahpet, dried fish, honey) | region of origin | weight in kyatthar / viss (1 viss = 100 kyatthar) | pooled by weight |
| Online store (general) | none | seller-named options | per variant |

Group 1 still needs: the two stock modes (step 40), variant photos and position (planned in
steps 38 and 36), and Myanmar weight units.

## Group 2: pre-order

Categories: bakery & cake; home-cooked food; florist & gifts; custom print & handmade.

It needs:
- Per-product lead time and order cutoff.
- A date picker at checkout, with closed and full days blocked.
- Daily capacity.
- An order-line note.
- Delivery or pickup.
- A deposit-percent rule.
- The production schedule reused as the prep date (step 39).
- The buyer reference image (decision 6).
- The gift recipient and message (decision 7).

## Group 3: food & drink

Categories: cafe; tea shop; juice & bubble tea; restaurant & food stall.

It needs:
- Priced modifiers: single-choice groups (sugar level) and multi-choice groups (toppings).
  The server derives the price, and the order line snapshots it.
- Menu categories.
- Delivery, pickup or dine-in.
- Opening hours and a closed state (no ordering while closed).
- A sold-out toggle (the existing `in_stock`).
- Dine-in ordering and the per-table bill (decision 8).
- Staff accounts (decision 8).
- A counter screen: today's queue, big buttons, auto-refresh, works on a phone or tablet.

## Group 4: services & booking

Categories: barber; beauty salon & nail; spa & massage; tutor & classes.

It needs:
- Services as products, with duration and price on the variant.
- Staff: name, photo, services, working hours, days off. A new tenant table; needs its
  isolation test.
- Slot calculation.
- Appointments: staff, service, start, end, status, customer name and phone, deposit, slip.
- No double booking, enforced by a Postgres exclusion constraint on (staff, time range) and
  tested on Postgres, not SQLite.
- Per-slot capacity for classes.
- A per-staff day calendar (reuse the production-schedule day view).
- Booking states: requested → confirmed → completed or no_show; requested or confirmed →
  cancelled.
- Confirmation by deep link (decision 9).

## Status labels

The order states stay fixed; only labels vary by group (P4). Group 4 uses the booking states.

| State | Group 1 | Group 2 | Group 3 |
|---|---|---|---|
| awaiting_confirmation | အတည်ပြုရန် | အတည်ပြုရန် | အသစ် |
| pending | လက်ခံပြီး | လက်ခံပြီး | ပြင်ဆင်နေ |
| prepared | ထုပ်ပိုးပြီး (decant: ခွဲပြီး) | လုပ်ပြီး (bakery: ဖုတ်ပြီး) | အဆင်သင့် |
| delivered | ပို့ပြီး | ပို့ပြီး / ယူပြီး | ယူပြီး |

## Default modules

Seller-overridable (step 41).

| Module | G1 | G2 | G3 | G4 |
|---|---|---|---|---|
| Delivery zones + courier float | on | on | optional | off |
| Stock | on | off | sold-out toggle only | off |
| Cost & margin | on | on | off | off |
| Production schedule | decant only | on | off | off |
| Promo codes | on | on | on | on |
| Expenses / P&L | on | on | on | on |
| Pre-order (dates, capacity) | off | on | off | off |
| Modifiers | off | off | on | off |
| Opening hours | off | optional | on | on |
| Counter screen | off | off | on | off |
| Booking + staff | off | off | off | on |

## Design system

- **Base designs:** Clean (white, product-first), Bold (big photos, strong colour), Warm
  (story-first, for food and services).
- **The section library**, about 20 sections:
  - Shared: header, announcement bar, hero, product grid, featured, category/menu nav,
    about, contact & social, location map, delivery fees, order tracking, footer.
  - Category-specific: size guide, shade swatches, notes & performance, pre-order notice,
    menu board, opening hours / open now, service price list, staff cards, booking widget,
    region story.
- **A preset is data, not code:** base design + section order + colours + Burmese sample
  copy + sample photos, 3 per category.
- **A `shop_designs` table** (new tenant table, isolation test): `config` jsonb, `source`
  (preset | manual | ai), `prompt`, `input_tokens`, `output_tokens`, `created_by`,
  `created_at`. `shop_settings.published_design_id` points at the live row.
  - Every change is a new row, so undo means publishing an older row.
  - This month's `ai` rows are the AI quota.
  - The token columns give the cost per shop.
- Test every preset with ordinary phone photos, not only stock photos.

## Build order

1. Go-live → #113 → #112 → the CornerArea rename + letterhead fix.
2. This docs PR (P5 and PRODUCT.md).
3. Steps 35–38 (as amended).
4. Sign-up and the help button.
5. The design system without AI.
6. The AI editor, plans, and group 1 → first release.
7. Group 2 → release.
8. Group 3 → release.
9. Group 4 → release.

This is the *merge and release* order. The unattended run builds ahead of the go-live and
stacks PRs (`prompts/RUN-QUEUE.md`): the letterhead fix is built now (queue row 2) because
it doesn't depend on the rename; the rename in code waits for the go-live (row 31,
`after-go-live`).

## Parked / undecided

- Licensed goods (decision 11).
- Free-plan limits and pricing (decision 12).
