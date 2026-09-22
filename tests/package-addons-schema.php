<?php

declare(strict_types=1);

/**
 * ADR-044 Slice 1: persistence only, against the sentinel-owned fixture.
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/package-addons-schema.php
 * No development fallback. Destructive cases require both a current-run site
 * registry entry and that site's durable ownership token. No WooCommerce needed.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
if ( 'bingocommerce_state_test_scratch' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: scratch is reserved for ownership controls, not this suite.\n" );
	exit( 1 );
}

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

use BingoCommerce\Catalog\Migrations;

final class PackageAddonsSchemaTest {
	private const OWNER = '_bc_catalog_v6_fixture_owner';
	private const CATALOG = [ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons' ];
	private const SESSION = [ 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ];
	private array $sites = [];
	private string $token;
	private int $passed = 0;
	private int $failed = 0;

	public function run(): void {
		global $wpdb;
		$this->token = wp_generate_password( 24, false, false );
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->same( 6, Migrations::DB_VERSION, 'Catalog v6' );
		wp_set_current_user( (int) username_exists( 'fixture_admin' ) );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}
		$sweep_before = get_site_option( Migrations::NETWORK_SWEEP_OPTION, false );

		try {
			$a = $this->create_site();
			$b = $this->create_site();
			$this->same( false, $wpdb->get_blog_prefix( $a ) === $wpdb->get_blog_prefix( $b ), 'Hall A and B have independent prefixes' );
			switch_to_blog( $a );
			try {
				$this->upgrade( $a, $b );
				$this->schema_evidence();
				$this->malformed_shapes( $a );
				$this->read_and_create_failures( $a );
			} finally {
				restore_current_blog();
			}
			$this->catch_up( $a, $b );
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			foreach ( array_keys( $this->sites ) as $site ) {
				$this->cleanup( $site );
			}
			if ( false === $sweep_before ) {
				delete_site_option( Migrations::NETWORK_SWEEP_OPTION );
			} else {
				update_site_option( Migrations::NETWORK_SWEEP_OPTION, $sweep_before );
			}
		}
		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";
		exit( $this->failed > 0 ? 1 : 0 );
	}

	private function create_site(): int {
		$site = wp_insert_site( [ 'domain' => strtolower( 'catalog-v6-' . wp_generate_password( 12, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Catalog v6 disposable fixture', 'user_id' => get_current_user_id() ] );
		if ( is_wp_error( $site ) || $site <= 4 ) {
			throw new RuntimeException( 'Disposable site creation failed.' );
		}
		$this->sites[ $site ] = true;
		update_site_meta( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			$this->same( 6, (int) get_option( Migrations::VERSION_OPTION ), 'new Hall provisions directly to v6 through wp_initialize_site' );
			$this->same( true, Migrations::verify_current_site_schema(), 'all five new-Hall Catalog schemas verify' );
			$this->same( 0, $this->count_addons(), 'new-Hall add-on table starts empty' );
		} finally {
			restore_current_blog();
		}
		return (int) $site;
	}

	private function upgrade( int $a, int $b ): void {
		global $wpdb;
		$this->owned( $a );
		// Explicit v5 data fixtures, not service calls or WooCommerce writes.
		$time = '2026-01-02 03:04:05';
		foreach ( [
			[ Migrations::game_types_table(), [ 'game_type_id' => 1, 'code' => 'v5-fixture', 'name' => 'Preserve Café', 'local_name_key' => 'Preserve Café', 'status' => 'active' ] ],
			[ Migrations::play_products_table(), [ 'bingo_play_product_id' => 1, 'wc_product_id' => 101, 'medium' => 'paper', 'faces_per_unit' => 6, 'game_type_id' => 1, 'status' => 'active' ] ],
			[ Migrations::packages_table(), [ 'package_id' => 1, 'wc_product_id' => 102, 'package_type' => 'paper', 'status' => 'active' ] ],
			[ Migrations::package_components_table(), [ 'package_component_id' => 1, 'package_id' => 1, 'bingo_play_product_id' => 1, 'quantity' => 2, 'sort_order' => 7 ] ],
		] as [ $table, $row ] ) {
			$this->same( 1, $wpdb->insert( $table, $row + [ 'created_at' => $time, 'updated_at' => $time ] ), "v5 fixture row in {$table}" );
		}
		$this->drop_addons( $a );
		update_option( Migrations::VERSION_OPTION, 5, false );
		$before = $this->snapshot( $a, true );
		$other = $this->snapshot( $b );
		$this->error( Migrations::verify_current_site_schema(), 'v5 without fifth table fails current schema verification' );
		$this->same( true, Migrations::migrate_current_site(), 'v5 to v6 upgrade succeeds' );
		$this->same( 6, (int) get_option( Migrations::VERSION_OPTION ), 'version advances after all five tables verify' );
		$this->same( 0, $this->count_addons(), 'upgrade creates empty add-on table' );
		$after = $this->snapshot( $a, true );
		unset( $after[ $wpdb->prefix . 'bingo_package_addons' ] );
		$this->same( $before, $after, 'upgrade preserves every existing Hall table and row except schema-version option' );
		$this->same( $other, $this->snapshot( $b ), 'Hall A upgrade leaves Hall B byte-identical' );
		$rerun = $this->snapshot( $a );
		$this->same( true, Migrations::migrate_current_site(), 'v6 rerun succeeds' );
		$this->same( $rerun, $this->snapshot( $a ), 'v6 rerun is byte-identical and idempotent' );
	}

	private function schema_evidence(): void {
		global $wpdb;
		$table = Migrations::package_addons_table();
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$shape = [];
		foreach ( $columns as $column ) {
			$shape[ $column['Field'] ] = [ $column['Type'], $column['Null'], $column['Default'], $column['Extra'] ];
		}
		// MySQL 8.0.17+ removes display widths; MariaDB retains them.
		$widths = str_contains( $shape['package_addon_id'][0], '(' );
		$big = $widths ? 'bigint(20) unsigned' : 'bigint unsigned';
		$this->same( [
			'package_addon_id' => [ $big, 'NO', null, 'auto_increment' ],
			'package_id' => [ $big, 'NO', null, '' ],
			'bingo_play_product_id' => [ $big, 'NO', null, '' ],
			'unit_price' => [ 'decimal(19,2)', 'NO', null, '' ],
			'max_quantity' => [ $widths ? 'tinyint(3) unsigned' : 'tinyint unsigned', 'NO', '1', '' ],
			'enabled' => [ $widths ? 'tinyint(1) unsigned' : 'tinyint unsigned', 'NO', '1', '' ],
			'sort_order' => [ $widths ? 'int(10) unsigned' : 'int unsigned', 'NO', '0', '' ],
			'created_at' => [ 'datetime', 'NO', null, '' ],
			'updated_at' => [ 'datetime', 'NO', null, '' ],
		], $shape, 'exact SHOW FULL COLUMNS shape' );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$keys = [];
		foreach ( $indexes as $index ) {
			$keys[ $index['Key_name'] ][] = [ (int) $index['Non_unique'], (int) $index['Seq_in_index'], $index['Column_name'], $index['Sub_part'], $index['Index_type'], $index['Collation'] ];
		}
		$this->same( [
			'PRIMARY' => [ [ 0, 1, 'package_addon_id', null, 'BTREE', 'A' ] ],
			'package_play_product_unique' => [ [ 0, 1, 'package_id', null, 'BTREE', 'A' ], [ 0, 2, 'bingo_play_product_id', null, 'BTREE', 'A' ] ],
			'package_enabled_sort' => [ [ 1, 1, 'package_id', null, 'BTREE', 'A' ], [ 1, 2, 'enabled', null, 'BTREE', 'A' ], [ 1, 3, 'sort_order', null, 'BTREE', 'A' ] ],
			'play_product' => [ [ 1, 1, 'bingo_play_product_id', null, 'BTREE', 'A' ] ],
		], $keys, 'exact SHOW INDEX shape, order and uniqueness' );
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		$this->same( 'InnoDB', $status['Engine'], 'InnoDB engine' );
		$this->same( $wpdb->collate, $status['Collation'], 'expected WordPress table collation' );
		$ddl = $this->ddl( $table );
		$this->same( 0, preg_match( '/FOREIGN KEY|CHECK\s*\(|blog_id/i', $ddl ), 'no FK, CHECK or redundant blog_id' );
		echo 'EVIDENCE SHOW FULL COLUMNS ' . $table . ': ' . json_encode( $shape ) . "\n";
		echo 'EVIDENCE SHOW INDEX ' . $table . ': ' . json_encode( $keys ) . "\n";
		echo 'EVIDENCE STORAGE: ' . $status['Engine'] . ' ' . $status['Collation'] . "\n";
	}

	private function malformed_shapes( int $site ): void {
		global $wpdb;
		$table = Migrations::package_addons_table();
		$ddl = $this->ddl( $table );
		$cases = [
			'missing column' => 'DROP COLUMN updated_at',
			'extra column' => 'ADD COLUMN blog_id bigint unsigned NOT NULL DEFAULT 0',
			'wrong bigint signedness' => 'MODIFY package_id bigint NOT NULL',
			'wrong integer size' => 'MODIFY package_id int unsigned NOT NULL',
			'wrong decimal precision' => 'MODIFY unit_price decimal(18,2) NOT NULL',
			'wrong decimal scale' => 'MODIFY unit_price decimal(19,4) NOT NULL',
			'unsigned decimal' => 'MODIFY unit_price decimal(19,2) unsigned NOT NULL',
			'nullable price' => 'MODIFY unit_price decimal(19,2) DEFAULT NULL',
			'wrong default' => 'ALTER max_quantity SET DEFAULT 2',
			'missing default' => 'ALTER enabled DROP DEFAULT',
			'wrong quantity type' => 'MODIFY max_quantity smallint unsigned NOT NULL DEFAULT 1',
			'wrong sort default' => 'ALTER sort_order SET DEFAULT 1',
			'wrong date precision' => 'MODIFY updated_at datetime(6) NOT NULL',
			'no auto increment' => 'MODIFY package_addon_id bigint unsigned NOT NULL',
			'wrong primary key' => 'DROP PRIMARY KEY, ADD PRIMARY KEY (package_addon_id,package_id)',
			'nonunique pair' => 'DROP INDEX package_play_product_unique, ADD KEY package_play_product_unique (package_id,bingo_play_product_id)',
			'reversed pair' => 'DROP INDEX package_play_product_unique, ADD UNIQUE KEY package_play_product_unique (bingo_play_product_id,package_id)',
			'wrong listing order' => 'DROP INDEX package_enabled_sort, ADD KEY package_enabled_sort (package_id,sort_order,enabled)',
			'missing reference index' => 'DROP INDEX play_product',
			'extra index' => 'ADD KEY unapproved (enabled)',
			'wrong engine' => 'ENGINE=MyISAM',
			'wrong collation' => 'COLLATE=utf8mb4_bin',
			'foreign key' => 'ADD CONSTRAINT v6_unapproved_fk FOREIGN KEY (package_id) REFERENCES `' . Migrations::packages_table() . '` (package_id)',
		];
		foreach ( $cases as $label => $alter ) {
			$this->owned( $site );
			$this->sql( "ALTER TABLE `{$table}` {$alter}" );
			update_option( Migrations::VERSION_OPTION, 5, false );
			$before = $this->ddl( $table );
			$this->error( Migrations::verify_package_addons_schema(), "rejects {$label}" );
			$this->error( Migrations::migrate_current_site(), "migration refuses unexpected {$label}" );
			$this->same( 5, (int) get_option( Migrations::VERSION_OPTION ), "{$label}: version remains below 6" );
			$this->same( $before, $this->ddl( $table ), "{$label}: no silent repair" );
			$this->drop_addons( $site );
			$this->sql( $ddl );
		}
		$this->same( true, Migrations::migrate_current_site(), 'verified fixture can retry successfully' );
	}

	private function read_and_create_failures( int $site ): void {
		global $wpdb;
		$table = Migrations::package_addons_table();
		$this->drop_addons( $site );
		update_option( Migrations::VERSION_OPTION, 5, false );
		$failures = [];
		$listener = static function ( $error, $blog ) use ( &$failures ): void { $failures[] = [ $error->get_error_code(), $blog ]; };
		add_action( 'bingocommerce_catalog_migration_failed', $listener, 10, 2 );
		try {
			$result = $this->fault( "CREATE TABLE {$table}", static fn() => Migrations::maybe_migrate_current_site() );
			$this->error( $result, 'failed CREATE fails closed' );
			$this->same( 5, (int) get_option( Migrations::VERSION_OPTION ), 'failed CREATE cannot advance version' );
			$this->same( [ [ 'catalog_migration_verification_failed', $site ] ], $failures, 'existing migration failure hook preserved' );
		} finally {
			remove_action( 'bingocommerce_catalog_migration_failed', $listener, 10 );
		}
		$this->same( true, Migrations::maybe_migrate_current_site(), 'current-Hall self-heal retries and creates fifth table' );
		$this->same( 6, (int) get_option( Migrations::VERSION_OPTION ), 'self-heal reaches v6' );
		$needles = [ 'SHOW TABLES LIKE', "SHOW FULL COLUMNS FROM `{$table}`", "SHOW INDEX FROM `{$table}`", 'SHOW TABLE STATUS LIKE', 'information_schema.TABLE_CONSTRAINTS' ];
		foreach ( $needles as $needle ) {
			update_option( Migrations::VERSION_OPTION, 5, false );
			$this->error( $this->fault( $needle, static fn() => Migrations::migrate_current_site() ), "read failure: {$needle}" );
			$this->same( 5, (int) get_option( Migrations::VERSION_OPTION ), 'read failure cannot record v6' );
		}
		foreach ( array_slice( self::CATALOG, 0, 4 ) as $suffix ) {
			$needle = 'SHOW FULL COLUMNS FROM `' . $wpdb->prefix . $suffix . '`';
			$this->error( $this->fault( $needle, static fn() => Migrations::migrate_current_site() ), "existing table {$suffix} must also verify" );
			$this->same( 5, (int) get_option( Migrations::VERSION_OPTION ), 'any failed Catalog verifier prevents version advance' );
		}
		$this->same( true, Migrations::maybe_migrate_current_site(), 'verification failures remain retryable' );
	}

	private function catch_up( int $a, int $b ): void {
		global $wpdb;
		foreach ( [ $a, $b ] as $site ) {
			switch_to_blog( $site );
			$this->drop_addons( $site );
			update_option( Migrations::VERSION_OPTION, 5, false );
			restore_current_blog();
		}
		update_site_option( Migrations::NETWORK_SWEEP_OPTION, 5 );
		set_current_screen( 'dashboard-network' );
		$blog_before = get_current_blog_id();
		$result = $this->fault( 'CREATE TABLE ' . $wpdb->get_blog_prefix( $a ) . 'bingo_package_addons', static fn() => Migrations::maybe_migrate_network() );
		$this->same( 'catalog_network_migration_incomplete', is_wp_error( $result ) ? $result->get_error_code() : $result, 'network catch-up reports partial failure' );
		$this->same( 5, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION ), 'partial network catch-up does not record v6' );
		$this->same( $blog_before, get_current_blog_id(), 'failed sweep restores Hall context' );
		$this->same( true, Migrations::maybe_migrate_network(), 'network catch-up retries successfully' );
		$this->same( 6, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION ), 'network sweep records v6 after success' );
		foreach ( [ $a, $b ] as $site ) {
			switch_to_blog( $site );
			$this->same( $wpdb->get_blog_prefix( $site ) . 'bingo_package_addons', Migrations::package_addons_table(), 'table resolves only in current Hall namespace' );
			$this->same( 6, (int) get_option( Migrations::VERSION_OPTION ), 'network catch-up records Hall v6' );
			$this->same( true, Migrations::verify_current_site_schema(), 'network catch-up includes fifth table' );
			$this->same( 0, $this->count_addons(), 'network catch-up seeds no add-on rows' );
			restore_current_blog();
		}
		switch_to_blog( $a );
		try {
			$this->drop_addons( $a );
			update_option( Migrations::VERSION_OPTION, 5, false );
			$this->same( true, Migrations::maybe_migrate_current_site(), 'current-Hall self-heal works even after network sweep reached v6' );
			$this->same( true, Migrations::verify_package_addons_schema(), 'self-heal recreated the fifth table after completed network sweep' );
		} finally {
			restore_current_blog();
		}
		set_current_screen( 'dashboard' );
	}

	private function fault( string $needle, callable $operation ) {
		global $wpdb;
		$hits = 0;
		$filter = static function ( string $query ) use ( $needle, &$hits ): string {
			if ( str_contains( $query, $needle ) ) {
				++$hits;
				return 'INVALID catalog_v6_fixture_injected_failure';
			}
			return $query;
		};
		$prior = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return $operation();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $prior );
			$this->same( true, $hits > 0, 'failure injection actually exercised its target' );
		}
	}

	private function owned( int $site ): void {
		global $wpdb;
		if ( ! isset( $this->sites[ $site ] ) || $site <= 4 || $this->token !== get_site_meta( $site, self::OWNER, true ) || $wpdb->get_blog_prefix( $site ) !== $wpdb->base_prefix . $site . '_' || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture ownership guard refused destructive operation.' );
		}
	}

	private function drop_addons( int $site ): void {
		global $wpdb;
		$this->owned( $site );
		if ( get_current_blog_id() !== $site ) {
			throw new RuntimeException( 'Wrong fixture Hall context.' );
		}
		$this->sql( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Migrations::package_addons_table() ) );
	}

	private function cleanup( int $site ): void {
		global $wpdb;
		$this->owned( $site );
		$prefix = $wpdb->get_blog_prefix( $site );
		foreach ( array_merge( self::CATALOG, self::SESSION ) as $suffix ) {
			$this->sql( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $prefix . $suffix ) );
		}
		$deleted = wp_delete_site( $site );
		if ( is_wp_error( $deleted ) || null !== get_site( $site ) ) {
			throw new RuntimeException( 'Disposable site cleanup failed.' );
		}
		foreach ( array_merge( self::CATALOG, self::SESSION ) as $suffix ) {
			$this->same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . $suffix ) ) ), "owned fixture cleanup removed {$suffix}" );
		}
		unset( $this->sites[ $site ] );
	}

	/** Hash every Hall-local table's exact DDL and sorted serialized rows. */
	private function snapshot( int $site, bool $exclude_version = false ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$result = [];
		foreach ( $tables as $table ) {
			$query = $wpdb->prepare( 'SELECT * FROM %i', $table );
			if ( $exclude_version && $table === $prefix . 'options' ) {
				$query .= $wpdb->prepare( ' WHERE option_name <> %s', Migrations::VERSION_OPTION );
			}
			$rows = $wpdb->get_results( $query, ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Snapshot read failed.' );
			}
			$encoded = array_map( 'serialize', $rows );
			sort( $encoded, SORT_STRING );
			// WordPress update_option may consume an options AUTO_INCREMENT value
			// via its upsert; row content, not that counter, is the exception here.
			$ddl = $this->ddl( $table );
			if ( $exclude_version && $table === $prefix . 'options' ) {
				$ddl = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $ddl );
			}
			$result[ $table ] = hash( 'sha256', $ddl . serialize( $encoded ) );
		}
		ksort( $result );
		return $result;
	}

	private function ddl( string $table ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Schema snapshot failed.' );
		}
		return $row[1];
	}

	private function count_addons(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Migrations::package_addons_table() ) );
	}

	private function sql( string $sql ): void {
		if ( false === $GLOBALS['wpdb']->query( $sql ) ) {
			throw new RuntimeException( 'Fixture SQL failed: ' . $GLOBALS['wpdb']->last_error );
		}
	}

	private function error( $actual, string $label ): void {
		$this->same( 'catalog_migration_verification_failed', is_wp_error( $actual ) ? $actual->get_error_code() : $actual, $label );
	}

	private function same( $expected, $actual, string $label ): void {
		if ( $expected === $actual ) {
			++$this->passed;
			echo "PASS {$label}\n";
		} else {
			++$this->failed;
			echo "FAIL {$label}: " . var_export( $actual, true ) . "\n";
		}
	}
}

( new PackageAddonsSchemaTest() )->run();
