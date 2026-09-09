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

- **ADR-032 — [2026-08-26] — Catalog Critical Serialization Transport:**

`bingocommerce-catalog` may use a narrowly scoped captured-mysqli transaction
transport only for approved concurrency-critical Catalog mutations that
require shared row serialization. The initially approved uses are:

1. WooCommerce-product exclusivity between `bingo_play_products` and
   `bingo_packages`; and
2. Bingo Play Product-to-Game Type reference integrity against future Game
   Type hard deletion.

The transport uses the existing `$wpdb->dbh` connection, requires that handle
to be `mysqli`, and MUST NOT open a second database connection. Every value is
bound through native prepared statements. The transaction operates only in a
verified current hall/site context, follows the deterministic Catalog lock
order below, and fails closed if the required transport or trustworthy
connection state cannot be established.

Only the following statements are permitted inside this critical transaction:

- `BEGIN`;
- `COMMIT`;
- `ROLLBACK`;
- `SELECT ... FOR UPDATE`; and
- concurrency-critical authoritative DML against custom Catalog tables.

The linked WordPress/WooCommerce product row may be locked and minimally read
when it is the approved serialization anchor, but MUST NOT be mutated inside
the Catalog critical transaction. WooCommerce CRUD validation and preflight
occur before the transaction; only minimal row-level revalidation occurs while
the lock is held. The transaction MUST NOT contain WooCommerce CRUD writes,
WordPress or WooCommerce metadata writes (including `bc_product_kind` or
`bc_package_relation` projections), AuditLog writes, hooks, callbacks, emails,
cache mutations, UI effects, unrelated WordPress writes, or arbitrary
application callbacks.

This is not a generic `atomic(callable)`, a generic transaction helper for
other plugins, `HallStaffService` transport, or ADR-025 mandatory-audit
transport. It MUST NOT introduce or inherit an `operation_id`, commit witness,
ADR-025 reconciliation protocol, mandatory-audit atomicity, Hall Staff class,
or retry token.

Commit results use this contract:

- confirmed `COMMIT` acknowledgement means success;
- definite failure or confirmed rollback means failure; and
- ambiguous `COMMIT` acknowledgement returns
  `catalog_commit_outcome_unknown`.

For an ambiguous `COMMIT`, the caller MUST NOT report success, infer success
from current Catalog state, automatically retry the mutation, or emit an
ordinary audit event claiming that the mutation was confirmed. Current state
may have been produced by a different operation and is not proof that this
specific operation committed. Positive operation-specific reconciliation
would require durable operation identity and is intentionally not justified
for these ordinary, low-frequency Catalog administrative mutations.

The current site's WooCommerce product row in the WordPress posts table is the
approved serialization anchor for Package-versus-Play-Product claiming. This
is a WordPress-owned row and may contend with WooCommerce or WordPress product
administration. The transaction therefore holds that row lock for the shortest
practical duration. Before Package/Play claiming is considered complete,
operational tests MUST exercise concurrency with product administration, REST
updates, and product-status changes.

The canonical Catalog lock order is:

- **Play Product creation:** Woo product row; target Game Type row when
  required; authoritative Package/Play claim checks; insert.
- **Existing Play Product mutation when Woo identity must be revalidated:**
  Woo product row; existing Play Product row; target Game Type row when
  required; any additional authoritative claimant/reference rows; write.
- **Existing Play Product mutation that does not require Woo identity:**
  existing Play Product row; target Game Type row when required; write.

No Catalog writer may acquire an earlier-ranked lock after holding a
later-ranked lock. If one mutation ever needs multiple Game Type rows, it locks
them in ascending `game_type_id` order. Future `PackageService` product claims
begin by locking the same Woo product row.

Play Product Game Type reference creation/reassignment and future Game Type
hard deletion serialize on the same Game Type row lock. Future hard deletion
locks the Game Type row, performs an indexed existence check for every Play
Product reference (including archived rows), and then deletes or rejects. It
MUST NOT subsequently lock Play Product rows in reverse order. Game Type hard
delete remains disabled until the complete reference-writer/delete contract
and its concurrency tests are implemented.

The Catalog transport owns its supported transaction boundary. Supported
Catalog code MUST NOT nest entry into that boundary, and the transport/API
must prevent its own nested use. PHP/mysqli is not assumed to reliably expose
whether an arbitrary external transaction is already active on the captured
connection. Before implementation, actual runtime/mysqli behavior must be
inspected and only reliable transaction-state checks may be used; a fabricated
PHP-side transaction-state detector is prohibited. If connection state is
unsafe or cannot be trusted for the requested boundary, the operation fails
closed.

This decision is separate from ADR-025. ADR-025 provides security-sensitive
mandatory-audit atomicity, durable operation identity, and reconciliation for
Hall Staff mutations. ADR-032 provides only short relational concurrency
serialization for the approved Catalog invariants. Ordinary Catalog audit
events, when required under ADR-023, occur outside this transaction only after
a confirmed commit and continue through the canonical AuditLog path protected
by ADR-024. ADR-031's relational Catalog authority remains unchanged, and its
prohibition on a direct-SQL transaction spanning Catalog rows and WooCommerce
projection metadata remains in force.

*Rationale:* ADR-031 requires cross-table Package/Play-Product exclusivity and
restrictive reference integrity to be concurrency-safe. A short transaction on
the existing WordPress database connection provides one deterministic
serialization boundary without adding a competing authority table, extending
ADR-025's security-sensitive semantics, or bringing WordPress/WooCommerce side
effects into the critical transaction.

- **ADR-033 — [2026-08-27] — Package Composition Atomicity Transport:**

`bingocommerce-catalog` may use a second narrow captured-mysqli transaction
boundary, distinct from ADR-032, for one purpose only: replacing a Bingo
Package's base composition as a single all-or-nothing mutation.

*Why a boundary is required.* BR-061 derives Package type from the complete base
composition, so `bingo_package_components` rows and `bingo_packages.package_type`
must never be observable in disagreement. A component-set replacement decomposes
into deletes, inserts, updates and a type rewrite; applying part of that set
would persist a Package that no Hall Manager configured and that BR-061 cannot
classify. Additionally, two Hall Managers editing one Package must not silently
overwrite each other.

**Execution mechanism: the captured `$wpdb->dbh` mysqli handle, exactly as
ADR-032 uses it. Ordinary `$wpdb->query()` is PROHIBITED for any statement
issued while this transaction is open.**

That prohibition is a measured finding, not a precaution. `wpdb::query()`
executes `apply_filters( 'query', $query )` on every statement it runs,
including `BEGIN`, `COMMIT`, `ROLLBACK`, and every in-transaction statement. Any
plugin may register on that hook, and a callback there can both execute
arbitrary code and rewrite the SQL string before it reaches the server. The same
path can additionally invoke `strip_invalid_text_from_query()`, which issues
further queries of its own and calls `wp_load_translations_early()` and `__()`,
firing the `gettext` filters; and `log_query()` fires
`apply_filters( 'log_query_custom_data', ... )` whenever `SAVEQUERIES` is
enabled. `$wpdb->query()` is therefore not callback-free and cannot carry an
open Catalog transaction. The captured mysqli handle bypasses `wpdb` entirely
and fires no WordPress hook.

Only the following may execute inside the Package-composition transaction:

- `BEGIN`, `COMMIT`, `ROLLBACK`;
- `SELECT ... FOR UPDATE NOWAIT` against the parent `bingo_packages` row;
- `SELECT` against `bingo_package_components` for the current composition;
- `SELECT ... FOR UPDATE NOWAIT` against every `bingo_play_products` row whose
  current state the mutation depends on;
- `INSERT` / `UPDATE` / `DELETE` against `bingo_package_components`; and
- `UPDATE` of `bingo_packages.package_type` and `updated_at` as part of the same
  mutation.

Every explicit locking READ uses `FOR UPDATE NOWAIT`, matching ADR-032, so a
contended anchor fails fast rather than parking on
`innodb_lock_wait_timeout`.

That does NOT make the boundary wait-free. `INSERT`, `UPDATE` and `DELETE` have
no `NOWAIT` option in MySQL, and measurement on MySQL 9.5 confirms in-boundary
DML can both wait and deadlock between two CONFORMING Catalog writers:

- two Package creations for DIFFERENT WooCommerce products against an empty
  `bingo_packages` table both acquired their `wc_product_unique` claim locks
  with `NOWAIT` successfully — gap locks are mutually compatible — and then
  deadlocked on the subsequent inserts, because an insert-intention lock
  conflicts with the other transaction's retained gap lock (errno **1213**);
- two `replaceComponents` deletes against DIFFERENT packages produced a lock
  wait timeout (errno **1205**).

The tested empty-table case exposed contention that was not observed in the
corresponding populated-table run. This case is operationally important because
`bingo_packages` begins empty when Package Increment 1 is first introduced. The
experiment does not establish a universal worst-case table state.

Deadlock and lock-wait between conforming Catalog writers are therefore EXPECTED
and DESIGNED-FOR, not impossible. InnoDB's deadlock detector aborts one
transaction, and the bounded retry budget over errnos 1205, 1213 and 3572 is the
mechanism that resolves it. That retry budget is correctness-bearing for
liveness, not a latency optimization, and retry exhaustion legitimately surfaces
as `catalog_write_conflict`. The canonical lock order remains required because it
reduces cycle frequency, but it must not be described as eliminating cycles.

