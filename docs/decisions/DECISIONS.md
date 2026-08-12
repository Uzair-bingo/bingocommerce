# DECISIONS.md — BingoCommerce Decision Log

Append-only log. Every entry here is locked — do not silently revise an entry through code changes. If a decision needs to change, add a new entry that explicitly supersedes the old one; never edit history in place.

**Format:** `[ID] [Date] Decision — Rationale`

---

## Architecture Decisions (ADR)

- **ADR-001 — [2026-08-07] — Stack:** WordPress Multisite (subdomain mode) + WooCommerce with HPOS, one hall = one site, shared codebase and database.
  *Rationale:* Each hall must behave like an independent store (own products, orders, customers, taxes) while allowing Platform Admin oversight across all of them. Superseded an earlier single-install + Dokan/multi-vendor approach once requirements confirmed no shared catalog or cart across halls.
- **ADR-002 — [2026-08-07] — Multi-Tenancy Data Model:** Shared database with tenant-scoped rows (`site_id`/`blog_id`), NOT database-per-hall.
  *Rationale:* Database-per-tenant breaks native cross-hall Platform Admin reporting queries and is unnecessary at the target scale.
- **ADR-003 — [2026-08-07] — Domain Routing:** Wildcard subdomain (`hallname.parent-domain.com`) via WordPress Multisite native subdomain mode. No custom routing layer needed.
- **ADR-004 — [2026-08-07] — Order Storage Engine:** WooCommerce HPOS (High-Performance Order Storage) enabled from day one; legacy post-meta order storage is disabled.
- **ADR-005 — [2026-08-07] — Operational Data Storage:** Domain operational data (digital tickets, session capacity, billing status logs, device registries) lives in custom indexed database tables, never in unindexed `postmeta`.
- **ADR-006 — [2026-08-07] — Digital Capacity Enforcement:** Two-tier model — per-package limits via WooCommerce native stock; session-wide shared capacity (e.g. 300 orders) via a custom indexed table counting paid orders, validated atomically at checkout.
- **ADR-007 — [2026-08-07] — Redis Object Cache Guardrail:** Object caching requires `WP_REDIS_SELECTIVE_FLUSH` and a distinct `WP_REDIS_PREFIX` per site.
  *Rationale:* Default Redis plugin behavior flushes the entire cache network-wide on any single hall cache-clear event—unacceptable at multi-tenant scale.
- **ADR-008 — [2026-08-07] — Full-Page Caching Boundaries:** Full-page caching never applies to cart, checkout, account, QR-redemption endpoints, or admin dashboards. Object cache (Redis) handles dynamic views.
- **ADR-009 — [2026-08-07] — Media Offloading:** Product images offloaded to S3-compatible object storage (AWS S3, Cloudflare R2, or DigitalOcean Spaces) served via CDN. Specific provider TBD—do not hardcode vendor-specific SDKs.
- **ADR-010 — [2026-08-07] — Payment & POS Integration Layer:** WooCommerce native pluggable gateway API—never hardcode a payment provider. External POS integration via WooCommerce REST API with provider-specific adapters built reactively.
- **ADR-011 — [2026-08-07] — API Authentication Standards:** WooCommerce Consumer Keys (scoped Read/Write) for WooCommerce integrations; WordPress Core Application Passwords for non-WooCommerce REST integrations. Every custom REST endpoint requires explicit `permission_callback` and `sanitize_callback`.
- **ADR-012 — [2026-08-07] — Human Authentication:** Mandatory MFA/TOTP for all Hall Manager, Game Operator, and Platform Admin accounts.
- **ADR-013 — [2026-08-07] — Hosting Requirements:** Hosting must explicitly support WordPress Multisite, managed Redis, isolated staging environment, and automated point-in-time backups.
- **ADR-014 — [2026-08-08] — Repository Structure:** WordPress Core is git-ignored and never edited. Custom code lives ONLY in `wp-content/plugins/bingocommerce-*`, `wp-content/mu-plugins/bingocommerce-*`, and `wp-content/themes/bingocommerce-*`.
- **ADR-015 — [2026-08-08] — Naming Conventions:** Plugin slugs `bingocommerce-*`; PHP namespace `BingoCommerce\<PluginName>`; functions/hooks prefixed `bingocommerce_`; custom DB tables `$wpdb->prefix . 'bc_<name>'` (per-site) or `$wpdb->base_prefix . 'bc_<name>'` (network-wide).
- **ADR-016 — [2026-08-08] — AI Agent Memory Architecture:** `AGENTS.md` is the single shared source of truth read natively by Cursor, Claude Code, and CodeX.
- **ADR-017 — [2026-08-08] — Confirmed Local Dev Stack:** PHP 8.4.14, MySQL 9.5, WordPress 7.0.3.
- **ADR-018 — [2026-08-10] — WordPress Table Prefix & Custom Table Naming:**
  WordPress table prefix is `bc_` instead of the default `wp_`.
  Custom per-site tables use `$wpdb->prefix . '<name>'` and network-wide tables
  use `$wpdb->base_prefix . '<name>'`, with no additional `bc_` sub-prefix.
  This supersedes only the custom-table-naming portion of ADR-015; all other
  ADR-015 naming conventions remain in force.
  *Rationale:* The configured WordPress prefix already provides the `bc_`
  namespace, avoiding redundant names such as `bc_bc_sessions`.
