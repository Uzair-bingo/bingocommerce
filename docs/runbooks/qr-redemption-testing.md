# QR redemption server handoff and test contract

Date: 2026-10-06. This describes the current implementation boundary and manual
acceptance contract, not deployment or production readiness.

## Current boundary

The authenticated Caller relay, Commerce ledger, lifecycle transport and shared void
serialization implement approved ADR-047. This runbook exercises the actual backend
path using owned disposable data. Production deployment and Android integration are
not claimed. The Postman collection contains executable acceptance scenarios, not
production credentials.

No persistent development Hall, customer, order, ticket, gateway or payment
setting may be used or changed. Automated Commerce fixtures run only against an
allowlisted, sentinel-owned database, never the development database.

## Required relay handoff

Flow: tablet → local Caller → Portal → Commerce and back. Android owns local
identity generation/attribution, communication, original-request persistence,
response routing and loading. No device registry or local protocol is introduced.

`player_tablet_id` identifies **one actual tablet uniquely within its Hall,
including across different Callers**. It is an opaque UTF-8 string of 1–128 bytes
without Unicode control characters. Case, spaces and other valid bytes are
preserved. Caller identity authenticates/attributes the relay; it does not identify
the tablet for allowance. The authenticated relay supplies accurate attribution.

The original lowercase UUIDv4 `request_id` and unchanged QR survive retries,
reconnects and restarts. A fresh scan uses a new request. Android must avoid
loading the same recovered redemption twice. Acceptance does not mean loading
completed.

## Exact routes, headers and private credentials

The Postman/Caller URL is:

```text
POST {{portal_base_url}}/api/caller/redemptions
Content-Type: application/json
Accept: application/json
```

Set `portal_base_url` to the origin of an explicitly isolated Portal fixture
instance, without a trailing slash. For example,
`https://portal.fixture.invalid/api/caller/redemptions` shows the exact route;
that hostname is an unconfigured placeholder, not an existing server.

The JSON body has exactly these fields:

```json
{
  "CallerSN": "<private fixture Caller identity>",
  "APIKey": "<private fixture Caller credential>",
  "qr": "<unchanged fixture QR>",
  "player_tablet_id": "fixture-tablet-A",
  "request_id": "<persisted lowercase UUIDv4>"
}
```

Credentials belong only in the JSON body, never query strings/URLs. Existing
Caller authentication resolves the Caller record and Hall; clients cannot submit
a Hall ID or override `caller_id`. The public Portal route does not expose an
operating-Session assertion. The exact purchased Commerce occurrence is sufficient;
Portal Session-template mapping is not another eligibility requirement.

Portal internally posts to:

```text
POST {{commerce_network_origin}}/wp-json/bingocommerce/v1/portal-halls/{authenticated_hall_id}/redemptions
Authorization: Basic <dedicated service Application Password>
Content-Type: application/json
```

This internal URL is for server configuration and isolated authentication testing,
**not an alternative tablet/Caller path**. Privately configure existing Portal
`BINGOCOMMERCE_URL`, `BINGOCOMMERCE_APP_USERNAME` and
`BINGOCOMMERCE_APP_PASSWORD` for the isolated environment. The URL is the WordPress
network-main origin. Portal has only this one Commerce credential, shared by the
existing Hall provisioning calls and Caller redemption. Both Commerce endpoints
refuse Network Super Admins, so that single non-Super-Admin Portal service account
needs both the existing `bc_receive_portal_provisioning` capability and
`bc_receive_portal_redemptions`; broad management capabilities are unnecessary.
The plugins grant neither capability to any role automatically. Provision/revoke the
Application Password through WordPress's existing mechanism; never commit it or
share it with tablets. The disposable fixtures below create their own owned,
redemption-only account and never use or change the configured service account.

Portal forwards `qr`, `request_id`, `player_tablet_id` and its authenticated
`caller_id`. Commerce independently resolves the active Hall mapping to a
same-network non-main site. Archival/billing suspension does not independently
invalidate a paid ticket; invalid identity mappings and deleted/spam sites fail.

