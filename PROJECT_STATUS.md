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
- **[2026-09-08] Phase 3E — Portal snapshot application: COMPLETE.** Source
  review passed and the authenticated real Portal test passed against a local
  Portal test environment. ADR-040 approves and this
  increment implements `PortalSyncService`, `PortalSyncLock`,
  `PortalSnapshotApplicationPlan`, `PortalSnapshotTransactionTransport`,
  `PortalSyncState`, and `PortalSyncResult` in
  `bingocommerce-portal-integration`. The per-Hall advisory lock
  `bc:ps:<discriminator>:<blog_id>` is taken BEFORE the Portal request and
  released in `finally`; HTTP and validation complete before BEGIN; one
  transaction applies Game Types, Session templates, and the sync-state marker
  in the fixed lock order mapping -> Game Types -> Session templates -> sync
  state, with every lock taken as a read before the first write.
  - AGENTS.md §5 rule 4 now lists ADR-040's transport as the fourth approved
    captured-`$wpdb->dbh` boundary — the only one spanning both hall replicas.
  - No schema change: Catalog stays v5, Sessions stays v2, no new table, and no
    `portal_updated_at` column. The only new state is the non-autoloaded
    per-site option `bc_portal_snapshot_sync_state`, written inside the same
    transaction so no failed or rejected attempt leaves a sync-state row.
  - Ordering rejects only a strictly older `generated_at` instant; equal
    instants apply. Session-template `not_current_at` is stamped on
    disappearance, cleared on reappearance, and untouched during continued
    absence. Local Game Type rename of a linked row is NOT blocked in this
    increment and archive/restore is unchanged; Portal restores the
    Portal-owned name on the next pull.
  - No admin UI, cron, REST route, webhook, SSO, provisioning, Commerce
    Session, Package, product, or scheduling behaviour was added.
  - Source-review hardening: Phase 3E production code never calls
    `is_wp_error()` (it fires the `is_wp_error_instance` action), and a runtime
    observer proves a mid-transaction failure fires zero such callbacks;
    `PortalSyncLock` carries a request-local reentrancy guard that RESERVES the
    lock name before the first `$wpdb` acquisition query — `wpdb::query()` fires
    the `query` filter, so a callback on the GET_LOCK statement itself could
    otherwise re-enter and take the re-entrant MySQL lock again — releasing the
    reservation in `finally` if acquisition fails or throws, and holding it
    across every release-path database call. Release gives the reservation up
    only on proof the lock cannot still be held: a valid positive connection id
    that differs from the acquiring one, or `RELEASE_LOCK` returning exactly 1
    without a query error. The connection id is validated before it is cast,
    and a `Throwable` from either release query is caught so it cannot replace
    an already-committed synchronization result. Any UNCONFIRMED release —
    0, NULL, false, a query error, an unusable connection id, an exception —
    intentionally KEEPS the reservation and leaves that Hall refused for the
    remainder of the PHP request, logging one fixed generic message; refusing a
    later synchronization is recoverable, handing out a possibly-held lock is
    not. An absent marker row is the only
    "no previous state" case, with malformed, bad-timestamp, and wrong-Hall
    markers rejected as `portal_sync_state_invalid` with zero writes; no raw
    MySQL message reaches returned error data, only a numeric errno plus an
    allowlisted constraint identifier; and every participating table is verified
    to exist and be InnoDB before BEGIN.
  - `tests/portal-snapshot-application.php` passes **387/387** (the historical
    381/381 landing figure stands; the count rose to 387 on 2026-09-09 when the
    registration-log cleanup correction added one assertion per disposable
    site) and `tests/portal-snapshot-sync-worker.php` supplies real same-Hall
    lock contention. Sunrise, Tampa, and Orlando rows, schemas, WooCommerce
    state, and the Hall mapping table were byte-identical before and after the
    run, and all six disposable halls plus their test mappings were destroyed.
  - **Authenticated real Portal test: PASSED.** Run against a local Portal test
    environment at `http://127.0.0.1:8000`, with the integration token supplied
    from a `0600` file to a temporary runner outside the repository and never
    written to `wp-config.php`, Git, logs, or output. Portal Hall 2 was mapped
    to BingoCommerce site 2 through `HallMappingRepository::bind( 2, 2 )` — no
    raw SQL — and that mapping is retained.
    - First synchronization imported **7 Game Types and 26 Session templates**
      (`portal_snapshot_applied`). Imported Portal IDs, names, `portal_status`,
      and currentness match the validated Hall 2 snapshot exactly; names are
      byte-identical; every linked row carries `local_name_key` NULL and a code
      in the approved `portal-gt-<id>` family; the 5 active and 21 draft
      templates are all `is_current = 1` with `not_current_at` NULL.
    - Second synchronization was a **domain no-op**: zero Game Type and zero
      Session-template changes.
    - The sync-state marker exists, is non-autoloaded (`autoload = off`),
      decodes cleanly, and records `portal_hall_id = 2` with the applied
      `generated_at`. The Portal restamps `generated_at` on every request while
      content stays stable, so later pulls are strictly newer and apply as
      domain no-ops rather than being rejected as stale.
    - **Tenant isolation and Commerce non-interference passed.** Against
      pre-import baseline hashes, site 2 changed in exactly three places —
      Game Types, Session templates, sync marker. Commerce Sessions, session/
      package assignments, Packages, package components, Play Products, posts,
      postmeta, and `wc_orders` were UNCHANGED, as were Tampa and Orlando in
      every table. Sunrise's 2 pre-existing local Game Types remained
      byte-identical: not adopted, renamed, or relinked.
    - A post-import regression gate re-ran the full suite. All Portal, Catalog,
      and Session harnesses passed; `tests/play-products-increment-1.php`
      reproduced only the pre-existing, documented Tampa Game Type `#39`
      residue detection, byte-identical in both its FAIL and NOTE lines to the
      pre-import run, with no additional failure.
  - **Production deployment is NOT claimed.** This was a local Portal test
    environment on `127.0.0.1`; no production Portal, hosting, or customer data
    was involved, and no deployment has been performed.
- **[2026-09-09] Phase 3F — multi-Hall tenant-isolation proof: COMPLETE.** The
  Phase 3E automated harness already tested multiple disposable Hall mappings
  and tenant isolation, while its authenticated real-Portal run used one
  persistent mapping (Portal Hall 2 -> site 2). Phase 3F added the separate
  practical proof with two simultaneously active mappings against the real local
  Portal endpoint at `http://127.0.0.1:8000`, proving tenant isolation under
  real endpoint execution with a second mapped Hall present.
  - **Hall A stayed the established pair.** Portal Hall `2 -> site 2` remained
    active and mapped throughout; it was never deleted, disabled, or rebound,
    and its imported data was retained.
  - **Hall B was created disposable and bound dynamically.** A dedicated Portal
    test Hall was created through `HallManagementService::createHall()` and a
    disposable Multisite site through `wp_insert_site()`; both IDs were taken
    from the creation return values, asserted absent from the opening baseline,
    and asserted outside the protected `1-4` range before use. No Portal Hall
    ID or site ID was hard-coded. The pair was bound with
    `HallMappingRepository::bind()` — no raw SQL — and the existing integration
    token was granted Hall B with `syncWithoutDetaching()`, leaving Hall 2's
    grant intact.
  - **Independent synchronization.** Hall A's first synchronization was a
    domain no-op, as expected for an already-imported Hall. Hall B's first
    synchronization imported **3 Game Types and 5 Session templates** (2 active
    and 3 draft), matching its Portal fixtures exactly.
  - **Repeat synchronization of both Halls produced zero domain-row changes.**
    Both repeats reported `portal_snapshot_applied` with every count at zero.
  - **Portal identities never crossed between sites.** Site 2 held exactly Hall
    2's Game Type IDs and its 26 Session-template IDs; Hall B's site held
    exactly its own 3 Game Type IDs and 5 Session-template IDs. Both sets were
    disjoint, and each site's sync marker recorded its own mapped
    `portal_hall_id`.
  - **Existing local and Commerce-owned data was unchanged.** Sunrise's 2
    pre-existing local Game Types stayed byte-identical — not adopted, renamed,
    or relinked — and Packages, package components, Play Products, Commerce
    Sessions, session/package assignments, posts, postmeta, and `wc_orders`
    were unchanged on site 2, as were Tampa and Orlando throughout.
  - **Not every database value was byte-identical, and the exceptions are
    expected.** Site 2's `bc_portal_snapshot_sync_state` marker advanced its
    `generated_at` normally, because the Portal restamps it on every request,
    and WooCommerce updated one unrelated `wc_last_active` usermeta row. Both
    are normal platform behaviour, not tenant leakage.
  - **Cleanup removed only Hall B's disposable resources.** The temporary
    mapping row was deleted under guards requiring both dynamically returned
    IDs, absence from the opening baseline, exclusion of sites/Halls `1-4`, the
    exact pair present exactly once, and exactly one row deleted; the mapping
    table then equalled its opening baseline. The disposable site, its
    per-site tables, its site-scoped usermeta, and its uploads directory were
    all removed, the temporary token grant was detached before the Hall was
    deleted, and the Portal Hall was hard-deleted with its Game Types and
    Sessions cascading.
  - **Legitimate audit records and auto-increment advancement were preserved.**
    The two Portal `audit_logs` rows written by the supported service — the
    disposable Hall's `created` and `hard_deleted` entries, both carrying
    `hall_id = NULL` because that column is `ON DELETE SET NULL` — were kept as
    test evidence, and no pre-existing audit row was removed or modified.
    Auto-increment counters advanced as expected and were not reset.
  - **Production deployment is NOT claimed.** This was a local Portal test
    environment on `127.0.0.1`; no production Portal, hosting, or customer data
    was involved, and no deployment has been performed.
- **[2026-09-09] Phase 3E harness registration-log leakage: CORRECTED.**
  `wp_delete_site()` removes a site's row, blogmeta, and per-site tables, but
  the registration log is a Multisite-*global* table
  (`$wpdb->registration_log`, keyed by `blog_id`) that no core deletion path
  touches, so every disposable hall left a permanent row behind.
  `tests/portal-snapshot-application.php` now deletes the registration-log row
  for each of its own disposable `blog_id`s and asserts zero remain, so future
  harness runs are **net-zero for their disposable site registrations**. The
  fix is scoped to sites recorded in the current run's `disposable_sites`,
  guarded to IDs greater than `4`, and uses a typed, parameterized delete on
  the fixed `$wpdb->registration_log` property. The 7 verified rows left by the
  Phase 3F and Phase 3E runs were removed under per-row guards; 391 historical
  rows for earlier blog IDs were deliberately left untouched. No production PHP
  changed.
- **[2026-09-09] Phase 3G — manual Hall-scoped synchronization: COMPLETE.**
  Source review passed, the local harness landed at a historical **411/411**
  and currently reports **467 passing assertions** after fixture-isolation
  commit `7f3e332`, and the practical
  browser test PASSED against a local Portal test environment. ADR-041 approves
  and this increment implements `PortalSyncAdmin` in
  `bingocommerce-portal-integration` plus the `bc_sync_portal` capability in
  `bingocommerce-core`. No Phase 3E production file changed. **Production
  deployment is NOT claimed, and scheduling and webhooks do not exist.**
  - **Authorization.** A dedicated `bc_sync_portal` capability is granted only to
    `bc_hall_manager`, never to `bc_game_operator` or any other role. It rides
    the existing versioned role mechanism (`Roles::SCHEMA_VERSION` 1 -> 2), so
    existing halls converge through `maybe_provision_network()` /
    `maybe_sync_current_site()` and new halls through `provision_new_site()`. No
    new role, migration framework or table. Network Super Admin retains
    emergency/oversight access through ordinary Multisite capability behaviour
    plus the explicit `is_super_admin() ||` gate the other screens use, working
    from the same hall-local page; there is no network-wide Hall selector.
  - **Tenant boundary.** The target site comes only from
    `HallContext::site_id()` / `get_current_blog_id()`. No `blog_id`, Portal Hall
    ID, mapping ID or network ID is read from GET or POST, and the form carries
    no such field, so Phase 3E's four independent hall re-derivations cannot be
    redirected by a forged request.
  - **Request mechanism.** A hall-local `Portal Sync` page
    (`bingocommerce-portal-sync`) posts to the authenticated
    `admin_post_bc_portal_sync` handler; no `admin_post_nopriv` twin exists.
    The handler enforces POST, hall context, capability and nonce — all before
    any Portal fetch — then calls `PortalSyncService::synchronize()` exactly
    once, records a safe audit event, and redirects with HTTP 303. Phase 3E
    still owns locking: Phase 3G adds no lock, transient, cooldown or rate limit.
  - **Safe output.** The redirect carries at most `page`, `bc_sync`
    (`applied` | `no_change` | `error`), an allowlisted `bc_sync_code`, and
    `bc_sync_audit=1` — never counts, messages, identifiers, endpoints or error
    data. Failures are reduced to fixed categories from an explicit table of real
    Phase 3B/3D/3E error codes; an unrecognised code becomes `unknown`.
    `get_error_message()` and `get_error_data()` are never rendered.
  - **Audit.** `portal.snapshot.synchronized` (with the five integer counts and
    `outcome` of `applied` or `no_change`) and `portal.snapshot.failed` (with an
    allowlisted category only), both attributed to the trusted current site and
    the authenticated actor. An audit write failure never rewrites the
    synchronization outcome; it adds one fixed secondary warning and a single
    fixed generic log line.
  - `tests/portal-sync-admin.php` landed at a historical **411/411** across two
    consecutive runs with an identical count, and after fixture-isolation commit
    `7f3e332` the current measured result is **467 passing assertions**. It
    drives the real registered page and handler through
    `tests/portal-sync-admin-worker.php`. The Portal is intercepted at
    `pre_http_request`. `wp-config.php` executes and evaluates its definitions,
    including the Portal constants; fixture constants defined earlier remain the
    effective runtime constants because PHP keeps the first definition, so the
    operator values do not become the effective runtime test credential and do
    not reach an Authorization header, Portal request, output, redirect, audit
    context or test artifact.
  - **[2026-09-09] Source-review corrections applied.**
    - `record_audit()` now wraps `AuditLog::record()` in `try/catch ( \Throwable )`.
      A thrown exception and a returned `WP_Error` are handled identically: the
      real synchronization outcome is preserved, the redirect still carries
      HTTP 303, only `bc_sync_audit=1` is added, and one fixed generic log line
      is written. The exception object is never read, so its message, class and
      trace cannot reach the browser or the log. A worker scenario throws
      mid-insert and proves the exception does not escape, the committed rows
      are neither rolled back nor re-applied, the synchronization is not
      repeated, and no fixture string leaks.
    - Readiness now distinguishes an absent mapping from an unavailable mapping
      SYSTEM. A clean read that finds no active mapping shows `Not linked`; a
      missing mapping schema or a failed mapping read shows the fixed
      `Synchronization unavailable` state and the Portal Hall row reads
      `Unavailable` rather than falsely claiming the hall is not linked. The
      button is withheld in both cases, and no schema, table, SQL or `WP_Error`
      detail is rendered. The POST handler still leaves authoritative mapping
      resolution to Phase 3E.
    - The last-synchronization row is labelled **UTC**, matching Phase 3E's
      `current_time( 'mysql', true )` storage.
    - The worker captures `wp_redirect_status`, and applied, no-change and error
      redirects are each asserted to use HTTP **303**.
    - The worker's misleading `SERVICE=` line is renamed `HANDLER_REGISTERED=`,
      which is what it actually reported.
    - The production error-category table contains **52** mappings across 17
      categories, asserted by the harness.
    - Harness cleanup now removes current-run audit rows under two guards —
      `id > ` the opening high-water mark **and** a `blog_id` this run created —
      and then asserts none remain. A deleted hall alone is never grounds for
      deletion, and historical or persistent-hall rows are never touched.
  - **[2026-09-09] Practical browser test: PASSED.** Run in a real browser
    against the local Portal test environment at `http://127.0.0.1:8000`, with
    the integration plugin network-activated through `activate_plugin()` and a
    freshly issued web token supplied via the ADR-036 constant boundary in
    `wp-config.php` (git-ignored). Activation itself wrote only
    `active_sitewide_plugins`: the network schema stayed at version `1`, the
    database table count was unchanged at 239, sites 2-4 domain tables were
    byte-identical, and no synchronization occurred merely from activating.
    - A non-Super-Admin **Hall Manager** on Sunrise/site 2 saw the Portal Sync
      page and synchronized successfully. The Apache log shows three deliberate
      `POST /wp-admin/admin-post.php` clicks, each answered **HTTP 303** and each
      followed by a `GET` of the hall-local page: the first applied
      (`bc_sync=applied`), the second and third were domain no-ops
      (`bc_sync=no_change`).
    - **One audit event per request, three in total, all correctly attributed** —
      `portal.snapshot.synchronized`, `blog_id = 2`, actor = the Hall Manager,
      `target_type = portal_hall`, `target_id = 2`; contexts `{"outcome":"applied"}`
      then `{"outcome":"no_change"}` twice, carrying only the five integer counts.
    - **Refresh performed no synchronization.** Five page `GET`s produced zero
      additional POSTs and zero additional audit events; POST count equalled
      audit-event count exactly. No form-resubmission warning appeared, which is
      the PRG contract working.
    - **Site 2 changed only in approved Portal replica/sync-state areas:**
      `bingo_game_types` 9 -> 10 rows (Portal Game Type `45 "Mini Mad"`, imported
      as `portal-gt-45` with `local_name_key` NULL) plus the
      `bc_portal_snapshot_sync_state` marker. Play Products, Packages, package
      components, Commerce Sessions, session/package assignments, posts, postmeta
      and orders were untouched, and the 2 pre-existing local-only Game Types
      (`#654`, `#1343`) survived byte-identical.
    - **Tampa/site 3 and Orlando/site 4 were byte-identical** to the pre-test
      baseline in every domain table, with their sync markers absent throughout.
    - **Mapping `Portal Hall 2 -> site 2` remained active and unchanged**,
      including its `created_at`/`updated_at`.
    - **Game Operator denied** — the menu did not register, and a direct `GET` of
      the Portal Sync page URL returned **403**. WordPress did not redirect the
      unauthorized user; the request was refused. **Network Super Admin** reached
      the same hall-local page.
    - **No secret disclosure in tracked material.** The active token does not
      appear in tracked source, the Phase 3G diff, any Portal Sync page URL, a
      rendered notice, an audit record, or any log inspected during verification.
      Portal Sync page URLs carried only `page` and `bc_sync`, and the three audit
      rows contain no token, Authorization header, endpoint, snapshot body, SQL,
      errno or lock name. The active token IS present in `wp-config.php`, which is
      git-ignored and untracked configuration, so this is not a claim that no
      token exists anywhere in the working tree.
  - **Credential lifecycle.** A fresh token (`id 2`,
    "BingoCommerce Phase 3G local browser test") was issued through the Portal's
    own token service and is **ACTIVE, granted Hall 2 only**. The superseded
    Phase 3E CLI token (`id 1`) is **REVOKED**; it was revoked only after the new
    token authenticated successfully, and is retained as a revoked record rather
    than deleted. **Both temporary plaintext token files were deleted** — no
    plaintext remains in `/private/tmp`. The active token is currently configured
    **directly through the ADR-036 `BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN`
    constant in `wp-config.php`**, which is git-ignored and untracked; the Portal
    itself stores **only the SHA-256 hash**, and its table has no plaintext
    column. The two temporary `p3g_*` browser-test users were removed through
    `HallStaffService` and `wpmu_delete_user()`, leaving zero residual usermeta
    and every pre-existing real user untouched.
  - **NOT claimed:** no production deployment has occurred, and **scheduling and
    webhooks do not exist**. Local writer restrictions and reconciliation remain
    deferred and unimplemented. The plugin activation and web configuration are
    local test-environment state only.
  Overall Phase 3D is COMPLETE against ADR-037; ADR-039's
  name-validation amendment and Catalog v5 are implemented and tested; COMPLETE.
  - **[2026-09-07] Pre-Phase 3E Design Slice 1 — snapshot completeness:
    documented in ADR-038; COMPLETE.** A successful Portal v1
    snapshot is complete, non-paginated, and non-delta for one trusted mapped
    Hall. `game_types` contains every owned Portal Game Type; `sessions`
    contains every qualifying owner-only Portal Session template, excluding
    shared and received Sessions. Empty collections are authoritative, and
    membership represents one logically consistent source boundary. Phase 3D2
    validates structure but cannot independently prove completeness; the Portal
    snapshot endpoint contract guarantees it.
  - **Game Type absence is a contract/integrity violation.** Portal Game Type
    deletion is prohibited in v1; Portal Game Type Archive/Restore is not part
    of v1. A previously linked Portal Game Type missing from a later successful
    complete snapshot requires Phase 3E to reject the entire application
    attempt and perform zero writes. The local row, ID, code, name, status,
    references, and timestamps remain unchanged. Missing Game Types must not be
    deleted, archived, unlinked, or marked not-current; Game Type
    `portal_is_current` / `portal_not_current_at` fields are not required for v1.
    This also applies to an empty `game_types` collection when a previously
    linked Portal Game Type exists in that Hall. A later valid snapshot with
    the same Portal ID may be processed normally, matching only by Portal
    `game_types.id` within the trusted mapped Hall.
  - **Absence safety:** HTTP failure, validation failure, wrong-Hall data,
    incomplete responses, or application failure never create missing-record
    meaning. Names and codes never determine disappearance; duplicate Portal
    names with different IDs remain valid and distinct. No Commerce scheduling
    or product/order authority changes. This slice changes documentation only;
    no PHP, tests, migrations, plugins, schemas, or Phase 3E implementation.
  - **[2026-09-07] Pre-Phase 3E Design Slice 2 — names and internal codes:
    approved design documented in ADR-039; COMPLETE; implementation pending.** Catalog
    v4 -> v5 will retain exact names in `name varchar(160)`, add nullable
    `local_name_key varchar(160)` with the same charset/collation, replace
    `name_unique(name)` with `local_name_unique(local_name_key)`, and add
    nonunique `name_id(name, game_type_id)`. Local keys copy stored names;
    linked keys are SQL `NULL`. Distinct Portal IDs retain separate rows even
    with duplicate/collation-equivalent names; no name-based linking or merging.
    Imported codes try `portal-gt-<id>`, then suffixes `-2` through `-32`;
    `code_unique` governs collisions in the unreserved namespace. No code-based
    adoption/overwrite; allocated codes stay immutable, including on rename.
    Exhaustion rejects the complete application without partial domain changes.
    Backfill preserves names, codes, IDs, statuses, references, and timestamps;
    no automatic linking. `dbDelta()` remains normal, with one allowlisted,
    idempotent `DROP INDEX name_unique` exception, target verification before
    and after removal, replacement-key/backfill verification, and version
    advancement only after complete verification. New sites receive final v5.
  - **[2026-09-07] ADR-039 name-validation amendment: implemented and tested;
    COMPLETE.** Hall, Game Type, and Session names reject Unicode
    `Cc`/`Cf`, `Zl`/`Zp`, and whitespace/Unicode-separator-only names. Accepted
    names remain byte-for-byte unchanged with existing length limits. Errors
    reuse `portal_snapshot_invalid_contract` and only the safe structural path.
    Focused Unicode, callback-free, zero-HTTP, source-boundary, and persistent-
    state checks pass. Catalog v5 and Phase 3E remain unimplemented; no migration,
    schema, imported-code allocation, or snapshot application was added.
  - **Remaining pre-Phase 3E design work:** snapshot replay/stale-order
    handling; Phase 3E transaction and locking mechanics;
    local writer restrictions after synchronization; and Session-template
    application behavior beyond already-frozen decisions. Commerce scheduling,
    webhooks, SSO, and provisioning are not decided or implemented by this slice.
- **[2026-09-10]** Commerce-state persistence increment implemented and tested
  (internal persistence only).
  - New network table `{base_prefix}bingo_hall_commerce_state` (`blog_id`
    primary key, `seq`, `state`, `updated_at`) holding `provisioning` /
    `operational` / `suspended`, owned by a NEW `ProvisioningMigrations` class
    under its own `bc_portal_provisioning_db_version` option. The shipped Hall
    mapping schema version (`Migrations::NETWORK_DB_VERSION`) remains **1** and
    is deliberately untouched, so mapping reads and Portal Sync are unaffected.
  - `HallCommerceState` writes through a single guarded
    `INSERT ... ON DUPLICATE KEY UPDATE` whose ordering guard lives in the
    database, with `seq` assigned last. The stored row read back afterwards is
    authoritative for `superseded`, `conflict` and `unverified`. **Affected-row
    semantics are enforced, not assumed.** Normally MySQL reports 1 for an
    insert, 2 for a changed duplicate and 0 for a duplicate that already held
    those values. A connection carrying CLIENT_FOUND_ROWS reports the unchanged
    duplicate as 1 instead of 0 (the changed duplicate is still 2), which makes
    an insert and an unchanged duplicate indistinguishable — so that mode is
    refused, checked against the `MYSQL_CLIENT_FLAGS` constant wpdb actually
    uses, before any write. Only after the read-back confirms the requested
    sequence AND state do affected rows separate `applied` from
    `already_applied`. A timestamp comparison is **not** used for that:
    identical requests within one second share a timestamp, which the
    same-second retry test exercises directly — asserting both calls landed in
    one second, then applying the retired rule to two INDEPENDENT inputs (the
    stored timestamp and the separately captured timestamp of the retry) to show
    it would have misclassified that retry as `applied`.
  - Validation: parameters are untyped and checked explicitly, so a weakly typed
    caller cannot coerce `"5"`, `5.0`, `5.7` or `true` into an identifier or a
    sequence. The packer's numeric capacity (256 phases) is separate from the
    lifecycle phases; `provisioning` must be transitional, `operational` and
    `suspended` settled, and reserved phases are refused as lifecycle writes.
    Site IDENTITY (exists, this network, not the main site) is checked on
    **reads as well as writes**; lifecycle eligibility (deleted/spam refused,
    archived deliberately supported) applies to writes only, so a recorded state
    stays readable after a site is later marked deleted. Stored rows are
    validated for Hall-ID representation and range, sequence range, state/phase
    agreement and timestamp representation before being returned as trusted
    data; malformed storage stays distinct from verified absence.
  - Database paths — preconditions, the guarded write, the confirmation read,
    schema verification, version reads and version recording — catch
    `Throwable`, restore error suppression in `finally`, and return fixed
    categories with no SQL, driver text or traces. A write whose confirmation
    fails or throws returns `unverified`; it never claims nothing was written.
    **An unreadable schema version stops the migration** before any `dbDelta()`
    or version write: `run_migration()` calls `read_version()` directly — it no
    longer routes the initial decision through `is_current()`, which collapses
    "could not read" to false — and returns immediately on `WP_Error`.
    `is_current()` keeps its boolean contract for other callers and fails
    closed. Tested through `migrate()` itself for a database error and for a
    one-time throw, asserting the ATTEMPTED writes are zero rather than
    inferring it from the final table state, with a genuinely-absent-version
    positive control.
  - **The migration is NOT wired to activation, `init`, `admin_init`, cron or
    any other automatic path.** `migrate()` is called explicitly and nowhere
    else; the bootstrap change loads definitions only.
  - **The migration has NOT been applied to the development database.** Tests
    boot WordPress itself against an isolated fixture
    (`tests/fixtures/isolated-multisite.php`) that supplies its own network and
    two Hall sites and never loads `wp-config.php`. Before WordPress builds its
    hooks the fixture seeds `enable_loading_object_cache_dropin` to false — the
    supported switch, since `WP_CACHE` does not prevent that drop-in loading —
    and a `pre_http_request` rejection, because `WP_HTTP_BLOCK_EXTERNAL` still
    permits localhost and the site's own host. A `db.php` routing drop-in causes
    the fixture to refuse to boot. Ownership is proven before any write: a
    pre-existing database must already carry the exact fixture identity (an
    empty one is refused too), and `created_by_this_run` is set only after this
    run actually creates the database. An inconsistent leftover state
    table/version combination aborts rather than being adopted or destroyed.
    Destructive work is limited to three named operations whose SQL is built
    inside the guard, covering the version option as well as the table.
  - Verified after the run: no state table and no version option in
    `bingo_commerce`; the Hall mapping table, its schema version, the four
    development sites and the absence of drop-ins are unchanged.
  - Harness `tests/hall-commerce-state.php`: **252/252 PASS** (run twice consecutively, exit 0, no warnings or cleanup errors).
    Concurrency uses **separate processes** whose readiness is observed through
    per-worker database locks rather than a sleep: the parent waits for the lock
    to be held by a connection that is neither its own nor a known blocker, and
    if readiness is not reached the gate is never opened, so no worker can write
    after a failed readiness phase. Completion is bounded too — output is drained
    without blocking and a worker that has not exited by the deadline is
    terminated and reaped. Two failure scenarios are covered: a worker that never
    becomes ready, one that stalls after the gate opens, and one that IGNORES
    SIGTERM; all three are asserted to fail within a measured elapsed bound,
    write no state, and leave no worker or coordination lock behind. Cleanup
    confirms each child's exit before `proc_close()` — terminate, bounded grace
    poll, escalate with SIGKILL, and record an explicit failure if exit still
    cannot be confirmed — and workers are stopped before the gate is released.
    The escalation path is exercised for real by a worker that installs
    `SIG_IGN`; it is skipped with an explicit note where pcntl is unavailable. A deterministic interleaving test lands a newer write from a
    second connection between another operation's write and its read-back and
    requires that operation to report `superseded`. Ownership negative controls
    run only in a scratch database **this run created** — a pre-existing one is
    refused untouched and never dropped — covering an unmarked empty database, a
    database with foreign tables, and a wrong sentinel identity. The
    connection-mode probe process inspects the parent's fixture and is asserted
    to leave its table, rows and version unchanged.
  - **Remaining limitations:** migration upgrade paths are untested — `DB_VERSION`
    is 1, so no prior version exists to migrate from; concurrency is exercised
    across OS processes on one machine, which proves the database guard resolves
    the race but says nothing about multi-host behaviour; and the fixture
    mirrors development table STRUCTURE (never rows) via `SHOW CREATE TABLE`, so
    a development schema change is reflected automatically but a development
    server-configuration difference is not.
  - **Enable/Disable Ecommerce is NOT implemented.** No access enforcement, no
    maintenance page, no managed-marker enrolment or backfill, no site creation
    or suspension orchestration, no mapping changes, no Portal routes,
    credentials or grants. Nothing reads this table yet.
