## 1. Plugin Suite

Small, single-responsibility plugins — never one monolith. Each must be
independently activatable and testable.

| Plugin | Responsibility | Depends on |
|---|---|---|
| `bingocommerce-core` | Roles & capabilities, tenant context helpers, audit log service, migration framework | WooCommerce (network) |
| `bingocommerce-sessions` | Session entity, day/date/sales-window availability, capacity counter | core |
| `bingocommerce-digital-tickets` | Digital entitlement, QR issue/redeem, device binding, staff reactivation, Android REST API | core, sessions |
| `bingocommerce-platform` | Hall provisioning pipeline, billing lifecycle (BR-016), retention/deletion (BR-017), network reporting | core |

Theme: Phase 1 standard storefront in `wp-content/themes/bingocommerce-*`.
Presentation only — zero business logic (AGENTS.md rule 2).

## 2. Internal Structure (every plugin)

```
bingocommerce-core/
├── bingocommerce-core.php     # Bootstrap only
├── src/
│   ├── Plugin.php             # Plugin orchestrator
│   ├── HallContext.php
│   ├── Roles.php
│   ├── AuditLog.php
│   └── Migrations.php
└── uninstall.php
```

Rules:
- Namespace: `BingoCommerce\Core` (matching `BingoCommerce\<PluginName>` convention)
- Class loading may use explicit `require_once` while the plugin is small.
- Move to a PSR-4-style autoloader when the class surface grows enough to justify it.
- Do not introduce Composer solely for class loading at this stage.
- Nothing executes on file include; everything registers via hooks
- Class files: `*.php` inside `src/`; one concern per class

## 3. Multisite Activation Contract (critical)

Network-activated plugins must install per-site artifacts (roles, options,
tables) correctly in BOTH situations:

1. **Activation on an existing network:** loop existing sites
   (`get_sites( [ 'number' => 0 ] )` + `switch_to_blog()`) and install on each.
   Always `restore_current_blog()` (try/finally).
2. **New hall provisioned later:** hook `wp_initialize_site` and install on the
   new site automatically.

Test for both paths is mandatory. A feature that works on Sunrise but breaks
on the next hall created is a failed feature.

## 4. Migration Framework (core owns this)

- Table creation/updates via `dbDelta()` only
- Every plugin storing tables tracks `bc_<plugin>_db_version` option per site
- Migrations run idempotently: safe to run twice, safe at network scale
- Table naming per ADR-018: `$wpdb->prefix . '<name>'` per-site,
  `$wpdb->base_prefix . '<name>'` network-wide; names must not collide with
  WordPress Core or WooCommerce tables.
- Every table has an indexed site/session/order reference column as applicable

## 5. Capability Model (core owns this)

- Custom roles: `bc_hall_manager`, `bc_game_operator`
- Phase 1 platform administration is performed by trusted network-level
  administrators.
- Native WordPress Super Admin remains reserved for trusted platform operators.
- Hall staff must never receive Super Admin privileges.
- If platform operations later require a restricted operational-admin layer,
  implement it through explicit capabilities/UI rather than granting unnecessary
  network privileges.
- Custom capabilities prefixed `bc_` (e.g. `bc_manage_sessions`,
  `bc_redeem_tickets`, `bc_reactivate_tickets`)
- Roles are per-site in Multisite (`{prefix}user_roles` option) — installation
  follows the Multisite Activation Contract above
- All authorization checks: `current_user_can( 'bc_...' )` — never role-name
  comparisons (AGENTS.md rule 5.5/7)

## 6. Service Boundaries

- Core services are accessed through small static facades or service getters —
  feature plugins never duplicate tenant/audit logic
- Cross-plugin communication via documented hooks (`do_action`/`apply_filters`
  with `bingocommerce_` prefix), never by calling another plugin's internals
- No business logic in themes; no presentation logic in core services