The existing limiter remains **15 requests/minute per CallerSN**, with its existing
IP fallback; other Caller traffic can share that bucket. After 429, obey
`Retry-After` and retain the original request ID. Portal→Commerce has five-second
connect/thirty-second total timeouts. The ADR-047 lifecycle lock wait is at most four
seconds: its timeout is set a full second inside both the session's SELECT bound
(five seconds, lower when the operation budget is short) and the remaining budget,
or the wait is refused before it starts. Ordinary contention is therefore a known
retryable 503, not a cancelled lock request. The bounded database/session timeout
profile is implemented under ADR-047; no lock spans an external HTTP call.

## Importing Postman

1. Import `tests/postman/qr-redemption.postman_collection.json`.
2. Create a private local environment. Mark keys and QR values as secrets; never
   export/sync an environment containing usable credentials.
3. Populate the following variables from an owned disposable fixture handoff:

   | Variable | Meaning |
   |---|---|
   | `caller_sn`, `caller_api_key` | Active assigned Caller in fixture Hall A |
   | `other_caller_sn`, `other_caller_api_key` | Another authorised Caller in Hall A |
   | `qr_a`, `qr_b`, `qr_c` | Three unredeemed Hall A tickets for the same exact occurrence and Game Type, each contributing 12 digital faces |
   | `qr_other_hall` | Ticket owned by fixture Hall B |
   | `qr_voided` | Separate Hall A ticket with an existing terminal void marker |

4. Set collection variable `portal_base_url` to the isolated Portal origin.
   `tablet_a`/`tablet_b` default to simulated identities: backend test doubles,
   not a real-device identity scheme.
5. Scripts create each scenario's request UUID only when its stored variable is
   empty and preserve it on failure. JSON is built with `JSON.stringify` to
   preserve opaque identity bytes. No script logs credentials.
6. Use a fresh prepared fixture set and run the acceptance folder in numbered
   order. The final folder requires the guarded `void-a` command below after
   ticket A was accepted. Do not run an automated integration runner and the
   first-acceptance Postman folder against the same already-consumed tickets.

Do not reset request IDs to recover a lost result. First-acceptance cases require
fresh fixture tickets. The stored first-redemption ID is only a comparison aid.

## Guarded retained fixtures and exact local commands

Run from the named repositories. These commands never load the development Portal
`.env` or point WordPress at the development database. Commerce verifies its existing
allowlist and DB sentinel before mutations. Portal creates a new SQLite file with
its own sentinel inside a newly created private `0700` directory; credentials and
usable QR values are `0600` files. Use **one run at a time** against the shared
`bingocommerce_state_recon` fixture database. Do not run its concurrency/regression
suites while retaining this manual fixture, because the fixture temporarily sets
and later restores that isolated database's network limit.

Before WordPress boots, the retained fixture redirects Woo logs and uploads into
that same private directory. Portal logs/cache/storage are private as well. Cleanup
removes those owned files; it never removes pre-existing development logs/uploads.

Prepare Commerce (no external payment request; native Woo payment confirmation is
used only to issue these explicitly synthetic disposable orders; mail and outbound
WordPress HTTP are blocked):

```sh
cd /Users/abdesign/Project/bingocommerce
BC_TEST_DB=bingocommerce_state_recon php tests/fixtures/qr-redemption-manual.php prepare
```

Copy the reported `fixture_dir` into this **task-specific** environment variable in
each terminal below; do not copy the directory's private contents into chat:

```sh
export BC_QR_FIXTURE_DIR=/private/tmp/bc-qr-manual-<reported-owner-uuid>
cd /Users/abdesign/Project/bingo_portal
php tests/fixtures/qr-redemption-manual.php prepare
```

The Portal command creates the two matching Hall IDs, two authorised Hall A
Callers and one Hall B Caller in **its new SQLite database only**. It writes
`$BC_QR_FIXTURE_DIR/postman.private.json`, a ready-to-import private environment.
It uses the fixture-created least-privilege Application Password automatically;
no development credential or configuration needs editing. Import that environment
locally, mark values private, and never publish/sync/export it. The collection has
no usable QR or key values.

Start the two loopback servers in separate terminals, keeping them foreground so
Ctrl-C stops each exact owned process:

```sh
# Commerce terminal
cd /Users/abdesign/Project/bingocommerce
php -S 127.0.0.1:18782 tests/fixtures/qr-redemption-http-router.php
```