- **[2026-09-11]** Hall Commerce **access enforcement** implemented and tested
  (enforcement only; still no enrolment, provisioning or suspension
  orchestration).
  - New `HallCommerceAccess` is the single access decision, reusing
    `HallCommerceState`'s stored-row validation and the existing tenant identity
    rules. No second state parser and no competing policy class was introduced.
  - **The shared reader no longer gates on migration-version metadata, and that
    correction fixes a real inversion.** `read()` used to return
    `state_schema_unavailable` BEFORE querying whenever
    `bc_portal_provisioning_db_version` was absent, unreadable or behind — so a
    Hall that was genuinely SUSPENDED or PROVISIONING reported "no usable state",
    and a Hall whose witness was missing was then served as LEGACY. Open for
    business while its stored state said otherwise. The row is now queried
    directly and the version is not consulted on the read path at all: a failed
    read is classified from the STORAGE (`SHOW TABLES LIKE`), so a missing table
    is `state_schema_unavailable` and a present-but-unreadable one is
    `state_read_failed`. Identity is still established BEFORE the row is read,
    so an identity failure can never be mistaken for "no state". Writes keep
    their version gate in full — creating or advancing state against a schema
    this build has not confirmed is a different risk from reading what is
    already there — and `migrate()` still refuses to rebuild while the recorded
    version claims the schema is installed. Both are asserted directly. Cost is
    unchanged in the legacy case (one failed SELECT plus one metadata probe,
    where it was one sitemeta read plus a short-circuit) and one indexed SELECT
    once the schema is deployed.
  - The durable managed-Hall witness is the per-Hall option
    `bc_commerce_managed`, kept separate from the state value, and it stores the
    ENROLLING OPERATION'S lowercase UUID v4 — not a flag. A flag records only
    that someone set something; an operation id records WHICH enrolment produced
    the Hall, which is what a later increment needs to reconcile a half-finished
    one. Validation is strict on case, version nibble and variant nibble:
    uppercase, v1/v3/v5, the nil UUID, an unhyphenated form, a padded form, an
    empty string and the retired `1` are all INVALID — never a verified absence.
    The pattern is anchored `\A...\z`, NOT `^...$`: PCRE's `$` also matches
    immediately before a final newline, so an `$`-anchored pattern ACCEPTED a
    valid UUID carrying a trailing LF. A trailing LF, CRLF, CR, space, tab or
    NUL, and a leading LF, are each written to the options table, read back and
    asserted INVALID — so the anchoring is proven against real stored values,
    not argued.
    Its read has four outcomes — valid, invalid, VERIFIED absent, unreadable —
    and is a direct `SELECT` against the target Hall's own options table
    (`$wpdb->get_blog_prefix( $blog_id ) . 'options'`), never `get_option()`.
    Three reasons, all correctness: `get_option()` needs `switch_to_blog()` to
    reach another Hall, it cannot report a database error, and **a cached miss
    would otherwise establish absence** — proven by priming WordPress's negative
    `notoptions` entry and then writing the row underneath it. The read uses
    `get_row()`, not `get_var()`: `wpdb::get_var()` maps an EMPTY STRING to
    `null`, which would have turned a witness stored as `''` into a verified
    absence and handed a managed Hall legacy access. WordPress core avoids
    `get_var()` in `get_option()` for the same reason.
  - **Identity failures never reach the witness.** `state_precondition_failed`
    — what the reader returns when Hall identity validation THROWS — had been
    classified as unavailable state STORAGE, so the access layer consulted the
    witness for it, and a verified-absent witness then produced `allow /
    legacy`: an unidentifiable site served as an ordinary pre-Commerce Hall. It
    is no longer in that list. An identity failure falls through to a block and
    the witness is not consulted at all, asserted directly by checking that the
    returned verdict carries no marker value. The regression test injects the
    throw through the installed `get_site` filter that `validate_identity()`
    genuinely goes through, with the Hall's options table readable, and repeats
    it for a verified-absent, valid, invalid AND unreadable witness — every one
    blocks as undetermined. A Hall whose identity resolves is legacy again
    immediately afterwards, so the change refuses only what it should.
  - Decision table, all covered by assertions: operational row -> allow;
    provisioning or suspended row -> block; invalid stored row -> block and
    **never** legacy, even with a verified-absent witness; no row with a witness
    present (valid or invalid) -> block; unavailable state storage with a
    witness present -> block; no usable state result with an unreadable witness
    -> block; verified-absent witness with no row OR unavailable storage ->
    legacy access preserved. **Schema-version absence alone never means allow.**
    The witness is read only when the state result cannot decide alone, proven
    by making the witness unreadable and showing a valid row still decides.
  - **Nothing is cached.** A cached allow could outlive the suspension meant to
    end it, so every call re-reads; a suspension recorded between two
    evaluations takes effect on the next one. Evaluation is at most two indexed
    lookups plus the witness, does no SetupReadiness check, no WooCommerce
    inspection, no schema verification loop, no migration, no network-wide scan
    and no outbound call, and writes nothing anywhere (proven by a before/after
    fingerprint across repeated evaluation).
  - New `HallCommerceGate` refuses every request to a blocked Hall at
    `plugins_loaded`, priority `PHP_INT_MIN`, registered from `Plugin::boot()`
    at plugin include time rather than from inside another hook. **The boundary
    was chosen against the installed source, not assumed:** wp-settings.php
    (WP 7.0.3) resolves the site in ms-settings.php, loads network then ordinary
    plugins, loads pluggable.php, and only then fires `plugins_loaded`.
    `muplugins_loaded` is earlier but unusable — no pluggable functions, so no
    current user and therefore no Super Admin recovery, and the cookie constants
    are not defined yet. `plugins_loaded` still precedes every WooCommerce entry
    point that can mutate: `WC_Form_Handler` at `wp_loaded` 20 / `wp` 20 /
    `template_redirect`, `WC_AJAX::do_wc_ajax` at `template_redirect` 0, and the
    `wc-api` dispatch at `parse_request` 0. WordPress has not yet rebuilt and
    normalised `$_REQUEST` at this point — `wp_magic_quotes()` runs after this
    hook and is what assigns `array_merge( $_GET, $_POST )` to it and slashes
    the superglobals — so the gate reads `$_GET`/`$_POST` directly, does not
    unslash them, and mirrors WordPress's later POST-over-GET precedence.
  - Refusal is **HTTP 503** with `Cache-Control: no-store, no-cache,
    must-revalidate, max-age=0`, `Retry-After: 900`, `X-Robots-Tag: noindex`,
    and the fixed sentence *This store is temporarily unavailable. Please
    contact the Hall administrator.* It is a request-boundary refusal, not a
    hidden menu and not a maintenance template. Storefront, product, cart,
    checkout, order-pay, order-received, account, payment-method, Store API,
    WordPress REST, wp-admin, admin-ajax, admin-post, wp-signup, xmlrpc and feed
    requests are all refused, as are direct purchase and form paths
    (`add-to-cart`, `update_cart`, `apply_coupon`,
    `woocommerce_checkout_place_order`, `woocommerce_pay`, `cancel_order`,
    `wc-ajax`, customer login/registration/account POSTs). Existing
    authenticated Hall Manager and Game Operator sessions do **not** bypass it:
    the gate consults no capability at all, which is asserted with real users.
    Nothing is deleted or changed on any path.
  - **Preserved:** Network Super Admin recovery access, and the authentication
    path that reaches it — `wp-login.php` for its login and logout actions only.
    `lostpassword`, `retrievepassword`, `resetpass`, `rp`, `register`,
    `postpass` and `confirmaction` stay refused as customer account flows, with
    POST-over-GET action precedence matching wp-login.php's own `$_REQUEST`
    read. The current user is resolved **only** on the branch that needs it,
    never on an exempt or authentication path.
  - **Necessary exceptions, verified in the installed source rather than
    invented, and bound to what will ACTUALLY happen rather than to what a
    parameter claims.**
    - WP-Cron (`DOING_CRON`, defined by wp-cron.php before wp-load).
    - The Action Scheduler async queue runner. The action name alone is NOT
      sufficient and is no longer accepted: the gate verifies the same nonce
      the runner's own handler checks — `check_ajax_referer(
      'as_async_request_queue_runner', 'nonce' )` in the installed
      `WP_Async_Request::maybe_handle()` — locating it through the same
      `nonce` / `_ajax_nonce` / `_wpnonce` chain, against the same action, in
      the same user context. That context matches because the installed
      `get_post_args()` forwards `$_COOKIE` with the dispatch. A missing,
      empty, forged, truncated, wrong-action or other-session nonce now
      receives the maintenance refusal, so no queued action runs at all.
    - Exactly ONE payment-notification callback, `wc_gateway_paypal`, because
      `WC_Gateway_Paypal_IPN_Handler` is the only installed handler and
      `WC_Gateway_Paypal` registers it whenever the gateway is valid for use —
      INDEPENDENTLY of `enabled`, which is what keeps pending notifications
      working after the gateway is switched off for new checkout. **The
      exemption is now bound to dispatch.** The `wc-api` dispatch hangs off
      `parse_request`, which runs only inside `WP::main()`, which only the front
      controller calls — so an approved identifier on `/wp-admin/`,
      `admin-post.php`, an unrelated `admin-ajax.php` action, or a
      `wp-login.php` registration or password-recovery action dispatches
      nothing and now buys nothing. `is_admin()` settles `/wp-admin/`, which
      `$pagenow` alone cannot because wp-settings maps it to `index.php` too.
      Competing routing instructions are refused rather than resolved: a
      `rest_route` parameter, a REST path (including via `/index.php/`),
      AJAX/cron/XML-RPC, and a query identifier that disagrees with the path
      identifier. The path form requires a permalink structure, because the
      `wc-api` rewrite endpoint does not exist without one. Both forms are
      normalised exactly as WooCommerce normalises them — `sanitize_key()` for
      the query form, and the WHOLE remainder after `wc-api/` through
      `strtolower( sanitize_text_field() )` for the path form, so
      `/wc-api/wc_gateway_paypal/extra/` correctly names a hook nothing listens
      on. A POSTed `wc-api` routes nothing and is ignored.
    Neither check replaces a handler's own authentication; both run before it.
    The installed IPN handler still posts the notification back to PayPal
    itself. Unknown identifiers get nothing, and nothing is exempt when
    WooCommerce is not installed to dispatch it. No gateway framework and no
    queue-filtering subsystem was created.
  - **Mixed requests are refused before any mutation runs.** `wp_loaded`
    precedes `parse_request`, so a callback, cron or runner request carrying a
    shopping or account parameter would have mutated the store on the way to its
    own handler. Every exempt path is screened against a mutation-trigger list
    taken verbatim from installed WooCommerce, and each trigger is asserted to
    exist in that source. As a second line of defence an exempt request also
    detaches WooCommerce's own handlers; the detach table is compared
    element-by-element against `WC_Form_Handler::init()` in the installed file,
    so it cannot silently drift.
  - Manual synchronization: `PortalSyncService::synchronizeHall()` applies the
    same shared policy **before lock acquisition and before any HTTP**. Proven
    rather than asserted: with the Hall's advisory lock pre-held from a second
    MySQL connection, a blocked Hall returns `hall_commerce_blocked` in under a
    second while an allowed Hall waits out the 5-second lock timeout and reports
    `portal_sync_lock_timeout`. Super Admin does not lift it. Legacy Halls
    behave exactly as before. `PortalSyncAdmin` gains one mapping,
    `hall_commerce_blocked -> commerce_blocked`, with its own fixed notice.
    `HallMappingRepository` and mapping status are untouched, and no mapping row
    is created, changed or removed.
  - **Enforcement is exercised as REAL REQUESTS, not only as decision inputs.**
    The gate skips the CLI SAPI by design, so
    `tests/hall-commerce-access-request.php` runs each request in its own
    process: the caller describes it entirely through the environment
    (REQUEST_URI, PHP_SELF, QUERY_STRING, method, POST body), the SAPI populates
    `$_GET`/`$_POST`/`$_SERVER`, vars.php derives `$pagenow` from PHP_SELF for
    real, and the fixture loads the Portal Integration plugin as a
    NETWORK-ACTIVE plugin so `enforce()` runs on the genuine `plugins_loaded`
    hook. Under **php-cgi** the SAPI is `cgi-fcgi`, so the CLI exit does not
    apply and REAL response headers are observable: the suite asserts
    **HTTP 503**, `Cache-Control: no-store`, `Retry-After: 900`,
    `X-Robots-Tag: noindex` and `Content-Type: text/html` off the wire, plus the
    exact maintenance sentence as the body's only visible text. Canaries seeded
    into `$wp_filter` before WordPress builds its hook objects record whether
    `init`, `wp_loaded`, `wp` or `template_redirect` ever fired; a refused
    request reaches NONE of them, which is what makes "no cart or order
    mutation" structural — every WC_Form_Handler and WC_AJAX entry point hangs
    off those four hooks. **Reaching `init` is not on its own treated as a
    healthy worker:** every child is additionally checked for a bounded
    completion, an exit status of 0, empty stderr, and the absence of any PHP
    fatal, warning, notice, deprecation or WordPress database error, and a child
    that overruns its deadline is terminated, escalated and reaped rather than
    blocking the parent on `stream_get_contents()` or `proc_close()`.
    **Outbound HTTP is counted INSIDE each child**, before plugins load, and
    returned in its report — the parent's own counter can only ever observe the
    parent, and is asserted as exactly that and never offered as evidence about
    a child. **Termination is bounded AND confirmed:** after SIGTERM and, if
    needed, SIGKILL, the parent polls for a bounded period, drains the remaining
    output, and calls `proc_close()` only once exit is confirmed — because
    `proc_close()` is itself the call that blocks on a child that has not
    exited. A child that still cannot be confirmed is recorded as a controlled
    failure, named by pid, and deliberately NOT reaped. A fixture-only worker
    mode that IGNORES SIGTERM and stalls proves the escalation path end to end:
    observed timing out against a 2-second deadline, escalating, confirming
    exit, leaving no unreaped child, and finishing in **7.0 s**. Proven this way: the four item-1 bypass URLs, a
    checkout POST, an add-to-cart link, the Store API, the runner identifier
    with a forged and with an absent nonce, and a payment callback carrying a
    shopping parameter are all refused; a genuine PayPal IPN callback, the
    runner with a genuine nonce, WP-Cron, the login form, the CONTROL Hall's
    storefront and checkout, and the main network site all continue;
    authenticated Hall Manager and Game Operator sessions are refused while a
    Network Super Admin session keeps recovery access. WooCommerce is
    represented by defining `WC_PLUGIN_FILE` — literally the fact the gate
    consults — rather than by booting WooCommerce into the fixture, which would
    require its installer and full table set.
  - **Installed-handler execution, proved separately from admission.** Admission
    says a request was not refused; it does not say the handler it was admitted
    FOR ran. New `tests/hall-commerce-handlers.php` (**361/361 PASS**, run twice,
    exit 0) boots the
    REAL installed WooCommerce into the isolated fixture for one Hall and drives
    both exceptions through their own entry points.
    - **PayPal.** Four pending orders are created through WooCommerce's own API
      BEFORE the Hall is suspended — a payment already under way when the store
      closed. With the Hall suspended and PayPal Standard `enabled = 'no'` (off
      for new purchases), a verified IPN travels WP::main() -> `parse_request`
      -> LegacyRestApiStub -> `WC()->payment_gateways()` ->
      `woocommerce_api_wc_gateway_paypal` -> the installed
      `WC_Gateway_Paypal_IPN_Handler`, which posts back to PayPal to validate
      and then completes the order: status `pending` -> `completed` with the
      notification's transaction id recorded. Answer `INVALID` to that post-back
      instead and the SAME request still reaches the SAME handler, but the
      handler's own validation refuses it and the order stays `pending` with no
      transaction id — so the gate is shown to admit on routing grounds only and
      the gateway's authentication is shown to still be what protects the order.
      An unapproved identifier (`WC_Gateway_Stripe`) is refused at the gate with
      503, reaching no handler and attempting no HTTP at all; the checkout,
      add-to-cart and Store API remain refused, and the untouched orders are
      unchanged.
    - **Action Scheduler, with REAL QUEUED WORK.** Reaching
      `action_scheduler_run_queue` on an empty queue proves dispatch, not
      completion, so two fixture-only actions are enqueued through the installed
      `as_schedule_single_action()` BEFORE suspension: one already due
      (`bc_fixture_canary_action`) and one a week out (`bc_fixture_control_action`).
      The due action's callback writes a single dedicated option in the Hall's
      own options table, APPENDING a record per invocation, so "exactly once" is
      a fact about stored data rather than an inference.
      Every rejected-nonce case runs FIRST, while the action is still pending and
      due — no nonce, an empty nonce, a forged nonce, a nonce for another action,
      a nonce minted in another user's session, a valid nonce carrying
      `add-to-cart`, and two unrelated AJAX actions carrying a genuine runner
      nonce. Each returns 503, reaches no handler, and leaves both actions
      pending with the canary unwritten. Only then does the authenticated runner
      run: admin-ajax's own dispatch reaches `WP_Async_Request::maybe_handle()`,
      its own `check_ajax_referer()` passes, `action_scheduler_run_queue` fires
      with the `Async Request` context, and **the queued callback itself
      executes**. Observed afterwards: the canary holds exactly ONE run carrying
      this suite's token, the action's stored status is `complete`, the control
      action is still `pending` and its callback was never invoked, and a second
      authenticated run does not re-execute the completed action. No
      queue-filtering subsystem was built and the whole-queue exemption is
      unchanged.
    - **Route parity, against the installed dispatcher.** Each callback form is
      driven through WooCommerce's real dispatcher and TWO facts are recorded —
      whether the gate admitted it, and whether
      `woocommerce_api_wc_gateway_paypal` actually fired — then asserted as a
      biconditional. Query form, permalink form and unslashed permalink form:
      admitted AND reached. Extra path segment, doubled trailing slash, tripled
      trailing slash, and both conflicting-identifier orders: refused AND not
      reached. That test found a real defect: the path matcher used `rtrim()`,
      collapsing EVERY trailing slash, so `/wc-api/WC_Gateway_Paypal//`
      normalised to the approved identifier and was exempted — while the
      rewrite rule `wc-api(/(.*))?/?$` absorbs exactly ONE slash, hands
      WordPress `WC_Gateway_Paypal/`, and dispatches a hook nothing listens on.
      The matcher now removes exactly one trailing slash, and the parity
      assertion records both forms as `neither`.
    - **The callback contract is POST-bearing, on installed-source evidence.**
      `WC_Gateway_Paypal_IPN_Handler::check_response()` opens with
      `if ( ! empty( $_POST ) && $this->validate_ipn() )` and otherwise falls
      through to `wp_die( 'PayPal IPN Request Failure', ..., 500 )`. The gate
      mirrors that precondition — a non-empty POST body — rather than inventing
      a method check, since a GET cannot populate `$_POST` and an empty POST
      fails the handler identically. A bodyless callback is refused with 503,
      reaches no handler and attempts no post-back.
    - Nothing leaves the machine: the fixture rejects every outbound request
      before any plugin loads, and the worker answers exactly ONE url — matched
      on EXACT scheme, host and path (`https://www.paypal.com/cgi-bin/webscr`),
      never a substring, so a lookalike such as
      `https://paypal.com.attacker.example/cgi-bin/webscr` stays blocked. Every
      attempt is counted inside the child process. The only other attempt
      observed anywhere is Action Scheduler's own follow-up dispatch to the
      site's own admin-ajax; the host test compares against an EXACT allowlist
      of the fixture's two hosts and is itself proved to reject lookalikes such
      as `hall-a.fixture.bingocommerce.test.attacker.example`, a userinfo form,
      and a path-embedded form.
    - **Every seeded order's final state is asserted collectively**, not only by
      the test that touched it: of five pending orders seeded before suspension,
      exactly ONE completed — the one the VERIFIED notification named, carrying
      its transaction id — while the INVALID-notification order, the
      rejected-callback order, the route-parity order and the untouched control
      order all remain `pending` with no transaction id, and no refused request
      created an additional order.
    - WooCommerce's installer legitimately emits database errors on its FIRST
      boot for a site, because some of its own `init` callbacks query tables the
      installer has not finished creating. That boot is run on its own and
      exempted from the clean-run standard; the very NEXT boot is held to it and
      is clean, which is what proves the install completed rather than merely
      being tolerated.
  - Verification: `tests/hall-commerce-access.php` — **833/833 PASS**, run twice
    consecutively, exit 0. It boots the isolated fixture, creates the two Hall
    options tables and three real users (anonymous / Hall Manager / Game
    Operator / Super Admin), and drops only what it created. Fixture users are
    deleted through the SAME guarded mechanism as every other destructive
    operation, which re-proves the connected database, the fixture sentinel and
    ownership AT DELETION TIME — this run recorded the id, the stored login
    still matches that record, and it still carries the `fixture_` prefix — and
    inspects both delete results. Preservation comparisons carry actual VALUES:
    option names AND values AND autoload flags, user metadata keys AND values,
    and every user field claimed unchanged (`user_pass`, `user_email`,
    `user_nicename`, `user_url`, `user_registered`, `user_activation_key`,
    `user_status`, `display_name`). **A failed read fails the assertion**
    instead of comparing equal as an empty result: every capture carries its own
    success flag, and both sides must have read successfully before contents are
    compared at all. Table discovery is likewise THREE-valued — present, absent,
    or unreadable — because a failed `SHOW TABLES` returns nothing and would
    otherwise compare equal to a table that genuinely does not exist. `cron` and the default `widget_*` options are excluded by
    name from the hall-options capture, because a measured experiment showed
    WordPress itself writes them on a site's first boot. The fixture database
    was verified empty of every suite-owned table, version option and user
    afterwards. The handler suite additionally captures a FIXTURE-GLOBAL
    baseline before it runs — `active_sitewide_plugins`, `user_count`, the
    remaining network sitemeta, every user field, all usermeta, and the main
    site's options with values — and restores, by name, only what it changed.
    Two rows needed it: the fixture rewrites `active_sitewide_plugins` on every
    child boot, so the last WooCommerce-enabled child left WooCommerce in it;
    and WordPress recomputes `user_count` on its own throttled schedule after
    this suite creates and deletes its fixture user. Both are written back to
    the captured bytes and then asserted byte-identical, alongside sitemeta,
    users, usermeta and main options. `tests/hall-commerce-state.php` still
    reports **252/252 PASS**, confirming the shared-reader correction did not
    regress its committed behaviour. A read-only probe of the development database confirms every
    development Hall is a legacy Hall (no state table, no
    `bc_portal_provisioning_db_version`, no `bc_commerce_managed` on sites 1-4),
    so the registered gate cannot block one. No migration was run against
    development and no development Hall was enrolled or suspended.
  - **Historical environment-blocked Portal Sync Admin stop — RESOLVED by
    fixture isolation (`7f3e332`).** On 2026-09-11 the suite stopped at
    `missing Portal configuration maps to the not_configured category` after
    **107** assertions because operator Portal constants in git-ignored
    `wp-config.php` overrode the unconfigured worker path. Commit `7f3e332`
    isolates configuration fixtures: `wp-config.php` still executes and
    evaluates its definitions, but fixture constants remain authoritative; no
    operator credential becomes the effective runtime test credential or
    reaches HTTP. Current measured result: **467 passing assertions**. The
    historical 107 stop and the earlier 411 landing figure are retained as
    history only.
  - **What is proved, at three different strengths.** GATE REFUSAL is proved for
    a blocked Hall as a real request with real 503 headers. ROUTE ADMISSION is
    proved for the approved callback and runner paths, and is now asserted as a
    BICONDITIONAL against the installed dispatcher, so an admitted form that
    would not actually dispatch fails the test. INSTALLED HANDLER EXECUTION is
    proved only for the cases in `tests/hall-commerce-handlers.php` — the PayPal
    IPN completing an already-started payment, and the Action Scheduler runner
    executing one fixture-only queued action exactly once — and nowhere else. No
    claim is made that any other handler completed.
  - **Limitations, recorded not resolved.** Practical browser testing has NOT
    been performed and production readiness is NOT claimed; proxy and CDN
    behaviour in front of the 503 is unverified. The php-cgi evidence is a real
    non-CLI SAPI driven from the command line, which is not the same as a
    browser through a web server. The PayPal evidence uses a PREEMPTED
    verification post-back with a canned `VERIFIED`/`INVALID` body: the
    handler's validation logic is exercised, PayPal itself is not, and no real
    gateway account, live notification or production endpoint is involved.
    The Action Scheduler evidence now runs ONE fixture-only queued action of
    this suite's own making, so "a queued callback completed exactly once while
    the Hall was suspended" is proved for that action; the behaviour of real
    production work — WooCommerce's own scheduled actions, a backlog, or
    concurrent runners — is not, and the whole-queue exemption still means a
    blocked Hall's own queued actions run.
  - The isolated fixture now also accepts the `cgi-fcgi` SAPI, but only with an
    explicit environment variable AND the absence of `SERVER_SOFTWARE` and
    `REMOTE_ADDR` — neither of which an HTTP client can arrange — so it remains
    unreachable from the web.
  - On a Hall whose state table does not exist yet, each access evaluation now
    issues one SELECT that fails and one `SHOW TABLES LIKE` to classify it.
    Errors are suppressed and nothing is printed or logged by WordPress, and the
    cost matches what the version check used to be; once the schema is deployed
    it is a single indexed SELECT. A request already admitted
    before a suspension is recorded can finish — the gate evaluates once per
    request, at its start. The WP-Cron and Action Scheduler exceptions admit the
    WHOLE queue, so actions belonging to a blocked Hall still run; filtering
    individual actions would need the queue-filtering subsystem this increment
    deliberately does not build. Network Super Admin recovery is full
    pass-through, including the storefront. The request gate is HTTP-only: CLI
    and WP-CLI are skipped, which is also what lets every harness in `tests/`
    boot the plugin in-process. No marker is ever created, deleted or backfilled
    here, no state is mutated, and no scheduled or automatic synchronization was
    introduced.
- **[2026-09-12] ADR-042 / BR-076 — automatic Portal-controlled BingoCommerce
  enablement and suspension: DOCUMENTATION DECISION COMPLETE; implementation
  NOT started.** Documentation-only increment. Adds ADR-042 and BR-076 and
  reconciles AGENTS.md, PROJECT_STATUS.md, and the Portal/plugin/technical/
  Catalog architecture docs. Supersedes only BR-074/ADR-035’s requirement that
  webhooks and reconciliation precede tenant provisioning. Approves the machine
  REST contract, Application Password + `bc_receive_portal_provisioning`
  auth, operations table (ProvisioningMigrations 1 -> 2), empty-store MVP
  (narrowing ADR-027/BR-047), suspend/re-enable rules, plugin ownership split,
  and delivery order with automatic provisioning before webhooks/SSO. Contract
  follow-up closed implementation blockers and final passes resolved remaining
  contradictions: durable vs request slug/name columns; exact-retry before
  post-ownership null rules; unbound `site_slug` replaceable until durable
  ownership (immutable after); permanent internal `site_ownership_id` with
  `bc_commerce_managed = site_ownership_id` (never rotating Portal
  `operation_id`; absent from fingerprint/response/audit); eight-record
  injective length-prefixed fingerprint; classification with current-UUID
  conflict before lower-sequence superseded; Hall-scoped provisioning mutex
  and stale-command fencing; WordPress REST boundary with custom schema only
  after BingoCommerce callbacks; stored-row validation before classification;
  pre-bind no-site cancellation and marker-matched partial-site suspend with
  mapping absent/exact/`mapping_invalid` branches; recoverable marker-matched
  partial create; fully fixed custom response rules (`api_version` exact
  `"1"`); independent UNIQUE handling including three `site_ownership_id`
  allocation attempts; `bc_portal_provisioning_db_version` target 2 with
  verified migration and pre-mutex schema-readiness gate; non-overlapping
  `conflict`/`slug_conflict`; `last_error_category` clearing; MVP UUID
  semantics without historical uniqueness claims; Hall name aligned to Portal
  snapshot 255 Unicode characters; `archived=1` create plus
  clear-with-read-back before operational success; platform filter only for
  operational create/init/resume; billing-neutral `effective_access` until a
  BR-016 resolver exists; `BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID` as the sole
  Hall site-admin contract; full HTTP/audit matrices with exact audit action
  mapping; App Password request-scoped proof. Does **not** implement PHP,
  migrations, endpoints, Portal buttons, automatic site creation, BR-016
  billing, or SSO. Commerce-state persistence, SetupReadiness, and access
  enforcement remain the implemented foundations.
