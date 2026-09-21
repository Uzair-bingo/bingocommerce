# ADR-043 (DRAFT) — Commerce Schedule and Dated Occurrence Persistence

**Status:** DRAFT — not approved. An implementation exists in the current
repository and is not deployed. This document does not itself
change production data.

**Date drafted:** 2026-09-16 · **Revision:** 9 (BR-077 ratifies the thirty-day
window; BR-078 adds centralized committed-definition membership to availability,
Upcoming, administration and purchase eligibility. Removed future occurrences
are turned OFF on a successful definition edit; reconciliation and history
rules remain unchanged. The earlier Revision 9 advisory-lock, disable-first,
readiness and automatic-archival amendments are withdrawn. **Status remains
DRAFT; this document does not claim deployment.**) · **Revision:** 8 (Hall Manager UX correction:
manager-facing navigation, labels and create/generate orchestration described in
§15; sales window widened from five to thirty calendar days everywhere BR-051/
BR-063 apply; a Portal-connected hall's Game Type creation gated in
`GameTypeService::create()`. **No change to the schema, scheduling engine,
synchronization contract or reconciliation logic in §1–§14 and §16–§19** — see
§15's amendment note for exactly what changed and what did not) · **Revision:**
7 (implementation amendments: fixture-isolated test matrix, utf8mb4_bin on
`bingo_sessions` identity columns; otherwise Revision 6 unchanged) ·
**Revision:** 6 (template-eligible definition
commit, honest Package-validation timing, binary template-name proof on every
name write, non-empty Package-set proof, proved superseded-definition cleanup,
unsettled-ON survival semantics, non-destructive integrity runbook, verified
rollback baseline commit)

**Supersedes:** nothing. **Narrows:** nothing.
**Closes:** the deferred compatibility/scheduling design that ADR-035, BR-073 and
`docs/architecture/catalog-session-persistence-design.md` §2.8 each defer to
"the Session compatibility/scheduling design".

**On approval**, the body of this file is merged into
`docs/decisions/DECISIONS.md` as the ADR-043 entry and this draft file is
deleted. It is kept separate while in review so an unapproved decision never sits
inside the ratified decision log.

---

## 0. Scope

**This phase defines, and the current repository implements:** the
schedule and occurrence schema; definition-commit concurrency; run-fenced
reconciliation; timezone and DST; guarded availability with owned compensation,
retime and withdrawal; convergent incomplete-generation recovery; mutation
ownership over generated occurrences; database-contention handling; Portal-sync
readiness separation; rollback restrictions; permission, nonce, audit and
isolation rules. The implementation does not approve this draft or claim a
production deployment.

**This phase does not decide and does not implement:** the customer storefront
calendar; checkout, cart, orders or payment; entitlement, QR or redemption;
Portal webhooks, scheduled synchronization or reconciliation; SSO; and any change
to the Laravel Portal, which remains completely unchanged.

**Explicitly deferred to a later, separately reviewed design** (named so it is
not mistaken for an omission):

- garbage collection of **crash-abandoned private definition tokens** (§3.6);
- a durable order → occurrence reference for checkout (§7.2);
- exposing Game Type on the supported Package component read (§13.2);
- hiding `Create Game Type` in the WordPress UI (§14).

### 0.1 SQL shapes verified against the installed database — and what that proves

Every Revision 1–8 non-trivial statement shape was validated read-only against the live
MySQL 9.5.0 on 2026-09-16 using `EXPLAIN`, which parses, resolves names and plans
without executing. Revision 9's shared membership predicate and post-edit OFF
statement must be exercised by the focused isolated suites; the historical
validation below is not a claim that those new statements were tested then:

| Shape | Result |
|---|---|
| Multi-table `DELETE s, p FROM … JOIN … LEFT JOIN …` with `EXISTS` guards | **accepted** — §7.2 atomic withdrawal is source-compatible |
| Multi-table `DELETE p FROM … JOIN … JOIN …` with template `EXISTS` and a binary name proof | **accepted** — §7.3 step 2 is expressible |
| `UPDATE t … JOIN other … WHERE EXISTS (SELECT 1 FROM third …)` | **accepted** — §6.1, §7.3, §7.4, §7.5 fences are expressible |
| `UPDATE schedules s … WHERE EXISTS (SELECT 1 FROM templates t WHERE t.portal_session_id = s.portal_session_id …)` | **accepted** — §3.4, §3.5, §3.7 template guards are expressible |
| `CAST(t.name AS BINARY) = CAST(%s AS BINARY)` inside a guard | **accepted**, and measured **case- and accent-sensitive** (§7.8) |
| `EXISTS` proving a non-empty definition Package set | **accepted** — §7.3 step 4 and §10 are expressible |
| Double `NOT EXISTS` set-equality proof | **accepted** — §7.3 step 4 exact-set proof is expressible |
| `INSERT … SELECT … FROM DUAL WHERE EXISTS (…) AND EXISTS (…)` | **accepted** |
| `INSERT … SELECT … WHERE NOT EXISTS (SELECT 1 FROM <insert target>)` | **accepted** — the 1093 restriction does **not** extend to an INSERT's own target |
| `UPDATE` / `DELETE` whose subquery `FROM` names its **own target table** | **REJECTED — MySQL error 1093** |

**Error 1093 is a hard constraint on `UPDATE` and `DELETE`.** No such subquery may
name the statement's own target table in its `FROM` clause. Every guard below
therefore reaches other tables by `JOIN` or by `EXISTS` over a *different* table,
and correlates to the target only through column references.

**What `EXPLAIN` does NOT prove.** It validates syntax, name resolution and the
execution plan. **It proves nothing about locking, isolation, statement ordering,
or correctness under concurrent interleaving.** No concurrency claim in this ADR
rests on it. Every such claim is assigned a deterministic worker test in §16, and
the following must pass before the implementation may be called complete:

- dependency invalidation between validation and definition commit (test 19);
- template rename immediately before a name-writing statement (tests 25–27);
- same-token repair convergence (tests 53–54);
- cross-token repair (test 55);
- stale writer after completion (test 57);
- superseded-definition cleanup versus an old-token worker (tests 21–23);
- ON compensation ownership, including the three-request case (tests 38–40);
- unsettled ON surviving process termination (test 42);
- ON versus withdrawal (test 34) and ON versus retime (test 35);
- deadlock (1213) and lock-wait timeout (1205) injection (tests 70–71).

The implementation must also re-run the `EXPLAIN` validation against its own
final statements (test 7) rather than trusting this table.

---

## 1. Model

```
bingo_portal_session_templates        Portal replica — UNCHANGED by this ADR
  portal_session_id  (Portal bingo_sessions.id)
        │  1 : N
        ▼
bingo_session_schedules               Commerce authority (NEW)
  timezone_name, start_time_local, mode, weekday_mask, bounds,
  active_definition_token
        │  1 : N
        ▼
bingo_sessions                        Dated occurrence — ADDITIVE columns only
  schedule_id, start_time_local, timezone_name, starts_at_utc,
  sale_locked_at, generation_completed_at, availability_operation_token
        │  1 : N
        ▼
bingo_session_packages                Occurrence availability — UNCHANGED
```

Seven commitments:

1. **`bingo_sessions` continues as the dated-occurrence table.** ADR-035 and
   BR-073 forbid deleting or replacing it.
2. **Commerce selection is never stored on the replica.** A template is
   "selected for Ecommerce" exactly when a committed active schedule references
   it. This is required, not stylistic: the replica verifier demands exact
   column-set equality
   (`wp-content/plugins/bingocommerce-sessions/src/Migrations.php:356`) and
   `tests/portal-session-template-replica-persistence.php:342-350` fails on
   `selected_for_commerce`, `available_for_commerce`, `enabled_for_commerce`,
   `sell_online`, `session_date`, `weekday`, `recurrence`, `start_time`,
   `package_id`, `days` and `portal_updated_at`.
3. **The schedule links by `portal_session_id`**, the ADR-035 trusted identity.
   **Immutable** after creation.
4. **No manager-controlled display name exists.** Names are derived
   deterministically in a fixed storage format (§4.2), and every statement that
   writes one proves the source template name has not changed (§7.8).
5. **The past is immutable.** No path creates, restores, moves, renames, retimes
   or deletes an occurrence whose date is not strictly future (§7).
6. **The schedule owns its generated occurrences.** Name, date, time and
   lifecycle of a row with `schedule_id IS NOT NULL` change only through
   reconciliation and the guarded availability path (§9).
7. **Every reconciliation write is fenced on one definition.** A run captures
   `$run_definition_token` at preflight and proves it in the `WHERE` clause of
   every mutation (§7.1). A run never mixes two definitions.

---

## 2. Final Schema

`Sessions\Migrations::DB_VERSION` 2 → **3**. All tables per-site, isolated by
`$wpdb->prefix`, with no `blog_id` column (AGENTS.md rule 6), InnoDB, utf8mb4,
and no physical foreign keys — matching every existing BingoCommerce table.

### 2.1 NEW — `$wpdb->prefix . 'bingo_session_schedules'`

```sql
CREATE TABLE {prefix}bingo_session_schedules (
  schedule_id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_session_id       bigint(20) unsigned NOT NULL,
  timezone_name           varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  start_time_local        time                NOT NULL,
  mode                    varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  weekday_mask            tinyint(3) unsigned NOT NULL DEFAULT 0,
  recurrence_start        date                         DEFAULT NULL,
  recurrence_end          date                         DEFAULT NULL,
  active_definition_token char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  status                  varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  created_at              datetime            NOT NULL,
  updated_at              datetime            NOT NULL,
  archived_at             datetime                     DEFAULT NULL,
  PRIMARY KEY  (schedule_id),
  KEY portal_session_status (portal_session_id,status,schedule_id)
) ENGINE=InnoDB;
```

| Column | Rule |
|---|---|
| `portal_session_id` | Portal `bingo_sessions.id`. **Immutable** — never in an UPDATE assignment list. |
| `timezone_name` | IANA identifier. Written **once**, by the provisional INSERT. **Immutable for the schedule's whole life** (§5.1). |
| `start_time_local` | `TIME`, **NOT NULL**, always normalized to `HH:MM:00` (§5.4). |
| `mode` | `dates` or `weekly`. |
| `weekday_mask` | Bit 0 = Monday … bit 6 = Sunday (ISO-8601). Validated scalar, **never** a SQL predicate. `dates` requires `0`; `weekly` requires `> 0`. |
| `recurrence_start` / `recurrence_end` | Both SQL `NULL` for `dates`. Both NOT NULL for `weekly`, `start <= end`, span ≤ 366 calendar days. Creation requires `start >= tomorrow`; editing does not (§8.2). |
| `active_definition_token` | Lowercase UUID v4 naming the committed definition. **`NULL` means no readable definition** — inert, generates nothing, excluded from every eligibility predicate. |
| `status` | `active` or `archived`. |

`ascii_bin` on `timezone_name`, `mode`, `status` and `active_definition_token`
follows the precedent and reasoning at
`wp-content/plugins/bingocommerce-portal-integration/src/ProvisioningMigrations.php:428-441`:
under a case-insensitive collation `A3F` and `a3f` would be the same UUID.
`wp_generate_uuid4()` emits lowercase and the writer applies `strtolower()`
defensively as `ProvisioningOperationRepository.php:940` does.

### 2.2 NEW — `$wpdb->prefix . 'bingo_session_schedule_dates'`

```sql
CREATE TABLE {prefix}bingo_session_schedule_dates (
  schedule_date_id  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  schedule_id       bigint(20) unsigned NOT NULL,
  definition_token  char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schedule_date     date                NOT NULL,
  created_at        datetime            NOT NULL,
  PRIMARY KEY  (schedule_date_id),
  UNIQUE KEY schedule_token_date_unique (schedule_id,definition_token,schedule_date)
) ENGINE=InnoDB;
```

Holds **only explicitly selected dates for `mode = dates`**. A `weekly` schedule
stores zero rows here. Maximum **400 unique dates per definition token** — a
limit on the *active definition*, not on the schedule's lifetime (§8.3).

### 2.3 NEW — `$wpdb->prefix . 'bingo_session_schedule_packages'`

```sql
CREATE TABLE {prefix}bingo_session_schedule_packages (
  schedule_package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  schedule_id         bigint(20) unsigned NOT NULL,
  definition_token    char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  package_id          bigint(20) unsigned NOT NULL,
  created_at          datetime            NOT NULL,
  PRIMARY KEY  (schedule_package_id),
  UNIQUE KEY schedule_token_package_unique (schedule_id,definition_token,package_id),
  KEY package_schedule (package_id,schedule_id)
) ENGINE=InnoDB;
```

**At least one row per definition token is a hard requirement**, and it is proved
in SQL at three separate points rather than trusted: the phase-2 gate (§10), the
generation-completion statement (§7.3 step 4), and final verification (§7.12). A
definition with zero Package rows can neither complete an occurrence nor produce
`status = ok`.

These Packages are written onto an occurrence **only** by the internal generation
writer (§7.10), only while that occurrence is incomplete.

### 2.4 ADDITIVE on `$wpdb->prefix . 'bingo_sessions'`

```sql
ALTER TABLE {prefix}bingo_sessions
  ADD COLUMN schedule_id                  bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN start_time_local             time                DEFAULT NULL,
  ADD COLUMN timezone_name                varchar(64) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD COLUMN starts_at_utc                datetime            DEFAULT NULL,
  ADD COLUMN sale_locked_at               datetime            DEFAULT NULL,
  ADD COLUMN generation_completed_at      datetime            DEFAULT NULL,
  ADD COLUMN availability_operation_token char(36) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD UNIQUE KEY schedule_occurrence_unique (schedule_id,session_date),
  ADD KEY starts_at_utc_idx (starts_at_utc);
```

**Seven nullable columns.** Every existing column, index and row is untouched.
`UNIQUE (session_date, display_name)` **stays**: `dbDelta()` cannot drop it,
`verify_sessions_schema()` asserts it (`…/Migrations.php:275`), and four tests
depend on it.

| Column | Meaning |
|---|---|
| `schedule_id` | NULL = legacy/manual, behaviour unchanged. NOT NULL = generated, schedule-owned (§9). |
| `starts_at_utc` | The stable instant. Immune to any later change of the hall's WordPress timezone setting. |
| `timezone_name` | The zone that produced that instant, and the zone in which this occurrence's "today" and "strictly future" are evaluated (§5.3). |
| `start_time_local` | The wall time the occurrence was generated and sold as. Normalized `HH:MM:00`. Never re-derived from `starts_at_utc`. |
| `sale_locked_at` | Durable proof the occurrence has **ever** been Available for Sale. Set once, server-side, inside the guarded ON statement (§6.1). **Never cleared by anything, and an occurrence carrying it is never hard-deleted by any path** (§7.2, §19). |
| `generation_completed_at` | NULL while Package propagation is unfinished. Until set, the occurrence is **unsellable** and closed to manual Package edits. |
| `availability_operation_token` | The UUID of the availability request that most recently drove this occurrence ON. It is the **ownership fence** that lets exactly that request — and no older one — compensate its own failure (§6.2). **A non-NULL value means an ON transition that has not yet settled**, which may include one whose process died (§6.5). |
| `session_date` | Unchanged, still the sole date input to the BR-051/BR-063 thirty-day predicate (Revision 8; five days in Revisions 1-7). |

BR-077 ratifies the numeric thirty-day window. Historical five-day references
in BR-051, BR-063, ADR-035, BR-071 and BR-073 are superseded only in that number.

**Collation, and why these two columns differ from the new tables (Revision 7,
implementation correction — the DDL above carries it).** The two identity columns
ON `bingo_sessions` — `timezone_name` and `availability_operation_token` — use
**`COLLATE utf8mb4_bin`**, NOT `CHARACTER SET ascii COLLATE ascii_bin`. Revision 6
specified `ascii_bin` here; that was wrong, and the DDL above is the corrected
form. The three NEW tables keep `ascii_bin`, which is correct there because every
collated column in them is ascii.

This is not a preference. `wpdb::get_table_charset()` derives ONE charset for a
whole table from its columns and, on a mix, falls through to *"Two mixed
character sets. ascii."* (`wp-includes/class-wpdb.php:3284-3286`). Adding ascii
columns beside the utf8mb4 `display_name` therefore makes `wpdb` treat
`bingo_sessions` as an ascii table and **reject every non-ASCII display name** —
*"Could not perform query because it contains invalid data."* Existing halls
already store Session names containing en dashes, so the literal DDL of
Revision 6 would have broken them. Verified by reproduction against the installed
MySQL 9.5.0 before the correction and after.

`utf8mb4_bin` preserves the property the ADR actually requires — exact, case- and
accent-sensitive comparison, so `A3F` and `a3f` are never the same token —
measured live: `A3F` vs `a3f`, `Evening` vs `evening` and `Evening` vs `Évening`
all compare **unequal** under it.

`availability_operation_token` is byte-exact for the same reason as every other
UUID column here. **`updated_at` is deliberately not used as the ownership
fence**: nothing in source proves it unique or strictly monotonic across
concurrent requests within the same second.

`UNIQUE (schedule_id, session_date)` is the duplicate guard for generated
occurrences. MySQL treats NULLs as distinct, so unlimited legacy rows with
`schedule_id IS NULL` remain legal — this is what makes the constraint additive.

### 2.5 Verification

`verify_current_site_schema()` gains `verify_session_schedules_schema()`,
`verify_schedule_dates_schema()` and `verify_schedule_packages_schema()`; the
**seven** new columns and two new indexes join the existing `bingo_sessions`
expectations. `assert_columns()` / `assert_indexes()` **subset** semantics are
retained — the exact-`array_keys` pattern used by the replica verifier must not be
copied onto them, or the next additive change becomes a destructive one. Column
expectations assert the collation each column actually carries, in the same shape
`ProvisioningMigrations` already uses (`:504-515`): `ascii_bin` on the three new
tables' identity columns, and **`utf8mb4_bin` on `bingo_sessions`'s two**
(`timezone_name`, `availability_operation_token`), per §2.4.

Two **data-integrity** probes accompany the structural checks, both indexed
counts that must return `0`:

```sql
-- schedules: structured time is minute-precise
SELECT COUNT(*) FROM {prefix}bingo_session_schedules
 WHERE SECOND(start_time_local) <> 0
```

```sql
-- generated occurrences: structured time is complete AND minute-precise
SELECT COUNT(*) FROM {prefix}bingo_sessions
 WHERE schedule_id IS NOT NULL
   AND (
         start_time_local IS NULL
      OR SECOND(start_time_local) <> 0
      OR timezone_name  IS NULL
      OR starts_at_utc  IS NULL
   )
```

A generated occurrence with **missing** structured time data fails verification
exactly as a non-zero-seconds one does. Legacy rows (`schedule_id IS NULL`) are
exempt by the `schedule_id IS NOT NULL` predicate and are never inspected.

---

## 3. Definition Commit

### 3.1 Why a unique token, not an incrementing revision

An integer `revision + 1` scheme is **not concurrency-safe**: two editors compute
the same `next`, both insert child rows under it, and their rows interleave into
one merged set before either pointer update runs.

A **unique token per attempt** removes the shared namespace: each request
generates its own UUID and writes its complete child set under that token, and no
other request can write into it.

A genuine transaction is not available: AGENTS.md rule 4 enumerates four approved
mysqli transports and none covers this, and ADR-033 **prohibits** `$wpdb->query()`
for any statement issued inside an open transaction, because `wpdb::query()` fires
the `query` filter on every statement and is therefore not callback-free
(`docs/decisions/DECISIONS.md:468-482`). **No fifth transport is introduced.**

### 3.2 What the commit statement can and cannot prove

The compare-and-swap in §3.4/§3.5 proves, **atomically at commit time**:

- the schedule exists and is `status = 'active'`;
- the previous `active_definition_token` is exactly what this request observed;
- **the Portal template is still commerce-eligible** — added in this revision.

It **cannot** prove Package assignability. `PackageGateway::assert_assignable()`
(`…/PackageGateway.php:118-211`) reads Catalog rows and the linked WooCommerce
product and applies BR-061/BR-067 rules; it is PHP, and no SQL statement can carry
it. **This ADR therefore does not claim Package validity is guaranteed at the
instant of commit.**

What is claimed instead, precisely:

| Check | When | Strength |
|---|---|---|
| Package assignability at create/edit | **request-time validation** (§3.7) | best available at that boundary; a Package can become unassignable between validation and the CAS |
| Non-empty definition Package set | **proved in SQL** at §10 phase 2, §7.3 step 4, §7.12 | atomic |
| Package assignability before any occurrence is created or repaired | §10 phase 2 gate | authoritative, fail-closed |
| Package assignability before an occurrence goes ON | §6.2 pre-proof **and** post-proof | authoritative, fail-closed, compensated |
| Package assignability for the storefront | §6.4 `isSellable()` | authoritative, fail-closed |
| Package assignability before `complete = true` | §7.12 | authoritative, fail-closed |

So a definition committed with a Package that became unassignable a millisecond
later is **not** a correctness hole: it commits, and then **Generate refuses to
create anything, ON refuses, and the storefront refuses**. The schedule is simply
unusable until the Package is fixed — which is the correct fail-closed outcome,
and is exactly what test 19 asserts.

### 3.3 Null-safe SQL

`$wpdb->prepare()` with `%s` and a PHP `null` does not produce SQL `NULL`; it
produces a quoted empty string. Every statement uses **literal SQL `NULL`** where
NULL is intended and a **placeholder only where a validated non-null value
exists**. No statement in this ADR uses a placeholder for a column meant to
receive SQL NULL. Each operation therefore has **two exact statements**, selected
by `mode` — an internal validated literal, never request text — before any SQL is
built.

### 3.4 Provisional create INSERT

**`mode = 'dates'`**

```sql
INSERT INTO {prefix}bingo_session_schedules
  (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
   recurrence_start, recurrence_end, active_definition_token,
   status, created_at, updated_at, archived_at)
VALUES
  (%d, %s, %s, 'dates', 0, NULL, NULL, NULL, 'active', %s, %s, NULL)
```

**`mode = 'weekly'`**

```sql
INSERT INTO {prefix}bingo_session_schedules
  (portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
   recurrence_start, recurrence_end, active_definition_token,
   status, created_at, updated_at, archived_at)
VALUES
  (%d, %s, %s, 'weekly', %d, %s, %s, NULL, 'active', %s, %s, NULL)
```

Every non-null column is satisfied. `active_definition_token` is the literal
`NULL`, never a placeholder. The row is **inert and unreadable**.

### 3.5 Create commit — compare-and-swap, template-guarded

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'dates',
       s.weekday_mask            = 0,
       s.recurrence_start        = NULL,
       s.recurrence_end          = NULL,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token IS NULL
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'weekly',
       s.weekday_mask            = %d,
       s.recurrence_start        = %s,
       s.recurrence_end          = %s,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token IS NULL
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

`timezone_name` and `portal_session_id` are absent from both assignment lists.
Zero affected rows ⇒ another request committed first, **or the template stopped
being commerce-eligible between validation and commit**. Authoritative re-read
distinguishes the two, reports `schedule_state_changed` or
`template_not_commerce_eligible`, leaves the row alone, and applies §3.7 to this
request's own token.

### 3.6 Edit commit — compare-and-swap, template-guarded

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'dates',
       s.weekday_mask            = 0,
       s.recurrence_start        = NULL,
       s.recurrence_end          = NULL,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token = %s
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.mode                    = 'weekly',
       s.weekday_mask            = %d,
       s.recurrence_start        = %s,
       s.recurrence_end          = %s,
       s.start_time_local        = %s,
       s.active_definition_token = %s,
       s.updated_at              = %s
 WHERE s.schedule_id             = %d
   AND s.active_definition_token = %s
   AND s.status                  = 'active'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

The trailing `%s` in the `WHERE` is `$seen`, the token **observed before this edit
began**.

**Which fence applies is decided by whether a definition exists, not by which
method was called.** There are exactly two cases, and both are legitimate:

| Case | Fence | Why |
|---|---|---|
| **Ordinary edit** — the schedule has a committed definition | `active_definition_token = $seen` | The edit supersedes a specific definition. Comparing against the exact token observed beforehand is what makes two concurrent editors resolve to one winner; `IS NULL` would match nothing, because the column is not NULL. |
| **Recovery of an inert provisional create** — the schedule has none | `active_definition_token IS NULL` | There is no predecessor token to compare against. §3.8 step 5 requires the inert header row to be re-savable, **keeping the same `schedule_id`**, so the Manager can fix whatever blocked the original commit and save again. This is a create-shaped commit against an existing header, not a second create: no new header row is inserted, and `portal_session_id` and `timezone_name` remain immutable because the same §3.5 assignment list is reused. |

An earlier revision of this section said `IS NULL` "is never the correct fence
here". That was wrong, and it made the inert row unrecoverable — the Manager
could only archive it. The accurate statement is that `IS NULL` is never the
fence for an edit **that has a definition to supersede**.

### 3.7 Definition-token lifecycle and cleanup

An earlier revision claimed inactive token rows accumulate only from failed
edits. **That was wrong.** Every *successful* edit also leaves its predecessor's
date and Package rows behind, so a frequently edited schedule accumulates far
more superseded rows than failed ones. Superseded rows are now cleaned
deliberately and provably.

**After a successful edit CAS**, the request may delete the rows of the token it
just superseded — `$seen` — but only after SQL proves that token is no longer
active:

```sql
DELETE d
  FROM {prefix}bingo_session_schedule_dates d
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id = d.schedule_id
   AND  sch.schedule_id = %d
   AND  ( sch.active_definition_token IS NULL
          OR sch.active_definition_token <> %s )   -- provably NOT the active token
 WHERE  d.schedule_id      = %d
   AND  d.definition_token = %s                -- $seen
```

```sql
DELETE sp
  FROM {prefix}bingo_session_schedule_packages sp
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id = sp.schedule_id
   AND  sch.schedule_id = %d
   AND  ( sch.active_definition_token IS NULL
          OR sch.active_definition_token <> %s )   -- provably NOT the active token
 WHERE  sp.schedule_id      = %d
   AND  sp.definition_token = %s               -- $seen
```

- The `JOIN … AND ( … IS NULL OR … <> %s )` is the proof. If anything restored
  `$seen` as the active token, the join matches nothing and **zero rows are
  deleted**.
- `<>` against an `ascii_bin` column is exact.
- **The guard must be NULL-safe** (corrected in implementation). After a
  *successful edit* the active token is never NULL, because this request's own
  CAS just set it — but these same two statements also serve the **failed-commit
  private-token rule** below, and after a *failed create* the active token IS
  SQL NULL. In SQL `NULL <> 'token'` evaluates to NULL rather than TRUE, so a
  bare `<>` matches nothing on exactly the schedules that most need cleaning: a
  failed create would leave its staged rows behind for ever, and a later recovery
  of that inert row would find two token sets where there should be one. The
  `IS NULL OR` branch restores the intended meaning — *this token is not the
  active one* — without weakening the proof: when a token **is** active, `IS
  NULL` is false and `<>` is false, so zero rows are still deleted.
- **The current token is never deleted**, because it is not `$seen`.
- **Another editor's staged token is never deleted**, because only `$seen` — a
  token this request observed as active and then superseded — is named.

**On a FAILED commit** (create or edit) the request applies the private-token rule
unchanged: it deletes **only the token it generated itself**, after an
authoritative re-read proves that token is not active, and deletes nothing if the
re-read fails or returns its own token.

**What remains for the deferred GC design** (§0) is now precisely one case:
**private tokens abandoned by a crashed request** — staged rows whose creating
request died between staging and its CAS, so nothing ever ran either cleanup.
Volume is bounded by crash frequency and each row is a handful of bytes.

**Interaction with a reconciliation worker on the old token.** A run that captured
`$seen` as `$run_definition_token` and is still executing when the edit commits
will find its fence broken: every guarded mutation carries
`sch.active_definition_token = $run_definition_token`, which no longer matches, so
each affects **zero rows**, and the between-phase re-proof (§7.1) stops the run
with `schedule_state_changed`. **Deleting the old token's rows underneath that
worker is therefore safe** — the worker could not have written anything with them
after the CAS regardless. Tests 21–23 prove this, including the case where the
cleanup runs while the old-token worker is mid-phase.

### 3.8 Transitions

**Create**

1. Validate the **complete** request: hall context; `bc_manage_sessions` or super
   admin; Sessions v3 current; named IANA `timezone_string` (§5.1); exact `H:i`
   time (§5.4); template commerce-eligible; mode, mask, bounds, limits; **every
   date tomorrow-or-later** (§8.1); **at least one Package, each passing
   request-time `assert_assignable()`** (§3.2, §10). Nothing is written until all
   of this passes.
2. `INSERT` the provisional header (§3.4).
3. `token = strtolower( wp_generate_uuid4() )`; `INSERT` the date rows
   (`mode = dates` only) and Package-default rows under `token`.
4. Run §3.5. ← **commit point.**
5. On zero affected rows the schedule **remains inert and unreadable**; apply
   §3.7's failed-commit rule to this request's own token only. The inert row is
   left in place, generates nothing, and is displayed as *incomplete* so the
   Manager can re-save or archive it. **Safe to retry:** yes.

**Edit**

1. Observe `active_definition_token` as `$seen`. **Two cases, and the observed
   value selects between them**: a non-NULL `$seen` is an ordinary edit and
   proceeds as below; a NULL `$seen` means the schedule is an **inert provisional
   create**, and step 4 runs §3.5's create-shaped CAS fenced on
   `active_definition_token IS NULL` against the **same `schedule_id`** instead
   (§3.8 Create step 5). Step 5's superseded cleanup is skipped in that case,
   because an inert schedule superseded nothing.
2. Re-validate everything from Create **now** — template eligibility and Package
   assignability are re-proved at edit time, never inherited. Date rules follow
   §8.2 (weekly) and §8.3 (explicit dates).
3. `token = strtolower( wp_generate_uuid4() )`; `INSERT` the complete new child
   set under `token`.
4. Run §3.6 with `$seen`. ← **commit point and optimistic concurrency fence.**
5. **On success**, run §3.7's superseded-definition cleanup for `$seen`.
   **On zero affected rows**, apply §3.7's failed-commit rule to this request's
   own token only. **Safe to retry:** yes — the previous definition is intact.

**Revision 9 / BR-078:** removal does not require disabling sales first and does
not change the definition CAS or its optimistic concurrency rule. After a
successful edit CAS, an ordinary prepared, guarded UPDATE turns active ON
occurrences strictly after schedule-local today OFF when §6.4's shared current-
definition predicate is false. It clears `availability_operation_token`, but
preserves `sale_locked_at`, identity, Packages, lifecycle and history. This is
sales invalidation, not a new reconciliation phase. A failure is reported; the
definition has already committed, and membership still prevents purchase. A
retry uses the current definition. No claim of an atomic combined edit/OFF
transaction or of serialized ON-versus-removal ordering is made.

`portal_session_id` and `timezone_name` are never in any assignment list.

**Archive** — `UPDATE … SET status='archived', archived_at=%s, updated_at=%s
WHERE schedule_id=%d AND status='active'`. No template guard: stopping commerce
must never be blocked. Generation stops. **No occurrence is touched.** An
Available-for-Sale occurrence under an archived schedule stops being
customer-eligible by §6.4, with no write. Zero affected rows ⇒ re-read and
classify.

**Restore** — template-guarded, because it resumes commerce:

```sql
UPDATE {prefix}bingo_session_schedules s
   SET s.status      = 'active',
       s.archived_at = NULL,
       s.updated_at  = %s
 WHERE s.schedule_id = %d
   AND s.status      = 'archived'
   AND EXISTS (
         SELECT 1 FROM {prefix}bingo_portal_session_templates t
          WHERE t.portal_session_id = s.portal_session_id
            AND t.is_current        = 1
            AND t.portal_status     = 'active'
       )
```

Zero affected rows ⇒ authoritative re-read and classify: already active, template
not commerce-eligible, or not found.

### 3.9 Expansion

| Mode | Source | Limit |
|---|---|---|
| `dates` | `bingo_session_schedule_dates` rows at the active token | **400 unique dates in the active definition** |
| `weekly` | derived from `recurrence_start`, `recurrence_end`, `weekday_mask` | **366 calendar days** of range |

Weekly expansion walks each calendar day from start to end inclusive and emits
every date whose ISO weekday bit is set. Limits are validated at commit time and
apply to the definition being committed, never to the schedule's history. The
emitted set is then filtered to strictly-future dates by §7.1.

---

## 4. Template Eligibility and Occurrence Naming

### 4.1 Template eligibility

**Commerce-eligible** means the replica row satisfies
`is_current = 1 AND portal_status = 'active'` for the schedule's
`portal_session_id`.

| Operation | Template eligibility proved in SQL |
|---|---|
| Create commit (§3.5) | **Yes** |
| Edit commit (§3.6) | **Yes** |
| Schedule restore (§3.8) | **Yes** |
| Schedule archive (§3.8) | No — stopping commerce is never blocked |
| **Withdraw an occurrence (phase 1)** | **No — the only exempt reconciliation mutation** |
| Repair an incomplete occurrence (phase 3) | **Yes** (§7.3) |
| Create a new occurrence (phase 4) | **Yes** (§7.7) |
| Retime (phase 5) | **Yes** (§7.4) |
| Restore an occurrence (phase 6) | **Yes** (§7.5) |
| Turn an occurrence ON | **Yes** (§6.1) |
| Turn an occurrence OFF | No |
| Read / list anything | No |

**Withdrawal is the single eligibility-exempt reconciliation mutation.** Removing
commerce must never be blocked by a template going draft or non-current, or by a
Package becoming unassignable (§10). Every other mutation proves eligibility
inside its own statement — never by a pre-read.

Draft and non-current templates remain **visible** with their status shown
verbatim. `is_current` and `portal_status` are independent facts displayed as two
columns, never merged — the transport keeps them independent by design
(`…/PortalSnapshotTransactionTransport.php:656-663`).

Existing schedules and occurrences for a template that becomes draft or
non-current are **retained in full**. A template that reappears is restored to
`is_current = 1` by the existing transport (`…:712-724`) with **no manual step**.
A schedule whose `portal_session_id` has no replica row is treated identically to
non-current; the UI says *template unavailable*, never *template deleted*.

### 4.2 Stored occurrence display name

```
display_name = mb_substr( canonical( template.name ), 0, 183 ) . ' — ' . H:i
```

Example: `Evening Bingo — 18:00`

- The suffix is **fixed**: space, EM DASH (U+2014), space, `H:i` from
  `start_time_local`. Eight characters. **Never** produced from the WordPress
  `time_format` option, which is mutable.
- Truncation is **by characters** with `mb_substr()` to `191 - 8 = 183`, so the
  suffix always survives and the result fits `varchar(191)` and
  `SessionService::DISPLAY_NAME_MAX` (`…/SessionService.php:39`).
- `canonical()` collapses whitespace and trims exactly as
  `SessionService::canonical_display_name()` does (`…:724-736`), **before**
  truncation.
- Nothing ever reads a time, date or behaviour back out of a name. BR-050's
  prohibition is untouched.
- Because `start_time_local` is minute-precise (§5.4), the suffix is always
  exactly `HH:MM` and never silently drops a stored second.
- **Every statement that writes a `display_name` proves the source template name
  is unchanged, byte for byte** (§7.8).

**Collation — measured, not inferred.** The implementation must verify: what
`$wpdb->get_charset_collate()` returns; the created column definition via
`SHOW FULL COLUMNS`; and the effective comparison behaviour, by executing a
comparison against the live database.

The local environment was probed read-only on 2026-09-16 and contradicted a
plausible assumption, which is why this is mandatory:

| Fact | Measured |
|---|---|
| Server | MySQL 9.5.0 Community Server |
| `@@collation_server` / `@@collation_database` | `utf8mb4_0900_ai_ci` |
| **`bc_2_bingo_sessions.display_name`** | **`utf8mb4_unicode_520_ci`** |

The column does **not** use the server default. `wpdb::init_charset()`
(`wp-includes/class-wpdb.php:844-850`) sets `utf8_general_ci` on Multisite when
`DB_COLLATE` is empty, and `determine_charset()` (`:886-902`) upgrades it to
`utf8mb4_unicode_ci` then `utf8mb4_unicode_520_ci`. `get_charset_collate()` emits
a **non-empty** COLLATE clause, so `DB_COLLATE = ''` proves nothing.

Comparison behaviour under that measured collation, executed live:

| Comparison | Column collation | `CAST(… AS BINARY)` |
|---|---|---|
| `Evening Bingo` vs `evening bingo` | **equal** | **not equal** |
| `Evening Bingo` vs `Évening Bingo` | **equal** | **not equal** |
| `Evening Bingo` vs `Evening Bingo` | equal | equal |

Both behaviours are used deliberately and for different jobs:

- **Collision detection** uses the **column collation**, through the database, so
  two names a customer could not tell apart are caught (§8.4).
- **Rename detection** uses **`CAST(… AS BINARY)`**, so a rename that changes only
  case or an accent is still detected as a change (§7.8). The binary-comparison
  idiom follows the existing precedent at
  `wp-content/plugins/bingocommerce-catalog/src/Migrations.php:19-20`.

**Resolution of a collision is the Manager's.** It is reported with both template
names and the owning `session_id`. **No hidden character, zero-width space,
counter or Portal ID is ever appended to a customer-facing name.**

Two schedules on the same template at different times produce different suffixes,
so the common multi-sitting case never collides.

---

## 5. Timezone, DST and Time Precision

### 5.1 Named IANA requirement, and immutability

`wp_timezone_string()` (`wp-includes/functions.php:124-141`) returns
`timezone_string` when set and otherwise synthesises a fixed `±HH:MM` from
`gmt_offset` — a hall configured that way has **no DST rules**. Nothing in
`HallSiteProvisioner` sets a timezone on a new hall.

Creation is **refused** with `hall_timezone_not_named` when `timezone_string` is
empty, rejected by `new DateTimeZone()`, or a fixed-offset string.

**`timezone_name` is captured at creation and is immutable for the schedule's
entire life — including before any occurrence exists.** It appears in the
provisional INSERT and in **no** UPDATE assignment list anywhere. Runtime never
re-reads the hall's current WordPress timezone for a schedule or its occurrences.
A wrong zone is corrected by **archiving the schedule and creating a
replacement**.

### 5.2 Exact-instant algorithm

```
1. S     = "D T"                                   (Y-m-d H:i:s literal)
2. tz    = new DateTimeZone(Z)                     (throw ⇒ reject)
3. naive = (new DateTimeImmutable(S, UTC))->getTimestamp()
4. offsets = distinct 'offset' in tz->getTransitions(naive-172800, naive+172800)
5. matches = []
   for each o in offsets:
       ts = naive - o
       if (new DateTimeImmutable("@ts"))->setTimezone(tz)->format('Y-m-d H:i:s') === S:
           matches[] = ts
6. |matches| == 0 ⇒ REJECT  nonexistent_local_time   (spring-forward gap)
   |matches| == 2 ⇒ REJECT  ambiguous_local_time     (fall-back overlap)
   |matches| == 1 ⇒ ACCEPT  starts_at_utc = gmdate('Y-m-d H:i:s', matches[0])
```

PHP is never allowed to normalise. `new DateTimeImmutable('2026-03-08 02:30:00',
$tz)` silently yields 03:30; this algorithm never constructs that object.

**Verified empirically against PHP 8.4.24, `America/New_York`:**

| Input | Matches | Result |
|---|---|---|
| `2026-03-08 02:30:00` | 0 | rejected — nonexistent |
| `2026-11-01 01:30:00` | 2 | rejected — ambiguous |
| `2026-03-08 18:00:00` | 1 | `2026-03-08 22:00:00` UTC (EDT, −04:00) |
| `2026-03-07 18:00:00` | 1 | `2026-03-07 23:00:00` UTC (EST, −05:00) |

A rejected date is a **preflight blocker** and the whole run is refused (§8.4).

### 5.3 Which zone decides "today" and "strictly future"

`SessionService::evaluate_available_today( array $row, string $hall_local_today )`
(`…/SessionService.php:644`) already **takes** local-today as a parameter. No
signature change; only the caller changes.

| Occurrence | "Local today" from |
|---|---|
| Generated (`schedule_id IS NOT NULL`) | the occurrence's own `timezone_name` |
| Legacy (`schedule_id IS NULL`) | `wp_timezone()` — unchanged (`…:635`) |

The same rule decides **"strictly future"** for withdrawal, retime, repair and
the date boundary. A later change to the hall's WordPress timezone setting does
not move, reinterpret or re-window any existing generated occurrence.

Date expansion walks whole calendar days on a date-only `DateTimeImmutable` in
UTC — the pattern `parse_exact_date()` already uses (`…:583-604`). No timezone
participates, so DST cannot shift, drop or duplicate a *date*. The inclusive
BR-051/BR-063 window is **unchanged** in mechanism — same calendar-day walk, same
DST immunity — though its width changed from five to thirty calendar days in
Revision 8 (§15 amendment note); see `SessionService::SALES_WINDOW_DAYS`, the
single authoritative constant both `salesWindowStartDate()` and
`evaluate_available_today()` consume.

### 5.4 Minute precision

MySQL `TIME` stores seconds — verified live: `CAST('18:00:30' AS TIME)` yields
`18:00:30`, while `CAST('18:00' AS TIME)` yields `18:00:00`. A stored
`18:00:30` would render as `— 18:00` in the name (§4.2), silently hiding 30
seconds and making two different times produce one name.

Therefore:

- **Input format is exactly `H:i`**, validated by
  `/^([01][0-9]|2[0-3]):[0-5][0-9]$/D`. Anything else — including `18:00:30`,
  `8:00`, `18:0` — is **refused** with `invalid_start_time`, never coerced.
- **Stored `start_time_local` is normalized to `HH:MM:00`** on both the schedule
  and every occurrence.
- **Seconds must always equal `00`**, and a generated occurrence must additionally
  carry non-NULL `start_time_local`, `timezone_name` and `starts_at_utc` — both
  enforced by the §2.5 integrity probes.
- **UI controls use minute precision**: `<input type="time" step="60">`, with the
  server-side regex as the authority — the attribute is a convenience, never the
  check.

---

## 6. Available for Sale, and Storefront Eligibility

### 6.1 The guarded OFF → ON statement

A check performed before the write is worthless on its own: schedule status,
definition currentness and template eligibility can all change between the check
and the statement. **Everything expressible in SQL is expressed in SQL**, in the
one statement that flips the switch — and that statement also **claims ownership**
of the transition so that only this request can later compensate it.

The request first mints `$operation_token = strtolower( wp_generate_uuid4() )`.

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d          -- expected schedule
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s          -- exact expected definition
   SET  s.available_for_sale            = 1,
        s.sale_locked_at                = COALESCE(s.sale_locked_at, UTC_TIMESTAMP()),
        s.availability_operation_token  = %s,     -- $operation_token
        s.updated_at                    = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'active'
   AND  s.available_for_sale      = 0
   AND  s.generation_completed_at IS NOT NULL
   AND  /* SessionEligibility::current_definition_sql('s'), §6.4 */
        EXISTS (
          SELECT 1 FROM {prefix}bingo_session_schedules membership_schedule
           WHERE membership_schedule.schedule_id = s.schedule_id
             AND membership_schedule.active_definition_token IS NOT NULL
             AND membership_schedule.active_definition_token <> ''
             AND (
               (membership_schedule.mode = 'dates' AND EXISTS (
                 SELECT 1 FROM {prefix}bingo_session_schedule_dates membership_date
                  WHERE membership_date.schedule_id = membership_schedule.schedule_id
                    AND membership_date.definition_token = membership_schedule.active_definition_token
                    AND membership_date.schedule_date = s.session_date
               )) OR (
                 membership_schedule.mode = 'weekly'
                 AND s.session_date BETWEEN membership_schedule.recurrence_start AND membership_schedule.recurrence_end
                 AND membership_schedule.weekday_mask BETWEEN 1 AND 127
                 AND (membership_schedule.weekday_mask & (1 << WEEKDAY(s.session_date))) <> 0
               )
             )
        )
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
```

- The `JOIN` proves schedule existence, ownership, active status and the **exact
  active definition token** at statement time — not at read time.
- The `EXISTS` proves template commerce-eligibility at statement time.
- `UTC_TIMESTAMP()` is evaluated **server-side inside the guarded statement**, so
  the lock cannot be skewed by a PHP timestamp computed before the race resolved.
- `COALESCE` makes the lock **write-once**; there is no window in which an
  occurrence is ON but unlocked.
- `generation_completed_at IS NOT NULL` makes an incomplete occurrence unsellable
  at the SQL level, not merely in the UI.
- `availability_operation_token` records **who** drove this transition. Because
  `available_for_sale = 0` is in the `WHERE`, exactly one request can win.

### 6.2 Package assignability, and owned compensation

`assert_assignable()` cannot be expressed in SQL (§3.2). It is proved immediately
before the statement and re-proved immediately after, and **every compensating
write is fenced on this request's own operation token.**

```
1. mint $operation_token
2. pre-prove ≥1 occurrence-level Package passes assert_assignable()
3. execute the §6.1 guarded UPDATE
4. if 0 rows → authoritative re-read; classify; DONE. Nothing was written,
              no token is ours, and no compensation is attempted.
5. if 1 row  → re-prove ≥1 assignable Package
6a. re-proof SUCCEEDS → settle:
        UPDATE {prefix}bingo_sessions
           SET availability_operation_token = NULL, updated_at = %s
         WHERE session_id = %d
           AND availability_operation_token = %s    -- $operation_token
      Zero rows here is NOT an error: another request has already taken
      ownership. Re-read and report the current state; write nothing further.
6b. re-proof FAILS or errors → compensate:
        UPDATE {prefix}bingo_sessions
           SET available_for_sale            = 0,
               availability_operation_token  = NULL,
               updated_at                    = %s
         WHERE session_id                   = %d
           AND schedule_id                  = %d
           AND available_for_sale           = 1
           AND availability_operation_token = %s    -- $operation_token
        report `package_became_unassignable`; result is a FAILURE
```

**The compensation can only ever undo this request's own transition.** If another
request turned the occurrence OFF and a third turned it ON again, the token no
longer matches and the compensating UPDATE affects **zero rows**. The request then
performs an **authoritative re-read, writes nothing, and reports the observed
state** — it never overwrites a newer request's outcome.

`sale_locked_at` is **never cleared** by compensation, by OFF, or by anything
else.

**Zero affected rows on §6.1 is never treated as success.** It triggers an
authoritative re-read classifying the outcome exactly.

### 6.3 Turning OFF

```sql
UPDATE {prefix}bingo_sessions
   SET available_for_sale           = 0,
       availability_operation_token = NULL,
       updated_at                   = %s
 WHERE session_id         = %d
   AND status             = 'active'
   AND available_for_sale = 1
```

OFF requires **no** template, schedule, definition or Package check, and must
always succeed for an active occurrence. It clears the operation token — which is
also how an **abandoned** unsettled ON is resolved (§6.5) — and **never clears
`sale_locked_at`**. Zero affected rows ⇒ re-read and classify.

For a **legacy** occurrence (`schedule_id IS NULL`) the existing v2 ON/OFF writer
runs unchanged; `sale_locked_at` and `availability_operation_token` stay NULL.

### 6.4 Storefront eligibility predicate

`evaluate_available_today()` stays **byte-identical**. A new composed predicate:

```php
SessionEligibility::is_sellable( int $session_id ): bool|\WP_Error
```

```
evaluate_available_today( row, local_today_for_this_row )    // §5.3, BR-051/BR-063
AND ( schedule_id IS NULL                                    // legacy: no further test
      OR ( generation_completed_at IS NOT NULL
           AND schedule.status                  = 'active'
           AND schedule.active_definition_token IS NOT NULL
           AND occurrence date belongs to the current committed definition
           AND template.is_current              = 1
           AND template.portal_status           = 'active'
           AND at least one assignable occurrence-level Package ) )
```

Any linked fact that cannot be read returns `WP_Error` — **fail closed**, in
every state including during a failed ON re-proof, during an incomplete
generation, while an ON transition is unsettled (§6.5), and after a run that
ended `status = failed` because a Package became unassignable (§7.12). Callers
must treat `WP_Error` as not sellable and must never coerce it to a boolean.

**Centralized membership (Revision 9 / BR-078).** The existing
`SessionEligibility` class owns `current_definition_sql()` and its read wrapper
`belongs_to_current_definition()`. Explicit membership requires the exact date
row at the active token. Weekly membership requires inclusive committed bounds
and `1 << WEEKDAY(session_date)` (Monday bit 0 through Sunday bit 6), exactly
matching `ScheduleExpansion`. A missing/inert definition or invalid mode is
unavailable; an unreadable dependency fails closed. Legacy Sessions bypass
membership without an additional database dependency.

The same predicate is used in the final ON UPDATE, in service refusal before an
already-ON no-op, in Upcoming before pagination, and in the admin action data.
`SessionEligibility::is_sellable()` checks it independently for purchase
evaluation, including stale rows whose stored flag remains ON. No SQL advisory
lock or new transaction transport is used. Definition removal may commit before
or after an ON attempt; continued purchase eligibility depends on the current
committed definition, not on a promise that removal will be refused.

### 6.5 An unsettled ON transition, including process death

`availability_operation_token` is **not** a guarantee that a request is currently
running. It means exactly one thing:

> **An ON transition was committed and has not yet been settled by its owner.**

The owner may still be working, or its process may have been terminated between
the §6.1 UPDATE and the §6.2 settlement — by a fatal error, a PHP timeout, a
worker kill, or a deployment. **Such a row survives, ON and locked, with a
non-NULL token that will never be settled by anyone.** This design accepts that
state rather than pretending it cannot happen, and makes it harmless:

1. **The occurrence is genuinely ON**, and `sale_locked_at` is genuinely set. The
   §6.1 UPDATE committed. Neither fact is provisional and neither is reverted.
2. **Storefront eligibility is unaffected by the token.** §6.4 does not read
   `availability_operation_token` at all; it fails closed on its own facts. A
   dangling token never makes an occurrence sellable that otherwise would not be,
   and never makes one unsellable that otherwise would be.
3. **The stale token blocks nothing.** ON is already ON, so §6.1's
   `available_for_sale = 0` guard means no further ON applies. OFF (§6.3) does not
   inspect the token and **clears it**. Reconciliation never reads it.
4. **No older request can compensate after ownership changes.** Once OFF clears
   the token, or a later ON replaces it, the dead request's fence can never match
   again — and the dead request is not running to try.
5. **The row is not corrupt and needs no repair path.** The only observable
   residue is a non-NULL token on an ON occurrence, which the next OFF clears.
6. **The v3 forward-repair pass (§12.2) clears stale tokens** as part of returning
   from a rollback, because in that scenario the tokens genuinely have no owner.

Test 42 kills the process deterministically between the guarded ON and the
settlement and asserts every one of these.

---

## 7. Reconciliation

### 7.1 The run fence

**At preflight the run captures `$run_definition_token`** — the exact
`active_definition_token` of the target schedule — together with
`$run_schedule_id` and **`$run_template_name`**, the exact replica template name
used to derive every name this run will write (§7.8). Every mutation proves what
it needs in its own `WHERE`/`JOIN`, so an application-level read is never the
thing that authorizes a write.

Every reconciliation mutation proves, **in SQL**:

1. the schedule still exists;
2. the schedule is still `status = 'active'`;
3. `sch.active_definition_token = $run_definition_token`;
4. the occurrence still carries `schedule_id = $run_schedule_id`;
5. the template is still commerce-eligible — **for every mutation except
   withdrawal**, which is the single exempt one (§4.1);
6. **for every mutation that writes a `display_name`**, that the replica template
   name still equals `$run_template_name` **byte for byte** (§7.8).

**The token is additionally re-proved by an authoritative read** between every
phase, and as part of final verification (§7.12).

If it has changed, or the schedule has been archived or deleted:

```
stop the run immediately
complete = false
status   = schedule_state_changed
remaining per-date work is not enumerated in the result
reserved not_attempted = 0
the run is NEVER reported as complete
```

A run that has already written some rows and then loses the fence still reports
`complete = false`; the writes it made were each individually fenced on the token
that was active when they ran, so nothing is inconsistent — only unfinished.
**Safe to retry:** yes.

### 7.2 Withdrawal — one atomic multi-table DELETE, eligibility-exempt

Withdrawal is the **first** mutation phase and the **only** one exempt from
template and Package eligibility. Removing commerce must never be blocked by a
template going draft or by a configured Package becoming unassignable.

```sql
DELETE s, p
  FROM {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d          -- $run_schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s          -- $run_definition_token
  LEFT JOIN {prefix}bingo_session_packages p
    ON  p.session_id = s.session_id
 WHERE  s.session_id         = %d
   AND  s.status             = 'active'
   AND  s.available_for_sale = 0
   AND  s.sale_locked_at     IS NULL
   AND  s.session_date       > %s                 -- strictly future, occurrence timezone
```

- **No template `EXISTS`, and no Package validation gates this statement.**
- **Package rows can only be deleted through the parent.** If `s` does not satisfy
  every guard, the statement matches nothing and **neither** table is touched.
- `LEFT JOIN` means an occurrence with no Package rows is still withdrawn.
- **Atomic.** A single DML statement in InnoDB either applies to both tables or to
  neither.
- **`sale_locked_at IS NULL` is in the statement.** An occurrence that has **ever**
  been offered for sale is **never hard-deleted by this or any other path** — not
  by reconciliation, not by an administrator action in this phase, and not by the
  §19 runbook.
- Package assignment alone is **not** protection: a future, OFF, unlocked
  occurrence **is** withdrawable even though it has assignments.
- `s.status = 'active'` means an **archived** occurrence is never withdrawn; it is
  classified `retained_outside_pattern`.
- An **incomplete** occurrence outside the definition **is** withdrawable: there
  is no `generation_completed_at` predicate here.

Zero affected rows ⇒ **nothing** was deleted. Authoritative re-read and classify.
Counted `withdraw_lost_race` or `retained_outside_pattern`. **Safe to retry:**
yes.

**A successful withdrawal is never rolled back and never misreported.** If a later
phase fails — including the §10 eligibility gate — the run ends
`complete = false, status = failed` with `withdrawn` reported accurately.

This is the **only** hard-delete path for an occurrence, and it can never reach a
sale-locked one.

### 7.3 Incomplete generation — convergent, token- and template-fenced repair

"Delete everything, reinsert everything, then mark complete" is **unsafe when two
reconciliation workers overlap**. Repair is instead **idempotent set convergence
under a token fence and a template fence**. Every statement carries both; none is
authorized by a pre-read.

**Step 1 — capture.** `$run_definition_token`, `$run_schedule_id`,
`$definition_package_ids` (the exact package id set for that token, **which must
be non-empty** — §10), and `$run_template_name`.

**Step 2 — remove extras.** `NOT IN` binds the definition's ids, so no correlated
subquery on the target is needed:

```sql
DELETE p
  FROM {prefix}bingo_session_packages p
  JOIN {prefix}bingo_sessions s
    ON  s.session_id              = p.session_id
   AND  s.session_id              = %d
   AND  s.schedule_id             = %d          -- $run_schedule_id
   AND  s.generation_completed_at IS NULL
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s        -- $run_definition_token
 WHERE  p.package_id NOT IN ( %d, %d, … )       -- $definition_package_ids
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
```

**Step 3 — insert missing**, once per definition package id:

```sql
INSERT INTO {prefix}bingo_session_packages (session_id, package_id, created_at)
SELECT %d, %d, %s FROM DUAL
 WHERE EXISTS (
         SELECT 1
           FROM {prefix}bingo_sessions s
           JOIN {prefix}bingo_session_schedules sch
             ON  sch.schedule_id             = s.schedule_id
            AND  sch.status                  = 'active'
            AND  sch.active_definition_token = %s
          WHERE s.session_id              = %d
            AND s.schedule_id             = %d
            AND s.generation_completed_at IS NULL
            AND s.available_for_sale      = 0
            AND s.sale_locked_at          IS NULL
            AND EXISTS (
                  SELECT 1
                    FROM {prefix}bingo_portal_session_templates t
                   WHERE t.portal_session_id = sch.portal_session_id
                     AND t.is_current        = 1
                     AND t.portal_status     = 'active'
                )
       )
```

**Duplicate-key handling is explicit, because a plain `INSERT` against
`UNIQUE (session_id, package_id)` is NOT a no-op — it raises an error.** The rule:

1. Catch **only** the duplicate-key condition (`ER_DUP_ENTRY`, MySQL **1062**).
2. **Authoritatively re-read** under the same `$run_schedule_id` and
   `$run_definition_token`.
3. If the exact expected `(session_id, package_id)` assignment now exists **and**
   the occurrence is still incomplete, OFF and unlocked, treat that Package as
   **already satisfied** and continue.
4. If the re-read does not show that state, the run **stops**:
   `complete = false`, `status = failed`.
5. **Any other database error stops the run** with `complete = false`,
   `status = failed`. Contention codes are handled by §7.11.
6. **`INSERT IGNORE` is not used.** It would downgrade unrelated errors to
   warnings, hiding exactly the failures this rule must surface.

A `NOT EXISTS` pre-guard against `bingo_session_packages` is permitted (§0.1) but
is **not** a substitute: two workers can both pass it and one still loses on the
unique key.

**Step 4 — complete, with a non-empty proof, an exact-set proof and the template
fence:**

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s        -- $run_definition_token
   SET  s.generation_completed_at = UTC_TIMESTAMP(),
        s.updated_at              = %s
 WHERE  s.session_id              = %d
   AND  s.schedule_id             = %d
   AND  s.generation_completed_at IS NULL
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
   AND  EXISTS (
          SELECT 1
            FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
        )
   -- the active definition has AT LEAST ONE Package
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_session_schedule_packages spx
           WHERE spx.schedule_id      = sch.schedule_id
             AND spx.definition_token = sch.active_definition_token
        )
   -- no required Package missing
   AND NOT EXISTS (
         SELECT 1 FROM {prefix}bingo_session_schedule_packages sp
          WHERE sp.schedule_id      = sch.schedule_id
            AND sp.definition_token = sch.active_definition_token
            AND NOT EXISTS (
                  SELECT 1 FROM {prefix}bingo_session_packages op
                   WHERE op.session_id = s.session_id
                     AND op.package_id = sp.package_id
                )
       )
   -- no extra Package present
   AND NOT EXISTS (
         SELECT 1 FROM {prefix}bingo_session_packages op2
          WHERE op2.session_id = s.session_id
            AND NOT EXISTS (
                  SELECT 1 FROM {prefix}bingo_session_schedule_packages sp2
                   WHERE sp2.schedule_id      = sch.schedule_id
                     AND sp2.definition_token = sch.active_definition_token
                     AND sp2.package_id       = op2.package_id
                )
       )
```

**The non-empty `EXISTS` is not redundant.** Without it, a definition with **zero**
Package rows would satisfy both `NOT EXISTS` branches vacuously — nothing is
missing and nothing is extra when the target set is empty — and an occurrence with
no Packages would be marked complete and become sellable. The explicit `EXISTS`
makes that impossible **in SQL**, independently of §10's gate.

Completion happens **only** when the occurrence's assignment set is *exactly* the
active definition's non-empty set, the template is still commerce-eligible, and
the run fence still holds.

**Step 5 — fence loss.** If the definition token changes, or the template stops
being commerce-eligible, the worker stops **without completing**. **Safe to
retry:** yes.

**Step 6 — stale workers after completion.** Every statement in steps 2–4 carries
`s.generation_completed_at IS NULL`. Once any worker completes the occurrence,
every other worker's remaining mutation affects **zero rows**.

**Overlapping workers with the same token converge**, because steps 2 and 3 are
set operations toward one target set rather than a wipe-and-rebuild, and step 4
refuses to complete until the set is literally exact.

Concurrency correctness is proved by tests 53–57, not by `EXPLAIN`.

### 7.4 Retime — guarded, with expected previous values and the name proof

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.start_time_local = %s,
        s.starts_at_utc    = %s,
        s.display_name     = %s,
        s.updated_at       = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'active'
   AND  s.available_for_sale      = 0
   AND  s.sale_locked_at          IS NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            = %s          -- expected previous date
   AND  s.start_time_local        = %s          -- expected previous time
   AND  s.display_name            = %s          -- expected previous name
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current        = 1
             AND t.portal_status     = 'active'
             AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)   -- $run_template_name
        )
