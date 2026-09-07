# Portal Integration Architecture

Status: approved authority and boundary decision; implementation proceeds in
reviewed increments tracked in `PROJECT_STATUS.md`.

This document expands ADR-035 and BR-071 through BR-075. It defines the
integration boundary between the existing Laravel Bingo Innovation Portal and
the WordPress Multisite/WooCommerce BingoCommerce platform. Except for
ADR-036's narrow Hall snapshot transport URL contract and ADR-037's Hall
snapshot v1 validation contract, it is not an API, webhook, persistence,
provisioning, or SSO implementation design.

All Portal identities below belong to the single authoritative Bingo Innovation
Portal environment:

- Hall: `halls.id`;
- Game Type: `game_types.id`; and
- Session template: `bingo_sessions.id`.

Names are attributes and MUST NEVER be cross-system identities.

## 1. Authority Boundary

| Domain | Authoritative system |
|---|---|
| Hall identity | Portal |
| Game Type identity and configuration | Portal |
| Operational Session-template identity | Portal |
| Operational Session `days` | Portal, operational use only |
| Games, Patterns, Caller, device/XML behavior | Portal |
| WooCommerce Products | BingoCommerce |
| Bingo Play Products and Packages | BingoCommerce |
| Pricing, inventory, and commerce configuration | BingoCommerce |
| Commerce schedule and structured commerce time | BingoCommerce |
| Dated commerce occurrences | BingoCommerce |
| Package-to-occurrence availability and five-day eligibility | BingoCommerce |
| Checkout, orders, and payments | BingoCommerce/WooCommerce |
| Future entitlement, QR, and redemption commerce state | BingoCommerce |

This split does not make BingoCommerce a marketplace. One Portal Hall maps to
one isolated WordPress site/store, and one order belongs to one hall.

## 2. Stable Cross-System Identity

### Hall

```text
Portal halls.id <-> WordPress Site ID
```

Names and domains are editable routing/display attributes. They are not trusted
integration identity. Phase 3B implements the one-to-one Portal Hall ID / Site
ID persistence foundation through the network-wide
`bingo_portal_hall_mappings` table and its mapping repository. Tenant
provisioning and enablement lifecycles, plus reconciliation/recovery behavior
beyond that mapping foundation, remain deferred.

### Game Type

Portal `game_types.id` is the trusted Game Type identity. Portal Game Type
creation and rename are allowed. Its name is editable, and rename preserves
`game_types.id`. Once created, a Portal Game Type cannot be deleted. Archive and
Restore are not required in Portal v1.

`device_group_id` and XML GameGroup IDs remain hardware/XML/device concerns and
MUST NEVER be used as BingoCommerce integration identities. XML-generated
GameGroup identifiers MUST NEVER be BingoCommerce integration identities.

BingoCommerce retains its existing hall-scoped Game Type domain as a local
commerce replica. The replica may keep its local surrogate ID and machine code
for internal relationships and implementation compatibility, but it must be
linked to Portal `game_types.id` and must not present either local value or the
editable name as cross-system identity. Phase 3C1 implements that replica link
as nullable, unique `bingo_game_types.portal_game_type_id`. It does not
implement synchronization or import.

Existing local create, rename, archive, restore, and currently disabled hard-
delete behavior remains in place during the transition. It is transitional
BingoCommerce behavior, not a Portal v1 lifecycle feature, and will be
reconsidered when Portal synchronization becomes authoritative. Restricting
local writers is a later implementation step that occurs only after
synchronization, idempotent mapping, reconciliation, and recovery behavior are
proven.

## 3. Session and Commerce Scheduling Model

The integration has three deliberately separate layers:

```text
Portal Session template
        |
        | stable bingo_sessions.id; owning Portal halls.id
        v
BingoCommerce commerce schedule
        |
        | commerce weekdays, recurring yes/no, start/end,
        | specific dates, structured commerce time
        v
BingoCommerce dated commerce occurrence
        |
        | Package availability, five-day sales eligibility,
        | purchase/order attribution, future entitlement state
        v
Customer purchase references this exact occurrence
```

For example:

```text
Portal template: Evening Session

Commerce schedule:
  weekdays: Thursday + Friday
  recurring: yes
  start: 2026-09-03
  end: 2026-12-31
  time: 18:00

Dated occurrences:
  2026-09-03 18:00
  2026-09-04 18:00
  2026-09-10 18:00
  2026-09-11 18:00
  ...
```

Only Portal Sessions owned by the mapped Portal Hall are eligible for v1 sync.
Received/shared Sessions are excluded. An `allSessions()`-equivalent Portal
query is therefore not an eligible integration source.

The conceptual eligibility rule is:

```text
bingo_sessions.hall_id == mapped halls.id
```

This expresses the authority boundary; it does not select an API query or a
storage schema.

Phase 3C2 implements separate hall-local Portal Session-template replica
persistence in `bingo_portal_session_templates`. This non-destructive
persistence foundation does not implement snapshot application/import or
conflate Portal templates with existing dated Commerce Sessions.

