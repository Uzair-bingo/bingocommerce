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
