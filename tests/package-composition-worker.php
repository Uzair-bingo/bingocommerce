<?php

/**
 * Runs ONE ADR-033 replacePackageComposition() call in its own process, so the
 * Stage A suite can exercise two conforming composition writers against each
 * other on a real MySQL server.
 *
 * This is the transport machinery only. Stage A ships no PackageService, no
 * admin screen and no REST route, so nothing outside these tests can reach it.
 *
 * Every VALUE is bound by the transport's own prepared statements; this worker
 * only assembles a plan. Arguments are parsed into ONE namespaced array before
 * wp-load and the work runs in a function scope, because ms-settings.php
 * assigns WordPress globals (including $site_id) that would otherwise silently
 * overwrite a parsed argument at file scope.
 *
 * Args: <site_id> <package_id> <fingerprint> <components> <start_at_micros>
 *   components: "id:qty,id:qty" or the literal "empty"
 * Prints "READY", then one "OUTCOME <code>" line.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

function bc_pkga_composition_fail( string $message ): void {
	fwrite( STDERR, "composition worker: {$message}\n" );
	exit( 1 );
}

/** @return array{site_id:int,package_id:int,fingerprint:string,components:array,start_at:int} */
function bc_pkga_composition_parse( array $argv ): array {
	if ( 6 !== count( $argv ) ) {
		bc_pkga_composition_fail( 'usage: <site_id> <package_id> <fingerprint> <components> <start_at_micros>' );
	}

	if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $argv[1] ) ) {
		bc_pkga_composition_fail( 'site_id must be a positive integer' );
	}
	if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $argv[2] ) ) {
		bc_pkga_composition_fail( 'package_id must be a positive integer' );
	}
	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $argv[3] ) ) {
		bc_pkga_composition_fail( 'fingerprint must be a sha256 hex digest' );
	}
	if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', $argv[5] ) ) {
		bc_pkga_composition_fail( 'start_at_micros must be a non-negative integer' );
	}

	$components = [];
	if ( 'empty' !== $argv[4] ) {
		if ( 1 !== preg_match( '/^[0-9]+:[0-9]+(,[0-9]+:[0-9]+)*$/', $argv[4] ) ) {
			bc_pkga_composition_fail( 'components must be "id:qty[,id:qty...]" or "empty"' );
		}
		foreach ( explode( ',', $argv[4] ) as $pair ) {
			[ $id, $quantity ] = explode( ':', $pair );
			$components[]      = [ 'bingo_play_product_id' => (int) $id, 'quantity' => (int) $quantity ];
		}
	}

	return [
		'site_id'     => (int) $argv[1],
		'package_id'  => (int) $argv[2],
		'fingerprint' => $argv[3],
		'components'  => $components,
		'start_at'    => (int) $argv[5],
	];
}

/** @param array{site_id:int,package_id:int,fingerprint:string,components:array,start_at:int} $args */
function bc_pkga_composition_run( array $args ): void {
	if ( ! get_site( $args['site_id'] ) instanceof WP_Site ) {
		bc_pkga_composition_fail( "site_id {$args['site_id']} does not exist" );
	}

	switch_to_blog( $args['site_id'] );

	echo "READY\n";
	flush();

	if ( $args['start_at'] > 0 ) {
		$wait = $args['start_at'] - (int) ( microtime( true ) * 1000000 );
		if ( $wait > 0 && $wait < 10000000 ) {
			usleep( $wait );
		}
	}

	$outcome = \BingoCommerce\Catalog\CatalogTransactionTransport::replacePackageComposition(
		\BingoCommerce\Catalog\CatalogPackageCompositionPlan::forReplaceComposition(
			$args['site_id'],
			$args['package_id'],
			$args['fingerprint'],
			$args['components'],
			current_time( 'mysql', true )
		)
	);

	restore_current_blog();

	if ( is_wp_error( $outcome ) ) {
		echo 'OUTCOME ' . $outcome->get_error_code() . " -\n";

		return;
	}

	echo 'OUTCOME ok ' . ( $outcome->mutated ? 'mutated' : 'unchanged' ) . "\n";
}

$bc_pkga_composition_args = bc_pkga_composition_parse( $argv );

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__ ) . '/wp-load.php';
if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

bc_pkga_composition_run( $bc_pkga_composition_args );
