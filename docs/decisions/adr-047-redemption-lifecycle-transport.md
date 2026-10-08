# ADR-047 — Digital Tickets lifecycle transport

- Date: 2026-10-06
- Status: **Approved by the user, 2026-10-06; implementation and verification tracked separately.**
- Decision: the narrow captured-`$wpdb->dbh` exception described below.
- Scope: Digital Tickets redemption and existing entitlement-void convergence only.

The user explicitly approved this transport for Digital Tickets redemption and
existing entitlement void convergence only. This approval is added to AGENTS.md's
approved transport list; it does not extend Catalog or Portal transport scope.
Before any callback-capable preparation, capture the authorized Hall and original
database object/connection/context. Any subsequent change must fail with 503 before
a lifecycle write. Normalize native database scalar types to the WordPress row
representation without weakening identity or lifecycle comparison. Approval alone
does not establish successful implementation, concurrency or performance proof.

## Pre-change evidence and problem

- `AGENTS.md`, section 5 rule 4, permits direct mysqli only in explicitly approved
  captured-connection transports and requires native binding of values.
- `wp-content/plugins/bingocommerce-portal-integration/src/ProvisioningMutex.php`
  reserves the request-local guard before acquisition, checks server-side ownership,
  and retains a reservation after an ambiguous acquisition/release. Its five-second
  acquisition and connection-ownership checks are precedents, not a new domain
  authorization.
- `wp-content/plugins/bingocommerce-portal-integration/src/PortalSnapshotTransactionTransport.php`
  checks the captured handle, derived Hall table names, storage engines, writer
  database and server transaction instrumentation. Its comments record a measured
  false negative from `information_schema.innodb_trx`; `@@autocommit = 1` alone also
  does not establish the absence of an explicit transaction.
- `wp-content/plugins/bingocommerce-digital-tickets/src/EntitlementVoiding.php`
  at the baseline writes a terminal marker before converging entitlement status. It uses
  ordinary `$wpdb` queries without the proposed shared lifecycle mutex. A failure
  after the marker must not remove revocation.
- `wp-content/plugins/bingocommerce-digital-tickets/src/EntitlementSnapshot.php`
  calls mutating `enforce_block()` both before and after snapshot insertion. Those
  callers must participate in the same lifecycle guard, without changing the
  payment observer's purpose or creating tickets during redemption.
- `tests/digital-entitlement-snapshot.php` exercises controlled `query`-filter
  interleavings. Those hooks do not intercept a native prepared statement; they
  cannot stand in for independent-process proof of this proposed transport.

Single-use redemption requires one durable successful record, request-specific
recovery and atomic whole-ticket limits per actual player tablet. These must also
serialize with the existing terminal void marker. A PHP flag, several unrelated
`$wpdb` statements or an assumed rollback do not establish those guarantees.

## Approved fixed boundary

Introduce one private Digital Tickets lifecycle transport. It captures the current
`$wpdb->dbh` mysqli writer session; it does not open another connection, reconnect,
start a caller's transaction or provide a general SQL/callback transaction API.
All native values, including lock names, IDs, timestamps, JSON and session-setting
values, use prepared statements. Identifiers are derived only from captured trusted
table prefixes, validated and quoted; no request input supplies an SQL identifier.

The allowed operations are narrowly enumerated:

1. Verify the captured session and required existing lifecycle schema.
2. Acquire/check/release one Hall lifecycle advisory mutex.
3. Read ticket/entitlement/terminal-marker lifecycle state.
4. Read, claim and accept an original redemption request; read accepted contributions.
5. Insert the existing terminal void marker and converge existing entitlement status.
6. Set and restore supported session wait bounds used by this operation.

No Catalog mutation, WooCommerce mutation, ticket issuance, DDL, external HTTP,
allocation, staff reactivation, maintenance job or additional counter is authorized.

### Hall and connection ownership

The mutex name must distinguish database/installation, network and resolved Hall
site, within MySQL's supported name length. Every table remains Hall-local through
the verified site's prefix; no redundant `blog_id` is added to Hall-local rows.

Capture the `wpdb` object and mysqli handle, current database/connection ID,
network/site, expected prefix and fixed table identifiers. Reserve the PHP guard
before the first acquisition statement. Refuse a second public lifecycle entry in
the same request. Internal void convergence may receive a private owned token; it
must not call the public acquisition path again. An unexpected reentrant lifecycle
attempt poisons the outer operation so it cannot subsequently accept a ticket.

