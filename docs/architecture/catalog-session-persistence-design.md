# Catalog and Session Persistence Design

Status: approved conceptual architecture; not implemented.

This document expands ADR-031 into the conceptual persistence contract for the
BingoCommerce Catalog and Session model. It is not executable SQL and does not
design entitlement/ticket storage, QR behavior, payment-success hooks, Android
APIs, redemption persistence, offline synchronization, a device registry,
per-game player history, jackpot purchase flows, or Session capacity.

## 1. Authority and Scope

BingoCommerce uses a hybrid authority split:

- WooCommerce products are commerce shells for linked Bingo Play Products and
  sellable Packages.
- BingoCommerce custom tables are authoritative for Game Type, Bingo Play
  Product membership/metadata, Package identity/composition/add-on eligibility,
  Session identity, and Package-to-Session assignment.
- The linked WooCommerce Package product's native price is the authoritative
  Package base price.
- BR-032 WooCommerce metadata is a required derived compatibility/cart-
  enforcement projection under BR-065, not a second Catalog authority.

All seven custom tables are hall-owned/per-site. Their names are appended to
`$wpdb->prefix`; they do not add another `bc_` sub-prefix and do not contain a
redundant `blog_id`. Tenant isolation comes from the per-site table prefix.

The seven tables are:

1. `bingo_game_types`
2. `bingo_play_products`
3. `bingo_packages`
4. `bingo_package_components`
5. `bingo_package_addons`
6. `bingo_sessions`
7. `bingo_session_packages`

Game Type, Bingo Play Product, Package, and Session use surrogate
BingoCommerce domain IDs. Linked WooCommerce product IDs are unique mappings,
not domain identity.

## 2. Conceptual Tables

### 2.1 `$wpdb->prefix . 'bingo_game_types'`

Hall-local first-class Game Type identity.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `game_type_id` | Non-null | Unsigned numeric surrogate primary key |
| `code` | Non-null | Stable immutable machine code |
| `name` | Non-null | Editable display name |
| `status` | Non-null; `active` initially | Lifecycle state: `active` or `archived` |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |
| `archived_at` | Nullable | Time archived; null while active |

Constraints and indexes:

- Primary key: `game_type_id`.
- Unique per hall: `code`.
- Unique per hall: `name`.
- Index: `(status, name)` for active administrative/builder selection.
- `code` is immutable after creation; changing `name` does not change identity.
- Runtime behavior is never inferred from `name`, a WooCommerce product title,
  SKU, tag, or category.

The exact Game Type code syntax, maximum length, normalization, and generation
rules remain an implementation-slice decision that MUST be finalized before
the first Catalog migration is written.

### 2.2 `$wpdb->prefix . 'bingo_play_products'`

Authoritative Bingo Play Product membership and structured metadata for a
linked site-local WooCommerce product.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `bingo_play_product_id` | Non-null | Unsigned numeric surrogate primary key |
| `wc_product_id` | Non-null | Linked site-local WooCommerce product ID |
| `medium` | Non-null | `Paper` or `Digital` |
| `faces_per_unit` | Non-null | Positive number of faces represented by one unit |
| `game_type_id` | Conditional/nullable | Logical relationship to `bingo_game_types` |
| `status` | Non-null; `active` initially | Lifecycle state: `active` or `archived` |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |
| `archived_at` | Nullable | Time archived; null while active |

Constraints and indexes:

- Primary key: `bingo_play_product_id`.
- Unique: `wc_product_id`.
- Index: `game_type_id`.
- Index: `(medium, status)`.
- Index: `(status, wc_product_id)`.
- `faces_per_unit` is a positive integer.
- Digital requires exactly one non-null Game Type relationship.
- Paper may reference zero or one Game Type, but always contributes zero
  electronic faces.
- The linked WooCommerce product must belong to the current site.
- A `wc_product_id` present here cannot simultaneously be linked by
  `bingo_packages`.

Membership in this table is the authoritative declaration that the linked
WooCommerce product is a Bingo Play Product. In v1 it is package-building
inventory and is not independently customer-purchasable, even if compatibility
metadata says `bc_product_kind = regular` and `bc_package_relation = none`.

### 2.3 `$wpdb->prefix . 'bingo_packages'`

BingoCommerce Package identity linked to its sellable WooCommerce commerce
shell.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `package_id` | Non-null | Unsigned numeric surrogate primary key |
| `wc_product_id` | Non-null | Linked site-local WooCommerce Package product ID |
| `package_type` | Non-null | `Paper`, `Digital`, or `Hybrid` |
| `status` | Non-null; `active` initially | Lifecycle state: `active` or `archived` |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |
| `archived_at` | Nullable | Time archived; null while active |