- **[2026-09-13] ADR-042 provisioning persistence foundation — IMPLEMENTED
  (persistence primitives only).** `ProvisioningMigrations` advances
  `bc_portal_provisioning_db_version` from 1 to 2 and creates the network
  `{base_prefix}bingo_portal_provisioning_operations` table (InnoDB,
  `$wpdb->get_charset_collate()`, `ascii_bin` identity columns, PRIMARY KEY on
  `portal_hall_id` plus UNIQUE on `operation_id`, `site_ownership_id`,
  `blog_id` and `site_slug`). A genuine v1 -> v2 upgrade issues no statement at
  the commerce-state table, because the state DDL runs only when the state
  schema does not already verify; measured in the harness against a populated
  v1 table whose rows and checksum are unchanged afterwards. `is_ready()` is
  the runtime gate: it performs no DDL and no repair, requires structural
  verification rather than the recorded version, and caches only a verified
  positive result for the life of one request. A failed or unreadable version,
  DDL or verification never advances the version.
  - `ProvisioningCanonicalRequest` validates the closed seven-field command in
    its original representation before any cast, shift, hash, SQL or
    persistence, and produces the eight newline-terminated typed records with
    exact UTF-8 byte-length prefixes and lowercase SHA-256. JSON null (`n:name`)
    cannot collide with any string (`s:name:<len>:<bytes>`), including the
    literal characters `null`.
  - `ProvisioningOperationRepository` is the one-row-per-Hall ledger. It keeps
    verified absence, verified-valid row, unreadable read and corrupt row
    apart, revalidates and re-fingerprints every stored row before
    classification, and implements precedence (a)-(e) with current-UUID
    conflict ahead of lower-sequence superseded. `require_canonical()` enforces
    the ADR lifecycle shape (suspend => both request fields null; operational
    => both null or both non-null); `insert_first_command()` additionally
    refuses an operational first command unless both fields are non-null;
    `validate_stored_row()` refuses the corresponding impossible stored rows
    and never casts integer textual fields into strings. Concurrency is
    resolved in the database: the first command is a plain INSERT decided by
    the PRIMARY KEY, every later mutation is a single conditional UPDATE
    carrying the Hall, `operation_id` and `intent_sequence` fence, and there
    is no SELECT-then-INSERT. After every attempted write in
    `insert_first_command()`, `replace_command()`, `replace_unbound_slug()`,
    `write_error_category()`, `confirm_enforced_sequence()`,
    `assign_site_ownership_id()` and `attach_blog_id()`, an authoritative
    read-back — never an affected-row count — decides the result.
    `replace_command()` itself re-validates and re-classifies before writing
    and mutates only for a different UUID with a strictly higher sequence.
    `replace_unbound_slug()` and a null `assign_site_ownership_id()` operate
    only on the current operational command. A null `site_ownership_id` is
    allocated only while that command is operational, both request fields are
    non-null, and the durable `site_slug` exactly equals `request_site_slug`.
    After `execute()` in `replace_unbound_slug()`, `assign_site_ownership_id()`,
    `attach_blog_id()`, `write_error_category()` and
    `confirm_enforced_sequence()`, a failed, unreadable, absent or superseded
    read-back is `unverified`, never `lock_unavailable`. A fence miss before
    those methods write remains `lock_unavailable`. `site_ownership_id` allocation
    spends one of its three UUIDv4 attempts only when a COUNT-only probe
    positively proves a foreign holder of that exact candidate. A preflight
    occupied address is `address_occupied`; an atomic `UNIQUE(site_slug)`
    refusal is `conflict`. Errors are a fixed closed set and disclose no SQL,
    table name, driver text, stored payload, candidate UUID or foreign Hall
    identity.
  - `ProvisioningMutex` is one advisory lock per `portal_hall_id` — never
    global — on a name space distinct from `PortalSyncLock`'s, refuses
    acquisition while a sync lock is already held (ADR-042 lock order),
    contains every Throwable from `acquire()` and `isStillHeld()`, locally
    suppresses wpdb SQL/driver diagnostics around every lock probe and restores
    the previous display flags afterwards, and clears the request-local
    reservation only when the server proves the lock was not acquired,
    `RELEASE_LOCK` proves release, or a valid changed connection identity
    proves the acquiring session is gone.
  - Measured: the focused harness `tests/portal-provisioning-foundation.php`
    passes **551/551 assertions** on two consecutive runs with identical
    totals; `tests/hall-commerce-state.php` passes **254/254**. Regression:
    Hall Commerce access **833/833**, installed handler integration
    **361/361**, Phase 3E snapshot application **387/387**, Phase 3G Portal
    sync admin **467 passing assertions**, SetupReadiness **199/199**.
    Same-Hall/different-Hall mutex contention and two-process first-writer
    races through `insert_first_command()` are proven by worker processes with
    pipe/barrier coordination, not by sleep timing.
  - The persistent development database was proven unchanged before and after:
    no provisioning version option, no provisioning tables, sites 1-4, Hall
    mapping 2 -> 2, and all Catalog/Session table checksums byte-identical, and
    `wp-config.php` unchanged by SHA-256.
  - Still **NOT implemented**: the REST route and Application Password
    controller, the platform provisioning filter, WordPress site creation or
    initialization, Hall mapping mutation, Portal snapshot synchronization from
    a provisioning command, HallCommerceState writes on this path, the Portal
    Enable/Disable Ecommerce UI, SSO, webhooks, scheduling and reconciliation.
    Nothing loads on a request trigger, so no request behaviour changed and
    automatic provisioning still cannot occur.
- **[2026-09-13] ADR-042 / BR-076 — BingoCommerce-side machine provisioning
  path — IMPLEMENTED.** The authenticated REST endpoint, the ADR-042
  orchestration state machine, the `bingocommerce-platform` plugin and its
  single provisioning filter, Hall mapping coordination, the trusted
  provisioning Portal full pull, SetupReadiness gating, HallCommerceState
  writes, `archived` clearing with read-back, `enforced_sequence` confirmation,
  the exact response matrix and the terminal audit all exist in code and are
  exercised end to end through real `WP_REST_Server` dispatch.
  - **New plugin `bingocommerce-platform`** (network-only). It owns actual
    WordPress Multisite site creation and platform-owned site/WooCommerce
    initialization and nothing else: `HallSiteAddress` (read-only network
    topology resolution), `HallSiteAdminUser`
    (`BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID` validation),
    `HallSiteCommand` (defensive command validation) and `HallSiteProvisioner`
    (the one `bingocommerce_platform_provision_hall_site` consumer). A new site
    is created `archived = 1` FROM INSERTION, and `bc_commerce_managed` is set to
    the permanent `site_ownership_id` DURING INITIALIZATION — the site row is
    inserted first and the marker is supplied as an initial site option, so it is
    not present from the row's first moment and a crash in between leaves an
    archived, unmarked, ownership-unproven orphan for operator recovery. `public`
    is left at the `wpmu_create_blog()` default, and the configured recovery
    Super Admin — not the machine account — is its administrator. No starter WooCommerce Product
    and no starter Bingo Play Product is copied.
  - **REST**: exactly one handler on
    `PUT /wp-json/bingocommerce/v1/portal-halls/{portal_hall_id}/commerce`,
    PUT only, with no registered REST `args`. `permission_callback` requires all
    three of a request-scoped Application Password UUID, a recorded `WP_User`
    status equal to `get_current_user_id()`, and
    `bc_receive_portal_provisioning` — a capability this plugin grants to no
    role. Malformed JSON, unmatched routes, wrong methods and core
    registered-argument failures keep WordPress core's own bodies; the custom
    reduced 401/403 conversion is scoped to this route, this handler callback
    and this controller's own private marker.
  - **Source-review correction pass [2026-09-14].** Eleven defects found by
    review were corrected before this increment was accepted:
    - `portal_hall_id` now comes from `WP_REST_Request::get_url_params()`.
      `get_param()` resolves JSON, then POST, then GET and only then URL, so
      `PUT /portal-halls/5/commerce?portal_hall_id=9` would have locked,
      classified and mutated Hall 9.
    - A Network Super Admin with valid Application Password proof is now
      refused with HTTP 403 BEFORE classification. `WP_User::has_cap()` answers
      true for every capability when `is_super_admin()` is true, so the
      capability check never kept one out; every comment claiming a Super Admin
      "does not carry" the capability was corrected.
    - The `rest_request_after_callbacks` conversion now also requires the exact
      ADR-042 route pattern, so a route registered against these public
      callbacks keeps WordPress core's error shape.
    - The reserved-address probe is a prepared read against `$wpdb->blogs`
      keeping verified absence, one valid site, corrupt and unreadable apart.
      `get_sites()`, `WP_Site_Query` and `domain_exists()` are all filterable
      and cached, and an ownership identity is permanent.
    - Mapping inspection returns exact / absent / conflict / unreadable rather
      than overloading null; a mapping is created only from verified absence.
    - `already_applied` re-proves every enforced fact including SetupReadiness.
    - Currentness-failure normalisation is centralised; the mutex and fence are
      re-proven after the platform filter BEFORE its result is interpreted; and
      `lock_unavailable` is ruled out at the single response exit point once a
      mutation may have occurred.
    - The platform consumer validates network, address, main-site, deleted,
      spam, marker and lifecycle before initializing; an unarchived unbound
      marker-matched partial site is `site_ownership_unproven`; and a create
      command with a null Hall name is `internal_error`.
    - A JSON Content-Type is required through `WP_REST_Request::is_json_content_type()`.
    - Provisioning synchronization opens its `try` around `switch_to_blog()` and
      unwinds by switched-stack depth.
  - **Second source-review correction pass [2026-09-14].** Nine further defects
    were corrected:
    - The Hall mutex and command fence are now proven immediately before EVERY
      terminal snapshot that speaks for a current operation row — success as
      well as failure — through one `prove_current()` used by both the workflow
      guards and the snapshot methods. Proving before the last MUTATION left a
      window in which a newer command could arrive and still be answered
      `applied` or `already_applied`. Superseded / conflict classification and
      an unreadable pre-classification row are deliberately NOT fenced: they
      have no matching current row.
    - `record_error_category()` is treated as the row mutation it is: guarded
      before, marked as a possible mutation, and re-proven after, so a stale
      category can never be stamped onto a row that has moved on.
    - A pre-bind marker-matched suspend candidate must now prove unique address,
      expected network, not-main-site, not-deleted, not-spam and `archived = 1`
      — in addition to the exact marker — before `attach_blog_id()`.
    - `HallSiteProvisioner` now performs `switch_to_blog()` inside its protected
      boundary, records entry depth, blog id and `$wpdb->prefix`, contains
      Throwables from switching, work and restoration, and NEVER reports success
      when restoration cannot be proven.
    - `PortalSyncService::run_provisioning()` no longer returns from inside a
      `try` whose `finally` merely logs: it calculates the outcome, restores,
      proves depth/blog/prefix, and only then returns. Its declared
      result/WP_Error boundary no longer leaks Throwable, and an unproven
      context maps through the orchestrator as `unverified` rather than a
      retryable `sync_failed`.
    - `is_blog_installed()` is gone from the platform consumer. On a site with
      missing tables it ends in `wpdb::bail()`, which TERMINATES the request
      rather than returning false. The branch it guarded was unreachable anyway:
      every path into initialization has already read a VALID
      `bc_commerce_managed` marker out of that site's own options table, so the
      table provably exists. An unmarked orphan is never adopted or initialized.
    - The Portal-facing URL is read from the site's own options table and
      verified against the already-verified site row — absolute http/https, no
      userinfo, query, fragment or control characters, matching host, path and
      configured port. `site_url`, `option_siteurl` and `pre_option_siteurl`
      filters can no longer decide what Portal stores, and the platform filter's
      advisory `site_url` is still never trusted.
    - Bound resume, ordinary bound verification, re-enable and `already_applied`
      now re-prove that the authoritative address resolves UNIQUELY to the same
      `blog_id`; a duplicate, different, corrupt or unreadable result fails
      closed without naming either row.
    - A false-positive assertion (`$response['filter_calls'] > 0 ? 1 : 1`, which
      is always 1 and read the wrong response) and a self-comparison
      (`assert_same( site_count(), site_count() )`) were replaced with exact
      facts; the complete new test code was swept for the same patterns.
  - **Measured**: `tests/portal-provisioning-endpoint.php` passes
    **50352/50352 assertions** and
    `tests/platform-hall-site-provisioning.php` passes **491/491**, each on two
    consecutive runs with identical totals. Regression, all re-run after these
    changes: ADR-042 provisioning foundation **551/551**, Commerce state
    **254/254**, installed handler integration **364/364**, Hall Commerce access
    **834/834**, SetupReadiness **199/199**.
  - `tests/portal-snapshot-application.php` (**387/387**),
    `tests/portal-sync-admin.php` (**468/468**) and
    `tests/portal-integration-foundation.php` (**77/77**) now RUN, against the
    isolated fixture rather than the persistent development database. Each
    creates disposable Multisite sites, so they boot through the new additive
    `tests/fixtures/wordpress-bootstrap.php`: without `BC_TEST_DB` it does
    exactly what those files did before — `require wp-load.php` — and with it
    they boot the guarded fixture instead. They use their own allowlisted
    database (`bingocommerce_state_test_suites`) because they need a
    development-shaped fixture — four legacy halls, each initialized and
    role-provisioned — which would otherwise collide with the bare seeded halls
    the other harnesses create and drop themselves.
  - **Fixture integrity.** `tests/hall-commerce-access.php` and
    `tests/hall-commerce-handlers.php` both applied the v2 provisioning schema
    but dropped only the commerce-state table, leaving
    `{base_prefix}bingo_portal_provisioning_operations` behind and making
    `tests/portal-provisioning-foundation.php` abort when it ran second. Both
    now drop the operations table with the same guarded mechanism and assert it
    is gone. `tests/portal-integration-foundation.php` now also removes the
    Multisite-global `registration_log` rows its disposable halls leave behind,
    which no core deletion path touches. Every suite was re-run in BOTH
    directions; none requires another to run first. Per-run residue is proven
    separately for each fixture database by a structural snapshot taken before
    and after a full run: both are byte-identical, so the suites add nothing
    beyond the baseline the bootstrap establishes. `bingocommerce_state_test` is
    left with exactly its three seeded sites, no users, no Application
    Passwords, no provisioning, mapping or audit tables, no orphaned per-site
    tables and no schema-version options.
  - The REAL Application Password path is exercised with a disposable
    credential: `WP_Application_Passwords::create_new_application_password()`,
    authentication through `wp_authenticate_application_password()`, and core's
    own `application_password_did_authenticate` collection hook, with the
    credential destroyed and its absence asserted afterwards.
  - **Development-database preservation**, measured before and after all
    testing: `bc_portal_provisioning_db_version` still absent, both provisioning
    tables still absent, 239 tables, sites 1-4 with an identical row checksum,
    Hall mapping 2 -> 2 unchanged, all 21 Catalog/Session tables identical by
    count and checksum, no `bc_commerce_managed` option on any existing site, 11
    users and **0 Application Password meta rows**, and `wp-config.php` unchanged
    by SHA-256. **No development migration was run, no development site was
    created, and no Application Password was issued there.** No live Portal HTTP request was made — the fixture blocks
    outbound HTTP and the harness short-circuits only the `.invalid` Portal
    snapshot URL — and no mail was sent.
  - Three narrow ADR-042 corrections were recorded in `docs/decisions/DECISIONS.md`
    and nothing else was changed there: a genuinely pre-mutation, reduced,
    outcome-absent `lock_unavailable` is exempt from the terminal provisioning
    audit because it has no outcome to map to an action (once a mutation may
    have occurred, currentness loss is `unverified` and IS audited); the
    no-DDL rule is about the NETWORK provisioning schema and not about a new
    Hall site's own per-site tables, which approved platform initialization
    necessarily creates; and the managed marker is NOT present from the site
    row's first moment — the row is inserted archived and the marker is supplied
    as an initial option during initialization, so a crash in between leaves an
    ownership-unproven orphan for operator recovery. No new outcome, audit
    action or migration mechanism was introduced.
  - **Four source blockers corrected in a final review pass**, with no change to
    any accepted ADR-042 response, audit, authentication, ownership, sequencing,
    migration or plugin-ownership contract, and no new outcome, audit action,
    error category or migration mechanism.
    - **Blog-context restoration is contained per ATTEMPT**, in both production
      restoration sites — `PortalSyncService::run_provisioning()` and
      `HallSiteProvisioner::initialize()`. `restore_current_blog()` pops the
      switched-blog stack FIRST and fires `switch_blog` LAST, so a listener that
      throws has already consumed one level; a single `try` around the whole
      unwind loop abandoned every remaining level, and with two or more switches
      open the request carried on with `$wpdb` still pointing at the Hall. Each
      attempt is now contained on its own: the depth is recorded before it and
      re-read after it returns or throws, unwinding continues only on valid
      progress toward the recorded entry depth, and it stops safely on no
      progress, on underflow, on a stack that is no longer a list, and on an
      exhausted budget. No WordPress global is ever assigned. Success still
      requires exact agreement on depth, blog id and table prefix, and a
      restoration Throwable still fails the operation even when the unwind
      afterwards lands on the entry facts.
    - **The network site-URL authority is a verified fact, not a default.** The
      helper that decided whether a per-site URL may carry a port returned
      `?int`, which merged a VERIFIED network that uses no port with a network
      row that was absent, duplicated, corrupt, unparseable or unreadable — so
      every storage failure asserted "no port", matched a portless site URL, and
      confirmed it to Portal. It is now a structured fail-closed read of
      `$wpdb->sitemeta` that keeps verified-with-port, verified-without-port and
      unverified apart, and `authoritative_site_url()` returns no URL unless the
      authority is positively verified. `LIMIT 2` is deliberate: the installed
      schema declares no `UNIQUE (site_id, meta_key)` and `get_network_option()`
      takes whichever row the server returns first, so a duplicated row is a
      state the schema permits and this read refuses rather than picks between.
    - **Bound suspend distinguishes an ABSENT resolver from a FAILED one.**
      ADR-042 licenses exactly one thing — `platform_unavailable` must not block
      safe suspension when the platform component is missing, because the
      three-fact marker agreement is the authority and needs nothing from it.
      The previous shape stretched that licence over every WP_Error the resolver
      could produce, so an unreadable `blogs` lookup, a duplicated address or a
      thrown lookup stood in for a passed identity check. Component absence is
      now established POSITIVELY through `PlatformGateway::resolverInstalled()`;
      an installed resolver that fails is `platform_unavailable` on both paths, a
      verified absence is `site_missing`, and a different resolved blog is
      `slug_conflict`. No foreign identity is named on any of them.
    - **An escaped Throwable is finalized like any other failure.** The state
      machine's outer catch answered with a bare terminal snapshot: it never
      proved currentness, so a Throwable arriving after a newer command had
      replaced this one produced a terminal audit against a fence that no longer
      existed, and it never persisted the category, so the one failure mode
      nobody planned for was the only one a later retry could not see standing
      on the row. It now routes through a guarded, non-recursive finalizer that
      proves currentness, persists an allowlisted category when it is safe to,
      treats that persistence as a possible mutation, re-proves, and never
      audits a current result for a superseded fence. Post-mutation uncertainty
      is `unverified`; a pre-mutation loss keeps the reduced `lock_unavailable`
      with no audit; and a Throwable before any row is established touches no
      row at all.
  - **Tests added for each of the four**, and each was verified to FAIL against
    the previous behaviour before being kept: deterministic nested-switch
    restoration cases for both production paths (two extra switches open, a
    `switch_blog` listener throwing on the middle of three restores, with depth,
    blog id and prefix captured immediately after the production call and before
    any harness cleanup); ten network-authority cases covering verified with and
    without a port, a mismatched port in both directions, and absent, duplicated,
    empty, non-http, userinfo-bearing, query-bearing, control-character,
    out-of-range-port, unreadable and thrown storage; four resolver faults across
    BOTH bound suspend paths plus a genuine component-absence case in a worker
    process that never loads `bingocommerce-platform`
    (`tests/portal-platform-absent-worker.php`); and Throwables before a row is
    established, after classification pre-mutation, post-mutation, and with a
    newer command replacing the fence in both mutation states. Three vacuous
    `assert_true( true, ... )` assertions were removed from the two new
    harnesses.
  - **Fixture residue.** Both harnesses that load WooCommerce now remove the
    guest sessions `WC_Session_Handler` writes to the network main site — bounded
    by a high-water mark taken before the first mutating test, with a final sweep
    on `shutdown` at last priority because WooCommerce saves at priority 20,
    after every assertion a harness can make. A full ten-suite matrix now leaves
    both fixture databases structurally byte-identical.
  - Still **NOT implemented**: the Portal Enable/Disable Ecommerce UI and any
    Portal-side work; SSO; webhooks; scheduling; reconciliation; BR-016 billing
    persistence or any billing resolver; and starter Product / Play Product
    copy. The manual cross-repository Portal end-to-end test has **not** been
    performed. **Production deployment is NOT claimed.**
- **[2026-09-19] DRAFT ADR-043 Revision 9 — implemented; documentation
  synchronized.** The additive Session schema v3,
  Portal-template/Commerce-schedule/dated-occurrence separation, explicit and
  weekly generation/reconciliation, named immutable schedule timezone,
  BR-077's inclusive
  `D - 30 calendar days <= hall_local_today <= D` window, user-facing
  `MM/DD/YYYY` boundaries, and BR-078 centralized committed-definition membership
  exist in the current repository implementation. Removing a strictly future
  date is allowed while ON: membership immediately makes it ineligible and a
  successful edit turns it OFF; re-adding a retained occurrence preserves its
  identity and Package assignments and leaves it OFF. Existing withdrawal,
  sale-lock, archival and historical-retention rules are unchanged. The focused
  schedule/admin verification most recently reports **530/530 passing
  assertions** (`session-schedules-increment-1.php` 159/159 plus
  `session-schedule-admin-increment-1.php` 371/371). ADR-043 remains DRAFT and
  unapproved; separate ratification remains; production deployment is not
  claimed.
- **[2026-09-21] ADR-043 Revision 9 ratified and approved.** Documentation is
  synchronized. Implementation commit: `262b250`. Latest focused evidence
  remains **530/530** (`session-schedules-increment-1.php` 159/159 plus
  `session-schedule-admin-increment-1.php` 371/371) and was not rerun during
  this ratification pass. Production deployment is not claimed. The next
  delivery phase is the Portal-side Enable/Disable Ecommerce control and
  practical cross-repository ADR-042 provisioning workflow.
- **[2026-09-21] Portal Enable/Disable Ecommerce control verified in the
  Laravel Portal repository.** Branch `feature/commerce_connection`, HEAD
  `5a5156f`. Global Admin Enable, Suspend, Re-enable and Retry, the ADR-042
  HTTP client, operation UUID/sequence persistence, stale-response
  protection, snapshot-token Hall granting, and outbound HTTP outside Portal
  database transactions are implemented and committed. Hall Owner Ecommerce
  access is read-only. Assigned Hall Managers cannot see the Ecommerce
  section or store URL. Mutation remains Global Admin-only. This
  documentation pass reran Portal automated tests against isolated SQLite:
  focused commerce tests **72 passed, 817 assertions**; full Portal suite
  **738 tests passed, 2,909 assertions**. Those tests used HTTP fakes or a mocked
  client and did not call WordPress. An earlier 2026-09-15 read-only browser
  check showed a Hall Owner Open Ecommerce Store link; an actual Hall
  Manager was not independently browser verified. The last recorded Hall
  9/blog 870 note is Enabled and mapped; this pass did not re-query that
  environment. The practical cross-repository Enable → Open Store →
  Suspend → Re-enable lifecycle against a real WordPress site remains
  incomplete. Production deployment is not claimed.
- **[2026-09-21] Local-development Portal commerce lifecycle verification passed.**
  A user-performed local-development Open Store → Suspend → Re-enable
  workflow passed against one mapped WordPress multisite test site, and
  re-enable reused the same site. Local IDs for traceability: Portal Hall
  ID 8, WordPress blog ID 869. BingoCommerce audit on 2026-09-21 records
  Suspend (`portal.commerce.suspended`, desired_state=suspended,
  outcome=applied, effective_access=portal_blocked at 15:43:58) then
  Re-enable (`portal.commerce.applied`, desired_state=operational,
  outcome=applied, effective_access=allowed at 15:44:05) on that same local
  blog ID. Maintenance/access blocking was user-reported; this pass did
  not independently re-observe a specific maintenance page, user type, or
  access path. No complete before/after byte comparison was made. Only
  the data checked during the test remained available. Final Portal and
  BingoCommerce commerce state: operational. WordPress archived=0,
  deleted=0, spam=0. Automated tests were not rerun during this
  documentation-only pass. Real Retry/idempotency testing is not claimed.
  Production deployment is not claimed.
- **[2026-09-21] Package Add-ons Increment 1 documentation and governance
  approved.** Added BR-079 and ADR-044 after verifying BR-078/ADR-043 were the
  live index endpoints. The approved design fixes medium compatibility,
  Package-specific reuse, canonical exactly-two-decimal `DECIMAL(19,2)` pricing,
  quantity range 1–99, lifecycle, administration, per-row bulk behavior,
  Catalog v6 schema, the fixed add-on mutation profile, versioned injective
  stale fingerprints, composition/medium concurrency, post-commit audit,
  projection convergence and tenant isolation. The final correction passes fix
  exact `wc_get_product()` / edit-context regular-price prefill, visibility of
  all configured rows versus active compatible current-Hall new candidates, one
  Save Add-ons form whose unchecked never-configured rows are ignored, simple
  browser-confirmed permanent Remove, reuse of
  `play_product_package_referenced` for both component and enabled-add-on medium
  blockers, Bingo-Play-Product-ID targeted reconciliation, Hall-wide scanner
  deferral to the later storefront/readiness phase, archived-state no-ops and
  the ADR-044-aware lock order. The last correction distinguishes UI-only price
  padding from strict already-canonical Manager/service input, removes every
  WooCommerce price read from the create-operation boundary, and defines
  same-numbered tenant IDs as independent current-Hall rows rather than automatic
  not-found results. WooCommerce's separate
  four-decimal lookup schema is not the add-on precision authority; the installed
  WordPress `dbDelta()` type/index parser was reviewed. No production PHP,
  migration or test file was changed; no migration or implementation test ran;
  nothing was staged, committed, pushed or deployed. Implementation remains
  pending a separate review-first pass at that documentation checkpoint.
- **[2026-09-22] Package Add-ons Increment 1 — Slice 1 implemented and
  isolated-tested.** Catalog schema v6 adds only the Hall-local
  `bingo_package_addons` table under ADR-044, using signed `DECIMAL(19,2)`, the
  approved quantity/enabled/sort defaults and four exact indexes. The existing
  `bc_catalog_db_version` advances only after all five Catalog tables verify.
  `SHOW FULL COLUMNS`/`SHOW INDEX`, InnoDB, exact WordPress collation and
  foreign-key absence are checked. Modern MySQL's omitted integer display
  widths are accepted; wrong reported widths, storage types, signedness or
  decimal precision are rejected. Unexpected existing add-on shapes fail
  without automatic conversion. Existing network catch-up, new-site hooks,
  current-Hall self-heal, failure reporting and retry boundaries are preserved.
  - Focused `tests/package-addons-schema.php`: **196 passed, 0 failed** on the
    allowlisted, sentinel-owned `bingocommerce_state_recon` fixture. Covers v5
    upgrade, exact shape, empty table, idempotence, new Halls, 23 malformed
    shapes, failed creation/reads, all-five-table verification, network retry,
    post-sweep self-heal, per-site isolation, data preservation and owned cleanup.
  - Affected regression suites passed: Game Types **164**, Play Products
    **472**, Package Stage A **320**, Package Stage B **258**, legacy Sessions
    **352**, Portal Game Type persistence **142**, Session-template persistence
    **104**, Portal foundation **77**, snapshot application **424**, setup
    readiness **199**: **2,708 assertions passed, 0 failed** across the eleven
    final suite runs. All nine changed PHP files passed syntax checks;
    `git diff --check` passed. Missing-database, unallowlisted-database and
    reserved-scratch guard probes all refused execution as intended.
  - Read-only development Catalog before/after snapshots matched exactly
    (table definitions, serialized rows, table absence and schema-version
    options); no development Hall was migrated. The v5 upgrade fixture also
    proved existing Hall data unchanged except the added empty table and
    Catalog version option. All current-run disposable Hall cleanup verified.
  - Initial `_test_suites` run: **189 passed, 2 failed** because its pre-existing
    Hall 2 options table is missing. It was not repaired or cleaned. The healthy
    `_recon` fixture was used instead. Session-template persistence's narrower
    guard rejects `_recon`; that suite passed on its existing approved
    `bingocommerce_state_test` fixture without weakening the guard. Existing
    regression harnesses emit injected-failure/teardown diagnostics; all final
    assertion counts above pass.
  - No `PackageAddonService`, result/plan classes, add-on transaction profile,
    UI, price prefill, lifecycle/medium extension, bulk saving, projection or
    add-on audit is implemented. These remain later slices, not authorized by
    this implementation. No real Hall migration, staging, commit, push,
    deployment or production-readiness claim.
- **[2026-09-22] Package Add-ons Increment 1 — Slice 2 backend implemented and
  isolated-tested.** Added `PackageAddonService`, `PackageAddonResult`, the
  immutable `CatalogPackageAddonPlan`, and ADR-044's fixed callback-free profile
  inside the existing `CatalogTransactionTransport`. The backend implements
  list/create/update/remove, exact no-ops, canonical two-decimal price and native
  integer quantity validation, lifecycle/medium compatibility, versioned stale
  fingerprints, bounded conflict retries, ambiguous-COMMIT handling,
  current-Hall authorization/isolation, post-confirmed-commit audit, WooCommerce
  projection convergence and Bingo-Play-Product-ID targeted reconciliation.
  Play Product medium changes and Package composition replacement now fail
  closed on enabled add-on references, including corrupt stored enabled state.
  The pre-existing transport error builder was made callback-free without
  changing its public codes/data, locking, retries or commit semantics.
  - The initial full-matrix checkpoint, before the later unresolved-configured-row
    listing correction, passed the focused transport/add-on, schema, Package
    Stage A/B, Play Products, Game Types, Sessions, Portal Game Type identity,
    Portal Session-template persistence, Portal foundation, Portal snapshot
    application and setup-readiness suites: **3,513 assertions passed, 0
    failed** across thirteen suite runs. This is preserved as the immediately
    preceding full-matrix checkpoint, not described as the final current-code
    run. At that checkpoint, all nine changed/new PHP files passed syntax
    checks, twelve database guard probes refused
    missing/unallowlisted/reserved targets, and `git diff --check` passed.
  - Final post-correction affected-suite verification passed: Package Add-ons
    backend **582/582**, Package Stage A **320/320**, Package Stage B
    **258/258**, and Play Products **472/472**, for **1,632 passed, 0 failed**.
    PHP syntax passed **9/9**, `git diff --check` passed, persistent Hall
    preservation passed, and disposable-Hall cleanup passed. The remaining
    suites passed during the immediately preceding full-matrix checkpoint and
    were not rerun after this narrow unresolved-listing correction.
  - Persistent fixture Hall snapshots remained byte-identical and disposable
    Hall cleanup/ownership checks passed. Existing disposable-Hall Action
    Scheduler missing-table diagnostics predate Slice 2, affected no assertion
    or persistent data, and remain visible in the saved test logs.
  - At this Slice 2 checkpoint, the Package Admin Optional Add-ons UI, nonce/controller boundary,
    checkbox/bulk orchestration, WooCommerce regular-price prefill and browser
    Remove confirmation remained unimplemented for Slice 3. No real Hall was
    migrated and no staging, commit, push, deployment or production-readiness
    claim is made.
