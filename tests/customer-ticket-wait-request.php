<?php
/**
 * One real request against a disposable tests/customer-ticket-wait.php Hall, for that suite and its browser
 * stage. php-cgi, never a web server.
 *
 * The Hall is booted directly from HTTP_HOST (as tests/checkout-recovery-request.php does), so the
 * customer's real logged-in cookie, WooCommerce's endpoints and session handling, the BingoCommerce plugins
 * (Digital Tickets included), the WordPress REST server and BingoThemev1 run in their genuine lifecycle.
 * Only these requests are served; anything else is refused before WordPress boots:
 *   GET  <BC_TW_CHECKOUT_PATH>order-received/<id>/?key=<order key>      Order Received
 *   GET  the same with key, order_id, wc_payment_method, _wpnonce, wc_stripe_cs and pay_for_order
 *        (the shape of Stripe's Checkout Sessions return URL; Stripe itself is not loaded here)
 *   POST <BC_TW_CHECKOUT_PATH>order-received/<id>/?key=<order key>      WooCommerce's guest email form
 *   GET  <BC_TW_ACCOUNT_PATH>view-order/<id>/                            My Account order view
 *   POST /wp-json/bingocommerce-digital-tickets/v1/ticket-status        the status route (JSON body)
 *
 * BC_TW_FAULT=void-read or ticket-read makes that one read fail inside this request (a database fault the
 * customer cannot cause). BC_TW_NO_ACCOUNT=1 gives this request a Hall without a My Account page.
 * BC_TW_OBSERVE=<file in /private/tmp/bc-ticket-wait-observe.*> records, at shutdown, whether WooCommerce
 * created a session or loaded a cart and every write statement the request issued.
 *
 * Environment: BC_TEST_DB=bingocommerce_state_recon BC_FIXTURE_CGI=1 BC_FIXTURE_WOOCOMMERCE=1
 * BC_TW_SITE BC_TW_OWNER BC_TW_CHECKOUT_PATH BC_TW_ACCOUNT_PATH, plus the CGI request variables.
 */
declare(strict_types=1);

function bc_tw_refuse( string $reason ): void {
	error_log( 'Ticket waiting request fixture refused: ' . $reason );
	exit( 1 );
}

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' )
	|| '1' !== getenv( 'BC_FIXTURE_WOOCOMMERCE' ) || isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) ) {
	bc_tw_refuse( 'environment' );
}

