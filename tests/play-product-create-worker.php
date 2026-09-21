<?php

/**
 * Concurrent Bingo Play Product creation worker.
 *
 * Invoked only by tests/play-products-increment-1.php.
 * Args: <site_id> <actor_id> <wc_product_id> <medium> <faces> <game_type_id|0> <start_microtime>
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

/*
 * ADR-043 §16 amendment: routed through the shared fixture bootstrap.
 *
 * A WORKER IS ROUTED BECAUSE ITS PARENT IS. proc_open() hands the child the
 * parent's environment, so `BC_TEST_DB` arrives here already set; a worker
 * still bound to wp-load.php would race its parent across two different
 * databases and every concurrency assertion in the parent would be measuring
 * nothing. The parent creates disposable Multisite SITES, which is what makes
 * the fixture mandatory: `bingocommerce-sessions` is network-active, so each
 * wp_insert_site() provisions the CURRENT Sessions schema into whichever
 * database is booted.
 *
 * tests/fixtures/wordpress-bootstrap.php is fail-closed — it has no
 * development-database fallback — so running this file without `BC_TEST_DB`
 * stops with a guard message instead of reaching the development installation.
 */
/*
 * WooCommerce is network-active on the development installation, so it has
 * always been loaded here. The fixture loads it only on request, and this
 * suite uses WooCommerce product classes, so it asks for it explicitly.
 */
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( ! class_exists( '\BingoCommerce\Catalog\BingoPlayProductService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

[ , $site_id, $actor_id, $wc_product_id, $medium, $faces, $game_type_id, $start ] = $argv;

switch_to_blog( (int) $site_id );
try {
	wp_set_current_user( (int) $actor_id );
	while ( microtime( true ) < (float) $start ) {
		usleep( 100 );
	}

	$began  = microtime( true );
	$result = ( new \BingoCommerce\Catalog\BingoPlayProductService() )->create(
		(int) $wc_product_id,
		$medium,
		(int) $faces,
		( (int) $game_type_id ) > 0 ? (int) $game_type_id : null
	);

	echo wp_json_encode(
		[
			'status'   => is_wp_error( $result ) ? $result->get_error_code() : $result->status,
			'id'       => is_wp_error( $result ) ? 0 : (int) $result->play_product['bingo_play_product_id'],
			'elapsed'  => round( microtime( true ) - $began, 4 ),
		]
	);
} finally {
	restore_current_blog();
}
