<?php
/**
 * ADR-049 slices 3.2 and 3.3 worker: runs one import step for a Hall in its own process
 * and CRASHES (exit 9) at a chosen point, the way a dying web request would.
 * PHP's exit skips `finally` blocks, so nothing tidies up after the crash; the
 * database connection closes, which frees the Hall import lock.
 *
 * BC_WORKER_HALL        disposable Hall ID
 * BC_WORKER_USER        user to act as
 * BC_WORKER_ACTION      start | process
 * BC_WORKER_CRASH_STEP  an importer checkpoint step, or "insert",
 *                       "insert-variation" or "insert-attachment" to crash
 *                       inside the save right after that row is inserted
 *                       (before its ID is returned)
 * BC_WORKER_BUDGET      seconds for process_next (default 0: one unit of work)
 * BC_STARTER_DIR        frozen starter image folder for this test run
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

if ( '' !== (string) getenv( 'BC_STARTER_DIR' ) ) {
	define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', (string) getenv( 'BC_STARTER_DIR' ) );
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

$insert_types = [
	'insert'           => 'product',
	'insert-variation' => 'product_variation',
];

if ( 'insert-attachment' === $step ) {
	// Attachments return early from wp_insert_post() with "add_attachment",
	// after the row and its attached-file record are written.
	add_action(
		'add_attachment',
		static function ( $post_id ): void {
			if ( str_starts_with( (string) get_post_field( 'post_name', (int) $post_id ), 'bcsi-' ) ) {
				exit( 9 );
			}
		},
		1,
		1
	);
} elseif ( isset( $insert_types[ $step ] ) ) {
	$post_type = $insert_types[ $step ];
	add_action(
		'wp_insert_post',
		static function ( $post_id, $post, $update ) use ( $post_type ): void {
			if ( ! $update && $post instanceof WP_Post && $post_type === $post->post_type && str_starts_with( (string) $post->post_name, 'bcsi-' ) ) {
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
$result   = 'start' === $action ? $importer->start() : $importer->process_next( (float) getenv( 'BC_WORKER_BUDGET' ) );

echo wp_json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : ( is_array( $result ) ? $result['status'] : null ) ] );
exit( 0 );