```

Corrected **in place**; the `session_id` survives. `session_date` is in the
`WHERE` and never in the assignment list.

**Zero affected rows is never success.** Authoritative re-read classifies: turned
ON, sale-locked, archived, incomplete, already retimed, fence lost, template no
longer eligible, **template renamed** (§7.8), or foreign schedule. **Safe to
retry:** yes.

**A duplicate-key error (1062) is caught and classified**, never surfaced as a raw
persistence failure. The reconciler re-reads authoritatively, reports `conflicted`
with the owning `session_id`, and the run ends `complete = false, status = failed`.
This applies to the phase-4 `INSERT` as well.

### 7.5 Restore — lifecycle only, Packages preserved

Restore applies only to **archived future complete** occurrences.

**Unlocked** — lifecycle, time and name in one statement, with the name proof:

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.status             = 'active',
        s.archived_at        = NULL,
        s.available_for_sale = 0,
        s.start_time_local   = %s,
        s.starts_at_utc      = %s,
        s.display_name       = %s,
        s.updated_at         = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'archived'
   AND  s.sale_locked_at          IS NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            > %s
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current = 1 AND t.portal_status = 'active'
             AND CAST(t.name AS BINARY) = CAST(%s AS BINARY)   -- $run_template_name
        )
```

**Locked** — lifecycle only; time, name and lock preserved. **No name proof is
required or included, because this statement writes no `display_name`:**