Constraints and indexes:

- Primary key: `package_id`.
- Unique: `wc_product_id`.
- Index: `(status, package_type)`.
- The linked WooCommerce product must belong to the current site.
- A `wc_product_id` present here cannot simultaneously be linked by
  `bingo_play_products`.
- `PackageService` and `BingoPlayProductService` each validate cross-table
  product-link exclusivity before creating their respective link.

The linked WooCommerce product's native price is the sole mutable Package base-
price authority. The Package row does not duplicate that price. Component-
product prices are never summed into the Package price.

### 2.4 `$wpdb->prefix . 'bingo_package_components'`

Base Package composition.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `package_component_id` | Non-null | Unsigned numeric surrogate row primary key |
| `package_id` | Non-null | Logical relationship to `bingo_packages` |
| `bingo_play_product_id` | Non-null | Logical relationship to `bingo_play_products` |
| `quantity` | Non-null | Positive base-component quantity |
| `sort_order` | Non-null; default `0` | Administrative/display ordering |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |

Constraints and indexes:

- Primary key: `package_component_id`.
- Unique: `(package_id, bingo_play_product_id)`.
- Index: `(package_id, sort_order)`.
- Index: `bingo_play_product_id`.
- `quantity` is a positive integer.
- Repeated use of the same product is represented by one row with its aggregate
  quantity, not duplicate relationship rows.

Base-composition rules from BR-061:

- Paper: at least one base component; every base component is Paper.
- Digital: at least one Digital base component; no Paper base components.
- Hybrid: at least one Paper and at least one Digital base component.
- Optional add-ons do not change stored `package_type`.

### 2.5 `$wpdb->prefix . 'bingo_package_addons'`

Authoritative Package-specific add-on eligibility, price, and quantity ceiling.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `package_addon_id` | Non-null | Unsigned numeric surrogate row primary key |
| `package_id` | Non-null | Logical relationship to `bingo_packages` |
| `bingo_play_product_id` | Non-null | Logical relationship to `bingo_play_products` |
| `unit_price` | Non-null | Non-negative Package-specific monetary value |
| `max_quantity` | Non-null; default `1` | Maximum selectable quantity for this relationship |
| `enabled` | Non-null | Whether this relationship is currently selectable |
| `sort_order` | Non-null; default `0` | Administrative/display ordering |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |

Constraints and indexes:

- Primary key: `package_addon_id`.
- Unique: `(package_id, bingo_play_product_id)`.
- Index: `(package_id, enabled, sort_order)`.
- Index: `bingo_play_product_id`.
- `unit_price` is non-negative and uses a WooCommerce-compatible decimal
  monetary representation in the hall's store currency.
- `max_quantity >= 1`; default is `1`.
- When selected, `1 <= selected_quantity <= max_quantity`.

The relationship's `unit_price` is authoritative. The underlying reusable
Bingo Play Product's WooCommerce price is not the Package-specific add-on
price. `bingo_package_addons` is the only authority for which exact Package may
use the add-on. Product-level `bc_package_relation` metadata never answers that
question.

### 2.6 `$wpdb->prefix . 'bingo_sessions'`

Hall-owned calendar occurrence.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `session_id` | Non-null | Unsigned numeric surrogate primary key |
| `session_date` | Non-null | Structured hall-calendar `DATE` |
| `display_name` | Non-null | Editable Session display label |
| `available_for_sale` | Non-null; default `OFF` | Hall Manager-controlled sales switch |
| `status` | Non-null; `active` initially | Administrative lifecycle: `active` or `archived` |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |
| `archived_at` | Nullable | Time archived; null while active |

Constraints and indexes:

- Primary key: `session_id`.
- Unique: `(session_date, display_name)`.
- Index: `(status, available_for_sale, session_date)`.
- Index: `session_date`.
- No structured Session time exists in the current implementation.
- Time-like display text is never parsed for sales, redemption, closing, or
  scheduling behavior.
- No capacity, order count, customer count, reservation count, or shared
  electronic counter exists on Session.

For a non-archived Session dated `D`, storefront eligibility is:

```text
D - 5 calendar days <= hall_local_today <= D
AND available_for_sale = ON
```

`hall_local_today` uses the hall site's configured WordPress timezone. The
Session becomes ineligible for online sale on the calendar day after `D`.
This sales boundary does not make an otherwise-valid QR unusable when the
Session starts or after the date boundary (BR-056).

### 2.7 `$wpdb->prefix . 'bingo_session_packages'`

