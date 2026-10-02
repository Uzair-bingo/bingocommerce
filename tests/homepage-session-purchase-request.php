<?php
/**
 * One real request against a disposable purchase-test Hall, for
 * tests/homepage-session-purchase.php and its browser check. php-cgi, never a
 * web server: the CGI SAPI parses the query string, cookies and body exactly as
 * a server SAPI would.
 *
 * THE HALL IS BOOTED DIRECTLY from HTTP_HOST, as tests/hall-commerce-access-request.php
 * boots its Halls (tests/homepage-session-calendar-request.php instead switches
 * after booting the main site, which would leave WooCommerce's session handler
 * bound to the main site's table). So WooCommerce builds its session and cart for
 * this Hall's own tables at `init`, loads the cart at `wp_loaded` with the
 * BingoCommerce cart hooks already registered, and the Store API, its nonce
 * check, HallCommerceGate and the Hall's own theme run in their genuine
 * lifecycle. The BingoCommerce plugins the fixture does not network-activate are
 * required at `muplugins_loaded`, so each Plugin::boot() registers on the
 * genuine `plugins_loaded`.
 *
 * Only these requests are served; anything else is refused before WordPress
 * boots. Nonce validation is never disabled.
 *   GET  /                                   homepage (bc_session, bc_date, bc_month)
 *   GET  /?post_type=product&s=<text>        WooCommerce's product search
 *   GET  <BC_HSP_CART_PATH>                  the fixture's cart page (Cart block or classic shortcode)
 *   GET  <BC_HSP_SHOP_PATH>                  the Shop (product archive)
 *   GET  <BC_HSP_CATEGORY_PATH>              the fixture's product category archive
 *   GET  /wp-json/wc/store/v1/cart           (or ?rest_route=, as rest_url() builds it)
 *   POST /wp-json/wc/store/v1/cart/extensions   JSON
 *   POST /wp-json/wc/store/v1/cart/add-item     JSON, merchandise seeding only
 *   POST /?wc-ajax=get_refreshed_fragments
 *
 * Environment: BC_TEST_DB=bingocommerce_state_recon BC_FIXTURE_CGI=1
 * BC_FIXTURE_WOOCOMMERCE=1 BC_HSP_SITE BC_HSP_OWNER BC_HSP_CART_PATH, optionally
 * BC_HSP_SHOP_PATH and BC_HSP_CATEGORY_PATH, plus the CGI request variables
 * (HTTP_HOST, REQUEST_URI, QUERY_STRING, REQUEST_METHOD, HTTP_COOKIE,
 * HTTP_NONCE, CONTENT_TYPE, CONTENT_LENGTH).
 */
declare(strict_types=1);

function bc_hsp_refuse( string $reason ): void {
	error_log( 'Purchase request fixture refused: ' . $reason );
	exit( 1 );
}

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' )
	|| '1' !== getenv( 'BC_FIXTURE_WOOCOMMERCE' ) || isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) ) {
	bc_hsp_refuse( 'environment' );
}

