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
- **[2026-08-13]** Locked ADR-025 (Fail-Closed Transaction Transport, Operation Identity, and Commit Reconciliation). Fail-closed architecture proven using direct mysqli on captured $wpdb->dbh with operation_id reconciliation.
- **[2026-08-13]** Implemented and locally verified Phase A Hall Staff Management:
  - existing-network-user assignment, HM/GO role changes, and hall-only removal
  - signed idempotent retry intent and authoritative audit-witness outcomes
  - same-connection direct-mysqli domain/audit transactions with hall advisory
    serialization and narrow last-Hall-Manager row locks
  - canonical current-hall admin UI plus native role/metadata writer guards
  - Super Admin target safeguards, cross-hall preservation, and cache
    reconciliation verified
  Result: Phase A service/transaction integration verified; interactive wp-admin verification pending.
- **[2026-08-15]** Hall Staff Phase A final technical verification completed
  (Scenario 32). Browser scenarios 1–30 previously completed; the two deferred
  scenarios are now closed.
  - Scenario 25 (legacy Super Admin target cleanup): **PASS** via controlled
    verification. Non-Super-Admin removal denied; Super Admin removal returned
    COMMITTED_VERIFIED with `safeguard_override = true`; network identity,
    Super Admin status, and other-hall memberships preserved.
  - Scenario 31A–D (committed replay, token tampering, retry intent mismatch,
    cross-actor retry): **PASS**. Replay is resolved witness-first and never
    re-applies DML; tampered and validly-signed-but-mismatched tokens are
    rejected with no witness created; no token "upgrade" when replayed by a
    higher-privileged actor.
  - Scenario 32 transaction/failure/concurrency verification: **PASS**.
    Mandatory-audit insert failure and domain-DML failure both roll back with
    no witness and byte-identical usermeta restoration; commit-ambiguity
    reconciliation resolves to COMMITTED_VERIFIED / NOT_COMMITTED_VERIFIED /
    OUTCOME_UNKNOWN as appropriate, within an independent two-attempt budget
    that preflight witness reads do not consume.
  - Audit schema `UNIQUE` index on `operation_id` **reconfirmed** independently
    (`SHOW INDEX` / `SHOW CREATE TABLE`, plus a rejected duplicate INSERT).
  - Incompatible mysqli transport handle (null and non-mysqli) fail-closed:
    **PASS** — OUTCOME_UNKNOWN, no mutation, no witness, no `$wpdb` fallback.
  - Native writer guard: **PASS** across `WP_User::set_role`/`add_role`/
    `remove_role`, add/update/delete user metadata, and update/delete by meta
    ID; ordinary non-protected users are unaffected.
  - Cross-hall preservation under forced failure: **PASS**.
  - Cleanup/baseline restoration: **PASS** — no temporary users, metadata,
    triggers, seams, or advisory locks remain.
  - **Last-Hall-Manager concurrency (precise result):** two real concurrent
    Hall Manager removal operations were serialized through the hall-scoped
    advisory lock. One operation committed. After the first mutation the losing
    actor was no longer authorized, so the second operation failed with
    `actor_not_authorized`. 10/10 runs preserved at least one Hall Manager, so
    the zero-Hall-Manager business invariant is verified under real concurrency.
    The literal `last_hall_manager` rejection branch was **not** independently
    exercised by that race; it remains covered by non-concurrent testing.
- **[2026-08-15]** Locked `BR-034` (Super Admin Hall Staff Target Policy) and
  `BR-035` (Hall Staff Identity and Account Lifecycle):
  - Customer/subscriber → Hall Staff **in-place promotion is intentionally NOT
    the supported business workflow**; the assignment flow fails closed on a
    non-canonical existing role. This is product policy, not a defect.
  - Separate operational staff identities (e.g. `john@sunrisebingo.com`
    alongside a personal `john@gmail.com`) are the approved model.
  - Hall Staff removal never deletes the shared network identity and never
    touches other halls or customer accounts.
  - **Future implementation, not present today:** Platform Admin
    Disable/Reactivate/Permanently-Delete account lifecycle
    (ACTIVE → UNASSIGNED → DISABLED → PERMANENTLY DELETED).
  - **Future implementation, not present today:** Hall Manager
    create/invite-new-operational-user. Phase A supports assignment of existing
    network users only.
- **[2026-08-15]** Moved the Hall Staff CLI integration harness from
  `wp-content/plugins/bingocommerce-core/tests/` to the repository-root
  `tests/` directory so it cannot ship inside the deployable plugin tree.
---

## In Progress
- Phase A service/transaction integration verified; interactive wp-admin verification pending.

## Next Up (Strict Execution Order)
1. Review and commit the verified Phase A Hall Staff Management implementation.
2. Begin session-domain design and migration planning only after staff management
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