- **ADR-019 — [2026-08-10] — WooCommerce Network Activation:**
  WooCommerce is installed and network-activated across the BingoCommerce
  Multisite network. Hall sites are commerce stores by platform design;
  individual hall administrators cannot activate or deactivate WooCommerce.
  The primary network site may expose WooCommerce internally but is not
  required to operate as a customer-facing storefront.
  *Rationale:* Commerce is a mandatory platform capability for every hall,
  and centralized activation prevents configuration drift between hall sites.
- **ADR-020** — [2026-08-11] — Hall Role Provisioning Mechanism: versioned,
  idempotent capability reconciliation via a network-level and per-site
  version option (not register_activation_hook, not destructive
  remove_role()/add_role()). Existing sites sync via admin_init; new sites
  sync via wp_initialize_site (priority 20), with self-healing for
  WooCommerce Shop Manager's lazy initialization timing. Every sync
  converges each role to its exact desired capability set — additions AND
  removals — preventing capability drift over time.
- **ADR-021 — [2026-08-12] — PHP Method & Hook Naming:**
  Namespaced class methods under `BingoCommerce\<PluginName>` do not require a
  `bingocommerce_` method-name prefix because the namespace already prevents
  collisions. The `bingocommerce_` prefix remains mandatory for custom
  WordPress hook-name strings and for rare standalone procedural functions.
  This supersedes only the function/method naming portion of ADR-015; all other
  ADR-015 naming conventions remain in force.
- **ADR-022 — [2026-08-12] — Multisite Data Model (supersedes ADR-002):**
  BingoCommerce uses one shared MySQL database with native WordPress Multisite
  table separation. Hall-owned WordPress and WooCommerce data is stored in
  per-site table sets (for example `bc_{blog_id}_posts` and
  `bc_{blog_id}_wc_orders`), while WordPress network identity and registry data
  uses shared network tables such as `bc_users`, `bc_usermeta`, `bc_blogs`,
  `bc_site`, and `bc_sitemeta`.

  Custom BingoCommerce tables follow the same ownership principle:
  hall-owned operational data is per-site unless there is a documented reason
  for network-wide storage; genuinely network-level data may use network-wide
  tables with an indexed `blog_id`/`site_id` where tenant attribution is
  required.

  BingoCommerce does NOT use database-per-hall.

  This supersedes ADR-002.
---

## Business Rules (BR)

