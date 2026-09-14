<?php

/**
 * CLI coverage for the ADR-042 provisioning persistence foundation:
 * ProvisioningMigrations v1 -> v2, ProvisioningCanonicalRequest,
 * ProvisioningOperationRepository and ProvisioningMutex.
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_test php tests/portal-provisioning-foundation.php
 *
 * WordPress boots against the isolated fixture — see
 * tests/fixtures/isolated-multisite.php. The development database is never
 * opened by the bootstrap, its identities are never borrowed, outbound HTTP is
 * blocked before any plugin can run, and persistent object caching is off.
 *
 * NO REST ROUTE, NO PLATFORM FILTER, NO SITE CREATION, NO PORTAL REQUEST. This
 * increment persists and classifies provisioning commands; it does not carry
 * one out. Every assertion below is about storage, validation, classification
 * and locking.
 *
 * Every destructive statement re-verifies the connected database, the
 * allowlist, the fixture sentinel and the table name before it runs.
 */

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

use BingoCommerce\PortalIntegration\HallCommerceAccess;
use BingoCommerce\PortalIntegration\ProvisioningCanonicalRequest;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;
use BingoCommerce\PortalIntegration\ProvisioningMutex;
use BingoCommerce\PortalIntegration\ProvisioningOperationRepository as Repo;

final class PortalProvisioningFoundationTest {

	private int $assertions = 0;
	private int $failures   = 0;

	private int $hall_a = BC_FIXTURE_HALL_A;
	private int $hall_b = BC_FIXTURE_HALL_B;

	/** Portal Hall identities. Deliberately unlike the fixture blog IDs. */
	private const PORTAL_HALL_A = 7001;
	private const PORTAL_HALL_B = 7002;
	private const PORTAL_HALL_C = 7003;
	private const PORTAL_HALL_D = 7004;

	/**
	 * Halls used ONLY by the mutex containment tests.
	 *
	 * One Hall each, because an ambiguous acquisition deliberately keeps its
	 * request-local reservation for the rest of the process: reusing a Hall
	 * across those cases would measure the previous case's leftover refusal
	 * instead of the behaviour under test.
	 */
	private const PORTAL_HALL_MUTEX_THROW     = 7011;
	private const PORTAL_HALL_MUTEX_UNREAD    = 7012;
	private const PORTAL_HALL_MUTEX_TIMEOUT   = 7013;
	private const PORTAL_HALL_MUTEX_NULL      = 7014;
	private const PORTAL_HALL_MUTEX_HELD      = 7015;
	private const PORTAL_HALL_MUTEX_NO_SESSION = 7016;
	private const PORTAL_HALL_MUTEX_RESTORE    = 7017;

	/** True only once THIS run has created the v2 schema. */
	private bool $owns_schema = false;

	/** Hall option tables THIS run created, and may therefore drop. */
	private array $owns_hall_options = [];

