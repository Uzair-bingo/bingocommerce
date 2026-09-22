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
Source review passed, the local harness passes 387/387 (testing multiple
disposable Hall mappings and tenant isolation), and the authenticated real Portal
test passed against a local Portal test environment with one persistent mapping:
Portal Hall 2 mapped to BingoCommerce site 2 through
`HallMappingRepository::bind( 2, 2 )`, a first synchronization importing 7 Game
Types and 26 Session templates matching the validated snapshot exactly, an
immediate second synchronization that was a domain no-op, and tenant isolation
plus Commerce non-interference verified against pre-import baseline hashes.
**Phase 3F, the separate multi-Hall tenant-isolation proof, is COMPLETE**
(practical proof with two simultaneously active mappings against the real local
Portal endpoint; recorded concisely below and detailed in `PROJECT_STATUS.md`).
Production deployment is NOT claimed: those runs used a local Portal on
`127.0.0.1`, with no production Portal, hosting, or customer data, and no
deployment has been performed. **Phase 3G, manual Hall-scoped synchronization,
is the next increment and is unimplemented**: an authorized Hall Manager must be
able to synchronize their own Hall, Network Super Admin retains
emergency/oversight access, ordinary Hall staff must not receive synchronization
authority, exact capability names, provisioning, and UI mechanics remain
undecided until source inspection, and webhooks and scheduling remain deferred,
as do local writer restrictions and reconciliation. It
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

**Phase 3F completion and verification note — [2026-09-09] — multi-Hall
tenant-isolation proof: COMPLETE.** Phase 3F introduced no new architectural
decision; it provides the separate practical proof with two simultaneously
active mappings against the real local Portal endpoint (`http://127.0.0.1:8000`),
confirming that the ADR-040 boundary holds when multiple mapped Halls exist at
the same time. (The Phase 3E automated harness had already tested multiple
disposable Hall mappings and tenant isolation, while its authenticated real-Portal
run used one persistent mapping.)

The practical run verified complete tenant isolation in both directions: Hall A
(retained pair `2 -> site 2`) ran as a domain no-op; disposable Hall B imported
3 Game Types and 5 Session templates; repeat synchronizations produced zero
domain changes; Portal identity sets remained disjoint with zero crossing
between sites; pre-existing local and Commerce data remained untouched; expected
restamped `generated_at` markers and WooCommerce `wc_last_active` telemetry were
confirmed normal platform behaviour rather than tenant leakage; disposable
resources were cleaned up under strict safety guards; Portal audit log records
and auto-increment advancement were preserved; and the harness Multisite
`$wpdb->registration_log` cleanup was corrected, bringing the suite to 387/387
passing assertions. Production deployment is NOT claimed (local Portal test
environment on `127.0.0.1` only). For the full operational verification record
and step-by-step evidence, see `PROJECT_STATUS.md`.

**Phase 3G business scope:** Phase 3G provides manual, Hall-scoped
synchronization: an authorized Hall Manager must be able to synchronize their own
Hall, while Network Super Admin retains emergency and oversight access. Ordinary
Hall staff (such as Game Operators) must not receive synchronization authority.
Its capability, provisioning and UI mechanics are decided by ADR-041 below.
Webhooks and scheduling remain deferred, as do local writer restrictions and
reconciliation.

- **ADR-041 — [2026-09-09] — Phase 3G Manual Hall-Scoped Portal
  Synchronization: Capability, Tenant Derivation, Request Mechanism, Notices and
  Audit:**

**Status and scope. COMPLETE.** Source review passed, the local harness landed at
a historical **411/411** and currently reports **467 passing assertions** after
fixture-isolation commit `7f3e332`, and the practical browser test PASSED against
a local Portal test
environment. Production deployment is NOT claimed, and scheduling and webhooks do
not exist. This entry decides only how an authorized person triggers the
already-complete Phase 3E synchronization from inside one hall's wp-admin. It
adds no scheduling, cron, webhook, REST route, Portal-side trigger, SSO,
reconciliation, local writer restriction, Commerce scheduling change, Portal API
change, or new persistence, and it changes no Phase 3E production file.

**Dedicated capability.** Phase 3G introduces `bc_sync_portal`, granted only to
`bc_hall_manager` and never to `bc_game_operator` or any other role. Reuse was
rejected: `bc_manage_hall_settings` and `bc_manage_hall` are broad hall
administration grants, and `bc_manage_products` / `bc_manage_sessions` describe
LOCAL Catalog authority, whereas synchronization applies Portal-owned Game Type
names and Session-template replicas. Binding Portal authority to any of them
would make it impossible to grant or revoke synchronization without moving
unrelated powers with it. The capability rides the existing versioned role
mechanism — `Roles::SCHEMA_VERSION` advances 1 -> 2 — so existing sites converge
through `maybe_provision_network()` / `maybe_sync_current_site()` and new sites
through `provision_new_site()`. No new role, migration framework or table.

**Network Super Admin oversight is inherited, not special-cased.** A Multisite
Super Admin already satisfies `current_user_can()` for any non-meta capability
(`WP_User::has_cap()`), so oversight needs no dropdown, no network screen and no
bypass branch. The screen additionally carries the explicit `is_super_admin() ||`
gate the other BingoCommerce screens use. Super Admin acts through the same
hall-local page by entering that hall's site admin.

**Authorization lives at the Phase 3G boundary.** `PortalSyncService` remains a
TRUSTED-CALLER service and gains no WordPress-user authorization, because CLI
harnesses invoke it with no logged-in user and because Phase 3E is complete and
reviewed. Every WordPress-facing entry point must therefore establish method,
tenancy, capability and nonce before calling it. That contract is documented on
`PortalSyncAdmin`, not by editing Phase 3E.

**Tenant derivation is structural.** The target site comes only from
`HallContext::site_id()` / `get_current_blog_id()`. No `blog_id`, Portal Hall ID,
mapping ID or network ID is read from GET or POST, and the form carries no such
field. The active mapping is resolved from that trusted site through
`HallMappingRepository::findActiveByBlogId()`. Phase 3E then re-derives the
Portal Hall ID from the mapping, the validator re-checks hall identity on the
envelope and every child record, and the transport re-confirms the mapping under
`FOR UPDATE`, so a forged identifier would have to survive four independent
re-derivations. There is no network-wide Hall selector.

**Authenticated admin-post, not the render callback.** The handler is
`admin_post_bc_portal_sync`; no `admin_post_nopriv` twin is registered.
Synchronization must not run inside the page-render callback, because WordPress
has already emitted admin output by then, which would make a redirect impossible
and stall a half-rendered screen for the length of a Portal round-trip. Order is
fixed: POST, hall context, capability, nonce, trusted site id, dependency gate,
one `PortalSyncService::synchronize()` call, audit, `wp_safe_redirect( …, 303 )`,
exit. Every method, tenancy, capability and nonce rejection happens before any
Portal fetch.

**Post/Redirect/Get is a deliberate deviation.** The five existing BingoCommerce
screens self-POST and render their notice inline. That is tolerable for local
CRUD but not for an action whose repeat costs a Portal round-trip and an advisory
lock, so Phase 3G redirects with 303 and renders from the follow-up GET. This is
the new convention for outward-effect actions; it does not retrofit the existing
screens.

**Phase 3E owns locking.** Phase 3G adds no transient, cooldown, rate limit or
second lock. Concurrent submissions are resolved by the existing per-hall
advisory lock and surface as the fixed retry notice.

**Allowlisted notices only.** The redirect carries at most `page`, `bc_sync`
(`applied` | `no_change` | `error`), `bc_sync_code` (an allowlisted failure
category) and `bc_sync_audit=1`. It never carries counts, messages, hall or site
identifiers, endpoint URLs or error data. Every failure is reduced to one
category from an explicit table built from real Phase 3B/3D/3E error codes; an
unrecognised code becomes `unknown`. `get_error_message()` and
`get_error_data()` are never rendered, so no token, Authorization header,
endpoint, snapshot fragment, SQL, errno, table, constraint or lock name can reach
the browser. Because both the outcome and the category are allowlisted on read, a
hand-edited query string can only ever select one of the fixed strings.

**Audit policy.** Success records `portal.snapshot.synchronized` with the trusted
`blog_id`, the authenticated actor, the Portal Hall ID from `PortalSyncResult`,
an `outcome` of `applied` or `no_change`, the five integer counts, and a
server-generated UUID. Failure records `portal.snapshot.failed` with the same
trusted attribution, an allowlisted error category, and the Portal Hall ID only
when safely available from the trusted mapping. Never the token, Authorization
header, Portal body, snapshot, raw `WP_Error` message or data, raw SQL, or
endpoint URL. If the audit write fails AFTER a committed synchronization, the
synchronization outcome is NOT rewritten — reporting a committed sync as a
failure would invite a pointless retry — and the screen shows the true outcome
plus one fixed secondary warning, with a single fixed generic server log line
carrying no error data.

**Audit failure is one case, not two.** `AuditLog::record()` returning a
`WP_Error` and THROWING a `Throwable` are handled identically, because by that
point the synchronization has already committed: neither may rewrite the outcome
or escape to the browser. Both paths preserve the true result, keep the HTTP 303
redirect, add only `bc_sync_audit=1`, and write one fixed generic log line. The
exception object is never read — its message, class and trace can carry SQL,
table names and stored values.

**Absent mapping is not an unavailable mapping system.** A clean read that finds
no active mapping shows `Not linked`. A missing mapping schema or a failed
mapping read shows `Synchronization unavailable`, and the Portal Hall row reads
`Unavailable`; telling a Hall Manager their hall is Not linked during a database
failure would misdescribe a different problem with a different fix. The button is
withheld in both cases, no schema/table/SQL/`WP_Error` detail is rendered, and
the POST handler still leaves authoritative mapping resolution to Phase 3E.

**Testing.** `tests/portal-sync-admin.php`, with
`tests/portal-sync-admin-worker.php` (required because the handler terminates in
`exit()` or `wp_die()`), exercises the real registered page and handler, with the
Portal intercepted at `pre_http_request` and throwaway fixture constants.
`wp-config.php` executes and evaluates its definitions, including the Portal
constants; the fixture constants defined earlier remain the effective runtime
constants because PHP keeps the first definition, so the operator values do not
become the effective runtime test credential and do not reach an Authorization
header, Portal request, output, redirect, audit context or test artifact. The
harness landed at a historical **411/411** across two consecutive runs with an
identical count, and after fixture-isolation commit `7f3e332` the current
measured result is **467 passing assertions**. It
asserts HTTP **303** on applied, no-change and error
redirects, and asserts the error-category table contains **52** mappings across
17 categories. Its cleanup removes current-run audit rows only when BOTH the row
is newer than the opening high-water mark AND its `blog_id` is a site that run
created; a deleted hall alone is never grounds for deletion.

**Practical browser test: PASSED (2026-09-09).** The plugin was network-activated
through `activate_plugin()` — writing only `active_sitewide_plugins`, with the
network schema unchanged at version 1, the table count unchanged, sites 2-4
byte-identical, and no synchronization triggered by activation — and a freshly
issued Hall-2-only web token was supplied through the ADR-036 constant boundary.
A non-Super-Admin Hall Manager synchronized Sunrise/site 2 in a real browser:
three deliberate POSTs, each answered HTTP 303, each producing exactly one
correctly attributed `portal.snapshot.synchronized` audit event carrying only the
five integer counts (applied, then two domain no-ops). Five page GETs produced
neither a synchronization nor an audit event, confirming the PRG contract. Site 2
changed only in its Portal Game Type replica and sync-state marker; the two
pre-existing local-only Game Types survived byte-identical; Tampa and Orlando were
byte-identical; the mapping was unchanged; the Game Operator was denied both by
menu absence and by a 403 on a direct request for the Portal Sync page URL —
WordPress refused the request rather than redirecting the unauthorized user;
Network Super Admin was admitted. The active token does not appear in tracked
source, the Phase 3G diff, any Portal Sync page URL, a rendered notice, an audit
record, or any log inspected during verification.

**Credential lifecycle.** Token `id 2` is ACTIVE and granted Hall 2 only; the
superseded Phase 3E CLI token `id 1` is REVOKED, having been revoked only AFTER
the new token authenticated successfully, and is retained as a revoked record
rather than deleted so an access review can still see it existed. Both temporary
plaintext token files were deleted. The active token is currently configured
directly through the ADR-036 `BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN` constant in
`wp-config.php`, which is git-ignored and untracked configuration; the Portal
stores only the SHA-256 hash and has no plaintext column. That ignored file is
therefore the one place in the working tree holding the credential, which is why
no broader "the token exists nowhere" claim is made. Temporary browser-test users
were removed through `HallStaffService` and `wpmu_delete_user()`, leaving no
residual usermeta and no change to any pre-existing user.

**Still NOT claimed.** No production deployment has occurred. **Scheduling and
webhooks do not exist.** Local writer restrictions and reconciliation remain
deferred. The plugin activation and web configuration are local test-environment
state only.

*Rationale:* A dedicated capability keeps Portal authority separable from hall
administration; deriving tenancy from the current site rather than from the
request removes cross-tenant tampering as a class instead of validating against
it; and reducing every outcome to an allowlisted category makes the disclosure
surface a fixed table that can be reviewed in one place, rather than whatever an
upstream error happens to contain.

- **ADR-042 — [2026-09-12] — Automatic Portal-Controlled BingoCommerce
  Enablement and Suspension:**

**Status.** Decision and documentation COMPLETE. Automatic provisioning,
the provisioning-operations table (DB v1 -> v2), the machine REST endpoint, the
Portal Enable/Disable Ecommerce control, automatic site creation, and SSO are
**NOT implemented**. Commerce-state persistence (`HallCommerceState`),
`SetupReadiness`, and Hall Commerce access enforcement (`HallCommerceAccess` /
`HallCommerceGate`) are implemented foundations this lifecycle will use.
Production deployment is NOT claimed. The first practical proof will use a
disposable local Portal Hall and disposable BingoCommerce site.

**What this supersedes or narrows.**

- Supersedes only BR-074 / ADR-035’s requirement that Portal webhooks and
  reconciliation precede tenant provisioning. Authority boundaries and v1
  exclusions from ADR-035 through ADR-041 remain in force and are not altered.
- Narrows ADR-027 and BR-047 for this MVP only: the first automatic-provisioning
  release creates a ready but EMPTY Commerce store and does **not** copy starter
  WooCommerce Products or starter Bingo Play Products. Starter-product copying
  remains separately deferred because its template source and copy mechanism
  have not been designed or implemented. Game Types continue to synchronize
  under ADR-035 rather than copy-on-provision.
- Extends BR-075’s Portal Global Admin-only enablement authority with the
  concrete enable / suspend / re-enable lifecycle, machine authentication,
  REST contract, operations table, and plugin ownership below. BR-075’s future
  SSO requirement remains later and is not part of this MVP.

**Approved delivery order (current).**

1. authenticated snapshot read and full-pull synchronization — complete;
2. idempotent Hall/Game Type/Session mapping and multi-Hall proof — complete;
3. manual Hall-scoped synchronization — complete;
4. automatic Commerce enable/suspend provisioning — current work (this ADR;
   not yet implemented);
5. practical local end-to-end provisioning test;
6. SSO — later;
7. webhooks, scheduling, and reconciliation — later, separately reviewed.

Manual Hall-scoped Portal synchronization remains the current sync model.
Scheduling, webhooks, and reconciliation are not prerequisites for this MVP.

**Business contract.**

1. Only an authenticated Portal Global Admin may invoke Enable Ecommerce or
   Disable Ecommerce. Ordinary Portal Hall Settings authority does not grant
   this permission.
2. Enable Ecommerce automatically creates and connects the Hall’s BingoCommerce
   site. A WordPress Network Super Admin must not need to create or connect it
   manually.
3. Before enablement, the Portal suggests a lowercase Hall subdomain/slug. The
   Global Admin can edit it and must explicitly confirm it.
4. Names and domains remain attributes, never identity. Portal `halls.id` is
   the only cross-system Hall identity.
5. BingoCommerce is the authoritative source for address availability and the
   final site URL. The Portal must not construct or assume the final URL.
6. If the confirmed address is already occupied or reserved, enablement refuses
   clearly and changes no existing Hall, site, or mapping.
7. The first automatic-provisioning release creates a ready but EMPTY Commerce
   store: WordPress/WooCommerce and required BingoCommerce schemas are prepared;
   Portal Game Types and owner-only Session templates are synchronized; no
   starter WooCommerce Products or starter Bingo Play Products are copied; the
   Hall may create its products manually.
8. Disable Ecommerce means SUSPEND: stop new customer purchases; show the
   existing fixed maintenance response to customers, Hall Managers, and Game
   Operators; preserve the site, domain, mapping, products, orders, settings,
   and all data; allow already-started approved payment callbacks and required
   scheduled processing under the committed HallCommerceGate rules; retain
   Network Super Admin recovery access; do not delete or archive the WordPress
   site.
9. Re-enable must reuse the same WordPress site, mapping, domain, data, and
   permanent `site_ownership_id`. It must never create a second site for the
   same Portal Hall.
10. Manual Hall-scoped Portal synchronization remains the current sync model.
11. SSO remains later. Automatic provisioning does not synchronize Portal users
    or automatically log them into WordPress. Before SSO, Hall staff use a
    separately invited BingoCommerce account through the existing Hall Staff
    workflow. The Portal Ecommerce link may open the store URL, but wp-admin
    still requires a separate BingoCommerce login.
12. Production deployment is not claimed.

**Authority split (roles).**

- Portal Global Admin (Portal-side): sole initiator of automatic Enable Ecommerce
  / Disable Ecommerce through the machine API (BR-075, BR-076).
- WordPress Platform Admin / Network Super Admin: does **not** initiate automatic
  Enable/Disable. Retains network recovery access through HallCommerceGate,
  explicit legacy backfill/enrolment of an existing Hall, and explicit operator
  recovery of an ownership-unproven orphan site. Those operator actions are
  separate, reviewed procedures — not the Portal machine endpoint.
- Hall Managers / Game Operators: never invoke this endpoint.

**Billing interaction (BR-016) — current increment vs future authority.**

Portal administrative Commerce intent (`HallCommerceState`: `provisioning` /
`operational` / `suspended`) is distinct from BR-016 billing lifecycle state
(`Active` → `Overdue` → `Restricted` → `Suspended` → `Terminated`). Neither
subsystem may overwrite the other’s stored state or sequence.

**Source-checked current state:** No authoritative BR-016 billing persistence
or billing-status resolver exists in the BingoCommerce plugin tree today
(Portal Commerce state / `HallCommerceAccess` / `HallCommerceGate` exist;
billing lifecycle does not). This provisioning increment does **not**
implement BR-016 persistence or billing transitions.

**Until the separately reviewed BR-016 implementation exists**, the billing
dimension is **neutral** for `effective_access` calculation:

- Portal `operational` → `effective_access` = `allowed`;
- Portal `provisioning` / `suspended` → `effective_access` = `portal_blocked`;
- `billing_restricted` is **not** produced by this increment;
- No provisioning code may invent, persist, or infer a billing status.

Portal re-enable still cannot erase or bypass **future** authoritative billing
state once that resolver exists.

**After** an authoritative BR-016 resolver is implemented (name that exact
source in the implementing decision; do not assume one here), the
most-restrictive rule for managed Halls (customers and ordinary Hall Manager /
Game Operator storefront or wp-admin) becomes:

- Allowed only when Portal Commerce state is `operational` **and** billing
  status is in {`Active`, `Overdue`}.
- Portal `suspended` or `provisioning` blocks even when billing is
  `Active`/`Overdue`.
- Billing `Restricted` / `Suspended` / `Terminated` blocks even when Portal
  Commerce state is `operational`.
- Approved HallCommerceGate payment-callback and scheduled-processing
  exemptions are unchanged; this ADR adds no new bypass.

If a Portal `operational` intent is then successfully enforced while that
resolver reports restrictive billing, the provisioning command is still
**applied** (or `already_applied`). The response MUST NOT use HTTP 409 or
`billing_restricted` as an operation failure that would cause Portal to retry
a completed command. Deterministic success response in that future state:

- HTTP 200;
- `outcome` = `applied` or `already_applied`;
- `commerce_state` = `operational`;
- `effective_access` = `billing_restricted`;
- `error_category` = null.

**Plugin ownership.**

- `bingocommerce-portal-integration` owns: the Portal-facing machine endpoint;
  request authentication and validation; provisioning-operation persistence and
  sequencing; Portal Hall mapping coordination; HallCommerceState /
  managed-marker coordination; Portal snapshot synchronization; safe API
  responses; and integration audit attribution.
- `bingocommerce-platform` owns: actual WordPress Multisite site creation;
  WooCommerce/site initialization; triggering the existing per-site
  BingoCommerce migrations and role provisioning; and returning a structured
  setup result through the single filter below.
- `bingocommerce-catalog` continues to own Catalog rules. No starter-product
  copy runs in this MVP.
- `bingocommerce-core` remains shared infrastructure only (including AuditLog).

**Cross-plugin hook contract (decided; not implemented).**

`bingocommerce_platform_provision_hall_site` is invoked **only** when an
`operational` command needs initial creation or idempotent
initialization/resume. An ordinary `suspended` command for an existing valid
site MUST NOT invoke the platform initialization filter, and
`platform_unavailable` MUST NOT prevent safe suspension.

When that filter is used for an operational create/init/resume attempt:

- Hook name: `bingocommerce_platform_provision_hall_site`
- Mechanism: `apply_filters( 'bingocommerce_platform_provision_hall_site', $seed, $command )`
- Caller: `bingocommerce-portal-integration` orchestrator, after intent
  classification and locks, **exactly once** for that attempt (never a second
  “initialize later through the hook” step in the same attempt).
- Consumer: `bingocommerce-platform` only. No competing consumers.
- Seed rule: caller always passes `$seed = null`. The platform consumer acts
  **only** when `$seed === null`. If `$seed` is non-null (including
  `WP_Error`), the consumer returns that seed unchanged and performs **no**
  side effects. The orchestrator validates the final return value.
- `$command` exact keys:
  - `portal_hall_id` (int)
  - `operation_id` (string, lowercase UUID v4) — current Portal command only;
    never used as the site ownership marker
  - `site_ownership_id` (string, lowercase UUID v4) — permanent BingoCommerce
    internal ownership identity already persisted on the Hall operation row
  - `site_slug` (string) — durable reserved/bound slug for this command
  - `hall_name` (string|null) — current request hall name (null on every new
    suspended command and on post-ownership operational new commands)
  - `blog_id` (int|null) — `null`: resolve reserved address then create or
    recover partial create, and perform platform-owned initialization in this
    invocation; non-null: verify ownership and idempotently resume
    platform-owned initialization only
  - `request_fingerprint` (string, 64 lowercase hex)
- Internal defensive validation: the platform consumer MUST validate `$command`
  defensively. A malformed internal hook command is a programming/integration
  failure: return fixed `WP_Error( 'internal_error' )`, perform **no** platform
  side effects, and disclose no raw value or diagnostic. Client
  `validation_failed` exists only before operation identity/row mutation and
  keeps the reduced HTTP 400 response; it is **not** a platform-filter error
  code.
- When `blog_id` is null and `site_ownership_id` is already persisted, platform
  MUST resolve the reserved network address **before** creating, and MUST
  evaluate this recoverable branch **before** generic `address_occupied`
  handling so a retry does not reject its own safely identifiable partial
  creation:
  - No site at the reserved address: create via supported Multisite APIs
    (`wpmu_create_blog` / `wp_insert_site` path) with `archived` = 1 from
    insertion, initial site option `bc_commerce_managed` = this
    `site_ownership_id` (never `operation_id`), and `public` left at the API
    default (not an access-control barrier).
    **Marker-timing correction [2026-09-13]:** the site ROW and its `archived`
    flag are written by the INSERT; the marker is supplied through the same
    supported call as an INITIAL SITE OPTION and is therefore persisted during
    `wp_initialize_site()`, when that site's options table first exists. The
    marker is consequently NOT present from the site row's first moment, and
    any wording to that effect is wrong. The ordering guarantee that matters is
    unchanged and is the one the design depends on: the site is quarantined
    (`archived = 1`) before it exists at all, and the marker is written as part
    of initializing it rather than by a later separate write. A crash between
    the row INSERT and marker persistence therefore leaves an archived,
    UNMARKED site at the reserved address. That is an ownership-unproven orphan:
    the next attempt must refuse it as `site_ownership_unproven` and an operator
    must resolve it. It must never be adopted, re-marked, repaired or deleted
    automatically, because slug equality was never sufficient to prove
    ownership.
  - Site exists and `bc_commerce_managed` exactly equals the persisted
    `site_ownership_id`: treat as this operation’s recoverable partial create;
    return its verified `blog_id` with `created=false` and continue guarded
    attachment and initialization. The marker match — not slug alone —
    authorizes automatic recovery.
  - Site exists with an absent, different, or unreadable marker:
    `site_ownership_unproven`; never bind, rewrite, delete, or create another
    site.
- When `blog_id` is non-null, platform MUST verify
  `bc_commerce_managed === site_ownership_id` and MUST NOT derive or rewrite
  the marker from the current Portal `operation_id`.
- Success result exact keys: `blog_id` (positive int), `site_url` (non-empty
  string — advisory only; orchestrator MUST independently read `site_url` from
  the verified WordPress site and MUST NOT trust the filter return alone),
  `created` (bool), `managed_marker_present` (bool — true when
  `bc_commerce_managed` read-back equals this `site_ownership_id`).
- Failure: `WP_Error` with `code` exactly one of
  `site_create_failed`, `site_ownership_unproven`, `platform_unavailable`,
  `internal_error`. No SQL/paths/traces/raw values in message/data.
- Platform unavailable (filter returns null unchanged): orchestrator maps to
  `platform_unavailable`; no local create fallback.
- Exception containment: platform catches `\Throwable` →
  `WP_Error( 'internal_error' )`; orchestrator also wraps `apply_filters`.
- After the filter returns successfully, the orchestrator — **not** a second
  filter call — attaches `blog_id` with a current-sequence guarded write and
  read-back before three-fact ownership becomes durable, independently reads
  `site_url` from the verified WordPress site, then runs mapping, Portal
  full-pull synchronization, SetupReadiness, HallCommerceState writes, clears
  `archived=1` with read-back (enable path), and `enforced_sequence`
  confirmation.
- If the marker is absent after create, or ownership is ambiguous: treat as
  `site_ownership_unproven`; explicit Network Super Admin operator recovery;
  never silently bind or delete.

Business logic MUST NOT be duplicated between plugins.

**WordPress REST execution boundary (installed core order).**

Application Password / core authentication may execute before dispatch. Route
and method matching are controlled by WordPress core. For a matched route,
WordPress parses JSON and runs any registered-argument validation/sanitization
**before** `permission_callback`. Therefore:

- Malformed JSON returns WordPress core `rest_invalid_json` HTTP 400 **before**
  the custom permission callback or endpoint callback.
- Route/method mismatches and any registered REST argument validation failures
  may likewise return WordPress core response bodies.
- Only requests that reach the BingoCommerce callbacks can receive the complete
  custom ADR-042 response schema.

This ADR does **not** claim that Application Password rejection is the sole
core response-body exception. Core auth failures, malformed JSON, route/method
mismatches, and any core registered-argument failures are all outside the
custom schema.

**Machine authentication (ADR-011) — request-scoped Application Password proof.**

Installed WordPress REST Application Password flow
(`wp-includes/user.php`, `wp-includes/rest-api.php`):

- Successful App Password auth sets global
  `$wp_rest_application_password_status` to a `WP_User` and records the App
  Password UUID via `rest_application_password_collect_status()` /
  `rest_get_authenticated_app_password()`.
- Invalid App Password credentials may be rejected through
  `rest_authentication_errors` before BingoCommerce callbacks run. Those
  failures use WordPress’s core REST error response.
- Custom reduced `authentication_failed` bodies apply only when the request
  reaches BingoCommerce route handling (`permission_callback` / callback).

`permission_callback` MUST require **all** of:

1. `rest_get_authenticated_app_password()` returns a non-null UUID for this
   request (proves this request authenticated with an Application Password);
2. `$wp_rest_application_password_status instanceof \WP_User` and that user’s
   ID equals `get_current_user_id()`;
3. `current_user_can( 'bc_receive_portal_provisioning' )`.

Insufficient alone (explicitly rejected as proof): Authorization header
presence, Basic-auth syntax shape, `wp_is_application_passwords_in_use()`,
cookies/session login, or `current_user_can()` without (1)–(2).

Account rules:

- One dedicated nonhuman Portal provisioning account holds
  `bc_receive_portal_provisioning` only; it is not Network Super Admin and
  receives no Hall Manager or Game Operator role.
- Production requires HTTPS.
- Credentials remain server-side in Portal environment configuration.
- No custom HMAC, shared-secret signature, or nonce/replay table.

**New-site administrator identity.**

Supported Multisite creation requires a WordPress `$user_id` site
administrator. The exact server-only contract is the PHP constant
`BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID`. It MUST:

- be defined outside version control (deployment/local config only; this
  documentation increment does **not** modify `wp-config.php`);
- resolve to a positive existing WordPress user ID;
- identify an authorized Network Super Admin used for Hall recovery;
- differ from the authenticated Portal provisioning machine user;
- never come from the Portal request or `portal_actor_id`.

Missing, malformed, nonexistent, non-Super-Admin, or machine-account-equal
configuration MUST fail **before** site creation as `platform_unavailable`,
with no raw constant value or user information disclosed in the API response.
The least-privilege Portal provisioning machine account MUST NOT silently
become Administrator of every new Hall site.

Application-level retry safety uses `intent_sequence`, current `operation_id`,
and the request fingerprint below. Permanent site ownership uses
`site_ownership_id` / `bc_commerce_managed`, never the rotating Portal
`operation_id`.

**REST contract.**

```text
PUT /wp-json/bingocommerce/v1/portal-halls/{portal_hall_id}/commerce
```

**Route registration and manual validation (no WordPress REST args
validation for this contract).**

- Do **not** require WordPress REST `args` validation/sanitization callbacks
  for the provisioning path or request-body contract.
- Register a path pattern broad enough to match one non-empty path segment for
  `{portal_hall_id}`.
- After `permission_callback`, validate the complete `portal_hall_id` text
  **manually**: digits-only; canonical spelling with no leading zeroes;
  integer in `1 .. PHP_INT_MAX`. Failure → custom reduced
  `validation_failed` HTTP 400.
- Validate **every** body field manually in the endpoint callback after
  `permission_callback`. Missing, scalar, list, or otherwise invalid JSON body
  shapes that reach the callback → fixed custom `validation_failed`.
- Malformed JSON remains WordPress core HTTP 400 (`rest_invalid_json`) and
  MUST NOT be intercepted merely to manufacture the custom schema.

**Body object — every accepted key must be explicitly present; unknown keys
rejected; missing keys rejected (JSON null ≠ missing key).**

Exact keys:

| Key | Presence | Nullability |
|---|---|---|
| `operation_id` | required | never null |
| `intent_sequence` | required | never null |
| `desired_state` | required | never null |
| `site_slug` | required | see retry/null rules below |
| `hall_name` | required | see retry/null rules below |
| `portal_actor_id` | required | never null |

**Null rules versus exact-retry classification (exact precedence order).**

1. After BingoCommerce `permission_callback` succeeds, manually parse and
   type-validate path + every body field (including allowing JSON null only
   for `site_slug` / `hall_name`). Client `validation_failed` uses the reduced
   HTTP 400 body and creates no operation row, acquires no Hall mutex, and
   writes no provisioning audit.
2. Provisioning-schema readiness check (no DDL/repair). Unready → reduced 503
   `platform_unavailable`; no Hall mutex; no row access; no audit.
