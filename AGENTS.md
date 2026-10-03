# AGENTS.md — BingoCommerce Master AI Guidelines

Read this file FIRST, every session. It is the single source of truth for what this project is and how we build it. If a prompt or request conflicts with this file or with files in `docs/decisions/`, STOP and ask before proceeding.

---

## 1. System Overview
BingoCommerce is a hosted multi-tenant e-commerce platform for bingo halls, churches, and similar venues, built for **Bingo Innovation**. 

- **Not a Marketplace:** Each hall operates its own fully independent WooCommerce store on a subdomain (`hallname.parent-domain.com`).
- **Cart & Order Isolation:** One order belongs to exactly ONE hall. Cross-hall shopping carts are strictly prohibited.
- **Products:** Halls sell physical goods, food, merchandise, and Bingo Packages (Paper, Digital, or Hybrid/Combo) built from Bingo Play Products. Within the single authoritative Bingo Innovation Portal environment, `game_types.id` is trusted cross-system Game Type identity. Portal Game Types may be created and renamed; rename preserves `game_types.id`; deletion is prohibited once created; Portal Archive/Restore is not required in v1. Names are attributes, never identity. `device_group_id` remains hardware/XML/device-only and MUST NEVER be a BingoCommerce integration identity; XML-generated GameGroup identifiers likewise MUST NEVER be integration identities (BR-037, BR-039, BR-072). BingoCommerce retains a hall-scoped synchronized replica; its current local create/edit/archive/restore and disabled hard-delete behavior is transitional until sync is authoritative (ADR-035). Do not introduce an All Game Types bucket (BR-057). ADR-031's per-site Catalog tables remain authoritative for BingoCommerce Packages and Play Products; linked WooCommerce products are commerce shells, and BR-032 product metadata is only a required derived compatibility/cart-enforcement projection under BR-065.
- **Sessions:** Within that Portal environment, `halls.id` is Hall identity and `bingo_sessions.id` is operational Session-template identity. Only owned Sessions satisfying `bingo_sessions.hall_id == mapped halls.id` may sync in v1; received/shared Sessions and `allSessions()` semantics are excluded. Portal Session `days` are independent from Commerce scheduling and MUST NOT drive scheduling, generate occurrences, or constrain/validate scheduling in v1; future advisory validation requires a separately approved requirement. BingoCommerce owns the distinct commerce schedule, including structured Commerce time that a Hall Manager may enter manually in v1, and exact dated commerce occurrences. A schedule captures the Hall's named WordPress timezone when created, and that timezone is immutable for the schedule's lifetime. Time MUST NOT be parsed from display names. One Package may be assigned to multiple occurrences. For occurrence date `D`, eligibility is `D - 30 calendar days <= hall-local today <= D`, Available for Sale = ON, and—when schedule-managed—membership in the schedule's current committed definition; the order stays associated with that exact occurrence (BR-051–BR-052, BR-063, BR-073, BR-077–BR-078). Removing a strictly future date is allowed while its occurrence is ON: membership immediately makes it ineligible, a successful edit turns it OFF, and Upcoming and administration must omit/refuse Enable Sales. Re-adding a retained occurrence preserves its identity and Package assignments but leaves sales OFF until explicitly enabled. Existing reconciliation, withdrawal, sale-lock, archival and historical-retention rules remain unchanged. The additive Session schedule/occurrence implementation exists in the current repository under approved ADR-043; production deployment is not claimed.
- **Digital Entitlement:** A Paper-only Bingo Package produces no QR. A Digital or Hybrid/Combo Bingo Package produces exactly **ONE QR**, covering the digital entitlement only, redeemed onto **ONE Android device**. For online purchases, that entitlement/QR becomes active only after successful payment confirmation (BR-053). Multiple valid Digital Packages for the same exact dated commerce occurrence may be redeemed onto the same tablet; electronic entitlement accumulates independently by Game Type (BR-045, BR-055, BR-073). An occurrence starting does not automatically make a valid QR unusable (BR-056). Staff can perform a staff-gated ticket reactivation (Game Operator or Hall Manager) on device failure. (BR-044, BR-010)

