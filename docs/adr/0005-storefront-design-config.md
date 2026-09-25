# ADR-0005: Per-shop storefront design is a config the AI edits, not code

**Status:** Accepted — 2026-09-25
**Date:** 2026-09-25
**Deciders:** Naing Aung Linn
**Resolves:** `PRODUCT.md` open question 3 (per-shop theming)
**Builds on:** ADR-0004 (one storefront deployment for every shop domain), which stands
unchanged. Detail lives in `prompts/43-cornerarea-roadmap.md` § Design system.

## Context

CornerArea shops span 23 categories in four groups (roadmap). Each seller wants a storefront
that looks like *their* brand, and wants to change it from a phone without learning a
design tool. An AI editor makes that possible. Two ways to build it:

- **Option A — per-seller generated code.** The AI writes each seller's storefront as code,
  deployed as that seller's own site (a codebase or Vercel project per seller).
- **Option B — one storefront, a design config.** One storefront deployment renders every
  shop from a validated design config (JSON): base design, section order, section on/off,
  colours, fonts, text, images. Sections come from one shared section library. The AI
  (and the manual form editor) only edit that config.

## Decision

**Option B.** Each shop starts from one of three presets for its category and edits the
design config by form or by AI chat. The config is validated against the section library's
schema before it is saved; every change is a new `shop_designs` row, and publishing points
`shop_settings.published_design_id` at one. The AI never writes code, and there are no
per-seller codebases or deployments (ADR-0004). A request the library can't serve goes to
support and is logged; that log is the section backlog.

## Consequences

- **API changes don't break seller sites.** Every shop renders through the same components,
  so one change to the API updates one storefront, not N generated copies.
- **AI cost per edit is small.** The model edits a short JSON document, not a codebase; the
  token columns on `shop_designs` give the cost per shop, and this month's `ai` rows are
  the quota.
- **No seller code runs on `*.cornerarea.me`,** so there is no phishing page or cookie
  exposure under our domain.
- **One section library to maintain,** tested once, instead of N sites.
- **The cost: the AI can't build what the library lacks.** A seller asking for something
  outside it waits for a new section. The support log makes that wait visible and decides
  which section to build next.