3. Acquire the Hall provisioning mutex. Then load the current Hall operation
   row and distinguish: verified row absence; verified readable valid row;
   unreadable storage/read failure; readable but corrupt or internally
   impossible row. **Validate stored operation state before classification**
   (see below). Only then classify against a verified-absent or verified-valid
   row **before** applying post-ownership null rules, in this exact order:
   - **(a)** Exact current `operation_id` + `intent_sequence` +
     `request_fingerprint` → retry/resume or `already_applied` classification.
     Accept the request’s `site_slug` / `hall_name` exactly as stored in
     `request_site_slug` / `request_hall_name` for that command (including the
     original non-null first-enable values after `blog_id` became bound
     mid-flight, and including JSON-null values for a suspended command). Exact
     retries retain the same durable slug and the same `site_ownership_id`. Do
     not reject a matching in-progress first-enable retry merely because a site
     is now bound.
   - **(b)** Same current `operation_id` with any different sequence or
     fingerprint/payload → `conflict`, **regardless of whether the supplied
     sequence is lower or higher**.
   - **(c)** Different `operation_id` with a lower sequence → `superseded`.
   - **(d)** Different `operation_id` with an equal sequence → `conflict`.
   - **(e)** Only a different `operation_id` with a higher valid sequence →
     **new command**.
4. Every **new** `desired_state=suspended` command uses JSON-null `site_slug`
   and `hall_name` (before or after durable ownership). Those nulls MUST NOT
   erase durable `site_slug` or `site_ownership_id` when present.
5. For a new `desired_state=operational` command when **durable ownership**
   already exists: `site_slug` and `hall_name` MUST be JSON null. Those nulls
   MUST NOT erase durable `site_slug` or `site_ownership_id`. A non-null
   `site_slug` that differs from the durable value is `conflict` (identity
   conflict / attempted rename; zero row mutation — not `slug_conflict`).
6. For a new `desired_state=operational` command **before** durable ownership
   exists: `site_slug` and `hall_name` MUST be non-null and valid. A newer
   valid operational command may **atomically replace** this Hall operation’s
   previously reserved unbound `site_slug`. That replacement releases only this
   Hall operation’s previous reservation and MUST NEVER alter another
   Hall/site/mapping. It MUST NOT regenerate `site_ownership_id` if one is
   already persisted.
7. **Pre-durable-ownership suspended commands** (higher valid suspended new
   command; must supersede and prevent any older Enable from resuming). Exact
   branches:
   - **`site_ownership_id` is null:** automatic create could not previously
     have started. Complete pre-bind suspended cancellation without binding or
     modifying an unrelated site at the reserved address; no platform filter;
     no new ownership ID; no mapping; no per-site HallCommerceState.
     Response: `commerce_state=suspended`, `effective_access=portal_blocked`,
     `blog_id=null`, `site_url=null`, `error_category=null`. First processing
     `applied`; exact confirmed retry `already_applied`.
   - **`site_ownership_id` exists and no site exists at the reserved address:**
     complete the same no-site pre-bind cancellation while **preserving** that
     ownership ID (never regenerate). Same null `blog_id`/`site_url` success
     response as above.
   - **`site_ownership_id` exists, `blog_id` is null, a site exists at the
     reserved address, and `bc_commerce_managed` exactly equals that
     `site_ownership_id` (marker-matched partial site):** do **not** invoke
     `bingocommerce_platform_provision_hall_site`. Resolve the candidate through
     the same address-and-marker verification used by partial-create recovery.
     Guardedly attach `blog_id` under the current command fence and read it
     back; verify all three durable-ownership facts; keep `archived=1` if the
     partial site remains archived; do **not** perform platform initialization
     merely to suspend. **Before confirming suspension, inspect mapping state
     (tenant-local; never disclose a foreign Hall or blog identity):**
     - no mapping: do **not** create one during suspend; continue the verified
       suspension;
     - exact mapping for this `portal_hall_id` and `blog_id`: preserve it;
     - any mapping that associates this Hall with another blog, or this blog
       with another Hall: return `mapping_invalid`; keep the site archived;
       perform no mapping, HallCommerceState, or confirmation mutation; return
       the fixed non-200 custom response with `blog_id`/`site_url`/
       `commerce_state`/`effective_access` null; require explicit operator
       recovery; never repair silently.
     On the success path: write settled suspended HallCommerceState; confirm
     `enforced_sequence` and clear `last_error_category` under the current
     command fence. Return `applied` (exact confirmed retry
     `already_applied`) with verified positive `blog_id` and independently read
     authoritative `site_url`, `commerce_state=suspended`,
     `effective_access=portal_blocked`, `error_category=null`. Preserve the
     site, slug, marker, ownership ID, exact mapping if already present, and
     partial data. A later operational command resumes platform initialization
     and clears `archived` only after readiness succeeds.
   - **Candidate marker absent, different, or unreadable:**
     `site_ownership_unproven`; operator recovery. Never claim successful
     cancellation.
   - If guarded attachment/read-back fails or ownership cannot be confirmed,
     never report suspension success (`state_write_failed` or `unverified` per
     the documented distinction).
8. If an ownership-unproven orphan exists for this Hall’s prior create attempt,
   a new slug MUST NOT silently abandon or bypass it; return
   `site_ownership_unproven` and require explicit operator recovery.

**Stored operation-row validation (before classification).**

After schema readiness and Hall-mutex acquisition, before casting, comparing,
hashing, or classifying an existing row, validate all persisted
external-derived values and relational invariants, including:

- `portal_hall_id` equals the requested Hall;
- canonical `operation_id` UUID;
- `intent_sequence` and `enforced_sequence` integer bounds;
- `enforced_sequence <= intent_sequence`;
- closed `desired_state` values on the operation row;
- when HallCommerceState is consulted, closed `commerce_state` values
  (`provisioning`|`operational`|`suspended`) only;
- `request_canonical_version` exactly `1`;
- valid `request_fingerprint`;
- recomputed fingerprint equals the stored fingerprint;
- nullable request and durable slug/name fields satisfy their documented
  format;
- `portal_actor_id` and `last_error_category` satisfy their closed contracts;
- `blog_id != null` requires non-null `site_ownership_id` and durable
  `site_slug`;
- `site_ownership_id != null` requires durable `site_slug`;
- no ownership-null branch may treat a non-null `blog_id` as “no site
  created.”

Unreadable or corrupt / internally impossible existing state must fail closed:

- no row insert/update;
- no platform filter;
- no site, mapping, or HallCommerceState mutation;
- no confirmation;
- release every acquired lock;
- HTTP 503; full custom body; `outcome=unverified`;
  `error_category=unverified`;
- `blog_id`, `site_url`, `commerce_state`, and `effective_access` null;
- fixed safe generic server diagnostics only;
- no raw stored value or database diagnostic in response, audit, or logs;
- exactly one terminal `portal.commerce.unverified` audit attempt using only
  safe request identity and `blog_id` null.

Verified row absence and verified readable valid rows continue into
classification. Schema unreadiness remains the earlier reduced 503 path and
does not acquire the Hall mutex.

**Field validation (closed)**

- `operation_id`: string; lowercase UUID v4 with hyphens;
  `\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`.
- `intent_sequence`: JSON integer (reject floats/strings);
  `1 .. (PHP_INT_MAX >> 8)`.
- `desired_state`: exactly `operational` or `suspended`.
- `site_slug` when non-null: string; DNS label
  `\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z`; max 63 octets.
- `hall_name` when non-null: reuse the committed Portal snapshot Hall-name
  contract in `PortalSnapshotValidator` (`HALL_NAME_MAX = 255` Unicode
  characters; valid UTF-8; reject `\p{Cc}`/`\p{Cf}`/`\p{Zl}`/`\p{Zp}`; require
  at least one non-whitespace/non-`Z` character; **do not trim or rewrite** the
  accepted string). The tested 255-character multibyte Hall name (765 bytes)
  must be accepted. Display attribute only, never identity.
- `portal_actor_id`: JSON integer `1 .. PHP_INT_MAX`; Portal attribution only;
  never a WordPress user ID; never authorization proof.

Portal retries reuse the same operation ID, sequence, and canonical request
values. A timeout or unreadable response is unconfirmed; the Portal MUST NOT
report completion.

**Response object (full body)** — exact custom-response rules:

All custom bodies:

- `api_version` is the exact string `"1"`.
- `portal_hall_id` is the validated path ID.
- `operation_id` and `intent_sequence` echo the validated request.
- Never return `site_ownership_id`, fingerprint, foreign identity, SQL,
  internal diagnostics, credentials, paths, or lock names.

Exact keys on every custom full body: `api_version`, `portal_hall_id`,
`operation_id`, `intent_sequence`, `outcome`, `commerce_state`,
`effective_access`, `blog_id`, `site_url`, `error_category`.

HTTP 200 `applied` / `already_applied` with verified durable ownership:

- `blog_id` MUST be a positive integer.
- `site_url` MUST be the independently read authoritative WordPress site URL.
- Neither may be null.
- Operational success: `commerce_state=operational`;
  `effective_access=allowed` in the billing-neutral MVP.
- Durable-site suspended success (including marker-matched partial-site
  suspend that verified three-fact ownership): `commerce_state=suspended`;
  `effective_access=portal_blocked`.
- `error_category=null`.

HTTP 200 pre-bind suspended cancellation with no site:

- `blog_id=null`;
- `site_url=null`;
- `commerce_state=suspended`;
- `effective_access=portal_blocked`;
- `error_category=null`.

Every custom non-200 response (full or reduced where applicable):

- Full bodies set `blog_id=null`, `site_url=null`, `commerce_state=null`,
  `effective_access=null`, and `error_category` equal to the exact HTTP-matrix
  condition.
- A null error-response field must **never** instruct Portal to erase a
  previously confirmed site connection.
- Reduced bodies use exact keys only: `api_version` (`"1"`), `error_category`,
  and `portal_hall_id` when the path id was validly parsed.

WordPress core response bodies apply whenever BingoCommerce callbacks are
not reached — including Application Password / core authentication failures,
malformed JSON (`rest_invalid_json`), route/method mismatches, and any core
registered-argument validation failures. Only requests that reach BingoCommerce
callbacks can receive the complete custom ADR-042 schema.

**UUID / sequence / ownership-identity semantics (MVP — one mutable row per
Hall).**

- `intent_sequence` is the durable anti-replay / ordering authority.
- `operation_id` identifies **only** the current Portal command on that row.
- `site_ownership_id` is the permanent BingoCommerce-internal site ownership
  identity. It is never supplied by Portal, is not part of Canonical Request v1
  or `request_fingerprint`, is not returned in REST responses, and is not
  written into audit context.
- Classification precedence is exactly (a)–(e) above: current-UUID mismatch is
  `conflict` **before** any lower-sequence `superseded` evaluation.
- A higher accepted sequence with a different `operation_id` replaces the
  current command identity but **never** replaces `site_ownership_id` or an
  established `blog_id`. Accepting that newer command clears
  `last_error_category` as part of the guarded command replacement.
- Confirmations and mutations remain guarded by the current command sequence.
- With one mutable row per Hall, **historical** Portal `operation_id`
  uniqueness across replaced commands cannot be guaranteed detectable and MUST
  NOT be claimed. Global historical UUID uniqueness would require a separate
  append-only UUID registry (not part of this MVP).

**Canonical Request fingerprint v1 (injective).**

SHA-256 over the UTF-8 bytes of exactly eight newline-terminated records in
this order (the digest input ends with the eighth record’s trailing `\n`).
Encoding is typed and length-prefixed so JSON null cannot collide with any
string (including the literal characters `null`):

```text
v1\n
u:portal_hall_id:<decimal>\n
s:operation_id:<byte_length>:<raw utf-8 bytes>\n
u:intent_sequence:<decimal>\n
s:desired_state:<byte_length>:<raw utf-8 bytes>\n
<site_slug_record>\n
<hall_name_record>\n
u:portal_actor_id:<decimal>\n
```

where `<site_slug_record>` is exactly `n:site_slug` or
`s:site_slug:<byte_length>:<raw utf-8 bytes>`, and `<hall_name_record>` is
exactly `n:hall_name` or `s:hall_name:<byte_length>:<raw utf-8 bytes>`.

Rules:

- `u:` = unsigned decimal integer token (no leading zeros).
- `n:name` = JSON null for that field (exactly those bytes).
- `s:name:<len>:` = non-null string; `<len>` is the exact UTF-8 byte length in
  decimal; following bytes are the raw string bytes with no escaping. Length is
  authoritative. Accepted Hall names forbid `Cc`/`Cf`/`Zl`/`Zp` and accepted
  slugs are DNS-safe, so line boundaries cannot be injected through accepted
  values. `site_ownership_id` is not a fingerprint input.
- Fingerprint hex is lowercase 64 chars.
- Store `request_canonical_version = 1` and `request_fingerprint`.

**Provisioning operations table.**

Exact migration contract (`ProvisioningMigrations`):

- Version option: `bc_portal_provisioning_db_version`.
- Target version: `2`.
- Clean installation creates the final v2 commerce-state and provisioning-
  operations schemas.
- A genuine v1→v2 upgrade adds the operations table without recreating,
  rewriting, truncating, or changing `bingo_hall_commerce_state` or any
  existing row.
- After DDL, verify: operations table existence; every required column and
  type; InnoDB engine; table charset/collation; `ascii_bin` on required ASCII
  identity columns; primary key; and UNIQUE indexes for `operation_id`,
  `site_ownership_id`, `blog_id`, and `site_slug`.
- Set version=`2` only after all verification succeeds. Version value alone
  never proves schema readiness.
- Missing version is clean-install state only when option storage was read
  successfully and expected schema absence/presence was safely classified.
- Unreadable version storage, database error, exception, failed DDL, missing
  or incorrect schema, or failed verification must never falsely advance
  version.
- Re-running a verified v2 migration is idempotent.
- If version=`2` exists but schema verification fails, do not report the
  schema as current.
- Migration failures use fixed safe errors without SQL, table prefix, database
  driver diagnostics, filesystem paths, or credentials.

**Runtime endpoint gate (before locks / operation-row access).**

- The REST request must never migrate or repair the NETWORK provisioning
  schema: no `migrate()`, no DDL and no repair against
  `{base_prefix}bingo_portal_provisioning_operations`,
  `{base_prefix}bingo_hall_commerce_state` or their version option. An unready
  network provisioning schema is the reduced 503 `platform_unavailable` above
  and is repaired by an explicit operator migration.
  **Clarification [2026-09-13]:** this rule is about the network provisioning
  schema and not about a new Hall site's own tables. Platform-owned creation of
  a NEW Hall site necessarily creates that site's required WordPress,
  WooCommerce, Catalog, Session and related per-site tables as part of the
  approved initialization in
  `bingocommerce_platform_provision_hall_site`; that is the "prepared" store
  clause 7 of the business contract requires, it is performed by invoking the
  owners of those schemas rather than by reissuing their SQL, and it is scoped
  to the site being provisioned.
- After BingoCommerce authentication and manual client request validation,
  but before Hall-mutex acquisition or operation-row access, perform a safe
  provisioning-schema readiness check.
- The check may use safely cached verified migration state, but a failed
  required storage read must invalidate readiness.
- If v2 storage is unavailable, unreadable, or unverified: HTTP 503; reduced
  custom body; `error_category=platform_unavailable`; outcome absent; create/
  mutate no operation row; invoke no platform filter; create no site or
  mapping; write no provisioning audit (classification not reached); same
  request retryable after operator repair.

Network table `{base_prefix}bingo_portal_provisioning_operations`:

- ENGINE=`InnoDB`
- Default charset/collation: `$wpdb->get_charset_collate()`
- ASCII binary columns: `CHARACTER SET ascii COLLATE ascii_bin`

| Column | Definition |
|---|---|
| `portal_hall_id` | BIGINT UNSIGNED NOT NULL PRIMARY KEY |
| `blog_id` | BIGINT UNSIGNED NULL |
| `site_ownership_id` | CHAR(36) NULL, ascii_bin — permanent BingoCommerce-internal ownership UUID; never Portal-supplied; stable across retries, unbound-slug replacement, suspend, re-enable, and later Portal commands |
| `site_slug` | VARCHAR(63) NULL, ascii_bin — **durable** reserved/bound slug; never overwritten with null; atomically replaceable only before durable ownership; immutable once durable ownership exists |
| `request_site_slug` | VARCHAR(63) NULL, ascii_bin — current command’s request value (null allowed) |
| `request_hall_name` | VARCHAR(255) NULL — current command’s request value; table/default utf8mb4 charset; sized for 255 Unicode characters per Portal Hall-name contract |
| `portal_actor_id` | BIGINT UNSIGNED NOT NULL |
| `operation_id` | CHAR(36) NOT NULL, ascii_bin — current Portal command only |
| `intent_sequence` | BIGINT UNSIGNED NOT NULL |
| `enforced_sequence` | BIGINT UNSIGNED NOT NULL DEFAULT 0 |
| `desired_state` | VARCHAR(16) NOT NULL, ascii_bin |
| `request_canonical_version` | TINYINT UNSIGNED NOT NULL |
| `request_fingerprint` | CHAR(64) NOT NULL, ascii_bin |
| `last_error_category` | VARCHAR(64) NULL, ascii_bin |
| `created_at` | DATETIME NOT NULL (UTC) |
| `updated_at` | DATETIME NOT NULL (UTC) |

Indexes: PRIMARY (`portal_hall_id`); UNIQUE (`operation_id`); UNIQUE
(`site_ownership_id`); UNIQUE (`blog_id`); UNIQUE (`site_slug`). Operational
state only — not an audit log.

**Sequence and outcome contract.**

Six outcomes:

- `applied` — this call confirmed `enforced_sequence == intent_sequence` for
  this current `operation_id` after performing needed work, including (on the
  operational enable path) successful `archived=1` clear with read-back when
  that clear is required, and including pre-durable-ownership suspended
  cancellation when no blog/orphan exists. Do not return `applied` if a
  required clear/confirmation fails or is unverifiable.
- `already_applied` — before workflow work, read-back already proves the same
  current `operation_id`, sequence, fingerprint, full enforcement,
  `last_error_category` is null, and (when already operational) that `archived`
  is cleared; or (for a confirmed pre-bind no-site suspended cancellation)
  `commerce_state=suspended` with `blog_id`/`site_url` null; or (for a
  confirmed marker-matched partial-site / durable-site suspended command)
  `commerce_state=suspended` with verified positive `blog_id` and authoritative
  `site_url`. Exact retries that are **not** yet confirmed MUST resume; they
  MUST NOT return `already_applied`.
- `superseded` — different `operation_id` with a lower sequence (precedence
  (c)); zero mutation.
- `conflict` — request/sequence identity conflict with **zero row mutation**,
  including: same current `operation_id` with any different sequence or
  fingerprint/payload (precedence (b)); different `operation_id` with equal
  sequence (precedence (d)); a new request trying to rename an already durably
  bound slug; and foreign UNIQUE collisions that prevent establishing this
  Hall’s row. `conflict` is **not** `slug_conflict`.
- `failed` — ordinary domain failure after identity (see HTTP matrix),
  including `slug_conflict`.
- `unverified` — mutation may have occurred but confirmation/read-back failed;
  never report success.

Writer MUST NOT use SELECT-then-INSERT as concurrency guarantee. Sequence
assigned last. Read-back is authoritative.

**`last_error_category` lifecycle.**

- Accepting a newer command clears the previous command’s error category as
  part of the guarded command replacement.
- A failed current command stores its allowlisted category only while that
  identity/sequence remains current.
- Successful confirmation clears `last_error_category` with guarded write and
  read-back.
- `already_applied` requires confirmed enforcement and a null stale-error
  field.
- `superseded`, `conflict`, authentication, authorization, validation, and
  unsafe foreign-collision paths never change another/current row’s error
  field.
- Failure to verify the guarded clearing MUST NOT return success; use
  `unverified` according to the existing contract.

**Closed error allowlists (no additions without a new reviewed decision).**

Response `error_category` set:

`authentication_failed`, `authorization_failed`, `validation_failed`,
`lock_unavailable`, `superseded`, `conflict`, `address_invalid`,
`address_occupied`, `slug_conflict`, `mapping_invalid`, `site_missing`,
`site_create_failed`, `site_ownership_unproven`, `readiness_failed`,
`sync_failed`, `state_write_failed`, `confirmation_failed`,
`platform_unavailable`, `unverified`, `internal_error`.

`last_error_category` set (persist only after Hall operation identity is
safely established):

`address_invalid`, `address_occupied`, `slug_conflict`, `mapping_invalid`,
`site_missing`, `site_create_failed`, `site_ownership_unproven`,
`readiness_failed`, `sync_failed`, `state_write_failed`,
`confirmation_failed`, `platform_unavailable`, `unverified`, `internal_error`.

MUST NOT create/mutate a Hall operation row:

core pre-callback failures (auth / malformed JSON / route-method / core args),
`authentication_failed`, `authorization_failed`, `validation_failed`,
provisioning-schema unreadiness (`platform_unavailable` reduced 503 before
Hall mutex), `lock_unavailable` when the Hall operation cannot be safely
established, unreadable/corrupt/impossible existing operation rows, and foreign
UNIQUE (`site_slug`) / current UNIQUE (`operation_id`) collisions that prevent
establishing or mutating this Hall’s row.

`address_invalid`: slug fails DNS/WordPress checks while this Hall’s
operation row is safely established — `failed`; Portal issues a **new**
operational command with a corrected slug (may replace an unbound reservation
for this Hall only). Evaluated only after the recoverable marker-matching
partial-create branch does not apply.

`address_occupied`: an existing WordPress site or foreign reservation at the
reserved address that is **not** this Hall’s recoverable marker-matched partial
create — `failed`; new operational command with a different confirmed slug
before durable ownership. The recoverable partial-create branch is evaluated
**before** this category.

`conflict` versus `slug_conflict` (non-overlapping):

- `conflict`: request/sequence identity conflict, including a new request
  trying to rename an already durably bound slug, and foreign UNIQUE collisions
  that prevent establishing this Hall’s row. Outcome `conflict`; **zero** row
  mutation; does not persist `last_error_category`.
- `slug_conflict`: an internal same-Hall invariant failure discovered **after**
  safe operation identity establishment — for example, the operation row’s
  durable slug disagrees with the verified bound site/network address or
  reservation state. Outcome `failed`; full body; may persist
  `last_error_category`. Recovery: explicit Network Super Admin / Platform
  Admin operator repair of the bound address/reservation invariant; Portal MUST
  NOT treat a new slug command as an automatic rename of a durably bound site.

**UNIQUE constraint handling (each independently).**

`UNIQUE(operation_id)` — foreign current-UUID collision: `conflict`, zero
mutation, no foreign disclosure, no `last_error_category` persistence when this
Hall has no safely established row; terminal conflict audit with `blog_id`
null; Portal submits a new UUID and higher sequence when appropriate.

`UNIQUE(site_slug)` — preserve address reservation, recoverable-marker partial
create, and foreign collision behavior (`conflict` with zero mutation and no
foreign disclosure when this Hall’s row cannot be established/mutated). Cross-
Hall slug exclusion uses this UNIQUE only; do **not** introduce a global slug
mutex.

`UNIQUE(site_ownership_id)` — internally generated; never a client conflict.
Use exactly **three** UUIDv4 allocation attempts. Each attempt is allowed only
while `site_ownership_id` remains null and the current operation fence still
matches. A collision leaves the field null and causes another internally
generated lowercase UUIDv4 attempt. After three collisions: `internal_error`;
do not invoke platform creation; do not expose the candidate UUID or conflicting
row; never replace an already-persisted `site_ownership_id`.

`UNIQUE(blog_id)` — a collision during guarded attachment must not overwrite
either Hall row; do not establish durable ownership or confirmation; return
`site_ownership_unproven` with explicit operator recovery; return no `blog_id`,
`site_url`, foreign Hall ID, mapping, slug, or ownership ID.

A Hall-mapping-table disagreement remains `mapping_invalid` and must never be
silently repaired by the machine endpoint.

**Hall-scoped provisioning mutex and stale-command fencing.**

Define one provisioning mutex per `portal_hall_id`. It must never serialize
different Halls globally. Hold it from current-row load/classification through
the final provisioning mutation and confirmation snapshot. Protected scope
includes: accepted-command persistence; `site_ownership_id` assignment;
platform filter invocation; `blog_id` attachment; ownership verification; Hall
mapping mutation; Portal snapshot application; HallCommerceState mutation;
`archived`-status clearing; `last_error_category` clearing; and
`enforced_sequence` confirmation.

If the existing snapshot synchronizer acquires its Hall sync lock, provisioning
acquires the provisioning mutex **first** and the sync lock **second**. No path
may acquire them in reverse order. Cross-Hall slug exclusion continues to use
`UNIQUE(site_slug)` only.

Immediately before each irreversible or externally visible mutation, revalidate
mutex ownership and the current `operation_id` / `intent_sequence` fence. If
the mutex is lost or the fence cannot be verified: stop further work; never
clear `archived`; never write `operational` state; never overwrite a newer
`suspended` state; never confirm success; return `lock_unavailable` if no
mutation occurred, otherwise `unverified`. If platform creation already produced
an archived marked site before stale or lost-lock detection, leave it archived;
a later current command must handle it through marker-matched partial recovery.
A stale Enable must never resume after a newer Disable. Release the mutex only
after the terminal response snapshot is fixed. The terminal audit attempt may
occur afterward using the immutable captured outcome.

**Deterministic HTTP matrix (no implementation choice).**

| Condition | HTTP | Body | `outcome` | Row may change | Same-operation retryable |
|---|---|---|---|---|---|
| applied (incl. pre-bind cancel / marker-matched partial suspend) | 200 | full | `applied` | yes (confirmed) | n/a |
| already_applied (incl. confirmed pre-bind cancel / partial suspend) | 200 | full | `already_applied` | no | n/a |
| `authentication_failed` (reached BingoCommerce `permission_callback`) | 401 | reduced | absent | no | yes, with valid App Password |
| Core Application Password / authentication rejection before BingoCommerce callbacks | 401 | WordPress core REST body | absent | no | yes, with valid App Password |
| Core malformed JSON (`rest_invalid_json`) before BingoCommerce callbacks | 400 | WordPress core REST body | absent | no | no (fix JSON) |
| Core route/method mismatch or core registered-argument validation failure | 4xx per core | WordPress core REST body | absent | no | no (fix route/method/registration) |
| `authorization_failed` | 403 | reduced | absent | no | no |
| `validation_failed` (manual path/body validation after permission; before mutex/row) | 400 | reduced | absent | no | no (fix request) |
| Provisioning schema unreadiness (after auth+manual validation; before Hall mutex) | 503 | reduced | absent | no | yes (after operator repair) |
| `lock_unavailable` | 503 | reduced | absent | no | yes |
| Unreadable or corrupt/impossible existing operation row (after mutex; before classify) | 503 | full | `unverified` | **no** | yes (after operator repair) |
| `superseded` (different `operation_id`, lower sequence) | 409 | full | `superseded` | no | no |
| `conflict` (identity/payload/rename/foreign `operation_id` or `site_slug` UNIQUE; zero mutation) | 409 | full | `conflict` | **no** | no |
| `address_invalid` (Hall row safely established; after recoverable-partial branch) | 400 | full | `failed` | yes (`last_error_category`) | no (new operational command; may replace unbound reservation) |
| `address_occupied` (Hall row safely established; not marker-matched partial create) | 409 | full | `failed` | yes | no (new operational command with different slug before durable ownership) |
| `slug_conflict` (same-Hall invariant after identity; not rename conflict) | 409 | full | `failed` | yes | no (operator repair of bound address/reservation invariant) |
| `mapping_invalid` | 409 | full | `failed` | yes | no (operator repair; never silent machine repair) |
| `site_missing` | 409 | full | `failed` | yes | no (operator recovery) |
| `site_create_failed` | 503 | full | `failed` | yes | yes |
| `site_ownership_unproven` (incl. `UNIQUE(blog_id)` attach collision) | 409 | full | `failed` | yes | no (operator recovery) |
| `readiness_failed` | 503 | full | `failed` | yes | yes |
| `sync_failed` | 503 | full | `failed` | yes | yes |
| `state_write_failed` | 503 | full | `failed` | yes | yes |
| `platform_unavailable` (after classification / filter unavailable) | 503 | full | `failed` | yes | yes |
| `internal_error` (incl. three `site_ownership_id` allocation collisions) | 503 | full | `failed` | yes | yes |
| `confirmation_failed` | 503 | full | `unverified` | maybe | yes |
| `unverified` (incl. lost mutex/fence after possible mutation) | 503 | full | `unverified` | maybe | yes |

On every custom response, fields follow the exact custom-response rules above.
Schema-unreadiness uses reduced body, `error_category=platform_unavailable`,
outcome absent, no Hall mutex, and no operation-row mutation. Corrupt/unreadable
stored-row failures use full body, `outcome=unverified`,
`error_category=unverified`, all four state/site fields null, no row mutation,
locks released, and one terminal `portal.commerce.unverified` audit with
`blog_id` null.

Custom reduced authentication/authorization/validation bodies apply **only**
when BingoCommerce callbacks are reached. Core auth, malformed JSON,
route/method, and core argument failures keep WordPress core bodies. No
operation row, Hall mutex, provisioning audit, or site action occurs when
execution never reaches custom classification.

**Permanent site ownership identity (`site_ownership_id`).**

- BingoCommerce generates a lowercase canonical UUIDv4 and stores it in
  `site_ownership_id`.
- Never supplied by Portal; absent from Canonical Request v1,
  `request_fingerprint`, REST responses, and audit context.
- UNIQUE across provisioning-operation rows.
- Stable across retries, unbound-slug replacement, suspend, re-enable, and
  every later Portal command.
- Site marker definition: `bc_commerce_managed` = `site_ownership_id`.
  **Never** use current Portal `operation_id` as the marker.

**Assignment before first platform create.** Before the first platform create
attempt for a Hall, assign `site_ownership_id` to that Hall’s operation row
with a guarded write and read-back verification under the Hall mutex and
current-command fence:

- If the row already has a persisted `site_ownership_id`, reuse it (same or
  newer valid command) — do not generate another; never replace it.
- Allocation uses exactly three lowercase UUIDv4 attempts per the
  `UNIQUE(site_ownership_id)` rules above. After three collisions return
  `internal_error` without invoking platform creation.
- If assignment or read-back cannot be verified, do **not** invoke the platform
  creation filter. Return `state_write_failed` or `unverified` according to the
  documented distinction (`failed` when the write is known unsuccessful;
  `unverified` when mutation may have occurred but confirmation/read-back
  failed).

**Address / durable-slug mutability contract.**

Portal suggestion is UX only. BingoCommerce validates against the network,
WordPress restrictions, existing sites, and active operation-table
reservations, re-checking while holding the reservation.

Durable `site_slug` mutability:

- **Before durable site ownership exists**, a newer valid first-enable command
  may atomically replace the reserved `site_slug` for this Hall’s operation row
  only (for example after `address_invalid` / `address_occupied`).
- **Durable ownership begins only when all three facts agree**, or an existing
  valid Hall mapping has been adopted through explicitly authorized
  recovery/backfill that also establishes a permanent `site_ownership_id`
  (see recovery below).
- **Once durable ownership exists**, `site_slug` is immutable.
- Exact retries retain the same slug and the same `site_ownership_id`.
- If an ownership-unproven orphan exists, a new slug MUST NOT silently abandon
  or bypass it; explicit operator recovery is required.
- Replacing an unbound reservation releases only that Hall operation’s previous
  reservation and never alters another Hall/site/mapping.

**Durable ownership (three-fact agreement).**

Create and BingoCommerce witnesses are not one atomic cross-system transaction.
Durable ownership for automatic provisioning requires **all three**:

1. The Hall operation row contains `blog_id`;
2. The same row contains `site_ownership_id`;
3. That blog has `bc_commerce_managed` exactly equal to that
   `site_ownership_id`.

