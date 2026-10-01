<?php
/**
 * One real homepage GET for tests/homepage-session-calendar.php and its browser
 * check. php-cgi, never a web server: the CGI SAPI parses QUERY_STRING into
 * $_GET exactly as a server SAPI would, WordPress parses the request with wp(),
 * and the real template loader chooses and renders the BingoThemev1 template.
 *
 * Like tests/qr-email-view-request.php, the owned disposable Hall is switched
 * to after the fixture boots, so the theme is loaded here and HallCommerceGate's
 * `plugins_loaded` decision is not exercised by this worker.
 *
 * Environment: BC_TEST_DB=bingocommerce_state_recon BC_FIXTURE_CGI=1
 * BC_FIXTURE_WOOCOMMERCE=1 BC_HSC_SITE BC_HSC_OWNER, plus HTTP_HOST,
 * REQUEST_URI, QUERY_STRING, REQUEST_METHOD=GET, SCRIPT_NAME and PHP_SELF.
 * Fixture-only test doubles (environment only, unreachable over HTTP):
 *   BC_HSC_PROVIDER=absent|unusable  remove the provider / return a malformed response
 *   BC_HSC_ELIGIBLE=<json file>      replace ONLY the eligibility result; the
 *                                    real request parsing and resolve() still run
 */
declare(strict_types=1);

if ( 'cgi-fcgi' !== PHP_SAPI || '1' !== getenv( 'BC_FIXTURE_CGI' ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' )
	|| isset( $_SERVER['SERVER_SOFTWARE'] ) || isset( $_SERVER['REMOTE_ADDR'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	error_log( 'Session calendar request fixture refused.' );
	exit( 1 );
}
if ( ! defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}
// As index.php does: the template loader renders only when themes are in use.
define( 'WP_USE_THEMES', true );
$bc_request_host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Sessions\HomepageSessionCalendar;

$bc_site  = (int) getenv( 'BC_HSC_SITE' );
$bc_owner = (string) getenv( 'BC_HSC_OWNER' );
$bc_hall  = get_site( $bc_site );
if ( $bc_site <= 4 || ! $bc_hall instanceof WP_Site || $bc_hall->domain !== $bc_request_host
	|| ! wp_is_uuid( $bc_owner, 4 ) || $bc_owner !== get_blog_option( $bc_site, 'bc_session_calendar_test_owner' ) ) {
	error_log( 'Session calendar request Hall ownership refused.' );
	exit( 1 );
}

switch_to_blog( $bc_site );
$_SERVER['HTTP_HOST'] = $bc_request_host;
wp_set_current_user( 0 );
// locate_template() caches the boot site's theme paths; re-point them at this Hall.
wp_set_template_globals();
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';
// after_setup_theme already ran for the boot site, so run the theme's own setup.
\BingoCommerce\BingoThemeV1\Theme::setup();
\BingoCommerce\BingoThemeV1\WooCommerce::support();
$GLOBALS['wp_rewrite']->init();

switch ( (string) getenv( 'BC_HSC_PROVIDER' ) ) {
	case 'absent':
		remove_filter( HomepageSessionCalendar::FILTER, [ HomepageSessionCalendar::class, 'provide' ] );
		break;
	case 'unusable':
		add_filter( HomepageSessionCalendar::FILTER, static fn() => [ 'version' => 1, 'status' => 'ok' ], 20 );
		break;
}
$bc_eligible_file = (string) getenv( 'BC_HSC_ELIGIBLE' );
if ( '' !== $bc_eligible_file ) {
	$bc_eligible = json_decode( (string) file_get_contents( $bc_eligible_file ), true );
	if ( ! is_array( $bc_eligible ) || ! is_string( $bc_eligible['today'] ?? null ) || ! is_array( $bc_eligible['eligible'] ?? null ) ) {
		error_log( 'Session calendar eligibility double unreadable.' );
		exit( 1 );
	}
	add_filter(
		HomepageSessionCalendar::FILTER,
		static function () use ( $bc_eligible ): array {
			$model = HomepageSessionCalendar::resolve( $bc_eligible['eligible'], $_GET, $bc_eligible['today'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_array( $model['session'] ?? null ) ) {
				$model['packages'] = [ 'status' => 'ok', 'items' => [] ];
			}
			return $model;
		},
		20
	);
}

add_filter(
	'template_include',
	static function ( $template ) {
		$registered = array_intersect( [ 'bc_date', 'bc_session', 'bc_month' ], $GLOBALS['wp']->public_query_vars );
		header( 'X-BC-Template: ' . basename( (string) $template ) );
		header( 'X-BC-Front: ' . ( is_front_page() ? '1' : '0' ) );
		header( 'X-BC-Registered-Vars: ' . ( $registered ? implode( ',', $registered ) : 'none' ) );
		header( 'X-BC-Fixture-Guest: ' . get_current_user_id() );
		return $template;
	},
	PHP_INT_MAX
);

// The front controller's own tail (wp-blog-header.php).
wp();
require ABSPATH . WPINC . '/template-loader.php';
