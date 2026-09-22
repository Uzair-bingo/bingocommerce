<?php

/**
 * CLI integration coverage for Phase 3C1 Portal Game Type identity persistence.
 *
 * Run from the repository root:
 * php tests/portal-game-type-identity-persistence.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

/*
 * ADR-043 §16 amendment: routed through the shared fixture bootstrap.
 *
 * This suite creates disposable Multisite SITES. `bingocommerce-sessions` is
 * network-active, so every wp_insert_site() fires its `wp_initialize_site`
 * handler and applies the CURRENT Sessions schema — from v3, three new tables
 * and seven new `bingo_sessions` columns — to whichever database is booted.
 * Against the development installation that would write v3 structure into the
 * development database, which ADR-043's rollout forbids outside a deliberate,
 * verified migration.
 *
 * tests/fixtures/wordpress-bootstrap.php is fail-closed: it has no
 * development-database fallback, so this harness cannot be run against the
 * development installation by forgetting an environment variable. Without
 * `BC_TEST_DB` it stops with a guard message; with it, it boots the
 * allowlisted, sentinel-owned fixture.
 */
/*
 * WooCommerce is network-active on the development installation, so it has
 * always been loaded here. The fixture loads it only on request, and this
 * suite uses WooCommerce product classes, so it asks for it explicitly.
 */
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! class_exists( '\BingoCommerce\Catalog\GameTypeService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}

use BingoCommerce\Catalog\GameTypeResult;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations;

final class PortalGameTypeIdentityPersistenceTest {

	private const PERSISTENT_HALLS = [ 2, 3, 4 ];

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> */
	private array $deleted_site_tables = [];

	private int $assertions = 0;
	private int $super_admin_id = 0;

	/** @var array<string,string> */
	private array $persistent_domain_snapshot = [];

	/** @var array<string,string> */
	private array $persistent_woo_snapshot = [];

	/** @var array<int,array<string,mixed>> */
	private array $mapping_snapshot = [];

	/** @var array<int,string> */
	private array $portal_session_template_baseline = [];

	/** @var array<string,string> */
	private array $portal_session_template_row_baseline = [];

	/** @var array<string,string> */
	private array $portal_session_template_schema_baseline = [];

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->assert_same( 6, Migrations::DB_VERSION, 'Catalog v6 retains v5 name compatibility' );
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );

		$this->persistent_domain_snapshot = $this->snapshot_tables( self::PERSISTENT_HALLS, $this->domain_table_suffixes() );
		$this->persistent_woo_snapshot    = $this->snapshot_tables( self::PERSISTENT_HALLS, $this->woo_table_suffixes() );
		$this->mapping_snapshot           = $this->snapshot_mappings();

		// Phase 3C2 legitimately owns per-site Portal Session-template replicas, so
		// this suite baselines the replicas that already exist instead of asserting
		// their absence. Phase 3C1 still owns nothing in that table.
		$this->portal_session_template_baseline        = $this->portal_session_template_tables();
		$this->portal_session_template_row_baseline    = $this->hash_tables( $this->portal_session_template_baseline );
		$this->portal_session_template_schema_baseline = $this->schema_snapshot( $this->portal_session_template_baseline );
		$this->assert_same(
			$this->portal_session_template_baseline,
			array_keys( $this->portal_session_template_row_baseline ),
			'every pre-existing Portal Session-template table is captured in the baseline'
		);

		try {
			$this->upgrade_existing_v4_names();
			$this->upgrade_existing_v3_hall();
			$this->new_site_provisioning();
		} finally {
			$this->cleanup();
		}

		$this->assert_same(
			$this->persistent_domain_snapshot,
			$this->snapshot_tables( self::PERSISTENT_HALLS, $this->domain_table_suffixes() ),
			'Sunrise, Tampa, and Orlando domain rows are byte-identical after the suite'
		);
		$this->assert_same(
			$this->persistent_woo_snapshot,
			$this->snapshot_tables( self::PERSISTENT_HALLS, $this->woo_table_suffixes() ),
			'Sunrise, Tampa, and Orlando WooCommerce product/order data is byte-identical after the suite'
		);
		$this->assert_same( $this->mapping_snapshot, $this->snapshot_mappings(), 'Portal Hall mapping rows are byte-identical after the suite' );
		$this->assert_same( $this->portal_session_template_baseline, $this->portal_session_template_tables(), 'Phase 3C1 creates and removes no Portal Session-template table' );
		$this->assert_same( $this->portal_session_template_row_baseline, $this->hash_tables( $this->portal_session_template_baseline ), 'pre-existing Portal Session-template rows are byte-identical after the suite' );
		$this->assert_same( $this->portal_session_template_schema_baseline, $this->schema_snapshot( $this->portal_session_template_baseline ), 'pre-existing Portal Session-template schemas are unchanged after the suite' );
		$this->assert_same( [], $this->disposable_sites, 'every disposable hall created by this run was destroyed' );
		$this->verify_disposable_cleanup();

		echo "PASS: {$this->assertions} Phase 3C1 Portal Game Type identity persistence assertions.\n";
	}

	/** Catalog v5 fixtures are explicit SQL setup, not Portal importing. */
	private function upgrade_existing_v4_names(): void {
		global $wpdb;
		$site_id = $this->create_disposable_site( 'Names v4' );
		switch_to_blog( $site_id );
		try {
			$table = Migrations::game_types_table();
			$this->disposable_ddl( $site_id, "ALTER TABLE `{$table}` DROP INDEX local_name_unique, DROP INDEX name_id, DROP COLUMN local_name_key, ADD UNIQUE KEY name_unique (name), ADD KEY preserved_status (status), MODIFY name varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" );
			update_option( Migrations::VERSION_OPTION, 4, false );
			$now = '2026-09-01 12:00:00';
			foreach ( [ [ null, 'Local Reserved', 'active' ], [ null, '  Café  Archived  ', 'archived' ], [ 17, 'Portal Original', 'active' ] ] as $i => [ $portal_id, $name, $status ] ) {
				$this->assert_same( 1, $wpdb->insert( $table, [
					'portal_game_type_id' => $portal_id, 'code' => 'v4-name-' . $i,
					'name' => $name, 'status' => $status, 'created_at' => $now,
					'updated_at' => $now, 'archived_at' => 'archived' === $status ? $now : null,
				] ), 'v4 local/linked fixture inserted' );
			}
			$before = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY game_type_id", ARRAY_A );
			$play = Migrations::play_products_table();
			$this->assert_same( 1, $wpdb->insert( $play, [
				'wc_product_id' => 987654321, 'medium' => 'digital', 'faces_per_unit' => 6,
				'game_type_id' => $before[0]['game_type_id'], 'status' => 'active',
				'created_at' => $now, 'updated_at' => $now,
			] ), 'existing Play Product references the v4 local ID' );
			$protected = $this->snapshot_current_site_tables( [ 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_sessions', 'bingo_session_packages' ] );

			// Simulate a DROP that did not take effect: version advancement must fail.
			$block_drop = static fn( string $sql ): string => str_contains( $sql, "ALTER TABLE `{$table}` DROP INDEX `name_unique`" ) ? 'SELECT 1' : $sql;
			add_filter( 'query', $block_drop );
			try {
				$failed = Migrations::migrate_current_site();
			} finally {
				remove_filter( 'query', $block_drop );
			}
			$this->assert_true( is_wp_error( $failed ), 'a retained legacy index fails final verification' );
			$this->assert_same( 4, (int) get_option( Migrations::VERSION_OPTION ), 'failed final verification does not advance v4' );
			$this->assert_same( $before[0]['name'], $wpdb->get_var( "SELECT local_name_key FROM `{$table}` WHERE code = 'v4-name-0'" ), 'replacement key is backfilled before legacy index removal' );
			$this->assert_same( 1, count( $this->name_index( $table, 'name_unique' ) ), 'failed DROP leaves the legacy index intact' );
			$this->assert_true( true === Migrations::migrate_current_site(), 'interrupted v4 upgrade resumes successfully' );
			$this->assert_same( 6, (int) get_option( Migrations::VERSION_OPTION ), 'current Catalog version advances after complete verification' );
			$this->assert_v5_names( $table );
			$after = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY game_type_id", ARRAY_A );
			foreach ( $after as &$row ) {
				$this->assert_same( null === $row['portal_game_type_id'] ? $row['name'] : null, $row['local_name_key'], 'local names backfill exactly and linked keys remain NULL' );
				unset( $row['local_name_key'] );
			}
			unset( $row );
			$this->assert_same( $before, $after, 'all existing names, codes, IDs, statuses and timestamps survive byte-identically' );
			$this->assert_same( $protected, $this->snapshot_current_site_tables( [ 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_sessions', 'bingo_session_packages' ] ), 'all existing records and relationships remain unchanged' );
			$this->assert_same( 'status', $this->name_index( $table, 'preserved_status' )[0]['Column_name'], 'an unrelated existing index is preserved' );

			wp_set_current_user( $this->super_admin_id );
			$service = new GameTypeService( static fn( array $event ): int => 1 );
			$this->assert_same( 1, $wpdb->insert( $table, [
				'portal_game_type_id' => 18, 'code' => 'linked-second', 'name' => 'Portal Original',
				'local_name_key' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
			] ), 'two distinct Portal IDs can have identical names' );
			$linked_id = (int) $wpdb->insert_id;
			$local = $service->create( 'Portal Original' );
			$this->assert_true( $local instanceof GameTypeResult, 'local creation ignores duplicate linked names' );
			$this->assert_same( 'duplicate_name', $service->create( 'portal original' )->get_error_code(), 'local case-equivalent duplicates remain rejected' );
			$this->assert_same( 'duplicate_name', $service->create( 'Local Reserved' )->get_error_code(), 'backfilled local names remain reserved' );
			$this->assert_true( $service->rename( (int) $before[1]['game_type_id'], 'Café Archived' ) instanceof GameTypeResult, 'archived local rename remains supported' );
			$this->assert_same( 'duplicate_name', $service->create( 'Cafe Archived' )->get_error_code(), 'archived local names reserve accent-equivalent names' );
			$this->assert_same( 'duplicate_name', $service->rename( (int) $before[1]['game_type_id'], 'local reserved' )->get_error_code(), 'local rename cannot take another local name' );
			$this->assert_true( $service->rename( $linked_id, 'local reserved' ) instanceof GameTypeResult, 'linked rename can use a collation-equivalent local name' );
			$linked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE game_type_id = %d', $table, $linked_id ), ARRAY_A );
			$this->assert_same( null, $linked['local_name_key'], 'linked rename keeps its key NULL' );
			$this->assert_same( 'linked-second', $linked['code'], 'linked rename does not allocate or change a code' );
			$this->assert_true( true === Migrations::verify_game_types_schema(), 'create and rename maintain all v5 name-key invariants' );
			$rows = $this->snapshot_current_site_tables( [ 'bingo_game_types' ] );
			$schema = $this->schema_snapshot( [ $table ] );
			$this->assert_true( true === Migrations::migrate_current_site(), 'v5 rerun succeeds with duplicate linked/local names' );
			$this->assert_same( $rows, $this->snapshot_current_site_tables( [ 'bingo_game_types' ] ), 'v5 rerun preserves every row byte-for-byte' );
			$this->assert_same( $schema, $this->schema_snapshot( [ $table ] ), 'v5 rerun preserves all indexes and column definitions' );

			$this->disposable_ddl( $site_id, "ALTER TABLE `{$table}` ADD UNIQUE KEY name_unique (code)" );
			update_option( Migrations::VERSION_OPTION, 4, false );
			$this->assert_true( is_wp_error( Migrations::migrate_current_site() ), 'a name_unique index on the wrong column is never dropped' );
			$this->assert_same( 'code', $this->name_index( $table, 'name_unique' )[0]['Column_name'], 'the unexpected index remains intact' );
			$this->assert_same( 4, (int) get_option( Migrations::VERSION_OPTION ), 'unexpected index definition prevents version advancement' );
			$this->disposable_ddl( $site_id, "ALTER TABLE `{$table}` DROP INDEX name_unique" );
			$this->assert_true( true === Migrations::migrate_current_site(), 'migration recovers after disposable index repair' );
		} finally {
			restore_current_blog();
		}
	}

	private function assert_v5_names( string $table ): void {
		$column = $this->column( $table, 'local_name_key' );
		$this->assert_same( 'varchar(160)', $column['Type'], 'v5 local-name key has the exact width' );
		$this->assert_same( 'YES', $column['Null'], 'v5 local-name key is nullable' );
		$this->assert_same( null, $column['Default'], 'v5 local-name key defaults to NULL' );
		$this->assert_same( $this->column( $table, 'name' )['Collation'], $column['Collation'], 'local-name key inherits the name column collation' );
		$unique = $this->name_index( $table, 'local_name_unique' );
		$this->assert_same( [ 'local_name_key' ], array_column( $unique, 'Column_name' ), 'local-name uniqueness covers only its key' );
		$this->assert_same( 0, (int) $unique[0]['Non_unique'], 'local-name index is unique' );
		$this->assert_same( null, $unique[0]['Sub_part'], 'local-name uniqueness uses the full key' );
		$this->assert_same( [], $this->name_index( $table, 'name_unique' ), 'global name uniqueness is absent' );
		$names = $this->name_index( $table, 'name_id' );
		$this->assert_same( [ 'name', 'game_type_id' ], array_column( $names, 'Column_name' ), 'name ordering index has the exact ordered columns' );
		$this->assert_same( 1, (int) $names[0]['Non_unique'], 'name ordering index is nonunique' );
	}

	/** @return array<int,array<string,mixed>> */
	private function name_index( string $table, string $index ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ), ARRAY_A );
	}

	private function upgrade_existing_v3_hall(): void {
		global $wpdb;

		$site_id = $this->create_disposable_site( 'Upgrade' );
		$this->assert_true( $site_id > 4, 'upgrade fixture is a current-run disposable hall' );
		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		try {
			$game_types   = Migrations::game_types_table();
			$play_products = Migrations::play_products_table();

			$this->disposable_ddl( $site_id, "ALTER TABLE `{$game_types}` DROP INDEX `portal_game_type_unique`, DROP COLUMN `portal_game_type_id`, DROP INDEX `local_name_unique`, DROP INDEX `name_id`, DROP COLUMN `local_name_key`, ADD UNIQUE KEY `name_unique` (`name`)" );
			update_option( Migrations::VERSION_OPTION, 3, false );
			$this->assert_same( 3, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'fixture represents the prior Catalog v3 state' );
			$this->assert_same( null, $this->column( $game_types, 'portal_game_type_id' ), 'prior v3 fixture has no Portal identity column' );

			$now      = current_time( 'mysql', true );
			$inserted = $wpdb->insert(
				$game_types,
				[
					'code'        => 'phase-3c1-existing',
					'name'        => 'Phase 3C1 Existing',
					'status'      => 'archived',
					'created_at'  => $now,
					'updated_at'  => $now,
					'archived_at' => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			$this->assert_same( 1, $inserted, 'existing v3 Game Type fixture is inserted' );
			$game_type_id = (int) $wpdb->insert_id;

			$play_inserted = $wpdb->insert(
				$play_products,
				[
					'wc_product_id'  => random_int( 1000000000, 2000000000 ),
					'medium'         => 'digital',
					'faces_per_unit' => 6,
					'game_type_id'   => $game_type_id,
					'status'         => 'active',
					'created_at'     => $now,
					'updated_at'     => $now,
					'archived_at'    => null,
				],
				[ '%d', '%s', '%d', '%d', '%s', '%s', '%s', null ]
			);
			$this->assert_same( 1, $play_inserted, 'v3 Play Product fixture references the local Game Type identity' );
			$play_product_id = (int) $wpdb->insert_id;

			$game_before    = $wpdb->get_row( $wpdb->prepare( "SELECT game_type_id, code, name, status, created_at, updated_at, archived_at FROM `{$game_types}` WHERE game_type_id = %d", $game_type_id ), ARRAY_A );
			$play_before    = $wpdb->get_row( $wpdb->prepare( "SELECT bingo_play_product_id, game_type_id FROM `{$play_products}` WHERE bingo_play_product_id = %d", $play_product_id ), ARRAY_A );
			$protected_rows = $this->snapshot_current_site_tables( [ 'bingo_packages', 'bingo_package_components', 'bingo_sessions', 'bingo_session_packages' ] );
			$protected_ddl  = $this->schema_snapshot( [ Migrations::packages_table(), Migrations::package_components_table(), $wpdb->prefix . 'bingo_sessions', $wpdb->prefix . 'bingo_session_packages' ] );

			$this->assert_true( true === Migrations::migrate_current_site(), 'existing disposable hall upgrades from Catalog v3 to v6' );
			$this->assert_same( 6, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'v6 is recorded only after migration verification' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'complete Catalog v6 schema verifies' );
			$this->assert_portal_column_and_index( $game_types, 'upgraded hall' );

			$game_after = $wpdb->get_row( $wpdb->prepare( "SELECT game_type_id, code, name, status, created_at, updated_at, archived_at, portal_game_type_id FROM `{$game_types}` WHERE game_type_id = %d", $game_type_id ), ARRAY_A );
			$portal_id  = $game_after['portal_game_type_id'] ?? null;
			unset( $game_after['portal_game_type_id'] );
			$this->assert_same( $game_before, $game_after, 'every pre-existing Game Type field survives byte-identically' );
			$this->assert_same( null, $portal_id, 'existing Game Type remains unlinked with a null Portal identity' );
			$this->assert_same(
				$play_before,
				$wpdb->get_row( $wpdb->prepare( "SELECT bingo_play_product_id, game_type_id FROM `{$play_products}` WHERE bingo_play_product_id = %d", $play_product_id ), ARRAY_A ),
				'existing Play Product reference to local game_type_id survives unchanged'
			);
			$this->assert_same( $protected_rows, $this->snapshot_current_site_tables( [ 'bingo_packages', 'bingo_package_components', 'bingo_sessions', 'bingo_session_packages' ] ), 'Package and Session rows are unchanged by the migration' );
			$this->assert_same( $protected_ddl, $this->schema_snapshot( [ Migrations::packages_table(), Migrations::package_components_table(), $wpdb->prefix . 'bingo_sessions', $wpdb->prefix . 'bingo_session_packages' ] ), 'Package and Session schemas are unchanged by the migration' );

			$second = $wpdb->insert(
				$game_types,
				[
					'code'       => 'phase-3c1-second',
					'name'       => 'Phase 3C1 Second',
					'local_name_key' => 'Phase 3C1 Second',
					'status'     => 'active',
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			$this->assert_same( 1, $second, 'a second local Game Type with null Portal identity is allowed' );
			$second_id = (int) $wpdb->insert_id;
			$this->assert_same( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$game_types}` WHERE portal_game_type_id IS NULL" ), 'multiple null Portal identities are allowed' );

			$portal_a = random_int( 2000000001, 3000000000 );
			$portal_b = random_int( 3000000001, 4000000000 );
			$this->assert_same( 1, $wpdb->update( $game_types, [ 'portal_game_type_id' => $portal_a, 'local_name_key' => null ], [ 'game_type_id' => $game_type_id ], [ '%d', null ], [ '%d' ] ), 'first non-null Portal identity is accepted' );
			$this->assert_same( 1, $wpdb->update( $game_types, [ 'portal_game_type_id' => $portal_b, 'local_name_key' => null ], [ 'game_type_id' => $second_id ], [ '%d', null ], [ '%d' ] ), 'a distinct non-null Portal identity is accepted' );

			$previous  = $wpdb->suppress_errors( true );
			$duplicate = $wpdb->update( $game_types, [ 'portal_game_type_id' => $portal_a ], [ 'game_type_id' => $second_id ], [ '%d' ], [ '%d' ] );
			$db_error  = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $previous );
			$this->assert_same( false, $duplicate, 'duplicate non-null Portal identity is rejected by the database' );
			$this->assert_true( false !== stripos( $db_error, 'portal_game_type_unique' ), 'duplicate failure identifies the Portal unique index' );
			$this->assert_same( $portal_b, (int) $wpdb->get_var( $wpdb->prepare( "SELECT portal_game_type_id FROM `{$game_types}` WHERE game_type_id = %d", $second_id ) ), 'rejected duplicate leaves the existing distinct identity unchanged' );

			wp_set_current_user( $this->super_admin_id );
			$service = new GameTypeService( static fn( array $event ): int => 1 );
			$created = $service->create( 'Phase 3C1 Local Service' );
			$this->assert_true( $created instanceof GameTypeResult, 'existing local GameTypeService creation still succeeds' );
			if ( $created instanceof GameTypeResult ) {
				$created_id = (int) $created->game_type['game_type_id'];
				$this->assert_same( null, $wpdb->get_var( $wpdb->prepare( "SELECT portal_game_type_id FROM `{$game_types}` WHERE game_type_id = %d", $created_id ) ), 'local service creation fabricates no Portal identity' );
			}

			$this->assert_true( true === Migrations::migrate_current_site(), 'current Catalog migration rerun is idempotent' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'schema still verifies after rerun' );
		} finally {
			restore_current_blog();
		}
	}

	private function new_site_provisioning(): void {
		$site_id = $this->create_disposable_site( 'Provisioning' );
		$this->assert_true( $site_id > 4, 'new-site fixture is a current-run disposable hall' );
		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		try {
			$this->assert_same( 6, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'normal new-site provisioning records Catalog v6' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'normal new-site provisioning produces the complete v6 schema' );
			$this->assert_portal_column_and_index( Migrations::game_types_table(), 'newly provisioned hall' );
			$this->assert_v5_names( Migrations::game_types_table() );
		} finally {
			restore_current_blog();
		}
	}

	private function assert_portal_column_and_index( string $table, string $label ): void {
		global $wpdb;

		$column = $this->column( $table, 'portal_game_type_id' );
		$this->assert_true( is_array( $column ), "{$label}: Portal identity column exists" );
		$this->assert_true( is_array( $column ) && 1 === preg_match( '/^bigint(?:\\(20\\))? unsigned$/', strtolower( (string) $column['Type'] ) ), "{$label}: Portal identity is unsigned bigint" );
		$this->assert_same( 'YES', is_array( $column ) ? $column['Null'] : null, "{$label}: Portal identity is nullable" );
		$this->assert_same( null, is_array( $column ) ? $column['Default'] : 'missing', "{$label}: Portal identity defaults to null" );

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'portal_game_type_unique'", ARRAY_A );
		$this->assert_same( 1, count( $indexes ), "{$label}: Portal identity index has exactly one column" );
		$this->assert_true( 1 === count( $indexes ) && 0 === (int) $indexes[0]['Non_unique'], "{$label}: Portal identity index is unique" );
		$this->assert_same( 'portal_game_type_id', 1 === count( $indexes ) ? $indexes[0]['Column_name'] : null, "{$label}: unique index targets only Portal identity" );
	}

	/** @return array<string,mixed>|null */
	private function column( string $table, string $name ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SHOW FULL COLUMNS FROM `{$table}` LIKE %s", $name ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function create_disposable_site( string $label ): int {
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'portal3c1' . wp_generate_password( 8, false, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'Portal 3C1 Disposable Hall ' . $label,
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->assert_true( false, 'disposable hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$site_id                          = (int) $site;
		$this->disposable_sites[ $site_id ] = true;

		return $site_id;
	}

	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		if ( $site_id <= 4 || empty( $this->disposable_sites[ $site_id ] ) || get_current_blog_id() !== $site_id ) {
			throw new \RuntimeException( 'Refusing structural SQL outside a current-run disposable hall.' );
		}

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Disposable schema mutation failed.' );
		}
	}

	/** @param array<int,string> $suffixes
	 *  @return array<string,string>
	 */
	private function snapshot_tables( array $site_ids, array $suffixes ): array {
		global $wpdb;

		$tables = [];
		foreach ( $site_ids as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( (int) $site_id );
			foreach ( $suffixes as $suffix ) {
				$tables[] = $prefix . $suffix;
			}
		}

		return $this->hash_tables( $tables );
	}

	/** @param array<int,string> $tables
	 *  @return array<string,string>
	 */
	private function hash_tables( array $tables ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			usort(
				$rows,
				static fn( array $left, array $right ): int => strcmp( serialize( $left ), serialize( $right ) )
			);
			$snapshot[ $table ] = hash( 'sha256', serialize( $rows ) );
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @param array<int,string> $suffixes
	 *  @return array<string,string>
	 */
	private function snapshot_current_site_tables( array $suffixes ): array {
		return $this->snapshot_tables( [ get_current_blog_id() ], $suffixes );
	}

	/** @param array<int,string> $tables
	 *  @return array<string,string>
	 */
	private function schema_snapshot( array $tables ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( is_array( $row ) ) {
				$snapshot[ $table ] = (string) ( $row[1] ?? '' );
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @return array<int,string> */
	private function domain_table_suffixes(): array {
		return [
			'bingo_game_types',
			'bingo_play_products',
			'bingo_packages',
			'bingo_package_components',
			'bingo_package_addons',
			'bingo_sessions',
			'bingo_session_packages',
		];
	}

	/**
	 * Disposable halls are provisioned by the live plugin stack, so a hall created
	 * by this run also receives the Phase 3C2 Session-template replica. Only the
	 * current-run disposable halls are destroyed here; pre-existing hall replicas
	 * are never dropped or modified.
	 *
	 * @return array<int,string>
	 */
	private function disposable_table_suffixes(): array {
		return array_merge( $this->domain_table_suffixes(), [ 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ] );
	}

	/** @return array<int,string> */
	private function woo_table_suffixes(): array {
		return [
			'posts',
			'postmeta',
			'woocommerce_order_items',
			'woocommerce_order_itemmeta',
			'wc_orders',
			'wc_order_addresses',
			'wc_order_operational_data',
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function snapshot_mappings(): array {
		global $wpdb;

		$table = $wpdb->base_prefix . 'bingo_portal_hall_mappings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return [];
		}

		return (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_hall_id", ARRAY_A );
	}

	/** @return array<int,string> */
	private function portal_session_template_tables(): array {
		global $wpdb;

		$tables = array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s',
					DB_NAME,
					$wpdb->esc_like( $wpdb->base_prefix ) . '%bingo_portal_session_templates'
				)
			)
		);
		sort( $tables );

		return $tables;
	}

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			$prefix  = $wpdb->get_blog_prefix( $site_id );
			$tables  = array_map( static fn( string $suffix ): string => $prefix . $suffix, $this->disposable_table_suffixes() );

			wp_delete_site( $site_id );
			$allowed = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|play_products|packages|package_components|package_addons|sessions|session_packages|portal_session_templates|session_schedules|session_schedule_dates|session_schedule_packages)$/';
			foreach ( $tables as $table ) {
				if ( 1 === preg_match( $allowed, $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			$this->deleted_site_tables[ $site_id ] = $tables;
			unset( $this->disposable_sites[ $site_id ] );
		}
	}

	private function verify_disposable_cleanup(): void {
		global $wpdb;

		foreach ( $this->deleted_site_tables as $site_id => $tables ) {
			$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ), "disposable hall {$site_id} record was removed" );
			foreach ( $tables as $table ) {
				$this->assert_same(
					0,
					(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) ),
					"disposable hall {$site_id} table {$table} was removed"
				);
			}
		}
	}

	private function find_super_admin_id(): int {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/** @param mixed $expected
	 *  @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new \RuntimeException( "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}

		echo "PASS: {$message}\n";
	}

	private function assert_true( bool $condition, string $message ): void {
		$this->assert_same( true, $condition, $message );
	}
}

( new PortalGameTypeIdentityPersistenceTest() )->run();
