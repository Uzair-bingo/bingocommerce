# BR-081 customer cart backend — Increment 1

This is a cart-only implementation of reviewed BR-081. BR-032, BR-065,
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
  callbacks return the normal cart response. No checkout/order hook is added.

## Verification and next boundary

Run `BC_TEST_DB=bingocommerce_state_recon php tests/customer-cart-increment-1.php`.
It uses real WooCommerce handlers/routes, signed-out customers and token-owned
disposable Halls. It covers direct and restored bypasses, incomplete selections,
prices, quantities, lifecycle, Paper compatibility, native stock, nonce, REST
batch/alias handling and colliding IDs. Existing eligibility, Session, schedule,
reconciliation, Package and add-on regressions must also pass. No real-Hall data
or migration is involved.

Not a complete purchase/launch implementation: final checkout/payment-boundary
revalidation, per-Game-Type electronic limits, exact immutable order facts,
order-item linkage, BR-046 whole-Package refunds, payment/entitlement snapshots
and QR activation are separate work. Cart keys must not become historical order
identity. The existing cart hooks may be consumed by checkout, but they do not
claim those deferred checkout/order guarantees.