```sh
# Portal terminal
cd /Users/abdesign/Project/bingo_portal
php -S 127.0.0.1:18781 tests/fixtures/qr-redemption-manual.php
```

Both require the same `BC_QR_FIXTURE_DIR` exported above. Only the exact redemption
paths are served; there is no file/static route. The Commerce bridge spawns the
existing guarded `php-cgi` worker, which executes actual WordPress Basic Application
Password authentication, REST permissions, Hall resolution and domain persistence.
The fixture synthesizes CGI HTTPS context because WordPress requires it for
Application Passwords; the actual sockets here are deliberately **loopback HTTP**,
not a production TLS test. Outside this local fixture, HTTPS remains required.

Exact Postman URL: `http://127.0.0.1:18781/api/caller/redemptions` with JSON headers
and the private environment. The existing Caller limiter is unchanged. Optional
alternative free ports must be set with `BC_QR_PORTAL_ORIGIN` and
`BC_QR_COMMERCE_ORIGIN` during Commerce preparation, and then used for both servers.
Successes show `game_type_name: null` (see the display-name note below).

Inspect accepted rows and independently derived per-tablet totals at any step:

```sh
cd /Users/abdesign/Project/bingocommerce
BC_TEST_DB=bingocommerce_state_recon php tests/fixtures/qr-redemption-manual.php inspect
```

Inspection prints no QR, API key, Application Password or billing details. Pending
requests are separate from accepted rows; after rejected/recovered requests, check
that **accepted_count and derived_totals** remain unchanged. It reports tablet
identity as exact-byte hex so case/whitespace identities cannot be confused.

After Postman cases 01–10, create a terminal void for owned ticket A and run case11:

```sh
BC_TEST_DB=bingocommerce_state_recon php tests/fixtures/qr-redemption-manual.php void-a
```

This command proves the ticket belongs to this fixture before performing native
cancellation. It does not expose an administrative API or touch an existing order.
Accepted history/usage must remain after the void; original recovery must fail410.

Alternatively, run the real server-to-server verification on **fresh** fixtures:

```sh
cd /Users/abdesign/Project/bingo_portal
php tests/qr-redemption-relay-integration.php
```

It uses actual HTTP requests and both authentication boundaries. Only a deliberate
lost-response fault is injected in the owned Commerce fixture bridge: one real
committed success is withheld so the original request must recover it. The runner
uses simulated tablet IDs, tests cross-Hall/caller routing and cumulative allowance,
records request latency samples, and leaves the owned rows available for inspection.
It does not test real Android or establish production throughput.

Stop both servers with Ctrl-C, then clean up in this order:

```sh
cd /Users/abdesign/Project/bingo_portal
php tests/fixtures/qr-redemption-manual.php cleanup
cd /Users/abdesign/Project/bingocommerce
BC_TEST_DB=bingocommerce_state_recon php tests/fixtures/qr-redemption-manual.php cleanup
```

Portal cleanup verifies its SQLite sentinel and removes its database/Caller secrets.
Commerce cleanup verifies each Hall/user ownership marker, removes only its Hall
sites/tables/mappings/audit rows and service account/Application Password, restores
the prior fixture limit and verifies seed Halls2/3/4 unchanged. It then removes the
owned private directory, including usable QR files. It never drops the shared
fixture database or resets development data. Setup checkpoints preserve ownership
for explicit cleanup if a later preparation step fails. Do not remove the directory
before Commerce cleanup has read its ownership/checkpoint state.

Pure checks, with mocked boundaries explicitly stated in their output:

```sh
cd /Users/abdesign/Project/bingocommerce
php tests/qr-redemption-adapter-contract.php
php tests/qr-redemption-domain-contract.php
```

Portal `RedemptionRelayTest` covers the authenticated route with Commerce HTTP
mocked. The loopback integration runner above supplies the separate real-network
verification. Independent-process concurrency and representative ledger performance
remain the separate Commerce integration suite's responsibility.

## Expected acceptance cases