- **BR-001 — [2026-08-07] — Customer Accounts & Payments:** Customer address book is shared across a customer's accounts at different halls; payment methods and tokens stay strictly isolated per hall.
- **BR-002 — [2026-08-07] — Session Capacity Definition:** Session capacity = total qualifying digital orders sold for that session, summed across every package type tied to it. Configurable, never hardcoded.
- **BR-003 — [2026-08-07] — Store Independence:** Each hall is a fully independent store—own pricing, inventory, taxes, and fulfillment. No central marketplace catalog. One order belongs to exactly one hall; no cross-hall carts.
- **BR-004 — [2026-08-07] — Direct Merchant Payments:** Each hall receives customer payments directly into its own merchant account. Platform billing to halls occurs via separate offline invoices.
- **BR-005 — [2026-08-07] — Physical Fulfillment:** Decentralized per hall. Each hall stocks, ships, and manages physical inventory independently.
- **BR-006 — [2026-08-07] — Catalog Freedom:** General-purpose product support (bingo supplies, apparel, food). New hall products go live immediately without Platform Admin approval gates.
- **BR-007 — [2026-08-07] — Customer Checkout:** Guest checkout is the primary path. Registered accounts use one shared credential across halls, but order history remains separated per hall.
- **BR-008 — [2026-08-07] — Verification Scope:** No phone or email OTP verification required for guest digital purchases. Email is used only to deliver receipts/QR codes.
- **BR-009 — [2026-08-07] — Digital Entitlement Model:** One QR code represents the entire digital order. The order binds to exactly ONE Android device; a second device attempting scan is blocked.
- **BR-010 — [2026-08-07] — Manager Ticket Reactivation:** Device resets are staff-gated only (Game Operator / Hall Manager). Every reset logs the staff actor, reason, previous device, and timestamp.
- **BR-011 — [2026-08-07] — Refund & Cancellation Policy:** Session refunds are managed directly between hall and customer. Refunding an order automatically voids the associated digital QR entitlement.
- **BR-012 — [2026-08-07] — POS Strategy:** Halls may use existing POS systems. Optional Bingo Innovation "Caller + POS" package (Clover under evaluation) offered separately.
- **BR-013 — [2026-08-07] — Gaming License Responsibility:** Platform Terms of Service assign all gaming compliance and legal licensing responsibility to the hall; no pre-onboarding license verification gate.
- **BR-014 — [2026-08-07] — Operational Target Scale:** Target scale is 20–30 active halls initially, scaling toward 100+ over time.
- **BR-015 — [2026-08-07] — Custom Domains:** Custom domain mapping (e.g. `sunrisebingo.com`) is deferred to Phase 2.
- **BR-016 — [2026-08-07] — Graduated Nonpayment Lifecycle:** 5-stage billing status: `Active` → `Overdue` (warning, store works) → `Restricted` (cannot publish products/accept orders) → `Suspended` (store closed, staff locked) → `Terminated` (archived). Default 60-day timeline.
- **BR-017 — [2026-08-07] — Terminated Data Retention:** Terminated hall data retained 90 days before deletion, with warnings sent at 30, 15, and 5 days. Data export requires Platform Admin approval.
- **BR-018 — [2026-08-08] — User Roles:** Three roles — `Hall Manager` (store management), `Game Operator` (QR redemption & reset), `Platform Admin` (network management).
- **BR-019 — [2026-08-08] — Multi-Hall Manager Switcher:** One Hall Manager credential can access and switch between multiple assigned halls via native "My Sites" context.
- **BR-020 — [2026-08-08] — Dashboard Permissions:** Plugin and theme installation is disabled for Hall Manager and Game Operator roles.
- **BR-021 — [2026-08-08] — Product Packaging Tools:** WooCommerce Product Add-ons used for tied product extras; WooCommerce Product Bundles used for predefined multi-item packages.
- **BR-022 — [2026-08-08] — Storefront Templates:** Phase 2 template library uses block-theme starter site imports (one-time copy into hall site).
- **BR-023 — [2026-08-08] — Platform Admin Override:** Platform Admins can create, edit, and publish products directly inside any hall store.
- **BR-024 — [2026-08-08] — Onboarding Modes:** Supports both self-service hall onboarding and assisted setup by Bingo Innovation.
- **BR-025 — [2026-08-10] — Session Capacity Canonical Definition:** A qualifying order is a completed order containing at least one digital package for that session. Multiple digital packages in one order still count as ONE qualifying order. Supersedes the looser summation language in BR-002.
- **BR-026 — [2026-08-10] — Super Admin Access Restriction:** Phase 1 Platform Admin authority is exercised only by trusted native WordPress Super Admins. Hall Manager and Game Operator must never receive Super Admin. If a restricted operational-admin tier is needed later, implement it with explicit `bc_*` capabilities/UI — not broader network privileges.
- **BR-027** — [2026-08-11] — Hall Role Capability Model (ratified after
manual authorization testing across Sunrise, Tampa, and Orlando):
bc_hall_manager = live WooCommerce Shop Manager baseline (synced, not
hardcoded) minus prohibited capabilities, plus bc_manage_hall,
bc_manage_hall_staff, bc_manage_hall_settings, bc_manage_products,
bc_manage_orders, bc_manage_sessions, bc_view_orders, bc_view_sessions,
bc_redeem_tickets, bc_reactivate_tickets. bc_game_operator = read only,
plus bc_view_orders, bc_view_sessions, bc_redeem_tickets,
bc_reactivate_tickets — no native management capabilities. Both roles are
unconditionally stripped of: install/activate/delete/update/edit_plugins,
install/switch/edit/delete/update_themes, manage_network*, promote_users,
delete_users.
- **BR-028 — [2026-08-12] — Cross-Hall Address Reuse:**
  A registered customer's previously used addresses may be suggested/reused
  across hall storefronts to reduce data entry, but each hall stores its own
  copy for commerce/order records. Updating an address in one hall must not
  silently rewrite another hall's stored customer/order data.
  This supersedes the "shared address book" portion of BR-001; payment
  methods/tokens remain hall-isolated.
- **BR-029 — [2026-08-12] — Hall Storefront Customization:**
  Hall Managers may customize their hall's approved BingoCommerce storefront,
  including fonts, styles, navigation, pages, and provided theme/template
  layouts. They may not install, upload, switch, delete, or introduce
  unapproved themes, plugins, or executable platform code.
