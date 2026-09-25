# Record the CornerArea scope in the repo (docs only)

Timing: docs only. This is row 1 of `prompts/RUN-QUEUE.md`: stack on row 0's branch (#112,
which stacks on `110-drop-is-studio-flag`/#113), target the PR at row 0's branch, and follow
`prompts/run-step.md` for the queue bookkeeping. No code, no migrations,
and no brand rename in code (Decant Please → CornerArea in code is its own PR after the
go-live). Building doesn't wait for the go-live; merging does: go-live → #113 → #112 → the
queue, bottom-up.

## Why

The owner has changed the product's scope. CornerArea (the platform; Decant Please becomes
shop #1) will ship every planned shop category complete, ahead of demand, instead of
building only when a seller asks. Today's AGENTS.md P5 says the opposite, so every future
PR would argue with the owner. Record the decisions below so later steps build from the repo,
not from chat.

## Decisions already made (don't reopen these)

1. **P5 is replaced** (exact text below). The step-38 real-seller gate in
   `prompts/35-generic-shop-plan.md` becomes a review stop: I review after 38, then 39–41
   continue. It no longer waits for a real seller.
2. **23 categories in 4 groups**, grouped by order flow (tables below).
3. **Template per product.** `products.template` is set per product and copied from the
   shop's default (`shop_settings.template`) on create. A shop may mix templates only
   within its own group (clothes + bags: yes; cafe drinks + shipped coffee beans: no).
   Shopify (product category) and Square (item type) both attach the type per product.
4. **Storefront design (option B).** One storefront deployment. Each shop picks one of
   three presets for its category, then edits colours, fonts, section order, section on/off,
   text and images, by form or by AI chat. The AI edits a validated design config (JSON)
   inside the section library. It never writes code, and there are no per-seller codebases
   or Vercel projects (ADR-0004 stands). A request the section library can't serve goes to
   support and is logged; that log is the section backlog.
5. **Seller images:** two kinds, both uploaded by the seller. Decoration images (banner,
   backgrounds) go in the design editor. Product images (the garment, the cake) go in the
   admin. Compress on the phone before upload.
6. **Buyer reference image** (for example, "make my cake like this"): allowed on products
   whose template enables it. Stored on private storage like payment proofs, with no public
   or presigned URL (AGENTS.md rule 8).
7. **Gift recipient:** an order can carry a recipient name, phone and address different
   from the buyer's, plus a gift message. The courier contacts the recipient. Tracking
   lookup stays on the buyer's phone (rule 4 unchanged).
8. **Dine-in ordering, two ways, in group 3's first release:** a table QR the customer scans
   to self-order, and a waiter quick-order screen. Both land on one counter screen with the
   table number. Repeat orders at one table combine into one bill. Waiters need staff
   accounts that can enter orders but can't see money. This moves the `shop_user.role`
   (owner | staff) column into group 3's scope.
9. **Booking confirmation:** the customer taps a Viber or Telegram deep link to message the
   shop. Facebook Messenger and TikTok come later. The platform itself sends nothing, so
   the end-customer-notifications non-goal is unchanged.
10. **Release per group.** A category ships only when it passes the "complete" checklist
    below. Groups release one at a time as each is complete, in the build order below.
11. **Parked:** licensed goods such as medicine. When reopened, the seller uploads a licence,
    the studio admin approves it, the category closes automatically when the licence
    expires, and the legal rules for selling medicine online are checked first.
12. **Undecided:** free-plan limits and paid pricing. Leave that as an open question.

## What to change

### AGENTS.md

Replace P5 with:

```markdown
### P5. Every category ships complete

- CornerArea builds every planned category ahead of demand. We don't wait for a seller to
  ask. The categories and their groups live in `prompts/43-cornerarea-roadmap.md`.
- A category is released only when its whole loop works: catalog (template attributes and
  options), ordering end to end, payment, the phone-first admin loop, status labels in
  Burmese and English, three design presets with Burmese sample content, default modules,
  and tests (money, stock or booking; tenant isolation; Postgres portability). Never ship
  half a category.
- Categories release group by group. A finished group doesn't wait for the others.
- Build the smallest version that makes the category complete, and record what was
  deliberately left out (the §8 pattern).
- Unused features are candidates for hiding or removal.
```

