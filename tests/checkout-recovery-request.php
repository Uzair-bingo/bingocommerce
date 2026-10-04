<?php
/**
 * One real order-page request against a disposable tests/checkout-recovery.php Hall, for its
 * optional browser stage (BC_CHECKOUT_RECOVERY_BROWSER=1). php-cgi, never a web server.
 *
 * The Hall is booted directly from HTTP_HOST (as tests/homepage-session-purchase-request.php does),
 * so WooCommerce's session, its endpoints, the customer's real logged-in cookie and BingoThemev1 run
 * in their genuine lifecycle. Only these requests are served; anything else is refused before
 * WordPress boots:
 *   GET <BC_CR_CHECKOUT_PATH>order-received/<id>/?key=<order key>   Order Received
 *   GET <BC_CR_ACCOUNT_PATH>view-order/<id>/                        My Account order view
 *
 * Environment: BC_TEST_DB=bingocommerce_state_recon BC_FIXTURE_CGI=1 BC_FIXTURE_WOOCOMMERCE=1
 * BC_CR_SITE BC_CR_OWNER BC_CR_CHECKOUT_PATH BC_CR_ACCOUNT_PATH, plus the CGI request variables.
 */
declare(strict_types=1);

function bc_cr_refuse( string $reason ): void {
	error_log( 'Checkout recovery request fixture refused: ' . $reason );
	exit( 1 );
}

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' )
	|| '1' !== getenv( 'BC_FIXTURE_WOOCOMMERCE' ) || isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) ) {
	bc_cr_refuse( 'environment' );
}

$bc_cr_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$bc_cr_keys = array_keys( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bc_cr_page = static fn( string $name ): string => 1 === preg_match( '#\A/[a-z0-9/-]+/\z#', (string) getenv( $name ) ) ? (string) getenv( $name ) : '';
$bc_cr_checkout = $bc_cr_page( 'BC_CR_CHECKOUT_PATH' );
$bc_cr_account  = $bc_cr_page( 'BC_CR_ACCOUNT_PATH' );
$bc_cr_ok = 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && '' !== $bc_cr_checkout && '' !== $bc_cr_account && (
	( [ 'key' ] === $bc_cr_keys && 1 === preg_match( '#\A' . preg_quote( $bc_cr_checkout, '#' ) . 'order-received/[1-9][0-9]*/\z#', $bc_cr_path ) )
	|| ( [] === $bc_cr_keys && 1 === preg_match( '#\A' . preg_quote( $bc_cr_account, '#' ) . 'view-order/[1-9][0-9]*/\z#', $bc_cr_path ) )
);
if ( ! $bc_cr_ok ) {
	bc_cr_refuse( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) . ' ' . (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
}

$bc_cr_host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
$bc_cr_site = (int) getenv( 'BC_CR_SITE' );
if ( $bc_cr_site <= 4 || 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', (string) getenv( 'BC_CR_OWNER' ) ) ) {
	bc_cr_refuse( 'owner' );
}

function bc_cr_load_plugins(): void {
	foreach ( [ 'bingocommerce-core/bingocommerce-core.php', 'bingocommerce-catalog/bingocommerce-catalog.php', 'bingocommerce-sessions/bingocommerce-sessions.php' ] as $file ) {
		require_once ABSPATH . 'wp-content/plugins/' . $file;
	}
	add_filter( 'pre_wp_mail', '__return_true' );
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP.' ), PHP_INT_MAX );
}
$GLOBALS['wp_filter']['muplugins_loaded'][ PHP_INT_MIN ][] = [ 'function' => 'bc_cr_load_plugins', 'accepted_args' => 0 ];

define( 'WP_USE_THEMES', true );
require __DIR__ . '/fixtures/isolated-multisite.php';

$bc_cr_hall = get_site( get_current_blog_id() );
if ( get_current_blog_id() !== $bc_cr_site || ! $bc_cr_hall instanceof WP_Site || strtolower( $bc_cr_hall->domain ) !== $bc_cr_host
	|| (string) getenv( 'BC_CR_OWNER' ) !== (string) get_option( 'bc_checkout_recovery_test_owner' ) ) {
	bc_cr_refuse( 'Hall ownership' );
}

wp();
require ABSPATH . WPINC . '/template-loader.php';
