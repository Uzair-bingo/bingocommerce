# Technical Architecture — BingoCommerce

Current-state technical spec. References ADR IDs from `docs/decisions/DECISIONS.md`
for rationale/history — this file describes *what*, the decision log explains *why/when*.

---

## Stack
| Layer | Choice | ADR |
|---|---|---|
| CMS / Network | WordPress 7.0.x Multisite, subdomain mode | ADR-001, ADR-003 |
| Commerce | WooCommerce, HPOS enabled | ADR-001, ADR-004 |
| Runtime | PHP 8.4 | ADR-017 |
| Database | MySQL 9.5 (InnoDB) in current local development; production
  version requires compatibility verification before deployment | ADR-017 |
| Object cache | Redis — approach locked; specific managed-service provider open | ADR-007, ADR-013 |
| Media storage | S3-compatible object storage + CDN — approach locked; specific provider open | ADR-009 |
| Payment | WooCommerce native pluggable gateway API — no hardcoded provider | ADR-010 |

## Application Flow
```
Request -> hallname.parent-domain.com
  -> DNS wildcard -> web server
  -> WP Multisite resolves subdomain to hall site (native)
  -> WooCommerce loads that hall's own products/cart/orders
     -> Physical product / ordinary merchandise: cart -> checkout -> gateway -> order (HPOS)
     -> Bingo Package (Paper / Digital / Hybrid-Combo): customer selects an
        eligible dated Session (exact date + Session name) before selecting
        the Bingo Package
        -> implemented server-side cart validation and electronic-limit checks
           at cart addition and classic/Store API checkout; storefront/theme
           selection screens remain pending
        -> order records exact Session, Package, add-on links/quantities and
           saved digital-face totals by Game Type; Woo line amounts, discounts
           and taxes remain the financial record (BR-083)
        -> after confirmed positive-total online payment (BR-053): save the
           immutable Hall-local entitlement snapshot and issue one stable QR
           reference for an eligible Digital/Hybrid Package; Paper-only and
           ordinary merchandise get none (BR-043/BR-044)
        -> authorized customer order view renders the QR; paid-order email
           provides a link to that view, not an embedded QR
        -> Game Operator scanning, Android redemption, device binding and
           accumulated per-tablet usage remain future work (BR-041/BR-045/BR-055)
```
Platform administration uses WordPress Network Admin as the trusted entry
context, with BingoCommerce custom network-level modules/services implementing
hall provisioning, billing lifecycle, retention/deletion, cross-hall reporting,
and other platform-specific operations.

## Portal Integration Boundary

ADR-035 adds the existing Laravel Bingo Innovation Portal as the authority for
Hall identity, Game Type identity/configuration, and operational Session-template
identity. BingoCommerce remains the authority for WooCommerce/catalog commerce,
commerce schedules and structured time, exact dated occurrences, Package
availability, sales eligibility, checkout/orders/payments, and Hall-local
entitlement/ticket state.

Within the single authoritative Bingo Innovation Portal environment, stable
mappings use `halls.id` <-> WordPress Site ID and `game_types.id` <-> a hall-
scoped BingoCommerce Game Type replica; `bingo_sessions.id` identifies the
operational Session template. Names/domains are never cross-system identities.
`device_group_id` and XML-generated GameGroup identifiers are never
BingoCommerce integration identities. Portal Game Types may be created and
renamed, with rename preserving `game_types.id`; deletion is prohibited once
created, and Portal Archive/Restore is not required in v1.

Only owned Sessions satisfying
`bingo_sessions.hall_id == mapped halls.id` are v1 sync candidates;
received/shared Sessions and `allSessions()` semantics are excluded. Portal
Session `days` are independent from Commerce scheduling and must not drive it,
generate occurrences, or constrain/validate it in v1. Future advisory
validation requires a separately approved requirement.

The Session model separates Portal template, BingoCommerce commerce schedule,
and BingoCommerce dated occurrence. Orders eventually reference the exact dated
occurrence. Structured Commerce time belongs to BingoCommerce and may be entered
manually by the Hall Manager in v1; time is never parsed from display names.
One Package may be assigned to multiple dated commerce occurrences. The
compatibility review preserved the existing Game Type and Session tables and
added separate Portal-template and Commerce-schedule persistence in Session
schema v3. ADR-043 is approved and implemented; the implementation exists in
the current repository and production deployment is not claimed.

