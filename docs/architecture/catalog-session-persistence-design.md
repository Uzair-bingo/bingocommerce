# Catalog and Session Persistence Design

Status: ADR-031's seven-table baseline is approved conceptual architecture. The
current repository implements five Catalog tables at schema v6 and six Session
tables at schema v3, for eleven implemented tables total. ADR-044 Slice 1
implements the `bingo_package_addons` migration and schema verification; Slice 2
implements `PackageAddonService`, its fixed transaction profile,
lifecycle/medium integrity, post-commit audit/projection and targeted
reconciliation. Slice 3 implements Package administration UI and request handling;
storefront/cart/checkout enforcement remains unimplemented. No real Hall
migration or deployment is claimed. The
additive Session schedule/occurrence model exists under approved ADR-043;
production deployment is not claimed.

This document expands ADR-031 into the conceptual persistence contract for the
BingoCommerce Catalog and Session model. It is not executable SQL and does not
design entitlement/ticket storage, QR behavior, payment-success hooks, Android
APIs, redemption persistence, offline synchronization, a device registry,
per-game player history, jackpot purchase flows, or Session capacity.

ADR-035 narrows this baseline for Portal integration. Within the single
authoritative Bingo Innovation Portal environment, `halls.id` is Portal Hall
identity, `game_types.id` is trusted cross-system Game Type identity, and
`bingo_sessions.id` is operational Session-template identity. The compatibility
review produced a non-destructive additive model: the existing local Game Type,
Session and Package-assignment tables remain, while separate Portal-template
and Commerce-schedule tables support generated dated occurrences. Nothing in
that model deletes legacy tables or discards existing relationships. ADR-043
is approved and implemented; production deployment is not claimed.

ADR-039 approves the Game Type name/index and imported-code design below for
Catalog v4 -> v5. The prerequisite Phase 3D2 name-validation amendment is
implemented and tested; COMPLETE. Catalog v5 is implemented and tested;
COMPLETE. ADR-040's Phase 3E snapshot application, Portal importing, and
imported-code allocation are COMPLETE: source review passed, the local harness
passes 387/387, and the authenticated real Portal test passed against a local
Portal test environment, importing 7 Game Types and 26 Session templates for
Portal Hall 2 into BingoCommerce site 2 with an idempotent second run. Imported
Game Type rows carry `local_name_key` NULL and codes from the approved
`portal-gt-<id>` family, and the hall's pre-existing local Game Types were
untouched. Production deployment is not claimed.

## 1. Authority and Scope

BingoCommerce uses a hybrid authority split:

- WooCommerce products are commerce shells for linked Bingo Play Products and
  sellable Packages.
- BingoCommerce custom tables are authoritative for Bingo Play Product
  membership/metadata, Package identity/composition/add-on eligibility, and
  local commerce relationships. `bingo_game_types` becomes the hall-scoped
  local replica of Portal-authoritative Game Type identity/configuration.
  `bingo_sessions` currently stores the implemented hall-local dated Session
  identity, which ADR-035 narrows toward a dated commerce occurrence rather
  than the Portal operational Session template.
- The linked WooCommerce Package product's native price is the authoritative
  Package base price.
- BR-032 WooCommerce metadata is a required derived compatibility/cart-
  enforcement projection under BR-065, not a second Catalog authority.

The eleven currently implemented Catalog/Session custom tables are hall-owned/per-
site. Their names are appended to
`$wpdb->prefix`; they do not add another `bc_` sub-prefix and do not contain a
redundant `blog_id`. Tenant isolation comes from the per-site table prefix.

The eleven implemented tables are:

1. `bingo_game_types`
2. `bingo_play_products`
3. `bingo_packages`
4. `bingo_package_components`
5. `bingo_sessions`
6. `bingo_session_packages`
7. `bingo_portal_session_templates`
8. `bingo_session_schedules`
9. `bingo_session_schedule_dates`
10. `bingo_session_schedule_packages`
11. `bingo_package_addons`

ADR-044 Slice 1 implements `bingo_package_addons` persistence. Slice 2 implements
the approved backend service, transaction profile, integrity extensions,
post-commit audit/projection and targeted reconciliation. Slice 3 implements
Package administration and per-row request orchestration. Automated Catalog v6
schema and add-on service tests used isolated fixtures. The user reports testing
Package add-on administration in the local Star Bingo Hall and seeing changes
persist after refresh. These browser observations do not establish that every
scenario passed. No production migration or deployment is claimed.

Game Type, Bingo Play Product, Package, and Session use local surrogate
BingoCommerce domain IDs. Portal `game_types.id` is the trusted cross-system
Game Type identity; the local Game Type surrogate and machine code remain
internal identifiers. Portal `bingo_sessions.id` identifies the external operational
template, while customers/orders ultimately reference a BingoCommerce dated
commerce occurrence. Linked WooCommerce product IDs are unique mappings, not
domain identity.

## 2. Conceptual Tables

### 2.1 `$wpdb->prefix . 'bingo_game_types'`

Hall-local first-class Game Type replica and commerce relationship identity.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `game_type_id` | Non-null | Unsigned numeric surrogate primary key |
| `portal_game_type_id` | Nullable | Unique Portal identity link implemented in Catalog v4 |
| `code` | Non-null | Stable immutable machine code |
| `name` | Non-null, `varchar(160)` | Actual display/source name; accepted Portal names stay unchanged |
| `local_name_key` | Nullable, `varchar(160)`; approved for v5 | Exact stored name for local rows; SQL `NULL` for Portal-linked rows; same charset/collation as `name` |
| `status` | Non-null; `active` initially | Lifecycle state: `active` or `archived` |
| `created_at` | Non-null | Creation timestamp |
| `updated_at` | Non-null | Last-change timestamp |
| `archived_at` | Nullable | Time archived; null while active |

Constraints and indexes:

- Primary key: `game_type_id`.
- Unique per hall: `code`.
- Unique per hall: non-null `portal_game_type_id`.
- Catalog v5 removes `UNIQUE name_unique(name)` and adds
  `UNIQUE local_name_unique(local_name_key)`, protecting local-versus-local
  names, including archived local rows. Writers must maintain the key.
- Add nonunique `name_id(name, game_type_id)` in v5.
- Index: `(status, name)` for active administrative/builder selection.
- `code` is immutable after creation; changing `name` does not change identity.
- Under ADR-035, neither `code` nor `name` is cross-system identity. Portal
  `game_types.id` supplies that identity through `portal_game_type_id`.