---

## 2. Confirmed Application Architecture

### Confirmed
- WordPress Multisite
- Subdomain mode
- WooCommerce
- Custom BingoCommerce plugins
- Custom indexed tables where appropriate
- ADR-031 hybrid Catalog/Session persistence baseline: seven approved
  conceptual hall-local domain tables plus linked WooCommerce commerce shells.
  The current repository implements all seven baseline tables (five Catalog
  v6 and two Session tables) plus Session schema v3's Portal-template replica
  and three Commerce-schedule definition tables: eleven implemented tables total.
  ADR-044 Slice 1 implements the `bingo_package_addons` migration and schema
  verification; Slice 2 implements `PackageAddonService`, its fixed transaction
  profile, lifecycle/medium integrity, post-commit audit, projection, targeted
  reconciliation and backend tests. Slice 3 implements the Package Admin UI,
  nonce/controller boundary, bulk-form orchestration, regular-price prefill and
  browser Remove confirmation. The customer cart backend and electronic-limit
  validation at cart addition and classic/Store API checkout are implemented;
  the BingoThemev1 storefront foundation, core Store Details and Hall homepage
  slider are implemented (ADR-045 / BR-084, slider addition 2026-09-30), the
  homepage Session calendar (date → eligible dated Session → informational
  Package cards, read-only `HomepageSessionCalendar` in Sessions) is implemented
  (ADR-045 / BR-084 addition 2026-10-01), and the homepage purchase step
  (one Package → optional eligible add-ons → the existing WooCommerce cart
  through the Store API cart-extension route, JavaScript only) is implemented
  (ADR-045 / BR-084 addition 2026-10-02), with the reference two-column layout,
  each Package's "What's included" (saved base composition), the ordinary Shop
  leaving out Bingo Packages and Play Products by Catalog membership, and add-on
  lines priced without a false sale (same-day addition); each verified Bingo
  Package cart line shows its own dated Session as one native "Bingo Session"
  item-data entry (same-day addition, display only). No real Hall migration or deployment is claimed. Add-on prices
  are canonical `DECIMAL(19,2)` values. The new-row UI
  prefill loads the linked product with `\wc_get_product( $wc_product_id )`,
  requires `\WC_Product`, and reads the unfiltered edit-context string with
  `$product->get_regular_price( 'edit' )`; it never uses a sale/effective price
  or floating-point conversion. UI form preparation may pad `5`/`5.5` without
  rounding to `5.00`/`5.50`, but Manager/service input must already be a
  canonical exactly-two-decimal string and is never normalized.
  `PackageAddonService::create()` never reads WooCommerce pricing. The
  Slice 3 Package editor shows every configured
  relationship, including disabled or currently ineligible rows, while new
  candidates are active, medium-compatible current-Hall Play Products only.
  Increment 1 projection retry is targeted by current-Hall
  Bingo Play Product ID; the Hall-wide mismatch scanner is deferred until the
  storefront/readiness phase. ADR-043 is approved and implemented; production
  deployment is not claimed.
- ADR-035 Portal integration authority: Portal owns Hall/Game Type/operational
  Session-template identity; BingoCommerce owns commerce schedules, dated
  occurrences, Catalog/commerce, checkout, orders, and Hall-local entitlement/
  ticket state
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
- `tests/` — CLI integration/verification harnesses (**NEVER** deployed to production). Test harnesses must live here, outside `wp-content/`, so they cannot ship inside a deployable plugin tree.

---