| Case | Expected result | Accepted history/usage |
|---|---|---|
| Ticket A, tablet A, original request | 200, `recovered:false`, stable redemption ID | One accepted row; total 12 |
| Same original request | 200, `recovered:true`, same payload/ID | Still one row; total 12 |
| Ticket A, fresh request | 409 `already_redeemed` | Unchanged |
| Original ID with tablet B | 409 `request_id_conflict`; no previous result | Unchanged |
| Original tablet/request via another Hall A Caller | 200 recovery | Same tablet usage; no second allowance |
| Ticket B, tablet A | 200 | Two accepted rows; total 24 |
| Ticket C, tablet A | 409 `electronic_limit_exceeded` | No partial acceptance; total 24 |
| Ticket C, tablet B, new request | 200 | Tablet A stays 24; tablet B has 12 |
| Hall B QR via Hall A Caller | 403 `wrong_hall` | No acceptance in either Hall |
| Already-voided ticket | 410 `ticket_voided` | No acceptance |
| Original A request after a fixture-only void | 410 `ticket_voided` | Accepted history/contribution remain |

Commerce's success contains `api_version`, original `request_id`,
`player_tablet_id`, stable `redemption_id`, `recovered`, `state:"redeemed"`, saved
`session`/`package`, and `digital_faces_by_game_type`. Session keys are
`commerce_session_id`, `name`, canonical `date`, `start_time_local`,
`timezone_name`, `starts_at_utc`; legitimate saved null timing stays null. Package
keys are `commerce_package_id` and `name`. Commerce's digital entries contain only
verified `portal_game_type_id` and integer `faces`. Paper contributes zero. No
historical component breakdown, price or billing information is returned; no
current Catalog composition is reconstructed. Commerce saves exactly this response
(with `recovered:false`) in the ledger's `response_json`; recovery returns that
saved payload with `recovered:true`.

The Caller/Postman response comes from Portal, not directly from Commerce. Only
after validating Commerce's success does Portal add `game_type_name` to each
digital entry: the current Portal display name for that `portal_game_type_id` in
the authenticated Hall, or `null`. Every other field is Commerce's response
unchanged. Names are never stored in Commerce's ledger and never affect
eligibility, recovery or limits; a Portal rename changes only the label on a later
recovery.

### Display names in the manual fixture

Portal's bounded name lookup is enabled only on its verified native MySQL profile
(see Portal `docs/BINGOCOMMERCE_INTEGRATION.md`, "Bounded display lookup"). The
manual Portal fixture uses SQLite, so **every Postman success shows
`game_type_name: null`, even if matching Game Types are inserted** into the owned
fixture database. This is expected and does not affect redemption, recovery or
limits. The former SQLite insert/rename recipe no longer applies.

To check names and the one-second lookup bound, use Portal's
guarded native MySQL test, run from the Portal repository as documented there:

```sh
BC_NAME_TIMEOUT_TEST=1 php tests/qr-redemption-name-timeout.php
```

It creates and removes only its own disposable schema; never point it at, or edit
Game Types in, development Portal data.

Errors contain `api_version:1` and `error:{code,message,retryable}`. Statuses:
400 malformed input; 401/403 authentication/authority/Hall mismatch; 404 a
successfully read unknown ticket; 409 conflict/limit; 410 terminal void; 422 invalid
saved facts; 429 rate limit; 503 mapping/dependency/database unavailability or
uncertain writes. A database failure is neither 404 nor success. Preserve the
original request ID on 429/503.

## Read-only ledger checks

Obtain the Hall prefix from verified fixture context, never from the QR. Against
the explicitly selected owned fixture database, substitute that trusted prefix:

```sql
SELECT state, COUNT(*) AS rows_in_state
FROM <verified_hall_prefix>bingo_redemptions
GROUP BY state;

SELECT request_id, HEX(player_tablet_id) AS tablet_bytes, session_id,
       accepted_entitlement_id, redemption_id, contributions_json
FROM <verified_hall_prefix>bingo_redemptions
WHERE state = 'accepted'
ORDER BY request_id;

SELECT accepted_entitlement_id, COUNT(*) AS accepted_count
FROM <verified_hall_prefix>bingo_redemptions
WHERE accepted_entitlement_id IS NOT NULL
GROUP BY accepted_entitlement_id
HAVING COUNT(*) > 1;
```

The last query must return no rows. Decode accepted `contributions_json` as the
stored local-Game-Type→positive-face map; group by exact `session_id` and
byte-identical tablet ID and sum each Game Type independently. Do not count pending
journal rows or recompute Catalog composition. Compare accepted rows/JSON and
derived totals before/after each recovery and rejection. A rejected request may
leave a pending journal entry that consumes nothing, so total row count alone is
not an allowance check.

