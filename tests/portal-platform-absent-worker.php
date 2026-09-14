<?php

/**
 * ONE PROCESS, NO `bingocommerce-platform`.
 *
 * ADR-042 says a suspend "MUST verify permanent site ownership using
 * `site_ownership_id` (three-fact agreement) before mutating the bound site's
 * Commerce state; `platform_unavailable` MUST NOT block safe suspension." The
 * only honest way to prove that an ABSENT platform component really is stepped
 * over is to run the suspend in a process where the component is absent —
 * `class_exists()` cannot be un-declared once a file has been required, so the
 * parent harness, which needs the plugin for every other case, cannot answer
 * this one.
 *
 * So this worker boots the SAME isolated fixture against the SAME database and
 * requires every plugin the parent does EXCEPT `bingocommerce-platform`, then
 * dispatches the suspend commands the parent hands it through the real REST
 * route, with real Application Password proof, and prints one JSON line.
 *
 * IT OWNS NOTHING AND CLEANS UP NOTHING. Every identity it uses — the Hall, the
 * site, the machine account, the operation row — was created by the parent
 * harness and is torn down by the parent harness. It creates no site, no user
 * and no table.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

/*
 * The same disposable, unroutable Portal configuration the parent defines, so
 * the plugin loads under identical conditions. Never read from wp-config.php.
 */
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'http://portal.fixture.invalid' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'fixture-disposable-token-0000000000000000' );

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-core/bingocommerce-core.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
// DELIBERATELY ABSENT: wp-content/plugins/bingocommerce-platform.

use BingoCommerce\PortalIntegration\PlatformGateway;

$report = [
	/*
	 * THE PRECONDITION, REPORTED RATHER THAN ASSUMED. If this is ever true the
	 * parent must treat every result below as meaningless: it would mean the
	 * plugin reached the process by some other route and the case did not run.
	 */
	'platform_loaded'    => class_exists( '\BingoCommerce\Platform\HallSiteAddress', false ),
	'resolver_installed' => PlatformGateway::resolverInstalled(),
	'dispatches'         => [],
];

$hall    = (int) getenv( 'BC_ABSENT_HALL' );
$machine = (int) getenv( 'BC_ABSENT_MACHINE' );

if ( $hall > 0 && $machine > 0 ) {
	/* The machine account the parent created, with its Application Password proof. */
	wp_set_current_user( 0 );
	wp_set_current_user( $machine );
	$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
	$GLOBALS['wp_rest_application_password_status'] = get_userdata( $machine );

	do_action( 'rest_api_init', rest_get_server() );

	foreach ( [ 'BC_ABSENT_PAYLOAD_RETRY', 'BC_ABSENT_PAYLOAD_NEW' ] as $slot ) {
		$raw = (string) getenv( $slot );
		if ( '' === $raw ) {
			continue;
		}

		$request = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $raw );

		$response = rest_get_server()->dispatch( $request );

		$report['dispatches'][ $slot ] = [
			'status' => (int) $response->get_status(),
			'body'   => $response->get_data(),
		];
	}
}

fwrite( STDOUT, (string) wp_json_encode( $report ) . "\n" );