```sql
UPDATE {prefix}bingo_sessions s
  JOIN {prefix}bingo_session_schedules sch
    ON  sch.schedule_id             = s.schedule_id
   AND  sch.schedule_id             = %d
   AND  sch.status                  = 'active'
   AND  sch.active_definition_token = %s
   SET  s.status             = 'active',
        s.archived_at        = NULL,
        s.available_for_sale = 0,
        s.updated_at         = %s
 WHERE  s.session_id              = %d
   AND  s.status                  = 'archived'
   AND  s.sale_locked_at          IS NOT NULL
   AND  s.generation_completed_at IS NOT NULL
   AND  s.session_date            > %s
   AND  EXISTS (
          SELECT 1 FROM {prefix}bingo_portal_session_templates t
           WHERE t.portal_session_id = sch.portal_session_id
             AND t.is_current = 1 AND t.portal_status = 'active'
        )
```

Both require `generation_completed_at IS NOT NULL`. **An archived future
incomplete occurrence is repaired in phase 3 and restored in phase 6 of the SAME
run** (§7.6).

**Zero affected rows on either statement is never success.** Authoritative
re-read classifies, counted `restore_lost_race`. **Safe to retry:** yes.

**Restore never touches Package assignments.** Schedule defaults are **not**
re-applied. `sale_locked_at` and `generation_completed_at` are preserved.
`available_for_sale` is forced to `0`.

