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

- **ADR-023 — [2026-08-12] — Network-Wide Audit Log Architecture:**
  BingoCommerce uses a shared network-wide audit service owned by
  `bingocommerce-core` for security-sensitive and administrative events that
  require Platform Admin visibility across halls.

  Canonical audit events are stored in a network-wide custom table using
  `$wpdb->base_prefix`, with tenant attribution through an indexed
  `blog_id` / `site_id`.

  Audit records must capture sufficient context to reconstruct the action,
  including at minimum:
  - hall/site
  - actor identity
  - action/event type
  - target type and target identifier where applicable
  - timestamp
  - relevant before/after or contextual metadata where required

  Initial consumers include Hall Staff Management (BR-033), ticket
  reactivation (BR-010), and hall billing/lifecycle administrative actions
  (BR-016/BR-030).

  The central audit log is an append-only accountability trail and does NOT
  replace domain-specific operational state or history. Feature plugins may
  retain their own domain records where required for correctness, while also
  emitting the corresponding canonical audit event through the core AuditLog
  service.

  Feature plugins must not create competing general-purpose audit-log systems
  when the event belongs in the shared platform audit trail.

  For security-sensitive mutations where an audit event is mandatory, the domain mutation and its audit event form one atomic logical operation.
  The mutation MUST NOT remain committed if the mandatory audit record cannot
  be persisted. On audit failure, the operation must fail and the previous
  domain state must be restored.

  Implementations must not silently continue, downgrade audit failures to
  warnings, or leave unaudited privilege/security changes in place.

  No WordPress role or capability may grant edit or delete access to audit
  records. All writes occur exclusively through the AuditLog service's
  internal methods — never exposed through any UI, REST endpoint, or
  capability grant.

  The platform audit trail has an independent retention lifecycle governed by platform-level compliance and legal policy. Hall lifecycle transitions, including Terminated, Pending Deletion, and Deleted, do not automatically delete corresponding audit records.

  The exact audit-retention period is not hardcoded and remains subject to
  platform compliance/legal review.

  Rationale: Platform Admin requires efficient cross-hall auditing, while
  ADR-022 identifies this as genuinely network-level data.
  
- **ADR-024 — [2026-08-13] — Audit Validation and Write Path Extension Policy:**
  The canonical `bingocommerce-core` AuditLog validation and persistence path
  must not expose WordPress filters or actions that allow third-party code to
  alter audit-event acceptance, bypass sensitive-data restrictions, mutate the
  canonical payload being persisted, or change whether a required audit write
  succeeds.

  Audit security policy is owned by `bingocommerce-core` and changes to that
  policy require reviewed code changes rather than runtime extension hooks.

  This restriction does not prohibit observability hooks outside the canonical
  audit validation/write path where those hooks cannot weaken, modify, or
  bypass the security control. For example,
  `bingocommerce_core_migration_failed` may remain as an operational
  notification emitted after a migration failure.

  Rationale: mandatory audit integrity must not depend on arbitrary plugin or
  theme callbacks, particularly when AuditLog is used by security-sensitive
  operations governed by ADR-023.

- **ADR-025 — [2026-08-13] — Fail-Closed Transaction Transport, Operation Identity, and Commit Reconciliation for Mandatory-Audit Mutations**

BingoCommerce security-sensitive mutations that require a mandatory ADR-023 audit event (initially Hall Staff Management per BR-033) must use the following rules:

1. **Transport:** Critical transactional DML (domain mutation + mandatory audit insert + COMMIT/ROLLBACK) MUST execute via **direct mysqli** on a **captured** WordPress connection handle (`$wpdb->dbh` validated as `mysqli`). These statements MUST NOT use `$wpdb->query()`, `$wpdb->insert()`, `$wpdb->update()`, or `$wpdb->delete()`.
2. **No `$wpdb` mutation fallback:** On any transport or commit uncertainty, BingoCommerce MUST NOT “recover” by re-issuing critical DML through `$wpdb` (reconnect-and-retry hazard). Fail closed.
3. **Same transaction:** Protected domain DML and the mandatory audit insert MUST occur in **one** InnoDB transaction on that same connection.
4. **No native WP user/role APIs inside the critical transaction:** `wp_insert_user()`, `add_user_to_blog()`, `remove_user_from_blog()`, `WP_User::set_role()` / `add_role()` / `remove_role()`, and equivalent wrappers MUST NOT run inside the critical transaction. Side effects (email, cache, WooCommerce hooks) occur only after `COMMITTED_VERIFIED`.
5. **Canonical writer path (Hall Staff):** Assigning, changing, or removing `bc_hall_manager` / `bc_game_operator` on a hall MUST occur only through the BingoCommerce Hall Staff service (or a successor core-owned canonical writer). Parallel native WordPress/WooCommerce user-admin paths, direct usermeta edits, and ad-hoc `$wpdb`/mysqli writers MUST NOT be treated as supported mutation channels for these roles. Platform controls MUST prevent or fail closed against bypasses that would skip locking, operation identity, mandatory audit, and reconciliation rules. Super Admin override of BR-033 safeguards still goes through this canonical writer — it does not authorize an unaudited native shortcut.
6. **No generic `atomic(callable)`:** Core MUST NOT expose a generic wrapper that claims transactional safety for arbitrary WordPress callbacks.
7. **Operation identity:** Every mandatory-audit mutation MUST have a server-generated UUID v4 `operation_id` created **before** BEGIN. The ID identifies one logical operation.
   - Clients MAY resubmit an `operation_id` only for **idempotent retry** of an operation the server previously issued.
   - A reused `operation_id` MUST be **bound to the original canonical operation intent** recorded by the server (at minimum: hall/`blog_id`, action type, actor, target, and the intended before/after staff state).
   - BingoCommerce MUST reject reuse when the retry payload does not match that bound intent. A free-floating client-supplied UUID that was not issued for that intent is invalid.
   - New intent ALWAYS gets a new server-issued `operation_id`.
8. **Audit schema:** `bc_audit_log` MUST gain a nullable `operation_id CHAR(36)` with a UNIQUE index. Mandatory transactional audits REQUIRE non-NULL `operation_id`. Schema changes bump `bc_core_network_db_version` (v1 → v2); do not silently revise an already-applied version.
9. **Commit witness:** Durable presence of the audit row with that `operation_id` is the primary proof the transaction committed. Current domain/role state is not a commit witness for a specific operation.
10. **Ambiguous COMMIT & strongly consistent reconciliation:** If COMMIT acknowledgement is uncertain, reconcile by selecting the audit witness by `operation_id`. That read MUST use the **authoritative writer** (the primary / same strongly consistent database endpoint used for the transactional writes)—not a potentially lagged replica or any read path that can return stale absence/presence of the witness. Map results to `COMMITTED_VERIFIED`, `NOT_COMMITTED_VERIFIED`, `OUTCOME_UNKNOWN`, or `CONSISTENCY_VIOLATION`. Never report success without `COMMITTED_VERIFIED`.
11. **Caller behavior:** Brief in-request reconciliation only; then return structured unknown/failure — not indefinite HTTP waits. Phase 1 recovery is idempotent retry with the same server-issued `operation_id` and matching bound intent.
12. **Idempotency:** Before mutating, if a witness exists for `operation_id` **and** the bound intent matches, return success without re-applying DML. UNIQUE(`operation_id`) prevents duplicate mandatory audit rows.
13. **Locking:** Hall Staff writers for a hall MUST acquire a MySQL advisory lock on the transactional connection and use appropriate InnoDB `FOR UPDATE` row locks for last-Hall-Manager safety. The lock name MUST be **installation/network-scoped**, not merely `bc_hall_staff_{blog_id}`, so distinct WordPress installations or networks sharing a MySQL server cannot collide (for example, include a stable network/installation discriminator derived from BingoCommerce/WordPress network identity together with `blog_id`). Locks are connection-scoped and auto-release on disconnect; reconciliation MUST NOT depend on still holding the lock.
14. **Incompatible handle/drop-in:** If `$wpdb->dbh` is missing or not `mysqli`, mandatory-audit mutations MUST fail closed until an approved alternate transport exists.
15. **AuditLog authority:** Only AuditLog writes `bc_audit_log`. It may provide a narrow internal transaction-context insert; feature code never writes audit SQL. ADR-024 remains in force (no validation/write extension hooks).
16. **Retention:** Operation-ID witness rows MUST NOT be deleted earlier than the idempotent-retry / reconciliation window; hall lifecycle deletion MUST NOT remove them (ADR-023).
**Rationale:** BR-033 and ADR-023 require privileged mutations to never remain committed without their mandatory audit record. Direct use of the existing `mysqli` session combined with `operation_id` commit witnesses preserves one transactional database boundary while avoiding WordPress's automatic reconnect-and-retry behavior, ensuring strict reconciliation under connection loss, and avoiding the global blast radius of a custom database drop-in.