Approved delivery order (ADR-042 / BR-076) is:

1. authenticated Portal snapshot read and full-pull synchronization — complete;
2. idempotent Hall/Game Type/Session mapping and multi-Hall proof — complete;
3. manual Hall-scoped synchronization — complete;
4. BingoCommerce-side automatic Commerce enable/suspend provisioning —
   implemented and tested;
5. Portal Global Admin Enable/Suspend/Re-enable/Retry controls — implemented
   and tested; a user-performed local-development Open Store → Suspend →
   Re-enable workflow passed against one mapped WordPress multisite test
   site, and re-enable reused the same site;
6. SSO — later;
7. Portal webhooks — later; Commerce scheduling and occurrence reconciliation
   exist under approved ADR-043.

`Enable BingoCommerce` / Disable Ecommerce is a separate Portal Global
Admin-only platform capability (BR-075, BR-076). ADR-042 decides the machine
REST contract (`api_version` exact `"1"`; custom schema only after BingoCommerce
callbacks; core malformed JSON / route-method bodies retained), Hall-scoped
provisioning mutex and stale-command fencing, stored-row validation before
classification, durable-vs-request command columns, permanent internal
`site_ownership_id` with `bc_commerce_managed = site_ownership_id`,
eight-record fingerprint, empty-store MVP, pre-bind cancellation and
marker-matched partial-site suspend with mapping absent/exact/conflict rules,
recoverable partial create, verified `bc_portal_provisioning_db_version=2`
migration with pre-mutex readiness gate, independent UNIQUE-constraint
handling, closed HTTP/audit matrices, billing-neutral MVP, App Password proof,
Hall site admin via `BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID`, and plugin
ownership (`bingocommerce-portal-integration` orchestrates;
`bingocommerce-platform` create/init only when operational create/init is
required). WordPress Platform Admin / Network Super Admin do not initiate
automatic Enable/Disable. See
`docs/architecture/portal-integration-architecture.md`.

## Data Architecture
- Each hall has site-scoped products, orders, WooCommerce settings, and
  hall-specific commerce/customer records.
- WordPress user identity is network-shared (`bc_users` / `bc_usermeta`);
  hall membership, roles, orders, merchant/payment context, and commerce
  records remain hall-scoped.
- Digital tickets and entitlements: implemented Hall-local tables use the
  current site's `$wpdb->prefix`, not a `blog_id` column. `bingo_entitlements`
  stores the immutable order/session/package/selection snapshot and digital
  faces; `bingo_entitlement_voids` stores terminal refund/cancellation markers;
  `bingo_tickets` stores one opaque token and issue time per entitlement. A
  Paper-only Package has no entitlement or ticket; an eligible paid Digital or
  Hybrid Package gets one QR reference for its digital entitlement only
  (BR-043/BR-044). Android device binding, redemption records and staff
  reactivation history remain future storage/design work; do not treat proposed
  `blog_id`, device, redemption or reactivation fields as existing ticket-table
  columns (BR-010/BR-045/ADR-029/ADR-030).
- Catalog/Session persistence (ADR-031, narrowed by ADR-035 and extended in the
  current repository by approved ADR-043): WooCommerce
  products are commerce shells. The current implementation has eleven per-site
  custom tables: five Catalog v6 tables (`bingo_game_types`,
  `bingo_play_products`, `bingo_packages`, `bingo_package_components`,
  `bingo_package_addons`) and six
  Session v3 tables:
  `bingo_sessions`, `bingo_session_packages`,
  `bingo_portal_session_templates`, `bingo_session_schedules`,
  `bingo_session_schedule_dates`, and `bingo_session_schedule_packages`, each appended to
  `$wpdb->prefix`. ADR-044 Slice 1 implements Catalog v6 migration and schema
  verification; Slice 2 implements `PackageAddonService`, its fixed mutation
  profile, integrity extensions, post-commit audit/projection and targeted
  reconciliation. Slice 3 implements Package administration UI and request
  handling. The customer cart backend and electronic-limit checks at cart
  addition and classic/Store API checkout are implemented; storefront theme and
  selection screens remain pending. No real Hall migration, deployment or
  production readiness is claimed.
  Game Type, Bingo Play Product, Package, and Session use local
  surrogate domain IDs; Portal `game_types.id` is the trusted cross-
  system Game Type identity, and the local Game Type row becomes its hall-scoped
  replica. Linked WooCommerce product IDs are unique mappings.
  One WooCommerce product ID cannot be linked simultaneously as a Package and
  Bingo Play Product. ADR-032 serializes that exclusivity through the narrow
  Catalog critical-serialization transport, using the current-site Woo product
  row as the shared anchor; standalone cross-table check-then-insert remains
  prohibited. Play Product/Game Type reference creation and future Game Type
  hard delete serialize on the Game Type row under the existing local design,
  although hard delete remains disabled and is transitional—not a Portal v1
  lifecycle feature. WooCommerce CRUD and projection writes remain outside the critical
  transaction. Detailed conceptual columns, indexes, relationships, and rules
  are in `docs/architecture/catalog-session-persistence-design.md`.
