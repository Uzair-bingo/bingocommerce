<?php

declare(strict_types=1);

/** One owned-fixture backend operation, synchronized by READY/GO over pipes. */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_test_scratch' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI required; reserved scratch refused.\n" );
	exit( 1 );
}
$bc_addon_args = json_decode( $argv[1] ?? '', true, 32, JSON_THROW_ON_ERROR );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;

function bc_addon_worker( array $args ): void {
	global $wpdb;
	$site = $args['site'] ?? 0;
	if ( ! is_int( $site ) || $site <= 4 || ! is_string( $args['token'] ?? null ) || $args['token'] !== get_site_meta( $site, '_bc_package_addon_backend_owner', true ) || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
		throw new RuntimeException( 'Worker fixture ownership guard refused operation.' );
	}
	switch_to_blog( $site );
	wp_set_current_user( (int) $args['actor'] );
	$operation = $args['operation'];
	$values = $args['values'];
	if ( str_starts_with( $operation, 'hold_' ) ) {
		[ $table, $column ] = match ( $operation ) {
			'hold_package' => [ Migrations::packages_table(), 'package_id' ],
			'hold_play' => [ Migrations::play_products_table(), 'bingo_play_product_id' ],
			'hold_addon' => [ Migrations::package_addons_table(), 'package_addon_id' ],
			default => throw new RuntimeException( 'Unsupported lock fixture.' ),
		};
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) || ! is_int( $values[0] ) || ! is_int( $values[1] ) || $values[1] < 1 || $values[1] > 3000 ) { throw new RuntimeException( 'Invalid lock fixture.' ); }
		$connection = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$connection->begin_transaction();
		try {
			$statement = $connection->prepare( "SELECT {$column} FROM `{$table}` WHERE {$column} = ? FOR UPDATE" );
			$statement->bind_param( 'i', $values[0] );
			$statement->execute();
			if ( 1 !== $statement->get_result()->num_rows ) { throw new RuntimeException( 'Lock fixture target missing.' ); }
			$statement->close();
			echo "LOCKED\n"; flush();
			usleep( $values[1] * 1000 );
		} finally { $connection->rollback(); $connection->close(); restore_current_blog(); }
		echo "OUTCOME released\n";
		return;
	}
	echo "READY\n"; flush();
	if ( "GO\n" !== fgets( STDIN ) ) { throw new RuntimeException( 'Missing worker start signal.' ); }
	$addon = new PackageAddonService();
	$result = match ( $operation ) {
		'create' => $addon->create( ...$values ),
		'update' => $addon->update( ...$values ),
		'remove' => $addon->remove( ...$values ),
		'medium' => ( new BingoPlayProductService() )->updateMedium( ...$values ),
		'composition' => ( new PackageService() )->replaceComponents( ...$values ),
		'archive_package' => ( new PackageService() )->archive( ...$values ),
		'archive_play' => ( new BingoPlayProductService() )->archive( ...$values ),
		default => throw new RuntimeException( 'Unsupported worker operation.' ),
	};
	restore_current_blog();
	echo 'OUTCOME ' . ( $result instanceof WP_Error ? $result->get_error_code() : $result->status ) . "\n";
}
bc_addon_worker( $bc_addon_args );