Portal Session `days` are independent from BingoCommerce Commerce scheduling in
v1. They MUST NOT drive Commerce scheduling, generate Commerce occurrences, or
constrain or validate Commerce scheduling. Future advisory validation between
Portal operational days and Commerce scheduling MAY be introduced only by a
separately approved future requirement.

For a dated occurrence on date `D`, the current BingoCommerce storefront rule
continues to apply:

```text
D - 5 calendar days <= hall_local_today <= D
AND Available for Sale = ON
```

The hall site's WordPress timezone supplies `hall_local_today`. Structured
Commerce time is owned by BingoCommerce and may be entered manually by the Hall
Manager in v1. It is not derived from display text. No business logic may parse
a Session/template/occurrence name to infer time, sales boundaries, or
redemption cutoffs. Database columns, UI controls, storage schema, and timezone
implementation remain deferred to the Session compatibility/scheduling design.

## 4. Existing BingoCommerce Compatibility Boundary

The approved authority split is not permission for destructive refactoring.

- Packages are retained and remain BingoCommerce-authoritative.
- Bingo Play Products are retained and remain BingoCommerce-authoritative.
- The existing local Game Type domain is retained, with its Phase 3C1 Portal
  identity link in place; synchronization remains a later phase.
- The initial compatibility review produced Phase 3C2's separate Portal
  Session-template replica table without replacing the existing dated Commerce
  Session implementation.
- Existing Package-to-Session assignment work is preserved wherever it can map
  safely to Package-to-dated-occurrence availability.
- One Package may be assigned to multiple dated commerce occurrences; no one-
  Package-to-one-occurrence restriction is introduced.
- Existing WooCommerce product, cart, checkout, order, pricing, and inventory
  authority remains unchanged.
- Existing Catalog concurrency transports and lock-order decisions remain
  unchanged; this document authorizes no new database transport.

The initial compatibility review produced the approved, non-destructive Phase
3C1 Game Type identity link and Phase 3C2 Session-template replica persistence.
Existing dated Commerce Sessions and `bingo_session_packages` relationships
remain preserved. Any future commerce-schedule or dated-occurrence refactor
still requires a separate reviewed compatibility design that preserves
historical identities and relationships and does not assume the existing
Session table is deleted or replaced.

## 5. Synchronization Architecture and Sequence

### Phase 3D1 Hall snapshot transport URL

ADR-036 defines `BINGOCOMMERCE_PORTAL_BASE_URL` as the Portal application root
URL. It may include the application's deployment subpath, but it must not
include the `/integration` API prefix. `PortalHttpClient` owns and appends the
fixed Hall snapshot path:

```text
/integration/v1/halls/{portalHallId}/snapshot
```

For example, `https://portal.example.com` produces
`https://portal.example.com/integration/v1/halls/42/snapshot`, while
`https://example.com/portal` produces
`https://example.com/portal/integration/v1/halls/42/snapshot`. Credentials
remain server-side and are carried only in the HTTP `Authorization` header.
This contract does not authorize snapshot persistence,
synchronization/import, webhooks, SSO, or provisioning.

### Phase 3D2 Hall snapshot v1 validation

ADR-037 resolves the Hall snapshot v1 payload and validation contract, which
this document previously left undecided. `PortalSnapshotValidator` turns the raw
HTTP 200 body into a trusted, reconstructed in-memory array or a `WP_Error`. It
is deterministic, read-only, and side-effect-free: no HTTP, no database access,
no site switching, and no option, metadata, Catalog, Session, replica, or Hall
mapping write.

```text
raw HTTP 200 body -> strict v1 validator -> trusted reconstructed snapshot
```

The exact accepted v1 payload is:

| Object | Exact fields |
|---|---|
| envelope | `api_version`, `generated_at`, `hall`, `game_types`, `sessions` |
| `hall` | `id`, `name`, `state`, `updated_at` |
| `game_types[*]` | `id`, `hall_id`, `name`, `updated_at` |
| `sessions[*]` | `id`, `hall_id`, `name`, `status`, `updated_at` |

A missing required member and an unknown member are both fatal at every level,
and either rejects the complete snapshot rather than being ignored. The
operational Portal fields excluded in §8 — `days`, `games`, patterns, caller,
`device_group_id`, XML GameGroup identifiers, `deleted_at`, `game_type_ids` and
similar — therefore cannot enter the commerce boundary even by accident.

`api_version` must be exactly `v1`. Portal timestamps are
`YYYY-MM-DDTHH:MM:SS±HH:MM` with no fractional seconds and an explicit numeric
offset whose hour is `00`-`23` and minute `00`-`59`, checked structurally
because PHP's own parser round-trips impossible offsets such as `+25:00`. That
is the numeric shape of an Atom offset, not a Portal timezone range.
`generated_at` is required and non-null, while each `updated_at` is a required
key whose value may be null. Valid timestamp strings are preserved verbatim —
timezone conversion, UTC normalization, MySQL `DATETIME` conversion, and
`portal_updated_at` persistence semantics all remain Phase 3E concerns.