`replaceComponents()` acquires locks in exactly this order:

1. the parent `bingo_packages` row;
2. read the current composition and verify the expected fingerprint;
3. determine which `bingo_play_products` rows the mutation depends on;
4. lock those rows in ascending `bingo_play_product_id` order;
5. validate under those locks;
6. mutate `bingo_package_components`;
7. recompute `package_type` from the `medium` values read under those locks;
8. `UPDATE bingo_packages.package_type` and `updated_at`;
9. `COMMIT`.

Validation under lock distinguishes two cases:

- a **newly added** Bingo Play Product must exist and must be `active` at the
  moment its row is locked; and
- an **already-existing** component may remain referenced even if its Bingo Play
  Product has since been archived. Archive never silently detaches a component
  (BR-067).

An already-existing component whose Bingo Play Product is archived may be
**retained unchanged or removed**, but its **quantity may not be changed**; a
quantity change on an archived component is rejected with
`play_product_not_active`. Retention is preservation, which BR-067 requires;
altering how much of an inactive product a Package contains is a new authoring
decision about unsellable inventory, and is therefore treated the same as adding
a new reference. A Hall Manager who needs that change restores the Bingo Play
Product first.

The transaction MUST NOT contain WooCommerce CRUD, `wp_posts` or `postmeta`
mutation, AuditLog writes, BR-032/BR-065 projection writes, hooks, callbacks,
translation calls, emails, cache mutations, logging callbacks, UI or
presentation work, or any `bingocommerce-core` Hall Staff transaction mechanism.
Error objects constructed inside the boundary MUST be built without `__()`,
`_x()`, or any other translation call, for the reason stated above;
localization happens in the presentation layer after the boundary closes.

`package_type` is computed ONLY from the `medium` values read from the locked
`bingo_play_products` rows inside the transaction. Values observed during
preflight are advisory validation only and are never authoritative for
derivation. This applies to composition replacement and equally to the initial
composition written during Package creation.

The lock set and the derivation set are deliberately different. Locking covers
the UNION of the currently referenced and the requested Bingo Play Product IDs,
because a row being removed must also be held still while the mutation runs.
Derivation, however, considers ONLY the Bingo Play Products that remain in the
FINAL REQUESTED component set. A component being removed is locked but MUST NOT
contribute to `package_type`.

For example, a Package whose current composition is Paper A plus Digital B, with
a requested set of Paper A alone, locks both A and B and derives
`package_type = paper`.

The `$active` nested-use guard and the `$untrusted` poisoned-session flag are
shared with ADR-032, because both boundaries live in the one Catalog transport
class. A Package composition transaction therefore cannot begin while an
ADR-032 boundary is open in the same request, and a session marked untrusted by
either boundary is untrusted for both. Two independent guard pairs are
prohibited: they would let one boundary nest inside the other undetected.

Two distinct concurrency controls operate together and are not
interchangeable:

- the parent `bingo_packages` row lock provides **serialization**, so two
  writers cannot interleave; and
- a canonical composition fingerprint provides **stale-edit detection**, so a
  Hall Manager submitting a form built from an older composition is rejected
  rather than silently overwriting a newer committed edit.

`bingo_packages.updated_at` MUST NOT be used as the sole optimistic-concurrency
token, because the approved schema's `datetime` precision does not guarantee
distinct values for rapid consecutive edits.

Commit results reuse ADR-032's contract exactly: a clean acknowledgement is
success; a rejection detected before `COMMIT` followed by a confirmed rollback
is an ordinary failure; a failed `ROLLBACK` marks the session untrusted and is
never retried; and an unclean or interrupted `COMMIT` acknowledgement returns
`catalog_commit_outcome_unknown` with no state inference, no retry, and no audit
event.

This decision is NOT ADR-025: it introduces no `operation_id`, no commit
witness, no retry token, no mandatory-audit atomicity, no reconciliation
protocol, and no Hall Staff class. It is NOT a generic `atomic(callable)` or
transaction-callback API, and MUST NOT be offered to other plugins or to
arbitrary Catalog code. ADR-032 remains the sole mechanism for WooCommerce
product classification serialization between `bingo_play_products` and
`bingo_packages`; ADR-033 never claims a WooCommerce product and never locks the
`wp_posts` anchor. Ordinary ADR-023 audit events occur outside both boundaries,
only after a confirmed commit, through the canonical AuditLog path protected by
ADR-024.

Package creation is deliberately excluded from ADR-033. A Package may never
persist as an active zero-component object, so its initial component rows are
inserted inside the single ADR-032 Package-create profile as part of creating
one valid Package. That narrow allowance applies to creation only and does not
authorize later composition replacement to use ADR-032.

That create profile MUST also lock every referenced component
`bingo_play_products` row, in ascending `bingo_play_product_id` order, and
verify under those locks that each exists and is `active`, before inserting the
Package or its components. Preflight state is never authoritative for creation.
The resulting concurrency behaviour is deterministic: if Package creation
acquires those locks and commits first, a subsequent Bingo Play Product archive
proceeds and the existing relationship is preserved (BR-067); if the archive
commits first, Package creation observes the archived state under lock and
rejects the new reference.

*Rationale:* BR-061's Package-type invariant spans several rows, so composition
replacement needs atomicity, while the classification claim it does not touch
needs the different guarantee ADR-032 already provides. Adding a second narrow,
explicitly bounded transport is safer than widening ADR-032's approved uses or
routing multi-row Catalog writes through a `$wpdb` path that can execute
third-party callbacks mid-transaction.


- **ADR-034 — [2026-08-28] — Play Product Medium Reference Integrity:**

BR-069 forbids a Bingo Play Product's `medium` from changing while any
`bingo_package_components` row references it, because persisted
`bingo_packages.package_type` is derived from component media (BR-061). This
entry decides the mechanism that enforces it.

ADR-034 adds a THIRD approved use to ADR-032's captured-mysqli transport:

3. Bingo Play Product medium-change integrity against Package-component
   references.

ADR-032's first two approved uses, its permitted statement set, its canonical
lock order, its `FOR UPDATE NOWAIT` policy, its bounded retry budget, its
failed-ROLLBACK rule, and its commit contract are unchanged and are inherited
here in full. This entry does not edit ADR-032; it extends its approved use
list.

Both medium directions are covered and both serialize on the Play Product's own
`bingo_play_products` row:

- Paper to Digital already runs inside the ADR-032 transport and gains the
  reference check.
- Digital to Paper MOVES OUT of the ordinary single-statement `$wpdb` path and
  into the ADR-032 transport. It is no longer an ordinary Catalog mutation.

The fixed medium-change profile is:

1. `BEGIN`;
2. lock the target `bingo_play_products` row `FOR UPDATE NOWAIT`;
3. verify the current medium and that the requested transition applies;
4. perform all transition-specific validation, including Paper-to-Digital's
   existing requirement of an explicit active Game Type;
5. determine whether any `bingo_package_components` row references the Play
   Product, using `SELECT ... FOR UPDATE NOWAIT`;
6. if referenced: `ROLLBACK` and return `play_product_package_referenced`;
7. apply the medium mutation;
8. `COMMIT`;
9. emit the ordinary ADR-023 audit event outside the transaction, only after a
   confirmed commit.

No `operation_id`, commit witness, reconciliation token, retry token, or Hall
Staff semantics are introduced. This is not ADR-025 and not a generic
transaction API.

*The component-reference check is a locking read, and that is a measured
decision.* The Play Product row is already the shared serialization anchor:
Package creation locks it before inserting a component, `replaceComponents`
locks it before inserting, retaining or removing one, and the medium change
locks it before checking. A plain non-locking `SELECT` was therefore tested as a
possible simplification. Across REPEATABLE READ and READ COMMITTED, and across
every required interleaving — Package writer first, medium writer first,
multiple references, replacement adding a reference, and replacement removing
the last reference — the plain `SELECT` was correct in all fourteen measured
runs, PROVIDED it was the transaction's first consistent read.

That proviso is what disqualifies it. A separate measured case showed that if
any non-locking read occurs earlier in the same transaction, InnoDB opens the
REPEATABLE READ consistent snapshot at that earlier point, and the later plain
`SELECT` then observed ZERO references while a committed reference existed —
silently permitting exactly the medium change BR-069 forbids. The same case was
safe under READ COMMITTED, so the failure is isolation-dependent as well as
ordering-dependent. Encoding a correctness requirement that depends on no
future profile ever adding a preceding consistent read is a fragile contract for
a durable architecture document. The `bingo_play_products` row remains the
serialization anchor; the Package-component reference check is a SEPARATE
locking read against `bingo_package_components`, on rows the anchor does not
cover. That separate locking read is retained precisely because it is a current
read: it observes the latest committed component state regardless of when the
transaction's consistent snapshot was opened, and therefore regardless of
isolation level. It is mandatory, not optional.

