<?php

/**
 * Ambiguous-commit worker for the Hall Staff Create/Invite harness.
 *
 * Runs one real Create/Invite while the parent renames the audit table. The
 * rename is queued behind the open transaction's metadata lock, so it lands
 * immediately after COMMIT: the assignment is durably committed but the
 * transport's post-commit witness read cannot resolve it, which is exactly the
 * ADR-025 ambiguous / ACK-loss outcome.
 *
 * Emits the real response as JSON, including whatever authentic tokens it
 * carries. Nothing is fabricated here.
 *
 * Not a standalone test: invoked only by tests/hall-staff-create-invite.php.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// These legacy suites may run only on the existing isolated fixture.
if ( 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "Isolated Hall Staff fixture required; development bootstrap is forbidden.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db
	|| DB_NAME !== 'bingocommerce_state_recon'
	|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) {
	throw new RuntimeException( 'Refusing Hall Staff tests: isolated database binding is not proven.' );
}
if ( 1 !== (int) username_exists( 'fixture_admin' ) || ! is_super_admin( 1 ) ) {
	throw new RuntimeException( 'The isolated fixture must provide fixture_admin as actor 1 for these unchanged legacy cases.' );
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

use BingoCommerce\Core\HallStaffProvisioningService;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\Roles;

[ , $email, $start ] = $argv;

add_filter( 'pre_wp_mail', '__return_true' );

global $wpdb;
$wpdb->suppress_errors( true );

switch_to_blog( 2 );
wp_set_current_user( 1 );

while ( microtime( true ) < (float) $start ) {
	usleep( 200 );
}

$result = HallStaffProvisioningService::create_and_assign( $email, 'Ambiguous', Roles::GAME_OPERATOR );

if ( $result instanceof HallStaffResult ) {
	echo wp_json_encode(
		[
			'status'       => $result->status,
			'retry_token'  => $result->retry_token,
			'operation_id' => $result->operation_id,
		]
	);
	exit( 0 );
}

$data = $result->get_error_data();
$data = is_array( $data ) ? $data : [];

echo wp_json_encode(
	[
		'status'               => $data['status'] ?? $result->get_error_code(),
		'code'                 => $result->get_error_code(),
		'retry_token'          => $data['retry_token'] ?? '',
		'creation_retry_token' => $data['creation_retry_token'] ?? '',
	]
);
