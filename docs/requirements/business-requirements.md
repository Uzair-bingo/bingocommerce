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

Portal Global Admin is an external Portal authority, not a fourth
BingoCommerce role. Its ADR-035/BR-075 authority is limited here to the future
platform-level `Enable BingoCommerce` decision; exact identity/role mapping is
deferred.

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
- **Game Type** is a first-class domain entity (e.g. Regular, Winner Take All,
  Red Hot), distinct from the WooCommerce product that sells it (e.g. "3-on
  Regular" is a product; "Regular" is its Game Type). The Portal is authoritative
  for Game Type identity/configuration, and Portal `game_types.id` is the trusted
  cross-system identity within the single authoritative Bingo Innovation Portal
  environment. Portal Game Type creation and rename are allowed; rename preserves
  `game_types.id`. Once created, a Portal Game Type cannot be deleted. Archive and
  Restore are not required in Portal v1. Name is editable and never identity. BingoCommerce
  retains its hall-scoped Game Type domain as a synchronized local commerce
  replica. Its existing surrogate ID and immutable machine code may remain local
  implementation identifiers, but neither replaces the Portal ID across systems.
  Existing local create/edit/archive/restore and currently disabled hard-delete
  behavior remains transitional BingoCommerce behavior—not the Portal v1
  lifecycle—and will be reconsidered when synchronization becomes authoritative.
  Game Type is never inferred from product title, SKU,
  tags or category. `device_group_id` remains hardware/XML/device-only and MUST
  NEVER be a BingoCommerce integration identity; XML-generated GameGroup
  identifiers MUST NEVER be BingoCommerce integration identities. Do not
  introduce an All Game Types bucket. (BR-037, BR-057,
  BR-071, BR-072, ADR-031, ADR-035)
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
- Within the single authoritative Bingo Innovation Portal environment, Portal
  Hall identity is `halls.id` and operational Session-template identity is
  `bingo_sessions.id`. A Portal Session belongs to its owning Hall through
  `bingo_sessions.hall_id`. Only Sessions satisfying
  `bingo_sessions.hall_id == mapped halls.id` are eligible for v1 commerce sync;
  received/shared Sessions and `allSessions()`-equivalent collections are
  excluded. This is an architectural eligibility rule, not an API query or
  schema implementation decision. (BR-071, BR-072, BR-073)
- Portal Session `days` are independent from BingoCommerce Commerce scheduling
  in v1. They MUST NOT drive Commerce scheduling, generate Commerce occurrences,
  or constrain or validate Commerce scheduling. Future advisory validation MAY
  be introduced only by a separately approved future requirement. (BR-073)
- BingoCommerce separately owns a commerce schedule—commerce weekdays, recurring
  yes/no, recurrence start/end dates, specific non-recurring dates, and structured
  commerce time—and the exact dated commerce occurrences generated from it.
  Structured Commerce time is owned by BingoCommerce and may be entered manually
  by the Hall Manager in v1. It is never parsed from a human display name;
  database columns, UI controls, storage schema, and timezone implementation are
  deferred. A
  customer purchases against one exact occurrence, and the order retains that
  occurrence identity. Package availability, purchase validity, future entitlement
  state, and electronic accumulation attach to that occurrence. Display names are
  labels only and no business logic may parse them for time. (BR-041, BR-050,
  BR-052, BR-073; ADR-035 narrows the earlier BingoCommerce Session-identity
  language and BR-073 supersedes only BR-050's no-structured-time provision)
- The existing `bingo_sessions` / `bingo_session_packages` implementation remains
  in place until a schema-specific compatibility/refactoring review determines how
  it maps to the template/schedule/occurrence model. This requirement does not
  authorize deleting the tables, destructively migrating them, or discarding
  compatible Package assignments. Sessions/occurrences do not contain the retired
  session-wide capacity counter. (BR-073, ADR-035)
- One Package may be assigned to multiple dated commerce occurrences. No one-
  Package-to-one-occurrence restriction is introduced. Existing Package/Session
  relationship work remains preserved where compatible. (BR-073, ADR-035)
- **Sales availability:** For dated commerce occurrence date `D`, storefront
  date eligibility is inclusive:
  `D - 5 calendar days <= hall_local_today <= D`, using the hall
  site's configured WordPress timezone. A Hall Manager-controlled Available for
  Sale ON/OFF setting also applies. In the implemented current model, new Sessions
  default to OFF; occurrence-generation defaults remain part of the compatibility
  review. The dated occurrence appears on the customer storefront only when BOTH
  the inclusive date predicate
  and Available for Sale = ON are true. Beginning the calendar day after `D`, the
  Session is no longer storefront-eligible.
  Turning Available for Sale ON does not bypass the 5-day window; turning it OFF
  prevents the Session from appearing for purchase. Week- or month-ahead
  future-occurrence browsing is not a current requirement. Do not invent an
  automatic sales-window close from display-name text. This sales boundary does
  not make an otherwise-valid QR automatically unusable when the occurrence starts.
  (BR-051, BR-056, BR-063)
- **Customer selection:** Customers see only dated occurrences currently eligible
  for sale. Selection must clearly identify the exact date and Session name. The
  customer selects the Session before selecting the Bingo Package. The order remains
  associated with that exact dated occurrence. (BR-052, BR-073)

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
  Bingo Play Products, and their WooCommerce commerce shells remain a one-time,
  hall-owned copy. The Game Type portion of that older model is superseded: Portal
  Game Types are synchronized hall-scoped replicas keyed across systems by Portal
  `game_types.id`, not independently editable one-time master copies. Existing local
  Game Type behavior remains transitional until synchronization is proven. (BR-047,
  BR-072; ADR-027/ADR-035)
- The unaffected starter-copy rules remain in force: a Hall may edit, delete, and
  publish its WooCommerce product copies according to the existing approved
  rules; a Hall may create additional Bingo Play Products; and a Hall may create
  Packages. The Portal authority change supersedes only the former Game Type
  starter-copy model. (BR-047, ADR-035)

## Portal Integration (approved; not implemented)
- Within the single authoritative Bingo Innovation Portal environment,
  `halls.id` maps to WordPress Site ID, `game_types.id` identifies a Game Type,
  and `bingo_sessions.id` identifies an operational Session template. Names,
  subdomains, and domains are not cross-system identity. Exact mapping
  persistence and provisioning lifecycle are deferred.
  (BR-072)
- Approved integration shape: authenticated Portal read API, initial/full pull,
  proven idempotent mapping, Portal webhooks, reconciliation, tenant provisioning,
  then SSO. Individual Games, Patterns, Caller/device data, Portal users, Portal
  Session `days`, `device_group_id`, and XML GameGroup IDs are excluded from v1
  sync. (BR-074, ADR-035)
- `Enable BingoCommerce` is a separate Portal Global Admin-only platform
  capability and does not inherit ordinary Hall Settings authorization. Hall
  Managers/Admins may manage commerce only after enablement. Future SSO is a
  short-lived secure handoff into the correct mapped tenant, but is not in the
  first integration increment. (BR-075)
- API/webhook contracts, authentication, mapping persistence, reconciliation,
  provisioning, recurrence expansion/timezone/DST behavior, and SSO protocol are
  deferred to dedicated implementation/security designs. No API, webhook, schema,
  provisioning, or SSO implementation is authorized by these requirements.

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
- Portal integration implementation details: mapping persistence; Portal API
  authentication and contract; webhook signature/retry/order behavior;
  reconciliation/conflict policy; recurrence expansion horizon and timezone/DST
  rules; enablement/provisioning lifecycle; and SSO protocol/account mapping.
- Schema-specific compatibility/refactoring plan for the implemented local Game
  Type and Session models. Do not assume either domain or the existing Session
  tables are deleted or replaced.
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