Change P6 question 1 to: "Which category, and which seller in it, needs this? What are they
doing when they hit the problem?" In §1, point to the roadmap file next to the generic-shop
plan. Change nothing else in AGENTS.md.

### PRODUCT.md

- "What this is": name the platform CornerArea (Decant Please is shop #1), name the four
  groups, and link the roadmap.
- Fix the stale line "with a separate storefront deployment per shop domain". ADR-0004
  made it one deployment.
- WHO table: the end customer buys from a shop, not "buys decants".
- Non-goals:
  - Clarify that a customer-initiated Viber/Telegram deep link is not a chat feature and
    not a platform notification.
  - Add "per-seller generated code or per-seller deployments (ADR-0004; design option B)".
  - Add licensed goods as parked, with the reopening conditions from decision 11.
- Open questions: mark #3 (per-shop theming) resolved by the design system in the roadmap.
  Keep #2 (plan and trial state) open and note that free-plan limits are undecided.

### prompts/35-generic-shop-plan.md (amend; these came out of the catalog review)

- **Step 36:**
  - `order_items.size_ml` becomes nullable. A clothing line has no ml.
  - `product_variants` gains `is_active`: archive a size or colour, never delete it, and
    `order_items.product_variant_id` restricts delete.
  - `product_variants` gains `position` for display order (S, M, L, XL isn't alphabetical).
  - `products.brand_id` and `brands.type` become nullable.
- **Step 37:**
  - Add `products.template`, and move `shop_settings.template` here from step 41
    (step 38 already needs it).
  - Add the per-shop `categories` table (menu sections, "tops / dresses"), with its
    isolation test in the same PR.
- **Step 38:** add `product_variants.image_path` (a photo per colour).
- **The gate after 38:** make it a review stop, per decision 1.
- **"Deferred / open":** resolve "brand as a concept". Brand is optional per template.

### New: prompts/43-cornerarea-roadmap.md

A roadmap, not a step spec. Each phase gets its own step file later. It holds:

**"Complete" checklist** (from P5): catalog, ordering end to end, payment, the admin loop,
status labels in Burmese and English, three presets, default modules, tests.

**Shared foundation:**

| Piece | Status |
|---|---|
| Multi-tenancy, payments (COD, KBZPay, Wave, MMQR, slip, deposit), delivery zones + courier float, promo codes, expenses and P&L, studio take-control | Built |
| Generic catalog | Steps 35–38 |
| Per-shop `categories` | Step 37 (amended) |
| Self-serve sign-up: phone verify; shop created as `onboarding`; `slug.cornerarea.me` added automatically; publish makes it `live`; wildcard domain attached once | New |
| Help button: admin → studio on Viber/Telegram, in Burmese | New |
| Design system (below) | New |
| Plans | New; free plan undecided |
| Letterhead bug: `pdf/invoice.blade.php` and `production-schedule-day.blade.php` hard-code "Decant Please!", so a second shop's invoices would print it. Must use the shop's name. | Fix before a second shop goes live |

**Group 1: ship products.** Uses the existing flow: order → slip/COD → pack → courier →
delivered.

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

Group 1 still needs: the two stock modes (step 40), variant photos and position, and
Myanmar weight units.

**Group 2: pre-order.** Categories: bakery & cake; home-cooked food; florist & gifts;
custom print & handmade. It needs:
- Per-product lead time and order cutoff.
- A date picker at checkout, with closed and full days blocked.
- Daily capacity.
- An order-line note.
- Delivery or pickup.
- A deposit-percent rule.
- The production schedule reused as the prep date (step 39).
- The buyer reference image (decision 6).
- The gift recipient and message (decision 7).

**Group 3: food & drink.** Categories: cafe; tea shop; juice & bubble tea; restaurant &
food stall. It needs:
- Priced modifiers: single-choice groups (sugar level) and multi-choice groups (toppings).
  The server derives the price, and the order line snapshots it.
- Menu categories.
- Delivery, pickup or dine-in.
- Opening hours and a closed state (no ordering while closed).
- A sold-out toggle (the existing `in_stock`).
- Dine-in ordering and the per-table bill (decision 8).
- Staff accounts (decision 8).
- A counter screen: today's queue, big buttons, auto-refresh, works on a phone or tablet.

**Group 4: services & booking.** Categories: barber; beauty salon & nail; spa & massage;
tutor & classes. It needs:
- Services as products, with duration and price on the variant.
- Staff: name, photo, services, working hours, days off. This is a new tenant table and
  needs its isolation test.
- Slot calculation.
- Appointments: staff, service, start, end, status, customer name and phone, deposit, slip.
- No double booking, enforced by a Postgres exclusion constraint on (staff, time range) and
  tested on Postgres, not SQLite.
- Per-slot capacity for classes.
- A per-staff day calendar (reuse the production-schedule day view).
- Booking states: requested → confirmed → completed or no_show; requested or confirmed →
  cancelled.
- Confirmation by deep link (decision 9).

**Status labels.** The order states stay fixed; only labels vary by group (P4). Group 4
uses the booking states.

| State | Group 1 | Group 2 | Group 3 |
|---|---|---|---|
| awaiting_confirmation | အတည်ပြုရန် | အတည်ပြုရန် | အသစ် |
| pending | လက်ခံပြီး | လက်ခံပြီး | ပြင်ဆင်နေ |
| prepared | ထုပ်ပိုးပြီး (decant: ခွဲပြီး) | လုပ်ပြီး (bakery: ဖုတ်ပြီး) | အဆင်သင့် |
| delivered | ပို့ပြီး | ပို့ပြီး / ယူပြီး | ယူပြီး |

**Default modules.** Seller-overridable (step 41).

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

**Design system:**
- Base designs: Clean (white, product-first), Bold (big photos, strong colour), Warm
  (story-first, for food and services).
- The section library, about 20 sections:
  - Shared: header, announcement bar, hero, product grid, featured, category/menu nav,
    about, contact & social, location map, delivery fees, order tracking, footer.
  - Category-specific: size guide, shade swatches, notes & performance, pre-order notice,
    menu board, opening hours / open now, service price list, staff cards, booking widget,
    region story.
- A preset is data, not code: base design + section order + colours + Burmese sample copy
  + sample photos, 3 per category.
- A `shop_designs` table (new tenant table, isolation test): `config` jsonb, `source`
  (preset | manual | ai), `prompt`, `input_tokens`, `output_tokens`, `created_by`,
  `created_at`. `shop_settings.published_design_id` points at the live row.
  - Every change is a new row, so undo means publishing an older row.
  - This month's `ai` rows are the AI quota.
  - The token columns give the cost per shop.
- Test every preset with ordinary phone photos, not only stock photos.

**Build order:**
1. Go-live → #113 → #112 → the CornerArea rename + letterhead fix.
2. This docs PR (P5 and PRODUCT.md).
3. Steps 35–38 (as amended).
4. Sign-up and the help button.
5. The design system without AI.
6. The AI editor, plans, and group 1 → first release.
7. Group 2 → release.
8. Group 3 → release.
9. Group 4 → release.

**Parked / undecided:** licensed goods (decision 11); free-plan limits and pricing
(decision 12).

### New: docs/adr/0005-storefront-design-config.md

A short ADR. Context: per-seller AI-generated code on per-seller deployments (option A)
vs one storefront whose design config the AI edits (option B). Decision: B.

Consequences:
- API changes don't break seller sites.
- AI cost per edit is small.
- No seller code runs on `*.cornerarea.me`, so there is no phishing or cookie exposure
  under our domain.
- One section library to maintain.
- The cost: the AI can't build what the library lacks.

### Also

- `prompts/README.md`: add the roadmap row.
- `CHANGELOG.md`: add a docs-only entry.
- Open an issue for the letterhead bug. Don't fix it in this PR.

## Checks

Docs and runner config only, so no test run is required. Confirm that the PR diff touches
only these files:
- `AGENTS.md`
- `PRODUCT.md`
- `prompts/35-generic-shop-plan.md`
- `prompts/43-cornerarea-roadmap.md`
- `prompts/README.md`
- `docs/adr/0005-*.md`
- `CHANGELOG.md`
- `prompts/RUN-QUEUE.md` (queue bookkeeping only; row 0 committed the runner files)

Answer P6 in the PR description, using the new P5 wording. Follow issue → branch → PR per
WORKFLOW.md. Don't merge.