	/** Unsafe environment: stop before any mutating test, do not continue. */
	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		fwrite( STDERR, "Stopping before any mutating test.\n" );
		exit( 1 );
	}

	public function run(): void {
		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();

		try {
			$this->test_not_wired_to_automatic_paths();
			$this->test_clean_install();
			$this->test_repeated_verified_v2_is_idempotent();
			$this->test_runtime_readiness_performs_no_ddl();
			$this->test_readiness_cache_is_not_a_substitute_for_storage();
			$this->test_version_two_with_damaged_schema();
			$this->test_failed_verification_does_not_advance_version();
			$this->test_migration_refuses_unreadable_version();
			$this->test_genuine_v1_to_v2_preserves_commerce_state();
			$this->test_canonical_field_validation();
			$this->test_canonical_fingerprint_is_exact_and_injective();
			$this->test_read_outcomes_are_distinct();
			$this->test_stored_row_invariants();
			$this->test_classification_precedence();
			$this->test_first_command_insert();
			$this->test_first_writer_race_between_processes();
			$this->test_foreign_unique_collisions_on_insert();
			$this->test_replace_command();
			$this->test_replace_command_enforces_precedence();
			$this->test_address_reservation_lifecycle();
			$this->test_site_ownership_assignment();
			$this->test_ownership_allocation_requires_matching_reserved_slug();
			$this->test_site_ownership_allocation_collisions();
			$this->test_blog_attachment();
			$this->test_error_category_lifecycle();
			$this->test_confirmation();
			$this->test_read_back_decides_failed_writes();
			$this->test_post_mutation_fence_is_unverified();
			$this->test_durable_ownership_three_facts();
			$this->test_mutex_scope_and_reentrancy();
			$this->test_mutex_contains_failures();
			$this->test_mutex_ordering_is_enforced();
			$this->test_mutex_serializes_one_hall_only();
			$this->test_guard_failure_mapping();
			$this->test_errors_disclose_nothing();
			$this->test_guard_refusals();
		} finally {
			$this->teardown();
		}

		$this->report();
	}

	/* ------------------------------------------------------------------ *
	 * Isolation evidence
	 * ------------------------------------------------------------------ */

	private function test_environment_is_isolated(): void {
		global $wpdb;

		$this->assert_true( is_multisite(), 'the fixture boots as Multisite' );
		if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db ) {
			$this->abort( 'WordPress is not connected to the fixture database.' );
		}
		if ( (string) $wpdb->get_var( 'SELECT DATABASE()' ) !== BC_Fixture::$database ) {
			$this->abort( 'the live connection does not report the fixture database.' );
		}
		$this->assert_true( true, 'WordPress is connected to the fixture database, not development' );
		$this->assert_true(
			defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && '' === WP_ACCESSIBLE_HOSTS,
			'outbound HTTP is blocked with no accessible hosts'
		);
		$this->assert_true( ! wp_using_ext_object_cache(), 'no persistent object cache is in use' );
		$this->assert_true(
			false === apply_filters( 'enable_loading_object_cache_dropin', true ),
			'the object-cache drop-in is disabled by the supported filter, not merely absent'
		);
		$this->assert_true( ! file_exists( WP_CONTENT_DIR . '/db.php' ), 'no database-routing drop-in is present' );

		$response = wp_remote_get( 'https://example.invalid/probe' );
		$this->assert_true(
			is_wp_error( $response ) && 'bc_fixture_http_blocked' === $response->get_error_code(),
			'outbound HTTP is rejected by the fixture hook'
		);

		$this->assert_true(
			get_site( $this->hall_a ) instanceof WP_Site && get_site( $this->hall_b ) instanceof WP_Site,
			'the fixture supplies two Hall sites'
		);
	}

	/**
	 * Refuse an inconsistent leftover fixture rather than destroying it.
	 *
	 * A schema this run did not create is not this run's to adopt or remove.
	 */
	private function require_clean_starting_state(): void {
		$state      = $this->table_exists( ProvisioningMigrations::state_table() );
		$operations = $this->table_exists( ProvisioningMigrations::operations_table() );
		$version    = (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );

		if ( $state || $operations || $version > 0 ) {
			$this->abort( 'provisioning schema or version state is already present from a previous run. Refusing to adopt it.' );
		}
	}

	private function test_not_wired_to_automatic_paths(): void {
		foreach ( [ 'init', 'admin_init', 'wp_loaded', 'plugins_loaded', 'wp_initialize_site', 'rest_api_init' ] as $hook ) {
			$this->assert_true(
				false === has_action( $hook, [ ProvisioningMigrations::class, 'migrate' ] ),
				"migrate() is not attached to {$hook}"
			);
		}

		$this->assert_true(
			false === has_filter( 'bingocommerce_platform_provision_hall_site' ),
			'the platform provisioning filter has no consumer in this increment'
		);
		$this->assert_true(
			false === has_action( 'rest_api_init', [ \BingoCommerce\PortalIntegration\Plugin::class, 'init' ] ),
			'no provisioning REST route is registered by the plugin bootstrap'
		);
		$this->assert_true(
			2 === ProvisioningMigrations::DB_VERSION
				&& 2 === ProvisioningMigrations::OPERATIONS_DB_VERSION,
			'the provisioning schema target version is 2'
		);
		$this->assert_true(
			1 === \BingoCommerce\PortalIntegration\Migrations::NETWORK_DB_VERSION,
			'the shipped Hall-mapping schema version is unchanged'
		);
		$this->assert_true(
			ProvisioningMigrations::operations_table() === $GLOBALS['wpdb']->base_prefix . 'bingo_portal_provisioning_operations',
			'the operations table is network-wide and carries no sub-prefix'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Migration
	 * ------------------------------------------------------------------ */

	private function test_clean_install(): void {
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the clean v2 installation succeeds' );
		$this->owns_schema = true;

		$this->assert_true( $this->table_exists( ProvisioningMigrations::state_table() ), 'the commerce-state table exists' );
		$this->assert_true( $this->table_exists( ProvisioningMigrations::operations_table() ), 'the operations table exists' );
		$this->assert_true(
			2 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the recorded version is exactly 2'
		);
		$this->assert_true( true === ProvisioningMigrations::verify_schema(), 'the whole v2 schema verifies' );
		$this->assert_true( true === ProvisioningMigrations::verify_state_schema(), 'the commerce-state schema verifies' );
		$this->assert_true( true === ProvisioningMigrations::verify_operations_schema(), 'the operations schema verifies' );

		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'the runtime gate reports ready' );
	}

	private function test_repeated_verified_v2_is_idempotent(): void {
		$before = $this->operations_fingerprint() . '|' . $this->state_fingerprint();
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'a repeated verified v2 migration succeeds' );
		$this->assert_true(
			$before === $this->operations_fingerprint() . '|' . $this->state_fingerprint(),
			'a repeated migration changes neither table'
		);
	}

	/**
	 * The runtime gate must never repair anything.
	 *
	 * Every statement the gate issues is captured and inspected, so "no DDL" is
	 * observed rather than asserted from reading the source.
	 */
	private function test_runtime_readiness_performs_no_ddl(): void {
		$seen = [];
		$spy  = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;

			return $query;
		};

		ProvisioningMigrations::flush_ready_cache();
		add_filter( 'query', $spy, 1 );
		try {
			$ready = ProvisioningMigrations::is_ready();
		} finally {
			remove_filter( 'query', $spy, 1 );
		}

		$this->assert_true( $ready, 'the runtime gate reports ready against a verified schema' );
		$this->assert_true( [] !== $seen, 'the runtime gate did consult storage' );

		$ddl = false;
		foreach ( $seen as $query ) {
			if ( 1 === preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME|INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
				$ddl = true;
			}
		}
		$this->assert_true( ! $ddl, 'the runtime gate issues no DDL and no write of any kind' );
	}

	private function test_readiness_cache_is_not_a_substitute_for_storage(): void {
		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'readiness is established from storage' );
		$this->assert_true( ProvisioningMigrations::is_ready(), 'the positive result is reusable within one request' );

		/*
		 * A FAILED READ MUST INVALIDATE READINESS, not be remembered. The
		 * version read is obstructed, so the gate cannot answer from storage;
		 * it must say no, and it must still say no after the cache flush that a
		 * real request boundary would perform.
		 */
		$break = static function ( $query ) {
			return 1 === preg_match( '/FROM\s+\S*sitemeta/i', (string) $query )
				? 'SELECT meta_value FROM zz_bc_absent_sitemeta LIMIT 1'
				: $query;
		};

		ProvisioningMigrations::flush_ready_cache();
		add_filter( 'query', $break, 1 );
		try {
			$unready = ProvisioningMigrations::is_ready();
			$again   = ProvisioningMigrations::is_ready();
		} finally {
			remove_filter( 'query', $break, 1 );
		}

		$this->assert_true( ! $unready, 'an unreadable version makes the gate report not ready' );
		$this->assert_true( ! $again, 'the not-ready answer is not cached as ready' );

		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'readiness returns once storage answers again' );
	}

	/**
	 * Version 2 with a damaged schema is NOT ready.
	 *
	 * This is the whole reason the gate is not the version option: the option
	 * records what a migration once installed, not what the database holds now.
	 */
	private function test_version_two_with_damaged_schema(): void {
		$this->fixture_operation( self::OP_DROP_OPERATIONS );

		$this->assert_true(
			2 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the recorded version still claims 2'
		);
		$this->assert_true( ProvisioningMigrations::is_current(), 'the version-only question still answers yes' );

		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ! ProvisioningMigrations::is_ready(), 'the runtime gate refuses a damaged schema at version 2' );
		$this->assert_true(
			is_wp_error( ProvisioningMigrations::verify_operations_schema() ),
			'operations verification fails when the table is missing'
		);
		$this->assert_true(
			is_wp_error( ProvisioningMigrations::verify_schema() ),
			'whole-schema verification fails when one table is missing'
		);

		// A migration at the recorded version re-verifies and reports the
		// failure rather than silently claiming the schema is present.
		$this->assert_true(
			is_wp_error( ProvisioningMigrations::migrate() ),
			'a migration at version 2 with a damaged schema returns an error'
		);

		// Restore by resetting the version and re-migrating, so later tests run
		// against a verified schema.
		$this->fixture_operation( self::OP_DELETE_VERSION );
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the schema is rebuilt after the damage test' );
		ProvisioningMigrations::flush_ready_cache();
		$this->assert_true( ProvisioningMigrations::is_ready(), 'the rebuilt schema is ready' );
	}

	private function test_failed_verification_does_not_advance_version(): void {
		$this->fixture_operation( self::OP_DROP_OPERATIONS );
		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->fixture_operation( self::OP_DELETE_VERSION );

		$hide = static function ( $query ) {
			return 1 === preg_match( '/^SHOW TABLES LIKE/i', ltrim( (string) $query ) )
				? "SHOW TABLES LIKE 'zz_bc_absent'"
				: $query;
		};

		add_filter( 'query', $hide, 1 );
		try {
			$result = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $hide, 1 );
		}

		$this->assert_true( is_wp_error( $result ), 'migration fails when verification fails' );
		$this->assert_true(
			0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'a failed verification never advances the version'
		);

		$this->assert_true( true === ProvisioningMigrations::migrate(), 'migration succeeds once verification is unobstructed' );
		$this->assert_true(
			2 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the version advances only after both tables verify'
		);
	}

	private function test_migration_refuses_unreadable_version(): void {
		$before_version = (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );
		$before_schema  = $this->operations_fingerprint();

		$break = static function ( $query ) {
			return 1 === preg_match( '/FROM\s+\S*sitemeta/i', (string) $query )
				? 'SELECT meta_value FROM zz_bc_absent_sitemeta LIMIT 1'
				: $query;
		};

		add_filter( 'query', $break, 1 );
		try {
			$result = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $break, 1 );
		}

		$this->assert_true( is_wp_error( $result ), 'an unreadable version stops the migration' );
		$this->assert_true(
			$before_version === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'an unreadable version leaves the recorded version untouched'
		);
		$this->assert_true( $before_schema === $this->operations_fingerprint(), 'an unreadable version leaves the schema untouched' );
	}

	/**
	 * A genuine v1 -> v2 upgrade must not touch the commerce-state table.
	 *
	 * Proven on THREE axes: the structure fingerprint, the exact stored rows,
	 * and the table's own update time are all compared across the migration.
	 */
	private function test_genuine_v1_to_v2_preserves_commerce_state(): void {
		global $wpdb;

		// Build a v1 installation: state table only, version 1, with rows.
		$this->fixture_operation( self::OP_DROP_OPERATIONS );
		$this->fixture_operation( self::OP_SET_VERSION, [ 'version' => 1 ] );
		$this->assert_true(
			! $this->table_exists( ProvisioningMigrations::operations_table() ),
			'the v1 starting point has no operations table'
		);
		$this->assert_true( $this->table_exists( ProvisioningMigrations::state_table() ), 'the v1 starting point has the state table' );

		$this->fixture_operation( self::OP_INSERT_STATE_ROW, [ 'blog_id' => $this->hall_a, 'seq' => 4096, 'state' => 'operational' ] );
		$this->fixture_operation( self::OP_INSERT_STATE_ROW, [ 'blog_id' => $this->hall_b, 'seq' => 2048, 'state' => 'suspended' ] );

		$state_table    = ProvisioningMigrations::state_table();
		$rows_before    = $wpdb->get_results( "SELECT blog_id, seq, state, updated_at FROM `{$state_table}` ORDER BY blog_id", ARRAY_A );
		$schema_before  = $this->state_fingerprint();
		$create_before  = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$state_table}`", ARRAY_N )[1] ?? '' );
		$this->assert_true( is_array( $rows_before ) && 2 === count( $rows_before ), 'two commerce-state rows exist before the upgrade' );

		// Capture the statements the upgrade issues, to prove none of them
		// targets the state table.
		$seen = [];
		$spy  = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;

			return $query;
		};

		add_filter( 'query', $spy, 1 );
		try {
			$result = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $spy, 1 );
		}

		$this->assert_true( true === $result, 'the genuine v1 -> v2 upgrade succeeds' );
		$this->assert_true(
			2 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the upgrade records version 2'
		);
		$this->assert_true(
			$this->table_exists( ProvisioningMigrations::operations_table() ),
			'the upgrade adds the operations table'
		);

		$mutating = [];
		foreach ( $seen as $query ) {
			if ( 1 === preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME|INSERT|UPDATE|DELETE|REPLACE)\b/i', $query )
				&& false !== stripos( $query, ProvisioningMigrations::STATE_TABLE ) ) {
				$mutating[] = $query;
			}
		}
		$this->assert_true( [] === $mutating, 'the upgrade issues no mutating statement against the commerce-state table' );

		$rows_after   = $wpdb->get_results( "SELECT blog_id, seq, state, updated_at FROM `{$state_table}` ORDER BY blog_id", ARRAY_A );
		$create_after = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$state_table}`", ARRAY_N )[1] ?? '' );

		$this->assert_true( $rows_before === $rows_after, 'every commerce-state row survives the upgrade unchanged' );
		$this->assert_true( $schema_before === $this->state_fingerprint(), 'the commerce-state structure survives the upgrade unchanged' );
		$this->assert_true( '' !== $create_before && $create_before === $create_after, 'the commerce-state CREATE TABLE is byte-identical after the upgrade' );

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
	}

	/* ------------------------------------------------------------------ *
	 * Canonical Request v1
	 * ------------------------------------------------------------------ */

	/** A valid baseline command. Individual fields are then broken one at a time. */
	private function command( array $overrides = [] ): array {
		return array_merge(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'intent_sequence' => 10,
				'desired_state'   => 'operational',
				'site_slug'       => 'sunrise-hall',
				'hall_name'       => 'Sunrise Bingo Hall',
				'portal_actor_id' => 42,
			],
			$overrides
		);
	}

	private function test_canonical_field_validation(): void {
		$valid = ProvisioningCanonicalRequest::validate( $this->command() );
		$this->assert_true( is_array( $valid ), 'a well-formed command validates' );
		$this->assert_true(
			is_array( $valid ) && 1 === $valid['request_canonical_version'],
			'the canonical version is recorded as 1'
		);
		$this->assert_true(
			is_array( $valid ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $valid['request_fingerprint'] ),
			'the fingerprint is lowercase 64-character hex'
		);
		$this->assert_true(
			is_array( $valid ) && 'Sunrise Bingo Hall' === $valid['hall_name'] && 'sunrise-hall' === $valid['site_slug'],
			'accepted values are returned unchanged, never trimmed or rewritten'
		);

		// The key set is closed in BOTH directions.
		$missing = $this->command();
		unset( $missing['hall_name'] );
		$this->assert_invalid( $missing, 'a missing field' );

		$extra              = $this->command();
		$extra['sneaky']    = 'x';
		$this->assert_invalid( $extra, 'an unexpected field' );

		$this->assert_invalid( 'not-an-array', 'a non-object command' );

		// portal_hall_id.
		$this->assert_invalid( $this->command( [ 'portal_hall_id' => 0 ] ), 'a zero Portal Hall ID' );
		$this->assert_invalid( $this->command( [ 'portal_hall_id' => -1 ] ), 'a negative Portal Hall ID' );
		$this->assert_invalid( $this->command( [ 'portal_hall_id' => '7001' ] ), 'a stringly-typed Portal Hall ID' );
		$this->assert_invalid( $this->command( [ 'portal_hall_id' => 7001.0 ] ), 'a float Portal Hall ID' );

		// operation_id.
		$this->assert_invalid( $this->command( [ 'operation_id' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301' ] ), 'an uppercase UUID' );
		$this->assert_invalid( $this->command( [ 'operation_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301' ] ), 'a version 1 UUID' );
		$this->assert_invalid( $this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-7a0c-0305e82c3301' ] ), 'an invalid UUID variant' );
		$this->assert_invalid( $this->command( [ 'operation_id' => '3f2504e04f8941d39a0c0305e82c3301' ] ), 'an unhyphenated UUID' );
		$this->assert_invalid( $this->command( [ 'operation_id' => "3f2504e0-4f89-41d3-9a0c-0305e82c3301\n" ] ), 'a UUID with a trailing newline' );

		// intent_sequence.
		$this->assert_invalid( $this->command( [ 'intent_sequence' => 0 ] ), 'a zero intent sequence' );
		$this->assert_invalid( $this->command( [ 'intent_sequence' => -5 ] ), 'a negative intent sequence' );
		$this->assert_invalid( $this->command( [ 'intent_sequence' => '10' ] ), 'a stringly-typed intent sequence' );
		$this->assert_invalid( $this->command( [ 'intent_sequence' => 10.0 ] ), 'a float intent sequence' );
		$this->assert_invalid( $this->command( [ 'intent_sequence' => true ] ), 'a boolean intent sequence' );
		$this->assert_invalid(
			$this->command( [ 'intent_sequence' => ProvisioningCanonicalRequest::max_intent_sequence() + 1 ] ),
			'an intent sequence past the packed-sequence bound'
		);
		$this->assert_true(
			is_array( ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => ProvisioningCanonicalRequest::max_intent_sequence() ] ) ) ),
			'the maximum intent sequence is accepted'
		);
		$this->assert_true(
			PHP_INT_MAX >> 8 === ProvisioningCanonicalRequest::max_intent_sequence(),
			'the intent-sequence bound matches the packed commerce-state capacity'
		);

		// desired_state.
		$this->assert_invalid( $this->command( [ 'desired_state' => 'provisioning' ] ), 'an out-of-vocabulary desired state' );
		$this->assert_invalid( $this->command( [ 'desired_state' => 'OPERATIONAL' ] ), 'an uppercase desired state' );

		// site_slug.
		$this->assert_invalid( $this->command( [ 'site_slug' => '-leading' ] ), 'a slug with a leading hyphen' );
		$this->assert_invalid( $this->command( [ 'site_slug' => 'trailing-' ] ), 'a slug with a trailing hyphen' );
		$this->assert_invalid( $this->command( [ 'site_slug' => 'Upper' ] ), 'an uppercase slug' );
		$this->assert_invalid( $this->command( [ 'site_slug' => 'under_score' ] ), 'a slug with an underscore' );
		$this->assert_invalid( $this->command( [ 'site_slug' => 'a.b' ] ), 'a multi-label slug' );
		$this->assert_invalid( $this->command( [ 'site_slug' => str_repeat( 'a', 64 ) ] ), 'a 64-octet slug' );
		$this->assert_true(
			is_array( ProvisioningCanonicalRequest::validate( $this->command( [ 'site_slug' => str_repeat( 'a', 63 ) ] ) ) ),
			'a 63-octet slug is accepted'
		);
		$this->assert_true(
			is_array( ProvisioningCanonicalRequest::validate( $this->command( [ 'site_slug' => 'a' ] ) ) ),
			'a single-character slug is accepted'
		);

		// hall_name — the committed Portal contract.
		$multibyte_255 = str_repeat( "\xE6\xBC\xA2", 255 );
		$accepted      = ProvisioningCanonicalRequest::validate( $this->command( [ 'hall_name' => $multibyte_255 ] ) );
		$this->assert_true( is_array( $accepted ), 'a 255-character multibyte Hall name is accepted' );
		$this->assert_true( 765 === strlen( $multibyte_255 ), 'that Hall name really occupies 765 bytes' );
		$this->assert_true(
			is_array( $accepted ) && $multibyte_255 === $accepted['hall_name'],
			'the multibyte Hall name round-trips byte-for-byte'
		);
		$this->assert_invalid( $this->command( [ 'hall_name' => str_repeat( "\xE6\xBC\xA2", 256 ) ] ), 'a 256-character Hall name' );
		$this->assert_invalid( $this->command( [ 'hall_name' => "Bad\x07Name" ] ), 'a Hall name with a control character' );
		$this->assert_invalid( $this->command( [ 'hall_name' => "Bad\xE2\x80\x8BName" ] ), 'a Hall name with a format character' );
		$this->assert_invalid( $this->command( [ 'hall_name' => "Line\xE2\x80\xA8Sep" ] ), 'a Hall name with a line separator' );
		$this->assert_invalid( $this->command( [ 'hall_name' => '   ' ] ), 'a whitespace-only Hall name' );
		$this->assert_invalid( $this->command( [ 'hall_name' => '' ] ), 'an empty Hall name' );
		$this->assert_invalid( $this->command( [ 'hall_name' => "Bad\xFFName" ] ), 'a Hall name that is not valid UTF-8' );

		// portal_actor_id.
		$this->assert_invalid( $this->command( [ 'portal_actor_id' => 0 ] ), 'a zero Portal actor ID' );
		$this->assert_invalid( $this->command( [ 'portal_actor_id' => '42' ] ), 'a stringly-typed Portal actor ID' );

		// Suspend commands carry null slug and name, before or after ownership.
		$suspend = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] )
		);
		$this->assert_true( is_array( $suspend ), 'a suspend command with null slug and name validates' );
		$this->assert_invalid(
			$this->command( [ 'desired_state' => 'suspended' ] ),
			'a suspend command carrying a slug and a name'
		);
		$this->assert_invalid(
			$this->command( [ 'desired_state' => 'suspended', 'hall_name' => null ] ),
			'a suspend command carrying a slug'
		);
		$this->assert_invalid(
			$this->command( [ 'desired_state' => 'suspended', 'site_slug' => null ] ),
			'a suspend command carrying a Hall name'
		);

		// An operational command with nulls is accepted here: whether nulls are
		// correct depends on durable ownership, which is a classification-time
		// question, not a field-shape one.
		$this->assert_true(
			is_array( ProvisioningCanonicalRequest::validate( $this->command( [ 'site_slug' => null, 'hall_name' => null ] ) ) ),
			'an operational command with null slug and name passes field validation'
		);
	}

	/** @param mixed $input */
	private function assert_invalid( $input, string $label ): void {
		$result = ProvisioningCanonicalRequest::validate( $input );
		$this->assert_true(
			$result instanceof WP_Error && 'validation_failed' === $result->get_error_code(),
			"validation rejects {$label}"
		);
	}

	private function test_canonical_fingerprint_is_exact_and_injective(): void {
		$canonical = [
			'portal_hall_id'  => 7001,
			'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			'intent_sequence' => 10,
			'desired_state'   => 'operational',
			'site_slug'       => 'sunrise-hall',
			'hall_name'       => 'Sunrise Bingo Hall',
			'portal_actor_id' => 42,
		];

		$expected = "v1\n"
			. "u:portal_hall_id:7001\n"
			. "s:operation_id:36:3f2504e0-4f89-41d3-9a0c-0305e82c3301\n"
			. "u:intent_sequence:10\n"
			. "s:desired_state:11:operational\n"
			. "s:site_slug:12:sunrise-hall\n"
			. "s:hall_name:18:Sunrise Bingo Hall\n"
			. "u:portal_actor_id:42\n";

		$actual = ProvisioningCanonicalRequest::canonical_string( $canonical );
		$this->assert_true( $expected === $actual, 'the canonical string matches the documented byte sequence exactly' );
		$this->assert_true(
			is_string( $actual ) && 8 === count( array_filter( explode( "\n", $actual ), static function ( $line ) {
				return '' !== $line;
			} ) ),
			'the digest input carries exactly eight records'
		);
		$this->assert_true(
			is_string( $actual ) && "\n" === substr( $actual, -1 ),
			'the digest input ends with the eighth record\'s newline'
		);
		$this->assert_true(
			hash( 'sha256', $expected ) === ProvisioningCanonicalRequest::fingerprint( $canonical ),
			'the fingerprint is SHA-256 over exactly that input'
		);
		$this->assert_true(
			ProvisioningCanonicalRequest::fingerprint( $canonical ) === strtolower( (string) ProvisioningCanonicalRequest::fingerprint( $canonical ) ),
			'the fingerprint is lowercase'
		);

		// NULL VERSUS THE LITERAL STRING. The typed, length-prefixed encoding is
		// what makes these three distinct rather than merely unlikely to collide.
		$null_slug   = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'site_slug' => null ] ) );
		$literal     = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'site_slug' => 'null' ] ) );
		$this->assert_true( false !== strpos( (string) $null_slug, "n:site_slug\n" ), 'a null slug encodes as the bare null marker' );
		$this->assert_true( false !== strpos( (string) $literal, "s:site_slug:4:null\n" ), 'the literal string "null" encodes as a length-prefixed string' );
		$this->assert_true( $null_slug !== $literal, 'a null slug and the literal string "null" produce different inputs' );
		$this->assert_true(
			ProvisioningCanonicalRequest::fingerprint( array_merge( $canonical, [ 'site_slug' => null ] ) )
				!== ProvisioningCanonicalRequest::fingerprint( array_merge( $canonical, [ 'site_slug' => 'null' ] ) ),
			'a null slug and the literal string "null" fingerprint differently'
		);

		$null_name = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'hall_name' => null ] ) );
		$this->assert_true( false !== strpos( (string) $null_name, "n:hall_name\n" ), 'a null Hall name encodes as the bare null marker' );

		// FIELD BOUNDARIES CANNOT BE SHIFTED. Moving a character from one field
		// to the next changes the lengths, so no two distinct commands share an
		// input even when their concatenated values are identical.
		$a = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'site_slug' => 'ab', 'hall_name' => 'cd' ] ) );
		$b = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'site_slug' => 'abc', 'hall_name' => 'd' ] ) );
		$this->assert_true( $a !== $b, 'a character moved across a field boundary changes the digest input' );

		// LENGTHS ARE UTF-8 BYTES, NOT CHARACTERS.
		$multibyte = ProvisioningCanonicalRequest::canonical_string( array_merge( $canonical, [ 'hall_name' => "\xE6\xBC\xA2\xE6\xBC\xA2" ] ) );
		$this->assert_true( false !== strpos( (string) $multibyte, 's:hall_name:6:' ), 'string lengths are exact UTF-8 byte lengths' );

		// Every field participates: changing any one of the seven changes the
		// fingerprint.
		$base = ProvisioningCanonicalRequest::fingerprint( $canonical );
		foreach (
			[
				'portal_hall_id'  => 7002,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3302',
				'intent_sequence' => 11,
				'desired_state'   => 'suspended',
				'site_slug'       => 'sunrise-halls',
				'hall_name'       => 'Sunrise Bingo Halls',
				'portal_actor_id' => 43,
			] as $field => $changed
		) {
			$this->assert_true(
				$base !== ProvisioningCanonicalRequest::fingerprint( array_merge( $canonical, [ $field => $changed ] ) ),
				"changing {$field} changes the fingerprint"
			);
		}

		$this->assert_true(
			ProvisioningCanonicalRequest::canonical_string( [ 'portal_hall_id' => 1 ] ) instanceof WP_Error,
			'an incomplete canonical request cannot be hashed'
		);
		$this->assert_true(
			ProvisioningCanonicalRequest::fingerprint( $canonical ) === ProvisioningCanonicalRequest::fingerprint( $canonical ),
			'the fingerprint is deterministic'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Repository reads
	 * ------------------------------------------------------------------ */

	private function test_read_outcomes_are_distinct(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$absent = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true( null === $absent, 'a Hall with no row reads as a VERIFIED absence' );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		$stored  = Repo::insert_first_command( $request );
		$this->assert_true(
			is_array( $stored ) && 'stored' === $stored['outcome'] && is_array( $stored['row'] ),
			'a first command stores and reads back as a valid row'
		);

		$valid = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true( is_array( $valid ) && self::PORTAL_HALL_A === $valid['portal_hall_id'], 'a valid row reads as an array' );

		// UNREADABLE: the storage could not be asked at all.
		$break = static function ( $query ) {
			return 1 === preg_match( '/FROM\s+\S*bingo_portal_provisioning_operations/i', (string) $query )
				? 'SELECT 1 FROM zz_bc_absent_operations'
				: $query;
		};
		add_filter( 'query', $break, 1 );
		try {
			$unreadable = Repo::load( self::PORTAL_HALL_A );
		} finally {
			remove_filter( 'query', $break, 1 );
		}
		$this->assert_true(
			$unreadable instanceof WP_Error && 'operation_read_failed' === $unreadable->get_error_code(),
			'an unreadable operations table is reported as a read failure, not as an absence'
		);
		$this->assert_true(
			null !== Repo::load( self::PORTAL_HALL_A ) && ! Repo::load( self::PORTAL_HALL_A ) instanceof WP_Error,
			'the row is readable again once the obstruction is removed'
		);

		$this->assert_true(
			Repo::load( 0 ) instanceof WP_Error && 'validation_failed' === Repo::load( 0 )->get_error_code(),
			'an invalid Hall ID is refused before any query'
		);
		$this->assert_true(
			Repo::load( '7001' ) instanceof WP_Error,
			'a stringly-typed Hall ID is refused rather than coerced'
		);
	}

	/**
	 * Corrupt or internally impossible rows must fail closed, never read as
	 * "no row" and never as a usable row.
	 */
	private function test_stored_row_invariants(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$valid = [
			'portal_hall_id'            => self::PORTAL_HALL_C,
			'blog_id'                   => null,
			'site_ownership_id'         => null,
			'site_slug'                 => 'valid-slug',
			'request_site_slug'         => 'valid-slug',
			'request_hall_name'         => 'Valid Hall',
			'portal_actor_id'           => 42,
			'operation_id'              => '3f2504e0-4f89-41d3-9a0c-0305e82c3399',
			'intent_sequence'           => 10,
			'enforced_sequence'         => 0,
			'desired_state'             => 'operational',
			'request_canonical_version' => 1,
			'request_fingerprint'       => '',
			'last_error_category'       => null,
			'created_at'                => '2026-01-01 00:00:00',
			'updated_at'                => '2026-01-01 00:00:00',
		];
		$valid['request_fingerprint'] = (string) ProvisioningCanonicalRequest::fingerprint(
			[
				'portal_hall_id'  => $valid['portal_hall_id'],
				'operation_id'    => $valid['operation_id'],
				'intent_sequence' => $valid['intent_sequence'],
				'desired_state'   => $valid['desired_state'],
				'site_slug'       => $valid['request_site_slug'],
				'hall_name'       => $valid['request_hall_name'],
				'portal_actor_id' => $valid['portal_actor_id'],
			]
		);

		$this->assert_true(
			is_array( Repo::validate_stored_row( $valid, self::PORTAL_HALL_C ) ),
			'the control row validates'
		);
		$this->assert_true(
			Repo::validate_stored_row( $valid, self::PORTAL_HALL_A ) instanceof WP_Error,
			'a row belonging to another Hall is refused'
		);

		$cases = [
			'a non-canonical operation UUID'          => [ 'operation_id' => 'not-a-uuid' ],
			'an uppercase operation UUID'             => [ 'operation_id' => '3F2504E0-4F89-41D3-9A0C-0305E82C3399' ],
			'a zero intent sequence'                  => [ 'intent_sequence' => 0 ],
			'an out-of-range intent sequence'         => [ 'intent_sequence' => '184467440737095516150' ],
			'an enforced sequence ahead of intent'    => [ 'enforced_sequence' => 11 ],
			'an out-of-vocabulary desired state'      => [ 'desired_state' => 'provisioning' ],
			'a wrong canonical version'               => [ 'request_canonical_version' => 2 ],
			'a malformed fingerprint'                 => [ 'request_fingerprint' => 'abc' ],
			'an uppercase fingerprint'                => [ 'request_fingerprint' => strtoupper( $valid['request_fingerprint'] ) ],
			'a zero Portal actor ID'                  => [ 'portal_actor_id' => 0 ],
			'a malformed ownership UUID'              => [ 'site_ownership_id' => 'nope', 'site_slug' => 'valid-slug' ],
			'a malformed durable slug'                => [ 'site_slug' => 'Bad_Slug' ],
			'a malformed request slug'                => [ 'request_site_slug' => 'Bad_Slug' ],
			'an invalid stored Hall name'             => [ 'request_hall_name' => "Bad\x07Name" ],
			'an out-of-vocabulary error category'     => [ 'last_error_category' => 'made_up' ],
			'a malformed timestamp'                   => [ 'created_at' => '2026-02-30 00:00:00' ],
			'a non-timestamp updated_at'              => [ 'updated_at' => 'yesterday' ],
			'a zero blog_id'                          => [ 'blog_id' => 0 ],
		];
		foreach ( $cases as $label => $override ) {
			$row = array_merge( $valid, $override );
			$this->assert_true(
				Repo::validate_stored_row( $row, self::PORTAL_HALL_C ) instanceof WP_Error,
				"a stored row with {$label} is refused"
			);
		}

		// RELATIONAL INVARIANTS: the ones an ownership-null branch would misread.
		$bound_without_ownership = array_merge( $valid, [ 'blog_id' => 2, 'site_ownership_id' => null ] );
		$this->assert_true(
			Repo::validate_stored_row( $bound_without_ownership, self::PORTAL_HALL_C ) instanceof WP_Error,
			'a bound blog_id without an ownership ID is impossible and is refused'
		);

		$bound_without_slug = array_merge(
			$valid,
			[ 'blog_id' => 2, 'site_ownership_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3400', 'site_slug' => null ]
		);
		$this->assert_true(
			Repo::validate_stored_row( $bound_without_slug, self::PORTAL_HALL_C ) instanceof WP_Error,
			'a bound blog_id without a durable slug is impossible and is refused'
		);

		$ownership_without_slug = array_merge(
			$valid,
			[ 'site_ownership_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3400', 'site_slug' => null ]
		);
		$this->assert_true(
			Repo::validate_stored_row( $ownership_without_slug, self::PORTAL_HALL_C ) instanceof WP_Error,
			'an ownership ID without a durable slug is impossible and is refused'
		);

		/*
		 * REQUEST-FIELD LIFECYCLE INVARIANTS. Each of these rows carries a
		 * fingerprint recomputed from its OWN request values, so the row is
		 * self-consistent and the only thing left to reject it is the
		 * relational rule being tested. Reusing the control fingerprint would
		 * have every one of them fail on the digest instead, and prove nothing.
		 */
		$impossible = [
			'a suspend command carrying a request slug and name' => [
				'desired_state' => 'suspended',
			],
			'a suspend command carrying a request slug'          => [
				'desired_state'     => 'suspended',
				'request_hall_name' => null,
			],
			'a suspend command carrying a request Hall name'     => [
				'desired_state'     => 'suspended',
				'request_site_slug' => null,
			],
			'a request slug with no request Hall name'           => [
				'request_hall_name' => null,
			],
			'a request Hall name with no request slug'           => [
				'request_site_slug' => null,
			],
			'an operational pre-bind row with no request address' => [
				'request_site_slug' => null,
				'request_hall_name' => null,
			],
		];
		foreach ( $impossible as $label => $override ) {
			$row = $this->consistent_row( $valid, $override );
			$this->assert_true(
				Repo::validate_stored_row( $row, self::PORTAL_HALL_C ) instanceof WP_Error,
				"a stored row with {$label} is refused"
			);
		}

		// The POST-OWNERSHIP form of the same shape is legitimate: once a site
		// is bound there is a durable address to fall back on.
		$post_ownership = $this->consistent_row(
			$valid,
			[
				'request_site_slug' => null,
				'request_hall_name' => null,
				'blog_id'           => 2,
				'site_ownership_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3400',
			]
		);
		$this->assert_true(
			is_array( Repo::validate_stored_row( $post_ownership, self::PORTAL_HALL_C ) ),
			'an operational row with a bound site may carry null request fields'
		);

		// NUMERIC COLUMNS use the strict unsigned parser, so a decimal string
		// from the driver is a value and a malformed one is not.
		$string_blog = $this->consistent_row(
			$valid,
			[ 'blog_id' => '2', 'site_ownership_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3400' ]
		);
		$this->assert_true(
			is_array( Repo::validate_stored_row( $string_blog, self::PORTAL_HALL_C ) )
				&& 2 === Repo::validate_stored_row( $string_blog, self::PORTAL_HALL_C )['blog_id'],
			'a decimal-string blog_id is parsed, not cast blindly'
		);

		/*
		 * TEXTUAL COLUMNS ARE NEVER CAST INTO STRINGS. An integer where a slug,
		 * name, UUID, fingerprint or category belongs is storage this build
		 * could not have written, and casting it would manufacture a plausible
		 * value out of an impossible one.
		 */
		$integers = [
			'an integer durable slug'      => [ 'site_slug' => 12345 ],
			'an integer request slug'      => [ 'request_site_slug' => 12345 ],
			'an integer request Hall name' => [ 'request_hall_name' => 12345 ],
			'an integer operation UUID'    => [ 'operation_id' => 12345 ],
			'an integer fingerprint'       => [ 'request_fingerprint' => 12345 ],
			'an integer error category'    => [ 'last_error_category' => 12345 ],
			'an integer created_at'        => [ 'created_at' => 20260101 ],
			'an integer ownership UUID'    => [ 'site_ownership_id' => 12345 ],
		];
		foreach ( $integers as $label => $override ) {
			$row = array_merge( $valid, $override );
			$this->assert_true(
				Repo::validate_stored_row( $row, self::PORTAL_HALL_C ) instanceof WP_Error,
				"a stored row with {$label} is refused rather than coerced"
			);
		}

		// The fingerprint is RECOMPUTED, not merely pattern-checked.
		$wrong_fingerprint                      = $valid;
		$wrong_fingerprint['request_hall_name'] = 'A Different Name';
		$this->assert_true(
			Repo::validate_stored_row( $wrong_fingerprint, self::PORTAL_HALL_C ) instanceof WP_Error,
			'a stored fingerprint that disagrees with its own stored values is refused'
		);
		$this->assert_true(
			is_array( Repo::validate_stored_row( $this->consistent_row( $wrong_fingerprint ), self::PORTAL_HALL_C ) ),
			'that same row IS valid once its fingerprint describes its own values, isolating the digest as the defect'
		);

		/*
		 * THE SAME DEFECT IN REAL STORAGE. A valid row is stored through the
		 * ordinary write path first, proven readable, and then ONE column is
		 * changed underneath it — the fingerprint is deliberately left alone.
		 * Nothing else about the row differs, so the refusal can only be the
		 * recomputation.
		 */
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$planted = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_C,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3398',
					'site_slug'      => 'planted-hall',
				]
			)
		);
		$this->assert_true(
			is_array( Repo::insert_first_command( $planted ) ),
			'the corruption test starts from a row stored through the ordinary write path'
		);
		$before_corruption = Repo::load( self::PORTAL_HALL_C );
		$this->assert_true( is_array( $before_corruption ), 'that stored row reads back as valid before corruption' );

		$this->fixture_operation(
			self::OP_CORRUPT_HALL_NAME,
			[ 'hall' => self::PORTAL_HALL_C, 'value' => 'A Silently Different Name' ]
		);

		$loaded = Repo::load( self::PORTAL_HALL_C );
		$this->assert_true(
			$loaded instanceof WP_Error && 'operation_row_invalid' === $loaded->get_error_code(),
			'a corrupt row in real storage fails closed as invalid, not as an absence'
		);
		$this->assert_true( null !== $loaded, 'a corrupt row is never reported as a verified absence' );

		// Prove the refusal is the fingerprint and nothing else: the identical
		// row with a fingerprint recomputed for its new name validates.
		$corrupted = array_merge( (array) $before_corruption, [ 'request_hall_name' => 'A Silently Different Name' ] );
		$this->assert_true(
			Repo::validate_stored_row( $corrupted, self::PORTAL_HALL_C ) instanceof WP_Error,
			'the stored row as it now stands is refused'
		);
		$this->assert_true(
			is_array( Repo::validate_stored_row( $this->consistent_row( $corrupted ), self::PORTAL_HALL_C ) ),
			'the same row with a matching fingerprint is valid, so the digest is the sole defect'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * A stored row whose fingerprint describes its OWN request values.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function consistent_row( array $row, array $overrides = [] ): array {
		$row                        = array_merge( $row, $overrides );
		$row['request_fingerprint'] = (string) ProvisioningCanonicalRequest::fingerprint(
			[
				'portal_hall_id'  => $row['portal_hall_id'],
				'operation_id'    => $row['operation_id'],
				'intent_sequence' => $row['intent_sequence'],
				'desired_state'   => $row['desired_state'],
				'site_slug'       => $row['request_site_slug'],
				'hall_name'       => $row['request_hall_name'],
				'portal_actor_id' => $row['portal_actor_id'],
			]
		);

		return $row;
	}

	/* ------------------------------------------------------------------ *
	 * Classification
	 * ------------------------------------------------------------------ */

	private function test_classification_precedence(): void {
		$request = ProvisioningCanonicalRequest::validate( $this->command() );

		$this->assert_true(
			Repo::CLASSIFY_NEW_COMMAND === Repo::classify( null, $request ),
			'a verified absence classifies as a new command'
		);

		$stored = [
			'portal_hall_id'      => self::PORTAL_HALL_A,
			'operation_id'        => $request['operation_id'],
			'intent_sequence'     => $request['intent_sequence'],
			'request_fingerprint' => $request['request_fingerprint'],
		];

		// (a) exact identity.
		$this->assert_true(
			Repo::CLASSIFY_EXACT_RETRY === Repo::classify( $stored, $request ),
			'(a) the same UUID, sequence and fingerprint classify as an exact retry'
		);

		// (b) same UUID, ANY difference — in EITHER sequence direction.
		$higher = ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => 11 ] ) );
		$lower  = ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => 9 ] ) );
		$this->assert_true(
			Repo::CLASSIFY_CONFLICT === Repo::classify( $stored, $higher ),
			'(b) the same UUID with a HIGHER sequence is a conflict'
		);
		$this->assert_true(
			Repo::CLASSIFY_CONFLICT === Repo::classify( $stored, $lower ),
			'(b) the same UUID with a LOWER sequence is a conflict, never superseded'
		);

		$repayload = ProvisioningCanonicalRequest::validate( $this->command( [ 'hall_name' => 'Renamed Hall' ] ) );
		$this->assert_true(
			Repo::CLASSIFY_CONFLICT === Repo::classify( $stored, $repayload ),
			'(b) the same UUID and sequence with a different payload is a conflict'
		);

		// (c) different UUID, lower sequence.
		$older = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3302', 'intent_sequence' => 9 ] )
		);
		$this->assert_true(
			Repo::CLASSIFY_SUPERSEDED === Repo::classify( $stored, $older ),
			'(c) a different UUID with a lower sequence is superseded'
		);

		// (d) different UUID, equal sequence.
		$tie = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3302' ] )
		);
		$this->assert_true(
			Repo::CLASSIFY_CONFLICT === Repo::classify( $stored, $tie ),
			'(d) a different UUID with an equal sequence is a conflict'
		);

		// (e) different UUID, higher sequence.
		$newer = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3302', 'intent_sequence' => 11 ] )
		);
		$this->assert_true(
			Repo::CLASSIFY_NEW_COMMAND === Repo::classify( $stored, $newer ),
			'(e) a different UUID with a higher sequence is a new command'
		);

		$this->assert_true(
			Repo::classify( null, [ 'operation_id' => 'bad' ] ) instanceof WP_Error,
			'classification refuses an unvalidated request rather than guessing'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Repository writes
	 * ------------------------------------------------------------------ */

	private function test_first_command_insert(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		$result  = Repo::insert_first_command( $request );

		$this->assert_true( is_array( $result ) && 'stored' === $result['outcome'], 'the first command is stored' );
		$row = is_array( $result ) ? $result['row'] : null;

		$this->assert_true( is_array( $row ) && null === $row['blog_id'], 'a first command binds no site' );
		$this->assert_true( is_array( $row ) && null === $row['site_ownership_id'], 'a first command generates no ownership ID' );
		$this->assert_true( is_array( $row ) && 'sunrise-hall' === $row['site_slug'], 'an operational first command reserves the durable slug' );
		$this->assert_true( is_array( $row ) && 'sunrise-hall' === $row['request_site_slug'], 'the request slug is recorded separately' );
		$this->assert_true( is_array( $row ) && 0 === $row['enforced_sequence'], 'nothing is enforced yet' );
		$this->assert_true( is_array( $row ) && null === $row['last_error_category'], 'no error category is set' );
		$this->assert_true( ! Repo::is_confirmed( (array) $row ), 'a stored-but-unenforced command is not confirmed' );

		// A repeated identical insert does not duplicate: the primary key
		// resolves it, and the read-back recognises our own command.
		$again = Repo::insert_first_command( $request );
		$this->assert_true(
			is_array( $again ) && 'stored' === $again['outcome'],
			'a repeated identical first insert recognises the stored command'
		);
		$this->assert_true( 1 === $this->operations_row_count(), 'no duplicate row was created' );

		// A DIFFERENT command losing the primary-key race must be reported as a
		// race, not as its own success.
		$rival = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c33aa', 'site_slug' => 'rival-hall' ] )
		);
		$raced = Repo::insert_first_command( $rival );
		$this->assert_true(
			is_array( $raced ) && 'raced' === $raced['outcome'],
			'a different command that loses the primary-key race is reported as raced'
		);
		$this->assert_true(
			is_array( $raced ) && is_array( $raced['row'] ) && $request['operation_id'] === $raced['row']['operation_id'],
			'the raced result carries the row that actually won'
		);
		$this->assert_true( 1 === $this->operations_row_count(), 'the losing command created no row' );

		// A suspend first command reserves no address.
		$suspend = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_B,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c33bb',
				'intent_sequence' => 5,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		$stored_suspend = Repo::insert_first_command( $suspend );
		$this->assert_true( is_array( $stored_suspend ) && 'stored' === $stored_suspend['outcome'], 'a suspend first command is stored' );
		$this->assert_true(
			is_array( $stored_suspend ) && null === $stored_suspend['row']['site_slug'],
			'a suspend first command reserves no durable slug'
		);
		$this->assert_true( 2 === $this->operations_row_count(), 'two Halls now hold one row each' );

		// Two Halls may hold NULL slugs simultaneously: UNIQUE in MySQL does not
		// exclude repeated NULLs, which is what makes the reservation optional.
		$second_suspend = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_C,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c33cc',
				'intent_sequence' => 5,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		$this->assert_true(
			is_array( Repo::insert_first_command( $second_suspend ) ),
			'a second Hall may also hold a null durable slug'
		);
		$this->assert_true( 3 === $this->operations_row_count(), 'three Halls hold rows' );

		// An unvalidated or self-inconsistent command is refused before any write.
		$hand_made                        = $request;
		$hand_made['request_fingerprint'] = str_repeat( 'a', 64 );
		$this->assert_true(
			Repo::insert_first_command( $hand_made ) instanceof WP_Error,
			'a command whose fingerprint does not describe its own values is refused'
		);

		// Field validation admits paired operational nulls; a FIRST command
		// cannot, because there is no durable site to inherit.
		$first_nulls = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_D,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c33ee',
					'site_slug'      => null,
					'hall_name'      => null,
				]
			)
		);
		$this->assert_true( is_array( $first_nulls ), 'paired operational nulls pass field validation' );
		$refused_first = Repo::insert_first_command( $first_nulls );
		$this->assert_true(
			$refused_first instanceof WP_Error && 'validation_failed' === $refused_first->get_error_code(),
			'an operational first command without an address is refused'
		);
		$this->assert_true( null === Repo::load( self::PORTAL_HALL_D ), 'the refused first command stored no row' );

		$mixed = $first_nulls;
		$mixed['site_slug']            = 'lonely-slug';
		$mixed['request_fingerprint']  = ProvisioningCanonicalRequest::fingerprint( $mixed );
		$refused_mixed                 = Repo::insert_first_command( $mixed );
		$this->assert_true(
			$refused_mixed instanceof WP_Error && 'validation_failed' === $refused_mixed->get_error_code(),
			'require_canonical refuses an operational command whose address and name are not paired'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * Two distinct processes race two different first commands for ONE Hall.
	 *
	 * The operations table, not PHP, is the concurrency control: exactly one
	 * coherent row may exist afterwards, the loser is `raced` and carries the
	 * winner, and nothing is mixed or disclosed.
	 */
	private function test_first_writer_race_between_processes(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$left = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_D,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3501',
					'site_slug'      => 'first-writer-left',
					'hall_name'      => 'First Writer Left',
				]
			)
		);
		$right = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_D,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3502',
					'site_slug'      => 'first-writer-right',
					'hall_name'      => 'First Writer Right',
				]
			)
		);
		$this->assert_true( is_array( $left ) && is_array( $right ), 'both racing commands validate' );

		$results = $this->run_first_writer_race( [ $left, $right ] );
		$this->assert_true( is_array( $results ) && 2 === count( $results ), 'both first-writer workers answered' );
		if ( ! is_array( $results ) || 2 !== count( $results ) ) {
			return;
		}

		$stored = [];
		$raced  = [];
		foreach ( $results as $result ) {
			if ( 'stored' === ( $result['outcome'] ?? '' ) ) {
				$stored[] = $result;
			}
			if ( 'raced' === ( $result['outcome'] ?? '' ) ) {
				$raced[] = $result;
			}
			$this->assert_true(
				'error' !== ( $result['outcome'] ?? '' ),
				'a first-writer worker did not surface a write failure'
			);
			$this->assert_true(
				false === strpos( (string) ( $result['message'] ?? '' ), ProvisioningMigrations::operations_table() )
					&& false === strpos( (string) ( $result['message'] ?? '' ), 'SELECT' )
					&& false === strpos( (string) ( $result['message'] ?? '' ), 'INSERT' ),
				'a first-writer outcome discloses no SQL or table name'
			);
		}

		$this->assert_true( 1 === count( $stored ) && 1 === count( $raced ), 'exactly one command stored and the other raced' );
		$this->assert_true( 1 === $this->operations_row_count(), 'exactly one operations row exists after the race' );

		$row = Repo::load( self::PORTAL_HALL_D );
		$this->assert_true( is_array( $row ), 'the surviving row is valid and coherent' );
		$winner = is_array( $row ) && $left['operation_id'] === $row['operation_id'] ? $left : $right;
		$loser  = $winner === $left ? $right : $left;
		$this->assert_true(
			is_array( $row )
				&& $winner['operation_id'] === $row['operation_id']
				&& $winner['request_fingerprint'] === $row['request_fingerprint']
				&& $winner['site_slug'] === $row['site_slug']
				&& $winner['hall_name'] === $row['request_hall_name']
				&& $winner['intent_sequence'] === $row['intent_sequence'],
			'the surviving row is exactly one of the two commands, not a mix'
		);
		$this->assert_true(
			$loser['operation_id'] !== $row['operation_id']
				&& $loser['site_slug'] !== $row['site_slug'],
			'the losing command is not mixed into the surviving row'
		);
		$this->assert_true(
			1 === count( $raced )
				&& $winner['operation_id'] === $raced[0]['operation_id']
				&& $winner['request_fingerprint'] === $raced[0]['fingerprint']
				&& $winner['site_slug'] === $raced[0]['site_slug'],
			'the raced worker carries the winning row and no foreign identity'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * @param array<int,array<string,mixed>> $commands
	 * @return array<int,array<string,mixed>>|null
	 */
	private function run_first_writer_race( array $commands ): ?array {
		$procs      = [];
		$pipes      = [];
		$stdout     = [];
		$stderr     = [];
		$exit_codes = [];

		try {
			foreach ( $commands as $i => $command ) {
				$env = [
					'BC_TEST_DB'             => BC_Fixture::$database,
					'BC_WORKER_PORTAL_HALL'  => (string) $command['portal_hall_id'],
					'BC_WORKER_OPERATION_ID' => (string) $command['operation_id'],
					'BC_WORKER_INTENT'       => (string) $command['intent_sequence'],
					'BC_WORKER_SITE_SLUG'    => (string) $command['site_slug'],
					'BC_WORKER_HALL_NAME'    => (string) $command['hall_name'],
					'BC_WORKER_ACTOR'        => (string) $command['portal_actor_id'],
					'PATH'                   => (string) getenv( 'PATH' ),
				];
				$handle = proc_open(
					[ PHP_BINARY, __DIR__ . '/portal-provisioning-first-writer-worker.php' ],
					[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
					$pipes[ $i ],
					dirname( __DIR__ ),
					$env
				);
				if ( ! is_resource( $handle ) ) {
					$this->assert_true( false, "first-writer worker {$i} could not be created" );

					return null;
				}
				$procs[ $i ]  = $handle;
				$stdout[ $i ] = '';
				$stderr[ $i ] = '';
				if ( isset( $pipes[ $i ][1] ) && is_resource( $pipes[ $i ][1] ) ) {
					stream_set_blocking( $pipes[ $i ][1], false );
				}
				if ( isset( $pipes[ $i ][2] ) && is_resource( $pipes[ $i ][2] ) ) {
					stream_set_blocking( $pipes[ $i ][2], false );
				}
			}

			$ready_deadline = microtime( true ) + 20.0;
			$ready          = array_fill_keys( array_keys( $procs ), false );

			while ( microtime( true ) < $ready_deadline && in_array( false, $ready, true ) ) {
				$waiting = [];
				foreach ( $pipes as $i => $set ) {
					if ( ! $ready[ $i ] && isset( $set[1] ) && is_resource( $set[1] ) ) {
						$waiting[] = $set[1];
					}
				}
				$this->select_streams( $waiting, $ready_deadline );
				$this->drain_first_writer_pipes( $pipes, $stdout, $stderr );
				foreach ( $stdout as $i => $buffer ) {
					if ( 1 === preg_match( '/^READY\s*$/m', $buffer ) ) {
						$ready[ $i ] = true;
					}
				}
				foreach ( $procs as $i => $handle ) {
					if ( $this->observe_first_writer_exit( $i, $handle, $exit_codes ) ) {
						$this->assert_true(
							false,
							$ready[ $i ]
								? "first-writer worker {$i} exited before GO"
								: "first-writer worker {$i} exited before READY"
						);

						return null;
					}
				}
			}

			foreach ( $ready as $i => $announced ) {
				if ( ! $announced ) {
					$this->assert_true( false, "first-writer worker {$i} never announced READY" );

					return null;
				}
			}

			foreach ( $pipes as $i => $set ) {
				if ( ! isset( $set[0] ) || ! is_resource( $set[0] ) ) {
					$this->assert_true( false, "first-writer worker {$i} lost stdin before GO" );

					return null;
				}
				$written = fwrite( $set[0], "GO\n" );
				if ( false === $written || $written < 3 ) {
					$this->assert_true( false, "first-writer worker {$i} could not be released" );

					return null;
				}
				fflush( $set[0] );
				fclose( $set[0] );
				unset( $pipes[ $i ][0] );
			}

			/*
			 * Startup (boot + READY) has its own deadline. Completion time is
			 * measured from the GO barrier so a slow worker start cannot eat
			 * the window the INSERTs themselves are given.
			 */
			$completion_deadline = microtime( true ) + 20.0;
			$running             = array_keys( $procs );
			while ( [] !== $running && microtime( true ) < $completion_deadline ) {
				$waiting = [];
				foreach ( $running as $i ) {
					if ( isset( $pipes[ $i ][1] ) && is_resource( $pipes[ $i ][1] ) ) {
						$waiting[] = $pipes[ $i ][1];
					}
					if ( isset( $pipes[ $i ][2] ) && is_resource( $pipes[ $i ][2] ) ) {
						$waiting[] = $pipes[ $i ][2];
					}
				}
				$this->select_streams( $waiting, $completion_deadline );
				$this->drain_first_writer_pipes( $pipes, $stdout, $stderr );
				foreach ( $running as $k => $i ) {
					if ( $this->observe_first_writer_exit( $i, $procs[ $i ], $exit_codes ) ) {
						unset( $running[ $k ] );
					}
				}
			}

			if ( [] !== $running ) {
				$this->assert_true( false, 'a first-writer worker never exited' );

				return null;
			}

			$this->drain_first_writer_pipes( $pipes, $stdout, $stderr );

			$results = [];
			foreach ( $procs as $i => $handle ) {
				if ( ! array_key_exists( $i, $exit_codes ) ) {
					$this->assert_true( false, "first-writer worker {$i} never exited" );

					return null;
				}
				if ( 0 !== $exit_codes[ $i ] ) {
					$this->assert_true( false, "first-writer worker {$i} exited {$exit_codes[ $i ]}" );

					return null;
				}
				if ( 1 !== preg_match( '/READY\s+(\{.*\})\s*\z/s', $stdout[ $i ], $matches ) ) {
					$this->assert_true( false, "first-writer worker {$i} did not print a JSON outcome" );

					return null;
				}
				$decoded = json_decode( $matches[1], true );
				if ( ! is_array( $decoded ) ) {
					$this->assert_true( false, "first-writer worker {$i} did not print a JSON outcome" );

					return null;
				}
				$results[] = $decoded;
			}

			return $results;
		} finally {
			foreach ( $pipes as $i => $set ) {
				foreach ( $set as $fd => $pipe ) {
					if ( is_resource( $pipe ) ) {
						fclose( $pipe );
					}
					unset( $pipes[ $i ][ $fd ] );
				}
			}
			foreach ( $procs as $i => $handle ) {
				if ( ! is_resource( $handle ) ) {
					continue;
				}
				if ( ! array_key_exists( $i, $exit_codes ) ) {
					$live = proc_get_status( $handle );
					if ( is_array( $live ) && false === $live['running'] ) {
						$exit_codes[ $i ] = (int) $live['exitcode'];
					} elseif ( is_array( $live ) && $live['running'] ) {
						proc_terminate( $handle, 15 );
						$stop = microtime( true ) + 2.0;
						while ( microtime( true ) < $stop && ! array_key_exists( $i, $exit_codes ) ) {
							$this->select_streams( [], microtime( true ) + 0.05 );
							$this->observe_first_writer_exit( $i, $handle, $exit_codes );
						}
						if ( ! array_key_exists( $i, $exit_codes ) ) {
							proc_terminate( $handle, 9 );
							$stop = microtime( true ) + 1.0;
							while ( microtime( true ) < $stop && ! array_key_exists( $i, $exit_codes ) ) {
								$this->select_streams( [], microtime( true ) + 0.05 );
								$this->observe_first_writer_exit( $i, $handle, $exit_codes );
							}
						}
					}
				}
				if ( array_key_exists( $i, $exit_codes ) ) {
					proc_close( $handle );
				}
			}
		}
	}

	/**
	 * Capture the first post-termination `proc_get_status()` for one worker.
	 *
	 * Before PHP 8.3 only that first call carries the real `exitcode`; later
	 * calls return -1. This neither requires 8.3 caching nor consults a later
	 * status for the code used in assertions.
	 *
	 * @param resource       $handle
	 * @param array<int,int> $exit_codes
	 */
	private function observe_first_writer_exit( int $i, $handle, array &$exit_codes ): bool {
		if ( array_key_exists( $i, $exit_codes ) ) {
			return true;
		}
		$status = proc_get_status( $handle );
		if ( ! is_array( $status ) || $status['running'] ) {
			return false;
		}
		$exit_codes[ $i ] = (int) $status['exitcode'];

		return true;
	}

	/**
	 * @param array<int,array<int,resource|null>> $pipes
	 * @param array<int,string>                   $stdout
	 * @param array<int,string>                   $stderr
	 */
	private function drain_first_writer_pipes( array $pipes, array &$stdout, array &$stderr ): void {
		foreach ( $pipes as $i => $set ) {
			if ( isset( $set[1] ) && is_resource( $set[1] ) ) {
				$chunk = stream_get_contents( $set[1] );
				if ( is_string( $chunk ) && '' !== $chunk ) {
					$stdout[ $i ] .= $chunk;
				}
			}
			if ( isset( $set[2] ) && is_resource( $set[2] ) ) {
				$chunk = stream_get_contents( $set[2] );
				if ( is_string( $chunk ) && '' !== $chunk ) {
					$stderr[ $i ] .= $chunk;
				}
			}
		}
	}

	/**
	 * Bounded wait on readable streams. An empty stream list still waits up to
	 * the deadline slice so cleanup cannot spin, but the wait never decides
	 * which first-writer won.
	 *
	 * @param array<int,resource> $streams
	 */
	private function select_streams( array $streams, float $deadline ): void {
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return;
		}
		$slice = min( 0.25, $remaining );
		$sec   = (int) $slice;
		$usec  = (int) round( ( $slice - $sec ) * 1000000 );
		$read  = [];
		foreach ( $streams as $stream ) {
			if ( is_resource( $stream ) ) {
				$read[] = $stream;
			}
		}
		if ( [] === $read ) {
			$pair = @stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			if ( ! is_array( $pair ) ) {
				return;
			}
			$read = [ $pair[0] ];
			$write = null;
			$except = null;
			@stream_select( $read, $write, $except, $sec, $usec );
			fclose( $pair[0] );
			fclose( $pair[1] );

			return;
		}
		$write  = null;
		$except = null;
		@stream_select( $read, $write, $except, $sec, $usec );
	}

	private function test_foreign_unique_collisions_on_insert(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		$this->assert_true( is_array( Repo::insert_first_command( $first ) ), 'Hall A holds the first row' );

		// UNIQUE(operation_id): another Hall submitting the SAME command UUID.
		$same_uuid = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'portal_hall_id' => self::PORTAL_HALL_B, 'site_slug' => 'other-hall' ] )
		);
		$collision = Repo::insert_first_command( $same_uuid );
		$this->assert_true(
			is_array( $collision ) && 'operation_id_taken' === $collision['outcome'],
			'a foreign operation_id collision is reported as such'
		);
		$this->assert_true( is_array( $collision ) && null === $collision['row'], 'the foreign collision discloses no row' );
		$this->assert_true( 1 === $this->operations_row_count(), 'the foreign operation_id collision created no row' );

		// UNIQUE(site_slug): another Hall reserving the SAME address.
		$same_slug = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_B,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c33dd',
				]
			)
		);
		$slug_collision = Repo::insert_first_command( $same_slug );
		$this->assert_true(
			is_array( $slug_collision ) && 'site_slug_taken' === $slug_collision['outcome'],
			'a foreign site_slug collision is reported as such'
		);
		$this->assert_true( is_array( $slug_collision ) && null === $slug_collision['row'], 'the slug collision discloses no row' );
		$this->assert_true( 1 === $this->operations_row_count(), 'the foreign slug collision created no row' );

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	private function test_replace_command(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$stored = Repo::load( self::PORTAL_HALL_A );

		// Give the row a failure to inherit, plus durable facts to preserve.
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'site_create_failed' );
		Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$before = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true(
			is_array( $before ) && null !== $before['site_ownership_id'] && 'site_create_failed' === $before['last_error_category'],
			'the row carries an ownership ID and a recorded failure before replacement'
		);

		$newer = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3311',
				'intent_sequence' => 20,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 99,
			]
		);
		$replaced = Repo::replace_command( (array) $before, $newer );

		$this->assert_true( is_array( $replaced ), 'a higher-sequence command replaces the current one' );
		$this->assert_true( is_array( $replaced ) && 20 === $replaced['intent_sequence'], 'the sequence advances' );
		$this->assert_true( is_array( $replaced ) && $newer['operation_id'] === $replaced['operation_id'], 'the command identity is replaced' );
		$this->assert_true( is_array( $replaced ) && null === $replaced['last_error_category'], 'accepting a newer command clears the previous error category' );
		$this->assert_true(
			is_array( $replaced ) && $before['site_ownership_id'] === $replaced['site_ownership_id'],
			'the permanent ownership ID survives the command replacement'
		);
		$this->assert_true(
			is_array( $replaced ) && 'sunrise-hall' === $replaced['site_slug'],
			'a null request slug does not erase the durable reservation'
		);
		$this->assert_true( is_array( $replaced ) && null === $replaced['request_site_slug'], 'the request slug records the new command\'s null' );
		$this->assert_true( is_array( $replaced ) && 'suspended' === $replaced['desired_state'], 'the desired state is replaced' );
		$this->assert_true( is_array( $replaced ) && 0 === $replaced['enforced_sequence'], 'the new command is not enforced merely by being accepted' );
		$this->assert_true( 1 === $this->operations_row_count(), 'replacement mutates one row, never inserts another' );

		// A STALE guard cannot replace anything. The previous identity no longer
		// matches, so the conditional UPDATE matches no row.
		$stale = Repo::replace_command( (array) $before, ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3312',
				'intent_sequence' => 30,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 99,
			]
		) );
		$this->assert_true(
			$stale instanceof WP_Error && 'unverified' === $stale->get_error_code(),
			'a replacement guarded by a stale identity is refused as unverified'
		);
		$current = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true( is_array( $current ) && 20 === $current['intent_sequence'], 'the stale replacement changed nothing' );

		// A LOWER sequence cannot win even with the correct current guard.
		$backwards = Repo::replace_command( $current, ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3313',
				'intent_sequence' => 15,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 99,
			]
		) );
		$this->assert_true( $backwards instanceof WP_Error, 'a lower sequence cannot replace a higher one' );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && 20 === Repo::load( self::PORTAL_HALL_A )['intent_sequence'],
			'the backwards replacement changed nothing'
		);

		// A replacement aimed at the wrong Hall is refused outright.
		$wrong_hall = Repo::replace_command(
			array_merge( (array) $current, [ 'portal_hall_id' => self::PORTAL_HALL_B ] ),
			ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => 40 ] ) )
		);
		$this->assert_true(
			$wrong_hall instanceof WP_Error && 'internal_error' === $wrong_hall->get_error_code(),
			'a replacement whose stored row is a different Hall is refused'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * replace_command() must itself refuse every classification that is not
	 * (e): a different UUID with a strictly higher sequence.
	 */
	private function test_replace_command_enforces_precedence(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$stored = Repo::load( self::PORTAL_HALL_A );
		$before = $stored;

		$retry = Repo::replace_command( (array) $stored, $first );
		$this->assert_true(
			$retry instanceof WP_Error && Repo::CLASSIFY_EXACT_RETRY === $retry->get_error_code(),
			'an exact retry cannot mutate through replace_command'
		);

		$same_higher = ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => 11 ] ) );
		$conflict    = Repo::replace_command( (array) $stored, $same_higher );
		$this->assert_true(
			$conflict instanceof WP_Error && Repo::CLASSIFY_CONFLICT === $conflict->get_error_code(),
			'same UUID plus a higher sequence is a conflict, not a replacement'
		);

		$same_lower = ProvisioningCanonicalRequest::validate( $this->command( [ 'intent_sequence' => 9 ] ) );
		$same_lower_result = Repo::replace_command( (array) $stored, $same_lower );
		$this->assert_true(
			$same_lower_result instanceof WP_Error && Repo::CLASSIFY_CONFLICT === $same_lower_result->get_error_code(),
			'same UUID plus a lower sequence is a conflict'
		);

		$repayload = ProvisioningCanonicalRequest::validate( $this->command( [ 'hall_name' => 'Renamed For Conflict' ] ) );
		$repayload_result = Repo::replace_command( (array) $stored, $repayload );
		$this->assert_true(
			$repayload_result instanceof WP_Error && Repo::CLASSIFY_CONFLICT === $repayload_result->get_error_code(),
			'same UUID with a changed payload is a conflict'
		);

		$equal = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3511' ] )
		);
		$equal_result = Repo::replace_command( (array) $stored, $equal );
		$this->assert_true(
			$equal_result instanceof WP_Error && Repo::CLASSIFY_CONFLICT === $equal_result->get_error_code(),
			'a different UUID with an equal sequence is a conflict'
		);

		$older = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3512',
					'intent_sequence' => 9,
				]
			)
		);
		$older_result = Repo::replace_command( (array) $stored, $older );
		$this->assert_true(
			$older_result instanceof WP_Error && Repo::CLASSIFY_SUPERSEDED === $older_result->get_error_code(),
			'a different UUID with a lower sequence is superseded'
		);

		$this->assert_true( $before === Repo::load( self::PORTAL_HALL_A ), 'precedence refusals left the stored row untouched' );

		$foreign = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_B,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3513',
					'site_slug'      => 'foreign-replace',
					'hall_name'      => 'Foreign Replace',
				]
			)
		);
		Repo::insert_first_command( $foreign );
		$before_b = Repo::load( self::PORTAL_HALL_B );

		$stolen = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => $foreign['operation_id'],
					'intent_sequence' => 20,
					'site_slug'       => 'sunrise-next',
					'hall_name'       => 'Sunrise Next',
				]
			)
		);
		$collision = Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $stolen );
		$this->assert_true(
			$collision instanceof WP_Error && Repo::CLASSIFY_CONFLICT === $collision->get_error_code(),
			'a foreign UNIQUE(operation_id) collision during replacement is a conflict'
		);
		$this->assert_true( $before === Repo::load( self::PORTAL_HALL_A ), 'the foreign operation_id collision left Hall A untouched' );
		$this->assert_true( $before_b === Repo::load( self::PORTAL_HALL_B ), 'the foreign operation_id collision left Hall B untouched' );
		$this->assert_true(
			false === strpos( (string) $collision->get_error_message(), (string) self::PORTAL_HALL_B )
				&& false === strpos( (string) $collision->get_error_message(), 'foreign-replace' )
				&& false === strpos( (string) $collision->get_error_message(), $foreign['operation_id'] ),
			'the foreign operation_id collision discloses no foreign identity'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * Durable slug reservation belongs to the current operational command.
	 *
	 * Preflight occupation and an atomic UNIQUE(site_slug) race are different
	 * outcomes and are tested separately.
	 */
	private function test_address_reservation_lifecycle(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];

		$same = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $fence, 'sunrise-hall' );
		$this->assert_true( is_array( $same ) && 'sunrise-hall' === $same['site_slug'], 'reserving the current request slug is idempotent' );

		$wrong = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $fence, 'other-label' );
		$this->assert_true(
			$wrong instanceof WP_Error && 'internal_error' === $wrong->get_error_code(),
			'a slug that is not the current request slug is refused'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && 'sunrise-hall' === Repo::load( self::PORTAL_HALL_A )['site_slug'],
			'the refused mismatch left the durable slug unchanged'
		);

		$next = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3521',
					'intent_sequence' => 20,
					'site_slug'       => 'sunrise-next',
					'hall_name'       => 'Sunrise Next',
				]
			)
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $next );
		$next_fence = [ 'operation_id' => $next['operation_id'], 'intent_sequence' => $next['intent_sequence'] ];

		$moved = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $next_fence, 'sunrise-next' );
		$this->assert_true(
			is_array( $moved ) && 'sunrise-next' === $moved['site_slug'],
			'an unbound operational command may move its durable slug to its own request slug'
		);

		$rival = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_B,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3522',
					'site_slug'      => 'occupied-address',
					'hall_name'      => 'Occupied Address',
				]
			)
		);
		Repo::insert_first_command( $rival );

		$retarget = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3523',
					'intent_sequence' => 30,
					'site_slug'       => 'occupied-address',
					'hall_name'       => 'Occupied Address',
				]
			)
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $retarget );
		$retarget_fence = [ 'operation_id' => $retarget['operation_id'], 'intent_sequence' => $retarget['intent_sequence'] ];
		$before_a       = Repo::load( self::PORTAL_HALL_A );
		$before_b       = Repo::load( self::PORTAL_HALL_B );

		$preflight = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $retarget_fence, 'occupied-address' );
		$this->assert_true(
			$preflight instanceof WP_Error && 'address_occupied' === $preflight->get_error_code(),
			'a preflight-discovered occupied address is address_occupied'
		);
		$this->assert_true( $before_a === Repo::load( self::PORTAL_HALL_A ), 'the preflight refusal mutated no row' );
		$this->assert_true( $before_b === Repo::load( self::PORTAL_HALL_B ), 'the preflight refusal left the occupying Hall untouched' );

		$preflights = 0;
		$hide       = static function ( $query ) use ( &$preflights ) {
			$query = (string) $query;
			if ( 1 === preg_match( '/SELECT COUNT\(\*\) FROM \S*bingo_portal_provisioning_operations/i', $query )
				&& false !== strpos( $query, 'site_slug' ) ) {
				$preflights++;
				if ( 1 === $preflights ) {
					return 'SELECT 0';
				}
			}

			return $query;
		};
		add_filter( 'query', $hide, 1 );
		try {
			$atomic = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $retarget_fence, 'occupied-address' );
		} finally {
			remove_filter( 'query', $hide, 1 );
		}
		$this->assert_true(
			$atomic instanceof WP_Error && 'conflict' === $atomic->get_error_code(),
			'an atomic UNIQUE(site_slug) refusal is conflict, not address_occupied'
		);
		$this->assert_true( $before_a === Repo::load( self::PORTAL_HALL_A ), 'the atomic slug collision mutated no row' );
		$this->assert_true( $before_b === Repo::load( self::PORTAL_HALL_B ), 'the atomic slug collision left the occupying Hall untouched' );
		$this->assert_true(
			false === strpos( (string) $atomic->get_error_message(), (string) self::PORTAL_HALL_B )
				&& false === strpos( (string) $atomic->get_error_message(), 'occupied-address' ),
			'the atomic slug collision discloses no foreign identity'
		);

		$this->assert_true(
			Repo::replace_unbound_slug( self::PORTAL_HALL_A, $retarget_fence, 'Bad_Slug' ) instanceof WP_Error,
			'an invalid slug is refused before any statement'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	private function test_site_ownership_assignment(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		$assigned = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$this->assert_true( is_array( $assigned ), 'an ownership ID is assigned' );
		$ownership = is_array( $assigned ) ? $assigned['site_ownership_id'] : null;
		$this->assert_true(
			ProvisioningCanonicalRequest::is_uuid( $ownership ),
			'the ownership ID is a lowercase canonical UUIDv4'
		);
		$this->assert_true(
			$ownership !== $request['operation_id'],
			'the ownership ID is NOT the Portal command UUID'
		);

		$reused = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$this->assert_true(
			is_array( $reused ) && $ownership === $reused['site_ownership_id'],
			'an existing ownership ID is reused, never regenerated'
		);

		// It also survives a newer command, which is the whole reason it exists.
		$newer = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3321',
				'intent_sequence' => 25,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $newer );
		$new_fence = [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => $newer['intent_sequence'] ];
		$after     = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $new_fence );
		$this->assert_true(
			is_array( $after ) && $ownership === $after['site_ownership_id'],
			'the ownership ID survives a rotated command UUID'
		);

		// A stale fence may not assign anything.
		$stale = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$this->assert_true(
			$stale instanceof WP_Error && 'lock_unavailable' === $stale->get_error_code(),
			'a stale fence cannot assign an ownership ID'
		);
		$this->assert_true(
			Repo::assign_site_ownership_id( self::PORTAL_HALL_A, [ 'operation_id' => 'bad', 'intent_sequence' => 1 ] ) instanceof WP_Error,
			'a malformed fence is refused'
		);

		// A Hall with no reserved address cannot be given an ownership ID: the
		// two are stored as a pair and the stored-row invariants depend on it.
		$suspend = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_B,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3322',
				'intent_sequence' => 5,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		Repo::insert_first_command( $suspend );
		$no_address = Repo::assign_site_ownership_id(
			self::PORTAL_HALL_B,
			[ 'operation_id' => $suspend['operation_id'], 'intent_sequence' => $suspend['intent_sequence'] ]
		);
		$this->assert_true(
			$no_address instanceof WP_Error && 'internal_error' === $no_address->get_error_code(),
			'a suspend command is refused a new ownership ID'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_B ) ) && null === Repo::load( self::PORTAL_HALL_B )['site_ownership_id'],
			'the refused ownership assignment left the suspend row without an ownership ID'
		);

		$late = Repo::replace_unbound_slug(
			self::PORTAL_HALL_B,
			[ 'operation_id' => $suspend['operation_id'], 'intent_sequence' => $suspend['intent_sequence'] ],
			'late-reservation'
		);
		$this->assert_true(
			$late instanceof WP_Error && 'internal_error' === $late->get_error_code(),
			'a suspend command cannot reserve a durable slug'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_B ) ) && null === Repo::load( self::PORTAL_HALL_B )['site_slug'],
			'the refused reservation left the suspend row without a durable slug'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * A higher operational command that names a different slug must reserve
	 * that slug before it may receive a site ownership identity. Allocation
	 * against the previous durable slug is refused and leaves the field null.
	 */
	private function test_ownership_allocation_requires_matching_reserved_slug(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );

		$next = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3621',
					'intent_sequence' => 20,
					'site_slug'       => 'sunrise-next',
					'hall_name'       => 'Sunrise Next',
				]
			)
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $next );
		$next_fence = [ 'operation_id' => $next['operation_id'], 'intent_sequence' => $next['intent_sequence'] ];

		$refused = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $next_fence );
		$this->assert_true(
			$refused instanceof WP_Error && 'internal_error' === $refused->get_error_code(),
			'allocation is refused while the durable slug is not the requested slug'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) )
				&& null === Repo::load( self::PORTAL_HALL_A )['site_ownership_id']
				&& 'sunrise-hall' === Repo::load( self::PORTAL_HALL_A )['site_slug'],
			'the refused allocation left ownership null and did not mint against the previous durable slug'
		);

		$reserved = Repo::replace_unbound_slug( self::PORTAL_HALL_A, $next_fence, 'sunrise-next' );
		$this->assert_true(
			is_array( $reserved ) && 'sunrise-next' === $reserved['site_slug'],
			'the requested slug is reserved before ownership is allocated'
		);

		$assigned = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $next_fence );
		$this->assert_true(
			is_array( $assigned )
				&& ProvisioningCanonicalRequest::is_uuid( $assigned['site_ownership_id'] )
				&& 'sunrise-next' === $assigned['site_slug']
				&& 'sunrise-hall' !== $assigned['site_slug'],
			'ownership is allocated only after the requested slug is the durable slug'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * Exactly three allocation attempts, then `internal_error`.
	 *
	 * The collisions are REAL: another Hall's row already holds a known
	 * ownership UUID, and the candidate inside the prepared statement is
	 * rewritten to that value, so MySQL raises a genuine duplicate-key error on
	 * UNIQUE(site_ownership_id) rather than the harness faking a failure.
	 */
	private function test_site_ownership_allocation_collisions(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$taken = '3f2504e0-4f89-41d3-9a0c-0305e82c34ff';

		// A rival Hall row that legitimately owns that UUID.
		$rival = ProvisioningCanonicalRequest::validate(
			$this->command( [ 'portal_hall_id' => self::PORTAL_HALL_B, 'site_slug' => 'rival-address', 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3431' ] )
		);
		Repo::insert_first_command( $rival );
		$rival_row = Repo::load( self::PORTAL_HALL_B );
		$this->fixture_operation( self::OP_FORCE_OWNERSHIP, [ 'hall' => self::PORTAL_HALL_B, 'value' => $taken ] );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_B ) ) && $taken === Repo::load( self::PORTAL_HALL_B )['site_ownership_id'],
			'the rival Hall holds the colliding ownership UUID'
		);

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		$attempts   = 0;
		$candidates = [];
		$collide    = null;
		$collide    = static function ( $query ) use ( &$attempts, &$collide, &$candidates ) {
			global $wpdb;
			$query = (string) $query;
			if ( 1 !== preg_match( '/SET site_ownership_id = \'([0-9a-f-]{36})\'/', $query, $matches ) ) {
				return $query;
			}

			$attempts++;
			$candidates[] = $matches[1];
			/*
			 * Plant the GENERATED candidate on the rival Hall before this
			 * write lands. The probe then sees a foreign holder of that exact
			 * value, and MySQL raises a genuine UNIQUE(site_ownership_id)
			 * refusal — not a rewritten statement and not an assumed collision.
			 */
			remove_filter( 'query', $collide, 1 );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE `' . ProvisioningMigrations::operations_table() . '` SET site_ownership_id = %s WHERE portal_hall_id = %d',
					$matches[1],
					self::PORTAL_HALL_B
				)
			);
			add_filter( 'query', $collide, 1 );

			return $query;
		};

		add_filter( 'query', $collide, 1 );
		try {
			$captured = $this->with_output_capture(
				static function () use ( $fence ) {
					return Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
				}
			);
		} finally {
			remove_filter( 'query', $collide, 1 );
		}
		$result = $captured['result'];
		$output = $captured['stdout'] . "\n" . $captured['log'];

		$this->assert_true(
			$result instanceof WP_Error && 'internal_error' === $result->get_error_code(),
			'three ownership-UUID collisions return internal_error'
		);
		$this->assert_true( Repo::OWNERSHIP_ID_ATTEMPTS === $attempts, 'exactly three allocation attempts were made' );
		$this->assert_true( 3 === $attempts, 'the attempt budget is three, not more and not fewer' );
		$this->assert_true( 3 === count( $candidates ), 'every generated candidate was captured' );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && null === Repo::load( self::PORTAL_HALL_A )['site_ownership_id'],
			'a collision leaves the ownership ID null'
		);

		$disclosed = [];
		$haystacks = [
			$result instanceof WP_Error ? (string) $result->get_error_code() : '',
			$result instanceof WP_Error ? (string) $result->get_error_message() : '',
			$output,
		];
		foreach ( array_merge( [ $taken ], $candidates ) as $uuid ) {
			foreach ( $haystacks as $text ) {
				if ( '' !== $uuid && false !== strpos( $text, $uuid ) ) {
					$disclosed[] = $uuid;
				}
			}
		}
		$this->assert_true( [] === $disclosed, 'the failure never discloses a colliding or generated ownership UUID' );
		$rival_after = Repo::load( self::PORTAL_HALL_B );
		$this->assert_true(
			is_array( $rival_after ) && ProvisioningCanonicalRequest::is_uuid( $rival_after['site_ownership_id'] ),
			'the rival Hall still holds an ownership ID after the collisions'
		);
		$this->assert_true(
			is_array( $rival_row ) && is_array( $rival_after )
				&& $rival_row['operation_id'] === $rival_after['operation_id'],
			'the rival Hall\'s command identity is untouched'
		);

		// After the obstruction, allocation succeeds on the first attempt.
		$clean = Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$this->assert_true(
			is_array( $clean ) && ProvisioningCanonicalRequest::is_uuid( $clean['site_ownership_id'] ),
			'allocation succeeds once the collision is removed'
		);
		$this->assert_true(
			is_array( $clean ) && is_array( $rival_after )
				&& $rival_after['site_ownership_id'] !== $clean['site_ownership_id'],
			'the allocated ID differs from the one another Hall holds'
		);

		$ordinary = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_C,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3432',
					'site_slug'      => 'ordinary-failure',
					'hall_name'      => 'Ordinary Failure',
				]
			)
		);
		Repo::insert_first_command( $ordinary );
		$ordinary_fence = [ 'operation_id' => $ordinary['operation_id'], 'intent_sequence' => $ordinary['intent_sequence'] ];
		$failed_writes  = 0;
		$break_write    = static function ( $query ) use ( &$failed_writes ) {
			$query = (string) $query;
			if ( 1 === preg_match( '/SET site_ownership_id = \'[0-9a-f-]{36}\'/', $query ) ) {
				$failed_writes++;

				return 'UPDATE zz_bc_absent_operations SET site_ownership_id = 1';
			}

			return $query;
		};
		add_filter( 'query', $break_write, 1 );
		try {
			$ordinary_result = Repo::assign_site_ownership_id( self::PORTAL_HALL_C, $ordinary_fence );
		} finally {
			remove_filter( 'query', $break_write, 1 );
		}
		$this->assert_true(
			$ordinary_result instanceof WP_Error && 'state_write_failed' === $ordinary_result->get_error_code(),
			'a non-UNIQUE write failure is not treated as an ownership collision'
		);
		$this->assert_true( 1 === $failed_writes, 'an ordinary write failure spends one attempt, not three' );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_C ) ) && null === Repo::load( self::PORTAL_HALL_C )['site_ownership_id'],
			'the ordinary write failure left the ownership ID null'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	private function test_blog_attachment(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		// Attachment REQUIRES an ownership ID: a bound site with no permanent
		// ownership identity is an impossible row.
		$premature = Repo::attach_blog_id( self::PORTAL_HALL_A, $this->hall_a, $fence );
		$this->assert_true(
			$premature instanceof WP_Error,
			'attachment is refused before an ownership ID exists'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && null === Repo::load( self::PORTAL_HALL_A )['blog_id'],
			'the refused attachment bound nothing'
		);

		Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$attached = Repo::attach_blog_id( self::PORTAL_HALL_A, $this->hall_a, $fence );
		$this->assert_true(
			is_array( $attached ) && $this->hall_a === $attached['blog_id'],
			'a verified site is attached under the current fence'
		);

		$repeat = Repo::attach_blog_id( self::PORTAL_HALL_A, $this->hall_a, $fence );
		$this->assert_true(
			is_array( $repeat ) && $this->hall_a === $repeat['blog_id'],
			're-attaching the same site is idempotent'
		);

		// An already-bound row is never re-pointed at a different site.
		$repoint = Repo::attach_blog_id( self::PORTAL_HALL_A, $this->hall_b, $fence );
		$this->assert_true( $repoint instanceof WP_Error, 'a bound row is not re-pointed at another site' );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && $this->hall_a === Repo::load( self::PORTAL_HALL_A )['blog_id'],
			'the refused re-point changed nothing'
		);

		// UNIQUE(blog_id): a second Hall claiming the SAME site.
		$rival = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'portal_hall_id' => self::PORTAL_HALL_B,
					'operation_id'   => '3f2504e0-4f89-41d3-9a0c-0305e82c3441',
					'site_slug'      => 'rival-address',
				]
			)
		);
		Repo::insert_first_command( $rival );
		$rival_fence = [ 'operation_id' => $rival['operation_id'], 'intent_sequence' => $rival['intent_sequence'] ];
		Repo::assign_site_ownership_id( self::PORTAL_HALL_B, $rival_fence );

		$before_a = Repo::load( self::PORTAL_HALL_A );
		$before_b = Repo::load( self::PORTAL_HALL_B );

		$collision = Repo::attach_blog_id( self::PORTAL_HALL_B, $this->hall_a, $rival_fence );
		$this->assert_true(
			$collision instanceof WP_Error && 'site_ownership_unproven' === $collision->get_error_code(),
			'a UNIQUE(blog_id) collision returns site_ownership_unproven'
		);
		$this->assert_true( $before_a === Repo::load( self::PORTAL_HALL_A ), 'the collision left the owning Hall row untouched' );
		$this->assert_true( $before_b === Repo::load( self::PORTAL_HALL_B ), 'the collision left the claiming Hall row untouched' );

		$message = $collision instanceof WP_Error ? (string) $collision->get_error_message() : '';
		$this->assert_true(
			false === strpos( $message, (string) self::PORTAL_HALL_A )
				&& false === strpos( $message, (string) $this->hall_a )
				&& false === strpos( $message, 'sunrise-hall' ),
			'the collision discloses no foreign Hall ID, blog ID, slug or ownership ID'
		);

		$this->assert_true(
			Repo::attach_blog_id( self::PORTAL_HALL_B, 0, $rival_fence ) instanceof WP_Error,
			'an invalid site ID is refused before any statement'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	private function test_error_category_lifecycle(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		$recorded = Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'site_create_failed' );
		$this->assert_true(
			is_array( $recorded ) && 'site_create_failed' === $recorded['last_error_category'],
			'an allowlisted failure category is recorded under the current fence'
		);

		$cleared = Repo::clear_error_category( self::PORTAL_HALL_A, $fence );
		$this->assert_true(
			is_array( $cleared ) && null === $cleared['last_error_category'],
			'the failure category is cleared with a guarded write and read-back'
		);

		$this->assert_true(
			Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'not_a_category' ) instanceof WP_Error,
			'a category outside the closed allowlist is refused'
		);
		$this->assert_true(
			Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'conflict' ) instanceof WP_Error,
			'a response-only category is not persistable'
		);
		$this->assert_true(
			Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'superseded' ) instanceof WP_Error,
			'superseded is not a persistable failure category'
		);

		// A stale fence may not stamp its category onto a row that has moved on.
		$newer = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3451',
				'intent_sequence' => 30,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $newer );

		$stale = Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'sync_failed' );
		$this->assert_true(
			$stale instanceof WP_Error && 'unverified' === $stale->get_error_code(),
			'a stale fence cannot record a failure category after the write has been attempted'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && null === Repo::load( self::PORTAL_HALL_A )['last_error_category'],
			'the stale attempt left the error field null'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	private function test_confirmation(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'readiness_failed' );

		$confirmed = Repo::confirm_enforced_sequence( self::PORTAL_HALL_A, $fence );
		$this->assert_true( is_array( $confirmed ), 'confirmation succeeds under the current fence' );
		$this->assert_true(
			is_array( $confirmed ) && $confirmed['enforced_sequence'] === $confirmed['intent_sequence'],
			'confirmation advances the enforced sequence to the intent sequence'
		);
		$this->assert_true(
			is_array( $confirmed ) && null === $confirmed['last_error_category'],
			'confirmation clears any stale failure category'
		);
		$this->assert_true( Repo::is_confirmed( (array) $confirmed ), 'the row now reports as confirmed' );

		// A stale fence cannot confirm.
		$newer = ProvisioningCanonicalRequest::validate(
			[
				'portal_hall_id'  => self::PORTAL_HALL_A,
				'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3461',
				'intent_sequence' => 40,
				'desired_state'   => 'suspended',
				'site_slug'       => null,
				'hall_name'       => null,
				'portal_actor_id' => 42,
			]
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $newer );
		$new_row = Repo::load( self::PORTAL_HALL_A );

		/*
		 * The previous enforcement is RETAINED rather than zeroed.
		 * `enforced_sequence` records the last intent this Hall actually
		 * enforced, and the only thing that makes a command "done" is equality
		 * with the CURRENT `intent_sequence` — which is exactly what
		 * `already_applied` is allowed to depend on. Resetting it would discard
		 * the one durable fact about what was last enforced, and the documented
		 * invariant is `enforced_sequence <= intent_sequence`, which a strictly
		 * higher replacement sequence always keeps true.
		 */
		$this->assert_true(
			is_array( $new_row ) && $request['intent_sequence'] === $new_row['enforced_sequence'],
			'a newly accepted command is unenforced while the previous enforcement is retained'
		);
		$this->assert_true( ! Repo::is_confirmed( (array) $new_row ), 'the newly accepted command is not confirmed' );

		$stale = Repo::confirm_enforced_sequence( self::PORTAL_HALL_A, $fence );
		$this->assert_true(
			$stale instanceof WP_Error && 'unverified' === $stale->get_error_code(),
			'a stale fence cannot confirm enforcement after the write has been attempted'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) )
				&& $request['intent_sequence'] === Repo::load( self::PORTAL_HALL_A )['enforced_sequence'],
			'the stale confirmation changed nothing'
		);

		// A write that cannot be read back is never reported as success.
		$new_fence = [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => $newer['intent_sequence'] ];
		$break     = static function ( $query ) {
			return 1 === preg_match( '/^\s*UPDATE\s+\S*bingo_portal_provisioning_operations/i', (string) $query )
				? 'UPDATE zz_bc_absent_operations SET x = 1'
				: $query;
		};
		add_filter( 'query', $break, 1 );
		try {
			$failed = Repo::confirm_enforced_sequence( self::PORTAL_HALL_A, $new_fence );
		} finally {
			remove_filter( 'query', $break, 1 );
		}
		$this->assert_true(
			$failed instanceof WP_Error && 'confirmation_failed' === $failed->get_error_code(),
			'a failed confirmation write is reported as confirmation_failed, never as success'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) )
				&& $newer['intent_sequence'] !== Repo::load( self::PORTAL_HALL_A )['enforced_sequence']
				&& ! Repo::is_confirmed( (array) Repo::load( self::PORTAL_HALL_A ) ),
			'the failed confirmation enforced nothing'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * A reported write failure never skips the authoritative read-back.
	 *
	 * Two observations per mutation: a driver WP_Error, and a thrown
	 * exception. If the write actually landed, the proven state wins. If the
	 * old state remains, the documented failure is returned. A stale fence is
	 * never success.
	 */
	private function test_read_back_decides_failed_writes(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];

		$newer = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3531',
					'intent_sequence' => 20,
					'site_slug'       => 'sunrise-readback',
					'hall_name'       => 'Sunrise Readback',
				]
			)
		);

		$applied = Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $newer );
		$this->assert_true( is_array( $applied ), 'the control replacement stores the newer command' );

		$selects = 0;
		$throw   = function ( $query ) use ( &$selects ) {
			$query = (string) $query;
			if ( 1 === preg_match( '/^\s*UPDATE\s+\S*bingo_portal_provisioning_operations/i', $query ) ) {
				throw new RuntimeException( 'SELECT leaked from a write exception' );
			}
			if ( 1 === preg_match( '/SELECT .+bingo_portal_provisioning_operations/i', $query ) ) {
				$selects++;
			}

			return $query;
		};
		add_filter( 'query', $throw, 1 );
		try {
			$thrown = Repo::record_error_category( self::PORTAL_HALL_A, [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => 20 ], 'sync_failed' );
		} catch ( Throwable $escaped ) {
			$thrown = $escaped;
		} finally {
			remove_filter( 'query', $throw, 1 );
		}
		$this->assert_true(
			$thrown instanceof WP_Error && ! $thrown instanceof Throwable,
			'a thrown write is contained and still returns a fixed error'
		);
		$this->assert_true( $selects > 0, 'read-back still ran after a thrown write exception' );
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && null === Repo::load( self::PORTAL_HALL_A )['last_error_category'],
			'the thrown write left the old error category in place'
		);
		$this->assert_true(
			$thrown instanceof WP_Error && false === strpos( (string) $thrown->get_error_message(), 'SELECT leaked' ),
			'the thrown write discloses no exception text'
		);

		$selects = 0;
		$fail    = function ( $query ) use ( &$selects ) {
			$query = (string) $query;
			if ( 1 === preg_match( '/^\s*UPDATE\s+\S*bingo_portal_provisioning_operations/i', $query ) ) {
				return 'UPDATE zz_bc_absent_operations SET x = 1';
			}
			if ( 1 === preg_match( '/SELECT .+bingo_portal_provisioning_operations/i', $query ) ) {
				$selects++;
			}

			return $query;
		};
		add_filter( 'query', $fail, 1 );
		try {
			$failed = Repo::record_error_category( self::PORTAL_HALL_A, [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => 20 ], 'sync_failed' );
		} finally {
			remove_filter( 'query', $fail, 1 );
		}
		$this->assert_true(
			$failed instanceof WP_Error && 'state_write_failed' === $failed->get_error_code(),
			'a failed error-category write with the old state still present is state_write_failed'
		);
		$this->assert_true( $selects > 0, 'read-back still ran after a returned database failure' );

		$apply_then_fail = null;
		$apply_then_fail = static function ( $query ) use ( &$apply_then_fail ) {
			$query = (string) $query;
			if ( 1 === preg_match( '/^\s*UPDATE\s+\S*bingo_portal_provisioning_operations/i', $query )
				&& false !== strpos( $query, 'last_error_category' ) ) {
				remove_filter( 'query', $apply_then_fail, 1 );
				$GLOBALS['wpdb']->query( $query );
				add_filter( 'query', $apply_then_fail, 1 );
				throw new RuntimeException( 'driver reported failure after apply' );
			}

			return $query;
		};
		add_filter( 'query', $apply_then_fail, 1 );
		try {
			$proven = Repo::record_error_category( self::PORTAL_HALL_A, [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => 20 ], 'sync_failed' );
		} finally {
			remove_filter( 'query', $apply_then_fail, 1 );
		}
		$this->assert_true(
			is_array( $proven ) && 'sync_failed' === $proven['last_error_category'],
			'read-back of the requested state wins even when the driver reported failure'
		);

		$stale_fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		$stale       = Repo::clear_error_category( self::PORTAL_HALL_A, $stale_fence );
		$this->assert_true(
			$stale instanceof WP_Error && 'unverified' === $stale->get_error_code(),
			'a stale fence is never reported as a successful error-category write'
		);
		$this->assert_true(
			is_array( Repo::load( self::PORTAL_HALL_A ) ) && 'sync_failed' === Repo::load( self::PORTAL_HALL_A )['last_error_category'],
			'the stale fence left the proven category in place'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * After execute() has been invoked, a superseded current command is
	 * `unverified`. Returning success or `lock_unavailable` would either
	 * license the newer command or claim nothing changed.
	 */
	private function test_post_mutation_fence_is_unverified(): void {
		$suspend = static function ( string $operation_id, int $sequence ) {
			return ProvisioningCanonicalRequest::validate(
				[
					'portal_hall_id'  => self::PORTAL_HALL_A,
					'operation_id'    => $operation_id,
					'intent_sequence' => $sequence,
					'desired_state'   => 'suspended',
					'site_slug'       => null,
					'hall_name'       => null,
					'portal_actor_id' => 42,
				]
			);
		};

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$next = ProvisioningCanonicalRequest::validate(
			$this->command(
				[
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3701',
					'intent_sequence' => 20,
					'site_slug'       => 'sunrise-next',
					'hall_name'       => 'Sunrise Next',
				]
			)
		);
		Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $next );
		$next_fence = [ 'operation_id' => $next['operation_id'], 'intent_sequence' => $next['intent_sequence'] ];
		$this->assert_post_mutation_unverified(
			'SET site_slug =',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3702', 30 ),
			static function () use ( $next_fence ) {
				return Repo::replace_unbound_slug( self::PORTAL_HALL_A, $next_fence, 'sunrise-next' );
			},
			'replace_unbound_slug'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		$this->assert_post_mutation_unverified(
			'SET site_ownership_id =',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3703', 30 ),
			static function () use ( $fence ) {
				return Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
			},
			'assign_site_ownership_id'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$hall_a = $this->hall_a;
		$this->assert_post_mutation_unverified(
			'SET blog_id =',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3704', 30 ),
			static function () use ( $fence, $hall_a ) {
				return Repo::attach_blog_id( self::PORTAL_HALL_A, $hall_a, $fence );
			},
			'attach_blog_id'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		$this->assert_post_mutation_unverified(
			'SET last_error_category =',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3705', 30 ),
			static function () use ( $fence ) {
				return Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'sync_failed' );
			},
			'record_error_category'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		Repo::record_error_category( self::PORTAL_HALL_A, $fence, 'sync_failed' );
		$this->assert_post_mutation_unverified(
			'SET last_error_category =',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3706', 30 ),
			static function () use ( $fence ) {
				return Repo::clear_error_category( self::PORTAL_HALL_A, $fence );
			},
			'clear_error_category'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
		$first = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $first );
		$fence = [ 'operation_id' => $first['operation_id'], 'intent_sequence' => $first['intent_sequence'] ];
		$this->assert_post_mutation_unverified(
			'SET enforced_sequence = intent_sequence',
			$suspend( '3f2504e0-4f89-41d3-9a0c-0305e82c3707', 30 ),
			static function () use ( $fence ) {
				return Repo::confirm_enforced_sequence( self::PORTAL_HALL_A, $fence );
			},
			'confirm_enforced_sequence'
		);

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/**
	 * @param array<string,mixed>|\WP_Error $newer
	 */
	private function assert_post_mutation_unverified( string $match, $newer, callable $operation, string $label ): void {
		$this->assert_true( is_array( $newer ), "{$label} newer command validates" );
		if ( ! is_array( $newer ) ) {
			return;
		}

		$planted = null;
		$filter  = null;
		$filter  = static function ( $query ) use ( &$filter, &$planted, $match, $newer ) {
			$query = (string) $query;
			if ( null !== $planted ) {
				return $query;
			}
			if ( 0 !== stripos( ltrim( $query ), 'UPDATE' ) || false === strpos( $query, $match ) ) {
				return $query;
			}
			remove_filter( 'query', $filter, 1 );
			try {
				$GLOBALS['wpdb']->query( $query );
				$current = Repo::load( self::PORTAL_HALL_A );
				if ( is_array( $current ) ) {
					Repo::replace_command( $current, $newer );
				}
				$loaded  = Repo::load( self::PORTAL_HALL_A );
				$planted = is_array( $loaded ) ? $loaded : null;
			} finally {
				add_filter( 'query', $filter, 1 );
			}

			return 'SELECT 1';
		};

		add_filter( 'query', $filter, 1 );
		try {
			$result = $operation();
		} finally {
			remove_filter( 'query', $filter, 1 );
		}

		$this->assert_true(
			$result instanceof WP_Error && 'unverified' === $result->get_error_code(),
			"{$label} returns unverified after a post-write fence change"
		);
		$this->assert_true(
			$result instanceof WP_Error && 'lock_unavailable' !== $result->get_error_code(),
			"{$label} does not report lock_unavailable after a mutation may have landed"
		);

		$after = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true(
			is_array( $planted )
				&& is_array( $after )
				&& $planted['operation_id'] === $after['operation_id']
				&& $planted['intent_sequence'] === $after['intent_sequence']
				&& $planted['desired_state'] === $after['desired_state']
				&& $planted['site_slug'] === $after['site_slug']
				&& $planted['site_ownership_id'] === $after['site_ownership_id']
				&& $planted['blog_id'] === $after['blog_id']
				&& $planted['last_error_category'] === $after['last_error_category']
				&& $planted['enforced_sequence'] === $after['enforced_sequence'],
			"{$label} did not mutate the newer command"
		);
	}

	/* ------------------------------------------------------------------ *
	 * Durable ownership
	 * ------------------------------------------------------------------ */

	private function test_durable_ownership_three_facts(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		$row = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true( false === Repo::has_durable_ownership( (array) $row ), 'no blog_id means no durable ownership' );

		Repo::assign_site_ownership_id( self::PORTAL_HALL_A, $fence );
		$row = Repo::load( self::PORTAL_HALL_A );
		$this->assert_true(
			false === Repo::has_durable_ownership( (array) $row ),
			'an ownership ID alone does not establish durable ownership'
		);

		Repo::attach_blog_id( self::PORTAL_HALL_A, $this->hall_a, $fence );
		$row = Repo::load( self::PORTAL_HALL_A );

		// The Hall site has no options table yet, so the witness cannot be read.
		$unreadable = Repo::has_durable_ownership( (array) $row );
		$this->assert_true(
			$unreadable instanceof WP_Error && 'site_ownership_unproven' === $unreadable->get_error_code(),
			'an unreadable witness is reported as unproven, never as "not owned"'
		);

		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'hall' => $this->hall_a ] );
		$this->owns_hall_options[] = $this->hall_a;

		$this->assert_true(
			false === Repo::has_durable_ownership( (array) $row ),
			'an absent witness means no durable ownership'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'hall' => $this->hall_a, 'value' => '3f2504e0-4f89-41d3-9a0c-0305e82c34aa' ] );
		$this->assert_true(
			false === Repo::has_durable_ownership( (array) $row ),
			'a DIFFERENT witness value means no durable ownership'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'hall' => $this->hall_a, 'value' => (string) $row['site_ownership_id'] ] );
		$this->assert_true(
			true === Repo::has_durable_ownership( (array) $row ),
			'all three facts agreeing establishes durable ownership'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'hall' => $this->hall_a, 'value' => (string) $row['operation_id'] ] );
		$this->assert_true(
			false === Repo::has_durable_ownership( (array) $row ),
			'the Portal command UUID is not an ownership witness'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'hall' => $this->hall_a, 'value' => 'not-a-uuid' ] );
		$this->assert_true(
			false === Repo::has_durable_ownership( (array) $row ),
			'a malformed witness is not ownership'
		);

		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'hall' => $this->hall_a ] );
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/* ------------------------------------------------------------------ *
	 * Mutex
	 * ------------------------------------------------------------------ */

	private function test_mutex_scope_and_reentrancy(): void {
		$mutex = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the provisioning mutex is acquired for one Hall' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		try {
			$this->assert_true( $mutex->isStillHeld(), 'the server confirms this session holds the mutex' );
			$this->assert_true( self::PORTAL_HALL_A === $mutex->portalHallId(), 'the mutex reports the Hall it covers' );
			$this->assert_true( $mutex->connectionId() > 0, 'the mutex recorded its acquiring connection' );

			$nested = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
			$this->assert_true(
				$nested instanceof WP_Error && ProvisioningMutex::REENTRANT === $nested->get_error_code(),
				'a nested acquisition for the same Hall is refused before GET_LOCK'
			);

			// A DIFFERENT Hall is never serialized against this one.
			$other = ProvisioningMutex::acquire( self::PORTAL_HALL_B );
			$this->assert_true( $other instanceof ProvisioningMutex, 'a different Hall acquires its own mutex concurrently' );
			if ( $other instanceof ProvisioningMutex ) {
				$this->assert_true( $other->isStillHeld(), 'both Hall mutexes are held at once' );
				$other->release();
				$this->assert_true( ! $other->isStillHeld(), 'the other Hall mutex is released' );
			}

			$this->assert_true( $mutex->isStillHeld(), 'releasing another Hall did not release this one' );

			$this->assert_true(
				ProvisioningMutex::acquire( 0 ) instanceof WP_Error,
				'an invalid Hall ID is refused before any statement'
			);
			$this->assert_true(
				ProvisioningMutex::acquire( '7001' ) instanceof WP_Error,
				'a stringly-typed Hall ID is refused rather than coerced'
			);
		} finally {
			$mutex->release();
		}

		$this->assert_true( ! $mutex->isStillHeld(), 'the mutex is released' );
		$mutex->release();
		$this->assert_true( true, 'a repeated release is safe' );

		$again = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
		$this->assert_true( $again instanceof ProvisioningMutex, 'the Hall can be re-acquired after a confirmed release' );
		if ( $again instanceof ProvisioningMutex ) {
			$again->release();
		}
	}

	/**
	 * Throwing or unreadable lock probes never escape, emit no SQL/driver
	 * diagnostic, restore the previous wpdb display flags, and an ambiguous
	 * acquisition keeps the request-local reservation.
	 */
	private function test_mutex_contains_failures(): void {
		global $wpdb;

		$original_suppress = $wpdb->suppress_errors;
		$original_show     = $wpdb->show_errors;

		try {
			$wpdb->suppress_errors( false );
			$wpdb->show_errors( true );

			$throw_get = static function ( $query ) {
				if ( false !== stripos( (string) $query, 'GET_LOCK' ) ) {
					throw new RuntimeException( 'SELECT GET_LOCK leaked' );
				}

				return $query;
			};
			add_filter( 'query', $throw_get, 1 );
			$captured = $this->with_output_capture(
				static function () {
					try {
						return ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_THROW );
					} catch ( Throwable $escaped ) {
						return $escaped;
					}
				}
			);
			remove_filter( 'query', $throw_get, 1 );
			$thrown = $captured['result'];
			$this->assert_true(
				$thrown instanceof WP_Error && 'lock_unavailable' === $thrown->get_error_code(),
				'a throwing GET_LOCK is contained as lock_unavailable'
			);
			$this->assert_true(
				$thrown instanceof WP_Error && false === strpos( (string) $thrown->get_error_message(), 'GET_LOCK' ),
				'a throwing GET_LOCK discloses no SQL'
			);
			$this->assert_mutex_diagnostics_silent( $captured, 'a throwing GET_LOCK' );
			$this->assert_wpdb_display( false, true, 'display restored after a throwing GET_LOCK' );
			$held_after_throw = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_THROW );
			$this->assert_true(
				$held_after_throw instanceof WP_Error && ProvisioningMutex::REENTRANT === $held_after_throw->get_error_code(),
				'an ambiguous GET_LOCK throw keeps the request-local reservation'
			);

			$unread_get = static function ( $query ) {
				return false !== stripos( (string) $query, 'GET_LOCK' )
					? 'SELECT 1 FROM zz_bc_absent_mutex'
					: $query;
			};
			add_filter( 'query', $unread_get, 1 );
			$captured = $this->with_output_capture(
				static function () {
					return ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_UNREAD );
				}
			);
			remove_filter( 'query', $unread_get, 1 );
			$unreadable = $captured['result'];
			$this->assert_true(
				$unreadable instanceof WP_Error && 'lock_unavailable' === $unreadable->get_error_code(),
				'an unreadable GET_LOCK is lock_unavailable'
			);
			$this->assert_mutex_diagnostics_silent( $captured, 'an unreadable GET_LOCK' );
			$this->assert_wpdb_display( false, true, 'display restored after an unreadable GET_LOCK' );
			$this->assert_true(
				ProvisioningMutex::REENTRANT === ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_UNREAD )->get_error_code(),
				'an unreadable GET_LOCK keeps the request-local reservation'
			);

			$timeout = static function ( $query ) {
				return false !== stripos( (string) $query, 'GET_LOCK' )
					? 'SELECT 0 AS acquired, CONNECTION_ID() AS connection_id'
					: $query;
			};
			add_filter( 'query', $timeout, 1 );
			try {
				$timed_out = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_TIMEOUT );
			} finally {
				remove_filter( 'query', $timeout, 1 );
			}
			$this->assert_true(
				$timed_out instanceof WP_Error && 'lock_unavailable' === $timed_out->get_error_code(),
				'a proven GET_LOCK timeout is lock_unavailable'
			);
			$this->assert_wpdb_display( false, true, 'display restored after a proven GET_LOCK timeout' );
			$after_timeout = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_TIMEOUT );
			$this->assert_true(
				$after_timeout instanceof ProvisioningMutex,
				'a proven timeout clears the request-local reservation'
			);
			if ( $after_timeout instanceof ProvisioningMutex ) {
				$after_timeout->release();
			}

			$null_get = static function ( $query ) {
				return false !== stripos( (string) $query, 'GET_LOCK' )
					? 'SELECT NULL AS acquired, CONNECTION_ID() AS connection_id'
					: $query;
			};
			add_filter( 'query', $null_get, 1 );
			try {
				$ambiguous = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_NULL );
			} finally {
				remove_filter( 'query', $null_get, 1 );
			}
			$this->assert_true(
				$ambiguous instanceof WP_Error && 'lock_unavailable' === $ambiguous->get_error_code(),
				'a NULL GET_LOCK result is lock_unavailable'
			);
			$this->assert_true(
				ProvisioningMutex::REENTRANT === ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_NULL )->get_error_code(),
				'an ambiguous NULL GET_LOCK keeps the request-local reservation'
			);

			$no_session = static function ( $query ) {
				$query = (string) $query;
				if ( false !== stripos( $query, 'GET_LOCK' ) && false !== stripos( $query, 'CONNECTION_ID' ) ) {
					return (string) preg_replace( '/CONNECTION_ID\(\)/', 'NULL', $query, 1 );
				}
				if ( false !== stripos( $query, 'RELEASE_LOCK' ) ) {
					return 'SELECT NULL';
				}

				return $query;
			};
			add_filter( 'query', $no_session, 1 );
			$captured = $this->with_output_capture(
				static function () {
					return ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_NO_SESSION );
				}
			);
			remove_filter( 'query', $no_session, 1 );
			$unidentified = $captured['result'];
			$this->assert_true(
				$unidentified instanceof WP_Error && 'lock_unavailable' === $unidentified->get_error_code(),
				'GET_LOCK without a usable session identity is lock_unavailable'
			);
			$this->assert_mutex_diagnostics_silent( $captured, 'GET_LOCK without a usable session identity' );
			$this->assert_true(
				ProvisioningMutex::REENTRANT === ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_NO_SESSION )->get_error_code(),
				'an unproved release after GET_LOCK success keeps the request-local reservation'
			);

			$held = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_HELD );
			$this->assert_true( $held instanceof ProvisioningMutex, 'a later Hall can still be acquired' );
			$this->assert_wpdb_display( false, true, 'display restored after a successful acquire' );
			if ( $held instanceof ProvisioningMutex ) {
				$throw_used = static function ( $query ) {
					if ( false !== stripos( (string) $query, 'IS_USED_LOCK' ) ) {
						throw new RuntimeException( 'SELECT IS_USED_LOCK leaked' );
					}

					return $query;
				};
				add_filter( 'query', $throw_used, 1 );
				$captured = $this->with_output_capture(
					static function () use ( $held ) {
						try {
							return $held->isStillHeld();
						} catch ( Throwable $escaped ) {
							return $escaped;
						}
					}
				);
				remove_filter( 'query', $throw_used, 1 );
				$probe = $captured['result'];
				$this->assert_true( false === $probe, 'a throwing IS_USED_LOCK is contained as not-held' );
				$this->assert_mutex_diagnostics_silent( $captured, 'a throwing IS_USED_LOCK' );
				$this->assert_wpdb_display( false, true, 'display restored after a throwing IS_USED_LOCK' );

				$unread_used = static function ( $query ) {
					return false !== stripos( (string) $query, 'IS_USED_LOCK' )
						? 'SELECT 1 FROM zz_bc_absent_mutex'
						: $query;
				};
				add_filter( 'query', $unread_used, 1 );
				$captured = $this->with_output_capture(
					static function () use ( $held ) {
						return $held->isStillHeld();
					}
				);
				remove_filter( 'query', $unread_used, 1 );
				$unread_probe = $captured['result'];
				$this->assert_true( false === $unread_probe, 'an unreadable IS_USED_LOCK is not-held' );
				$this->assert_mutex_diagnostics_silent( $captured, 'an unreadable IS_USED_LOCK' );
				$this->assert_wpdb_display( false, true, 'display restored after an unreadable IS_USED_LOCK' );
				$this->assert_true( $held->isStillHeld(), 'the mutex is still held once the probe can be asked' );
				$held->release();
				$this->assert_true( ! $held->isStillHeld(), 'a confirmed release still clears the mutex' );
				$this->assert_wpdb_display( false, true, 'display restored after a successful release' );
				$again = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_HELD );
				$this->assert_true( $again instanceof ProvisioningMutex, 'the Hall can be re-acquired after a confirmed release' );
				if ( $again instanceof ProvisioningMutex ) {
					$again->release();
				}
			}

			$wpdb->suppress_errors( true );
			$wpdb->show_errors( false );
			$throw_hidden = static function ( $query ) {
				if ( false !== stripos( (string) $query, 'GET_LOCK' ) ) {
					throw new RuntimeException( 'SELECT GET_LOCK leaked' );
				}

				return $query;
			};
			add_filter( 'query', $throw_hidden, 1 );
			try {
				$hidden = ProvisioningMutex::acquire( self::PORTAL_HALL_MUTEX_RESTORE );
			} catch ( Throwable $escaped ) {
				$hidden = $escaped;
			} finally {
				remove_filter( 'query', $throw_hidden, 1 );
			}
			$this->assert_true(
				$hidden instanceof WP_Error && 'lock_unavailable' === $hidden->get_error_code(),
				'a throwing GET_LOCK is still contained when display was already off'
			);
			$this->assert_wpdb_display( true, false, 'suppressed display restored after a throwing GET_LOCK' );
		} finally {
			$wpdb->suppress_errors( $original_suppress );
			$wpdb->show_errors( $original_show );
		}
	}

	/**
	 * ADR-042 requires the provisioning mutex FIRST and the sync lock SECOND.
	 */
	private function test_mutex_ordering_is_enforced(): void {
		$this->assert_true(
			! \BingoCommerce\PortalIntegration\PortalSyncLock::hasAnyHeld(),
			'no synchronization lock is held to begin with'
		);

		$sync = \BingoCommerce\PortalIntegration\PortalSyncLock::acquire( $this->hall_a );
		$this->assert_true( $sync instanceof \BingoCommerce\PortalIntegration\PortalSyncLock, 'a synchronization lock is acquired' );
		if ( ! $sync instanceof \BingoCommerce\PortalIntegration\PortalSyncLock ) {
			return;
		}

		try {
			$this->assert_true( \BingoCommerce\PortalIntegration\PortalSyncLock::hasAnyHeld(), 'the sync lock reports itself held' );

			$refused = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
			$this->assert_true(
				$refused instanceof WP_Error && ProvisioningMutex::ORDER_VIOLATION === $refused->get_error_code(),
				'acquiring the provisioning mutex while a sync lock is held is refused'
			);
		} finally {
			$sync->release();
		}

		$this->assert_true(
			! \BingoCommerce\PortalIntegration\PortalSyncLock::hasAnyHeld(),
			'the sync lock is released'
		);

		// The correct order works: provisioning first, sync second.
		$mutex = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the provisioning mutex is acquired first' );
		if ( $mutex instanceof ProvisioningMutex ) {
			$second = \BingoCommerce\PortalIntegration\PortalSyncLock::acquire( $this->hall_a );
			$this->assert_true(
				$second instanceof \BingoCommerce\PortalIntegration\PortalSyncLock,
				'the sync lock may then be acquired second'
			);
			if ( $second instanceof \BingoCommerce\PortalIntegration\PortalSyncLock ) {
				$second->release();
			}
			$mutex->release();
		}
	}

	/**
	 * A second PROCESS is refused for the same Hall and admitted for another.
	 *
	 * The parent holds the mutex for the whole child lifetime, so the outcome is
	 * decided by the lock itself and not by any sleep or timing assumption.
	 */
	private function test_mutex_serializes_one_hall_only(): void {
		$mutex = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the parent holds the Hall A mutex' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		try {
			$same = $this->run_worker( self::PORTAL_HALL_A );
			$this->assert_true(
				is_array( $same ) && false === $same['acquired'] && 'lock_unavailable' === $same['code'],
				'a second process is refused the SAME Hall while the parent holds it'
			);

			$different = $this->run_worker( self::PORTAL_HALL_B );
			$this->assert_true(
				is_array( $different ) && true === $different['acquired'],
				'a second process acquires a DIFFERENT Hall concurrently'
			);

			$this->assert_true( $mutex->isStillHeld(), 'the parent still holds its mutex after both children' );
		} finally {
			$mutex->release();
		}

		$after = $this->run_worker( self::PORTAL_HALL_A );
		$this->assert_true(
			is_array( $after ) && true === $after['acquired'],
			'the Hall is available to another process once the parent releases it'
		);
	}

	/** @return array{acquired:bool,code:string}|null */
	private function run_worker( int $portal_hall_id ): ?array {
		$env = [
			'BC_TEST_DB'          => BC_Fixture::$database,
			'BC_WORKER_PORTAL_HALL' => (string) $portal_hall_id,
			'PATH'                => (string) getenv( 'PATH' ),
		];

		$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$pipes       = [];
		$handle      = proc_open(
			[ PHP_BINARY, __DIR__ . '/portal-provisioning-mutex-worker.php' ],
			$descriptors,
			$pipes,
			dirname( __DIR__ ),
			$env
		);

		if ( ! is_resource( $handle ) ) {
			$this->assert_true( false, 'the mutex worker process could not be created' );

			return null;
		}

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $handle );

		if ( 0 !== $code ) {
			$this->assert_true( false, 'the mutex worker exited non-zero: ' . trim( $stderr ) );

			return null;
		}

		$decoded = json_decode( trim( $stdout ), true );

		return is_array( $decoded ) ? [ 'acquired' => (bool) $decoded['acquired'], 'code' => (string) $decoded['code'] ] : null;
	}

	/**
	 * The pre-mutation guard maps a lost fence differently depending on whether
	 * a mutation may already have happened.
	 */
	private function test_guard_failure_mapping(): void {
		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$request = ProvisioningCanonicalRequest::validate( $this->command() );
		Repo::insert_first_command( $request );
		$fence = [ 'operation_id' => $request['operation_id'], 'intent_sequence' => $request['intent_sequence'] ];

		$mutex = ProvisioningMutex::acquire( self::PORTAL_HALL_A );
		$this->assert_true( $mutex instanceof ProvisioningMutex, 'the guard test holds the Hall mutex' );
		if ( ! $mutex instanceof ProvisioningMutex ) {
			return;
		}

		try {
			$this->assert_true(
				true === Repo::guard( $mutex, self::PORTAL_HALL_A, $fence, false ),
				'the guard passes while the mutex and fence both hold'
			);
			$this->assert_true(
				true === Repo::guard( $mutex, self::PORTAL_HALL_A, $fence, true ),
				'the guard passes for a post-mutation check too'
			);

			$this->assert_true(
				Repo::guard( $mutex, self::PORTAL_HALL_B, $fence, false ) instanceof WP_Error,
				'a mutex covering another Hall cannot guard this one'
			);

			// Make the fence stale by accepting a newer command.
			$newer = ProvisioningCanonicalRequest::validate(
				[
					'portal_hall_id'  => self::PORTAL_HALL_A,
					'operation_id'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3471',
					'intent_sequence' => 50,
					'desired_state'   => 'suspended',
					'site_slug'       => null,
					'hall_name'       => null,
					'portal_actor_id' => 42,
				]
			);
			Repo::replace_command( (array) Repo::load( self::PORTAL_HALL_A ), $newer );

			$before_mutation = Repo::guard( $mutex, self::PORTAL_HALL_A, $fence, false );
			$this->assert_true(
				$before_mutation instanceof WP_Error && 'lock_unavailable' === $before_mutation->get_error_code(),
				'a lost fence BEFORE any mutation maps to lock_unavailable'
			);

			$after_mutation = Repo::guard( $mutex, self::PORTAL_HALL_A, $fence, true );
			$this->assert_true(
				$after_mutation instanceof WP_Error && 'unverified' === $after_mutation->get_error_code(),
				'a lost fence AFTER a possible mutation maps to unverified'
			);

			// An unreadable row is equally a guard failure, mapped the same way.
			$new_fence = [ 'operation_id' => $newer['operation_id'], 'intent_sequence' => $newer['intent_sequence'] ];
			$break     = static function ( $query ) {
				return 1 === preg_match( '/FROM\s+\S*bingo_portal_provisioning_operations/i', (string) $query )
					? 'SELECT 1 FROM zz_bc_absent_operations'
					: $query;
			};
			add_filter( 'query', $break, 1 );
			try {
				$unreadable_pre  = Repo::guard( $mutex, self::PORTAL_HALL_A, $new_fence, false );
				$unreadable_post = Repo::guard( $mutex, self::PORTAL_HALL_A, $new_fence, true );
			} finally {
				remove_filter( 'query', $break, 1 );
			}
			$this->assert_true(
				$unreadable_pre instanceof WP_Error && 'lock_unavailable' === $unreadable_pre->get_error_code(),
				'an unverifiable fence before mutation maps to lock_unavailable'
			);
			$this->assert_true(
				$unreadable_post instanceof WP_Error && 'unverified' === $unreadable_post->get_error_code(),
				'an unverifiable fence after a possible mutation maps to unverified'
			);
		} finally {
			$mutex->release();
		}

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );
	}

	/* ------------------------------------------------------------------ *
	 * Disclosure
	 * ------------------------------------------------------------------ */

	private function test_errors_disclose_nothing(): void {
		global $wpdb;

		$this->fixture_operation( self::OP_DELETE_OPERATIONS_ROWS );

		$table     = ProvisioningMigrations::operations_table();
		$collected = [];

		$break = static function ( $query ) {
			return 1 === preg_match( '/bingo_portal_provisioning_operations/i', (string) $query )
				? 'SELECT 1 FROM zz_bc_absent_operations'
				: $query;
		};
		add_filter( 'query', $break, 1 );
		try {
			$collected[] = Repo::load( self::PORTAL_HALL_A );
			$collected[] = Repo::insert_first_command( ProvisioningCanonicalRequest::validate( $this->command() ) );
		} finally {
			remove_filter( 'query', $break, 1 );
		}

		$collected[] = ProvisioningCanonicalRequest::validate( $this->command( [ 'site_slug' => 'Bad_Slug' ] ) );
		$collected[] = Repo::validate_stored_row( [ 'portal_hall_id' => 'oops' ], self::PORTAL_HALL_A );

		$leaked = [];
		foreach ( $collected as $item ) {
			if ( ! $item instanceof WP_Error ) {
				continue;
			}
			$text = (string) $item->get_error_message();
			foreach ( [ $table, $wpdb->base_prefix, 'SELECT', 'INSERT', 'UPDATE', 'Bad_Slug', 'zz_bc_absent', DB_NAME, DB_USER ] as $secret ) {
				if ( '' !== (string) $secret && false !== stripos( $text, (string) $secret ) ) {
					$leaked[] = $secret;
				}
			}
		}

		$this->assert_true( [] === $leaked, 'no failure message discloses a table, prefix, statement, payload or credential' );
		$this->assert_true(
			count( array_filter( $collected, static function ( $item ) {
				return $item instanceof WP_Error;
			} ) ) >= 3,
			'the disclosure check actually examined failures'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Destructive-operation guard
	 * ------------------------------------------------------------------ */

	private const OP_DROP_OPERATIONS        = 'drop_operations_table';
	private const OP_DELETE_OPERATIONS_ROWS = 'delete_operations_rows';
	private const OP_DROP_STATE_TABLE       = 'drop_state_table';
	private const OP_DELETE_STATE_ROWS      = 'delete_state_rows';
	private const OP_DELETE_VERSION         = 'delete_version_option';
	private const OP_SET_VERSION            = 'set_version_option';
	private const OP_INSERT_STATE_ROW       = 'insert_state_row';
	private const OP_CORRUPT_HALL_NAME      = 'corrupt_request_hall_name';
	private const OP_FORCE_OWNERSHIP        = 'force_ownership_id';
	private const OP_CREATE_HALL_OPTIONS    = 'create_hall_options';
	private const OP_DROP_HALL_OPTIONS      = 'drop_hall_options';
	private const OP_SET_MARKER             = 'set_marker';
	private const OP_DELETE_MARKER          = 'delete_marker';

	private const PERMITTED_OPERATIONS = [
		self::OP_DROP_OPERATIONS,
		self::OP_DELETE_OPERATIONS_ROWS,
		self::OP_DROP_STATE_TABLE,
		self::OP_DELETE_STATE_ROWS,
		self::OP_DELETE_VERSION,
		self::OP_SET_VERSION,
		self::OP_INSERT_STATE_ROW,
		self::OP_CORRUPT_HALL_NAME,
		self::OP_FORCE_OWNERSHIP,
		self::OP_CREATE_HALL_OPTIONS,
		self::OP_DROP_HALL_OPTIONS,
		self::OP_SET_MARKER,
		self::OP_DELETE_MARKER,
	];

	/**
	 * Verify ownership, then perform one NAMED fixture operation.
	 *
	 * Named operations rather than a table name plus an arbitrary SQL string:
	 * validating one argument while executing another proves nothing. Every
	 * statement is constructed HERE, from the operation and validated arguments
	 * alone, so the guard and the effect cannot diverge.
	 *
	 * @param array<string,mixed> $args
	 */
	private function fixture_operation( string $operation, array $args = [], bool $expect_refusal = false ): bool {
		global $wpdb;

		$refuse = function ( string $reason ) use ( $operation, $expect_refusal ): bool {
			if ( ! $expect_refusal ) {
				$this->assert_true( false, "{$operation}: refused, {$reason}" );
			}

			return false;
		};

		if ( ! in_array( $operation, self::PERMITTED_OPERATIONS, true ) ) {
			return $refuse( 'the operation is not permitted' );
		}

		$connected = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		if ( $connected !== BC_Fixture::$database
			|| ! in_array( $connected, BC_FIXTURE_DB_ALLOWLIST, true )
			|| $connected === BC_Fixture::$live_db ) {
			return $refuse( 'the connected database is not the fixture' );
		}

		$identity = $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' );
		if ( null === $identity || BC_FIXTURE_IDENTITY !== (string) $identity ) {
			return $refuse( 'the fixture sentinel is missing or wrong' );
		}

		$operations = ProvisioningMigrations::operations_table();
		$state      = ProvisioningMigrations::state_table();
		if ( $operations !== $wpdb->base_prefix . ProvisioningMigrations::OPERATIONS_TABLE
			|| $state !== $wpdb->base_prefix . ProvisioningMigrations::STATE_TABLE ) {
			return $refuse( 'a table name did not resolve as expected' );
		}

		switch ( $operation ) {
			case self::OP_DROP_OPERATIONS:
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $operations . '`' );
				break;

			case self::OP_DELETE_OPERATIONS_ROWS:
				$result = $wpdb->query( 'DELETE FROM `' . $operations . '`' );
				break;

			case self::OP_DROP_STATE_TABLE:
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $state . '`' );
				break;

			case self::OP_DELETE_STATE_ROWS:
				$result = $wpdb->query( 'DELETE FROM `' . $state . '`' );
				break;

			case self::OP_DELETE_VERSION:
				$result = delete_site_option( ProvisioningMigrations::VERSION_OPTION ) ? 1 : 0;
				break;

			case self::OP_SET_VERSION:
				if ( ! isset( $args['version'] ) || ! is_int( $args['version'] ) || $args['version'] < 0 ) {
					return $refuse( 'the version is not a non-negative integer' );
				}
				$result = update_site_option( ProvisioningMigrations::VERSION_OPTION, $args['version'] ) ? 1 : 0;
				break;

			case self::OP_INSERT_STATE_ROW:
				foreach ( [ 'blog_id', 'seq', 'state' ] as $key ) {
					if ( ! isset( $args[ $key ] ) ) {
						return $refuse( "the state {$key} is missing" );
					}
				}
				if ( ! is_int( $args['blog_id'] ) || ! is_int( $args['seq'] ) || ! is_string( $args['state'] ) ) {
					return $refuse( 'the state arguments are not the expected types' );
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO `' . $state . '` (blog_id, seq, state, updated_at) VALUES (%d, %d, %s, %s)
						 ON DUPLICATE KEY UPDATE seq = VALUES(seq), state = VALUES(state), updated_at = VALUES(updated_at)',
						$args['blog_id'],
						$args['seq'],
						$args['state'],
						'2026-01-01 00:00:00'
					)
				);
				break;

			/*
			 * ONE COLUMN, AND DELIBERATELY NOT THE FINGERPRINT. A whole raw row
			 * written by the harness would have to reproduce every nullable
			 * column correctly — and a nullable column bound through `%s` is
			 * stored as an empty string, not SQL NULL, which is a different
			 * defect from the one under test and would make the row fail for a
			 * reason the assertion does not name. Changing a single non-null
			 * textual column of an ordinarily-written row leaves exactly one
			 * thing wrong with it.
			 */
			case self::OP_CORRUPT_HALL_NAME:
				if ( ! isset( $args['hall'], $args['value'] )
					|| ! is_int( $args['hall'] )
					|| ! is_string( $args['value'] )
					|| '' === $args['value'] ) {
					return $refuse( 'the corruption arguments are invalid' );
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE `' . $operations . '` SET request_hall_name = %s WHERE portal_hall_id = %d',
						$args['value'],
						$args['hall']
					)
				);
				break;

			case self::OP_FORCE_OWNERSHIP:
				if ( ! isset( $args['hall'], $args['value'] )
					|| ! is_int( $args['hall'] )
					|| ! ProvisioningCanonicalRequest::is_uuid( $args['value'] ) ) {
					return $refuse( 'the forced ownership arguments are invalid' );
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE `' . $operations . '` SET site_ownership_id = %s WHERE portal_hall_id = %d',
						$args['value'],
						$args['hall']
					)
				);
				break;

			case self::OP_CREATE_HALL_OPTIONS:
				if ( ! isset( $args['hall'] ) || ! is_int( $args['hall'] ) || $args['hall'] < 2 ) {
					return $refuse( 'the Hall site ID is invalid' );
				}
				// Structure copied from the fixture's own main-site options
				// table. No development table is read.
				$table  = $this->options_table( $args['hall'] );
				$result = $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $table . '` LIKE `' . $wpdb->base_prefix . 'options`' );
				break;

			case self::OP_DROP_HALL_OPTIONS:
				if ( ! isset( $args['hall'] ) || ! is_int( $args['hall'] ) || $args['hall'] < 2 ) {
					return $refuse( 'the Hall site ID is invalid' );
				}
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $this->options_table( $args['hall'] ) . '`' );
				break;

			case self::OP_SET_MARKER:
				if ( ! isset( $args['hall'], $args['value'] ) || ! is_int( $args['hall'] ) || ! is_string( $args['value'] ) ) {
					return $refuse( 'the marker arguments are invalid' );
				}
				$table  = $this->options_table( $args['hall'] );
				$result = $wpdb->query(
					$wpdb->prepare(
						'INSERT INTO `' . $table . '` (option_name, option_value, autoload) VALUES (%s, %s, \'no\')
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
						HallCommerceAccess::MARKER_OPTION,
						$args['value']
					)
				);
				break;

			case self::OP_DELETE_MARKER:
				if ( ! isset( $args['hall'] ) || ! is_int( $args['hall'] ) ) {
					return $refuse( 'the Hall site ID is invalid' );
				}
				$table  = $this->options_table( $args['hall'] );
				$result = $wpdb->query(
					$wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE option_name = %s', HallCommerceAccess::MARKER_OPTION )
				);
				break;

			default:
				return $refuse( 'unreachable operation' );
		}

		if ( ! $expect_refusal ) {
			$this->assert_true( false !== $result, "{$operation}: statement succeeded" );
		}

		return false !== $result;
	}

	private function options_table( int $blog_id ): string {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			$this->abort( 'a Hall options table name did not resolve safely.' );
		}

		return $table;
	}

	private function test_guard_refusals(): void {
		$this->assert_true(
			false === $this->fixture_operation( 'drop_users_table', [], true ),
			'the guard refuses an operation outside the permitted set'
		);
		$this->assert_true(
			false === $this->fixture_operation( 'TRUNCATE wp_users', [], true ),
			'the guard refuses an arbitrary statement supplied as an operation'
		);
		$this->assert_true(
			false === $this->fixture_operation( self::OP_SET_VERSION, [ 'version' => '2' ], true ),
			'the guard refuses a stringly-typed version'
		);
		$this->assert_true(
			false === $this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'hall' => 1 ], true ),
			'the guard refuses creating an options table for the main site'
		);

		$this->assert_subprocess_refuses( [ 'BC_TEST_DB' => BC_Fixture::$live_db ], 'a development database name' );
		$this->assert_subprocess_refuses( [ 'BC_TEST_DB' => '' ], 'an unnamed database' );
		$this->assert_subprocess_refuses( [ 'BC_TEST_DB' => 'bingo_innovation' ], 'a non-allowlisted database' );
	}

	/** @param array<string,string> $env */
	private function assert_subprocess_refuses( array $env, string $label ): void {
		$assignments = '';
		foreach ( $env as $key => $value ) {
			$assignments .= $key . '=' . escapeshellarg( $value ) . ' ';
		}

		$command = $assignments . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
		$output  = [];
		$code    = 0;
		exec( $command, $output, $code );

		$this->assert_true( 1 === $code, "the fixture refuses {$label}" );
		$this->assert_true(
			false !== strpos( implode( "\n", $output ), 'FIXTURE GUARD' ),
			"the refusal for {$label} names the fixture guard"
		);
	}

	private function teardown(): void {
		global $wpdb;

		foreach ( $this->owns_hall_options as $hall ) {
			$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'hall' => $hall ] );
		}

		if ( ! $this->owns_schema ) {
			return;
		}

		$this->fixture_operation( self::OP_DROP_OPERATIONS );
		$this->assert_true(
			! $this->table_exists( ProvisioningMigrations::operations_table() ),
			'teardown removed the operations table'
		);

		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->assert_true(
			! $this->table_exists( ProvisioningMigrations::state_table() ),
			'teardown removed the commerce-state table'
		);

		/*
		 * The schema version is fixture-owned state and must go with the tables.
		 * Leaving it behind would make the next run's migrate() short-circuit on
		 * the recorded version and then fail verification against tables this
		 * teardown had already dropped — an inconsistent fixture, not a defect.
		 */
		$this->fixture_operation( self::OP_DELETE_VERSION );
		$this->assert_true(
			0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'teardown cleared the provisioning schema version'
		);

		unset( $wpdb );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function table_exists( string $table ): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private function operations_row_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::operations_table() . '`' );
	}

	private function state_fingerprint(): string {
		return $this->fingerprint( ProvisioningMigrations::state_table() );
	}

	private function operations_fingerprint(): string {
		return $this->fingerprint( ProvisioningMigrations::operations_table() );
	}

	private function fingerprint( string $table ): string {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return 'absent';
		}

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

		return md5( (string) wp_json_encode( [ $columns, $indexes ] ) );
	}

	/**
	 * @param callable():mixed $fn
	 * @return array{result:mixed,stdout:string,log:string}
	 */
	private function with_output_capture( callable $fn ): array {
		$log_file     = tempnam( sys_get_temp_dir(), 'bc-mutex-log-' );
		$previous_log = ini_get( 'error_log' );
		if ( is_string( $log_file ) && '' !== $log_file ) {
			ini_set( 'error_log', $log_file );
		}
		ob_start();
		try {
			$result = $fn();
		} catch ( Throwable $escaped ) {
			$result = $escaped;
		}
		$stdout = (string) ob_get_clean();
		if ( is_string( $previous_log ) ) {
			ini_set( 'error_log', $previous_log );
		} else {
			ini_restore( 'error_log' );
		}
		$logged = ( is_string( $log_file ) && is_file( $log_file ) ) ? (string) file_get_contents( $log_file ) : '';
		if ( is_string( $log_file ) && is_file( $log_file ) ) {
			unlink( $log_file );
		}

		return [
			'result' => $result,
			'stdout' => $stdout,
			'log'    => $logged,
		];
	}

	/** @param array{result:mixed,stdout:string,log:string} $captured */
	private function assert_mutex_diagnostics_silent( array $captured, string $label ): void {
		$haystack = $captured['stdout'] . "\n" . $captured['log'];
		if ( $captured['result'] instanceof WP_Error ) {
			$haystack .= "\n" . $captured['result']->get_error_code() . "\n" . $captured['result']->get_error_message();
		} elseif ( $captured['result'] instanceof Throwable ) {
			$haystack .= "\n" . $captured['result']->getMessage();
		}
		$haystack = str_replace(
			'BingoCommerce provisioning mutex release failed on a live connection.',
			'',
			$haystack
		);
		foreach ( [ 'WordPress database error', 'GET_LOCK', 'RELEASE_LOCK', 'IS_USED_LOCK', 'zz_bc_absent', 'SELECT ', 'mysqli' ] as $secret ) {
			$this->assert_true(
				false === stripos( $haystack, $secret ),
				"{$label} emits no SQL or driver diagnostic"
			);
		}
	}

	private function assert_wpdb_display( bool $suppress, bool $show, string $label ): void {
		global $wpdb;

		$this->assert_true(
			(bool) $wpdb->suppress_errors === $suppress && (bool) $wpdb->show_errors === $show,
			$label
		);
	}

	private function assert_true( bool $condition, string $label ): void {
		$this->assertions++;
		if ( ! $condition ) {
			$this->failures++;
			echo "FAIL: {$label}\n";
		}
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		echo "\n{$passed}/{$this->assertions} assertions passed";
		echo 0 === $this->failures ? " — PASS\n" : " — {$this->failures} FAILED\n";
		exit( 0 === $this->failures ? 0 : 1 );
	}
}

( new PortalProvisioningFoundationTest() )->run();
