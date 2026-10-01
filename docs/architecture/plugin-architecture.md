## 1. Plugin Suite

Small, single-responsibility plugins — never one monolith. Each must be
independently activatable and testable.

| Plugin | Responsibility | Depends on |
|---|---|---|
| `bingocommerce-core` | Roles & capabilities, tenant context helpers, audit log service, migration framework; Hall public storefront profile (Store Details: per-site `bc_store_details`, Appearance → Store Details screen, read-only `bingocommerce_store_details` filter — ADR-045 narrow clarification of ADR-026); Hall homepage slider (`bc_homepage_slider`, Appearance → Homepage, read-only `bingocommerce_homepage_slider` filter — ADR-045 addition 2026-09-30); BR-084 `StorefrontStyleGuard` (on Hall sites only Super Admins may edit/delete/publish `wp_global_styles`; the unavailable Appearance → Fonts entry is removed for everyone else) | WooCommerce (network) |
| `bingocommerce-catalog` | Hall-scoped Game Type replica/domain behavior, Bingo Play Product domain metadata/mapping, Bingo Package definitions/components, ADR-044 Package add-on eligibility/configuration, administration and targeted projection reconciliation, BR-032/BR-065 metadata projections, BR-064 network maximum and Hall override settings screens/writers plus the read-only current-value resolver, starter-product domain logic when that deferred copy exists (ADR-026/ADR-031/ADR-035/ADR-044; add-on schema Slice 1, backend Slice 2 and admin UI Slice 3 implemented; ADR-042 MVP runs no starter copy) | core |
| `bingocommerce-sessions` | Portal Session-template replica; Commerce schedules with immutable named timezone and structured time; dated occurrence generation/reconciliation; inclusive hall-local 30-day window; centralized current-definition membership; Available for Sale; Package assignment; and customer occurrence-before-Package selection (BR-050–BR-052/BR-063/BR-073/BR-077/BR-078; approved ADR-043) | core, catalog |
| `bingocommerce-digital-tickets` | Implemented: Hall-local purchase-time entitlement snapshot on successful online payment, refund/cancellation voiding and terminal markers, stable QR reference, guarded customer order-view rendering, and a paid-order email link to that authorized view. Pending: Android scanning/redemption, device binding, and staff reactivation | core, catalog, sessions |
| `bingocommerce-portal-integration` | Portal-facing machine endpoint; request authentication/validation; provisioning-operation persistence/sequencing; Portal Hall mapping coordination; HallCommerceState/managed-marker coordination; Portal snapshot synchronization; safe API responses and integration audit attribution (ADR-035–ADR-042) | core |
| `bingocommerce-platform` | Actual WordPress Multisite site creation; WooCommerce/site initialization; triggering existing per-site BingoCommerce migrations and role provisioning; returning a structured setup result to the integration orchestrator; billing lifecycle (BR-016); retention/deletion (BR-017); network reporting. Does not own Catalog domain rules. ADR-042 MVP does not trigger starter Product/Play Product copy | core |

Theme: Phase 1 standard storefront in `wp-content/themes/bingocommerce-*`.
Presentation only — zero business logic (AGENTS.md rule 2). The v1 theme is
`BingoThemev1` in `wp-content/themes/bingocommerce-bingothemev1/` (ADR-045): a
classic PHP theme with a fixed header/footer, native logo and `primary`/`footer`
menus, fixed colours and fonts, and native WooCommerce output with no
WooCommerce template overrides. It reads Store Details through the core
`bingocommerce_store_details` filter and the homepage slider through
`bingocommerce_homepage_slider`. Missing Core data gives empty contact output
and the original Hall-name/Shop homepage banner. The theme owns slider rendering
and interaction; Core validates and persists its Hall-local display configuration.
The homepage map is theme presentation derived on each render from the same
Store Details filter, with a nonblank public address line 1 required. It uses
the Google Maps `q` / `output=embed` iframe method, encoding the ordered address
once and escaping the URL at output. Existing Appearance → Store Details owns
editing; there is no new configuration boundary, option, API key or geocoding
service. Hall switching follows the current site without a theme static cache;
missing Core/address data omits the map safely. Other homepage sections and
Session selection remain pending.