- Bingo Catalog domain (BR-037/BR-039/BR-040/BR-061/BR-062/BR-071/BR-072/BR-079,
  ADR-026/ADR-031/ADR-035/ADR-044):
  Game Type, Bingo Play Product metadata, and Package/component/add-on
  definitions are never inferred from a WooCommerce title, SKU, tag, or
  category. Portal owns Game Type identity/configuration and Portal
  `game_types.id` is cross-system identity; the local surrogate and machine code
  remain internal compatibility identifiers. The linked Package product's native
  WooCommerce price is the Package base-price authority; the Package/add-on
  relationship owns its canonical, exactly-two-decimal `DECIMAL(19,2)` unit
  price and 1–99 maximum quantity. While preparing an eligible
  never-configured row outside the transaction, the UI loads
  `\wc_get_product( $wc_product_id )`, requires `\WC_Product`, and treats
  `$product->get_regular_price( 'edit' )` as a string-only prefill. It never uses
  `get_price()`, sale/effective price, direct meta, formatted/tax-adjusted output,
  filtered `view` context or floats; invalid precision leaves the field blank.
  Existing relationships keep their saved independent price. Package type is
  validated from base components only; optional add-ons do not change it. Paper
  Packages accept only Paper add-ons and never gain QR behavior from one;
  Digital and Hybrid Packages
  accept either medium.
- BR-032 product metadata projection (BR-065/ADR-031): `bc_product_kind` and
  `bc_package_relation` are derived compatibility/cart-enforcement projections,
  not Catalog authorities. `PackageService` and `BingoPlayProductService` own
  their implemented projection writes. ADR-044 add-on projection writes are
  implemented by `PackageAddonService`; Package-specific eligibility
  comes only from `bingo_package_addons`. Final
  cart/checkout checks use Catalog state. Projection synchronization is deterministic,
  idempotent, verifiable, and reconcilable; no direct-SQL transaction spans
  Catalog rows and WooCommerce metadata, and ADR-025 does not automatically
  apply. The initial model uses indexed logical relationships with restrictive
  service validation, not database cascades/physical foreign keys.
  Increment 1 retry is targeted by current-Hall `bingo_play_product_id` and
  internally resolves the linked WooCommerce product. The Hall-wide mismatch
  scanner is deferred until storefront/readiness work and must remain
  current-Hall scoped.
- Package add-on concurrency (ADR-044): one fixed profile inside the existing
  `CatalogTransactionTransport` locks Package, Play Product, then relationship;
  uses stale fingerprints and bounded conflict retries; and preserves the
  existing fail-closed ambiguous-COMMIT contract. Enabled add-ons also
  participate in both-direction Play Product medium-transition integrity and in
  Package composition replacement compatibility checks. Either a base-component
  or enabled-add-on medium blocker returns `play_product_package_referenced`
  with cause-identifying details. Audit and WooCommerce
  projection remain outside the transaction after confirmed commit. Multi-row
  administration invokes one relationship operation at a time and returns one
  result per row; it never uses one bulk transaction.
