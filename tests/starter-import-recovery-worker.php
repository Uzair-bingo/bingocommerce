<?php
/**
 * ADR-049 slice 3.2 worker: runs one import step for a Hall in its own process
 * and CRASHES (exit 9) at a chosen point, the way a dying web request would.
 * PHP's exit skips `finally` blocks, so nothing tidies up after the crash; the
 * database connection closes, which frees the Hall import lock.
 *
 * BC_WORKER_HALL        disposable Hall ID
 * BC_WORKER_USER        user to act as
 * BC_WORKER_ACTION      start | process
 * BC_WORKER_CRASH_STEP  an importer checkpoint step, or "insert" to crash
 *                       inside WooCommerce's save right after the product row
 *                       is inserted (before WooCommerce returns its ID)
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\StarterImporter;

$hall   = (int) getenv( 'BC_WORKER_HALL' );
$user   = (int) getenv( 'BC_WORKER_USER' );
$action = (string) getenv( 'BC_WORKER_ACTION' );
$step   = (string) getenv( 'BC_WORKER_CRASH_STEP' );

if ( $hall < 5 || $user < 1 || '' === $step || ! in_array( $action, [ 'start', 'process' ], true ) ) {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

wp_set_current_user( $user );
switch_to_blog( $hall );

if ( 'insert' === $step ) {
	add_action(
		'wp_insert_post',
		static function ( $post_id, $post, $update ): void {
			if ( ! $update && $post instanceof WP_Post && 'product' === $post->post_type && str_starts_with( (string) $post->post_name, 'bcsi-' ) ) {
				exit( 9 );
			}
		},
		1,
		3
	);
} else {
	add_action(
		StarterImporter::CHECKPOINT_ACTION,
		static function ( string $reached ) use ( $step ): void {
			if ( $reached === $step ) {
				exit( 9 );
			}
		},
		10,
		1
	);
}

$importer = new StarterImporter();
$result   = 'start' === $action ? $importer->start() : $importer->process_next( 0.0 );

echo wp_json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : ( is_array( $result ) ? $result['status'] : null ) ] );
exit( 0 );