Never include raw QR, API keys or Application Passwords in inspection output. The
ledger stores an intent hash, not the QR. Cleanup must remove only creator-owned
sites/rows/users, revoke its Application Passwords and report zero owned artifacts
remaining. Never drop a database without its allowlist and sentinel checks.

## Focused verification follow-up (2026-10-06)

Executed sequentially against `bingocommerce_state_recon`, with PHP 8.4.24 and
MySQL 9.5.0. No production PHP or Portal files changed in this follow-up.

```sh
BC_TEST_DB=bingocommerce_state_recon /opt/homebrew/opt/php@8.4/bin/php tests/qr-redemption-persistence.php
BC_TEST_DB=bingocommerce_state_recon /opt/homebrew/opt/php@8.4/bin/php tests/digital-entitlement-snapshot.php
```

The first persistence run passed 112 checks in 56.242 seconds. Entitlement then
passed 1,304 checks in 35.288 seconds. One explicit immediate post-race safety
assertion was added, and persistence alone reran: **113 passed, zero failures in
58.638 seconds**. No suites overlapped; no existing cancellation or schema-failure
case was removed. These totals describe executed assertions, not coverage targets.

- **Package refund/payment race:** separate PHP processes execute real
  `wc_create_refund(refund_payment:false)` and `payment_complete()`. A native
  marker-insert trigger and two process signals establish overlap. Independent
  merchandise keeps the whole-Package refund partial at order level, allowing
  Woo's actual payment observer to execute without a status-filter bypass.
  The matching durable refund marker prevents an entitlement or usable ticket
  immediately and after two native repeats plus explicit observer redeliveries.
  Frozen quantities, saved purchase facts, original line items and financial
  totals remain unchanged; the single refund and marker survive unchanged.
- **Individual marker-read failure:** only after native schema verification
  succeeds, a test callback in the limit-preparation phase temporarily renames the
  owned marker table. The subsequent real native marker SELECT fails; its SQL or
  return value is not mocked. Redemption returns retryable 503
  `verification_unavailable`, with no journal/acceptance/usage write. The native
  convergence primitive used by `enforce_block()` separately throws rather than
  reporting no marker. The original request accepts once after restoration.
  The existing entitlement test for failure at **schema verification** remains a
  separate executed check. The primitive check does not claim a new end-to-end
  payment-observer injection at that exact native-read boundary.

Final persistence measurements: `tablet_session` index at 1,000/10,000 synthetic
historical rows, estimated matches 2/21; ten actual acceptances per size, p50
43.630/44.280 ms and p95/max 46.282/46.263 ms. Advisory/row/metadata waits refused
safely after 5.045/5.089/5.072 seconds. These are local samples, not production
throughput proof. The existing cancellation races and recovery checks also passed.

Cleanup removed the persistence Halls 1700/1701 and 1704/1705, their service users,
and both entitlement Halls; each suite restored the fixture limit and verified
seed Halls 2/3/4 unchanged. A separate read-only audit of IDs 1700–1705 found zero
sites, Hall tables, triggers, mappings or audit rows, and zero QR fixture service
ownership markers. Evidence is retained in
`/private/tmp/qr-redemption-focused-evidence-20261006/` (`results.json`, logs and
`cleanup.json`). No development Hall or external payment service was used.

**Not rerun in this follow-up:** customer Chrome/Vision, CGI authentication,
Portal/relay, checkout and Postman UI. Production/customer code did not change.
Real native SQL and Woo persistence were exercised; external payment transport,
email delivery and tablet hardware were not. Android attribution/loading, packet
level SQL acknowledgement loss, global server capability failures and production
load remain limitations, as below.

Historical evidence correction: the earlier 747 customer result contains **746
executed checks plus one skip notice**. Ten optional preview checks (five per
viewport) were omitted because `BC_TICKET_WAIT_SHOTS` was unset; the two Vision
decodes still ran. The earlier 1,330 → 1,304 entitlement change was 31 removed
obsolete marker-insert hook checks plus five native fault setup/cleanup/marker
checks. The snapshot-insert refund/cancellation cases stayed. The native refund
race and post-schema marker-read gap are now explicitly covered above, rather
than claimed to have been equivalent coverage in that earlier run.