- Session domain (BR-041, ADR-028, BR-050–BR-052, BR-063, BR-073, ADR-035):
  Portal `bingo_sessions.id` identifies the Portal-owned operational template.
  Only templates satisfying `bingo_sessions.hall_id == mapped halls.id` are
  sync-eligible. BingoCommerce
  owns a separate commerce schedule and exact dated occurrences; the local
  occurrence identity remains hall-scoped/per-site. Sessions/occurrences no
  longer carry a capacity counter. The portal-integrated schedule has structured
  commerce time, which may be entered manually by the Hall Manager in v1, while
  display-name time remains a label that never drives automatic logic. One
  Package may be assigned to multiple occurrences. For occurrence date `D`, storefront
  date eligibility is inclusive:
  `D - 30 calendar days <= hall_local_today <= D`, using the captured named
  schedule timezone for generated occurrences, and Available for Sale must also
  be ON (BR-051/BR-063/BR-077). A generated occurrence must additionally belong
  to its schedule's current committed definition; the same centralized rule
  governs guarded ON, purchase eligibility, Upcoming and administration.
  Removing a strictly future date is allowed while ON: membership immediately
  fails closed and a successful edit turns it OFF. Re-adding a retained
  occurrence preserves identity and Packages and leaves it OFF until explicitly
  enabled. Existing withdrawal, sale-lock, archival and history rules are
  unchanged (BR-078). Schedules capture a named WordPress timezone at creation
  and keep it immutable. User-facing dates use `MM/DD/YYYY`; database, SQL, API
  and internal canonical values may remain `YYYY-MM-DD`. New Sessions default
  to OFF. Customers select the Session
  before the Bingo Package; the order stays associated with that exact Session
  (BR-052). The sales boundary does not expire an otherwise-valid QR (BR-056).