- **[2026-09-22] Package Add-ons Increment 1 — Slice 3 administration implemented
  and isolated-tested.** `PackageAdmin` now presents one Optional Add-ons form on
  the existing Edit Package screen. Configured relationships remain first and
  visible when disabled, archived, incompatible or unresolved. Eligible new
  candidates receive string-only regular-price prefill through
  `wc_get_product()` / `get_regular_price( 'edit' )`. Submitted prices remain
  strict canonical two-decimal strings; validated HTTP maximums become native
  integers. One service operation per row preserves partial success, exact
  no-ops and submitted stale fingerprints. POST, current Hall, capability,
  nonce, Package and relationship membership checks precede mutation.
  - Add-on requests run on the existing screen's load hook before admin output.
    Notices/failed values are request-local, reset on editor load, and rendered
    only for the matching current Hall, actor and Package.
    Committed warnings retain successful status; projection warnings offer only
    a signed current-Hall Play-Product-ID targeted Retry action. Remove and
    Retry ignore bulk Save fields. A small editor-only vanilla script filters
    without changing submissions and uses browser confirmation for Remove;
    without JavaScript, Save works and permanent Remove is unavailable.
  - Initial full-matrix verification (before the save-notice correction below):
    admin/controller **206/206**, add-on backend **582/582**,
    schema **196/196**, transport **241/241**, Package Stage A **320/320**,
    Stage B **258/258**, Play Products **471/471**, Game Types **164/164**,
    Sessions **352/352**, Portal Game Type identity **142/142**, Portal
    Session-template persistence **104/104**, Portal foundation **77/77**,
    Portal snapshot application **424/424**, setup readiness **199/199**:
    **3,736 passed, 0 failed** across fourteen final suite runs. Play Products'
    existing create-versus-trash race returned `woo_product_trashed`; its
    conditional created-row assertion did not apply (an earlier run was 472/472).
  - Tests used `bingocommerce_state_recon`, except the existing restricted Portal
    Session-template suite used its approved `bingocommerce_state_test` fixture.
    Persistent-Hall preservation and ownership-proven disposable cleanup passed.
    All three changed/new PHP files passed syntax checks; JavaScript passed
    `node --check`; all twelve missing/unallowlisted/reserved-database probes
    refused safely; `git diff --check` passed. Logs:
    `/tmp/bc-slice3.020ACE`. Known disposable-Hall Action Scheduler/WooCommerce
    missing-table and teardown diagnostics, injected failures and Staff writer
    guard messages remain visible in the logs; no assertion or preservation
    check failed in the final runs.
  - Slice 1 schema and Slice 2 backend remain unchanged. Slice 3 automated
    verification is complete; later manual browser checks remain required,
    including actual Remove Cancel behavior. Storefront/cart/checkout enforcement
    remains unimplemented. No real Hall migration, staging, commit, push,
    deployment or production-readiness claim.
  - **[2026-09-23] Save-notice correction:** WordPress's native My Sites toolbar
    performs temporary `switch_blog` calls after `load_editor()` and before the
    Package page renders. The unconditional clearing hook erased successful
    results before `render_addons()`; it has been removed. Existing Hall/actor/
    Package matching still prevents another Hall from displaying the response,
    and the next editor load resets it. No persistent notice storage was added.
    A same-request POST → native toolbar callback → full Package render
    regression reproduced six failures before the fix, then passed for disable,
    re-enable and exact no-op. The focused admin suite passed **234/234** on
    `bingocommerce_state_recon`; both changed PHP files passed syntax checks and
    `git diff --check` passed. Exact no-op row/timestamp/audit/projection
    preservation, persistent Halls 2/3/4 and ownership-proven disposable cleanup
    passed. Existing disposable-Hall WooCommerce/Action Scheduler diagnostics
    remain recorded in `/tmp/bc-addon-notices.l3dvW2/after-fix.log`. The other
    suites were not rerun after this narrow notice correction. Real-browser
    message visibility still needs manual confirmation; Star Bingo Hall was
    not mutated during this correction.
  - **[2026-09-23] Save feedback summary:** Save Add-ons now renders one summary
    from its existing per-row results, such as `1 add-on updated; 2 unchanged.`
    or `Add-ons unchanged.` when every processed row is unchanged. Applicable
    created/updated/unchanged counts replace individual success notices only for
    Save. Row `WP_Error` results add a rows-needing-attention count and a review
    instruction to that same summary: mixed outcomes use warning styling,
    all-error outcomes use error styling, and all-unchanged saves use an
    informational notice. Unknown COMMIT is not described as a definite failure
    or automatically retried. Row errors and submitted values, committed warnings and targeted Retry,
    and separate Remove/Retry feedback remain intact. Submission and service
    mutation behavior are unchanged; no JavaScript or persistent notices added.
    Initial summary verification passed **257/257**; final verification after
    the row-attention correction passed **278/278** on `bingocommerce_state_recon`,
    including POST → native toolbar → full page rendering, no-op byte
    preservation, Hall isolation, persistent Halls 2/3/4 and ownership-proven
    disposable cleanup. Post-commit model read failure preserves the confirmed
    summary; detailed errors and submitted stale fingerprints remain visible.
    Both changed PHP files passed syntax checks and
    `git diff --check` passed. Logs, including existing non-failing disposable-
    Hall WooCommerce/Action Scheduler diagnostics:
    `/tmp/bc-addon-attention.IR9qW8/admin-final.log` (initial summary checkpoint:
    `/tmp/bc-addon-summary.GulIp8/admin.log`). Other suites were not rerun for
    this presentation-only change. Browser review remains pending; no real Hall
    was mutated, migrated or deployed, and all work remains unstaged/uncommitted.
  - **[2026-09-23] Package list base-price display:** Only the `Price
    (WooCommerce)` cell now obtains the linked current-Hall product's native
    `get_price_html()` and sanitizes it with `wp_kses_post()` at output. This
    preserves sale `<del>`/`<ins>` markup and visually hidden screen-reader
    explanations instead of escaping the service's already-stripped text.
    `PackageService::wooBasePrice()` retains its plain-text `formatted` contract.
    Currency, tax display, suffixes and effective sale behavior remain delegated
    to WooCommerce; missing/empty prices show `Unavailable`. No composition,
    Catalog authority, product data, add-on price or prefill behavior changed.
    Focused admin **310/310**, Package Stage A **320/320**, and Stage B **258/258**
    passed on `bingocommerce_state_recon`: **888 passed, 0 failed**. Both changed
    PHP files passed syntax checks and `git diff --check` passed. Tests verify
    safe markup, hidden accessibility text, unsafe-markup removal, same-number
    current-Hall price isolation, read-only byte preservation and owned cleanup.
    Logs: `/tmp/bc-package-price.p4xYUF`, including disposable-fixture WooCommerce
    lookup/tax-table and Action Scheduler diagnostics during test-product setup
    and existing teardown scenarios; no assertion/preservation check failed.
    Real-browser visual confirmation remains pending. No real Hall was mutated,
    migrated or deployed; existing work remains unstaged/uncommitted.
  - **[2026-09-23] Add-on recovery after a failed list read:** A confirmed
    projection warning now retains its targeted Retry synchronization control
    even when the subsequent page-model read fails. The list stays unavailable:
    the recovery-only form offers no bulk Save, Remove or unverified rows.
    Existing Hall/actor/Package/nonce checks and authoritative reads remain
    unchanged; Retry fails safely while the read is unavailable and rederives
    current projection state once it recovers. Unknown COMMIT offers no Retry.
    Summary and other row errors remain visible. Focused admin **350/350** and
    backend **582/582** passed on `bingocommerce_state_recon`: **932 passed,
    0 failed**, including POST → native toolbar → full-page recovery rendering,
    submission of the rendered recovery form, tenant/actor/Package isolation,
    persistent Hall byte preservation and ownership-proven disposable cleanup.
    Both changed PHP files passed syntax checks; `git diff --check` passed.
    Logs: `/tmp/bc-addon-recovery.vySzk8/admin-final.log` and `backend.log` in
    that directory. The admin log retains existing non-failing disposable-Hall
    WooCommerce lookup/tax-table and Action Scheduler diagnostics. Other suites
    were not rerun for this correction. Browser confirmation remains pending.
    No service, transaction or fixture behavior changed; no real Hall was
    mutated, migrated or deployed. Work remains unstaged/uncommitted.
- **Manage Session Dates selected-schedule UX:** Portal links identify each
  schedule by ID and saved time; the editor identifies its selected schedule,
  Portal session and saved time separately from form inputs. The Hall-wide table
  appears only on the main list. Save and the selected schedule's separate
  Update Sessions action return to that editor; a confirmed Save clears obsolete
  rejected form input. Failed definition reads offer neither Save nor Update,
  retaining a route to the list and its existing Archive action. Focused admin
  **444/444** and reconciliation **271/271** passed on the approved isolated
  `bingocommerce_state_recon` fixture, including persistent Hall preservation
  and disposable cleanup. All three changed PHP files passed syntax checks;
  `git diff --check` passed. Browser layout confirmation remains pending.
  Scheduling rules, services and schema are unchanged; no live Hall was changed,
  migrated or deployed. Existing work remains unstaged and uncommitted.
- **Manage Session Dates partial-create notice follow-up:** The warning now
  directs the Manager to the selected schedule's Update Sessions action once
  its definition is readable, and instructs a reload after a failed definition
  read. It adds no action form to the notice. The focused admin suite passed
  **447/447** on `bingocommerce_state_recon`, including both readable and
  failed-read notice states; browser confirmation remains pending.
- **[2026-09-24] Signed-out generated-occurrence eligibility correction:** An
  isolated regression proved the same eligible generated occurrence returned
  `true` for a Hall Manager but `sellability_unknown` for a signed-out customer,
  with nested cause `authentication_required`, because
  `SessionEligibility` reached management-only `PackageService::getById()`.
  Catalog now exposes one current-Hall, schema-checked, read-only
  `getForSessionEligibility()` boundary, Sessions consumes it through a distinct
  `PackageGateway` method, and all existing management reads/writers remain
  authorization-gated. No Package rule changed: this still applies the existing
  ADR-043 assignability condition, including its existing archived-component
  behavior, and legacy Sessions retain their no-further-test path. Focused tests
  run genuinely signed out and cover Manager/guest parity, an ineligible Package,
  an unreadable Catalog dependency, legacy behavior, and colliding local Package/
  Session IDs in two disposable Halls. Availability **544/544**, legacy Session
  **352/352**, schedules **159/159**, reconciliation **271/271**, and Package
  Stage B **258/258** passed on `bingocommerce_state_recon`: **1,584 passed, 0
  failed**. Expected injected-read, duplicate-key, contention, disposable-Hall
  WooCommerce/Action Scheduler, and Hall Staff writer-guard diagnostics remained
  visible; final preservation checks passed. No schema, migration, cart,
  checkout, add-on, electronic-limit, order, BR-066, theme or frontend behavior
  changed. No real Hall was mutated or migrated. This work is committed on
  `feature/portal-sync`; it has not been pushed or deployed.
- **[2026-09-24] BR-080 exact-occurrence customer Package eligibility:** The
  approved rule now resolves BR-067 item 4's sale-enforcement deferral without
  rewriting BR-067 or ADR-043: a Package with any archived required base Bingo
  Play Product is unavailable for new customer purchase until restored or
  deliberately corrected. The Package, composition, occurrence assignments,
  Available for Sale setting and historical orders are preserved; no automatic
  Package archive, detach or Session sales change occurs, and another valid
  Package on the same occurrence remains available. The new read-only
  `CustomerPackageEligibility` boundary runs signed out, composes the existing
  occurrence predicate, proves the exact Hall-local occurrence assignment,
  consumes a separately named anonymous-safe Catalog read, retains existing
  Package integrity/assignability checks and then applies the archived-required-
  component rejection. Unreadable dependencies return fail-closed
  `package_eligibility_unknown`; ordinary management reads and all writers stay
  authorization-gated. `SessionEligibility::is_sellable()` and
  `PackageGateway::assert_assignable()` are unchanged by this rule. Focused
  signed-out coverage includes active, archived and restored components, an
  unassigned Package, another valid Package on the same occurrence, preservation
  and injected assignment/Catalog read failures. Availability **564/564**,
  legacy Session **352/352**, schedules **159/159**, reconciliation **271/271**
  and Package Stage B **258/258** passed on `bingocommerce_state_recon`:
  **1,604 passed, 0 failed**. Expected injected-read, duplicate-key, contention,
  disposable-Hall WooCommerce/Action Scheduler and Hall Staff writer-guard
  diagnostics remained visible; preservation and cleanup checks passed. No
  schema, migration, cart, checkout, add-on selection, BR-066, electronic-limit,
  order-metadata, theme or frontend behavior changed. No real Hall was mutated
  or migrated. This work is committed on `feature/portal-sync`; it has not
  been pushed or deployed.
- **[2026-09-25] BR-081 customer cart backend — implemented, unstaged:** Verified
  `feature/portal-sync` clean at `febf32044bbcbd793d7c79e102471b2c64d2a159` before
  editing. Its tree `245750befe6ffe301c8ab86cd5b65026387c1264` matches the locally
  available earlier `1a1a634c9236faabd5f9ed297655fd6ec0aa6646`. Appended reviewed
  BR-081 without rewriting BR-066 or other historical decisions. The approved
  mechanism enforces authoritative Hall-local purchase rules at cart boundaries,
  without global Play Product non-purchasability/status/stock/visibility changes.
  Catalog adds anonymous read-only classification/eligible-relationship reads;
  Sessions adds exact-occurrence selection validation and WooCommerce cart
  adapters. The signed-out eligibility correction, `CustomerPackageEligibility`,
  BR-080, management authorization and all schema/migration code are unchanged.
  One Package line (quantity one) carries the dated Session and a selection ID;
  separate add-on lines carry the exact parent key and relationship ID. Ordinary
  merchandise remains independent. Current relationship prices and limits are
  read server-side. Direct adds, quantity changes, stale/restored/forged carts,
  orphan add-ons, missing selected children and cross-Hall metadata fail closed.
  Parent removal cascades children; Undo revalidates the original group. Failed
  group adds roll back only the new selection. No Catalog/Session write occurs.
  Inspected installed WooCommerce 11.0.1's distinct classic and Store API paths;
  added a callback to its existing guest-safe cart-extension endpoint, not a new
  custom REST/AJAX route. Both API aliases and REST batch quantity sanitization
  are covered. Contract and implementation boundaries are documented in
  `docs/architecture/customer-cart-increment-1.md`.
  Isolated `bingocommerce_state_recon` results: signed-out cart **140/140**,
  availability **564/564**, legacy Session **352/352**, schedules **159/159**,
  reconciliation **271/271**, Package Stage B **258/258**, add-on backend
  **582/582**, add-on administration **350/350**: **2,676 passed, 0 failed**.
  Focused follow-up reran the cart suite serially: 18 new assertions cover two
  distinct Paper add-ons on one Paper Package, separate relationship prices and
  quantities, child removal/Undo, parent removal/Undo restoring both children,
  and unchanged ordinary merchandise. No production defect was exposed or
  production code changed; the other regression counts above are from the prior
  run, not rerun in this follow-up. The Hall-access ownership guard is unchanged.
  Current-run cart fixture Halls/tables were cleaned up, and persistent-Hall
  preservation checks passed. Expected fault-injection and Hall Staff writer
  diagnostics remained in regression logs. The additional Hall-access suite
  refused to run because its separate `bingocommerce_state_test` fixture already
  contained a mapping table/schema version; that ownership guard was preserved,
  not bypassed, and no pass is claimed for that suite. All seven touched PHP
  files passed syntax checks; `git diff --check` passed. No theme, frontend,
  full checkout, electronic limits, order facts, refunds or entitlement was
  implemented. No real Hall was changed or migrated. Nothing staged, committed,
  pushed or deployed; this is not a customer-launch readiness claim.
