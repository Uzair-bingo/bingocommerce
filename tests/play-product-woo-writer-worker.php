<?php

/**
 * Performs a real WooCommerce product administration write against the same
 * wp_posts row the ADR-032 Catalog transport uses as its serialization anchor.
 *
 * Invoked only by tests/play-products-increment-1.php.
 * Args: <site_id> <actor_id> <wc_product_id> <save|trash|untrash|delete> <start_microtime>
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

[ , $site_id, $actor_id, $wc_product_id, $action, $start ] = $argv;

switch_to_blog( (int) $site_id );
try {
	wp_set_current_user( (int) $actor_id );
	while ( microtime( true ) < (float) $start ) {
		usleep( 100 );
	}

	$began   = microtime( true );
	$outcome = 'noop';

	switch ( $action ) {
		case 'save':
			$product = wc_get_product( (int) $wc_product_id );
			if ( $product instanceof WC_Product ) {
				$product->set_description( 'concurrent admin save ' . microtime( true ) );
				$product->save();
				$outcome = 'saved';
			}
			break;
		case 'trash':
			$outcome = wp_trash_post( (int) $wc_product_id ) ? 'trashed' : 'trash_failed';
			break;
		case 'untrash':
			$outcome = wp_untrash_post( (int) $wc_product_id ) ? 'untrashed' : 'untrash_failed';
			break;
		case 'delete':
			$outcome = wp_delete_post( (int) $wc_product_id, true ) ? 'deleted' : 'delete_failed';
			break;
	}

	echo wp_json_encode( [ 'outcome' => $outcome, 'elapsed' => round( microtime( true ) - $began, 4 ) ] );
} finally {
	restore_current_blog();
}
