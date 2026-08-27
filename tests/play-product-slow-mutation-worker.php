<?php

/**
 * Runs one REAL ADR-032 Catalog transaction that is made slow by a temporary
 * BEFORE UPDATE trigger the parent harness installs. The transaction therefore
 * genuinely holds the bingo_play_products row lock while the parent races an
 * ordinary $wpdb writer against the same row.
 *
 * Invoked only by tests/play-products-increment-1.php.
 * Args: <site_id> <actor_id> <bingo_play_product_id> <game_type_id> <start_microtime> [assign|digital]
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

[ , $site_id, $actor_id, $play_product_id, $game_type_id, $start ] = $argv;
$mode = $argv[6] ?? 'assign';

switch_to_blog( (int) $site_id );
try {
	wp_set_current_user( (int) $actor_id );
	while ( microtime( true ) < (float) $start ) {
		usleep( 100 );
	}

	$began   = microtime( true );
	$service = new \BingoCommerce\Catalog\BingoPlayProductService();
	$result  = 'digital' === $mode
		? $service->updateMedium( (int) $play_product_id, 'digital', (int) $game_type_id )
		: $service->assignGameType( (int) $play_product_id, (int) $game_type_id );

	echo wp_json_encode(
		[
			'status'  => is_wp_error( $result ) ? $result->get_error_code() : $result->status,
			'elapsed' => round( microtime( true ) - $began, 4 ),
		]
	);
} finally {
	restore_current_blog();
}