Portal identities must be native integers `>= 1`; the validator requires
`hall.id` and every child `hall_id` to equal the expected Portal Hall ID
supplied by trusted mapping context, and never derives tenancy from a name,
domain, host, request URL, or ordering. Collections may be empty, input order is
preserved without any sorting requirement, and a duplicate `id` within either
collection rejects the snapshot. Hall names and Session names allow 255 Unicode
characters, Game Type names 50, counted as characters rather than bytes; empty
or whitespace-only names are rejected and valid names are returned unchanged.
Hall `state` is `active` or `inactive` and Session `status` is `active` or
`draft`, matched case-sensitively. Inactive Halls and draft Sessions are valid
and retained.

Decoding uses PHP's native JSON decoder with `JSON_THROW_ON_ERROR` and
`JSON_BIGINT_AS_STRING`, objects decoded as objects so `{}` and `[]` stay
distinguishable, and the invalid-UTF-8 tolerance flags prohibited. The
implementation explicitly passes PHP's standard decoder depth of `512`, because
the flags argument follows the depth argument positionally. That number is a
technical decoder safety bound with no Portal v1 business or API meaning;
BingoCommerce derives no domain semantics from it and introduces no domain
constant for it. Changing this technical bound later requires implementation
review, but does not by itself require a Portal API-version change. A body
nested beyond the configured decoder depth fails safely as invalid JSON.

The public error vocabulary is limited to `invalid_portal_hall_id`,
`portal_snapshot_invalid_json`, `portal_snapshot_invalid_contract`,
`portal_snapshot_unsupported_version`, `portal_snapshot_hall_mismatch`, and
`portal_snapshot_duplicate_identity`, and error data may carry only a
validator-generated structural path. Raw bodies, Portal names, credentials, and
upstream responses never reach an error or a log.

This contract authorizes validation only. Snapshot application/import,
missing-record handling, replica currentness, reconciliation, Hall mapping
mutation, Commerce scheduling, webhooks, SSO, and provisioning remain Phase 3E
or later.

Approved shape:

```text
Portal authenticated read API
        +
Portal webhooks
        +
BingoCommerce hall-scoped local replicas
        +
reconciliation
```

Implementation order is fixed:

1. authenticated read API;
2. initial/full pull synchronization;
3. prove idempotent Hall, Game Type, and Session-template mapping;
4. Portal webhooks;
5. reconciliation;
6. tenant provisioning; and
7. SSO.

The first implementation design must not skip directly to webhooks. Full pull
and idempotent mapping establish the recovery baseline that later webhook and
reconciliation work depend on.

Except for ADR-036's Hall snapshot transport URL and header-only credential
placement and ADR-037's Hall snapshot v1 validation contract, the following
details remain undecided until their dedicated reviews:

- Portal API authentication and authorization lifecycle;
- other endpoint paths, versions, filters, pagination, and payloads;
- Portal Game Type replica currentness and missing-record lifecycle, which must
  be frozen before Phase 3E writers are implemented;
- webhook event types, signatures, ordering, retry, replay, and delivery logs;
- any additional sync-state/cursor persistence, reconciliation state, and
  future snapshot-application/import writer details;
- conflict policy for transitional local Game Type changes;
- provisioning lifecycle and rollback/recovery;
- recurrence expansion horizon, regeneration, timezone, daylight-saving, and
  boundary behavior; and
- monitoring, alerting, retention, and operational runbooks.

## 6. Commerce Enablement

`Enable BingoCommerce` is a separate platform capability controlled only by a
Portal Global Admin. It must not inherit the Portal's ordinary Hall Settings
authorization.

After enablement, authorized Hall Managers/Admins may manage the commerce state
that BingoCommerce owns. Enablement, tenant provisioning, suspension, retry,
partial-failure, and rollback lifecycle details remain deferred.

## 7. Future SSO Requirement

Future SSO is approved in principle:

```text
authenticated Portal staff
        -> short-lived secure handoff
        -> correct mapped BingoCommerce Hall tenant
```

SSO is not part of the first integration increment. The later security design
must decide identity/account mapping, authorization claims, tenant binding,
expiry, one-time use/replay defense, revocation, MFA interaction, auditing, and
failure behavior. This document does not select a protocol or token format.

## 8. V1 Exclusions

Do not sync in v1 unless a later approved requirement proves it necessary:

- individual Games;
- Patterns;
- Caller or device data;
- Portal users;
- Portal Session `days`;
- `device_group_id`; or
- XML GameGroup IDs.

Do not implement Add-ons, QR, customer entitlement, or electronic-capacity work
as part of the Portal integration increment. Those domains remain governed by
their own independent decisions and delivery plans.