## 4. Roles & Permissions Model
- **Game Operator:** Floor/session role only. Can scan and redeem digital QR receipts, lookup redemptions, and execute staff-gated ticket reactivations. Zero access to pricing, products, or billing settings.
- **Hall Manager:** Runs one or more assigned hall stores. Manages products, Packages, add-ons, pricing, inventory, sessions, orders, staff, refunds, payment gateway setup, the optional hall electronic-limit override, and approved storefront customization. In v1 (BR-084 / ADR-045) that customization is the Hall logo, primary/footer menu links and their order, the public Store Details, page content, and homepage text, images and map location (the slider and Shop by Category cards have separate Appearance → Homepage editors (category addition 2026-10-01); the homepage Google Maps iframe uses the public address edited in Appearance → Store Details; other homepage sections and their controls remain pending); homepage section order, the shared BingoThemev1 header/footer structure, and its colours and fonts are fixed. Cannot install/upload/switch/activate themes, install or activate plugins, alter network configuration, or set the platform electronic maximum (BR-064).
- **Platform Admin:** Bingo Innovation network-wide administrator. May edit any hall store, manage platform billing status (BR-016), manage starter WooCommerce Products/Bingo Play Products when that deferred mechanism exists (starter Package templates are not a v1 requirement), set the platform electronic-card maximum (BR-038), oversee data retention, and perform **explicit** Network Super Admin / Platform Admin recovery: legacy Hall backfill/enrolment and ownership-unproven orphan-site recovery. Portal Game Types synchronize by Portal ID under ADR-035. Automatic Enable Ecommerce / Disable Ecommerce is **Portal Global Admin-only** via the BingoCommerce machine API (BR-075, BR-076 / ADR-042) and is not initiated by WordPress Platform Admin. The BingoCommerce REST endpoint, orchestrator, provisioning-operation persistence, platform plugin/filter and automatic site-creation path are implemented and tested. The Portal Global Admin Enable, Suspend, Re-enable and Retry Ecommerce controls are implemented and tested. Hall Owner Ecommerce access is read-only; assigned Hall Managers cannot see the Ecommerce section or store URL; mutation remains Global Admin-only. A user-performed local-development Open Store → Suspend → Re-enable workflow passed against one mapped WordPress multisite test site, and re-enable reused the same site. Production deployment is not claimed. The automatic-provisioning MVP creates an empty ready store (no starter Product / Play Product copy).

---

## 5. Non-Negotiable Rules (Violations Reject the Work)

### Core Safeguards
1. **Zero Core Modifications:** NEVER edit `wp-admin/`, `wp-includes/`, WooCommerce core, or third-party plugin source files.
2. **No Logic in Themes:** Business logic belongs strictly in `wp-content/plugins/` or `mu-plugins/`. Themes only render presentation layers.
3. **No Unapproved Plugins:** Do not install new third-party plugins without explicit architectural approval.

### Database & Security
4. **Safe SQL Parameterization:** SQL values must never be interpolated unsafely. Normal `$wpdb` SQL MUST use `$wpdb->prepare()`. Direct mysqli is permitted ONLY inside an explicitly approved captured-`$wpdb->dbh` transport, and every such transport binds every value through native prepared mysqli statements. The approved transports are: ADR-025's Hall Staff mandatory-audit transport; ADR-032's Catalog critical-serialization transport, including ADR-034's Play Product medium-change integrity; ADR-033's Package composition atomicity transport; ADR-044's implemented fixed Package-add-on mutation profile and its narrow add-on integrity extensions to ADR-033/ADR-034; and ADR-040's Portal snapshot application transport, which is the only boundary spanning the hall's Game Type replica, its Portal Session-template replica, and the Phase 3E sync-state marker. Those approved transports must not be rewritten to `$wpdb` merely to satisfy this rule — ADR-033/ADR-044 in particular PROHIBIT `$wpdb->query()` for statements issued while their Catalog transactions are open, because `wpdb::query()` fires the `query` filter on every statement and is not callback-free. No other feature may introduce direct mysqli without a reviewed decision. Raw string interpolation into SQL is prohibited in all cases.
5. **REST API Hardening:** Every custom REST API endpoint MUST include an explicit `permission_callback` checking `current_user_can()` (or verifying valid Application Passwords) AND a `sanitize_callback` on every input parameter.
6. **Hall Isolation:** Every hall-scoped query, cache key, and file path MUST
   carry verified tenant context. Hall-owned per-site tables use the resolved
   site's `$wpdb->prefix` as their isolation boundary and MUST NOT add a
   redundant `blog_id`; tenant-attributed network-table rows require an indexed
   `site_id` / `blog_id`. Cross-hall data leakage is the highest severity bug
   in this platform.