- **ADR-026 — [2026-08-21] — Bingo Catalog Domain Model:**

New plugin: `bingocommerce-catalog`. This plugin owns Game Type (BR-037), Bingo Play Product domain metadata/mapping (BR-039), Package definitions, Package Components, and Package add-on eligibility, and package type (Paper/Digital/Hybrid) (BR-040), plus starter-catalog domain logic (BR-047).

Game Type belongs to the BingoCommerce domain model, not to WooCommerce native taxonomy (category/tag). No runtime component may infer Game Type, Medium, or package composition from a WooCommerce product title, SKU, tag, category, or other naming convention (BR-037, BR-039).

Plugin boundaries:
- `bingocommerce-core` — foundational/shared infrastructure only (roles/capabilities, tenant context, audit log, migration framework). Catalog domain behavior must not be added to core.
- `bingocommerce-catalog` — Game Types; Bingo Play Product domain metadata/mapping; Package definitions, components, and add-on eligibility; starter-catalog domain logic.
- `bingocommerce-sessions` — Session entity; Session schedule/state; Package-to-Session availability/assignment. The Session plugin must not own Catalog entities.
- `bingocommerce-digital-tickets` — entitlement/ticket issuance, QR, redemption, caller/tablet accumulation.
- `bingocommerce-platform` triggers `bingocommerce-catalog`'s starter-catalog copy mechanism during hall provisioning; it does not own Catalog domain rules itself.

*Rationale:* The Product → Game Type → Package → Session → Digital Entitlement domain is materially larger and more structured than general commerce/product data, and title/tag/SKU-based inference was identified as an explicit anti-pattern during the confirmed-model audit. A dedicated plugin keeps this domain's ownership unambiguous and prevents it from being folded into `bingocommerce-core` (which must stay foundational) or `bingocommerce-sessions` (which owns Session, not Catalog).

- **ADR-027 — [2026-08-21] — Starter Catalog Provisioning:**

At hall provisioning, Bingo Innovation's starter WooCommerce products, starter Bingo Play Products, and starter Game Types are copied into the new hall as a one-time, independent copy (BR-047). WooCommerce products begin as hall-owned drafts; Game Types are copied as hall-local Game Types. There is no ongoing master-to-hall synchronization after provisioning.

