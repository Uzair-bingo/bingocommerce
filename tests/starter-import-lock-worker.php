<?php
/**
 * ADR-049 slice 3.1 worker: tries to take a starter import lock from a second
 * database connection and reports what happened as JSON.
 *
 * BC_WORKER_LOCK_KIND:
 *   - hall          take the Hall import lock for BC_WORKER_BLOG_ID, then release it;
 *   - publish       take the network publish lock, then release it;
 *   - hall-and-die  take the Hall import lock and exit WITHOUT releasing it, so
 *                   the harness can prove a dead request frees the lock.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

require __DIR__ . '/fixtures/isolated-multisite.php';

// The fixture activates no plugins, so the class is loaded explicitly.
require_once ABSPATH . 'wp-content/plugins/bingocommerce-catalog/src/StarterImportLock.php';

use BingoCommerce\Catalog\StarterImportLock;

$kind    = (string) getenv( 'BC_WORKER_LOCK_KIND' );
$blog_id = (int) getenv( 'BC_WORKER_BLOG_ID' );

if ( 'publish' === $kind ) {
	$lock = StarterImportLock::acquire_publish();
} elseif ( in_array( $kind, [ 'hall', 'hall-and-die' ], true ) && $blog_id > 0 ) {
	$lock = StarterImportLock::acquire_hall( $blog_id );
} else {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

if ( ! $lock instanceof StarterImportLock ) {
	echo wp_json_encode(
		[
			'acquired' => false,
			'code'     => $lock instanceof WP_Error ? $lock->get_error_code() : 'unknown',
		]
	);
	exit( 0 );
}

$held = $lock->is_still_held();
if ( 'hall-and-die' !== $kind ) {
	$lock->release();
}

echo wp_json_encode( [ 'acquired' => true, 'code' => $held ? 'held' : 'unconfirmed' ] );
exit( 0 );