7. **No Raw Card Storage:** Credit card details must NEVER transit or touch our application server unencrypted. Gateway hosted fields / SDKs only.

### Architecture & Operations
8. **Custom Tables over Postmeta:** Structured operational data (sessions, digital entitlements, audit events, billing logs) must not be stored in unindexed `postmeta`. Exact persistence may be custom indexed tables, structured WooCommerce metadata, or site/network options as appropriate to the approved design. Do not dump structured operational data into unindexed postmeta merely for convenience. A device registry is not currently required and is not an already-decided operational table.
   ADR-031 specifically locks the Catalog/Session authority to the per-site
   `bingo_game_types`, `bingo_play_products`, `bingo_packages`,
   `bingo_package_components`, `bingo_package_addons`, `bingo_sessions`, and
   `bingo_session_packages` tables. Catalog v6 implements `bingo_package_addons`
   and schema verification under ADR-044 Slice 1; Slice 2 implements its backend
   service, fixed mutations, integrity extensions, post-commit audit/projection
   and targeted reconciliation. Slice 3 implements Package administration UI
   and request handling. The customer cart backend and electronic-limit checks
   at cart addition and classic/Store API checkout are implemented; the
   BingoThemev1 storefront foundation and Hall homepage slider are implemented
   (ADR-045, slider addition 2026-09-30), as is the homepage Session calendar
   with informational Package display (addition 2026-10-01) and its purchase
   step through the existing cart-extension route (addition 2026-10-02; its
   reference layout, Package contents, the Shop's exclusion of Bingo
   products and the Package lines' Bingo Session labels the same day). The
   current additive Session schema also uses `bingo_portal_session_templates`,
   `bingo_session_schedules`,
   `bingo_session_schedule_dates`, and `bingo_session_schedule_packages` for
   the approved ADR-043 model; this does not replace the ADR-031
   tables. Production deployment is not claimed. Per BR-065, do not treat
   `bc_product_kind` or `bc_package_relation` as a competing Catalog authority.
9. **Electronic-Limit Enforcement (Checkout vs. Redemption):** The electronic-card limit is a configurable maximum digital faces per Game Type, using a network-level platform maximum with an optional per-site Hall override. The value 300 is a configurable business reference, not an assured installed value or automatic default; the maximum must be configured and read at runtime. Checkout performs authoritative server-side validation against the current effective limit (BR-042); it does not increment or reserve a shared counter, so it requires no transactional locking merely because the retired ADR-006 model once needed that. Android redemption checks and mutates accumulated per-tablet/session/Game-Type usage (BR-045) and MUST be atomic/concurrency-safe, accepting or rejecting each incoming receipt as a whole. There is no session-wide qualifying-order capacity model.
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
- **Date format:** All user-facing dates in BingoCommerce use `MM/DD/YYYY`. Database columns, SQL values, API canonical values, and internal comparisons may remain `YYYY-MM-DD`. Conversion occurs at trusted input/output boundaries.

---

## 7. AI Agent Execution Protocol
1. **Consult Docs First:** Before implementing any feature, check `docs/requirements/`, `docs/architecture/`, and `docs/decisions/`.
2. **Ask on Ambiguity:** If a business rule or requirement is ambiguous, STOP and ask before proceeding. Do not guess or invent logic.
3. **Small Commits:** Make focused, incremental changes. Explain implementation plans before touching more than 2 files.
4. **Update Status:** Upon completing a task or sprint item, update `PROJECT_STATUS.md`.
