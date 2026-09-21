<?php

/**
 * Minimum isolated transaction fixture reproducing the ADR-033 / Package-writer
 * LOCKING SHAPE against a Play Product, for ADR-034 concurrency tests.
 *
 * No public Package writer exists in Stage A, so this worker performs only the
 * lock sequence a future Package composition writer will perform — lock the
 * bingo_play_products row, then insert or delete a bingo_package_components
 * reference — using a raw mysqli transaction. It deliberately does NOT create
 * production Package writer behaviour.
 *
 * Prints READY once the Play Product row lock is held, then acts, then commits.
 *
 * Every VALUE is bound through a native prepared statement. The only
 * interpolated strings are table identifiers derived from the resolved site's
 * $wpdb->prefix plus Migrations class constants, re-validated against the
 * naming contract before use.
 *
 * Arguments are parsed into ONE namespaced array before wp-load, and the work
 * runs in a function scope, because ms-settings.php assigns WordPress globals
 * (including $site_id and $blog_id) that would otherwise silently overwrite a
 * parsed argument at file scope and point the fixture at the wrong hall.
 *
 * Invoked only by tests/package-increment-1-stage-a.php.
 * Args: <site_id> <play_product_id> <package_id> <insert|delete|none> <hold_ms>
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

function bc_pkga_fixture_fail( string $message ): void {
	fwrite( STDERR, "fixture worker: {$message}\n" );
	exit( 1 );
}

function bc_pkga_fixture_positive_int( string $value, string $label ): int {
	if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
		bc_pkga_fixture_fail( "{$label} must be a positive integer, received \"{$value}\"" );
	}

	return (int) $value;
}

/** @return array{site_id:int,play_product_id:int,package_id:int,action:string,hold_ms:int} */
function bc_pkga_fixture_parse( array $argv ): array {
	if ( 6 !== count( $argv ) ) {
		bc_pkga_fixture_fail( 'usage: <site_id> <play_product_id> <package_id> <insert|delete|none> <hold_ms>' );
	}

	$action = $argv[4];
	if ( ! in_array( $action, [ 'insert', 'delete', 'none' ], true ) ) {
		bc_pkga_fixture_fail( "action must be exactly insert, delete or none, received \"{$action}\"" );
	}

	if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', $argv[5] ) ) {
		bc_pkga_fixture_fail( "hold_ms must be a non-negative integer, received \"{$argv[5]}\"" );
	}

	return [
		'site_id'         => bc_pkga_fixture_positive_int( $argv[1], 'site_id' ),
		'play_product_id' => bc_pkga_fixture_positive_int( $argv[2], 'play_product_id' ),
		// package_id is only meaningful for the two component actions.
		'package_id'      => 'none' === $action ? 0 : bc_pkga_fixture_positive_int( $argv[3], 'package_id' ),
		'action'          => $action,
		'hold_ms'         => (int) $argv[5],
	];
}

/** @param array{site_id:int,play_product_id:int,package_id:int,action:string,hold_ms:int} $args */
function bc_pkga_fixture_run( array $args ): void {
	if ( ! get_site( $args['site_id'] ) instanceof WP_Site ) {
		bc_pkga_fixture_fail( "site_id {$args['site_id']} does not exist" );
	}

	switch_to_blog( $args['site_id'] );
	$play_products = \BingoCommerce\Catalog\Migrations::play_products_table();
	$components    = \BingoCommerce\Catalog\Migrations::package_components_table();
	restore_current_blog();

	foreach ( [ $play_products, $components ] as $identifier ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
			bc_pkga_fixture_fail( "derived table identifier \"{$identifier}\" is outside the naming contract" );
		}
	}

	$play_product_id = $args['play_product_id'];
	$package_id      = $args['package_id'];

	$connection = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$connection->query( 'SET SESSION innodb_lock_wait_timeout=10' );
	$connection->begin_transaction();

	// rank 1: the Play Product row — the shared serialization anchor.
	$lock = $connection->prepare( "SELECT bingo_play_product_id, medium FROM `{$play_products}` WHERE bingo_play_product_id = ? FOR UPDATE" );
	if ( false === $lock ) {
		bc_pkga_fixture_fail( "could not prepare the anchor lock against {$play_products}" );
	}
	$lock->bind_param( 'i', $play_product_id );
	$lock->execute();
	$result = $lock->get_result();
	$row    = $result ? $result->fetch_assoc() : null;
	if ( $result ) {
		$result->free();
	}
	$lock->close();

	echo 'READY ' . ( $row['medium'] ?? '?' ) . "\n";
	flush();

	usleep( $args['hold_ms'] * 1000 );

	$now = gmdate( 'Y-m-d H:i:s' );
	if ( 'insert' === $args['action'] ) {
		$statement = $connection->prepare(
			"INSERT INTO `{$components}` (package_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at)"
			. ' VALUES (?, ?, 1, 0, ?, ?)'
		);
		$statement->bind_param( 'iiss', $package_id, $play_product_id, $now, $now );
		$statement->execute();
		$statement->close();
	} elseif ( 'delete' === $args['action'] ) {
		$statement = $connection->prepare( "DELETE FROM `{$components}` WHERE package_id = ? AND bingo_play_product_id = ?" );
		$statement->bind_param( 'ii', $package_id, $play_product_id );
		$statement->execute();
		$statement->close();
	}

	$connection->commit();

	$after = $connection->prepare( "SELECT medium FROM `{$play_products}` WHERE bingo_play_product_id = ?" );
	$after->bind_param( 'i', $play_product_id );
	$after->execute();
	$after_result = $after->get_result();
	$medium_after = $after_result ? ( $after_result->fetch_assoc()['medium'] ?? '?' ) : '?';
	if ( $after_result ) {
		$after_result->free();
	}
	$after->close();
	$connection->close();

	echo 'DONE ' . $medium_after . "\n";
}

$bc_pkga_fixture_args = bc_pkga_fixture_parse( $argv );

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
if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

bc_pkga_fixture_run( $bc_pkga_fixture_args );
