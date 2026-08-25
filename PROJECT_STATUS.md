# BingoCommerce — Project Status

*Living document. Update at the END of every work session. Keep it short, factual, and chronological.*

---

## Current Phase
**Phase 1 — Core Platform & Digital Bingo Domain Foundation**

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
- **[2026-08-18]** Implemented, reviewed, manually verified, and committed
  Hall Staff operational identity provisioning and invitation lifecycle
  (`BR-035`, `BR-036`) - commit '327ae2':
  - Hall Managers can create new operational Hall Manager / Game Operator
    identities using a work email and display name.
  - Hall Managers never choose or see staff passwords; new staff receive the
    native WordPress account/password setup flow.
  - Initial invitation and Resend Invitation verified end-to-end with working
    local SMTP.
  - Existing eligible network identities can be assigned without creating a
    duplicate account.
  - The same operational identity may hold protected roles on multiple halls;
    hall memberships remain independent.
  - `BR-036` network-wide operational identity eligibility implemented:
    identities with non-protected, malformed, duplicated, mixed, or otherwise
    non-canonical capability rows fail closed.
  - `BR-036` enforced on fresh assignment, assignment retry, fresh role change,
    and role-change retry; removal deliberately remains available as the cleanup
    escape path for an invalid identity.
  - Duplicate email / `user_login` identity resolution fails closed using
    authoritative cache-bypassing database reads; no arbitrary identity may be
    selected.
  - Provisioning recovery is bound to the signed user ID, email, and login and
    requires the authoritative creation audit witness.
  - Assignment-stage ambiguous commit recovery verified end-to-end using
    authentic Phase A + creation retry tokens and authoritative ADR-025 witness
    reconciliation.
  - Invitation issuance is marker-first and serialized with a per-user advisory
    lock; automatic invitation and Resend concurrency are covered.
  - Invitation-only failures no longer expose a misleading "Retry the same
    operation" UI action; Hall Managers are directed to Resend Invitation.
  - Game Operator admin restrictions manually verified: Hall Staff management,
    products, WooCommerce admin, and cross-hall admin access denied.
  - Hall Manager safeguards manually verified: self-role change denied,
    self-removal denied, valid two-HM → one-HM transition succeeds.
  - `BR-036` negative browser tests verified:
    non-operational identity assignment rejected, GO → HM promotion rejected
    without mutation, and removal of the invalid protected membership remains
    allowed without disturbing the other-site role.
  - Automated Create/Invite harness: **262/262 PASS**.
  - Phase A regression harness: **67/67 PASS**.
  - `git diff --check`: clean; no automated-test users, orphan usermeta,
    triggers, advisory locks, or renamed audit tables remain.
  Result: Hall Staff identity provisioning, invitation lifecycle, eligibility,
  authorization, recovery, and manual browser verification **PASS**.
- **[2026-08-21]** Documentation-only architecture pass: locked the Product →
  Game Type → Package → Session → Digital Entitlement domain model.
  - Added `BR-037`–`BR-047` (Game Type, Bingo Play Product, Bingo Package,
    Configurable Electronic Card Limit, Session Domain, Checkout Enforcement,
    Entitlement Snapshot Immutability, QR/Digital Ticket Scope, Android
    Redemption, Package Refund Restriction, Starter Catalog).
  - Added `ADR-026`–`ADR-030` (Bingo Catalog Domain Model, Starter Catalog
    Provisioning, Electronic Limit and Session Scope, Immutable Purchase-Time
    Entitlement, Digital Ticket/Redemption Boundary).
  - `BR-041` formally supersedes `BR-002`, `BR-025`, and `BR-031`; `ADR-028`
    formally supersedes `ADR-006`. All superseded entries remain in
    `docs/decisions/DECISIONS.md` for history, unedited.
  - Retired the session-wide "300 qualifying orders" capacity model everywhere
    it was described as active guidance (`AGENTS.md`, `.cursor/rules/`,
    `docs/architecture/technical-architecture.md`,
    `docs/architecture/plugin-architecture.md`,
    `docs/requirements/business-requirements.md`) in favor of the
    configurable, per-Game-Type electronic-card limit.
  - Added `bingocommerce-catalog` to the plugin suite (owns Game Type, Bingo
    Play Product metadata, Package/Package-Component/add-on-eligibility,
    starter-catalog logic) per `ADR-026`.
  - Added `BR-048` (Local Fulfillment / No Shipping in v1), narrowing only
    BR-005's shipping-specific wording (not its stocking/inventory
    provisions, and not BR-003); clarified active `ADR-005` citations so they
    cannot be mistaken for the retired session-capacity mechanism.
  - No code, migrations, tables, or tests were touched — documentation and
    guardrails only.
