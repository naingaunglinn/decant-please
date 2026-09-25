# RUN-QUEUE — CornerArea build run

Source of truth for the unattended run (`prompts/run-step.md`). The run does one item per
session, in order. Each item's PR is stacked on the previous item's branch.

Statuses:
- `todo`
- `in-progress`
- `pr-open`
- `needs-owner`: skipped until the owner decides
- `after-go-live`: skipped until the owner flips it to `todo`
- `blocked`: the whole run stops

Owner: to request changes on a PR, leave review comments and add the label `fix`. The next
run handles them first. Merge stack PRs bottom-up with a merge commit, not a squash.

| # | Item | Spec | Status | Branch | PR |
|---|---|---|---|---|---|
| 0 | Commit the runner files; #112: money-table FKs cascade → restrict (orders, order_items, expenses `shop_id`) | issue #112 | in-progress | 112-money-fk-restrict | |
| 1 | Record the CornerArea scope | `prompts/queue-01-scope-docs.md` | todo | | |
| 2 | Invoice and print letterheads use the shop's name, not "Decant Please!" | roadmap: shared foundation (letterhead bug) | todo | | |
| 3 | Step 35: baseline parity test | `35-generic-shop-plan.md` §35 (stack from here, not from the #67 branch; #67 is merged) | todo | | |
| 4 | Step 36: product + variant model | §36, as amended by item 1 | todo | | |
| 5 | Step 37: templates, attributes, `products.template`, per-shop categories | §37, as amended | todo | | |
| 6 | Step 38: clothing template, variant photos | §38, as amended | todo | | |
| 7 | Step 39: template status labels (`decanted` → `prepared`) | §39 | todo | | |
| 8 | Step 40: stock modes (pooled / per variant), Myanmar weight units | §40 + roadmap group 1 | todo | | |
| 9 | Step 41: module toggles, template default modules | §41 + roadmap "default modules" | todo | | |
| 10 | Step 42: design-spec sync (docs) | §42 | todo | | |
| 11 | Self-serve sign-up + automatic `slug.cornerarea.me` | roadmap: shared foundation. The wildcard DNS/Vercel attach is an owner step: document it, don't do it | todo | | |
| 12 | Help button (admin → studio on Viber/Telegram) | roadmap: shared foundation | todo | | |
| 13 | Design system: section library, 3 base designs, `shop_designs`, preset picker, manual editor (no AI) | roadmap: design system | todo | | |
| 14 | AI design editor + per-shop quota | roadmap: design system. API key from env (owner step); tests mock the API | todo | | |
| 15 | Group 1: 11 templates + presets + Burmese sample content | roadmap: group 1 | todo | | |
| 16 | Plans (free / paid) | free-plan limits undecided | needs-owner | | |
| 17 | Group 2a: pre-order dates, cutoff, daily capacity, pickup, deposit rule | roadmap: group 2 | todo | | |
| 18 | Group 2b: order-line note, buyer reference image, gift recipient | roadmap: group 2 | todo | | |
| 19 | Group 2c: group 2 templates + presets | roadmap: group 2 | todo | | |
| 20 | Group 3a: priced modifiers + menu categories | roadmap: group 3 | todo | | |
| 21 | Group 3b: delivery / pickup / dine-in, opening hours, closed state | roadmap: group 3 | todo | | |
| 22 | Group 3c: staff accounts (`shop_user.role` owner / staff) | roadmap: group 3 | todo | | |
| 23 | Group 3d: dine-in table QR, waiter quick-order, per-table bill | roadmap: group 3 | todo | | |
| 24 | Group 3e: counter screen | roadmap: group 3 | todo | | |
| 25 | Group 3f: group 3 templates + presets | roadmap: group 3 | todo | | |
| 26 | Group 4a: services, service providers, working hours | roadmap: group 4 | todo | | |
| 27 | Group 4b: slots, appointments, no-double-booking constraint | roadmap: group 4 | todo | | |
| 28 | Group 4c: booking storefront + deep-link confirmation | roadmap: group 4 | todo | | |
| 29 | Group 4d: per-provider day calendar | roadmap: group 4 | todo | | |
| 30 | Group 4e: group 4 templates + presets | roadmap: group 4 | todo | | |
| 31 | CornerArea brand rename in code | roadmap: build order 1 | after-go-live | | |

## Log

(one line per run: date · item · what happened · PR)
