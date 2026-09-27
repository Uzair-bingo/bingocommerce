# BR-081 customer cart backend — Increment 1

This records the cart implementation of reviewed BR-081 and the subsequent
BR-082 electronic-limit cart/checkout validation slice. BR-032, BR-065,
BR-079 and BR-080 remain authoritative. The older BR-066 entry is retained;
BR-081 resolves its mechanism deferral. No bundle/add-on plugin, new domain
table, migration, direct-mysqli boundary or product lifecycle mutation is added.
Earlier ADR-044 implementation-status summaries describe the pre-cart checkpoint;
this document and `PROJECT_STATUS.md` record the subsequent cart slice.

## Ownership and request contract

Catalog's `CustomerCartCatalog` exposes anonymous, Hall-local classification and
one eligible add-on relationship. It uses the existing Catalog integrity and
medium predicates. Existing Manager reads/writers are unchanged. Sessions'
`CartSelection` composes `CustomerPackageEligibility` for the exact occurrence,
the current Package shell, and selected add-ons; it never changes
`SessionEligibility::is_sellable()` or schedule assignability.

`BingoCommerce\Sessions\CustomerCart::add($selection)` is the server-side entry
point, with WooCommerce's cart initialized. It returns the parent cart key or a
safe `WP_Error`. For an HTTP customer request use WooCommerce's existing
`POST /wc/store/v1/cart/extensions` endpoint:

```json
{
  "namespace": "bingocommerce-selection",
  "data": {
    "session_id": 123,
    "package_id": 45,
    "addons": [{"package_addon_id": 67, "quantity": 2}]
  }
}
```

`addons` may be omitted or empty. IDs/quantities must be positive integers or
canonical integer strings; duplicate add-on IDs, fractional quantities, unknown
fields, Hall overrides, Woo product IDs and submitted prices are rejected.
WooCommerce retains its guest session and Nonce/Cart-Token validation; the
existing Hall commerce gate still applies. No custom REST route or AJAX handler
is registered and no capability is granted to guests. A future classic theme
can use this same endpoint/cart; this slice adds no frontend request code.

## Cart representation and behavior

- One Package line at quantity one, followed by separate selected add-on lines.
  Base composition does not produce extra lines. Ordinary merchandise uses
  native WooCommerce lines and remains independent of the one-Package limit.
- `bc_selection` carries version, current site ID, random selection ID, exact
  Session/Package IDs, role, add-on relationship ID and parent cart key. The
  parent also retains selected relationship IDs: a child dropped by native
  session loading must not silently turn the cart into a Package-only selection.
  These are revalidated claims, not authorization tokens or order facts.
- Add-on unit prices are current canonical relationship strings, applied only
  to cart product objects. Package prices are current Woo prices. A narrowly
  scoped price getter during a validated child add allows an independently
  priced relationship even when the shell has no standalone price. Saved Woo
  prices/status/stock/visibility and global purchasability are untouched; native
  purchasability and stock checks still apply.
- All new lines are removed if any child add fails or post-add revalidation
  fails. Existing merchandise is preserved. Native sold-individually quantity
  clamping is detected and rejected, not treated as the customer's selection.
- Explicit parent removal cascades children. Explicit child removal updates
  the parent's selection. Parent Undo attempts the original linked group and
  retains none of it if current validation fails; orphan-child Undo and a second
  Package are rejected. Replacement requires explicit parent removal first.
- Existing invalid/unreadable selections remain blocked by cart errors until
  corrected or removed; no Catalog/Session data is changed. Native Woo removal
  of an unavailable child leaves the parent blocked as incomplete. Current valid
  cart prices are refreshed on load/totals. These are not historical price locks.
- Store API item extensions expose read-only Session/Package/relationship IDs,
  role and parent key. Backend linkage/order is implemented; visual nesting,
  labels, theme, calendar and selection screens remain frontend work.

## Installed WooCommerce integration evidence

Inspected installed WooCommerce **11.0.1**, not assumed hook parity:

