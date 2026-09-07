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
- **[2026-08-25]** Game Type Increment 1 implemented, locally verified, and
  committed in `bingocommerce-catalog` as `d1ce5d7`.
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
- **[2026-08-26]** Documentation-only Catalog critical-serialization decision
  recorded as `ADR-032`; no implementation was created.
  - Authorized one narrow captured-mysqli transaction boundary owned by
    `bingocommerce-catalog` for approved Package/Play-Product claim exclusivity
    and Play-Product/Game-Type reference serialization.
  - Kept WooCommerce CRUD/projections, AuditLog writes, hooks, and other
    WordPress side effects outside the transaction.
  - Preserved ADR-025 as the separate mandatory-audit Hall Staff transport;
    no operation identity, commit witness, or reconciliation protocol was
    imported into Catalog.
- **[2026-08-26]** Bingo Play Product Increment 1 implemented and locally
  verified in `bingocommerce-catalog` (not committed).
  - Catalog schema migrated v1 -> v2 (`bc_catalog_db_version`), adding the
    hall-local `bingo_play_products` table. The version is persisted only after
    BOTH the applied Game Type schema and the new Play Product schema verify.
    The committed hook registration is reused unchanged: `network_admin_menu`
    trusted Super Admin sweep, `admin_init` current-site self-heal, and
    `wp_initialize_site` new-hall provisioning. No second v2 trigger was added.
  - Added `CatalogTransactionTransport`, the narrow ADR-032 critical
    serialization boundary. It takes no callable, exposes exactly four named
    entry points, uses the captured `$wpdb->dbh` with native prepared
    statements, and permits only BEGIN/COMMIT/ROLLBACK,
    `SELECT ... FOR UPDATE NOWAIT`, and authoritative `bingo_play_products` DML.
    No `operation_id`, commit witness, retry token, mandatory-audit atomicity,
    or ADR-025 reuse.
  - ADR-032 is entered ONLY for `create()`, `assignGameType()` when the
    reference actually changes, `updateMedium()` Paper -> Digital (and a
    Digital -> Digital reassignment), and `restore()` on an archived row.
    `updateFacesPerUnit()`, `archive()`, Digital -> Paper, and
    `clearGameType()` use ordinary `$wpdb->prepare()` conditional atomic
    statements whose guard predicate lives inside the UPDATE.
  - `FOR UPDATE NOWAIT` verified supported on the installed MySQL 9.5.0
    (errno 3572 in ~0.3 ms versus a 50 s `innodb_lock_wait_timeout` wait). No
    timeout fallback path was implemented.
  - Commit contract: a clean acknowledgement is success; a pre-COMMIT rejection
    with confirmed rollback is an ordinary failure; an unclean/interrupted
    acknowledgement returns `catalog_commit_outcome_unknown` with no
    post-COMMIT reconciliation, no state inference, no retry, and no audit.
  - Ordinary ADR-023 auditing (`catalog.play_product.created` / `.updated` /
    `.archived` / `.restored`) occurs only after a confirmed commit; no-ops emit
    nothing and `operation_id` stays NULL. An audit failure leaves the committed
    mutation in place and returns an `audit_write_failed` warning.
  - Added the minimal Hall Manager Play Product administration screen. The
    linked WooCommerce product is immutable after creation; there is no hard
    delete, no relink, and no Package UI.
  - Play Product integration suite: **404/404 PASS**. Game Type regression
    suite: **151/151 PASS** (unchanged).
  - Real local-MySQL concurrency verified: same-product create race resolves to
    exactly one winner and one row; independent products proceed in parallel;
    bounded retry resolves a 30 ms anchor lock (~0.08 s) while a 2 500 ms lock
    returns `catalog_write_conflict` in ~0.07 s; the target Game Type row is
    genuinely locked for reference creation/reassignment; concurrent
    `clearGameType()` versus Paper -> Digital never produced
    `medium = digital AND game_type_id IS NULL` in either interleaving.
  - `wp_posts` contention exercised against real WooCommerce administration
    (save, trash, permanent delete, untrash) in both orderings. A live Catalog
    transaction artificially held the anchor for ~1 s; a concurrent product save
    completed after waiting ~0.78 s.
  - Ordinary `$wpdb` writers competing with a live ADR-032 transaction holding
    the same Play Product row completed correctly after waiting for that
    transaction (~0.76 s and ~1.77 s measured). These statements carry no
    NOWAIT, so a stalled Catalog transaction would block them up to
    `innodb_lock_wait_timeout`; no timeout architecture was added.
  - Packages, Sessions, checkout/storefront behaviour, starter-catalog copy,
    Game Type hard delete, Play Product hard delete, Woo relinking, variation
    mappings, and the `bc_product_kind` / `bc_package_relation` projections
    remain deferred and are not implemented.
  - Source-review corrections applied before commit:
    - **Fail-closed defect fixed:** a failed `ROLLBACK` after an in-transaction
      lock conflict previously returned the retryable lock-conflict code, so the
      bounded retry could issue `BEGIN` on a session whose transaction was still
      open. It now marks the session untrusted and returns
      `catalog_transaction_state_untrusted`, which carries no `db_errno` and is
      therefore never retried; `run()` also re-checks the untrusted flag before
      any further attempt.
    - **ADR-032 callback prohibition honoured:** the transport's error builder no
      longer calls `__()`. `translate()` fires the `gettext` /
      `gettext_{$domain}` filters, and that ran on every in-transaction rejection
      while `BEGIN` was open. Transport messages are now untranslated developer
      strings; localization moved to `PlayProductAdmin::error_message()`, after
      the boundary closes.
    - **Package-table probe restored to the approved minimal signature**
      (`package_id`, `wc_product_id`, `status`) and now additionally requires a
      single-column UNIQUE index on `wc_product_id`, which is what actually
      proves ADR-031's one-product/one-Package invariant. A non-unique or
      composite index no longer qualifies. The probe carries an explicit comment
      that this absent-table bridge is temporary and that PackageService's
      migration/writer rollout must revisit it so a deployment-time
      table-creation race cannot create a dual claim.
    - **Derived Catalog table identifiers are validated fail-closed** against
      `^[A-Za-z0-9_]+$` before interpolation, rejecting rather than sanitizing,
      then quoted as before.
    - **`settle()` removed.** Settlement is mutation-only via `settle_direct()`,
      eliminating an unreachable branch that would have mislabelled a verified
      no-op with a mutation status.
  - New coverage added by those corrections, all passing: lock-conflict followed
    by a failed ROLLBACK performs exactly one `BEGIN` and never retries; a
    registered `gettext` callback is never invoked while a Catalog transaction is
    active (proved against a positive control that does record one); a
    committed-but-unacknowledged `COMMIT` leaves the row present while still
    returning `catalog_commit_outcome_unknown` with no audit event and no retry;
    and eight malformed Package-table shapes each fail closed.
  - **Three further test-harness safety defects found and FIXED before commit:**
    cleanup deleted network-wide `bc_audit_log` rows by `target_type` +
    `target_id` only, so a hall-local Play Product ID colliding across halls
    could delete another hall's audit trail — every audit read and delete is now
    scoped by `blog_id`; a `PPI1` title/name was treated as ownership proof, so a
    legitimate Hall Manager product named "PPI1 …" could be deleted — ownership
    is now exact tracked IDs for the current run plus a durable
    `_bc_ppi1_test_artifact` post-meta marker for prior runs, with name matching
    demoted to detection that reports rather than deletes; and destructive
    migration scenarios ran `DROP TABLE` against persistent halls with only
    snapshot/restore as protection, which fails if the process dies mid-test —
    they now run on a purpose-created disposable Multisite hall that is removed
    afterwards, and the suite asserts Sunrise/Tampa/Orlando schemas are intact.
  - **Test-harness destructive-cleanup defect found and FIXED before commit.**
    An earlier revision of `tests/play-products-increment-1.php` ran an
    unqualified `DELETE FROM bingo_play_products` plus a hall-wide delete of
    every `bingo_play_product` audit row, and it destroyed manually created
    Sunrise smoke-test rows during verification. Cleanup is now ownership-scoped:
    a Play Product row is removed only when this run tracked its exact ID, or
    when a prior run's artefact carries the durable `_bc_ppi1_test_artifact`
    post meta; `PPI1` naming alone is detection/report-only and never authorizes
    deletion. Package stub tables are dropped only when the run created them.
    Destructive migration scenarios no longer touch a persistent hall at all:
    they run on a disposable Multisite hall, so no backup/restore of real hall
    rows is involved. Unownable residue is reported for deliberate cleanup
    instead of being deleted. A new `Cleanup safety` group
    plants an unowned sentinel, runs the real cleanup path, and requires the
    sentinel's row, audit trail, product and Game Type to survive while
    suite-owned residue is removed.
  - Interactive Hall Manager wp-admin verification **completed**, covering
    create/edit, Paper/Digital transition, archive/restore, restore rejection
    while the linked WooCommerce product is trashed and recovery after untrash,
    archived Game Type reference preservation, Hall Manager authority, Game
    Operator denial, and Sunrise/Tampa tenant isolation.
  - The recurring `wp-includes/class-wp-http.php` "Maximum execution time of 30
    seconds exceeded" fatal was diagnosed as a **local PHP/Homebrew libcurl
    `.test` hostname-resolution problem, unrelated to Catalog**. Every request
    to a hall — admin, front end, `wp-login.php`, authenticated or anonymous —
    reproduces it once the `doing_cron` transient goes stale, because
    `spawn_cron()` and WooCommerce Action Scheduler both issue non-blocking
    loopback POSTs to `*.bingocommerce.test` that take 30 s instead of 0.01 s.
    Forcing IPv4 resolution makes them instant. `bingocommerce-catalog` and
    `bingocommerce-core` contain no HTTP calls at all. This is a local-
    environment follow-up; no repository workaround was added.
  - **Test-harness follow-up (NOT fixed here):** `tests/game-types-increment-1.php`
    leaks roughly 29 orphaned `catalog.game_type.*` audit rows per run, for Game
    Types it then deletes. This is a harness cleanup gap, not a Game Type domain
    regression; the Game Type suite still passes 151/151.
  - **Second test-harness follow-up — RESOLVED on 2026-08-28.** The collation
    scenario no longer touches a persistent hall: it damages and repairs the
    `name` collation on a disposable hall created for that run alone, so an
    interrupted run can no longer leave Sunrise or Tampa mis-collated.
  - **Follow-up technical review item (NOT fixed here):**
    `information_schema.innodb_trx` was measured returning a false negative on
    MySQL 9.5 while a transaction was open holding a real `FOR UPDATE` row lock.
    `bingocommerce-core`'s ADR-025 `HallStaffTransactionTransport` uses that
    expression for its `transaction_already_open` guard, so that guard can
    false-negative. Core was deliberately not modified. The Catalog transport
    uses `performance_schema.events_transactions_current` instead, which was
    verified to report ACTIVE for an externally opened transaction both before
    and after row access.