Slug equality alone remains insufficient. The current Portal `operation_id` is
not an ownership witness.

**Partial-site-creation recovery.**

When an operational attempt has persisted `site_ownership_id` but `blog_id` is
null, the platform consumer resolves the reserved address using the recoverable
marker-matching branch defined in the filter contract (create / recover /
`site_ownership_unproven`) **before** generic `address_occupied`. After the
filter returns successfully, the orchestrator attaches `blog_id` with a
current-sequence guarded write and read-back before three-fact ownership
becomes durable, independently reads `site_url` from the verified WordPress
site, then continues mapping/sync/readiness/state. Clear `archived` only when
those facts hold and Portal Commerce can enforce HallCommerceGate; operational
enable success (`applied` / `already_applied`) is confirmed **only after**
clearing `archived=1` is itself successful and read back. Failure or
unverifiable read-back of that clear MUST NOT return `applied` /
`already_applied` (map to `state_write_failed` or `unverified` per the
documented distinction). Never re-archive from an older/resumed command after
that clearing. Ambiguous orphans → `site_ownership_unproven` + explicit
operator recovery; never silent bind/delete or silent slug bypass.

Recovery/backfill for legacy or ambiguous sites may establish a permanent
`site_ownership_id` **only** through the separately authorized Network Super
Admin / Platform Admin procedure. Never infer or reclaim ownership from a slug
or from a current Portal command UUID.

**Enable / cancel / suspend workflow (conceptual order).**

1. WordPress core may authenticate and match the route/method, parse JSON, and
   run any registered-argument checks before BingoCommerce callbacks. Malformed
   JSON / core route-method / core-arg failures never reach custom handling.
2. BingoCommerce `permission_callback` (App Password proof + capability). Custom
   auth/authorization failures only here; no operation-row write.
3. Endpoint callback: manual path + body validation (`validation_failed` →
   reduced 400; no mutex; no row write; no audit).
4. Provisioning-schema readiness check (no DDL/repair). Unready → reduced 503
   `platform_unavailable`; no Hall mutex; no row access; no audit.
5. Acquire the Hall provisioning mutex. Lock failure → `lock_unavailable`; no
   row write. If snapshot sync is needed later, acquire sync lock only after
   this mutex.
6. Load and **validate stored operation state** before classification. Verified
   absence or valid row continues; unreadable/corrupt/impossible row → fail
   closed as `unverified` (full 503; release locks; one terminal unverified
   audit; no mutation).
7. Classify by exact precedence (a)–(e); on accepted new command, guarded
   replace of current command columns clears `last_error_category`. Never
   replace `site_ownership_id` or established `blog_id` on a higher sequence.
   Revalidate mutex + fence before each irreversible/externally visible
   mutation.
8. Apply the exact pre-durable-ownership suspended branches from null-rule 7
   (no-site cancel; marker-matched partial-site suspend with mapping
   absent/exact/conflict handling and without platform filter; or
   `site_ownership_unproven`).
9. Before first platform create on an operational path: assign/verify
   `site_ownership_id` (three allocation attempts) with guarded write +
   read-back; on failure do not invoke the platform filter.
10. For operational create/init/resume only: single platform filter invocation
   (resolve address → create or recover partial → init, or resume init) using
   the persisted `site_ownership_id`. Suspend commands skip this filter.
11. Attach `blog_id` with current-sequence guarded write/read-back (handle
   `UNIQUE(blog_id)` as `site_ownership_unproven`); verify three-fact
   ownership; independently read `site_url`.
12. Orchestrator: HallCommerce `provisioning`/`suspended` as required when a
    blog exists, mapping, full-pull sync (enable path; mutex before sync lock),
    SetupReadiness (never repair inside evaluator), clear `archived=1` with
    successful read-back before reporting operational enable success only while
    the current fence still owns the mutex, confirm `enforced_sequence` and
    clear `last_error_category`, fix the terminal response snapshot, then
    release the mutex. Terminal audit may run afterward from the immutable
    captured outcome.

Portal must ensure the snapshot credential has a Hall grant before requesting
provisioning; payload never contains snapshot token plaintext.

**Audit contract (ordinary ADR-023 AuditLog; not ADR-025 transactional).**

- Storage: `{base_prefix}audit_log` only.
- Before durable `blog_id` bind: `blog_id` key present with null.
- After bind: that site id.
- `actor_user_id`: Application Password provisioning account’s WordPress user
  id (never `portal_actor_id`).
- Allowlisted context keys only: `portal_actor_id`, `portal_hall_id`,
  `operation_id`, `intent_sequence`, `desired_state`, `outcome`,
  `error_category`, `effective_access`. `site_ownership_id` MUST NOT appear.
- Allowlisted actions (terminal only — no `intent_accepted` event):
  `portal.commerce.applied`,
  `portal.commerce.already_applied`,
  `portal.commerce.superseded`,
  `portal.commerce.conflict`,
  `portal.commerce.failed`,
  `portal.commerce.unverified`,
  `portal.commerce.suspended`.
- Exact one-action mapping (keep exactly one terminal audit attempt):
  - `desired_state=suspended` and outcome `applied` or `already_applied`
    (including pre-bind no-site cancellation with `blog_id` null, and
    marker-matched partial-site suspend with verified `blog_id`) →
    `portal.commerce.suspended`;
  - `desired_state=operational` and outcome `applied` →
    `portal.commerce.applied`;
  - `desired_state=operational` and outcome `already_applied` →
    `portal.commerce.already_applied`;
  - every other outcome maps to its matching terminal action
    (`superseded` → `portal.commerce.superseded`, `conflict` →
    `portal.commerce.conflict`, `failed` → `portal.commerce.failed`,
    `unverified` → `portal.commerce.unverified`).
- Exactly **one** terminal provisioning audit **attempt** for every
  authenticated, valid request that reaches operation classification —
  including `already_applied`, `superseded`, and `conflict` even when the
  operation row is not mutated (and including foreign UNIQUE collision
  conflict with `blog_id` null).
- “Mandatory” means the application must attempt `AuditLog::record()`. Ordinary
  AuditLog failure MUST NOT roll back an already-confirmed cross-system
  provisioning result; keep the confirmed result and write one fixed generic
  server log line with no error payload.
- Unauthenticated requests, core pre-callback failures (auth / malformed
  JSON / route-method / core args), pre-mutex validation failures, and
  pre-mutex schema-unreadiness: no operation row and no provisioning audit.
- **`lock_unavailable` clarification [2026-09-13].** A `lock_unavailable`
  response is by definition the REDUCED body with `outcome` ABSENT, and the
  one-action mapping above is keyed on the six outcomes, so such a response has
  no terminal action to record. Every genuinely PRE-MUTATION currentness
  failure — mutex acquisition refused, or the Hall mutex/command fence lost
  before this request could have changed anything — is therefore exempt from
  the terminal provisioning audit, whether or not classification had been
  reached. This narrows, and does not contradict, the "exactly one terminal
  audit attempt for every authenticated valid request that reaches operation
  classification" rule: it applies only where no outcome exists to attribute.
  ONCE A MUTATION MAY HAVE OCCURRED the same loss is `unverified`, not
  `lock_unavailable` — full body, `outcome=unverified`, and exactly one
  `portal.commerce.unverified` audit attempt, as already required. No new
  outcome and no new audit action is introduced.
- Unreadable/corrupt stored-row failures that reach mutex acquisition: exactly
  one terminal `portal.commerce.unverified` audit with safe request identity
  and `blog_id` null; no raw stored values.
- Terminal audit may run after mutex release using the immutable captured
  outcome snapshot.

**Suspend and re-enable.**

Every new suspended command uses JSON-null `site_slug` / `hall_name` (before or
after durable ownership). Suspend/re-enable are new commands only under
precedence (e) (higher sequence + different `operation_id`). Durable
`site_slug`, `blog_id`, and `site_ownership_id` are retained and immutable
once established; an already-persisted `site_ownership_id` is preserved even on
pre-bind cancellation and is never regenerated. A stale Enable must never
resume after a newer Disable.

**Before durable ownership:** follow null-rule 7 exactly — no-site
cancellation (`blog_id`/`site_url` null), or marker-matched partial-site
suspend (attach `blog_id`, keep `archived=1`, inspect mapping
absent/exact/`mapping_invalid`, write suspended HallCommerceState only on the
success path, return verified `blog_id`/`site_url`), or
`site_ownership_unproven`. Never invoke the platform filter on these paths.

**After durable ownership:** suspend skips
`bingocommerce_platform_provision_hall_site` but MUST verify permanent site
ownership using `site_ownership_id` (three-fact agreement) before mutating the
bound site’s Commerce state; `platform_unavailable` MUST NOT block safe
suspension. Suspend writes `suspended` settled; preserves
marker/mapping/domain/site/data; does not archive/delete. Re-enable reuses the
same site; never creates another; never regenerates `site_ownership_id`; again
requires JSON-null `site_slug`/`hall_name`. A later higher operational command
**before** durable ownership again requires valid non-null `site_slug` and
`hall_name` and may replace only this Hall’s unbound reservation; after a
marker-matched partial suspend it resumes platform initialization and clears
`archived` only after readiness succeeds.

Under the billing-neutral MVP rule, successful Portal `operational` yields
`effective_access=allowed` only after `archived=1` clear + read-back succeeds
when that clear is part of the enable path. After a future authoritative
BR-016 resolver exists, Portal re-enable still cannot erase or bypass that
billing state (`effective_access` may then be `billing_restricted` on HTTP 200
success).

**Portal-side behaviour (later Portal increment).**

- One monotonic intent sequence per Hall.
- New desired-state commands get a new lowercase UUID v4.
- Retries reuse UUID, sequence, and canonical body values.
- Global Admin confirms slug before first enable.
- Store/display only BingoCommerce’s authoritative URL from a custom HTTP 200
  success that returned non-null verified `blog_id`/`site_url`.
- A custom non-200 response with null `blog_id`/`site_url` must never erase a
  previously confirmed site connection.
- Ecommerce link opens that URL; no WP login before SSO; no Portal user copy.
- Disable shows suspended; connection retained when previously confirmed.
- Unconfirmed responses stay pending and retryable.
- Treat any response lacking the exact successful custom response schema as
  non-success. Core HTTP 401 is authentication failure; core malformed-request
  400 and route/method errors are protocol/configuration failures. None may
  create, change, or erase a confirmed Portal connection.

**Future deterministic test contract (not implemented).**

Mutex/fencing: different Halls remain concurrent; the same Hall is serialized;
a newer Disable wins over an older Enable; an older process cannot clear
`archived` or restore operational state; loss of the mutex after a possible
mutation returns `unverified`.

REST boundary: malformed JSON returns core `rest_invalid_json` before custom
callbacks; custom `validation_failed` covers only callback-reached invalid
shapes; core route/method errors never create rows/audits/sites.

Stored-row validation: verified absence vs valid row vs unreadable vs
corrupt/impossible ownership invariants; corrupt rows fail closed as
`unverified` with null state/site fields and one safe unverified audit.

Partial-site suspend: marker-matched success returns verified
`blog_id`/`site_url` while keeping `archived=1`; no mapping created on
suspend; exact mapping preserved; conflicting mapping → `mapping_invalid`
with site kept archived and no confirmation; no-site cancel returns nulls;
absent/different/unreadable marker → `site_ownership_unproven`.

UNIQUE paths: foreign `operation_id` collision; foreign `site_slug` collision;
three `site_ownership_id` allocation collisions → `internal_error`;
`UNIQUE(blog_id)` attach collision → `site_ownership_unproven`.

Migration/runtime: clean install; repeated verified v2; genuine v1→v2 with
byte-identical commerce-state preservation; failed DDL; failed verification;
unreadable version/schema storage; version=`2` with damaged/missing schema; no
false version advancement; no request-time DDL; schema unreadiness → reduced
503 `platform_unavailable` before locks; no fixture residue.

**Legacy halls.**

Sites 1–4 and Portal Hall 2 → site 2 remain legacy/untouched. Backfill requires
explicit Network Super Admin / Platform Admin operator action.

*Rationale:* Custom responses apply only after BingoCommerce callbacks are
reached; WordPress core owns malformed JSON and unmatched routes; stored
operation rows must be validated before classification so corrupt state fails
closed; marker-matched partial suspend must respect tenant-local mapping
absence/exact/conflict; and Portal must treat any non-successful custom schema
as non-success without erasing a confirmed connection.

- **ADR-043 — [2026-09-21] — Commerce Schedule and Dated Occurrence Persistence:**

**Status. APPROVED and IMPLEMENTED.** Ratified on 2026-09-21 at Revision 9. The implementation was committed in 262b250. Production deployment is not claimed.

