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

require dirname( __DIR__ ) . '/wp-load.php';
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
