<?php
/**
 * Checkout recovery, unsupported Bingo reorders and Bingo checkout errors (2026-10-03 increment).
 *
 *  1. A stored WooCommerce item-removal notice (an unpriced add-on removed while the cart loads after
 *     its Session became unavailable) is printed once by BingoThemev1's block Cart and Checkout pages,
 *     so it no longer rejects a later valid Store API checkout; current Bingo validation still rejects.
 *  2. A native "Order again" request for an order with Bingo purchase facts is stopped before
 *     WooCommerce's cart loader replaces the cart; merchandise-only reorders stay native; refused,
 *     malformed and foreign requests leave the cart unchanged without warnings or disclosure.
 *  3. Classic checkout shows each distinct Bingo message once, with codes and WooCommerce errors kept;
 *     Store API and classic messages use the plain wording below.
 *  4. Order views: the single-paragraph Bingo Session label and ordinary merchandise metadata with two
 *     paragraphs, as WooCommerce renders them. Optional browser stage (BC_CHECKOUT_RECOVERY_BROWSER=1
 *     with BC_PLAYWRIGHT_MODULE and BC_CHROME_EXECUTABLE; optional BC_NODE_EXECUTABLE and
 *     BC_CHECKOUT_RECOVERY_SHOTS, an existing /private/tmp directory): tests/checkout-recovery-browser.cjs
 *     loads Order Received and the My Account order view through real php-cgi requests
 *     (tests/checkout-recovery-request.php) at 320, 375 and 1440px.
 *
 * BC_TEST_DB=bingocommerce_state_recon php tests/checkout-recovery.php
 * Real WooCommerce paths, token-owned disposable Halls and customers only; mail suppressed,
 * outbound HTTP refused; the network electronic-limit setting is restored.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and the isolated bingocommerce_state_recon database are required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( 'bingocommerce_state_recon' !== DB_NAME ) { fwrite( STDERR, "FIXTURE GUARD: bound to the wrong database.\n" ); exit( 1 ); }
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Sessions\CartSelection;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\CustomerOrder;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;

final class CheckoutRecoveryTest {
	private const OWNER = 'bc_checkout_recovery_test_owner';
	private const THEME = 'bingocommerce-bingothemev1';

	// The approved customer wording (2026-10-03).
	private const UNAVAILABLE = 'This Bingo selection is no longer available. Remove the Bingo Package from your cart, then choose an available Session and Package.';
	private const TEMPORARY   = 'We couldn’t check your Bingo selection right now. Please try again in a moment.';
	private const INCOMPLETE  = 'Part of your Bingo selection is no longer in your cart. Remove the Bingo Package from your cart, then choose it again with the add-ons you want.';
	private const EXCEEDED    = 'Your selection exceeds this Hall’s digital bingo face limit. Reduce the digital add-ons or choose a different Package.';
	private const NO_DIGITAL  = 'Digital bingo can’t be purchased at this Hall right now. Please try again later, or choose a Paper Package.';
	private const RECORDER    = 'We couldn’t complete checkout because your Bingo selection couldn’t be saved, so payment wasn’t attempted. Please check your cart and try again.';
	private const REORDER     = 'Bingo Packages can’t be ordered again because each one is for a specific Session. Your cart hasn’t changed.';

	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;
	private string $group = '';
	private array $address = [ 'first_name' => 'Fixture', 'last_name' => 'Customer', 'address_1' => '1 Test Street', 'city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US', 'email' => 'recovery@example.invalid', 'phone' => '5185550100' ];

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP in checkout recovery fixtures.' ) );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$missing = new stdClass();
		$limit = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		try {
			update_site_option( ElectronicLimit::PLATFORM_OPTION, '48' );
			$a = $this->fixture( 'A' );
			$b = $this->fixture( 'B' );
			$this->recovery( $a );
			$this->errors( $a );
			$this->reorder( $a, $b );
			$this->order_views( $a );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			if ( $limit === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $limit ); }
			$this->cleanup();
		}
		$this->group = 'Preservation';
		$this->same( $limit, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'network electronic-limit setting restored' );
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Checkout recovery: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	/* ================================================================== *
	 * 1. A stored removal notice no longer blocks a later valid checkout
	 * ================================================================== */

	private function recovery( array $a ): void {
		$this->group = 'Recovery';
		$this->as_guest( $a );
		$removed = 'Extra paper pack has been removed from your cart because it can no longer be purchased. Please contact us if you need assistance.';

		// Cart arrival.
		$stored = $this->select( $a, 'friday', 'paper', [ 'unpriced' => 1, 'chips' => 1 ] );
		$this->sales( $a, 'friday', false );
		$this->reload( $stored ); // the next request loads the cart from the session
		$this->same( [ $removed ], $this->notices( 'error' ), 'session load removes the unpriced add-on with WooCommerce’s stored notice' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::UNAVAILABLE, 'current Bingo validation rejects the stale selection' );
		$html = $this->render_page( wc_get_page_id( 'cart' ) );
		$this->same( 1, substr_count( $html, $removed ), 'block Cart page shows the stored notice exactly once (no duplicate in the hydrated cart)' );
		$this->same( [], $this->notices( 'error' ), 'the shown notice is consumed' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::UNAVAILABLE, 'showing the notice does not suppress current validation' );
		$this->remove_package();
		$order = $this->checkout_status( 200, null, null, 'merchandise-only checkout succeeds without visiting Shop' );
		$this->same( '', $order ? $order->get_meta( CustomerOrder::META, true ) : 'no order', 'merchandise-only order carries no Bingo facts' );
		$this->sales( $a, 'friday', true );

		// Direct Checkout arrival, then a new valid Bingo purchase.
		$stored = $this->select( $a, 'friday', 'paper', [ 'unpriced' => 1 ] );
		$this->sales( $a, 'friday', false );
		$this->reload( $stored );
		$html = $this->render_page( wc_get_page_id( 'checkout' ) );
		$this->same( 1, substr_count( $html, $removed ), 'block Checkout page shows the stored notice exactly once' );
		$this->same( [], $this->notices( 'error' ), 'the shown notice is consumed on Checkout arrival' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::UNAVAILABLE, 'the invalid Package still rejects checkout' );
		$this->remove_package();
		$this->same( true, is_string( CustomerCart::add( [ 'session_id' => $a['sessions']['sunday'], 'package_id' => $a['packages']['paper'], 'addons' => [ [ 'package_addon_id' => $a['relations']['unpriced'], 'quantity' => 2 ] ] ] ) ), 'a new valid Bingo selection is added' );
		$order = $this->checkout_status( 200, null, null, 'new Bingo purchase succeeds without visiting Shop' );
		$facts = $order ? $order->get_meta( CustomerOrder::META, true ) : [];
		$this->same( [ $a['sessions']['sunday'], [ [ $a['relations']['unpriced'], 2 ] ] ], [ $facts['session_id'] ?? null, array_map( static fn( $x ) => [ $x['addon_id'], $x['quantity'] ], $facts['addons'] ?? [] ) ], 'new order records the newly selected Session and add-on' );
		$this->sales( $a, 'friday', true );

		// The Session becomes unavailable while Checkout is already open.
		$stored = $this->select( $a, 'friday', 'paper', [ 'unpriced' => 1 ] );
		$html = $this->render_page( wc_get_page_id( 'checkout' ) );
		$this->same( 0, substr_count( $html, $removed ), 'open Checkout page had nothing stored to show' );
		$this->sales( $a, 'friday', false );
		$this->reload( $stored ); // Place Order: the POST loads the cart first
		$this->checkout_status( 409, 'bc_cart_invalid', self::UNAVAILABLE, 'Place Order is rejected after the Session closes' );
		$this->same( [ $removed ], $this->notices( 'error' ), 'the removal notice is now stored' );
		$html = $this->render_page( wc_get_page_id( 'cart' ) );
		$this->same( 1, substr_count( $html, $removed ), 'Cart page shows it once' );
		$this->remove_package();
		$this->checkout_status( 200, null, null, 'merchandise-only checkout succeeds after recovery' );
		$this->sales( $a, 'friday', true );
		$this->clear();
	}

	/* ================================================================== *
	 * 3. One notice per distinct Bingo message; plain wording; codes kept
	 * ================================================================== */

	private function errors( array $a ): void {
		$this->group = 'Errors';
		$this->as_guest( $a );

		// Package and two add-ons all invalid; a WooCommerce field error at the same time.
		$this->select( $a, 'friday', 'paper', [ 'unpriced' => 1, 'chips' => 1 ] );
		$this->sales( $a, 'friday', false );
		$r = $this->classic( [ 'billing_city' => '' ] );
		$this->same( $r['errors'], array_values( array_unique( $r['errors'] ) ), 'classic: no notice repeated ' . wp_json_encode( $r['errors'], JSON_UNESCAPED_UNICODE ) );
		$this->same( 1, $this->count( $r['errors'], self::UNAVAILABLE ), 'classic: one notice for three invalid Bingo lines' );
		$this->same( 1, count( array_filter( $r['errors'], static fn( $n ) => str_contains( $n, 'Town / City' ) || str_contains( $n, 'City' ) ) ), 'classic: WooCommerce’s own field error kept once' );
		$this->ok( in_array( 'billing_city', array_column( $r['data'], 'id' ), true ), 'classic: the field error keeps its field link' );
		$this->ok( in_array( 'bc_cart_invalid', $r['codes'], true ) && in_array( 'billing_city_required', $r['codes'], true ), 'classic: Bingo and WooCommerce codes kept ' . wp_json_encode( $r['codes'] ) );
		$this->same( false, $r['reached'], 'classic: still rejected before order creation' );
		$this->sales( $a, 'friday', true );

		// One add-on unavailable but still in the cart: one unavailable message, not also "missing".
		$this->select( $a, 'friday', 'paper', [ 'unpriced' => 1, 'chips' => 1 ] );
		$this->addon_enabled( $a, 'chips', false );
		$r = $this->classic();
		$this->same( [ self::UNAVAILABLE ], $r['errors'], 'classic: unavailable add-on shown once, not also reported as missing' );
		$this->same( [ 'bc_cart_invalid' ], $r['codes'], 'classic: code kept' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::UNAVAILABLE, 'Store API: unavailable add-on rejected with the plain wording' );
		$this->addon_enabled( $a, 'chips', true );

		// An add-on line really gone from the cart: the incomplete selection, worded as such.
		$this->select( $a, 'friday', 'paper', [ 'unpriced' => 1, 'chips' => 1 ] );
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $a['relations']['chips'] === ( $item[ CartSelection::META ]['addon_id'] ?? null ) ) { unset( WC()->cart->cart_contents[ $key ] ); }
		}
		$r = $this->classic();
		$this->same( [ self::INCOMPLETE ], $r['errors'], 'classic: missing add-on shown once in the incomplete wording' );
		$this->same( [ 'bc_cart_incomplete' ], $r['codes'], 'classic: incomplete code kept' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::INCOMPLETE, 'Store API: incomplete selection rejected with the plain wording' );

		// Electronic limit exceeded (approved wording) and unavailable configuration.
		$this->select( $a, 'friday', 'digital', [ 'digital_extra' => 2 ] );
		update_site_option( ElectronicLimit::PLATFORM_OPTION, '40' );
		$r = $this->classic();
		$this->same( $r['errors'], array_values( array_unique( $r['errors'] ) ), 'classic: no notice repeated (electronic limit)' );
		$this->same( 1, $this->count( $r['errors'], self::EXCEEDED ), 'classic: one exceeded notice in the approved wording' );
		$this->ok( in_array( 'bc_electronic_limit_exceeded', $r['codes'], true ), 'classic: exceeded code kept' );
		$this->checkout_status( 409, 'bc_cart_invalid', self::EXCEEDED, 'Store API: exceeded in the approved wording' );
		delete_site_option( ElectronicLimit::PLATFORM_OPTION );
		$this->checkout_status( 409, 'bc_cart_invalid', self::NO_DIGITAL, 'Store API: missing configuration in plain wording' );
		update_site_option( ElectronicLimit::PLATFORM_OPTION, '48' );

		// A temporary Catalog read failure is worded as such.
		$this->select( $a, 'friday', 'paper', [ 'chips' => 1 ] );
		$this->with_query_fault( static fn( $sql ) => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'bingo_package_addons' ), function () {
			$this->checkout_status( 409, 'bc_cart_invalid', self::TEMPORARY, 'Store API: unreadable Catalog is a temporary verification failure' );
			$r = $this->classic();
			$this->same( [ self::TEMPORARY ], $r['errors'], 'classic: one temporary notice, nothing claimed missing or unavailable' );
		} );

		// The recorder stops before payment and says so precisely.
		$processed = 0; $count = static function () use ( &$processed ) { ++$processed; };
		add_action( 'woocommerce_store_api_checkout_order_processed', $count );
		$this->with_query_fault( static fn( $sql ) => str_starts_with( $sql, 'SELECT session_id, session_date, display_name, schedule_id, start_time_local' ), function () use ( &$processed ) {
			$r = $this->checkout();
			$this->same( [ 500, 'woocommerce_rest_unknown_server_error', self::RECORDER ], [ $r['status'], $r['code'], $r['message'] ], 'Store API: recorder failure wording' );
			$this->same( 0, $processed, 'Store API: recorder failure stops before payment processing' );
			$result = WC()->checkout()->create_order( [ 'payment_method' => 'bacs' ] );
			$this->same( self::RECORDER, is_wp_error( $result ) ? $result->get_error_message() : 'no error', 'classic: recorder failure wording' );
		} );
		remove_action( 'woocommerce_store_api_checkout_order_processed', $count );
		$this->clear();
	}

	/* ================================================================== *
	 * 2. Unsupported Bingo reorders never replace the cart
	 * ================================================================== */

	private function reorder( array $a, array $b ): void {
		$this->group = 'Reorder';
		$guard = 'BingoCommerce\Sessions\CustomerReorder';
		$customer = $this->customer( 'owner', [ $a['site'], $b['site'] ] );
		$stranger = $this->customer( 'stranger', [ $a['site'] ] );
		// Orders are created in mirrored order so local IDs collide: A = [Bingo, merchandise], B = [merchandise, Bingo].
		$a_orders = $this->completed_orders( $a, $customer, [ 'bingo', 'merch' ] );
		$b_orders = $this->completed_orders( $b, $customer, [ 'merch', 'bingo' ], $a_orders['bingo'] );
		$foreign = $this->completed_orders( $a, $stranger, [ 'bingo' ] );
		$this->same( $a_orders['bingo'], $b_orders['merch'], 'colliding local order IDs: Bingo in Hall A, merchandise in Hall B' );

		$this->group = 'Reorder registration';
		$priority = class_exists( $guard ) ? has_action( 'wp_loaded', [ $guard, 'guard' ] ) : false;
		$loader = $this->cart_loader_priority();
		$this->same( 5, $priority, 'guard registered on wp_loaded at explicit priority 5' );
		$this->same( 10, $loader, 'WooCommerce’s cart loader (WC_Cart_Session::get_cart_from_session) is on wp_loaded at 10' );
		$this->same( 1, class_exists( $guard ) ? has_action( 'woocommerce_load_cart_from_session', [ $guard, 'neutralize' ] ) : false, 'every cart load (Store API included) is covered at the top of get_cart_from_session' );

		switch_to_blog( $a['site'] );
		try {
			$this->login( $customer );
			$this->group = 'Reorder button';
			$this->same( false, str_contains( $this->order_details( $a_orders['bingo'] ), 'order_again=' ), 'no native Order again button on a completed Bingo order' );
			$this->same( true, str_contains( $this->order_details( $a_orders['merch'] ), 'order_again=' ), 'merchandise-only completed order keeps the native button' );

			$this->group = 'Reorder requests';
			$nonce = wp_create_nonce( 'woocommerce-order_again' );
			$this->seed_merch( $a, 3 );
			$r = $this->reorder_request( [ 'order_again' => (string) $a_orders['bingo'], '_wpnonce' => $nonce ], $guard, true );
			$this->same( 0, $r['loads_during_guard'], 'the guard itself never loads the cart' );
			$this->same( wc_get_cart_url(), $r['redirect'], 'direct Bingo reorder URL redirects to the cart without the query' );
			$this->same( [ [ $a['merch'], 3 ] ], $this->cart_after(), 'existing cart preserved (no Bingo line copied)' );
			$this->same( [ [ $a['merch'], 3 ] ], $this->persistent_cart(), 'signed-in customer’s persistent cart preserved' );
			$notice = implode( ' ', $this->notices( 'notice' ) );
			$this->ok( str_contains( $notice, self::REORDER ) && str_contains( $notice, 'href="' . esc_url( home_url( '/' ) . '#calendar' ) . '"' ), 'explanation with a link to this Hall’s Session calendar' );
			$this->same( [], $r['warnings'], 'no PHP warnings' );

			$this->seed_merch( $a, 3 );
			$r = $this->reorder_request( [ 'order_again' => (string) $a_orders['merch'], '_wpnonce' => $nonce ], $guard );
			$this->same( wc_get_cart_url(), $r['redirect'], 'merchandise-only reorder keeps WooCommerce’s redirect' );
			$this->same( [ [ $a['merch_long'], 1 ] ], $this->cart_after(), 'merchandise-only reorder is native (WooCommerce replaces the cart)' );
			$this->ok( in_array( 'The cart has been filled with the items from your previous order.', $this->notices( 'success' ), true ), 'native success notice' );

			foreach ( [ 'another customer’s Bingo order' => (string) $foreign['bingo'], 'a missing order' => '99999999', 'a malformed order ID' => 'abc' ] as $label => $value ) {
				$this->seed_merch( $a, 3 );
				$r = $this->reorder_request( [ 'order_again' => $value, '_wpnonce' => $nonce ], $guard );
				$this->same( [ [ $a['merch'], 3 ] ], $this->cart_after(), "{$label}: cart unchanged" );
				$this->same( [ [ $a['merch'], 3 ] ], $this->persistent_cart(), "{$label}: persistent cart unchanged" );
				$this->same( [ [], null ], [ $r['warnings'], $r['thrown'] ], "{$label}: no warning or error" );
				$this->same( [], array_filter( $this->notices( 'notice' ), static fn( $n ) => str_contains( $n, 'Bingo' ) ), "{$label}: nothing about the order is disclosed" );
			}
			$this->seed_merch( $a, 3 );
			$r = $this->reorder_request( [ 'order_again' => (string) $a_orders['bingo'], '_wpnonce' => 'invalid' ], $guard );
			$this->same( [ [ [ $a['merch'], 3 ] ], null ], [ $this->cart_after(), $r['redirect'] ], 'invalid nonce: WooCommerce ignores the request; cart unchanged' );

			// Malformed array parameters, through the guard AND WooCommerce's later cart loads in the same request
			// (wp_loaded, then its shutdown hook). Natively an array nonce raises "Array to string conversion" and an
			// array order ID is coerced by absint() to order 1; the guard ends these requests defensively instead.
			foreach ( [
				'array order_again (own Bingo order), valid nonce' => [ 'order_again' => [ (string) $a_orders['bingo'] ], '_wpnonce' => $nonce ],
				'array order_again (another customer’s order), valid nonce' => [ 'order_again' => [ (string) $foreign['bingo'] ], '_wpnonce' => $nonce ],
				'array _wpnonce, valid own merchandise order ID' => [ 'order_again' => (string) $a_orders['merch'], '_wpnonce' => [ $nonce ] ],
				'array _wpnonce, valid own Bingo order ID' => [ 'order_again' => (string) $a_orders['bingo'], '_wpnonce' => [ $nonce ] ],
				'both parameters arrays' => [ 'order_again' => [ (string) $a_orders['bingo'] ], '_wpnonce' => [ $nonce ] ],
			] as $label => $query ) {
				$this->seed_merch( $a, 3 );
				$r = $this->reorder_request( $query, $guard );
				$this->same( [ [], null ], [ $r['warnings'], $r['thrown'] ], "{$label}: no PHP warning or error" );
				$this->same( wc_get_cart_url(), $r['redirect'], "{$label}: handled by the guard’s redirect to the cart" );
				$this->same( [ false, 0 ], [ $r['loader_saw_order_again'], $r['native_reorders'] ], "{$label}: WooCommerce’s later cart load never sees order_again; no native reorder" );
				$this->same( [ [ $a['merch'], 3 ] ], $this->cart_after(), "{$label}: session cart unchanged (no order item copied)" );
				$this->same( [ [ $a['merch'], 3 ] ], $this->persistent_cart(), "{$label}: persistent cart unchanged" );
				$this->same( [], $this->notices( 'notice' ), "{$label}: no notice, nothing about any order disclosed" );
			}

			// Store API requests have no cart at wp_loaded, so the frontend guard never runs; WooCommerce loads the
			// cart later from inside the Store API (CartController::load_cart → get_cart → get_cart_from_session).
			foreach ( [
				'Store API cart load, own Bingo order' => [ (string) $a_orders['bingo'], $nonce, [ [ $a['merch'], 3 ] ] ],
				'Store API cart load, another customer’s order' => [ (string) $foreign['bingo'], $nonce, [ [ $a['merch'], 3 ] ] ],
				'Store API cart load, array order_again' => [ [ (string) $a_orders['bingo'] ], $nonce, [ [ $a['merch'], 3 ] ] ],
			] as $label => [ $id, $wpnonce, $expected ] ) {
				$this->seed_merch( $a, 3 );
				$r = $this->reorder_request( [ 'order_again' => $id, '_wpnonce' => $wpnonce ], 'no wp_loaded guard on this request' );
				$this->same( [ [], null, 0 ], [ $r['warnings'], $r['thrown'], $r['native_reorders'] ], "{$label}: no warning, error or native reorder" );
				$this->same( [ $expected, $expected ], [ $this->cart_after(), $this->persistent_cart() ], "{$label}: session and persistent cart unchanged" );
				$this->same( [], $this->notices( 'notice' ), "{$label}: no notice" );
			}
			$this->seed_merch( $a, 3 );
			$r = $this->reorder_request( [ 'order_again' => (string) $a_orders['merch'], '_wpnonce' => $nonce ], 'no wp_loaded guard on this request' );
			$this->same( [ 1, [ [ $a['merch_long'], 1 ] ] ], [ $r['native_reorders'], $this->cart_after() ], 'Store API cart load, merchandise-only order: still WooCommerce’s native reorder' );

			// A request without WooCommerce's cart (admin, REST) never reaches its reorder; the guard stays out of it too.
			$cart = WC()->cart; WC()->cart = null; $get = $_GET;
			$_GET = [ 'order_again' => (string) $a_orders['bingo'], '_wpnonce' => $nonce ]; $redirected = null;
			$stop = static function ( $location ) { throw new RuntimeException( 'BC_FIXTURE_REDIRECT ' . $location ); };
			add_filter( 'wp_redirect', $stop, PHP_INT_MAX );
			try { $guard::guard(); } catch ( RuntimeException $e ) { $redirected = $e->getMessage(); }
			finally { remove_filter( 'wp_redirect', $stop, PHP_INT_MAX ); $_GET = $get; WC()->cart = $cart; }
			$this->same( null, $redirected, 'no cart in this request (admin, REST): the guard does not redirect' );
		} finally { restore_current_blog(); }

		$this->group = 'Reorder Hall isolation';
		switch_to_blog( $b['site'] );
		try {
			$this->login( $customer );
			$nonce = wp_create_nonce( 'woocommerce-order_again' );
			$this->seed_merch( $b, 3 );
			$this->reorder_request( [ 'order_again' => (string) $b_orders['merch'], '_wpnonce' => $nonce ], $guard );
			$this->same( [ [ $b['merch_long'], 1 ] ], $this->cart_after(), 'Hall B: the same local ID is Hall B’s merchandise order and reorders natively' );
			$this->seed_merch( $b, 3 );
			$this->reorder_request( [ 'order_again' => (string) $b_orders['bingo'], '_wpnonce' => $nonce ], $guard );
			$this->same( [ [ $b['merch'], 3 ] ], $this->cart_after(), 'Hall B: its own Bingo order is blocked and the cart preserved' );
			$this->same( [ [ $b['merch'], 3 ] ], $this->persistent_cart(), 'Hall B: persistent cart preserved' );
		} finally { restore_current_blog(); }
		wp_set_current_user( 0 );
	}

	/* ================================================================== *
	 * 4. Order views: Bingo Session label and multi-paragraph metadata
	 * ================================================================== */

	private function order_views( array $f ): void {
		$this->group = 'Order views';
		$customer = $this->customer( 'viewer', [ $f['site'] ] );
		$zone = 'America/North_Dakota/New_Salem';
		switch_to_blog( $f['site'] );
		try {
			$f['sessions']['long'] = $this->manager( fn() => $this->session( 991003, 'Saturday Super Jackpot Early Bird Special Session with Bonus Rounds and Prize Draws', 3, '18:30', $zone, [ $f['packages']['paper'] ] ) );
			$this->login( $customer );
			$this->select( $f, 'long', 'paper', [ 'unpriced' => 2 ] ); // with one merchandise line
			$r = $this->checkout();
			if ( 200 !== $r['status'] ) { throw new RuntimeException( 'Order-view fixture checkout failed: ' . wp_json_encode( $r ) ); }
			WC()->session->set( 'store_api_draft_order', 0 );
			// Ordinary merchandise metadata with two paragraphs, as a Hall Manager or extension saves it on the line.
			$note = "Happy birthday from the Friday crew.\n\nPlease wrap it in blue paper.";
			$this->manager( static function () use ( $r, $f, $note ) {
				foreach ( wc_get_order( $r['order_id'] )->get_items() as $item ) {
					if ( $f['merch'] === $item->get_product_id() ) { $item->add_meta_data( 'Gift message', $note, true ); $item->save(); }
				}
			} );
			$order = wc_get_order( $r['order_id'] );
			$label = '';
			foreach ( $order->get_items() as $item ) { if ( '' !== (string) $item->get_meta( 'bc_bingo_session', true ) ) { $label = (string) $item->get_meta( 'bc_bingo_session', true ); break; } }
			$this->ok( str_contains( $label, $zone ), 'saved Bingo Session label carries the long time zone' );
			$html = $this->order_details( $order->get_id() );
			$this->same( 1, preg_match_all( '#Gift message:</strong>\s*<p>Happy birthday from the Friday crew\.</p>\s*<p>Please wrap it in blue paper\.</p>#', $html ), 'WooCommerce renders the merchandise metadata as two paragraphs' );
			$this->ok( preg_match_all( '#Bingo Session:</strong>\s*<p>[^<]+</p>\s*</li>#', $html ) >= 2, 'WooCommerce renders each Bingo Session label as one paragraph' );
			$this->browser_order_views( $f, $order, $customer, $zone );
		} finally { wp_set_current_user( 0 ); restore_current_blog(); }
	}

	/** Optional real-request browser stage for Order Received and the My Account order view. */
	private function browser_order_views( array $f, WC_Order $order, int $customer, string $zone ): void {
		if ( '1' !== getenv( 'BC_CHECKOUT_RECOVERY_BROWSER' ) ) { echo "NOTE [Order views] browser stage skipped (BC_CHECKOUT_RECOVERY_BROWSER=1 not set)\n"; return; }
		$shots = (string) getenv( 'BC_CHECKOUT_RECOVERY_SHOTS' );
		if ( '' !== $shots && ( false === realpath( $shots ) || ! str_starts_with( (string) realpath( $shots ), '/private/tmp/' ) ) ) { throw new RuntimeException( 'Screenshots require an existing /private/tmp directory.' ); }
		$expires = time() + HOUR_IN_SECONDS;
		$cookie = wp_generate_auth_cookie( $customer, $expires, 'logged_in', WP_Session_Tokens::get_instance( $customer )->create( $expires ) );
		$path = static fn( int $page ): string => (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		$env = [
			'PATH' => (string) getenv( 'PATH' ), 'HOME' => (string) getenv( 'HOME' ), 'BC_PLAYWRIGHT_MODULE' => (string) getenv( 'BC_PLAYWRIGHT_MODULE' ), 'BC_CHROME_EXECUTABLE' => (string) getenv( 'BC_CHROME_EXECUTABLE' ),
			'BC_PHP_CGI' => dirname( PHP_BINARY ) . '/php-cgi', 'BC_CR_SITE' => (string) $f['site'], 'BC_CR_OWNER' => $this->token, 'BC_CR_HOST' => strtolower( get_site( $f['site'] )->domain ),
			'BC_CR_CHECKOUT_PATH' => $path( wc_get_page_id( 'checkout' ) ), 'BC_CR_ACCOUNT_PATH' => $path( wc_get_page_id( 'myaccount' ) ),
			'BC_CR_RECEIVED_URL' => $order->get_checkout_order_received_url(), 'BC_CR_VIEW_URL' => wc_get_endpoint_url( 'view-order', (string) $order->get_id(), wc_get_page_permalink( 'myaccount' ) ),
			'BC_CR_COOKIE_NAME' => LOGGED_IN_COOKIE, 'BC_CR_COOKIE_VALUE' => $cookie, 'BC_CR_LONG_WORD' => $zone, 'BC_CR_SHOTS' => $shots,
		];
		$process = proc_open( [ (string) getenv( 'BC_NODE_EXECUTABLE' ) ?: 'node', __DIR__ . '/checkout-recovery-browser.cjs' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Browser stage could not start.' ); }
		fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$exit = proc_close( $process );
		$result = json_decode( (string) $out, true );
		$this->group = 'Order views (browser)';
		$this->same( [ 0, true ], [ $exit, is_array( $result ) && ! isset( $result['error'] ) ], 'browser stage ran' . ( is_array( $result ) && isset( $result['error'] ) ? ': ' . substr( (string) $result['error'], 0, 300 ) : ( '' !== trim( (string) $err ) ? ': ' . substr( trim( (string) $err ), 0, 300 ) : '' ) ) );
		if ( ! is_array( $result ) || isset( $result['error'] ) ) { return; }
		$this->same( [], $result['refused'], 'every page request was served by a real php-cgi request' );
		foreach ( [ 'received' => 'Order Received', 'view-order' => 'My Account order view' ] as $key => $name ) {
			foreach ( [ 320, 375, 1440 ] as $width ) {
				$m = $result['pages'][ $key ][ $width ] ?? null;
				$at = "{$name} at {$width}px";
				$this->same( true, $m['tableFound'] ?? false, "{$at}: order details table rendered" );
				$this->same( 0, $m['pageOverflow'] ?? null, "{$at}: no horizontal page overflow" );
				$this->same( [ 2, [ 1, 1 ], [ [], [] ] ], [ count( $m['bingo'] ?? [] ), array_column( $m['bingo'] ?? [], 'paragraphs' ), array_column( $m['bingo'] ?? [], 'splitWords' ) ], "{$at}: Bingo Session labels wrap at word boundaries (only the long zone breaks)" );
				$this->ok( str_contains( (string) ( $m['bingo'][0]['text'] ?? '' ), $zone ), "{$at}: saved label shown in full" );
				$notes = $m['notes'][0] ?? [];
				$this->same( [ 1, 2, true ], [ count( $m['notes'] ?? [] ), $notes['paragraphs'] ?? null, $notes['separateLines'] ?? null ], "{$at}: two-paragraph merchandise metadata keeps separate paragraphs" );
				$this->same( [ 'block', 'block' ], $notes['display'] ?? null, "{$at}: merchandise metadata paragraphs remain block paragraphs" );
				$this->same( [], $notes['splitWords'] ?? null, "{$at}: merchandise metadata words not split" );
			}
		}
	}

	/** A dated Session in another Hall zone; the schedule captures the Hall zone when it is created. */
	private function session( int $template, string $name, int $days, string $time, string $zone, array $packages ): int {
		global $wpdb;
		$audit = static fn( array $e ): int => 1;
		$now = current_time( 'mysql', true );
		$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => $template, 'name' => $name, 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
		$previous = get_option( 'timezone_string' );
		update_option( 'timezone_string', $zone );
		try {
			$schedules = new SessionScheduleService( $audit );
			$date = ( new DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->setTime( 12, 0 )->modify( "+{$days} days" )->format( 'Y-m-d' );
			$schedule = $schedules->create( $template, 'dates', $time, [ $date ], $packages );
			if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Long-zone Session fixture failed.' ); }
			$schedules->generate( (int) $schedule->schedule['schedule_id'] );
		} finally { update_option( 'timezone_string', $previous ); }
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
		( new SessionService( $audit ) )->setAvailableForSale( $id, true );
		return $id;
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	private function as_guest( array $f ): void {
		switch_to_blog( $f['site'] );
		wp_set_current_user( 0 );
		WC()->session = null; WC()->cart = null; WC()->customer = null;
		WC()->initialize_session(); WC()->initialize_cart();
		WC()->customer->set_props( array_combine( array_map( static fn( $k ) => 'billing_' . $k, array_keys( $this->address ) ), $this->address ) );
		restore_current_blog(); switch_to_blog( $f['site'] );
	}

	private function login( int $user ): void {
		wp_set_current_user( $user );
		WC()->session = null; WC()->cart = null; WC()->customer = null;
		WC()->initialize_session(); WC()->initialize_cart();
		WC()->customer->set_props( array_combine( array_map( static fn( $k ) => 'billing_' . $k, array_keys( $this->address ) ), $this->address ) );
	}

	/** @return array Stored cart, as WooCommerce saves it to the session. */
	private function select( array $f, string $session, string $package, array $addons ): array {
		$this->clear();
		WC()->cart->add_to_cart( $f['merch'], 1 );
		$input = [];
		foreach ( $addons as $key => $quantity ) { $input[] = [ 'package_addon_id' => $f['relations'][ $key ], 'quantity' => $quantity ]; }
		$parent = CustomerCart::add( [ 'session_id' => $f['sessions'][ $session ], 'package_id' => $f['packages'][ $package ], 'addons' => $input ] );
		if ( ! is_string( $parent ) ) { throw new RuntimeException( 'Selection fixture failed: ' . wp_json_encode( $parent ) ); }
		WC()->cart->calculate_totals();
		return ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session();
	}

	private function reload( array $stored ): void {
		WC()->cart->cart_contents = []; WC()->session->set( 'cart', $stored ); WC()->session->set( 'cart_totals', null );
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
		WC()->cart->calculate_totals();
	}

	private function remove_package(): void {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( 'package' === ( $item[ CartSelection::META ]['role'] ?? null ) ) { WC()->cart->remove_cart_item( $key ); }
		}
		WC()->cart->calculate_totals();
	}

	private function clear(): void { WC()->cart->empty_cart( false ); wc_clear_notices(); WC()->cart->cart_context = 'shortcode'; WC()->session->set( 'store_api_draft_order', 0 ); }

	private function notices( string $type ): array {
		return array_values( array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, wc_get_notices( $type ) ) );
	}

	private function count( array $messages, string $message ): int { return count( array_keys( $messages, $message, true ) ); }

	/** Real Store API POST /checkout with the offline BACS gateway, through a fresh route (as each real request has). */
	private function checkout(): array {
		$route = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class )->get( 'checkout' );
		register_rest_route( 'wc/store/v1', '/checkout', $route->get_args(), true );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_body_params( [ 'billing_address' => $this->address, 'payment_method' => 'bacs' ] );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$response = rest_get_server()->dispatch( $request ); $data = $response->get_data();
		return [ 'status' => $response->get_status(), 'code' => $data['code'] ?? null, 'message' => $data['message'] ?? null, 'order_id' => (int) ( $data['order_id'] ?? 0 ) ];
	}

	private function checkout_status( int $status, ?string $code, ?string $message, string $label ): ?WC_Order {
		$r = $this->checkout();
		$this->same( [ $status, $code, $message ], [ $r['status'], 200 === $r['status'] ? null : $r['code'], 200 === $r['status'] ? null : $r['message'] ], $label );
		if ( 200 !== $r['status'] ) { return null; }
		$order = wc_get_order( $r['order_id'] );
		$this->same( 'on-hold', $order ? $order->get_status() : 'missing', $label . ' (offline BACS order awaits payment)' );
		WC()->session->set( 'store_api_draft_order', 0 );
		return $order ?: null;
	}

	/** Real classic WC_Checkout::process_checkout(); stopped before order creation if validation ever passes. */
	private function classic( array $override = [] ): array {
		wc_clear_notices(); WC()->cart->cart_context = 'shortcode';
		$reached = false; $codes = [];
		$stop = static function () use ( &$reached ) { $reached = true; throw new RuntimeException( 'BC_FIXTURE_AFTER_VALIDATION' ); };
		$observe = static function ( $data, $errors ) use ( &$codes ) { $codes = $errors->get_error_codes(); };
		add_filter( 'woocommerce_create_order', $stop ); add_action( 'woocommerce_after_checkout_validation', $observe, PHP_INT_MAX, 2 );
		$post = $_POST; $request = $_REQUEST;
		try {
			$_POST = [ 'terms' => '1', 'payment_method' => 'bacs' ];
			foreach ( $this->address as $key => $value ) { $_POST[ 'billing_' . $key ] = $value; }
			$_POST = array_merge( $_POST, $override );
			$_REQUEST = $_POST + [ 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ) ];
			WC()->checkout()->process_checkout();
		} finally { $_POST = $post; $_REQUEST = $request; remove_filter( 'woocommerce_create_order', $stop ); remove_action( 'woocommerce_after_checkout_validation', $observe, PHP_INT_MAX ); }
		$errors = wc_get_notices( 'error' );
		wc_clear_notices();
		return [ 'errors' => array_map( static fn( $n ) => wp_strip_all_tags( (string) $n['notice'] ), $errors ), 'data' => array_column( $errors, 'data' ), 'codes' => $codes, 'reached' => $reached ];
	}

	/** BingoThemev1's page.php for one page of this Hall, as WordPress renders it. */
	private function render_page( int $page ): string {
		global $wp_query, $wp_the_query, $post;
		$previous = [ $wp_query, $wp_the_query, $post ];
		$wp_query = new WP_Query( [ 'page_id' => $page ] ); $wp_the_query = $wp_query;
		ob_start();
		try { include ABSPATH . 'wp-content/themes/' . self::THEME . '/page.php'; return (string) ob_get_contents(); }
		finally { ob_end_clean(); [ $wp_query, $wp_the_query, $post ] = $previous; }
	}

	private function with_query_fault( callable $match, callable $callback ): void {
		global $wpdb;
		$fault = static fn( $sql ) => $match( (string) $sql ) ? 'SELECT * FROM bc_checkout_recovery_unreadable' : $sql;
		$quiet = $wpdb->suppress_errors( true ); add_filter( 'query', $fault );
		try { $callback(); } finally { remove_filter( 'query', $fault ); $wpdb->suppress_errors( $quiet ); }
	}

	private function sales( array $f, string $session, bool $on ): void {
		$this->manager( fn() => ( new SessionService( static fn( array $e ): int => 1 ) )->setAvailableForSale( $f['sessions'][ $session ], $on ) );
	}

	private function addon_enabled( array $f, string $relation, bool $enabled ): void {
		$this->manager( function () use ( $f, $relation, $enabled ) {
			$service = new PackageAddonService( static fn( array $e ): int => 1 );
			foreach ( $f['packages'] as $package ) {
				foreach ( (array) $service->listForPackage( $package ) as $row ) {
					if ( $f['relations'][ $relation ] === (int) $row['package_addon_id'] ) {
						if ( is_wp_error( $service->update( (int) $row['package_addon_id'], (string) $row['fingerprint'], (string) $row['unit_price'], (int) $row['max_quantity'], $enabled ) ) ) { throw new RuntimeException( 'Add-on fixture update failed.' ); }
						return;
					}
				}
			}
		} );
	}

	private function manager( callable $callback ): mixed {
		$previous = get_current_user_id(); wp_set_current_user( $this->admin );
		try { return $callback(); } finally { wp_set_current_user( $previous ); }
	}

	private function customer( string $label, array $sites ): int {
		$suffix = strtolower( wp_generate_password( 8, false ) );
		switch_to_blog( $sites[0] );
		try {
			$id = wp_insert_user( [ 'user_login' => "bc_recovery_{$label}_{$suffix}", 'user_pass' => wp_generate_password( 32 ), 'user_email' => "recovery-{$label}-{$suffix}@example.invalid", 'role' => 'customer' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Customer fixture failed.' ); }
		} finally { restore_current_blog(); }
		$this->users[] = (int) $id;
		update_user_meta( (int) $id, self::OWNER, $this->token );
		foreach ( array_slice( $sites, 1 ) as $site ) { add_user_to_blog( $site, (int) $id, 'customer' ); }
		return (int) $id;
	}

	/**
	 * Completed orders placed through the real Store API checkout by a signed-in customer. With $align,
	 * extra completed merchandise orders are placed first until the first listed order takes that local ID.
	 */
	private function completed_orders( array $f, int $customer, array $kinds, int $align = 0 ): array {
		switch_to_blog( $f['site'] );
		try {
			$this->login( $customer );
			$ids = [];
			foreach ( $kinds as $index => $kind ) {
				$attempts = 0;
				do {
				$this->clear();
				if ( 'bingo' === $kind ) { $this->select( $f, 'friday', 'paper', [ 'chips' => 1 ] ); }
				else { WC()->cart->add_to_cart( $f['merch_long'], 1 ); WC()->cart->calculate_totals(); }
				$r = $this->checkout();
				if ( 200 !== $r['status'] ) { throw new RuntimeException( 'Order fixture failed: ' . wp_json_encode( $r ) ); }
				WC()->session->set( 'store_api_draft_order', 0 );
				$this->manager( static fn() => wc_get_order( $r['order_id'] )->update_status( 'completed' ) );
				if ( 0 === $index && $align && $r['order_id'] > $align ) { throw new RuntimeException( 'Order IDs cannot be aligned for the Hall isolation case.' ); }
				} while ( 0 === $index && $align && $r['order_id'] < $align && ++$attempts < 50 );
				$ids[ $kind ] = $r['order_id'];
			}
			$this->clear();
			return $ids;
		} finally { wp_set_current_user( 0 ); restore_current_blog(); }
	}

	private function order_details( int $order ): string {
		ob_start();
		try { woocommerce_order_details_table( $order ); return (string) ob_get_contents(); } finally { ob_end_clean(); }
	}

	private function seed_merch( array $f, int $quantity ): void {
		$this->clear();
		WC()->cart->add_to_cart( $f['merch'], $quantity );
		WC()->session->set( 'cart', ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session() );
		WC()->cart->cart_contents = [];
	}

	/** The signed-in customer's persistent cart for the current Hall, as WooCommerce stores it in user meta. */
	private function persistent_cart(): array {
		$saved = get_user_meta( get_current_user_id(), '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
		return array_values( array_map( static fn( $i ) => [ (int) $i['product_id'], (int) $i['quantity'] ], (array) ( $saved['cart'] ?? [] ) ) );
	}

	private function cart_after(): array {
		WC()->cart->cart_contents = [];
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
		return array_values( array_map( static fn( $i ) => [ (int) $i['product_id'], (int) $i['quantity'] ], WC()->cart->get_cart() ) );
	}

	/**
	 * One request for an order_again URL: the BingoCommerce guard (wp_loaded priority 5, when present),
	 * then WooCommerce's own cart loader. Without a redirect the loader runs on wp_loaded (priority 10);
	 * after a redirect's exit it still runs from WooCommerce's shutdown hook
	 * (WC_Cart_Session::maybe_set_cart_cookies → WC_Cart::get_cart), with the same request parameters.
	 */
	private function reorder_request( array $query, string $guard, bool $count_loads = false ): array {
		$get = $_GET; $request = $_REQUEST;
		$_GET = $query; $_REQUEST = $query;
		$warnings = []; $redirect = null; $thrown = null; $loads = null; $saw = null; $native = 0;
		$count_native = static function () use ( &$native ) { ++$native; };
		add_action( 'woocommerce_ordered_again', $count_native );
		$stop = static function ( $location ) { throw new RuntimeException( 'BC_FIXTURE_REDIRECT ' . $location ); };
		add_filter( 'wp_redirect', $stop, PHP_INT_MAX );
		set_error_handler( static function ( $no, $message ) use ( &$warnings ) { $warnings[] = $message; return true; }, E_WARNING | E_NOTICE | E_USER_WARNING | E_DEPRECATED );
		wc_clear_notices();
		$step = static function ( callable $callback ) use ( &$redirect ): void {
			try { $callback(); } catch ( RuntimeException $e ) {
				if ( ! str_starts_with( $e->getMessage(), 'BC_FIXTURE_REDIRECT ' ) ) { throw $e; }
				$redirect ??= substr( $e->getMessage(), strlen( 'BC_FIXTURE_REDIRECT ' ) );
			}
		};
		try {
			if ( class_exists( $guard ) ) { $before = did_action( 'woocommerce_cart_loaded_from_session' ); try { $step( [ $guard, 'guard' ] ); } finally { $loads = did_action( 'woocommerce_cart_loaded_from_session' ) - $before; } }
			$step( static function () use ( &$saw ): void { $saw = isset( $_GET['order_again'] ); WC()->cart->cart_contents = []; ( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session(); } );
		} catch ( Throwable $e ) {
			$thrown = get_class( $e ) . ': ' . $e->getMessage();
		} finally {
			restore_error_handler(); remove_filter( 'wp_redirect', $stop, PHP_INT_MAX ); remove_action( 'woocommerce_ordered_again', $count_native );
			$_GET = $get; $_REQUEST = $request;
		}
		return [ 'redirect' => $redirect, 'warnings' => $warnings, 'thrown' => $thrown, 'loads_during_guard' => $count_loads ? $loads : null, 'loader_saw_order_again' => $saw, 'native_reorders' => $native ];
	}

	private function cart_loader_priority(): int|false {
		foreach ( $GLOBALS['wp_filter']['wp_loaded']->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && $fn[0] instanceof WC_Cart_Session && 'get_cart_from_session' === $fn[1] ) { return (int) $priority; }
			}
		}
		return false;
	}

	/* ================================================================== *
	 * Fixture: two Halls built in the same order (colliding local IDs)
	 * ================================================================== */

	private function fixture( string $label ): array {
		global $wpdb;
		wp_set_current_user( $this->admin );
		$site = wp_insert_site( [ 'domain' => 'recovery-' . strtolower( $label ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Recovery Hall ' . $label, 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = (int) $site;
		update_blog_option( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			WC_Install::create_tables(); WC_Install::create_roles();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			\BingoCommerce\Core\Roles::sync_current_site();
			foreach ( [ 'timezone_string' => 'America/New_York', 'woocommerce_currency' => 'USD', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_manage_stock' => 'yes', 'woocommerce_ship_to_countries' => 'disabled', 'woocommerce_calc_taxes' => 'no', 'woocommerce_checkout_phone_field' => 'optional' ] as $option => $value ) { update_option( $option, $value ); }
			update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Direct bank transfer' ] );
			switch_theme( self::THEME );
			WC_Install::create_pages(); // the block Cart/Checkout pages HallSiteProvisioner's installer creates
			$audit = static fn( array $e ): int => 1;
			$game = (int) ( new GameTypeService( $audit ) )->create( 'Regular' )->game_type['game_type_id'];
			$play = static function ( string $name, string $medium, int $faces, ?int $game, string $price ) use ( $audit ): int {
				$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $wc = (int) $p->save();
				return (int) ( new BingoPlayProductService( $audit ) )->create( $wc, $medium, $faces, $game )->play_product['bingo_play_product_id'];
			};
			$product = static function ( string $name, string $price ): int { $p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); return (int) $p->save(); };
			$base = $play( 'Paper sheets', 'paper', 1, null, '1.00' );
			$unpriced = $play( 'Extra paper pack', 'paper', 1, null, '' ); // priced only by its relationships
			$chips = $play( 'Magnetic chips', 'paper', 1, null, '5.00' );
			$digital_base = $play( 'Digital cards', 'digital', 6, $game, '1.00' );
			$digital_extra = $play( 'Extra digital cards', 'digital', 6, $game, '1.00' );
			$packages = new PackageService( $audit );
			$paper = (int) $packages->create( $product( 'Paper Night Pack', '10.00' ), [ [ 'bingo_play_product_id' => $base, 'quantity' => 1 ] ] )->package['package_id'];
			$digital = (int) $packages->create( $product( 'Digital Night Pack', '20.00' ), [ [ 'bingo_play_product_id' => $digital_base, 'quantity' => 6 ] ] )->package['package_id'];
			$addons = new PackageAddonService( $audit );
			$relations = [
				'unpriced'      => (int) $addons->create( $paper, $unpriced, '1.50', 2 )->package_addon['package_addon_id'],
				'chips'         => (int) $addons->create( $paper, $chips, '3.00', 1 )->package_addon['package_addon_id'],
				'digital_extra' => (int) $addons->create( $digital, $digital_extra, '4.00', 3 )->package_addon['package_addon_id'],
			];
			$merch = $product( 'Bingo dauber, blue', '2.00' );
			$merch_long = $product( 'Commemorative bingo hall tote bag', '12.00' );
			$sessions = [];
			foreach ( [ 'friday' => [ 2, 991001 ], 'sunday' => [ 4, 991002 ] ] as $key => [ $days, $template ] ) {
				$now = current_time( 'mysql', true );
				$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => $template, 'name' => ucfirst( $key ) . ' Bingo', 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
				$schedules = new SessionScheduleService( $audit );
				$date = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )->setTime( 12, 0 )->modify( "+{$days} days" )->format( 'Y-m-d' );
				$schedule = $schedules->create( $template, 'dates', '18:00', [ $date ], [ $paper, $digital ] );
				if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Schedule fixture failed.' ); }
				$schedules->generate( (int) $schedule->schedule['schedule_id'] );
				$sessions[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
				( new SessionService( $audit ) )->setAvailableForSale( $sessions[ $key ], true );
			}
			return [ 'site' => (int) $site, 'packages' => compact( 'paper', 'digital' ), 'relations' => $relations, 'merch' => $merch, 'merch_long' => $merch_long, 'sessions' => $sessions ];
		} finally { wp_set_current_user( 0 ); restore_current_blog(); }
	}

	private function snapshot( int $site ): array {
		global $wpdb; $result = [];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) ) as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}

	private function cleanup(): void {
		global $wpdb;
		$this->group = 'Cleanup';
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( [ null, [] ], [ get_site( $site ), $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) ], 'owned disposable Hall and its tables removed' );
		}
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( $this->users as $user ) {
			if ( $user === $this->admin || $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Customer ownership guard refused.' ); }
			wpmu_delete_user( $user ); clean_user_cache( $user );
			$this->same( [ null, '0' ], [ $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->users, $user ) ), (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->usermeta, $user ) ) ], 'owned disposable customer and all its metadata deleted' );
		}
		wp_set_current_user( 0 );
	}

	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' actual=' . wp_json_encode( $actual, JSON_UNESCAPED_UNICODE ) ) );
	}

	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS' : 'FAIL' ) . ' [' . $this->group . '] ' . $label . "\n";
	}
}

( new CheckoutRecoveryTest() )->run();
