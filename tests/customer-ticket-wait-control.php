<?php
/**
 * One fixture step for tests/customer-ticket-wait-browser.cjs, between browser steps, so an order page
 * that has already rendered meets changed server state. Owned disposable Halls only:
 *
 *   pay <site> <order>                native payment_complete(), standing in for the gateway webhook:
 *                                     the real Digital Tickets observers create the entitlement and ticket
 *   pay-without-ticket <site> <order> the same, with the ticket insert failing inside this process, so the
 *                                     order is paid and its entitlement active but no ticket exists yet
 *   redeliver <site> <order>          the payment event again (do_action), which issues a missing ticket
 *   counts <site> <order>             read-only: status, entitlement/ticket/marker counts and a short hash
 *                                     of the ticket token (never the token itself), as JSON
 *   seed-cart <site> <order>          a WooCommerce session cart of two of the order's merchandise product, as
 *                                     if filled in another tab: the signed-in owner's session, or for a guest
 *                                     order a new guest session whose cookie is returned as JSON
 *   cart <site> <order> [<guest>]     read-only: quantity, raw-value hash and expiry of the owner's session
 *                                     cart, or of the named guest session, as JSON
 *   age <site> <order>                the order's creation time moved 20 minutes back, past WooCommerce's
 *                                     guest grace period, so its order page asks a guest to verify the email
 *   cancel <site> <order>             a Hall manager cancels the order (native status change; the real Digital
 *                                     Tickets observer records its void marker)
 *
 * BC_TEST_DB=bingocommerce_state_recon BC_TW_OWNER=<token> php tests/customer-ticket-wait-control.php <action> <site> <order> [<guest>]
 */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php';

use BingoCommerce\DigitalTickets\Migrations;