- `includes/class-wc-form-handler.php::add_to_cart_handler_simple()` calls the
  classic validation filter and `WC_Cart::add_to_cart()`. The latter does not
  call that validation filter itself. Both it and
  `src/StoreApi/Utilities/CartController.php::filter_request_data()` call
  `woocommerce_add_cart_item_data`; the guard there blocks direct Bingo adds,
  even with forged selection metadata. Only the internal validated add scope
  can create Bingo lines.
- `includes/class-wc-cart-session.php::get_cart_from_session()` checks native
  purchasability before its session-item filter. The pre-removal hook supplies
  a currently verified relationship price to the in-memory product, and the
  loaded cart is revalidated. Current cart errors reach classic
  `woocommerce_check_cart_items` and Store API `woocommerce_store_api_cart_errors`.
- Classic quantity updates call `woocommerce_update_cart_validation`.
  Store API uses `QuantityLimits`, then `WC_Cart::set_quantity()`, which also
  serves direct callers. The post-update guard restores invalid quantities.
  A narrow `rest_endpoints` adapter retains raw quantities before Woo's
  `wc_stock_amount()` truncation, for both `/wc/store/v1` and `/wc/store` aliases,
  including REST batch calls. Original Woo route/nonce callbacks are retained.
- `woocommerce_remove_cart_item` supplies parent cascade; Undo is revalidated
  at `woocommerce_cart_item_restored`. Native Store API cart-extension update
  callbacks return the normal cart response. BR-081 added no checkout/order hook.

## Electronic-face validation follow-up (BR-082)

Catalog's `ElectronicLimit` reads current base composition and eligible selected
add-ons and sums `faces_per_unit × quantity` independently by Hall-local
`game_type_id`. Digital references must resolve in that Hall's Game Type table;
no name, Woo classification projection or device-group identity is used. Paper
contributes zero, including a Paper add-on on a Digital/Hybrid Package. A Play
Product used in both base and add-on contributes each selected quantity.
Arithmetic overflow and unreadable digital dependencies fail closed.

The resolver follows persistence-design §6 and BR-082. It performs parameterized
current reads of the Hall's network maximum and optional Hall override, with no
option-cache reuse, settings mutation or automatic 300 fallback. Only selections
with digital faces require valid configuration. Both configured limits must be
positive whole numbers (`>= 1`); stored `0` or `'0'` returns
`bc_electronic_limit_unavailable`. An absent Hall override remains valid.

`CartSelection::resolve()` checks before adding any lines. `inspect()` repeats
the calculation after BR-080/BR-081 integrity checks, covering stale/restored
carts, quantity changes and checkout. Neither totals nor limits are persisted
as cart authority. `inspect()` also returns the successfully validated face map
for order recording below; unsuccessful inspections return no face map.
The installed WooCommerce 11.0.1 checkout boundaries were inspected separately:

- Classic `WC_Checkout::process_checkout()` runs `validate_checkout()` before
  customer/order processing. The new `woocommerce_after_checkout_validation`
  callback reuses `inspect()` and adds errors to Woo's final validation result.
- Store API `Routes/V1/Checkout::process_order()` runs
  `CartController::validate_cart()` on POST before creating/updating the draft
  order. Its existing `woocommerce_store_api_cart_errors` callback now includes
  the electronic validation and causes a 409 on failure. It does not depend on
  the classic-only checkout hook.

This is purchase validation, not a reservation or Session/order/customer counter.
Retry/payment of an existing order is a separate future boundary requiring
immutable order facts; this slice does not infer order facts from a later cart.

## Order recording follow-up (BR-083)

Sessions' `CustomerOrder` records purchases through WooCommerce order/item CRUD
metadata, compatible with the existing HPOS configuration. There is no domain
schema change or direct order SQL. `bc_purchase` on the order stores version,
Hall site ID, selection UUID, exact occurrence ID/date/name/schedule and nullable
structured timing, Package ID/type/Woo product/name/quantity, and selected
add-on relationship IDs, Play Product/Woo IDs and quantities.