- **[2026-08-27]** Package Increment 1 design pass completed; **no code,
  migration, table, or test was created**. Reviewed decisions were locked in
  `docs/decisions/DECISIONS.md` and
  `docs/architecture/catalog-session-persistence-design.md`.
  - Added `ADR-033` (Package Composition Atomicity Transport): a second narrow
    captured-mysqli boundary, distinct from ADR-032, for all-or-nothing Package
    composition replacement across `bingo_package_components` and
    `bingo_packages.package_type`.
  - **`$wpdb->query()` was measured and REJECTED for that boundary.**
    `wpdb::query()` fires `apply_filters( 'query', $query )` on every statement
    it runs, including `BEGIN`/`COMMIT`/`ROLLBACK`, so a third-party callback
    could execute arbitrary code and rewrite SQL mid-transaction; the same path
    can call `strip_invalid_text_from_query()` (which issues further queries and
    fires `gettext`) and `log_query_custom_data` under `SAVEQUERIES`. ADR-033
    therefore uses the captured `$wpdb->dbh` handle, which bypasses `wpdb` and
    fires no hook.
  - Added `BR-066` (Play Product storefront mechanism deferred; Catalog
    increments must not touch `catalog_visibility`, `is_purchasable`, post
    status, add-to-cart, stock, or storefront/search visibility),
    `BR-067` (Package base composition integrity: duplicate input rejected, one
    relationship row, no empty active Package, archived components preserved and
    surfaced, new references must be active), and `BR-068` (composition mutation
    rejected while a Package is archived).
  - Documented the canonical composition fingerprint for stale-edit detection —
    row lock provides serialization, fingerprint provides stale-edit detection —
    and recorded that `updated_at` is unusable as the concurrency token because
    `datetime` precision cannot separate rapid consecutive edits.
  - Documented the two-stage Catalog v3 rollout contract that retires the Play
    Product absent-`bingo_packages` bridge before any Package writer is enabled.
  - Recorded base-component `quantity` as a storage/validation bound (1 to the
    unsigned-int maximum), explicitly NOT a business maximum, and kept
    `sort_order` in v3 per the approved design with no reorder UI in Increment 1.
  - Concurrency correction pass applied to the same documents: the ADR-032
    Package-create profile and the ADR-033 replacement profile both now lock
    every referenced `bingo_play_products` row in ascending
    `bingo_play_product_id` order and validate under those locks; `package_type`
    is derived only from `medium` values read under lock, with preflight state
    advisory only.
  - Recorded that an already-existing component whose Bingo Play Product was
    archived may be retained unchanged or removed, but its quantity may not be
    changed (`play_product_not_active`).
  - Documented the full Catalog lock-order review across all eight Play Product
    paths plus both Package paths. Two relative inversions exist, one of them
    pre-existing and shipped. Explicit locking reads use `FOR UPDATE NOWAIT` and
    fail fast, but DML may wait and may deadlock; errnos 1205, 1213 and 3572 are
    handled by the bounded Catalog retry contract. Lock ordering reduces
    contention and keeps reasoning tractable, but does not eliminate all cycles.
  - Confirmed ADR-033 shares ADR-032's `$active` nested-use guard and
    `$untrusted` poisoned-session flag in the one Catalog transport class; two
    independent guard pairs are prohibited.
  - Removed PHP-FPM as an assumed production mechanism from the Stage A
    completion condition; production runtime is still unselected (ADR-013).
  - Clarified that the ADR-033 lock set and derivation set differ:
    `replaceComponents()` locks the UNION of currently referenced and requested
    Bingo Play Product IDs, but derives `package_type` from ONLY the Play
    Products in the final requested set. A component being removed is locked and
    contributes nothing to the resulting type.
  - Added `BR-069`: a Bingo Play Product's `medium` may not change while it is
    referenced by any `bingo_package_components` row, in either direction,
    because persisted `package_type` is derived from component media. Changing
    `faces_per_unit`, assigning/reassigning or clearing a Game Type, and
    archiving/restoring all remain permitted on a Package-referenced Play
    Product. Enforcement serializes on the `bingo_play_products` row, which
    Package create and `replaceComponents` already lock.
  - **BR-069 enforcement mechanism decided and recorded as `ADR-034`.** Both
    medium transitions run inside the ADR-032 transport and serialize on the
    Play Product row; `Digital -> Paper` must move out of the ordinary `$wpdb`
    path during Package implementation. ADR-034 adds a third approved ADR-032
    use without editing ADR-032.
  - The narrower single guarded autocommit `UPDATE ... AND NOT EXISTS (...)`
    alternative was **empirically rejected**: safe under REPEATABLE READ, but
    under READ COMMITTED real MySQL 9.5 testing committed the medium change
    despite a committed Package reference in 3/3 runs of the real writer shape.
    Production database defaults are not locked (ADR-013), so BR-069 must not
    depend on an external isolation default. WordPress `query`-filter callbacks
    were **not** the reason for rejection.
  - The component-reference check keeps `SELECT ... FOR UPDATE NOWAIT`. A plain
    read passed all fourteen required interleavings under both isolation levels,
    but only as the transaction's first consistent read; an earlier non-locking
    read opened the snapshot sooner and silently observed zero references while a
    committed reference existed.
  - Asserting or pinning transaction isolation inside the ADR-032 transport
    remains a **separate technical follow-up**, not a BR-069 blocker, because
    ADR-034's mechanism is isolation-independent.
  - No implementation was performed; Package Increment 1 remains design-only.
  - Removed the PHP-FPM example from the Stage A rollout condition entirely; no
    process manager, restart mechanism, or deployment topology is part of the
    contract while ADR-013 remains open.
  - **Withdrew an incorrect deadlock-freedom claim.** An earlier revision of
    ADR-033 and the persistence design asserted that every Catalog statement
    uses `FOR UPDATE NOWAIT` and therefore never waits, so no wait-for cycle
    could form. Measurement on MySQL 9.5 disproved it: only explicit locking
    READS take `NOWAIT`, while `INSERT`/`UPDATE`/`DELETE` have no such option.
    Two Package creates for DIFFERENT products against an empty
    `bingo_packages` both passed their `NOWAIT` claim checks (gap locks are
    mutually compatible) and then **deadlocked on insert, errno 1213**; two
    `replaceComponents` deletes on DIFFERENT packages produced a **lock wait
    timeout, errno 1205**. The tested empty-table case exposed contention not
    observed in the corresponding populated-table run; it matters operationally
    because `bingo_packages` begins empty when Package Increment 1 is first
    introduced, and it does not establish a universal worst-case. Deadlock and
    lock-wait between conforming writers are now documented as expected and
    handled by the existing bounded retry over errnos 1205/1213/3572, which is
    recorded as correctness-bearing for liveness rather than a latency choice.
  - Synced `AGENTS.md` Rule 4, which named only ADR-025 as an approved
    direct-mysqli exception and therefore conflicted with ADR-032/033/034. It
    now lists all three approved captured-`$wpdb->dbh` transports, keeps
    `$wpdb->prepare()` mandatory for ordinary SQL, records ADR-033's
    prohibition on `$wpdb->query()` inside its open transaction, and still
    forbids raw interpolation everywhere.
  - Corrected the Catalog lock-order table: both medium directions now show the
    ADR-034 mechanism and the Package-component reference check; `Digital ->
    Paper` is no longer listed as an ordinary `$wpdb` writer.
  - No Package production code, migration, table, or test was created in this
    pass; it was design and documentation only.