Assignment of a Package to one exact Session.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `session_package_id` | Non-null | Unsigned numeric surrogate row primary key |
| `session_id` | Non-null | Logical relationship to `bingo_sessions` |
| `package_id` | Non-null | Logical relationship to `bingo_packages` |
| `created_at` | Non-null | Assignment timestamp |

Constraints and indexes:

- Primary key: `session_package_id`.
- Unique: `(session_id, package_id)`.
- Index: `(session_id, package_id)` through the unique key.
- Reverse index: `(package_id, session_id)`.
- Relationship presence means assigned; no Session-specific price, component
  override, capacity, or counter is introduced.

A Package is selectable only when the Session is storefront-eligible, this
assignment exists, the Package and required base components are active/valid,
and the linked WooCommerce Package product is valid and purchasable.

## 3. Logical Relationship and Deletion Policy

Logical relationships are indexed and enforced through canonical services:

- `bingo_play_products.game_type_id` → `bingo_game_types.game_type_id`
- `bingo_package_components.package_id` → `bingo_packages.package_id`
- `bingo_package_components.bingo_play_product_id` →
  `bingo_play_products.bingo_play_product_id`
- `bingo_package_addons.package_id` → `bingo_packages.package_id`
- `bingo_package_addons.bingo_play_product_id` →
  `bingo_play_products.bingo_play_product_id`
- `bingo_session_packages.session_id` → `bingo_sessions.session_id`
- `bingo_session_packages.package_id` → `bingo_packages.package_id`

The initial implementation uses restrictive service-level reference validation
rather than relying on physical foreign keys or database cascades. Physical
foreign keys may be reconsidered only after compatibility with WordPress,
WooCommerce lifecycle behavior, and the project's `dbDelta()` migration
contract is proven in a dedicated design.

Lifecycle rules:

- Archive/inactivate preserves identity and relationships while preventing new
  selection/sale.
- Hard deletion is blocked while referenced.
- Archive and delete operations never silently detach relationships.
- Referenced Game Types may be archived but not hard-deleted.
- Referenced Bingo Play Products may be archived but cannot be unlinked or
  permanently deleted.
- An archived required base component makes the Package unavailable for new
  sale until explicitly corrected; it is not silently removed.
- A Package assigned to a Session may be archived without detaching the
  assignment, but cannot be hard-deleted until all eligible references are
  explicitly removed and no historical reference remains.
- A Session may be archived without detaching Package assignments. Hard delete
  is blocked once orders, future snapshots, redemptions, or reporting facts
  reference it.
- Mutable relationship changes affect future authoritative validation only.
  Historical purchases will rely on WooCommerce order facts and the later
  immutable entitlement snapshot, never current Catalog rows.

Package revision tables are not required in this increment. Revisions may be
introduced later only if scheduled future definitions, draft/publish approval,
or full Catalog change history becomes a confirmed requirement.

## 4. WooCommerce Mapping and BR-032/BR-065 Projection

### Product-link exclusivity

One WooCommerce product ID must never be registered simultaneously as a linked
Package product and a Bingo Play Product. Both canonical link-creation services
must enforce this invariant concurrency-safely. A standalone check-then-insert
across `bingo_packages` and `bingo_play_products` is insufficient because two
concurrent writers could each observe absence in the other table and commit
conflicting links. ADR-032's narrow Catalog critical-serialization transport
uses the current-site Woo product row as the shared lock anchor and the
canonical Catalog lock order; it does not add another table or reuse ADR-025's
Hall Staff transaction semantics. Operational verification must include
concurrent product administration/REST/status changes because that anchor is a
WordPress-owned row.

Play Product Game Type reference creation/reassignment and future Game Type
hard deletion also use ADR-032's shared Game Type row lock. Game Type hard
delete remains deferred until the complete reference writer/delete contract
and concurrency tests exist. WooCommerce CRUD and all BR-032/BR-065 projection
writes remain outside the Catalog critical transaction.

### Package kind projection

While an authoritative `bingo_packages` row exists:

```text
bc_product_kind = package
```

Permanently removing a Package requires the caller to select exactly one
supported linked-product disposition:

1. Permanently delete the linked WooCommerce product; or
2. Intentionally retain and convert it to ordinary merchandise, setting
   `bc_product_kind = regular`.

A request selecting neither disposition is rejected. Package removal never
silently converts the product, and a surviving product is never left with a
missing or undefined `bc_product_kind`.

### Package-add-on relation projection

After every authoritative relationship mutation, `PackageAddonService`
recalculates the complete enabled relationship count for that Bingo Play
Product:

```text
if enabled_package_addon_relationship_count > 0:
    bc_package_relation = package_only_addon
else:
    bc_package_relation = none
```

