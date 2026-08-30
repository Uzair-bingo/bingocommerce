<?php

/**
 * Concurrent Bingo Package creation worker; invoked by
 * package-increment-1-stage-b.php.
 *
 * Runs ONE PackageService::create() in its own process so the suite can put two
 * real conforming writers against each other on a real MySQL server.
 *
 * Arguments are parsed into ONE namespaced array before wp-load and the work
 * runs in a function scope, because ms-settings.php assigns WordPress globals
 * (including $site_id) that would otherwise silently overwrite a parsed
 * argument at file scope and point the worker at the wrong hall.
 *
 * Args: <site_id> <actor_id> <wc_product_id> <components> <start_at_micros>
 *   components: "id:qty[,id:qty...]"
 * Prints "READY", then one "OUTCOME <code>[ <type>]" line.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

function bc_pkgb_fail( string $message ): void {
	fwrite( STDERR, "package create worker: {$message}\n" );
	exit( 1 );
}

/** @return array{site_id:int,actor_id:int,wc_product_id:int,components:array,start_at:int} */
function bc_pkgb_parse( array $argv ): array {
	if ( 6 !== count( $argv ) ) {
		bc_pkgb_fail( 'usage: <site_id> <actor_id> <wc_product_id> <components> <start_at_micros>' );
	}

	foreach ( [ 1 => 'site_id', 2 => 'actor_id', 3 => 'wc_product_id' ] as $index => $label ) {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $argv[ $index ] ) ) {
			bc_pkgb_fail( "{$label} must be a positive integer" );
		}
	}
	if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', $argv[5] ) ) {
		bc_pkgb_fail( 'start_at_micros must be a non-negative integer' );
	}
	if ( 1 !== preg_match( '/^[0-9]+:[0-9]+(,[0-9]+:[0-9]+)*$/', $argv[4] ) ) {
		bc_pkgb_fail( 'components must be "id:qty[,id:qty...]"' );
	}

	$components = [];
	foreach ( explode( ',', $argv[4] ) as $pair ) {
		[ $id, $quantity ] = explode( ':', $pair );
		$components[]      = [ 'bingo_play_product_id' => (int) $id, 'quantity' => (int) $quantity ];
	}

	return [
		'site_id'       => (int) $argv[1],
		'actor_id'      => (int) $argv[2],
		'wc_product_id' => (int) $argv[3],
		'components'    => $components,
		'start_at'      => (int) $argv[5],
	];
}

/** @param array{site_id:int,actor_id:int,wc_product_id:int,components:array,start_at:int} $args */
function bc_pkgb_run( array $args ): void {
	if ( ! get_site( $args['site_id'] ) instanceof WP_Site ) {
		bc_pkgb_fail( "site_id {$args['site_id']} does not exist" );
	}

	switch_to_blog( $args['site_id'] );
	wp_set_current_user( $args['actor_id'] );

	echo "READY\n";
	flush();

	if ( $args['start_at'] > 0 ) {
		$wait = $args['start_at'] - (int) ( microtime( true ) * 1000000 );
		if ( $wait > 0 && $wait < 10000000 ) {
			usleep( $wait );
		}
	}

	$result = ( new \BingoCommerce\Catalog\PackageService() )->create( $args['wc_product_id'], $args['components'] );

	restore_current_blog();

	if ( is_wp_error( $result ) ) {
		echo 'OUTCOME ' . $result->get_error_code() . "\n";

		return;
	}

	echo 'OUTCOME ' . $result->status . ' ' . $result->package['package_type'] . "\n";
}

$bc_pkgb_args = bc_pkgb_parse( $argv );

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__ ) . '/wp-load.php';
if ( ! class_exists( '\BingoCommerce\Catalog\PackageService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

bc_pkgb_run( $bc_pkgb_args );
