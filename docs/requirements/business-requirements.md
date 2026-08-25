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
- Physical fulfillment is fully decentralized — each hall stocks and reorders
  independently. BingoCommerce v1 does not support shipping; fulfillment is
  local/on-site/pickup only. (BR-005, BR-048 — BR-048 supersedes only BR-005's
  prior shipping wording, not its stocking/inventory provisions)
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

## Bingo Catalog Domain — Game Type, Bingo Play Product, Package
- **Game Type** is a first-class BingoCommerce domain entity (e.g. Regular, Winner
  Take All, Red Hot), distinct from the WooCommerce product that sells it (e.g.
  "3-on Regular" is a product; "Regular" is its Game Type). Game Type is never
  inferred from product title, SKU, tags, or category at runtime. Bingo Innovation
  provides starter Game Types copied into each hall at provisioning; Hall Managers
  may add hall-local Game Types. Do not introduce an All Game Types Game Type or
  special combined Game-Type bucket — Game Types remain individually identified.
  Each hall-local Game Type has a surrogate domain ID and a stable immutable
  machine code separate from its editable display name. (BR-037, BR-057,
  ADR-031)
- **Bingo Play Product** is a reusable WooCommerce product used as a package-building
  component — not independently customer-purchasable in this release. Structured
  metadata: Medium (Paper/Digital), Faces per unit, and a Game Type reference where
  required. A Digital Bingo Play Product must reference exactly one Game Type; a
  Paper Bingo Play Product always contributes zero to the electronic-card limit and
  is not required to carry a Game Type solely for that enforcement. Legacy vendor
  catalog tags may be used for one-time migration/bootstrap only — never as an
  authoritative runtime source. Membership in the authoritative
  `bingo_play_products` table overrides BR-032 compatibility metadata and keeps
  the product non-independently-purchasable in v1. (BR-039, BR-065, ADR-031)
- **Bingo Package** is the customer-facing entity a Hall Manager builds from Bingo
  Play Products and quantities (Paper, Digital, or Hybrid/Combo). Electronic-face
  contribution is calculated structurally as `faces_per_unit × quantity` per
  component — never by parsing a title. (Illustrative only: faces_per_unit = 3 and
  quantity = 12 gives 36 faces; the real per-product value must come from the
  authoritative source, never inferred from a title/naming pattern such as "3-on".)
  Package-scoped add-ons are shown/selectable only within an eligible parent
  package's selection context — never listed as independently purchasable normal
  catalog items — cannot be purchased without their eligible package, and remain
  rejected server-side even if UI controls are bypassed. They participate in the
  same electronic calculation as the package when digital. WooCommerce Product
  Bundles/Add-ons may be used as commerce/UI mechanisms where appropriate, but they
  are not the authoritative Bingo Package domain model, and BingoCommerce is not
  committed to those specific plugins ahead of the technical implementation design.
  BingoCommerce owns package identity, components, quantities, Game Type mapping,
  entitlement calculation, and add-on eligibility. Paper Packages contain at
  least one base component and only Paper base components; Digital Packages
  contain at least one Digital base component and no Paper base components;
  Hybrid Packages contain at least one Paper and one Digital base component.
  Optional add-ons never change the stored Package type. (BR-040, BR-061;
  BR-040 amends BR-021)
