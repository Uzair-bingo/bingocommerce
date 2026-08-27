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

require dirname( __DIR__ ) . '/wp-load.php';

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