*Rejected alternative, recorded with its measured reason.* A single autocommit
`UPDATE ... WHERE ... AND NOT EXISTS (SELECT 1 FROM bingo_package_components
...)` was evaluated as a narrower mechanism that would have left Digital to
Paper on the ordinary `$wpdb` path. Under REPEATABLE READ it behaved correctly
in every interleaving, because the `NOT EXISTS` subquery performs a locking read
and blocks on the uncommitted component row. Under READ COMMITTED, real MySQL
9.5 testing produced an invariant violation in three of three runs of the real
writer shape: the statement blocked on the Play Product row lock, and on being
granted it re-evaluated only the row predicate while reusing the stale subquery
result, committing the medium change despite a committed Package reference.
Production hosting and database defaults are not yet locked (ADR-013 remains
open), several managed MySQL services default to READ COMMITTED, and ADR-032's
transport does not currently assert or pin an isolation level. BR-069 must not
depend on an external default isolation level, so the guarded single-statement
form is rejected. WordPress `query`-filter callbacks were NOT the reason for
this rejection; for a single autocommit statement with no application-held
transaction they create no domain atomicity problem.

Asserting or pinning the transaction isolation level inside the ADR-032
transport remains a separate technical follow-up. It is not a prerequisite for
BR-069, because this entry's mechanism is isolation-independent.


- **ADR-035 — [2026-09-03] — Portal Integration Authority, Identity, and Commerce Occurrence Boundary:**

BingoCommerce integrates with the existing Laravel Bingo Innovation Portal
without absorbing the Portal's operational domain or transferring commerce
authority into the Portal.

All Portal integration identities in this decision belong to the single
authoritative Bingo Innovation Portal environment. Portal Hall identity is
`halls.id`, Portal Game Type identity is `game_types.id`, and Portal Session
template identity is `bingo_sessions.id`. Names are attributes and MUST NEVER
be used as cross-system identity.

**System authority.** The Portal is authoritative for Hall identity, Game Type
identity and configuration, operational Session-template identity, the
Portal's existing operational Session `days`, and the existing Games,
Patterns, Caller, device, XML, and other operational functionality.
BingoCommerce remains authoritative for WooCommerce products, Bingo Play
Products, Packages, pricing, inventory and commerce configuration; commerce
weekdays; recurrence choice and boundaries; specific non-recurring commerce
dates; structured commerce time; generated dated commerce occurrences;
Package-to-occurrence availability; five-day sales eligibility; checkout,
orders and payments; and future entitlement, QR, and redemption commerce
state.

**Hall identity.** A Portal Hall is mapped to exactly one BingoCommerce
Multisite tenant by immutable identifiers:

```text
Portal halls.id <-> WordPress Site ID
```

Hall names, WordPress site names, subdomains, and custom domains are attributes,
not integration identity. The exact mapping persistence, provisioning workflow,
and lifecycle reconciliation are deferred to the integration implementation
design. ADR-001 and ADR-022's one-hall-per-site and tenant-isolation rules
remain in force.

**Game Type identity and v1 lifecycle.** Portal `game_types.id` is the trusted
cross-system Game Type identity. Portal Game Type creation and rename are
allowed. A name is an editable attribute, and rename preserves `game_types.id`.
Once created, a Portal Game Type cannot be deleted. Portal Archive and Restore
are not required in v1. `device_group_id` remains hardware/XML/device-only and
MUST NEVER be used as a BingoCommerce integration identity. XML-generated
GameGroup identifiers MUST NEVER be BingoCommerce integration identities.

BingoCommerce retains its hall-scoped Game Type domain as a local replica for
commerce relationships and runtime use, linked to Portal `game_types.id`. The
existing local surrogate ID and machine code may remain internal implementation
identifiers, but neither is the trusted cross-system identity. Exact link
persistence and synchronization columns are deferred. Existing local
create/edit/archive/restore and currently disabled hard-delete behavior is not
removed or redesigned by this documentation decision. It is transitional local
BingoCommerce behavior, not the Portal v1 lifecycle, and will be reconsidered
when Portal synchronization becomes authoritative. Local writer restrictions
apply only after authenticated synchronization, idempotent mapping,
reconciliation, and operational rollback/recovery have been proven.

**Session and commerce-occurrence identity.** Three separate concepts MUST NOT
be conflated:

1. A Portal Session template, identified by `bingo_sessions.id` and owned by its
   Portal Hall through `bingo_sessions.hall_id`.
2. A BingoCommerce commerce schedule linked to that template and owning
   commerce weekdays, recurring yes/no, recurrence start/end dates, specific
   non-recurring dates, and structured commerce time.
3. A BingoCommerce dated commerce occurrence generated from that commerce
   schedule and used for Package availability, five-day sales eligibility,
   purchase attribution, and future entitlement/redemption state.

Only Sessions owned by the mapped Portal Hall are eligible for v1 commerce
sync. Received/shared Sessions are excluded; integration eligibility MUST NOT
use the Portal equivalent of `allSessions()`. The conceptual eligibility rule
is `bingo_sessions.hall_id == mapped halls.id`; this is an authority boundary,
not an API query or schema implementation decision. Portal Session `days` are
independent from BingoCommerce commerce scheduling in v1 and MUST NOT drive
Commerce scheduling, generate Commerce occurrences, or constrain or validate
Commerce scheduling. Future advisory validation between Portal operational
days and Commerce scheduling MAY be introduced only by a separately approved
future requirement.

Customers purchase against and orders retain the identity of the exact dated
commerce occurrence, not merely the Portal Session template or its schedule.
For occurrence date `D`, BR-051/BR-063's hall-local inclusive five-day sales
predicate and Available for Sale rule remain in force. Structured commerce time
is owned by BingoCommerce and may be entered manually by the Hall Manager in v1,
but display-name text remains non-authoritative and MUST NOT be parsed for time
or automatic behavior. Database columns, UI controls, storage schema, and
timezone implementation remain deferred to the compatibility/scheduling design.

One Package may be assigned to multiple dated commerce occurrences. No one-
Package-to-one-occurrence restriction is introduced. Existing Package/Session
relationship work is preserved where compatible.

The implemented `bingo_sessions` and `bingo_session_packages` model is retained
until a schema-specific compatibility/refactoring review determines how it can
represent or transition to the template/schedule/occurrence model. This ADR does
not delete or replace either table, authorize a destructive migration, or
discard compatible Package/Session relationship work.

**Synchronization direction and delivery sequence.** The approved integration
shape is an authenticated Portal read API plus Portal webhooks, BingoCommerce
hall-scoped local replicas, and reconciliation. Delivery order is:

1. authenticated read API;
2. initial/full pull synchronization;
3. prove idempotent Hall, Game Type, and Session-template mapping;
4. webhooks;
5. reconciliation;
6. tenant provisioning; and
7. SSO.

No API contract, authentication credential, webhook payload, retry policy,
mapping table, reconciliation algorithm, provisioning lifecycle, or SSO
protocol is selected here. Each requires its own reviewed implementation design.
Existing ADR-011 REST hardening remains applicable to any future
BingoCommerce-owned endpoint, but this ADR does not implement or select an
endpoint.

**Commerce enablement and SSO.** `Enable BingoCommerce` is a separate platform
capability authorized only by a Portal Global Admin. It MUST NOT inherit normal
Portal Hall Settings authorization. Hall Managers/Admins may manage commerce
only after the platform has enabled their Hall. The detailed enablement and
provisioning lifecycle is deferred. Future SSO is approved only as a requirement
for a short-lived secure handoff from an authenticated Portal staff session to
the correct mapped BingoCommerce tenant; SSO is not in the first integration
increment, and protocol details are deferred.

**V1 exclusions.** Individual Games, Patterns, Caller/device data, Portal users,
Portal Session `days`, `device_group_id`, and XML GameGroup IDs are not synced.
Add-ons, QR, customer entitlement, and electronic-capacity implementation are
not part of this integration decision; their existing independent rules are
neither implemented nor altered here.

**Relationship to earlier decisions.** This entry:

- narrows ADR-026 only for Game Type source authority: the Catalog plugin keeps
  the local domain/replica boundary, while Portal owns identity/configuration;
- supersedes ADR-027 only where it makes Game Types one-time, independently
  editable, unsynchronized hall copies; its WooCommerce Product and Bingo Play
  Product starter-copy decisions remain in force;
- narrows ADR-028 and ADR-031 only where they assign canonical Session identity
  to BingoCommerce: that authority now describes the dated commerce occurrence,
  not the Portal operational Session template;
- clarifies ADR-030's tenant/session/caller/Game-Type accumulation scope so its
  Session component is the exact dated commerce occurrence; ticket, QR,
  device-binding, and concurrency decisions remain unchanged;
- narrows ADR-031's Game Type authority in the same manner, without changing its
  Package, Bingo Play Product, WooCommerce shell/projection, per-site isolation,
  or concurrency decisions; and
- leaves ADR-001, ADR-004, ADR-022, and ADR-032 through ADR-034's existing
  concurrency/transaction mechanics unchanged. Earlier local Game Type hard-
  delete design remains transitional BingoCommerce behavior and does not create
  a Portal v1 delete, Archive, or Restore feature.