- Duplicate and collation-equivalent Portal names with different Portal IDs
  remain separate rows. Never link, merge, or overwrite rows using names.
- Portal Game Types may be created and renamed; rename preserves
  `game_types.id`; deletion is prohibited once created; Portal Archive/Restore
  is not part of v1 (ADR-038). Existing local lifecycle behavior remains transitional
  and is not the Portal lifecycle contract.
- Runtime behavior is never inferred from `name`, a WooCommerce product title,
  SKU, tag, or category.

ADR-039's imported-code candidates are `portal-gt-<portal_game_type_id>`, then
`portal-gt-<id>-2` through `portal-gt-<id>-32`, in order. They fit the existing
64-character code limit and grammar. Existing local codes remain unchanged;
the namespace is not reserved and database `code_unique` is the collision
authority. Find existing imported rows only by `portal_game_type_id` within
the trusted Hall; never adopt or overwrite a row because its code matches.
Allocated codes are immutable across rename and re-import. Exhausting all 32
candidates rejects the complete application without partial domain changes.

The ADR-039 Phase 3D2 name-validation amendment is implemented and tested;
COMPLETE. Hall, Game Type, and Session names reject Unicode
control and format characters, line and paragraph separators, and names
containing only whitespace or Unicode separators. Accepted names remain unchanged; do
not normalize, trim, rewrite, or escape the stored value. Reuse the existing
safe name-validation error vocabulary and structural field paths.

For the v4 -> v5 migration, backfill `local_name_key = name` where
`portal_game_type_id IS NULL` and set/retain a `NULL` key for linked rows.
Preserve existing names, codes, IDs, statuses, references, and timestamps;
do not automatically link local rows. `dbDelta()` remains the normal mechanism.
ADR-039 authorizes only the narrowly scoped, idempotent, allowlisted
`DROP INDEX name_unique` exception on the trusted Hall's Game Type table.
Verify the table/index before removal, the replacement key/backfill before
dropping it, and the table/index state afterward. Advance the Catalog version
only after complete verification; new sites receive the final v5 schema
directly. Transaction/locking mechanics, replay/stale-order handling,
Session-template application, and local writer restrictions after
synchronization remain governed by ADR-040 and later reconciliation work.
Portal webhooks, SSO and transitional local-writer reconciliation remain open.
Commerce scheduling and occurrence reconciliation exist in the current
repository under approved ADR-043. ADR-042's BingoCommerce-side machine provisioning path
is implemented and tested. The Portal Global Admin Enable/Suspend/Re-enable/Retry
controls are implemented and tested. A user-performed local-development Open
Store → Suspend → Re-enable workflow passed against one mapped WordPress
multisite test site, and re-enable reused the same site.

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

Package Increment 1 notes:

- `package_type` is persisted, but is NEVER supplied by a caller. It is computed
  only from the base composition (BR-061): Paper-only components produce
  `paper`, Digital-only produce `digital`, and a mixture produces `hybrid`. It is
  recomputed and written inside the same transaction as every composition
  change, and is not recomputed by an unrelated Bingo Play Product lifecycle
  change (BR-067).
- `status` values are `active` and `archived`, lowercase, matching the
  implemented Game Type and Bingo Play Product tables.
- `wc_product_id` is immutable after creation. There is no relink operation.

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

Package Increment 1 notes:

- `quantity` accepts 1 through the unsigned-integer persistence maximum. This is
  a STORAGE AND VALIDATION bound only; it is not a business maximum, and no
  business rule asserts that the column ceiling is a meaningful catalog limit. A
  value beyond the column bound is rejected rather than truncated. A smaller
  business maximum would require a separately confirmed BR. BR-062's ceiling
  applies to Package ADD-ONS only and does not bound base components.
- `quantity` is distinct from `bingo_play_products.faces_per_unit`. Electronic
  contribution is `faces_per_unit x quantity`; that calculation is a later
  concern and is not enforced by this table.
- `sort_order` is created in Catalog v3 with its approved
  `(package_id, sort_order)` index and is written with its default. Package
  Increment 1 exposes no reordering interface; the column exists so the approved
  contract is met without a later schema change.
- A requested component set containing a duplicate `bingo_play_product_id` is
  rejected with `duplicate_component` (BR-067); quantities are never silently
  merged.

Base-composition rules from BR-061:

- Paper: at least one base component; every base component is Paper.
- Digital: at least one Digital base component; no Paper base components.
- Hybrid: at least one Paper and at least one Digital base component.
- Optional add-ons do not change stored `package_type`.

### 2.5 `$wpdb->prefix . 'bingo_package_addons'` (ADR-044 Slices 1–3 implemented)

Approved Hall-local authority for Package-specific add-on eligibility, price,
quantity ceiling and enabled state. The Catalog v6 migration, schema verifier
and backend `PackageAddonService` behavior are implemented; Slice 3 implements
Package administration UI/request orchestration. Storefront enforcement
remains unimplemented.

| Column | Nullability/default intent | Meaning |
|---|---|---|
| `package_addon_id` | `bigint(20) unsigned`; non-null; auto-increment | Surrogate row primary key |
| `package_id` | `bigint(20) unsigned`; non-null | Logical relationship to `bingo_packages` |
| `bingo_play_product_id` | `bigint(20) unsigned`; non-null | Logical relationship to `bingo_play_products` |
| `unit_price` | `decimal(19,2)`; non-null | Canonical non-negative Package-specific monetary value; non-negativity is service-validated |
| `max_quantity` | `tinyint(3) unsigned`; non-null; default `1` | Maximum selectable quantity; business range 1–99 |
| `enabled` | `tinyint(1) unsigned`; non-null; default `1` | Whether this relationship is currently selectable |
| `sort_order` | `int(10) unsigned`; non-null; default `0` | Stable administrative/display ordering; not editable in Increment 1 |
| `created_at` | `datetime`; non-null | UTC creation timestamp |
| `updated_at` | `datetime`; non-null | UTC last-change timestamp |

Constraints and indexes:

- Primary key: `package_addon_id`.
- Unique: `(package_id, bingo_play_product_id)`.
- Index: `(package_id, enabled, sort_order)`.
- Index: `bingo_play_product_id`.
- `unit_price` is a canonical non-negative two-decimal string in the Hall store's
  currency. A Manager-submitted or service-supplied value is valid only when it
  already contains exactly two fractional digits. `5.00`, `4.23`, `10.29` and
  `0.00` are valid. `5`, `5.0`, `5.5`, floating-point input, exponent notation,
  signs, currency symbols, grouping commas, malformed values, noncanonical
  leading zeros, values outside `DECIMAL(19,2)`, and more than two fractional
  digits are rejected. Manager/service input is never normalized or rounded.
  Persisted/displayed and submitted values, no-op comparisons, audit values and
  fingerprint inputs always use exactly two fractional digits.
- `max_quantity` defaults to 1 and must be an integer from 1 through the BR-079
  business maximum of 99.
- When selected, `1 <= selected_quantity <= max_quantity <= 99`.
- `enabled` is exactly 0 or 1.
- Listing order is `sort_order`, then `package_addon_id`; Increment 1 writes the
  default sort order and exposes no reordering surface.
- The same Play Product may be a base component and optional add-on in one
  Package; the two quantities remain independent.

The relationship's `unit_price` is authoritative. The underlying reusable Bingo
Play Product's WooCommerce price is not the Package-specific add-on price, and
existing relationships always display their saved price.
`bingo_package_addons` is the only authority for which exact Package may use the
add-on. Product-level `bc_package_relation` metadata never answers that question.
For an eligible never-configured row only, form preparation after WooCommerce
initialization and outside the Catalog transaction loads the linked product with
`\wc_get_product( $wc_product_id )`, confirms the result is an instance of
`\WC_Product`, and reads `$product->get_regular_price( 'edit' )`. The return is
treated as a string and never converted to floating point. The implementation
must not use `get_price()`, sale price, direct `_regular_price` post meta,
formatted HTML price, a tax-adjusted display price or a filtered `view` value.

Prefill is UI convenience only and persists nothing before the Manager selects
and submits the row. During form preparation only, the returned string `5` may
be padded without rounding to `5.00`, and `5.5` to `5.50`; a value already
containing exactly two decimal digits remains unchanged. The displayed and
submitted field value contains exactly two decimal digits. Blank, negative,
signed, exponent-form, malformed, noncanonical-leading-zero, overflowing or
greater-than-two-decimal regular price leaves the field blank so the Manager
must enter a valid value when selecting that row. No price is silently rounded,
and UI-only padding never makes loose Manager/service input acceptable.
Once saved, later WooCommerce regular- or sale-price changes never overwrite or
refresh the Package-specific value.

`PackageAddonService::create()` receives the submitted canonical two-decimal
price. It never reads WooCommerce to derive, replace or repair that value, and no
WooCommerce API call runs in relationship-mutation preflight or the transaction.

### 2.6 `$wpdb->prefix . 'bingo_sessions'`

Hall-owned dated commerce occurrence. Legacy rows have `schedule_id IS NULL`;
generated rows link to an additive Commerce schedule without changing their
existing identity or Package-assignment table.

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
| `schedule_id` | Nullable | Owning Commerce schedule; null for legacy/manual Sessions |
| `start_time_local` | Nullable | Structured local start time for generated occurrences |
| `timezone_name` | Nullable | Captured named-IANA schedule timezone |
| `starts_at_utc` | Nullable | Exact generated UTC instant |
| `sale_locked_at` | Nullable | Durable history guard set when generated sales are first enabled |
| `generation_completed_at` | Nullable | Package propagation completion marker |
| `availability_operation_token` | Nullable | Guarded ON-operation ownership token |

Constraints and indexes:

- Primary key: `session_id`.
- Unique: `(session_date, display_name)`.
- Unique: `(schedule_id, session_date)`; multiple NULL `schedule_id` values keep
  legacy/manual compatibility.
- Index: `(status, available_for_sale, session_date)`.
- Index: `session_date`.
- Index: `starts_at_utc`.
- Structured Commerce time is BingoCommerce-owned and may be entered manually
  by the Hall Manager in v1. Time is never parsed from display names. A schedule
  captures the Hall's named WordPress timezone at creation; that timezone is
  immutable for the schedule's lifetime.
- All user-facing dates use `MM/DD/YYYY`; stored SQL/API/internal canonical
  dates may remain `YYYY-MM-DD`, with conversion at trusted boundaries.
- Time-like display text is never parsed for sales, redemption, closing, or
  scheduling behavior.
- No capacity, order count, customer count, reservation count, or shared
  electronic counter exists on Session.

For a non-archived Session dated `D`, storefront eligibility is:

```text
D - 30 calendar days <= hall_local_today <= D
AND available_for_sale = ON
AND, for a generated occurrence, current committed-definition membership
```

`hall_local_today` uses the hall site's configured WordPress timezone. The
Session becomes ineligible for online sale on the calendar day after `D`.
This sales boundary does not make an otherwise-valid QR unusable when the
Session starts or after the date boundary (BR-056).

The same centralized membership rule is used by guarded ON, purchase
eligibility, Upcoming and administration actions. Removing a strictly future
date is allowed while its occurrence is ON: membership makes it immediately
ineligible and a successful edit turns it OFF. Re-adding a retained occurrence
preserves its identity and Package assignments and leaves it OFF until an
explicit Enable Sales request passes every guard. Existing withdrawal,
sale-lock, archival and historical-retention rules are unchanged; removal does
not introduce automatic archival (BR-077, BR-078).

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
- One Package may be assigned to multiple dated commerce occurrences; no one-
  Package-to-one-occurrence restriction is introduced.

A Package is selectable only when the exact dated occurrence is storefront-
eligible, this exact `(session_id, package_id)` assignment exists, the Package
passes its existing active/integrity/WooCommerce-shell condition, and every
required base component is active (BR-080). An archived required component
blocks only that Package from new customer purchase until restored or
deliberately corrected. The read does not archive the Package, detach a
component, remove an assignment, turn occurrence sales OFF, or alter historical
orders; another valid assigned Package remains selectable. An unreadable
occurrence, assignment, Package, or component dependency fails closed.

This selected-Package predicate is intentionally separate from ADR-043's
occurrence-level `SessionEligibility::is_sellable()` and schedule/occurrence
assignability. The latter continues to allow a preserved archived component and
answers whether the occurrence has at least one assignable Package; it is not a
complete Package purchase validator.

### 2.8 Implemented Additive Portal/Schedule Model (approved ADR-043)

The current repository implements the compatibility model as follows:

