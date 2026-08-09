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
| Database | MySQL 9.5 (InnoDB) — WooCommerce officially tested to 9.0, 9.5 unverified but expected compatible | ADR-017 |
| Object cache | Redis — approach locked, vendor open | ADR-007 |
| Media storage | S3-compatible object storage — approach locked, vendor open (AWS S3 vs. R2 vs. other) | ADR-009 |
| Payment | WooCommerce native pluggable gateway API — no hardcoded provider | ADR-010 |

## Application Flow
```
Request -> hallname.parent-domain.com
  -> DNS wildcard -> web server
  -> WP Multisite resolves subdomain to hall site (native)
  -> WooCommerce loads that hall's own products/cart/orders
     -> Physical product: cart -> checkout -> gateway -> order (HPOS)
     -> Digital product: cart -> checkout -> gateway -> order (HPOS)
        -> Ticket system generates one QR per order (custom table)
        -> Game Operator scans -> REST endpoint validates -> marks redeemed
        -> Android app loads games
```
Platform Admin actions (billing status, cross-hall product edits) route through
WordPress Network Admin — native cross-site capability, no custom layer.

## Data Architecture
- Each hall = separate Multisite site, own table set (own prefix) — native isolation
  for products, orders, customers, settings.
- Digital tickets: custom indexed table. Fields: ticket_token (unique, unguessable),
  order_id (unique — one ticket per order, not per item), hall/site_id, status
  (issued/redeemed/void), redeemed_device_id, redeemed_at, reactivated_count,
  last_reactivated_at, last_reactivated_by_staff_id.
- Capacity enforcement is two-tier (ADR-006):
  - Per-package: WooCommerce native stock management
  - Session-wide shared cap: custom indexed table, counts qualifying orders
    (see BR-002/BR-025 in requirements — one order with multiple digital items
    still counts as one qualifying order), validated atomically at checkout
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
- Every custom table indexed on the hall/site reference column.
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
- Redis as a managed service
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
- Offline handling strategy for digital ticket redemption
- Final payment gateway / POS hardware provider (Clover under discussion)
- Object storage provider (AWS S3 vs. Cloudflare R2 vs. other)
- Overall hosting provider
- Parent domain name
