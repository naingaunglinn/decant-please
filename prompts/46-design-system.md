# Step 46 — Storefront design system: section library, base designs, presets, design history

Roadmap: `prompts/43-cornerarea-roadmap.md` § Design system and decision 4;
`docs/adr/0005-storefront-design-config.md` (option B). Issue #142. Queue row 13.

**Goal.** Each shop's storefront looks like its own brand, and the seller changes that look
from a phone without learning a design tool. One storefront deployment renders every shop
from a **design config**: a small, validated JSON document that names a base design,
colours, a font, and an ordered list of sections with their text and images. The seller
starts from one of three presets for their category and later edits the config by form
(13c) or by AI chat (row 14). Nothing a seller or the AI does can produce code, a script,
an external image, an unreadable colour pair, or a link off the shop.

What a shop sees after this whole step ships: a **Design** page under Settings with three
presets, a Publish button and a history. A shop that never opens it keeps exactly the
storefront it has today.

---

## Split

The step is well over one reviewable PR. It splits on the same seam as 36 and 38
(a Resource change lands with `types.ts`):

- **46a (queue row 13): schema, section library, validator, presets, admin picker.** The
  public API and the storefront are unchanged, and the Design page stays out of the menu
  until 46b renders what it picks.
- **46b (row 13b): the storefront renders the published design.** The design goes into the
  API with `types.ts`. The storefront applies the colours and font as CSS variables on a
  wrapper (the `globals.css` `@theme` block is untouched) and builds the home page from
  the section list. The Design page joins the menu. Publishing busts only
  `api.meta.{slug}` (`ShopSetting::booted`), so the design goes in `/meta`, or a new
  endpoint's cache key is busted there too.
- **46c (row 13c): the manual editor.** A phone-first form over the same config: colours
  (with the contrast rule shown in words), font, section order and on/off, section text,
  and decoration images (hero, about) uploaded to `shops/{id}/design/…`, compressed on the
  phone. Save makes a new row; Publish makes it live.

## Design

### The design config (version 1)

```json
{
  "version": 1,
  "base": "clean",
  "preset": "decant.clean",
  "theme": {
    "colors": { "background": "#f2f8fc", "text": "#212121", "primary": "#013e37", "primary_text": "#f2f8fc" },
    "font": "modern"
  },
  "sections": [
    { "type": "hero", "on": true, "props": { "title": "…", "subtitle": "…", "button": "…", "image": null } },
    { "type": "featured", "on": true, "props": { "title": "…" } }
  ]
}
```

- `base`: one of the three base designs. **Clean** (light, product-first), **Bold** (big
  photos, strong colour), **Warm** (story-first, for food and services). A base sets
  what colours and a font don't: the type scale, corner radius, image shape and section
  spacing. The storefront owns that styling (46b); the backend knows each base's key,
  name and description only.
- `preset`: which preset the config started from. For information only.
- `theme.colors`: exactly four colours, `#rrggbb`. `text` on `background` and
  `primary_text` on `primary` must each reach a WCAG contrast ratio of 4.5:1, so no
  config (a preset, the form or the AI) can make the shop unreadable.