- Portal `halls.id` <-> WordPress Site ID;
- Portal `game_types.id` <-> hall-scoped `bingo_game_types` replica;
- Portal `bingo_sessions.id` <-> operational Session template reference;
- `bingo_portal_session_templates` stores the hall-scoped operational-template
  replica keyed by Portal `bingo_sessions.id`;
- `bingo_session_schedules` stores immutable named timezone, structured local
  time, explicit/weekly recurrence and the active definition token;
- `bingo_session_schedule_dates` stores active-token explicit dates; weekly
  definitions use the schedule's inclusive bounds and weekday mask;
- `bingo_session_schedule_packages` stores definition-token Package choices;
- generated BingoCommerce dated commerce occurrences remain in
  `bingo_sessions`; and
- existing `bingo_session_packages` assignments as Package-to-occurrence
  availability where compatible.

The implementation preserves existing domain IDs and relationships, does not use
names/domains/`device_group_id`/XML IDs as identity, and must not assume
`bingo_sessions` is deleted or replaced. ADR-043 is approved and implemented;
the repository state does not claim production deployment.

Only Portal Sessions satisfying
`bingo_sessions.hall_id == mapped halls.id` are eligible for Commerce v1 sync.
Received/shared Sessions and `allSessions()` semantics are excluded. Portal
Session `days` must not drive scheduling, generate occurrences, or constrain or
validate Commerce scheduling in v1; future advisory validation requires a
separately approved requirement. This is an architectural eligibility rule, not
an API query or schema decision.

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
- `bingo_session_schedules.portal_session_id` →
  `bingo_portal_session_templates.portal_session_id`
- definition-token rows in `bingo_session_schedule_dates` and
  `bingo_session_schedule_packages` → `bingo_session_schedules.schedule_id`
- generated `bingo_sessions.schedule_id` → `bingo_session_schedules.schedule_id`

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
- Under the existing local transitional lifecycle, referenced Game Types may be
  archived but not hard-deleted. This is not a Portal v1 Archive/Delete feature.
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

Under the existing local design, Play Product Game Type reference
creation/reassignment and any reconsidered local Game Type hard deletion also
use ADR-032's shared Game Type row lock. Local Game Type hard delete remains
disabled and transitional; it is not part of the Portal v1 lifecycle and may
be reconsidered when Portal synchronization becomes authoritative.
WooCommerce CRUD and all BR-032/BR-065 projection writes remain outside the
Catalog critical transaction.

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

The implemented ADR-044 `PackageAddonService`
recalculates the complete enabled relationship count for that Bingo Play Product
after every authoritative relationship mutation:

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
- The implemented ADR-044 `PackageAddonService` owns add-on
  relationship mutations, enabled-count recalculation, and
  `bc_package_relation` projection maintenance.
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

A targeted Package-add-on reconciliation accepts the current Hall's
`bingo_play_product_id`, derives the expected value from current authoritative
relationships, and internally resolves the Play Product's linked current-Hall
WooCommerce product through the normal product API. It does not accept a
WooCommerce product ID, and a request-supplied WooCommerce product ID is never
reconciliation authority.

No direct-SQL transaction spans custom Catalog rows and WooCommerce metadata.
ADR-025's Hall Staff direct-mysqli transport does not automatically apply.

ADR-044 narrows the repair surface: the Package edit screen offers the same
targeted Bingo-Play-Product-ID Retry synchronization action only after a
projection warning or detected mismatch, not an ordinary reconciliation
dashboard. The bounded current-Hall-wide mismatch scanner is deferred until the
later storefront/readiness phase. This simplifies Increment 1 without changing
authority: `bingo_package_addons` remains authoritative, stale projection
metadata cannot change exact Package eligibility, and targeted repair remains
available when the affected Play Product is known. A later scanner must remain
current-Hall scoped and must not become a normal Hall Manager dashboard.
Increment 1 adds no queue, cron job, table, WP-CLI command or background worker
for reconciliation.

## 5a. Package Composition Atomicity and Stale-Edit Detection

ADR-033 governs Bingo Package composition replacement. Two distinct controls
operate together and are not interchangeable:

```text
parent bingo_packages row lock  =  serialization   (writers cannot interleave)
canonical composition fingerprint =  stale-edit detection (an older form is refused)
```

A row lock alone does not prevent a Hall Manager whose edit form was built from
an older composition from overwriting a newer committed edit: the stale writer
simply waits for the lock and then applies its outdated set. The fingerprint
closes that window.

`bingo_packages.updated_at` is NOT used as the optimistic-concurrency token. The
approved schema stores `datetime`, whose one-second resolution cannot
distinguish rapid consecutive edits.

### Canonical composition fingerprint

The fingerprint is computed identically when an edit form is rendered and again
inside the transaction before any write:

1. Read every current component row for the Package.
2. Canonicalize: sort ascending by `bingo_play_product_id`; from each row take
   exactly `bingo_play_product_id` and `quantity`, both as base-10 integers.
3. Serialize deterministically, one component per record, with a fixed
   separator and no whitespace, locale, or float formatting.
4. Hash the serialization with a stable algorithm to produce an opaque token.

The token is opaque to callers: it is compared for equality only, never parsed,
ordered, or interpreted. `sort_order`, timestamps, and surrogate
`package_component_id` values are excluded, so an administrative reorder or a
row rewrite that preserves the same components and quantities does not
invalidate a pending edit.

### Replacement sequence

