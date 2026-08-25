<?php

/** Concurrent Game Type creation worker; invoked by game-types-increment-1.php. */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__ ) . '/wp-load.php';
if ( ! class_exists( '\BingoCommerce\Catalog\GameTypeService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

[ , $site_id, $actor_id, $name, $start ] = $argv;

switch_to_blog( (int) $site_id );
try {
	wp_set_current_user( (int) $actor_id );
	while ( microtime( true ) < (float) $start ) {
		usleep( 200 );
	}

	$result = ( new \BingoCommerce\Catalog\GameTypeService() )->create( $name );
	if ( is_wp_error( $result ) ) {
		echo 'ERROR:' . $result->get_error_code();
	} else {
		echo $result->status . ':' . $result->game_type['code'];
	}
} finally {
	restore_current_blog();
}
