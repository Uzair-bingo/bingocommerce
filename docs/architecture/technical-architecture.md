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
        the Bingo Package; cart -> checkout -> gateway -> order (HPOS)
        -> Checkout aggregates digital faces per Game Type against the effective
           electronic-card limit (BR-038/BR-042) -- server-side, authoritative
        -> when online payment is successfully confirmed (BR-053;
           do not hardcode a particular WooCommerce order-status name as the
           business rule):
              -> freeze purchase-time entitlement snapshot (BR-043)
              -> Digital/Hybrid packages: issue exactly one digital ticket/QR
                 for the digital entitlement (custom table); Paper-only
                 packages issue no QR (BR-044)
        -> Game Operator scans -> REST endpoint validates -> marks redeemed
           (a valid QR remains redeemable after Session start; late redemption
            during the Session is allowed -- BR-056)
        -> Redemption confirms/records the player's digital entitlement for
           that Session (BR-041, BR-045, BR-055); Android uses that entitlement later
           when the applicable Game Type(s) are actually played -- redemption
           does not itself launch or change any game
```
Platform administration uses WordPress Network Admin as the trusted entry
context, with BingoCommerce custom network-level modules/services implementing
hall provisioning, billing lifecycle, retention/deletion, cross-hall reporting,
and other platform-specific operations.

## Data Architecture
- Each hall has site-scoped products, orders, WooCommerce settings, and
  hall-specific commerce/customer records.
- WordPress user identity is network-shared (`bc_users` / `bc_usermeta`);
  hall membership, roles, orders, merchant/payment context, and commerce
  records remain hall-scoped.
- Digital tickets: custom indexed table (exact final schema is a later
  technical-design decision per ADR-029/ADR-030). Conceptually needs to track:
  an unguessable ticket reference, the order it was issued from (one ticket
  per Digital/Hybrid order, not per line item — BR-044), hall/site_id, status
  (issued/redeemed/void), the redeeming device/tablet identity (BR-045's
  `caller_id` is the confirmed Android tablet identity — do not lock a
  differently-named device-identity column ahead of the technical-design
  pass), redemption time, and reactivation history (count, last timestamp,
  last staff actor — BR-010). A Paper-only Bingo Package never creates a
  ticket row; Digital and Hybrid/Combo packages create exactly one, covering
  the digital entitlement only (BR-044, ADR-030).
- Bingo Catalog domain (BR-037/BR-039/BR-040, ADR-026): Game Type, Bingo Play
  Product metadata, and Package/Package-Component/add-on-eligibility definitions
  are BingoCommerce-owned domain entities, never inferred from a WooCommerce
  product title, SKU, tag, or category at runtime.
- Session domain (BR-041, ADR-028, BR-050–BR-052): Session is hall-owned/per-site; tenant
  context + Session ID is the canonical identity. No network-wide Session
  registry is required in v1. Sessions no longer carry a capacity counter.
  A Session has an exact calendar date; display-name time is a label only and
  does not drive automatic logic (BR-050). Storefront appearance requires the
  automatic 5-day sales window AND Available for Sale = ON (BR-051). Customers
  select the Session before the Bingo Package; the order stays associated with
  that exact Session (BR-052). Week/month future-Session browsing is not a
  current requirement.
- Electronic card limit (BR-038/BR-042, ADR-028): a configurable, per-Game-Type
  maximum — network-level platform maximum with an optional per-site hall
  override, evaluated dynamically (`effective_limit = min(hall_override,
  current_platform_maximum)` when an override exists, else the platform
  maximum, always using the platform maximum's *current* value). Enforced
  authoritatively server-side at checkout, aggregating digital faces by Game
  Type; Paper never participates. This replaces the retired session-wide
  qualifying-order capacity model (superseded ADR-006).
- Purchase-time entitlement snapshot (BR-043, ADR-029, BR-053): frozen
  per-Game-Type digital entitlement captured when online payment is
  successfully confirmed (BR-053); never recalculated from later, mutable
  Product/Package/Game Type state. Follows ADR-005's general
  custom-indexed-table principle (not the retired session-capacity
  mechanism — see ADR-028); exact schema is a later technical-design
  decision. Do not hardcode a WooCommerce order-status name as the
  business activation rule.
- Redemption accumulation (BR-045, BR-055, ADR-030): scoped by hall/tenant + session_id
  + Android caller_id + Game Type; multiple valid Digital Packages for the same
  Session may redeem onto the same tablet and accumulate independently by Game
  Type. An incoming Package/receipt is accepted or rejected as a whole against
  the effective electronic limit — no partial redemption. A Session starting
  does not automatically expire a valid QR (BR-056).
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
- Every hall-owned / tenant-attributed custom table indexes its hall/site
  reference column. Truly network-global tables/records must not invent a
  fake hall/site reference merely to satisfy this rule (ADR-022). Tenant
  isolation remains strict wherever tenant attribution exists.
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
- **Hall isolation**: every hall-scoped query, cache key, table row, and file path
  includes `site_id`/`blog_id`. Cross-hall data leakage is the highest-severity bug
  class in this platform.

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