```text
BEGIN
  1. SELECT ... FROM bingo_packages WHERE package_id = ? FOR UPDATE NOWAIT
       absent   -> package_not_found
       archived -> package_archived                                 (BR-068)
  2. read current base components; canonicalize and validate their expected
     composition fingerprint
       current != expected -> ROLLBACK, return package_composition_stale
  3. read this Package's enabled bingo_package_addons relationships
       the held Package lock prevents every conforming add-on writer from
       changing them; do NOT acquire an add-on relationship lock before its
       Play Product
  4. build the complete bingo_play_product_id dependency set
       = current base components
         UNION requested final base components
         UNION enabled add-on Play Products
  5. SELECT ... FROM bingo_play_products WHERE bingo_play_product_id IN (...)
       ORDER BY bingo_play_product_id FOR UPDATE NOWAIT
       lock each required Play Product exactly once, in ascending ID order;
       validate base-component shape and lifecycle UNDER THOSE LOCKS:
       empty requested set          -> package_requires_component    (BR-067)
       duplicate play product       -> duplicate_component           (BR-067)
       unknown / cross-hall         -> play_product_not_found
         applies to EVERY id in the step-4 dependency union, including a
         CURRENT-ONLY component this edit would remove: a component whose
         authoritative row has gone missing fails closed and is never
         silently repaired
       newly added but archived     -> play_product_not_active       (BR-067)
       archived existing component,
         quantity changed           -> play_product_not_active
       archived existing component,
         quantity unchanged         -> allowed (retained)            (BR-067)
       quantity out of bound        -> invalid_component_quantity
  6. derive the resulting package_type only from the medium values read under
     lock for the FINAL REQUESTED BASE COMPONENTS
  7. validate every enabled add-on against that resulting Package type using
     its locked Play Product row; missing/corrupt state fails closed
  8. any enabled add-on incompatibility -> ROLLBACK, reject replacement
  9. only after every validation succeeds:
       DELETE component rows no longer requested
       INSERT / UPDATE requested component rows
       UPDATE bingo_packages.package_type, updated_at
COMMIT
(audit catalog.package.components_updated, outside the boundary, after commit)
```

Locking the referenced Bingo Play Product rows is what makes derivation
authoritative. Without it, a concurrent `updateMedium()` could change a
component from Paper to Digital between validation and the `package_type`
write, persisting a Package whose stored type contradicts its own composition
and violating BR-061.

**The lock set and the derivation set are not the same set.** Step 5 locks the
UNION of currently referenced base components, requested base components and
enabled add-on Play Product IDs. A base row being removed must be held still,
and an enabled add-on's medium must remain stable through compatibility
validation. Step 6 derives `package_type` from ONLY the Play Products remaining
in the final requested base set. A component being removed and every add-on are
locked but contribute nothing to the resulting type.

```text
current   = Paper A + Digital B
requested = Paper A

enabled add-on: Digital C

locked     : A, B and C       (complete dependency union)
derived from: A only          (final requested set)
package_type = paper
result      : rejected because enabled Digital C is incompatible with Paper
```

### Medium immutability while Package-referenced (BR-069)

`package_type` is persisted, so a referenced Bingo Play Product that flipped
`medium` would silently invalidate every Package that uses it. BR-069 therefore
forbids a `medium` change while any `bingo_package_components` row references
that Bingo Play Product, in both directions.

The serialization anchor is the `bingo_play_products` row itself:

| Writer | Holds | Then |
|---|---|---|
| `updateMedium` (either direction) | that `bingo_play_products` row | performs the existing locking `bingo_package_components` reference check, then a locking enabled-`bingo_package_addons` reference check; rejects if either blocking reference exists |
| Package create | that `bingo_play_products` row (step 3) | inserts the component row |
| `replaceComponents` | each dependency `bingo_play_products` row (step 5) | validates enabled add-on compatibility, then inserts or retains base-component rows |
| Package add-on create/update/remove | Package row, then that `bingo_play_products` row | probes/locks or mutates the relationship only after both parent anchors are held |

Because every conforming writer takes an exclusive lock on the same Play Product
row before checking or mutating its component/add-on references, the critical
parts cannot overlap. Either the medium change commits first and a later Package
or add-on writer validates the new medium under lock, or a blocking reference
exists first and the medium change is rejected. No interleaving produces stale
Package/add-on medium compatibility. Both relationship tables are written only
after the same `bingo_play_products` row is held.

**Settled mechanism (ADR-034).** Both medium transitions run inside the ADR-032
captured-mysqli transport and serialize on the Play Product's own
`bingo_play_products` row. Paper to Digital already ran there and retains its
Game Type and Package-component checks; Digital to Paper remains out of the
ordinary single-statement `$wpdb` path. Both directions now also perform a
locking enabled-add-on-reference check after the existing component check. Any
enabled add-on relationship blocks the actual transition; disabled add-on
relationships do not block it and are revalidated if later enabled. No Package
row lock is added to either medium-transition path. A component-referenced
medium change remains `play_product_package_referenced`; the enabled-add-on
check returns that same `play_product_package_referenced` code. Structured error
details and the Manager message identify whether base-component references,
enabled add-on references, or both caused the block; no second overlapping error
code is introduced.

Because Package creation, `replaceComponents`, add-on relationship writers and
the medium change all take an exclusive lock on the same
`bingo_play_products` row before touching their relevant references,
`package_type` and enabled add-on compatibility cannot become stale from a
conforming medium change: either the medium change commits first and the later
writer validates the new value under lock, or the reference exists first and
the medium change is refused.

The component-reference and enabled-add-on-reference checks use locking
`SELECT ... FOR UPDATE NOWAIT` reads, not plain `SELECT`s. The Play Product row
is locked before either relationship table. A plain component read was measured
as correct across both REPEATABLE READ and READ COMMITTED in all fourteen
required interleavings, but only while it was the transaction's first consistent
read; when an earlier non-locking read opened the snapshot sooner, it observed
zero references while a committed reference existed and would have permitted the
forbidden change. Locking reads remove that ordering and isolation dependency.

The single guarded autocommit `UPDATE ... AND NOT EXISTS (...)` alternative was
rejected on measured behaviour: safe under REPEATABLE READ, but under READ
COMMITTED it committed the medium change despite a committed Package reference
in three of three runs, because the statement re-evaluated only the row
predicate after waiting and reused a stale subquery result. Production database
defaults are not yet locked (ADR-013), so BR-069 must not depend on an external
isolation-level default.

Other Bingo Play Product mutations remain permitted on a Package-referenced Play
Product, because none of them feeds `package_type`: `faces_per_unit` changes,
Game Type assignment or reassignment, Game Type clearing where otherwise valid,
and archive or restore (whose existing references are preserved under BR-067).

### Package lifecycle (BR-070)

A Bingo Package's lifecycle states are `active` and `archived`. There is no hard
delete in this increment.

Archive and restore are ordinary conditional single-row `$wpdb` writes. They do
NOT revalidate the linked WooCommerce product, do NOT enter the ADR-032
captured-mysqli transport, and introduce no new transaction profile.

The authoritative `bingo_packages` row stays present while archived and keeps its
UNIQUE `wc_product_id` claim throughout, so restore creates, reassigns and
reacquires nothing. There is no claim to re-establish and nothing for a
WooCommerce row lock to serialize against. This is the material difference from
Bingo Play Product restore, which does revalidate the linked product row.