- **[2026-08-28]** **Package Increment 1 — Stage A: Catalog v3 + transport
  foundation implemented.** Subsequently committed as `35cef4c`, with its
  ADR-033 dependency-union correction committed as `80c41bb`. Infrastructure
  only: no Package writer, no Package admin UI, no storefront, no add-ons, no
  Session assignment, no REST, no `bc_product_kind` projection.
  - Catalog schema raised to **v3**, creating the per-site `bingo_packages` and
    `bingo_package_components` tables via `dbDelta()`. Before the version option
    is persisted, each table is verified for every REQUIRED NAMED column being
    present and type/null/default/extra-compatible, every REQUIRED NAMED index
    having exactly the expected uniqueness and exact ordered column list, InnoDB
    and utf8mb4. Verification does not demand an exact whole-table shape:
    unrelated extra columns or extra indexes are tolerated, and only the named
    requirements are enforced. Neither table carries a `blog_id`; hall isolation is
    the resolved site's `$wpdb->prefix` (Rule 6). No physical foreign keys.
  - `bingo_packages.wc_product_unique` is verified as a **single-column**
    UNIQUE on `wc_product_id`. A composite UNIQUE cannot satisfy it, because a
    composite key would constrain only the combination and would not prove
    ADR-031's one-Package-per-product invariant.
  - Retired the Play Product absent-`bingo_packages` rollout bridge. At v3 an
    absent or malformed Package table is a schema fault
    (`catalog_schema_inconsistent`), never a reason to skip the claim check.
    The pre-v3 compatibility return is retained but is unreachable, because
    `authorize()` requires `Migrations::is_current()`, which now means v3.
  - **ADR-034 implemented in both directions.** `Digital -> Paper` moved out of
    the ordinary single-statement `$wpdb` path into the Catalog transport as
    `transitionToPaper()`; `Paper -> Digital` gained the same check. Both hold
    the `bingo_play_products` row as the serialization anchor and then perform a
    `SELECT ... FOR UPDATE NOWAIT` component-reference check, index-backed
    through `bingo_package_components.play_product`. Any reference blocks,
    including one owned by an ARCHIVED Package (BR-067). `game_type_id` is
    absent from the `Digital -> Paper` SET list, preserving the reference per
    BR-039.
  - BR-069 is scoped to an **actual** transition. A row that is already Digital
    and is merely being re-pointed at another Game Type is not reference-checked,
    and a true no-op (`Paper -> Paper`) returns unchanged without entering the
    ADR-034 transition boundary and emits no audit event.
  - **ADR-033 transport machinery added** as `replacePackageComposition()` plus
    `CatalogPackageCompositionPlan` and the public
    `compositionFingerprint()` (sha256 over an ascending `id:quantity;`
    canonicalization). It is a DISTINCT boundary from ADR-032 that shares only
    the `$active` and `$untrusted` statics, so neither can nest inside the other
    and a session poisoned by one is poisoned for both. **It has no caller:**
    nothing in wp-admin or REST can reach it in Stage A.
  - The ADR-033 composition engine implemented here is fully tested, not merely
    guarded: `replacePackageComposition()` has first-class coverage for
    lifecycle rejection, plan-shape rejection, the quantity storage bound,
    stale-fingerprint detection including recomputation on every retry, BR-061
    derivation in all three shapes, exact no-op semantics, archived-component
    retention/removal/quantity rules, corrupt-state fail-closed, DML rollback
    integrity, and its own ambiguous-COMMIT and failed-ROLLBACK behaviour.
  - ADR-033 never locks `bingo_package_components` rows. The parent
    `bingo_packages` row is the sole serialization anchor for conforming
    composition writers; the current-composition read is a plain, callback-free
    SELECT that is structurally guaranteed to be the transaction's FIRST
    consistent read.
    Because ADR-033 takes no component lock, no
    `package_components -> play_products` rank exists and no ADR-033/ADR-034
    lock-order inversion exists.
  - A structurally malformed composition plan is rejected BEFORE `BEGIN` by
    `assert_composition_plan_shape()`: hall context, Package identity, timestamp
    shape, a plain component list, both required component fields present, and
    both of them actual integers with `bingo_play_product_id >= 1`. This is plan
    SHAPE only — Package lifecycle, the stale-composition fingerprint, duplicate
    components, Play Product existence and the archived rules all stay inside
    the transaction under their locks. It exists because a missing or
    non-integer inner-array field would otherwise raise a PHP warning while the
    transaction is open, invoking the error handler inside a boundary that must
    stay callback-free. It accepts no callable and is not a generic validator.
  - Base-component quantity is bounded at `1 <= quantity <= 4294967295` and
    rejected as `invalid_component_quantity` before `BEGIN`, with the same check
    repeated inside the sequence as defence in depth, so an out-of-range value
    never reaches MySQL. The composition plan carries only Play Product
    identity and quantity: `sort_order` is not caller-controlled, existing rows
    keep their order, and new rows start at 0.
  - An exact identical composition performs NO DML at all — no component
    DELETE/INSERT/UPDATE, no `bingo_packages.updated_at` rewrite — and reports
    `mutated = false`, so a future service can skip auditing a verified no-op.
  - An unrecognised `medium` or `status` on any locked dependency row fails
    closed as `catalog_schema_inconsistent` before component DML. A non-digital
    medium is never coerced to Paper and a non-active status is never coerced to
    archived.
  - Automated coverage: `tests/package-increment-1-stage-a.php`, **298/298
    assertions passing** — Setup 4, Migration 51, Rollout bridge 14, ADR-033
    composition 96, ADR-033 concurrency 27, ADR-034 medium transitions 20,
    Concurrency 35, Transport guards 19, Cleanup verification 32 — plus
    `tests/package-reference-fixture-worker.php` and
    `tests/package-composition-worker.php`, the isolated fixtures that reproduce
    the Package writer's locking shape and run a second conforming composition
    writer in its own process.
  - **No test in any of the three suites creates, drops, renames, or alters a
    persistent Sunrise/Tampa/Orlando schema object.** Two distinct protections
    cover this, and they are not the same mechanism:
    - every structural statement issued by a TEST SCENARIO —
      `CREATE TRIGGER`, `DROP TRIGGER`, `ALTER TABLE`, `RENAME TABLE`,
      `DROP TABLE` — goes through `disposable_ddl()` /
      `assert_disposable_hall()`, which aborts the run rather than touch a hall
      the suite did not create;
    - TEARDOWN does not use that gate. It is protected separately, by
      run-owned disposable-site identity (a site id present in this run's own
      disposable registry) plus exact site-scoped identifier validation
      (`^bc_<id>_bingo_(package_components|packages|play_products|game_types)$`),
      so it can only ever drop a table belonging to a hall this run created.

    Persistent halls receive ordinary domain DML against tracked fixture rows
    and nothing else, so a process crash at any point leaves their schema
    exactly as it was and no `finally` block is load-bearing for a persistent
    hall.
    Moved to disposable halls in this pass: the `bc_pp_kill_probe`,
    `bc_pp_anchor_probe` and `bc_pp_slow_probe` SLEEP-trigger concurrency
    scenarios; the shared-WooCommerce-product-ID scenario, which previously
    raised Sunrise's and Tampa's `wp_posts` AUTO_INCREMENT; the Game Type
    `bc_gti1_force_code` code-collision trigger; the Game Type
    `status_name` index damage/repair scenario; and the Game Type
    failed-provisioning collation scenario, which previously damaged Tampa's
    `name` collation and relied on a `finally` block to restore it. The
    scenarios were moved, not weakened: each still exercises the same real
    MySQL sleep/lock interleaving. Verified by structural snapshot — every
    persistent hall's `SHOW CREATE TABLE`, trigger list, and Catalog version
    are byte-identical before and after a full run of all three suites.
    The earlier `_pkga_bak` / `_ppi1_stash` RENAME pattern is gone entirely.
  - Disposable-hall teardown removes all FOUR hall-local Catalog v3 tables —
    `bingo_package_components`, `bingo_packages`, `bingo_play_products`,
    `bingo_game_types` — plus every suite-created trigger and every audit row
    for that `blog_id`, under an exact site-scoped identifier guard. All three
    suites then assert against `information_schema` that no table, no trigger
    and no audit row for any disposable hall survives, and that no persistent
    hall was ever registered as disposable.
  - Fixed two remaining unscoped `bc_audit_log` deletes in the Play Product
    harness's sentinel cleanup, plus three unscoped audit READS. `bc_audit_log`
    is network-wide while the target IDs are hall-local, so every audit read and
    delete for a hall-local object now carries `blog_id`.
  - Measured, not asserted: with a competing writer holding the anchor, the
    medium change returns `catalog_write_conflict` in ~0.08-0.10s rather than
    waiting out a 600-700ms hold, because the anchor is taken with `NOWAIT` and
    the retry budget is bounded. It never returns a success built on a
    reference read the other writer had not committed.
  - Updated `tests/play-products-increment-1.php` for Catalog v3. It previously
    **aborted** at the Package-claim group because `bingo_packages` now exists,
    and its cleanup carried an unqualified
    `DROP TABLE IF EXISTS {prefix}bingo_packages` that would have destroyed the
    authoritative v3 table. That whole group now runs on a disposable hall,
    version assertions follow `Migrations::DB_VERSION` instead of a hard-coded
    2, and final verification asserts the real v3 table is present and verifies.
  - **Test results at the end of this pass, stated separately:**
    - Package Increment 1 Stage A — `tests/package-increment-1-stage-a.php`:
      **298 / 298 PASS**.
    - Game Type Increment 1 — `tests/game-types-increment-1.php`:
      **164 / 164 PASS**.
    - Bingo Play Product Increment 1 — `tests/play-products-increment-1.php`:
      **457 functional/regression assertions PASS, 0 functional failures**, out
      of 460 total. The remaining **3 are residue detections**, not functional
      failures: unowned local test residue described under Next Up item 11
      exists on Sunrise and Tampa, ownership is not proven, and the harness
      reports it rather than deleting it because naming is not ownership proof.
      **No residue was deleted.** Residue detection was not weakened to make the
      suite green.