- Electronic card limit (BR-038/BR-042/BR-064, ADR-028/ADR-031): a configurable, per-Game-Type
  maximum — network-level platform maximum with an optional per-site hall
  override, evaluated dynamically (`effective_limit = min(hall_override,
  current_platform_maximum)` when an override exists, else the platform
  maximum, always using the platform maximum's *current* value). Platform Admin
  controls the network option; an authorized Hall Manager may configure the
  site option; `bingocommerce-catalog` owns the resolver. Enforced
  authoritatively server-side at checkout, aggregating digital faces by Game
  Type; Paper never participates. This replaces the retired session-wide
  qualifying-order capacity model (superseded ADR-006).
- Purchase-time entitlement snapshot (BR-043, ADR-029, BR-053): after confirmed
  positive-total online payment, Digital Tickets saves the immutable per-Game-
  Type face map from the order's `bc_purchase` facts; it never recalculates from
  later Catalog state. The implemented per-Hall `bingo_entitlements` table has
  a unique `order_id`, exact `session_id`, `package_id`, `selection_id`,
  `snapshot_version`, `digital_faces_json`, status and confirmation time.
  `bingo_entitlement_voids` stores indexed terminal refund/cancellation facts;
  `bingo_tickets` stores one stable opaque token per entitlement. These tables
  use the current Hall's `$wpdb->prefix`. QR lookup requires both active
  entitlement status and absence of a terminal marker. The authorized customer
  order view renders the QR, and eligible paid-order emails link to that view;
  no embedded QR or separate QR email sender exists. Zero-total orders and
  unknown/invalid historical totals do not create entitlements. Do not hardcode
  a WooCommerce order-status name as the payment-confirmation rule.
- Redemption accumulation (BR-045, BR-055, BR-073, ADR-030/ADR-035) remains
  future work: Android caller/device binding, Game Operator scanning and
  redemption are not implemented. The approved future scope is Hall + exact
  dated occurrence + device + Game Type, with each Package/receipt accepted or
  rejected as a whole against the effective electronic limit. A Session
  starting does not automatically expire a valid QR (BR-056).
- Reporting facts (BR-054): purchase/payment date and Session attribution are
  separate. Do not move a Monday payment for Tuesday's Session onto Tuesday's
  payment/accounting date.
- Do not introduce an All Game Types Game Type or combined Game-Type bucket
  (BR-057). Do not design per-game play-history storage as part of this
  feature (BR-058). Do not expand into separate jackpot/additional-game
  purchase flows in the current implementation (BR-059).
- Billing status: status field per hall site + append-only change log (who/when/why)

## Performance
- **Redis multisite gotcha (ADR-007)**: default plugin behavior flushes the entire
  cache network-wide on any single hall's cache-clear event. Must configure
  `WP_REDIS_SELECTIVE_FLUSH` + distinct `WP_REDIS_PREFIX` per site before any
  multisite testing. At production scale, Object Cache Pro is the stronger option
  (proper per-group invalidation).
- Full-page caching never applies to cart/checkout/account/QR-redemption/admin
  (ADR-008) — these stay dynamic. Page caching (e.g. WP Rocket) and object caching
  (Redis) are complementary layers, not substitutes for each other.
- HPOS keeps order-heavy queries fast as volume grows per hall.
- Hall-owned per-site custom tables use `$wpdb->prefix` as their tenant
  boundary and do not add a redundant `blog_id`. Tenant-attributed network
  tables index their hall/site reference. Truly network-global tables/records
  must not invent a fake hall/site reference merely to satisfy this rule
  (ADR-022/ADR-031).
- PHP-FPM + OPcache required.

## Image & Media Handling
- Offload to S3-compatible storage (S3/R2/Spaces) — never local disk long-term.
- Plugin must have confirmed, explicit Multisite support (per-site path
  namespacing, e.g. `sites/{blog_id}/`) — not all offload plugins handle this correctly.
- Format: AVIF with WebP fallback.
- Served via CDN.

## Security
- **Human auth**: mandatory 2FA (TOTP minimum) for Hall Manager, Game Operator,
  Platform Admin (ADR-012).
- **Machine auth**: WooCommerce Consumer Keys (per-integration, scoped Read/Write)
  for WooCommerce-specific integrations; WordPress core Application Passwords for
  non-WooCommerce REST integrations (ADR-011).
- **REST hardening**: every custom endpoint requires explicit `permission_callback`
  + `sanitize_callback` on every parameter. Every `$wpdb` query uses `->prepare()`.
  Disable unauthenticated `/wp/v2/users` (prevents Hall Manager username enumeration).
- **Edge/app layer**: one WAF (e.g. Cloudflare) + one endpoint security plugin —
  never stack two overlapping security suites (duplicate scans, conflicting rules).
- **Payment data**: card data never transits/stored on our server unencrypted —
  gateway hosted fields/SDK only. Code-review checkpoint before launch confirming
  no card field is ever logged or persisted.
- **Config hardening**: `wp-config.php` above web root where possible,
  `DISALLOW_FILE_EDIT`, `FORCE_SSL_ADMIN`, DB credentials via environment variables.
- **Hall isolation**: every hall-scoped query, cache key, and file path carries
  verified tenant context. Per-site tables use the resolved `$wpdb->prefix` as
  the isolation boundary; tenant-attributed network rows include indexed
  `site_id`/`blog_id`. Cross-hall data leakage is the highest-severity bug class.

## Hosting Requirements
Must explicitly support (confirm before committing to a provider, don't assume):
- WordPress Multisite (not default on every managed WP plan)
- Support for a production-grade persistent object-cache service if/when enabled
- Staging environment separate from production — a bad plugin/core update is
  network-wide, hitting every hall simultaneously; always stage first
- Automated backups with point-in-time restore

## Anti-Patterns — Do Not Do
- Do not share one Redis instance across halls without selective-flush configured
- Do not apply plugin/core updates directly to production
- Do not build custom auth for POS/machine integrations — WooCommerce/WP core
  already provide this
- Do not rely on full-page caching for anything logged-in or cart-related
- Do not store product images on local disk as a long-term plan
- Do not edit WordPress core, WooCommerce core, or third-party plugin source files
- Do not put business logic in themes — themes render presentation only

## Still Open (do not build against an assumed answer)
- Offline QR/Package usability **technical implementation** (local-server /
  cache / network architecture). The business expectation is locked by
  BR-060; do not invent that architecture in this pass.
- Final payment gateway / POS hardware provider (Clover under discussion)
- Object storage provider (AWS S3 vs. Cloudflare R2 vs. other)
- Overall hosting provider
- Parent domain name
- Portal webhook contracts, transitional synchronization conflict policy and
  SSO security protocol. The BingoCommerce-side ADR-042 provisioning path is
  implemented and tested. The Portal Global Admin Enable/Suspend/Re-enable/Retry
  controls are implemented and tested. A user-performed local-development
  Open Store → Suspend → Re-enable workflow passed against one mapped
  WordPress multisite test site, and re-enable reused the same site.
- Production deployment of approved ADR-043. Its
  non-destructive Session schedule/occurrence implementation exists in the
  current repository.