```text
archive  (active -> archived)
  set archived_at, updated_at
  preserve package_id, wc_product_id, package_type, composition
  preserve the BR-065 bc_product_kind = package projection
  (audit catalog.package.archived, after the confirmed write)

restore  (archived -> active)
  clear archived_at, set updated_at
  no Woo revalidation, no composition rederivation
  preserve Package identity and the Woo claim
  (audit catalog.package.restored, after the confirmed write)
```

Archiving an already-archived Package and restoring an already-active Package are
no-ops and emit no mutation audit event. A row holding an unrecognised lifecycle
value fails closed rather than being coerced to either state.

Package lifecycle state is Catalog lifecycle state. It is NOT a statement that
the linked WooCommerce product is currently storefront- or sale-eligible; Session,
storefront and checkout sale eligibility are settled by their own later
requirements.

### Package creation lock order

Package creation runs in the single ADR-032 Package-create profile and never
trusts preflight state:

```text
preflight (outside the transaction): authorize; hall context; Woo eligibility;
                                     component set shape; advisory validation
BEGIN
  1. {prefix}posts row for wc_product_id            FOR UPDATE NOWAIT
  2. bingo_play_products claim by wc_product_id     FOR UPDATE NOWAIT
        present -> play_product_claim_exists
  3. component bingo_play_products rows,
     ascending bingo_play_product_id                FOR UPDATE NOWAIT
        missing  -> play_product_not_found
        archived -> play_product_not_active
        read each row's current `medium` under this lock
  4. bingo_packages claim by wc_product_id          FOR UPDATE NOWAIT
        present -> package_already_linked
  5. derive package_type from the medium values read at step 3
  6. INSERT bingo_packages
  7. INSERT bingo_package_components
COMMIT
(audit catalog.package.created, outside the boundary, after confirmed commit)
```

Concurrency result, stated explicitly:

- if Package creation acquires the component locks and commits first, a later
  Bingo Play Product archive proceeds normally and the existing relationship is
  preserved (BR-067);
- if the archive commits first, Package creation reads the archived state under
  lock at step 3 and rejects the new reference.

Step 4 deliberately follows step 3 so that every `bingo_play_products`
acquisition is contiguous and no `bingo_packages` lock is held while a
`bingo_play_products` lock is still being sought.

### Catalog lock-order review

| Path | Mechanism | Acquisition order |
|---|---|---|
| Play Product create | ADR-032 | posts, game_types, play_products(claim), packages(claim) |
| Play Product restore | ADR-032 | posts, play_products, game_types |
| `assignGameType` | ADR-032 | play_products, game_types |
| `updateMedium` Paper -> Digital | ADR-032 (ADR-034 plus ADR-044 extension) | play_products, game_types, package_components(reference check), package_addons(enabled-reference check) |
| `updateMedium` Digital -> Paper | ADR-032 (ADR-034 plus ADR-044 extension) | play_products, package_components(reference check), package_addons(enabled-reference check) |
| `clearGameType` | ordinary `$wpdb` | one PK-qualified row |
| `archive` | ordinary `$wpdb` | one PK-qualified row |
| `updateFacesPerUnit` | ordinary `$wpdb` | one PK-qualified row |
| Package create | ADR-032 | posts, play_products(claim), play_products(components asc), packages(claim) |
| `replaceComponents` | ADR-033 plus ADR-044 extension | packages, read enabled package_addons under Package anchor without locking relationship rows, play_products(complete dependency set asc) |
| Add-on create | ADR-044 fixed profile | packages, play_products, package_addons(unique relationship probe/insert) |
| Add-on update | ADR-044 fixed profile | packages, play_products, package_addons(relationship row) |
| Add-on remove | ADR-044 fixed profile | packages, play_products, package_addons(relationship row) |
| Package `archive` | ordinary `$wpdb` | one PK-qualified row |
| Package `restore` | ordinary `$wpdb` | one PK-qualified row |

Two relative inversions exist across these paths:

1. Play Product create takes `bingo_game_types` before `bingo_play_products`,
   while `assignGameType`, `updateMedium` and restore take
   `bingo_play_products` first. This inversion is pre-existing and shipped.
2. Package create takes `bingo_play_products` before `bingo_packages`, while
   Play Product create takes them in the same relative order — consistent — but
   `replaceComponents` and every add-on relationship writer take
   `bingo_packages` before `bingo_play_products`.

ADR-044 does not introduce a Play-Product/relationship inversion. Medium
transition locks the Play Product before its enabled-add-on-reference locking
read and never requests a Package lock. Add-on writers lock Package, then Play
Product, then relationship. `replaceComponents` holds the Package anchor while
reading enabled relationships but does not lock those relationship rows before
acquiring the complete ascending Play Product set. All ADR-044 paths use the
existing bounded Catalog conflict retry rules.

**These inversions matter, because the boundary is NOT wait-free.** An earlier
revision of this document asserted that every Catalog statement uses
`FOR UPDATE NOWAIT` and therefore never waits, so no wait-for cycle could form.
Measurement on MySQL 9.5 disproved that assertion and it has been withdrawn.

Only explicit locking READS use `NOWAIT`. `INSERT`, `UPDATE` and `DELETE` have no
`NOWAIT` option, and in-boundary DML both waits and deadlocks between CONFORMING
Catalog writers:

| Measured scenario | Result |
|---|---|
| Two Package creates, DIFFERENT products, empty `bingo_packages` | both `NOWAIT` claim checks succeeded; inserts **deadlocked**, errno **1213** |
| Two Package creates, DIFFERENT products, populated table | no contention |
| Two component inserts, DIFFERENT packages | no contention |
| Two `replaceComponents` deletes, DIFFERENT packages | **lock wait timeout**, errno **1205** |

The Package-create deadlock arises because the claim check
`SELECT ... WHERE wc_product_id = ? FOR UPDATE NOWAIT` matches no row and so takes
a GAP lock. Gap locks are mutually compatible, letting both writers past their
`NOWAIT` checks; the following insert then needs an insert-intention lock, which
conflicts with the other transaction's retained gap. The Woo `wp_posts` anchor
does not help here, because the two writers target different products and
therefore different anchors.

The tested empty-table case exposed contention that was not observed in the
corresponding populated-table run. This case is operationally important because
`bingo_packages` begins empty when Package Increment 1 is first introduced. The
experiment does not establish a universal worst-case table state.

