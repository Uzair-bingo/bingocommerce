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
carts, quantity changes and checkout. No calculated total/limit is persisted.
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
add-on relationship IDs, Play Product/Woo IDs and quantities. This records the
purchase selection, not a base-composition or digital-entitlement snapshot.

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
recording failures are covered. All fixture Halls are token-owned and disposable.

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

Not a complete purchase/launch implementation: existing-order payment/retry
validation, BR-046 whole-Package refunds, payment/entitlement snapshots and QR
activation remain separate work. Configuration writers/UI also remain
unimplemented; configuration authority stays with BR-064.
