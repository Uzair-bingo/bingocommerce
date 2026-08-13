# BingoCommerce — Project Status

*Living document. Update at the END of every work session. Keep it short, factual, and chronological.*

---

## Current Phase
**Phase 0 — Local Environment & Project Foundation**

---

## Done
- **[2026-08-07]** Local stack verified: PHP 8.4.14 (Homebrew), MySQL 9.5, WordPress 7.0.3 installed at repo root (unconfigured).
- **[2026-08-07]** Repository structure created: `docs/` (`architecture/`, `decisions/`, `requirements/`, `runbooks/`, `ui-ux/`).
- **[2026-08-07]** Decisions locked in `docs/decisions/DECISIONS.md`:
  - `ADR-001`: Stack (WP Multisite Subdomain mode + WooCommerce HPOS).
  - `BR-001`: Address book shared across halls; payment tokens isolated per hall.
  - `BR-002`: Session capacity = total qualifying orders per session.
  A qualifying order is a completed order containing at least one digital package for that session.
  Multiple digital packages in one order still count as one qualifying order.
- **[2026-08-07]** AI instruction & memory layer created: `AGENTS.md`, `CLAUDE.md`, `README.md`, `.claude/`.
- **[2026-08-10]** Verified native WordPress Multisite site-membership isolation:
  a user assigned to Sunrise was denied access to Tampa. This established the
  underlying Multisite authorization boundary before custom BingoCommerce roles
  were implemented.
- **[2026-08-10]** Verified WooCommerce tenant isolation across Sunrise
  (site ID 2) and Tampa (site ID 3):
  - products remain isolated to site-specific WordPress tables
  - HPOS order headers remain isolated per hall
  - billing/shipping addresses remain isolated per hall
  - operational order data remains isolated per hall
  - order line items and item metadata remain isolated per hall
  - Tampa admin UI does not expose Sunrise products or orders
  Result: WooCommerce/HPOS tenant isolation PASS.
- **[2026-08-11]** Implemented and manually verified BingoCommerce hall roles
  and capability provisioning:
  - `bc_hall_manager`
  - `bc_game_operator`
  - versioned/idempotent provisioning for existing hall sites
  - automatic provisioning for newly created hall sites
  - primary network site excluded
  - Hall Manager capability and cross-hall/network isolation verified
  - Game Operator minimal capability and cross-hall/network isolation verified
  - plugin/theme installation and role escalation denied
  - approved Hall Manager storefront customization remains available
  - future-site provisioning verified using Orlando (site ID 4)
  Result: Role & Capability Isolation PASS.
- **[2026-08-12]** Doc-consistency pass: formalized method/hook naming
  convention as ADR-021; corrected cross-hall address handling (BR-028,
  supersedes the shared-address-book portion of BR-001); formalized Hall
  Manager storefront customization boundary (BR-029). Fixed Redis/S3
  "TBD vs. locked" contradiction across technical-architecture.md, README.md,
  and AGENTS.md that had drifted from ADR-007/ADR-009.
- **[2026-08-13]** Core network migration framework implemented and verified.
  Network-wide append-only AuditLog foundation implemented and verified.
  Audit transaction primitive intentionally deferred until Hall Staff Management
  consumer-level transaction/cache/hook analysis.
---

## In Progress
- Finalizing and committing the hall role/capability provisioning foundation.

## Next Up (Strict Execution Order)
1. Commit verified `bingocommerce-core` role/capability provisioning.
2. Design and implement controlled Hall Staff Management using `bc_manage_hall_staff`.
3. Verify Hall Manager can create/manage only approved hall staff roles without
   gaining broad WordPress user-administration privileges.
4. Begin session-domain design and migration planning only after staff management
   authorization is complete.

---

## Blockers / Waiting On
- **Local Dev:** Nothing blocking.
- **Open Business/Infra Items (Tracked in `docs/decisions/`):**
  - Online payment gateway selection
  - Production parent domain name
  - Production hosting provider & Object storage selection (AWS S3 vs. Cloudflare R2)
  - Offline QR redemption
  - Legal review (compliance clause + 90-day retention)
---

## Environment Notes
- **Local:** macOS (arm64), PHP 8.4.14, MySQL 9.5, Apache
- **WordPress:** 7.0.x Multisite subdomain; table prefix `bc_`
- **Halls:** Sunrise (2), Tampa (3), Orlando test hall (4) (Local)
- **Commerce:** WooCommerce network-active, HPOS on
- **Custom code:** `wp-content/plugins/bingocommerce-core/` only so far
- **Rule for AI agents:** Read `AGENTS.md`, `docs/architecture/plugin-architecture.md`, and this file before coding. Do not re-scaffold Multisite, WooCommerce, or core bootstrap.