### 7.6 Phase order and classification

Withdrawal runs **first**, so removing commerce is never blocked by the
eligibility gate that governs adding it.

| Phase | Work | Eligibility gated? | On failure |
|---|---|---|---|
| **0** | Preflight — read-only; capture `$run_definition_token`, `$run_schedule_id`, `$run_template_name`; classify; blockers abort (§8.4) | n/a | abort, **nothing written**, `complete = false`, `status = failed` |
| **1** | **Withdraw** removed future occurrences (§7.2) | **No** | stop, `complete = false`, `status = failed` |
| **2** | **Eligibility gate** — template commerce-eligible, definition Package set **non-empty**, and every configured Package assignable (§10) | — | stop, `complete = false`, `status = failed`; phase-1 withdrawals stand and are counted |
| **3** | **Repair** eligible incomplete occurrences (§7.3) | Yes | stop, `complete = false`, `status = failed` |
| **4** | **Create** missing future dates (§7.7) | Yes | stop, `complete = false`, `status = failed` |
| **5** | **Retime** correctable occurrences (§7.4) | Yes | stop, `complete = false`, `status = failed` |
| **6** | **Restore** archived future complete occurrences (§7.5), **including rows repaired in phase 3, reclassified at phase entry** | Yes | stop, `complete = false`, `status = failed` |
| **7** | **Final verification** (§7.12) before `complete = true` | Yes | `complete = false`, `status = failed` |

The definition token is re-proved by an authoritative read **between every
phase**. A phase that records a **write failure**, a **lost fence**, a
**duplicate-key conflict**, a **template rename** or a **contention error** stops
the run before the next phase begins. A *classification* —
`retained_historical`, `retained_historical_incomplete`,
`retained_outside_pattern`, `existing` — is informational and does not stop or
fail the run. `skipped_past` and `not_attempted` remain reserved, zero-valued
reporting fields in the current implementation (§8.6), not populated
classifications.

Let **T** = expansion of `$run_definition_token`, filtered to **strictly future**
dates in the schedule's `timezone_name`; **E** = every occurrence carrying
`schedule_id = $run_schedule_id`, any status.

| # | Case | Action | Effect on the run |
|---|---|---|---|
| 1 | **Historical incomplete** — `generation_completed_at IS NULL`, date not strictly future | **no mutation**, remains unsellable; `retained_historical_incomplete` | informational |
| 2 | **Incomplete, ON or sale-locked** (any date) | **no mutation**, no Package rewrite; `incomplete_integrity_conflict` | **preflight integrity blocker** — run aborts, `complete = false`, `status = failed` (§8.4) |
| 3 | **Incomplete, future, OFF, unlocked, in T** — active **or archived** | Phase 3 repair (§7.3); an archived one is then reclassified and restored in **phase 6 of the same run** | normal |
| 4 | **Incomplete, future, OFF, unlocked, not in T, active** | Phase 1 withdraw (§7.2) — permitted, no completion predicate | normal |
| 5 | In T, not in E | Phase 4 create (§7.7) | normal |
| 6 | In T, in E, active, complete, time matches | **no write**; `existing` | informational |
| 7 | In T, in E, active, complete, time differs, future ∧ OFF ∧ unlocked | Phase 5 retime (§7.4); `retimed` | normal |
| 8 | In T, in E, time differs, locked **or** ON | **no write**; `protected_time_conflict` | **preflight blocker** — run aborts (§8.4) |
| 9 | In T, in E, archived, future, complete, unlocked | Phase 6 restore + retime (§7.5); `restored` | normal |
| 10 | In T, in E, archived, future, complete, locked | Phase 6 restore lifecycle only (§7.5); `restored` | normal |
| 11 | In E, not in T, future ∧ OFF ∧ unlocked ∧ active | Phase 1 withdraw (§7.2); `withdrawn` | normal |
| 12 | In E, not in T, locked, ON, or archived | **no write**; `retained_outside_pattern` | informational |
| 13 | **Any occurrence whose date is not strictly future** (and not row 1) | **no write, in every phase**; `retained_historical` | informational |

Rows 1 and 13 together are the complete statement of past immutability.

### 7.7 Create

Per date in T, not in E:

1. Guarded `INSERT … SELECT … FROM DUAL WHERE EXISTS (…)` with
   `available_for_sale = 0`, `sale_locked_at = NULL`,
   **`generation_completed_at = NULL`**, `availability_operation_token = NULL`,
   `status = 'active'`, the derived name, and `start_time_local` /
   `timezone_name` / `starts_at_utc` from the schedule. The `EXISTS` guard carries
   the run fence, the template fence **and the binary name proof**
   `CAST(t.name AS BINARY) = CAST(%s AS BINARY)` against `$run_template_name`,
   because this statement writes a `display_name`.
2. Apply every definition Package through the **internal generation writer**
   (§7.10), using §7.3's duplicate-key rule.
3. Complete with §7.3 step 4's statement.

A duplicate-key error on the occurrence insert is handled as in §7.4. A Package
write failure leaves the row incomplete; the run **stops**. **Safe to retry:**
yes — phase 3 of the next run converges it.

### 7.8 Template rename, and the binary name proof

A Portal rename can land between the moment a run derives a name and the moment it
writes it. Without a guard the run would store a name derived from the **old**
template while the replica already holds the **new** one — a silent, permanent
inconsistency that no later phase would detect, because a name mismatch is
deliberately not a retime trigger.

**Every statement that writes an occurrence `display_name` therefore proves the
source name is unchanged:**

| Statement | Writes `display_name`? | Name proof |
|---|---|---|
| Create (§7.7) | **Yes** | **Required** |
| Retime (§7.4) | **Yes** | **Required** |
| Restore, unlocked (§7.5) | **Yes** | **Required** |
| Restore, locked (§7.5) | No | Not required, not included |
| Repair steps 2–4 (§7.3) | No | Not required, not included |
| Withdraw (§7.2) | No | Not required, not included |

The proof is `CAST(t.name AS BINARY) = CAST(%s AS BINARY)`, bound to
`$run_template_name` — the exact replica name captured at preflight.

**It must be a binary comparison, not the column's collation.** The replica column
collates case- and accent-insensitively (§4.2, measured), so `Evening Bingo` and
`evening bingo` compare **equal** under it — a rename between those two would slip
through and the stored name would be wrong. Under `CAST(… AS BINARY)` they compare
**unequal**, which is the required behaviour. The idiom follows the existing
precedent at `wp-content/plugins/bingocommerce-catalog/src/Migrations.php:19-20`.

**On a rename, the statement affects zero rows.** The reconciler re-reads
authoritatively, distinguishes *renamed* from the other zero-row causes, stops the
run with `complete = false`, `status = failed`,
`failure_category = template_renamed`, and **requires a retry**. The next run
captures the new `$run_template_name` and the proof then matches. Nothing is
half-written: the statement either wrote the row with a name derived from the name
it proved, or wrote nothing.