**Date drafted:** 2026-09-16 · **Date ratified:** 2026-09-21 · **Revision:** 9 (BR-077 ratifies the thirty-day
window; BR-078 adds centralized committed-definition membership to availability,
Upcoming, administration and purchase eligibility. Removed future occurrences
are turned OFF on a successful definition edit; reconciliation and history
rules remain unchanged. The earlier Revision 9 advisory-lock, disable-first,
readiness and automatic-archival amendments are withdrawn. **Revision 9 is ratified; this document does not claim deployment.**) · **Revision:** 8 (Hall Manager UX correction:
manager-facing navigation, labels and create/generate orchestration described in
§15; sales window widened from five to thirty calendar days everywhere BR-051/
BR-063 apply; a Portal-connected hall's Game Type creation gated in
`GameTypeService::create()`. **No change to the schema, scheduling engine,
synchronization contract or reconciliation logic in §1–§14 and §16–§19** — see
§15's amendment note for exactly what changed and what did not) · **Revision:**
7 (implementation amendments: fixture-isolated test matrix, utf8mb4_bin on
`bingo_sessions` identity columns; otherwise Revision 6 unchanged) ·
**Revision:** 6 (template-eligible definition
commit, honest Package-validation timing, binary template-name proof on every
name write, non-empty Package-set proof, proved superseded-definition cleanup,
unsettled-ON survival semantics, non-destructive integrity runbook, verified
rollback baseline commit)

**Supersedes:** nothing. **Narrows:** nothing.
**Closes:** the deferred compatibility/scheduling design that ADR-035, BR-073 and
`docs/architecture/catalog-session-persistence-design.md` §2.8 each defer to
"the Session compatibility/scheduling design".

---

### 0. Scope

**This phase defines, and the current repository implements:** the
schedule and occurrence schema; definition-commit concurrency; run-fenced
reconciliation; timezone and DST; guarded availability with owned compensation,
retime and withdrawal; convergent incomplete-generation recovery; mutation
ownership over generated occurrences; database-contention handling; Portal-sync
readiness separation; rollback restrictions; permission, nonce, audit and
isolation rules. This approved ADR governs the implementation; production deployment is not claimed.

**This phase does not decide and does not implement:** the customer storefront
calendar; checkout, cart, orders or payment; entitlement, QR or redemption;
Portal webhooks, scheduled synchronization or reconciliation; SSO; and any change
to the Laravel Portal, which remains completely unchanged.

**Explicitly deferred to a later, separately reviewed design** (named so it is
not mistaken for an omission):

- garbage collection of **crash-abandoned private definition tokens** (§3.6);
- a durable order → occurrence reference for checkout (§7.2);
- exposing Game Type on the supported Package component read (§13.2);
- hiding `Create Game Type` in the WordPress UI (§14).

#### 0.1 SQL shapes verified against the installed database — and what that proves

Every Revision 1–8 non-trivial statement shape was validated read-only against the live
MySQL 9.5.0 on 2026-09-16 using `EXPLAIN`, which parses, resolves names and plans
without executing. Revision 9's shared membership predicate and post-edit OFF
statement must be exercised by the focused isolated suites; the historical
validation below is not a claim that those new statements were tested then:

| Shape | Result |
|---|---|
| Multi-table `DELETE s, p FROM … JOIN … LEFT JOIN …` with `EXISTS` guards | **accepted** — §7.2 atomic withdrawal is source-compatible |
| Multi-table `DELETE p FROM … JOIN … JOIN …` with template `EXISTS` and a binary name proof | **accepted** — §7.3 step 2 is expressible |
| `UPDATE t … JOIN other … WHERE EXISTS (SELECT 1 FROM third …)` | **accepted** — §6.1, §7.3, §7.4, §7.5 fences are expressible |
| `UPDATE schedules s … WHERE EXISTS (SELECT 1 FROM templates t WHERE t.portal_session_id = s.portal_session_id …)` | **accepted** — §3.4, §3.5, §3.7 template guards are expressible |
| `CAST(t.name AS BINARY) = CAST(%s AS BINARY)` inside a guard | **accepted**, and measured **case- and accent-sensitive** (§7.8) |
| `EXISTS` proving a non-empty definition Package set | **accepted** — §7.3 step 4 and §10 are expressible |
| Double `NOT EXISTS` set-equality proof | **accepted** — §7.3 step 4 exact-set proof is expressible |
| `INSERT … SELECT … FROM DUAL WHERE EXISTS (…) AND EXISTS (…)` | **accepted** |
| `INSERT … SELECT … WHERE NOT EXISTS (SELECT 1 FROM <insert target>)` | **accepted** — the 1093 restriction does **not** extend to an INSERT's own target |
| `UPDATE` / `DELETE` whose subquery `FROM` names its **own target table** | **REJECTED — MySQL error 1093** |

**Error 1093 is a hard constraint on `UPDATE` and `DELETE`.** No such subquery may
name the statement's own target table in its `FROM` clause. Every guard below
therefore reaches other tables by `JOIN` or by `EXISTS` over a *different* table,
and correlates to the target only through column references.

**What `EXPLAIN` does NOT prove.** It validates syntax, name resolution and the
execution plan. **It proves nothing about locking, isolation, statement ordering,
or correctness under concurrent interleaving.** No concurrency claim in this ADR
rests on it. Every such claim is assigned a deterministic worker test in §16, and
the following must pass before the implementation may be called complete:

- dependency invalidation between validation and definition commit (test 19);
- template rename immediately before a name-writing statement (tests 25–27);
- same-token repair convergence (tests 53–54);
- cross-token repair (test 55);
- stale writer after completion (test 57);
- superseded-definition cleanup versus an old-token worker (tests 21–23);
- ON compensation ownership, including the three-request case (tests 38–40);
- unsettled ON surviving process termination (test 42);
- ON versus withdrawal (test 34) and ON versus retime (test 35);
- deadlock (1213) and lock-wait timeout (1205) injection (tests 70–71).

The implementation must also re-run the `EXPLAIN` validation against its own
final statements (test 7) rather than trusting this table.

---

### 1. Model

```
bingo_portal_session_templates        Portal replica — UNCHANGED by this ADR
  portal_session_id  (Portal bingo_sessions.id)
        │  1 : N
        ▼
bingo_session_schedules               Commerce authority (NEW)
  timezone_name, start_time_local, mode, weekday_mask, bounds,
  active_definition_token
        │  1 : N
        ▼
bingo_sessions                        Dated occurrence — ADDITIVE columns only
  schedule_id, start_time_local, timezone_name, starts_at_utc,
  sale_locked_at, generation_completed_at, availability_operation_token
        │  1 : N
        ▼
bingo_session_packages                Occurrence availability — UNCHANGED
```

Seven commitments:

1. **`bingo_sessions` continues as the dated-occurrence table.** ADR-035 and
   BR-073 forbid deleting or replacing it.
2. **Commerce selection is never stored on the replica.** A template is
   "selected for Ecommerce" exactly when a committed active schedule references
   it. This is required, not stylistic: the replica verifier demands exact
   column-set equality
   (`wp-content/plugins/bingocommerce-sessions/src/Migrations.php:356`) and
   `tests/portal-session-template-replica-persistence.php:342-350` fails on
   `selected_for_commerce`, `available_for_commerce`, `enabled_for_commerce`,
   `sell_online`, `session_date`, `weekday`, `recurrence`, `start_time`,
   `package_id`, `days` and `portal_updated_at`.
3. **The schedule links by `portal_session_id`**, the ADR-035 trusted identity.
   **Immutable** after creation.
4. **No manager-controlled display name exists.** Names are derived
   deterministically in a fixed storage format (§4.2), and every statement that
   writes one proves the source template name has not changed (§7.8).
5. **The past is immutable.** No path creates, restores, moves, renames, retimes
   or deletes an occurrence whose date is not strictly future (§7).
6. **The schedule owns its generated occurrences.** Name, date, time and
   lifecycle of a row with `schedule_id IS NOT NULL` change only through
   reconciliation and the guarded availability path (§9).
7. **Every reconciliation write is fenced on one definition.** A run captures
   `$run_definition_token` at preflight and proves it in the `WHERE` clause of
   every mutation (§7.1). A run never mixes two definitions.

---

### 2. Final Schema

`Sessions\Migrations::DB_VERSION` 2 → **3**. All tables per-site, isolated by
`$wpdb->prefix`, with no `blog_id` column (AGENTS.md rule 6), InnoDB, utf8mb4,
and no physical foreign keys — matching every existing BingoCommerce table.

#### 2.1 NEW — `$wpdb->prefix . 'bingo_session_schedules'`

```sql
CREATE TABLE {prefix}bingo_session_schedules (
  schedule_id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_session_id       bigint(20) unsigned NOT NULL,
  timezone_name           varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  start_time_local        time                NOT NULL,
  mode                    varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  weekday_mask            tinyint(3) unsigned NOT NULL DEFAULT 0,
  recurrence_start        date                         DEFAULT NULL,
  recurrence_end          date                         DEFAULT NULL,
  active_definition_token char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  status                  varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  created_at              datetime            NOT NULL,
  updated_at              datetime            NOT NULL,
  archived_at             datetime                     DEFAULT NULL,
  PRIMARY KEY  (schedule_id),
  KEY portal_session_status (portal_session_id,status,schedule_id)
) ENGINE=InnoDB;
```

| Column | Rule |
|---|---|
| `portal_session_id` | Portal `bingo_sessions.id`. **Immutable** — never in an UPDATE assignment list. |
| `timezone_name` | IANA identifier. Written **once**, by the provisional INSERT. **Immutable for the schedule's whole life** (§5.1). |
| `start_time_local` | `TIME`, **NOT NULL**, always normalized to `HH:MM:00` (§5.4). |
| `mode` | `dates` or `weekly`. |
| `weekday_mask` | Bit 0 = Monday … bit 6 = Sunday (ISO-8601). Validated scalar, **never** a SQL predicate. `dates` requires `0`; `weekly` requires `> 0`. |
| `recurrence_start` / `recurrence_end` | Both SQL `NULL` for `dates`. Both NOT NULL for `weekly`, `start <= end`, span ≤ 366 calendar days. Creation requires `start >= tomorrow`; editing does not (§8.2). |
| `active_definition_token` | Lowercase UUID v4 naming the committed definition. **`NULL` means no readable definition** — inert, generates nothing, excluded from every eligibility predicate. |
| `status` | `active` or `archived`. |

`ascii_bin` on `timezone_name`, `mode`, `status` and `active_definition_token`
follows the precedent and reasoning at
`wp-content/plugins/bingocommerce-portal-integration/src/ProvisioningMigrations.php:428-441`:
under a case-insensitive collation `A3F` and `a3f` would be the same UUID.
`wp_generate_uuid4()` emits lowercase and the writer applies `strtolower()`
defensively as `ProvisioningOperationRepository.php:940` does.

#### 2.2 NEW — `$wpdb->prefix . 'bingo_session_schedule_dates'`

```sql
CREATE TABLE {prefix}bingo_session_schedule_dates (
  schedule_date_id  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  schedule_id       bigint(20) unsigned NOT NULL,
  definition_token  char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_date     date                NOT NULL,
  created_at        datetime            NOT NULL,
  PRIMARY KEY  (schedule_date_id),
  UNIQUE KEY schedule_token_date_unique (schedule_id,definition_token,schedule_date)
) ENGINE=InnoDB;
```

Holds **only explicitly selected dates for `mode = dates`**. A `weekly` schedule
stores zero rows here. Maximum **400 unique dates per definition token** — a
limit on the *active definition*, not on the schedule's lifetime (§8.3).

#### 2.3 NEW — `$wpdb->prefix . 'bingo_session_schedule_packages'`

```sql
CREATE TABLE {prefix}bingo_session_schedule_packages (
  schedule_package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  schedule_id         bigint(20) unsigned NOT NULL,
  definition_token    char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  package_id          bigint(20) unsigned NOT NULL,
  created_at          datetime            NOT NULL,
  PRIMARY KEY  (schedule_package_id),
  UNIQUE KEY schedule_token_package_unique (schedule_id,definition_token,package_id),
  KEY package_schedule (package_id,schedule_id)
) ENGINE=InnoDB;
```

**At least one row per definition token is a hard requirement**, and it is proved
in SQL at three separate points rather than trusted: the phase-2 gate (§10), the
generation-completion statement (§7.3 step 4), and final verification (§7.12). A
definition with zero Package rows can neither complete an occurrence nor produce
`status = ok`.

These Packages are written onto an occurrence **only** by the internal generation
writer (§7.10), only while that occurrence is incomplete.

#### 2.4 ADDITIVE on `$wpdb->prefix . 'bingo_sessions'`

```sql
ALTER TABLE {prefix}bingo_sessions
  ADD COLUMN schedule_id                  bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN start_time_local             time                DEFAULT NULL,
  ADD COLUMN timezone_name                varchar(64) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD COLUMN starts_at_utc                datetime            DEFAULT NULL,
  ADD COLUMN sale_locked_at               datetime            DEFAULT NULL,
  ADD COLUMN generation_completed_at      datetime            DEFAULT NULL,
  ADD COLUMN availability_operation_token char(36) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD UNIQUE KEY schedule_occurrence_unique (schedule_id,session_date),
  ADD KEY starts_at_utc_idx (starts_at_utc);
```

**Seven nullable columns.** Every existing column, index and row is untouched.
`UNIQUE (session_date, display_name)` **stays**: `dbDelta()` cannot drop it,
`verify_sessions_schema()` asserts it (`…/Migrations.php:275`), and four tests
depend on it.

| Column | Meaning |
|---|---|
| `schedule_id` | NULL = legacy/manual, behaviour unchanged. NOT NULL = generated, schedule-owned (§9). |
| `starts_at_utc` | The stable instant. Immune to any later change of the hall's WordPress timezone setting. |
| `timezone_name` | The zone that produced that instant, and the zone in which this occurrence's "today" and "strictly future" are evaluated (§5.3). |
| `start_time_local` | The wall time the occurrence was generated and sold as. Normalized `HH:MM:00`. Never re-derived from `starts_at_utc`. |
| `sale_locked_at` | Durable proof the occurrence has **ever** been Available for Sale. Set once, server-side, inside the guarded ON statement (§6.1). **Never cleared by anything, and an occurrence carrying it is never hard-deleted by any path** (§7.2, §19). |
| `generation_completed_at` | NULL while Package propagation is unfinished. Until set, the occurrence is **unsellable** and closed to manual Package edits. |
| `availability_operation_token` | The UUID of the availability request that most recently drove this occurrence ON. It is the **ownership fence** that lets exactly that request — and no older one — compensate its own failure (§6.2). **A non-NULL value means an ON transition that has not yet settled**, which may include one whose process died (§6.5). |
| `session_date` | Unchanged, still the sole date input to the BR-051/BR-063 thirty-day predicate (Revision 8; five days in Revisions 1-7). |

BR-077 ratifies the numeric thirty-day window. Historical five-day references
in BR-051, BR-063, ADR-035, BR-071 and BR-073 are superseded only in that number.

**Collation, and why these two columns differ from the new tables (Revision 7,
implementation correction — the DDL above carries it).** The two identity columns
ON `bingo_sessions` — `timezone_name` and `availability_operation_token` — use
**`COLLATE utf8mb4_bin`**, NOT `CHARACTER SET ascii COLLATE ascii_bin`. Revision 6
specified `ascii_bin` here; that was wrong, and the DDL above is the corrected
form. The three NEW tables keep `ascii_bin`, which is correct there because every
collated column in them is ascii.

This is not a preference. `wpdb::get_table_charset()` derives ONE charset for a
whole table from its columns and, on a mix, falls through to *"Two mixed
character sets. ascii."* (`wp-includes/class-wpdb.php:3284-3286`). Adding ascii
columns beside the utf8mb4 `display_name` therefore makes `wpdb` treat
`bingo_sessions` as an ascii table and **reject every non-ASCII display name** —
*"Could not perform query because it contains invalid data."* Existing halls
already store Session names containing en dashes, so the literal DDL of
Revision 6 would have broken them. Verified by reproduction against the installed
MySQL 9.5.0 before the correction and after.

`utf8mb4_bin` preserves the property the ADR actually requires — exact, case- and
accent-sensitive comparison, so `A3F` and `a3f` are never the same token —
measured live: `A3F` vs `a3f`, `Evening` vs `evening` and `Evening` vs `Évening`
all compare **unequal** under it.

`availability_operation_token` is byte-exact for the same reason as every other
UUID column here. **`updated_at` is deliberately not used as the ownership
fence**: nothing in source proves it unique or strictly monotonic across
concurrent requests within the same second.

`UNIQUE (schedule_id, session_date)` is the duplicate guard for generated
occurrences. MySQL treats NULLs as distinct, so unlimited legacy rows with
`schedule_id IS NULL` remain legal — this is what makes the constraint additive.

#### 2.5 Verification

`verify_current_site_schema()` gains `verify_session_schedules_schema()`,
`verify_schedule_dates_schema()` and `verify_schedule_packages_schema()`; the
**seven** new columns and two new indexes join the existing `bingo_sessions`
expectations. `assert_columns()` / `assert_indexes()` **subset** semantics are
retained — the exact-`array_keys` pattern used by the replica verifier must not be
copied onto them, or the next additive change becomes a destructive one. Column
expectations assert the collation each column actually carries, in the same shape
`ProvisioningMigrations` already uses (`:504-515`): `ascii_bin` on the three new
tables' identity columns, and **`utf8mb4_bin` on `bingo_sessions`'s two**
(`timezone_name`, `availability_operation_token`), per §2.4.

Two **data-integrity** probes accompany the structural checks, both indexed
counts that must return `0`:

```sql
-- schedules: structured time is minute-precise
SELECT COUNT(*) FROM {prefix}bingo_session_schedules
 WHERE SECOND(start_time_local) <> 0
```

```sql
-- generated occurrences: structured time is complete AND minute-precise
SELECT COUNT(*) FROM {prefix}bingo_sessions
 WHERE schedule_id IS NOT NULL
   AND (
         start_time_local IS NULL
      OR SECOND(start_time_local) <> 0
      OR timezone_name  IS NULL
      OR starts_at_utc  IS NULL
   )
```

A generated occurrence with **missing** structured time data fails verification
exactly as a non-zero-seconds one does. Legacy rows (`schedule_id IS NULL`) are
exempt by the `schedule_id IS NOT NULL` predicate and are never inspected.

---

### 3. Definition Commit

#### 3.1 Why a unique token, not an incrementing revision

An integer `revision + 1` scheme is **not concurrency-safe**: two editors compute
the same `next`, both insert child rows under it, and their rows interleave into
one merged set before either pointer update runs.

A **unique token per attempt** removes the shared namespace: each request
generates its own UUID and writes its complete child set under that token, and no
other request can write into it.

A genuine transaction is not available: AGENTS.md rule 4 enumerates four approved
mysqli transports and none covers this, and ADR-033 **prohibits** `$wpdb->query()`
for any statement issued inside an open transaction, because `wpdb::query()` fires
the `query` filter on every statement and is therefore not callback-free
(`docs/decisions/DECISIONS.md:468-482`). **No fifth transport is introduced.**

#### 3.2 What the commit statement can and cannot prove

The compare-and-swap in §3.4/§3.5 proves, **atomically at commit time**:

- the schedule exists and is `status = 'active'`;
- the previous `active_definition_token` is exactly what this request observed;
- **the Portal template is still commerce-eligible** — added in this revision.

It **cannot** prove Package assignability. `PackageGateway::assert_assignable()`
(`…/PackageGateway.php:118-211`) reads Catalog rows and the linked WooCommerce
product and applies BR-061/BR-067 rules; it is PHP, and no SQL statement can carry
it. **This ADR therefore does not claim Package validity is guaranteed at the
instant of commit.**

What is claimed instead, precisely:

| Check | When | Strength |
|---|---|---|
| Package assignability at create/edit | **request-time validation** (§3.7) | best available at that boundary; a Package can become unassignable between validation and the CAS |
| Non-empty definition Package set | **proved in SQL** at §10 phase 2, §7.3 step 4, §7.12 | atomic |
| Package assignability before any occurrence is created or repaired | §10 phase 2 gate | authoritative, fail-closed |
| Package assignability before an occurrence goes ON | §6.2 pre-proof **and** post-proof | authoritative, fail-closed, compensated |
| Package assignability for the storefront | §6.4 `isSellable()` | authoritative, fail-closed |
| Package assignability before `complete = true` | §7.12 | authoritative, fail-closed |

So a definition committed with a Package that became unassignable a millisecond
later is **not** a correctness hole: it commits, and then **Generate refuses to
create anything, ON refuses, and the storefront refuses**. The schedule is simply
unusable until the Package is fixed — which is the correct fail-closed outcome,
and is exactly what test 19 asserts.

#### 3.3 Null-safe SQL

`$wpdb->prepare()` with `%s` and a PHP `null` does not produce SQL `NULL`; it
produces a quoted empty string. Every statement uses **literal SQL `NULL`** where
NULL is intended and a **placeholder only where a validated non-null value
exists**. No statement in this ADR uses a placeholder for a column meant to
receive SQL NULL. Each operation therefore has **two exact statements**, selected
by `mode` — an internal validated literal, never request text — before any SQL is
built.

#### 3.4 Provisional create INSERT

**`mode = 'dates'`**

```sql
INSERT INTO {prefix}bingo_session_schedules
  (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
   recurrence_start, recurrence_end, active_definition_token,
   status, created_at, updated_at, archived_at)
VALUES
  (%d, %s, %s, 'dates', 0, NULL, NULL, NULL, 'active', %s, %s, NULL)
```

**`mode = 'weekly'`**

```sql
INSERT INTO {prefix}bingo_session_schedules
  (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
   recurrence_start, recurrence_end, active_definition_token,
   status, created_at, updated_at, archived_at)
VALUES
  (%d, %s, %s, 'weekly', %d, %s, %s, NULL, 'active', %s, %s, NULL)
```

Every non-null column is satisfied. `active_definition_token` is the literal
`NULL`, never a placeholder. The row is **inert and unreadable**.

#### 3.5 Create commit — compare-and-swap, template-guarded

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'dates',
       s.weekday_mask            = 0,
       s.recurrence_start        = NULL,
       s.recurrence_end          = NULL,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token IS NULL
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'weekly',
       s.weekday_mask            = %d,
       s.recurrence_start        = %s,
       s.recurrence_end          = %s,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token IS NULL
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

`timezone_name` and `portal_session_id` are absent from both assignment lists.
Zero affected rows ⇒ another request committed first, **or the template stopped
being commerce-eligible between validation and commit**. Authoritative re-read
distinguishes the two, reports `schedule_state_changed` or
`template_not_commerce_eligible`, leaves the row alone, and applies §3.7 to this
request's own token.

#### 3.6 Edit commit — compare-and-swap, template-guarded

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'dates',
       s.weekday_mask            = 0,
       s.recurrence_start        = NULL,
       s.recurrence_end          = NULL,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token = %s
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'weekly',
       s.weekday_mask            = %d,
       s.recurrence_start        = %s,
       s.recurrence_end          = %s,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token = %s
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

The trailing `%s` in the `WHERE` is `$seen`, the token **observed before this edit
began**.

**Which fence applies is decided by whether a definition exists, not by which
method was called.** There are exactly two cases, and both are legitimate:

| Case | Fence | Why |
|---|---|---|
| **Ordinary edit** — the schedule has a committed definition | `active_definition_token = $seen` | The edit supersedes a specific definition. Comparing against the exact token observed beforehand is what makes two concurrent editors resolve to one winner; `IS NULL` would match nothing, because the column is not NULL. |
| **Recovery of an inert provisional create** — the schedule has none | `active_definition_token IS NULL` | There is no predecessor token to compare against. §3.8 step 5 requires the inert header row to be re-savable, **keeping the same `schedule_id`**, so the Manager can fix whatever blocked the original commit and save again. This is a create-shaped commit against an existing header, not a second create: no new header row is inserted, and `portal_session_id` and `timezone_name` remain immutable because the same §3.5 assignment list is reused. |

An earlier revision of this section said `IS NULL` "is never the correct fence
here". That was wrong, and it made the inert row unrecoverable — the Manager
could only archive it. The accurate statement is that `IS NULL` is never the
fence for an edit **that has a definition to supersede**.

#### 3.7 Definition-token lifecycle and cleanup

An earlier revision claimed inactive token rows accumulate only from failed
edits. **That was wrong.** Every *successful* edit also leaves its predecessor's
date and Package rows behind, so a frequently edited schedule accumulates far
more superseded rows than failed ones. Superseded rows are now cleaned
deliberately and provably.

**After a successful edit CAS**, the request may delete the rows of the token it
just superseded — `$seen` — but only after SQL proves that token is no longer
active:

```sql
DELETE d
  FROM {prefix}bingo_session_schedule_dates d
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id = d.schedule_id
   AND  sch.schedule_id = %d
   AND  ( sch.active_definition_token IS NULL
          OR sch.active_definition_token <> %s )   -- provably NOT the active token
 WHERE  d.schedule_id      = %d
   AND  d.definition_token = %s                -- $seen
```

```sql
DELETE sp
  FROM {prefix}bingo_session_schedule_packages sp
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id = sp.schedule_id
   AND  sch.schedule_id = %d
   AND  ( sch.active_definition_token IS NULL
          OR sch.active_definition_token <> %s )   -- provably NOT the active token
 WHERE  sp.schedule_id      = %d
   AND  sp.definition_token = %s               -- $seen
```

- The `JOIN … AND ( … IS NULL OR … <> %s )` is the proof. If anything restored
  `$seen` as the active token, the join matches nothing and **zero rows are
  deleted**.
- `<>` against an `ascii_bin` column is exact.
- **The guard must be NULL-safe** (corrected in implementation). After a
  *successful edit* the active token is never NULL, because this request's own
  CAS just set it — but these same two statements also serve the **failed-commit
  private-token rule** below, and after a *failed create* the active token IS
  SQL NULL. In SQL `NULL <> 'token'` evaluates to NULL rather than TRUE, so a
  bare `<>` matches nothing on exactly the schedules that most need cleaning: a
  failed create would leave its staged rows behind for ever, and a later recovery
  of that inert row would find two token sets where there should be one. The
  `IS NULL OR` branch restores the intended meaning — *this token is not the
  active one* — without weakening the proof: when a token **is** active, `IS
  NULL` is false and `<>` is false, so zero rows are still deleted.
- **The current token is never deleted**, because it is not `$seen`.
- **Another editor's staged token is never deleted**, because only `$seen` — a
  token this request observed as active and then superseded — is named.

**On a FAILED commit** (create or edit) the request applies the private-token rule
unchanged: it deletes **only the token it generated itself**, after an
authoritative re-read proves that token is not active, and deletes nothing if the
re-read fails or returns its own token.

**What remains for the deferred GC design** (§0) is now precisely one case:
**private tokens abandoned by a crashed request** — staged rows whose creating
request died between staging and its CAS, so nothing ever ran either cleanup.
Volume is bounded by crash frequency and each row is a handful of bytes.

**Interaction with a reconciliation worker on the old token.** A run that captured
`$seen` as `$run_definition_token` and is still executing when the edit commits
will find its fence broken: every guarded mutation carries
`sch.active_definition_token = $run_definition_token`, which no longer matches, so
each affects **zero rows**, and the between-phase re-proof (§7.1) stops the run
with `schedule_state_changed`. **Deleting the old token's rows underneath that
worker is therefore safe** — the worker could not have written anything with them
after the CAS regardless. Tests 21–23 prove this, including the case where the
cleanup runs while the old-token worker is mid-phase.

#### 3.8 Transitions

**Create**

1. Validate the **complete** request: hall context; `bc_manage_sessions` or super
   admin; Sessions v3 current; named IANA `timezone_string` (§5.1); exact `H:i`
   time (§5.4); template commerce-eligible; mode, mask, bounds, limits; **every
   date tomorrow-or-later** (§8.1); **at least one Package, each passing
   request-time `assert_assignable()`** (§3.2, §10). Nothing is written until all
   of this passes.
2. `INSERT` the provisional header (§3.4).
3. `token = strtolower( wp_generate_uuid4() )`; `INSERT` the date rows
   (`mode = dates` only) and Package-default rows under `token`.
4. Run §3.5. ← **commit point.**
5. On zero affected rows the schedule **remains inert and unreadable**; apply
   §3.7's failed-commit rule to this request's own token only. The inert row is
   left in place, generates nothing, and is displayed as *incomplete* so the
   Manager can re-save or archive it. **Safe to retry:** yes.

**Edit**

1. Observe `active_definition_token` as `$seen`. **Two cases, and the observed
   value selects between them**: a non-NULL `$seen` is an ordinary edit and
   proceeds as below; a NULL `$seen` means the schedule is an **inert provisional
   create**, and step 4 runs §3.5's create-shaped CAS fenced on
   `active_definition_token IS NULL` against the **same `schedule_id`** instead
   (§3.8 Create step 5). Step 5's superseded cleanup is skipped in that case,
   because an inert schedule superseded nothing.
2. Re-validate everything from Create **now** — template eligibility and Package
   assignability are re-proved at edit time, never inherited. Date rules follow
   §8.2 (weekly) and §8.3 (explicit dates).
3. `token = strtolower( wp_generate_uuid4() )`; `INSERT` the complete new child
   set under `token`.
4. Run §3.6 with `$seen`. ← **commit point and optimistic concurrency fence.**
5. **On success**, run §3.7's superseded-definition cleanup for `$seen`.
   **On zero affected rows**, apply §3.7's failed-commit rule to this request's
   own token only. **Safe to retry:** yes — the previous definition is intact.

**Revision 9 / BR-078:** removal does not require disabling sales first and does
not change the definition CAS or its optimistic concurrency rule. After a
successful edit CAS, an ordinary prepared, guarded UPDATE turns active ON
occurrences strictly after schedule-local today OFF when §6.4's shared current-
definition predicate is false. It clears `availability_operation_token`, but
preserves `sale_locked_at`, identity, Packages, lifecycle and history. This is
sales invalidation, not a new reconciliation phase. A failure is reported; the
definition has already committed, and membership still prevents purchase. A
retry uses the current definition. No claim of an atomic combined edit/OFF
transaction or of serialized ON-versus-removal ordering is made.

`portal_session_id` and `timezone_name` are never in any assignment list.

**Archive** — `UPDATE … SET status='archived', archived_at=%s, updated_at=%s
WHERE schedule_id=%d AND status='active'`. No template guard: stopping commerce
must never be blocked. Generation stops. **No occurrence is touched.** An
Available-for-Sale occurrence under an archived schedule stops being
customer-eligible by §6.4, with no write. Zero affected rows ⇒ re-read and
classify.

**Restore** — template-guarded, because it resumes commerce:

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.status      = 'active',
       s.archived_at = NULL,
       s.updated_at  = %s
 WHERE s.schedule_id = %d
   AND s.status      = 'archived'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

Zero affected rows ⇒ authoritative re-read and classify: already active, template
not commerce-eligible, or not found.

#### 3.9 Expansion

| Mode | Source | Limit |
|---|---|---|
| `dates` | `bingo_session_schedule_dates` rows at the active token | **400 unique dates in the active definition** |
| `weekly` | derived from `recurrence_start`, `recurrence_end`, `weekday_mask` | **366 calendar days** of range |

Weekly expansion walks each calendar day from start to end inclusive and emits
every date whose ISO weekday bit is set. Limits are validated at commit time and
apply to the definition being committed, never to the schedule's history. The
emitted set is then filtered to strictly-future dates by §7.1.

---

### 4. Template Eligibility and Occurrence Naming

#### 4.1 Template eligibility

**Commerce-eligible** means the replica row satisfies
`is_current = 1 AND portal_status = 'active'` for the schedule's
`portal_session_id`.

| Operation | Template eligibility proved in SQL |
|---|---|
| Create commit (§3.5) | **Yes** |
| Edit commit (§3.6) | **Yes** |
| Schedule restore (§3.8) | **Yes** |
| Schedule archive (§3.8) | No — stopping commerce is never blocked |
| **Withdraw an occurrence (phase 1)** | **No — the only exempt reconciliation mutation** |
| Repair an incomplete occurrence (phase 3) | **Yes** (§7.3) |
| Create a new occurrence (phase 4) | **Yes** (§7.7) |
| Retime (phase 5) | **Yes** (§7.4) |
| Restore an occurrence (phase 6) | **Yes** (§7.5) |
| Turn an occurrence ON | **Yes** (§6.1) |
| Turn an occurrence OFF | No |
| Read / list anything | No |

**Withdrawal is the single eligibility-exempt reconciliation mutation.** Removing
commerce must never be blocked by a template going draft or non-current, or by a
Package becoming unassignable (§10). Every other mutation proves eligibility
inside its own statement — never by a pre-read.

Draft and non-current templates remain **visible** with their status shown
verbatim. `is_current` and `portal_status` are independent facts displayed as two
columns, never merged — the transport keeps them independent by design
(`…/PortalSnapshotTransactionTransport.php:656-663`).

Existing schedules and occurrences for a template that becomes draft or
non-current are **retained in full**. A template that reappears is restored to
`is_current = 1` by the existing transport (`…:712-724`) with **no manual step**.
A schedule whose `portal_session_id` has no replica row is treated identically to
non-current; the UI says *template unavailable*, never *template deleted*.

#### 4.2 Stored occurrence display name

```
display_name = mb_substr( canonical( template.name ), 0, 183 ) . ' — ' . H:i
```

Example: `Evening Bingo — 18:00`

- The suffix is **fixed**: space, EM DASH (U+2014), space, `H:i` from
  `start_time_local`. Eight characters. **Never** produced from the WordPress
  `time_format` option, which is mutable.
- Truncation is **by characters** with `mb_substr()` to `191 - 8 = 183`, so the
  suffix always survives and the result fits `varchar(191)` and
  `SessionService::DISPLAY_NAME_MAX` (`…/SessionService.php:39`).
- `canonical()` collapses whitespace and trims exactly as
  `SessionService::canonical_display_name()` does (`…:724-736`), **before**
  truncation.
- Nothing ever reads a time, date or behaviour back out of a name. BR-050's
  prohibition is untouched.
- Because `start_time_local` is minute-precise (§5.4), the suffix is always
  exactly `HH:MM` and never silently drops a stored second.
- **Every statement that writes a `display_name` proves the source template name
  is unchanged, byte for byte** (§7.8).

**Collation — measured, not inferred.** The implementation must verify: what
`$wpdb->get_charset_collate()` returns; the created column definition via
`SHOW FULL COLUMNS`; and the effective comparison behaviour, by executing a
comparison against the live database.

The local environment was probed read-only on 2026-09-16 and contradicted a
plausible assumption, which is why this is mandatory:

| Fact | Measured |
|---|---|
| Server | MySQL 9.5.0 Community Server |
| `@@collation_server` / `@@collation_database` | `utf8mb4_0900_ai_ci` |
| **`bc_2_bingo_sessions.display_name`** | **`utf8mb4_unicode_520_ci`** |

The column does **not** use the server default. `wpdb::init_charset()`
(`wp-includes/class-wpdb.php:844-850`) sets `utf8_general_ci` on Multisite when
`DB_COLLATE` is empty, and `determine_charset()` (`:886-902`) upgrades it to
`utf8mb4_unicode_ci` then `utf8mb4_unicode_520_ci`. `get_charset_collate()` emits
a **non-empty** COLLATE clause, so `DB_COLLATE = ''` proves nothing.

Comparison behaviour under that measured collation, executed live:

| Comparison | Column collation | `CAST(… AS BINARY)` |
|---|---|---|
| `Evening Bingo` vs `evening bingo` | **equal** | **not equal** |
| `Evening Bingo` vs `Évening Bingo` | **equal** | **not equal** |
| `Evening Bingo` vs `Evening Bingo` | equal | equal |

Both behaviours are used deliberately and for different jobs:

- **Collision detection** uses the **column collation**, through the database, so
  two names a customer could not tell apart are caught (§8.4).
- **Rename detection** uses **`CAST(… AS BINARY)`**, so a rename that changes only
  case or an accent is still detected as a change (§7.8). The binary-comparison
  idiom follows the existing precedent at
  `wp-content/plugins/bingocommerce-catalog/src/Migrations.php:19-20`.

**Resolution of a collision is the Manager's.** It is reported with both template
names and the owning `session_id`. **No hidden character, zero-width space,
counter or Portal ID is ever appended to a customer-facing name.**

Two schedules on the same template at different times produce different suffixes,
so the common multi-sitting case never collides.

---

### 5. Timezone, DST and Time Precision

#### 5.1 Named IANA requirement, and immutability

`wp_timezone_string()` (`wp-includes/functions.php:124-141`) returns
`timezone_string` when set and otherwise synthesises a fixed `±HH:MM` from
`gmt_offset` — a hall configured that way has **no DST rules**. Nothing in
`HallSiteProvisioner` sets a timezone on a new hall.

Creation is **refused** with `hall_timezone_not_named` when `timezone_string` is
empty, rejected by `new DateTimeZone()`, or a fixed-offset string.

**`timezone_name` is captured at creation and is immutable for the schedule's
entire life — including before any occurrence exists.** It appears in the
provisional INSERT and in **no** UPDATE assignment list anywhere. Runtime never
re-reads the hall's current WordPress timezone for a schedule or its occurrences.
A wrong zone is corrected by **archiving the schedule and creating a
replacement**.

#### 5.2 Exact-instant algorithm

```
1. S     = "D T"                                   (Y-m-d H:i:s literal)
2. tz    = new DateTimeZone(Z)                     (throw ⇒ reject)
3. naive = (new DateTimeImmutable(S, UTC))->getTimestamp()
4. offsets = distinct 'offset' in tz->getTransitions(naive-172800, naive+172800)
5. matches = []
   for each o in offsets:
       ts = naive - o
       if (new DateTimeImmutable("@ts"))->setTimezone(tz)->format('Y-m-d H:i:s') === S:
           matches[] = ts
6. |matches| == 0 ⇒ REJECT  nonexistent_local_time   (spring-forward gap)
   |matches| == 2 ⇒ REJECT  ambiguous_local_time     (fall-back overlap)
   |matches| == 1 ⇒ ACCEPT  starts_at_utc = gmdate('Y-m-d H:i:s', matches[0])
```

PHP is never allowed to normalise. `new DateTimeImmutable('2026-03-08 02:30:00',
$tz)` silently yields 03:30; this algorithm never constructs that object.

**Verified empirically against PHP 8.4.24, `America/New_York`:**

| Input | Matches | Result |
|---|---|---|
| `2026-03-08 02:30:00` | 0 | rejected — nonexistent |
| `2026-11-01 01:30:00` | 2 | rejected — ambiguous |
| `2026-03-08 18:00:00` | 1 | `2026-03-08 22:00:00` UTC (EDT, −04:00) |
| `2026-03-07 18:00:00` | 1 | `2026-03-07 23:00:00` UTC (EST, −05:00) |

A rejected date is a **preflight blocker** and the whole run is refused (§8.4).

#### 5.3 Which zone decides "today" and "strictly future"

`SessionService::evaluate_available_today( array $row, string $hall_local_today )`
(`…/SessionService.php:644`) already **takes** local-today as a parameter. No
signature change; only the caller changes.

| Occurrence | "Local today" from |
|---|---|
| Generated (`schedule_id IS NOT NULL`) | the occurrence's own `timezone_name` |
| Legacy (`schedule_id IS NULL`) | `wp_timezone()` — unchanged (`…:635`) |

The same rule decides **"strictly future"** for withdrawal, retime, repair and
the date boundary. A later change to the hall's WordPress timezone setting does
not move, reinterpret or re-window any existing generated occurrence.

Date expansion walks whole calendar days on a date-only `DateTimeImmutable` in
UTC — the pattern `parse_exact_date()` already uses (`…:583-604`). No timezone
participates, so DST cannot shift, drop or duplicate a *date*. The inclusive
BR-051/BR-063 window is **unchanged** in mechanism — same calendar-day walk, same
DST immunity — though its width changed from five to thirty calendar days in
Revision 8 (§15 amendment note); see `SessionService::SALES_WINDOW_DAYS`, the
single authoritative constant both `salesWindowStartDate()` and
`evaluate_available_today()` consume.

#### 5.4 Minute precision

MySQL `TIME` stores seconds — verified live: `CAST('18:00:30' AS TIME)` yields
`18:00:30`, while `CAST('18:00' AS TIME)` yields `18:00:00`. A stored
`18:00:30` would render as `— 18:00` in the name (§4.2), silently hiding 30
seconds and making two different times produce one name.

Therefore:

- **Input format is exactly `H:i`**, validated by
  `/^([01][0-9]|2[0-3]):[0-5][0-9]$/D`. Anything else — including `18:00:30`,
  `8:00`, `18:0` — is **refused** with `invalid_start_time`, never coerced.
- **Stored `start_time_local` is normalized to `HH:MM:00`** on both the schedule
  and every occurrence.
- **Seconds must always equal `00`**, and a generated occurrence must additionally
  carry non-NULL `start_time_local`, `timezone_name` and `starts_at_utc` — both
  enforced by the §2.5 integrity probes.
- **UI controls use minute precision**: `<input type="time" step="60">`, with the
  server-side regex as the authority — the attribute is a convenience, never the
  check.

---

### 6. Available for Sale, and Storefront Eligibility

#### 6.1 The guarded OFF → ON statement

A check performed before the write is worthless on its own: schedule status,
definition currentness and template eligibility can all change between the check
and the statement. **Everything expressible in SQL is expressed in SQL**, in the
one statement that flips the switch — and that statement also **claims ownership**
of the transition so that only this request can later compensate it.

The request first mints `$operation_token = strtolower( wp_generate_uuid4() )`.

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d          -- expected schedule
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s          -- exact expected definition
   SET  s.available_for_sale            = 1,
        s.sale_locked_at                = COALESCE(s.sale_locked_at, UTC_TIMESTAMP()),
        s.availability_operation_token  = %s,     -- $operation_token
        s.updated_at                    = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'active'
   AND  s.available_for_sale      = 0
   AND  s.generation_completed_at IS NOT NULL
   AND  /* SessionEligibility::current_definition_sql('s'), §6.4 */
        EXISTS (
          SELECT 1 FROM {prefix}bingo_session_schedules membership_schedule
           WHERE membership_schedule.schedule_id = s.schedule_id
             AND membership_schedule.active_definition_token IS NOT NULL
             AND membership_schedule.active_definition_token <> ''
             AND (
               (membership_schedule.mode = 'dates' AND EXISTS (
                 SELECT 1 FROM {prefix}bingo_session_schedule_dates membership_date
                  WHERE membership_date.schedule_id = membership_schedule.schedule_id
                    AND membership_date.definition_token = membership_schedule.active_definition_token
                    AND membership_date.schedule_date = s.session_date
               )) OR (
                 membership_schedule.mode = 'weekly'
                 AND s.session_date BETWEEN membership_schedule.recurrence_start AND membership_schedule.recurrence_end
                 AND membership_schedule.weekday_mask BETWEEN 1 AND 127
                 AND (membership_schedule.weekday_mask & (1 << WEEKDAY(s.session_date))) <> 0
               )
             )
        )
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
```

- The `JOIN` proves schedule existence, ownership, active status and the **exact
  active definition token** at statement time — not at read time.
- The `EXISTS` proves template commerce-eligibility at statement time.
- `UTC_TIMESTAMP()` is evaluated **server-side inside the guarded statement**, so
  the lock cannot be skewed by a PHP timestamp computed before the race resolved.
- `COALESCE` makes the lock **write-once**; there is no window in which an
  occurrence is ON but unlocked.
- `generation_completed_at IS NOT NULL` makes an incomplete occurrence unsellable
  at the SQL level, not merely in the UI.
- `availability_operation_token` records **who** drove this transition. Because
  `available_for_sale = 0` is in the `WHERE`, exactly one request can win.

#### 6.2 Package assignability, and owned compensation

`assert_assignable()` cannot be expressed in SQL (§3.2). It is proved immediately
before the statement and re-proved immediately after, and **every compensating
write is fenced on this request's own operation token.**

```
1. mint $operation_token
2. pre-prove ≥1 occurrence-level Package passes assert_assignable()
3. execute the §6.1 guarded UPDATE
4. if 0 rows → authoritative re-read; classify; DONE. Nothing was written,
              no token is ours, and no compensation is attempted.
5. if 1 row  → re-prove ≥1 assignable Package
6a. re-proof SUCCEEDS → settle:
        UPDATE {prefix}bingo_sessions
           SET availability_operation_token = NULL, updated_at = %s
         WHERE session_id = %d
           AND availability_operation_token = %s    -- $operation_token
      Zero rows here is NOT an error: another request has already taken
      ownership. Re-read and report the current state; write nothing further.
6b. re-proof FAILS or errors → compensate:
        UPDATE {prefix}bingo_sessions
           SET available_for_sale            = 0,
               availability_operation_token  = NULL,
               updated_at                    = %s
         WHERE session_id                   = %d
           AND schedule_id                  = %d
           AND available_for_sale           = 1
           AND availability_operation_token = %s    -- $operation_token
        report `package_became_unassignable`; result is a FAILURE
```

**The compensation can only ever undo this request's own transition.** If another
request turned the occurrence OFF and a third turned it ON again, the token no
longer matches and the compensating UPDATE affects **zero rows**. The request then
performs an **authoritative re-read, writes nothing, and reports the observed
state** — it never overwrites a newer request's outcome.

`sale_locked_at` is **never cleared** by compensation, by OFF, or by anything
else.

**Zero affected rows on §6.1 is never treated as success.** It triggers an
authoritative re-read classifying the outcome exactly.

#### 6.3 Turning OFF

```sql
UPDATE {prefix}bingo_sessions
   SET available_for_sale           = 0,
       availability_operation_token = NULL,
       updated_at                   = %s
 WHERE session_id         = %d
   AND status             = 'active'
   AND available_for_sale = 1
```

OFF requires **no** template, schedule, definition or Package check, and must
always succeed for an active occurrence. It clears the operation token — which is
also how an **abandoned** unsettled ON is resolved (§6.5) — and **never clears
`sale_locked_at`**. Zero affected rows ⇒ re-read and classify.

For a **legacy** occurrence (`schedule_id IS NULL`) the existing v2 ON/OFF writer
runs unchanged; `sale_locked_at` and `availability_operation_token` stay NULL.

#### 6.4 Storefront eligibility predicate

`evaluate_available_today()` stays **byte-identical**. A new composed predicate:

```php
SessionEligibility::is_sellable( int $session_id ): bool|\WP_Error
```

```
evaluate_available_today( row, local_today_for_this_row )    // §5.3, BR-051/BR-063
AND ( schedule_id IS NULL                                    // legacy: no further test
      OR ( generation_completed_at IS NOT NULL
           AND schedule.status                  = 'active'
           AND schedule.active_definition_token IS NOT NULL
           AND occurrence date belongs to the current committed definition
           AND template.is_current              = 1
           AND template.portal_status           = 'active'
           AND at least one assignable occurrence-level Package ) )
```

Any linked fact that cannot be read returns `WP_Error` — **fail closed**, in
every state including during a failed ON re-proof, during an incomplete
generation, while an ON transition is unsettled (§6.5), and after a run that
ended `status = failed` because a Package became unassignable (§7.12). Callers
must treat `WP_Error` as not sellable and must never coerce it to a boolean.

**Centralized membership (Revision 9 / BR-078).** The existing
`SessionEligibility` class owns `current_definition_sql()` and its read wrapper
`belongs_to_current_definition()`. Explicit membership requires the exact date
row at the active token. Weekly membership requires inclusive committed bounds
and `1 << WEEKDAY(session_date)` (Monday bit 0 through Sunday bit 6), exactly
matching `ScheduleExpansion`. A missing/inert definition or invalid mode is
unavailable; an unreadable dependency fails closed. Legacy Sessions bypass
membership without an additional database dependency.

The same predicate is used in the final ON UPDATE, in service refusal before an
already-ON no-op, in Upcoming before pagination, and in the admin action data.
`SessionEligibility::is_sellable()` checks it independently for purchase
evaluation, including stale rows whose stored flag remains ON. No SQL advisory
lock or new transaction transport is used. Definition removal may commit before
or after an ON attempt; continued purchase eligibility depends on the current
committed definition, not on a promise that removal will be refused.

#### 6.5 An unsettled ON transition, including process death

`availability_operation_token` is **not** a guarantee that a request is currently
running. It means exactly one thing:

> **An ON transition was committed and has not yet been settled by its owner.**

The owner may still be working, or its process may have been terminated between
the §6.1 UPDATE and the §6.2 settlement — by a fatal error, a PHP timeout, a
worker kill, or a deployment. **Such a row survives, ON and locked, with a
non-NULL token that will never be settled by anyone.** This design accepts that
state rather than pretending it cannot happen, and makes it harmless:

1. **The occurrence is genuinely ON**, and `sale_locked_at` is genuinely set. The
   §6.1 UPDATE committed. Neither fact is provisional and neither is reverted.
2. **Storefront eligibility is unaffected by the token.** §6.4 does not read
   `availability_operation_token` at all; it fails closed on its own facts. A
   dangling token never makes an occurrence sellable that otherwise would not be,
   and never makes one unsellable that otherwise would be.
3. **The stale token blocks nothing.** ON is already ON, so §6.1's
   `available_for_sale = 0` guard means no further ON applies. OFF (§6.3) does not
   inspect the token and **clears it**. Reconciliation never reads it.
4. **No older request can compensate after ownership changes.** Once OFF clears
   the token, or a later ON replaces it, the dead request's fence can never match
   again — and the dead request is not running to try.
5. **The row is not corrupt and needs no repair path.** The only observable
   residue is a non-NULL token on an ON occurrence, which the next OFF clears.
6. **The v3 forward-repair pass (§12.2) clears stale tokens** as part of returning
   from a rollback, because in that scenario the tokens genuinely have no owner.

Test 42 kills the process deterministically between the guarded ON and the
settlement and asserts every one of these.

---

### 7. Reconciliation

#### 7.1 The run fence

**At preflight the run captures `$run_definition_token`** — the exact
`active_definition_token` of the target schedule — together with
`$run_schedule_id` and **`$run_template_name`**, the exact replica template name
used to derive every name this run will write (§7.8). Every mutation proves what
it needs in its own `WHERE`/`JOIN`, so an application-level read is never the
thing that authorizes a write.

Every reconciliation mutation proves, **in SQL**:

1. the schedule still exists;
2. the schedule is still `status = 'active'`;
3. `sch.active_definition_token = $run_definition_token`;
4. the occurrence still carries `schedule_id = $run_schedule_id`;
5. the template is still commerce-eligible — **for every mutation except
   withdrawal**, which is the single exempt one (§4.1);
6. **for every mutation that writes a `display_name`**, that the replica template
   name still equals `$run_template_name` **byte for byte** (§7.8).

**The token is additionally re-proved by an authoritative read** between every
phase, and as part of final verification (§7.12).

If it has changed, or the schedule has been archived or deleted:

```
stop the run immediately
complete = false
status   = schedule_state_changed
remaining per-date work is not enumerated in the result
reserved not_attempted = 0
the run is NEVER reported as complete
```

A run that has already written some rows and then loses the fence still reports
`complete = false`; the writes it made were each individually fenced on the token
that was active when they ran, so nothing is inconsistent — only unfinished.
**Safe to retry:** yes.

#### 7.2 Withdrawal — one atomic multi-table DELETE, eligibility-exempt

Withdrawal is the **first** mutation phase and the **only** one exempt from
template and Package eligibility. Removing commerce must never be blocked by a
template going draft or by a configured Package becoming unassignable.

```sql
DELETE s, p
  FROM {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d          -- $run_schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s          -- $run_definition_token
  LEFT JOIN {prefix}bingo_session_packages p
    ON  p.session_id = s.session_id
 WHERE  s.session_id         = %d
   AND  s.status             = 'active'
   AND  s.available_for_sale = 0
   AND  s.sale_locked_at     IS NULL
   AND  s.session_date       > %s                 -- strictly future, occurrence timezone
```

- **No template `EXISTS`, and no Package validation gates this statement.**
- **Package rows can only be deleted through the parent.** If `s` does not satisfy
  every guard, the statement matches nothing and **neither** table is touched.
- `LEFT JOIN` means an occurrence with no Package rows is still withdrawn.
- **Atomic.** A single DML statement in InnoDB either applies to both tables or to
  neither.
- **`sale_locked_at IS NULL` is in the statement.** An occurrence that has **ever**
  been offered for sale is **never hard-deleted by this or any other path** — not
  by reconciliation, not by an administrator action in this phase, and not by the
  §19 runbook.
- Package assignment alone is **not** protection: a future, OFF, unlocked
  occurrence **is** withdrawable even though it has assignments.
- `s.status = 'active'` means an **archived** occurrence is never withdrawn; it is
  classified `retained_outside_pattern`.
- An **incomplete** occurrence outside the definition **is** withdrawable: there
  is no `generation_completed_at` predicate here.

Zero affected rows ⇒ **nothing** was deleted. Authoritative re-read and classify.
Counted `withdraw_lost_race` or `retained_outside_pattern`. **Safe to retry:**
yes.

**A successful withdrawal is never rolled back and never misreported.** If a later
phase fails — including the §10 eligibility gate — the run ends
`complete = false, status = failed` with `withdrawn` reported accurately.

This is the **only** hard-delete path for an occurrence, and it can never reach a
sale-locked one.

#### 7.3 Incomplete generation — convergent, token- and template-fenced repair

"Delete everything, reinsert everything, then mark complete" is **unsafe when two
reconciliation workers overlap**. Repair is instead **idempotent set convergence
under a token fence and a template fence**. Every statement carries both; none is
authorized by a pre-read.

**Step 1 — capture.** `$run_definition_token`, `$run_schedule_id`,
`$definition_package_ids` (the exact package id set for that token, **which must
be non-empty** — §10), and `$run_template_name`.

**Step 2 — remove extras.** `NOT IN` binds the definition's ids, so no correlated
subquery on the target is needed:

```sql
DELETE p
  FROM {prefix}bingo_session_packages p
  JOIN {prefix}bingo_sessions s
    ON  s.session_id              = p.session_id
   AND  s.session_id              = %d
   AND  s.schedule_id             = %d          -- $run_schedule_id
   AND  s.generation_completed_at IS NULL
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s        -- $run_definition_token
 WHERE  p.package_id NOT IN ( %d, %d, … )       -- $definition_package_ids
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
```

**Step 3 — insert missing**, once per definition package id:

```sql
INSERT INTO {prefix}bingo_session_packages (session_id, package_id, created_at)
SELECT %d, %d, %s FROM DUAL
 WHERE EXISTS (
         SELECT 1
           FROM {prefix}bingo_sessions s
           JOIN {prefix}bingo_session_schedules sch
             ON  sch.schedule_id             = s.schedule_id
            AND  sch.status                  = 'active'
            AND  sch.active_definition_token = %s
          WHERE s.session_id              = %d
            AND s.schedule_id             = %d
            AND s.generation_completed_at IS NULL
            AND s.available_for_sale      = 0
            AND s.sale_locked_at          IS NULL
            AND EXISTS (
                  SELECT 1
                    FROM {prefix}bingo_portal_session_templates t
                   WHERE t.portal_session_id = sch.portal_session_id
                     AND t.is_current        = 1
                     AND t.portal_status     = 'active'
                )
       )
```

**Duplicate-key handling is explicit, because a plain `INSERT` against
`UNIQUE (session_id, package_id)` is NOT a no-op — it raises an error.** The rule:

1. Catch **only** the duplicate-key condition (`ER_DUP_ENTRY`, MySQL **1062**).
2. **Authoritatively re-read** under the same `$run_schedule_id` and
   `$run_definition_token`.
3. If the exact expected `(session_id, package_id)` assignment now exists **and**
   the occurrence is still incomplete, OFF and unlocked, treat that Package as
   **already satisfied** and continue.
4. If the re-read does not show that state, the run **stops**:
   `complete = false`, `status = failed`.
5. **Any other database error stops the run** with `complete = false`,
   `status = failed`. Contention codes are handled by §7.11.
6. **`INSERT IGNORE` is not used.** It would downgrade unrelated errors to
   warnings, hiding exactly the failures this rule must surface.

A `NOT EXISTS` pre-guard against `bingo_session_packages` is permitted (§0.1) but
is **not** a substitute: two workers can both pass it and one still loses on the
unique key.

**Step 4 — complete, with a non-empty proof, an exact-set proof and the template
fence:**

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s        -- $run_definition_token
   SET  s.generation_completed_at = UTC_TIMESTAMP(),
        s.updated_at              = %s
 WHERE  s.session_id              = %d
   AND  s.schedule_id             = %d
   AND  s.generation_completed_at IS NULL
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
   -- the active definition has AT LEAST ONE Package
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_session_schedule_packages spx
           WHERE spx.schedule_id      = sch.schedule_id
             AND spx.definition_token = sch.active_definition_token
        )
   -- no required Package missing
   AND NOT EXISTS (
         SELECT 1 FROM {prefix}bingo_session_schedule_packages sp
          WHERE sp.schedule_id      = sch.schedule_id
            AND sp.definition_token = sch.active_definition_token
            AND NOT EXISTS (
                  SELECT 1 FROM {prefix}bingo_session_packages op
                   WHERE op.session_id = s.session_id
                     AND op.package_id = sp.package_id
                )
       )
   -- no extra Package present
   AND NOT EXISTS (
         SELECT 1 FROM {prefix}bingo_session_packages op2
          WHERE op2.session_id = s.session_id
            AND NOT EXISTS (
                  SELECT 1 FROM {prefix}bingo_session_schedule_packages sp2
                   WHERE sp2.schedule_id      = sch.schedule_id
                     AND sp2.definition_token = sch.active_definition_token
                     AND sp2.package_id       = op2.package_id
                )
       )
```

**The non-empty `EXISTS` is not redundant.** Without it, a definition with **zero**
Package rows would satisfy both `NOT EXISTS` branches vacuously — nothing is
missing and nothing is extra when the target set is empty — and an occurrence with
no Packages would be marked complete and become sellable. The explicit `EXISTS`
makes that impossible **in SQL**, independently of §10's gate.

Completion happens **only** when the occurrence's assignment set is *exactly* the
active definition's non-empty set, the template is still commerce-eligible, and
the run fence still holds.

**Step 5 — fence loss.** If the definition token changes, or the template stops
being commerce-eligible, the worker stops **without completing**. **Safe to
retry:** yes.

**Step 6 — stale workers after completion.** Every statement in steps 2–4 carries
`s.generation_completed_at IS NULL`. Once any worker completes the occurrence,
every other worker's remaining mutation affects **zero rows**.

**Overlapping workers with the same token converge**, because steps 2 and 3 are
set operations toward one target set rather than a wipe-and-rebuild, and step 4
refuses to complete until the set is literally exact.

Concurrency correctness is proved by tests 53–57, not by `EXPLAIN`.

#### 7.4 Retime — guarded, with expected previous values and the name proof

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.start_time_local = %s,
        s.starts_at_utc    = %s,
        s.display_name     = %s,
        s.updated_at       = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'active'
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            = %s          -- expected previous date
   AND  s.start_time_local        = %s          -- expected previous time
   AND  s.display_name            = %s          -- expected previous name
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
             AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)   -- $run_template_name
        )
