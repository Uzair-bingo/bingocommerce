<?php

declare(strict_types=1);

/**
 * ADR-049 stage 3, slice 3.1: starter import storage and locks.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-schema.php
 *
 * Proves, against the sentinel-owned fixture only:
 *   - the three network tables and three Hall tables are created with their
 *     unique keys, idempotently, under their own version options;
 *   - site creation does NOT create them (no coupling to Enable);
 *   - a failed creation leaves no version option behind;
 *   - a current schema with a missing or malformed table is reported for
 *     repair and never silently recreated;
 *   - the Catalog schema, readiness and other Halls are unchanged;
 *   - the main site is refused;
 *   - the Hall import lock and the publish lock refuse a second holder,
 *     separate Halls do not block each other, and a dead request frees its lock.
 *
 * No development fallback. Destructive steps require a current-run site
 * registry entry plus that site's durable ownership token.
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
use BingoCommerce\Catalog\StarterImportLock;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\PortalIntegration\SetupReadiness;

final class StarterImportSchemaTest {
	private const OWNER = '_bc_starter_import_fixture_owner';

	private const HALL_TABLES = [
		StarterSchema::IMPORT_TABLE,
		StarterSchema::IMPORT_PRODUCTS_TABLE,
		StarterSchema::IMPORT_IMAGES_TABLE,
	];

	private const NETWORK_TABLES = [
		StarterSchema::VERSIONS_TABLE,
		StarterSchema::VERSION_PRODUCTS_TABLE,
		StarterSchema::VERSION_IMAGES_TABLE,
	];

	private const CATALOG = [ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons' ];
	private const SESSION = [ 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ];

	private array $sites = [];
	private string $token;
	private int $passed = 0;
	private int $failed = 0;

	public function run(): int {
		global $wpdb;

		$this->token = wp_generate_password( 24, false, false );
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		wp_set_current_user( (int) username_exists( 'fixture_admin' ) );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );

		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}

		try {
			$this->reset_network_tables();
			$this->network_tables();

			$a = $this->create_site();
			$b = $this->create_site();
			$this->same( false, $wpdb->get_blog_prefix( $a ) === $wpdb->get_blog_prefix( $b ), 'Hall A and B have independent prefixes' );

			$this->hall_tables( $a, $b );
			$this->main_site_refused();
			$this->locks( $a, $b );
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			foreach ( array_keys( $this->sites ) as $site ) {
				$this->cleanup( $site );
			}
			$this->reset_network_tables();
		}

		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		$this->same( false, get_site_option( StarterSchema::NETWORK_VERSION_OPTION, false ), 'network version option removed after the run' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Network tables
	 * ------------------------------------------------------------------ */

	private function network_tables(): void {
		global $wpdb;

		$this->same( false, StarterSchema::is_network_current(), 'network schema starts absent' );

		$failed = $this->fault(
			'CREATE TABLE ' . StarterSchema::version_images_table() . ' (',
			static fn() => StarterSchema::ensure_network()
		);
		$this->code( 'starter_import_schema_failed', $failed, 'a failed network table creation is reported' );
		$this->same( false, get_site_option( StarterSchema::NETWORK_VERSION_OPTION, false ), 'a failed creation saves no network version' );

		$this->same( true, StarterSchema::ensure_network(), 'network tables are created' );
		$this->same( StarterSchema::NETWORK_DB_VERSION, (int) get_site_option( StarterSchema::NETWORK_VERSION_OPTION ), 'network version saved after verification' );
		$this->same( true, StarterSchema::verify_network_schema(), 'network tables match the expected shape' );
		$this->same( true, str_contains( $this->ddl( StarterSchema::version_products_table() ), 'UNIQUE KEY `version_source` (`version_id`,`source_product_id`)' ), 'one row per source product per version' );
		$this->same( true, str_contains( $this->ddl( StarterSchema::version_images_table() ), 'UNIQUE KEY `version_attachment` (`version_id`,`source_attachment_id`)' ), 'one row per source image per version' );
		$this->same( false, str_contains( $this->ddl( StarterSchema::versions_table() ), 'blog_id' ), 'network tables carry no blog_id' );

		$before = $this->network_snapshot();
		$this->same( true, StarterSchema::ensure_network(), 'network rerun succeeds' );
		$this->same( $before, $this->network_snapshot(), 'network rerun is byte-identical' );

		$this->sql( $wpdb->prepare( 'DROP TABLE %i', StarterSchema::version_images_table() ) );
		$this->code( 'starter_import_schema_repair_needed', StarterSchema::ensure_network(), 'a missing network table under a current version needs repair' );
		$this->same( null, $this->table_exists( StarterSchema::version_images_table() ), 'a missing network table is never silently recreated' );

		delete_site_option( StarterSchema::NETWORK_VERSION_OPTION );
		$this->same( true, StarterSchema::ensure_network(), 'network tables recreate once the version is cleared' );

		$this->sql( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX version_source', StarterSchema::version_products_table() ) );
		$this->code( 'starter_import_schema_repair_needed', StarterSchema::ensure_network(), 'a missing unique key under a current version needs repair' );
		$this->code( 'starter_import_schema_invalid', StarterSchema::verify_network_schema(), 'verification names the shape as invalid' );

		$this->reset_network_tables();
		$this->same( true, StarterSchema::ensure_network(), 'network tables restored for the rest of the suite' );
	}

	/* ------------------------------------------------------------------ *
	 * Hall tables
	 * ------------------------------------------------------------------ */

	private function hall_tables( int $a, int $b ): void {
		global $wpdb;

		foreach ( [ $a, $b ] as $site ) {
			$prefix = $wpdb->get_blog_prefix( $site );
			foreach ( self::HALL_TABLES as $suffix ) {
				$this->same( null, $this->table_exists( $prefix . $suffix ), "site creation does not create {$suffix}" );
			}
			$this->same( false, get_blog_option( $site, StarterSchema::HALL_VERSION_OPTION, false ), 'site creation saves no Hall importer version' );
		}

		$readiness_before = $this->readiness( $a );
		$this->same( true, $this->readiness_ran( $readiness_before ), 'readiness actually evaluated Hall A before the importer schema (structured result with checks)' );
		$hall_b_before = $this->snapshot( $b );

		switch_to_blog( $a );
		try {
			$this->owned( $a );
			$this->same( false, StarterSchema::is_hall_current(), 'Hall A importer schema starts absent' );

			$failed = $this->fault(
				'CREATE TABLE ' . StarterSchema::import_images_table() . ' (',
				static fn() => StarterSchema::ensure_current_hall()
			);
			$this->code( 'starter_import_schema_failed', $failed, 'a failed Hall table creation is reported' );
			$this->same( false, get_option( StarterSchema::HALL_VERSION_OPTION, false ), 'a failed creation saves no Hall version' );
			$this->same( true, Migrations::is_current(), 'Catalog stays current after an importer failure' );
			$this->same( true, Migrations::verify_current_site_schema(), 'Catalog schema still verifies after an importer failure' );

			$this->same( true, StarterSchema::ensure_current_hall(), 'Hall tables are created' );
			$this->same( StarterSchema::HALL_DB_VERSION, (int) get_option( StarterSchema::HALL_VERSION_OPTION ), 'Hall version saved after verification' );
			$this->same( true, StarterSchema::is_hall_current(), 'Hall importer schema is current' );
			$this->same( true, StarterSchema::verify_hall_schema(), 'Hall tables match the expected shape' );
			$this->same( true, str_contains( $this->ddl( StarterSchema::import_table() ), 'UNIQUE KEY `singleton_unique` (`singleton`)' ), 'one import receipt per Hall' );
			$this->same( true, str_contains( $this->ddl( StarterSchema::import_products_table() ), 'UNIQUE KEY `import_version_product` (`import_id`,`version_product_id`)' ), 'one item per version product per import' );
			$this->same( true, str_contains( $this->ddl( StarterSchema::import_products_table() ), 'UNIQUE KEY `recovery_slug_unique` (`recovery_slug`)' ), 'product recovery identifiers are unique' );
			$this->same( true, str_contains( $this->ddl( StarterSchema::import_images_table() ), 'UNIQUE KEY `import_version_image` (`import_id`,`version_image_id`)' ), 'one image row per version image per import' );
			foreach ( self::HALL_TABLES as $suffix ) {
				$this->same( false, str_contains( $this->ddl( $wpdb->prefix . $suffix ), 'blog_id' ), "{$suffix} carries no redundant blog_id" );
			}

			$this->sql( $wpdb->prepare( 'INSERT INTO %i (singleton, import_uuid, version_id, started_at, updated_at) VALUES (1, %s, 1, %s, %s)', StarterSchema::import_table(), wp_generate_uuid4(), '2026-10-19 00:00:00', '2026-10-19 00:00:00' ) );
			$prior = $wpdb->suppress_errors( true );
			$second = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (singleton, import_uuid, version_id, started_at, updated_at) VALUES (1, %s, 1, %s, %s)', StarterSchema::import_table(), wp_generate_uuid4(), '2026-10-19 00:00:00', '2026-10-19 00:00:00' ) );
			$wpdb->suppress_errors( $prior );
			$this->same( false, $second, 'the database refuses a second import receipt for the same Hall' );

			$before = $this->snapshot( $a );
			$this->same( true, StarterSchema::ensure_current_hall(), 'Hall rerun succeeds' );
			$this->same( $before, $this->snapshot( $a ), 'Hall rerun is byte-identical, history kept' );

			$this->same( true, Migrations::is_current(), 'Catalog stays current' );
			$this->same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), 'Catalog version option unchanged' );
			$this->same( true, Migrations::verify_current_site_schema(), 'Catalog schema still verifies' );

			$this->sql( $wpdb->prepare( 'DROP TABLE %i', StarterSchema::import_products_table() ) );
			$this->code( 'starter_import_schema_repair_needed', StarterSchema::ensure_current_hall(), 'a missing Hall table under a current version needs repair' );
			$this->same( null, $this->table_exists( StarterSchema::import_products_table() ), 'a missing Hall table is never silently recreated' );
			$this->same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', StarterSchema::import_table() ) ), 'the import receipt survives the repair case' );
			$this->same( true, Migrations::is_current(), 'Catalog unaffected by an importer repair case' );
		} finally {
			restore_current_blog();
		}

		$readiness_after = $this->readiness( $a );
		$this->same( true, $this->readiness_ran( $readiness_after ), 'readiness actually evaluated Hall A after the importer schema (structured result with checks)' );
		$this->same( $readiness_before, $readiness_after, 'Hall A readiness result unchanged by the importer schema' );
		$this->same( $hall_b_before, $this->snapshot( $b ), 'Hall A importer work leaves Hall B byte-identical' );
		foreach ( self::HALL_TABLES as $suffix ) {
			$this->same( null, $this->table_exists( $wpdb->get_blog_prefix( $b ) . $suffix ), "Hall B has no {$suffix}" );
		}
	}

	private function main_site_refused(): void {
		global $wpdb;

		switch_to_blog( (int) get_main_site_id() );
		try {
			$this->code( 'starter_import_invalid_context', StarterSchema::ensure_current_hall(), 'the main site is refused' );
			$this->same( false, StarterSchema::is_hall_current(), 'the main site is never a current importer Hall' );
			$this->same( false, get_option( StarterSchema::HALL_VERSION_OPTION, false ), 'the main site gets no Hall importer version' );
			foreach ( self::HALL_TABLES as $suffix ) {
				$this->same( null, $this->table_exists( $wpdb->get_blog_prefix( (int) get_main_site_id() ) . $suffix ), "the main site has no {$suffix}" );
			}
		} finally {
			restore_current_blog();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Locks
	 * ------------------------------------------------------------------ */

	private function locks( int $a, int $b ): void {
		$this->code( StarterImportLock::LOCK_FAILED, StarterImportLock::acquire_hall( 0 ), 'an invalid Hall ID is refused' );

		$lock = StarterImportLock::acquire_hall( $a );
		$this->same( true, $lock instanceof StarterImportLock, 'Hall A import lock taken' );
		if ( ! $lock instanceof StarterImportLock ) {
			return;
		}
		$this->same( true, $lock->is_still_held(), 'Hall A lock is confirmed as held' );
		$this->code( StarterImportLock::REENTRANT, StarterImportLock::acquire_hall( $a ), 'a second take in the same request is refused' );
		$this->same( [ 'acquired' => false, 'code' => StarterImportLock::ALREADY_RUNNING ], $this->worker( 'hall', $a ), 'a second connection gets "already running" for Hall A' );
		$this->same( [ 'acquired' => true, 'code' => 'held' ], $this->worker( 'hall', $b ), 'Hall B is not blocked by Hall A' );
		$lock->release();
		$this->same( false, $lock->is_still_held(), 'a released lock is no longer held' );
		$this->same( [ 'acquired' => true, 'code' => 'held' ], $this->worker( 'hall', $a ), 'Hall A is free again after release' );

		$this->same( [ 'acquired' => true, 'code' => 'held' ], $this->worker( 'hall-and-die', $a ), 'a worker takes the Hall A lock and dies without releasing it' );
		$after_death = StarterImportLock::acquire_hall( $a );
		$this->same( true, $after_death instanceof StarterImportLock, 'a dead request frees its lock' );
		if ( $after_death instanceof StarterImportLock ) {
			$after_death->release();
		}

		$publish = StarterImportLock::acquire_publish();
		$this->same( true, $publish instanceof StarterImportLock, 'publish lock taken' );
		if ( ! $publish instanceof StarterImportLock ) {
			return;
		}
		$this->same( [ 'acquired' => false, 'code' => StarterImportLock::ALREADY_RUNNING ], $this->worker( 'publish', 0 ), 'a second publisher gets "already running"' );
		$this->same( [ 'acquired' => true, 'code' => 'held' ], $this->worker( 'hall', $a ), 'the publish lock does not block a Hall import' );
		$publish->release();
		$this->same( [ 'acquired' => true, 'code' => 'held' ], $this->worker( 'publish', 0 ), 'publish lock free again after release' );
	}

	/** @return array{acquired:bool,code:string}|null */
	private function worker( string $kind, int $blog_id ): ?array {
		$env = [
			'BC_TEST_DB'          => BC_Fixture::$database,
			'BC_WORKER_LOCK_KIND' => $kind,
			'BC_WORKER_BLOG_ID'   => (string) $blog_id,
			'PATH'                => (string) getenv( 'PATH' ),
		];

		$pipes  = [];
		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/starter-import-lock-worker.php' ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			dirname( __DIR__ ),
			$env
		);
		if ( ! is_resource( $handle ) ) {
			$this->same( true, false, 'the lock worker process could not be created' );

			return null;
		}

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $handle );
		if ( 0 !== $code ) {
			$this->same( 0, $code, 'the lock worker exited cleanly: ' . trim( $stderr ) );

			return null;
		}

		$decoded = json_decode( trim( $stdout ), true );

		return is_array( $decoded ) ? [ 'acquired' => (bool) $decoded['acquired'], 'code' => (string) $decoded['code'] ] : null;
	}

	/* ------------------------------------------------------------------ *
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	private function create_site(): int {
		$site = wp_insert_site( [ 'domain' => strtolower( 'starter-import-' . wp_generate_password( 12, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter import disposable fixture', 'user_id' => get_current_user_id() ] );
		if ( is_wp_error( $site ) || $site <= 4 ) {
			throw new RuntimeException( 'Disposable site creation failed.' );
		}
		$this->sites[ $site ] = true;
		update_site_meta( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			$this->same( true, Migrations::is_current(), 'new Hall gets Catalog v6 through site creation' );
		} finally {
			restore_current_blog();
		}

		return (int) $site;
	}

	private function owned( int $site ): void {
		global $wpdb;
		if ( ! isset( $this->sites[ $site ] ) || $site <= 4 || $this->token !== get_site_meta( $site, self::OWNER, true ) || $wpdb->get_blog_prefix( $site ) !== $wpdb->base_prefix . $site . '_' || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture ownership guard refused destructive operation.' );
		}
	}

	private function cleanup( int $site ): void {
		global $wpdb;
		$this->owned( $site );
		$prefix = $wpdb->get_blog_prefix( $site );
		$tables = array_merge( self::HALL_TABLES, self::CATALOG, self::SESSION );
		foreach ( $tables as $suffix ) {
			$this->sql( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $prefix . $suffix ) );
		}
		$deleted = wp_delete_site( $site );
		if ( is_wp_error( $deleted ) || null !== get_site( $site ) ) {
			throw new RuntimeException( 'Disposable site cleanup failed.' );
		}
		foreach ( $tables as $suffix ) {
			$this->same( null, $this->table_exists( $prefix . $suffix ), "owned fixture cleanup removed {$suffix}" );
		}
		unset( $this->sites[ $site ] );
	}

	/**
	 * The network tables are new in this slice and only this suite creates them
	 * in the fixture, so any copy found here is left over from an interrupted
	 * run of this suite. Guarded by the fixture database and sentinel checks.
	 */
	private function reset_network_tables(): void {
		global $wpdb;
		if ( ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture guard refused the network table reset.' );
		}
		foreach ( self::NETWORK_TABLES as $suffix ) {
			$this->sql( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $suffix ) );
		}
		delete_site_option( StarterSchema::NETWORK_VERSION_OPTION );
	}

	private function fault( string $needle, callable $operation ) {
		global $wpdb;
		$hits   = 0;
		$filter = static function ( string $query ) use ( $needle, &$hits ): string {
			if ( str_contains( $query, $needle ) ) {
				++$hits;

				return 'INVALID starter_import_fixture_injected_failure';
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

	/** Hash every Hall-local table's exact DDL and sorted serialized rows. */
	private function snapshot( int $site ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$result = [];
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Snapshot read failed.' );
			}
			$encoded = array_map( 'serialize', $rows );
			sort( $encoded, SORT_STRING );
			$result[ $table ] = hash( 'sha256', $this->ddl( $table ) . serialize( $encoded ) );
		}
		ksort( $result );

		return $result;
	}

	private function network_snapshot(): array {
		global $wpdb;
		$result = [];
		foreach ( self::NETWORK_TABLES as $suffix ) {
			$table = $wpdb->base_prefix . $suffix;
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Network snapshot read failed.' );
			}
			$result[ $table ] = hash( 'sha256', $this->ddl( $table ) . serialize( $rows ) );
		}
		$result['version_option'] = get_site_option( StarterSchema::NETWORK_VERSION_OPTION, false );

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

	/**
	 * Readiness as a comparable value: the full result array, or a string
	 * naming why no evaluation happened (class missing or an error code).
	 *
	 * @return array|string
	 */
	private function readiness( int $site ) {
		if ( ! class_exists( SetupReadiness::class ) ) {
			return 'missing:SetupReadiness';
		}
		$result = SetupReadiness::evaluate( $site );

		return is_wp_error( $result ) ? 'error:' . $result->get_error_code() : $result;
	}

	/**
	 * True only for a structured result whose checks actually ran. A refusal
	 * such as "site not found" must not count as an unchanged readiness.
	 * The result need not say ready = true.
	 */
	private function readiness_ran( $result ): bool {
		return is_array( $result )
			&& array_key_exists( 'ready', $result )
			&& isset( $result['checks'] )
			&& is_array( $result['checks'] )
			&& [] !== $result['checks'];
	}

	private function table_exists( string $table ): ?string {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return null === $found ? null : (string) $found;
	}

	private function sql( string $sql ): void {
		if ( false === $GLOBALS['wpdb']->query( $sql ) ) {
			throw new RuntimeException( 'Fixture SQL failed: ' . $GLOBALS['wpdb']->last_error );
		}
	}

	private function code( string $expected, $actual, string $label ): void {
		$this->same( $expected, is_wp_error( $actual ) ? $actual->get_error_code() : $actual, $label );
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

exit( ( new StarterImportSchemaTest() )->run() );