**Rename semantics otherwise are unchanged:**

- A rename affects **newly generated** occurrences.
- **A rename alone never causes a reconciliation write.** Name mismatch is not a
  retime trigger and not a conflict; only a **time** mismatch triggers row 7.
- An existing occurrence **keeps its stored name** when no schedule-owned
  mutation is otherwise required.
- When an unlocked future **complete** occurrence is legitimately retimed (row 7)
  or restored-unlocked (row 9), its name **is recomputed from the current template
  name** — and that name is the one the proof binds.
- **Locked, ON and historical occurrences retain their stored names.**
- The Manager message reads: *the Portal template was renamed. Existing
  occurrences keep their current names; an occurrence only takes the new name if
  its time also changes and it has never been offered for sale.*

### 7.9 Package defaults

**Defaults** are written onto an occurrence **only** by the internal generation
writer, and only while that occurrence is incomplete — on first generation (§7.7)
and on repair (§7.3). They are **never** written to a completed, locked or ON
occurrence, and **never** re-applied on restore.

### 7.10 Internal generation writer

`OccurrenceGenerationWriter` is an **internal reconciliation path**, not a Manager
action. It exists because `SessionService::assignPackage()` refuses generated
occurrences while `generation_completed_at IS NULL` (§9) — the very state in which
generation must write Packages. The two paths are disjoint by guard.

- **Not** exposed in any admin screen, POST handler, REST route or public service
  method.
- **Reuses the existing Package validation rules** — `PackageGateway::get()` and
  `PackageGateway::assert_assignable()`.
- Every statement requires: `generation_completed_at IS NULL`; the exact
  `schedule_id`; `sch.active_definition_token = $run_definition_token`;
  `available_for_sale = 0`; `sale_locked_at IS NULL`; **and the template
  commerce-eligible** by `EXISTS`.
- Therefore it **cannot mutate a completed, locked or ON occurrence**, and cannot
  write while the template is ineligible.
- It applies §7.3's duplicate-key rule; it never uses `INSERT IGNORE`.

### 7.11 Database contention

| MySQL error | Meaning |
|---|---|
| **1213** | deadlock detected, transaction rolled back |
| **1205** | lock wait timeout exceeded |

On either code, in **any** statement of **any** phase:

1. **Stop the run immediately.**
2. `complete = false`, `status = failed`,
   `failure_category = database_contention`.
3. **Authoritatively re-read before reporting.**
4. **Statements that individually committed earlier remain valid**, are counted
   accurately, and are not rolled back — there is no enclosing transaction (§3.1).
5. **No automatic retry inside the run.** Retrying means a **new run** from
   current state.
6. The reported category is the fixed string `database_contention`. **No SQL, no
   driver message, no table name and no stored value is surfaced or logged.**

### 7.12 Final success verification

Immediately before returning `complete = true`, the run re-proves by authoritative
read:

1. the schedule still exists and `status = 'active'`;
2. `active_definition_token` still equals `$run_definition_token`;
3. the template is still commerce-eligible;
4. **the replica template name still equals `$run_template_name` byte for byte**;
5. **the active definition's Package set is non-empty** — proved **before** it is
   iterated, so an empty set can never vacuously pass step 6;
6. **every configured definition Package is still assignable**
   (`assert_assignable()` for each id).

If (1), (2) or (4) fails → `complete = false`, `status = schedule_state_changed`
(or `failed` with `failure_category = template_renamed` for (4)).

If (3), (5) or (6) fails → **the run does not return success**:

```
complete = false
status   = failed
failure_category = final_verification_failed
```

Every occurrence this run created or completed **remains stored** — nothing is
rolled back, and the counts are reported accurately. Those occurrences **fail
closed through §6.4** for as long as the problem persists, and cannot be turned ON
because §6.2's pre-proof fails. **Retry is safe once the Package or template is
fixed.**

A successful run never reports `ok` on the strength of checks made only at its
start.

---

## 8. Date Boundary, Collisions and Partial Failure

### 8.1 New dates: tomorrow or later

- **At creation**, every explicitly selected date and `recurrence_start` must be
  **tomorrow or later** in the schedule's `timezone_name`, else
  `date_must_be_tomorrow_or_later`.
- The UI states this next to both controls: *dates must be tomorrow or later in
  this hall's scheduling timezone.*

### 8.2 Editing a weekly schedule after its start date

- Remains editable after the original `recurrence_start` has passed.
- `recurrence_start` is **preserved as stored**, past or not, unless the Manager
  supplies a new start — which must be tomorrow-or-later.
- Reconciliation emits **only dates from tomorrow onward**.
- The current reconciler does not populate `skipped_past`; that result field is
  reserved for deferred per-date reporting (§8.6).
- `recurrence_end >= recurrence_start`, span ≤ 366 days. An edit whose whole
  range is already past commits successfully, generates nothing, and says so.

### 8.3 Editing an explicit-date schedule after dates pass

- **An explicit-date schedule remains editable** after some of its selected dates
  have become today or historical.
- **Previously committed dates that are now today or historical MAY be omitted**
  from the new active definition.
- **Omitting them changes nothing.** Their occurrences are historical and
  untouchable under §7.6 rows 1 and 13, and withdrawal requires a strictly-future
  date. The **historical occurrences themselves remain the durable record**.
- **They may also be kept**, exempt from the tomorrow-or-later rule and not
  re-validated.
- **Newly added dates must be tomorrow or later.**
- **A historical date not in the previously committed definition cannot be
  added**, with `historical_date_not_addable`.
- **The 400-date limit applies to the new active definition**, never to the
  schedule's lifetime, so dropping passed dates frees room and a schedule can be
  extended indefinitely.
- **Superseded definition rows are cleaned by §3.7** after a successful edit, so
  they do not accumulate.
- **A Package-only edit is never blocked** by dates that have since passed.

**UI behaviour.** The date editor shows passed dates in a separate *Past dates*
group, each with a checkbox to keep or drop, defaulting to **drop** once the
active definition would otherwise exceed 400 entries and to **keep** below that.
The screen states plainly: *dropping a past date does not change or remove any
occurrence that was already generated.*

### 8.4 Preflight — read-only, before any write

1. Capture `$run_schedule_id`, `$run_definition_token` and `$run_template_name`.
2. Expand T; filter to strictly-future (§8.1–8.3).
3. Compute `starts_at_utc` for every date (§5.2); `nonexistent_local_time` and
   `ambiguous_local_time` are blockers.
4. Derive the display name for every date (§4.2) from `$run_template_name`.
5. Read, **through the database and in the collation in force** (§4.2): every
   occurrence for `$run_schedule_id`, and every occurrence in the hall matching
   any candidate `(session_date, display_name)`.
6. Classify every date and every existing occurrence against §7.6.
7. **If any blocker exists, abort before any write**, with `complete = false`,
   `status = failed`, `failure_category = preflight_conflict`, every blocker
   reported with its kind and — where applicable — the owning `session_id` and
   both template names. Every count is zero.

The **preflight blockers** are exactly:

- a foreign `(session_date, display_name)` clash;
- `protected_time_conflict` (§7.6 row 8);
- `incomplete_integrity_conflict` (§7.6 row 2);
- `nonexistent_local_time` or `ambiguous_local_time`.

Preflight decides *what to attempt*. It never authorizes a write.

### 8.5 Collisions after preflight

- **Guarded statement affects zero rows** → authoritative re-read and classify.
- **Duplicate-key (1062)** → §7.3's rule for Package inserts; §7.4's rule for
  occurrence inserts and retimes.
- **Template renamed** → §7.8, `failure_category = template_renamed`.
- **Contention (1213 / 1205)** → §7.11.
- **Fence lost** → §7.1.

### 8.6 Reported result

```
status                 : ok | failed | schedule_state_changed
complete               : bool          (true if and only if status = ok)
failure_category       : null | preflight_conflict | eligibility_failed
                       | write_failed | duplicate_conflict | template_renamed
                       | database_contention | final_verification_failed

withdrawn, created, completed, repaired, retimed, restored, existing,
incomplete, conflicted, protected_time_conflict,
incomplete_integrity_conflict, retained_outside_pattern,
retained_historical, retained_historical_incomplete,
skipped_past, withdraw_lost_race, restore_lost_race, not_attempted
```

`skipped_past` and `not_attempted` are reserved reporting fields retained in the
result vocabulary for deferred per-date reporting. The current reconciler
initializes both to zero and never increments them; zero-valued fields are
omitted from manager notices. They MUST NOT be interpreted as populated results.

A run with `complete = false` is **never** described as finished, always carries a
`failure_category`, and the notice names what to do next.

---

## 9. Mutation Ownership of Generated Occurrences

**Correction (Increment 3 follow-up pass).** Increment 3's first commit routed
`setAvailableForSale()` through the guarded §6.1/§6.2/§6.3 path but left every
OTHER writer below unguarded against `schedule_id`: `rename()`, `reschedule()`,
`updateDetails()`, `archive()` and `restore()` could all still reach a
schedule-owned row, and `updateDetails()` in particular could change
`available_for_sale` on one directly — the exact bypass this table exists to
rule out. **This was reported as an explicit unresolved risk at the end of that
pass, not silently.** The five refusals and the two completion gates below are
implemented in the follow-up correction pass, in
`SessionService::refuse_if_schedule_owned()` and
`SessionService::refuse_if_generation_incomplete()`, tested by §16 F43–F45 and
F49–F50 in `tests/session-availability-increment-1.php`.

| Writer | `schedule_id IS NULL` | `schedule_id IS NOT NULL` |
|---|---|---|
| `create()` | unchanged | n/a — never sets `schedule_id` |
| `rename()` (`…/SessionService.php:111`) | unchanged | **refused** — `schedule_owned_occurrence` |
| `reschedule()` (`:135`) | unchanged | **refused** |
| `updateDetails()` (`:199`) | unchanged | **refused** |
| `archive()` (`:284`) | unchanged | **refused** |
| `restore()` (`:289`) | unchanged | **refused** |
| `setAvailableForSale()` (`:159`) | unchanged | **allowed** — ON through §6.1/§6.2, OFF through §6.3 |
| `assignPackage()` (`:311`) | unchanged | allowed **only when** `generation_completed_at IS NOT NULL` |
| `removePackage()` (`:395`) | unchanged | allowed **only when** `generation_completed_at IS NOT NULL` |
| `OccurrenceGenerationWriter` (§7.10) | **never** — legacy rows are not reachable | **only** path that writes Packages while incomplete; internal, not a Manager action |

Reconciliation owns a generated occurrence's **name, date, time and lifecycle**.
`SessionAdmin`'s `update`, `archive` and `restore` actions
(`…/SessionAdmin.php:330-366`) are additionally hidden for generated rows, but
hiding is presentation; the service refusal is the enforcement, and both are
tested.

**Revision 9 / BR-078:** generated occurrences outside the current definition
are absent from default Upcoming and offer no Enable Sales action. A forced
handler or `setAvailableForSale(true)` request is refused server-side, including
an already-ON stale row. All/history reads remain unfiltered by membership;
already archived rows remain in Archived. Existing reconciliation may withdraw
only rows permitted by §7.2 and otherwise retains protected history under §7.6.
Re-adding an OFF retained date never enables sales; archived restoration stays
OFF and preserves its existing identity and Package assignments.

**Legacy rows keep their observable behaviour exactly**: same accepted inputs,
same validation, same lifecycle, same return shapes, same error codes, same audit
events, same thirty-day evaluation against `wp_timezone()` (five days before Revision 8;
the width changed, the evaluation path did not — §15 amendment note). The row
read now includes `schedule_id` because it is part of the extended `COLUMNS` list, and the
router inspects that value — **no additional query, no new failure mode and no
changed result for a legacy row**. The claim is behavioural equivalence, not that
no new column is read.

---

## 10. Package Validation

- A schedule **requires at least one same-Hall assignable Package** at creation
  and at every definition commit, checked as **request-time validation** (§3.2).
  That validation is not atomic with the CAS and does not claim to be.
- **Phase 2 is the eligibility gate**, and it proves three things:
  1. the template is commerce-eligible;
  2. **the active definition's Package set is non-empty** — a zero-Package
     definition fails the gate outright;
  3. every configured `package_id` resolves through `PackageGateway::get()` and
     passes `PackageGateway::assert_assignable()`.
  `map()` populates the selector only, because it is capped at
  `ADMIN_READ_PAGE_SIZE = 200` (`…:25`, `:60-78`).
- **The gate runs AFTER withdrawal (phase 1), never before it.** If it fails, the
  run stops with `complete = false`, `status = failed`,
  `failure_category = eligibility_failed`, every offending Package named — and
  **the phase-1 withdrawals stand and are counted accurately**.
- **If the gate fails, no occurrence is created, repaired, retimed or restored.**
- A Package that becomes unassignable **after** occurrences exist does not delete
  them; it blocks the next run's phases 3–6, blocks turning further occurrences ON
  (§6.2), and makes existing ones fail closed through §6.4.
- **The non-empty requirement is additionally proved in SQL** at §7.3 step 4, so
  an occurrence can never be completed against an empty definition even if the
  gate were bypassed.
- §7.12 re-proves non-emptiness **before iterating**, then assignability.
- `bingo_session_packages` is **unchanged**: no schema change, no data change.

---

## 11. Migration Rollout and Portal-Sync Readiness Separation

### 11.1 The existing migration framework is retained unchanged

No new migration UI, no new migration audit event, no change to either hook:

- `maybe_migrate_network()` stays exactly as it is (`…/Migrations.php:48-95`,
  hooked on `network_admin_menu` priority 6, `…/Plugin.php:39`). Once
  `DB_VERSION` becomes 3 its gate reads `2 >= 3` → false and the next
  network-admin load sweeps every hall. **This is the intended deployment
  mechanism.**
- `maybe_migrate_current_site()` stays exactly as it is (`…/Plugin.php:40`).
- Both run `migrate_current_site()`, which advances the version **only after all
  six structures and both integrity probes verify** (`…/Migrations.php:185-196`,
  §2.5).

**Deployment obligation:** the rollout must *deliberately trigger and verify* this
mechanism — load network admin as a Super Admin, then confirm every hall's
`bc_sessions_db_version` reads 3 and `verify_current_site_schema()` returns
`true`.

### 11.2 New-site provisioning

`provision_new_site()` is unchanged and builds all six structures, so a newly
provisioned hall has the **full current schema before any operational success is
reported**.

### 11.3 Portal-sync readiness separation

`PortalSyncService::assert_domain_schemas()` (`…/PortalSyncService.php:550-562`)
gates synchronization on whole-plugin *version* comparisons, so bumping Sessions
to v3 would break Portal sync on every hall until it migrated — though
synchronization touches none of the new tables.

Replace those with **structural verification of exactly what the transport
touches**:

| Requirement | What synchronization touches | Structural proof |
|---|---|---|
| **Game Type replica** | `game_type_id`, `portal_game_type_id`, `code`, `name`, `local_name_key`, `status`, `created_at`, `updated_at`, `archived_at` — `…/PortalSnapshotTransactionTransport.php:406`, `:591-594`, `:623-624` | `Catalog\Migrations::verify_game_types_schema()` (`…/catalog/src/Migrations.php:299-340`) plus its obsolete-index check and indexed `COUNT(*)` probe (`:458-466`) |
| **Portal Session-template replica** | all eight columns, `:675-750` | `Sessions\Migrations::verify_portal_session_templates_schema()` (`…/sessions/src/Migrations.php:334-378`) |
| **Sync-state storage** | a **per-site option**, `bc_portal_snapshot_sync_state` (`…/PortalSyncState.php:31`) — not a custom table | No additional requirement; the transport already asserts `options_table` exists and is InnoDB via `REQUIRED_INNODB_TABLES` (`…:101-107`) |

Exposed as `PortalSyncService::assert_snapshot_schemas()`. **No DDL**, no
migration side effect on a read path.

Precedent: `SetupReadiness::check_domain_schema()` (`…/SetupReadiness.php:725-740`)
already does this — *"Structure first, version marker second."*

**Effect: a hall still at Sessions v2 completes Portal synchronization
successfully.**

### 11.4 Schedule administration still requires full v3

`SessionScheduleService::authorize()` requires `Sessions\Migrations::is_current()`,
returning `sessions_schema_unavailable` otherwise — identical to
`SessionService::authorize()` (`…:687-689`).

---

## 12. Rollback Restrictions

### 12.1 The exact recorded-version comparison

```php
public static function is_current(): bool {
    return (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION;
}
```
`wp-content/plugins/bingocommerce-sessions/src/Migrations.php:201-203`.

**The operator is `>=`, not `===`.** With the option at 3 and code at v2,
`is_current()` returns `true`, `maybe_migrate_current_site()` returns early at
`…:29` with no DDL, and the option is never lowered.

### 12.2 A writable v2 rollback is UNSAFE

v2 can *read* a v3 database — it ignores additive nullable columns and tables.
**Reading is not the problem. Writing is.** Against a v3 database, v2 code can:

- **turn a generated occurrence ON or OFF without setting `sale_locked_at` and
  without an `availability_operation_token`**, because v2 has neither column —
  and when v3 returns, §7.2's guard considers that occurrence deletable;
- **mutate schedule-owned `session_date`, `display_name` and lifecycle** through
  `rename()`, `reschedule()`, `updateDetails()`, `archive()` and `restore()`,
  because v2 has no §9 enforcement;
- **turn an incomplete occurrence ON**, producing §7.6 row 2's
  `incomplete_integrity_conflict` — a preflight blocker that then requires the
  §19 runbook to clear.