Plugin boundaries are locked by ADR-026, narrowed by ADR-035, and extended for
automatic Commerce enable/suspend by ADR-042:
`bingocommerce-core` stays foundational/shared infrastructure only — Catalog
domain behavior must not be added there — with ADR-045's narrow exception for
the Hall public storefront profile (Store Details and the homepage slider, per the dated 2026-09-30
addition: display configuration, not domain authority). Its capability policy also includes the BR-084 storefront
style guard (Hall global-styles writes are Platform Admin-only). `bingocommerce-sessions` owns Session,
never Catalog entities. `bingocommerce-platform` does not own Catalog domain
rules; under ADR-042 it performs Multisite/WooCommerce/site initialization and
returns a structured setup result to `bingocommerce-portal-integration`. The
ADR-042 MVP does not run starter-product copy; that Catalog-owned mechanism
remains separately deferred. Portal Game Type identity/configuration is
external authority; the Catalog plugin retains the hall-scoped local replica
and its commerce relationships. Portal webhook delivery/reconciliation remains
deferred and must not be confused with the implemented occurrence reconciler.

ADR-031 conceptually assigns five per-site Catalog tables to
`bingocommerce-catalog`; Catalog schema v6 now implements all five, including
ADR-044 Slice 1's `bingo_package_addons` migration and schema verification;
Slice 2 implements `PackageAddonService`, the fixed mutation profile,
lifecycle/medium integrity, post-commit audit/projection and targeted
reconciliation. Slice 3 implements Package administration UI and request handling;
the customer cart backend and electronic-limit checks at cart addition and
classic/Store API checkout are implemented. The BingoThemev1 storefront
foundation is implemented (ADR-045); customer selection screens remain pending.
No real Hall migration or deployment is claimed. Its two
baseline Session tables belong to `bingocommerce-sessions`. Session schema v3
adds the Portal-template replica and three schedule-definition tables in the
same plugin without replacing those baseline tables. `bingocommerce-catalog`
owns both the Platform Admin network-maximum settings writer and the authorized
Hall override settings writer, as well as the read-only effective-value
resolver. `bingocommerce-platform` does not initialize or own the network
electronic-maximum option.

The compatibility review produced the additive Portal-template / Commerce-
schedule / dated-occurrence implementation in the current repository. It
preserves the implemented Game Type and Session schemas and existing Package and
Play Product ownership. ADR-043 is approved and implemented; production
deployment is not claimed.

## 1a. Portal Integration Boundary (approved increments implemented; not deployed)

- Portal authority: Hall identity, Game Type identity/configuration,
  operational Session-template identity, operational Session `days`, and
  Games/Patterns/Caller/device/XML functionality.
- BingoCommerce authority: Products, Play Products, Packages, pricing,
  inventory, commerce schedules, structured commerce time, dated occurrences,
  Package availability, sales eligibility, checkout/orders/payments, address
  availability, the authoritative Hall site URL, and implemented Hall-local
  entitlement/ticket state. Android redemption remains future work.
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
  by a Hall Manager in v1; it is never parsed from display names. A schedule
  captures the Hall's named WordPress timezone at creation and keeps it
  immutable. User-facing dates use `MM/DD/YYYY`; canonical internal dates may
  remain `YYYY-MM-DD`. One Package may be assigned to multiple dated commerce
  occurrences.
- Generated occurrence availability requires the inclusive thirty-day window,
  Available for Sale ON and current committed-definition membership. Removing a
  strictly future date is allowed while ON; membership immediately fails
  closed and a successful edit turns it OFF. Re-adding a retained occurrence
  preserves identity and Packages and leaves sales OFF until explicitly enabled.
  Existing reconciliation/history rules are unchanged (BR-077, BR-078).
- Approved delivery order (ADR-042): authenticated snapshot read and full-pull
  synchronization (complete); idempotent Hall/Game Type/Session mapping and
  multi-Hall proof (complete); manual Hall-scoped synchronization (complete);
  BingoCommerce-side automatic Commerce enable/suspend provisioning
  (implemented and tested); Portal Global Admin Enable/Suspend/Re-enable/Retry
  controls (implemented and tested); a user-performed local-development Open
  Store → Suspend → Re-enable workflow passed against one mapped WordPress
  multisite test site, and re-enable reused the same site; SSO later;
  Portal webhooks later. Commerce scheduling and occurrence reconciliation
  exist under approved ADR-043.
- `Enable BingoCommerce` / Disable Ecommerce is a Portal Global Admin-only
  platform capability (BR-075, BR-076), separate from normal Hall Settings
  authorization. ADR-042 decides the machine REST contract, operations table,
  empty-store MVP, suspend/re-enable rules, and plugin split above. The
  BingoCommerce endpoint, orchestrator, operations persistence, platform filter
  and automatic site-creation path are implemented and tested. The Portal
  Global Admin Enable/Suspend/Re-enable/Retry controls are implemented and
  tested. A user-performed local-development Open Store → Suspend → Re-enable
  workflow passed against one mapped WordPress multisite test site, and
  re-enable reused the same site.