Require an authoritative writable session, `autocommit = 1`, no pre-existing
transaction and verified InnoDB tables/required unique constraints. Follow the
existing tested MySQL transaction-instrumentation profile. Missing instrumentation,
unsupported driver/server capabilities or uncertain session state fail closed. This
decision does not claim MariaDB compatibility or authorize a fabricated transaction
detector.

Only a strict, successful server response proving ownership authorizes lifecycle
writes. Before every write, and after any permitted callback-capable read phase,
recheck the captured object/handle, Hall/database context, connection identity,
advisory-lock ownership, transaction state and unpoisoned guard. Never silently
follow a replacement `$wpdb->dbh` or switch to another Hall. Unknown acquisition or
release keeps the request-local reservation unavailable; a later request can retry.

### Callback boundary

The final lifecycle decision and write sequence is callback-free. Use native
prepared lifecycle SQL, primitive validated values and callback-free error results.
Do not call `$wpdb->query()`, translations, logging, hooks, `is_wp_error()` or other
callback-capable helpers within that sequence. Existing transports use
`instanceof WP_Error` specifically to avoid the `is_wp_error_instance` action.

Woo saved-fact loading and Catalog's existing read-only effective-limit/mapping
helpers may be used in a separately identified preparation phase. If that phase
holds the lifecycle mutex, it must make no lifecycle decision final: re-prove the
session and reread terminal state natively afterward. A callback that begins a
transaction, changes Hall/connection, releases the lock or attempts another
lifecycle operation invalidates the outer attempt. Do not let a failed reentrant
void callback be followed by a successful outer redemption.

Dispatch diagnostics only after releasing the guard. Logging failure must neither
change a proved acceptance nor trigger a second attempt. Keep raw SQL, driver
messages, stack traces and credentials out of errors and logs.

If release cannot be proved, the domain skips optional logging/translation hooks
and constructs its fixed error without `WP_Error::add()` (which dispatches
`wp_error_added`). WordPress REST and payment observers may still invoke framework
callbacks after the domain returns. The retained poisoned guard prevents those
callbacks from entering another lifecycle write; this does not claim to suppress
every framework callback or close/reconnect the caller's database connection.

### Request record and acceptance

Use one Hall-local InnoDB request/redemption ledger. Its minimum structural roles
are a binary-compared unique request UUID, intent association, exact opaque tablet
identity, relay Caller attribution, pending/accepted state, nullable unique accepted
entitlement ID, exact occurrence, stable redemption identity, immutable aggregate
contributions and complete versioned response. Index the exact occurrence/tablet/
accepted-state read. Tablet comparison must preserve the handoff's bytes and not
inherit a case-insensitive or trailing-space-insensitive string comparison.

The Caller is authentication/audit context, not the usage identity. A legitimate
retry from the same actual tablet through another authorized Caller is not a new
tablet and must not gain another allowance. The canonical request intent binds the
contract version, ticket credential fingerprint, player tablet and any independently
supplied operating-occurrence assertion; raw QR credentials need not be persisted
in this ledger. Trusted Hall is supplied by the table context.

Request registration is a separate autocommit insert associating the original
request with its intent. It consumes no entitlement or allowance. Rejected or
interrupted pending requests cannot later be reused for different intent. A fresh
request against an accepted entitlement returns `already_redeemed`, even when the
tablet matches.

After every required validation and complete response serialization, **one native
conditional autocommit UPDATE** changes the pending row to accepted and writes its
unique entitlement binding, stable redemption ID, exact occurrence, complete
contributions and complete response together. This single InnoDB statement is the
acceptance atomicity boundary. No separate binding, face-counter or response write
is allowed. Confirm the affected row and successful durable acknowledgement; a
failed or unacknowledged statement is not success.

All accepted contributions for the Hall/occurrence/tablet are read under the same
Hall mutex. Parse and validate every stored contribution, detect integer overflow,
and accept the entire incoming ticket only when each Game Type remains within the
current effective limit. Malformed existing contributions are an unavailable
verification boundary, not zero usage. Saved quantities are never recomputed from
the current Catalog. Loading failure, later voiding and retries do not delete
acceptance history or automatically release allowance.