- Bingo Play Product Increment 1 is implemented, verified, and **committed** as
  `e0459a9 feat(catalog): implement bingo play product management`.
- Package Increment 1 **Stage A (Catalog v3 + transport foundation) is
  committed** as `35cef4c feat(catalog): implement package stage a foundation`,
  with its ADR-033 dependency-union correction committed as
  `80c41bb fix(catalog): enforce package dependency integrity`.
- Package Increment 1 **Stage B (Package domain writer + Hall Manager
  administration) is complete and verified.** Implementation is finished, the
  source has been reviewed, automated verification passes, and interactive
  wp-admin verification has been performed. A Hall Manager can create a Package
  with its initial composition, see its derived Paper/Digital/Hybrid type, edit
  the composition, change permitted quantities, archive and restore it, and read
  the linked WooCommerce product and its native price.
- **Package Increment 1 is COMPLETE.** Stage A and Stage B together deliver the
  Catalog v3 persistence, the ADR-032/ADR-033/ADR-034 transport boundaries, the
  Package domain writer, and the Hall Manager administration screen.
- **[2026-08-30] Stage B — complete and verified.**
  - `PackageService` is the supported writer for `bingo_packages` and
    `bingo_package_components`. Creation enters the frozen ADR-032
    Package-create profile so a Package and its INITIAL composition commit
    together (BR-067); composition replacement calls the existing ADR-033
    boundary and never reimplements it; archive and restore are ordinary
    conditional single-row `$wpdb` writes (BR-070).
  - `package_type` is never accepted from a caller. It is derived inside the
    transaction from the `medium` values read while the component rows are
    locked (BR-061), and an unrecognised medium or status fails closed rather
    than being inferred as Paper or archived.
  - The linked WooCommerce product's NATIVE price remains the sole Package
    base-price authority (ADR-031, BR-049). No price is stored in either
    Catalog table, no price column exists on them, component prices are never
    summed, and the admin screen READS the price from WooCommerce.
  - BR-065's deterministic Package projection `bc_product_kind = package` is
    written post-commit, outside the transaction, and cannot roll back a
    committed Package; a failed projection surfaces as a warning.
    `bc_package_relation` is add-on relationship work and is NOT written here.
  - Ordinary post-commit Catalog audit only: `catalog.package.created`,
    `catalog.package.components_updated`, `catalog.package.archived`,
    `catalog.package.restored`. No `operation_id`, commit witness, retry token,
    audit atomicity or reconciliation — this is not ADR-025. Verified no-ops
    emit no audit event, and an ambiguous COMMIT emits none either.
  - Minimal Hall Manager screen under Products, gated by `is_super_admin()` or
    `bc_manage_products`, nonce-protected, with no Package type control, no
    Package price input, and no `sort_order` or reordering surface.
  - The admin screen treats Package lifecycle EXPLICITLY. `active` shows Active
    and offers Edit and Archive; `archived` shows Archived and offers Restore
    only; anything else shows an inconsistent-state warning, offers no
    Archive/Restore and no composition edit, and directs the Hall Manager to
    Platform Admin. An unrecognised status is never coerced to Archived.
  - A component that is absent from the active selection list is classified from
    its own authoritative joined state, never from that absence. An actually
    archived Bingo Play Product keeps BR-067's retain-or-remove behaviour; a
    component whose Play Product row does not resolve is an integrity fault,
    is labelled UNRESOLVED rather than Archived, and the ordinary
    composition-save workflow is withheld entirely — which agrees with ADR-033,
    where a missing current dependency fails closed and is not repairable
    through ordinary composition editing.
  - Administrative reads fail visibly. A component-read or listing failure
    propagates as `WP_Error` instead of degrading into an empty Package or
    composition list, and the screen renders an explicit error rather than
    "No Bingo Packages yet."
  - A component whose authoritative `medium` is neither exactly `paper` nor
    exactly `digital` is a Catalog integrity fault, not a Paper component. Paper
    is never inferred from "not digital" anywhere in administration: the
    affected Package is gated exactly like an unresolved component — labelled an
    integrity fault, composition editing withheld, Platform Admin named — and
    such a Bingo Play Product is never offered as a selectable new component.
    ADR-033 is unchanged; its writer already failed closed, and this makes the
    administrative reads agree with it.
  - Admin request integers are parsed WITHOUT absolute-value coercion.
    `absint()` mapped a crafted `-5` quantity to a valid `5`, silently turning
    invalid input into a different Package mutation. Identities and quantities
    now pass through unchanged so `PackageService` rejects them
    deterministically; it remains the final domain validator.
  - **Verification status: complete.** Implementation complete; source reviewed;
    automated verification complete — Package Stage B **258/258 PASS** and
    Package Stage A **313/313 PASS**; interactive wp-admin verification complete,
    covering Paper / Digital / Hybrid Package creation, composition
    re-derivation, the identical-composition no-op, archive and restore,
    WooCommerce-native Package price authority, Game Operator denial, and
    Tampa/Sunrise hall isolation.
  - Automated coverage: `tests/package-increment-1-stage-b.php`, **258/258
    assertions passing** — Setup 4, Authorization 18, Create 26, Derivation 8,
    Create atomicity 10, Composition edit 27, Dependency corruption 8, Archive
    and restore 31, Woo price authority 8, Presentation boundary 68, Claim
    concurrency 13, Failure contract 14, Cleanup verification 23 — plus
    `tests/package-create-worker.php` for real multi-process claim races.
  - Harness ownership was tightened across three suites, and nothing is deleted
    on inferred authority in any of them. A hall is destroyed only when THIS PHP
    process created and recorded its id in its own current-run registry;
    `destroy_disposable_site()` refuses any other id. The domain-pattern plus
    blog-id-range destructive sweep is gone from BOTH the Stage A and Stage B
    suites — a hall whose domain merely resembles a fixture is detected and
    REPORTED, never destroyed — and the final assertions require only that every
    hall THIS run created is gone, not that zero fixture-shaped halls exist
    network-wide. Products are deleted only by exact tracked ids or a durable
    ownership marker. The before/after auto-draft diff that deleted products
    merely for appearing during a run was removed from Stage B, Stage A and the
    Play Product suite; such products are now reported and left alone.
  - Persistent halls are checked for PRESERVATION, not emptiness, in BOTH the
    Stage A and Stage B suites. Each captures a deterministic
    primary-key-ordered snapshot of `bingo_packages` and
    `bingo_package_components` for Sunrise, Tampa and Orlando before any fixture
    work and requires it to be byte-identical after cleanup, so legitimate
    pre-existing or manually created Package data survives and never causes a
    failure. Each also proves the comparison is non-vacuous by asserting the
    live snapshot differs from the baseline while the run holds a Sunrise
    Package.
  - Nothing in Stage B changes `catalog_visibility`, `is_purchasable`, post
    status, add-to-cart, stock or storefront/search visibility (BR-066). No
    add-ons, Sessions, checkout, cart enforcement, QR, entitlement or REST work
    is included.