**Designed handling.** Deadlock and lock-wait between conforming writers are
expected. InnoDB aborts one transaction; the bounded retry budget over errnos
1205, 1213 and 3572 resolves it, and a retry generally succeeds because the
competing writer has committed and the gap has closed. That budget is
correctness-bearing for liveness, and retry exhaustion legitimately surfaces as
`catalog_write_conflict`.

The canonical lock order is still required: it reduces cycle frequency and keeps
reasoning tractable. It must not be described as eliminating cycles.

The remaining ordinary `$wpdb` mutation paths are single autocommit statements
rather than BingoCommerce-managed multi-statement transactions. They are not
covered by ADR-032/ADR-033's Catalog transaction retry contract, and this design
makes no general deadlock-freedom claim about InnoDB execution of those
statements.

## 5b. Catalog v3 Rollout Contract for Package Writers

Bingo Play Product Increment 1 shipped a transitional bridge: when
`bingo_packages` does not exist, `BingoPlayProductService` treats the Package
claim as impossible and skips it. A PHP request that made that decision before
the table existed could otherwise still be running when the first Package writer
goes live, allowing one WooCommerce product to be claimed twice.

Rollout is therefore two-stage, and no runtime feature-flag system is
introduced.

**Stage A — compatibility and schema.**

1. Deploy the revised `BingoPlayProductService` bridge, in which an absent
   `bingo_packages` table is fail-closed once the hall reports Catalog v3
   rather than a reason to skip the claim check.
2. Deploy the Catalog v3 migration.
3. Migrate and verify every hall to v3.
4. The Package writer (`PackageService` creation and the Package administration
   screen) is NOT enabled in this stage.

**Stage A is complete when both conditions hold:**

- every hall reports `bc_catalog_db_version = 3` and its full v3 schema
  verifies; and
- every request-serving PHP process or worker is confirmed to be executing the
  Stage A code, established through whatever restart, reload, or rolling
  deployment mechanism the eventual production runtime requires.

Production hosting and runtime are not yet selected (ADR-013 remains open), so
no process manager, restart mechanism, or deployment topology forms part of this
contract. The condition is satisfied by whatever mechanism the actual runtime
requires, determined when that runtime is chosen.

**Stage B — writer enablement.** Only after Stage A completes is the Package
creation writer and its administration screen deployed and enabled.

Because `PackageService` refuses to operate below Catalog v3, and because after
Stage A no worker can still hold a stale "Package table absent" decision, no old
Bingo Play Product request can overlap the first live Package writer.

## 5c. Package Add-ons Increment 1 (ADR-044 / BR-079; Slices 1–3 implemented)

The v6 migration/schema verification and backend service, fixed mutation
profile, lifecycle/medium extensions, audit, projection and targeted
reconciliation are implemented and isolated-tested. Slice 3 implements the
Package Admin UI, nonce/controller boundary, checkbox/bulk orchestration,
regular-price prefill and browser Remove confirmation on the existing Edit
Package screen. Storefront/cart/checkout enforcement remains unimplemented;
no real Hall migration or deployment is claimed.

The add-on slice extends, but does not replace, the existing Catalog boundaries.
Eligibility requires an active Package, active Play Product, enabled relationship
and compatible medium. Paper Packages accept Paper add-ons only; Digital and
Hybrid Packages accept Paper or Digital add-ons. Add-ons never participate in
stored `package_type` derivation, and a Paper Package never gains QR behavior
from an add-on.

Archive preserves relationship rows and enabled values. An archived Package is
read-only for actual mutations until restore. For an archived Play Product, only
explicit disable or remove is permitted as an actual relationship mutation.
Restore changes no relationship state. Disabled relationships preserve price
and maximum; those values may be edited only while both parents are active. A
verified unchanged submission may return the normal no-op result even against
archived state, but only after parent and relationship existence/integrity are
verified. Exact no-ops perform no DML, timestamp change, audit write or
projection rewrite.

`CatalogTransactionTransport` gains one fixed add-on profile, not a generic
transaction abstraction. One relationship mutation locks Package, Play Product,
then relationship; validates an opaque stale fingerprint where applicable; and
executes at most one relationship mutation. Bulk administration invokes that
same operation independently per row and never wraps the selection in one
transaction. Recognized contention receives the existing bounded Catalog retry;
ambiguous COMMIT returns unknown with no retry, projection or audit.

The relationship fingerprint is `hash('sha256', payload)`, returned as 64
lowercase hexadecimal characters. `payload` concatenates fixed-order
`LP(value)` records, where `LP` is the
base-10 ASCII byte length, `:`, then the value. The values are the version marker
`bc-package-addon-fingerprint-v1`, `package_addon_id`, `package_id`,
`bingo_play_product_id`, canonical exactly-two-decimal `unit_price`,
`max_quantity`, and enabled `0`/`1`. Integer fields use canonical base-10 ASCII.
That means digits only, no sign and no leading zero; zero is exactly `0`. The
form renderer and locked recheck use the identical serialization. The token is
opaque; locale formatting, PHP floats, ambiguous delimiter concatenation,
timestamps and `sort_order` are excluded.

ADR-034's medium-transition profile adds a locking enabled-add-on-reference
check, blocking actual transitions in both directions while any enabled add-on
references the Play Product. ADR-033 composition replacement reads enabled
add-ons under its Package lock, adds their Play Products to the ascending lock
set, derives type from final base components only, and rejects a type that would
conflict with an enabled add-on. A Play Product used in both roles is locked once;
base and add-on quantities remain separate.

Audit and WooCommerce projection occur only after confirmed commit. Projection
recounts every enabled current-Hall relationship for the Play Product, writes
through WooCommerce CRUD, verifies, rechecks authoritative state, and uses a
bounded convergence loop when a concurrent mutation changes the expected value.
Failure leaves the Catalog mutation committed and returns an explicit
reconciliation-required warning. Targeted retry accepts
`reconcileProjection(int $bingo_play_product_id)` and internally resolves that
current-Hall row's linked WooCommerce product; it never accepts a request-
supplied WooCommerce product ID as authority. The Hall-wide mismatch scanner is
deferred until the storefront/readiness phase. That later design remains
current-Hall scoped and cannot become a normal Manager dashboard.

