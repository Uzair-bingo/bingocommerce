<?php
/**
 * Isolated CGI request worker: real Application Password / REST dispatch.
 *
 * The parent owns fixture setup/cleanup. Required environment:
 * BC_TEST_DB=bingocommerce_state_recon, BC_FIXTURE_CGI=1,
 * BC_QR_REDEMPTION_OWNER=<owned Hall marker>, HTTP_AUTHORIZATION=Basic ...,
 * REQUEST_METHOD=POST, CONTENT_TYPE=application/json, CONTENT_LENGTH=<bytes>,
 * HTTP_HOST=fixture.bingocommerce.test,
 * REQUEST_URI=/wp-json/bingocommerce/v1/portal-halls/<id>/redemptions.
 * JSON comes from CGI stdin. No authentication globals or domain results mocked.
 * No web-server use, arbitrary route, SQL injection hook, setup or cleanup mode.
 */
declare(strict_types=1);

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' )
	|| 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' )
	|| isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) ) {
	error_log( 'QR redemption HTTP fixture refused.' ); exit( 1 );
}
if ( ! defined( 'STDERR' ) ) { define( 'STDERR', fopen( 'php://stderr', 'w' ) ); }
$bc_qr_fixture_path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
	|| 'fixture.bingocommerce.test' !== ( $_SERVER['HTTP_HOST'] ?? '' )
	|| ! is_string( $bc_qr_fixture_path ) || 1 !== preg_match( '#\A/wp-json(/bingocommerce/v1/portal-halls/([1-9][0-9]{0,18})/redemptions)\z#', $bc_qr_fixture_path, $bc_qr_fixture_route_parts )
	|| (string) (int) $bc_qr_fixture_route_parts[2] !== $bc_qr_fixture_route_parts[2]
	|| 1 !== preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', (string) getenv( 'BC_QR_REDEMPTION_OWNER' ) ) ) {
	error_log( 'QR redemption HTTP fixture request refused.' ); exit( 1 );
}
$bc_qr_fixture_rest_path = $bc_qr_fixture_route_parts[1];
$bc_qr_fixture_portal_hall = (int) $bc_qr_fixture_route_parts[2];

// Retained manual fixtures own their private logs/uploads as well as DB rows.
// The separate automated CGI harness does not supply this optional directory.
if ( false !== getenv( 'BC_QR_FIXTURE_DIR' ) ) {
	require __DIR__ . '/fixtures/qr-redemption-manual-state.php';
	$bc_qr_private_dir = QRManualState::directory();
	if ( QRManualState::owner( $bc_qr_private_dir ) !== getenv( 'BC_QR_REDEMPTION_OWNER' ) ) { exit( 1 ); }
	QRManualState::isolateWordPressFiles( $bc_qr_private_dir );
}

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
// The fixture guard independently verifies its allowlist and ownership sentinel;
// wp-config.php is never loaded, and development rows are never touched.
require __DIR__ . '/fixtures/isolated-multisite.php';
foreach ( [ 'core', 'catalog', 'sessions', 'digital-tickets' ] as $slug ) {
	require_once ABSPATH . 'wp-content/plugins/bingocommerce-' . $slug . '/bingocommerce-' . $slug . '.php';
}
BingoCommerce\Core\Plugin::init();
BingoCommerce\Catalog\Plugin::init();
BingoCommerce\Sessions\Plugin::init();
BingoCommerce\DigitalTickets\Plugin::init();

$mapping = ( new BingoCommerce\PortalIntegration\HallMappingRepository() )->findForRedemption( $bc_qr_fixture_portal_hall );
if ( $mapping instanceof WP_Error || ( is_array( $mapping ) && (
	(int) $mapping['blog_id'] <= 4
	|| (string) getenv( 'BC_QR_REDEMPTION_OWNER' ) !== get_blog_option( (int) $mapping['blog_id'], 'bc_qr_redemption_fixture_owner' )
) ) ) {
	error_log( 'QR redemption HTTP fixture Hall ownership refused.' ); exit( 1 );
}

// Match only the fixture-authorized path. WordPress performs parsing, Basic auth,
// REST argument/permission callbacks and HTTP response generation itself.
// HTTPS is supplied by the parent CGI environment, as by a TLS web server.
$GLOBALS['wp']->parse_request( [ 'rest_route' => $bc_qr_fixture_rest_path ] );
// A genuine REST request exits from rest_api_loaded(); reaching here is failure.
error_log( 'QR redemption HTTP fixture did not reach REST dispatch.' );
exit( 1 );
