# AGENTS.md — BingoCommerce Master AI Guidelines

Read this file FIRST, every session. It is the single source of truth for what this project is and how we build it. If a prompt or request conflicts with this file or with files in `docs/decisions/`, STOP and ask before proceeding.

---

## 1. System Overview
BingoCommerce is a hosted multi-tenant e-commerce platform for bingo halls, churches, and similar venues, built for **Bingo Innovation**. 

- **Not a Marketplace:** Each hall operates its own fully independent WooCommerce store on a subdomain (`hallname.parent-domain.com`).
- **Cart & Order Isolation:** One order belongs to exactly ONE hall. Cross-hall shopping carts are strictly prohibited.
- **Products:** Halls sell physical goods, food, merchandise, and digital bingo packages.
- **Digital Entitlement:** A digital purchase produces **ONE QR code per order**, which is redeemed onto **ONE Android device** at the hall entrance. Staff can perform a manager reactivation on device failure.

---

## 2. Confirmed Application Architecture

### Confirmed
- WordPress Multisite
- Subdomain mode
- WooCommerce
- Custom BingoCommerce plugins
- Custom indexed tables where appropriate
- Redis persistent object caching approach (ADR-007)
- S3-compatible object storage approach (ADR-009)

### Provider / Infrastructure Still Open
- Redis managed-service provider
- S3-compatible storage provider
- CDN provider
- Overall production hosting provider

---

## 3. Directory & Code Boundaries
This root directory contains the WordPress installation. **WordPress Core is git-ignored and READ-ONLY.**

Custom code lives ONLY in:
- `wp-content/plugins/bingocommerce-*` — Custom feature plugins (all business logic)
- `wp-content/mu-plugins/bingocommerce-*` — Reserved for narrowly scoped always-on platform guards only when explicitly approved by architecture; feature/domain business logic remains in normal `bingocommerce-*` plugins.
- `wp-content/themes/bingocommerce-*` — Phase 1 standard storefront theme
- `docs/` — Architecture, requirements, decisions, and runbooks (**NEVER** deployed to production)

---

## 4. Roles & Permissions Model
- **Game Operator:** Floor/session role only. Can scan and redeem digital QR receipts, lookup redemptions, and execute staff-gated ticket reactivations. Zero access to pricing, products, or billing settings.
- **Hall Manager:** Runs one or more assigned hall stores. Manages products, pricing, inventory, sessions, orders, staff, refunds, and approved storefront customization. May edit the provided hall theme's fonts, styles, navigation, pages, and layouts, but cannot install/upload/switch themes, install plugins, or alter network configuration.
- **Platform Admin:** Bingo Innovation network-wide administrator. Provision halls, edit any hall store, manage platform billing status, manage product templates, and oversee data retention.

---

## 5. Non-Negotiable Rules (Violations Reject the Work)

### Core Safeguards
1. **Zero Core Modifications:** NEVER edit `wp-admin/`, `wp-includes/`, WooCommerce core, or third-party plugin source files.
2. **No Logic in Themes:** Business logic belongs strictly in `wp-content/plugins/` or `mu-plugins/`. Themes only render presentation layers.
3. **No Unapproved Plugins:** Do not install new third-party plugins without explicit architectural approval.

### Database & Security
4. **Prepared Queries Only:** Every direct SQL query MUST use `$wpdb->prepare()`. No raw string interpolation into SQL, ever.
5. **REST API Hardening:** Every custom REST API endpoint MUST include an explicit `permission_callback` checking `current_user_can()` (or verifying valid Application Passwords) AND a `sanitize_callback` on every input parameter.
6. **Hall Isolation:** Every hall-scoped query, cache key, database table row, and file path MUST include the `site_id` / `blog_id`. Cross-hall data leakage is the highest severity bug in this platform.
7. **No Raw Card Storage:** Credit card details must NEVER transit or touch our application server unencrypted. Gateway hosted fields / SDKs only.

### Architecture & Operations
8. **Custom Tables over Postmeta:** Operational data (sessions, digital entitlements, device registries, audit events, billing logs) lives in custom indexed tables—NEVER in unindexed `postmeta`.
9. **Atomic Capacity Enforcement:** Session digital capacity (e.g., maximum 300 orders) must be validated server-side during checkout via atomic database locks.
10. **Tenant-Safe Object Caching:** If persistent object caching is enabled, cache keys and invalidation behavior MUST remain tenant-safe in Multisite. A hall-scoped cache-clear operation must never invalidate unrelated halls. Exact Redis/plugin/provider configuration must be verified against the selected implementation before production use.
11. **No Caching on Dynamic Pages:** Never full-page cache `/cart`, `/checkout`, `/my-account`, QR redemption endpoints, or admin dashboards.

---

## 6. Naming Conventions
- **Plugin Slugs:** `bingocommerce-*` (e.g., `bingocommerce-core`, `bingocommerce-digital-tickets`)
- **PHP Namespace:** `BingoCommerce\<PluginName>` (e.g., `BingoCommerce\DigitalTickets`)
- **Functions & Hooks:** Namespaced class methods (`BingoCommerce\<PluginName>\ClassName::method()`)
  are the standard — no `bingocommerce_` prefix needed on method names, since the namespace
  already prevents collisions. The `bingocommerce_` prefix still applies to any literal
  WordPress hook name string passed to `add_action()`/`add_filter()`, and to any standalone
  procedural function (rare — only where a class doesn't fit).
- **Custom DB Tables:** `$wpdb->prefix . '<name>'` for per-site tables; `$wpdb->base_prefix . '<name>'` for network-wide tables.
- **Options & Meta Keys:** `bc_` prefix

---

## 7. AI Agent Execution Protocol
1. **Consult Docs First:** Before implementing any feature, check `docs/requirements/`, `docs/architecture/`, and `docs/decisions/`.
2. **Ask on Ambiguity:** If a business rule or requirement is ambiguous, STOP and ask before proceeding. Do not guess or invent logic.
3. **Small Commits:** Make focused, incremental changes. Explain implementation plans before touching more than 2 files.
4. **Update Status:** Upon completing a task or sprint item, update `PROJECT_STATUS.md`.