*Rationale:* Stable Portal identifiers preserve identity across editable names,
while a separate commerce schedule and dated-occurrence model prevents Portal
operational scheduling fields from silently becoming commerce rules. Local
replicas allow BingoCommerce to keep tenant-safe commerce relationships and
runtime behavior without making names or hardware identifiers authoritative.
Deferring schema and protocol choices prevents this authority decision from
destructively predetermining the compatibility work.

- **ADR-036 — [2026-09-04] — Portal Hall Snapshot Transport URL Contract:**

`BINGOCOMMERCE_PORTAL_BASE_URL` is the Portal application root URL. It MAY
include the Portal application's deployment subpath, but MUST NOT include the
`/integration` API prefix. For example, `https://portal.example.com` and
`https://example.com/portal` are valid application-root forms. The
`PortalHttpClient` appends the fixed, versioned Hall snapshot path:

```text
/integration/v1/halls/{portalHallId}/snapshot
```

The configured base URL is not automatically stripped or otherwise corrected
when misconfigured. Portal credentials remain server-side and are sent only in
the HTTP `Authorization` header, never in the URL, path, query string, or
request body.

This decision covers only Phase 3D1 transport URL semantics and credential
placement. It does not authorize snapshot decoding or validation, persistence,
synchronization/import, webhooks, SSO, or provisioning.

**Relationship to earlier decisions.** This entry resolves only ADR-035's
previously deferred endpoint-path question for the Portal Hall snapshot
transport. ADR-035's historical deferral was correct when recorded, and all
other authority, identity, sequencing, tenant-isolation, and future-phase
boundaries remain unchanged.

*Rationale:* Treating the configured value as the application root supports
both root deployments and explicit deployment subpaths while assigning the
versioned integration route to the client that owns that operation. Excluding
the API prefix prevents ambiguous or duplicated paths such as
`/integration/integration/v1/...`.

- **ADR-037 — [2026-09-04] — Portal Hall Snapshot v1 Validation Contract:**

The Portal Hall snapshot v1 payload contract and its BingoCommerce validation
boundary are decided. `PortalSnapshotValidator` converts the raw HTTP 200 body
returned by ADR-036's transport into a trusted, reconstructed in-memory array or
a `WP_Error`. It is deterministic, read-only, and side-effect-free.

**Exact v1 allowlist.** The envelope carries exactly `api_version`,
`generated_at`, `hall`, `game_types`, and `sessions`. The Hall object carries
exactly `id`, `name`, `state`, and `updated_at`. Each Game Type carries exactly
`id`, `hall_id`, `name`, and `updated_at`. Each Session template carries exactly
`id`, `hall_id`, `name`, `status`, and `updated_at`.

**Strict rejection.** A missing required member and an unknown member are both
fatal at every level, and the complete snapshot is rejected. Partial acceptance
is prohibited. Operational Portal fields — including `days`, `type`,
`paper_type`, `allow_sleeper`, `notes`, `sort_order`, `created_by`, `games`,
`patterns`, `caller`, `device_group_id`, XML GameGroup identifiers, `deleted_at`
and `game_type_ids` — are never accepted and reject the snapshot if present.
Duplicate JSON object member names are not separately detected: PHP's native
decoder is the approved parser, and no custom JSON parser is introduced.

**Version.** `api_version` must be exactly the string `v1`.

**Timestamps.** Portal timestamps are serialized as `YYYY-MM-DDTHH:MM:SS±HH:MM`
with no fractional seconds and an explicit numeric offset; a `Z` suffix is not
accepted. `generated_at` is required and non-null. `hall.updated_at`,
`game_types[*].updated_at` and `sessions[*].updated_at` are required keys whose
value may be null. A non-null value is validated by anchored shape matching,
strict parsing, parse warning/error inspection, and exact round-trip equality,
so a silently normalized impossible date or time is rejected. The UTC offset is
additionally range-checked structurally — offset hour `00`-`23` and offset
minute `00`-`59` — because PHP's parser accepts and round-trips impossible
offsets such as `+25:00` without raising a warning. That bound is the numeric
shape of an Atom offset and is deliberately NOT a Portal-specific
`-12:00`..`+14:00` timezone range: `+23:59` is structurally valid, while
`+24:00`, `+25:00` and `+10:60` are rejected. A valid timestamp string is
preserved verbatim; no timezone conversion, UTC normalization, or MySQL
`DATETIME` conversion happens here.

**Identity and tenancy.** Every Portal identity must be a native integer `>= 1`
after safe decoding; numeric strings, floats, booleans, and oversized JSON
integers are contract violations, never values to coerce. The validator receives
the expected Portal Hall ID from trusted mapping/orchestration context and
requires `hall.id`, every `game_types[*].hall_id`, and every
`sessions[*].hall_id` to equal it. Tenant identity is never derived from a name,
domain, host, request URL, or list ordering. Any mismatch rejects the complete
snapshot.

**Collections.** `game_types` and `sessions` may be empty. Input order is
preserved exactly and sorting is never required or applied. Within each
collection every `id` must be unique; duplicates reject the snapshot and are
never deduplicated or merged. The same numeric value may legitimately appear
once in each independent identity domain. Names are never uniqueness keys.

**Names and enums.** Hall and Session names allow at most 255 Unicode
characters, Game Type names at most 50, counted as characters and never as
bytes. A name that is empty or whitespace-only is rejected, matching Portal
validation semantics. Valid names are returned unchanged — never trimmed,
sanitized, escaped, or normalized; escaping belongs to presentation boundaries.
Hall `state` is exactly `active` or `inactive`; Session `status` is exactly
`active` or `draft`. Enum matching is case-sensitive and an unknown value is
never mapped into a known one.

**Retention.** An inactive Hall snapshot is valid and retained. A draft Session
is valid and retained.

**Decoding.** Decoding uses PHP's native JSON decoder with `JSON_THROW_ON_ERROR`
and `JSON_BIGINT_AS_STRING`, and objects decoded as objects so `{}` and `[]`
remain distinguishable. The invalid-UTF-8 tolerance flags are prohibited. The
implementation explicitly passes PHP's standard decoder depth of `512`, because
the flags argument follows the depth argument positionally. That `512` is a
technical decoder safety bound only: it carries NO Portal v1 business or API
meaning, BingoCommerce derives no domain semantics from it, and no
BingoCommerce domain constant is introduced for it. Changing this technical
bound later requires implementation review, but does NOT by itself require a
Portal API-version change. A body nested beyond the configured decoder depth
fails safely as invalid JSON. No raw-body byte-size limit is introduced here,
because the body has already been received by the transport; any HTTP
response-size cap belongs to a separately reviewed transport hardening change.

**Error surface.** The public error vocabulary is exactly
`invalid_portal_hall_id`, `portal_snapshot_invalid_json`,
`portal_snapshot_invalid_contract`, `portal_snapshot_unsupported_version`,
`portal_snapshot_hall_mismatch`, and `portal_snapshot_duplicate_identity`. Error
data may carry only a validator-generated structural path such as
`sessions[2].hall_id`. Raw bodies, decoded structures, invalid rows, Portal
names, credentials, request arguments, and upstream responses must never appear
in an error message, error data, or a log.

**Boundary.** This decision authorizes validation only. It does NOT authorize
database writes, snapshot application or import, missing-record handling,
currentness mutation, `portal_updated_at` persistence, reconciliation, Hall
mapping mutation, Commerce scheduling, webhooks, SSO, or provisioning. Phase 3E
remains responsible for snapshot application/import, currentness, and
reconciliation.

**Relationship to earlier decisions.** This entry resolves only ADR-035's
deferred Hall snapshot payload/validation contract and builds on ADR-036's
transport. ADR-035's and ADR-036's authority, identity, sequencing, tenant-
isolation, and future-phase boundaries remain unchanged, and neither entry is
edited. Other Portal endpoints and payloads remain deferred.

*Rationale:* An exact allowlist with strict unknown-field rejection means a
field the Portal adds later cannot silently reach commerce code, and a partial
or foreign-tenant document cannot be half-accepted. Validating identity as
native integers and tenancy against trusted context keeps cross-hall data
leakage — the platform's highest-severity bug class — out of the first
increment. Freezing this contract before any writer exists means Phase 3E can be
reviewed as a persistence decision alone, on data already proven trustworthy.

- **ADR-038 — [2026-09-07] — Portal v1 Snapshot Completeness and Game Type Absence:**

**Completeness.** A successful Portal v1 snapshot is a complete, non-paginated,
non-delta snapshot for one trusted mapped Hall. `game_types` contains every
Portal Game Type owned by that Hall. `sessions` contains every qualifying
owner-only Portal Session template; shared and received Sessions remain
excluded. Empty collections are authoritative. Snapshot membership represents
one logically consistent source boundary.

Phase 3D2 validates structure but cannot independently prove completeness.
Completeness is guaranteed by the Portal snapshot endpoint contract. This
decision adds no payload fields and does not change ADR-037's validation rules.
Phase 3E must consume only Phase 3D2 validated data.