- **[2026-08-24]** Documentation-only business-rule sync for confirmed
  Jim/Sean decisions. No PHP, JavaScript, REST endpoints, migrations,
  tables, admin/storefront UI, or tests were implemented. Hall Staff
  architecture was not redesigned.
  - Added `BR-050`–`BR-060`.
  - Calendar-dated Sessions; 5-day sales window + Available for Sale;
    customer Session-before-Package selection.
  - Online entitlement activation resolved as successful online payment
    confirmation (`BR-053`).
  - Sales reporting dual attribution; multiple Digital Packages on one
    tablet; late redemption; no All Game Types bucket; no per-game player
    tracking; no extra-game/jackpot purchase flows; offline usability as a
    business expectation only.
  - Historical locked entries (`BR-002`/`BR-025`/`BR-031`, `ADR-006`, Hall
    Staff `BR-033`–`BR-036` / `ADR-025`, and `BR-037`–`BR-049` /
    `ADR-026`–`ADR-030`) were left unedited.
- **[2026-08-25]** Documentation-only Catalog + Session persistence
  architecture synchronization completed; no implementation was created.
  - Added `BR-061`–`BR-065`: Package base-composition rules, Package/add-on
    quantity ceiling, inclusive hall-local Session sales boundary, electronic-
    limit configuration authority, and Catalog classification / BR-032
    projection authority.
  - Added `ADR-031`, locking the hybrid WooCommerce-commerce-shell /
    BingoCommerce-relational-domain authority split and seven hall-local tables.
  - Added `docs/architecture/catalog-session-persistence-design.md` with the
    approved conceptual schema, indexes, lifecycle rules, BR-032 projection /
    reconciliation contract, and plugin/migration ownership.
  - Locked native WooCommerce Package price as base-price authority and the
    Package/add-on relationship as unit-price/quantity authority.
  - Preserved all historical ADR/BR entries unedited; synchronized active docs
    and guardrails only.
  - No PHP, JavaScript, SQL migration, table, REST endpoint, admin/storefront
    UI, or test was created or modified.
- **[2026-08-25]** Game Type Increment 1 implemented and locally verified in
  `bingocommerce-catalog` (uncommitted, pending review).
  - Added the hall-local `bingo_game_types` migration, per-site schema version,
    existing/new-hall provisioning, and post-`dbDelta()` schema verification.
  - Added capability-gated Game Type create, rename, archive, restore, get/list,
    active-selection reads, ordinary administrative auditing, and the minimal
    Hall Manager administration screen.
  - Full real-database integration suite passed: 151 assertions, including
    Unicode code generation, uniqueness/concurrency, lifecycle, authorization,
    audit warnings, migration recovery/idempotency, and Sunrise/Tampa isolation.
  - Cross-network existing-hall migration catch-up is restricted to the native
    Super Admin network-administration path; hall requests retain current-site
    migration/self-healing only.
  - Hard delete, Bingo Play Products, Packages, Sessions, and coordinated
    starter-catalog copy remain deferred and are not implemented.
---

## In Progress
- No Hall Staff Management work currently in progress.
- Game Type Increment 1 is implemented and verified locally but remains
  uncommitted pending review. The broader approved Catalog/Session persistence
  architecture remains unimplemented.

## Next Up (Strict Execution Order)
1. Review and commit Game Type Increment 1 without expanding its approved scope.
   The next Catalog increment requires its own focused Bingo Play Product
   integration-design slice, including the approved shared concurrency boundary
   needed before Game Type hard delete can be enabled.
2. The online entitlement-activation business rule is now resolved as
   successful online payment confirmation (`BR-053`). Exact WooCommerce
   successful-payment hook/status mapping belongs to later
   entitlement/QR integration-design work. It does not block the Game Type
   implementation-design slice.
3. Package pricing persistence is resolved by ADR-031: linked WooCommerce
   native price for Package base price; relational Package/add-on unit price
   and quantity ceiling.
4. Except for Game Type Increment 1, the Catalog/Session/sales-window/
   entitlement-activation/reporting feature areas are not implemented.
5. Continue only through separately reviewed, focused implementation slices
   that preserve ADR-031 and the detailed persistence design.
6. Platform Admin network identity Disable/Reactivate/Permanent Delete
   remains deferred under BR-035 §4 and requires a separate
   architecture/decision pass before implementation.

---

## Blockers / Waiting On
- **Local Dev:** Nothing blocking.
- **Open Business/Infra Items (Tracked in `docs/decisions/`):**
  - Online payment gateway selection
  - Production parent domain name
  - Production hosting provider & Object storage selection (AWS S3 vs. Cloudflare R2)
  - Offline QR/Package usability **technical implementation** (business
    expectation locked by BR-060; architecture not invented in this pass)
  - Legal review (compliance clause + 90-day retention)
---

## Environment Notes
- **Local:** macOS (arm64), PHP 8.4.14, MySQL 9.5, Apache
- **WordPress:** 7.0.x Multisite subdomain; table prefix `bc_`
- **Halls:** Sunrise (2), Tampa (3), Orlando test hall (4) (Local)
- **Commerce:** WooCommerce network-active, HPOS on
- **Local mail:** SMTP configured and verified for WordPress staff invitation/password setup testing
- **Custom code:** `wp-content/plugins/bingocommerce-core/` only so far
- **Rule for AI agents:** Read `AGENTS.md`, `docs/architecture/plugin-architecture.md`, and this file before coding. Do not re-scaffold Multisite, WooCommerce, or core bootstrap.