Removing one relationship cannot set `none` while another enabled relationship
remains. The projection indicates only that the product is used somewhere as a
Package-only add-on; it never identifies an eligible Package.

### Bingo Play Product precedence

A Bingo Play Product may carry compatibility metadata:

```text
bc_product_kind = regular
bc_package_relation = none
```

Its authoritative `bingo_play_products` membership overrides those values for
classification and purchasing. It remains non-independently-purchasable in v1.

### Cart and checkout

Final server-side classification, one-Package enforcement, add-on eligibility,
and Bingo Play Product purchasing restrictions use Catalog services/tables.
Projection metadata may assist compatibility routing but cannot grant Package
identity, Package-specific eligibility, or ordinary-merchandise behavior that
contradicts authoritative state.

## 5. Projection Write and Reconciliation Model

Canonical service ownership:

- `PackageService` owns Package mutations, linked-product coordination, native
  base-price coordination, and `bc_product_kind` projection maintenance.
- `PackageAddonService` owns add-on relationship mutations, enabled-count
  recalculation, and `bc_package_relation` projection maintenance.
- `BingoPlayProductService` owns authoritative membership and the v1 non-
  independently-purchasable boundary.

The Catalog mutation is authoritative. The subsequent WooCommerce metadata
projection update is deterministic, idempotent, verifiable, and retryable.
After saving through WooCommerce CRUD, the service verifies the resulting
projection.

If the Catalog mutation succeeds but projection synchronization fails or
cannot be verified, the service surfaces an explicit reconciliation-required
result rather than reporting the operation as wholly healthy. Expected
projection state is derived from current authoritative rows, so retry does not
depend on replaying an old request payload.

A targeted reconciliation accepts a WooCommerce product ID. A bounded hall-
level mismatch scan covers the union of products referenced by authoritative
Catalog rows and products carrying either relevant BR-032/BR-065 projection
value.
Including projected products allows stale metadata to be found after an
authoritative relationship was permanently removed. No new projection queue or
table is required for persistence correctness.

No direct-SQL transaction spans custom Catalog rows and WooCommerce metadata.
ADR-025's Hall Staff direct-mysqli transport does not automatically apply.

## 6. Electronic-Limit Configuration

The platform electronic maximum is stored as a network option controlled by
Platform Admin. Proposed option key:

```text
bc_platform_electronic_maximum
```

The optional hall override is stored as a per-site option configurable by an
authorized Hall Manager for that hall. Proposed option key:

```text
bc_electronic_limit_override
```

Absence means no override. `bingocommerce-catalog` owns the effective-limit
resolver:

```text
if a hall override exists:
    effective_limit = min(hall_override, current_platform_maximum)
else:
    effective_limit = current_platform_maximum
```

The current network value is evaluated at runtime; no stale effective value is
persisted. The configuration is per Game Type enforcement policy, not Session
capacity.

## 7. Plugin and Migration Ownership

### `bingocommerce-catalog`

Owns:

- The five Catalog tables.
- Game Type, Bingo Play Product, Package, component, and add-on services.
- WooCommerce product-link validation and BR-032/BR-065 projections.
- Package composition and add-on quantity/price validation.
- Hall override configuration and the effective-limit resolver.
- Starter Catalog copy logic triggered by `bingocommerce-platform`.
- Per-site Catalog migration/version state, proposed as
  `bc_catalog_db_version`.

### `bingocommerce-sessions`

Owns:

- `bingo_sessions` and `bingo_session_packages`.
- Hall-local Session date/window logic and Available for Sale.
- Package assignment and Session lifecycle services.
- Per-site Session migration/version state, proposed as
  `bc_sessions_db_version`.

### `bingocommerce-platform`

Owns:

- Platform Admin mutation and network-scope initialization/versioning of the
  platform electronic maximum option.
- Triggering Catalog's starter-copy contract during hall provisioning.

### `bingocommerce-core`

Owns shared migration infrastructure, tenant context, capabilities, and audit
infrastructure only. ADR-026 remains in force: Catalog and Session behavior do
not move into core.

Catalog and Session migrations must be idempotent, provision stale existing
sites only when needed, provision newly created halls automatically, and never
depend on network plugin deactivate/reactivate.

## 8. Deferred Scope

This persistence design deliberately does not decide:

- Entitlement/ticket table names, columns, or indexes.
- QR payload or implementation.
- WooCommerce payment-success hook/status mapping.
- Android API or wire contract.
- Redemption tables or accumulation schema.
- Offline-sync architecture.
- Device registry.
- Per-game player/play history.
- Separate jackpot/additional-game purchase flows.
- Any Session capacity concept.
