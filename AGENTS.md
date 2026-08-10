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

### Planned / Infrastructure-Dependent
- Persistent object cache such as Redis
- S3-compatible object storage
- CDN

---

## 3. Directory & Code Boundaries
This root directory contains the WordPress installation. **WordPress Core is git-ignored and READ-ONLY.**

Custom code lives ONLY in:
- `wp-content/plugins/bingocommerce-*` — Custom feature plugins (all business logic)
- `wp-content/mu-plugins/bingocommerce-*` — Must-Use platform guards (capacity, billing, isolation)
- `wp-content/themes/bingocommerce-*` — Phase 1 standard storefront theme
- `docs/` — Architecture, requirements, decisions, and runbooks (**NEVER** deployed to production)

---

## 4. Roles & Permissions Model
- **Game Operator:** Floor/session role only. Can scan and redeem digital QR receipts, lookup redemptions, and execute staff-gated ticket reactivations. Zero access to pricing, products, or billing settings.
- **Hall Manager:** Runs one or more assigned hall stores. Manages products, pricing, inventory, sessions, orders, staff, and refunds. Swaps contexts using a multi-hall switcher. Cannot install plugins or alter network configuration.
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
10. **Redis Selective Flush:** All caching code must respect `WP_REDIS_SELECTIVE_FLUSH` and use unique site prefixes (`WP_REDIS_PREFIX`). Global cache flushes are strictly forbidden.
11. **No Caching on Dynamic Pages:** Never full-page cache `/cart`, `/checkout`, `/my-account`, QR redemption endpoints, or admin dashboards.

---

## 6. Naming Conventions
- **Plugin Slugs:** `bingocommerce-*` (e.g., `bingocommerce-core`, `bingocommerce-digital-tickets`)
- **PHP Namespace:** `BingoCommerce\<PluginName>` (e.g., `BingoCommerce\DigitalTickets`)
- **Functions & Hooks:** `bingocommerce_` prefix
- **Custom DB Tables:** `$wpdb->prefix . '<name>'` for per-site tables; `$wpdb->base_prefix . '<name>'` for network-wide tables.
- **Options & Meta Keys:** `bc_` prefix

---

## 7. AI Agent Execution Protocol
1. **Consult Docs First:** Before implementing any feature, check `docs/requirements/`, `docs/architecture/`, and `docs/decisions/`.
2. **Ask on Ambiguity:** If a business rule or requirement is ambiguous, STOP and ask before proceeding. Do not guess or invent logic.
3. **Small Commits:** Make focused, incremental changes. Explain implementation plans before touching more than 2 files.
4. **Update Status:** Upon completing a task or sprint item, update `PROJECT_STATUS.md`.