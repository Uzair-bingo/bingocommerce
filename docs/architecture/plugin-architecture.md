## 1. Plugin Suite

Small, single-responsibility plugins — never one monolith. Each must be
independently activatable and testable.

| Plugin | Responsibility | Depends on |
|---|---|---|
| `bingocommerce-core` | Roles & capabilities, tenant context helpers, audit log service, migration framework | WooCommerce (network) |
| `bingocommerce-catalog` | Hall-scoped Game Type replica/domain behavior, Bingo Play Product domain metadata/mapping, Bingo Package definitions/components/add-on eligibility, BR-032/BR-065 metadata projections, hall electronic-limit override/current-value resolver, starter-product domain logic when that deferred copy exists (ADR-026/ADR-031/ADR-035; ADR-042 MVP runs no starter copy) | core |
| `bingocommerce-sessions` | Current calendar-dated Session/occurrence behavior, inclusive hall-local 5-day sales window, Available for Sale, Package assignment, and customer occurrence-before-Package selection; portal-integrated schedule/occurrence refactor pending compatibility review (BR-050–BR-052/BR-063/BR-073) | core, catalog |
| `bingocommerce-digital-tickets` | Purchase-time entitlement snapshot (activated on successful online payment confirmation — BR-053), QR issue/redeem, device binding, staff reactivation, Android REST API, redemption accumulation | core, catalog, sessions |
| `bingocommerce-portal-integration` | Portal-facing machine endpoint; request authentication/validation; provisioning-operation persistence/sequencing; Portal Hall mapping coordination; HallCommerceState/managed-marker coordination; Portal snapshot synchronization; safe API responses and integration audit attribution (ADR-035–ADR-042) | core |
| `bingocommerce-platform` | Actual WordPress Multisite site creation; WooCommerce/site initialization; triggering existing per-site BingoCommerce migrations and role provisioning; returning a structured setup result to the integration orchestrator; billing lifecycle (BR-016); retention/deletion (BR-017); network reporting. Does not own Catalog domain rules. ADR-042 MVP does not trigger starter Product/Play Product copy | core |

Theme: Phase 1 standard storefront in `wp-content/themes/bingocommerce-*`.
Presentation only — zero business logic (AGENTS.md rule 2).

Plugin boundaries are locked by ADR-026, narrowed by ADR-035, and extended for
automatic Commerce enable/suspend by ADR-042:
`bingocommerce-core` stays foundational/shared infrastructure only — Catalog
domain behavior must not be added there. `bingocommerce-sessions` owns Session,
never Catalog entities. `bingocommerce-platform` does not own Catalog domain
rules; under ADR-042 it performs Multisite/WooCommerce/site initialization and
returns a structured setup result to `bingocommerce-portal-integration`. The
ADR-042 MVP does not run starter-product copy; that Catalog-owned mechanism
remains separately deferred. Portal Game Type identity/configuration is
external authority; the Catalog plugin retains the hall-scoped local replica
and its commerce relationships. Webhook/reconciliation orchestration remains
deferred and must not be inferred here.

ADR-031 assigns the five per-site Catalog tables and their migrations to
`bingocommerce-catalog`, and the two per-site Session tables and their
migrations to `bingocommerce-sessions`. `bingocommerce-platform` owns Platform
Admin mutation/initialization of the network electronic-maximum option;
`bingocommerce-catalog` owns the optional hall site option and resolves the
effective current value.

ADR-035 does not authorize a migration. The implemented Game Type and Session
schemas remain in place until a schema-specific compatibility/refactoring review
defines Portal-ID linkage and the Portal-template / commerce-schedule / dated-
occurrence model. Existing Package and Play Product ownership is unchanged.

## 1a. Portal Integration Boundary (approved; automatic provisioning not implemented)

- Portal authority: Hall identity, Game Type identity/configuration,
  operational Session-template identity, operational Session `days`, and
  Games/Patterns/Caller/device/XML functionality.
- BingoCommerce authority: Products, Play Products, Packages, pricing,
  inventory, commerce schedules, structured commerce time, dated occurrences,
  Package availability, sales eligibility, checkout/orders/payments, future
  entitlement state, address availability, and the authoritative Hall site URL.
- Within the single authoritative Bingo Innovation Portal environment, cross-
  system identity uses `halls.id` -> WordPress Site ID, `game_types.id` -> hall-
  scoped Game Type replica, and `bingo_sessions.id` for the operational Session
  template. Names/domains, `device_group_id`, and XML-generated GameGroup IDs
  are never integration identities.