- **[2026-08-31] Session Increment 1 — COMPLETE.** New
  `wp-content/plugins/bingocommerce-sessions` plugin delivering hall-local
  Session scheduling and Session-to-Package assignment. Implemented, source
  reviewed, automated-verified and interactively verified in wp-admin.
  - New per-site tables `bingo_sessions` and `bingo_session_packages` under
    `bc_sessions_db_version` = 1, provisioned by a `Migrations` class that
    mirrors `Catalog\Migrations` exactly — `dbDelta()` followed by explicit
    verification before the version option is persisted, current-site migration
    on `admin_init`, trusted Super Admin network migration, and
    `wp_initialize_site` provisioning for new halls. ADR-031 remains the
    Catalog/Session persistence authority.
  - The structured schedule is a **calendar date only**. `session_date` is a
    `DATE` column; there is no time column, no datetime column and no stored
    offset. Human-readable time such as "6:00 PM" lives only inside
    `display_name`, which is display-label text and drives no automatic logic
    (BR-050).
  - Customer visibility is evaluated on request against the hall's WordPress
    timezone: a Session for date `D` is visible when
    `D - 5 calendar days <= hall-local today <= D` **and** Available for Sale is
    ON (**BR-051**, **BR-063**). `SessionService::salesWindowStartDate()` is the
    single place the window start is derived, no caller reconstructs a date, and
    the window is never precomputed into a scheduled job.
  - `SessionService` is the supported writer, using ordinary `$wpdb->prepare()`
    statements throughout. **No custom Session transaction transport was
    introduced.** ADR-025, ADR-032, ADR-033 and ADR-034 are untouched, and none
    of their transports were reused, extended or copied.
  - Package assignment is guarded inside a single statement — an
    `INSERT ... SELECT ... FROM DUAL WHERE EXISTS` that requires the target
    Session to still be `active` — rather than a read-then-write pair, with the
    `(session_id, package_id)` uniqueness constraint carrying the rest.
  - `PackageGateway` is the ONLY Catalog touchpoint. It validates a Package
    deeply before assignment: exact lifecycle, exact `package_type` among
    paper/digital/hybrid, a non-empty component list, per-component identity,
    quantity, medium and Play Product status with no duplicates, BR-061
    re-derivation matching the persisted type, and a healthy linked WooCommerce
    product. A Package carrying an archived component remains assignable.
  - Reads fail closed and fail visibly. Every confirming read propagates
    `WP_Error` on `$wpdb->last_error` instead of degrading into "not found", and
    a corrupt stored date is rejected rather than coerced. Admin request
    integers are parsed WITHOUT `absint()`, for the reason already recorded for
    Package Stage B.
  - Audit deliberately omits `session_id` from the payload:
    `AuditLog::is_sensitive_key()` compacts keys to alphanumerics and rejects
    `sessionid`, so `session_id` can never be an audit payload key.
  - The plugin fails closed on missing dependencies. If `HallContext`,
    `AuditLog` or `PackageService` is unavailable it registers nothing and shows
    an admin notice.
  - **Verification status: complete.**
    - Source review — **PASS**. No `bingocommerce-core` production file and no
      `bingocommerce-catalog` production file was modified.
    - Automated — `tests/session-increment-1.php`, **343 / 343 assertions
      PASS**.
    - `bingocommerce-sessions` is **Network Active**.
    - Manual wp-admin smoke test — **PASS**: Sunrise Session `#10` created;
      structured schedule remained calendar date only; human-readable time
      remained display-name text only; the Available for Sale and `D - 5`
      through `D` hall-local sales-window result was correct; Package `#89`
      assigned; the combined Session edit succeeded; archive preserved the
      Package assignment; an archived Session was not on sale; restore preserved
      Session identity and the Package assignment.
    - Tenant isolation — **PASS**. A Tampa Hall Manager cannot see Sunrise
      Session data.
    - Game Operator denial — **PASS**. A Sunrise Game Operator is denied Session
      management.
  - No checkout, cart enforcement, capacity, QR, entitlement, add-ons, REST or
    storefront work is included, and none was started.