- **BR-030 — [2026-08-12] — Full Hall Lifecycle States (extends BR-016 and BR-017):**
  The complete hall lifecycle is:

  Active → Overdue → Restricted → Suspended → Terminated →
  Pending Deletion → Deleted.
  `Pending Deletion` means permanent deletion has been scheduled but has not
  yet occurred. A Platform Admin may pause or extend the deletion schedule in
  accordance with retention policy.
  `Deleted` means the hall's deletable operational data has been permanently
  removed following the required retention period and administrative checks.
  Deletion completion or failure must be recorded and surfaced to Platform
  Admin.
  A hall's billing/lifecycle state alone must not invalidate digital tickets
  already purchased by customers before restriction or suspension. Such
  tickets remain subject to their normal session, refund, cancellation,
  redemption, and entitlement-validity rules.
  
- **BR-031 — [2026-08-12] — Session Capacity State Transitions (extends BR-025):**
  A qualifying order consumes one capacity slot in each session for which that
  order contains at least one qualifying digital package. Therefore, an order
  containing qualifying packages for multiple sessions consumes one slot in
  EACH applicable session.

  Pending, failed, abandoned, or otherwise non-completed orders do not consume
  session capacity.

  If a qualifying order is fully refunded or cancelled before the applicable
  session's capacity can no longer be resold, its capacity reservation for that
  session is released. Refunds or cancellations occurring after the session is
  no longer eligible for sale must remain visible in historical reporting and
  must not retroactively distort the session's historical capacity usage.

- **BR-032 — [2026-08-12] — Package Transaction Limit:**
  A customer may purchase at most one BingoCommerce package in a single
  customer checkout transaction, regardless of package type (paper,
  electronic/computer, or hybrid).

  The customer cart may contain no more than one product classified as a
  package at any time. If a package is already present, adding another package
  is blocked. A customer may remove the existing package and select a different
  package before checkout.

  Package identity is determined by explicit BingoCommerce product metadata
  and is NOT inferred from WooCommerce categories, titles, tags, or cart
  contents:

  - `bc_product_kind = package | regular`
    Defines whether the independently purchasable product is a BingoCommerce
    package.

  - `bc_package_relation = none | package_only_addon`
    Defines whether an independently represented product may be purchased
    without an eligible package.

  A WooCommerce category such as "Bingo Packages" may be used for catalog
  organization, merchandising, filtering, or reporting, but is never the
  authoritative source for package transaction enforcement.

  A product with `bc_product_kind = package` cannot be added when another
  package is already present in the customer cart.

  A product with `bc_package_relation = package_only_addon` may be purchased
  only while an eligible package is present in the cart. Where an add-on is
  limited to specific packages, BingoCommerce must maintain an explicit
  package-to-add-on eligibility relationship rather than assuming that the
  presence of any package is sufficient.

  The cart and checkout must never remain in an invalid state. If removal or
  replacement of a package causes a package-only add-on to lose its eligible
  parent package, the dependent item must be removed or checkout must be
  prevented until the invalid item is resolved.

  Regular merchandise
  (`bc_product_kind = regular`, `bc_package_relation = none`) may be purchased
  independently and may coexist with one package in the same cart.

  The same physical merchandise may be represented by separate SKUs when the
  business requires different purchase behavior. For example, a standard
  dauber may be independently purchasable while a separate promotional or
  package-specific dauber SKU may be restricted to an eligible package.

  For a WooCommerce Bundle that itself represents the BingoCommerce package,
  the bundle/container product carries `bc_product_kind = package`. Internal
  bundle components do not count as additional packages merely because they
  appear as components of that package. Independently purchasable component
  products retain their own BingoCommerce classification.

  Tied WooCommerce Product Add-ons configured as options on the package product
  are part of that package selection and do not count as independent packages
  for this transaction-limit rule.

  This rule applies to the normal customer cart and checkout flow and must be
  enforced server-side as well as reflected in the storefront UI.

  This supersedes only the assumption in BR-025 that multiple independently
  purchased package products may normally occur within a single customer
  checkout. BR-025's capacity rule remains defensive and authoritative for
  capacity accounting: a qualifying order consumes capacity according to the
  session(s) represented by its qualifying digital entitlements, including
  imported, administratively created, or legacy orders if such data exists.
---

## Still Open / TBD Items (Unresolved)

- Offline QR redemption handling during hall internet outage.
- Final online payment gateway selection.
- POS hardware partner selection (Clover under evaluation).
- Object storage provider selection (AWS S3 vs. Cloudflare R2).
- Production hosting provider selection.
- Production parent domain purchase.
- Legal review of 90-day financial record retention safety.