The 2026-09-26 order-recording extension adds
`bc_purchase.digital_faces_by_game_type`: a map of Hall-local `game_type_id`
to validated positive integer digital-face total, scoped by the summary's
`site_id`. It reuses the result of `ElectronicLimit::validate_selection()` from
the same successful `CartSelection::inspect()` call. This includes required
base components and selected eligible add-ons; Paper contributes zero. A
Paper-only selection records an explicit empty map. No electronic limit is
saved as a purchase fact. This is an additive field in the version-1 purchase
summary; item bindings and native financial values are unchanged. It records
purchased quantities of digital faces without creating an entitlement,
confirming payment or issuing a QR.

New classic orders and unplaced Store API drafts receive these validated
totals. Draft totals follow current selection and Catalog changes, including
unchanged-line drafts, add-on removal and Package replacement/removal. Once
placed, the saved map is retained through WooCommerce's native payment retries;
later Catalog edits cannot replace it. Existing cart checks still run at their
existing boundaries. No retry hook or payment policy is changed.

An older placed order without this field remains **unknown**, including when
it has no purchase summary at all. Recording and retry must not backfill it
from today's Catalog, and absence must never be interpreted as Paper/zero.
When entitlement is implemented, missing or invalid historical totals must
prevent automatic entitlement creation until a separately reviewed recovery
can establish the purchased quantities from trustworthy historical evidence.
This slice performs no backfill, recovery or entitlement activation.

Each Package/add-on order item has its own `bc_purchase` binding with the Hall,
Session, Package, selection UUID, role, relationship ID (zero for the parent),
and quantity. Within one order, the UUID identifies the one Package and its
children; cart keys are never retained as historical linkage. Ordinary
merchandise has no Bingo metadata. Native Woo product names, quantities, line
subtotals/totals, coupon discounts and taxes remain financial authority; the
recorder never sets prices, amounts, discounts or tax properties.

Inspected WooCommerce 11.0.1's actual order creation/update paths:

- Both classic and `StoreApi/Utilities/OrderController` use
  `WC_Checkout::create_order_line_items()`. Its
  `woocommerce_checkout_create_order_line_item` action binds Bingo line identity.
- Classic `woocommerce_checkout_create_order` runs after Woo has created all
  items and populated financial values, immediately before saving the order.
- Store API POST validates the cart before creating/updating its draft. The
  native `woocommerce_store_api_checkout_update_order_meta` action runs even
  when the line-item hash is unchanged, so the recorder revalidates the complete
  selection and current occurrence facts there as well. The existing persisted
  draft and changed item metadata are saved through order CRUD. New GET/PATCH
  interactions do not create a draft in this installed version; existing draft
  updates follow the same native metadata boundary.

The recorder reuses `CartSelection::inspect()` (BR-080/081/082), checks all bound
Woo items against the validated cart's identities/products/quantities and full
selection, and fails closed on missing, duplicate, altered or unreadable Bingo
facts. Unplaced drafts refresh current occurrence labels even when their lines
are unchanged; removing the Package clears draft facts, and replacing the
selection rebuilds them. Existing non-draft orders with recorded facts retain
those saved facts; a different selection cannot overwrite them. This defensive
preservation is not implementation or approval of an existing-order retry or
payment policy. No read/render/save hook recalculates historical facts.

Saved scalar item metadata `bc_bingo_session` and `bc_package` renders as
“Bingo Session” and “Add-on for” in normal WooCommerce order details, admin item
views, and HTML/plain receipts. User-facing dates are `MM/DD/YYYY`; structured
time is shown only when recorded on the occurrence. Woo omits structured array
metadata from this normal item display. There are no template/theme overrides.