- **[2026-08-31] Action Scheduler backlog diagnostic — classified
  NON-BLOCKING LOCAL ENVIRONMENT ISSUE.** Investigated during Session
  Increment 1 closeout. It is tracked as separate local maintenance under Local
  Development Cleanup and does **not** block Session Increment 1.
  - Sunrise carries a large and still-growing past-due Action Scheduler
    backlog. It had grown from the 2775 shown in the wp-admin notice to 2935
    past-due actions while the diagnostic ran.
  - Approximately **99.5%** of the Sunrise backlog — 2919 of 2935 — is
    WooCommerce `woocommerce_run_product_attribute_lookup_update_callback`
    work in the `woocommerce-db-updates` group. These are one-shot actions, not
    a single recurring hook repeated thousands of times.
  - The backlog substantially **predates Session Increment 1** and correlates
    with heavy Catalog/Package test product churn. Creation is concentrated on
    2026-08-27 through 2026-08-29, and 1464 of the 1469 referenced product ids
    no longer exist in `wp_posts`.
  - **`bingocommerce-sessions` schedules no Action Scheduler or WP-Cron work**,
    and neither does `bingocommerce-core` or `bingocommerce-catalog`. Verified
    by source search across every custom plugin and harness file.
  - **Session `#10` produced no scheduled actions.** The most recent action
    created on Sunrise predates Session `#10`'s creation, and zero actions were
    created at or after it.
  - Action Scheduler tables on the real halls (Sunrise, Tampa, Orlando) are
    **present and structurally healthy**: current store/logger schema, all
    expected indexes, `CHECK TABLE` clean, and no orphaned rows.
  - **No stale claims were found** on any hall, and no pending action holds a
    claim id.
  - Local cron/background execution is **not reliably completing**. The
    `doing_cron` lock is repeatedly refreshed while no `cron` entry ever
    advances.
  - The exact loopback/background-request failure mechanism remains **UNKNOWN**
    and must not be stated as proven. Confirming it would require issuing an
    HTTP request to a hall, which would execute pending actions.
  - **Procedural disclosure.** The diagnostic was intended to be read-only, but
    early diagnostic scripts loaded `wp-load.php` before `DISABLE_WP_CRON` was
    defined. Normal WordPress bootstrap therefore updated a `doing_cron`
    transient on blog 1 and dispatched a non-blocking wp-cron loopback request.
    No Action Scheduler queue execution was observed from that side effect, and
    the final diagnostic pass defined `DISABLE_WP_CRON` and reverified the
    counts unchanged. This entry deliberately does NOT record the diagnostic as
    "no data modified".
  - No scheduled action was executed, cancelled, retried or deleted, and no
    Action Scheduler data was cleaned.
- **[2026-08-31] Operator-authorized local demo cleanup — COMPLETE.** The
  project owner/operator adjudicated the deleted records as disposable local
  test residue; this was human authorization, not durable harness ownership
  proof.
  - Sunrise deletions: Game Types `#833`-`#844` and `#1345`-`#1347`; Bingo Play
    Products `#175`, `#191`, `#226`, `#233`, and `#1029`-`#1037`; Packages
    `#91`-`#94`; and Package components `#242`-`#248`.
  - Protected Sunrise data remains: Game Types `#654` and `#1343`; Bingo Play
    Products `#1026` and `#1027`; Packages `#87`, `#88`, and `#89`; components
    `#236`-`#240`; Session `#10`; and Session-Package relation `#7` to Package
    `#89`.
  - Hall `#78` was deleted by operator-authorized maintenance: the site and its
    exact `bc_78_*` custom tables were removed, while audit history was
    preserved.
  - Hall `#76` was deleted by explicit operator adjudication without claiming
    whole-site harness ownership as proven: the site and its exact `bc_76_*`
    custom tables were removed, while audit history was preserved.
  - No source code was changed by this cleanup.
- **[2026-08-31] Woo product selector cross-claim filtering — COMPLETE.**
  - The Bingo Play Product create selector now excludes WooCommerce products
    already claimed by either a Bingo Play Product or a Bingo Package. Its
    previous read path excluded only Bingo Play Product claims, which exposed
    Package-linked products such as Sunrise Woo product `#6300` as choices.
  - The Bingo Package create selector already excluded both claim types and was
    not changed. Unclaimed eligible draft products remain valid choices even
    when their titles look like old manual/test data.
  - ADR-032 writer serialization and exclusivity were not changed. Existing
    writer regression coverage still verifies that either rival claim is
    rejected authoritatively; six new selector assertions verify both create
    selectors hide both claim types and retain an unclaimed product.
  - Live read-only Sunrise verification confirms protected Woo products
    `#6297`-`#6301` are absent from both selectors, while unclaimed products
    `#208`, `#212`, and `#213` remain eligible.
  - Protected Sunrise Game Types `#654`/`#1343`, Play Products `#1026`/`#1027`,
    Packages `#87`/`#88`/`#89`, and Session `#10` were not mutated. No Woo
    product was deleted.
  - Syntax checks and the selector regression pass. Play Product
    functional/regression assertions are **461 / 461 PASS**, with zero
    functional/regression failures. Current-run disposable-hall cleanup
    assertions are **5 / 5 PASS**, with zero cleanup failures. One residue-only
    detection remains: Tampa Game Type `#39 "PPI1 Tampa Only 89AUU"` has
    unproven ownership, was not deleted, and is **not** a functional failure.
- **[2026-09-03] Phase 3B Portal mapping/config foundation — COMPLETE.**
  Introduced the network-only `bingocommerce-portal-integration` plugin, the
  one-to-one Portal Hall/WordPress site mapping, and the server-only Portal
  configuration abstraction. The focused Phase 3B harness passes 73/73
  assertions. No Portal HTTP client/call, Game Type or Session replica schema
  change, sync/import, webhook, scheduler, SSO, provisioning, or WP-CLI was
  added.
---

## In Progress
- **Phase 3C1 — Portal Game Type identity persistence: COMPLETE.** Catalog
  schema v4 adds only nullable
  `bingo_game_types.portal_game_type_id` with the single-column
  `portal_game_type_unique` index. The focused v3 -> v4/new-site harness passed
  66/66 assertions when Phase 3C1 landed and passes 70/70 after the 2026-09-07
  stale-precondition harness maintenance recorded under Latest verified
  results. Existing rows remain unlinked (`NULL`), and local
  `GameTypeService` creation still fabricates no Portal identity. No Portal
  client/call, import, reconciliation, or Session-template persistence was
  added.
- **Phase 3C2 — Portal Session-template replica persistence: COMPLETE.**
  Sessions schema v2 adds the separate
  hall-local `bingo_portal_session_templates` replica table without changing
  existing dated Commerce Sessions or Session/Package assignments. All Portal
  Session Templates may be replicated. Hall Manager Commerce selection remains
  separate BingoCommerce-owned configuration; no selection configuration or UI
  is implemented in Phase 3C2. The focused Phase 3C2 harness passes 98/98
  assertions, and Session Increment 1 passes 344/344 assertions on schema v2.
  Portal `updated_at` validation/normalization remains deferred to Phase 3D2;
  any `portal_updated_at` persistence decision remains deferred to Phase 3E
  snapshot application/import.
  No Portal HTTP client/call, import, reconciliation, webhook, or scheduler was
  added.
- **Phase 3D1 — Portal HTTP transport: COMPLETE.** ADR-036 defines the Portal
  base URL as the application root, permits an optional deployment subpath,
  and excludes the `/integration` API prefix. The server-only
  `PortalHttpClient` appends `/integration/v1/halls/{id}/snapshot`, performs a
  blocking, read-only GET, and does not follow redirects. The focused harness
  passes 67/67 assertions. Phase 3D1 itself performs no snapshot validation
  and no Portal data import/write.