**Game Type absence.** Portal Game Type deletion is prohibited in v1. Portal
Game Type Archive/Restore is not part of v1. Therefore, a previously linked
Portal Game Type missing from a later successful complete snapshot for the
same trusted mapped Hall is a contract/integrity violation. Phase 3E MUST
reject the entire application attempt and perform zero writes. The local Game
Type row, ID, code, name, status, references, and timestamps remain unchanged.
Missing Game Types MUST NOT be deleted, archived, unlinked, or marked
not-current. Game Type `portal_is_current` or `portal_not_current_at` fields
are not required for v1.

An authoritative empty `game_types` collection does not override this rule:
if that Hall has a previously linked Portal Game Type, the empty collection
requires rejection of the entire application attempt with zero writes. A later
valid snapshot containing the same Portal ID may be processed normally;
identity matching remains based only on Portal `game_types.id` within the
trusted mapped Hall.

**Absence safety.** HTTP failure, validation failure, wrong-Hall data,
incomplete responses, or application failure never create missing-record
meaning. Names and codes are never used to determine whether a record
disappeared. Duplicate Portal names with different IDs remain valid and
distinct.

**Authority and scope.** No Commerce scheduling or product/order authority
changes. Portal Session `days` remain excluded from Commerce scheduling,
occurrence generation, and scheduling constraints or validation. This is a
documentation-only decision; it does not implement Phase 3E or authorize PHP,
test, migration, plugin, or schema changes.

**Remaining pre-Phase 3E design work.** This slice does not decide:

- exact duplicate-name persistence/index design;
- imported internal-code allocation;
- snapshot replay/stale-order protection;
- Phase 3E transaction and locking mechanics;
- local writer restrictions after synchronization; or
- Session-template application behavior beyond already-frozen decisions.

**Relationship to earlier decisions.** This entry resolves snapshot
completeness and the previously open Game Type absence/currentness questions
for v1. It narrows ADR-035's Portal Game Type Archive/Restore wording from
not required to not part of v1. ADR-035's authority, identity, and delivery
sequence and ADR-036/ADR-037's transport and validation boundaries remain in
force. Existing ADRs are not rewritten.

*Rationale:* A complete source collection combined with the v1 prohibition on
Game Type deletion makes a missing linked identity an integrity violation,
not a local lifecycle transition. Rejecting the entire application with zero
writes preserves existing commerce identities and relationships.

- **ADR-039 — [2026-09-07] — Portal Game Type Names, Imported Internal Codes, and Catalog v5 Design:**

**Status and scope.** Approved design, not implemented. Catalog schema will
advance from v4 to v5 in a separately reviewed implementation. This entry
resolves ADR-038's duplicate-name persistence/index and imported-code questions
and narrowly amends ADR-037's name boundary before Phase 3E. Earlier ADRs are
not rewritten; ADR-038's completeness and zero-write absence rules remain in
force. No Commerce scheduling or product/order authority changes.

**Name storage.** Keep `name varchar(160) NOT NULL` as the actual display/source
name. Add ordinary nullable `local_name_key varchar(160) NULL`, using the same
charset and collation as `name`. Locally created rows whose
`portal_game_type_id IS NULL` store their exact stored name in this key;
Portal-linked rows store SQL `NULL`. Writers must maintain this invariant on
creation and rename. The unique key protects local-versus-local names,
including archived local rows; it does not itself enforce key population.

Remove `UNIQUE name_unique(name)`. Add
`UNIQUE local_name_unique(local_name_key)` and nonunique
`name_id(name, game_type_id)`. Preserve the existing primary key,
`portal_game_type_unique`, `code_unique`, and `status_name` indexes. Valid Portal
names remain unchanged. Duplicate and collation-equivalent Portal names with
different Portal IDs remain separate rows. Never link, merge, or overwrite
rows using names; trusted Hall context and Portal `game_types.id` establish
cross-system identity.

**Portal name validation prerequisite.** Before Phase 3E, narrowly strengthen
the Phase 3D2 name boundary to reject Unicode control and format characters
(`Cc`/`Cf`), Unicode line and paragraph separators (`Zl`/`Zp`), and names
containing only whitespace or Unicode separators, including NBSP-only names.
This applies to the snapshot name boundary; existing UTF-8 and character-limit
requirements remain unchanged. Accepted names remain unchanged: do not
normalize, trim, rewrite, or escape the stored value. Escaping belongs to
presentation. Reuse `portal_snapshot_invalid_contract` with a safe generated
name-field path; do not expose source names in errors or logs. This task does
not implement the validator amendment.

**Imported internal codes.** For a new imported Game Type, the first candidate
is `portal-gt-<portal_game_type_id>`, using the validated ID in decimal form.
Collision candidates are `portal-gt-<id>-2` through `portal-gt-<id>-32`, tried
in order, with at most 32 candidates per new row. The family fits the existing
64-character limit and code grammar. Existing local codes remain unchanged;
the namespace is not reserved. Database `code_unique` remains the collision
authority, including collisions with existing local codes or concurrent local
creation. Never adopt or overwrite a row because its code matches.

Existing imported rows are found only through `portal_game_type_id` within
the trusted mapped Hall and retain their local ID and allocated code. Once
allocated, a code is immutable; Portal rename and re-import do not change it.
Allocation follows the ordered candidate family and the Hall's occupied codes,
not a pure function of Portal ID. Exhausting all 32 candidates rejects the
complete application without partial domain changes. A previously rejected
snapshot does not reserve a code; a later valid snapshot resolves Portal
identity normally. This defines failure requirements, not transaction or
locking mechanics.

**Migration contract.** Backfill `local_name_key` from `name` for rows where
`portal_game_type_id IS NULL`; set or retain the key as SQL `NULL` for linked
rows. Do not change existing names, codes, IDs, statuses, references, or
timestamps, and do not automatically link existing local rows.

`dbDelta()` remains the normal migration mechanism. Because it cannot remove
`name_unique`, this decision authorizes one narrowly scoped, idempotent,
allowlisted `DROP INDEX` operation for that index on the trusted Hall's
resolved `bingo_game_types` table. This is the sole exception to the existing
`dbDelta()`-only migration rule; it authorizes no general-purpose DDL facility
or new database transport. Verify the target table and exact index definition
before removal, verify the replacement key/backfill before dropping the old
index, and verify the target table and index absence afterward. On an
idempotent rerun, verify the final state rather than dropping an absent index.
Catalog version advances only after complete schema and backfill verification.
New sites receive the final v5 schema directly. Implementation must account for
writers that do not yet maintain the nullable key; this does not decide local
writer permissions after synchronization.

**Still open.** Snapshot replay/stale-order handling; Phase 3E transaction and
locking mechanics; Session-template application; local writer restrictions
after synchronization; Commerce scheduling; webhooks, SSO, and provisioning
remain outside this slice. No PHP, tests, migrations, plugins, database data,
or Phase 3E implementation are changed by this documentation task.

*Rationale:* A nullable local-name key preserves local name uniqueness while
allowing exact Portal names to repeat across distinct identities. Bounded
ID-derived code allocation preserves existing local rows and immutable codes.
The explicit name-validation prerequisite and index-removal exception make
the later implementation boundaries reviewable without implementing them.

- **ADR-040 — [2026-09-08] — Phase 3E Portal Snapshot Application: Transport,
  Locking, Ordering, and Session-Template Currentness:**

**Status and scope. COMPLETE.** Implemented and tested together with this entry.
Source review passed, the local harness passes 381/381, and the authenticated
real Portal test passed against a local Portal test environment: Portal Hall 2
mapped to BingoCommerce site 2 through `HallMappingRepository::bind( 2, 2 )`, a
first synchronization importing 7 Game Types and 26 Session templates matching
the validated snapshot exactly, an immediate second synchronization that was a
domain no-op, and tenant isolation plus Commerce non-interference verified
against pre-import baseline hashes. Production deployment is NOT claimed: that
run used a local Portal on `127.0.0.1`, with no production Portal, hosting, or
customer data, and no deployment has been performed. The Phase 3E trigger
surface (UI or scheduling), local writer restrictions, webhooks, and
reconciliation remain future work. It
resolves the remaining pre-Phase 3E design work left open by ADR-038 and
ADR-039: the Phase 3E transaction and locking mechanics, snapshot
replay/stale-order handling, and Session-template application behaviour. It
decides nothing about webhooks, reconciliation, SSO, provisioning, Commerce
scheduling, or a trigger surface. ADR-035 through ADR-039 remain in force and
are not rewritten.

**Approved direct-mysqli transport (the sole Rule 4 addition).** Phase 3E
introduces `PortalSnapshotTransactionTransport`, a fourth approved
captured-`$wpdb->dbh` transport, and AGENTS.md §5 rule 4 is amended to list it.
One invariant justifies it: a validated snapshot must apply to the hall's
`bingo_game_types` replica AND its `bingo_portal_session_templates` replica
ALL-OR-NOTHING together with the sync-state marker. No existing boundary spans
those two domains — ADR-032/ADR-033 own WooCommerce-product exclusivity, Play
Product reference integrity, and Package composition, with different lock orders
and different statement sequences, and ADR-025 owns Hall Staff mandatory-audit
atomicity. Reusing either would widen a deliberately tiny boundary.