[ , $bc_action, $bc_site, $bc_order, $bc_guest ] = array_pad( $argv, 5, '' );
$bc_site = (int) $bc_site; $bc_order = (int) $bc_order;
if ( ! in_array( $bc_action, [ 'pay', 'pay-without-ticket', 'redeliver', 'counts', 'seed-cart', 'cart', 'age', 'cancel' ], true ) || $bc_site <= 4 || $bc_order <= 0
	|| ( '' !== $bc_guest && ( 'cart' !== $bc_action || 1 !== preg_match( '/\At_[A-Za-z0-9]{30}\z/', $bc_guest ) ) )
	|| ! wp_is_uuid( (string) getenv( 'BC_TW_OWNER' ), 4 ) || (string) getenv( 'BC_TW_OWNER' ) !== (string) get_blog_option( $bc_site, 'bc_ticket_wait_test_owner' ) ) {
	fwrite( STDERR, "Ticket waiting control refused.\n" );
	exit( 1 );
}
if ( function_exists( 'WC' ) && WC()->session ) { remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP.' ), PHP_INT_MAX );
BingoCommerce\DigitalTickets\Plugin::init();

switch_to_blog( $bc_site );
try {
	global $wpdb;
	$order = wc_get_order( $bc_order );
	if ( ! $order instanceof WC_Order || 'stripe' !== $order->get_payment_method() ) { throw new RuntimeException( 'Not an owned fixture card order.' ); }
	if ( 'counts' === $bc_action ) {
		$entitlements = $wpdb->get_results( $wpdb->prepare( 'SELECT entitlement_id, status FROM %i WHERE order_id = %d', Migrations::table(), $bc_order ), ARRAY_A );
		$tickets = $wpdb->get_col( $wpdb->prepare( 'SELECT t.token FROM %i t JOIN %i e ON e.entitlement_id = t.entitlement_id WHERE e.order_id = %d', Migrations::tickets_table(), Migrations::table(), $bc_order ) );
		$markers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Migrations::voids_table(), $bc_order ) );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Counts read failed.' ); }
		echo wp_json_encode( [ 'status' => $order->get_status(), 'paid' => $order->is_paid(), 'entitlements' => count( $entitlements ), 'active' => count( array_filter( $entitlements, static fn( $row ) => 'active' === $row['status'] ) ), 'tickets' => count( $tickets ), 'markers' => $markers, 'token' => $tickets ? substr( hash( 'sha256', (string) $tickets[0] ), 0, 12 ) : '' ] ) . "\n";
		exit( 0 );
	}
	if ( 'cart' === $bc_action ) {
		$key = '' !== $bc_guest ? $bc_guest : (string) $order->get_customer_id();
		if ( '0' === $key ) { throw new RuntimeException( 'A guest order’s cart is read by its guest session ID.' ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT session_value, session_expiry FROM %i WHERE session_key = %s', $wpdb->prefix . 'woocommerce_sessions', $key ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Session read failed.' ); }
		$session = is_array( $row ) ? (array) maybe_unserialize( $row['session_value'] ) : [];
		$cart = isset( $session['cart'] ) ? (array) maybe_unserialize( $session['cart'] ) : [];
		echo wp_json_encode( [ 'quantity' => array_sum( array_map( static fn( $line ) => (int) ( $line['quantity'] ?? 0 ), $cart ) ), 'hash' => is_array( $row ) ? substr( hash( 'sha256', (string) $row['session_value'] ), 0, 16 ) : '', 'expiry' => is_array( $row ) ? (int) $row['session_expiry'] : 0 ] ) . "\n";
		exit( 0 );
	}
	if ( 'seed-cart' === $bc_action ) {
		$merch = 0;
		foreach ( $order->get_items() as $item ) { if ( '' === $item->get_meta( 'bc_purchase', true ) ) { $merch = $item->get_product_id(); } }
		wp_set_current_user( $order->get_customer_id() );
		WC()->session = null; WC()->cart = null; WC()->customer = null;
		WC()->initialize_session(); WC()->initialize_cart();
		WC()->cart->empty_cart( false );
		if ( ! $merch || ! WC()->cart->add_to_cart( $merch, 2 ) ) { throw new RuntimeException( 'Cart seeding failed.' ); }
		WC()->session->set( 'cart', ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session() );
		$cookie = '';
		if ( 0 === $order->get_customer_id() ) {
			// WooCommerce 11's own guest session cookie: customer|expiry|expiring|hmac (WC_Session_Handler::hash()).
			$handler = WC()->session; $read = static fn( string $name ) => ( new ReflectionProperty( $handler, $name ) )->getValue( $handler );
			$customer = $handler->get_customer_id(); $expiration = (string) $read( '_session_expiration' ); $expiring = (string) $read( '_session_expiring' );
			( new ReflectionProperty( $handler, '_has_cookie' ) )->setValue( $handler, true );
			$cookie = $customer . '|' . $expiration . '|' . $expiring . '|' . hash_hmac( 'md5', $customer . '|' . $expiration, wp_hash( $customer . '|' . $expiration ) );
		}
		WC()->session->save_data();
		remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 );
		echo wp_json_encode( '' === $cookie ? [ 'ok' => true ] : [ 'ok' => true, 'guest' => WC()->session->get_customer_id(), 'name' => 'wp_woocommerce_session_' . COOKIEHASH, 'value' => $cookie ] ) . "\n";
		exit( 0 );
	}
	if ( 'age' === $bc_action ) {
		$order->set_date_created( time() - 20 * MINUTE_IN_SECONDS );
		$order->save();
	} elseif ( 'cancel' === $bc_action ) {
		wp_set_current_user( (int) username_exists( 'fixture_admin' ) );
		if ( true !== $order->update_status( 'cancelled' ) ) { throw new RuntimeException( 'Cancellation failed.' ); }
	} elseif ( 'redeliver' === $bc_action ) {
		do_action( 'woocommerce_payment_complete', $bc_order );
	} else {
		$fail = static fn( $sql ) => str_starts_with( (string) $sql, 'INSERT INTO `' . Migrations::tickets_table() . '`' ) ? 'SELECT * FROM bc_ticket_wait_failed_ticket_insert' : $sql;
		if ( 'pay-without-ticket' === $bc_action ) { $wpdb->suppress_errors( true ); add_filter( 'query', $fail ); }
		try { $paid = wc_get_order( $bc_order )->payment_complete( 'fixture-card-' . $bc_order ); }
		finally { remove_filter( 'query', $fail ); }
		if ( true !== $paid ) { throw new RuntimeException( 'Native payment completion failed.' ); }
	}
	echo "ok\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Ticket waiting control failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
} finally {
	restore_current_blog();
}