- Portal Game Types may be created and renamed; rename preserves
  `game_types.id`; deletion is prohibited once created; Portal Archive/Restore
  is not required in v1. Existing local lifecycle behavior remains transitional.
- Only Sessions satisfying `bingo_sessions.hall_id == mapped halls.id` are
  sync-eligible in v1. Received/shared Sessions and `allSessions()` semantics
  are excluded. Portal Session `days` must not drive, generate, constrain, or
  validate Commerce scheduling in v1; later advisory validation requires a
  separately approved requirement.
- Structured Commerce time is BingoCommerce-owned and may be entered manually
  by a Hall Manager in v1; it is never parsed from display names. One Package
  may be assigned to multiple dated commerce occurrences.
- Approved delivery order (ADR-042): authenticated snapshot read and full-pull
  synchronization (complete); idempotent Hall/Game Type/Session mapping and
  multi-Hall proof (complete); manual Hall-scoped synchronization (complete);
  automatic Commerce enable/suspend provisioning (current work; not
  implemented); practical local end-to-end provisioning test; SSO later;
  webhooks, scheduling, and reconciliation later.
- `Enable BingoCommerce` / Disable Ecommerce is a Portal Global Admin-only
  platform capability (BR-075, BR-076), separate from normal Hall Settings
  authorization. ADR-042 decides the machine REST contract, operations table,
  empty-store MVP, suspend/re-enable rules, and plugin split above; those
  features are not yet implemented.

See `docs/architecture/portal-integration-architecture.md`. Cross-plugin
communication for operational create/init/resume uses the synchronous filter
`bingocommerce_platform_provision_hall_site`, owned as specified in ADR-042
(caller: portal-integration; consumer: platform; command includes permanent
`site_ownership_id` for `bc_commerce_managed`; recoverable marker-matched
partial create before `address_occupied`; malformed internal command →
`internal_error`; not invoked on ordinary suspend, pre-bind cancellation, or
marker-matched partial-site suspend — including when that suspend path returns
`mapping_invalid`). Business logic MUST NOT be duplicated between plugins. The
hook is decided and not yet implemented.

## 2. Internal Structure (every plugin)

```
bingocommerce-core/
├── bingocommerce-core.php     # Bootstrap only
├── src/
│   ├── Plugin.php             # Plugin orchestrator
│   ├── HallContext.php
│   ├── Roles.php
│   ├── Migrations.php
│   ├── AuditLog.php
│   ├── PreparedAuditEvent.php
│   ├── HallStaffIntent.php
│   ├── HallStaffResult.php
│   ├── HallStaffRetryToken.php
│   ├── HallStaffTransactionTransport.php
│   ├── HallStaffService.php
│   ├── HallStaffWriterGuard.php
│   └── HallStaffAdmin.php
└── uninstall.php
```

Test harnesses never live inside the plugin tree — they belong in the
repository-root `tests/` directory (AGENTS.md §3) so they cannot ship with a
deployable plugin.

Rules:
- Namespace: `BingoCommerce\Core` (matching `BingoCommerce\<PluginName>` convention)
- Class loading may use explicit `require_once` while the plugin is small.
- Move to a PSR-4-style autoloader when the class surface grows enough to justify it.
- Do not introduce Composer solely for class loading at this stage.
- Nothing executes on file include; everything registers via hooks
- Class files: `*.php` inside `src/`; one concern per class
> **Implementation status:** every `src/` class listed above is implemented and
> verified (Hall Staff Phase A). Hall Manager create/invite-new-operational-user
> and BR-036 network-wide operational identity eligibility were implemented,
> tested, manually verified, and committed on 2026-08-18 (commit `327ae2`).
> `uninstall.php` remains planned — note that ADR-023 gives the audit trail an
> independent retention lifecycle, so uninstall must not casually drop
> `bc_audit_log`.
>
> Not yet implemented, locked as intended behavior by BR-035: the Platform
> Admin network-identity lifecycle (Disable / Reactivate / Permanently
> Delete).



## 3. Multisite Provisioning Contract (critical)

Network-activated BingoCommerce plugins that install per-site artifacts must
support BOTH situations:

1. Existing sites during deployment/upgrade:
   - use a versioned, idempotent provisioning mechanism
   - enumerate sites only while the installed schema/version is stale
   - use `switch_to_blog()` / `restore_current_blog()` safely
   - deployment must NOT depend on network deactivate/reactivate

