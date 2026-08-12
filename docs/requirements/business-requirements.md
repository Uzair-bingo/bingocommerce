# Business Requirements — BingoCommerce

Current-state functional/business spec. References BR IDs from
`docs/decisions/DECISIONS.md` for rationale/history — this file describes
*what the system does*, the decision log explains *why/when it was decided*.

---

## What This Is
Multi-hall e-commerce platform for Bingo Innovation. Bingo halls, churches, and
similar vendors each run a fully independent store selling physical and digital
products. Not a marketplace — no shared catalog, no shared cart, no central
shopping page. (BR-003)

## Store Structure
- One hall = one independent store: own products, pricing, inventory, tax,
  fulfillment. One order belongs to exactly one hall — no cross-hall carts. (BR-003)
- Each hall receives customer payments directly into its own merchant account.
  Bingo Innovation invoices halls separately for platform use — no in-platform
  subscription or per-transaction commission engine. (BR-004)
- Physical fulfillment is fully decentralized — each hall stocks/ships/reorders
  independently. (BR-005)
- Product catalog is general-purpose, not bingo-only — halls can sell anything.
  New hall-added products go live immediately, no approval gate. (BR-006)
- Custom domains (beyond the standard subdomain) are Phase 2, not launch. (BR-015)

## Roles
Three roles — do not conflate or merge:

| Role | Scope | Can do | Cannot do |
|---|---|---|---|
| **Hall Manager** | One or more owned halls | Products, pricing, inventory, orders, staff, refunds; switch between owned halls from one login | Install plugins, alter network config |
| **Game Operator** | One hall, floor/session only | Scan/redeem digital tickets, look up redemptions, staff-gated ticket reactivation | Pricing, products, billing — zero access |
| **Platform Admin** | Network-wide | Provision halls, edit/create products in any hall's store, set billing status, manage retention/export | Not assigned to any single hall |

(BR-018, BR-019, BR-020, BR-023, BR-027, BR-029)

## Customer Accounts
- Guest checkout is the primary path, no account required. (BR-007)
- Registered accounts: one shared login (email + password) works across every
  hall's site — but order history and cart stay fully separated per hall. (BR-007)
- Address book: previously used addresses may be suggested/reused across a
  customer's accounts at different halls to reduce data entry, but each hall
  stores its own separate copy for commerce/order records — updating an
  address at one hall never rewrites another hall's stored data. Payment
  methods/tokens remain fully hall-isolated (separate merchant accounts). (BR-028)
- No phone/email verification required for guest purchases — email is only used
  to deliver the receipt/QR. (BR-008)

## Digital Products
- One QR code represents the entire order (not per line item). Order loads onto
  exactly one Android device; a second device attempting redemption is blocked. (BR-009)
- **Capacity rule (canonical definition — supersedes any looser phrasing
  elsewhere)**: a qualifying order is a completed order containing at least one
  digital package for that session. Multiple digital packages in one order still
  count as ONE qualifying order. Session capacity = total qualifying orders,
  configurable per session, never hardcoded. (BR-002, BR-025)
- Ticket reactivation (device failure) is staff-gated only, never customer
  self-service. Every reactivation logs staff actor, timestamp, and increments a
  visible count. (BR-010)
- Refunds/cancellations (including refund-after-redemption) are resolved directly
  between hall and customer — platform not involved in that resolution. Refunding
  an order automatically voids the associated ticket. (BR-011)

## POS
- Halls may use their own existing POS system.
- Bingo Innovation separately offers an optional "Caller + POS" package
  (hardware + software, direct terminal control — POS sends amount, receives
  approve/decline). Provider (Clover) under discussion, not confirmed.
- External POS integration is selective (not universal), Level 2 depth
  (product + inventory sync only). (BR-012)

## Nonpayment Handling
Graduated five-state system, never an instant cutoff:

| Status | Store behavior |
|---|---|
| Active | Normal operation |
| Payment Overdue | Warning shown to Hall Manager; store works normally |
| Restricted | Hall staff **can still log in**, cannot publish products or accept new orders |
| Suspended | Storefront blocks new purchases; **staff login also blocked** |
| Terminated | Store archived |

Full cycle defaults to 60 days total, adjustable via Platform Admin settings —
never hardcoded. Digital tickets already sold remain redeemable regardless of
the hall's current billing status — a hall's billing dispute never blocks a
customer who already paid. (BR-016)

Terminated hall data is retained 90 days before deletion, with reminders at 30,
15, and 5 days before deletion. Only Platform Admin can export a hall's data —
halls cannot self-export at any status. (BR-017)

## Product Configuration Tools
- **Tied add-ons** (extras selected on a single product's own page, bundled into
  one add-to-cart action — e.g. topping-style extras): WooCommerce Product Add-ons.
- **Predefined packages** (combining multiple separate products, required +
  optional items): WooCommerce Product Bundles.
These are distinct tools for distinct use cases — do not conflate. (BR-021)

## Compliance
No gaming-license verification gate for halls to start selling. Terms & Conditions
place legal/regulatory responsibility on the hall instead. **This specific clause
needs actual lawyer review before launch** — not just internal sign-off. (BR-013)

## Onboarding
Supports both self-service hall onboarding and Bingo Innovation-assisted setup —
not either/or. (BR-024)

## Scale
Actual year-one target is approximately 20-30 halls, not 100+ (100+ was an
earlier planning ceiling, not the launch expectation). Architecture is built to
the same standard regardless of this correction — nothing here is over-built
for the smaller real starting scale. (BR-014)

## Still Open (do not build against an assumed answer)
- Offline handling strategy for digital ticket redemption
- Final payment gateway / POS hardware provider
- Legal review of whether 90-day retention satisfies any actual financial/tax
  record-keeping requirement