$bc_hsp_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
$bc_hsp_path   = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
$bc_hsp_keys   = array_keys( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
sort( $bc_hsp_keys );
$bc_hsp_value  = static fn( string $key ): string => is_string( $_GET[ $key ] ?? null ) ? (string) $_GET[ $key ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bc_hsp_json   = 0 === strpos( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), 'application/json' );
$bc_hsp_kind   = null;
$bc_hsp_route  = null;
if ( '/' === $bc_hsp_path && [ 'rest_route' ] === $bc_hsp_keys ) {
	$bc_hsp_route = $bc_hsp_value( 'rest_route' );
} elseif ( 0 === strpos( $bc_hsp_path, '/wp-json/' ) && [] === $bc_hsp_keys ) {
	$bc_hsp_route = substr( $bc_hsp_path, strlen( '/wp-json' ) );
}
if ( null !== $bc_hsp_route ) {
	if ( ( 'GET' === $bc_hsp_method && '/wc/store/v1/cart' === $bc_hsp_route )
		|| ( 'POST' === $bc_hsp_method && $bc_hsp_json && in_array( $bc_hsp_route, [ '/wc/store/v1/cart/extensions', '/wc/store/v1/cart/add-item' ], true ) ) ) {
		$bc_hsp_kind = 'rest';
	}
} elseif ( '/' === $bc_hsp_path ) {
	if ( 'GET' === $bc_hsp_method && [] === array_diff( $bc_hsp_keys, [ 'bc_session', 'bc_date', 'bc_month' ] ) ) {
		$bc_hsp_kind = 'document';
	} elseif ( 'GET' === $bc_hsp_method && [ 'post_type', 's' ] === $bc_hsp_keys && 'product' === $bc_hsp_value( 'post_type' ) ) {
		$bc_hsp_kind = 'document';
	} elseif ( 'POST' === $bc_hsp_method && [ 'wc-ajax' ] === $bc_hsp_keys && 'get_refreshed_fragments' === $bc_hsp_value( 'wc-ajax' ) ) {
		$bc_hsp_kind = 'document';
	}
} elseif ( 'GET' === $bc_hsp_method && [] === $bc_hsp_keys
	&& in_array( $bc_hsp_path, array_values( array_filter( [ (string) getenv( 'BC_HSP_CART_PATH' ), (string) getenv( 'BC_HSP_SHOP_PATH' ), (string) getenv( 'BC_HSP_CATEGORY_PATH' ) ], static fn( string $path ): bool => 1 === preg_match( '#\A/[a-z0-9/-]*\z#', $path ) && '/' !== $path ) ), true ) ) {
	$bc_hsp_kind = 'document';
}
if ( null === $bc_hsp_kind ) {
	bc_hsp_refuse( $bc_hsp_method . ' ' . (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
}

$bc_hsp_host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
$bc_hsp_site = (int) getenv( 'BC_HSP_SITE' );
if ( $bc_hsp_site <= 4 || ! bc_hsp_is_uuid( (string) getenv( 'BC_HSP_OWNER' ) ) ) {
	bc_hsp_refuse( 'owner' );
}

/** wp_is_uuid() is not loaded yet: the same version-4 shape. */
function bc_hsp_is_uuid( string $value ): bool {
	return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
}

function bc_hsp_load_plugins(): void {
	foreach ( [ 'bingocommerce-core/bingocommerce-core.php', 'bingocommerce-catalog/bingocommerce-catalog.php', 'bingocommerce-sessions/bingocommerce-sessions.php' ] as $file ) {
		require_once ABSPATH . 'wp-content/plugins/' . $file;
	}
}
$GLOBALS['wp_filter']['muplugins_loaded'][ PHP_INT_MIN ][] = [ 'function' => 'bc_hsp_load_plugins', 'accepted_args' => 0 ];

if ( 'document' === $bc_hsp_kind ) {
	define( 'WP_USE_THEMES', true );
}

require __DIR__ . '/fixtures/isolated-multisite.php';

$bc_hsp_hall = get_site( get_current_blog_id() );
if ( get_current_blog_id() !== $bc_hsp_site || ! $bc_hsp_hall instanceof WP_Site || strtolower( $bc_hsp_hall->domain ) !== $bc_hsp_host
	|| (string) getenv( 'BC_HSP_OWNER' ) !== (string) get_option( 'bc_session_purchase_test_owner' ) ) {
	bc_hsp_refuse( 'Hall ownership' );
}
if ( has_filter( 'woocommerce_store_api_disable_nonce_check' ) ) {
	bc_hsp_refuse( 'nonce check disabled' );
}

// WP::main(), as index.php: REST requests are served from `parse_request`.
wp();
if ( 'document' === $bc_hsp_kind ) {
	require ABSPATH . WPINC . '/template-loader.php';
}