**Therefore:**

1. **A v3 → v2 code rollback requires Session mutation paths to be placed in
   maintenance / read-only mode.** Reads may continue.
2. **Returning to v3 requires a forward verification and repair pass before
   writes reopen**, which must at minimum:
   - set `sale_locked_at` on every generated occurrence with
     `available_for_sale = 1 AND sale_locked_at IS NULL`;
   - clear every `availability_operation_token`, since after a rollback none has
     a living owner (§6.5);
   - report every generated occurrence whose `session_date`, `display_name`,
     `start_time_local` or `starts_at_utc` disagrees with its schedule, as drift,
     **without auto-rewriting it**;
   - report every `incomplete_integrity_conflict` row for investigation;
   - repair every eligible incomplete row.
   Writes reopen only after the pass completes and its report is reviewed.
3. **Never lower the stored database version.**
4. **Any structural correction after release ships as `DB_VERSION = 4`.**
5. **No destructive down-migration is authorized.** `dbDelta()` cannot drop a
   column or convert a key's uniqueness (`tests/session-increment-1.php:229-231`).

### 12.3 How rollback is actually tested, and against which commit

**Forcing `DB_VERSION` to 2 does not execute v2 behaviour.** The constant is
compiled into the loaded v3 class; every v3 guard, refusal and column write still
runs. This ADR forbids a test that does that.

**The verified Sessions-v2 baseline commit is:**

```
488c064dbaf4a0bfd4126e18afdd9de848ff86b5
feat(sessions): add portal session template replicas
Fri Sep 4 10:41:42 2026 -0400
```

This was established, not assumed, on 2026-09-16:

- `git log --follow -- …/bingocommerce-sessions/src/Migrations.php` shows exactly
  two commits: `2505129` (v1, `feat(sessions): implement session management`) and
  `488c064` (v2).
- `git show 488c064:…/src/Migrations.php` confirms `DB_VERSION = 2` and the
  `bingo_portal_session_templates` constant.
- `git diff --stat 488c064 HEAD -- wp-content/plugins/bingocommerce-sessions/`
  is **empty**: the Sessions plugin is byte-identical between 488c064 and the
  current HEAD.

**A correction to an earlier assumption:** `4104f88706ee8e1dc8c3b2d4e50d424b213d8451`
— the current HEAD — is **NOT** the Sessions-v2 commit. It is
`feat(integration): add automatic Hall provisioning` and touches only
`bingocommerce-portal-integration` and `bingocommerce-platform`. It happens to
*contain* identical v2 Sessions source, but `488c064` is the commit that
established v2 and is the correct anchor to record.

**If any Sessions commit lands between today and the v3 change, this reference is
stale** and the implementation must re-run the three verification commands above
and record the new baseline before writing the rollback tests.

The rollback tests use **both** of these:

**(a) Frozen-source assertions.** The v2 implementation is retrieved by
`git show 488c064dbaf4a0bfd4126e18afdd9de848ff86b5:wp-content/plugins/bingocommerce-sessions/src/SessionService.php`
and asserted against directly. Verified today: that source contains **zero**
occurrences of `sale_locked_at`, `generation_completed_at`,
`availability_operation_token` and `schedule_id`.

**(b) Seeded database states.** The test writes the exact rows v2 *can* produce —
a generated occurrence with `available_for_sale = 1` and `sale_locked_at IS NULL`;
one with a stale `availability_operation_token`; one whose `session_date` and
`display_name` no longer match its schedule; an incomplete occurrence with
`available_for_sale = 1` — and exercises the **v3 forward-repair pass**.

(a) is what makes (b)'s seeded states provably reachable rather than invented.

Optionally, where repository rules permit vendoring a frozen v2 plugin copy under
`tests/fixtures/` — outside `wp-content/` and never deployed, per AGENTS.md §3 —
an isolated worker may load that copy for a genuine execution test. **This ADR
does not require it.**

---

## 13. Permission, Nonce, Audit, Isolation, and Game Type Flow

### 13.1 Permission, nonce, isolation, audit

**Capability.** `bc_manage_sessions` with the `is_super_admin()` override. No new
capability. Game Operator holds `bc_view_sessions` but not `bc_manage_sessions`
(`…/Roles.php:39-62`) and is excluded.

**Authorization shape.** Reuse `SessionService::authorize()` verbatim: logged in
→ `HallContext::is_hall()` → capability → `Migrations::is_current()` (`…:672-697`).

**Nonce.** `check_admin_referer()` on every mutation (`…/SessionAdmin.php:56`).
Generation runs through `admin_post_bc_session_schedule_generate`, never in a
render callback — the reasoning is recorded at `…/PortalSyncAdmin.php:150-158`.
The handler enforces POST-only, tenancy, capability and nonce **in that order**.

**Hall isolation.** Per-site `$wpdb->prefix`; no `blog_id` column anywhere. Every
id resolves only against the current site's own tables. Every guarded occurrence
statement additionally carries `schedule_id = %d` and joins that site's schedules
table, so ownership is proved in SQL. `portal_session_id` is always validated
against *this hall's* replica before any write. Request integers use
`SessionAdmin::request_int()` (`…:370-379`), not `absint()`.

**HallCommerceGate.** No change; no soft `class_exists()` check and no fail-open
notice. `HallCommerceGate::decide()` (`…:284-307`) exempts only cron, the Action
Scheduler runner, the approved payment callback and the login path — `/wp-admin/`
is not exempt, so a BLOCK hall already refuses this surface at `plugins_loaded`.
This phase introduces **no cron and no CLI writer**.

**Audit** — `<domain>.<entity>.<verb>`:

| Action | `target_type` | `target_id` |
|---|---|---|
| `sessions.schedule.created` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.updated` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.archived` / `.restored` | `bingo_session_schedule` | `schedule_id` |
| `sessions.schedule.generated` | `bingo_session_schedule` | `schedule_id` |

**No schema-migration audit event is added.** Occurrence-level events reuse the
existing `sessions.session.*` actions. `sessions.schedule.generated` carries
**counts, `status` and `failure_category` only** in `context` (§8.6).

**The `sessionid` landmine, and the `token` one (corrected, Increment 5).**
`AuditLog` compacts every payload key to alphanumerics and rejects `sessionid`
(`…/AuditLog.php:83`); this is why `session_id` is absent from every Session
payload (`…:884-888`).

**A key containing `token` is rejected the same way, and an earlier revision of
this section wrongly claimed otherwise.** `is_sensitive_key()` splits a key on
non-alphanumerics and refuses it when ANY resulting token is a sensitive term
(`…/AuditLog.php:534-550`), and `token` is on that list (`…:58`). So
`active_definition_token` split to `['active','definition','token']` and was
refused: every schedule create, edit, archive and restore recorded an
`audit_write_failed` warning and **no audit row at all**. This went unseen until
the administration screens became the first caller to exercise the real audit
recorder — every harness before them passed a swallowing stub.

The remedy follows the `session_id` precedent: the payload key is
**`active_definition`**, carrying the same value. No `*_token` key is ever
placed in an audit payload. `definition_token`, `run_definition_token` and
`availability_operation_token` are likewise **never audited** —
`SessionService` already documents that exclusion for the last of them
(`…/SessionService.php:1048`).

**`portal_session_id` was refused by the same filter, one position along**, and
this section wrongly claimed it passed too. `is_sensitive_key()` also rejects a
key when two ADJACENT parts join into a sensitive compound
(`…/AuditLog.php:560-564`), and `portal_session_id` splits to
`['portal','session','id']` whose adjacent pair `session`+`id` is `sessionid` —
the very name that keeps `session_id` out of every Session payload. The audit
key is therefore **`portal_template_id`**, which is also what ADR-035 calls this
identity in prose. **The column is unchanged**; only the audit key differs.

Keys actually used, and checked against `SENSITIVE_KEY_TOKENS` and
`SENSITIVE_KEY_NAMES`: `schedule_id` (as `target_id`, not a payload key),
`portal_template_id`, `active_definition`, `timezone_name`, `start_time_local`,
`mode`, `weekday_mask`, `recurrence_start`, `recurrence_end`, `status`,
`failure_category` and the §8.6 counts.
`tests/session-schedule-admin-increment-1.php` asserts that a real schedule
mutation records its audit event with **no** `audit_write_failed` warning, and
enumerates `audit_payload()`'s own keys for both landmines — which is what makes
this claim testable rather than assumed. The general lesson: a key is checked
part-by-part AND pairwise, so "it contains no obviously secret word" is not
enough to conclude it passes.
**`run_template_name` is never placed in an audit payload** — it is a
Portal-supplied display string, and the audit carries counts only.

**No new transaction transport is introduced** (§3.1, §7.3, §7.11).

### 13.2 Game Type flow — the schedule never selects one

- **A schedule does not select a Game Type**, directly or indirectly.
- **The frozen Portal session snapshot contains no Game Type information.**
  `PortalSnapshotValidator::SESSION_FIELDS` is exactly
  `id, hall_id, name, status, updated_at` (`…/PortalSnapshotValidator.php:31`).
- **The Manager selects a Portal session template and one or more Packages.**
- **Game Types enter the purchase/play flow through the selected Packages**, via
  their Bingo Play Products: `bingo_packages` →
  `bingo_package_components.bingo_play_product_id` (`…/catalog/src/Migrations.php:219`)
  → `bingo_play_products.game_type_id` (`…:185`) →
  `bingo_game_types.portal_game_type_id` (`…:156`). Game Type is chosen when a
  Bingo Play Product is created — `BingoPlayProductService::create( …, ?int
  $game_type_id = null )` (`…/BingoPlayProductService.php:56`) — which
  **requires** one for `medium = digital` (`…:76`).

Two source facts recorded because they matter downstream, neither a conflict:

1. `bingo_play_products.game_type_id` is **nullable** (`…:185`) — correct by
   design; paper needs no Game Type, digital is enforced at `:76`.
2. The supported Package component read does **not project Game Type**:
   `PackageService`'s component query selects `pp.medium`, `pp.faces_per_unit`,
   `pp.status` and `pp.wc_product_id` but **not** `pp.game_type_id`
   (`…/catalog/src/PackageService.php:773-775`). **ADR-043 does not add it.**

---

## 14. Legacy Compatibility, and Deferred Work

**Legacy `schedule_id IS NULL`.** Every occurrence created before v3, and every
manual occurrence afterwards, carries NULL in all **seven** new columns and keeps
its existing observable behaviour: same writers, same validation, same lifecycle,
same thirty-day evaluation against `wp_timezone()` (§5.3; five days before
Revision 8 — the width changed, not the mechanism), same Package assignment,
no §9 refusal, no §6.1 guarded path, no sale lock, no operation token, no
completion requirement, no reconciliation, and exemption from the §2.5
generated-occurrence integrity probe. The one mechanical change is that the row
read now carries `schedule_id` (§9).

**Deferred, and named so it is not mistaken for an omission:**

- **Garbage collection of crash-abandoned private definition tokens** (§3.7) —
  the only remaining accumulation case now that superseded definitions are
  cleaned.
- **A durable order → occurrence reference** — needed by checkout, not by
  withdrawal safety, which §7.2 settles atomically.
- **Game Type projection on the Package component read** (§13.2).
- **The customer calendar** — a later phase consuming §6.4's predicate.
- **The Laravel Portal** — completely unchanged.
- Checkout, orders, entitlement, QR, redemption, webhooks, scheduled sync,
  reconciliation and SSO.

---

## 15. Manager Workflow

**Revision 9 amendment.** BR-077 ratifies the thirty-day sales window. Under
BR-078 a Manager may remove a future date without a disable-first refusal. The
removed occurrence becomes ineligible for purchase, disappears from Upcoming,
and cannot be enabled while absent from the committed definition. A successful
edit clears its sales flag. Protected history stays reachable through All (or
Archived for archived records). Retry Creating Sessions uses the existing
withdrawal/retention rules; this revision adds no archival phase. Re-adding a
retained date keeps sales OFF until the Manager explicitly enables it. No
advisory mutex, capability probe or infrastructure dependency is introduced.
Schedule-administration dates are displayed and accepted as `MM/DD/YYYY`;
database, SQL, API and internal canonical dates remain `YYYY-MM-DD`, with
strict conversion at trusted input/output boundaries.

**Revision 8 amendment note.** Everything below this note describes a
**manager-facing UX correction** made directly against §15 as it read in
Revisions 1–7: navigation, screen labels, and one new orchestration method
(`SessionScheduleAdmin::create_and_generate()`) that calls the *existing* §3.8
create service and then the *existing* §7.6 generation service, in that order,
inside the same request. **Nothing in §1–§14 or §16–§19 changed.** No table, no
column, no service method signature, no CAS statement, no reconciliation phase,
no run fence, no compare-and-swap protection, and no idempotency guarantee moved
or was rewritten. Concretely:

- `SessionScheduleService::create()` (§3.8) and `SessionScheduleService::generate()`
  → `OccurrenceReconciler` (§7.6) are called exactly as they always were —
  `create_and_generate()` is a two-line caller, not a second implementation, and
  it never runs generation unless create() itself returned `STATUS_OK`.
- `SessionScheduleAdmin::handle_generate()`, the `admin_post_` retry handler, is
  unchanged; `create_and_generate()` reuses it by calling the same
  `SessionScheduleService::generate()` method, not a parallel one.
- The Commerce Schedules / "Manage Session Dates" screen (`SessionScheduleAdmin`)
  and its edit/reconciliation-backed forms are unchanged; it is only removed from
  the **rendered admin menu** via `remove_submenu_page()` after
  `add_submenu_page()` registers it — a presentation-only WordPress technique
  that leaves the page hook, its capability gate and its nonce-protected POST
  handler fully intact and directly reachable at its own URL. Hiding a menu
  entry is not an authorization change.
- The sales window changed from five to thirty calendar days; see the §15
  amendment note's own cross-references at §5.3, §9 and §14 above for the single
  constant (`SessionService::SALES_WINDOW_DAYS`) both consumers read, and BR-051/
  BR-063 in `AGENTS.md`, updated to match.
- A Portal-connected hall's `GameTypeService::create()` now refuses with
  `game_type_portal_managed` (§13's `GameTypeAdmin`/`GameTypeService` are
  otherwise unchanged); `GameTypeAdmin::render()` additionally hides the create
  form for such a hall, using the same `is_hall_portal_connected()` signal the
  service enforces with, so the UI and the enforcement cannot diverge. Rename,
  archive and restore are untouched for both Portal-connected and legacy halls.

The **approved manager flow**, replacing the Revision 1–7 list below it:

1. **Portal Sync.**
2. **Create Play Products** using synchronized Portal Game Types.
3. **Create Packages** from Play Products.
4. **Bingo Sessions → Sessions from Portal** (labelled "Synced Templates" before
   this revision; same read-only screen, same replica, same class) lists every
   Portal session. An eligible, current, active Portal session offers
   **Add to Store**. Draft, inactive or superseded Portal sessions stay visible
   with their status and offer no route to a schedule. A Portal session that
   already has one or more schedules additionally lists **Manage Dates** per
   schedule — more than one is expected and supported, there is no one-to-one
   assumption — and **Retry Creating Sessions** for any schedule whose create
   committed but whose definition is not yet active.
5. **Add to Store** opens the create form (§3.8's existing fields: Portal
   session name and hall timezone read-only; start time; explicit dates or
   weekly recurrence; only the controls belonging to the selected mode; one or
   more Packages) with the main action labelled **Create Sessions**.
6. **Create Sessions** runs §3.8 Create exactly as before. If the Portal session
   stopped being eligible between validation and commit, the save is refused and
   says so, and generation never runs. If creation commits, the SAME request
   immediately calls §7.6 generation once, using the id the create step just
   returned — the amendment note's `create_and_generate()`.
   - Both steps succeed: redirect (HTTP 303), a plain-language success message,
     and a link to Bingo Sessions.
   - Creation succeeds but generation does not fully complete: the schedule is
     **not** rolled back or hidden; the Manager sees "The schedule was saved,
     but sessions were not fully created," safe committed counts, and
     **Retry Creating Sessions**, which calls the same idempotent §7.6 path.
   - Creation itself fails: generation never runs; the existing field-level
     errors and safe recovery behaviour (§3.8, §8.5) are unchanged.
7. Generated sessions appear on **Bingo Sessions**, **OFF**. The Manager
   **turns ON only the sessions ready for customers**, through §6.1/§6.2 exactly
   as before; the first ON still permanently protects the occurrence from
   withdrawal. Manager-facing copy distinguishes *enabled by manager* from
   *available to customers today*, the latter being the §6.4 predicate under the
   thirty-day window.
8. Eligible sessions surface on the customer calendar in a later phase — still
   out of scope for this pass, exactly as in Revision 1–7.

To change a generated occurrence's date, time or name, the Manager still edits
the **schedule**, from its **Manage Dates** link, reached from Sessions from
Portal rather than a normal menu entry. To change a schedule's timezone, the
Manager still archives it and creates a replacement. Both go through the
unchanged §3.6/§3.8 edit and reconciliation services and their existing
compare-and-swap and run-fence protections — the amendment does not add a
second way to reach them.

Internal vocabulary — definition tokens, reconciliation phases, occurrences
versus templates versus replicas, manual "Generate," internal operation codes —
remains in the code exactly as described throughout this document. None of it is
manager-facing copy; §17's file responsibilities and §7's phase names are
unchanged by this note.

**Revision 1–7 workflow, superseded by the list above (kept for the internal
service sequence it still accurately describes — §3.8 Create and §7.6 Generate
are unchanged, only who calls Generate and when changed):**

