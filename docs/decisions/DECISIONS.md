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
**BR-025** — [2026-08-10] — Session Capacity Canonical Definition: a
qualifying order is a completed order containing at least one digital
package for that session. Multiple digital packages in one order still
count as ONE qualifying order. Supersedes the looser summation language in
BR-002.
**BR-026** — [2026-08-10] — Super Admin Access Restriction: Platform Admin
(native WordPress Super Admin) access is restricted to trusted platform
operators only. Hall Manager and Game Operator roles must never be granted
Super Admin. Any future restricted operational-admin tier is implemented
via explicit bc_ capabilities, not broader network privileges.

---

## Still Open / TBD Items (Unresolved)

- Offline QR redemption handling during hall internet outage.
- Final online payment gateway selection.
- POS hardware partner selection (Clover under evaluation).
- Object storage provider selection (AWS S3 vs. Cloudflare R2).
- Production hosting provider selection.
- Production parent domain purchase.
- Legal review of 90-day financial record retention safety.