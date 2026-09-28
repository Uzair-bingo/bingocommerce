<?php
/** Isolated CGI worker: real GET/POST parsing for Woo's guest order verification. */
declare(strict_types=1);

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' )
	|| 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) || isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) ) {
	error_log( 'QR email request fixture refused.' ); exit( 1 );
}
if ( ! defined( 'STDERR' ) ) { define( 'STDERR', fopen( 'php://stderr', 'w' ) ); }
$request_host = $_SERVER['HTTP_HOST'] ?? '';
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$site_id = (int) getenv( 'BC_QR_EMAIL_SITE' ); $site = get_site( $site_id );
if ( $site_id <= 4 || ! $site || $site->domain !== $request_host
	|| ! wp_is_uuid( (string) getenv( 'BC_QR_EMAIL_OWNER' ), 4 )
	|| getenv( 'BC_QR_EMAIL_OWNER' ) !== get_blog_option( $site_id, 'bc_entitlement_test_owner' ) ) {
	error_log( 'QR email request Hall ownership refused.' ); exit( 1 );
}
switch_to_blog( $site_id );
try {
	$_SERVER['HTTP_HOST'] = $request_host; wp_set_current_user( 0 );
	$GLOBALS['wp_rewrite']->init(); WC()->query->init_query_vars(); WC()->query->add_endpoints();
	require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php';
	BingoCommerce\DigitalTickets\Plugin::init();
	WC()->initialize_session(); WC()->initialize_cart();
	add_filter( 'pre_wp_mail', '__return_true' );
	// Run WordPress's query parsing on the URL actually extracted from the email.
	wp();
	$id = (int) get_query_var( 'order-received' );
	if ( ! $id || ! is_page( wc_get_page_id( 'checkout' ) ) || 0 !== get_current_user_id() ) {
		throw new RuntimeException( 'Fixture URL did not reach its native checkout order-received endpoint.' );
	}
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'X-BC-Fixture-Guest: 0' );
	echo '<!doctype html><html><body>';
	// The disposable checkout page contains the real classic shortcode or the
	// installed Woo blockified confirmation template. No permission mocks.
	echo apply_filters( 'the_content', get_post_field( 'post_content', wc_get_page_id( 'checkout' ) ) );
	wp_print_footer_scripts();
	echo '</body></html>';
} finally {
	if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
	restore_current_blog();
}