```

Corrected **in place**; the `session_id` survives. `session_date` is in the
`WHERE` and never in the assignment list.

**Zero affected rows is never success.** Authoritative re-read classifies: turned
ON, sale-locked, archived, incomplete, already retimed, fence lost, template no
longer eligible, **template renamed** (§7.8), or foreign schedule. **Safe to
retry:** yes.

**A duplicate-key error (1062) is caught and classified**, never surfaced as a raw
persistence failure. The reconciler re-reads authoritatively, reports `conflicted`
with the owning `session_id`, and the run ends `complete = false, status = failed`.
This applies to the phase-4 `INSERT` as well.

#### 7.5 Restore — lifecycle only, Packages preserved

Restore applies only to **archived future complete** occurrences.

**Unlocked** — lifecycle, time and name in one statement, with the name proof:

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.status             = 'active',
        s.archived_at        = NULL,
        s.available_for_sale = 0,
        s.start_time_local   = %s,
        s.starts_at_utc      = %s,
        s.display_name       = %s,
        s.updated_at         = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'archived'
   AND  s.sale_locked_at          IS NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            > %s
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current = 1 AND t.portal_status = 'active'
             AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)   -- $run_template_name
        )
```

**Locked** — lifecycle only; time, name and lock preserved. **No name proof is
required or included, because this statement writes no `display_name`:**

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.status             = 'active',
        s.archived_at        = NULL,
        s.available_for_sale = 0,
        s.updated_at         = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'archived'
   AND  s.sale_locked_at          IS NOT NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            > %s
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current = 1 AND t.portal_status = 'active'
        )
```

Both require `generation_completed_at IS NOT NULL`. **An archived future
incomplete occurrence is repaired in phase 3 and restored in phase 6 of the SAME
run** (§7.6).

**Zero affected rows on either statement is never success.** Authoritative
re-read classifies, counted `restore_lost_race`. **Safe to retry:** yes.

**Restore never touches Package assignments.** Schedule defaults are **not**
re-applied. `sale_locked_at` and `generation_completed_at` are preserved.
`available_for_sale` is forced to `0`.

#### 7.6 Phase order and classification

Withdrawal runs **first**, so removing commerce is never blocked by the
eligibility gate that governs adding it.

| Phase | Work | Eligibility gated? | On failure |
|---|---|---|---|
| **0** | Preflight — read-only; capture `$run_definition_token`, `$run_schedule_id`, `$run_template_name`; classify; blockers abort (§8.4) | n/a | abort, **nothing written**, `complete = false`, `status = failed` |
| **1** | **Withdraw** removed future occurrences (§7.2) | **No** | stop, `complete = false`, `status = failed` |
| **2** | **Eligibility gate** — template commerce-eligible, definition Package set **non-empty**, and every configured Package assignable (§10) | — | stop, `complete = false`, `status = failed`; phase-1 withdrawals stand and are counted |
| **3** | **Repair** eligible incomplete occurrences (§7.3) | Yes | stop, `complete = false`, `status = failed` |
| **4** | **Create** missing future dates (§7.7) | Yes | stop, `complete = false`, `status = failed` |
| **5** | **Retime** correctable occurrences (§7.4) | Yes | stop, `complete = false`, `status = failed` |
| **6** | **Restore** archived future complete occurrences (§7.5), **including rows repaired in phase 3, reclassified at phase entry** | Yes | stop, `complete = false`, `status = failed` |
| **7** | **Final verification** (§7.12) before `complete = true` | Yes | `complete = false`, `status = failed` |

The definition token is re-proved by an authoritative read **between every
phase**. A phase that records a **write failure**, a **lost fence**, a
**duplicate-key conflict**, a **template rename** or a **contention error** stops
the run before the next phase begins. A *classification* —
`retained_historical`, `retained_historical_incomplete`,
`retained_outside_pattern`, `existing` — is informational and does not stop or
fail the run. `skipped_past` and `not_attempted` remain reserved, zero-valued
reporting fields in the current implementation (§8.6), not populated
classifications.

Let **T** = expansion of `$run_definition_token`, filtered to **strictly future**
dates in the schedule's `timezone_name`; **E** = every occurrence carrying
`schedule_id = $run_schedule_id`, any status.

| # | Case | Action | Effect on the run |
|---|---|---|---|
| 1 | **Historical incomplete** — `generation_completed_at IS NULL`, date not strictly future | **no mutation**, remains unsellable; `retained_historical_incomplete` | informational |
| 2 | **Incomplete, ON or sale-locked** (any date) | **no mutation**, no Package rewrite; `incomplete_integrity_conflict` | **preflight integrity blocker** — run aborts, `complete = false`, `status = failed` (§8.4) |
| 3 | **Incomplete, future, OFF, unlocked, in T** — active **or archived** | Phase 3 repair (§7.3); an archived one is then reclassified and restored in **phase 6 of the same run** | normal |
| 4 | **Incomplete, future, OFF, unlocked, not in T, active** | Phase 1 withdraw (§7.2) — permitted, no completion predicate | normal |
| 5 | In T, not in E | Phase 4 create (§7.7) | normal |
| 6 | In T, in E, active, complete, time matches | **no write**; `existing` | informational |
| 7 | In T, in E, active, complete, time differs, future ∧ OFF ∧ unlocked | Phase 5 retime (§7.4); `retimed` | normal |
| 8 | In T, in E, time differs, locked **or** ON | **no write**; `protected_time_conflict` | **preflight blocker** — run aborts (§8.4) |
| 9 | In T, in E, archived, future, complete, unlocked | Phase 6 restore + retime (§7.5); `restored` | normal |
| 10 | In T, in E, archived, future, complete, locked | Phase 6 restore lifecycle only (§7.5); `restored` | normal |
| 11 | In E, not in T, future ∧ OFF ∧ unlocked ∧ active | Phase 1 withdraw (§7.2); `withdrawn` | normal |
| 12 | In E, not in T, locked, ON, or archived | **no write**; `retained_outside_pattern` | informational |
| 13 | **Any occurrence whose date is not strictly future** (and not row 1) | **no write, in every phase**; `retained_historical` | informational |

Rows 1 and 13 together are the complete statement of past immutability.

#### 7.7 Create

Per date in T, not in E:

1. Guarded `INSERT … SELECT … FROM DUAL WHERE EXISTS (…)` with
   `available_for_sale = 0`, `sale_locked_at = NULL`,
   **`generation_completed_at = NULL`**, `availability_operation_token = NULL`,
   `status = 'active'`, the derived name, and `start_time_local` /
   `timezone_name` / `starts_at_utc` from the schedule. The `EXISTS` guard carries
   the run fence, the template fence **and the binary name proof**
   `CAST(t.name AS BINARY) = CAST(%s AS BINARY)` against `$run_template_name`,
   because this statement writes a `display_name`.
2. Apply every definition Package through the **internal generation writer**
   (§7.10), using §7.3's duplicate-key rule.
3. Complete with §7.3 step 4's statement.

A duplicate-key error on the occurrence insert is handled as in §7.4. A Package
write failure leaves the row incomplete; the run **stops**. **Safe to retry:**
yes — phase 3 of the next run converges it.

#### 7.8 Template rename, and the binary name proof

A Portal rename can land between the moment a run derives a name and the moment it
writes it. Without a guard the run would store a name derived from the **old**
template while the replica already holds the **new** one — a silent, permanent
inconsistency that no later phase would detect, because a name mismatch is
deliberately not a retime trigger.

**Every statement that writes an occurrence `display_name` therefore proves the
source name is unchanged:**

| Statement | Writes `display_name`? | Name proof |
|---|---|---|
| Create (§7.7) | **Yes** | **Required** |
| Retime (§7.4) | **Yes** | **Required** |
| Restore, unlocked (§7.5) | **Yes** | **Required** |
| Restore, locked (§7.5) | No | Not required, not included |
| Repair steps 2–4 (§7.3) | No | Not required, not included |
| Withdraw (§7.2) | No | Not required, not included |

The proof is `CAST(t.name AS BINARY) = CAST(%s AS BINARY)`, bound to
`$run_template_name` — the exact replica name captured at preflight.

**It must be a binary comparison, not the column's collation.** The replica column
collates case- and accent-insensitively (§4.2, measured), so `Evening Bingo` and
`evening bingo` compare **equal** under it — a rename between those two would slip
through and the stored name would be wrong. Under `CAST(… AS BINARY)` they compare
**unequal**, which is the required behaviour. The idiom follows the existing
precedent at `wp-content/plugins/bingocommerce-catalog/src/Migrations.php:19-20`.

**On a rename, the statement affects zero rows.** The reconciler re-reads
authoritatively, distinguishes *renamed* from the other zero-row causes, stops the
run with `complete = false`, `status = failed`,
`failure_category = template_renamed`, and **requires a retry**. The next run
captures the new `$run_template_name` and the proof then matches. Nothing is
half-written: the statement either wrote the row with a name derived from the name
it proved, or wrote nothing.

**Rename semantics otherwise are unchanged:**

- A rename affects **newly generated** occurrences.
- **A rename alone never causes a reconciliation write.** Name mismatch is not a
  retime trigger and not a conflict; only a **time** mismatch triggers row 7.
- An existing occurrence **keeps its stored name** when no schedule-owned
  mutation is otherwise required.
- When an unlocked future **complete** occurrence is legitimately retimed (row 7)
  or restored-unlocked (row 9), its name **is recomputed from the current template
  name** — and that name is the one the proof binds.
- **Locked, ON and historical occurrences retain their stored names.**
- The Manager message reads: *the Portal template was renamed. Existing
  occurrences keep their current names; an occurrence only takes the new name if
  its time also changes and it has never been offered for sale.*

#### 7.9 Package defaults

**Defaults** are written onto an occurrence **only** by the internal generation
writer, and only while that occurrence is incomplete — on first generation (§7.7)
and on repair (§7.3). They are **never** written to a completed, locked or ON
occurrence, and **never** re-applied on restore.

#### 7.10 Internal generation writer

`OccurrenceGenerationWriter` is an **internal reconciliation path**, not a Manager
action. It exists because `SessionService::assignPackage()` refuses generated
occurrences while `generation_completed_at IS NULL` (§9) — the very state in which
generation must write Packages. The two paths are disjoint by guard.

- **Not** exposed in any admin screen, POST handler, REST route or public service
  method.
- **Reuses the existing Package validation rules** — `PackageGateway::get()` and
  `PackageGateway::assert_assignable()`.
- Every statement requires: `generation_completed_at IS NULL`; the exact
  `schedule_id`; `sch.active_definition_token = $run_definition_token`;
  `available_for_sale = 0`; `sale_locked_at IS NULL`; **and the template
  commerce-eligible** by `EXISTS`.
- Therefore it **cannot mutate a completed, locked or ON occurrence**, and cannot
  write while the template is ineligible.
- It applies §7.3's duplicate-key rule; it never uses `INSERT IGNORE`.

#### 7.11 Database contention

| MySQL error | Meaning |
|---|---|
| **1213** | deadlock detected, transaction rolled back |
| **1205** | lock wait timeout exceeded |

On either code, in **any** statement of **any** phase:

1. **Stop the run immediately.**
2. `complete = false`, `status = failed`,
   `failure_category = database_contention`.
3. **Authoritatively re-read before reporting.**
4. **Statements that individually committed earlier remain valid**, are counted
   accurately, and are not rolled back — there is no enclosing transaction (§3.1).
5. **No automatic retry inside the run.** Retrying means a **new run** from
   current state.
6. The reported category is the fixed string `database_contention`. **No SQL, no
   driver message, no table name and no stored value is surfaced or logged.**

#### 7.12 Final success verification

Immediately before returning `complete = true`, the run re-proves by authoritative
read:

1. the schedule still exists and `status = 'active'`;
2. `active_definition_token` still equals `$run_definition_token`;
3. the template is still commerce-eligible;
4. **the replica template name still equals `$run_template_name` byte for byte**;
5. **the active definition's Package set is non-empty** — proved **before** it is
   iterated, so an empty set can never vacuously pass step 6;
6. **every configured definition Package is still assignable**
   (`assert_assignable()` for each id).

If (1), (2) or (4) fails → `complete = false`, `status = schedule_state_changed`
(or `failed` with `failure_category = template_renamed` for (4)).

If (3), (5) or (6) fails → **the run does not return success**:

```
complete = false
status   = failed
failure_category = final_verification_failed
```

Every occurrence this run created or completed **remains stored** — nothing is
rolled back, and the counts are reported accurately. Those occurrences **fail
closed through §6.4** for as long as the problem persists, and cannot be turned ON
because §6.2's pre-proof fails. **Retry is safe once the Package or template is
fixed.**

A successful run never reports `ok` on the strength of checks made only at its
start.

---

### 8. Date Boundary, Collisions and Partial Failure

#### 8.1 New dates: tomorrow or later

- **At creation**, every explicitly selected date and `recurrence_start` must be
  **tomorrow or later** in the schedule's `timezone_name`, else
  `date_must_be_tomorrow_or_later`.
- The UI states this next to both controls: *dates must be tomorrow or later in
  this hall's scheduling timezone.*

#### 8.2 Editing a weekly schedule after its start date

- Remains editable after the original `recurrence_start` has passed.
- `recurrence_start` is **preserved as stored**, past or not, unless the Manager
  supplies a new start — which must be tomorrow-or-later.
- Reconciliation emits **only dates from tomorrow onward**.
- The current reconciler does not populate `skipped_past`; that result field is
  reserved for deferred per-date reporting (§8.6).
- `recurrence_end >= recurrence_start`, span ≤ 366 days. An edit whose whole
  range is already past commits successfully, generates nothing, and says so.

#### 8.3 Editing an explicit-date schedule after dates pass

- **An explicit-date schedule remains editable** after some of its selected dates
  have become today or historical.
- **Previously committed dates that are now today or historical MAY be omitted**
  from the new active definition.
- **Omitting them changes nothing.** Their occurrences are historical and
  untouchable under §7.6 rows 1 and 13, and withdrawal requires a strictly-future
  date. The **historical occurrences themselves remain the durable record**.
- **They may also be kept**, exempt from the tomorrow-or-later rule and not
  re-validated.
- **Newly added dates must be tomorrow or later.**
- **A historical date not in the previously committed definition cannot be
  added**, with `historical_date_not_addable`.
- **The 400-date limit applies to the new active definition**, never to the
  schedule's lifetime, so dropping passed dates frees room and a schedule can be
  extended indefinitely.
- **Superseded definition rows are cleaned by §3.7** after a successful edit, so
  they do not accumulate.
- **A Package-only edit is never blocked** by dates that have since passed.

**UI behaviour.** The date editor shows passed dates in a separate *Past dates*
group, each with a checkbox to keep or drop, defaulting to **drop** once the
active definition would otherwise exceed 400 entries and to **keep** below that.
The screen states plainly: *dropping a past date does not change or remove any
occurrence that was already generated.*

#### 8.4 Preflight — read-only, before any write

1. Capture `$run_schedule_id`, `$run_definition_token` and `$run_template_name`.
2. Expand T; filter to strictly-future (§8.1–8.3).
3. Compute `starts_at_utc` for every date (§5.2); `nonexistent_local_time` and
   `ambiguous_local_time` are blockers.
4. Derive the display name for every date (§4.2) from `$run_template_name`.
5. Read, **through the database and in the collation in force** (§4.2): every
   occurrence for `$run_schedule_id`, and every occurrence in the hall matching
   any candidate `(session_date, display_name)`.
6. Classify every date and every existing occurrence against §7.6.
7. **If any blocker exists, abort before any write**, with `complete = false`,
   `status = failed`, `failure_category = preflight_conflict`, every blocker
   reported with its kind and — where applicable — the owning `session_id` and
   both template names. Every count is zero.

The **preflight blockers** are exactly:

- a foreign `(session_date, display_name)` clash;
- `protected_time_conflict` (§7.6 row 8);
- `incomplete_integrity_conflict` (§7.6 row 2);
- `nonexistent_local_time` or `ambiguous_local_time`.

Preflight decides *what to attempt*. It never authorizes a write.

#### 8.5 Collisions after preflight

- **Guarded statement affects zero rows** → authoritative re-read and classify.
- **Duplicate-key (1062)** → §7.3's rule for Package inserts; §7.4's rule for
  occurrence inserts and retimes.
- **Template renamed** → §7.8, `failure_category = template_renamed`.
- **Contention (1213 / 1205)** → §7.11.
- **Fence lost** → §7.1.

#### 8.6 Reported result

```
status                 : ok | failed | schedule_state_changed
complete               : bool          (true if and only if status = ok)
failure_category       : null | preflight_conflict | eligibility_failed
                       | write_failed | duplicate_conflict | template_renamed
                       | database_contention | final_verification_failed