- **Phase 3D2 — strict Portal snapshot validation: COMPLETE.** ADR-037
  freezes the Hall snapshot v1 validation contract. The new
  `PortalSnapshotValidator` turns a raw HTTP 200 body into a trusted,
  reconstructed in-memory array or a `WP_Error`. The focused harness passes
  **1443/1443 assertions**.
  - **Exact v1 allowlist.** Envelope `api_version`, `generated_at`, `hall`,
    `game_types`, `sessions`; Hall `id`, `name`, `state`, `updated_at`; Game
    Type `id`, `hall_id`, `name`, `updated_at`; Session `id`, `hall_id`,
    `name`, `status`, `updated_at`.
  - **Strict unknown-field rejection.** A missing required member and an
    unknown member are both fatal at every level and reject the complete
    snapshot; forbidden operational fields (`days`, `games`, `patterns`,
    `caller`, `device_group_id`, `deleted_at`, `game_type_ids` and similar)
    cannot enter the commerce boundary. `api_version` must be exactly `v1`.
  - **Exact timestamp validation.** `YYYY-MM-DDTHH:MM:SS±HH:MM` only — no
    fractional seconds, no `Z` suffix, no offset-free form — enforced by
    anchored shape matching, strict parsing, parse warning/error inspection,
    and exact round-trip equality, so silently normalized impossible dates and
    times are rejected. The UTC offset is additionally range-checked
    structurally (hour `00`-`23`, minute `00`-`59`), because PHP's parser
    round-trips impossible offsets such as `+25:00` without a warning; that is
    the numeric shape of an Atom offset, not a Portal timezone range. Valid
    strings are preserved verbatim.
  - **JSON decoding depth.** The implementation explicitly passes PHP's
    standard decoder depth of `512`, because the flags argument follows the
    depth argument positionally. That number is a technical decoder safety
    bound only: it carries no Portal v1 business or API meaning, BingoCommerce
    derives no domain semantics from it, and no BingoCommerce domain constant
    is introduced for it. Changing this technical bound later requires
    implementation review, but does not by itself require a Portal API-version
    change. A body nested beyond the configured decoder depth fails safely as
    invalid JSON.
  - **Nullable `updated_at`.** `hall.updated_at`, `game_types[*].updated_at`
    and `sessions[*].updated_at` are required keys whose value may be null;
    `generated_at` is required and non-null.
  - **Hall mismatch rejection and child Hall mismatch rejection.** `hall.id`
    and every child `hall_id` must equal the expected Portal Hall ID supplied
    by trusted mapping context; any mismatch rejects the complete snapshot and
    returns no partial rows. Tenancy is never derived from a name, domain,
    host, URL, or ordering.
  - **Duplicate identity rejection.** A repeated `id` within `game_types` or
    within `sessions` rejects the snapshot; nothing is deduplicated or merged.
    The same number may appear once in each independent identity domain, and
    names are never uniqueness keys.
  - **Draft Session preservation and inactive Hall preservation.** Both are
    valid, retained, and never rewritten. Collection input order is preserved
    exactly with no sorting requirement. Names are validated by Unicode
    character count (Hall/Session 255, Game Type 50) and returned unchanged.
  - **Boundary proven by the harness:** no HTTP (zero requests counted through
    a `pre_http_request` seam), no persistence, no import/application, no
    reconciliation, no currentness behavior, no Hall mapping mutation, and no
    webhook/scheduler/SSO/provisioning. Catalog, Session, Portal replica,
    WooCommerce, Hall mapping, and schema/version option state were all
    byte-identical before and after the run.
- **NEXT Portal-integration checkpoint: Phase 3E — snapshot
  application/import.** Phase 3E is not implemented. Overall Phase 3D is
  COMPLETE.
  - **Phase 3E prerequisite that MUST be frozen first: Portal Game Type replica
    currentness / missing-record lifecycle.** Phase 3C1 added
    `portal_game_type_id` but no Portal replica-currentness state, and the local
    `status = active | archived` column must NOT be reused as Portal snapshot
    currentness without a reviewed decision. Still unresolved: what happens when
    a previously synced Portal Game Type disappears; whether
    `portal_is_current` / `portal_not_current_at` are added; how a reappearing
    Portal Game Type is restored; how the transitional local lifecycle interacts
    with replica currentness; the deterministic internal `code` strategy for a
    newly imported Portal Game Type; and name/collation collision handling
    during import. ADR-037 deliberately does not resolve any of it.
- No Hall Staff Management work currently in progress.
- No other Catalog or Session implementation work is in progress. Package
  Increment 1 and Session Increment 1 remain COMPLETE and are recorded under
  Done.

---

## Immediate Demo Critical Path
1. **Package Increment 1** — **COMPLETE.** Stage A and Stage B are implemented,
   source reviewed, automated-verified and interactively verified in wp-admin.
2. **Session Increment 1** — **COMPLETE.** Implemented, source reviewed,
   automated-verified (344/344) and interactively verified in wp-admin on a
   Network Active plugin, including tenant isolation and Game Operator denial.
3. **Phase 3C1 — Portal Game Type identity persistence: COMPLETE. Phase 3C2 —
   Portal Session-template replica persistence: COMPLETE. Phase 3D1 — Portal
   HTTP transport: COMPLETE.** The next checkpoint is **Phase 3D2 — strict
   Portal snapshot validation**, which is not implemented; overall Phase 3D is
   not complete. Snapshot application/import remains Phase 3E. Commerce
   selection remains separate and is not implemented.

Everything below is tracked work that is NOT a blocker to that demo.

## Before Customer-Facing Digital Launch
1. Complete the remaining Portal integration delivery increments. Phase 3B
   Hall mapping/config, Phase 3C1 Game Type identity persistence, Phase 3C2
   Session-template replica persistence, and Phase 3D1 read-only HTTP transport
   are complete. Remaining work begins with Phase 3D2 strict snapshot
   validation, followed by Phase 3E snapshot application/full-pull idempotent
   synchronization, followed by a separate second-mapped-Hall tenant-isolation
   proof, then the separately reviewed webhook, reconciliation, and tenant-
   provisioning increments. Any further Game Type/Session schema or writer
   change requires its own reviewed compatibility/application design. SSO
   remains a later integration phase and is not a customer-launch prerequisite.
2. The BR-066 storefront mechanism that enforces Bingo Play Product
   non-purchasability. Deferred by decision; requires a new reviewed entry
   before any implementer selects a mechanism.
3. Package add-ons: `PackageAddonService`, `bingo_package_addons`, BR-062's
   quantity ceiling, and the BR-065 `bc_package_relation` projection. This is a
   SEPARATE concern from the Package base writer and must not be folded into it
   without its own approved slice.
4. Checkout electronic-limit enforcement (BR-042) and redemption accumulation
   (BR-045), including the effective-limit resolver (BR-038, BR-064).
5. Entitlement snapshot (BR-043, ADR-029), QR issuance (BR-044) and Android
   redemption (ADR-030).
6. Exact WooCommerce successful-payment hook/status mapping for BR-053
   entitlement activation. The business rule is resolved; only the integration
   mapping is open.
7. Coordinated starter-product catalog copy at provisioning (BR-047, ADR-027);
   Game Types instead follow ADR-035 synchronization.
8. Package hard delete, including BR-065's mandatory linked-product
   disposition. No hard delete exists in any Catalog entity today.

## Production Hardening / Technical Follow-ups
1. Platform Admin network identity Disable/Reactivate/Permanent Delete remains
   deferred under BR-035 §4 and requires a separate architecture/decision pass.
2. Review the ADR-025 `HallStaffTransactionTransport`
   `information_schema.innodb_trx` false-negative recorded above. It does not
   block Catalog work and must not be folded into a Catalog slice.
3. Asserting or pinning the transaction isolation level inside the ADR-032
   transport (recorded as a follow-up by ADR-034). Not a prerequisite for
   BR-069.
4. Fix the remaining `tests/game-types-increment-1.php` harness issue: the
   orphaned `catalog.game_type.*` audit rows left behind for Game Types the
   suite deletes.
5. Provider selections that remain open: Redis, S3-compatible storage, CDN, and
   production hosting (ADR-013).