Run `BC_TEST_DB=bingocommerce_state_recon php tests/customer-order-recording.php`
serially. It creates and reads actual HPOS orders through signed-out classic
checkout and Store API REST POST, with offline BACS, suppressed mail and blocked
outbound HTTP. Classic interception happens only after offline gateway success
to avoid terminating the CLI on redirect. Additional Store API fixture
sentinels preserve unplaced drafts for repeat native-update tests. Two distinct
add-ons, merchandise, coupon discounts/nonzero taxes, persisted receipt output,
Catalog/Session edits, legacy timing, colliding local IDs in two Halls and
recording failures are covered. The digital-fact cases exercise Digital and
Hybrid Packages with two Game Types and add-ons, Paper-only totals, draft
updates including Paper add-ons, and preserved placed totals after changes to
faces, component quantities and Game Type assignments. Actual classic
pay-for-order/resume and Store API existing-order/cart retries use unchanged
customer selections; older orders keep missing totals absent. Refund
regressions read back the unchanged purchase facts and financial values.
All fixture Halls are token-owned and disposable.

## Paid online digital entitlement snapshot (BR-043 / BR-053 / ADR-029)

The 2026-09-26 follow-up implements the snapshot only in the planned normal
`bingocommerce-digital-tickets` plugin. It observes **only**
`woocommerce_payment_complete`. In installed WooCommerce 11.0.1,
`WC_Order::payment_complete()` saves its payment date and new status before
emitting that event. Its already-paid branch emits a different dynamic event;
this slice does not subscribe to that branch or alter native retries. Classic
checkout and Store API create orders with `created_via = checkout` and
`store-api`, respectively. BACS awaiting-payment success is not confirmation.
No status transition, checkout success/redirect, or cart event creates a snapshot.
The integration trusts the gateway's normal verified call to `payment_complete`;
it does not independently verify a provider webhook or change a gateway.

The observer reloads the current-Hall order via Woo CRUD and requires an online
checkout origin, positive order total, Woo paid state, payment date and payment
method. It validates version-1 saved `bc_purchase` Hall/selection/dated-Session/
Package identity and the current order lines' saved Package/add-on links and
quantities. It never looks up current Catalog, Game Types, Session sales state,
prices or electronic limits. This is not a new-purchase validation or a policy
for changed-input payment retries.

Schema v1 is owned by Digital Tickets, with the per-site option
`bc_digital_tickets_db_version`. The Hall-local
`$wpdb->prefix . 'bingo_entitlements'` table contains:

- `entitlement_id` primary key and unique indexed `order_id`;
- exact `session_id`, `package_id`, and binary selection UUID;
- `snapshot_version` and `digital_faces_json`, the complete saved Hall-local
  Game-Type-to-positive-integer-total map (not the electronic limit);
- `status` initially `active`, `confirmed_at` from Woo's saved payment time in
  UTC, and index `(session_id, status)`.

There is no redundant `blog_id`, device registry, QR field, capacity counter or
financial record in this table. Exact occurrence date/name/timing and native
charged line amounts/discounts/taxes remain in the existing order facts/lines.
Versioned `dbDelta` migration verifies columns, engine and indexes before
recording its version. Existing-Hall admin catch-up, a version-gated Network
Super Admin sweep and new-site initialization are registered. Migration is
never run by payment events. No real-Hall activation/migration was performed.

A single prepared insert writes the whole snapshot; `UNIQUE(order_id)` is the
concurrent-delivery arbiter. Readback recognizes a matching winning insert.
There is no UPDATE, REPLACE or upsert: repeats never overwrite totals, identity,
confirmation time or state, including a future void state. Conflicting saved
facts report `bc_entitlement_snapshot_conflict`, leaving the original intact.

Paper with an explicit empty saved map, and unmarked ordinary merchandise,
create no entitlement. Missing, empty-for-Digital/Hybrid, zero, negative,
non-integer or malformed totals remain **unknown**, not zero; marked orders
without a valid purchase summary also fail closed. No historical recovery or
Catalog-based backfill is implemented. An entirely unmarked older order cannot
be identified as Bingo from absent facts, so it receives no entitlement either;
future recovery requires trustworthy historical evidence, not current Catalog.