2. Newly created sites:
   - use the appropriate Multisite initialization lifecycle
   - provision automatically
   - reconcile dependencies that may initialize later

A feature that works on existing halls but fails on a newly created hall is incomplete.

## 4. Migration Framework (core owns this)

- Table creation/updates via `dbDelta()` only
- Migration version state scope follows the artifact's own scope: per-site
  schema/artifacts version state lives in site scope (e.g.
  `bc_<plugin>_db_version` option per site); network-wide schema/artifacts
  version state lives in network scope (e.g. `bc_core_network_db_version`,
  per ADR-025 item 8). Do not assume every plugin/table schema version is
  necessarily per-site.
- Migrations run idempotently: safe to run twice, safe at network scale
- Table naming per ADR-018: `$wpdb->prefix . '<name>'` per-site,
  `$wpdb->base_prefix . '<name>'` network-wide; names must not collide with
  WordPress Core or WooCommerce tables.
- Every table has an indexed site/session/order reference column as applicable

For ADR-031 artifacts:

- `bingocommerce-catalog` owns one per-site Catalog schema version (proposed
  `bc_catalog_db_version`) for `bingo_game_types`, `bingo_play_products`,
  `bingo_packages`, `bingo_package_components`, and `bingo_package_addons`.
- `bingocommerce-sessions` owns one per-site Session schema version (proposed
  `bc_sessions_db_version`) for `bingo_sessions` and
  `bingo_session_packages`.
- The seven tables use `$wpdb->prefix` and contain no redundant `blog_id`.
- Initial relationships are indexed logical relationships with restrictive
  service-level validation; do not assume physical foreign keys or cascades.

## 5. Capability Model (core owns this)

- Custom roles: `bc_hall_manager`, `bc_game_operator`
- Phase 1 platform administration is performed by trusted network-level
  administrators.
- Native WordPress Super Admin remains reserved for trusted platform operators.
- Hall staff must never receive Super Admin privileges.
- If platform operations later require a restricted operational-admin layer,
  implement it through explicit capabilities/UI rather than granting unnecessary
  network privileges.
- Custom capabilities prefixed `bc_`. Full ratified capability list per role: BR-027.
- Roles are per-site in Multisite (`{prefix}user_roles` option) — installation
  follows the Multisite Provisioning Contract above
- All authorization checks: `current_user_can( 'bc_...' )` — never role-name
  comparisons (AGENTS.md rule 5.5/7)

## 6. Service Boundaries

- Core services are accessed through small static facades or service getters —
  feature plugins never duplicate tenant/audit logic
- Cross-plugin communication via documented hooks (`do_action`/`apply_filters`
  with `bingocommerce_` prefix), never by calling another plugin's internals
- No business logic in themes; no presentation logic in core services

### Catalog Services and WooCommerce Projections

ADR-031 and BR-065 define these supported writers in
`bingocommerce-catalog`:

- `PackageService` owns authoritative Package mutations, linked WooCommerce
  Package coordination/native base price, concurrency-safe linked-product
  exclusivity enforcement,
  and deterministic `bc_product_kind` projection maintenance.
- `PackageAddonService` owns authoritative Package/add-on relationship
  mutations, enabled-relationship recount, and deterministic
  `bc_package_relation` projection maintenance.
- `BingoPlayProductService` owns authoritative Bingo Play Product membership,
  concurrency-safe linked-product exclusivity enforcement, and the v1 non-
  independently-purchasable boundary.

A standalone check-then-insert across `bingo_packages` and
`bingo_play_products` is insufficient. ADR-032 authorizes only
`bingocommerce-catalog`'s narrow captured-mysqli critical-serialization
transport for the approved product-claim and Game-Type-reference boundaries;
it adds no table and does not reuse or broaden ADR-025.

Catalog tables remain authoritative. Projection metadata is updated through
WooCommerce CRUD after the authoritative Catalog mutation, then verified. A
failed/unverifiable projection is surfaced as reconciliation-required and is
safely retryable from current Catalog state; it is not reported as wholly
healthy. Do not reuse ADR-025's Hall Staff direct-mysqli transport or invent a
direct-SQL transaction spanning Catalog rows and WooCommerce metadata.

### Shared Audit Service

`AuditLog.php` is the shared network-wide platform audit service defined by
ADR-023. Security-sensitive cross-cutting events from staff management,
ticket reactivation, billing/lifecycle administration, and future platform
features must use this service rather than creating independent general-purpose
audit-log tables.

The audit service records accountability events; it does not replace
feature-specific operational state or history.