- `theme.font`: a key from a fixed list: `modern` (today's sans), `classic` (serif) and
  `friendly` (rounded). Each maps to a stack in the storefront that includes a Burmese
  face. A config never carries a font URL.
- `sections`: the home page, top to bottom. Each type appears at most once. `on: false`
  keeps a section's text while hiding it, so turning it back on loses nothing.

### The section library (home page)

The header and footer are the fixed frame: always shown, never reordered, styled by the
theme. They are not in the list.

| Type | Props | Renders (46b) |
|---|---|---|
| `announcement` | `text` ≤ 120 | a thin bar above the hero |
| `hero` | `title` ≤ 80, `subtitle` ≤ 240, `button` ≤ 30, `track_button` ≤ 30, `image` | headline, Shop button, a quieter order-tracking button (hidden when empty), image (the first featured product's photo when `image` is null) |
| `featured` | `title` ≤ 40 | the featured-products rail |
| `product_grid` | `title` ≤ 40 | the newest products |
| `category_nav` | `title` ≤ 40 | links to the shop's categories (hidden when it has none) |
| `steps` | `title` ≤ 40, `items` ≤ 4 × {`title` ≤ 30, `text` ≤ 160} | "How it works" |
| `tiles` | `items` ≤ 3 × {`label` ≤ 30, `text` ≤ 80, `link`} | large link tiles |
| `about` | `title` ≤ 60, `text` ≤ 1000, `image` | the shop's story |
| `contact` | `title` ≤ 40, `text` ≤ 300 | text plus the shop's social links (from settings) |
| `location` | `title` ≤ 40, `address` ≤ 300, `map_link` | address with an "Open in Maps" link |
| `delivery_fees` | `title` ≤ 40 | the shop's delivery zones and fees (from the API) |
| `order_tracking` | `title` ≤ 40, `text` ≤ 200 | a link into order tracking |
| `recently_viewed` | none | the recently-viewed rail (browser-only) |

Product-page blocks (the clothing size guide, decant notes and performance) stay driven by
template attributes (step 37b, `show: section`). They aren't home-page sections and aren't
in the design config.

**Validation rules** (one validator; every writer calls it):

- Unknown top-level keys, unknown section types, unknown props and duplicate types are
  refused with a message naming the path (`sections.2.props.title`). A missing prop is
  filled with its empty default, so the renderer always gets a complete shape.
- Text is plain text: trimmed, control characters refused (and the invisible line and
  direction controls, U+0085, U+2028/2029, U+202A–202E, U+2066–2069, which could reverse a
  phone number; not ZWSP or ZWNJ, which Burmese uses), lengths capped as above. The
  storefront renders it as text, never as HTML.
- `link` (tiles) is a path on the shop itself: it starts with one `/` and uses URL path and
  query characters only, with no empty (`//`) or dot (`.`, `..`) segment and no encoded
  slash or dot. `//host`, `/.//host`, `https://…` and `javascript:` are refused.
- `map_link` is `https://` on a maps host: `google.com` or `www.google.com` under
  `/maps` (not `/url`, which redirects anywhere), `maps.google.com` under `/maps` or bare
  (`?q=`), and `maps.app.goo.gl`. Not `goo.gl`: it shortened any URL. A path with an empty
  or dot segment, or an encoded slash or dot, is refused. Nothing else leaves the shop.
- `image` is null or a stored path under **this shop's** `shops/{id}/design/` prefix, with
  no `..`, ending in `.jpg`, `.jpeg`, `.png` or `.webp` (never SVG or HTML, which the
  public disk serves as-is). A path under another shop's prefix, or a URL, is refused. The API resolves
  paths to URLs (46b). A config never holds a URL.
- A section list longer than the library is refused.

Rows are append-only by construction: `ShopDesign` throws on update.

Old rows must keep rendering after the library grows: validation runs on **write** only.
The renderer skips a section type or prop it doesn't know, and never errors on one.
`version` changes only for a change the renderer can't skip over.

### Presets

A preset is data, not code: `backend/resources/designs/presets/{template}.{base}.json`,
three per template, one per base. Each is a full config with Burmese sample copy. A test
loads every registered template's three presets and validates them, so a template can't
ship without its presets (P5). Presets carry no sample photos. The hero falls back to the
shop's own first featured product, which is always one of the seller's own photos
(the roadmap asks to test with ordinary phone photos).

**Decant's Clean preset is today's storefront.** It has the same colours, the same font
and the same English copy as the current home page. A shop with no published design
renders its template's Clean preset, so Decant Please looks exactly as it does now.
Decant's Bold and Warm presets and all three clothing presets carry Burmese copy.

### Design history

Every change is a new `shop_designs` row, never an update. Publishing points
`shop_settings.published_design_id` at a row. **Undo** means publishing an older row. The
admin never deletes a row, and a row is never rewritten.

**One domain class**, `App\Design\Designs`. It's the only writer: the Design page, the
13c form, the row-14 AI editor and the tests all go through it (P4).

- `create(config, source, user, prompt?, tokens?)` validates, then inserts. It doesn't
  publish.
- `publish(id)` loads the row through the shop-scoped query, so another shop's id is a
  not-found, never a cross-shop pointer. It then saves the settings row with the model,
  so `ShopSetting::booted` busts the shop's cached `/meta`.
- `usePreset(key, user)` does create and publish in one transaction.
- `live()` returns the published config, or the template's Clean preset when none is
  published.

## Schema

`shop_designs`, a new tenant table with `BelongsToShop` and its isolation test:

| Column | Type |
|---|---|
| `id` | bigint |
| `shop_id` | FK shops, cascade on delete (a design isn't money) |
| `config` | jsonb |
| `source` | string: `preset`, `manual` or `ai` (`App\Enums\DesignSource`) |
| `prompt` | text, nullable (row 14: the seller's AI request) |
| `input_tokens`, `output_tokens` | unsigned int, nullable (row 14: cost per shop) |
| `created_by` | FK users, nullable, null on delete |
| `created_at`, `updated_at` | timestamps |

Index `(shop_id, created_at)` for the history list and row 14's monthly quota count. The
AI columns land now, so row 14 needs no migration.

`shop_settings.published_design_id`: FK `shop_designs`, nullable, **null on delete**.
It isn't RESTRICT, because both tables cascade from `shops` and SQLite checks RESTRICT
mid-cascade (the step-36 `brand_id` lesson). Null means "the template's Clean preset".

## API

46a: none. 46b adds the live design, with image paths resolved to URLs, to the API and
`types.ts`.

## Admin (46a)

A **Design** page (`App\Filament\Pages\ManageDesign`), Settings group. It is registered
but left out of the menu until 46b.

- **Live now**: the live design's name ("Clean — default" while none is published).
- **Presets**: three cards for the shop's template, each with its name, a one-line
  description and four colour swatches. **Use this design** asks for confirmation, then
  publishes.
- **History**: the last 20 rows, newest first, each with its date, what it is ("Preset:
  Bold"), who made it, and a **Live** badge or a **Use this one** button.
- Labels are in English and Burmese side by side, as on the sign-up page. The page is
  core, not a module: every shop has a storefront.

## Tests (`DesignSystemTest`, `TenantIsolationTest`)

- Every registered template has three presets, and each passes the validator.
- The validator refuses each rule above with a path-named message, and normalizes a
  valid config: lowercased colours, missing props filled.
- `live()` with nothing published gives the template's Clean preset. Decant's Clean
  preset matches today's colours.
- `usePreset` inserts a `preset` row with `created_by` and publishes it. Publishing the
  older row is undo. Rows are never updated, only added.
- Isolation: `shop_designs` is scoped by count. Shop A publishing shop B's design id fails
  and leaves A's pointer unchanged. An image path under another shop's prefix is refused.
- Deleting a shop removes its designs without tripping the settings FK.
- The Design page renders for a shop member, and its actions publish.

## Risks

- **Parity.** 46b must render decant's Clean preset exactly as today's home page. The
  preset's copy is taken verbatim from `app/[host]/page.tsx` and `Hero.tsx` (both hero
  buttons: `button` and `track_button`), and 46b's browser checks compare the two. The
  Designer / Niche tiles keep today's rule: 46b hides a `brand_type` tile when `/meta`
  has no brand types.
- **Library drift.** An old row can name a section a later release retires. The renderer
  skips it (above), and the validator refuses it only on write.
- **Contrast rule and presets.** Strong brand colours may fail 4.5:1 with white text. A
  preset picks `primary_text` to pass. The 46c form explains a refusal in plain words.

## Deliberately not built

- Group-specific sections: pre-order notice, menu board, opening hours, service price
  list, staff cards, booking widget, shade swatches and region story. Each lands with
  the group or template row whose data it shows (rows 15–30), because a section without
  its data would render empty.
- Sample photos in presets. The hero uses the seller's own product photo.
- Per-section styling beyond the base design, and custom CSS. A base plus four colours
  and a font is the whole surface (ADR-0005).
- Deleting design rows. History is append-only and small (one JSON document per change).
- A draft preview in 46a. Publishing is one tap to undo from the history.
- The support log for requests the library can't serve. It comes with the AI editor
  (row 14), where such requests arrive.

## P6 answers

1. Every seller, in every category, as soon as they have a storefront. They're on their
   phone and want the shop to look like theirs rather than like a perfume shop.
2. Nothing changes until they open the Design page. Their storefront keeps today's look.
3. Core. Every shop has a storefront, so every shop has a design.
4. Four colours, a font, three bases and 13 sections, all validated. Presets, publish
   and undo come first. The form and the AI follow.
5. No money, stock or personal data. Tenancy: a new tenant table with its isolation test,
   a publish that can't point across shops, and image paths confined to the shop's own
   prefix.
6. Not built: listed above.
