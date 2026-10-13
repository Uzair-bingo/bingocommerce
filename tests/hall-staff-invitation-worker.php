<?php

/**
 * Invitation-concurrency worker for the Hall Staff Create/Invite harness.
 *
 * Two of these are launched on a shared wall-clock start instant so the
 * invitation issuance lock is genuinely contended. Prints SENT, SUPPRESSED, or
 * an error code so the parent can count actual deliveries.
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

use BingoCommerce\Core\HallStaffInvitation;
use BingoCommerce\Core\HallStaffProvisioningContext;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\Roles;

[ , $mode, $user_id, $start ] = $argv;
$user_id = (int) $user_id;

$sent = 0;
add_filter(
	'pre_wp_mail',
	static function () use ( &$sent ) {
		$sent++;
		return true;
	}
);

switch_to_blog( 2 );
wp_set_current_user( 1 );

while ( microtime( true ) < (float) $start ) {
	usleep( 200 );
}

if ( 'auto' === $mode ) {
	$context = new HallStaffProvisioningContext( $user_id, get_current_network_id(), 2, 1, Roles::GAME_OPERATOR );
	$result  = HallStaffInvitation::maybe_send_automatic( $context, $user_id, HallStaffResult::COMMITTED_VERIFIED );
} else {
	$result = HallStaffInvitation::resend( $user_id );
}

if ( $sent > 0 ) {
	echo 'SENT';
} elseif ( is_wp_error( $result ) ) {
	echo 'ERR:' . $result->get_error_code();
} else {
	echo 'SUPPRESSED';
}
