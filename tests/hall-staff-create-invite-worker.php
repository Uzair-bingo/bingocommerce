<?php

/**
 * Concurrency worker for the Hall Staff Create/Invite harness.
 *
 * Runs one real HallStaffProvisioningService::create_and_assign() call, aligned
 * with a sibling process on a shared wall-clock start instant so the creation
 * lock is genuinely contended.
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

[ , $email, $display_name, $role, $start ] = $argv;

// Never send real mail from a worker.
add_filter( 'pre_wp_mail', '__return_true' );

switch_to_blog( 2 );
wp_set_current_user( 1 );

while ( microtime( true ) < (float) $start ) {
	usleep( 200 );
}

$result = \BingoCommerce\Core\HallStaffProvisioningService::create_and_assign( $email, $display_name, $role );

echo is_wp_error( $result ) ? $result->get_error_code() : $result->status;