1. **Portal Sync** pulls templates (existing, unchanged).
2. **Bingo Sessions → Synced Templates** lists the replica read-only. Draft and
   non-current templates are listed with their status and cannot start a
   schedule.
3. **Create schedule** from a commerce-eligible template. The form captures:
   minute-precision start time; timezone (defaulted from the hall setting,
   **fixed for the schedule's life**, stated on the form); mode; explicit dates or
   weekday mask plus bounds; and one or more Packages. Date controls state
   *tomorrow or later*.
4. **Save** runs §3.8 Create. If the template stopped being commerce-eligible
   between validation and commit, the save is refused and says so. A schedule that
   fails to commit is listed as *incomplete* and can be re-saved or archived.
5. **Edit** an explicit-date schedule later and the passed dates appear under
   *Past dates*, each keepable or droppable; dropping one changes no occurrence
   (§8.3). A successful edit also cleans the superseded definition's rows (§3.7).
6. **Generate** runs §7.6's phases and returns the §8.6 result. Withdrawals happen
   first, so tidying a schedule works even while a Package or the template is
   temporarily invalid. A preflight blocker aborts before any write.
   `schedule_state_changed` means someone edited the schedule mid-run — re-run.
   `template_renamed` means the Portal template was renamed mid-run — re-run.
   `database_contention` means the database was busy — re-run.
7. Occurrences appear **OFF** and, briefly, *incomplete* until their Packages
   land. An incomplete occurrence cannot be sold or hand-edited; re-running
   Generate converges it.
8. **Turn ON** per occurrence, through §6.1/§6.2. The first ON permanently
   protects the occurrence from withdrawal.
9. Eligible occurrences surface on the customer calendar in a later phase.

Under Revision 8, step 6 above ("Generate") is no longer a normal first-time
manager action — it now runs automatically, once, immediately after a
successful Create (see the amendment note). It remains available, relabelled
**Retry Creating Sessions**, as the recovery path for a schedule whose
generation did not fully complete, and as a general, idempotent, manually
triggerable re-run for any active schedule — both reachable from Manage Dates
and from Sessions from Portal.

---

## 16. Required Test Matrix

New harness `tests/session-schedules-increment-1.php`, following
`tests/session-increment-1.php:113-158`: disposable hall for destructive work;
persistent halls receive ordinary domain DML against tracked fixture rows only;
run-token registry; baseline preservation; unproven rows reported, never deleted.

**FIXTURE ISOLATION (Revision 7, implementation amendment).**

Every harness that can apply a Sessions migration now boots
`tests/fixtures/wordpress-bootstrap.php` and runs against an allowlisted,
sentinel-owned `BC_TEST_DB`, never the development installation. This is
required, not tidiness: `bingocommerce-sessions` is **network-active**, so every
`wp_insert_site()` fires its `wp_initialize_site` handler and applies the current
Sessions schema to whichever database is booted — and
`tests/session-increment-1.php`'s `ensure_persistent_schema()` migrates halls 2,
3 and 4 directly. Against the development database that would write v3 structure
into the persistent halls.

The change is **behaviour-preserving**: without `BC_TEST_DB` the bootstrap does
exactly what the old `require wp-load.php` line did, so previously recorded
results remain reproducible the same way.

- `tests/session-increment-1.php` and
  `tests/portal-session-template-replica-persistence.php` additionally **fail
  closed before WordPress boots** when `BC_TEST_DB` is unset, not allowlisted, or
  names the development database, and assert the selected database again
  afterwards.
- A worker MUST be routed with its parent: `proc_open()` inherits the
  environment, so a worker still bound to `wp-load.php` would race its parent
  across two different databases.
- Hall-specific fixture data the development installation has and a fresh
  fixture does not — Hall Manager and Game Operator accounts, Action Scheduler's
  per-site tables — is recreated inside the fixture. The capability row is
  planted directly, exactly as `tests/hall-staff-create-invite.php` already does,
  because ADR-025's `HallStaffWriterGuard` deliberately refuses
  `add_user_to_blog()` for a hall role. **No production class is copied or
  replaced**; the real plugin code under `wp-content/plugins` is what runs, and
  no assertion is weakened.
- Cleanup lists and drop allowlists in the affected harnesses gain the three new
  Sessions v3 tables.

This supersedes §17's earlier statement that only two harnesses change.

**Concurrency tests must use deterministic barriers or interceptors — a filter, a
lock file, or a coordinated worker blocking at a named point. No test may decide a
winner with `sleep()`, and no contention or process-death test may manufacture
timing by waiting.** `tests/play-product-row-lock-worker.php` is the model.

### A. SQL correctness (1–7)
1. Create-commit contains `active_definition_token IS NULL` and no placeholder for it.
2. Edit-commit contains `active_definition_token = %s`.
3. `dates` statements contain literal `recurrence_start = NULL, recurrence_end = NULL`.
4. `weekly` statements bind validated non-null bounds.
5. No emitted statement passes PHP `null` to a `%s`, asserted by intercepting prepared SQL.
6. The provisional `INSERT` satisfies every non-null column, executed against the real table in strict mode.
7. **Every reconciliation, definition-commit and availability statement's final SQL is `EXPLAIN`-validated against the live server, and none triggers MySQL error 1093.**

### B. Definition commit, template guard and dependency timing (8–19)
8. Two concurrent definition writes, barrier-released: different tokens, exactly one commit affects a row, the other returns `schedule_state_changed`.
9. Each editor's child rows carry only its own token; no set is merged.
10. After the winning commit, every header field and the token reflect the winner, from one row read.
11. **Create commit affects zero rows when the template is made non-current between validation and the CAS**; the schedule stays inert; the result says `template_not_commerce_eligible`, not `schedule_state_changed`.
12. **Create commit affects zero rows when the template is made `draft`** between validation and the CAS.
13. **Edit commit affects zero rows** under the same two template invalidations; the previous definition is intact and unchanged.
14. **Schedule restore affects zero rows** when the template is non-current or draft.
15. **Schedule archive succeeds** while the template is non-current — stopping commerce is never blocked.
16. A failed commit deletes only its own private token, and nothing when the re-read shows its token active.
17. **No staged-token cross-request deletion**: while editor B's token is staged, editor A's failed cleanup runs and B's rows still exist; B then commits.
18. A schedule with `active_definition_token IS NULL` generates nothing and is excluded from `isSellable()`.
19. **Dependency invalidation between validation and commit (§3.2).** A configured Package is made unassignable after request-time validation but before the CAS. The commit **succeeds** — this is expected and documented. The test then asserts the fail-closed consequences: Generate stops at the phase-2 gate with `eligibility_failed` and creates nothing; `setAvailableForSale(ON)` fails its pre-proof; and `isSellable()` returns a `WP_Error` or false for every occurrence of that schedule. **No claim of atomic Package validity at commit is made or tested.**

### C. Superseded-definition cleanup (20–23)
20. **A successful edit deletes the superseded `$seen` token's date and Package rows**, and leaves the new token's rows and every other token's rows untouched.
21. **Cleanup affects zero rows if `$seen` is somehow active again**: the guard `sch.active_definition_token <> $seen` is proven by forcing that state before cleanup runs.
22. **Old-token reconciliation worker during cleanup**, barrier-released: a run that captured `$seen` is mid-phase when the edit commits and cleanup deletes `$seen`'s rows. The worker's next guarded mutation affects **zero rows**, its between-phase re-proof stops it with `schedule_state_changed`, and **no row is written using the deleted definition**.
23. Cleanup never deletes the current active token's rows, and never deletes another editor's staged token, asserted by enumerating rows per token before and after.

### D. Template rename proof (24–27)
24. The name proof uses `CAST(… AS BINARY)`, asserted by intercepting the emitted SQL; the column-collation comparison is **not** used for rename detection.
25. **Rename immediately before a create INSERT** → the statement affects **zero rows**; no occurrence is created; the run stops with `failure_category = template_renamed`.
26. **Rename immediately before a retime UPDATE** → zero rows; the occurrence is unchanged; same failure category.
27. **Rename immediately before an unlocked restore UPDATE** → zero rows; the occurrence stays archived; same failure category.
28. **A case-only rename** (`Evening Bingo` → `evening bingo`) is **detected** — proving the binary comparison, since the column collation would call these equal.
29. **An accent-only rename** (`Evening Bingo` → `Évening Bingo`) is detected for the same reason.
30. **Locked restore, repair and withdrawal carry no name proof** and still succeed after a rename, because they write no `display_name`.
31. After a rename-failed run, a **retry** captures the new `$run_template_name`, the proof matches, and the run returns `status = ok`.

### E. Guarded availability, ownership and races (32–42)
32. Every ON writer sets `sale_locked_at`, asserted by enumerating call sites and executing each.
33. First ON sets the lock; OFF does not clear it; a second ON does not change the stored timestamp.
34. **ON versus withdrawal**, barrier-released: ON cannot rescue a date already removed from the definition. The forced ON is refused and existing withdrawal may remove the unlocked row and its assignments. If removal/withdrawal occurs after ON prechecks but before its guarded UPDATE, ON fails. Sale-locked retention and ON-versus-retime protections remain unchanged; no serialized definition-removal contract is asserted.
35. **ON versus retime**, barrier-released: if ON wins, the retime affects zero rows, re-reads and classifies.
36. An occurrence with `generation_completed_at IS NULL` cannot be turned ON.
37. Schedule archived, or template invalidated, between pre-proof and the ON update → the ON affects zero rows and no token is written.
38. **Package invalidated between the ON update and the post-proof** → the occurrence is forced OFF **by its own `availability_operation_token`**, the result is a failure, and `sale_locked_at` **remains set**.
39. **Compensation cannot touch a row it does not own**: the token is changed between the ON update and the compensating UPDATE; compensation affects **zero rows** and the request writes nothing further.
40. **Three-request ownership test, barrier-released.** A turns ON (token Ta) and pauses before its failed post-proof. B turns it OFF. C turns it ON again (token Tc). A resumes and compensates. **Assert: the occurrence is still ON, still carries Tc, and A changed nothing.**
41. Successful settlement clears only the request's own token; if another request already took ownership the settle affects zero rows and that is **not** an error. Normal OFF clears the token and never clears the lock.
42. **Process death between the guarded ON and settlement (§6.5), deterministic kill.** Assert: the occurrence is ON, `sale_locked_at` is set, `availability_operation_token` is **non-NULL and unsettled**; `isSellable()` is unaffected by the token and still fails closed on its own facts; a subsequent OFF clears the token; a subsequent ON by another request replaces it; and the dead request's token can never match again.

### F. Mutation ownership (43–50)

**Implementation status.** Items 43–45 and 49–50 are implemented and covered in
`tests/session-availability-increment-1.php`. `OccurrenceGenerationWriter`
exists, and items 46–48 are implemented and covered by
`tests/session-reconciliation-increment-1.php`'s
`generation_writer_unreachable_group()`.

43. Direct `rename()`, `reschedule()`, `updateDetails()`, `archive()`, `restore()` on a generated occurrence are refused **at the service layer**, called directly.
44. The same refusals hold through `SessionAdmin`'s POST handler.
45. `assignPackage()` / `removePackage()` refused while incomplete, allowed after completion.
46. **The internal generation writer is not reachable** from any admin screen, POST handler or REST route, asserted by enumeration.
47. The internal writer affects zero rows against a completed occurrence, a locked one, and an ON one.
48. The internal writer affects zero rows when the template is ineligible.
49. `setAvailableForSale()` on a generated occurrence routes through §6.1/§6.3.
50. Every writer behaves observably as at v2 for `schedule_id IS NULL`, against a captured baseline.

### G. Incomplete generation — overlapping workers (51–60)
51. A Package write forced to fail after the first assignment leaves the occurrence incomplete with a partial set, `complete = false`, `status = failed`, and the run **stopped** (no later phase ran).
52. That occurrence is unsellable and rejects manual Package edits.
53. **Two repairs using the same token**, barrier-released: exactly one completion succeeds.
54. **Same-token convergence**: after both workers finish, the final assignment set is **exactly** the definition's and contains no duplicate rows.
55. **Old-token repair versus new-token repair**: the old-token worker's remove-extras, insert-missing and completion statements each affect **zero rows**; the new-token worker converges.
56. **Definition change between the final Package write and completion**: completion affects zero rows; the occurrence stays incomplete; the next run converges.
57. **Stale worker after another worker completed**: its insert-missing, remove-extras and completion statements each affect **zero rows**.
58. **Duplicate-key rule, expected state**: a concurrent worker inserts the same `(session_id, package_id)` first; the loser catches **1062**, re-reads under the same schedule id and run token, finds the expected state, treats it as satisfied and continues.
59. **Duplicate-key rule, unexpected state**: the re-read does not show that state; the run stops with `complete = false`, `status = failed`. **No `INSERT IGNORE`** appears in any emitted statement, and a non-duplicate error stops the run rather than being swallowed.
60. The exact-set completion statement refuses to complete when a required Package is **missing**, and when an **extra** Package is present — both proven by seeding each state directly.

### H. Empty Package definition (61–63)
61. **Phase 2 fails** when the active definition has **zero** Package rows: `complete = false`, `status = failed`, `failure_category = eligibility_failed`, and no occurrence is created, repaired, retimed or restored.
62. **The completion statement affects zero rows** against a zero-Package definition, proven by seeding that state directly and bypassing the gate — demonstrating the SQL `EXISTS` is load-bearing and that both `NOT EXISTS` branches would otherwise pass vacuously.
63. **§7.12 proves non-emptiness before iterating** and returns `complete = false`, `status = failed` — never `ok` — for a zero-Package definition.

### I. Withdrawal (64–69)
64. Withdrawal removes the occurrence and its Package rows in **one statement**; both are gone.
65. A forced failure of the withdrawal statement leaves **both** the occurrence and all its Package rows intact — **no undiscoverable residue is possible**.
66. Withdrawal of a **sale-locked** occurrence affects zero rows and deletes no Package row. **No path in the design hard-deletes a sale-locked occurrence**, asserted by enumerating every `DELETE` against `bingo_sessions`.
67. An occurrence with assignments but future, OFF and unlocked **is** withdrawn, with its assignments. An **incomplete** one outside the definition is withdrawn. An **archived** one is **not**, and is classified `retained_outside_pattern`.
68. **Withdrawal while one configured Package is unassignable** succeeds; the run then ends `complete = false`, `status = failed`, `failure_category = eligibility_failed`, with `withdrawn` counted accurately.
69. **Withdrawal while the template is non-current** behaves identically to test 68.

### J. Database contention (70–72)
70. **Deadlock injection (1213)** on a reconciliation statement: the run stops, `complete = false`, `status = failed`, `failure_category = database_contention`; the reconciler re-reads authoritatively before reporting; earlier committed phases are counted accurately; no SQL or driver text appears in the result or the audit context. **No sleep is used.**
71. **Lock-wait timeout injection (1205)**: identical assertions.
72. A new run after either error converges from current state without duplicating or losing work.

### K. Reconciliation classification and result status (73–84)
73. One deterministic assertion per §7.6 row 1–13.
74. **`complete = false` is never paired with `status = ok`** — asserted across every failure path in this matrix.
75. **`complete = true` implies `status = ok`** and a null `failure_category`.
76. `protected_time_conflict` aborts at preflight with **zero writes**, `failed`, `preflight_conflict`.
77. `incomplete_integrity_conflict` aborts at preflight with **zero writes** and the same status shape; no Package row is rewritten.
78. Historical retained classifications are informational and **do not** fail the run.
79. **An archived future incomplete occurrence in T is repaired in phase 3 and restored in phase 6 of the SAME run** — a single Generate leaves it active, complete and OFF.
80. Retime corrects in place: the `session_id` is unchanged.
81. Restore preserves Package assignments exactly; locked restore also preserves `sale_locked_at`, time and name.
82. **No past occurrence is created, restored, rewritten, retimed or deleted in any phase** — a byte-identical snapshot of every past row across a run that adds, removes and retimes future dates. Covers incomplete past rows.
83. A failed phase stops the run and no later phase executes — asserted by instrumenting phase entry.
84. **Phase order is withdrawal-first**, asserted by instrumenting phase entry order.

### L. Dates, timezone, precision, naming (85–97)
85. Today rejected; tomorrow accepted — explicit dates and `recurrence_start`, in the schedule's timezone.
86. Editing a weekly schedule after its original start date succeeds; `recurrence_start` preserved; only tomorrow-onward emitted.
87. **Editing an explicit-date schedule after some dates became historical succeeds**; a previously committed historical date MAY be omitted, the edit commits, and **every historical occurrence is byte-identical afterwards**; it may also be kept, exempt from validation.
88. **No 400-date lifetime trap**: a schedule that has accumulated 400 committed dates, most now historical, can be edited to drop passed dates and add new future ones, and the edit commits.
89. Adding a future date succeeds; adding a historical date not previously committed is refused `historical_date_not_addable`.
90. **A Package-only edit succeeds** on an explicit-date schedule whose definition contains dates that have since passed.
91. **Minute precision**: `18:00:30` refused `invalid_start_time`; `8:00` refused; `18:00` accepted and stored `18:00:00`; two schedules at `18:00` and `18:01` produce different names.
92. **Structured-time integrity probe**: a generated occurrence seeded with `start_time_local IS NULL`, one with `timezone_name IS NULL`, one with `starts_at_utc IS NULL`, and one with non-zero seconds each **fail** `verify_current_site_schema()`; a legacy row with all four NULL **passes**.
93. Timezone immutability: no code path updates `timezone_name`; an edit attempt is refused; changing the site option leaves `starts_at_utc`, `start_time_local` and `timezone_name` byte-identical on every occurrence.
94. DST: `2026-03-08 02:30` → nonexistent; `2026-11-01 01:30` → ambiguous; `2026-03-07 18:00` → `2026-03-07 23:00:00`; `2026-03-08 18:00` → `2026-03-08 22:00:00`; a weekly schedule spanning both transitions yields the exact `starts_at_utc` for every date; `Australia/Sydney` asserted for one transition.
95. Generated eligibility and future/past decisions use the occurrence's stored `timezone_name`, proven with a hall whose site option names a different zone; legacy occurrences use `wp_timezone()` exactly as at v2, same fixture.
96. Naming: `<template> — HH:MM`; changing `time_format` changes no stored name; a 200-character template truncates to 183 and keeps the full suffix; truncation by characters, proven with a multi-byte name; `"Late Show 9:00 PM"` with `start_time_local = 18:00` generates `— 18:00`.
97. **Collation**: the test reads the live column collation via `SHOW FULL COLUMNS` and asserts **collision detection** behaves per that measured collation, while **rename detection** uses the binary comparison (test 24); case- and accent-differing template names on the same date and time are a preflight blocker; no hidden character or Portal ID is appended.

### M. Migration, rollback, isolation, audit (98–108)
98. v2→v3 migration leaves every existing row byte-identical; all six structures and both integrity probes verify; the version persists only after all of them; the binary collation asserted on **every** identity column — `ascii_bin` on the three new tables', **`utf8mb4_bin`** on `bingo_sessions`'s `timezone_name` and `availability_operation_token` (§2.4); **seven** new `bingo_sessions` columns asserted.
99. `maybe_migrate_network()` and `maybe_migrate_current_site()` still work on their existing hooks and priorities; no migration UI and no `sessions.schema.*` audit action exists.
100. Portal synchronization **succeeds while the hall remains at Sessions v2**; a structurally damaged replica fails; a damaged `bingo_sessions` with intact replicas still synchronizes; `assert_snapshot_schemas()` issues no DDL.
101. **Rollback baseline commit (§12.3)**: the test asserts `git log --follow` over the Sessions `Migrations.php` yields exactly the two known commits; that `488c064dbaf4a0bfd4126e18afdd9de848ff86b5` carries `DB_VERSION = 2`; and that `git diff 488c064 <v3-parent> -- wp-content/plugins/bingocommerce-sessions/` is empty. **If any of these fails the baseline is stale and the test fails loudly rather than testing the wrong commit.**
102. **Rollback, frozen-source assertions (§12.3a)**: the v2 source at `488c064…` contains no `sale_locked_at`, no `generation_completed_at`, no `availability_operation_token` and no `schedule_id`. **No test forces `DB_VERSION` and calls it v2.**
103. **Rollback, seeded-state repair (§12.3b)**: seeded ON-without-lock, stale-token, drifted-name/date and incomplete-but-ON rows are handled by the forward repair pass — lock set, stale token cleared, drift reported without rewrite, integrity conflict flagged.
104. The stored version is never lowered by any code path.
105. Hall isolation: hall B cannot read, edit, archive, restore or generate from hall A's `schedule_id`; cannot use a `portal_session_id` absent from hall B's replica; a hall A `package_id` is not configurable on a hall B schedule; `request_int('-5')` yields `-5`.
106. Handler ordering: GET → 405, non-hall → 403, missing capability → 403, bad nonce → dies, in that order.
107. Audit: correct `target_type` / `target_id`; every payload and context key asserted against `SENSITIVE_KEY_TOKENS` and `SENSITIVE_KEY_NAMES`; generation context carries counts, `status` and `failure_category` only, and **never** `run_template_name`.
108. Compatibility regression: re-run `tests/session-increment-1.php` with only the `start_time` assertion amended; re-run `tests/portal-session-template-replica-persistence.php` and `tests/portal-snapshot-application.php` with **no assertion changes**, only cleanup-list extensions. Disposable-hall teardown drops all six tables; persistent-hall baselines byte-identical; role/capability snapshot unchanged.

---

### N. Revision 9 membership regressions (109–117)

**Implementation status.** Items 109–117 are implemented in the current
repository. The focused coverage is in
`tests/session-availability-increment-1.php`'s
`removed_definition_group()`, with existing withdrawal/past-immutability
coverage in `tests/session-reconciliation-increment-1.php` and legacy behavior
coverage in `tests/session-increment-1.php`.

109. BR-077 retains inclusive `D - 30 <= local_today <= D`, ON, and every other
eligibility requirement; historical five-day references are not rewritten.
110. Generate two explicit dates, turn one ON, remove it and save, disable
sales, retry generation: the retained occurrence is absent from Upcoming, has
no Enable Sales action, and forced service/handler ON is refused.
111. Repeat membership exclusion for weekly bounds and weekday-mask changes,
using the Monday-zero bit mapping and inclusive endpoints.
112. `SessionEligibility` returns false for a removed date even with a stale ON
flag; unreadable membership fails closed.
113. All/history still reaches retained rows, with IDs, Packages and sale locks
preserved; already archived removed rows remain reachable under Archived.
114. Re-add the removed date and generate again: the preserved occurrence stays
OFF with the same ID and Packages. Removal while ON does not make re-add resume
sales, even without an intervening manual OFF.
115. Existing guarded withdrawal, archived restoration and past immutability
tests still pass; no new reconciliation concurrency or archival phase is added.
116. Legacy Sessions retain their existing availability and administration.
117. Definition edits retain their existing CAS behavior. No advisory-lock,
readiness, session-affinity or reconnect infrastructure is required or tested.

## 17. Implementation Files in the Current Repository

These production files exist in the current repository. Their presence does
not approve this draft or claim production deployment.

**Production** (`wp-content/plugins/bingocommerce-sessions/src/`)

| File | Responsibility |
|---|---|
| `SessionScheduleService.php` | schedule CRUD, request-time validation, token generation, §3.5/§3.6 template-guarded CAS, §3.8 removed-future sales invalidation, §3.7 cleanup, audit |
| `SessionScheduleResult.php` | mirrors `SessionResult.php`; carries `status`, `complete`, `failure_category` and the §8.6 counts |
| `ScheduleTimezone.php` | named-IANA option capture, minute-precision validation, §5.2 exact-instant algorithm |
| `ScheduleExpansion.php` | pure date expansion, limits, tomorrow-or-later filtering, past-date retention rules (§8.3). No DB, no WP |
| `OccurrenceReconciler.php` | preflight, run fence incl. `$run_template_name`, §7.6 phases in withdrawal-first order, §7.12 final verification, counts |
| `OccurrenceGenerationWriter.php` | the internal, non-Manager Package writer (§7.10) and the convergent repair statements (§7.3), including the duplicate-key rule |
| `OccurrenceAvailability.php` | §6.1 guarded ON, §6.2 owned settlement and compensation, §6.3 OFF, §6.5 unsettled-token semantics, classification |
| `SessionEligibility.php` | §6.4 composed purchase predicate and centralized explicit/weekly committed-definition membership used by SQL guards, services and administration |
| `DatabaseContention.php` | 1213 / 1205 classification into the fixed `database_contention` category (§7.11) |
| `PortalTemplateGateway.php` | the only read path into the replica; fail-closed, mirroring `PackageGateway.php` |
| `SessionTemplateAdmin.php` | read-only "Sessions from Portal" screen (labelled "Synced Templates" before Revision 8; same class, same table, same read-only contract — §15 amendment note) |
| `SessionScheduleAdmin.php` | Schedules screen, past-date keep/drop editor, `admin_post_` generation handler |

Twelve files. `OccurrenceProtection.php` and `SessionSchemaAdmin.php` are **not**
part of this design.

**Production — modified**

| File | Change |
|---|---|
| `…/src/Migrations.php` | `DB_VERSION = 3`; three `CREATE TABLE`; **seven** `ADD COLUMN`; two indexes; three verifiers; extended `bingo_sessions` expectations; both §2.5 integrity probes. **No change to either migration hook or gate.** |
| `…/src/SessionService.php` | **seven** columns in `COLUMNS`, `map_row()`, `audit_payload()`; §9 refusals (see the correction note under §9 — not present in the first Increment 3 commit, added in the follow-up correction pass); completion gate in `assignPackage()` / `removePackage()`; `setAvailableForSale()` routed through `OccurrenceAvailability`; membership-aware ON, Upcoming and action data; per-row local-today selection; `evaluate_available_today()` **unchanged** |
| `…/src/SessionAdmin.php` | parent menu + two submenus; Time / Schedule / Source / Sale-locked / Generation columns; generated-row controls hidden, including Enable Sales for removed dates (presentation only) |
| `…/src/Plugin.php` | register the two new admin screens. **Existing migration hook registrations unchanged.** |
| `…/bingocommerce-sessions.php` | twelve `require_once` |
| `…/portal-integration/src/PortalSyncService.php` | `assert_domain_schemas()` → `assert_snapshot_schemas()` (§11.3) |

**Tests — new:** `tests/session-schedules-increment-1.php`,
`tests/session-schedule-commit-worker.php` (concurrent commit, template
invalidation, superseded-cleanup vs old-token worker),
`tests/session-occurrence-race-worker.php` (ON vs withdraw, ON vs retime, ON vs
invalidation, three-request ownership, **process-death between ON and
settlement**),
`tests/session-generation-repair-worker.php` (overlapping repair workers,
same-token and cross-token, duplicate-key and stale-writer cases).

**Revision 9 focused tests:** `tests/session-availability-increment-1.php`
(explicit/weekly membership, service and handler refusals, UI/Upcoming,
purchase eligibility, history and re-add OFF), and
`tests/session-reconciliation-increment-1.php` (E34 updated for membership).
Run these with `tests/session-schedules-increment-1.php`,
`tests/session-schedule-admin-increment-1.php` and `tests/session-increment-1.php`
against the existing isolated disposable fixture. No full matrix or mutation
campaign is part of this correction.

**Tests — modified:** `tests/session-increment-1.php` (the `start_time` assertion
at `:201`; disposable-hall table list and regex at `:1406-1424`),
`tests/portal-snapshot-application.php` (`domain_table_suffixes()` at
`:1719-1724`, cleanup regex at `:1793`),
`tests/portal-session-template-replica-persistence.php` (cleanup list only —
**the forbidden-column assertion stays exactly as it is**),
`tests/setup-readiness.php` (only if a schedules check is added).

**Docs — synchronized with the current implementation:** active guidance,
requirements, architecture and status documentation already reflect this DRAFT
implementation without approving it or claiming deployment. After ratification,
merge the ADR decision into `docs/decisions/DECISIONS.md`, remove this draft file
as appropriate, and update `PROJECT_STATUS.md` to record ratification.

---

## 18. Decisions Closed by This ADR

| Decision |
|---|
| **BR-077 ratifies the inclusive thirty-day sales window**, superseding only the historical numeric five-day width. |
| **BR-078 uses one current-definition membership rule** across guarded ON, purchase eligibility, Upcoming and admin controls; missing or unreadable membership fails closed. |
| **Definition removal does not require sales to be disabled first.** A successful edit clears sales on excluded future occurrences; re-add does not enable them. Existing reconciliation/history rules and schema remain unchanged. |
| **Create commit, edit commit and schedule restore prove template commerce-eligibility in SQL.** Archive does not — stopping commerce is never blocked. |
| **Package assignability is request-time validation, not an atomic commit guarantee.** The authoritative fail-closed checks are the phase-2 gate, occurrence ON, storefront eligibility and §7.12. |
| **Every statement that writes a `display_name` proves the template name is unchanged**, using `CAST(… AS BINARY)` — not the case- and accent-insensitive column collation. A rename affects zero rows and requires a retry. |
| **A zero-Package definition can never complete an occurrence or return `ok`**, proved at the phase-2 gate, in the completion SQL, and in §7.12 before iteration. |
| **Superseded definition rows are deleted after a successful edit**, guarded by SQL proving the token is no longer active. Only crash-abandoned private tokens remain for the deferred GC design. |
| **A non-NULL `availability_operation_token` means an unsettled ON transition**, which may outlive its process. Storefront eligibility ignores it and fails closed on its own facts; OFF clears it; no older request can compensate once ownership changes. |
| **An occurrence that has ever been offered for sale is never hard-deleted by any path**, including the integrity runbook. |
| **Every reconciliation write is fenced on `$run_definition_token`**, re-proved between phases and in §7.12. |
| **Withdrawal runs first and is the only eligibility-exempt reconciliation mutation**; a successful withdrawal is never rolled back or misreported. |
| **Withdrawal is one atomic multi-table DELETE.** No orphan state is reachable. |
| **Repair is convergent set reconciliation under token and template fences**, completing only on a database-proved exact, non-empty set match. |
| **Duplicate keys are handled explicitly**: catch 1062 only, re-read, continue only on the exact expected state; never `INSERT IGNORE`. |
| **ON claims an `availability_operation_token`; compensation is fenced on it**, so an older request can never turn OFF a newer successful ON. `updated_at` is not an ownership fence. |
| **Deadlock (1213) and lock-wait timeout (1205) stop the run** with `database_contention`, an authoritative re-read, and no in-run retry. |
| **`complete = false` is never paired with `status = ok`**; every failure carries a fixed category. |
| **An archived future incomplete occurrence is repaired and restored in the same run.** |
| **Timezone is immutable after schedule creation.** |
| **Passed dates may be dropped from a later definition without touching any occurrence**, so no 400-date lifetime trap exists. |
| **Time is minute-precise**, and a generated occurrence must carry complete structured time. |
| **Name, date, time and lifecycle of a generated occurrence are schedule-owned.** |
| **A writable v2 rollback is unsafe.** The verified baseline is `488c064dbaf4a0bfd4126e18afdd9de848ff86b5`, **not** HEAD. |
| **`EXPLAIN` proves syntax and plan only.** Every concurrency claim has a deterministic test. |
| **A schedule never selects a Game Type.** |

**The one ratified-test amendment this ADR requires** is a single assertion at
`tests/session-increment-1.php:201`, which currently forbids any `start_time`
substring in `bingo_sessions`. It may be amended only on these terms: the
replacement keeps an explicit comment scoping it to BR-073's supersession of
BR-050 for structured commerce time, and **the forbidden replica-column assertion
at `tests/portal-session-template-replica-persistence.php:342-350` remains
unchanged**.

---

## 19. Accepted Operational Dependencies

Neither is a design gap; both are recorded so nobody discovers them in
production.

### 19.1 `incomplete_integrity_conflict` requires a Platform Admin runbook step

The state is unreachable through §6.1 and arises only from data damage or a
v2-rollback window (§12.2). Because it is a preflight blocker (§8.4), one such row
blocks further generation for that schedule, and **nothing in this phase clears it
automatically**.

**The runbook is non-destructive. It may not hard-delete the occurrence.**

1. **Force the occurrence OFF** through the ordinary §6.3 path. This clears any
   `availability_operation_token` and stops the occurrence being offered. It does
   **not** clear `sale_locked_at`, and must not.
2. **Investigate** why an incomplete occurrence was ever ON or sale-locked —
   almost always a rollback window or direct data manipulation.
3. **Resolve deliberately, one of two ways:**
   - **complete it** — repair its Package set to match the active definition and
     set `generation_completed_at`, after which it behaves as any generated
     occurrence; or
   - **archive it** — leaving the row, its `sale_locked_at`, its Package
     assignments and its full history intact and out of the pattern.
4. **The occurrence record and `sale_locked_at` are preserved in every path.**
   **An occurrence that has ever been offered for sale is never hard-deleted** —
   not by reconciliation (§7.2's `sale_locked_at IS NULL` guard), not by an
   administrator, and not by this runbook. Archiving is the terminal state for
   something that must stop being sold but must not stop existing.

A future increment may add a reviewed administrative action for steps 1 and 3;
this one does not.

### 19.2 Crash-abandoned private definition tokens accumulate

§3.7 cleans superseded definitions after every successful edit and private tokens
after every failed one. What remains is the narrow case of a request that died
between staging its rows and running either cleanup. Volume is bounded by crash
frequency and each row is a handful of bytes. Garbage collection is deferred to
its own reviewed design (§0).

### 19.3 Revision 9 introduces no infrastructure dependency

Membership is evaluated through ordinary prepared `$wpdb` statements against
the existing schedule, schedule-date and occurrence tables. No `GET_LOCK`,
`IS_USED_LOCK`, `RELEASE_LOCK`, readiness probe, physical-session-affinity check
or reconnect detection is required. Existing CAS, operation-token and run-fence
protections remain as described above. The earlier Revision 9 advisory-lock
protocol and its two allowed commit orders are superseded by BR-078's simpler
membership-based rule; they are not deployment requirements.

*Rationale:* separating Portal template identity, Commerce schedule definition and
dated occurrence into three tables keeps Portal operational fields from silently
becoming commerce rules, while an additive-only schema preserves every existing
row, index, writer and test. The correctness of the whole design rests on one
discipline: **no write is authorized by something the application read earlier** —
and where that is impossible, the ADR says so plainly instead of claiming an
atomicity it cannot deliver. Package assignability is PHP and cannot join a SQL
guard, so it is named as request-time validation and backed by four independent
fail-closed checks. Everything that *can* be proved in the statement is: the
definition token, the schedule's status, the template's eligibility, the template's
name byte for byte, the non-emptiness and exactness of the Package set, and the
ownership of an availability transition. A rename that would otherwise be stored
silently now stops the run; a zero-Package definition that would otherwise pass two
vacuous `NOT EXISTS` branches now cannot; a superseded definition no longer
accumulates; an ON transition whose process died is a described, harmless state
rather than an unexamined one; and an occurrence that was ever offered for sale is,
by construction, never deleted by anything.