$bc_tw_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
$bc_tw_type   = strtolower( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) );
$bc_tw_path   = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$bc_tw_keys   = array_keys( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
sort( $bc_tw_keys );
$bc_tw_page = static fn( string $name ): string => 1 === preg_match( '#\A/[a-z0-9/-]+/\z#', (string) getenv( $name ) ) ? (string) getenv( $name ) : '';
$bc_tw_checkout = $bc_tw_page( 'BC_TW_CHECKOUT_PATH' );
$bc_tw_account  = $bc_tw_page( 'BC_TW_ACCOUNT_PATH' );
$bc_tw_return   = [ '_wpnonce', 'key', 'order_id', 'pay_for_order', 'wc_payment_method', 'wc_stripe_cs' ];
$bc_tw_received = '' !== $bc_tw_checkout && 1 === preg_match( '#\A' . preg_quote( $bc_tw_checkout, '#' ) . 'order-received/[1-9][0-9]*/\z#', $bc_tw_path );
$bc_tw_ok = '' !== $bc_tw_account && (
	( 'GET' === $bc_tw_method && $bc_tw_received && in_array( $bc_tw_keys, [ [ 'key' ], $bc_tw_return ], true ) )
	|| ( 'POST' === $bc_tw_method && $bc_tw_received && [ 'key' ] === $bc_tw_keys && str_starts_with( $bc_tw_type, 'application/x-www-form-urlencoded' ) )
	|| ( 'GET' === $bc_tw_method && [] === $bc_tw_keys && 1 === preg_match( '#\A' . preg_quote( $bc_tw_account, '#' ) . 'view-order/[1-9][0-9]*/\z#', $bc_tw_path ) )
	|| ( 'POST' === $bc_tw_method && [] === $bc_tw_keys && '/wp-json/bingocommerce-digital-tickets/v1/ticket-status' === $bc_tw_path && str_starts_with( $bc_tw_type, 'application/json' ) )
);
if ( ! $bc_tw_ok ) {
	bc_tw_refuse( $bc_tw_method . ' ' . (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
}

$bc_tw_host    = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
$bc_tw_site    = (int) getenv( 'BC_TW_SITE' );
$bc_tw_fault   = (string) getenv( 'BC_TW_FAULT' );
$bc_tw_observe = (string) getenv( 'BC_TW_OBSERVE' );
if ( $bc_tw_site <= 4 || 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', (string) getenv( 'BC_TW_OWNER' ) )
	|| ! in_array( $bc_tw_fault, [ '', 'void-read', 'ticket-read' ], true ) || ! in_array( (string) getenv( 'BC_TW_NO_ACCOUNT' ), [ '', '1' ], true )
	|| ( '' !== $bc_tw_observe && 1 !== preg_match( '#\A/private/tmp/bc-ticket-wait-observe\.[A-Za-z0-9]+/[a-z0-9-]+\.json\z#', $bc_tw_observe ) ) ) {
	bc_tw_refuse( 'owner' );
}

$GLOBALS['bc_tw_writes'] = [];
function bc_tw_load_plugins(): void {
	foreach ( [ 'bingocommerce-core/bingocommerce-core.php', 'bingocommerce-catalog/bingocommerce-catalog.php', 'bingocommerce-sessions/bingocommerce-sessions.php', 'bingocommerce-digital-tickets/bingocommerce-digital-tickets.php' ] as $file ) {
		require_once ABSPATH . 'wp-content/plugins/' . $file;
	}
	add_filter( 'pre_wp_mail', '__return_true' );
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP.' ), PHP_INT_MAX );
	$fault = (string) getenv( 'BC_TW_FAULT' );
	if ( '' !== $fault ) {
		add_filter( 'query', static function ( $sql ) use ( $fault ) {
			$hit = 'void-read' === $fault
				? str_starts_with( (string) $sql, 'SELECT (SELECT COUNT(*) FROM' ) && str_contains( (string) $sql, 'bingo_entitlement_voids' )
				: str_starts_with( (string) $sql, 'SELECT e.*,t.token' );
			return $hit ? 'SELECT * FROM bc_ticket_wait_unreadable' : $sql;
		} );
	}
	if ( '1' === getenv( 'BC_TW_NO_ACCOUNT' ) ) {
		add_filter( 'woocommerce_get_myaccount_page_id', static fn() => 0 );
	}
	if ( '' !== (string) getenv( 'BC_TW_OBSERVE' ) ) {
		// Every statement that could change anything, as the database would receive it.
		add_filter( 'query', static function ( $sql ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', (string) $sql ) ) { $GLOBALS['bc_tw_writes'][] = substr( (string) $sql, 0, 240 ); }
			return $sql;
		}, PHP_INT_MAX );
		register_shutdown_function( static function (): void {
			$woo = function_exists( 'WC' ) ? WC() : null;
			$observed = [
				'session'      => $woo && $woo->session ? get_class( $woo->session ) : null,
				'cart'         => $woo && $woo->cart ? get_class( $woo->cart ) : null,
				'cart_loaded'  => did_action( 'woocommerce_cart_loaded_from_session' ),
				'writes'       => $GLOBALS['bc_tw_writes'],
			];
			file_put_contents( (string) getenv( 'BC_TW_OBSERVE' ), (string) json_encode( $observed ) );
		} );
	}
}
$GLOBALS['wp_filter']['muplugins_loaded'][ PHP_INT_MIN ][] = [ 'function' => 'bc_tw_load_plugins', 'accepted_args' => 0 ];

define( 'WP_USE_THEMES', true );
require __DIR__ . '/fixtures/isolated-multisite.php';

$bc_tw_hall = get_site( get_current_blog_id() );
if ( get_current_blog_id() !== $bc_tw_site || ! $bc_tw_hall instanceof WP_Site || strtolower( $bc_tw_hall->domain ) !== $bc_tw_host
	|| (string) getenv( 'BC_TW_OWNER' ) !== (string) get_option( 'bc_ticket_wait_test_owner' ) ) {
	bc_tw_refuse( 'Hall ownership' );
}
if ( '' !== $bc_tw_fault ) {
	$GLOBALS['wpdb']->suppress_errors( true );
}

wp();
require ABSPATH . WPINC . '/template-loader.php';
