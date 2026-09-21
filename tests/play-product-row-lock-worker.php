<?php

/**
 * Holds one InnoDB row lock on its own connection for a bounded time, so the
 * parent harness can exercise real NOWAIT contention against the exact rows the
 * ADR-032 Catalog transport locks.
 *
 * Prints "LOCKED" once the lock is held, then releases it by ROLLBACK.
 *
 * Invoked only by tests/play-products-increment-1.php.
 * Args: <site_id> <posts|play_product|game_type> <row_id> <hold_ms>
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
if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}

[ , $site_id, $target, $row_id, $hold_ms ] = $argv;

global $wpdb;
switch_to_blog( (int) $site_id );

switch ( $target ) {
	case 'posts':
		$table  = $wpdb->prefix . 'posts';
		$column = 'ID';
		break;
	case 'play_product':
		$table  = \BingoCommerce\Catalog\Migrations::play_products_table();
		$column = 'bingo_play_product_id';
		break;
	case 'game_type':
		$table  = \BingoCommerce\Catalog\Migrations::game_types_table();
		$column = 'game_type_id';
		break;
	default:
		fwrite( STDERR, "Unknown lock target.\n" );
		exit( 1 );
}

$connection = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$connection->begin_transaction();

$statement = $connection->prepare( "SELECT {$column} FROM `{$table}` WHERE {$column} = ? FOR UPDATE" );
$id        = (int) $row_id;
$statement->bind_param( 'i', $id );
$statement->execute();
$result = $statement->get_result();
$rows   = $result ? $result->num_rows : 0;
if ( $result ) {
	$result->free();
}
$statement->close();

echo "LOCKED {$rows}\n";
flush();

usleep( (int) $hold_ms * 1000 );

$connection->rollback();
$connection->close();
restore_current_blog();

echo "RELEASED\n";