See `docs/architecture/portal-integration-architecture.md`. Cross-plugin
communication for operational create/init/resume uses the synchronous filter
`bingocommerce_platform_provision_hall_site`, owned as specified in ADR-042
(caller: portal-integration; consumer: platform; command includes permanent
`site_ownership_id` for `bc_commerce_managed`; recoverable marker-matched
partial create before `address_occupied`; malformed internal command →
`internal_error`; not invoked on ordinary suspend, pre-bind cancellation, or
marker-matched partial-site suspend — including when that suspend path returns
`mapping_invalid`). Business logic MUST NOT be duplicated between plugins. The
hook is implemented by `bingocommerce-platform`.

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

- `bingocommerce-catalog` owns per-site `bc_catalog_db_version = 6` for the five
  implemented tables: `bingo_game_types`, `bingo_play_products`,
  `bingo_packages`, `bingo_package_components`, and `bingo_package_addons`.
  ADR-044 Slice 1 records v6 only after all five schemas verify; migration
  creates no relationship rows and performs no WooCommerce writes.
- `bingocommerce-sessions` owns one per-site Session schema version
  (`bc_sessions_db_version = 3`) for `bingo_sessions`, `bingo_session_packages`,
  `bingo_portal_session_templates`, `bingo_session_schedules`,
  `bingo_session_schedule_dates`, and `bingo_session_schedule_packages`.
- All eleven implemented Catalog/Session tables use `$wpdb->prefix` and contain no
  redundant `blog_id`, including the add-on table.
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

ADR-031, BR-065, BR-079 and ADR-044 define these implemented and approved writer
boundaries in `bingocommerce-catalog`:

- `PackageService` owns authoritative Package mutations, linked WooCommerce
  Package coordination/native base price, concurrency-safe linked-product
  exclusivity enforcement,
  and deterministic `bc_product_kind` projection maintenance.
- The implemented ADR-044 `PackageAddonService` owns authoritative
  Package/add-on one-relationship mutations and results used by the implemented
  Slice 3 `PackageAdmin` per-row bulk orchestration,
  canonical two-decimal relationship pricing, enabled-relationship recount,
  targeted `bingo_play_product_id`-based retry, and deterministic
  `bc_package_relation` projection maintenance. It internally resolves the
  current-Hall linked WooCommerce product and never trusts a request-supplied
  WooCommerce product ID as reconciliation authority.
- `BingoPlayProductService` owns authoritative Bingo Play Product membership,
  concurrency-safe linked-product exclusivity enforcement, and the v1 non-
  independently-purchasable boundary.

A standalone check-then-insert across `bingo_packages` and
`bingo_play_products` is insufficient. ADR-032 authorizes only
`bingocommerce-catalog`'s narrow captured-mysqli critical-serialization
transport for the approved product-claim and Game-Type-reference boundaries;
it adds no table and does not reuse or broaden ADR-025.

ADR-044 separately authorizes one fixed Package-add-on mutation profile within
the existing `CatalogTransactionTransport`. It shares transport safety and
guards without converting the transport into a generic transaction wrapper.
`PackageService` must extend composition replacement to validate enabled add-on
compatibility, and `BingoPlayProductService` must extend actual medium
transitions to reject enabled add-on references. A transition blocked by either
a base component or an enabled add-on continues to return
`play_product_package_referenced`, with details identifying the cause. Bulk
administration uses one Add-ons form with one `Save Add-ons` action, calls the same
one-relationship service operation independently for each submitted configured
or selected row, and ignores unchecked never-configured rows; no bulk
transaction or separate save mode is permitted.

Catalog tables remain authoritative. Projection metadata is updated through
WooCommerce CRUD after the authoritative Catalog mutation, then verified. A
failed/unverifiable projection is surfaced as reconciliation-required and is
safely retryable from current Catalog state; it is not reported as wholly
healthy. Increment 1 defers the bounded Hall-wide mismatch scanner until the
later storefront/readiness phase; targeted retry remains available and no
ordinary Manager reconciliation dashboard is introduced. Do not reuse ADR-025's
Hall Staff direct-mysqli transport or invent a direct-SQL transaction spanning
Catalog rows and WooCommerce metadata.

### Shared Audit Service

`AuditLog.php` is the shared network-wide platform audit service defined by
ADR-023. Security-sensitive cross-cutting events from staff management,
ticket reactivation, billing/lifecycle administration, and future platform
features must use this service rather than creating independent general-purpose
audit-log tables.

The audit service records accountability events; it does not replace
feature-specific operational state or history.