- **Bingo Package pricing** is separate from Package composition: every Package has
  its own Hall-Manager-set fixed base price, never auto-summed from included
  component product prices, and never automatically recalculated when components or
  quantities change. Eligible add-ons carry a price set specifically for their
  Package relationship (not the underlying reusable product's standalone price),
  multiplied by selected quantity. Bingo commercial total = package base price +
  Σ(add-on unit price × quantity); ordinary merchandise in the same order prices
  separately per normal WooCommerce rules and is not part of that total. The
  linked WooCommerce Package product's native price is the authoritative base
  price; the authoritative Package/add-on relationship stores its own unit price
  and maximum quantity. Add-ons default to maximum quantity 1 and permit larger
  quantities only when that exact relationship explicitly configures a larger
  maximum; selected quantity must satisfy
  `1 <= selected_quantity <= max_quantity`. (BR-049, BR-062, ADR-031)
- **Catalog authority and BR-032 compatibility projection:** under BR-065, the seven per-site
  relational Catalog/Session tables selected by ADR-031 are authoritative.
  `bc_product_kind` and `bc_package_relation` remain deterministic WooCommerce
  compatibility/cart-enforcement projections maintained by Catalog services.
  A Package row projects `bc_product_kind = package`. Permanent Package removal
  requires exactly one caller-selected disposition: delete the linked WooCommerce
  product, or retain/convert it to ordinary merchandise with
  `bc_product_kind = regular`; choosing neither is rejected. An enabled Package-
  add-on relationship count greater than zero projects
  `bc_package_relation = package_only_addon`, otherwise it projects
  `bc_package_relation = none`. Exact Package eligibility always comes from
  `bingo_package_addons`, and final cart/checkout enforcement always uses
  authoritative Catalog state. (BR-032, BR-065, ADR-031)
- Ordinary merchandise is never a Game Type, never participates in digital-
  entitlement calculations, never generates a Bingo QR, and may coexist with one
  Bingo Package in the same checkout — the 0/1-Bingo-Package-per-checkout rule is
  unchanged. (BR-032, BR-040)

## Electronic Card Limit
- There is no maximum orders/customers per Session and no aggregate Session-wide
  capacity counter — that model is retired. The **electronic card limit** applies
  independently per Game Type. Platform Admin sets a network-level platform maximum
  (currently 300, but configurable, never a hard-coded invariant); an authorized
  Hall Manager may set one optional per-site override for their hall, which can never effectively exceed the current
  platform maximum. Effective limit = `min(hall_override, current_platform_maximum)`
  if an override exists, else the current platform maximum — always evaluated
  against the platform maximum's *current* value, even if a hall's saved override
  predates a later platform-maximum decrease. Only Digital Bingo Play Products
  contribute; Paper always contributes zero. The current-value resolver belongs
  to `bingocommerce-catalog`. (BR-038, BR-064, ADR-031)
- At checkout, BingoCommerce aggregates digital faces from the selected package's
  components plus any selected eligible add-ons, by Game Type, and each Game Type's
  total must independently satisfy `total <= effective_hall_limit`. Paper never
  participates. Enforcement is authoritative server-side — UI-side calculation alone
  is never sufficient. Checkout no longer consumes a Session capacity/order slot.
  (BR-042)

## Session Domain
- A BingoCommerce Session is hall-owned/per-site; tenant context + Session ID is the
  canonical Session identity, with no network-wide Session registry needed in v1. A
  Session scopes exact structured calendar date, Session display name, hall, package
  availability, purchase validity, digital-ticket validity, redemption, and
  electronic accumulation for that play occurrence. A separately structured Session
  time is not required. Bingo Packages must be assignable/available for a specific
  Session. Sessions do not contain the retired session-wide capacity counter.
  (BR-041, BR-050 — BR-041 formally supersedes BR-002, BR-025, BR-031; BR-050
  supersedes only BR-041's earlier "scheduled date and time" wording regarding a
  separately structured Session time)
- **Calendar dating:** A Hall Manager schedules Sessions on specific calendar dates.
  A Session has an exact structured calendar date. A separate structured Session
  time is not required. Time may appear as part of the Session display name (for
  example, "Evening – 7:00 PM"); no business logic may parse that display-name
  time. Customers purchase against one specific dated Session. (BR-050)
- **Sales availability:** For Session date `D`, storefront date eligibility is
  inclusive: `D - 5 calendar days <= hall_local_today <= D`, using the hall
  site's configured WordPress timezone. A Hall Manager-controlled Available for
  Sale ON/OFF setting also applies. New Sessions default to OFF. The Session
  appears on the customer storefront only when BOTH the inclusive date predicate
  and Available for Sale = ON are true. Beginning the calendar day after `D`, the
  Session is no longer storefront-eligible.
  Turning Available for Sale ON does not bypass the 5-day window; turning it OFF
  prevents the Session from appearing for purchase. Week- or month-ahead
  future-Session browsing is not a current requirement. Do not invent an automatic
  sales-window close from text in the Session name. This sales boundary does not
  make an otherwise-valid QR automatically unusable when the Session starts.
  (BR-051, BR-056, BR-063)
- **Customer selection:** Customers see only Sessions currently eligible for sale.
  Selection must clearly identify the exact date and Session name. The customer
  selects the Session before selecting the Bingo Package. The order remains
  associated with that exact Session. (BR-052)

## Digital Entitlement & Redemption
- **Online activation:** For online purchases, the Bingo entitlement becomes active
  only after payment is successfully confirmed. Failed, cancelled, or unsuccessful
  payment does not create an active Bingo entitlement or QR. Do not hardcode a
  particular WooCommerce order-status name as the business rule — successful
  online payment confirmation activates entitlement creation. (BR-053;
  this resolves the previously open online entitlement-activation question)
- Entitlement snapshot is immutable: once an order becomes entitled, its frozen
  per-Game-Type digital entitlement is never recalculated from later, mutable
  product/package/Game Type configuration changes. (BR-043)
- QR scope: a Paper-only package produces no QR; a Digital package produces exactly
  one QR; a Hybrid/Combo package produces exactly one QR covering the digital
  entitlement only. Ordinary merchandise and paper components never enter the QR
  entitlement. The QR is a reference to server-authoritative state — QR-carried
  values are never themselves authoritative. Order loads onto exactly one Android
  device; a second device attempting redemption is blocked. (BR-044, amends BR-009)
- Manager ticket reactivation on device failure remains staff-gated only, logging
  actor, reason, previous device, and timestamp. (BR-010)
- Android redemption: `caller_id` is the tablet identity; accumulation is scoped by
  hall + session_id + caller_id + Game Type. Multiple valid Digital Packages for the
  same Session may be redeemed onto the same tablet. Electronic entitlement
  accumulates independently by Game Type. For every incoming Package:
  `existing tablet/session/Game-Type total + incoming Package contribution <=
  effective limit`. If any affected Game Type would exceed its effective limit,
  reject the entire incoming Package — no partial redemption. Example: Package 1
  Regular = 3, Winner Take All = 5, Hot = 5; Package 2 Regular = 4, Winner Take All
  = 4; accumulated Regular = 7, Winner Take All = 9, Hot = 5 — valid if each value
  remains within its individual Game Type limit. Exact Android wire-contract details
  (JSON fields, DTOs, game codes) are a separate, later, versionable integration
  decision. (BR-045, BR-055)
- **Late redemption:** A Session starting does not automatically make a valid QR
  unusable. Late customers may still redeem during the Session. Do not introduce a
  strict "must redeem before Session start" rule, and do not invent an automatic
  closing time from text contained in the Session name. (BR-056)
- Refunds: a Bingo Package cannot be partially refunded — it is refunded as a whole,
  and its package-scoped add-ons are refunded with it rather than independently in a
  way that would create entitlement inconsistency. A refund/cancellation of the Bingo
  Package (the bingo commercial unit) voids its associated digital entitlement — this
  entitlement-voiding is scoped to the Package, not to the WooCommerce order as a
  whole. Ordinary merchandise in the same order remains independently refundable per
  hall policy, and a merchandise-only refund in a mixed order must NOT void or alter
  the Bingo Package's digital entitlement. Refund handling is separate from Android
  redemption-limit enforcement. (BR-046, amends and narrows BR-011)

## Starter Catalog
- At hall provisioning, Bingo Innovation's starter WooCommerce products, starter
  Bingo Play Products, and starter Game Types are copied into the hall as hall-owned
  WooCommerce drafts / hall-local Game Types — a one-time copy, not ongoing
  synchronization. After provisioning, halls may edit, delete, publish, or add to
  their copies; future changes to Bingo Innovation's master starter catalog never
  silently modify an already-provisioned hall's catalog. (BR-047)

## Sales Reporting
- Keep purchase/payment date separate from Session attribution. Example: $4,000
  paid Monday for Tuesday's Session and $6,000 paid Tuesday for Tuesday's Session
  means daily transaction reporting is Monday = $4,000 collected and Tuesday =
  $6,000 collected, while Session reporting is Tuesday Session = $10,000 total
  Session sales. Do not move the Monday transaction onto Tuesday's
  payment/accounting date. (BR-054)

## Current-Implementation Scope Limits
- Detailed game-by-game player activity tracking is not required. Do not design
  storage for per-game play history as part of this feature. (BR-058)
- Do not expand the current implementation into separate jackpot or additional-game
  purchase flows. Current focus is Digital Package purchase and Package redemption.
  Additional separate purchase behavior can be considered later if required.
  (BR-059)

## Offline Usability (business expectation)
- Previously synchronized QR/Package information should remain usable at the hall
  if Internet connectivity is later lost. This is a business expectation only —
  do not invent the final local-server, cache, or network architecture here. Exact
  offline implementation remains a later technical design concern. (BR-060)

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
- **Amended by BR-040**: for Bingo Packages specifically, these WooCommerce tools
  may be used as commerce/UI mechanisms where appropriate, but BingoCommerce is not
  committed to them ahead of the technical implementation design that determines
  whether/how they should be used. BingoCommerce — not WooCommerce — owns package
  identity, components, quantities, Game Type mapping, entitlement calculation,
  and add-on eligibility. Do not describe WooCommerce Bundles/Add-ons as the
  authoritative Bingo Package domain model.

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
- Offline QR/Package usability **technical implementation** (local-server / cache /
  network architecture). The **business expectation** is locked by BR-060:
  previously synchronized QR/Package information should remain usable at the hall
  if Internet connectivity is later lost. Do not invent that architecture now.
- Final payment gateway / POS hardware provider
- Legal review of whether 90-day retention satisfies any actual financial/tax
  record-keeping requirement
The previously open **online entitlement-activation** question is resolved by
BR-053: successful online payment confirmation activates entitlement creation.
That item is no longer open.