## Lock-wait correction (2026-10-08)

Review finding F1: MySQL applies the session `max_execution_time` to
`SELECT GET_LOCK(...)`. A five-second lock wait under the five-second SELECT bound
(or a lower bound after slow preparation) let contention cancel the lock request
instead of returning "not acquired". That unknown acquisition correctly failed
closed, but the PHP process then refused every later redemption, payment-time block
and void until it ended. `LifecycleTransport` now sizes the wait a full second
inside both the active SELECT bound and the remaining budget before acquisition
becomes uncertain, and refuses to wait when no whole second fits. Handling of a
genuinely uncertain acquisition or release is unchanged.

The same-process regression holds the Hall mutex from a separate real connection
for normal contention, a reduced SELECT budget and an insufficient budget. After
releasing it, the same process must accept the original request and record and
converge a void. Run it alone (about 50 seconds); it is also part of the full
persistence run, and measured results are in PROJECT_STATUS.md:

```sh
BC_TEST_DB=bingocommerce_state_recon php tests/qr-redemption-persistence.php --same-process-contention-only
```

## Earlier verification and remaining limitations

The 2026-10-06 fresh disposable loopback run passed **37 assertions / 18 actual HTTP
requests** on PHP **8.4.24**, MySQL **9.5.0**. Caller authentication, trusted Hall,
Application Password authentication, REST dispatch and database acceptance were real.
Only the post-acceptance response discard was injected by the test bridge. Local
sequential latency including CGI startup was p50 **860.714 ms**, p95 **877.199 ms**,
max **1231.013 ms**; this is not a production throughput measurement. Accepted totals
remained 24/12 across two Hall A tablets and 12 independently in Hall B after retries,
rejections and a subsequent void. Final cleanup removed owned Hall sites 1686/1687
(and earlier run 1684/1685), their service credentials, private SQLite/logs/uploads and
directories; the isolated network limit was restored and seed Halls 2/3/4 were
unchanged. Both fixture servers stopped. The private log scan found no QR/API/
Application Password values. A first harness-only malformed-literal leakage check
was corrected before the successful fresh run. The Postman JSON was parsed and its
scenarios mirror this run; importing/running it in the Postman application remains
a manual handoff, not an already-performed UI test.

Sequential Postman cases do not prove concurrency. The final guarded
`BC_TEST_DB=bingocommerce_state_recon php tests/qr-redemption-persistence.php`
run passed **89 real database/process assertions**. It covered identical requests,
fresh competing scans, whole-ticket limits, simultaneous redemption/cancellation,
both deterministic ordering outcomes, durable-marker/status failure, connection
replacement/loss, context switches, reentry, interrupted registration and lost
responses. Native rows were strictly equal to WordPress rows. Changed context or
connection produced 503 without even a pending journal write. Accepted recovery
used its stored response despite later changes to saved details, mappings or limits.

At 1,000 and 10,000 synthetic historical ledger rows, ten actual domain acceptances
per size measured p50 **38.987/37.317 ms**, p95/max **42.117/42.731 ms**. The usage
query chose `tablet_session` (`ref`, three constants), estimating **2/21** matching
rows. Real advisory, row and metadata contention returned safely after
**5.040/5.076/5.076 seconds** against deliberate approximately nine-second holds;
original requests succeeded after release. These are local sample measurements,
not production capacity evidence. There are no independent allowance counters.

The 25-second transport budget prevents starting further statements after expiry;
row/metadata waits and supported SELECT execution have explicit bounds. MySQL's
SELECT timeout does not bound arbitrary DML CPU or network acknowledgement. Global
instrumentation loss/read-only mode and other database vendors were not induced on
the shared MySQL server; unavailable required capabilities fail closed. A lost
post-acceptance response and an interrupted native writer were separately tested;
packet-level SQL acknowledgement loss was not injected. A refund provider's action
and Woo notification delivery remain outside the Hall mutex's local guarantee.

Real Caller/tablet attribution, local reconnection, response routing and duplicate
loading prevention remain joint verification. No device registration, local
communication or loading code is included. Staff reactivation, maintenance jobs
and rollout automation remain pending; offline authorization is deferred.
Payment, checkout, receipts, emails and coupons are unchanged.
