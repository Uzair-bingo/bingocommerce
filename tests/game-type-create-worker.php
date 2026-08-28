<?php

/**
 * Concurrent Game Type creation worker; invoked by game-types-increment-1.php.
 *
 * Prints exactly one line:
 *   <status>:<game_type_id>:<code>   when THIS process created the row
 *   ERROR:<code>                     when it did not
 *
 * Arguments are destructured AFTER wp-load on purpose: ms-settings.php assigns
 * WordPress globals including $site_id, so parsing earlier at file scope would
 * silently overwrite the target hall.
 */

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
		// A worker that did not create a row contributes NO owned id. The parent
		// must never infer ownership of a pre-existing row from the name it lost
		// the race on.
		echo 'ERROR:' . $result->get_error_code();
	} else {
		// status:<game_type_id>:<code> — the id is the ONLY ownership proof the
		// parent accepts, and it exists only because THIS process created it.
		echo $result->status . ':' . (int) $result->game_type['game_type_id'] . ':' . $result->game_type['code'];
	}
} finally {
	restore_current_blog();
}