The existing Edit Package screen gains one Optional Add-ons section: a
searchable/filterable current-Hall checkbox list with configured relationships
first, including disabled relationships. It always shows every existing
relationship for the Package, including a disabled or currently ineligible row
whose Play Product is archived, unresolved or now incompatible. Those rows are
never silently hidden or removed and remain available for review and for any
lifecycle-permitted disable or remove operation. A configured-but-disabled row
shows `Configured — disabled` and remains distinct from a never-configured
product. For products without a relationship, the candidate list includes only
active, medium-compatible current-Hall Play Products and never exposes another
Hall's products.

| Existing state | Manager action | Result |
|---|---|---|
| Never configured | Leave unchecked | No relationship exists |
| Never configured | Check | Create an enabled relationship |
| Configured and enabled | Leave checked | Remains enabled; exact unchanged submission is a no-op |
| Configured and enabled | Uncheck | Disable while preserving price and maximum |
| Configured and disabled | Leave unchecked | Remains configured and disabled |
| Configured and disabled | Check | Re-enable after lifecycle and medium validation |
| Configured relationship | Explicit Remove | Permanently remove that relationship after confirmation |

Configured rows expose the canonical two-decimal saved Package-specific price
and maximum. Eligible never-configured rows use only the exact WooCommerce
edit-context regular-price prefill contract stated above; saving severs the
dependency. The section is one form with one `Save Add-ons` action. An unchecked
never-configured row is ignored completely: it needs no price or maximum and
causes no operation, validation error, relationship, audit, projection or
timestamp update. Each submitted configured or selected row is one independent
relationship operation; partial success remains permitted and each failed row
keeps its own message. No separate save modes exist.

Unchecking a configured row is reversible disable. Permanent Remove uses simple
browser confirmation only: cancellation sends no request and makes no mutation;
confirmation submits that row's remove operation. No custom modal, framework or
separate confirmation screen is added. The workflow is complete on this single
Edit Package screen and adds no Hall selector, Package-type editor, modal,
wizard, drag-and-drop, separate dashboard, storefront behavior, or ordinary
technical reconciliation control.

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

- The five implemented Catalog v6 tables, including ADR-044's
  `bingo_package_addons` Slice 1 schema.
- Hall-scoped Game Type replica/domain behavior plus Bingo Play Product,
  Package, component, and Package add-on backend services. Slice 3 implements
  the Package Add-ons administration UI and per-row request handling.
- WooCommerce product-link validation and BR-032/BR-065 projections.
- Package composition plus implemented add-on price, quantity, lifecycle and
  compatibility validation.
- Hall override configuration and the effective-limit resolver.
- Starter WooCommerce Product/Bingo Play Product copy logic owned by Catalog
  when that deferred mechanism exists; ADR-042 MVP runs no starter copy.
  Game Types instead synchronize under ADR-035.
- Current per-site Catalog migration/version state:
  `bc_catalog_db_version = 6`, recorded only after all five Catalog schemas
  verify. Existing/new Halls use the same `dbDelta()` path, network catch-up and
  current-Hall self-heal. A malformed pre-existing add-on table fails closed
  without automatic conversion. Verification checks `SHOW FULL COLUMNS`,
  ordered `SHOW INDEX` shape, InnoDB, the expected WordPress collation and
  foreign-key absence. Integer display widths may be omitted by modern MySQL;
  reported widths, storage types, signedness and decimal precision remain exact.
  No service validation, seed rows, WooCommerce writes or projection runs here.

### `bingocommerce-sessions`

Owns:

- `bingo_sessions`, `bingo_session_packages`,
  `bingo_portal_session_templates`, `bingo_session_schedules`,
  `bingo_session_schedule_dates`, and `bingo_session_schedule_packages`.
- Portal-template replicas, Commerce-schedule definitions, occurrence
  generation/reconciliation, the thirty-day window, current-definition
  membership, Available for Sale and Session lifecycle services.
- Package assignment and the non-destructive legacy compatibility path.
- Per-site Session migration/version state: `bc_sessions_db_version = 3`.

### `bingocommerce-platform`

Owns:

- Platform Admin mutation and network-scope initialization/versioning of the
  platform electronic maximum option.
- Under ADR-042: actual WordPress Multisite site creation, WooCommerce/site
  initialization, triggering existing per-site BingoCommerce migrations and
  role provisioning, and returning a structured setup result to
  `bingocommerce-portal-integration`. The ADR-042 MVP does not trigger starter
  Product/Bingo Play Product copy; that Catalog-owned mechanism remains
  separately deferred. Game Type synchronization follows ADR-035.

### `bingocommerce-portal-integration`

Owns Portal-facing machine endpoint authentication/validation,
provisioning-operation persistence and sequencing, Portal Hall mapping
coordination, HallCommerceState/managed-marker coordination, Portal snapshot
synchronization, safe API responses, and integration audit attribution
(ADR-035–ADR-042). The BingoCommerce machine endpoint, operations repository,
orchestrator, platform provisioning filter and automatic site-creation path are
implemented and tested. The Portal Global Admin Enable/Suspend/Re-enable/Retry
controls are implemented and tested. A user-performed local-development Open
Store → Suspend → Re-enable workflow passed against one mapped WordPress
multisite test site, and re-enable reused the same site.

### `bingocommerce-core`

Owns shared migration infrastructure, tenant context, capabilities, and audit
infrastructure only. ADR-026 remains in force: Catalog and Session behavior do
not move into core.

Catalog and Session migrations must be idempotent, provision stale existing
sites only when needed, provision newly created halls automatically, and never
depend on network plugin deactivate/reactivate.

## 8. Deferred Scope

This persistence design deliberately does not decide:

- Portal webhook/reconciliation persistence and protocols.
- SSO persistence/protocol.
- Production deployment of approved ADR-043.

ADR-042 decides the automatic enable/suspend provisioning operations table
(including permanent internal `site_ownership_id`,
`bc_commerce_managed = site_ownership_id`, and
`bc_portal_provisioning_db_version` target 2) and lifecycle at the
integration/platform boundary; that table is not a Catalog/Session persistence
artifact. It and the BingoCommerce-side machine provisioning path are
implemented and tested. The Portal Global Admin Enable/Suspend/Re-enable/Retry
controls are implemented and tested. A user-performed local-development Open
Store → Suspend → Re-enable workflow passed against one mapped WordPress
multisite test site, and re-enable reused the same site. Starter Product / Play Product copy remains
separately deferred for the ADR-042 MVP empty-store scope (narrowing ADR-027 /
BR-047).

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
