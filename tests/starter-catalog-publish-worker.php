<?php
/**
 * ADR-049 slice 4.2 worker: runs one Publish step in its own process and
 * CRASHES (exit 9) at a chosen checkpoint, the way a dying web request would.
 * PHP's exit skips `finally` blocks; the database connection closes, which
 * frees the publish lock.
 *
 * BC_WORKER_USER        user to act as
 * BC_WORKER_ACTION      start | continue | discard
 * BC_WORKER_CRASH_STEP  a publisher checkpoint step, or empty for no crash
 * BC_WORKER_BUDGET      seconds for continue_build (default 0: one product)
 * BC_WORKER_VERSION     version ID for discard
 * BC_STARTER_DIR        frozen starter image folder for this test run
 * BC_MAIN_UPLOADS       the main site's upload folder for this test run
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 3 );
}

if ( '' !== (string) getenv( 'BC_STARTER_DIR' ) ) {
	define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', (string) getenv( 'BC_STARTER_DIR' ) );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\StarterCatalogPublisher;

$user   = (int) getenv( 'BC_WORKER_USER' );
$action = (string) getenv( 'BC_WORKER_ACTION' );
$step   = (string) getenv( 'BC_WORKER_CRASH_STEP' );
$main   = (string) getenv( 'BC_MAIN_UPLOADS' );

if ( $user < 1 || '' === $main || ! in_array( $action, [ 'start', 'continue', 'discard' ], true ) ) {
	fwrite( STDERR, "worker: incomplete instructions\n" );
	exit( 3 );
}

add_filter(
	'upload_dir',
	static function ( array $dirs ) use ( $main ): array {
		if ( (int) get_current_blog_id() !== (int) get_main_site_id() ) {
			return $dirs;
		}
		$dirs['basedir'] = $main;
		$dirs['baseurl'] = 'https://uploads.invalid';
		$dirs['path']    = $main . $dirs['subdir'];
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}
);

wp_set_current_user( $user );
switch_to_blog( get_main_site_id() );

if ( '' !== $step ) {
	add_action(
		StarterCatalogPublisher::CHECKPOINT_ACTION,
		static function ( string $reached ) use ( $step ): void {
			if ( $reached === $step ) {
				exit( 9 );
			}
		},
		10,
		1
	);
}

$publisher = new StarterCatalogPublisher();
$result    = match ( $action ) {
	'start'    => $publisher->start(),
	'continue' => $publisher->continue_build( (float) getenv( 'BC_WORKER_BUDGET' ) ),
	'discard'  => $publisher->discard( (int) getenv( 'BC_WORKER_VERSION' ) ),
};

echo wp_json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : ( is_array( $result ) ? $result['status'] : null ) ] );
exit( 0 );