## Local Development Cleanup
1. Resolve the local PHP/Homebrew libcurl `.test` hostname-resolution stall
   recorded above. It is an environment issue, not a platform defect, and must
   not be worked around in repository code.
2. The only remaining Play Product-suite residue detection is Tampa Game Type
   `#39 "PPI1 Tampa Only 89AUU"`. Ownership remains unproven, so it was not
   deleted. This is a residue detection only, not a functional failure.
3. Deliberately remove six orphaned Catalog tables left by disposable halls
   that were destroyed BEFORE the teardown fix: `bc_9_bingo_packages`,
   `bc_9_bingo_package_components`, `bc_10_bingo_packages`,
   `bc_10_bingo_package_components`, `bc_12_bingo_packages` and
   `bc_12_bingo_package_components`. Sites 9, 10 and 12 no longer exist in
   `wp_blogs`. New disposable halls can no longer leave these behind, and the
   suites do not delete them, because ownership is inferred rather than proven.
4. **Interrupted CLI test runs can lose persistent-hall fixture ownership
   proof.** The durable `_bc_pkga_test_artifact` marker lives on the WooCommerce
   post. If a run is interrupted after the marker-proven post is deleted but
   before the corresponding Catalog rows are removed, the surviving Catalog rows
   become permanently unprovable: the evidence was destroyed with the post. Do
   NOT infer ownership for orphan Catalog rows from title, prefix, timestamp,
   id range, a missing Woo post, or domain shape. Recorded, not resolved.
5. **Sunrise operator-adjudicated residue cleanup is complete.** Packages
   `#91`-`#94`, components `#242`-`#248`, Bingo Play Products `#1029`-`#1037`,
   and Game Types `#1345`-`#1347` were deleted under explicit human/operator
   authorization, not harness-proven ownership.
6. **Disposable hall cleanup is complete for `#78` and `#76`.** Both site
   records and only their exact `bc_78_*` / `bc_76_*` custom tables were
   removed; audit history was preserved. Hall `#76` was deleted by explicit
   operator adjudication, and whole-site harness ownership was not claimed as
   proven.
7. **Disposable test halls emit WooCommerce Action Scheduler missing-table
   warnings.** Observed: disposable halls created by the current CLI test
   harness are missing the Action Scheduler tables when WooCommerce product
   saves run, logging `bc_{id}_actionscheduler_actions doesn't exist` noise. The
   exact provisioning/root-cause contract remains a follow-up. It is not Package
   logic and does not affect any Package assertion.
8. **Action Scheduler backlog and local WP-Cron execution investigation.**
   Classified NON-BLOCKING LOCAL ENVIRONMENT ISSUE and recorded in full under
   Done for 2026-08-31. Sunrise's past-due backlog is growing, is ~99.5%
   WooCommerce product-attribute-lookup work generated by Catalog/Package test
   product churn, and predates Session Increment 1; the Action Scheduler tables
   on the real halls are healthy and carry no stale claims; local cron is not
   reliably completing and the failure mechanism is UNKNOWN. **Do NOT clean
   Action Scheduler. Do NOT execute, cancel, retry or delete scheduled
   actions.** This is separate local-environment maintenance and blocks
   nothing.
9. **Local `class-wp-http.php` 30-second timeout observed on the Game Operator
   denied-page flow.** Seen during Session Increment 1 manual wp-admin
   verification. The denial itself was correct and the check PASSED; the stall
   is local HTTP behaviour, is consistent with item 1's `.test`
   hostname-resolution stall, and must not be worked around in repository code.
10. **Local cleanup state.** The operator-authorized Sunrise and PKGA cleanup
    recorded under Done is complete; those deleted records and halls no longer
    remain. Tampa Game Type `#39` remains the sole unproven Play Product-suite
    residue detection and was not deleted. The unrelated orphan-table,
    ownership-proof, Action Scheduler/local-cron, and local HTTP follow-ups
    above remain open. Sunrise Game Type `#654 "Manual Test Regular"` remains
    protected manual data.

## Standing Execution Rules
- Continue only through separately reviewed, focused implementation slices that
  preserve ADR-031, ADR-032, ADR-033, ADR-034 and the detailed persistence
  design.
- The frozen `docs/architecture/catalog-session-persistence-design.md`
  §"Catalog lock-order review" is accurate as written. ADR-033 locks
  `bingo_packages` then `bingo_play_products` ascending, and locks no component
  rows at all.
- Latest verified results: Phase 3D2 Portal snapshot validation **1443/1443
  PASS**; Phase 3D1 Portal HTTP transport **67/67 PASS**; Phase
  3B Portal integration foundation regression **75/75 PASS**; Phase 3C2 Portal
  Session-template replica persistence **98/98 PASS**; Phase 3C1 Portal Game
  Type identity persistence **70/70 PASS**; Session Increment 1 **344/344
  PASS**; Package Stage B **258/258 PASS**; Package Stage A **313/313 PASS**;
  Game Type **164/164 PASS**. The Play Product suite reports **461/461
  functional/regression assertions PASS** with zero functional/regression
  failures; the selector
  regression passes; and current-run disposable-hall cleanup reports **5/5
  PASS** with zero cleanup failures. **Phase 3C1 stale-harness caveat —
  RESOLVED on 2026-09-07.** `tests/portal-game-type-identity-persistence.php`
  had stopped at its fourth assertion, `no Portal Session-template table exists
  before the suite` (expected `0`, actual `3`), because that precondition was
  written before Phase 3C2 existed: the three tables are
  `bc_2_/bc_3_/bc_4_bingo_portal_session_templates`, created by Phase 3C2's
  committed migration on Sunrise/Tampa/Orlando. It was verified failing at
  commit `f50fb09` before any Phase 3D2 change and was never a Phase 3D2
  regression. Test-only maintenance replaced that obsolete absence precondition
  with a baseline of the already-existing replicas plus scoped after-suite
  assertions that Phase 3C1 creates, removes, and mutates none of them, and
  extended the harness's own disposable-hall cleanup to drop the replica that
  live Sessions provisioning now gives every hall the run itself creates. The
  historical 66/66 landing figure stands; the corrected harness passes 70/70.
  No production code, schema, or hall data was changed. Tampa Game Type `#39 "PPI1 Tampa Only
  89AUU"` remains an unproven, undeleted residue detection only, not a
  functional failure. The persistent Sunrise protected-row snapshot was
  byte-identical before and after the run.

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
- **Local:** macOS (arm64), PHP 8.4.24, MySQL 9.5, Apache
- **WordPress:** 7.0.x Multisite subdomain; table prefix `bc_`
- **Halls:** Sunrise (2), Tampa (3), Orlando test hall (4) (Local)
- **Commerce:** WooCommerce network-active, HPOS on
- **Local mail:** SMTP configured and verified for WordPress staff invitation/password setup testing
- **Custom code:** `wp-content/plugins/bingocommerce-core/`,
  `wp-content/plugins/bingocommerce-catalog/` and
  `wp-content/plugins/bingocommerce-sessions/` (all three Network Active);
  `wp-content/plugins/bingocommerce-portal-integration/` is declared
  network-only and contains the Phase 3B Hall mapping/config foundation, the
  Phase 3D1 read-only Portal HTTP transport, and the Phase 3D2 strict
  `PortalSnapshotValidator`; Phase 3E snapshot application/import is not
  implemented
- **Catalog schema:** per-site `bc_catalog_db_version` = 4
  (`bingo_game_types`, `bingo_play_products`, `bingo_packages`,
  `bingo_package_components`); v4 adds nullable, unique
  `bingo_game_types.portal_game_type_id`
- **Session schema:** per-site `bc_sessions_db_version` = 2
  (`bingo_sessions`, `bingo_session_packages`,
  `bingo_portal_session_templates`); v2 adds the Portal Session-template replica
  table
- **Rule for AI agents:** Read `AGENTS.md`, `docs/architecture/plugin-architecture.md`, and this file before coding. Do not re-scaffold Multisite, WooCommerce, or core bootstrap.