Storage of the master template source itself (where Bingo Innovation's starter catalog is authored/held prior to copy) remains an implementation detail for the later technical-design pass; this entry does not invent a synchronization architecture.

*Rationale:* Extends the BR-022 copy-on-provision precedent (block-theme starter-site imports) to the Catalog domain, and keeps each hall's catalog independently owned per ADR-001/ADR-022 rather than live-linked to a shared platform source.

- **ADR-028 — [2026-08-21] — Electronic Limit and Session Scope (supersedes ADR-006):**

This entry formally supersedes ADR-006. The former 300-order Session-capacity mechanism — a session-wide shared capacity counted in a custom indexed table tracking paid orders — is retired.

Locked model:
- No session-wide qualifying-order capacity of any kind.
- The former fixed "300" becomes a platform-configurable electronic maximum (BR-038), stored at network level under Platform Admin control.
- An optional hall override is stored per-site.
- The effective limit is computed dynamically from the current platform maximum at evaluation time (BR-038) — never from a stale cached/saved value.
- The limit is calculated and enforced independently per Game Type (BR-037); it is not cumulative across Game Types.
- Paper is excluded from the electronic calculation entirely.
- Session is per-hall/per-site (consistent with ADR-022); BingoCommerce owns the canonical Session identity via tenant context + Session ID. No network-wide Session registry is required in v1.

*Rationale:* The confirmed business model (BR-038, BR-041, BR-042) replaces the fixed session-wide order-capacity invariant with a configurable, per-Game-Type electronic-face limit enforced independently at checkout and redemption. ADR-006's two-tier model's session-wide tier no longer reflects the platform's business rules and must not be built. ADR-006's text is preserved in the decision log for history and is not rewritten.

- **ADR-029 — [2026-08-21] — Immutable Purchase-Time Digital Entitlement:**

A digital entitlement snapshot is created at the moment an order becomes entitled for bingo play and is authoritative for that specific purchase thereafter (BR-043). Mutable Product, Package, component-quantity, faces-per-unit, or Game Type state must never be used to recalculate a historical purchase's entitlement.

Operational entitlement snapshot data follows ADR-005's custom-indexed-table principle (never unindexed postmeta). This entry does not define final SQL schema, table names, indexes, or migration ownership — that belongs to the next technical-design pass.

*Rationale:* Preserves purchase-time accuracy for customers and Hall Managers even as packages, Game Types, and pricing evolve after purchase, consistent with the existing immutable-snapshot principle already stated for checkout in `.cursor/rules/020-woocommerce.mdc`.

- **ADR-030 — [2026-08-21] — Digital Ticket and Redemption Boundary:**

Locked model:
- A Paper-only Bingo Package purchase creates no digital ticket.
- A Digital or Hybrid/Combo Bingo Package purchase creates exactly one digital ticket, covering the digital entitlement portion only (BR-044).
- The QR is a secure reference to server-authoritative ticket/entitlement state; values carried on the QR itself are never the authoritative record.
- The Android `caller_id` is the tablet identity for redemption purposes; BingoCommerce owns Session identity (ADR-028).
- Electronic accumulation is scoped by tenant/session/caller_id/Game Type (BR-045).
- Each incoming redemption receipt is accepted or rejected as a whole — no partial redemption (BR-045).
- The Android integration contract (JSON fields, DTOs, game codes) remains versionable/extensible and is not defined by this entry.

Reliable redemption should require authoritative server confirmation and idempotent request handling as an architectural direction; this entry does not define the final endpoint, schema, or API contract.

*Rationale:* Extends the QR/device-binding principle already locked by BR-009/BR-010 into the confirmed Game-Type-scoped entitlement model, while keeping the Android wire contract's exact shape a separate, later, versionable integration decision.

- **ADR-031 — [2026-08-25] — Catalog and Session Persistence Architecture:**

BingoCommerce uses a hybrid persistence model: WooCommerce products are the
commerce shells, while BingoCommerce owns the authoritative relational Bingo
Catalog and Session domain.

Hall-owned domain state is stored in these seven per-site custom tables:
`$wpdb->prefix . 'bingo_game_types'`,
`$wpdb->prefix . 'bingo_play_products'`,
`$wpdb->prefix . 'bingo_packages'`,
`$wpdb->prefix . 'bingo_package_components'`,
`$wpdb->prefix . 'bingo_package_addons'`,
`$wpdb->prefix . 'bingo_sessions'`, and
`$wpdb->prefix . 'bingo_session_packages'`. The WordPress prefix supplies the
installation/site namespace; no additional `bc_` table-name sub-prefix is
added.

Game Type, Bingo Play Product, Package, and Session use surrogate
BingoCommerce domain IDs. Game Type also has a stable immutable machine code
separate from its editable display name. Linked WooCommerce product IDs are
unique mappings, not BingoCommerce domain identity. One WooCommerce product ID
MUST NOT be linked simultaneously by `bingo_packages` and
`bingo_play_products`; `PackageService` and `BingoPlayProductService` each
enforce that exclusivity. Enforcement MUST be concurrency-safe: a standalone
check-then-insert across the two authoritative tables is insufficient. The
exact serialization/transaction mechanism is finalized in the Bingo Play
Product/Package implementation-design slice before either writer is
implemented. This clarification does not add another table and does not make
ADR-025's Hall Staff transport automatically applicable.

The linked WooCommerce Package product's native price is the authoritative
Package base price. Package composition never sums component-product prices.
The Package/add-on relationship owns its Package-specific unit price and
maximum selectable quantity. `bingo_package_addons` is the only authority for
which Package may use an add-on.

Session stores a structured calendar `DATE` and display name. No separately
structured Session time is required in the current implementation, and time
text in the display name is never parsed for business logic.

The platform electronic maximum is a network option controlled by Platform
Admin; the optional hall override is a site option configurable by an
authorized Hall Manager. The current-value effective-limit resolver belongs to
`bingocommerce-catalog`.

Catalog tables are authoritative under BR-065. BR-032's `bc_product_kind` and
`bc_package_relation` WooCommerce metadata are derived compatibility/cart-
enforcement projections, maintained only through supported Catalog services:

- While an authoritative Package row exists,
  `bc_product_kind = package`.
- Permanently removing a Package REQUIRES the caller to choose exactly one
  linked-product disposition: permanently delete the WooCommerce product, or
  intentionally retain it as ordinary merchandise and set
  `bc_product_kind = regular`. A request choosing neither is rejected; removal
  never silently converts the product, and a retained product is never left
  with missing/undefined `bc_product_kind`.
- If a Bingo Play Product has one or more enabled Package/add-on relationships,
  `bc_package_relation = package_only_addon`; otherwise
  `bc_package_relation = none`. The count is recalculated from all current
  authoritative relationships, so removing one relationship cannot clear the
  projection while another enabled relationship remains.
- Membership in `bingo_play_products` remains authoritative and makes that
  product non-independently-purchasable in v1 even when compatibility metadata
  says `bc_product_kind = regular` and `bc_package_relation = none`.

BR-065 is the active business-rule authority clarification for BR-032. ADR-031
preserves BR-032's explicit metadata values, one-Package cart limit, dependent-
add-on behavior, and server-side enforcement, with the metadata maintained as
projections of the relational Catalog model.

`PackageService`, `PackageAddonService`, and `BingoPlayProductService` are the
supported writers for their respective Catalog state and projections. Final
cart/checkout enforcement reads authoritative Catalog state; projection
metadata never grants identity or Package-specific eligibility by itself.
Projection updates are deterministic, idempotent, verifiable, and safely
reconcilable. A committed Catalog mutation followed by an unverifiable or
failed projection update is surfaced as requiring reconciliation rather than
reported as wholly healthy.

No direct-SQL transaction spanning custom Catalog rows and WooCommerce
metadata is introduced. ADR-025's Hall Staff direct-mysqli transaction
transport does not automatically apply to this projection problem. The initial
implementation uses indexed logical relationships and restrictive service-
level reference validation; it does not rely on database cascades or physical
foreign keys. Archive/inactivation preserves relationships while preventing
new selection/sale, hard deletion is blocked while referenced, and lifecycle
operations never silently detach relationships.

ADR-026's plugin boundaries remain in force. Detailed conceptual columns,
indexes, lifecycle behavior, projection reconciliation, and plugin/migration
ownership are documented in
`docs/architecture/catalog-session-persistence-design.md`.

*Rationale:* The Catalog and Session model needs indexed hall-local identities
and relationships without duplicating WooCommerce's commerce responsibilities.
This split preserves native product/cart/order integration, provides an
unambiguous relational authority for Bingo rules, and makes compatibility
metadata repairable rather than a competing source of truth.

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

- **BR-033 — [2026-08-12] — Hall Staff Management & Hall Manager Delegation:**
  A Hall Manager may add or remove Game Operators, and may add or remove other
  Hall Managers, but only for a hall on which they themselves currently hold
  Hall Manager rights — never network-wide and never for a hall they do not
  already manage.

  Both existing network users and newly created users may be assigned as hall
  staff. Assigning an existing user reuses their shared network identity
  (BR-007) and grants the role only on the specific hall in question.

  Removing a user from hall staff removes their role/capability on that hall
  only. It must never delete their shared WordPress identity or remove access
  they legitimately hold on any other hall.

  A Hall Manager may assign only the approved BingoCommerce hall roles:
  Hall Manager and Game Operator. They may not grant Administrator, Shop
  Manager, Super Admin, arbitrary WordPress roles, or network capabilities,
  and may not use staff management to expand their own hall scope.

  Every grant, role change, and removal of Hall Manager or Game Operator status
  is logged with at least actor, target user, hall, timestamp, previous role,
  new role, and action. All staff grants, role changes, and removals must emit
  an audit event through the shared platform audit service defined by ADR-023.

  Platform Admin (native Super Admin, per BR-026) retains an always-on,
  non-removable override to view, audit, grant, change, and revoke Hall Manager
  or Game Operator assignments on any hall.

  Normal Hall Manager actions must not remove the last remaining Hall Manager
  from a hall. Platform Admin may override this safeguard when necessary.

  A Hall Manager may not change or remove their own role or membership on a
  hall; that change must be performed by another Hall Manager of the same
  hall or by Platform Admin.

  A Hall Manager role assignment, role change, or hall-membership removal is
  successful only when its mandatory ADR-023 audit event is persisted
  successfully. If audit persistence fails, the staff change must fail and
  the previous staff state must be restored.

  No Hall Manager or Game Operator action under this rule may grant
  Administrator or Super Admin privileges, consistent with BR-026.

- **BR-034 — [2026-08-15] — Super Admin Hall Staff Target Policy:**
  A user holding native WordPress Super Admin status may never be assigned or
  role-changed into `bc_hall_manager` or `bc_game_operator` on any hall. Such
  requests are denied regardless of actor.

  If a Super Admin already holds a legacy `bc_hall_manager` or
  `bc_game_operator` membership, only a Super Admin actor may remove that
  protected hall membership through the canonical Hall Staff service. A
  non-Super-Admin Hall Manager may not perform that cleanup.

  Legacy cleanup:
  - removes only the protected Hall Staff membership for the specified hall;
  - preserves the WordPress Multisite network identity;
  - preserves native Super Admin status;
  - preserves memberships on all other halls;
  - is treated as a safeguard override;
  - requires the mandatory ADR-023 audit witness under BR-033/ADR-025.

  This entry formalizes already-implemented and verified behavior.

  Relationship to prior rules: This entry is additive to BR-033 and does not
  supersede it.

- **BR-035 — [2026-08-15] — Hall Staff Identity and Account Lifecycle:**
  BingoCommerce distinguishes personal/customer identities from operational
  Hall Staff identities.

  **1. Separate customer and staff identities.**
  A person may have separate BingoCommerce network accounts for separate
  purposes. For example, `john@gmail.com` is a personal/customer identity
  holding WooCommerce purchases and customer order history, while
  `john@sunrisebingo.com` is an operational Hall Staff identity holding
  `bc_hall_manager` or `bc_game_operator` access. These are independent
  identities even if they belong to the same physical person.

  A customer/subscriber account is not automatically promoted in-place into a
  protected Hall Staff role. If an existing hall member has a
  non-canonical/non-Hall-Staff role such as `subscriber`/`customer`, the Hall
  Staff assignment flow continues to fail closed rather than silently
  replacing that role. This behavior is intentional product policy, not a
  defect.

  **2. Hall Manager staff authority.**
  A Hall Manager may manage Hall Staff only for halls the actor is authorized
  to manage. The intended canonical Hall Staff lifecycle permits Hall Managers
  to create/invite operational Hall Staff identities for their own halls;
  assign only `bc_hall_manager` or `bc_game_operator`; change Hall Staff
  between those protected roles where permitted; and remove Hall Staff
  membership from halls they manage.

  Hall Managers may NOT create or assign Administrator, Shop Manager, Editor,
  Super Admin, or arbitrary WordPress roles; grant network-level privileges;
  disable an entire WordPress Multisite network identity; permanently delete a
  WordPress Multisite network identity; or alter memberships belonging to
  another hall outside their authority.

  *Implementation status:* create/invite-new-operational-user is **not
  implemented in Phase A**. It is locked intended behavior for the next Hall
  Staff increment. Phase A supports assignment of existing network users only.

  **3. Removing Hall Staff.**
  When a Hall Manager removes a Hall Staff member, the protected membership
  for that hall is removed immediately; access to that hall as Hall Staff is
  lost immediately; the underlying WordPress Multisite network identity is
  preserved; memberships on all other halls are preserved; customer
  identities/accounts are not touched; and Hall Managers never delete the
  network user as a side effect of Hall Staff removal. This preserves the
  behavior already verified by Hall Staff Phase A.

  **4. Platform Admin account lifecycle.**
  Network-level operational-account lifecycle belongs exclusively to Platform
  Admin. The intended lifecycle is
  `ACTIVE → UNASSIGNED → DISABLED → optionally PERMANENTLY DELETED`.

  - `ACTIVE` — operational identity currently has authorized network/hall access.
  - `UNASSIGNED` — operational network identity exists but has no current Hall
    Staff assignment.
  - `DISABLED` — network identity remains for history/audit/administration, but
    login is blocked.
  - `PERMANENTLY DELETED` — Platform Admin intentionally removes the network
    identity through a separate network-level destructive action after required
    safety checks.

  Platform Admin should eventually be able to view operational identities
  network-wide; see Active/Unassigned/Disabled state; see current hall
  assignments; see previous staff-hall information where retained by the
  platform; disable login; reactivate a disabled account; and permanently
  delete an account when safe.

  *Implementation status:* these Platform Admin lifecycle controls are a
  **locked product requirement but are NOT implemented**, and are not part of
  Hall Staff Phase A. Recording this decision does not imply the controls
  exist.

  **5. Authority boundary.**
  Canonical authority is: Hall Manager → hall-scoped staff membership
  lifecycle; Platform Admin → network-identity lifecycle. Removing Hall Staff
  membership and disabling/deleting a network identity are separate operations
  with separate authorization boundaries.

  Relationship to prior rules: This entry is additive to BR-033 and does not
  supersede it.

- **BR-036 — [2026-08-17] — Network-Wide Operational Identity Eligibility:**
  An identity is eligible to hold a protected hall role only if **every**
  capability row it holds network-wide — the unprefixed `bc_capabilities` and
  every `bc_{n}_capabilities` — is canonical and contains exclusively protected
  roles (`bc_hall_manager`, `bc_game_operator`). An identity holding no
  capability row anywhere is eligible.

  A capability row is canonical only when it decodes to an array of exactly one
  entry whose key is a protected role and whose value is strictly `true`.

  Any of the following makes the identity non-operational, and assignment is
  denied with guidance to use a separate operational staff email:
  - the same capability meta key appears more than once for the user;
  - a capability value cannot be decoded safely;
  - a row mixes protected and non-protected roles;
  - a row contains any unknown, custom, or non-protected role;
  - a row contains more than one role key, even if both are protected;
  - a role key's value is not strictly `true`.

  The unprefixed `bc_capabilities` row is real primary-network-site membership
  and is evaluated like any other site membership. No claim is made about how
  such a row came to exist; historical creation path is never inferred.

  Eligibility is evaluated as a pre-transaction check and fails closed. It never
  reads provenance metadata, never repairs or normalizes state, and is never
  laxer than the target-hall check already performed under BR-033.

  Eligibility is evaluated immediately before assignment but is not revalidated
  inside the ADR-025 assignment transaction. A narrow TOCTOU window therefore
  remains in which an external or independent writer could alter the target
  identity's network role state between eligibility validation and commit. This
  is an accepted residual risk for this low-frequency administrative workflow.
  Supported BingoCommerce assignment paths continue to enforce this rule and
  fail closed. This does not justify modifying the ADR-025 transaction boundary.

  Relationship to prior rules: This rule is additive to BR-033 and BR-035 and
  does not supersede either.

- **BR-037 — [2026-08-21] — Game Type Domain Entity:**

  Game Type is a first-class BingoCommerce domain entity, distinct from the
  WooCommerce Product that sells it. A Game Type identifies a category of bingo
  play semantics (for example: Regular, Winner Take All, Red Hot); a WooCommerce
  product identifies a sellable unit (for example: "3-on Regular", "6-on Regular")
  that may reference one Game Type.

  Runtime business logic MUST NOT infer Game Type from a WooCommerce product's
  title, SKU, tags, category, or any other naming convention. Game Type identity
  is carried only through explicit BingoCommerce domain metadata/mapping (see
  BR-039).

  Bingo Innovation provides a starter set of Game Types (see BR-047) that are
  copied into a hall at provisioning. Hall Managers may create and edit
  additional hall-local Game Types beyond the starter set.

  Relationship to prior rules: This entry is net-new. It does not supersede any
  existing BR.

- **BR-038 — [2026-08-21] — Configurable Electronic Card Limit:**

  This entry retires the session-wide order-capacity model. There is no maximum
  number of customers per Session, no maximum number of orders per Session, no
  fixed "300 qualifying orders" rule, and no aggregate Session-wide card-capacity
  counter.

  The electronic-card limit is expressed as a configurable maximum number of
  digital ("electronic") faces, independently per Game Type, not as a count of
  orders or customers.

  Configuration model:
  - Platform Admin controls a network-level platform electronic maximum. The
    current platform maximum is 300, but 300 is configuration, not a
    hard-coded business invariant.
  - A hall may optionally specify one hall-level override, stored per-site.
  - A hall override can never effectively exceed the current platform maximum.
  - Without a hall override, the platform maximum applies.

  Effective limit:
  ```
  if a hall override exists:
      effective_limit = min(hall_override, current_platform_maximum)
  else:
      effective_limit = current_platform_maximum
  ```

  This calculation MUST use the platform maximum's current value at evaluation
  time, not the value that was in effect when a hall override was saved. If the
  platform maximum is later lowered below a previously saved hall override, the
  effective limit still resolves correctly to the (now-lower) platform maximum
  without requiring a data migration first. The stale stored override may be
  flagged for administrative correction later; runtime correctness must never
  depend on that correction happening first.

  The limit applies independently per Game Type — it is not cumulative across
  Game Types. For example, at an effective limit of 72, Regular, Winner Take
  All, and Red Hot may each independently reach 72 electronic faces in the same
  hall; the combined total across Game Types (216 in that example) is not
  itself limited.

  Only Digital Bingo Play Products (BR-039) contribute toward the
  electronic-card limit. Paper Bingo Play Products always contribute zero.

  Relationship to prior rules: This entry is net-new. The formal supersession
  of ADR-006 is recorded in ADR-028; the formal supersession of BR-002,
  BR-025, and BR-031 is recorded in BR-041.

- **BR-039 — [2026-08-21] — Bingo Play Product Metadata:**

  A Bingo Play Product is a reusable WooCommerce product that a Hall Manager
  selects as a component when composing a Bingo Package (BR-040). In the
  current release, Bingo Play Products are package-building components only —
  they are not independently customer-purchasable. Standalone-sale of a Bingo
  Play Product is out of scope for this release and is not a requirement to
  design or build against.

  Structured BingoCommerce metadata on a Bingo Play Product must include, at
  minimum:
  - **Medium**: `Paper` or `Digital`.
  - **Faces per unit**: the number of bingo faces one unit of this product
    contributes.
  - **Game Type relationship**, where required (see below).

  A **Digital** Bingo Play Product MUST reference exactly one Game Type
  (BR-037). Illustrative example only — not a confirmed real-catalog
  mapping: a Digital Bingo Play Product might have Medium = Digital, Faces
  per unit = 3, Game Type = Regular. The actual faces-per-unit value for any
  real catalog product (including any product informally labeled "3-on")
  must be confirmed from the authoritative business/catalog source; it must
  never be inferred from the product's title or naming pattern.

  A **Paper** Bingo Play Product contributes zero toward the electronic-card
  limit (BR-038) regardless of any Game Type reference. A Paper Bingo Play
  Product MAY reference a Game Type where meaningful for package composition
  semantics or reporting, but is not required to — a Paper product must never
  be forced to carry a Game Type solely to satisfy electronic-limit
  enforcement, since paper never participates in that calculation.

  Legacy vendor/CSV catalog tags MAY be used for one-time migration/bootstrap
  classification when importing legacy catalog data. Legacy tags MUST NEVER
  become the authoritative runtime source for Medium, Game Type, Faces per
  unit, or package behavior — only the structured BingoCommerce metadata above
  is authoritative at runtime.

  Relationship to prior rules: This entry is net-new.

- **BR-040 — [2026-08-21] — Bingo Package Domain Model:**

  A Bingo Package is a customer-facing BingoCommerce entity, distinct from the
  WooCommerce commerce mechanisms used to sell it (see the BR-021 amendment
  below). A package has one of three types: **Paper**, **Digital**, or
  **Hybrid/Combo**.

  A Hall Manager builds a package from existing Bingo Play Products (BR-039)
  and per-component quantities — illustratively, a package like "Thursday
  Digital Gold" might combine a Regular-type digital component × 12, a
  Winner Take All component × 6, and a Red Hot component × 6. Component
  names/quantities here are illustrative only, not a confirmed real catalog
  package.

  Electronic-face contribution per component is structured, not inferred from
  any title or free text:
  ```
  component_contribution = faces_per_unit × package_component_quantity
  ```
  Illustrative example only: if a Digital Bingo Play Product has
  faces_per_unit = 3 and package_component_quantity = 12, contribution = 36
  electronic faces for its Game Type. This is illustrative arithmetic only —
  it does not assert that any specific real catalog product (including any
  product informally labeled "3-on") actually has faces_per_unit = 3; that
  value must be confirmed from the authoritative source and never inferred
  from a product's title or naming pattern. No title parsing is permitted at
  runtime.

  Package-scoped add-ons may be configured. An add-on:
  - is valid/selectable only when an eligible parent Bingo Package is present
    in the cart;
  - cannot be purchased independently of an eligible parent package;
  - participates in the same electronic-entitlement calculation as the
    package when the add-on is digital;
  - is presented in the storefront only within an eligible Bingo Package's
    selection context — never listed or exposed as an independently
    purchasable normal catalog item;
  - remains rejected server-side even if UI controls are bypassed or
    manipulated — this display/visibility rule does not relax BR-032's or
    this entry's underlying enforcement.

  Ordinary merchandise (BR-032's `bc_product_kind = regular`) is not a Bingo
  Game Type, does not participate in digital-entitlement calculations, does
  not generate a Bingo QR, and may coexist with one Bingo Package in the same
  checkout. This entry amends BR-032's transaction-limit rule only insofar as
  it restates that rule in Game-Type/Package-domain terms; it does not change
  BR-032's 0/1-package-per-checkout enforcement, `bc_product_kind`, or
  `bc_package_relation` mechanics, which remain in force unedited.

  WooCommerce Product Bundles and WooCommerce Product Add-ons (BR-021) may be
  used as commerce/UI mechanisms where appropriate to sell a package, but
  BingoCommerce is not committed to those specific WooCommerce plugins ahead
  of the technical implementation design that will determine whether/how
  they should be used. In any case, WooCommerce does not own the Bingo
  domain model: BingoCommerce owns package identity, components, quantities,
  Game Type mapping, entitlement calculation, and add-on eligibility. This
  entry amends BR-021: WooCommerce Product Add-ons and Product Bundles are,
  at most, commerce/UI tools and must not be described or relied upon as the
  authoritative Bingo Package domain model.

  Relationship to prior rules: This entry is net-new and amends BR-021 as
  stated above. It does not edit or reinterpret BR-032's other provisions (the
  0/1-package-per-checkout rule, `bc_product_kind`, `bc_package_relation`),
  which remain unaffected.

- **BR-041 — [2026-08-21] — Session Domain and Package Assignment:**

  A BingoCommerce Session is hall-owned and per-site, consistent with the
  platform's per-hall data-ownership model (ADR-022). Tenant context
  (`blog_id`) plus Session ID together define the canonical internal Session
  identity. BingoCommerce owns this canonical Session identity; no
  network-wide Session registry is required in v1.

  A Session scopes: scheduled date and time, hall, package availability for
  that occurrence, purchase validity, digital-ticket validity, redemption, and
  electronic accumulation for that specific play occurrence. Bingo Packages
  (BR-040) must be assignable/available for a specific Session.

  A Session does NOT contain a session-wide capacity counter of any kind. The
  electronic-card limit is governed exclusively by BR-038 (configurable,
  per-Game-Type, platform/hall-override) and enforced at checkout by BR-042 —
  not by any per-Session order or customer count.

  **Formal supersession.** This entry formally supersedes BR-002, BR-025, and
  BR-031 in their entirety. Their session-wide qualifying-order capacity
  model — including the definition of a "qualifying order," the
  one-order-per-session-consumes-one-slot accounting, and the associated
  state-transition/refund-release rules — is retired and replaced by the
  Game-Type-scoped electronic-card limit (BR-038) enforced at checkout
  (BR-042) and at redemption (BR-045). BR-002, BR-025, and BR-031 remain in
  the decision log for history and are not deleted or rewritten; active
  documentation and guardrails must no longer instruct developers to build the
  retired qualifying-order Session-capacity mechanism.

  This entry also formally voids BR-032's closing-paragraph attribution of
  ongoing capacity-accounting authority to BR-025 ("BR-025's capacity rule
  remains defensive and authoritative for capacity accounting: a qualifying
  order consumes capacity according to the session(s) represented by its
  qualifying digital entitlements..."). That attribution no longer holds once
  BR-025 is superseded by this entry. BR-032's other provisions — the
  0/1-Bingo-Package-per-checkout rule, `bc_product_kind`, and
  `bc_package_relation` — are otherwise unaffected by this entry and must not
  be edited or reinterpreted.

  Relationship to prior rules: Formally supersedes BR-002, BR-025, and BR-031
  as stated above; voids only BR-032's closing-paragraph capacity-authority
  attribution, leaving the rest of BR-032 unedited and in force.

- **BR-042 — [2026-08-21] — Checkout Electronic-Limit Aggregation and Enforcement:**

  At checkout, BingoCommerce calculates the digital electronic faces
  contributed by the selected Bingo Package's components (BR-040) plus any
  selected eligible package add-ons, and aggregates that contribution by Game
  Type (BR-037). For example: Regular = 36 (package) + 12 (add-on) = 48 total;
  Winner Take All = 6 (package) = 6 total.

  Each Game Type's aggregated total MUST independently satisfy:
  ```
  total <= effective_hall_limit
  ```
  using the effective limit defined by BR-038. Paper components (BR-039) MUST
  NOT participate in this electronic calculation.

  This enforcement MUST be authoritative server-side. Client/UI-side
  calculation alone is insufficient and must never be trusted as the
  enforcement boundary.

  Active documentation and guardrails must no longer describe checkout as
  consuming one Session capacity/order slot; that mechanism is retired per
  BR-041.

  Relationship to prior rules: This entry is net-new. It is the checkout-time
  enforcement counterpart to BR-038's configurable limit and BR-041's
  Session-domain supersession of BR-002/BR-025/BR-031.

- **BR-043 — [2026-08-21] — Purchase-Time Entitlement Snapshot Immutability:**

  When an order becomes entitled for bingo play, BingoCommerce freezes the
  purchased digital entitlement as a snapshot at that moment. For example:
  Order #8451, Session 118 — Regular = 36, Winner Take All = 12, Red Hot = 6.

  A historical entitlement snapshot must never be recalculated using current,
  mutable Product, Package, component-quantity, faces-per-unit, or Game Type
  configuration. If a Hall Manager later changes a package definition after an
  order was purchased against it, that earlier order's frozen entitlement
  remains unchanged. Historical accuracy is preserved rather than recomputed.

  This entry explicitly carries forward BR-031's historical-integrity
  principle — that historical records must not be retroactively distorted —
  and applies that same principle to entitlement snapshots. BR-031's own
  session-wide qualifying-order capacity-accounting mechanism remains
  superseded by BR-041; only its historical-integrity principle carries
  forward here.

  Relationship to prior rules: This entry is net-new for purchase-time
  entitlement snapshot immutability, while explicitly carrying forward
  BR-031's historical-integrity principle as described above. It does not
  revive any part of BR-031's superseded capacity-accounting mechanism.

- **BR-044 — [2026-08-21] — Digital Ticket / QR Scope:**

  This entry amends BR-009. It preserves BR-009's one-ticket/one-Android-device
  binding concept where that concept does not conflict with the scope defined
  here, and replaces BR-009's blanket "one QR represents the entire order"
  framing with package-type-scoped rules:

  - A **Paper-only** Bingo Package produces no QR.
  - A **Digital** Bingo Package produces exactly one QR.
  - A **Hybrid/Combo** Bingo Package produces exactly one QR, covering the
    digital entitlement portion only.

  Ordinary merchandise (BR-032, BR-040) never enters the QR entitlement. Paper
  components of a package never enter the QR entitlement.

  The QR is a reference to server-authoritative ticket/entitlement state
  (BR-043). Values carried on/within the QR itself must never become the
  authoritative entitlement record — the server-side entitlement snapshot is
  always authoritative.

  Relationship to prior rules: Amends BR-009. BR-009's device-binding and
  staff-gated-reactivation concepts (BR-010) remain in force where consistent
  with this entry.

- **BR-045 — [2026-08-21] — Android Redemption Accumulation and Atomic Rejection:**

  The Android `caller_id` is unique per tablet; BingoCommerce treats
  `caller_id` as the tablet identity for redemption purposes. BingoCommerce
  owns `session_id` (BR-041).

  The conceptual electronic-accumulation key is: hall/tenant + session_id +
  caller_id + Game Type. Multiple digital orders may be redeemed to the same
  tablet for the same Session.

  For each incoming redemption receipt, BingoCommerce must verify, for every
  affected Game Type:
  ```
  existing tablet/session/Game-Type entitlement + incoming entitlement <= effective_hall_limit
  ```
  using the effective limit defined by BR-038. If even one affected Game Type
  would exceed its effective limit, the ENTIRE incoming receipt is rejected —
  partial redemption of a receipt is not permitted.

  This entry does not define the Android API implementation. Exact JSON
  property names, loader fields, DTO/class structures, and extra Android game
  codes remain future versioned integration-contract details and are not
  blockers to this domain decision.

  Relationship to prior rules: This entry is net-new and is the
  redemption-time counterpart to BR-042's checkout-time enforcement, sharing
  the same effective-limit definition (BR-038).

- **BR-046 — [2026-08-21] — Bingo Package Refund Restriction:**

  This entry amends and narrows BR-011. BR-011's blanket statement that
  "refunding an order automatically voids the associated digital QR
  entitlement" is narrowed as follows: a refund or cancellation of the Bingo
  Package (BR-040) — the bingo commercial unit — voids its associated digital
  entitlement. A merchandise-only refund within a mixed order, where the
  Bingo Package itself is not refunded, MUST NOT void or alter the Bingo
  Package's digital entitlement.

  A Bingo Package (BR-040) cannot be partially refunded — if a Bingo Package
  is refunded, it is refunded as a whole. Package-scoped Bingo add-ons
  (BR-040) belong to the same Bingo commercial unit as their parent package
  and must not be independently partially refunded in a way that would
  create entitlement inconsistency with the frozen purchase snapshot
  (BR-043).

  Ordinary merchandise (BR-032, BR-040) present in the same WooCommerce order
  as a Bingo Package remains independently refundable according to hall
  policy, separate from the Bingo Package refund restriction above, and such
  a merchandise-only refund never triggers entitlement voiding.

  Refund handling remains separate from, and must not be conflated with,
  Android redemption-limit enforcement (BR-045).

  Relationship to prior rules: Amends and narrows BR-011. BR-011's principle
  that refunding a Bingo Package voids its associated digital QR entitlement
  remains in force, narrowed to apply at the Bingo Package/commercial-unit
  level rather than to the WooCommerce order as a whole; this entry adds the
  whole-package/no-partial-refund restriction and clarifies its relationship
  to ordinary merchandise and redemption enforcement.

- **BR-047 — [2026-08-21] — Starter Catalog and Starter Game Type Copy-on-Provision:**

  At hall provisioning, Bingo Innovation provides starter WooCommerce
  products, starter Bingo Play Products (BR-039), and starter Game Types
  (BR-037). These are copied into the new hall as a one-time operation:

  - WooCommerce products are created as hall-owned WooCommerce drafts.
  - Game Types become hall-owned/local Game Types.

  After provisioning, the hall may edit its copies, delete its copies,
  publish its product copies, and create additional Game Types, Bingo Play
  Products, and packages of its own. There is no ongoing live synchronization
  between Bingo Innovation's master starter catalog and a hall's provisioned
  copy — future changes to the master starter catalog must never silently
  modify an already-provisioned hall's catalog.

  Relationship to prior rules: This entry is net-new. It extends BR-022's
  copy-on-provision precedent (block-theme starter-site imports) to the
  Catalog domain without editing BR-022.

- **BR-048 — [2026-08-21] — Local Fulfillment / No Shipping in v1:**

  BingoCommerce v1 does not support shipping. Halls may sell physical
  merchandise, but fulfillment in v1 is local/on-site/pickup only — there is
  no platform-supported carrier shipping, shipping-rate calculation, or
  shipping-label workflow.

  This entry supersedes only the shipping-specific wording in BR-005 ("Each
  hall stocks, ships, and manages physical inventory independently") —
  specifically the word "ships." BR-005's other provisions (decentralized
  per-hall stocking and independent physical-inventory management) are
  otherwise unaffected and remain in force. BR-003's "fulfillment" language
  does not name shipping specifically and is not superseded by this entry —
  it remains accurate under local/on-site/pickup fulfillment.

  Relationship to prior rules: Formally supersedes only the shipping-specific
  wording in BR-005, as stated above. Does not supersede BR-003 or any other
  provision of BR-005.

- **BR-049 — [2026-08-21] — Bingo Package Pricing Model:**

  Every Bingo Package (BR-040) has its own explicit fixed base price
  configured by the Hall Manager — for example, "3 Pages Hotline Package"
  might have Base Package Price = $50.

  The Bingo Play Products/components included in a Package determine the
  Package's bingo content and entitlement (BR-039, BR-040), but their
  individual WooCommerce product prices MUST NOT be automatically summed
  into or determine the Package's customer-facing base price. Package
  composition != package pricing calculation. Changing a Package's included
  components or component quantities MUST NOT automatically recalculate the
  Package base price — the Hall Manager explicitly controls the base price.

  Eligible Package Add-ons (BR-040) have a price configured specifically for
  their Package relationship. The underlying reusable Bingo Play Product's
  standalone/WooCommerce price MUST NOT be assumed to be the authoritative
  add-on price. The same underlying Bingo Play Product may therefore carry
  different add-on pricing in different Packages, as configured by the Hall
  Manager, without changing the reusable Bingo Play Product itself. For
  example: Package A's "Extra Regular" add-on = $5, while Package B's "Extra
  Regular" add-on (same underlying Bingo Play Product) = $6.

  Where an eligible Package Add-on permits a quantity greater than one:
  ```
  add_on_price_contribution = package_specific_add_on_unit_price × selected_quantity
  ```
  For example, Extra Regular at $5 each with quantity 2 contributes $10. Its
  bingo-entitlement contribution also multiplies according to the configured
  add-on quantity and the authoritative structured component metadata
  (BR-039/BR-040) — pricing and entitlement are calculated separately, from
  separate authoritative inputs.

  The Bingo commercial total is:
  ```
  bingo_commercial_total = package_base_price + Σ(package_specific_add_on_unit_price × selected_quantity)
  ```
  Underlying included component-product prices do not contribute separately
  to this total. Ordinary merchandise (BR-032, BR-040) in the same
  WooCommerce cart/order remains separately priced according to normal
  WooCommerce pricing and is not part of the Bingo commercial total. For
  example: Package base price $50 + Extra Regular ×2 ($10) + Extra WTA ×1
  ($3) = Bingo commercial total $63; a T-shirt ($15) and beverage ($4) in the
  same order price separately, for a WooCommerce order total of $82.

  This entry does not define final database columns or schema for Package
  base price or package-specific add-on pricing. Where Package base price
  and package-specific add-on pricing are persisted is a decision for the
  upcoming technical persistence/domain-design pass.

  Relationship to prior rules: This entry is net-new and extends BR-040
  (Bingo Package Domain Model), which it does not supersede or edit.

- **BR-050 — [2026-08-24] — Calendar-Dated Sessions:**

  A Hall Manager schedules Sessions on specific calendar dates. Customers
  purchase against one specific dated Session.

  For the current implementation:
  - the Session calendar date is structured;
  - a separate structured Session time is not required;
  - time may appear as part of the Session display name, for example
    "Evening – 7:00 PM";
  - no business logic may parse that display-name time.

  Do not invent an automatic sales-window close, redemption cutoff, or
  Session-close time by parsing text from the Session name.

  This entry supersedes only BR-041's earlier "scheduled date and time"
  wording regarding the requirement for a separately structured Session
  time. All other BR-041 provisions remain in force. This entry does not
  rewrite BR-041's historical text.

  Relationship to prior rules: This entry is additive to BR-041 and
  ADR-028 except for the structured-time clarification above. BR-041's
  Session identity, hall ownership, Package assignment, and retirement of
  session-wide capacity remain in force.

- **BR-051 — [2026-08-24] — Session Sales Availability:**

  A Session automatically enters its online sales window 5 calendar days
  before the Session date. A Session also has a Hall Manager-controlled
  Available for Sale ON/OFF setting.

  A Session appears on the customer storefront only when BOTH are true:
  - it is inside the 5-day sales window, AND
  - Available for Sale = ON.

  Turning Available for Sale ON does not bypass the 5-day window. Turning
  it OFF prevents the Session from appearing for purchase.

  Week- or month-ahead future-Session browsing is not a current
  requirement. This entry does not invent an automatic sales-window close
  from text contained in the Session name (BR-050).

  Relationship to prior rules: This entry is net-new and extends BR-041 /
  BR-050. It does not restore any session-wide order/customer capacity.

- **BR-052 — [2026-08-24] — Customer Session Selection:**

  Customers see only Sessions currently eligible for sale (BR-051). Session
  selection must clearly identify the exact date and Session name. The
  customer selects the Session before selecting the Bingo Package. The
  order remains associated with that exact Session.

  Relationship to prior rules: This entry is net-new and extends BR-041 /
  BR-050 / BR-051. It does not change BR-032's 0/1-Bingo-Package-per-
  checkout rule.

- **BR-053 — [2026-08-24] — Online Entitlement Activation on Successful Payment Confirmation:**

  For online purchases, the Bingo entitlement becomes active only after
  payment is successfully confirmed. Failed, cancelled, or unsuccessful
  payment does not create an active Bingo entitlement or QR.

  This entry does not hardcode a particular WooCommerce order-status name.
  The business rule is: successful online payment confirmation activates
  entitlement creation (BR-043 snapshot and, where applicable, BR-044 QR).
  The exact WooCommerce hook, status, or gateway event that constitutes
  that confirmation is an implementation-design concern — not an open
  business question.

  This entry resolves the previously open online entitlement-activation
  question.

  Relationship to prior rules: This entry is additive to BR-043 and
  ADR-029. It does not rewrite those entries. It does not invent
  entitlement-activation rules for administrator-created or POS/imported
  orders.

- **BR-054 — [2026-08-24] — Sales Reporting Dual Attribution:**

  Keep purchase/payment date separate from Session attribution.

  Example: $4,000 paid Monday for Tuesday's Session; $6,000 paid Tuesday
  for Tuesday's Session.

  - Daily transaction reporting: Monday = $4,000 collected; Tuesday =
    $6,000 collected.
  - Session reporting: Tuesday Session = $10,000 total Session sales.

  Do not move the Monday transaction onto Tuesday's payment/accounting
  date.

  Relationship to prior rules: This entry is net-new. It does not restore
  session-wide capacity accounting (BR-041).

- **BR-055 — [2026-08-24] — Multiple Digital Packages on One Tablet:**

  Multiple valid Digital Packages for the same Session may be redeemed onto
  the same tablet. Their electronic entitlement accumulates independently
  by Game Type.

  For every incoming Package:
  ```
  existing tablet/session/Game-Type total + incoming Package contribution
    <= effective limit
  ```
  If any affected Game Type would exceed its effective limit, reject the
  entire incoming Package redemption. No partial redemption.

  Example:
  - Package 1: Regular = 3, Winner Take All = 5, Hot = 5
  - Package 2: Regular = 4, Winner Take All = 4
  - Accumulated: Regular = 7, Winner Take All = 9, Hot = 5

  This is valid if each value remains within its individual Game Type
  limit.

  Relationship to prior rules: This entry is additive to BR-045 and
  ADR-030. It does not rewrite those entries. One Package per customer
  checkout (BR-032) remains in force — multiple Packages on one tablet
  come from separate purchases/redemptions, not from multiple Packages in
  one cart.

- **BR-056 — [2026-08-24] — Late Redemption During Session:**

  A Session starting does not automatically make a valid QR unusable. Late
  customers may still redeem during the Session.

  Do not introduce a strict "must redeem before Session start" rule. Do
  not invent an automatic closing time from text contained in the Session
  name (BR-050).

  Relationship to prior rules: This entry is net-new and extends BR-044 /
  BR-045. It does not change staff-gated reactivation (BR-010) or
  whole-receipt rejection (BR-045).

- **BR-057 — [2026-08-24] — Game Types Remain Individually Identified:**

  Do not introduce an All Game Types Game Type or special combined
  Game-Type bucket. Game Types remain individually identified.

  Relationship to prior rules: This entry is additive to BR-037. It does
  not rewrite BR-037. Per-Game-Type electronic-limit independence
  (BR-038) remains in force.

- **BR-058 — [2026-08-24] — No Per-Game Player Activity Tracking in Current Implementation:**

  Detailed game-by-game player activity tracking is not required for the
  current implementation. Do not design storage for per-game play history
  as part of this feature.

  Relationship to prior rules: This entry is net-new. It does not change
  redemption accumulation (BR-045 / BR-055), which tracks electronic-face
  totals by tablet/session/Game Type, not play-by-play history.

- **BR-059 — [2026-08-24] — No Separate Extra-Game / Jackpot Purchase Flows in Current Implementation:**

  Do not expand the current implementation into separate jackpot or
  additional-game purchase flows. Current focus is Digital Package
  purchase and Package redemption. Additional separate purchase behavior
  can be considered later if required.

  Relationship to prior rules: This entry is net-new. Eligible
  Package-scoped add-ons (BR-040, BR-049) remain in force and are not
  extra-game/jackpot purchase flows.

- **BR-060 — [2026-08-24] — Offline Usability Business Expectation:**

  Previously synchronized QR/Package information should remain usable at
  the hall if Internet connectivity is later lost.

  This entry records the business expectation only. It does not invent the
  final local-server, cache, or network architecture. Exact offline
  implementation remains a later technical design concern.

  Relationship to prior rules: This entry is net-new. It does not change
  server-authoritative redemption (ADR-030) as the online/connected model.

- **BR-061 — [2026-08-25] — Package Type Composition:**

  A Paper Package has at least one base component, and every base component is
  Paper.

  A Digital Package has at least one Digital base component and has no Paper
  base components.

  A Hybrid/Combo Package has at least one Paper base component and at least one
  Digital base component.

  Package-scoped optional add-ons do not dynamically change the Package
  definition's stored Package type. Package type is validated from base
  composition only.

  Relationship to prior rules: This entry is additive to BR-040's Package
  domain model and BR-044's Package-type-specific QR scope. It does not alter
  the rule that Paper content contributes no digital entitlement.

- **BR-062 — [2026-08-25] — Package Add-on Quantity Ceiling:**

  A Package-specific add-on relationship defaults to a maximum selectable
  quantity of 1. Quantity greater than 1 is available only when that exact
  Package/add-on relationship explicitly configures a larger maximum.

  When an add-on is selected:

  ```
  1 <= selected_quantity <= max_quantity
  ```

  BR-049's Package-specific unit-price multiplication and structured
  entitlement multiplication remain in force.

  Relationship to prior rules: This entry is additive to BR-040 and BR-049. It
  does not make the underlying Bingo Play Product's WooCommerce price
  authoritative for add-on pricing.

- **BR-063 — [2026-08-25] — Session Sales Window Inclusive Boundary:**

  For Session calendar date `D`, storefront date eligibility is inclusive:

  ```
  D - 5 calendar days <= hall_local_today <= D
  ```

  and `Available for Sale = ON` is also required. `hall_local_today` is
  calculated using the hall site's configured WordPress timezone. A Session is
  no longer storefront-eligible beginning the calendar day after its Session
  date.

  No structured Session time is introduced, and no time may be parsed from the
  Session display name. BR-056 remains independent: the end of online sales
  after the Session calendar date does not make an otherwise-valid QR
  automatically unusable when the Session starts.

  Relationship to prior rules: This entry clarifies BR-051's five-calendar-day
  sales window and is additive to BR-050 and BR-056. It does not introduce
  Session capacity or a redemption cutoff.

- **BR-064 — [2026-08-25] — Electronic Limit Configuration Authority:**

  Platform Admin controls the network-level platform electronic maximum. An
  authorized Hall Manager may configure the optional hall-level override for
  that Hall Manager's hall.

  Runtime resolution remains dynamically bounded by the current platform
  maximum:

  ```
  if a hall override exists:
      effective_limit = min(hall_override, current_platform_maximum)
  else:
      effective_limit = current_platform_maximum
  ```

  Relationship to prior rules: This entry clarifies BR-038's configuration
  authority. It does not introduce a Session-wide capacity, order, customer,
  or reservation counter.

- **BR-065 — [2026-08-25] — Catalog Classification Authority and BR-032 Projection Clarification:**

  `bingo_packages` is authoritative for Bingo Package identity.
  `bingo_play_products` is authoritative for Bingo Play Product identity.
  `bingo_package_addons` is authoritative for exact Package-specific add-on
  eligibility.

  BR-032's `bc_product_kind` and `bc_package_relation` WooCommerce metadata
  remain required derived compatibility/cart-enforcement projections. They are
  not independent authorities and cannot override the relational Catalog
  model. Final cart and checkout classification and eligibility enforcement
  MUST use authoritative Catalog state.

  The Package projection is deterministic:

  ```
  if an authoritative bingo_packages row exists:
      bc_product_kind = package
  ```

  Permanently removing a Package requires the caller to specify exactly one
  explicit disposition for the linked WooCommerce product:

  1. Permanently delete the linked WooCommerce product; or
  2. Intentionally retain it as ordinary merchandise and set
     `bc_product_kind = regular`.

  The operation MUST reject any removal request that specifies neither. It
  MUST NOT automatically convert a Package product to ordinary merchandise,
  and a surviving WooCommerce product MUST NOT be left with a missing or
  undefined `bc_product_kind`.

  The Package-add-on projection is deterministic and is recalculated from all
  current authoritative relationships:

  ```
  if enabled Package-add-on relationship count > 0:
      bc_package_relation = package_only_addon
  else:
      bc_package_relation = none
  ```

  Removing one Package/add-on relationship MUST NOT set the projection to
  `none` while another enabled relationship remains. Product-level projection
  metadata never determines which Package is eligible; exact eligibility comes
  only from `bingo_package_addons`.

  Membership in `bingo_play_products` overrides compatibility metadata for
  classification and keeps Bingo Play Products non-independently-purchasable
  in v1, including when compatibility metadata says
  `bc_product_kind = regular` and `bc_package_relation = none`.

  Relationship to prior rules: This entry formally supersedes ONLY BR-032's
  prior attribution of authoritative Package/add-on classification to
  WooCommerce metadata. BR-032's one-Package-per-checkout rule, dependent
  add-on enforcement, ordinary-merchandise coexistence, explicit metadata
  values, and server-side cart/checkout enforcement remain in force.

---

## Still Open / TBD Items (Unresolved)

- Offline QR/Package usability **technical implementation** (local-server /
  cache / network architecture). The **business expectation** is locked by
  BR-060: previously synchronized QR/Package information should remain
  usable at the hall if Internet connectivity is later lost. Do not invent
  that architecture ahead of a dedicated technical-design pass.
- Final online payment gateway selection.
- POS hardware partner selection (Clover under evaluation).
- Object storage provider selection (AWS S3 vs. Cloudflare R2).
- Production hosting provider selection.
- Production parent domain purchase.
- Legal review of 90-day financial record retention safety.
- Platform audit-log retention period and legal/compliance policy.
The previously open **online entitlement-activation** question is resolved by
BR-053: successful online payment confirmation activates entitlement creation.
That item is no longer open.