withdrawn, created, completed, repaired, retimed, restored, existing,
incomplete, conflicted, protected_time_conflict,
incomplete_integrity_conflict, retained_outside_pattern,
retained_historical, retained_historical_incomplete,
skipped_past, withdraw_lost_race, restore_lost_race, not_attempted
```

`skipped_past` and `not_attempted` are reserved reporting fields retained in the
result vocabulary for deferred per-date reporting. The current reconciler
initializes both to zero and never increments them; zero-valued fields are
omitted from manager notices. They MUST NOT be interpreted as populated results.

A run with `complete = false` is **never** described as finished, always carries a
`failure_category`, and the notice names what to do next.

---

### 9. Mutation Ownership of Generated Occurrences

**Correction (Increment 3 follow-up pass).** Increment 3's first commit routed
`setAvailableForSale()` through the guarded §6.1/§6.2/§6.3 path but left every
OTHER writer below unguarded against `schedule_id`: `rename()`, `reschedule()`,
`updateDetails()`, `archive()` and `restore()` could all still reach a
schedule-owned row, and `updateDetails()` in particular could change
`available_for_sale` on one directly — the exact bypass this table exists to
rule out. **This was reported as an explicit unresolved risk at the end of that
pass, not silently.** The five refusals and the two completion gates below are
implemented in the follow-up correction pass, in
`SessionService::refuse_if_schedule_owned()` and
`SessionService::refuse_if_generation_incomplete()`, tested by §16 F43–F45 and
F49–F50 in `tests/session-availability-increment-1.php`.

| Writer | `schedule_id IS NULL` | `schedule_id IS NOT NULL` |
|---|---|---|
| `create()` | unchanged | n/a — never sets `schedule_id` |
| `rename()` (`…/SessionService.php:111`) | unchanged | **refused** — `schedule_owned_occurrence` |
| `reschedule()` (`:135`) | unchanged | **refused** |
| `updateDetails()` (`:199`) | unchanged | **refused** |
| `archive()` (`:284`) | unchanged | **refused** |
| `restore()` (`:289`) | unchanged | **refused** |
| `setAvailableForSale()` (`:159`) | unchanged | **allowed** — ON through §6.1/§6.2, OFF through §6.3 |
| `assignPackage()` (`:311`) | unchanged | allowed **only when** `generation_completed_at IS NOT NULL` |
| `removePackage()` (`:395`) | unchanged | allowed **only when** `generation_completed_at IS NOT NULL` |
| `OccurrenceGenerationWriter` (§7.10) | **never** — legacy rows are not reachable | **only** path that writes Packages while incomplete; internal, not a Manager action |

Reconciliation owns a generated occurrence's **name, date, time and lifecycle**.
`SessionAdmin`'s `update`, `archive` and `restore` actions
(`…/SessionAdmin.php:330-366`) are additionally hidden for generated rows, but
hiding is presentation; the service refusal is the enforcement, and both are
tested.

**Revision 9 / BR-078:** generated occurrences outside the current definition
are absent from default Upcoming and offer no Enable Sales action. A forced
handler or `setAvailableForSale(true)` request is refused server-side, including
an already-ON stale row. All/history reads remain unfiltered by membership;
already archived rows remain in Archived. Existing reconciliation may withdraw
only rows permitted by §7.2 and otherwise retains protected history under §7.6.
Re-adding an OFF retained date never enables sales; archived restoration stays
OFF and preserves its existing identity and Package assignments.

**Legacy rows keep their observable behaviour exactly**: same accepted inputs,
same validation, same lifecycle, same return shapes, same error codes, same audit
events, same thirty-day evaluation against `wp_timezone()` (five days before Revision 8;
the width changed, the evaluation path did not — §15 amendment note). The row
read now includes `schedule_id` because it is part of the extended `COLUMNS` list, and the
router inspects that value — **no additional query, no new failure mode and no
changed result for a legacy row**. The claim is behavioural equivalence, not that
no new column is read.

---

### 10. Package Validation

- A schedule **requires at least one same-Hall assignable Package** at creation
  and at every definition commit, checked as **request-time validation** (§3.2).
  That validation is not atomic with the CAS and does not claim to be.
- **Phase 2 is the eligibility gate**, and it proves three things:
  1. the template is commerce-eligible;
  2. **the active definition's Package set is non-empty** — a zero-Package
     definition fails the gate outright;
  3. every configured `package_id` resolves through `PackageGateway::get()` and
     passes `PackageGateway::assert_assignable()`.
  `map()` populates the selector only, because it is capped at
  `ADMIN_READ_PAGE_SIZE = 200` (`…:25`, `:60-78`).
- **The gate runs AFTER withdrawal (phase 1), never before it.** If it fails, the
  run stops with `complete = false`, `status = failed`,
  `failure_category = eligibility_failed`, every offending Package named — and
  **the phase-1 withdrawals stand and are counted accurately**.
- **If the gate fails, no occurrence is created, repaired, retimed or restored.**
- A Package that becomes unassignable **after** occurrences exist does not delete
  them; it blocks the next run's phases 3–6, blocks turning further occurrences ON
  (§6.2), and makes existing ones fail closed through §6.4.
- **The non-empty requirement is additionally proved in SQL** at §7.3 step 4, so
  an occurrence can never be completed against an empty definition even if the
  gate were bypassed.
- §7.12 re-proves non-emptiness **before iterating**, then assignability.
- `bingo_session_packages` is **unchanged**: no schema change, no data change.

---

### 11. Migration Rollout and Portal-Sync Readiness Separation

#### 11.1 The existing migration framework is retained unchanged

No new migration UI, no new migration audit event, no change to either hook:

- `maybe_migrate_network()` stays exactly as it is (`…/Migrations.php:48-95`,
  hooked on `network_admin_menu` priority 6, `…/Plugin.php:39`). Once
  `DB_VERSION` becomes 3 its gate reads `2 >= 3` → false and the next
  network-admin load sweeps every hall. **This is the intended deployment
  mechanism.**
- `maybe_migrate_current_site()` stays exactly as it is (`…/Plugin.php:40`).
- Both run `migrate_current_site()`, which advances the version **only after all
  six structures and both integrity probes verify** (`…/Migrations.php:185-196`,
  §2.5).

**Deployment obligation:** the rollout must *deliberately trigger and verify* this
mechanism — load network admin as a Super Admin, then confirm every hall's
`bc_sessions_db_version` reads 3 and `verify_current_site_schema()` returns
`true`.

#### 11.2 New-site provisioning

`provision_new_site()` is unchanged and builds all six structures, so a newly
provisioned hall has the **full current schema before any operational success is
reported**.

#### 11.3 Portal-sync readiness separation

`PortalSyncService::assert_domain_schemas()` (`…/PortalSyncService.php:550-562`)
gates synchronization on whole-plugin *version* comparisons, so bumping Sessions
to v3 would break Portal sync on every hall until it migrated — though
synchronization touches none of the new tables.

Replace those with **structural verification of exactly what the transport
touches**:

| Requirement | What synchronization touches | Structural proof |
|---|---|---|
| **Game Type replica** | `game_type_id`, `portal_game_type_id`, `code`, `name`, `local_name_key`, `status`, `created_at`, `updated_at`, `archived_at` — `…/PortalSnapshotTransactionTransport.php:406`, `:591-594`, `:623-624` | `Catalog\Migrations::verify_game_types_schema()` (`…/catalog/src/Migrations.php:299-340`) plus its obsolete-index check and indexed `COUNT(*)` probe (`:458-466`) |
| **Portal Session-template replica** | all eight columns, `:675-750` | `Sessions\Migrations::verify_portal_session_templates_schema()` (`…/sessions/src/Migrations.php:334-378`) |
| **Sync-state storage** | a **per-site option**, `bc_portal_snapshot_sync_state` (`…/PortalSyncState.php:31`) — not a custom table | No additional requirement; the transport already asserts `options_table` exists and is InnoDB via `REQUIRED_INNODB_TABLES` (`…:101-107`) |

Exposed as `PortalSyncService::assert_snapshot_schemas()`. **No DDL**, no
migration side effect on a read path.

Precedent: `SetupReadiness::check_domain_schema()` (`…/SetupReadiness.php:725-740`)
already does this — *"Structure first, version marker second."*

**Effect: a hall still at Sessions v2 completes Portal synchronization
successfully.**

#### 11.4 Schedule administration still requires full v3

`SessionScheduleService::authorize()` requires `Sessions\Migrations::is_current()`,
returning `sessions_schema_unavailable` otherwise — identical to
`SessionService::authorize()` (`…:687-689`).

---

### 12. Rollback Restrictions

#### 12.1 The exact recorded-version comparison

```php
public static function is_current(): bool {
    return (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION;
}
```
`wp-content/plugins/bingocommerce-sessions/src/Migrations.php:201-203`.

**The operator is `>=`, not `===`.** With the option at 3 and code at v2,
`is_current()` returns `true`, `maybe_migrate_current_site()` returns early at
`…:29` with no DDL, and the option is never lowered.

#### 12.2 A writable v2 rollback is UNSAFE

v2 can *read* a v3 database — it ignores additive nullable columns and tables.
**Reading is not the problem. Writing is.** Against a v3 database, v2 code can:

- **turn a generated occurrence ON or OFF without setting `sale_locked_at` and
  without an `availability_operation_token`**, because v2 has neither column —
  and when v3 returns, §7.2's guard considers that occurrence deletable;
- **mutate schedule-owned `session_date`, `display_name` and lifecycle** through
  `rename()`, `reschedule()`, `updateDetails()`, `archive()` and `restore()`,
  because v2 has no §9 enforcement;
- **turn an incomplete occurrence ON**, producing §7.6 row 2's
  `incomplete_integrity_conflict` — a preflight blocker that then requires the
  §19 runbook to clear.

**Therefore:**

1. **A v3 → v2 code rollback requires Session mutation paths to be placed in
   maintenance / read-only mode.** Reads may continue.
2. **Returning to v3 requires a forward verification and repair pass before
   writes reopen**, which must at minimum:
   - set `sale_locked_at` on every generated occurrence with
     `available_for_sale = 1 AND sale_locked_at IS NULL`;
   - clear every `availability_operation_token`, since after a rollback none has
     a living owner (§6.5);
   - report every generated occurrence whose `session_date`, `display_name`,
     `start_time_local` or `starts_at_utc` disagrees with its schedule, as drift,
     **without auto-rewriting it**;
   - report every `incomplete_integrity_conflict` row for investigation;
   - repair every eligible incomplete row.
   Writes reopen only after the pass completes and its report is reviewed.
3. **Never lower the stored database version.**
4. **Any structural correction after release ships as `DB_VERSION = 4`.**
5. **No destructive down-migration is authorized.** `dbDelta()` cannot drop a
   column or convert a key's uniqueness (`tests/session-increment-1.php:229-231`).

#### 12.3 How rollback is actually tested, and against which commit

**Forcing `DB_VERSION` to 2 does not execute v2 behaviour.** The constant is
compiled into the loaded v3 class; every v3 guard, refusal and column write still
runs. This ADR forbids a test that does that.

**The verified Sessions-v2 baseline commit is:**

```
488c064dbaf4a0bfd4126e18afdd9de848ff86b5
feat(sessions): add portal session template replicas
Fri Sep 4 10:41:42 2026 -0400
```

This was established, not assumed, on 2026-09-16:

- `git log --follow -- …/bingocommerce-sessions/src/Migrations.php` shows exactly
  two commits: `2505129` (v1, `feat(sessions): implement session management`) and
  `488c064` (v2).
- `git show 488c064:…/src/Migrations.php` confirms `DB_VERSION = 2` and the
  `bingo_portal_session_templates` constant.
- `git diff --stat 488c064 HEAD -- wp-content/plugins/bingocommerce-sessions/`
  is **empty**: the Sessions plugin is byte-identical between 488c064 and the
  current HEAD.

**A correction to an earlier assumption:** `4104f88706ee8e1dc8c3b2d4e50d424b213d8451`
— the current HEAD — is **NOT** the Sessions-v2 commit. It is
`feat(integration): add automatic Hall provisioning` and touches only
`bingocommerce-portal-integration` and `bingocommerce-platform`. It happens to
*contain* identical v2 Sessions source, but `488c064` is the commit that
established v2 and is the correct anchor to record.

**If any Sessions commit lands between today and the v3 change, this reference is
stale** and the implementation must re-run the three verification commands above
and record the new baseline before writing the rollback tests.

The rollback tests use **both** of these:

**(a) Frozen-source assertions.** The v2 implementation is retrieved by
`git show 488c064dbaf4a0bfd4126e18afdd9de848ff86b5:wp-content/plugins/bingocommerce-sessions/src/SessionService.php`
and asserted against directly. Verified today: that source contains **zero**
occurrences of `sale_locked_at`, `generation_completed_at`,
`availability_operation_token` and `schedule_id`.

**(b) Seeded database states.** The test writes the exact rows v2 *can* produce —
a generated occurrence with `available_for_sale = 1` and `sale_locked_at IS NULL`;
one with a stale `availability_operation_token`; one whose `session_date` and
`display_name` no longer match its schedule; an incomplete occurrence with
`available_for_sale = 1` — and exercises the **v3 forward-repair pass**.

(a) is what makes (b)'s seeded states provably reachable rather than invented.

Optionally, where repository rules permit vendoring a frozen v2 plugin copy under
`tests/fixtures/` — outside `wp-content/` and never deployed, per AGENTS.md §3 —
an isolated worker may load that copy for a genuine execution test. **This ADR
does not require it.**

---

### 13. Permission, Nonce, Audit, Isolation, and Game Type Flow

#### 13.1 Permission, nonce, isolation, audit

**Capability.** `bc_manage_sessions` with the `is_super_admin()` override. No new
capability. Game Operator holds `bc_view_sessions` but not `bc_manage_sessions`
(`…/Roles.php:39-62`) and is excluded.

**Authorization shape.** Reuse `SessionService::authorize()` verbatim: logged in
→ `HallContext::is_hall()` → capability → `Migrations::is_current()` (`…:672-697`).

**Nonce.** `check_admin_referer()` on every mutation (`…/SessionAdmin.php:56`).
Generation runs through `admin_post_bc_session_schedule_generate`, never in a
render callback — the reasoning is recorded at `…/PortalSyncAdmin.php:150-158`.
The handler enforces POST-only, tenancy, capability and nonce **in that order**.

**Hall isolation.** Per-site `$wpdb->prefix`; no `blog_id` column anywhere. Every
id resolves only against the current site's own tables. Every guarded occurrence
statement additionally carries `schedule_id = %d` and joins that site's schedules
table, so ownership is proved in SQL. `portal_session_id` is always validated
against *this hall's* replica before any write. Request integers use
`SessionAdmin::request_int()` (`…:370-379`), not `absint()`.

**HallCommerceGate.** No change; no soft `class_exists()` check and no fail-open
notice. `HallCommerceGate::decide()` (`…:284-307`) exempts only cron, the Action
Scheduler runner, the approved payment callback and the login path — `/wp-admin/`
is not exempt, so a BLOCK hall already refuses this surface at `plugins_loaded`.
This phase introduces **no cron and no CLI writer**.

**Audit** — `<domain>.<entity>.<verb>`:

| Action | `target_type` | `target_id` |
|---|---|---|
| `sessions.schedule.created` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.updated` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.archived` / `.restored` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.generated` | `bingo_session_schedule` | `schedule_id` |

**No schema-migration audit event is added.** Occurrence-level events reuse the
existing `sessions.session.*` actions. `sessions.schedule.generated` carries
**counts, `status` and `failure_category` only** in `context` (§8.6).

**The `sessionid` landmine, and the `token` one (corrected, Increment 5).**
`AuditLog` compacts every payload key to alphanumerics and rejects `sessionid`
(`…/AuditLog.php:83`); this is why `session_id` is absent from every Session
payload (`…:884-888`).

**A key containing `token` is rejected the same way, and an earlier revision of
this section wrongly claimed otherwise.** `is_sensitive_key()` splits a key on
non-alphanumerics and refuses it when ANY resulting token is a sensitive term
(`…/AuditLog.php:534-550`), and `token` is on that list (`…:58`). So
`active_definition_token` split to `['active','definition','token']` and was
refused: every schedule create, edit, archive and restore recorded an
`audit_write_failed` warning and **no audit row at all**. This went unseen until
the administration screens became the first caller to exercise the real audit
recorder — every harness before them passed a swallowing stub.

The remedy follows the `session_id` precedent: the payload key is
**`active_definition`**, carrying the same value. No `*_token` key is ever
placed in an audit payload. `definition_token`, `run_definition_token` and
`availability_operation_token` are likewise **never audited** —
`SessionService` already documents that exclusion for the last of them
(`…/SessionService.php:1048`).

**`portal_session_id` was refused by the same filter, one position along**, and
this section wrongly claimed it passed too. `is_sensitive_key()` also rejects a
key when two ADJACENT parts join into a sensitive compound
(`…/AuditLog.php:560-564`), and `portal_session_id` splits to
`['portal','session','id']` whose adjacent pair `session`+`id` is `sessionid` —
the very name that keeps `session_id` out of every Session payload. The audit
key is therefore **`portal_template_id`**, which is also what ADR-035 calls this
identity in prose. **The column is unchanged**; only the audit key differs.

Keys actually used, and checked against `SENSITIVE_KEY_TOKENS` and
`SENSITIVE_KEY_NAMES`: `schedule_id` (as `target_id`, not a payload key),
`portal_template_id`, `active_definition`, `timezone_name`, `start_time_local`,
`mode`, `weekday_mask`, `recurrence_start`, `recurrence_end`, `status`,
`failure_category` and the §8.6 counts.
`tests/session-schedule-admin-increment-1.php` asserts that a real schedule
mutation records its audit event with **no** `audit_write_failed` warning, and
enumerates `audit_payload()`'s own keys for both landmines — which is what makes
this claim testable rather than assumed. The general lesson: a key is checked
part-by-part AND pairwise, so "it contains no obviously secret word" is not
enough to conclude it passes.
**`run_template_name` is never placed in an audit payload** — it is a
Portal-supplied display string, and the audit carries counts only.

**No new transaction transport is introduced** (§3.1, §7.3, §7.11).

#### 13.2 Game Type flow — the schedule never selects one

- **A schedule does not select a Game Type**, directly or indirectly.
- **The frozen Portal session snapshot contains no Game Type information.**
  `PortalSnapshotValidator::SESSION_FIELDS` is exactly
  `id, hall_id, name, status, updated_at` (`…/PortalSnapshotValidator.php:31`).
- **The Manager selects a Portal session template and one or more Packages.**
- **Game Types enter the purchase/play flow through the selected Packages**, via
  their Bingo Play Products: `bingo_packages` →
  `bingo_package_components.bingo_play_product_id` (`…/catalog/src/Migrations.php:219`)
  → `bingo_play_products.game_type_id` (`…:185`) →
  `bingo_game_types.portal_game_type_id` (`…:156`). Game Type is chosen when a
  Bingo Play Product is created — `BingoPlayProductService::create( …, ?int
  $game_type_id = null )` (`…/BingoPlayProductService.php:56`) — which
  **requires** one for `medium = digital` (`…:76`).

Two source facts recorded because they matter downstream, neither a conflict:

1. `bingo_play_products.game_type_id` is **nullable** (`…:185`) — correct by
   design; paper needs no Game Type, digital is enforced at `:76`.
2. The supported Package component read does **not project Game Type**:
   `PackageService`'s component query selects `pp.medium`, `pp.faces_per_unit`,
   `pp.status` and `pp.wc_product_id` but **not** `pp.game_type_id`
   (`…/catalog/src/PackageService.php:773-775`). **ADR-043 does not add it.**

---

### 14. Legacy Compatibility, and Deferred Work

**Legacy `schedule_id IS NULL`.** Every occurrence created before v3, and every
manual occurrence afterwards, carries NULL in all **seven** new columns and keeps
its existing observable behaviour: same writers, same validation, same lifecycle,
same thirty-day evaluation against `wp_timezone()` (§5.3; five days before
Revision 8 — the width changed, not the mechanism), same Package assignment,
no §9 refusal, no §6.1 guarded path, no sale lock, no operation token, no
completion requirement, no reconciliation, and exemption from the §2.5
generated-occurrence integrity probe. The one mechanical change is that the row
read now carries `schedule_id` (§9).

**Deferred, and named so it is not mistaken for an omission:**

- **Garbage collection of crash-abandoned private definition tokens** (§3.7) —
  the only remaining accumulation case now that superseded definitions are
  cleaned.
- **A durable order → occurrence reference** — needed by checkout, not by
  withdrawal safety, which §7.2 settles atomically.
- **Game Type projection on the Package component read** (§13.2).
- **The customer calendar** — a later phase consuming §6.4's predicate.
- **The Laravel Portal** — completely unchanged.
- Checkout, orders, entitlement, QR, redemption, webhooks, scheduled sync,
  reconciliation and SSO.

---

### 15. Manager Workflow

**Revision 9 amendment.** BR-077 ratifies the thirty-day sales window. Under
BR-078 a Manager may remove a future date without a disable-first refusal. The
removed occurrence becomes ineligible for purchase, disappears from Upcoming,
and cannot be enabled while absent from the committed definition. A successful
edit clears its sales flag. Protected history stays reachable through All (or
Archived for archived records). Retry Creating Sessions uses the existing
withdrawal/retention rules; this revision adds no archival phase. Re-adding a
retained date keeps sales OFF until the Manager explicitly enables it. No
advisory mutex, capability probe or infrastructure dependency is introduced.
Schedule-administration dates are displayed and accepted as `MM/DD/YYYY`;
database, SQL, API and internal canonical dates remain `YYYY-MM-DD`, with
strict conversion at trusted input/output boundaries.

**Revision 8 amendment note.** Everything below this note describes a
**manager-facing UX correction** made directly against §15 as it read in
Revisions 1–7: navigation, screen labels, and one new orchestration method
(`SessionScheduleAdmin::create_and_generate()`) that calls the *existing* §3.8
create service and then the *existing* §7.6 generation service, in that order,
inside the same request. **Nothing in §1–§14 or §16–§19 changed.** No table, no
column, no service method signature, no CAS statement, no reconciliation phase,
no run fence, no compare-and-swap protection, and no idempotency guarantee moved
or was rewritten. Concretely:

- `SessionScheduleService::create()` (§3.8) and `SessionScheduleService::generate()`
  → `OccurrenceReconciler` (§7.6) are called exactly as they always were —
  `create_and_generate()` is a two-line caller, not a second implementation, and
  it never runs generation unless create() itself returned `STATUS_OK`.
- `SessionScheduleAdmin::handle_generate()`, the `admin_post_` retry handler, is
  unchanged; `create_and_generate()` reuses it by calling the same
  `SessionScheduleService::generate()` method, not a parallel one.
- The Commerce Schedules / "Manage Session Dates" screen (`SessionScheduleAdmin`)
  and its edit/reconciliation-backed forms are unchanged; it is only removed from
  the **rendered admin menu** via `remove_submenu_page()` after
  `add_submenu_page()` registers it — a presentation-only WordPress technique
  that leaves the page hook, its capability gate and its nonce-protected POST
  handler fully intact and directly reachable at its own URL. Hiding a menu
  entry is not an authorization change.
- The sales window changed from five to thirty calendar days; see the §15
  amendment note's own cross-references at §5.3, §9 and §14 above for the single
  constant (`SessionService::SALES_WINDOW_DAYS`) both consumers read, and BR-051/
  BR-063 in `AGENTS.md`, updated to match.
- A Portal-connected hall's `GameTypeService::create()` now refuses with
  `game_type_portal_managed` (§13's `GameTypeAdmin`/`GameTypeService` are
  otherwise unchanged); `GameTypeAdmin::render()` additionally hides the create
  form for such a hall, using the same `is_hall_portal_connected()` signal the
  service enforces with, so the UI and the enforcement cannot diverge. Rename,
  archive and restore are untouched for both Portal-connected and legacy halls.

The **approved manager flow**, replacing the Revision 1–7 list below it:

1. **Portal Sync.**
2. **Create Play Products** using synchronized Portal Game Types.
3. **Create Packages** from Play Products.
4. **Bingo Sessions → Sessions from Portal** (labelled "Synced Templates" before
   this revision; same read-only screen, same replica, same class) lists every
   Portal session. An eligible, current, active Portal session offers
   **Add to Store**. Draft, inactive or superseded Portal sessions stay visible
   with their status and offer no route to a schedule. A Portal session that
   already has one or more schedules additionally lists **Manage Dates** per
   schedule — more than one is expected and supported, there is no one-to-one
   assumption — and **Retry Creating Sessions** for any schedule whose create
   committed but whose definition is not yet active.
5. **Add to Store** opens the create form (§3.8's existing fields: Portal
   session name and hall timezone read-only; start time; explicit dates or
   weekly recurrence; only the controls belonging to the selected mode; one or
   more Packages) with the main action labelled **Create Sessions**.
6. **Create Sessions** runs §3.8 Create exactly as before. If the Portal session
   stopped being eligible between validation and commit, the save is refused and
   says so, and generation never runs. If creation commits, the SAME request
   immediately calls §7.6 generation once, using the id the create step just
   returned — the amendment note's `create_and_generate()`.
   - Both steps succeed: redirect (HTTP 303), a plain-language success message,
     and a link to Bingo Sessions.
   - Creation succeeds but generation does not fully complete: the schedule is
     **not** rolled back or hidden; the Manager sees "The schedule was saved,
     but sessions were not fully created," safe committed counts, and
     **Retry Creating Sessions**, which calls the same idempotent §7.6 path.
   - Creation itself fails: generation never runs; the existing field-level
     errors and safe recovery behaviour (§3.8, §8.5) are unchanged.
7. Generated sessions appear on **Bingo Sessions**, **OFF**. The Manager
   **turns ON only the sessions ready for customers**, through §6.1/§6.2 exactly
   as before; the first ON still permanently protects the occurrence from
   withdrawal. Manager-facing copy distinguishes *enabled by manager* from
   *available to customers today*, the latter being the §6.4 predicate under the
   thirty-day window.
8. Eligible sessions surface on the customer calendar in a later phase — still
   out of scope for this pass, exactly as in Revision 1–7.

To change a generated occurrence's date, time or name, the Manager still edits
the **schedule**, from its **Manage Dates** link, reached from Sessions from
Portal rather than a normal menu entry. To change a schedule's timezone, the
Manager still archives it and creates a replacement. Both go through the
unchanged §3.6/§3.8 edit and reconciliation services and their existing
compare-and-swap and run-fence protections — the amendment does not add a
second way to reach them.

Internal vocabulary — definition tokens, reconciliation phases, occurrences
versus templates versus replicas, manual "Generate," internal operation codes —
remains in the code exactly as described throughout this document. None of it is
manager-facing copy; §17's file responsibilities and §7's phase names are
unchanged by this note.

**Revision 1–7 workflow, superseded by the list above (kept for the internal
service sequence it still accurately describes — §3.8 Create and §7.6 Generate
are unchanged, only who calls Generate and when changed):**

1. **Portal Sync** pulls templates (existing, unchanged).
2. **Bingo Sessions → Synced Templates** lists the replica read-only. Draft and
   non-current templates are listed with their status and cannot start a
   schedule.
3. **Create schedule** from a commerce-eligible template. The form captures:
   minute-precision start time; timezone (defaulted from the hall setting,
   **fixed for the schedule's life**, stated on the form); mode; explicit dates or
   weekday mask plus bounds; and one or more Packages. Date controls state
   *tomorrow or later*.
4. **Save** runs §3.8 Create. If the template stopped being commerce-eligible
   between validation and commit, the save is refused and says so. A schedule that
   fails to commit is listed as *incomplete* and can be re-saved or archived.
5. **Edit** an explicit-date schedule later and the passed dates appear under
   *Past dates*, each keepable or droppable; dropping one changes no occurrence
   (§8.3). A successful edit also cleans the superseded definition's rows (§3.7).
6. **Generate** runs §7.6's phases and returns the §8.6 result. Withdrawals happen
   first, so tidying a schedule works even while a Package or the template is
   temporarily invalid. A preflight blocker aborts before any write.
   `schedule_state_changed` means someone edited the schedule mid-run — re-run.
   `template_renamed` means the Portal template was renamed mid-run — re-run.
   `database_contention` means the database was busy — re-run.
7. Occurrences appear **OFF** and, briefly, *incomplete* until their Packages
   land. An incomplete occurrence cannot be sold or hand-edited; re-running
   Generate converges it.
8. **Turn ON** per occurrence, through §6.1/§6.2. The first ON permanently
   protects the occurrence from withdrawal.
9. Eligible occurrences surface on the customer calendar in a later phase.

Under Revision 8, step 6 above ("Generate") is no longer a normal first-time
manager action — it now runs automatically, once, immediately after a
successful Create (see the amendment note). It remains available, relabelled
**Retry Creating Sessions**, as the recovery path for a schedule whose
generation did not fully complete, and as a general, idempotent, manually
triggerable re-run for any active schedule — both reachable from Manage Dates
and from Sessions from Portal.

---

### 16. Required Test Matrix

New harness `tests/session-schedules-increment-1.php`, following
`tests/session-increment-1.php:113-158`: disposable hall for destructive work;
persistent halls receive ordinary domain DML against tracked fixture rows only;
run-token registry; baseline preservation; unproven rows reported, never deleted.

**FIXTURE ISOLATION (Revision 7, implementation amendment).**

Every harness that can apply a Sessions migration now boots
`tests/fixtures/wordpress-bootstrap.php` and runs against an allowlisted,
sentinel-owned `BC_TEST_DB`, never the development installation. This is
required, not tidiness: `bingocommerce-sessions` is **network-active**, so every
`wp_insert_site()` fires its `wp_initialize_site` handler and applies the current
Sessions schema to whichever database is booted — and
`tests/session-increment-1.php`'s `ensure_persistent_schema()` migrates halls 2,
3 and 4 directly. Against the development database that would write v3 structure
into the persistent halls.

The change is **behaviour-preserving**: without `BC_TEST_DB` the bootstrap does
exactly what the old `require wp-load.php` line did, so previously recorded
results remain reproducible the same way.

- `tests/session-increment-1.php` and
  `tests/portal-session-template-replica-persistence.php` additionally **fail
  closed before WordPress boots** when `BC_TEST_DB` is unset, not allowlisted, or
  names the development database, and assert the selected database again
  afterwards.
- A worker MUST be routed with its parent: `proc_open()` inherits the
  environment, so a worker still bound to `wp-load.php` would race its parent
  across two different databases.
- Hall-specific fixture data the development installation has and a fresh
  fixture does not — Hall Manager and Game Operator accounts, Action Scheduler's
  per-site tables — is recreated inside the fixture. The capability row is
  planted directly, exactly as `tests/hall-staff-create-invite.php` already does,
  because ADR-025's `HallStaffWriterGuard` deliberately refuses
  `add_user_to_blog()` for a hall role. **No production class is copied or
  replaced**; the real plugin code under `wp-content/plugins` is what runs, and
  no assertion is weakened.
- Cleanup lists and drop allowlists in the affected harnesses gain the three new
  Sessions v3 tables.

This supersedes §17's earlier statement that only two harnesses change.

**Concurrency tests must use deterministic barriers or interceptors — a filter, a
lock file, or a coordinated worker blocking at a named point. No test may decide a
winner with `sleep()`, and no contention or process-death test may manufacture
timing by waiting.** `tests/play-product-row-lock-worker.php` is the model.

#### A. SQL correctness (1–7)
1. Create-commit contains `active_definition_token IS NULL` and no placeholder for it.
2. Edit-commit contains `active_definition_token = %s`.
3. `dates` statements contain literal `recurrence_start = NULL, recurrence_end = NULL`.
4. `weekly` statements bind validated non-null bounds.
5. No emitted statement passes PHP `null` to a `%s`, asserted by intercepting prepared SQL.
6. The provisional `INSERT` satisfies every non-null column, executed against the real table in strict mode.
7. **Every reconciliation, definition-commit and availability statement's final SQL is `EXPLAIN`-validated against the live server, and none triggers MySQL error 1093.**

#### B. Definition commit, template guard and dependency timing (8–19)
8. Two concurrent definition writes, barrier-released: different tokens, exactly one commit affects a row, the other returns `schedule_state_changed`.
9. Each editor's child rows carry only its own token; no set is merged.
10. After the winning commit, every header field and the token reflect the winner, from one row read.
11. **Create commit affects zero rows when the template is made non-current between validation and the CAS**; the schedule stays inert; the result says `template_not_commerce_eligible`, not `schedule_state_changed`.
12. **Create commit affects zero rows when the template is made `draft`** between validation and the CAS.
13. **Edit commit affects zero rows** under the same two template invalidations; the previous definition is intact and unchanged.
14. **Schedule restore affects zero rows** when the template is non-current or draft.
15. **Schedule archive succeeds** while the template is non-current — stopping commerce is never blocked.
16. A failed commit deletes only its own private token, and nothing when the re-read shows its token active.
17. **No staged-token cross-request deletion**: while editor B's token is staged, editor A's failed cleanup runs and B's rows still exist; B then commits.
18. A schedule with `active_definition_token IS NULL` generates nothing and is excluded from `isSellable()`.
19. **Dependency invalidation between validation and commit (§3.2).** A configured Package is made unassignable after request-time validation but before the CAS. The commit **succeeds** — this is expected and documented. The test then asserts the fail-closed consequences: Generate stops at the phase-2 gate with `eligibility_failed` and creates nothing; `setAvailableForSale(ON)` fails its pre-proof; and `isSellable()` returns a `WP_Error` or false for every occurrence of that schedule. **No claim of atomic Package validity at commit is made or tested.**

#### C. Superseded-definition cleanup (20–23)
20. **A successful edit deletes the superseded `$seen` token's date and Package rows**, and leaves the new token's rows and every other token's rows untouched.
21. **Cleanup affects zero rows if `$seen` is somehow active again**: the guard `sch.active_definition_token <> $seen` is proven by forcing that state before cleanup runs.
22. **Old-token reconciliation worker during cleanup**, barrier-released: a run that captured `$seen` is mid-phase when the edit commits and cleanup deletes `$seen`'s rows. The worker's next guarded mutation affects **zero rows**, its between-phase re-proof stops it with `schedule_state_changed`, and **no row is written using the deleted definition**.
23. Cleanup never deletes the current active token's rows, and never deletes another editor's staged token, asserted by enumerating rows per token before and after.

#### D. Template rename proof (24–27)
24. The name proof uses `CAST(… AS BINARY)`, asserted by intercepting the emitted SQL; the column-collation comparison is **not** used for rename detection.
25. **Rename immediately before a create INSERT** → the statement affects **zero rows**; no occurrence is created; the run stops with `failure_category = template_renamed`.
26. **Rename immediately before a retime UPDATE** → zero rows; the occurrence is unchanged; same failure category.
27. **Rename immediately before an unlocked restore UPDATE** → zero rows; the occurrence stays archived; same failure category.
28. **A case-only rename** (`Evening Bingo` → `evening bingo`) is **detected** — proving the binary comparison, since the column collation would call these equal.
29. **An accent-only rename** (`Evening Bingo` → `Évening Bingo`) is detected for the same reason.
30. **Locked restore, repair and withdrawal carry no name proof** and still succeed after a rename, because they write no `display_name`.
31. After a rename-failed run, a **retry** captures the new `$run_template_name`, the proof matches, and the run returns `status = ok`.

#### E. Guarded availability, ownership and races (32–42)
32. Every ON writer sets `sale_locked_at`, asserted by enumerating call sites and executing each.
33. First ON sets the lock; OFF does not clear it; a second ON does not change the stored timestamp.
34. **ON versus withdrawal**, barrier-released: ON cannot rescue a date already removed from the definition. The forced ON is refused and existing withdrawal may remove the unlocked row and its assignments. If removal/withdrawal occurs after ON prechecks but before its guarded UPDATE, ON fails. Sale-locked retention and ON-versus-retime protections remain unchanged; no serialized definition-removal contract is asserted.
35. **ON versus retime**, barrier-released: if ON wins, the retime affects zero rows, re-reads and classifies.
36. An occurrence with `generation_completed_at IS NULL` cannot be turned ON.
37. Schedule archived, or template invalidated, between pre-proof and the ON update → the ON affects zero rows and no token is written.
38. **Package invalidated between the ON update and the post-proof** → the occurrence is forced OFF **by its own `availability_operation_token`**, the result is a failure, and `sale_locked_at` **remains set**.
39. **Compensation cannot touch a row it does not own**: the token is changed between the ON update and the compensating UPDATE; compensation affects **zero rows** and the request writes nothing further.
40. **Three-request ownership test, barrier-released.** A turns ON (token Ta) and pauses before its failed post-proof. B turns it OFF. C turns it ON again (token Tc). A resumes and compensates. **Assert: the occurrence is still ON, still carries Tc, and A changed nothing.**
41. Successful settlement clears only the request's own token; if another request already took ownership the settle affects zero rows and that is **not** an error. Normal OFF clears the token and never clears the lock.
42. **Process death between the guarded ON and settlement (§6.5), deterministic kill.** Assert: the occurrence is ON, `sale_locked_at` is set, `availability_operation_token` is **non-NULL and unsettled**; `isSellable()` is unaffected by the token and still fails closed on its own facts; a subsequent OFF clears the token; a subsequent ON by another request replaces it; and the dead request's token can never match again.

#### F. Mutation ownership (43–50)

**Implementation status.** Items 43–45 and 49–50 are implemented and covered in
`tests/session-availability-increment-1.php`. `OccurrenceGenerationWriter`
exists, and items 46–48 are implemented and covered by
`tests/session-reconciliation-increment-1.php`'s
`generation_writer_unreachable_group()`.

43. Direct `rename()`, `reschedule()`, `updateDetails()`, `archive()`, `restore()` on a generated occurrence are refused **at the service layer**, called directly.
44. The same refusals hold through `SessionAdmin`'s POST handler.
45. `assignPackage()` / `removePackage()` refused while incomplete, allowed after completion.
46. **The internal generation writer is not reachable** from any admin screen, POST handler or REST route, asserted by enumeration.
47. The internal writer affects zero rows against a completed occurrence, a locked one, and an ON one.
48. The internal writer affects zero rows when the template is ineligible.
49. `setAvailableForSale()` on a generated occurrence routes through §6.1/§6.3.
50. Every writer behaves observably as at v2 for `schedule_id IS NULL`, against a captured baseline.

#### G. Incomplete generation — overlapping workers (51–60)
51. A Package write forced to fail after the first assignment leaves the occurrence incomplete with a partial set, `complete = false`, `status = failed`, and the run **stopped** (no later phase ran).
52. That occurrence is unsellable and rejects manual Package edits.
53. **Two repairs using the same token**, barrier-released: exactly one completion succeeds.
54. **Same-token convergence**: after both workers finish, the final assignment set is **exactly** the definition's and contains no duplicate rows.
55. **Old-token repair versus new-token repair**: the old-token worker's remove-extras, insert-missing and completion statements each affect **zero rows**; the new-token worker converges.
56. **Definition change between the final Package write and completion**: completion affects zero rows; the occurrence stays incomplete; the next run converges.
57. **Stale worker after another worker completed**: its insert-missing, remove-extras and completion statements each affect **zero rows**.
58. **Duplicate-key rule, expected state**: a concurrent worker inserts the same `(session_id, package_id)` first; the loser catches **1062**, re-reads under the same schedule id and run token, finds the expected state, treats it as satisfied and continues.
59. **Duplicate-key rule, unexpected state**: the re-read does not show that state; the run stops with `complete = false`, `status = failed`. **No `INSERT IGNORE`** appears in any emitted statement, and a non-duplicate error stops the run rather than being swallowed.
60. The exact-set completion statement refuses to complete when a required Package is **missing**, and when an **extra** Package is present — both proven by seeding each state directly.

#### H. Empty Package definition (61–63)
61. **Phase 2 fails** when the active definition has **zero** Package rows: `complete = false`, `status = failed`, `failure_category = eligibility_failed`, and no occurrence is created, repaired, retimed or restored.
62. **The completion statement affects zero rows** against a zero-Package definition, proven by seeding that state directly and bypassing the gate — demonstrating the SQL `EXISTS` is load-bearing and that both `NOT EXISTS` branches would otherwise pass vacuously.
63. **§7.12 proves non-emptiness before iterating** and returns `complete = false`, `status = failed` — never `ok` — for a zero-Package definition.

#### I. Withdrawal (64–69)
64. Withdrawal removes the occurrence and its Package rows in **one statement**; both are gone.
65. A forced failure of the withdrawal statement leaves **both** the occurrence and all its Package rows intact — **no undiscoverable residue is possible**.
66. Withdrawal of a **sale-locked** occurrence affects zero rows and deletes no Package row. **No path in the design hard-deletes a sale-locked occurrence**, asserted by enumerating every `DELETE` against `bingo_sessions`.
67. An occurrence with assignments but future, OFF and unlocked **is** withdrawn, with its assignments. An **incomplete** one outside the definition is withdrawn. An **archived** one is **not**, and is classified `retained_outside_pattern`.
68. **Withdrawal while one configured Package is unassignable** succeeds; the run then ends `complete = false`, `status = failed`, `failure_category = eligibility_failed`, with `withdrawn` counted accurately.
69. **Withdrawal while the template is non-current** behaves identically to test 68.

#### J. Database contention (70–72)
70. **Deadlock injection (1213)** on a reconciliation statement: the run stops, `complete = false`, `status = failed`, `failure_category = database_contention`; the reconciler re-reads authoritatively before reporting; earlier committed phases are counted accurately; no SQL or driver text appears in the result or the audit context. **No sleep is used.**
71. **Lock-wait timeout injection (1205)**: identical assertions.
72. A new run after either error converges from current state without duplicating or losing work.

#### K. Reconciliation classification and result status (73–84)
73. One deterministic assertion per §7.6 row 1–13.
74. **`complete = false` is never paired with `status = ok`** — asserted across every failure path in this matrix.
75. **`complete = true` implies `status = ok`** and a null `failure_category`.
76. `protected_time_conflict` aborts at preflight with **zero writes**, `failed`, `preflight_conflict`.
77. `incomplete_integrity_conflict` aborts at preflight with **zero writes** and the same status shape; no Package row is rewritten.
78. Historical retained classifications are informational and **do not** fail the run.
79. **An archived future incomplete occurrence in T is repaired in phase 3 and restored in phase 6 of the SAME run** — a single Generate leaves it active, complete and OFF.
80. Retime corrects in place: the `session_id` is unchanged.
81. Restore preserves Package assignments exactly; locked restore also preserves `sale_locked_at`, time and name.
82. **No past occurrence is created, restored, rewritten, retimed or deleted in any phase** — a byte-identical snapshot of every past row across a run that adds, removes and retimes future dates. Covers incomplete past rows.
83. A failed phase stops the run and no later phase executes — asserted by instrumenting phase entry.
84. **Phase order is withdrawal-first**, asserted by instrumenting phase entry order.

#### L. Dates, timezone, precision, naming (85–97)
85. Today rejected; tomorrow accepted — explicit dates and `recurrence_start`, in the schedule's timezone.
86. Editing a weekly schedule after its original start date succeeds; `recurrence_start` preserved; only tomorrow-onward emitted.
87. **Editing an explicit-date schedule after some dates became historical succeeds**; a previously committed historical date MAY be omitted, the edit commits, and **every historical occurrence is byte-identical afterwards**; it may also be kept, exempt from validation.
88. **No 400-date lifetime trap**: a schedule that has accumulated 400 committed dates, most now historical, can be edited to drop passed dates and add new future ones, and the edit commits.
89. Adding a future date succeeds; adding a historical date not previously committed is refused `historical_date_not_addable`.
90. **A Package-only edit succeeds** on an explicit-date schedule whose definition contains dates that have since passed.
91. **Minute precision**: `18:00:30` refused `invalid_start_time`; `8:00` refused; `18:00` accepted and stored `18:00:00`; two schedules at `18:00` and `18:01` produce different names.
92. **Structured-time integrity probe**: a generated occurrence seeded with `start_time_local IS NULL`, one with `timezone_name IS NULL`, one with `starts_at_utc IS NULL`, and one with non-zero seconds each **fail** `verify_current_site_schema()`; a legacy row with all four NULL **passes**.
93. Timezone immutability: no code path updates `timezone_name`; an edit attempt is refused; changing the site option leaves `starts_at_utc`, `start_time_local` and `timezone_name` byte-identical on every occurrence.
94. DST: `2026-03-08 02:30` → nonexistent; `2026-11-01 01:30` → ambiguous; `2026-03-07 18:00` → `2026-03-07 23:00:00`; `2026-03-08 18:00` → `2026-03-08 22:00:00`; a weekly schedule spanning both transitions yields the exact `starts_at_utc` for every date; `Australia/Sydney` asserted for one transition.
95. Generated eligibility and future/past decisions use the occurrence's stored `timezone_name`, proven with a hall whose site option names a different zone; legacy occurrences use `wp_timezone()` exactly as at v2, same fixture.
96. Naming: `<template> — HH:MM`; changing `time_format` changes no stored name; a 200-character template truncates to 183 and keeps the full suffix; truncation by characters, proven with a multi-byte name; `"Late Show 9:00 PM"` with `start_time_local = 18:00` generates `— 18:00`.
97. **Collation**: the test reads the live column collation via `SHOW FULL COLUMNS` and asserts **collision detection** behaves per that measured collation, while **rename detection** uses the binary comparison (test 24); case- and accent-differing template names on the same date and time are a preflight blocker; no hidden character or Portal ID is appended.

#### M. Migration, rollback, isolation, audit (98–108)
98. v2→v3 migration leaves every existing row byte-identical; all six structures and both integrity probes verify; the version persists only after all of them; the binary collation asserted on **every** identity column — `ascii_bin` on the three new tables', **`utf8mb4_bin`** on `bingo_sessions`'s `timezone_name` and `availability_operation_token` (§2.4); **seven** new `bingo_sessions` columns asserted.
99. `maybe_migrate_network()` and `maybe_migrate_current_site()` still work on their existing hooks and priorities; no migration UI and no `sessions.schema.*` audit action exists.
100. Portal synchronization **succeeds while the hall remains at Sessions v2**; a structurally damaged replica fails; a damaged `bingo_sessions` with intact replicas still synchronizes; `assert_snapshot_schemas()` issues no DDL.
101. **Rollback baseline commit (§12.3)**: the test asserts `git log --follow` over the Sessions `Migrations.php` yields exactly the two known commits; that `488c064dbaf4a0bfd4126e18afdd9de848ff86b5` carries `DB_VERSION = 2`; and that `git diff 488c064 <v3-parent> -- wp-content/plugins/bingocommerce-sessions/` is empty. **If any of these fails the baseline is stale and the test fails loudly rather than testing the wrong commit.**
102. **Rollback, frozen-source assertions (§12.3a)**: the v2 source at `488c064…` contains no `sale_locked_at`, no `generation_completed_at`, no `availability_operation_token` and no `schedule_id`. **No test forces `DB_VERSION` and calls it v2.**
103. **Rollback, seeded-state repair (§12.3b)**: seeded ON-without-lock, stale-token, drifted-name/date and incomplete-but-ON rows are handled by the forward repair pass — lock set, stale token cleared, drift reported without rewrite, integrity conflict flagged.
104. The stored version is never lowered by any code path.
105. Hall isolation: hall B cannot read, edit, archive, restore or generate from hall A's `schedule_id`; cannot use a `portal_session_id` absent from hall B's replica; a hall A `package_id` is not configurable on a hall B schedule; `request_int('-5')` yields `-5`.
106. Handler ordering: GET → 405, non-hall → 403, missing capability → 403, bad nonce → dies, in that order.
107. Audit: correct `target_type` / `target_id`; every payload and context key asserted against `SENSITIVE_KEY_TOKENS` and `SENSITIVE_KEY_NAMES`; generation context carries counts, `status` and `failure_category` only, and **never** `run_template_name`.
108. Compatibility regression: re-run `tests/session-increment-1.php` with only the `start_time` assertion amended; re-run `tests/portal-session-template-replica-persistence.php` and `tests/portal-snapshot-application.php` with **no assertion changes**, only cleanup-list extensions. Disposable-hall teardown drops all six tables; persistent-hall baselines byte-identical; role/capability snapshot unchanged.

---

#### N. Revision 9 membership regressions (109–117)

**Implementation status.** Items 109–117 are implemented in the current
repository. The focused coverage is in
`tests/session-availability-increment-1.php`'s
`removed_definition_group()`, with existing withdrawal/past-immutability
coverage in `tests/session-reconciliation-increment-1.php` and legacy behavior
coverage in `tests/session-increment-1.php`.

109. BR-077 retains inclusive `D - 30 <= local_today <= D`, ON, and every other
eligibility requirement; historical five-day references are not rewritten.
110. Generate two explicit dates, turn one ON, remove it and save, disable
sales, retry generation: the retained occurrence is absent from Upcoming, has
no Enable Sales action, and forced service/handler ON is refused.
111. Repeat membership exclusion for weekly bounds and weekday-mask changes,
using the Monday-zero bit mapping and inclusive endpoints.
112. `SessionEligibility` returns false for a removed date even with a stale ON
flag; unreadable membership fails closed.
113. All/history still reaches retained rows, with IDs, Packages and sale locks
preserved; already archived removed rows remain reachable under Archived.
114. Re-add the removed date and generate again: the preserved occurrence stays
OFF with the same ID and Packages. Removal while ON does not make re-add resume
sales, even without an intervening manual OFF.
115. Existing guarded withdrawal, archived restoration and past immutability
tests still pass; no new reconciliation concurrency or archival phase is added.
116. Legacy Sessions retain their existing availability and administration.
117. Definition edits retain their existing CAS behavior. No advisory-lock,
readiness, session-affinity or reconnect infrastructure is required or tested.

### 17. Implementation Files in the Current Repository

These production files implement the approved ADR. Production deployment is
not claimed.

**Production** (`wp-content/plugins/bingocommerce-sessions/src/`)

| File | Responsibility |
|---|---|
| `SessionScheduleService.php` | schedule CRUD, request-time validation, token generation, §3.5/§3.6 template-guarded CAS, §3.8 removed-future sales invalidation, §3.7 cleanup, audit |
| `SessionScheduleResult.php` | mirrors `SessionResult.php`; carries `status`, `complete`, `failure_category` and the §8.6 counts |
| `ScheduleTimezone.php` | named-IANA option capture, minute-precision validation, §5.2 exact-instant algorithm |
| `ScheduleExpansion.php` | pure date expansion, limits, tomorrow-or-later filtering, past-date retention rules (§8.3). No DB, no WP |
| `OccurrenceReconciler.php` | preflight, run fence incl. `$run_template_name`, §7.6 phases in withdrawal-first order, §7.12 final verification, counts |
| `OccurrenceGenerationWriter.php` | the internal, non-Manager Package writer (§7.10) and the convergent repair statements (§7.3), including the duplicate-key rule |
| `OccurrenceAvailability.php` | §6.1 guarded ON, §6.2 owned settlement and compensation, §6.3 OFF, §6.5 unsettled-token semantics, classification |
| `SessionEligibility.php` | §6.4 composed purchase predicate and centralized explicit/weekly committed-definition membership used by SQL guards, services and administration |
| `DatabaseContention.php` | 1213 / 1205 classification into the fixed `database_contention` category (§7.11) |
| `PortalTemplateGateway.php` | the only read path into the replica; fail-closed, mirroring `PackageGateway.php` |
| `SessionTemplateAdmin.php` | read-only "Sessions from Portal" screen (labelled "Synced Templates" before Revision 8; same class, same table, same read-only contract — §15 amendment note) |
| `SessionScheduleAdmin.php` | Schedules screen, past-date keep/drop editor, `admin_post_` generation handler |

Twelve files. `OccurrenceProtection.php` and `SessionSchemaAdmin.php` are **not**
part of this design.

**Production — modified**

| File | Change |
|---|---|
| `…/src/Migrations.php` | `DB_VERSION = 3`; three `CREATE TABLE`; **seven** `ADD COLUMN`; two indexes; three verifiers; extended `bingo_sessions` expectations; both §2.5 integrity probes. **No change to either migration hook or gate.** |
| `…/src/SessionService.php` | **seven** columns in `COLUMNS`, `map_row()`, `audit_payload()`; §9 refusals (see the correction note under §9 — not present in the first Increment 3 commit, added in the follow-up correction pass); completion gate in `assignPackage()` / `removePackage()`; `setAvailableForSale()` routed through `OccurrenceAvailability`; membership-aware ON, Upcoming and action data; per-row local-today selection; `evaluate_available_today()` **unchanged** |
| `…/src/SessionAdmin.php` | parent menu + two submenus; Time / Schedule / Source / Sale-locked / Generation columns; generated-row controls hidden, including Enable Sales for removed dates (presentation only) |
| `…/src/Plugin.php` | register the two new admin screens. **Existing migration hook registrations unchanged.** |
| `…/bingocommerce-sessions.php` | twelve `require_once` |
| `…/portal-integration/src/PortalSyncService.php` | `assert_domain_schemas()` → `assert_snapshot_schemas()` (§11.3) |

**Tests — new:** `tests/session-schedules-increment-1.php`,
`tests/session-schedule-commit-worker.php` (concurrent commit, template
invalidation, superseded-cleanup vs old-token worker),
`tests/session-occurrence-race-worker.php` (ON vs withdraw, ON vs retime, ON vs
invalidation, three-request ownership, **process-death between ON and
settlement**),
`tests/session-generation-repair-worker.php` (overlapping repair workers,
same-token and cross-token, duplicate-key and stale-writer cases).

**Revision 9 focused tests:** `tests/session-availability-increment-1.php`
(explicit/weekly membership, service and handler refusals, UI/Upcoming,
purchase eligibility, history and re-add OFF), and
`tests/session-reconciliation-increment-1.php` (E34 updated for membership).
Run these with `tests/session-schedules-increment-1.php`,
`tests/session-schedule-admin-increment-1.php` and `tests/session-increment-1.php`
against the existing isolated disposable fixture. No full matrix or mutation
campaign is part of this correction.

**Tests — modified:** `tests/session-increment-1.php` (the `start_time` assertion
at `:201`; disposable-hall table list and regex at `:1406-1424`),
`tests/portal-snapshot-application.php` (`domain_table_suffixes()` at
`:1719-1724`, cleanup regex at `:1793`),
`tests/portal-session-template-replica-persistence.php` (cleanup list only —
**the forbidden-column assertion stays exactly as it is**),
`tests/setup-readiness.php` (only if a schedules check is added).

**Docs — synchronized at ratification:** `AGENTS.md`, `PROJECT_STATUS.md`,
`docs/requirements/business-requirements.md`,
`docs/architecture/catalog-session-persistence-design.md`,
`docs/architecture/plugin-architecture.md`,
`docs/architecture/portal-integration-architecture.md`,
`docs/architecture/technical-architecture.md`,
`.cursor/rules/030-database.mdc`, `.cursor/rules/060-multisite-tenancy.mdc`,
and the ADR-043 Architecture Decision plus the active Still Open / TBD Items
section of `docs/decisions/DECISIONS.md`. Production deployment is not claimed.

---

### 18. Decisions Closed by This ADR

| Decision |
|---|
| **BR-077 ratifies the inclusive thirty-day sales window**, superseding only the historical numeric five-day width. |
| **BR-078 uses one current-definition membership rule** across guarded ON, purchase eligibility, Upcoming and admin controls; missing or unreadable membership fails closed. |
| **Definition removal does not require sales to be disabled first.** A successful edit clears sales on excluded future occurrences; re-add does not enable them. Existing reconciliation/history rules and schema remain unchanged. |
| **Create commit, edit commit and schedule restore prove template commerce-eligibility in SQL.** Archive does not — stopping commerce is never blocked. |
| **Package assignability is request-time validation, not an atomic commit guarantee.** The authoritative fail-closed checks are the phase-2 gate, occurrence ON, storefront eligibility and §7.12. |
| **Every statement that writes a `display_name` proves the template name is unchanged**, using `CAST(… AS BINARY)` — not the case- and accent-insensitive column collation. A rename affects zero rows and requires a retry. |
| **A zero-Package definition can never complete an occurrence or return `ok`**, proved at the phase-2 gate, in the completion SQL, and in §7.12 before iteration. |
| **Superseded definition rows are deleted after a successful edit**, guarded by SQL proving the token is no longer active. Only crash-abandoned private tokens remain for the deferred GC design. |
| **A non-NULL `availability_operation_token` means an unsettled ON transition**, which may outlive its process. Storefront eligibility ignores it and fails closed on its own facts; OFF clears it; no older request can compensate once ownership changes. |
| **An occurrence that has ever been offered for sale is never hard-deleted by any path**, including the integrity runbook. |
| **Every reconciliation write is fenced on `$run_definition_token`**, re-proved between phases and in §7.12. |
| **Withdrawal runs first and is the only eligibility-exempt reconciliation mutation**; a successful withdrawal is never rolled back or misreported. |
| **Withdrawal is one atomic multi-table DELETE.** No orphan state is reachable. |
| **Repair is convergent set reconciliation under token and template fences**, completing only on a database-proved exact, non-empty set match. |
| **Duplicate keys are handled explicitly**: catch 1062 only, re-read, continue only on the exact expected state; never `INSERT IGNORE`. |
| **ON claims an `availability_operation_token`; compensation is fenced on it**, so an older request can never turn OFF a newer successful ON. `updated_at` is not an ownership fence. |
| **Deadlock (1213) and lock-wait timeout (1205) stop the run** with `database_contention`, an authoritative re-read, and no in-run retry. |
| **`complete = false` is never paired with `status = ok`**; every failure carries a fixed category. |
| **An archived future incomplete occurrence is repaired and restored in the same run.** |
| **Timezone is immutable after schedule creation.** |
| **Passed dates may be dropped from a later definition without touching any occurrence**, so no 400-date lifetime trap exists. |
| **Time is minute-precise**, and a generated occurrence must carry complete structured time. |
| **Name, date, time and lifecycle of a generated occurrence are schedule-owned.** |
| **A writable v2 rollback is unsafe.** The verified baseline is `488c064dbaf4a0bfd4126e18afdd9de848ff86b5`, **not** HEAD. |
| **`EXPLAIN` proves syntax and plan only.** Every concurrency claim has a deterministic test. |
| **A schedule never selects a Game Type.** |

**The one ratified-test amendment this ADR requires** is a single assertion at
`tests/session-increment-1.php:201`, which currently forbids any `start_time`
substring in `bingo_sessions`. It may be amended only on these terms: the
replacement keeps an explicit comment scoping it to BR-073's supersession of
BR-050 for structured commerce time, and **the forbidden replica-column assertion
at `tests/portal-session-template-replica-persistence.php:342-350` remains
unchanged**.

---

### 19. Accepted Operational Dependencies

Neither is a design gap; both are recorded so nobody discovers them in
production.

#### 19.1 `incomplete_integrity_conflict` requires a Platform Admin runbook step

The state is unreachable through §6.1 and arises only from data damage or a
v2-rollback window (§12.2). Because it is a preflight blocker (§8.4), one such row
blocks further generation for that schedule, and **nothing in this phase clears it
automatically**.

**The runbook is non-destructive. It may not hard-delete the occurrence.**

1. **Force the occurrence OFF** through the ordinary §6.3 path. This clears any
   `availability_operation_token` and stops the occurrence being offered. It does
   **not** clear `sale_locked_at`, and must not.
2. **Investigate** why an incomplete occurrence was ever ON or sale-locked —
   almost always a rollback window or direct data manipulation.
3. **Resolve deliberately, one of two ways:**
   - **complete it** — repair its Package set to match the active definition and
     set `generation_completed_at`, after which it behaves as any generated
     occurrence; or
   - **archive it** — leaving the row, its `sale_locked_at`, its Package
     assignments and its full history intact and out of the pattern.
4. **The occurrence record and `sale_locked_at` are preserved in every path.**
   **An occurrence that has ever been offered for sale is never hard-deleted** —
   not by reconciliation (§7.2's `sale_locked_at IS NULL` guard), not by an
   administrator, and not by this runbook. Archiving is the terminal state for
   something that must stop being sold but must not stop existing.

A future increment may add a reviewed administrative action for steps 1 and 3;
this one does not.

#### 19.2 Crash-abandoned private definition tokens accumulate

§3.7 cleans superseded definitions after every successful edit and private tokens
after every failed one. What remains is the narrow case of a request that died
between staging its rows and running either cleanup. Volume is bounded by crash
frequency and each row is a handful of bytes. Garbage collection is deferred to
its own reviewed design (§0).

#### 19.3 Revision 9 introduces no infrastructure dependency

Membership is evaluated through ordinary prepared `$wpdb` statements against
the existing schedule, schedule-date and occurrence tables. No `GET_LOCK`,
`IS_USED_LOCK`, `RELEASE_LOCK`, readiness probe, physical-session-affinity check
or reconnect detection is required. Existing CAS, operation-token and run-fence
protections remain as described above. The earlier Revision 9 advisory-lock
protocol and its two allowed commit orders are superseded by BR-078's simpler
membership-based rule; they are not deployment requirements.

*Rationale:* separating Portal template identity, Commerce schedule definition and
dated occurrence into three tables keeps Portal operational fields from silently
becoming commerce rules, while an additive-only schema preserves every existing
row, index, writer and test. The correctness of the whole design rests on one
discipline: **no write is authorized by something the application read earlier** —
and where that is impossible, the ADR says so plainly instead of claiming an
atomicity it cannot deliver. Package assignability is PHP and cannot join a SQL
guard, so it is named as request-time validation and backed by four independent
fail-closed checks. Everything that *can* be proved in the statement is: the
definition token, the schedule's status, the template's eligibility, the template's
name byte for byte, the non-emptiness and exactness of the Package set, and the
ownership of an availability transition. A rename that would otherwise be stored
silently now stops the run; a zero-Package definition that would otherwise pass two
vacuous `NOT EXISTS` branches now cannot; a superseded definition no longer
accumulates; an ON transition whose process died is a described, harmless state
rather than an unexamined one; and an occurrence that was ever offered for sale is,
by construction, never deleted by anything.

- **ADR-044 — [2026-09-21] — Package Add-on Persistence, Concurrency, and Projection:**

  **Status and scope.** This decision approves the Package Add-ons Increment 1
  design governed by BR-079. It authorizes a future additive Catalog v5 -> v6
  migration, the fifth Catalog table `bingo_package_addons`, a narrow
  `PackageAddonService`, and one new fixed add-on profile inside the existing
  `CatalogTransactionTransport`. It does not claim implementation, migration,
  test execution, deployment, or production readiness.

  Increment 1 is administration and Catalog persistence only. It does not
  implement storefront selection, WooCommerce Product Add-ons/Product Bundles,
  cart or checkout enforcement, BR-042 electronic-limit enforcement,
  entitlement snapshots, QR issuance, Android redemption, BR-066, Package hard
  deletion, Portal synchronization, webhooks, or SSO.

  **Hall-local table and migration.** Catalog v6 adds exactly this per-site
  table, with the current site's trusted `$wpdb->prefix` as the tenant boundary
  and no redundant `blog_id`:

  ```sql
  CREATE TABLE {$wpdb->prefix}bingo_package_addons (
      package_addon_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      package_id bigint(20) unsigned NOT NULL,
      bingo_play_product_id bigint(20) unsigned NOT NULL,
      unit_price decimal(19,2) NOT NULL,
      max_quantity tinyint(3) unsigned NOT NULL DEFAULT 1,
      enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
      sort_order int(10) unsigned NOT NULL DEFAULT 0,
      created_at datetime NOT NULL,
      updated_at datetime NOT NULL,
      PRIMARY KEY  (package_addon_id),
      UNIQUE KEY package_play_product_unique (package_id,bingo_play_product_id),
      KEY package_enabled_sort (package_id,enabled,sort_order),
      KEY play_product (bingo_play_product_id)
  ) ENGINE=InnoDB ...;
  ```

  The exact schema uses indexed logical relationships and no physical foreign
  keys or cascades. `unit_price` is a canonical non-negative two-decimal string,
  never a PHP floating-point value. A Manager-submitted or service-supplied
  value is valid only when it is already canonical and contains exactly two
  fractional digits. `5.00`, `4.23`, `10.29` and `0.00` are valid. `5`, `5.0`,
  `5.5`, values with more than two fractional digits, exponent notation, signs,
  currency symbols, grouping commas, malformed decimals, noncanonical leading
  zeros, and values outside `DECIMAL(19,2)` are rejected. Manager/service input
  is never normalized, converted through floating point or rounded. Persisted
  values, displayed/submitted form values, no-op comparisons, audit values and
  fingerprint inputs use exactly two fractional digits. `max_quantity` is an integer from 1 through the
  BR-079 business maximum of 99. `enabled` is exactly 0 or 1. `sort_order` is
  written as 0 in Increment 1; listing is deterministic by `sort_order`, then
  `package_addon_id`, and no reorder UI exists.

  The installed WooCommerce lookup table uses its own `decimal(19,4)` price
  columns, but that projection schema is not the authority or precision rule for
  Package-specific add-on prices. ADR-044 deliberately uses `decimal(19,2)`.
  WordPress's installed `dbDelta()` parser accepts parenthesized column types,
  optional `unsigned` attributes and named primary/unique/key definitions of
  this form; the numeric defaults follow the existing Catalog DDL convention.
  The implementation still must prove the emitted SQL and
  resulting `SHOW FULL COLUMNS` / `SHOW INDEX` shape on the isolated fixture;
  this source review is not a migration run.

  Migration remains plugin-versioned and idempotent. Existing Halls add the
  empty table and advance `bc_catalog_db_version` only after all five Catalog
  tables verify. New Halls provision directly to v6. The existing network
  catch-up and current-Hall self-heal hooks remain; no metadata projection runs
  inside `dbDelta()` or the schema transaction. Tests and cleanup inventories
  must include the fifth Catalog table before any destructive migration test is
  permitted.

  **Authority, identity, and service contract.** `bingo_package_addons` is the
  sole authority for exact Package-specific eligibility, unit price, maximum
  quantity, and enabled state. `bc_package_relation` is a derived compatibility
  projection only. One Play Product may have different relationship state in
  many Packages, and the same Play Product may also be a base component of the
  same Package. Base quantity and selected add-on quantity are distinct facts.

  `PackageAddonService` exposes explicit operations rather than a generic
  mutation API: list one Package's relationships; create one relationship;
  update one relationship's price, maximum and enabled state; remove one
  relationship; and retry projection synchronization for one current-Hall
  Bingo Play Product. An identical create request against an already-existing
  identical pair is a verified no-op; an existing pair with different stored
  configuration is returned as a row-specific conflict rather than overwritten.
  Exact update no-ops likewise perform no DML, do not change `updated_at`, emit
  no audit event, and do not rewrite metadata.

  The public contract is deliberately small:

  - `listForPackage(int $package_id)` returns every enabled and disabled
    relationship plus its fingerprint in deterministic listing order, or a
    read/integrity error. An archived Package remains readable.
  - `create(int $package_id, int $bingo_play_product_id, string $unit_price,
    int $max_quantity = 1)` always requests the approved enabled default and
    returns `package_addon_created` or `package_addon_unchanged`.
  - `update(int $package_addon_id, string $expected_fingerprint, string
    $unit_price, int $max_quantity, bool $enabled)` returns
    `package_addon_updated` or `package_addon_unchanged`.
  - `remove(int $package_addon_id, string $expected_fingerprint)` returns
    `package_addon_removed`.
  - `reconcileProjection(int $bingo_play_product_id)` derives current expected
    metadata, internally resolves that row's linked current-Hall WooCommerce
    product, and returns `package_addon_projection_synchronized` or
    `package_addon_projection_unchanged`. It does not accept a WooCommerce
    product ID; a request-supplied WooCommerce product ID is never projection
    reconciliation authority.

  Focused validation/conflict codes are
  `package_addon_management_denied`, `invalid_package_addon_id`,
  `package_addon_not_found`, `package_addon_already_exists`,
  `package_addon_price_invalid`, `package_addon_max_quantity_invalid`,
  `package_addon_medium_incompatible`, `package_addon_state_inconsistent`, and
  `package_addon_stale`. Existing Package, Play Product, Hall/schema, database,
  transaction-conflict and `catalog_commit_outcome_unknown` codes remain in use
  rather than being aliased. An actual Play Product medium change blocked by a
  base-component reference, an enabled add-on reference, or both returns the
  existing `play_product_package_referenced` error code. Its structured details
  and Manager message identify which reference cause or causes blocked the
  transition; ADR-044 introduces no second overlapping medium-reference code.
  A successful mutation may carry existing
  `state_read_failed` or `audit_write_failed` warnings and the new
  `projection_reconciliation_required` warning; warnings never change the
  committed success status. A verified exact no-op is allowed because it
  performs no mutation, including against archived state. The service still
  verifies under lock that the Package, Play Product and relationship exist,
  belong together and contain valid state before returning the normal unchanged
  result. Missing or corrupt parent/relationship state fails closed. An archived
  Package remains read-only for actual mutations; for an archived Play Product,
  the only actual relationship mutations allowed are disable and remove. A
  no-op performs no DML, timestamp change, audit write or projection rewrite.

  Every service operation resolves the Hall only from the current WordPress
  site, authorizes with the existing Package-management pattern
  (`HallContext`, authenticated actor, and `bc_manage_products`, with native
  Super Admin operating only inside an explicitly selected Hall context), and
  requires the current Catalog schema. No request-supplied Hall/blog/site ID is
  accepted or trusted. Package, Play Product, relationship, WooCommerce product,
  cache, reconciliation and audit lookups remain current-site scoped. Every ID
  lookup uses only the verified current Hall's prefixed tables and APIs. An ID
  absent from the current Hall returns not found. If two Halls legitimately have
  the same numeric ID, a request in Hall A resolves only Hall A's entity and
  never reads, exposes, validates against, modifies or returns Hall B's entity.
  No request-supplied Hall/blog/site ID may change that namespace. Game Operator
  has no Package-management capability. Admin writes require
  both a nonce and the service capability check. Increment 1 introduces no REST
  endpoint and no object cache; any later cache key must include the blog ID.
  Services derive table names after resolving current Hall context on every
  operation and retain no static tenant row/table state across native My Sites
  switches. Any persisted warning, retry or reconciliation key must include the
  current blog ID and Play Product identity.

  **Lifecycle and compatibility.** Eligibility requires all four facts:

  ```text
  Package active
  AND Play Product active
  AND relationship enabled
  AND medium compatible with the Package type
  ```

  Paper Packages permit only Paper add-ons. Digital and Hybrid Packages permit
  Paper or Digital add-ons. Add-ons never change stored `package_type`, and a
  Paper Package never acquires QR behavior from an add-on. A Package may have
  zero add-ons.

  Archive preserves relationship rows, prices, maximums, sort order and enabled
  values. An archived Package makes every relationship read-only for actual
  mutations until restore. For an archived Play Product, only an explicit
  disable or remove is allowed as an actual mutation; create, enable, price
  change and maximum change are rejected. A verified unchanged request remains
  the no-op described above. Restore never creates, removes, enables or disables
  a relationship. A disabled relationship preserves its configuration. Its
  price and maximum may be edited only while both parent entities are active;
  enabling always revalidates medium and lifecycle. Missing or corrupt Package,
  Play Product, relationship, medium, lifecycle, price, maximum or enabled state
  fails closed and is not presented as an empty list or silently repaired.

  Disabling retains identity/configuration and excludes the relationship from
  eligibility and projection count. Remove is a separate explicit action that
  deletes that relationship only. Neither archive nor restore is an implicit
  disable, enable, remove, or create operation.

  **Fixed add-on mutation profile.** ADR-032, ADR-033 and ADR-034 are not
  repurposed. ADR-044 approves one separately named, fixed statement profile
  inside the existing `CatalogTransactionTransport` so it shares the captured
  connection validation, nested-use guard, poisoned-session state, conflict
  classification, rollback handling and ambiguous-COMMIT contract without
  introducing a second general transaction abstraction.

  Every add-on mutation is represented by one immutable plan carrying the
  server-derived current blog ID, target identities, requested canonical values,
  expected relationship fingerprint where applicable, and UTC timestamp. The
  transport accepts no callback and has no generic `atomic()` entry point.

  For create, the fixed order is:

  ```text
  preflight outside transaction: authorization, current-Hall schema, request
                                 shape and submitted canonical two-decimal price
  BEGIN
    1. lock bingo_packages(package_id) FOR UPDATE NOWAIT
    2. lock bingo_play_products(bingo_play_product_id) FOR UPDATE NOWAIT
    3. lock/probe the unique Package/Play-Product relationship key
    4. revalidate lifecycle, medium, price, maximum and duplicate/no-op state
    5. insert one relationship, or return a verified identical no-op
  COMMIT
  post-commit only: confirming read, projection, audit
  ```

  WooCommerce regular-price reading occurs only while preparing an eligible
  never-configured admin-form row. It is not part of
  `PackageAddonService::create()`. The create service receives the submitted
  canonical two-decimal string and never reads WooCommerce to derive, replace or
  repair that value. No WooCommerce API call runs in relationship-mutation
  preflight or inside the transaction.

  Update and remove use the same parent lock order. They pre-read only to build
  the plan, then lock Package, Play Product and relationship in that order,
  verify immutable parent IDs and the current fingerprint, revalidate permitted
  lifecycle transitions, and execute at most one relationship update or delete.
  Preflight state never authorizes the write.

  The relationship fingerprint is the 64-character lowercase hexadecimal
  SHA-256 digest of one versioned, injective ASCII serialization. Define
  `LP(value)` as the base-10 ASCII byte
  length of `value`, followed by `:`, followed by `value`. The payload is the
  concatenation, with no locale-sensitive formatting, of:

  ```text
  LP("bc-package-addon-fingerprint-v1")
  LP(package_addon_id as canonical base-10 integer)
  LP(package_id as canonical base-10 integer)
  LP(bingo_play_product_id as canonical base-10 integer)
  LP(unit_price as canonical two-decimal string)
  LP(max_quantity as canonical base-10 integer)
  LP(enabled as "0" or "1")
  ```

  Canonical base-10 integer strings contain digits only, no sign and no leading
  zero; the value zero is exactly `0`.
  The final opaque token is `hash('sha256', payload)`. The identical
  serialization is used when rendering the form and when rechecking locked
  state. PHP floating-point conversion, ambiguous delimiter concatenation,
  timestamps and `sort_order` are prohibited. `updated_at` is not a concurrency
  token because its one-second resolution cannot distinguish rapid edits. A
  stale fingerprint rejects the row; it never overwrites a newer committed edit.

  **Composition and medium-transition integrity.** ADR-044 extends the existing
  fixed Catalog profiles only where add-on integrity requires it:

  - An actual Play Product Paper/Digital transition locks the Play Product row
    first and performs a locking enabled-add-on-reference check in addition to
    ADR-034's component-reference check. Any enabled add-on reference blocks
    either direction. Disabled references do not block; later enable validates
    the then-current medium. Either reference source returns the existing
    `play_product_package_referenced` code with cause-identifying details.
  - Package composition replacement locks the Package row before reading its
    enabled relationships. It adds the enabled add-on Play Product IDs to the
    ascending Play Product lock set already needed by ADR-033, derives the new
    Package type from final base components only, and rejects the replacement if
    that type conflicts with any enabled add-on. A Play Product that is both a
    base component and add-on is locked once and retains separate quantities.
  - Every conforming add-on writer locks the Package before the Play Product.
    Package composition uses the same order. Medium transition holds only the
    Play Product before its fixed reference checks and never attempts a Package
    lock, so it cannot form a Package/Play-Product lock cycle.

  Package or Play Product archive may wait on these row locks and then preserve
  the relationship. If archive commits first, an operation requiring an active
  entity rejects under lock. If the add-on mutation commits first, archive may
  proceed afterward without detachment. These are valid serial outcomes.

  Recognized deadlock, lock-wait-timeout and NOWAIT conflicts use the existing
  bounded Catalog retry budget and re-read/revalidate on every attempt. Semantic
  failures and stale fingerprints are not retried. No WordPress/WooCommerce API,
  `$wpdb` statement, metadata write, AuditLog call, hook, filter, callback,
  localization, email, cache mutation or UI work may run inside the profile.
  Every SQL value is bound through native prepared `mysqli` statements; derived
  identifiers are fixed, validated current-site identifiers.

  If COMMIT acknowledgement is ambiguous, return
  `catalog_commit_outcome_unknown`. Do not infer success from current state,
  automatically retry, project metadata, or emit an audit event. ADR-025
  operation IDs, witnesses and mandatory-audit atomicity do not apply.

  **Audit and bulk behavior.** After a confirmed non-no-op commit, ordinary
  ADR-023 audit records the actor, current Hall, relationship target, Package,
  Play Product, and canonical before/after values. The target type is
  `bingo_package_addon`; actions are `catalog.package_addon.created`,
  `catalog.package_addon.updated`, and `catalog.package_addon.removed`.
  Enable/disable is an update whose before/after state remains explicit. Audit
  failure is an `audit_write_failed` warning on the already-committed Catalog
  result and cannot roll it back or change it into a reported failure.
  Projection retry repairs derived metadata and emits no separate
  domain-mutation audit event.

  The Optional Add-ons section is one form with one `Save Add-ons` action. An
  unchecked Play Product that has never been configured is ignored completely:
  it requires no price or maximum and produces no service mutation, validation
  error, relationship, audit event, projection write or timestamp update. Every
  submitted configured or selected row is orchestration over the same
  one-relationship service operation independently; no transaction may cover
  the whole form. The response preserves every successful row, returns one
  result for every submitted relationship row, and leaves each failed row
  visible with its own correction message. Partial success is permitted. Safe
  resubmission of an identical already-created row returns a no-op. There are no
  separate save modes or alternative workflows.

  **Projection and convergence.** After each confirmed relationship mutation,
  the service recounts all current authoritative enabled relationships for the
  affected current-Hall Play Product:

  ```text
  enabled count > 0  -> bc_package_relation = package_only_addon
  enabled count = 0  -> bc_package_relation = none
  ```

  Parent archive does not rewrite enabled flags, so it does not redefine this
  projection. Eligibility still reads active Package, active Play Product,
  enabled relationship and compatible medium from Catalog state.

  Projection occurs through WooCommerce product CRUD after the Catalog
  transaction, is read back and verified, then rechecks the authoritative count.
  A bounded convergence loop retries if a concurrent Catalog mutation changed
  the expected value. Failure or inability to verify adds a visible
  `projection_reconciliation_required` warning to the successful Catalog result;
  it cannot roll back or misreport that mutation. The Package edit screen offers
  a conditional Retry synchronization action only after such a warning or a
  detected mismatch. It does not expose an ordinary technical reconciliation
  dashboard. Expected projection is always re-derived from current Catalog rows,
  never from an old request payload or metadata.

  Increment 1 implements only this targeted Bingo-Play-Product-ID reconciliation.
  The bounded current-Hall-wide mismatch scanner is deferred until the later
  storefront/readiness phase. That deferral simplifies scope without changing
  authority: `bingo_package_addons` remains authoritative, stale metadata cannot
  change exact Package eligibility, and targeted repair remains available when
  the affected Bingo Play Product is known. The later scanner must remain
  current-Hall scoped and must not become a normal Hall Manager dashboard.
  Increment 1 introduces no queue, cron job, new table, WP-CLI command or
  background worker for reconciliation.

  **Administration.** The existing Edit Package screen gains one Optional
  Add-ons section. It uses a searchable/filterable current-Hall checkbox list,
  supports multiple selections, displays configured relationships first,
  including disabled relationships, and orders stored relationships by
  `sort_order`, then `package_addon_id`. A configured-but-disabled relationship
  remains visibly distinct from a never-configured Play Product with the simple
  status `Configured — disabled`.

  The section always shows every existing configured relationship for this
  Package, including disabled relationships and relationships whose Play Product
  is now archived, unresolved or medium-incompatible, so the Manager can review
  and, where lifecycle rules permit, disable or remove it. Such a relationship
  is never silently removed or hidden because its Play Product later became
  archived or incompatible. A Play Product with no existing relationship is
  shown as a candidate only when it belongs to the current Hall, is active, and
  is medium-compatible with the Package. Another Hall's Play Products are never
  exposed.

  | Existing state | Manager action | Result |
  |---|---|---|
  | Never configured | Leave unchecked | No relationship exists |
  | Never configured | Check | Create an enabled relationship |
  | Configured and enabled | Leave checked | Remains enabled; exact unchanged submission is a no-op |
  | Configured and enabled | Uncheck | Disable while preserving price and maximum |
  | Configured and disabled | Leave unchecked | Remains configured and disabled |
  | Configured and disabled | Check | Re-enable after lifecycle and medium validation |
  | Configured relationship | Explicit Remove | Permanently remove that relationship after confirmation |

  Each configured row shows the Play Product, saved Package-specific unit price,
  maximum quantity and enabled state. Existing rows never refresh their price
  from WooCommerce. While preparing the admin form after WooCommerce
  initialization and outside the add-on transaction, an eligible
  never-configured row may load its linked product with
  `\wc_get_product( $wc_product_id )`, require that the result is an instance of
  `\WC_Product`, and read `$product->get_regular_price( 'edit' )`. The return is
  handled as a string and never converted to floating point. The implementation
  must not use `get_price()`, a sale price, direct `_regular_price` post meta,
  formatted HTML price, a tax-adjusted display price, or a filtered `view`
  value. `5` may only be padded to `5.00`, `5.5` may only be padded to `5.50`,
  and an already-two-decimal value remains unchanged. This string padding is
  permitted only during UI form preparation and never makes loose Manager or
  service input acceptable. The displayed and submitted field value contains
  exactly two decimal digits. Blank, negative, exponent-form, signed, malformed,
  noncanonical-leading-zero, overflowing or greater-than-two-decimal values do
  not prefill and are never rounded; the field remains blank for Manager
  correction if that row is selected. Prefill is UI convenience only and
  persists nothing before submission. Saving creates the independent
  relationship price, and later WooCommerce regular- or sale-price changes
  never overwrite it. Maximum defaults to 1.

  The section is one form with one `Save Add-ons` action. Unchecking a configured
  row is the reversible disable action and preserves configuration; an unchecked
  never-configured row is ignored completely and requires neither price nor
  maximum. Permanent Remove is a separate row operation guarded only by a simple
  browser confirmation: cancelling sends no request and performs no mutation;
  confirming submits that row's remove operation. No custom modal, framework or
  separate confirmation screen is introduced.

  This single Edit Package screen is the complete Manager workflow. It has no
  Hall selector, Package-type editor, modal, wizard, drag-and-drop, separate
  add-on dashboard, or ordinary reconciliation control. Reads fail visibly; a
  database error is never rendered as an empty add-on list. Archived, unresolved
  and inconsistent states show explicit messages and only the lifecycle actions
  permitted above. UI filtering is presentation only; every submitted identity
  and value is independently validated by the service.

  **Implementation and verification boundary.** Expected production candidates
  are `wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php` for
  class loading; `src/Migrations.php`; `src/CatalogTransactionTransport.php`;
  `src/PackageService.php` for composition/add-on compatibility;
  `src/BingoPlayProductService.php` for medium-reference integrity;
  `src/PackageAdmin.php`; and new focused `src/PackageAddonService.php`,
  `src/PackageAddonResult.php` and `src/CatalogPackageAddonPlan.php` classes.
  `src/Plugin.php` must be reviewed but changes only if registration or
  admin-asset hooks cannot remain owned by `PackageAdmin`. A small enqueued admin
  script is permitted for local filtering; no framework or third-party plugin is
  added.

  Expected verification candidates are a new
  `tests/package-addons-increment-1.php` suite and a narrow add-on concurrency
  worker; focused updates to `tests/package-increment-1-stage-a.php`,
  `tests/package-increment-1-stage-b.php`,
  `tests/package-reference-fixture-worker.php`,
  `tests/play-products-increment-1.php`, and
  `tests/play-product-row-lock-worker.php`; plus inventory/cleanup-only changes
  where required in `tests/game-types-increment-1.php`,
  `tests/session-increment-1.php`,
  `tests/portal-session-template-replica-persistence.php`, and
  `tests/portal-snapshot-application.php`. Other test files found by a literal
  table-name search are review candidates, not automatic edit targets.

  These tests must update every Catalog version/table-inventory assertion,
  persistent-Hall preservation snapshot, disposable-Hall table list, cleanup
  regex and ownership guard that assumes four Catalog tables. They cover
  migration, service, lifecycle, authorization, nonce, tenant isolation, stale
  edits, races, ambiguous COMMIT, audit, projection failure/convergence, bulk
  partial success, no-ops, and fixture-safe cleanup. The fixture bootstraps
  `tests/fixtures/isolated-multisite.php` and
  `tests/fixtures/wordpress-bootstrap.php` are reviewed and changed only if
  their actual table inventory requires it; their allowlist, sentinel,
  no-development-fallback and positive cleanup ownership rules remain
  mandatory. `.cursor/rules/020-woocommerce.mdc`,
  `.cursor/rules/030-database.mdc`, and
  `.cursor/rules/060-multisite-tenancy.mdc` are binding implementation inputs,
  not reasons for unrelated rule churn.

  The automated matrix must prove:

  - Catalog v6 schema shape, malformed-shape rejection, existing-site upgrade,
    idempotent rerun, new-Hall provisioning and version advance only after all
    five Catalog tables verify;
  - create, update, enable, disable and remove; duplicate-pair conflict;
    identical resubmission/no-op; canonical price acceptance/rejection; maximum
    default and 1/99 boundaries; corrupt stored-state failure;
  - every Package/Play Product lifecycle combination, permitted archived-Play-
    Product disable/remove, restore preservation, medium compatibility and both
    directions of medium/composition transition races;
  - one Play Product across several Packages, one Play Product as both base and
    add-on, removing/disabling one relationship while another remains, and zero
    enabled relationships;
  - projection write/readback failure, bounded convergence and conditional
    retry, while proving the Catalog mutation remains successful;
  - timestamps, projection and audit remain untouched on exact no-op; successful
    writes record ordinary audit; audit failure is only a warning;
  - Hall Manager and Hall-context success, nonce rejection, capability and Game
    Operator denial and Super Admin without Hall-context denial; an ID present
    only in Hall B returns not found from Hall A; the same numeric ID present in
    both Halls resolves only Hall A's row from Hall A while Hall B remains byte-
    identical; and repeated multi-Hall switching does not leak state;
  - stale fingerprints, bounded lock-conflict retries, parent archive races,
    ambiguous COMMIT, bulk partial success, persistent-Hall before/after
    preservation, and ownership-proven disposable-Hall cleanup.

  Manual verification must exercise one Paper, Digital and Hybrid Package in two
  Halls; multiple selection and configured-relationships-first filtering,
  including disabled relationships; WooCommerce regular-price prefill followed
  by independent two-decimal editing; never-configured versus
  configured-disabled display; disable-preserve and confirmed explicit remove;
  archived/inconsistent messages; role/nonce denials; a forced projection
  warning and conditional Retry synchronization; and confirmation that no Hall
  selector, Package-type editor, drag/drop, storefront selector or ordinary
  reconciliation control appears.

  *Rationale:* one Hall-local relationship row is the smallest authority that
  can express Package-specific eligibility, price, quantity and state. The new
  fixed transport profile protects that row against parent lifecycle, medium
  and Package-type races without broadening the transaction API. Keeping audit
  and WooCommerce projection after confirmed commit preserves the authoritative
  Catalog result while making secondary failures visible and safely repairable.

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

- **BR-076 — [2026-09-12] — Automatic Portal-Controlled Commerce Enablement
  and Suspension:**

  Portal Global Admin Enable Ecommerce / Disable Ecommerce MUST automatically
  provision, suspend, and re-enable a Hall’s BingoCommerce site under ADR-042.
  Ordinary Portal Hall Settings authority does not grant this permission.
  WordPress Platform Admin / Network Super Admin do not initiate automatic
  Enable/Disable; they retain explicit legacy backfill and ownership-unproven
  orphan recovery only. BingoCommerce owns address availability and the
  authoritative site URL. Disable means suspend with full data preservation,
  not delete or archive. Re-enable reuses the same site, mapping, domain, and
  data. Until a separately reviewed BR-016 billing resolver exists,
  `effective_access` is billing-neutral (`operational` → `allowed`;
  `provisioning`/`suspended` → `portal_blocked`); provisioning MUST NOT invent
  billing status. After that resolver exists, Portal re-enable still cannot
  erase or bypass authoritative billing state (`billing_restricted` on HTTP 200
  success must not become a failed/retryable 409). The first automatic-
  provisioning MVP creates an empty ready store (no starter Product / Play
  Product copy).
  Manual Hall-scoped synchronization remains the sync model for this MVP. SSO,
  webhooks, scheduling, and reconciliation remain later work.

  Relationship to prior rules: supersedes only BR-074 / ADR-035’s requirement
  that webhooks and reconciliation precede tenant provisioning; narrows
  ADR-027 / BR-047 for this MVP’s empty-store scope; extends BR-075 with the
  concrete lifecycle. ADR-035 through ADR-041 authority boundaries and v1
  exclusions are unchanged. Implementation is authorized by ADR-042 and is
  not claimed complete by this rule alone.

- **BR-077 — [2026-09-18] — Thirty-Day Session Sales Window:**

  For Session calendar date `D`, storefront date eligibility is inclusive:

  ```
  D - 30 calendar days <= hall_local_today <= D
  ```

  `Available for Sale = ON` is still required. `hall_local_today` remains
  calculated using the hall site's configured WordPress timezone, and every
  other Session-eligibility rule remains unchanged.

  Relationship to prior rules: supersedes **only** the numeric five-day window
  in BR-051 and BR-063. References to a five-day sales window in ADR-035,
  BR-071 and BR-073 are superseded by this rule. Their historical entries are
  preserved and are not rewritten.

- **BR-078 — [2026-09-18] — Removed Schedule Date Safety and Retention:**

  This rule applies to **strictly-future generated occurrences**. Past-
  occurrence immutability remains unchanged.

  A generated occurrence may be turned Available for Sale ON only while its
  date belongs to its schedule's **current committed definition**. Explicit
  membership uses the active-token date rows; weekly membership uses the
  committed inclusive bounds and weekday mask.

  Removing a date is allowed even while its occurrence is ON. Membership
  immediately makes that occurrence unavailable to purchase. A successful
  definition edit also turns excluded strictly-future occurrences OFF, so
  re-adding a date does not automatically resume sales.

  An occurrence whose date has been removed from the current committed
  definition MUST NOT appear under Upcoming, MUST NOT offer Enable Sales, and
  MUST reject forced ON requests. Purchase eligibility independently fails
  closed, including when a stored availability flag is still ON. Retained
  records remain reachable through All, or Archived when already archived.

  Existing reconciliation and history rules remain in force: future OFF
  unlocked occurrences may be withdrawn; sale-locked occurrences are never
  hard-deleted and retain their ID, Package assignments, `sale_locked_at`, and
  history. No new automatic archival path is required. Re-adding a retained
  date preserves its identity and Packages; existing archived restoration
  remains OFF, and generation never turns sales ON.

  One centralized membership rule is shared by services, SQL guards and UI.
  No advisory lock, readiness probe, connection-affinity requirement, new
  infrastructure or schema is required. Definition removal is not serialized
  with ON, and the former disable-first/two-commit-order contract is withdrawn.

  Relationship to prior rules: additive to BR-073's schedule and exact-dated-
  occurrence authority; adds membership to availability and presentation while
  preserving existing withdrawal rules. BR-056 late-redemption behavior, order history, Package
  assignment history, and all past-occurrence immutability rules remain
  unchanged.

- **BR-079 — [2026-09-21] — Package Add-on Configuration, Compatibility, and Lifecycle:**

  Each Bingo Package independently controls which current-Hall Bingo Play
  Products are offered as optional add-ons. A Package may have zero add-ons.
  The same Play Product may be used by multiple Packages with different
  Package-specific unit prices, maximum quantities and enabled states. The same
  Play Product may also be both a base component and an optional add-on in one
  Package; base quantity and selected add-on quantity remain separate.

  Medium compatibility is:

  - Paper Package: Paper add-ons only.
  - Digital Package: Paper or Digital add-ons.
  - Hybrid Package: Paper or Digital add-ons.

  Add-ons never change stored Package type. A Paper Package never gains QR
  behavior from an add-on. Later entitlement work may count an eligible selected
  Digital add-on only for a Digital or Hybrid Package; that later calculation is
  not implemented by Package Add-ons Increment 1.

  Each Package/Play Product relationship owns its unit price. The linked Play
  Product's WooCommerce regular price may be read only while preparing an
  eligible never-configured row after WooCommerce initialization and outside the
  add-on transaction. The UI loads `\wc_get_product( $wc_product_id )`, requires
  a `\WC_Product`, and treats
  `$product->get_regular_price( 'edit' )` as the editable starting string. It
  never uses `get_price()`, sale price, direct `_regular_price` post meta,
  formatted HTML, tax-adjusted display price, filtered `view` context or a float.
  During UI form preparation only, a returned `5` may be padded without rounding
  to `5.00` and `5.5` to `5.50`; an already-two-decimal value remains unchanged.
  Invalid values or values requiring rounding leave the field blank. The
  displayed and submitted field value contains exactly two decimal digits.
  Prefill persists nothing before selection and submission. Saving creates an
  independent canonical non-negative `DECIMAL(19,2)` value displayed and stored
  with exactly two decimal places. Manager-submitted and service-supplied values
  must already be canonical: `5.00`, `4.23`, `10.29` and `0.00` are valid;
  `5`, `5.0`, `5.5`, excess fractional precision, exponent notation, signs,
  currency symbols, grouping commas, malformed values, noncanonical leading
  zeros and overflow are rejected. They are never normalized, converted through
  floating point or rounded. This strict submitted-value rule is separate from
  UI-only WooCommerce prefill padding. A missing or invalid WooCommerce regular price
  leaves the field blank for Manager correction if selected. Existing
  relationships always display their saved relationship price. Later
  WooCommerce regular- or sale-price changes never overwrite or refresh it.

  `max_quantity` defaults to 1. Its business maximum is 99. Every configured
  maximum and every later selected quantity is a whole number satisfying:

  ```text
  1 <= quantity <= max_quantity <= 99
  ```

  A new relationship defaults to enabled. Disabling preserves unit price and
  maximum quantity. While both Package and Play Product are active, those values
  may be edited while disabled. An exact no-op changes no timestamp, writes no
  audit event and rewrites no projection metadata.

  Add-on eligibility requires an active Package, active Play Product, enabled
  relationship and medium compatibility. Archiving either parent preserves the
  relationship row and enabled value. An archived Package makes its add-ons
  read-only for actual mutations until restore. For an archived Play Product,
  only explicit disable or remove is permitted as an actual relationship
  mutation. After parent and relationship existence/integrity are verified, an
  exact unchanged submission against archived state may return the normal
  no-op result. It performs no DML, timestamp change, audit write or projection
  rewrite. Restore never creates, removes, enables or disables a relationship.

  An actual Play Product medium transition in either direction is blocked while
  any enabled add-on relationship references it. Disabled relationships do not
  block the transition; later enabling must revalidate compatibility. Package
  composition replacement must reject a resulting base-derived Package type
  that conflicts with any enabled add-on. Add-ons still never participate in
  deriving stored Package type. A medium change blocked by a base component, an
  enabled add-on, or both returns the existing
  `play_product_package_referenced` code with details identifying the cause; no
  overlapping add-on-specific medium-reference code is introduced.

  The existing Edit Package screen contains one Optional Add-ons section with a
  searchable/filterable current-Hall checkbox list and multiple selection. It
  displays configured relationships first, including disabled relationships;
  a configured-but-disabled row remains visibly distinct from a never-configured
  Play Product and shows `Configured — disabled`. Every configured relationship
  remains visible even when its Play Product is archived, unresolved or now
  incompatible, so it can be reviewed and, where allowed, disabled or removed;
  it is never silently hidden or detached. Products without a relationship are
  candidates only when they are active, medium-compatible current-Hall Play
  Products, and another Hall's products are never exposed. Checking a
  never-configured product creates an enabled relationship; leaving it unchecked
  creates none.
  Leaving a configured enabled row checked is unchanged; unchecking disables
  while preserving price and maximum. Leaving a configured disabled row
  unchecked preserves it; checking re-enables after lifecycle and medium
  validation. A small explicit Remove action uses simple browser confirmation:
  cancel sends no request or mutation, while confirm submits that row's remove
  operation. No custom modal, framework or confirmation screen is added. This
  single Edit Package screen is the complete Manager workflow; it has no
  separate dashboard, modal, wizard, Hall selector, Package-type editor,
  drag-and-drop, or ordinary technical
  reconciliation control. A failed projection displays a simple warning and a
  conditional Retry synchronization action.

  The section is one Add-ons form with one `Save Add-ons` action. An unchecked
  never-configured row is ignored completely: it requires no price or maximum
  and creates no validation error, relationship, audit event, projection or
  timestamp change. Each submitted configured or selected row uses the same safe
  one-relationship service operation. It does not create one transaction across
  all selected products. Partial success is permitted: every submitted row
  returns a result, successful rows remain committed, and each failed row remains
  visible with its own correction message. No separate save mode or alternative
  workflow exists. Safe resubmission of an identical already-created row is a
  no-op.

  `bingo_package_addons` remains the Hall-local authority. Listing is
  deterministic by `sort_order`, then `package_addon_id`.
  `bc_package_relation` remains derived metadata and is recalculated from all
  enabled relationships for the Play Product. Projection failure cannot roll
  back or misreport an already-committed Catalog mutation. Targeted retry
  accepts the current Hall's Bingo Play Product ID and internally resolves its
  linked WooCommerce product; it never trusts a request-supplied WooCommerce
  product ID. A bounded Hall-wide mismatch scanner is deferred until the later
  storefront/readiness phase and must not become a normal Manager dashboard.

  Relationship to prior rules: additive to BR-039, BR-040, BR-049, BR-061,
  BR-062, BR-065, BR-068 and BR-070; extends BR-069's medium-integrity
  restriction to enabled add-on references and BR-070's Package archive/restore
  preservation semantics to add-on relationships. It does not change BR-066 or
  authorize storefront, cart, checkout, entitlement, QR, Android, hard-delete
  or Portal behavior.


---

## Still Open / TBD Items (Unresolved)

- ADR-044 Slice 1 implements only Catalog v6's `bingo_package_addons` migration,
  schema verification and isolated-fixture tests. `PackageAddonService`,
  administration, transaction-profile extension, lifecycle/medium extensions,
  projection synchronization and add-on audit remain unimplemented. ADR-044
  and BR-079 business decisions are unchanged; no real Hall migration,
  deployment or production readiness is claimed.
- Portal integration details still open after ADR-036 through ADR-042: webhook
  signing, retry, replay and ordering; transitional synchronization conflict
  policy; and SSO protocol/account/tenant mapping. Commerce-schedule recurrence,
  timezone/DST, regeneration and occurrence reconciliation are specified by
  approved ADR-043 and implemented in the current repository; they
  are not deployed. ADR-036 fixed the Hall
  snapshot transport URL. ADR-037 froze the Hall snapshot v1 validation
  contract. ADR-038 freezes completeness and zero-write rejection of missing
  linked Game Types. ADR-039’s Catalog v5 name/index, imported-code design,
  name-validation amendment, and allowlisted index-removal exception are
  implemented and tested. ADR-040’s Phase 3E snapshot application is COMPLETE.
  ADR-041’s Phase 3G manual Hall-scoped synchronization is COMPLETE. ADR-042 /
  BR-076 decide automatic Commerce enable/suspend provisioning. The
  BingoCommerce machine endpoint, operations table, orchestrator,
  `bingocommerce-platform` provisioning filter and automatic site-creation path
  are implemented and tested in the repository. The Portal Global Admin
  Enable, Suspend, Re-enable and Retry Ecommerce controls are implemented and
  tested. Hall Owner Ecommerce access is read-only; assigned Hall Managers
  cannot see the Ecommerce section or store URL; mutation remains Global
  Admin-only. A user-performed local-development Open Store → Suspend →
  Re-enable workflow passed against one mapped WordPress multisite test
  site, and re-enable reused the same site. Production deployment is not claimed.
- ADR-040 resolves the former pre-Phase 3E design work: snapshot
  replay/stale-order protection, Phase 3E transaction and locking mechanics, and
  Session-template application behavior are decided and implemented. Game Type
  `portal_is_current` / `portal_not_current_at` fields are still not required
  for v1; missing linked Game Types remain integrity violations, not currentness
  changes. ADR-040's real Portal test-environment run PASSED against a local
  Portal test environment (Portal Hall 2 -> site 2; 7 Game Types and 26 Session
  templates imported, second run a domain no-op), so Phase 3E is COMPLETE.
  The separate multi-Hall tenant-isolation proof — Phase 3F — also PASSED and is
  COMPLETE (387/387 assertions). Production deployment is not claimed. Phase 3G
  is COMPLETE (ADR-041). The BingoCommerce-side ADR-042 machine path is
  implemented and tested. The Portal Global Admin Enable/Suspend/Re-enable/Retry
  controls are implemented and tested; a user-performed local-development
  Open Store → Suspend → Re-enable workflow passed against one mapped
  WordPress multisite test site, and re-enable reused the same site.
  Local writer restrictions
  after synchronization stay deliberately transitional until reconciliation
  and recovery are proven. Webhooks remain deferred and unimplemented.
  Commerce scheduling and occurrence reconciliation exist in the current
  repository under approved ADR-043 and are not deployed. SSO
  remains later.
- ADR-043 is approved and implemented; the implementation was committed in
  262b250. Production deployment is not claimed.
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