**$0 checkouts are outside this paid-order slice.** Woo may call
`payment_complete()` for a free checkout. A marked zero-total order creates no
snapshot and reports `bc_entitlement_zero_total_out_of_scope`; Woo's free
checkout still completes normally. This does not decide free-order entitlement.
Admin/POS/import origins are likewise outside the online purchase contract.

Snapshot errors are logged under Woo source `bingocommerce-digital-tickets`
with Hall/order IDs and emitted through
`bingocommerce_digital_tickets_snapshot_failed`. They do not change payment
outcome, order state, items, facts or amounts. Schema errors fail closed. A
verified redelivery can recover a missed insert, but **automatic recovery and
gateway reconciliation remain unfinished**: calling Woo `payment_complete()`
again on an already-paid order need not emit the primary event again. Never
charge again to repair a missing snapshot. Provider integration/recovery needs
verification before customer-facing digital launch.

**QR issuance and refund/cancellation voiding remain unfinished.** This record
is not a QR or redemption implementation. Future QR state must reference the
Hall-local entitlement ID; Package-scoped BR-046 refund/cancellation must void
it without reacting to merchandise-only refunds. A refund/cancel-before-late-
confirmation race needs that future lifecycle integration. No current refund
or payment-retry behavior is changed by this slice.

Focused verification:
`BC_TEST_DB=bingocommerce_state_recon php tests/digital-entitlement-snapshot.php`.
It creates signed-out classic Woo order-creation and Store API checkout orders,
uses offline BACS, then invokes real `WC_Order::payment_complete()` without a
payment transport. Coverage includes multiple Game Types and add-ons, Hybrid
Paper exclusion, Paper/merchandise, actual free coupon checkout, malformed and
missing historical facts, duplicate/interleaved deliveries, failed storage,
schema verification, Catalog edits, immutable order financials and Hall isolation.
The interleaved insert test exercises a real duplicate-key loser, not two
parallel OS processes. All data and migrations are confined to token-owned
disposable Halls in the guarded isolated database.

Existing order/refund, cart and electronic-limit regressions were also run
serially with the new plugin loaded in each isolated process. Reproduce for
each of `tests/customer-order-recording.php`,
`tests/customer-cart-increment-1.php`, and `tests/customer-electronic-limits.php`
by replacing the final path in this command (no persistent plugin activation):

```sh
BC_TEST_DB=bingocommerce_state_recon BC_FIXTURE_WOOCOMMERCE=1 php -r 'require "tests/fixtures/wordpress-bootstrap.php"; require "wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php"; BingoCommerce\DigitalTickets\Plugin::init(); require "tests/customer-order-recording.php";'
```

## Whole-Package refund restriction (BR-046)

`Sessions\CustomerRefund` validates native WooCommerce refunds; it neither
processes payments nor creates refunds itself. It resolves the commercial unit
from the saved BR-083 order/line bindings and **current** order item IDs. Classic
resume can replace those IDs before payment. It verifies the Hall, selection,
Session, Package, add-on relationships, products and quantities without reading
mutable Catalog prices/eligibility or changing the purchase facts.

A refund touching any Bingo line must contain the Package and every selected
add-on, including zero-priced children, with their full quantities, charged
post-discount line totals and taxes. Merchandise remains independently
refundable, including partial quantities/amounts and a later refund of the
other commercial unit. Every amount on a Bingo-containing order must be
allocated to current product/fee/shipping lines. Both partial and full
**amount-only** refunds are refused: a lump sum does not identify which unit is
being refunded. Requests must match their allocation at Woo's currency
precision/tax-rounding mode, and cannot exceed each line's remaining quantity,
amount or taxes. Missing/inconsistent links or unallocated refund history fail
closed for review. Ordinary-only orders retain Woo's native behavior.

Installed WooCommerce 11.0.1 evidence (paths below are under its plugin root):