- **[2026-09-25] BR-082 electronic-face purchase validation — implemented, unstaged:**
  Verified clean `feature/portal-sync` at
  `8c4c9edeb44adde77ea094f268c4dfa53a140551` before editing. Appended BR-082
  without rewriting older decisions. Catalog now owns a read-only current-value
  resolver for `bc_platform_electronic_maximum` (the Hall's network) and optional
  `bc_electronic_limit_override` (that Hall's site). Missing/malformed/unreadable
  settings block digital selections only. Both configured limits must be positive
  whole numbers (`>= 1`); stored `0` or `'0'` is invalid configuration and returns
  `bc_electronic_limit_unavailable` for digital selections. An absent Hall override
  remains valid; Paper-only Packages and ordinary merchandise are unaffected. No
  settings UI/writer, implicit default, automatic 300 initialization or migration
  was added. Fresh parameterized reads avoid stale option caches and distinguish
  an absent override from a failed read.
  Current Package base and eligible selected add-on faces are multiplied by their
  quantities and summed independently by authoritative Hall-local Game Type;
  Paper contributes zero. Missing digital dependencies and integer overflow fail
  closed. BR-080/BR-081 checks remain in place, with electronic validation added
  before cart insertion and during cart revalidation. Installed WooCommerce
  11.0.1 classic final checkout validation and Store API POST checkout's native
  cart-validation path both recheck current data before order creation.
  Initial isolated `bingocommerce_state_recon` results before the zero-setting
  correction: electronic limits
  **351/351**, cart **140/140**, availability **564/564**, legacy Session
  **352/352**, schedules **159/159**, reconciliation **271/271**, Package Stage B
  **258/258**, add-on backend **582/582**, add-on administration **350/350**:
  **3,027 passed, 0 failed**, all suites exit 0. New signed-out tests use real
  classic checkout processing and Store API dispatch; fixture-only sentinels
  stop valid cases after validation, before orders/payment. Coverage includes
  independent Game Types, base/add-on multiplication, shared base/add-on products,
  Paper exclusions, configuration failures, Hall overrides/colliding IDs, lower
  current platform values despite stale cache, restored carts, current changed
  faces, missing dependencies and overflow. New-suite fixture Halls/tables were
  cleaned up, the original fixture network option restored, and persistent
  fixture Hall snapshots preserved. Existing regression logs include deliberate
  fault injection/Hall Staff diagnostics and disposable-table Action Scheduler
  and post-cleanup Woo shutdown database diagnostics; zero failed assertions is
  not a claim of warning-free logs. Hall-access was not rerun or modified.
  Zero-setting correction: both configured limits now require `>= 1` and return
  `bc_electronic_limit_unavailable` for integer `0` and string `'0'`. The focused
  test first reproduced 16 zero-specific failures, then passed **437/437** after
  the two resolver checks were corrected. The cart regression passed **140/140**:
  **577 passed, 0 failed** in this serial follow-up on the isolated fixture.
  Classic and Store API checkout report configuration failure; Paper/merchandise,
  absent overrides and the valid minimum of 1 remain covered. Fixture restoration
  and cleanup, both changed PHP files' syntax checks, and `git diff --check`
  passed. Other regression counts above are from the initial run, not rerun for
  this correction. Face aggregation, checkout adapters and writers are unchanged.
  This slice creates no Session/customer/order capacity counter or reservation.
  Order facts, existing-order payment/retry validation, refunds, entitlement/QR
  and theme remain separate. Nothing staged, committed, pushed or deployed;
  no real Hall was changed or migrated.
- **[2026-09-25] BR-083 customer order recording — implemented and verified:**
  Verified clean `feature/portal-sync` at
  `b40eea226f14a609fde36e191de3ed893940f3f1` before editing. The narrow Sessions
  `CustomerOrder` service records exact Hall-local occurrence and Package facts
  through WooCommerce order/item CRUD after BR-080/BR-081/BR-082 revalidation.
  Separate Package/add-on lines retain a persistent selection UUID, role,
  authoritative add-on relationship ID and purchased quantity; ordinary
  merchandise remains independent. Cart keys are not historical linkage.
  Native charged amounts, discounts and taxes are unchanged. Saved Session
  and parent-Package item labels appear in normal Woo order/receipt information.
  No theme or template override is required. Legacy structured timing remains
  nullable; no time is inferred from display names.
  Inspected WooCommerce 11.0.1's shared line-creation hook and separate classic
  pre-save/Store API draft-update boundaries. Unplaced drafts refresh facts even
  when line hashes are unchanged, and Package removal/replacement removes stale
  facts. Existing non-draft recorded selections preserve their saved facts;
  this defensive behavior does not implement existing-order payment/retry policy.
  Appended BR-083 without rewriting historical decisions; documented the
  metadata contract in `docs/architecture/customer-cart-increment-1.md`.
  Serial isolated `bingocommerce_state_recon` results: actual order recording
  **170/170**, cart **140/140**, electronic limits **437/437**, availability and
  BR-080 eligibility **564/564**, legacy Session **352/352**: **1,663 passed,
  0 failed**, all suites exit 0. An independent final rerun of the order suite
  also passed **170/170** (not double-counted). It exercises signed-out actual
  HPOS order creation through classic checkout and Store API REST POST with
  offline BACS, two distinct add-ons, merchandise, real coupon discounts and
  nonzero taxes. Mail is suppressed and outbound HTTP blocked in the harness;
  no real payment method is charged. Saved facts, names, finances and normal
  receipt metadata survive later Catalog/Session edits. Native draft updates,
  legacy timing, two Halls with colliding IDs, missing/altered Bingo lines and
  unreadable occurrence facts are covered. Token-owned disposable Halls/tables
  were cleaned, and persistent fixture Hall snapshots were preserved.
  The order suite's only diagnostics were existing Hall Staff writer guards;
  no PHP/database warnings occurred in its final runs. Existing Session suites
  retain deliberate fault-injection SQL diagnostics and legacy Session's
  post-cleanup Woo shutdown reads of dropped disposable tables. Hall-access
  was not changed or rerun. All four touched PHP files passed syntax checks;
  `git diff --check` passed. Payment retries, refunds, entitlement snapshots/QR,
  configuration UI and storefront remain separate work. No real Hall changes,
  real-Hall migration, deployment or production-readiness claim is made.
- **[2026-09-25] BR-046 whole-Package native refund guard — implemented and verified:**
  Started from clean `feature/portal-sync` at
  `b53f47baa8426cabfaa9a41a07713df091659520`. Added Sessions `CustomerRefund`
  and its registration; no payment/retry code changed. The guard reconstructs
  the commercial unit from BR-083 saved links and current order item IDs, not
  Catalog state or pre-payment line IDs. Any Bingo refund must include the
  whole Package and every selected add-on with full quantities, post-discount
  amounts and taxes. Merchandise stays independently refundable. Unallocated
  amount-only refunds on Bingo-containing orders, inconsistent links and
  excessive allocations are refused; ordinary-only orders are unchanged.
  Inspected WooCommerce 11.0.1 function/admin AJAX/REST refund paths. Its early
  refund saves require both the refund-object pre-save guard and the shared
  `woocommerce_create_refund` validation hook. Invalid new requests fail before
  refund persistence, gateway processing, restocking and completion events;
  WooCommerce still owns all valid refund processing. No custom processor or
  Woo/core edit was added. Architecture records the exact boundaries and limits.
  Serial isolated `bingocommerce_state_recon` results: order recording plus
  native refunds **736/736**, cart **140/140**, electronic limits **437/437**:
  **1,313 passed, 0 failed**, all exit 0. The expanded order suite retains its
  original regressions and tests actual AJAX/REST/function requests, automatic
  offline-gateway and manual refunds, whole units/mixed orders, partial and
  add-on-only refusals, amount-only refusals, coupons/taxes, free children,
  merchandise independence, historical prices, native resume/current IDs,
  stale-ID refusals and Hall isolation. Persisted order facts, names, IDs and
  finances remain unchanged; valid refund line allocations are read back.
  BACS creates guest orders; the fixture refund gateway has no payment transport.
  Outbound HTTP/mail are blocked. Token-owned Halls/tables were removed after
  testing and persistent fixture Halls 2/3/4 remained unchanged. Only the known
  Hall Staff writer-guard diagnostics appeared on test output; deliberate early
  vetoes use Woo's normal save-exception logger. All four changed/new PHP files
  pass syntax checks and `git diff --check` passes. Hall-access was not rerun.
  **Not full refund-integration coverage:** the tested status-only `refunded`
  edit retains its already-saved status even though its amount-only refund is
  refused. Direct gateway/provider-dashboard refunds, arbitrary item/history
  edits and custom reuse of an existing refund ID are not safely covered by
  this native new-refund boundary; see the architecture note before integration.
  Entitlement voiding/QR, retry behavior, gateway reconciliation and storefront
  remain separate. No real Hall data/migration, stage, commit, push or deployment.
- **[2026-09-26] Order recording: validated digital-face purchase totals — implemented and verified:**
  Started from clean `feature/portal-sync` at
  `872451925df2d7394468f085b4790af7694cfa41`. `CartSelection::inspect()` now
  exposes the result of its existing authoritative electronic validation to
  `CustomerOrder`. New classic orders and unplaced Store API drafts save that
  map as `bc_purchase.digital_faces_by_game_type`, keyed by Hall-local Game
  Type ID. It includes required base and eligible selected add-on quantities;
  Paper-only selections record an empty map. No electronic limit is saved.
  Placed orders retain their saved totals, including through native classic
  pay-for-order/resume and Store API existing-order/cart retries. Drafts follow
  current selections and Catalog data. Older placed orders missing the field
  remain unknown and are never backfilled from mutable Catalog data, including
  when the order lacks the whole purchase summary. Future entitlement work
  must require reviewed historical recovery before automatically activating an
  order with missing/invalid totals; absence must not mean Paper/zero.
  Serial `bingocommerce_state_recon` results: order recording/refunds
  **1,098/1,098**, cart **140/140**, electronic limits **437/437**:
  **1,675 passed, 0 failed**, all exit 0. Tests use actual signed-out classic
  and Store API checkout, offline BACS, Digital/Hybrid/Paper Packages, two Game
  Types, add-ons, merchandise, coupons and tax. Native retries preserve saved
  facts after face-count, component-quantity and Game Type assignment edits;
  customer selections/prices remain unchanged in those retry cases. Draft
  refresh/replacement/removal, older missing facts, digital-order refunds and
  two Halls with colliding IDs are covered. Financial records remain unchanged.
  Fixture network configuration was restored; disposable Halls/tables were
  cleaned, and persistent fixture Halls 2/3/4 remained unchanged. Known Hall
  Staff writer-guard diagnostics appeared; no PHP/database warnings occurred.
  All three changed PHP files pass syntax checks; `git diff --check` passes.
  No production retry/refund behavior, entitlement/QR, settings writer, capacity
  counter or UI was added. No real Hall changes or migration; nothing staged,
  committed, pushed or deployed.
- **[2026-09-26] Paid online digital entitlement snapshot — implemented and isolated-verified:**
  Started from clean `feature/portal-sync` at
  `7f51448c4ac580ef8d7de5da51b134aad50496bb`. The new planned normal
  `bingocommerce-digital-tickets` plugin observes only
  `woocommerce_payment_complete`; it does not change gateways, checkout,
  payment retries, order recording or refunds. Positive-total online checkout
  orders with confirmed Woo payment and valid saved purchase facts produce one
  immutable Hall-local `bingo_entitlements` row, keyed uniquely by order ID.
  It stores exact Session/Package/selection identity and the saved
  `digital_faces_by_game_type` map, never today's Catalog calculation or an
  electronic limit. Current order-line links/quantities must match saved facts.
  Plugin-owned schema v1 verifies structure/indexes before recording its site
  version; payment callbacks never migrate. Duplicate/interleaved events use
  the unique index and readback, never an UPDATE/REPLACE; conflicting facts do
  not overwrite the original. Paper with an explicit empty map and ordinary
  merchandise produce no entitlement. Missing/malformed historical totals
  remain unknown and require reviewed historical recovery. **Zero-total
  checkouts remain outside this paid-order slice** and report
  `bc_entitlement_zero_total_out_of_scope`, without preventing native completion.
  Snapshot failures are Hall/order-attributed diagnostics, not payment failures.
  Serial `bingocommerce_state_recon` verification: new entitlement harness
  **357/357**, existing order recording/refunds **1,098/1,098**, cart
  **140/140**, electronic limits **437/437**: **2,032 passed, 0 failed**,
  all exit 0. The existing suites also loaded the new plugin in their isolated
  processes, without persistent activation. Real guest classic order creation
  and Store API checkout use offline BACS; real `WC_Order::payment_complete()`
  models confirmation without a payment transport. Tests cover multiple Game
  Types/add-ons, Hybrid/Paper/merchandise, actual free-coupon completion in both
  paths, corrupt/missing facts, unpaid/draft refusals, immutable order facts and
  finances, Catalog edits before confirmation, failed storage/diagnostics,
  interleaved duplicate-key insert, verified migrations and colliding Hall-local
  identities. This is not provider-webhook or parallel-process load testing.
  An initial test used a nonexistent Catalog method; corrected to the existing
  `updateFacesPerUnit()` API before the passing run. The sandbox initially
  blocked MySQL; the guarded fixture ran with local-connection permission and
  never fell back to the development database. Known Hall Staff writer-guard
  diagnostics and the deliberately failed diagnostic-sink message appeared.
  Disposable Halls/tables were cleaned; fixture Halls 2/3/4 remained unchanged;
  the fixture network option was restored. All five new PHP files pass syntax
  checks; `git diff --check` passes.
  **QR issuance, refund/cancellation voiding, automatic snapshot failure
  recovery and gateway reconciliation remain unfinished.** Native repeated
  payment completion on an already-paid order need not emit the primary event
  again. No free-order entitlement or admin/POS/import activation rule was added.
  The plugin was not activated on the development installation; no real-Hall
  migration/data change, stage, commit, push or deployment was performed.
- **[2026-09-26] BR-046 existing digital entitlement voiding — implemented and isolated-verified:**
  Started from clean `feature/portal-sync` at
  `102c3b625f23afaf975569f9d3107a476854101a`. `CustomerRefund` observes
  Woo's successful native `woocommerce_refund_created` event, reloads the saved
  refund, and reuses its existing whole-unit validator and current order-item
  links. Only a complete Bingo Package/add-on refund emits
  `bingocommerce_package_refunded`; valid merchandise-only refunds do not.
  Automatic refunds additionally require Woo's persisted refunded-payment flag.
  The pre-refund restrictions and native refund processing are unchanged.
  Digital Tickets consumes that notification and Woo's persisted
  `woocommerce_order_status_cancelled` transition. `EntitlementVoiding` matches
  current-Hall saved order/Session/Package/selection identity and conditionally
  changes an existing entitlement's `active` status to `void`. Snapshot totals,
  identity, version and confirmation timestamp remain unchanged; repeats are
  harmless. No current Catalog reads, inserted entitlement/tombstone, schema
  change or reactivation path is added. Failure diagnostics do not throw into
  Woo's already-successful refund/cancellation flow; verified event redelivery
  can retry the status write without another refund or payment.
  Serial `bingocommerce_state_recon` results with Digital Tickets loaded:
  entitlement/snapshot/voiding **628/628**, existing order recording/native
  refunds **1,098/1,098**, cart **140/140**, electronic limits **437/437**:
  **2,303 passed, 0 failed**, all exit 0. The expanded suite uses actual
  `wc_create_refund()`, Woo REST v3 refunds on Store API-created orders and
  native order cancellation, with signed-out purchases, fixture-admin refund
  actions and a noncharging fixture gateway. It verifies whole-unit versus
  merchandise/partial/add-on/amount-only refunds, gateway failure, coupons/tax,
  repeats, archived Catalog dependencies, immutable order facts/financials and
  entitlement snapshots, no-existing-entitlement cases, invalid identities,
  failed void storage/redelivery and colliding Hall-local order/domain IDs.
  The existing regression additionally covers native AJAX and replaced item IDs.
  Disposable Halls/tables were removed, persistent fixture Halls 2/3/4 stayed
  unchanged, and the fixture network setting was restored. Known Hall Staff
  writer-guard and deliberate diagnostic-sink test messages appeared. All five
  changed/new PHP files pass syntax; `git diff --check` passes.
  **Not complete lifecycle coverage:** status-only `refunded` edits (tested),
  direct/provider-dashboard refunds, existing-refund rewrites, arbitrary
  order/refund-history edits, missed-event recovery and refund/cancellation
  before an entitlement exists still require reviewed reconciliation. Storage
  failures require operational review. QR/Android, payment, retries, checkout
  and storefront are unchanged. No real-Hall data/migration, plugin activation,
  stage, commit, push or deployment was performed.
- **[2026-09-27] BR-046 refund/cancellation before entitlement — implemented and isolated-verified:**
  Started clean on `feature/portal-sync` at `a00dc03`. The earlier observer
  returned when no entitlement existed, losing the terminal event before a
  later payment-complete delivery. Digital Tickets schema v2 now records an
  insert-only, Hall-local indexed `bingo_entitlement_voids` marker using saved
  order/Session/Package/selection identity and first cause/refund/time. It
  invents neither digital totals nor a paid snapshot. The existing validated
  native whole-Package refund and saved-cancellation listeners record the marker
  before enforcing the existing status-only void. The payment observer checks
  it before and after snapshot insertion. Repeated delivery cannot clear the
  marker or reactivate/overwrite a snapshot. Paper/merchandise and rejected or
  merchandise-only refunds do not acquire a digital block. Unknown saved face
  totals remain unknown; unreadable marker evidence fails closed.
  The requested earlier-priority cancellation callback race was reproduced
  before its fix: **960 passed, 13 failed**, with all four classic/Store API ×
  reloaded/event-object cases leaving `active` without a marker after native
  payment and again after repeated payment. The observer now receives all three
  native cancellation arguments and retains the original transition after a
  nested payment changes current status. It verifies the event order ID,
  transition target/from, absence of unsaved status, and exact saved purchase
  facts in the current Hall. ID-only delivery still requires saved cancellation.
  All four cases now leave `void` plus a cancellation marker, with Woo still
  processing and the original snapshot/facts/financial values unchanged.
  Negative cases cover ID-only/malformed/wrong-order/foreign-Hall/unsaved
  evidence and a failed cancellation save. The synthetic foreign-Hall object
  reloads its metadata because Woo order clones share mutable metadata objects.
  Serial `bingocommerce_state_recon` results with Digital Tickets loaded:
  entitlement/snapshot/voiding **1,033/1,033**, order recording/native refunds and
  retries **1,098/1,098**, cart **140/140**, electronic limits **437/437**:
  **2,708 passed, 0 failed**, all exit 0. Both event orders use real guest
  classic/Store API orders and native refund/cancellation, including repeated
  payment events, mixed merchandise, two add-ons, coupons/tax and unchanged
  financials. Controlled insert interleavings converge to void. Schema-v1
  upgrade preserves populated snapshots. Disposable Halls/tables were cleaned,
  persistent fixture Halls 2/3/4 stayed unchanged and fixture settings were
  restored. Expected Hall Staff guard/diagnostic-failure messages appeared.
  All five changed PHP files pass syntax; `git diff --check` passes.
  **Timing limits:** no independent-process/crash-safety proof; a transient
  active row can exist before the cancellation observer or post-insert marker
  check. Future QR/redemption must coordinate lifecycle writes and honor terminal
  evidence. The earlier-callback case is now covered; arbitrary concurrent Woo
  writes, combined persistence failures/reentrant callbacks, failed/missed
  marker writes and pre-upgrade history remain outside this proof. No backfill is
  added. Earlier provider/direct-gateway/status-only refund limits remain.
  No payment, retry, refund processor, QR, Android or storefront changes; no
  real-Hall data/migration, stage, commit, push or deployment. Seven modified
  files remain unstaged for review.
- **[2026-09-28] BR-044 / ADR-030 first online QR issuance increment — implemented and isolated-verified:**
  Started clean on `feature/portal-sync` at
  `a280d0bb95f1447632a13b3b22cf7942168d1a65`. Digital Tickets schema v3 adds
  Hall-local `bingo_tickets`, with a primary entitlement key and unique binary
  256-bit random token. Priority-20 payment-complete issuance runs after the
  existing snapshot observer, reuses its saved-fact validation and requires
  matching immutable digital totals, positive-total confirmed online payment,
  active entitlement and no terminal marker. Repeat/interleaved events reuse
  the same insert-only ticket. No current Catalog reads, face/price/person data
  in the QR, Woo financial writes or new payment/refund behavior are introduced.
  Paper, merchandise, pending payment, unknown/invalid facts, $0, void or marked
  orders issue nothing. Hybrid QR scope is digital only.
  Both internal reference lookup methods read entitlement status AND terminal
  marker afresh; a marker blocks even if the snapshot status update failed.
  Hall hints never switch context. Normal Woo customer order details display a
  scannable canvas using WooCommerce 11.0.1's bundled encoder and a small plugin
  renderer. Owner capability or guest key plus Woo email verification is
  required. Views never issue/rotate a ticket; there is no public lookup API.
  **Email gap:** actual classic/Store API native processing emails were observed
  before the payment-complete entitlement/ticket exists. No email QR, reordered
  notification or separate sender is added. Deferred/later email image delivery
  remains unfinished; browser canvas code cannot execute inside an email.
  Serial `bingocommerce_state_recon` runs with Digital Tickets loaded passed:
  entitlement/QR/lifecycle **1,220/1,220**, order recording/native refunds and
  retries **1,098/1,098**, cart **140/140**, electronic limits **437/437**.
  Network-blocked headless Chrome rendered actual fixture classic shortcode order-view HTML
  (**6/6**); macOS Vision independently decoded the QR to the exact saved
  reference (**1/1**). Total **2,902 passed, 0 failed**, all final runs exit 0.
  Coverage includes real guest classic/Store API orders, multiple Game Types
  and add-ons, Hybrid/Paper/merchandise, repeat/interleaved issuance, missing or
  malformed facts, insert/read failures, Catalog changes, both terminal-event
  orders, marker-only blocking, native refunds/cancellation, coupons/taxes,
  unchanged financials and colliding local IDs. Disposable Halls were removed,
  persistent fixture Halls 2/3/4 remained unchanged and settings were restored.
  Seven changed/new PHP files and both JavaScript files pass syntax checks;
  `git diff --check` passes. Browser/Vision checks needed local-process sandbox
  permission; no dependency/plugin was installed or external QR service used.
  **Remaining limits:** JavaScript-required customer-view rendering; no QR
  emails, Android redemption/device binding, in-store/POS activation, offline
  flow, $0 policy, historical ticket backfill or automatic recovery. Existing
  terminal-observer timing/missed-event and out-of-band/status-only refund
  boundaries remain; no independent-process/crash-safety claim is made.
  No real-Hall migration/data, gateway, checkout, retry, refund processor,
  shipping, theme or storefront changes. Work remains unstaged/uncommitted;
  no push, deployment or live plugin activation was performed.
- **[2026-09-28] Online QR Order Confirmation block guest follow-up — isolated-verified:**
  Preserved the uncommitted QR work on `feature/portal-sync` at `a280d0b`;
  index was empty. The earlier QR run used the classic shortcode view even
  for Store API-created orders; it did not prove Order Confirmation blocks.
  The added test renders the installed Woo blockified Order Confirmation
  template through `do_blocks()` and its real registered permission/render
  callbacks, with a natively paid Store API fixture order and user ID zero.
  Immediate authorized viewing worked. After the grace period, native blocks
  displayed the order totals for a valid `_wpnonce`/email, but the QR renderer
  only understood classic `check_submission`: corrected reproduction
  **1,241 passed, 2 failed**, one for each native verification nonce action.
  The only production correction adds support for the block's `wc_verify_email`
  and `wc_create_account` nonce actions in `CustomerTicket::can_view()`. Hall
  isolation, mandatory guest order key, sanitized email verification and all
  entitlement/terminal-marker checks remain intact. No other production file
  was changed in this follow-up.
  Focused suite rerun: **1,243/1,243**; browser block-output checks **10/10**;
  independent QR decode **1/1** — **1,254 passed, 0 failed**, all exit 0.
  Authorized guests see the same QR immediately and after email verification;
  missing/wrong keys, expired unverified access, wrong email and invalid nonce
  reveal neither QR nor reference. Order facts and financial values remain
  unchanged. Disposable Halls were cleaned; persistent fixture Halls 2/3/4
  remained unchanged. Changed PHP/JavaScript syntax and `git diff --check` pass.
  This is native PHP block-template rendering plus browser/decoder checks of
  that output, not a live HTTP/browser checkout or real payment integration.
  BACS clears the draft-order session ID: an initial test assumption otherwise
  was removed, and the block's separate session-only authorization branch is
  not claimed as verified. Existing QR email/lifecycle/Android limits remain.
  Five existing work-in-progress files were amended (renderer, two tests,
  architecture and this status); everything remains unstaged/uncommitted.
- **[2026-09-28] Customer email → guest order → QR follow-up — isolated-verified:**
  Started clean on `feature/portal-sync` at
  `181566ada6356a6a4a5ef2cb0f35b8b231d955ff`, with an empty index.
  Capturing the actual native paid customer emails exposed missing order-view
  links for both classic and Store API orders: **1,257 passed, 2 failed**.
  The only production correction adds one normal Woo order-received link to
  existing customer processing/completed HTML/plain emails for valid paid,
  positive-total online Digital/Hybrid orders. Current-Hall saved facts are
  checked; no QR/token is embedded, no separate notification is sent and no
  payment, retry, refund or authorization behavior is changed. The link can be
  generated before entitlement issuance; the destination still requires an
  eligible existing ticket and Woo guest authorization.
  Fresh signed-out PHP-CGI requests followed the extracted email URL through
  WordPress query parsing to the native classic and block confirmation views.
  After aging the disposable orders beyond the grace period, actual native
  verification form POSTs succeeded with matching email/nonces. Wrong email,
  nonce and order key disclosed no QR. Both verified pages rendered and decoded
  the exact saved reference; views and emails preserved purchase facts, item
  records, amounts, discounts and taxes. Paper, merchandise, unpaid, $0 and
  malformed/foreign-Hall facts receive no added link.
  Serial `bingocommerce_state_recon` verification: focused suite **1,330/1,330**;
  existing order/refund/retry suite with Digital Tickets loaded **1,098/1,098**;
  browser rendering **16/16** and independent Vision decoding **2/2**.
  Total **2,446 passed, 0 failed**, all final runs exit 0. Four changed/new PHP
  files pass syntax checks; `git diff --check` passes. Owned disposable Halls
  were removed; persistent fixture Halls 2/3/4 and restored settings were checked.
  **Limits:** mail was captured at `pre_wp_mail`, not delivered; CGI exercised
  actual GET/POST parsing and native content, not public web-server/theme or
  mailbox navigation. No custom/deferred email transport or real gateway was
  tested. Embedded QR email delivery or a separate QR email sender, $0 policy,
  Android and previous lifecycle
  limits remain unfinished. No real Hall, fixture ownership guard, schema or
  Woo source was changed. Five modified files and one new test worker remain
  unstaged/uncommitted; no push or deployment.
- **[2026-09-28] BR-064 electronic-limit administration — implemented, isolated-verified:**
  Started clean on `feature/portal-sync` at
  `d6d685f75657934219bce5e589e926abef29990e`, with an empty index.
  Catalog now registers Electronic Limits under Network Admin → Settings and
  current-Hall WooCommerce administration. Platform saves require native Super
  Admin and `manage_network_options`; Hall saves/removal require the existing
  Hall context and `bc_manage_hall_settings` or Super Admin. Same-page POSTs
  recheck authority and context-bound WordPress nonces and reject submitted
  tenant/actor IDs. No capabilities or role guards were changed.
  Saves accept canonical positive whole numbers through `PHP_INT_MAX`, using
  native network/site option APIs and fresh readback. Invalid submissions do
  not change stored values. Explicit Hall removal deletes the option; blank
  input cannot remove it. Screens show saved values separately from the current
  effective ceiling. No implicit 300, migration, clamp/rewrite of a saved Hall
  override, counter or cached effective limit was introduced. The existing
  `ElectronicLimit` resolver, face aggregation and purchase hooks are unchanged.
  Serial isolated `bingocommerce_state_recon` verification passed:
  electronic/settings **651/651**, cart **140/140**, order/refund/retry with
  Digital Tickets loaded **1,098/1,098**, entitlement/QR **1,330/1,330** —
  **3,219 passed, 0 failed**, all final runs exit 0. Coverage includes native
  rendered form POSTs/nonces, submenu registration, authorized/rejected roles,
  invalid and failed-storage saves, main/network/Hall boundaries, two-Hall
  isolation, removal, and a platform decrease from 48 to 35 while a Hall keeps
  override 99. Both checkout paths reject the existing 36-face guest cart;
  new digital adds also fail while Paper and merchandise remain valid.
  Four changed/new PHP files pass syntax checks; `git diff --check` passes.
  Persistent fixture Halls 2/3/4 remain unchanged and final-run disposable Halls,
  users and user metadata were removed. An initial harness `wp_die` interrupted
  cleanup because native staff-role guards rejected fixture role assignment;
  the existing ownership-checked fixture pattern fixed setup without weakening
  those guards. Its owned leftovers were removed. The pre-interruption network
  setting was not logged, so the isolated fixture maximum was retained at 48;
  subsequent runs restored that baseline rather than guessing its earlier value.
  These are native PHP admin rendering/controller tests, not live browser/admin
  deployment verification. No real Hall, payment, retry, refund, entitlement,
  QR or storefront behavior changed. Seven modified files and one new settings
  class remain unstaged/uncommitted; no push or deployment.
- **[2026-09-28] BR-064 higher-override notice follow-up — isolated-verified:**
  Preserved the existing uncommitted settings work at `d6d685f` on
  `feature/portal-sync`. The Hall settings page now persistently explains when
  a valid saved override exceeds the valid platform maximum, using actual saved,
  platform and effective values. The notice appears after save and on later
  visits, not for equal/lower, absent or invalid values. Higher saves remain
  allowed; no resolver or purchase-rule changes were made. The full focused
  electronic/settings suite passed **679/679** on `bingocommerce_state_recon`,
  including exact 350/300 wording and a later platform decrease. Configuration
  was restored, disposable Halls/users were removed, and persistent fixture
  Halls 2/3/4 remained unchanged. Changed PHP syntax and `git diff --check` pass.
  Only the settings renderer, focused tests and this status were amended in
  this follow-up; all existing work remains unstaged/uncommitted.
- No Hall Staff Management work currently in progress.
- Package Increment 1 and the legacy Session Increment 1 remain COMPLETE and
  are recorded under Done. Package Add-ons Increment 1 is approved by
  ADR-044/BR-079; schema Slice 1, backend Slice 2 and administration Slice 3 are implemented.

---

## Immediate Demo Critical Path
1. **Package Increment 1** — **COMPLETE.** Stage A and Stage B are implemented,
   source reviewed, automated-verified and interactively verified in wp-admin.
2. **Session Increment 1** — **COMPLETE.** Implemented, source reviewed,
   automated-verified (344/344) and interactively verified in wp-admin on a
   Network Active plugin, including tenant isolation and Game Operator denial.
3. **Phase 3C1 — Portal Game Type identity persistence: COMPLETE. Phase 3C2 —
   Portal Session-template replica persistence: COMPLETE. Phase 3D1 — Portal
   HTTP transport: COMPLETE. Phase 3D2 — strict Portal snapshot validation:
   COMPLETE against ADR-037.** ADR-038 records snapshot completeness and Game
   Type absence. ADR-039's name-validation amendment is implemented and tested;
   COMPLETE. Catalog v5 is implemented and tested; COMPLETE.
   **Phase 3E snapshot application is COMPLETE under ADR-040: source review
   passed, the local harness passes 387/387 (testing multiple disposable Hall
   mappings and tenant isolation), and the authenticated real Portal test
   passed with one persistent mapping (Portal Hall 2 -> site 2; first run
   imported 7 Game Types and 26 Session templates, second run was a domain
   no-op, tenant isolation and Commerce non-interference passed).**
   **Phase 3F — the multi-Hall tenant-isolation proof — is COMPLETE.**
   **Phase 3G — manual Hall-scoped synchronization — is COMPLETE** (ADR-041):
   source review passed; the local harness currently reports **468 passing
   assertions** (467 at fixture isolation `7f3e332`, plus the WooCommerce
   session-residue assertion added with the ADR-042 machine path); the practical
   browser test PASSED. Commerce-state persistence (**254/254**), SetupReadiness
   (**199/199**), and Hall Commerce access enforcement (**834/834**; handlers
   **364/364**) are implemented foundations.
   **ADR-042 / BR-076 decide automatic Commerce enable/suspend provisioning —
   documentation complete; the persistence foundation is implemented
   (551/551), and the BingoCommerce-side machine path — REST endpoint,
   orchestrator, `bingocommerce-platform` and its single provisioning filter —
   is implemented and measured (endpoint **50352/50352**, platform
   **491/491**). The Portal Global Admin Enable, Suspend, Re-enable and Retry
   Ecommerce controls are implemented and tested (Portal automated suite
   **738 tests passed, 2,909 assertions** on 2026-09-21, isolated SQLite, no WordPress). A
   user-performed local-development Open Store → Suspend → Re-enable
   workflow passed against one mapped WordPress multisite test site, and
   re-enable reused the same site.**
   **Production deployment is NOT claimed.** Commerce scheduling and occurrence
   reconciliation exist in the current repository under approved ADR-043.
   Portal webhooks and transitional local-writer policy remain deferred.

Everything below is tracked work that is NOT a blocker to that demo.

## Before Customer-Facing Digital Launch
1. Complete the remaining Portal integration delivery increments. Phase 3B
   through Phase 3G and the BingoCommerce-side ADR-042 machine provisioning path
   are implemented and tested. The Portal Global Admin Enable, Suspend,
   Re-enable and Retry Ecommerce controls are implemented and tested. A
   user-performed local-development Open Store → Suspend → Re-enable
   workflow passed against one mapped WordPress multisite test site, and
   re-enable reused the same site. Portal
   webhooks and SSO remain later integration phases.
   Commerce scheduling and occurrence reconciliation exist under approved
   ADR-043; production deployment is not claimed.
2. WooCommerce's native payment retry flow remains in use; isolated retries with
   unchanged inputs preserved saved purchase facts. Changed-input retries and
   real gateway paths remain unverified; no custom retry feature is approved.
   Other purchase guarantees remain as described below. BR-083 records exact checkout order facts and Package/add-on
   linkage through both classic and Store API checkout.
   BR-081 now resolves BR-066's mechanism deferral: server-side cart enforcement
   is implemented without global Play Product purchasability/lifecycle changes.
   Order recording alone does not complete payment/refund/entitlement guarantees.
   BR-046 now guards native itemized refund creation and voids existing digital
   entitlements after whole-Package refunds or order cancellation. Status-only
   refunded edits, out-of-band gateway paths and missing-event/lifecycle races
   still need separate integration review and reconciliation.
3. Package add-ons: ADR-044/BR-079 approve `PackageAddonService`, Catalog v6's
   `bingo_package_addons`, the 1–99 quantity rule, lifecycle/medium integrity,
   fixed transaction profile, and BR-065 `bc_package_relation` projection.
   Slice 1 migration/schema verification and Slice 2 backend service, mutation
   profile, lifecycle/medium extensions, audit, projection and targeted
   reconciliation are implemented and isolated-tested. Slice 3 Package administration
   UI and request handling are implemented and automated-verified. User-reported
   browser observations: normal and sale prices display correctly; add-on Save
   feedback and partial-error feedback appeared; Remove → refresh → re-create
   worked; cancelling Remove left the relationship and values intact after
   refresh; and Package archive, read-only view and restore were checked.
   These observations do not establish that every browser scenario passed.
   The injected post-commit list-read failure and its recovery-only Retry screen
   are covered by automated tests but have not been browser-tested by the user.
   BR-081 customer cart enforcement, BR-082 electronic checkout validation and
   BR-083 order recording are implemented. The homepage date → Session →
   informational Package display is implemented (2026-10-01, uncommitted);
   add-on selection and purchase actions remain outstanding; payment-retry
   status is summarized in item 2 above.
4. Redemption accumulation (BR-045). BR-064 authorized configuration screens
   and save/removal actions are implemented and isolated-tested. BR-082 keeps
   the read-only effective-limit resolver and electronic validation at cart
   addition and final classic/Store API checkout;
   BR-083 adds exact order facts. WooCommerce's native retry flow remains in
   use; only unchanged-input retries were observed. Changed-input retries and
   real gateway paths remain unverified, and no custom retry feature is approved.
5. Embedded QR email delivery or a separate QR email sender, Android redemption
   (ADR-030) and remaining lifecycle reconciliation. The first positive-total online QR/customer-order-view
   increment (BR-044), entitlement snapshot (BR-043, ADR-029) and BR-046 native
   whole-Package refund/cancellation markers/voiding are implemented and
   isolated-verified, but are not the complete lifecycle or a customer-facing
   digital launch.
6. Provider confirmation integration, missed-snapshot recovery/reconciliation,
   and a separately reviewed zero-total checkout boundary. BR-053's normal
   paid-online snapshot event is now `woocommerce_payment_complete`; this
   does not verify any real gateway webhook or change Woo retries. One
   user-placed local Stripe test-mode order (Star Bingo Hall #71, 2026-10-04)
   had its payment confirmed natively through `checkout.session.completed`,
   delivered by temporary Stripe CLI forwarding; its verified WooCommerce status
   was Processing, with exactly one entitlement and one ticket. Order #70
   remained pending without webhook delivery and was later cancelled
   automatically by WooCommerce's hold-stock timeout. Production webhook
   delivery remains unverified.
7. Coordinated starter-product catalog copy at provisioning (BR-047, ADR-027);
   Game Types instead follow ADR-035 synchronization. ADR-042 / BR-076 narrow
   this for the automatic-provisioning MVP: no starter Product / Play Product
   copy runs until that deferred mechanism is separately designed.
8. Package hard delete, including BR-065's mandatory linked-product
   disposition. No hard delete exists in any Catalog entity today.
9. Checkout review follow-ups still pending after the 2026-10-03 recovery
   increment (not implemented): the invalid-selection price fallback, the
   fixed "1" Package quantity display, offline-payment QR issuance, the
   old-order payment policy and suspension access, and coupons (last).
   Missing-ticket feedback now exists for online card orders only (the
   2026-10-04 ticket waiting area, uncommitted); offline payments show nothing,
   as before. The header capture, repeated time text and the
   bank-transfer/QR wording remain clarifications only.

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
  preserve ADR-031, ADR-032, ADR-033, ADR-034, ADR-044 and the detailed
  persistence design.
- The corrected ADR-044-aware
  `docs/architecture/catalog-session-persistence-design.md` §"Catalog lock-order
  review" is the current lock-order authority. It includes add-on
  create/update/remove, enabled-add-on checks on both medium transitions, and
  the complete base/add-on dependency set for `replaceComponents()`. ADR-033
  locks `bingo_packages` then `bingo_play_products` ascending and locks no
  component or add-on relationship row before its Play Product.
- Latest verified results **[2026-09-19, ADR-043 focused schedule/admin
  verification]**: `tests/session-schedules-increment-1.php` **159/159 PASS**
  and `tests/session-schedule-admin-increment-1.php` **371/371 PASS**, for
  **530/530 passing assertions**. This is existing evidence from 2026-09-19
  and was not rerun during the 2026-09-21 ratification pass. ADR-043 is
  approved and implemented; production deployment is not claimed.
- Latest verified results **[2026-09-14, ADR-042 BingoCommerce machine
  provisioning path, after three source-review correction passes]**:
  `tests/portal-provisioning-endpoint.php` **50352/50352** and
  `tests/platform-hall-site-provisioning.php` **491/491**, each on two
  consecutive runs with identical totals. Every other affected suite was re-run
  and every one passes, none skipped: ADR-042 provisioning foundation
  **551/551**, Commerce state **254/254**, installed handler integration
  **364/364**, Hall Commerce access **834/834**, SetupReadiness **199/199**,
  Phase 3E Portal snapshot application **387/387**, Phase 3G Portal sync admin
  **468/468**, Phase 3B Portal integration foundation **77/77**. No development
  migration was run, no development site was created, no Application Password
  was issued in the development database, no live Portal HTTP request was made,
  and no mail was sent.
  The Portal Enable/Disable UI, SSO, webhooks, scheduling, reconciliation,
  BR-016 billing persistence and starter-product copy remain unimplemented, the
  manual cross-repository Portal end-to-end test has not been performed, and
  production deployment is NOT claimed.
- Latest verified results **[2026-09-13, ADR-042 documentation + provisioning
  persistence foundation + current measured harness status]**: Portal Sync Admin
  **467 passing assertions** (after fixture isolation `7f3e332`; historical 107
  stop and earlier 411 landing figure are not current). ADR-042 provisioning
  persistence foundation **551/551 PASS** (two consecutive runs with identical
  totals). Hall Commerce access enforcement
  **833/833 PASS**; installed payment/runner handler integration **361/361
  PASS**; Commerce state persistence **254/254 PASS**; SetupReadiness
  **199/199 PASS**; Phase 3E Portal snapshot application **387/387 PASS**.
  The provisioning REST endpoint, platform filter, site creation and Portal
  Enable/Disable button remain unimplemented; only the persistence primitives
  exist. Production deployment is NOT claimed.
- Latest verified results **[2026-09-09, Phase 3G gate — historical landing]**:
  Phase 3G practical browser test **PASSED** (3 clicks -> 3 correctly
  attributed audit events, 0 on refresh); Phase 3G Portal sync admin landed at
  **411/411 PASS** before later fixture-isolation raised the current count to
  467; Phase 3E Portal snapshot application **387/387 PASS**; Phase 3D2 Portal
  snapshot validation **1638/1638
  PASS**; Phase 3D1 Portal HTTP transport **67/67 PASS**; Phase
  3B Portal integration foundation regression **75/75 PASS**; Phase 3C2 Portal
  Session-template replica persistence **98/98 PASS**; Phase 3C1 Portal Game
  Type identity persistence **133/133 PASS**; Session Increment 1 **344/344
  PASS**; Package Stage B **258/258 PASS**; Package Stage A **316/316 PASS**;
  Game Type **164/164 PASS**; Hall Staff Create/Invite **262 passed, 0 failed**.
  The Play Product suite reports **466 of 467 assertions PASS**, the
  single failure being the documented Tampa residue detection described below;
  the selector
  regression passes; and current-run disposable-hall cleanup reports **5/5
  PASS** with zero cleanup failures.
- **Open pre-existing precondition failure — `tests/hall-staff-phase-a-fixes.php`
  (NOT a Phase 3G defect).** The harness aborts on its first precondition,
  `hall three starts without Hall Managers: expected [], received [34]`. Tampa
  (site 3) has carried Hall Manager `HMtampa1` (user `34`, registered
  `2026-08-14`, `bc_3_capabilities` at `umeta_id 23443`) since well before this
  work, and that exact row is present in the Phase 3F opening baseline captured
  at `2026-09-09T14:34:49Z`, before any Phase 3F or Phase 3G change. The Phase 3G
  change adds a capability to the `bc_hall_manager` role definition and cannot
  assign a role to a user. This is the same class of issue as the resolved Phase
  3C1 stale-harness caveat: a harness precondition that no longer matches local
  data. It is recorded here rather than silently reclassified, and needs a
  decision — refresh the precondition, or make the harness provision its own
  disposable hall — before the previously recorded **67/67 PASS** figure can be
  reproduced.
- **Phase 3C1 stale-harness caveat — RESOLVED on 2026-09-07.**
  `tests/portal-game-type-identity-persistence.php`
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
- **Halls:** six disposable local Hall sites — Sunrise (2), Tampa (3), Orlando
  (4, blogname "Orland Bingo Hall"), Disposable Ecommerce 20260914 (868), Star
  Bingo Hall (869) and Texas Hall (870); 2, 868, 869 and 870 are Portal-mapped
  (read-only check, 2026-09-29). WooCommerce "Coming soon" (store pages only) is
  on for every Hall, so signed-out visitors see WooCommerce's Coming soon page
  on store pages.
- **Storefront theme (local test configuration, 2026-09-29):** BingoThemev1 is
  network-enabled alongside Twenty Twenty-Five. It was activated on the
  designated test Halls Star Bingo Hall (869) and Orlando (4) for the ADR-045
  checks. It is also active on Sunrise (2), activated through wp-admin by a
  separate signed-in Super Admin session at 11:38:03 EDT on 2026-09-29 (access
  log); the user reports they think they activated it, so this is likely
  user-initiated but not conclusively confirmed. It was not part of the
  scripted test changes and Sunrise was left unchanged. Tampa, 868 and 870
  remain on Twenty Twenty-Five.
- **Digital Tickets (local configuration):** `bingocommerce-digital-tickets`
  is `Network: true` and is **not** network-activated here; no Hall has its
  schema version or tables, and no Hall has orders. Its implemented QR view is
  therefore inactive locally; its suites run in the isolated
  `bingocommerce_state_recon` fixture, which loads the plugin directly.
- **Commerce:** WooCommerce network-active, HPOS on
- **Local mail:** SMTP configured and verified for WordPress staff invitation/password setup testing
- **Custom code:** `wp-content/plugins/bingocommerce-core/`,
  `wp-content/plugins/bingocommerce-catalog/` and
  `wp-content/plugins/bingocommerce-sessions/` (all three Network Active);
  `wp-content/plugins/bingocommerce-portal-integration/` is declared
  network-only and contains the Phase 3B Hall mapping/config foundation, the
  Phase 3D1 read-only Portal HTTP transport, the Phase 3D2 strict
  `PortalSnapshotValidator`, and the Phase 3E snapshot application/import
  (`PortalSyncService`, `PortalSyncLock`, `PortalSnapshotApplicationPlan`,
  `PortalSnapshotTransactionTransport`, `PortalSyncState`, `PortalSyncResult`);
  **Phase 3E is COMPLETE.** Phase 3F, the multi-Hall tenant-isolation proof, is
  COMPLETE. Phase 3G, manual Hall-scoped synchronization, adds `PortalSyncAdmin`
  (hall-local `Portal Sync` page plus the authenticated
  `admin_post_bc_portal_sync` handler) and is **COMPLETE** — manual
  synchronization only, with no REST route, cron event, scheduler or webhook
  for sync. SetupReadiness is implemented (**199/199 PASS**). ADR-042 / BR-076
  decide automatic Commerce enable/suspend provisioning; the provisioning
  persistence primitives now exist in code (`ProvisioningCanonicalRequest`,
  `ProvisioningOperationRepository`, `ProvisioningMutex`, and the v2 migration),
  and the machine endpoint, the platform provisioning filter, automatic site
  creation and the orchestration that ties them together are now implemented
  too — in `ProvisioningRestController`, `ProvisioningOrchestrator`,
  `PlatformGateway`, `ProvisioningResponse`, `ProvisioningAudit` and the new
  network-only `wp-content/plugins/bingocommerce-platform/`.
  `bingocommerce-platform` is network-active on this local environment
  (read-only check of `active_sitewide_plugins`, 2026-09-29; earlier entries
  recorded it as inactive). The Portal Global
  Admin Enable, Suspend, Re-enable and Retry Ecommerce controls are
  implemented and tested in the Laravel Portal repository. A
  user-performed local-development Open Store → Suspend → Re-enable
  workflow passed against one mapped WordPress multisite test site, and
  re-enable reused the same site. SSO does not exist.
  **`bingocommerce-portal-integration` is network-active on this local
  environment** — as is `bingocommerce-platform`, as stated above — and the
  ADR-036 Portal constants are configured in the git-ignored `wp-config.php` for
  ongoing manual synchronization; both are local test-environment state, not a
  deployment. `bingocommerce-portal-integration` also carries
  `ProvisioningMigrations` and
  `HallCommerceState` (Commerce-state persistence;
  `ProvisioningMigrations::DB_VERSION` is **2**, carrying the ADR-042
  operations table) and `HallCommerceAccess` / `HallCommerceGate` (Commerce
  access enforcement). Earlier entries recorded the provisioning migration as
  not applied here and every development Hall as legacy. A read-only check on
  2026-09-30 found otherwise: the network option
  `bc_portal_provisioning_db_version` = `2`; `bc_bingo_hall_commerce_state` and
  `bc_bingo_portal_provisioning_operations` both exist (created
  2026-09-14 12:01:43) with three rows each; Halls 868, 869 and 870 carry the
  `bc_commerce_managed` marker and an `operational` Commerce state; the main
  site (1) and Halls 2–4 have neither. The gate IS registered on
  `plugins_loaded`; it allows Halls 2–4 as legacy (`HallCommerceAccess`
  `legacy`) and 868–870 by their `operational` state, so all six Halls are
  currently allowed
- **Catalog schema:** current repository per-site `bc_catalog_db_version` = **6**
  (`bingo_game_types`, `bingo_play_products`, `bingo_packages`,
  `bingo_package_components`, `bingo_package_addons`); v6 adds only the approved
  add-on schema/verifier. Real Halls were not migrated in this pass.
  v4 added nullable, unique
  `bingo_game_types.portal_game_type_id`, and v5 is implemented and tested as
  the Portal-compatible Game Type name/index and imported-code design
- **Session schema:** current repository `bc_sessions_db_version` = **3**
  (`bingo_sessions`, `bingo_session_packages`,
  `bingo_portal_session_templates`, `bingo_session_schedules`,
  `bingo_session_schedule_dates`, `bingo_session_schedule_packages`); v2 added
  the Portal Session-template replica and v3 adds the non-destructive
  Commerce-schedule definition and generated-occurrence fields under approved
  ADR-043. This is repository state, not a production-deployment claim.
- **Current Catalog/Session inventory:** **eleven implemented tables** — five
  Catalog v6 tables and six Session v3 tables. ADR-044 Slice 1 implements the
  add-on migration/schema verifier and Slice 2 implements its backend service,
  fixed mutations, integrity extensions, post-commit audit/projection and
  targeted reconciliation. Slice 3 implements Package administration UI and
  request handling. The BR-081 customer cart backend and BR-082 electronic-limit
  checks at cart addition and classic/Store API checkout are implemented; the
  BingoThemev1 storefront foundation and core Store Details are implemented
  (ADR-045 / BR-084), as is the homepage Session calendar with informational
  Package display (2026-10-01); add-on selection and purchase actions remain
  pending. No real Hall migration or deployment is claimed.
- **Rule for AI agents:** Read `AGENTS.md`, `docs/architecture/plugin-architecture.md`, and this file before coding. Do not re-scaffold Multisite, WooCommerce, or core bootstrap.

### Current checkpoint — user-reported commit `2f886fc` (2026-09-28)

BR-064's Catalog-owned Network Admin platform-maximum settings and Hall-local
override settings, including explicit override removal, are committed. The Hall
screen also reports when a saved override exceeds the platform maximum and
shows the actual saved and effective values. This records repository state only;
it does not claim production deployment or restoration/verification of live
Hall database values.

### Current checkpoint — BingoThemev1 foundation + Store Details, branch `bingotheme`, uncommitted (2026-09-29)

**Implemented code (ADR-045 / BR-084).**
- `wp-content/themes/bingocommerce-bingothemev1/`: classic PHP theme
  "BingoThemev1" with fixed header/footer, native custom logo, `primary`
  (two levels) and `footer` (one level) menu locations, account link, live
  cart count, Store Details display, accessible off-canvas mobile menu, and
  native WooCommerce output with no WooCommerce template overrides (wrapper and
  sidebar swapped through hooks only). No `theme.json`, block templates,
  template parts, editor styles, widget areas or WooCommerce product-grid/image
  defaults. Inter and Baloo 2 Latin variable WOFF2 files are bundled from
  `@fontsource-variable/inter@5.3.0` and `@fontsource-variable/baloo-2@5.3.0`
  with their OFL licence files. The front page is a first-slice foundation only:
  Hall name, Shop link and Store Details contact; no Session, Package or
  merchandising sections.
- `bingocommerce-core`: `StoreDetails` (per-site `bc_store_details`, validation,
  `tel:` link with leading `+` kept, read-only `bingocommerce_store_details`
  filter) and `StoreDetailsAdmin` (Appearance → Store Details, existing
  `bc_manage_hall_settings`, Hall-scoped nonce, allow-listed POST,
  all-or-nothing validation, per-field messages). Registered in `Plugin.php`.
- `tests/store-details.php`: isolated-fixture harness.

**Tests actually run.** `BC_TEST_DB=bingocommerce_state_recon php
tests/store-details.php` → **204 passed, 0 failed** (validation matrix, optional
blanks, trimming, `tel:` links, escaping, nonce/request refusal, Hall Manager
and Super Admin allowed, Game Operator/subscriber/site administrator/guest
denied, WooCommerce address and `blogname` untouched, malformed storage
reported not repaired, Hall A/Hall B isolation and nonce non-reuse, persistent
fixture Halls byte-identical). Regression: `tests/customer-electronic-limits.php`
→ **679 passed, 0 failed**. `php -l` clean on all changed PHP.

**Local browser/environment checks actually run (Star Bingo Hall 869, Orlando 4).**
Desktop 1280, tablet 768 and mobile 375 layouts without horizontal overflow;
keyboard order, skip link, visible focus, desktop dropdown via keyboard, mobile
menu open/Escape/focus return; test Hall Manager saw Appearance → Themes
(active theme only), Patterns, Customize (Site Identity logo only, Menus,
WooCommerce), Fonts, Menus and Store Details, with no Editor/Design, Widgets,
Additional CSS or colour/header controls, and Site Editor template routes
reported unsupported; Store Details validation errors and save; logo and menu
edits rendered; Orlando showed none of 869's details; the header cart count
updated without a page reload after the shop-archive AJAX add and after Cart
block increase, decrease and removal; the product-page Add to cart used
WooCommerce's normal form submission and page reload, after which the count
showed the new total; WooCommerce notices visible, including the existing
BR-081 refusal when a Package is added from its native product page. (That
pass listed Appearance → Fonts but did not establish what it could change; see
the correction pass below.)
Theme activation, switch back to Twenty Twenty-Five and re-activation were
compared value-by-value: only theme bookkeeping changed (`template`,
`stylesheet`, `current_theme`, `theme_switched`, `sidebars_widgets` layout,
both themes' `theme_mods_*`, and the network theme-pattern cache); logo, menu,
Store Details, products, Catalog/Session rows, users and other Halls were
preserved. WooCommerce email colours and catalog settings did not change.

**Intentional local test changes (not deployment).** BingoThemev1
network-enabled; activated on 869 and 4. Test network users
`bingotheme-test-manager` (Hall Manager on 869 and 4 through
`HallStaffService`, audit rows recorded) and `bingotheme-test-customer`
(customer on 869 and 4). Test product 32 "BingoThemev1 test merchandise
(review only)" on 869. On 869 the test Hall Manager saved Store Details,
created menu "Main menu (test)" (primary location) and selected existing media
17 as the logo. `woocommerce_private_link` was set to `yes` on 869 and 4 for
signed-out checks and restored to `no`. A temporary child menu item used for
the dropdown check was removed.

**Deferred, not passed.** Digital-ticket order/QR browser checks (plugin not
network-active locally; no orders). Automatic BingoThemev1 assignment for new
Halls (required before the storefront is complete) and rollout to existing
Halls. Session → Package → add-on screens, customer listing reads and Bingo
cart-line labels. Native product screens and the empty-cart "New in store"
block still show Add to cart for Package and Play Product shells, which BR-081
backend enforcement refuses; the shopping-screen slice must present Bingo
products through the approved flow. A theme preview image (`screenshot.png`,
1024×1024) was added by the user on 2026-09-30; WordPress displays theme
previews at 1200×900, so a square image is cropped on the Themes screen.

### Correction pass — BingoThemev1 slice review items, branch `bingotheme`, uncommitted (2026-09-30)

**Fixed.**
- *Hall-specific Store Details in the theme:* `Template::details()` kept a
  static copy without a site key, so after `switch_to_blog()` it returned the
  first Hall's details (reproduced by the new regression test before the fix).
  The cache was removed; the core filter already reads an autoloaded per-site
  option.
- *Narrow-phone header:* reproduced in the browser before the fix. A 600×80
  (7.5:1) logo with a saved phone pushed the menu button to 334px at a 320px
  viewport; a long Hall name with no logo stayed on one line and pushed all
  four controls past 470px at 320px and to 623px at 375px. The logo may now
  shrink (images scale proportionally; a long name wraps to at most two lines
  in the header and fully in the footer) while phone, account, cart and menu
  keep their size, with an 8px gap and 36px controls at 360px and below.
- *Hall Manager fonts/styles:* a prohibited change was possible. WordPress
  7.0.3 renders user global styles over this classic theme (read-only
  in-memory probe on 869), writes map to `edit_theme_options`, and a Star Bingo
  Hall Manager's Appearance → Fonts visit saved that Hall's global styles on
  2026-09-29 (`POST /wp/v2/global-styles/38`; only Font Library font
  availability was saved — Cardo and WooCommerce's Inter — no theme styles).
  New core `StorefrontStyleGuard`: on Hall sites only Super Admins may edit,
  delete or publish `wp_global_styles`. No role or `edit_theme_options` change.
  Star's existing post 38 content was left as saved.
- *Documentation:* product-page Add to cart described as a form submission
  with reload; provisioning/legacy Environment Notes corrected from a dated
  read-only check; core-ownership wording acknowledges ADR-045; requirements
  no longer call the implemented Enable/Suspend/Re-enable path future work;
  ADR-045/BR-084 record the style guard; Sunrise recorded as likely
  user-initiated.

**Tests actually run (isolated `bingocommerce_state_recon` fixture).**
New `tests/bingothemev1-storefront.php` → **41 passed, 0 failed** (12 of its
checks failed against the pre-fix code, confirming it covers both defects:
real theme helper Hall A → B → A in one request and blank on the main site;
Hall Manager global-styles edit/delete refused, REST update and font
activation 403, read allowed, storefront stylesheet unaffected, Super Admin
still allowed, Game Operator and other-Hall Manager refused, Hall Manager keeps
`edit_theme_options`, Customizer and page editing). Regression:
`store-details` 204/0, `customer-electronic-limits` 679/0,
`package-addons-admin` 350/0, `customer-cart-increment-1` 140/0,
`setup-readiness` 199/199, `portal-sync-admin` exit 0 with 469 passes and no
failures. `hall-commerce-access` did not run: its harness guard stops before
any mutation because this fixture already has Hall-mapping schema (fixture
precondition, unrelated to this change). `php -l` and `git diff --check` clean.

**Browser checks actually run (Star 869, Orlando 4).** 320px and 375px header
cases above, before and after the fix; keyboard order and mobile menu
open/Escape/focus return at 320px; footer wrapping; tablet 768px and desktop
1280px spot checks with no overlap or overflow. As the test Hall Manager on
869: the Fonts screen loads but its edit-context global-styles read and a real
save attempt return 403 `rest_cannot_edit`; Menus shows the Hall's menu with
Save available; the Customizer shows only Site Identity (logo), Menus and
WooCommerce; the Sample Page opens in the block editor as editable with no
error notices (nothing saved).

**Temporary environment changes and restoration.** Orlando's `blogname` was
temporarily set to a long name and a phone-only `bc_store_details` was added
for the header check; the exact originals ("Orland Bingo Hall", option absent)
were restored and a value-level comparison showed no remaining option
difference. The wide-logo case used an in-browser image swap only (no Hall
data changed). `woocommerce_private_link` remains `yes` on 869 and 4 at the
user's request for manual testing; it is to be restored to `no` after the user
confirms testing is finished.

**User-reported manual results (not run by the agent).** Star Bingo Hall
Store Details saved and displayed correctly; Sunrise displayed none of Star's
details; an ordinary product appeared on Star's Shop page; ordinary-product
add, quantity increase/decrease, removal and the header count returning to 0
worked; checkout showed matching quantities and totals; checkout showed no
payment methods because none is configured, so payment and completed-order
behaviour remain unverified; direct Paper/Digital Bingo product adds were
refused. The Session → Package → add-on screens remain pending, and those
refusals must not be "fixed" by bypassing backend validation.

**Still deferred.** Payment setup, Digital Tickets activation/table creation,
order/QR verification, automatic theme assignment for new Halls, and the
customer selection screens.

### User-requested footer and contact changes, branch `bingotheme`, uncommitted (2026-09-30)

- **Social icons:** only networks with a saved URL are shown; with none saved,
  no icon row is rendered (replaces the earlier inert `#` links; BR-084
  updated). The Store Details help text now says a blank link hides its icon.
- **Contact layout** (front-page "Visit us" and footer): the address is one
  line (parts joined with commas), then phone and email share the next line.
  Verified in the browser on Star: one line each at 1280px, 768px and 375px;
  at 320px phone and email still share a line, but Star's 42-character
  address wraps its last word ("Canada") because it is wider than the screen
  at a readable size.
- **Privacy Policy / Terms of Service:** native Footer menu only (user
  decision). The location is now labelled "Footer links, bottom right (e.g.
  Privacy Policy, Terms of Service)"; Hall Managers set each link's text, URL
  and order in Appearance → Menus. Links sit at the right of the copyright
  line on desktop and tablet and are centred below it on phones. Verified with
  a temporary two-link footer menu on Star, which was then deleted and Star's
  saved theme settings restored exactly (value-level comparison: no remaining
  difference on the site).
- **Tests actually run:** `tests/bingothemev1-storefront.php` extended →
  **52 passed, 0 failed** (icons only for saved links, no `#` placeholders,
  address row before a single phone/email row, nothing rendered without Store
  Details); `tests/store-details.php` 204/0.

### Foundation cleanup — Fonts menu, core wording, homepage rule, branch `bingotheme`, uncommitted (2026-09-30)

- **Fonts menu:** WordPress 7.0.3 registers Appearance → Fonts as
  `$submenu['themes.php'][9]` (`font-library.php`, `edit_theme_options`;
  `wp-admin/menu.php:240`), and the Themes screen builds its action buttons
  from the same submenu. Core's `StorefrontStyleGuard` now also calls
  `remove_submenu_page( 'themes.php', 'font-library.php' )` on `admin_menu`
  (priority 999) for the same users whose global-style writes it refuses:
  everyone except Super Admins, on Hall sites. The server-side refusal is
  unchanged and still applies to a direct visit of the Fonts page. No role,
  `edit_theme_options` or core file changed.
- **Documentation:** `catalog-session-persistence-design.md` core ownership now
  states ADR-045's Store Details exception and the storefront capability
  policy; ADR-045, BR-084, requirements, AGENTS.md and plugin architecture
  record the Fonts removal and the confirmed homepage rule.
- **Homepage rule recorded (user-confirmed):** Hall Managers may edit homepage
  text, images and map location; they cannot move or reorder homepage
  sections; header/footer structure, theme fonts and colours stay fixed; menu
  links and their order remain editable separately; the public storefront
  location is Hall-local and separate from Portal Hall identity and
  WooCommerce's business/tax address. **Not implemented:** the remaining
  homepage sections, the map and the homepage editing controls; no section
  design, map provider or editing mechanism has been chosen.
- **Tests actually run (isolated fixture):** `bingothemev1-storefront` 59/0
  (adds: WordPress Fonts registration drift guard; hook registered at 999;
  Hall Manager loses only Fonts while Themes, Customize, Menus and Store
  Details stay; Super Admin keeps Fonts; main network site unrestricted),
  `store-details` 204/0, `customer-electronic-limits` 679/0,
  `package-addons-admin` 350/0, `customer-cart-increment-1` 140/0,
  `setup-readiness` 199/199. `php -l` and `git diff --check` clean.
- **Browser (Star 869, test Hall Manager):** Fonts absent from Appearance and
  from the Themes-screen buttons; Menus, Customize (logo) and Store Details
  load with their editing controls; a direct visit to the Fonts page still
  opens and a real global-styles save from it returns 403 `rest_cannot_edit`.
  Super Admin keeping Fonts was verified in the fixture only (no Super Admin
  browser session is available to the agent).

### Homepage slider — branch `bingotheme`, uncommitted (2026-09-30)

- Implemented the approved slider only. Core owns versioned, non-autoloaded
  Hall-local `bc_homepage_slider`, the read-only `bingocommerce_homepage_slider`
  filter and Appearance → Homepage. Existing Hall settings capability, a
  Hall-specific nonce, strict complete-form validation and current-site media
  resolution protect saves. Invalid/truncated submissions preserve saved data.
- Hall Managers can add, remove and arrange up to six slides using native Media
  Library images, required headings (80 characters), optional supporting text
  (160), two optional complete label/HTTP(S)-destination pairs (32/2,048), and
  the two reference framing presets. Buttons only navigate; action URLs are
  refused. No sample content or images were imported into any existing Hall.
- BingoThemev1 renders the slider at the original banner position with responsive
  cover images, separate escaped content, reference desktop/mobile spacing,
  arrows/dots and six-second autoplay / 0.6-second fade. Pause/Resume, keyboard
  operation, focus/hover/visibility handling, reduced motion, inactive-slide
  exclusion and no-JavaScript rendering are included. Small fixed navy text
  shadows aid readability; chosen Hall artwork still needs contrast review.
- Zero usable slides retain the original banner; one slide omits controls.
  Missing/deleted/unresolvable attachments are skipped with admin warnings;
  browser image-load errors retain content on the navy background. Reads never
  mutate saved settings. Header, footer, existing contact section, font assets,
  colour tokens and the header/cart JavaScript are unchanged. Map, categories,
  Session selection and other homepage sections remain pending.
- ADR-045 / BR-084 configuration ownership is recorded as a dated addition to
  DECISIONS.md. Historical decision text was not rewritten. Current requirements,
  plugin boundaries and AGENTS.md reflect the slider's scope.
- Verification: guarded `bingocommerce_state_recon` disposable-fixture suites:
  `homepage-slider.php` 222 passed / 0 failed; `store-details.php` 204 / 0;
  `bingothemev1-storefront.php` 61 / 0. Includes the actual native WordPress Media
  Library query handler, overlapping attachment IDs, A → B → A reads, capability
  and nonce refusal, invalid saves, deletion and zero/one/multiple rendering.
  Owned Halls/users were cleaned up; persistent fixture Halls were unchanged.
- `homepage-slider-browser.cjs`: 59 passed / 0 failed in local Chrome, using
  static PHP fixture output with all browser requests intercepted. Checked six
  widths (1440/1024/768/640/390/320), long/enlarged text, both crops, autoplay,
  Pause/Resume, focus, hover, keyboard, document visibility, reduced motion,
  broken images, no JavaScript, and admin add/remove/reorder. Desktop/mobile
  screenshots were visually compared with the read-only original HTML using
  local fonts. The form's media-frame selection uses a test double; the native
  query handler is independently covered by the PHP fixture.
- Remaining manual checks: complete native wp-admin Media Library modal flow,
  actual screen-reader announcements, Safari/Firefox/physical mobile devices,
  and contrast/cropping for each Hall's chosen artwork. No existing-Hall admin
  settings were changed for browser testing. No production-readiness claim.
- PHP syntax (8 changed/new PHP files), JavaScript syntax (3 changed/new scripts)
  and whitespace checks passed. The complete tracked/new-file diff and test logs
  are in `/private/tmp/bingocommerce-slider-review/`. No staging, commit, push
  or deployment was performed; HEAD remains
  `6c03d2f4fafe73b7fb32ec66efcb975fe00c0233`.

### Homepage map — branch `bingotheme`, uncommitted (2026-09-30)

- Implemented only the Visit Us map, starting from clean HEAD
  `0167e4b6abe319939f9b490fa3027be3327b507f`. The committed slider/fallback,
  contact helper, header/footer, fonts and colour tokens are preserved.
- The theme derives `https://www.google.com/maps?q={encoded address}&output=embed`
  from the current Hall's `bingocommerce_store_details` filter on every render:
  address line 1, address line 2, city, state, postal code, country; blanks are
  omitted and the query value is encoded once. A nonblank address line 1 is
  required. Missing Core or address omits the map; available contacts remain.
  The final URL and Hall-specific iframe title are escaped at output.
- Existing Appearance → Store Details storage, validation and permissions are
  reused without changes. There are no new map settings, options, tables,
  plugins, API keys, JavaScript libraries or geocoding services; WooCommerce's
  tax/shipping address and Portal Hall records are not read. No configuration
  boundary extension is needed. Historical decisions remain unchanged.
- The map sits outside the constrained contact container, below Visit Us and
  before the footer. It matches the read-only reference's 520px height, 380px
  at widths ≤640px, 48px contact-to-map spacing and top border, using 100% width
  to avoid viewport-width overflow. Contact typography/layout remain intact.
  The iframe is lazy-loaded with `no-referrer-when-downgrade`.
- Reference inspected: `bingocommer-frontendstore/front-page.html` lines 226–249
  and `assets/css/style.css` lines 84–86, 396–407 and 920–927. No supplied map
  screenshot was available. Local desktop/mobile previews were compared with
  these actual reference files; Google responses were replaced/refused.
- Guarded `bingocommerce_state_recon` fixture results: storefront 106 passed /
  0 failed; Store Details 204 / 0; homepage slider 222 / 0. Tests include
  address-derived iframe URLs and escaping, UTF-8/punctuation encoding, blank
  field/street omission, Core absence, contact preservation, address updates
  and A → B → A isolation. Owned disposable Halls/users were removed and
  persistent fixture Halls 2/3/4 were unchanged. No existing development Hall
  settings were edited.
- Browser results: map 34 passed / 0 failed at 1440/1024/768/641/640/390/320px;
  existing slider browser suite 59 / 0. Map checks used static fixture-rendered
  HTML with every browser request intercepted: reference sizing, full width,
  no overflow, blocked-Google contact visibility/keyboard focus, omission and
  no-JavaScript markup. This verifies generated iframe markup/layout, not a
  successfully loaded live Google map or the accuracy of Google's location.
- PHP syntax checks passed for all three changed PHP files; `git diff --check`
  passed. Logs, static previews, browser checker/screenshots and the complete
  diff are in `/private/tmp/bingocommerce-map-review/`.
- Remaining manual checks: in Hall A, change Appearance → Store Details and
  reload its uncached homepage; verify the live Google location, then verify
  Hall B remains unchanged and return to A. Clear A's address line 1 and check
  that its map disappears while phone/email remain. Check a desktop/mobile
  browser and keyboard access. Live Google loading, a complete wp-admin browser
  save, Safari/Firefox, screen readers and physical devices were not tested.
- Shop by Category, Session selection and other homepage components remain
  pending. Changes are unstaged/uncommitted; no push or deployment performed.

### Shop by Category — branch `bingotheme`, uncommitted (2026-10-01)

- Implemented only the homepage category section from clean HEAD
  `2dea66ee37cf4677e2d75c77a21bd66ed9eaaaf8`. Slider/banner and Visit Us/map
  behavior/settings are preserved. No existing development Hall settings or
  media were changed, and no sample cards/images were imported.
- Core owns version 1 of non-autoloaded Hall-local `bc_homepage_categories`,
  the `bingocommerce_homepage_categories` read filter, and a separate Shop by
  Category form/save action in Appearance → Homepage. The existing Hall settings
  capability is reused with its own Hall-specific nonce and form field. Slider
  and category POSTs/notices are independent; mixed or truncated payloads fail
  without changing either configuration. Count/completion markers distinguish
  an explicit empty list from missing data. Tenant selectors are rejected.
- Managers can add/edit/remove/order up to five cards, choose/remove the section
  background independently of required main artwork, and supply
  optional plain text (80 characters). Each card has one destination: a full
  HTTP(S) navigation URL under the unchanged homepage URL policy (2,048
  characters), or a current-Hall `product_cat` ID including empty categories.
  No taxonomy records or product assignments are modified. Clearing configured
  image references does not delete media attachments.
- Core resolves media, current category links and accessible names for the
  current Hall on every read. Names use visible text, category name, then
  explicit main-image Alt Text, with no filename fallback. Invalid saves keep
  saved settings. Deleted backgrounds fall back to theme styling; unavailable
  main images, destinations or names omit cards with administrator warnings.
  No usable cards hides the section; reads never rewrite saved data.
- The theme inserts the section below slider/banner and before Visit Us/map.
  Four/two/one-column reference proportions, centered smaller selections and
  incomplete rows, floating arrows, visible keyboard focus and contained
  transparent main artwork are implemented. Extra row space keeps arrow hit
  areas clear. Optional backgrounds are separate decorative layers. Existing
  font and colour tokens remain unchanged; no storefront JavaScript/carousel
  is required. Theme controls the heading and decorative presentation.
- Read-only reference reviewed: `front-page.html` lines 67–109;
  `assets/css/style.css` lines 253–286, 887–891, 920–922 and 973–975;
  `assets/js/main.js` reveal/boot lines 546–560 and 875–895; and
  `assets/images/card-today.webp`, `card-sessions.webp`, `card-novelties.webp`,
  `card-supplies.webp` (all 1122 × 1402) and `category-bg.webp` (1959 × 803).
  Card artwork contains baked-in text. No supplied category screenshot was
  available for the initial implementation; the supplied screenshot was reviewed
  for the subsequent background/text-spacing fix below. Desktop/mobile fixture
  previews were compared with the original HTML/CSS/artwork using local theme
  fonts; reference files remain untouched.
- Guarded `bingocommerce_state_recon` fixture results: category suite 257 passed /
  0 failed; slider 222 / 0; Store Details 204 / 0; storefront/map 106 / 0.
  Includes 0–5 cards, both destinations, optional layers/text, invalid/truncated
  saves, independent forms/notices, permissions/nonces, deleted references,
  accessible-name fallbacks, overlapping media/category IDs and A → B → A reads.
  Native WordPress Media Library query handlers are exercised. Disposable
  Halls/users were cleaned up; persistent fixture Halls 2/3/4 were unchanged.
- Chrome static-fixture browser results: categories 148 passed / 0 failed;
  slider 59 / 0; map 34 / 0. Category layouts cover 1440/1024/768/640/390/320px,
  fully decoded artwork/background proportions, keyboard add/remove/reorder,
  image selection/removal, exclusive destinations, focus, reduced motion and
  no-JavaScript storefront output. All requests were intercepted. Native media
  modal selection uses a test double; reference image URLs/metadata are adapted
  only in the browser harness. Google map responses were stubbed/refused.
- PHP syntax passed for all 10 changed/new PHP files; JavaScript syntax passed
  for the two new scripts; whitespace checks passed for tracked and new files.
  Logs, screenshots, static fixture output, review manifest and complete diff
  are in `/private/tmp/bingocommerce-categories-review/`.
- Remaining manual checks: complete native wp-admin media modal and save flow;
  chosen Hall artwork/contrast; actual WooCommerce category archive navigation;
  Safari/Firefox, screen readers and physical devices. Live Google loading was
  not tested. Use separate Halls to confirm independent cards and unchanged
  slider/map settings, then remove all cards and confirm the section hides.
- ADR-045/BR-084 ownership is recorded as a dated addition; historical decisions
  remain unchanged. Session selection and other homepage components remain
  pending. Changes remain unstaged/uncommitted; no push or deployment occurred.

#### Category coverage and label-spacing follow-up (2026-10-01)

- The section background now fills the section with `inset: 0`, `height: 100%`
  and `object-fit: cover`, including the final card row and arrows. Main artwork
  still uses `contain`; individual card-background presentation is unchanged.
  Cards with visible text now keep a 12px gap between the text panel and arrow,
  including wrapped labels. Image-only arrow placement is unchanged.
- Removed the optional per-card background picker as requested. Existing
  per-card background IDs remain in hidden form fields and survive saving and
  reordering; new cards start without one. The section-background and main-image
  selectors remain available. No stored configuration was migrated or cleared.
- Fresh guarded fixture results: categories 263 passed / 0 failed; slider
  222 / 0; Store Details 204 / 0; storefront/map 106 / 0. Native media queries,
  Hall isolation, invalid saves and independent homepage forms still pass.
  Disposable actors/Halls were removed; persistent fixture Halls 2/3/4 remained
  unchanged. Existing development Hall settings were not changed.
- Chrome categories: 224 passed / 0 failed; slider: 59 / 0; map: 34 / 0.
  One- and five-card fixtures include a real section-background element and a
  retained individual card background. Checks wait for every image to decode,
  then verify full section/last-row coverage, text/arrow clearance, no overflow
  and unchanged card image treatment at 1440/1024/768/640/390/320px. A temporary
  intercepted-CSS probe restoring the old rule fails the new coverage assertion
  as expected. Desktop/mobile previews were visually reviewed.
- These are static fixture browser checks: category HTML is freshly exported;
  slider/map markup reuses the prior guarded exports with current assets. All
  requests are intercepted, the native media modal is a test double, and Google
  is stubbed/refused. Reference artwork/metadata are substituted only in the
  harness. Live wp-admin/browser saves, live Google, Safari/Firefox, screen
  readers and physical devices were not tested in this follow-up.
- Changed PHP and JavaScript syntax checks and `git diff --check` pass. Focused
  diff, logs and one-/five-card desktop/mobile previews are in
  `/private/tmp/bingocommerce-category-coverage-review/`. Prior uncommitted
  implementation changes and historical decisions are preserved. Everything
  remains unstaged/uncommitted; no push or deployment occurred.

#### Mobile category sizing and Homepage admin tabs (2026-10-01)

- At viewport widths up to 640px, category cards now form a centered single
  column with a maximum width of 280px. Desktop/tablet sizing, contained main
  artwork, full-section background coverage and the 12px text/arrow gap remain
  intact. The one-column rule prevents two cards appearing at the 640px edge.
- Appearance → Homepage now uses WordPress's native `nav-tab-wrapper` /
  `nav-tab` styling for Homepage slider and Shop by Category. Only the selected
  editor renders. Native page links work with keyboard access and JavaScript
  disabled; slider is the default for missing/unknown tab values. As with other
  page-navigation settings tabs, save edits before leaving a tab.
- Each form has an explicit current-Hall action URL for its own tab. POST form
  identity, rather than the tab query, chooses the existing handler and returns
  its own success/errors and submitted values. Nonces, permissions, validation,
  storage and independent saves are unchanged. The category editor's former
  divider/top spacing was removed because it is no longer stacked below slider.
- Fresh guarded fixture results: categories 316 passed / 0 failed; slider
  222 / 0. Tab tests cover default/unknown/array values, read-only navigation,
  exactly one form, form actions, successful/invalid saves on a conflicting tab
  URL, submitted-value/error retention and preservation of both configurations.
  Existing Hall isolation/permissions/invalid-save checks pass. Owned fixtures
  were cleaned up; persistent fixture Halls 2/3/4 and development Halls are intact.
- Chrome static-fixture results: categories 255 passed / 0 failed; slider
  59 / 0; map 34 / 0. Mobile card caps are verified at 320/390/640px for 1–5
  cards; coverage, wrapping, text clearance and no overflow pass across all six
  widths through 1440px. Desktop reference image dimensions still match. Native
  tabs fit side by side at 390/1440px and work by keyboard and without JavaScript.
  Mobile storefront and desktop/mobile admin previews were visually reviewed.
- Browser requests are intercepted, category/slider markup is freshly exported,
  map markup reuses the prior guarded export, and WordPress admin styles are read
  from the local installation. Media selection uses a test double; Google is
  stubbed/refused. Live admin browser saves, Safari/Firefox, screen readers and
  physical devices were not tested. Unchanged Store Details/storefront PHP suites
  were not rerun in this follow-up; their prior results remain recorded above.
- PHP syntax for the three changed PHP files, category browser JavaScript syntax
  and `git diff --check` pass. Incremental diff, logs and previews are in
  `/private/tmp/bingocommerce-homepage-tabs-review/`. Prior uncommitted work and
  historical decisions are preserved; nothing was staged, committed, pushed or
  deployed, and no existing Hall settings were changed.

#### Legacy card-background recovery (2026-10-01)

- Added a removal-only button for retained per-card background IDs greater than
  zero. The individual card-background picker remains removed. Keyboard removal
  changes only that card's hidden background ID to zero, announces the pending
  change and keeps focus in the card. Storage changes only through Save category
  cards; no references are silently cleared on load and no settings are migrated.
- Warning/error links target a focusable recovery region. Links and help IDs
  remain correct after reordering, and the region stays usable after removal.
  Existing validation, nonce/capability checks and save handlers are unchanged.
  No Media Library attachment is deleted by the removal control or its save.
- New browser-backed regression runs within the guarded disposable PHP fixture:
  save valid backgrounds, delete one test attachment, submit the rendered form
  and confirm rejection, follow warning/error links, use Space to activate the
  removal button, and pass the browser's actual Save submission to the real PHP
  handler. Whole-configuration equality proves that only the selected background
  becomes zero; main image, text, destination, order and section background stay
  intact. Ordinary saves/reordering retain valid backgrounds. Explicitly removing
  a valid background preserves its attachment. Slider, Store Details and every
  table in the other owned Hall remain unchanged.
- `BC_CATEGORY_RECOVERY_BROWSER=1` enables this integrated coverage in
  `tests/homepage-categories.php`; set `BC_NODE_EXECUTABLE`,
  `BC_PLAYWRIGHT_MODULE` and `BC_CHROME_EXECUTABLE` for local runtimes. Without
  the flag the suite explicitly reports this coverage as skipped. The browser
  subprocess requires the same guarded fixture flag/database and cannot send
  network requests or open a database; it returns native form fields to the
  existing fixture process rather than constructing a replacement configuration.
- Actual results with integrated coverage enabled: category PHP/browser bridge
  383 passed / 0 failed (67 additional checks); existing category browser suite
  255 / 0; slider PHP 222 / 0. Owned disposable actors/Halls were cleaned up and
  persistent fixture Halls 2/3/4 were unchanged. Existing development Hall
  settings and attachments were not changed.
- PHP syntax for both changed PHP files, JavaScript syntax for the production
  editor and new browser helper, and whitespace checks pass. Mobile sizing,
  section coverage, text spacing and native admin-tab regressions pass in the
  existing browser suite. Theme/CSS, slider, map, storage/validation code and
  historical decisions remain unchanged in this follow-up.
- Limits: the recovery test uses a real Chrome DOM, production editor JavaScript,
  native form serialization and the real PHP handler, but intercepts submission
  instead of making a live wp-admin HTTP request. The media API guard and speech
  API are test doubles; native media-query isolation remains covered in PHP.
  Safari/Firefox, a screen reader and physical devices were not tested. Logs and
  the focused diff are in `/private/tmp/bingocommerce-legacy-background-recovery-review/`.
  All changes remain unstaged/uncommitted; no push or deployment occurred.

### Homepage Session calendar — branch `bingotheme`, uncommitted (2026-10-01)

- Started from clean HEAD `fa0e57e5d64184ae1688d94b22fd6ca01f052176`; implements the
  reviewed plan and its request/state corrections (ADR-045 / BR-084 addition
  2026-10-01; BR-052, BR-080). No schema, migration, route, rewrite rule,
  REST/AJAX endpoint, page, Manager control, sample content or Hall setting.
- Sessions: new read-only `HomepageSessionCalendar` on the
  `bingocommerce_homepage_session_calendar` filter, registered before the
  dependency gate so a missing dependency reads as unavailable. Per request, one
  bounded candidate query (active, Available for Sale non-zero, server UTC date
  −2…+32 days, cap 500) and `SessionEligibility::is_sellable()` alone per row;
  `ok`/`partial`/`unavailable` classification (a `false` plus a `WP_Error` is
  unavailable). Unregistered `bc_session`/`bc_date`/`bc_month` are validated
  before resolution exactly as reviewed; reachable months come from verified
  dates only; MM/DD/YYYY labels and links are built server-side. Packages for the
  verified Session: `CustomerPackageEligibility::is_available()`, then
  `is_post_publicly_viewable()` and `WC_Product::is_purchasable()`; catalog-hidden
  Packages may appear; no stock or electronic-limit listing filter; read failures
  are partial/unavailable; presentation fields only.
- BingoThemev1: section below Shop by Category and before Visit Us
  (`template-parts/homepage-session-calendar.php`, `homepage-session-packages.php`,
  `inc/session-calendar.php`); reference calendar and Package-card styling on the
  theme tokens (the selected date uses solid `--color-blue-dark` for white-digit
  contrast); optional front-page `assets/js/homepage-calendar.js`. A missing,
  unusable or unavailable provider keeps the heading with "Sessions can’t be
  shown right now."; omitted on the network main site.
- Guarded `bingocommerce_state_recon` results: `tests/homepage-session-calendar.php`
  **340 passed / 0 failed** with `BC_SESSION_CALENDAR_BROWSER=1` (337 / 0
  without the browser stage), two consecutive identical runs; its browser stage
  **94 passed / 0 failed** over 38 live php-cgi homepage requests in Chrome 154
  (Playwright 1.62.1). Regressions: Session availability 564, Session 352,
  schedules 159, schedule admin 447, reconciliation 271 (all PASS); customer
  cart 140/0; order recording 1098/0; entitlement snapshot 1330/0; storefront
  106/0; slider 222/0; categories 316/0; Store Details 204/0; slider browser
  59/0 and categories browser 255/0 on fresh exports. PHP/JS syntax, whitespace
  and control-byte checks pass. Disposable Halls/actors were removed and
  persistent fixture Halls 2/3/4 compared unchanged; no development Hall or
  media directory was touched.
- **Pre-existing, not caused by this work:** `tests/customer-electronic-limits.php`
  reports 611 passed / 68 failed; every failure is a Store API checkout case that
  reaches the fixture's 418 post-validation sentinel instead of the expected 409.
  The identical failure set reproduces on an unmodified HEAD copy with the
  installed WooCommerce 11.1.2 (updated 2026-09-30; earlier evidence is 11.0.1).
  Not investigated or changed here; recorded as a follow-up.
- Limits: the php-cgi worker switches to the Hall after the fixture boots, so
  HallCommerceGate's request decision is not exercised and asset URLs use the
  fixture network host; browser documents are php-cgi responses routed by
  Playwright (no web server); fixture media are metadata-only attachments
  answered with one reference image; the three-month browser Hall uses a
  synthetic eligibility result with real request parsing, resolution and
  rendering. WordPress core's canonical redirect turns `?bc_date=` into
  `?bc_date` and `?bc_date[]=…` into `?bc_date%5B0%5D=…`; both are still
  rejected. Fixture prices show no currency symbol because WooCommerce's
  installer is not run there. The Catalog-absent guard is not testable
  in-process. Safari/Firefox, screen readers, physical devices and live
  development Halls were not tested.
- Frontend follow-up (same day, presentation only; backend, request rules and
  Package behaviour unchanged): (1) any explicit date click, including the
  selected Session's own date, now clears the Session row's selection and hides
  the whole Package block; the address becomes the date link's date-only URL and
  Packages return only via a Session's "View Packages" link. (2) Month arrows
  re-word a still-visible month-only message from the server-rendered
  highlighted dates of the month shown (both translated messages are rendered;
  partial results keep "could be confirmed"); recovery, stale, mismatch and
  requested-date messages are untouched. (3) Package cards use one column at
  ≤640px. Re-run: calendar harness **355 / 0** with the browser stage (350 / 0
  without), browser stage **183 / 0** over 56 php-cgi requests (adds a guarded
  partial three-month Hall); storefront 106/0, slider 222/0, categories 316/0,
  Store Details 204/0, slider browser 59/0, categories browser 255/0.
  `customer-electronic-limits.php` is still 611 / 68 failed, with a failure set
  identical to the HEAD baseline.
- Nothing was staged, committed, pushed or deployed.

### Electronic-limit checkout fixture ordering — branch `bingotheme`, uncommitted (2026-10-01)

- Cause of the 611 / 68 result recorded above (kept as history): a test-fixture
  ordering issue, not missing enforcement. `tests/customer-electronic-limits.php`
  stopped Store API checkout with an intentional 418 on
  `woocommerce_store_api_checkout_update_customer_from_request`. WooCommerce
  11.0.1 fired that action after `CartController::validate_cart()`; since 11.1.0
  (upstream PR #67773) `Checkout::process_order()` updates the customer first, so
  on installed WooCommerce **11.1.2** the stop ran before BingoCommerce's
  priority-100 `woocommerce_store_api_cart_errors` validator. All 68 failures were
  the 25 expected-rejection Store API cases (stop reached / 418 instead of 409,
  plus 18 configuration-message checks). Classic checkout and add-to-cart checks
  passed, and a diagnostic trace showed rejected carts receive WooCommerce's
  genuine 409 with BingoCommerce's messages once validation runs.
- Test-only correction: the Store API stop now runs on
  `woocommerce_store_api_cart_errors` at `PHP_INT_MAX` and is removed with the same
  hook and priority. It returns without throwing when errors exist (WooCommerce
  then returns its own 409); otherwise it marks the stop reached and throws the
  existing 418, after BingoCommerce's cart-error checks and before later
  WooCommerce validation and order creation. No complete successful checkout is
  exercised. Assertions are unchanged; no production code, business rule,
  dependency or Hall setting changed.
- Guarded `bingocommerce_state_recon` result:
  `BC_TEST_DB=bingocommerce_state_recon php tests/customer-electronic-limits.php`
  → **679 passed, 0 failed** (exit 0), with the same 679 assertion labels as the
  failing run; disposable Halls/tables removed, network setting restored,
  persistent fixture Halls 2/3/4 unchanged. `php -l` and `git diff --check`
  clean. WooCommerce 11.0.1 itself was not re-run (its ordering is from upstream
  source).
- Nothing was staged, committed, pushed or deployed.

### Homepage purchase step — branch `bingotheme`, uncommitted (2026-10-02)

- Started from clean HEAD `515e799`; implements the reviewed purchase plan and its
  corrections (ADR-045 / BR-084 addition 2026-10-02). Flow: verified dated
  Session → one Package (native radio group, nothing preselected, quantity one)
  → optional eligible add-ons (only the chosen Package's panel; native
  checkboxes, labelled quantities 1…relationship maximum) → WooCommerce's
  existing Store API cart-extension route, validated again by `CustomerCart`.
  No new production route, cart service, schema, setting, checkout, order,
  refund or entitlement change.
- Catalog: anonymous `CustomerCartCatalog::addons()` composes the existing
  `addon()` predicate. Sessions: per-Package `addons` (relationship ID, name,
  image, maximum, relationship price formatted by WooCommerce from an in-memory
  copy, never `get_price_html()`), ok/partial/unavailable, plus `purchase`
  (current Hall endpoint, cart read, namespace, Store API nonce, cart URL).
  BingoThemev1: validated data, hidden/disabled form markup, summary without a
  browser total, and the optional `assets/js/homepage-purchase.js` (captured
  JSON, one in-flight guard, exact-selection success check, one retry only after
  a confirmed 401/403 Store API nonce rejection, header refresh through the
  existing `wc-blocks_added_to_cart` path). Existing server messages are shown
  as text; uncertain outcomes say "We couldn't confirm your selection was
  added. Check your cart before trying again."
- Found and fixed during browser verification: the first nonce check treated a
  200 response as a nonce rejection (undefined === undefined) and retried.
- Guarded `bingocommerce_state_recon` results: new
  `tests/homepage-session-purchase.php` **141 passed / 0 failed** with
  `BC_SESSION_PURCHASE_BROWSER=1` (138 / 0 without it; browser stage **197 / 0** over 129 live
  php-cgi requests that boot each disposable Hall directly, real JSON POSTs,
  normal nonce checks and real Set-Cookie headers; cart page = classic
  `[woocommerce_cart]` shortcode, Cart block not exercised, no checkout run).
  Regressions: calendar 358/0 with browser (browser stage 185/0) and 353/0
  without; customer cart 140/0; electronic limits 679/0; order recording
  1098/0; storefront 106/0; Store Details 204/0; slider 222/0 and browser 59/0;
  categories 316/0 and browser 255/0. PHP/JS syntax and whitespace checks pass.
  Disposable Halls (sessions tables included) removed; network electronic-limit
  setting restored; main-site WooCommerce session table and persistent fixture
  Halls 2/3/4 unchanged.
- Observed, not changed: a native WooCommerce add refusal inside
  `CustomerCart::add()` (e.g. insufficient stock) leaves WooCommerce's error
  notice in the guest session; the classic cart page later prints it, and until
  then Store API cart reads report it and Store API cart validation returns 409.
  Next presentation task: Bingo Session/date labels on cart lines.
- Review revision (same day, still uncommitted): compact add-on rows (checkbox,
  optional thumbnail, name, unit price; labelled quantity outside the label,
  enabled only while selected; "Quantity 1" for a maximum of one), the intro
  "Choose one Bingo Package. Add-ons are optional.", compact Package choices on
  mobile with the summary directly below the add-ons; translated theme wording
  for the known refusal codes (`bc_cart_package_limit` → the one-Package message
  with a prominent View cart; no stock wording from the generic
  `bc_cart_selection_invalid`); a strict success check against the existing
  Store API extension data (one consistent Package line, exactly the captured
  add-ons, no other Bingo line; merchandise allowed). The retained-notice side
  effect above was reproduced and corrected in `CustomerCart::add()`'s failed-add
  rollback: only notices appended during that failed attempt are dropped.
  Cart-page mode: HallSiteProvisioner's `WC_Install::install()` creates a Cart
  BLOCK page; verified with that Cart block (Hall A) and the classic shortcode
  (Hall B). Checkout was not exercised by these suites.
- Revision results (guarded `bingocommerce_state_recon`): purchase suite
  **151 / 0** with the browser stage (**306 / 0** over 217 php-cgi requests,
  first-time guests without seeding or cookie injection included) and 148 / 0
  without; calendar 358/0 with browser (browser stage 185/0) and 353/0 without;
  customer cart 140/0; electronic limits 679/0; order recording 1098/0; digital
  entitlement snapshot 1330/0; storefront 106/0; Store Details 204/0; slider
  222/0 and browser 59/0; categories 316/0 and browser 255/0. The main-site
  WooCommerce session table is now compared by row contents (unchanged).
- Observed, not changed (cart presentation, out of scope): WooCommerce's Cart
  block shows an add-on whose product has its own regular price as discounted
  ("Previous price $5.00 / Discounted price $3.00 / Save $2.00") because the
  relationship price is applied as the line price while the product's regular
  price stays. A first-time guest's lost first response leaves that first cart
  in a session the browser never received (until WooCommerce's guest-session
  expiry); a manual retry adds the selection once to a new session.
- Nothing was staged, committed, pushed or deployed.

### Purchase-step reference layout, Package contents, Shop listing and add-on prices — branch `bingotheme`, uncommitted (2026-10-02)

- Started from HEAD `515e799` plus the uncommitted purchase-step work above
  (23 paths, backed up before editing); ADR-045 / BR-084 same-day addition.
  No schema, endpoint, plugin, setting or migration added; request format,
  nonce retry, success check, rejection messages (except the digital-limit
  wording), calendar, slider, map, categories, header, footer and tokens
  unchanged.
- Layout (BingoThemev1, presentation only): the reference session page's
  structure — a narrower left column of compact Package choices (one radio
  group, nothing preselected, placeholder tile when a Package has no image)
  and a wider right column with a "Selected Package" panel, the chosen
  Package's add-on image cards (checkbox; quantity field outside the label
  with pointer −/+ buttons; "Maximum quantity: N" or a fixed "Quantity: 1";
  "Add-ons are optional.") and the dark summary with Add to cart below the
  grid; stacked at ≤1024px. Local, case- and accent-insensitive name search
  for Packages with more than one add-on: hidden cards keep their choices,
  quantities and place in the summary/request; a polite "No add-ons match …"
  message; Enter never submits; a hidden invalid choice is revealed before its
  message. "Your cart shows the total." kept.
- "What's included": Sessions' `HomepageSessionCalendar` adds read-only
  `contents` per listed Package from the same Catalog customer read (component
  product name, configured quantity, Paper/Digital; `ok` or `unavailable`);
  the theme validates it and shows "Package contents can't be shown right
  now." when unverifiable (e.g. a component whose product is trashed). No cart
  line or request change.
- Shop: new Catalog `ShopListing` on `woocommerce_product_query` leaves the
  Hall's own Package and Play Product products (every row, active or archived)
  out of the Shop page, product archives and product search; fails open
  (listing unchanged) when the Catalog can't be read; no product, visibility,
  stock, price or purchasability change; direct-purchase guards unchanged.
- Add-on price presentation: `CartSelection::relation_priced()` (used on add,
  session restore, refresh and the homepage listing) makes the relationship
  price an add-on line's price and regular price with no sale price — the Cart
  block's false "Previous price / Save" is gone; genuine Package and
  merchandise sales still show. Behaviour change by design: a coupon that
  excludes sale items now discounts an add-on whose product has its own sale.
- Wording: `bc_electronic_limit_exceeded` → "Your selection exceeds this
  Hall’s digital bingo face limit. Reduce the digital add-ons or choose a
  different Package." Stale test comments about the cart page corrected (Hall A
  Cart block, Hall B classic shortcode).
- Guarded `bingocommerce_state_recon` results (disposable token-owned Halls
  only): `tests/homepage-session-purchase.php` **235 / 0** with
  `BC_SESSION_PURCHASE_BROWSER=1` (browser stage **383 / 0** over 230 live
  php-cgi requests that boot each Hall directly; real JSON POSTs, normal nonce
  checks, real Set-Cookie; Hall A Cart block page and Hall B classic cart;
  Shop page, product category and product search requests) and **232 / 0**
  without it. New coverage: contents (provider, theme validation, rendering,
  no-JS, trashed component), layout and responsive metrics at 1440–320px,
  long names, missing images, keyboard order, stepper/checkbox independence,
  search (no-match, Enter, hidden selections, accents), Shop exclusion with a
  product ID that is merchandise in Hall A and a Play Product in Hall B,
  unchanged product states and guards, Store API prices (no false sale;
  genuine Package/merchandise sales), a restored cart, a coupon excluding sale
  items, and taxes. Simulated (router-altered) responses remain only where
  labelled (mappings, strict success, nonces, uncertain outcomes).
- Regressions: calendar **362 / 0** with browser (browser stage **184 / 0**;
  its Package-grid check now follows the purchase layout when the script runs)
  and **357 / 0** without; customer cart **140 / 0**; electronic limits
  **679 / 0**; order recording incl. refunds, coupons and taxes **1098 / 0**;
  digital entitlement snapshot **1330 / 0**; storefront **106 / 0**; Store
  Details **204 / 0**; slider **222 / 0** and browser **59 / 0**; categories
  **316 / 0** and browser **255 / 0**; session availability 564, sessions 352,
  schedules 159, schedule admin 447 and reconciliation 271 assertions (all
  PASS); package add-ons **582 / 0** and add-on admin **350 / 0**. PHP and
  JavaScript syntax checks and `git diff --check` pass. Persistent fixture
  Halls 2/3/4 and the main site's WooCommerce session table unchanged;
  network electronic-limit setting restored.
- Not changed or not covered: related/up-sell products, product blocks,
  shortcodes, widgets, the general site search and WooCommerce's stored
  category counts (the Shop filter is the main product query only); a Bingo
  product's single product page is still reachable by URL (its add is still
  refused); the add-on grid has no internal scroll area (the reference caps it
  at 450px); the purchase suites run no checkout (the order-recording and
  entitlement suites above do).
- Nothing was staged, committed, pushed or deployed.

### Cart Session labels — branch `bingotheme`, uncommitted (2026-10-02)

- Started from clean HEAD `6d80a16dafe5b1134bfa89e446d9ea5567b381b5`; installed
  WooCommerce 11.1.2 and the existing validator signatures verified before
  editing. Implements the reviewed plan with its placement and Mini-Cart
  corrections (ADR-045 / BR-084 addition 2026-10-02). No schema, setting,
  endpoint, script, template override, dependency or order-format change.
- Production: Sessions' `CustomerCart::session_details()` — one read-only
  `woocommerce_get_item_data` callback (priority 100, existing registration
  guard) — adds one translated "Bingo Session" entry to a line
  `CartSelection::line()` verifies as a Package. A prepared primary-key read of
  that line's own Session from the current Hall's `bingo_sessions` gives
  `MM/DD/YYYY · HH:MM Zone · Name` (generated) or `MM/DD/YYYY · Name` (legacy),
  escaped; the generated name's exact " — HH:MM" ending is left off the screen
  only when it matches the verified structured time. Fields are checked with
  `HomepageSessionCalendar::date_label()`, `ScheduleTimezone::canonical_start_time()`,
  `display_time()`, `validate_zone()` and `exact_instant()` (against the stored
  UTC start) and the existing name rules; any failure, missing row, different
  returned identity, read failure or formatting exception shows "Session
  details can’t be shown right now."; unverified lines get nothing and existing
  validation keeps reporting them. Merchandise, add-on lines, other entries and
  variation details are unchanged. Saved order labels keep their format.
- Displays (WooCommerce's own markup and places): Cart block, Checkout block
  summary, Mini-Cart block (Interactivity API; its native 15-word "…" applies,
  with date, time and zone first), classic cart, classic checkout review and
  classic mini-cart (cart-fragments HTML; BingoThemev1 shows no mini-cart).
- BingoThemev1 `main.css`: one rule, `overflow-wrap: anywhere` on
  `.wc-block-components-product-details__value` and
  `.woocommerce td.product-name dl.variation dd`, after measurement showed a
  long label's unbroken 30-character zone widening the line (rule removed /
  present, page or display-box overflow with the label vs without it): Cart
  block 375px 3/0 → 0/0 and 320px page 42/12, box 58/28 → 12/12, 28/28;
  Mini-Cart drawer 320px 42/12 → 12/12; classic cart 320px 4/0 → 0/0; classic
  review 375px 22/0 → 0/0 and 320px 77/0 → 0/0. No layout, font, colour,
  spacing or width change.
- Tests: `tests/homepage-session-purchase.php` section 7 plus its browser and
  request worker (checkout and Mini-Cart test pages, the homepage's own
  parameters on cart/checkout, the classic `update_order_review` refresh, the
  Checkout block's `__experimental_calc_totals` PUT only with
  `X-HTTP-Method-Override`, opt-in fixture item-data filters) and a `minicart`
  control action. Guarded `bingocommerce_state_recon` results: purchase suite
  **559 / 0** with `BC_SESSION_PURCHASE_BROWSER=1` (browser stage **1087 / 0**
  over 379 live php-cgi requests) and **556 / 0** without it. Regressions:
  customer cart **140 / 0**, electronic limits **679 / 0**, order recording
  **1098 / 0**, digital entitlement snapshot **1330 / 0**, storefront
  **106 / 0**, calendar **357 / 0** (its browser stage not run). PHP/JS syntax
  and whitespace checks pass.
- Observed, not changed (pre-existing, identical with or without the label):
  at 320px WooCommerce's Cart block line-items table overflows the page by
  12px and the Mini-Cart drawer's table its drawer by 12px (right-hand prices
  clipped); below its large breakpoint the Checkout block renders its order
  summary twice (each copy shows the one entry); WordPress core's emoji script
  may draw an emoji in a name as an image (its alternative text is the emoji).
- Limitations: labels are current reads, not snapshots (a renamed legacy
  Session shows its new name until checkout records the order); a zone later
  dropped from PHP's named-zone list shows the neutral message; browser checks
  do not prove screen-reader output; checkout pages were displayed and the
  Checkout block's own totals refresh served, but no checkout was submitted
  (orders in section 7 come from `WC_Checkout::create_order()` without payment).
- Disposable Halls, their tables (sessions included) and the disposable
  customer with all its user metadata removed; persistent fixture Halls 2/3/4,
  the main site's WooCommerce session table and the network electronic-limit
  setting unchanged. Nothing was staged, committed, pushed or deployed.

### Cart Session labels — review corrections, uncommitted (2026-10-02)

- `CustomerCart::session_details()` now also returns non-list item data
  (`! array_is_list( $item_data )`, right after `! is_array( $item_data )`)
  exactly as received, before `CartSelection::line()` or any database read.
  New regressions on a verified Package line — an associative and a sparse
  array (identical result with keys, no Bingo entry, no PHP warning, zero
  queries) and a list control (its entry kept, one Bingo entry, 14 queries) —
  failed six times against the previous guard (an entry appended at key `0` /
  `3`, 14 queries each) and pass now.
- Coverage wording corrected (the overstated "six displays at 1440, 375 and
  320px" was in the cart architecture document): the browser stage renders
  five displays and checks the entry's text, placement and overflow at 1440,
  375 and 320px — Cart, Checkout and Mini-Cart blocks, classic cart and
  classic checkout review. The classic mini-cart is checked only through
  cart-fragments HTML parsed into a detached document (text, escaping and
  markup order, not responsive layout). Fixed in
  `docs/architecture/customer-cart-increment-1.md` and in two browser-suite
  strings; no visual fixture or Mini-Cart change.
- Fresh guarded `bingocommerce_state_recon` results: purchase suite
  **566 / 0** without the browser stage and **569 / 0** with it (browser stage
  **1087 / 0** over 379 live php-cgi requests; same pre-existing overflow notes
  as above). `php -l` on the changed PHP files, `node --check` on the browser
  script and `git diff --check` pass. Disposable Halls (tables included) and
  the disposable customer with all its user metadata removed; persistent
  fixture Halls 2/3/4, the main site's WooCommerce session table and the
  network electronic-limit setting unchanged. Nothing was staged, committed,
  pushed or deployed.

### Checkout recovery, Bingo reorder and error wording — branch `bingotheme`, uncommitted (2026-10-03)

- Started from clean HEAD `1d18ffb`. Implements the confirmed items of the
  2026-10-03 checkout review. Each functional defect was reproduced first by
  the new `tests/checkout-recovery.php` (**32 passed / 39 failed** before any
  production change). No schema, setting, route, dependency, template
  override, shipping method, pricing, payment/ticket or Hall configuration
  change; saved order facts unchanged.
- Recovery: a stored WooCommerce removal notice (an add-on purchasable only at
  its relationship price, removed while the cart loads after its Session
  closed) made every Store API checkout fail with 409 until a classic page
  printed it, so even merchandise-only carts were stuck. BingoThemev1
  `page.php` now calls `WooCommerce::block_page_notices()`, which prints
  WooCommerce's native notices once on the Hall's own block Cart and Checkout
  pages (not endpoints), before the block reads the cart. Nothing is cleared
  globally; current Bingo validation still rejects.
- Reorder: new `BingoCommerce\Sessions\CustomerReorder`, a `wp_loaded`
  priority 5 guard (WooCommerce's loader is at 10) that never loads the cart
  and acts only where WooCommerce would (its cart present, signed in, valid
  native nonce). Orders with Bingo purchase markers, mixed orders included,
  redirect to the cart unchanged with a notice linking to the Session
  calendar. Requests WooCommerce would refuse (another customer's, unknown,
  non-order or malformed IDs; previously an emptied cart plus PHP warning, or
  a fatal error) redirect with the cart unchanged and nothing disclosed.
  Before redirecting it removes `order_again` from the request: the first
  real-request preview showed WooCommerce's shutdown hook
  (`maybe_set_cart_cookies` → `get_cart()`) still acting on it after the
  guard's exit (missing order: fatal error; Bingo order: notice lost), and the
  regression now models that shutdown load. Merchandise-only reorders stay
  native; the native button is omitted for Bingo orders.
- Errors: one message per distinct cause and code; established read failures
  are worded as temporary (codes unchanged, so the homepage mapping is
  unaffected); an add-on line still in the cart is not also reported as
  missing; classic checkout no longer repeats the notice `classic_errors()`
  stored. Plain wording for unavailable, temporary, incomplete,
  electronic-limit, Catalog and recorder messages. The recorder still stops
  before payment and keeps the draft.
- CSS (BingoThemev1 `main.css`): on order received and My Account order views
  the item-meta label and value flow inline with `overflow-wrap: anywhere`
  (with WooCommerce's floated label, the value's first word was split beside
  it), so only the too-long zone name breaks; at ≤360px the Cart block row
  grid is `64px minmax(0, 1fr)`. Labels are not truncated or rewritten.
- Final guarded `bingocommerce_state_recon` results on the final code:
  checkout recovery **82 / 0** (current and persistent cart preserved on every blocked or refused reorder), customer cart **140 / 0**, electronic limits
  **679 / 0**, order recording **1098 / 0** (also **1098 / 0** with Digital
  Tickets loaded), digital entitlement snapshot **1330 / 0**, purchase suite
  **569 / 0** with its browser stage (**1087 / 0**, 379 live php-cgi requests),
  storefront **106 / 0**. `php -l`, `node --check` and `git diff --check`
  pass. Real-request previews (disposable harness Halls): recovery for Cart
  arrival, direct Checkout arrival and a Session closing mid-checkout; reorder
  of Bingo, missing, malformed and merchandise orders; Cart page overflow at
  320px 12px → 0 (unchanged at 361/375/1440); order views at 320/375px
  114/59px → 0.
- Fixture cleanup: every disposable Hall (tables included) and customer was
  removed, including the residue of one purchase-suite run stopped
  mid-browser-stage (token-verified Halls 1513/1514 and customer 870). The
  fixture table list, Halls 1–4, users and network electronic limit (48), and
  checksums of all 439 development database tables match the pre-run
  baseline. Nothing was staged, committed, pushed or deployed.
- Pending: see "Before Customer-Facing Digital Launch" item 9 (coupons last).
  The reorder guard is security-relevant (nonce/ownership); per `CLAUDE.md`
  it needs the code-reviewer subagent before merging (not yet run).

### Checkout recovery follow-up — order metadata CSS, malformed reorder input, security review, uncommitted (2026-10-03)

- Order views: the inline label/value rules and `overflow-wrap: anywhere` are
  now scoped to single-paragraph metadata (`li:has(> p:only-of-type)`), so
  multi-paragraph merchandise metadata keeps WooCommerce's block paragraphs.
  New browser stage in `tests/checkout-recovery.php`
  (`BC_CHECKOUT_RECOVERY_BROWSER=1`; `tests/checkout-recovery-browser.cjs` and
  `tests/checkout-recovery-request.php`, real php-cgi requests) checks Order
  Received and the My Account order view at 320/375/1440px with the long Bingo
  Session label and two-paragraph "Gift message" metadata. It failed 12 times
  against the previous CSS (paragraphs inline) and passes now.
- Reorder: array-valued `order_again`, `_wpnonce` and both are covered through
  the guard and WooCommerce's later cart loads (no warning, no native reorder,
  session and persistent carts unchanged, no notice). The documentation now
  separates invalid-string nonce rejection (left to WooCommerce) from the
  defensive handling of array input.
- Security review: no `code-reviewer` subagent is defined in this project or
  user configuration, so the `CLAUDE.md` requirement remains unfulfilled. An
  independent read-only review by a general-purpose subagent was run instead.
  It confirmed one low-severity gap: Store API requests had no guard, so a
  customer could natively reorder their own Bingo order into their own cart
  (checkout still blocked it). Fixed with a `woocommerce_load_cart_from_session`
  priority 1 hook that removes `order_again` for Bingo and refused requests;
  regression fails 6 times without it.
- Results (guarded `bingocommerce_state_recon`): checkout recovery
  **171 / 0** with the browser stage; customer cart **140 / 0**, electronic
  limits **679 / 0**, order recording **1098 / 0** (also with Digital
  Tickets), entitlement snapshot **1330 / 0**, storefront **106 / 0**, purchase
  suite **569 / 0** with its browser stage (**1087 / 0**). Fixtures cleaned up.
  Nothing staged, committed, pushed or deployed.

### Digital Bingo ticket waiting area — branch `bingotheme`, uncommitted (2026-10-04)

- Superseded in part (2026-10-05): checks now use the read-only ticket status
  route (ADR-046; see "Ticket status route for automatic QR updates" below), not
  page fetches, and guests get automatic QR updates.
- Context (Star Bingo Hall, site 869, local Stripe test mode): Order #70
  remained pending because this Stripe Gateway 11.0.0 configuration (Checkout
  Sessions / Adaptive Pricing) confirms payment in WooCommerce only from the
  `checkout.session.completed` webhook, and none could reach the local `.test`
  Hall; WooCommerce's 60-minute hold-stock timeout later cancelled it
  automatically and a cancellation void marker was recorded. Order #71, paid
  while temporary Stripe CLI forwarding delivered the test webhooks, had its
  payment confirmed natively (verified WooCommerce status Processing, not
  Completed) with exactly one entitlement and one ticket;
  its first Order Received page loaded about a second before the ticket existed
  and the QR appeared after a refresh. The Hall's original test webhook secret
  was restored and verified; forwarding was stopped.
- Order Received and My Account → View order now show a waiting area instead of
  nothing when an authorized viewer (Hall, order-key, ownership and guest
  email-verification checks unchanged) opens an eligible Digital/Hybrid order
  paid by online card (the `stripe` gateway ID only; bank transfer, cheque, cash
  on delivery and other `stripe_<type>` methods are excluded) whose ticket is
  not shown: online payment confirmation pending (`pending`/`on-hold`, asking
  the customer not to pay again); payment confirmed but the ticket not available
  yet; or a neutral "can't be checked right now" state when the ticket or void
  read fails (manual checks only). Nothing promises issuance or implies a repair.
  Failed, cancelled, refunded and draft orders, other statuses, void markers and
  non-active entitlements still show nothing. A shown ticket keeps its markup and
  now carries `data-bc-ticket-scope` (Hall:order). The email link shares the
  same eligibility helper and is unchanged.
- Checks (`customer-ticket.js` 1.1.0) GET a clean URL for the same order (a
  signed-in owner's My Account order view, also when the owner is on Order
  Received; otherwise Order Received with only its key; same Hall origin; no
  Stripe return or action parameters; no Referer) 5 s after load and 5 s after
  each finished attempt, only where `fetch`, `DOMParser`, promises and
  `AbortController` exist: at most six automatic attempts per Hall and order per tab
  (`sessionStorage`, counted before each request, kept across reloads), one
  request at a time, 10 s timeout, paused while the tab is hidden. Only a section
  for the same Hall and order is used, and readiness needs exactly one reference
  naming this Hall; only validated text and that reference are copied into a
  newly built section and drawn with the existing QR renderer, so nothing from
  the fetched HTML is inserted or run. Missing, foreign or duplicate sections,
  login pages and non-HTML or malformed responses stop the checks with neutral
  guidance; HTTP errors, network failures and timeouts are counted and end with
  network guidance. "Check again" makes one request, is `aria-disabled` while it
  runs and never resets or restarts the six-attempt budget. Without storage the
  checks are manual only; without JavaScript a reload note is shown. Focus moves
  to the new heading only when it was inside the replaced section; when the
  customer has scrolled past the section's start, the content below is held in
  place. One polite live region announces only meaningful changes. Existing
  fonts, colours and theme button styles are used; no CSS changed.
- Production files: `bingocommerce-digital-tickets/src/CustomerTicket.php`,
  `assets/customer-ticket.js` and a read-only `EntitlementVoiding::display_blocked()`.
  No gateway, payment-completion, issuance, order-status, void, email, shipping,
  coupon or theme change, and no new REST endpoint.
- New guarded tests: `tests/customer-ticket-wait.php` with
  `tests/customer-ticket-wait-request.php` (php-cgi worker),
  `tests/customer-ticket-wait-control.php` (fixture steps) and
  `tests/customer-ticket-wait-browser.cjs` (`BC_TICKET_WAIT_BROWSER=1`, with
  `BC_PLAYWRIGHT_MODULE`, `BC_CHROME_EXECUTABLE`, optional
  `BC_TICKET_WAIT_SHOTS`). A fixture card gateway with the `stripe` ID moves no
  money and leaves orders pending; native `payment_complete()` stands in for the
  webhook. Result **408 / 0**: 205 PHP checks (states, exclusions, read failures,
  authorization, colliding-ID Hall isolation, clean URLs, email, 35 real
  php-cgi page checks for both displays, reads-only and unchanged ticket rows)
  and 203 browser checks on real pages under Playwright's fake clock: delayed
  availability on both displays (Vision decodes the swapped-in QR), payment
  confirmed then ticket, six attempts and reload persistence, manual checks and
  overlapping clicks, storage blocked, no JavaScript, timeouts, HTTP 500,
  network failure, login/non-HTML/malformed/foreign-scope/duplicate responses,
  scripted HTML (nothing runs), hidden tab, focus and scroll with and without
  browser scroll anchoring, guest, unavailable read, excluded order and
  previews. Deliberate faults were caught: allowing bank transfer (4 failures),
  ignoring void markers (6), counting an attempt after its request (6).
- Regressions on the final code (guarded `bingocommerce_state_recon`): digital
  entitlement snapshot **1330 / 0**; QR browser rendering 10, 6 and 10 checks
  with 3 exact Vision decodes; checkout recovery **171 / 0** with its browser
  stage; purchase suite **569 / 0** with its browser stage (**1087 / 0**, 379
  live php-cgi requests). Disposable Halls and customers were removed and
  persistent fixture Halls 2/3/4 are unchanged. Read-only checksums of all 457
  development database tables were identical before and after the final runs;
  Orders #70/#71, the Hall's single ticket and its Stripe settings are
  unchanged. Desktop and mobile previews:
  `/private/tmp/bc-ticket-wait-review/previews/`.
- Limits: Chrome only (Safari's missing scroll anchoring emulated with
  `overflow-anchor: none`; Firefox, Safari/WebKit, screen readers and physical
  devices untested); hidden tabs emulated; no real Stripe charge or production
  webhook in the automated tests. A guest verified only through WooCommerce's
  email form cannot be re-verified by a background GET, so that guest gets
  "reload this page" guidance. A new tab gets six fresh attempts. WooCommerce's
  Order Received empties the session cart on every load (corrected 2026-10-05;
  see the review corrections below), so only a guest's checks still have that
  effect. WooCommerce's own Pay and Cancel order actions still
  appear beside "Please don't pay again" on pending orders (native behaviour,
  not changed). The `CLAUDE.md` code-reviewer step is still required before
  merging. Nothing staged, committed, pushed or deployed.

### Ticket waiting area — review corrections and security review, uncommitted (2026-10-05)

- Superseded in part later the same day: the owner's My Account check URL, the
  guest cart and email-verification limitations and the open "dedicated
  read-only check" decision are resolved by the ticket status route below.
- HEAD `2246bbe`, branch `bingotheme`; the waiting-area work remains uncommitted.
- `customer-ticket.js`: ticket-page requests now also require `AbortController`.
  Without it a timed-out request could not be cancelled and the next attempt
  could start beside it; such browsers now get the existing reload guidance and
  no automatic or manual request, while an existing ticket's QR still renders.
  Checks also send no Referer, so the page address (its order key, or Stripe
  return parameters when present) is not repeated in request headers.
- `CustomerTicket.php`: a signed-in owner's checks now read My Account's order
  view, also from Order Received (Order Received when the Hall has no My Account
  page). WooCommerce's Order Received (`WC_Shortcode_Checkout::order_received()`,
  reached through the Checkout block on this classic theme) calls `wc_empty_cart()`
  on every load, before its own authorization checks, so each background check
  of that page emptied the customer's session cart, including a cart filled in
  another tab. The earlier "only when it matches the paid order" statement was
  wrong: that guard belongs to `wc_clear_cart_after_payment()` only.
- Security review: `CLAUDE.md` requires the code-reviewer subagent, but none is
  defined (`.claude/agents/` is empty and no user-level agent exists), and the
  built-in security-review skill cannot run here because it diffs against
  `origin/HEAD` and this repository has no remote. As a stated substitute, an
  independent read-only general-purpose review covered authorization, Hall/order
  scope, clean URLs, response handling, token handling and read-only behaviour:
  no critical, high or medium findings. Its low finding (cart emptying) is fixed
  for signed-in owners; its Referer note was applied as hardening. The formal
  `CLAUDE.md` code-reviewer step remains unfulfilled until such a reviewer exists.
- Decisions still needed (not changed): a guest's checks can only read Order
  Received, so each automatic or manual check still empties that guest's session
  cart (options: accept, make guest checks manual-only, or approve a dedicated
  read-only check); "Please don't pay again" also shows on an abandoned pending
  card order, which WooCommerce only cancels automatically when stock management
  and hold-stock minutes are on. WooCommerce's Pay/Cancel actions and the guest
  email-verification limitation remain disclosed follow-ups.
- Status wording corrected above: Order #70 remained pending without webhook
  delivery and was later cancelled automatically by WooCommerce's hold-stock
  timeout; Order #71's payment was confirmed natively and its verified WooCommerce
  status was Processing (not Completed). Production webhook delivery remains
  unverified.
- Tests: a no-`AbortController` browser scenario (reload guidance, no
  automatic-checking note or Check again, no request in two minutes, no attempt
  used, an existing ticket's QR still renders); owner/guest check URLs; a
  real-request cart check (two My Account checks leave the owner's cart intact,
  while Order Received itself empties it); a browser check that a cart filled
  after the page loaded survives the automatic checks; no Referer on any check.
  Against the previous implementation the suite fails 55 checks, including all
  five no-`AbortController` checks plus both fallback previews, the cart checks
  and the Referer checks.
- Fresh results (guarded `bingocommerce_state_recon`): waiting-area suite
  **430 / 0** (212 PHP, 218 browser) with its browser stage; digital entitlement
  snapshot **1330 / 0**; QR browser rendering 10, 6 and 10 checks with 3 exact
  Vision decodes; `node --check`, `php -l`, `git diff --check` and whitespace
  checks pass. Checkout recovery and the purchase suite were not rerun: neither
  loads Digital Tickets, and no other code changed. Previews, including the
  reload fallback at desktop and mobile widths:
  `/private/tmp/bc-ticket-wait-review/previews/`. Payment completion, issuance,
  gateway settings, Orders #70/#71, Hall settings, shipping, coupons and Android
  redemption are unchanged. Nothing staged, committed, pushed or deployed.

### Ticket status route for automatic QR updates (ADR-046) — branch `bingotheme`, uncommitted (2026-10-05)

- Replaces the page-fetch checks and the guest reload fallback. Guests,
  email-verified guests and signed-in owners, including in a Hall without a My
  Account page, now see the existing QR appear without refreshing, signing up
  or paying again. Checks no longer load Order Received or My Account, and no
  check changes any cart.
- New read-only route `POST /wp-json/bingocommerce-digital-tickets/v1/ticket-status`
  (`src/TicketStatus.php`, registered on `rest_api_init`). It is the single
  AGENTS.md rule 5 exception, approved by the user; rule 5 and ADR-046 are
  updated, with a note in `docs/architecture/customer-cart-increment-1.md`.
  - Credential: the waiting area carries a 30-minute credential,
    `<order ID>.<expiry>.<HMAC-SHA256>` over the Hall, order, owner, expiry
    and an order-key hash. It is issued only after the existing `can_view()`
    checks; signed-in viewers also get a `wp_rest` nonce.
  - Authorization: customer-owned orders still need the signed-in owner's
    `view_order` capability. Guest-owned orders need the credential.
  - Refusals disclose nothing: 400 for a missing or malformed credential; 403
    for one that is tampered, expired, too long-lived, for an unknown order,
    from another Hall, for another customer, sent signed out or sent in the
    URL.
  - Answer: only the scope and state (`payment`, `ticket`, `unavailable`,
    `closed` or `ready`). The reference is added only when the page would show
    that ticket, using `CustomerTicket::current()`, the page's own read. Every
    answer is sent with `Cache-Control: no-store, …, private`.
- `customer-ticket.js` 1.2.0 POSTs the credential as JSON: never in a URL, with
  no Referer, and with the nonce header only when signed in. It keeps six
  attempts, 5 s spacing after each finished attempt, a 10 s timeout, one
  request at a time, the hidden-tab pause and the `AbortController`
  requirement. Each request is released once its answer is used. With
  WordPress's real response headers, a refusal body the page never read had
  stayed open in Chrome.
  - A 400, 401 or 403 answer says "This page can no longer check for your
    ticket. Reload this page to check again." and removes Check again.
  - `closed` says "A Digital Bingo ticket can’t be shown for this order. Reload
    this page to see the latest order details."
  - In both cases the page drops its credential, and focus moves to the section
    heading if Check again had it.
  - New pending text: "Payment hasn’t been confirmed. If you have already
    submitted payment, please don’t pay again while confirmation is pending."
    The unavailable text now ends "If you have already submitted payment,
    please don’t pay again."
  - WooCommerce's Pay and Cancel actions are unchanged.
- Verified on real php-cgi requests, observed at shutdown: the route starts no
  WooCommerce session, loads no cart and writes nothing. This holds for guests
  sending a WooCommerce session cookie and for signed-in owners. Carts filled
  after the page loaded keep the same value hash and expiry. The only write
  seen is WooCommerce Admin's `woocommerce_product_task_has_product_transient`
  cache, on a new Hall's first REST request of any kind, even a malformed one
  refused before any route callback runs.
- Tests (guarded `bingocommerce_state_recon`) in the rewritten
  `tests/customer-ticket-wait.php` and its php-cgi worker, control and browser
  files:
  - PHP stage, **326 / 0**:
    - route registration stays narrow;
    - every state, and ten repeat checks with unchanged rows;
    - every refusal, plus owner, other-customer and signed-out access;
    - cross-Hall refusal both ways with colliding order IDs;
    - a route on another origin is never offered;
    - real requests with cookies and nonces, observation and cart
      preservation;
    - WooCommerce's real email-verification form and a Hall without My
      Account;
    - the same saved ticket after payment.
  - Browser stage, **315 / 0**:
    - delayed owner and guest QRs appear in place and Vision decodes them;
    - carts filled in another tab survive automatic and manual checks, for
      owners and guests;
    - an email-verified guest and a Hall without My Account;
    - a tampered credential is refused by the real route;
    - an order is cancelled while its page is waiting;
    - JSON fault fixtures, budget, keyboard, storage, no `AbortController`,
      no JavaScript, hidden tab, focus and scroll, and previews;
    - all 33 real status requests were observed clean;
    - no status request was left open once its answer was used.
  - Deliberate faults were caught: the route loading the cart (4 failures),
    no ownership check (5), a URL credential (4) and a client that never
    released answered requests (1).
  - Total **641 / 0**.
- Regressions: digital entitlement snapshot **1330 / 0**; QR browser rendering
  10, 6 and 10 checks with 3 exact Vision decodes. `node --check`, `php -l`,
  `git diff --check` and whitespace checks pass. Read-only checksums of all 457
  development tables match the 2026-10-04 baseline. Orders #70/#71, the Hall's
  single ticket and its Stripe settings are unchanged.
- Limits:
  - Tested in Chrome only: not Safari/WebKit, Firefox, screen readers or
    physical devices.
  - No real Stripe charge or production webhook was exercised.
  - The credential is a 30-minute bearer secret visible to anyone who can see
    the authorized page. After it expires, or after six attempts, the customer
    reloads.
    *Correction (2026-10-05, later):* "or after six attempts" was wrong.
    After the automatic attempts are used, Check again stays available for as long as the page's credential is valid (30 minutes) and no request has been refused; each manual check is one request and never resets or restarts the automatic budget. A refused request (400/401/403), including an expired credential, removes Check again and asks the customer to reload.
  - A guest order later linked to an account no longer matches its guest
    credential.
  - A new tab gets six fresh attempts.
  - The pending text also shows on an abandoned pending card order.
  - The `CLAUDE.md` code-reviewer step is still required before merging; no
    such agent is defined.
- Nothing staged, committed, pushed or deployed.

### Faster ticket checks, "confirming payment" line and phone order summary — uncommitted (2026-10-05)

- Why: in a real guest test, Order #73 at Star Bingo Hall (local Stripe test
  mode with temporary Stripe CLI forwarding) showed the QR automatically, but
  only after the first 5-second wait. Meanwhile the page said "Thank you. Your
  order has been received.", which looks finished before payment is confirmed.
  The order summary also wrapped unevenly on phones.
- `customer-ticket.js` 1.3.0:
  - The first check now runs as the page opens, and each next one 2 s after
    the previous one finished (was 5 s). There are still six attempts, so the
    automatic checks end after about 10–20 s instead of 30–90 s; Check again
    is unchanged.
    *Correction (2026-10-05, later):* "about 10–20 s" understated the slow case.
    Timing as configured: the first request is sent as the page opens; each next one starts 2 s after the previous one completed (answered, failed or abandoned at the 10 s timeout); at most six automatic attempts. With quick answers they end about 10 s after the page opens; if all six time out, about 70 s (6 × 10 s + 5 × 2 s). Time while the tab is hidden pauses them and adds to the elapsed time.
  - A tab opened in the background checks as soon as it becomes visible.
- `CustomerTicket::received_notice()`, on WooCommerce's
  `woocommerce_thankyou_order_received_text` filter, priority 20:
  - While the waiting area is in its payment-pending state, Order Received's
    thank-you line reads "Thank you. We’re confirming your payment. This
    usually takes a few seconds."
  - WooCommerce's own line stays in the page, hidden, and the script shows it
    again once payment is confirmed, the ticket appears or the order closes.
  - Same authorization and read as the ticket area. `render()` reuses that one
    read, so the line and the ticket area always agree.
  - Every other state, gateway, order and page is unchanged.
- BingoThemev1 `assets/css/main.css`: at 640px and below, the Order Received
  summary is one row per fact (label left, value right) in a bordered box.
  Wider screens keep WooCommerce's row.
- Tests (guarded `bingocommerce_state_recon`):
  - Waiting suite with browser stage **692 / 0** (362 PHP, 330 browser):
    - the thank-you line in every state, exclusion and authorization case;
    - real Order Received pages for owner and guest, before and after
      payment;
    - the line switching back in the browser;
    - immediate first check and 2-second spacing;
    - background tab;
    - mobile summary rows and the unchanged desktop row.
  - Digital entitlement snapshot **1330 / 0**; QR rendering 10, 6 and 10 checks
    with 3 exact Vision decodes.
  - Checkout recovery with its browser stage **171 / 0**.
- Development data: the only changes since the earlier baseline come from
  Orders #72 (left pending) and #73 (paid; one entitlement, one ticket), which
  were placed manually, and from the Hall's Stripe test webhook secret. That
  secret is still the temporary Stripe CLI one. The previous value had already
  been overwritten by an unedited console snippet, so restore it with Stripe's
  Configure webhooks when the Hall is reachable from the internet.
  *Correction (2026-10-05, later):* "lost" was not verified and is too strong.
  What is verified: the Hall's locally stored test webhook secret was
  overwritten (the console reported the stored value as the unedited
  placeholder), and it now holds the Stripe CLI secret, which only works for CLI
  forwarding. Not verified: whether the Hall's original test-mode webhook
  endpoint still exists in Stripe. An endpoint's signing secret is held by
  Stripe, so if it does, its secret can be revealed in the Stripe dashboard
  (Developers → Webhooks → the endpoint) and saved to the Hall again. Only if
  it no longer exists does Configure webhooks need to create a new endpoint.
- Limits: a webhook slower than about 10–20 s needs Check again or a reload;
  Chrome only; nothing staged, committed, pushed or deployed.
  *Correction (2026-10-05, later):* a webhook arriving after the automatic
  attempts end (about 10 s with quick answers; up to about 70 s if requests time
  out) needs Check again or a reload.

### Ticket waiting area corrections: receipt line recovery, redirects refused, loading sign — uncommitted (2026-10-05)

- Receipt line: `CustomerTicket::received_notice()` now supplies both lines
  (confirming-payment and WooCommerce's own, filtered and translated) on every
  authorized Order Received page with a ticket waiting area. Only the line for
  the current state is visible: `payment` shows confirming; `ticket` and
  `unavailable` keep WooCommerce's line visible. Before, an initially
  `unavailable` page that recovered to `payment` updated the ticket section but
  left the top line unchanged.
  - The script switches the line: to confirming on `payment`; to WooCommerce's
    line on `ticket`, a shown ticket or a closed order. An `unavailable` answer
    changes nothing, so no payment result is inferred.
  - Pages without a waiting area (ready tickets, other gateways, excluded
    orders, unauthorized viewers) and My Account are unchanged.
- Redirects: `request()` uses `redirect: 'error'`, so a redirect answer is never
  followed. Followed redirects could resend the POST body (the credential) and
  the REST nonce to another origin on 307/308, or load Order Received (which
  empties the session cart). A redirect now fails like any other request:
  counted, retried within the budget, then network guidance; never a payment
  result.
- Loading sign: a small spinning ring (new `assets/customer-ticket.css`,
  enqueued with the waiting area; it takes the text colour) shows beside the
  checking note while automatic checks run, and beside Check again during a
  manual check. It is hidden from screen readers, which get the text, and is
  static under reduced motion.
- Script and stylesheet version 1.3.1 (`CustomerTicket::VERSION`).
- Timing, as configured: the first request is sent as the page opens; each next one starts 2 s after the previous one completed (answered, failed or abandoned at the 10 s timeout); at most six automatic attempts. With quick answers they end about 10 s after the page opens; if all six time out, about 70 s (6 × 10 s + 5 × 2 s). Time while the tab is hidden pauses them and adds to the elapsed time. After the automatic attempts are used, Check again stays available for as long as the page's credential is valid (30 minutes) and no request has been refused; each manual check is one request and never resets or restarts the automatic budget. A refused request (400/401/403), including an expired credential, removes Check again and asks the customer to reload.
- Tests (guarded `bingocommerce_state_recon`), written first and run against
  the uncorrected code:
  - Recovery regression (initial read failure → Check again → payment pending
    → an unavailable answer → confirmation → issued ticket): the top-line
    assertions failed as expected.
  - Cross-origin 307/308: a real local collector permitting CORS with
    credentials received the credential/nonce. Same-origin 302/307: the page
    requested Order Received.
  - The browser for the redirect cases maps the collector's name to 127.0.0.1
    and lifts Chrome's private-network block in that browser only. A fixture
    probe proves the collector accepts a credentialed cross-origin POST, so
    CORS cannot mask a forwarded credential.
  - Corrected code: waiting suite with browser stage **733 / 0** (365 PHP,
    368 browser), including:
    - the order summary at 320, 375 and 640px (rows) and at 641, 768 and
      1024px (WooCommerce's floated row with dashed separators);
    - the loading sign appearing and disappearing.
  - Digital entitlement snapshot **1330 / 0**; QR rendering 10, 6 and 10
    checks with 3 exact Vision decodes.
- Development data: the only change since the previous fingerprint is Order
  #74 (a manual guest re-test: paid, one entitlement, one ticket). The Stripe
  settings are unchanged.
- Nothing staged, committed, pushed or deployed.

### Customer email: "View your Bingo order" as a button — uncommitted (2026-10-05)

- Order #78's emailed link was reported to do nothing when clicked.
  - The link itself works: requested as a signed-out guest, Order #78's
    Order Received URL returned HTTP 200 with the ticket section, and in
    Chrome it stayed on that page and drew the QR with no page errors.
  - The order URL worked when opened directly as a guest in Chrome. The cause
    of the ignored click in the delivered email remains unverified because the
    actual mail viewer was not inspected. WP Mail SMTP Lite (generic SMTP) keeps
    no email log, and the viewer and screenshot were not available.
- `CustomerTicket::email_order_link()`: HTML emails show an email-safe button.
  - An inline-styled link inside a table cell (so Outlook also shows the
    colour), in the Hall's WooCommerce email base colour, with WooCommerce's
    own contrasting text colour.
  - 48px tall and at least 220px wide, never wider than the email.
- Unchanged: the plain-text email, the native Order Received URL with its full
  order key, escaping, placement and eligibility.
- Tests:
  - Waiting suite with browser stage **746 / 0**, including the button's
    destination and complete key, plain text, escaping of a hostile URL,
    survival through WooCommerce's email CSS inliner, admin and Paper
    exclusions.
  - The complete styled email at 320, 375 and 600px: button 225×48px, inside
    the screen, no horizontal scroll.
  - Digital entitlement snapshot **1330 / 0** (actual captured emails; the
    link opens the guest's saved QR, then WooCommerce's email verification
    after the guest window); QR rendering 10, 6 and 10 checks with 3 exact
    Vision decodes.
- Nothing staged, committed, pushed or deployed.

### QR validity message — uncommitted (2026-10-05)

- Under every Digital Bingo ticket QR (Order Received and My Account order
  view, server-rendered or swapped in automatically):
  "**Valid for one Session only.** This QR covers only the digital part of your
  purchase: one Package (Digital Night Pack) and its add-ons for the Bingo
  Session “Star Evening” on 10/15/2026 at 18:30. It can’t be used for any other
  Session, date or Package." (Wording corrected the same day: "only the digital
  part of your purchase".)
  - "and its add-ons" appears only when add-ons were bought; the time only when
    the Session has one, shown once.
  - Built from the order's saved purchase facts and escaped. Omitted if those
    facts can't be read.
  - Informational only: nothing enforces it until Android redemption exists.
- `CustomerTicket::validity()`, `render()` and `waiting()`
  (`validLead`/`validDetail` strings); `customer-ticket.js` `show()`; version
  1.3.2. Recorded as an addition under ADR-046.
- Tests (guarded `bingocommerce_state_recon`):
  - Waiting suite with browser stage **756 / 0**: wording with and without
    add-ons, with a start time and a generated name, escaping, missing facts,
    both displays, swapped-in QR equal to the server rendering after reload.
  - Digital entitlement snapshot **1330 / 0**; QR rendering 10, 6 and 10 checks
    with 3 exact Vision decodes.
- Nothing staged, committed, pushed or deployed.
