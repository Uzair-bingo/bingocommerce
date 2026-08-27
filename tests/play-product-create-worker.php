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

require dirname( __DIR__ ) . '/wp-load.php';
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