- Admin `includes/class-wc-ajax.php:2391–2477` and REST v1/v2/v3 refund creation
  use `wc_create_refund()`; v3 calls it at
  `includes/rest-api/Controllers/Version3/class-wc-rest-order-refunds-controller.php:55–65`.
  There is no Store API refund route; Store API-created orders use these same
  management refund paths.
- `includes/wc-order-functions.php:650–681` saves a preliminary refund during
  `update_taxes()`/`calculate_totals()` **before** `woocommerce_create_refund`.
  The guard therefore also uses `woocommerce_before_order_refund_object_save`.
  `WC_Abstract_Order::save()` catches that early exception
  (`includes/abstracts/abstract-wc-order.php:247–279`), so validation repeats at
  `woocommerce_create_refund` to return the native `WP_Error`/AJAX/REST failure.
  Invalid new refunds never reach persistence, gateway refund, restocking or
  completed-refund events. Valid requests retain Woo's normal processing.

Explicit limits, not claimed protections:

- A manual status-only change to `refunded` is already saved when
  `wc_order_fully_refunded()` attempts an amount-only refund and ignores its
  result (`includes/wc-order-functions.php:906–935`). That refund is refused,
  but the status remains changed. Use itemized refunds, not a status edit;
  this slice does not add a status-transition guard or entitlement behavior.
- Direct `wc_refund_payment()`/gateway `process_refund()` calls or refunds in
  a provider dashboard have no shared pre-refund line-validation hook. They
  are outside this enforcement boundary. A gateway integration must not bypass
  the native itemized creation flow and needs its own reviewed reconciliation.
- Arbitrary direct item writes, deleted refund history, orders with all Bingo
  markers erased, and custom `wc_create_refund( refund_id: existing_id )`
  rewrites are not safe supported paths. In that last path Woo can write existing
  tax items before the hook and deletes the supplied refund on an exception
  (`includes/wc-order-functions.php:750–754`). Native admin/REST creation does
  not supply an existing refund ID. No core patch, custom refund processor,
  cross-request lock or gateway idempotency guarantee is introduced here.

The order-recording harness also tests real native function/AJAX/REST refunds,
manual and offline-gateway modes, complete/partial/add-on-only/amount-only
requests, mixed orders, coupons/taxes, free children, current versus stale IDs
after native resume, historical prices and the status-only limitation. It
checks persisted orders/refund lines and that refused requests never save a
refund or reach gateway/restock/completion boundaries. Only token-owned
disposable Halls are used; no funds move.

## Verification and next boundary

Run `BC_TEST_DB=bingocommerce_state_recon php tests/customer-cart-increment-1.php`.
It uses real WooCommerce handlers/routes, signed-out customers and token-owned
disposable Halls. It covers direct and restored bypasses, incomplete selections,
prices, quantities, lifecycle, Paper compatibility, native stock, nonce, REST
batch/alias handling and colliding IDs. Existing eligibility, Session, schedule,
reconciliation, Package and add-on regressions must also pass. No real-Hall data
or migration is involved.

Run `BC_TEST_DB=bingocommerce_state_recon php tests/customer-electronic-limits.php`
serially on the same isolated fixture. It calls the real classic checkout
processor and dispatches real Store API POST checkout requests as a guest.
Fixture-only sentinels stop successful validation before order creation/payment;
validators themselves are not mocked. Failed digital cases must report an
electronic validation error. It restores the fixture's original network option
and checks persistent-fixture Hall preservation and disposable-Hall cleanup.

Not a complete purchase/launch implementation: WooCommerce retains its native
payment retry flow. Isolated retries with unchanged inputs preserved the saved
purchase facts; changed-input retries were not verified. Refund integration
limits above, BR-046 entitlement voiding, snapshot failure recovery and QR
issuance/activation remain separate work. The narrow positive-total online
payment-complete snapshot boundary is implemented above; it does not complete
the digital entitlement lifecycle.
Configuration writers/UI also remain
unimplemented; configuration authority stays with BR-064.
