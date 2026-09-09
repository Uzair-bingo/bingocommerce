<?php

/**
 * Holds the Phase 3E per-Hall advisory lock on its own MySQL session for a
 * bounded time, so the parent harness can exercise real contention against the
 * exact lock PortalSyncService acquires before it fetches anything.
 *
 * Prints "LOCKED" once the lock is held, then releases it.
 *
 * Invoked only by tests/portal-snapshot-application.php.
 * Args: <site_id> <hold_ms>
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require dirname( __DIR__ ) . '/wp-load.php';
if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSyncLock' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}

[ , $site_id, $hold_ms ] = $argv;

$lock = \BingoCommerce\PortalIntegration\PortalSyncLock::acquire( (int) $site_id );
if ( is_wp_error( $lock ) ) {
	fwrite( STDERR, 'Worker could not acquire the Portal synchronization lock: ' . $lock->get_error_code() . "\n" );
	exit( 1 );
}

echo "LOCKED\n";
flush();

usleep( (int) $hold_ms * 1000 );

$lock->release();

echo "RELEASED\n";