A transaction is genuinely required rather than preferred: ADR-039 mandates that
exhausting the 32 imported-code candidates reject the complete application
"without partial domain changes", and a code collision is only detectable at
INSERT time against `code_unique`.

`$wpdb` transactions were considered and rejected. The repository has none, and
`wpdb::query()` fires the `query` filter on every statement — the exact reason
ADR-033 already prohibits it inside an open transaction. Because the transport
captures the connection `$wpdb` itself uses, a filter callback would execute
inside the open transaction. The transport therefore accepts NO callable, owns
one fixed reviewed statement sequence, issues no `$wpdb` call between BEGIN and
COMMIT, and reuses ADR-032's `$active`/`$untrusted` guards, server-side
transaction-state assertion, unknown-COMMIT handling, and callback-free
`WP_Error` construction. It adds no operation_id, commit witness, or retry
token, and performs NO bounded retry: a snapshot application is idempotent and
non-interactive, so re-invoking the whole flow is safer than re-proving a
poisoned session.

**Locking.** One MySQL advisory lock per hall,
`bc:ps:<32 hex of DB_NAME|base_prefix|network_id>:<blog_id>`, five-second
timeout, taken through ordinary `$wpdb->prepare()` following
`HallStaffProvisioningService` and `HallStaffInvitation`. It is acquired BEFORE
the Portal HTTP request and covers mapping resolution, fetch, validation, and
application, so a second synchronization of the same hall never issues a Portal
request at all; it is released in `finally`. Because the lock name carries
`blog_id`, different halls never serialize against one another. Possession is
proven with `IS_USED_LOCK()` against the acquiring `CONNECTION_ID()` rather than
inferred, since a `$wpdb` reconnect releases advisory locks silently, and the
transport re-asserts possession before BEGIN.

HTTP completes before BEGIN. That is structural, not documentary: no network
call is reachable from the transport's fixed statement set.

**Lock order.** Fixed and total:
`mapping -> Game Types -> Session templates -> sync state`. Every lock is taken
as a READ, in that order, before the first write, so every rejection happens
while the transaction has written nothing. Rank 1 re-reads the mapping row
`FOR UPDATE` and re-confirms `portal_hall_id`, `blog_id`, active status,
network membership, non-main-site, and non-archived/deleted/spam — a mapping can
be disabled or a site archived during a Portal round-trip. Rank 2 locks linked
Game Type rows in ascending Portal-ID order; local rows are deliberately not
locked. Rank 3 locks the Session-template replica in ascending Portal-ID order,
because absence is decided by set difference. Rank 4 locks the marker row by its
unique `option_name`. Isolation is REPEATABLE READ, and every in-transaction read
is a locking read, so ADR-033's first-consistent-read hazard cannot arise.

**Stale ordering, using only the existing v1 payload.** The per-hall marker is
the non-autoloaded per-site option `bc_portal_snapshot_sync_state`, holding
`portal_hall_id`, the verbatim `generated_at`, and `applied_at`. No Portal field
is invented and no `portal_updated_at` column is added: the snapshot is complete
and non-delta, so per-record `updated_at` contributes nothing to ordering. Only
a STRICTLY older `generated_at` instant is rejected, as
`portal_snapshot_stale` with zero writes. Equal instants apply: Portal
timestamps carry one-second resolution, applying a duplicate is a no-op by
construction, and skipping a real change would be data loss. Comparison is on
the absolute instant, never the text, so offsets cannot invert ordering. Only an
ABSENT marker row means there is no previous state; an existing but unusable
marker — malformed, bad timestamp, or a different `portal_hall_id` — fails
closed as `portal_sync_state_invalid` with zero writes rather than being treated
as absent. The stale check precedes ADR-038's absence gate, because a genuinely
older snapshot may legitimately lack a later-created Game Type.

The marker is created or updated INSIDE the same transaction, never pre-created,
so fetch failure, validation failure, missing-Game-Type rejection, stale
rejection, and any transaction failure all leave no sync-state write. Its
non-autoload value is the literal `off`, verified against this installation's
WordPress. The object cache — both the option key and the negative `notoptions`
entry — is invalidated only after a CONFIRMED commit.

**Game Type application.** Identity is `portal_game_type_id` within the trusted
hall's own prefixed table, and nothing else; names and codes never link, merge,
adopt, or overwrite. New rows take the ADR-039 candidate family by real INSERT
attempts, because the database index is the collision authority and a pre-read
would race `GameTypeService::create()`. Existing linked rows keep their local ID,
immutable code, local status, and `created_at`; only a byte-different name is
rewritten, compared in PHP so no collation can hide a rename. Every linked row
stores `local_name_key` as SQL NULL. ADR-038's absence gate is unchanged.

**Session-template application.** Upsert by `portal_session_id`; absence never
deletes. `portal_status` and `is_current` stay independent. `created_at` is
preserved on every path. `not_current_at` records the CURRENT absence: stamped
when a present row goes missing, CLEARED when the row reappears, and left
untouched while a row merely stays missing, so a later absence starts a new
transition and a repeated snapshot rewrites nothing. Phase 3E issues no
statement against `bingo_sessions` or `bingo_session_packages`.

**Local writers remain transitional.** Phase 3E does NOT restrict local Game
Type writers. Local rename of a Portal-linked row stays permitted and local
archive/restore is unchanged; Portal remains authoritative and restores the
Portal-owned name on the next pull. ADR-035 sequences writer restrictions after
reconciliation and recovery are proven, and Phase 3E delivers neither, so that
decision remains deferred.

**Callback-free error handling.** Phase 3E production code NEVER calls
`is_wp_error()`. That helper fires the `is_wp_error_instance` action for every
error it inspects, and the transport inspects an error on every in-transaction
rejection path, so a single use would run arbitrary third-party callbacks on the
captured session while the transaction is open — the same hazard as the `query`
and `gettext` filters. Every check is `instanceof \WP_Error`, and a runtime
observer test asserts a mid-transaction failure fires zero such callbacks.

**Request-local reentrancy.** MySQL advisory locks are re-entrant within one
session, so `GET_LOCK` alone would let a nested same-Hall synchronization take
the same name, fetch, and write concurrently with the outer one while MySQL
reported success. `PortalSyncLock` therefore keeps a request-local set of held
names and refuses a second acquisition of the same name BEFORE `GET_LOCK`,
before any fetch, and before any write.

The reservation is made BEFORE the first `$wpdb` query of acquisition, not
after it. `wpdb::query()` fires the `query` filter on every statement, so
checking the guard and only then querying would leave a re-entrancy window
exactly the width of the GET_LOCK statement: a callback running on that
statement could re-enter, find the guard empty, and be handed the same
re-entrant lock. A `finally` releases the reservation if acquisition fails or
throws.

Release keeps the reservation across BOTH of its database calls and gives it up
ONLY on positive proof that the lock can no longer be held: either a valid,
positive connection id that differs from the acquiring one — so the original
session and its advisory locks are provably gone — or `RELEASE_LOCK` returning
exactly 1, without a query error, on that same session. The connection-id result
is validated before it is cast, because `get_var()` yields null on a query error
and `(int) null` is 0, which would compare unequal to a real id and be misread as
"the connection changed" on precisely the failure where the lock is most likely
still held. Both queries are wrapped so a `Throwable` cannot escape: release runs
from the caller's `finally`, and an escaping exception would replace an
already-committed synchronization result.

Every other outcome — 0, NULL, false, a query error, an unusable connection id,
an exception — is an UNCONFIRMED release, and INTENTIONALLY keeps the
reservation, leaving that Hall refused for the remainder of the PHP request.
This is deliberate, not a wedge to be avoided: refusing a later synchronization
is recoverable, while handing out a lock that may still be held is not. Only one
fixed generic message is logged on every failure branch, disclosing no lock name,
connection id, SQL, or MySQL text. Different Halls are unaffected throughout.

**Sync-state strictness.** An ABSENT marker row is the ONLY "no previous state"
case. A marker that exists but is malformed, carries a timestamp that is not the
Portal Atom serialization, or records a different `portal_hall_id` is rejected
as `portal_sync_state_invalid` with zero writes. Treating any of those as absent
would silently bypass stale-order protection exactly when the ordering record is
least trustworthy, and a wrong Hall ID means the marker and the hall's tables
disagree about tenancy. Repair is an operator decision, not an assumption.

**Error-data disclosure.** No raw MySQL message reaches returned error data, a
log, or a caller. A duplicate-entry message quotes the offending value — a
Portal name or an imported code — plus the schema-qualified index. The raw
message is read in exactly one place, reduced there to a numeric errno plus an
ALLOWLISTED constraint identifier, and discarded; an unrecognised index
contributes nothing. Imported-code collision detection consumes that distilled
identifier, so ADR-039's candidate walk still works without a MySQL string
existing anywhere outside that function.

**Storage-engine gate.** Before BEGIN, every table this boundary locks or writes
— hall mappings, `wp_blogs`, the hall's Game Types, the hall's Session
templates, and the hall's options — is verified to exist and to be InnoDB. A
MyISAM or MEMORY participant would accept every write, ignore ROLLBACK, and
leave the all-or-nothing guarantee that justifies this transport quietly false,
including ADR-039's no-partial-changes requirement. A missing or non-InnoDB
table fails closed with zero writes.

