<?php

/**
 * Mutex worker for tests/portal-provisioning-foundation.php.
 *
 * Boots the isolated fixture, attempts to acquire ONE Hall's provisioning
 * mutex, prints the outcome as JSON, releases if it succeeded, and exits.
 *
 * NO COORDINATION SLEEP. The parent holds the mutex for this process's whole
 * lifetime, so the outcome is decided by the lock itself: refusal for the Hall
 * the parent holds, and success for any other Hall. Nothing here depends on
 * timing, and nothing here mutates a row, a site or a schema.
 *
 * Exit codes: 0 answered, 3 bootstrap/usage failure.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

require __DIR__ . '/fixtures/isolated-multisite.php';

// The fixture activates no plugins, so the definitions are loaded explicitly —
// exactly as the harness does.
require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

$portal_hall_id = (int) getenv( 'BC_WORKER_PORTAL_HALL' );

if ( $portal_hall_id < 1 ) {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

$mutex = \BingoCommerce\PortalIntegration\ProvisioningMutex::acquire( $portal_hall_id );

if ( $mutex instanceof \BingoCommerce\PortalIntegration\ProvisioningMutex ) {
	$held = $mutex->isStillHeld();
	$mutex->release();

	echo wp_json_encode( [ 'acquired' => true, 'code' => $held ? 'held' : 'unconfirmed' ] );

	exit( 0 );
}

echo wp_json_encode(
	[
		'acquired' => false,
		'code'     => $mutex instanceof WP_Error ? $mutex->get_error_code() : 'unknown',
	]
);

exit( 0 );