An accepted original request recovers its saved result only for matching intent
and current caller/Hall authority, after checking current terminal void state. It
does not add faces, rebuild the purchase payload or require the current limit to
admit its already-counted contribution again.

### Shared void ordering and durable revocation

Every public redemption, `record_void()` and mutating `enforce_block()` entry uses
the same mutex. Acquire it before authoritative lifecycle reads and writes. Use the
consistent order: terminal marker, entitlement/ticket, then request/ledger. A
preliminary token lookup may obtain an order identifier only; revalidate the ticket
and entitlement relationship under the guard before deciding anything.

For voiding, retain the existing terminal-marker autocommit INSERT followed by
entitlement-status convergence. A confirmed marker remains durable even if the
status UPDATE or its acknowledgement fails. Never wrap those operations in a
transaction that could roll the marker back. Matching duplicate markers are
idempotent; missing or mismatched readback is a failure. Do not manufacture a paid
snapshot when a marker precedes entitlement creation.

If the marker wins serialization, new acceptance and usable recovery are refused.
If acceptance wins, its history remains, and a later marker blocks usable recovery.
This orders local persistence only. WooCommerce/provider refund persistence occurs
before existing notification hooks; missed or failed notifications are not made
atomic by this mutex. No change to payment or refund processing is included.

The new ledger schema is a redemption prerequisite. Adding it must not make the
pre-existing issuance, customer display or void-marker path depend on a ledger it
does not use. Verify the lifecycle tables needed by each fixed profile; perform
migrations only through the existing owner migration entry points, never a scan.

### Unknown outcomes and bounded waits

Autocommit removes an application-level multi-statement COMMIT, not uncertain write
outcomes. Connection loss after an acceptance UPDATE may mean it committed. Return
`write_outcome_unknown` when the durable outcome cannot be established. Do not infer
rollback from disconnect, an error, a later transaction-state probe or cleanup.
Only a later authenticated attempt with the original request ID may read/resume or
recover that intent. It must never manufacture a replacement request ID.

Keep `GET_LOCK` acquisition at at most five seconds. Apply a fixed, tested profile
of InnoDB row-lock and metadata-lock wait bounds on the captured session, verify
them and restore their original values in `finally`. Refuse mutation if required
bounds cannot be installed. An absolute operation budget must prevent commencing
further statements after its deadline. The Portal connect timeout remains five
seconds and its total request timeout thirty seconds; no lifecycle lock spans HTTP.

Do not claim that `MAX_EXECUTION_TIME` bounds every statement: MySQL's SELECT limit,
row-lock timeout, metadata-lock timeout and HTTP timeout govern different work.
Any server-specific execution bound must be tested for the actual supported server.
Failed session-setting restoration or unproved lock release poisons the transport
for the rest of the request and produces only safe diagnostics. A cleanup failure
cannot convert an unproved acceptance to success.

## Required proof before implementation is considered complete

Use guarded fixture-owned databases, real separate PHP processes/connections and
explicit synchronization barriers. Existing native payment/customer regressions
remain required; replacing an obsolete query-filter injection is not removing its
invariant.

- Same original request races: exactly one accepted row and one saved result.
- Different request IDs and tablets race for one entitlement: one winner; all fresh
  losers receive `already_redeemed`, with no information leaked by conflicting IDs.
- Competing tickets near one tablet's per-Game-Type limit: no overshoot or partial
  acceptance; other tablets and Halls remain correctly separated.
- Void-first and acceptance-first; marker success/status failure; snapshot's
  `enforce_block()` callers; refusal of callback reentry/changed session context.
- Outer transaction with autocommit still enabled, autocommit disabled, connection
  replacement/loss, early/late lock release, absent instrumentation and wrong engines.
- Crash after journal registration, before acceptance, after acceptance reaches the
  server and before response; prove original-request recovery and unchanged usage.
- Missing tables/indexes, failed reads, malformed saved JSON, broken required
  mappings/limits and failed safe logging; no false invalid-ticket or success result.
- Timeout installation/restoration, real row/metadata/advisory contention, and the
  supported server's actual execution bounds.
- Query plans, latency and mutex hold/wait measurements at representative ledger
  sizes. Assertion totals alone do not demonstrate performance readiness.

No production-readiness claim follows from approving this design. Real tablet/Caller
attribution, request persistence, response routing and duplicate-load prevention
remain joint integration checks, not prerequisites for backend fixture testing.