**Scope exclusions.** No admin UI, cron, REST route, webhook, SSO, provisioning,
Commerce scheduling, Package, product, or occurrence behaviour. No new table and
no schema change: Catalog stays v5 and Sessions stays v2.

*Rationale:* The hall-scoped advisory MySQL lock is INTENTIONALLY held across the
HTTP round-trip, but no database transaction and no row lock is held during it —
the transaction begins only after the response has been fetched and validated.
That split is the point: a second synchronization of the hall is excluded from the
moment mapping resolution starts, while InnoDB row locks and the transaction
itself exist only for the few milliseconds of the apply, so a slow or hung
Portal cannot stall ordinary hall administration behind held row locks. A total
lock order taken entirely as reads then makes every rejection provably
zero-write. Rejecting only strictly older snapshots keeps ordering safe without
discarding real changes that share a one-second timestamp, and treating
`not_current_at` as the current absence keeps the replica's history honest
across repeated pulls.

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

- **BR-066 — [2026-08-27] — Bingo Play Product Storefront Mechanism Deferred:**

  The domain rule is unchanged and remains locked: a Bingo Play Product is a
  package-building component and is NOT independently customer-purchasable in
  v1 (BR-039), and membership in `bingo_play_products` is authoritative for that
  classification regardless of BR-032 compatibility metadata (BR-065).

  The exact WooCommerce mechanism that enforces non-purchasability in the
  storefront is DEFERRED to the checkout/storefront increment and is not decided
  by this entry.

  Until that decision exists, no Catalog increment may change any of the
  following on a linked WooCommerce product:

  - `catalog_visibility`
  - `is_purchasable` / purchasability filters
  - post status
  - add-to-cart behaviour
  - stock or stock-status behaviour
  - storefront or search visibility

  Package Increment 1 specifically must not change them. An implementer must not
  select a mechanism by inference; doing so requires a new reviewed entry.

  Relationship to prior rules: this entry clarifies the scope boundary of
  BR-039 and BR-065. It does not weaken either. BR-032's server-side cart and
  checkout enforcement remains in force and remains the locked enforcement
  layer.

- **BR-067 — [2026-08-27] — Bingo Package Base Composition Integrity:**

  A Bingo Package's base composition is a set, not a list of repeatable entries.

  1. **No duplicate input.** A requested component set containing the same
     `bingo_play_product_id` more than once is rejected deterministically with a
     `duplicate_component` error. Quantities are never silently merged, because
     silent consolidation would conceal a caller or interface defect.

  2. **One relationship row.** Repeated use of the same Bingo Play Product is
     represented by one relationship row carrying its aggregate quantity, never
     by duplicate rows. This restates the approved persistence design and is
     enforced by the unique `(package_id, bingo_play_product_id)` constraint.

  3. **No empty active Package.** A Package may never persist as an active
     zero-component object. BR-061 defines every Package type in terms of at
     least one base component, so a component-less Package cannot be classified.
     Creation therefore supplies the initial component set, and composition
     replacement rejects an empty set.

  4. **Archived components are preserved.** If a Bingo Play Product is archived
     after a Package already references it, that existing component reference
     survives. Package identity, composition, and stored `package_type` are not
     silently rewritten by an unrelated Play Product lifecycle change.
     Administrative reads MUST surface that a Package contains an archived
     component so a Hall Manager can correct it deliberately. Actual sale
     eligibility enforcement is a later checkout concern and is not decided
     here.

  5. **New references must be active.** A Bingo Play Product that is archived at
     the time of the request may not be newly added to a composition.

  Relationship to prior rules: additive to BR-040 and BR-061, and consistent
  with the preservation model already locked for Bingo Play Product to Game Type
  references. It does not alter BR-049's pricing separation.

- **BR-068 — [2026-08-27] — Bingo Package Composition Editing Boundary:**

  Composition mutation is rejected while a Bingo Package is archived. A Hall
  Manager restores the Package first, then edits its composition.

  Archiving a Package never removes, detaches, or rewrites its component rows.
  Restoring a Package never requires its component Bingo Play Products to be
  active, because BR-067 preserves existing references.

  Relationship to prior rules: additive to BR-040 and BR-061, and consistent
  with the archive/restore preservation model already applied to Game Types and
  Bingo Play Products.


- **BR-069 — [2026-08-27] — Medium Is Immutable While a Bingo Play Product Is Package-Referenced:**

  A Bingo Play Product's `medium` MUST NOT change while that Bingo Play Product
  is referenced by one or more `bingo_package_components` rows. Both directions
  are covered: Paper to Digital and Digital to Paper.

  A Hall Manager who needs to change a referenced Bingo Play Product's medium
  must first remove it from every Package that uses it as a base component. The
  attempt is otherwise rejected, and the rejection names the referencing
  Packages so the Hall Manager can act on it.

  *Why this restriction exists.* BR-061 derives Package type from base
  composition, and the approved persistence design stores `package_type` on the
  Package row. A referenced Bingo Play Product that flipped medium would
  therefore make every referencing Package's stored `package_type` immediately
  wrong, with no mutation of the Package itself having occurred. Two alternatives
  were considered and rejected: synchronously rewriting every referencing
  Package's type turns one Play Product edit into an unbounded multi-Package
  write with no Hall Manager intent behind it, and deferred reconciliation leaves
  authoritative Catalog rows knowingly inconsistent between the change and the
  repair. Refusing the edit keeps the Hall Manager's intent explicit and keeps
  every Package row correct at all times.

  This restriction is specific to `medium`, because `medium` alone determines
  persisted Package type. The following remain permitted on a
  Package-referenced Bingo Play Product unless some other rule prohibits them:

  - changing `faces_per_unit`;
  - assigning or reassigning a Game Type;
  - clearing a Game Type where otherwise valid (Paper only);
  - archiving and restoring the Bingo Play Product, whose existing Package
    references are preserved under BR-067 and do not rewrite `package_type`.

  Enforcement MUST be concurrency-safe. The check and the write occur while
  holding the `bingo_play_products` row lock, which is the same row that Package
  creation and Package composition replacement lock before inserting or
  retaining a component referencing it. A medium change and a new component
  reference therefore serialize on that row: either the medium change commits
  first and the Package writer then derives `package_type` from the new value
  under lock, or the component row exists first and the medium change is
  rejected. Neither ordering can produce a stale `package_type`.

  Relationship to prior rules: additive to BR-039, BR-061 and BR-067. It does
  not alter BR-039's medium definition, only when a change may be applied.

- **BR-070 — [2026-08-29] — Bingo Package Lifecycle Semantics:**

  A Bingo Package's lifecycle states are `active` and `archived`. There is no
  hard delete in this increment.

  Archive and restore are ordinary conditional single-row Catalog writes. They
  do NOT revalidate the linked WooCommerce product, do NOT enter the ADR-032
  captured-mysqli transport, and do NOT introduce a new transaction profile.

  *Why no Woo revalidation.* The authoritative `bingo_packages` row remains
  present while archived and retains its UNIQUE `wc_product_id` claim
  throughout. Restore therefore creates, reassigns and reacquires nothing: there
  is no claim to re-establish and nothing for a WooCommerce row lock to
  serialize against. This is the material difference from Bingo Play Product
  restore, which does revalidate the linked product row.

  **Archive** (`active` -> `archived`): sets `archived_at` and `updated_at`;
  preserves `package_id`, `wc_product_id`, `package_type`, the complete
  component composition, and the BR-065 `bc_product_kind = package` projection.

  **Restore** (`archived` -> `active`): clears `archived_at` and sets
  `updated_at`; performs no WooCommerce revalidation, no composition
  rederivation, and preserves Package identity and the Woo claim.

  **No-ops.** Archiving an already-archived Package and restoring an
  already-active Package are no-ops. A no-op emits no mutation audit event.

  **Corrupt state.** A `bingo_packages` row holding an unrecognised lifecycle
  value fails closed rather than being coerced to either state.

  Package archive and restore emit the ordinary post-commit Catalog audit
  events `catalog.package.archived` and `catalog.package.restored`. These are
  ordinary Catalog audit events and are NOT ADR-025 mandatory-audit operations:
  there is no `operation_id`, commit witness, retry token, audit atomicity, or
  reconciliation protocol.

  **Scope limit.** Package lifecycle state is Catalog lifecycle state. It is NOT
  a statement that the linked WooCommerce product is currently storefront- or
  sale-eligible. Session, storefront and checkout sale eligibility remain
  undecided here and are settled by their own later requirements.

  Relationship to prior rules: additive to BR-067 and BR-068, which already lock
  composition preservation across archive and the rejection of composition
  edits while archived. It does not alter either, and it does not alter BR-065's
  projection or its Package-removal disposition requirement, which applies only
  to permanent removal and is out of scope while no hard delete exists.


- **BR-071 — [2026-09-03] — Portal and BingoCommerce Authority Boundary:**

  The Laravel Bingo Innovation Portal is authoritative for Hall identity, Game
  Type identity/configuration, operational Session-template identity, Portal
  Session `days`, and existing Games, Patterns, Caller, device/XML, and other
  operational functionality.

  BingoCommerce is authoritative for WooCommerce Products, Bingo Play Products,
  Packages, pricing, inventory and commerce configuration; commerce weekdays;
  recurring yes/no; recurrence start/end dates; specific non-recurring commerce
  dates; structured commerce time; dated commerce occurrences; Package-to-
  occurrence availability; five-day sales eligibility; checkout, orders and
  payments; and future entitlement/QR/redemption commerce state.

  Packages and Bingo Play Products remain BingoCommerce-authoritative and are
  retained without redesign by this rule. The existing local Game Type domain
  is retained as a future synchronized replica, and the existing Session
  implementation is retained pending compatibility review.

  Relationship to prior rules: narrows BR-037 and BR-041 only as stated in
  BR-072/BR-073. BR-039, BR-040, BR-049, BR-065, WooCommerce ownership, and hall
  isolation remain in force.

- **BR-072 — [2026-09-03] — Portal Hall and Game Type Integration Identity:**

  Within the single authoritative Bingo Innovation Portal environment, Portal
  Hall identity is `halls.id`, Portal Game Type identity is `game_types.id`, and
  Portal Session-template identity is `bingo_sessions.id`. Portal `halls.id`
  maps to WordPress Site ID. Names and domains are editable attributes and MUST
  NEVER be cross-system identities.

  Portal Game Type creation and rename are allowed. Rename preserves
  `game_types.id`. Once created, a Portal Game Type cannot be deleted. Archive
  and Restore are not required in Portal v1. `device_group_id` remains
  hardware/XML/device-only and MUST NEVER be used as a BingoCommerce integration
  identity. XML-generated GameGroup identifiers MUST NEVER be BingoCommerce
  integration identities.

  BingoCommerce may retain a hall-scoped local Game Type replica linked to the
  Portal ID. Its existing surrogate ID and immutable machine code may remain
  internal identifiers but are not cross-system identity. Existing local
  Game Type create/edit/archive/restore and currently disabled hard-delete
  behavior remains transitional local behavior until sync and reconciliation
  are proven; this rule does not authorize its immediate removal or redesign and
  does not make it part of the Portal v1 lifecycle.

  Exact Hall/Game-Type mapping persistence, provisioning, synchronization, and
  transitional-local-writer handling are deferred to reviewed implementation
  designs.

  Relationship to prior rules: narrows BR-037's BingoCommerce-authority and
  hall-local-creation provisions. Supersedes only BR-047's Game Type one-time-
  copy/no-live-sync provisions; BR-047's starter WooCommerce Product and Bingo
  Play Product behavior remains in force. BR-057 remains unchanged.

- **BR-073 — [2026-09-03] — Portal Session Template, Commerce Schedule, and Dated Occurrence:**

  Portal `bingo_sessions.id` identifies an operational Session template owned
  by its Portal Hall through `bingo_sessions.hall_id`. Only Sessions owned by
  that Hall are eligible for v1
  BingoCommerce sync; received/shared Sessions are excluded, and an
  `allSessions()`-equivalent source MUST NOT define integration eligibility.
  The conceptual eligibility rule is
  `bingo_sessions.hall_id == mapped halls.id`; this is not an API query or schema
  implementation decision. Portal Session `days` are independent from Commerce
  scheduling in v1 and MUST NOT drive Commerce scheduling, generate Commerce
  occurrences, or constrain or validate Commerce scheduling. Future advisory
  validation MAY be introduced only by a separately approved future requirement.

  A BingoCommerce commerce schedule is distinct from the Portal template and
  owns commerce weekdays, recurring yes/no, recurrence start/end dates,
  specific non-recurring dates, and structured commerce time. Structured
  commerce time is BingoCommerce-owned and may be entered manually by the Hall
  Manager in v1. Database columns, UI controls, storage schema, and timezone
  implementation remain deferred. The schedule
  generates exact dated commerce occurrences. Package availability, BR-051/
  BR-063 five-day sales eligibility, purchase attribution, and future
  entitlement/redemption state apply to the exact dated occurrence. Customers
  and orders reference that occurrence, not only the template or schedule.

  Display names remain labels and MUST NOT be parsed for time or scheduling
  behavior. The portal-integrated commerce schedule requires structured time,
  superseding only BR-050's statement that separately structured time is not
  required. BR-050's prohibition on parsing display-name time remains in force.

  The existing BingoCommerce Session tables and implementation are retained
  until a schema-specific compatibility/refactoring review. No deletion,
  replacement, destructive migration, or loss of compatible Package/Session
  assignments is authorized by this rule.

  One Package may be assigned to multiple dated commerce occurrences. No one-
  Package-to-one-occurrence restriction is introduced, and existing Package/
  Session relationship work is preserved where compatible.

  Relationship to prior rules: narrows BR-041's canonical identity to the
  BingoCommerce dated commerce occurrence rather than the Portal Session
  template; supersedes BR-050 only for structured commerce time; clarifies
  BR-051, BR-052, BR-045, BR-055, BR-056, and BR-063 to operate on the exact
  occurrence. Redemption accumulation, atomicity, and late-redemption behavior
  are unchanged. The retired Session-capacity decisions remain retired.

- **BR-074 — [2026-09-03] — Portal Synchronization Sequence and V1 Exclusions:**

  The approved integration uses an authenticated Portal read API, initial/full
  pull synchronization, idempotent mappings, Portal webhooks, BingoCommerce
  local replicas, and reconciliation. Implementation proceeds in this order:
  authenticated read API; full pull; prove idempotent mapping; webhooks;
  reconciliation; tenant provisioning; SSO.

  No API, webhook, reconciliation, provisioning, or SSO implementation is
  authorized by this documentation rule. Individual Games, Patterns,
  Caller/device data, Portal users, Portal Session `days`, `device_group_id`,
  and XML GameGroup IDs are excluded from v1 sync. Add-ons, QR, customer
  entitlement, and electronic-capacity implementation are outside this
  integration increment and remain governed by their independent decisions.

  Relationship to prior rules: additive. ADR-011 remains applicable to future
  BingoCommerce endpoints; ADR-032 through ADR-034 and BR-038/BR-042/BR-045
  concurrency boundaries are unchanged.

- **BR-075 — [2026-09-03] — Commerce Enablement and Future SSO Authority:**

  `Enable BingoCommerce` is a separate platform capability authorized only by
  a Portal Global Admin. It MUST NOT inherit ordinary Portal Hall Settings
  authorization. Hall Managers/Admins may manage commerce only after the
  platform has enabled the Hall. Detailed enablement and provisioning lifecycle
  rules are deferred.

  Future SSO must provide a short-lived secure handoff from authenticated Portal
  staff to the correct mapped BingoCommerce Hall tenant. SSO is approved in
  principle but is not part of the first integration increment; protocol,
  claims, expiry, replay defense, account mapping, and failure behavior are
  deferred to a later security design.

  Relationship to prior rules: additive. It does not broaden Hall Manager,
  Platform Admin, or Super Admin permissions defined by BR-018/BR-023/BR-026,
  and it does not waive ADR-012 authentication requirements.


---

## Still Open / TBD Items (Unresolved)

- Portal integration persistence and contract details deferred by ADR-035 and
  not yet resolved by ADR-036/ADR-037/ADR-038/ADR-039: Portal API authentication
  lifecycle;
  other endpoint paths and payloads; webhook signing, retry, replay and
  ordering; snapshot application/import writers; reconciliation and transitional
  conflict policy; commerce-schedule recurrence horizon, timezone/DST and
  regeneration rules; enablement/provisioning lifecycle; and SSO
  protocol/account/tenant mapping. ADR-036 fixed the Hall snapshot transport URL
  and ADR-037 froze the Hall snapshot v1 validation contract. ADR-038 freezes
  completeness and zero-write rejection of missing linked Game Types. ADR-039
  approves the Catalog v5 name/index and imported-code design, a narrow name-
  validation amendment, and the allowlisted index-removal exception; all three
  are now implemented and tested. ADR-040 authorizes and implements Phase 3E
  snapshot application, including imported-code allocation; it is COMPLETE,
  source-reviewed, and validated by an authenticated real Portal test against a
  local Portal test environment.
- ADR-040 resolves the former pre-Phase 3E design work: snapshot
  replay/stale-order protection, Phase 3E transaction and locking mechanics, and
  Session-template application behavior are decided and implemented. Game Type
  `portal_is_current` / `portal_not_current_at` fields are still not required
  for v1; missing linked Game Types remain integrity violations, not currentness
  changes. ADR-040's real Portal test-environment run PASSED against a local
  Portal test environment (Portal Hall 2 -> site 2; 7 Game Types and 26 Session
  templates imported, second run a domain no-op), so Phase 3E is COMPLETE.
  Production deployment is not claimed. Still open after ADR-040 and all future
  work: the Phase 3E trigger surface (UI or scheduling); local writer
  restrictions after synchronization, which stay deliberately transitional until
  reconciliation and recovery are proven; webhooks; and reconciliation.
- Schema-specific compatibility/refactoring review for the implemented local
  Game Type and Session models. The review must not assume either domain or the
  existing Session tables are deleted or replaced.
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
