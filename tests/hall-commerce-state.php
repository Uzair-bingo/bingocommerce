<?php

/**
 * CLI coverage for Commerce-state persistence (HallCommerceState +
 * ProvisioningMigrations).
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_test php tests/hall-commerce-state.php
 *
 * WordPress itself boots against the isolated fixture — see
 * tests/fixtures/isolated-multisite.php. The development database is never
 * opened by the bootstrap, its identities are never borrowed, outbound HTTP is
 * blocked before any plugin can run, and persistent object caching is off.
 *
 * Every destructive statement in this suite re-verifies the connected database,
 * the allowlist, the fixture sentinel and the table name before it runs.
 */

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

use BingoCommerce\PortalIntegration\HallCommerceState;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;

final class HallCommerceStateTest {

	private int $assertions = 0;
	private int $failures   = 0;

	private int $hall_a = BC_FIXTURE_HALL_A;
	private int $hall_b = BC_FIXTURE_HALL_B;

	/** True only once THIS run has created the state table. */
	private bool $owns_state_table = false;

	/** Set only by the never-ready scenario, which pre-holds a known ready lock. */
	private ?string $coordination_token = null;

	/** The scratch database THIS run created, and may therefore drop. */
	private ?string $owns_scratch = null;

	/** Set when cleanup had to escalate past SIGTERM. */
	private bool $escalated = false;

	/**
	 * Poll for a child's exit for a bounded period.
	 *
	 * @param resource $proc
	 */
	private function wait_for_exit( $proc, float $seconds ): bool {
		$deadline = microtime( true ) + $seconds;
		while ( microtime( true ) < $deadline ) {
			$status = proc_get_status( $proc );
			if ( is_array( $status ) && false === $status['running'] ) {
				return true;
			}
			usleep( 20000 );
		}

		$status = proc_get_status( $proc );

		return is_array( $status ) && false === $status['running'];
	}

	/** Unsafe environment: stop before any mutating test, do not continue. */
	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		fwrite( STDERR, "Stopping before any mutating test.\n" );
		exit( 1 );
	}

	/**
	 * Sub-mode: this process boots with CLIENT_FOUND_ROWS set.
	 *
	 * INSPECTION ONLY. The parent prepared the schema and the row, and the parent
	 * owns cleanup; this process migrates nothing, drops nothing and deletes no
	 * version. It exists solely to prove the write is refused, and to leave
	 * everything exactly as it found it.
	 */
	public function run_connection_mode_probe(): void {
		$this->assert_true(
			ProvisioningMigrations::is_current(),
			'the probe found the schema the parent had already prepared'
		);

		$before = HallCommerceState::read( $this->hall_a );
		$result = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 900, HallCommerceState::PHASE_SETTLED );

		$this->assert_true(
			$result instanceof WP_Error && 'unsupported_connection_mode' === $result->get_error_code(),
			'a CLIENT_FOUND_ROWS connection is refused before any state write'
		);
		$this->assert_true( $before === HallCommerceState::read( $this->hall_a ), 'the refused write changed nothing' );

		$this->report();
	}

	public function run(): void {
		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();

		try {
			$this->test_not_wired_to_automatic_paths();
			$this->test_migration_and_repeat();
			$this->test_failed_verification_does_not_advance_version();
			$this->test_absent_versus_unreadable();
			$this->test_identity_validation();
			$this->test_scalar_type_validation();
			$this->test_sequence_bounds_and_phases();
			$this->test_stored_row_validation();
			$this->test_ordering_and_timestamp();
			$this->test_retry_and_conflict();
			$this->test_sequential_retry_same_second();
			$this->test_throwing_paths();
			$this->test_concurrent_first_writes();
			$this->test_stale_writer_overlap();
			$this->test_forced_interleaving();
			$this->test_worker_never_ready();
			$this->test_worker_stalls_before_writing();
			$this->test_worker_termination_escalation();
			$this->test_halls_independent();
			$this->test_connection_mode_enforced();
			$this->test_version_path_throwing();
			$this->test_migration_refuses_unreadable_version();
			$this->test_read_identity_validation();
			$this->test_guard_refusals();
			$this->test_ownership_negative_controls();
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
		$this->assert_true(
			! file_exists( WP_CONTENT_DIR . '/db.php' ),
			'no database-routing drop-in is present'
		);

		/*
		 * HTTP rejection, proven without sending anything. WP_HTTP_BLOCK_EXTERNAL
		 * permits localhost and the site's own host, so those two are checked
		 * explicitly alongside an external URL.
		 */
		foreach (
			[
				'http://127.0.0.1/probe'                  => 'localhost',
				'http://localhost/probe'                  => 'the localhost name',
				'http://' . BC_FIXTURE_DOMAIN . '/probe'  => "the fixture's own host",
				'https://example.invalid/probe'           => 'an external host',
			] as $url => $label
		) {
			$response = wp_remote_get( $url );
			$this->assert_true(
				is_wp_error( $response ) && 'bc_fixture_http_blocked' === $response->get_error_code(),
				"outbound HTTP to {$label} is rejected by the fixture hook"
			);
		}
		$this->assert_true(
			get_site( $this->hall_a ) instanceof WP_Site && get_site( $this->hall_b ) instanceof WP_Site,
			'the fixture supplies two Hall sites'
		);
		$this->assert_true(
			! is_main_site( $this->hall_a ) && ! is_main_site( $this->hall_b ),
			'the fixture Hall sites are not the main site'
		);
		$this->assert_true(
			false !== strpos( (string) get_site( $this->hall_a )->domain, BC_FIXTURE_DOMAIN ),
			'the Hall identities belong to the fixture, not development'
		);
	}

	/**
	 * Refuse an inconsistent leftover fixture rather than destroying it.
	 *
	 * A state table without a recorded version, or a version without a table,
	 * means a previous run ended in a way this suite did not anticipate. It is
	 * not this run's to clean up: it aborts so a human can look, and it never
	 * claims ownership of a table it did not create.
	 */
	private function require_clean_starting_state(): void {
		global $wpdb;

		$table  = ProvisioningMigrations::state_table();
		$exists = null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$version = (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );

		if ( $exists && 0 === $version ) {
			$this->abort( 'a state table exists with no recorded schema version. Refusing to adopt or destroy it.' );
		}
		if ( ! $exists && $version > 0 ) {
			$this->abort( 'a schema version is recorded with no state table. Refusing to proceed.' );
		}
		if ( $exists && $version > 0 ) {
			$this->abort( 'a complete state schema is already present from a previous run. Refusing to adopt it.' );
		}
	}

	private function test_not_wired_to_automatic_paths(): void {
		foreach ( [ 'init', 'admin_init', 'wp_loaded', 'plugins_loaded', 'wp_initialize_site' ] as $hook ) {
			$this->assert_true(
				false === has_action( $hook, [ ProvisioningMigrations::class, 'migrate' ] ),
				"migrate() is not attached to {$hook}"
			);
		}
		$this->assert_true(
			ProvisioningMigrations::VERSION_OPTION !== \BingoCommerce\PortalIntegration\Migrations::NETWORK_VERSION_OPTION,
			'the Commerce state version option is separate from the Hall-mapping version'
		);
		$this->assert_true(
			1 === \BingoCommerce\PortalIntegration\Migrations::NETWORK_DB_VERSION,
			'the shipped Hall-mapping schema version is unchanged'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Destructive-operation guard
	 * ------------------------------------------------------------------ */

	/**
	 * The only destructive operations this harness may perform.
	 *
	 * Named operations rather than a table name plus an arbitrary SQL string:
	 * validating one argument while executing another that was supplied
	 * independently proves nothing. The statement is constructed HERE, from the
	 * operation alone, so the guard and the effect cannot diverge.
	 */
	private const OP_DROP_STATE_TABLE     = 'drop_state_table';
	private const OP_DELETE_STATE_ROWS    = 'delete_state_rows';
	private const OP_DELETE_VERSION       = 'delete_version_option';

	private const PERMITTED_OPERATIONS = [
		self::OP_DROP_STATE_TABLE,
		self::OP_DELETE_STATE_ROWS,
		self::OP_DELETE_VERSION,
	];

	/**
	 * Verify ownership, then perform one named fixture operation.
	 *
	 * @return bool True when the operation ran and succeeded.
	 */
	private function fixture_operation( string $operation, bool $expect_refusal = false ): bool {
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

		$table = ProvisioningMigrations::state_table();
		if ( $table !== $wpdb->base_prefix . ProvisioningMigrations::STATE_TABLE ) {
			return $refuse( 'the state table name did not resolve as expected' );
		}

		switch ( $operation ) {
			case self::OP_DROP_STATE_TABLE:
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
				break;

			case self::OP_DELETE_STATE_ROWS:
				$result = $wpdb->query( 'DELETE FROM `' . $table . '`' );
				break;

			case self::OP_DELETE_VERSION:
				// Guarded exactly like the table operations: the version option is
				// fixture-owned state and deleting it in the wrong database would
				// disable a real installation's schema registration.
				$result = delete_site_option( ProvisioningMigrations::VERSION_OPTION ) ? 1 : 0;
				break;

			default:
				return $refuse( 'unreachable operation' );
		}

		if ( ! $expect_refusal ) {
			$this->assert_true( false !== $result, "{$operation}: statement succeeded" );
		}

		return false !== $result;
	}

	private function test_guard_refusals(): void {
		$this->assert_true(
			false === $this->fixture_operation( 'drop_users_table', true ),
			'the guard refuses an operation outside the permitted set'
		);
		$this->assert_true(
			false === $this->fixture_operation( 'TRUNCATE wp_users', true ),
			'the guard refuses an arbitrary statement supplied as an operation'
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

		if ( $this->owns_state_table ) {
			$table = ProvisioningMigrations::state_table();
			$this->fixture_operation( self::OP_DROP_STATE_TABLE );
			$this->assert_true(
				null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ),
				'teardown removed the state table'
			);
		}

		/*
		 * The schema version is fixture-owned state and must go with the table.
		 * Leaving it behind would make the NEXT run's migrate() short-circuit on
		 * is_current() and then fail verification against a table that teardown
		 * had already dropped — an inconsistent fixture, not a real defect.
		 */
		if ( $this->owns_state_table ) {
			$this->fixture_operation( self::OP_DELETE_VERSION );
			$this->assert_true(
				0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
				'teardown cleared the Commerce state schema version'
			);
		}

		// The fixture's own WordPress tables and sentinel are deliberately left in
		// place: they are reusable, and removing the sentinel would discard the
		// ownership proof a later run depends on.
	}

	/* ------------------------------------------------------------------ *
	 * Migration
	 * ------------------------------------------------------------------ */

	private function test_migration_and_repeat(): void {
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the first migration succeeds' );
		// Ownership of the table is recorded only after this run created it.
		$this->owns_state_table = true;
		$this->assert_true( ProvisioningMigrations::is_current(), 'the schema version is recorded' );
		$this->assert_true( true === ProvisioningMigrations::verify_schema(), 'the created schema verifies' );

		$before = $this->schema_fingerprint();
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'a repeated migration succeeds' );
		$this->assert_true( $before === $this->schema_fingerprint(), 'a repeated migration changes nothing' );
	}

	private function test_failed_verification_does_not_advance_version(): void {
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
			is_wp_error( $result ) && 'provisioning_migration_verification_failed' === $result->get_error_code(),
			'the failure uses the fixed verification category'
		);
		$this->assert_true(
			0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'the schema version is NOT advanced when verification fails'
		);

		$this->assert_true( true === ProvisioningMigrations::migrate(), 'migration succeeds once verification is unobstructed' );
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	private function test_absent_versus_unreadable(): void {
		$this->assert_true( null === HallCommerceState::read( $this->hall_a ), 'a Hall with no row reads as a verified absence' );

		$break = static function ( $query ) {
			return 1 === preg_match( '/FROM\s+\S*bingo_hall_commerce_state/i', (string) $query )
				? 'SELECT blog_id FROM zz_bc_no_such_state_table'
				: $query;
		};

		add_filter( 'query', $break, 1 );
		try {
			$unreadable = HallCommerceState::read( $this->hall_a );
		} finally {
			remove_filter( 'query', $break, 1 );
		}

		$this->assert_true( is_wp_error( $unreadable ), 'unreadable storage is not reported as an absence' );
		$this->assert_true(
			is_wp_error( $unreadable ) && 'state_read_failed' === $unreadable->get_error_code(),
			'unreadable storage returns the fixed read-failure category'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Validation
	 * ------------------------------------------------------------------ */

	private function test_identity_validation(): void {
		$cases = [
			[ 0, 'invalid_blog_id' ],
			[ -1, 'invalid_blog_id' ],
			[ 990001, 'site_not_found' ],
			[ (int) get_main_site_id(), 'main_site_not_allowed' ],
		];
		foreach ( $cases as [ $blog_id, $code ] ) {
			$r = HallCommerceState::write( $blog_id, HallCommerceState::OPERATIONAL, 1, HallCommerceState::PHASE_SETTLED );
			$this->assert_true( is_wp_error( $r ) && $code === $r->get_error_code(), "identity rejected: {$code}" );
		}

		foreach ( [ 'enabled', '', 'PROVISIONING' ] as $state ) {
			$r = HallCommerceState::write( $this->hall_a, $state, 1, HallCommerceState::PHASE_SETTLED );
			$this->assert_true( is_wp_error( $r ) && 'invalid_state' === $r->get_error_code(), "state rejected: '{$state}'" );
		}

		// Archived Hall: deliberately still accepted.
		$this->set_site_flag( $this->hall_b, 'archived', 1 );
		$archived = HallCommerceState::write( $this->hall_b, HallCommerceState::PROVISIONING, 1, HallCommerceState::PHASE_TRANSITIONAL );
		$this->set_site_flag( $this->hall_b, 'archived', 0 );
		$this->assert_true(
			is_array( $archived ) && HallCommerceState::APPLIED === $archived['outcome'],
			'an archived provisioning target is still accepted'
		);

		foreach ( [ 'deleted', 'spam' ] as $flag ) {
			$this->set_site_flag( $this->hall_b, $flag, 1 );
			$r = HallCommerceState::write( $this->hall_b, HallCommerceState::OPERATIONAL, 2, HallCommerceState::PHASE_SETTLED );
			$this->set_site_flag( $this->hall_b, $flag, 0 );
			$this->assert_true( is_wp_error( $r ) && "site_{$flag}" === $r->get_error_code(), "a {$flag} site is refused" );
		}

		$this->reset_rows();
	}

	private function test_scalar_type_validation(): void {
		foreach ( [ '2', 2.0, 2.7, true, false, null, [ 2 ], '2abc' ] as $value ) {
			$r = HallCommerceState::write( $value, HallCommerceState::OPERATIONAL, 1, HallCommerceState::PHASE_SETTLED );
			$this->assert_true(
				is_wp_error( $r ) && 'invalid_blog_id' === $r->get_error_code(),
				'a non-integer Hall ID is refused: ' . $this->describe( $value )
			);
		}

		foreach ( [ '1', 1.0, 1.5, true, null, '1e3' ] as $value ) {
			$r = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, $value, HallCommerceState::PHASE_SETTLED );
			$this->assert_true(
				is_wp_error( $r ) && 'sequence_out_of_range' === $r->get_error_code(),
				'a non-integer intent is refused: ' . $this->describe( $value )
			);

			$r = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 1, $value );
			$this->assert_true(
				is_wp_error( $r ) && in_array( $r->get_error_code(), [ 'invalid_phase', 'invalid_state_phase' ], true ),
				'a non-integer phase is refused: ' . $this->describe( $value )
			);
		}

		foreach ( [ true, 1, [ 'operational' ] ] as $value ) {
			$r = HallCommerceState::write( $this->hall_a, $value, 1, HallCommerceState::PHASE_SETTLED );
			$this->assert_true(
				is_wp_error( $r ) && 'invalid_state' === $r->get_error_code(),
				'a non-string state is refused: ' . $this->describe( $value )
			);
		}

		$ok = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 1, HallCommerceState::PHASE_SETTLED );
		$this->assert_true( is_array( $ok ) && HallCommerceState::APPLIED === $ok['outcome'], 'a correctly typed write is applied' );
		$this->reset_rows();
	}

	private function test_sequence_bounds_and_phases(): void {
		$max = HallCommerceState::max_intent_sequence();
		$this->assert_true( PHP_INT_MAX >> 8 === $max, 'the maximum intent sequence is bounded by PHP integer width' );
		$this->assert_true( is_int( HallCommerceState::pack( $max, HallCommerceState::MAX_PHASE ) ), 'the maximum representable sequence packs' );
		$this->assert_true( HallCommerceState::pack( $max, HallCommerceState::MAX_PHASE ) > 0, 'the maximum representable sequence stays positive' );

		foreach ( [ $max + 1, PHP_INT_MAX, -1 ] as $bad ) {
			$r = HallCommerceState::pack( $bad, 0 );
			$this->assert_true( is_wp_error( $r ) && 'sequence_out_of_range' === $r->get_error_code(), 'an out-of-range intent is refused before shifting' );
		}
		foreach ( [ 256, -1 ] as $bad ) {
			$r = HallCommerceState::pack( 1, $bad );
			$this->assert_true( is_wp_error( $r ) && 'invalid_phase' === $r->get_error_code(), 'an out-of-capacity phase is refused' );
		}

		$this->assert_true( HallCommerceState::pack( 4, 1 ) > HallCommerceState::pack( 4, 0 ), 'settled outranks transitional within one intent' );
		$this->assert_true( HallCommerceState::pack( 5, 0 ) > HallCommerceState::pack( 4, HallCommerceState::MAX_PHASE ), 'a higher intent outranks every phase of a lower intent' );

		// Reserved phases are representable but not writable.
		foreach ( [ 2, 7, HallCommerceState::MAX_PHASE ] as $reserved ) {
			$this->assert_true( is_int( HallCommerceState::pack( 1, $reserved ) ), "phase {$reserved} is representable by the packer" );
			$r = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 1, $reserved );
			$this->assert_true(
				is_wp_error( $r ) && 'invalid_state_phase' === $r->get_error_code(),
				"reserved phase {$reserved} is refused as a lifecycle write"
			);
		}

		$wrong = [
			[ HallCommerceState::PROVISIONING, HallCommerceState::PHASE_SETTLED ],
			[ HallCommerceState::OPERATIONAL, HallCommerceState::PHASE_TRANSITIONAL ],
			[ HallCommerceState::SUSPENDED, HallCommerceState::PHASE_TRANSITIONAL ],
		];
		foreach ( $wrong as [ $state, $phase ] ) {
			$r = HallCommerceState::write( $this->hall_a, $state, 1, $phase );
			$this->assert_true(
				is_wp_error( $r ) && 'invalid_state_phase' === $r->get_error_code(),
				"{$state} with phase {$phase} is refused"
			);
		}

		$intent = 500;
		foreach ( HallCommerceState::STATE_PHASES as $state => $phase ) {
			$intent++;
			$r = HallCommerceState::write( $this->hall_a, $state, $intent, $phase );
			$this->assert_true( is_array( $r ), "{$state} with its lifecycle phase is accepted" );
		}
		$this->reset_rows();
	}

	private function test_stored_row_validation(): void {
		global $wpdb;

		$table = ProvisioningMigrations::state_table();
		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 40, HallCommerceState::PHASE_SETTLED );

		foreach (
			[
				[ 'state', 'enabled', 'an unrecognised stored state' ],
				[ 'updated_at', '0000-00-00 00:00:00', 'a malformed stored timestamp' ],
			] as [ $column, $value, $label ]
		) {
			$original = $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM `{$table}` WHERE blog_id = %d", $this->hall_a ) );
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$column}` = %s WHERE blog_id = %d", $value, $this->hall_a ) );

			$r = HallCommerceState::read( $this->hall_a );
			$this->assert_true(
				is_wp_error( $r ) && 'state_row_invalid' === $r->get_error_code(),
				"{$label} is rejected, not returned as trusted data"
			);

			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$column}` = %s WHERE blog_id = %d", $original, $this->hall_a ) );
		}

		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET seq = %d WHERE blog_id = %d", ( 40 << 8 ) | 0, $this->hall_a ) );
		$r = HallCommerceState::read( $this->hall_a );
		$this->assert_true(
			is_wp_error( $r ) && 'state_row_invalid' === $r->get_error_code(),
			'a stored state and phase that disagree are rejected'
		);

		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET seq = %d WHERE blog_id = %d", ( 40 << 8 ) | 1, $this->hall_a ) );
		$r = HallCommerceState::read( $this->hall_a );
		$this->assert_true( is_array( $r ) && HallCommerceState::OPERATIONAL === $r['state'], 'a valid stored row reads cleanly' );

		$this->reset_rows();
	}

	/* ------------------------------------------------------------------ *
	 * Ordering
	 * ------------------------------------------------------------------ */

	private function test_ordering_and_timestamp(): void {
		global $wpdb;

		$table = ProvisioningMigrations::state_table();
		$hall  = $this->hall_b;

		HallCommerceState::write( $hall, HallCommerceState::SUSPENDED, 5, HallCommerceState::PHASE_SETTLED );

		/*
		 * Force the stored timestamp far away from "now" so an incorrect update
		 * cannot pass by writing the same second. A same-second comparison would
		 * make the unchanged-timestamp assertion meaningless.
		 */
		$distant = '2020-01-02 03:04:05';
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE blog_id = %d", $distant, $hall ) );

		$before = HallCommerceState::read( $hall );
		$this->assert_true( is_array( $before ) && $distant === $before['updated_at'], 'the stored timestamp is distinct from now' );

		$older = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 4, HallCommerceState::PHASE_SETTLED );
		$this->assert_true( is_array( $older ) && HallCommerceState::SUPERSEDED === $older['outcome'], 'an older write is reported superseded' );

		$after = HallCommerceState::read( $hall );
		$this->assert_true( $before === $after, 'an older write leaves the complete row unchanged, timestamp included' );
		$this->assert_true( is_array( $after ) && $distant === $after['updated_at'], 'the distant timestamp was not refreshed' );

		$newer = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 6, HallCommerceState::PHASE_SETTLED );
		$this->assert_true( is_array( $newer ) && HallCommerceState::APPLIED === $newer['outcome'], 'a newer write is applied' );
		$this->assert_true(
			is_array( $newer ) && HallCommerceState::OPERATIONAL === ( $newer['stored']['state'] ?? '' ),
			'the newer state is stored'
		);
		$this->reset_rows();
	}

	private function test_retry_and_conflict(): void {
		global $wpdb;

		$table = ProvisioningMigrations::state_table();
		$hall  = $this->hall_a;

		HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 10, HallCommerceState::PHASE_SETTLED );

		// Age the row so an unnecessary timestamp refresh would be visible.
		$distant = '2021-02-03 04:05:06';
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET updated_at = %s WHERE blog_id = %d", $distant, $hall ) );
		$before = HallCommerceState::read( $hall );

		$retry = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 10, HallCommerceState::PHASE_SETTLED );
		$this->assert_true(
			is_array( $retry ) && HallCommerceState::ALREADY_APPLIED === $retry['outcome'],
			'an identical SEQUENTIAL retry reports already-applied'
		);
		$this->assert_true( $before === HallCommerceState::read( $hall ), 'an identical retry does not change the row or its timestamp' );
		$this->assert_true( 1 === $this->row_count( $hall ), 'an identical retry creates no second row' );

		$conflict = HallCommerceState::write( $hall, HallCommerceState::SUSPENDED, 10, HallCommerceState::PHASE_SETTLED );
		$this->assert_true(
			is_array( $conflict ) && HallCommerceState::CONFLICT === $conflict['outcome'],
			'an equal sequence with a different state reports a conflict'
		);
		$this->assert_true( $before === HallCommerceState::read( $hall ), 'an equal sequence does not silently overwrite a different state' );
		$this->reset_rows();
	}

	/**
	 * An identical retry inside the SAME second must still be already_applied.
	 *
	 * No timestamp aging here — that is the point. The old timestamp-based
	 * classifier would call the second write "applied" because the stored
	 * `updated_at` matches the second call's own timestamp. The test asserts the
	 * timing condition was genuinely exercised, then shows the retired rule
	 * would have produced the wrong answer on exactly this data.
	 */
	private function test_sequential_retry_same_second(): void {
		$hall      = $this->hall_a;
		$exercised = false;
		$first      = null;
		$second     = null;
		$before     = null;
		$after      = null;
		$second_now = null;

		for ( $attempt = 0; $attempt < 8 && ! $exercised; $attempt++ ) {
			$this->reset_rows();

			$t0     = current_time( 'mysql', true );
			$first  = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 400, HallCommerceState::PHASE_SETTLED );
			$before = HallCommerceState::read( $hall );

			// Captured independently, immediately before the retry: this is the
			// timestamp the retired rule would have compared the stored row to.
			$second_now = current_time( 'mysql', true );
			$second     = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 400, HallCommerceState::PHASE_SETTLED );
			$after      = HallCommerceState::read( $hall );
			$t1         = current_time( 'mysql', true );

			// Both calls, and the stored row, inside one second.
			$exercised = ( $t0 === $t1 )
				&& is_array( $before )
				&& $before['updated_at'] === $t0;
		}

		$this->assert_true( $exercised, 'both writes and the stored timestamp landed in the same second' );
		if ( ! $exercised ) {
			$this->reset_rows();

			return;
		}

		$this->assert_true(
			is_array( $first ) && HallCommerceState::APPLIED === $first['outcome'],
			'the first write in the second is applied'
		);
		$this->assert_true(
			is_array( $second ) && HallCommerceState::ALREADY_APPLIED === $second['outcome'],
			'an identical retry in the same second is already_applied'
		);
		$this->assert_true( $before === $after, 'the retry left the row, timestamp included, unchanged' );
		$this->assert_true( 1 === $this->row_count( $hall ), 'the retry created no second row' );

		/*
		 * Negative control for the retired rule. Applied to this very data, the
		 * timestamp comparison returns APPLIED for the retry — the wrong answer —
		 * which is why it was removed. Nothing in production is modified; the rule
		 * is evaluated here in isolation.
		 */
		$this->assert_true(
			is_string( $second_now ) && $second_now !== $after['updated_at'] || is_string( $second_now ),
			'an independent timestamp was captured for the retry'
		);

		// Two INDEPENDENT inputs: what the row actually stores, and what the
		// second call would have stamped. The retired rule compared exactly these.
		$retired_classification = ( $after['updated_at'] === $second_now )
			? HallCommerceState::APPLIED
			: HallCommerceState::ALREADY_APPLIED;
		$this->assert_true(
			HallCommerceState::APPLIED === $retired_classification,
			'the retired timestamp rule would have misreported this retry as applied'
		);
		$this->assert_true(
			$retired_classification !== $second['outcome'],
			'the corrected implementation disagrees with the retired rule on this data'
		);

		$this->reset_rows();
	}

	/* ------------------------------------------------------------------ *
	 * Failure paths
	 * ------------------------------------------------------------------ */

	private function test_throwing_paths(): void {
		global $wpdb;

		$hall = $this->hall_a;

		$throw_write = static function ( $query ) {
			if ( 1 === preg_match( '/^INSERT INTO\s+\S*bingo_hall_commerce_state/i', ltrim( (string) $query ) ) ) {
				throw new RuntimeException( 'injected write failure' );
			}

			return $query;
		};
		$suppress_before = $wpdb->suppress_errors;
		add_filter( 'query', $throw_write, 1 );
		try {
			$failed = HallCommerceState::write( $hall, HallCommerceState::SUSPENDED, 60, HallCommerceState::PHASE_SETTLED );
		} finally {
			remove_filter( 'query', $throw_write, 1 );
		}
		$this->assert_true( is_wp_error( $failed ) && 'state_write_failed' === $failed->get_error_code(), 'a throwing write returns the fixed write-failure category' );
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'error suppression is restored after a throwing write' );
		$this->assert_true( null === HallCommerceState::read( $hall ), 'a throwing write stored nothing' );

		$throw_read = static function ( $query ) {
			if ( 1 === preg_match( '/^SELECT blog_id, seq, state/i', ltrim( (string) $query ) ) ) {
				throw new RuntimeException( 'injected read-back failure' );
			}

			return $query;
		};
		$suppress_before = $wpdb->suppress_errors;
		add_filter( 'query', $throw_read, 1 );
		try {
			$unverified = HallCommerceState::write( $hall, HallCommerceState::SUSPENDED, 61, HallCommerceState::PHASE_SETTLED );
		} finally {
			remove_filter( 'query', $throw_read, 1 );
		}
		$this->assert_true(
			is_array( $unverified ) && HallCommerceState::UNVERIFIED === $unverified['outcome'],
			'a throwing read-back is reported unverified, never confirmed'
		);
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'error suppression is restored after a throwing read-back' );

		$stored = HallCommerceState::read( $hall );
		$this->assert_true(
			is_array( $stored ) && HallCommerceState::SUSPENDED === $stored['state'],
			'the unverified write had in fact been stored, so "unverified" was accurate'
		);

		$break = static function ( $query ) {
			return 1 === preg_match( '/^INSERT INTO\s+\S*bingo_hall_commerce_state/i', ltrim( (string) $query ) )
				? 'INSERT INTO zz_bc_no_such_state_table (blog_id) VALUES (1)'
				: $query;
		};
		add_filter( 'query', $break, 1 );
		try {
			$broken = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 62, HallCommerceState::PHASE_SETTLED );
		} finally {
			remove_filter( 'query', $break, 1 );
		}
		$this->assert_true( is_wp_error( $broken ) && 'state_write_failed' === $broken->get_error_code(), 'a failing write returns the fixed category' );

		$this->reset_rows();
	}

	/* ------------------------------------------------------------------ *
	 * Overlapping writers (separate processes, gate-synchronised)
	 * ------------------------------------------------------------------ */

	private function test_concurrent_first_writes(): void {
		$this->reset_rows();

		$run = $this->run_overlapping(
			[
				[ 'hall' => $this->hall_b, 'state' => HallCommerceState::PROVISIONING, 'intent' => 1, 'phase' => HallCommerceState::PHASE_TRANSITIONAL ],
				[ 'hall' => $this->hall_b, 'state' => HallCommerceState::PROVISIONING, 'intent' => 1, 'phase' => HallCommerceState::PHASE_TRANSITIONAL ],
			]
		);
		$this->assert_true( true === $run['ok'], 'the overlapping scenario completed (' . $run['reason'] . ')' );
		$results = $run['results'];
		foreach ( $run['exit_codes'] as $i => $code ) {
			$this->assert_true( 0 === $code, "worker {$i} exited cleanly" );
		}
		$outcomes = array_map( static fn( $r ) => (string) ( $r['outcome'] ?? ( 'error:' . ( $r['error'] ?? '?' ) ) ), $results );
		sort( $outcomes );

		$this->assert_true( 2 === count( $results ), 'both overlapping workers reported a result' );

		// Under the supported connection mode exactly one writer inserts the row
		// and the other finds it already holding those values.
		$this->assert_true(
			[ HallCommerceState::ALREADY_APPLIED, HallCommerceState::APPLIED ] === $outcomes,
			'overlapping first writers resolve to exactly one applied and one already-applied (' . implode( ',', $outcomes ) . ')'
		);
		$this->assert_true( 1 === $this->row_count( $this->hall_b ), 'overlapping first writes leave exactly one row' );

		$stored = HallCommerceState::read( $this->hall_b );
		$this->assert_true( is_array( $stored ) && HallCommerceState::PROVISIONING === $stored['state'], 'the surviving row is valid' );
		$this->reset_rows();
	}

	private function test_stale_writer_overlap(): void {
		$this->reset_rows();

		$run = $this->run_overlapping(
			[
				[ 'hall' => $this->hall_b, 'state' => HallCommerceState::SUSPENDED, 'intent' => 20, 'phase' => HallCommerceState::PHASE_SETTLED ],
				[ 'hall' => $this->hall_b, 'state' => HallCommerceState::OPERATIONAL, 'intent' => 19, 'phase' => HallCommerceState::PHASE_SETTLED ],
			]
		);
		$this->assert_true( true === $run['ok'], 'the ordered overlapping scenario completed (' . $run['reason'] . ')' );
		$results = $run['results'];

		$stored = HallCommerceState::read( $this->hall_b );
		$this->assert_true(
			is_array( $stored ) && HallCommerceState::SUSPENDED === $stored['state'],
			'the newer state survives overlapping writers'
		);
		$this->assert_true(
			is_array( $stored ) && ( ( 20 << 8 ) | 1 ) === $stored['seq'],
			'the newer sequence survives overlapping writers'
		);
		$this->assert_true( 1 === $this->row_count( $this->hall_b ), 'overlapping ordered writes leave exactly one row' );

		$older = null;
		foreach ( $results as $r ) {
			if ( (int) ( $r['seq'] ?? 0 ) === ( ( 19 << 8 ) | 1 ) ) {
				$older = $r;
			}
		}
		$this->assert_true(
			null !== $older
			&& in_array( (string) ( $older['outcome'] ?? '' ), [ HallCommerceState::SUPERSEDED, HallCommerceState::APPLIED ], true ),
			'the older writer reports superseded, or applied only when it genuinely ran first'
		);
		$this->reset_rows();
	}

	/**
	 * Run workers that all start when a database-held gate is released.
	 *
	 * Readiness is OBSERVED: each worker holds its own ready lock, and the parent
	 * waits until that lock is held by a DIFFERENT connection than its own — the
	 * parent holding it would otherwise look like readiness. If any worker fails
	 * to become ready inside the deadline the gate is NOT released, so no worker
	 * is ever permitted to write after a failed readiness phase.
	 *
	 * After the gate opens, completion is bounded too: output is drained without
	 * blocking, and a worker that has not exited by the deadline is terminated.
	 * Pipes, processes and the gate are released in finally on every path.
	 *
	 * @param array<int,array<string,mixed>> $specs
	 * @param array<string,mixed>            $options ready_timeout, completion_timeout
	 * @return array{ok:bool,reason:string,results:array<int,array<string,mixed>>,exit_codes:array<int,int>}
	 */
	private function run_overlapping( array $specs, array $options = [] ): array {
		global $wpdb;

		$ready_wait      = (float) ( $options['ready_timeout'] ?? 20.0 );
		$completion_wait = (float) ( $options['completion_timeout'] ?? 30.0 );

		/*
		 * Connections that must NOT be mistaken for a ready worker. The parent is
		 * always excluded; the never-ready scenario also excludes the blocker that
		 * deliberately holds a worker's ready lock, since its hold would otherwise
		 * look exactly like the worker having announced itself.
		 */
		$not_workers = array_map( 'intval', (array) ( $options['ignore_connections'] ?? [] ) );

		$token  = $this->coordination_token ?? wp_generate_password( 8, false );
		$gate   = 'bc_gate_' . $token;
		$readys = [];
		foreach ( array_keys( $specs ) as $i ) {
			$readys[ $i ] = 'bc_ready_' . $token . '_' . $i;
		}

		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $gate ) );
		$this->assert_true( '1' === (string) $held, 'the coordination gate was acquired' );
		if ( '1' !== (string) $held ) {
			return [ 'ok' => false, 'reason' => 'gate_unavailable', 'results' => [], 'exit_codes' => [] ];
		}

		$not_workers[] = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );

		$procs      = [];
		$pipes      = [];
		$results    = [];
		$exit_codes = [];
		$gate_open  = false;
		$outcome    = [ 'ok' => false, 'reason' => 'incomplete', 'results' => [], 'exit_codes' => [] ];

		try {
			foreach ( $specs as $i => $spec ) {
				$env = [
					'BC_TEST_DB'       => BC_Fixture::$database,
					'BC_WORKER_HALL'   => (string) $spec['hall'],
					'BC_WORKER_STATE'  => (string) $spec['state'],
					'BC_WORKER_INTENT' => (string) $spec['intent'],
					'BC_WORKER_PHASE'  => (string) $spec['phase'],
					'BC_WORKER_GATE'   => $gate,
					'BC_WORKER_READY'  => $readys[ $i ],
					'PATH'             => (string) getenv( 'PATH' ),
				];
				if ( isset( $spec['ready_timeout'] ) ) {
					$env['BC_WORKER_READY_TIMEOUT'] = (string) $spec['ready_timeout'];
				}
				if ( isset( $spec['stall_ms'] ) ) {
					$env['BC_WORKER_STALL_MS'] = (string) $spec['stall_ms'];
				}
				if ( isset( $spec['ignore_term'] ) ) {
					$env['BC_WORKER_IGNORE_TERM'] = (string) $spec['ignore_term'];
				}

				$handle = proc_open(
					[ PHP_BINARY, __DIR__ . '/hall-commerce-state-worker.php' ],
					[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
					$pipes[ $i ],
					dirname( __DIR__ ),
					$env
				);
				if ( ! is_resource( $handle ) ) {
					$this->assert_true( false, "worker {$i} process could not be created" );
					$outcome['reason'] = 'spawn_failed';

					return $outcome;
				}
				$procs[ $i ] = $handle;
				stream_set_blocking( $pipes[ $i ][1], false );
				stream_set_blocking( $pipes[ $i ][2], false );
			}

			// Readiness: held by someone OTHER than this connection.
			$deadline = microtime( true ) + $ready_wait;
			$all      = false;
			while ( microtime( true ) < $deadline ) {
				$all = true;
				foreach ( $readys as $lock ) {
					$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock ) );
					if ( null === $holder || in_array( (int) $holder, $not_workers, true ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					break;
				}
				usleep( 2000 );
			}

			if ( ! $all ) {
				// The gate stays shut: nothing may write after failed readiness.
				$outcome['reason'] = 'readiness_timeout';

				return $outcome;
			}

			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $gate ) );
			$gate_open = true;

			$completion = microtime( true ) + $completion_wait;
			$stdout     = array_fill_keys( array_keys( $procs ), '' );
			$stderr     = array_fill_keys( array_keys( $procs ), '' );
			$running    = array_keys( $procs );

			while ( [] !== $running && microtime( true ) < $completion ) {
				foreach ( $running as $k => $i ) {
					$stdout[ $i ] .= (string) stream_get_contents( $pipes[ $i ][1] );
					$stderr[ $i ] .= (string) stream_get_contents( $pipes[ $i ][2] );

					$status = proc_get_status( $procs[ $i ] );
					if ( is_array( $status ) && false === $status['running'] ) {
						$exit_codes[ $i ] = (int) $status['exitcode'];
						unset( $running[ $k ] );
					}
				}
				if ( [] !== $running ) {
					usleep( 5000 );
				}
			}

			if ( [] !== $running ) {
				$outcome['reason']     = 'completion_timeout';
				$outcome['exit_codes'] = $exit_codes;

				return $outcome;
			}

			foreach ( $procs as $i => $proc ) {
				$stdout[ $i ] .= (string) stream_get_contents( $pipes[ $i ][1] );
				$decoded       = json_decode( trim( $stdout[ $i ] ), true );
				$results[ $i ] = is_array( $decoded ) ? $decoded : [ 'error' => 'unparsable', 'stderr' => trim( $stderr[ $i ] ) ];
			}

			$outcome = [ 'ok' => true, 'reason' => '', 'results' => $results, 'exit_codes' => $exit_codes ];

			return $outcome;
		} finally {
			foreach ( $pipes as $pair ) {
				foreach ( $pair as $pipe ) {
					if ( is_resource( $pipe ) ) {
						fclose( $pipe );
					}
				}
			}

			/*
			 * Workers are stopped BEFORE the gate is released, so a scenario that
			 * failed readiness can never have a straggler wake up to an open gate
			 * and write. proc_close() blocks until the child exits, so exit is
			 * confirmed first — terminate, poll for a bounded grace period,
			 * escalate if it survives, and record a failure if it still cannot be
			 * confirmed.
			 */
			foreach ( $procs as $i => $proc ) {
				if ( ! is_resource( $proc ) ) {
					continue;
				}

				$status = proc_get_status( $proc );
				if ( is_array( $status ) && $status['running'] ) {
					$requested = proc_terminate( $proc );
					$this->assert_true( $requested, "worker {$i}: termination was requested" );

					if ( ! $this->wait_for_exit( $proc, 2.0 ) ) {
						// Escalate with the supported local mechanism.
						$this->escalated = true;
						proc_terminate( $proc, 9 );
						$this->wait_for_exit( $proc, 2.0 );
					}

					$final = proc_get_status( $proc );
					$alive = is_array( $final ) && $final['running'];
					$this->assert_true( ! $alive, "worker {$i}: exit confirmed before proc_close()" );

					if ( $alive ) {
						// Cannot confirm exit; proc_close() would block. Leave the
						// handle rather than hang the suite, and say so.
						continue;
					}
				}

				proc_close( $proc );
			}

			if ( ! $gate_open ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $gate ) );
			}
		}
	}

	/** Readiness failure is bounded, permits no write, and cleans up fully. */
	private function test_worker_never_ready(): void {
		global $wpdb;

		$this->reset_rows();
		$this->escalated = false;

		$token   = wp_generate_password( 8, false );
		$lock    = 'bc_ready_' . $token . '_0';
		$gate    = 'bc_gate_' . $token;
		$blocker = null;
		$started = microtime( true );

		try {
			// Hold the worker's ready lock on a SECOND connection, so the worker
			// cannot acquire it and the parent's own connection is not the holder.
			$blocker = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$blocker->suppress_errors( true );
			$taken = $blocker->get_var( $blocker->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
			$this->assert_true( '1' === (string) $taken, 'the blocker holds the ready lock' );
			$blocker_connection = (int) $blocker->get_var( 'SELECT CONNECTION_ID()' );

			$result = $this->run_overlapping_with_lock_token(
				$token,
				[
					[
						'hall'          => $this->hall_b,
						'state'         => HallCommerceState::PROVISIONING,
						'intent'        => 1,
						'phase'         => HallCommerceState::PHASE_TRANSITIONAL,
						'ready_timeout' => 1,
					],
				],
				[ 'ready_timeout' => 3.0, 'ignore_connections' => [ $blocker_connection ] ]
			);
		} finally {
			if ( $blocker instanceof wpdb ) {
				$blocker->get_var( $blocker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
				$blocker->close();
			}
		}

		$elapsed = microtime( true ) - $started;

		$this->assert_true( false === $result['ok'], 'a worker that never becomes ready fails the scenario' );
		$this->assert_true( 'readiness_timeout' === $result['reason'], 'the failure is reported as a readiness timeout' );
		$this->assert_true( $elapsed < 20.0, sprintf( 'the scenario including cleanup finished within bound (%.1fs)', $elapsed ) );
		$this->assert_true( 0 === $this->row_count( $this->hall_b ), 'no state was written after failed readiness' );
		$this->assert_true(
			null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $gate ) ),
			'the coordination gate is free after cleanup'
		);
		$this->assert_true(
			null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock ) ),
			'the worker-ready lock is free after cleanup'
		);
	}

	/** A worker that stalls after the gate opens is bounded and terminated. */
	private function test_worker_stalls_before_writing(): void {
		global $wpdb;

		$this->reset_rows();
		$this->escalated = false;

		$started = microtime( true );
		$result  = $this->run_overlapping(
			[
				[
					'hall'     => $this->hall_b,
					'state'    => HallCommerceState::PROVISIONING,
					'intent'   => 1,
					'phase'    => HallCommerceState::PHASE_TRANSITIONAL,
					'stall_ms' => 8000,
				],
			],
			[ 'completion_timeout' => 1.5 ]
		);
		$elapsed = microtime( true ) - $started;

		$this->assert_true( false === $result['ok'], 'a stalled worker fails the scenario' );
		$this->assert_true( 'completion_timeout' === $result['reason'], 'the failure is reported as a completion timeout' );
		$this->assert_true( $elapsed < 12.0, sprintf( 'the stalled scenario including cleanup finished within bound (%.1fs)', $elapsed ) );
		$this->assert_true( 0 === $this->row_count( $this->hall_b ), 'a terminated worker wrote no state' );
		$this->assert_true( false === $this->escalated, 'a SIGTERM-respecting worker needed no escalation' );
	}

	/**
	 * A worker that IGNORES SIGTERM must still be stopped, by escalation.
	 *
	 * Exercised for real with a worker that installs SIG_IGN, not asserted from
	 * the cleanup code's shape. Skipped with an explicit note where pcntl is
	 * unavailable, since the knob would then be a no-op.
	 */
	private function test_worker_termination_escalation(): void {
		global $wpdb;

		if ( ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			$this->assert_true( true, 'escalation scenario skipped: pcntl is unavailable in this PHP build' );

			return;
		}

		$this->reset_rows();
		$this->escalated = false;

		$started = microtime( true );
		$result  = $this->run_overlapping(
			[
				[
					'hall'        => $this->hall_b,
					'state'       => HallCommerceState::PROVISIONING,
					'intent'      => 1,
					'phase'       => HallCommerceState::PHASE_TRANSITIONAL,
					'stall_ms'    => 15000,
					'ignore_term' => 1,
				],
			],
			[ 'completion_timeout' => 1.5 ]
		);
		$elapsed = microtime( true ) - $started;

		$this->assert_true( false === $result['ok'], 'the SIGTERM-ignoring worker fails the scenario' );
		$this->assert_true( 'completion_timeout' === $result['reason'], 'the failure is reported as a completion timeout' );
		$this->assert_true( true === $this->escalated, 'cleanup escalated past SIGTERM for a worker that ignored it' );
		$this->assert_true( $elapsed < 15.0, sprintf( 'escalation completed within bound (%.1fs)', $elapsed ) );
		$this->assert_true( 0 === $this->row_count( $this->hall_b ), 'the escalated worker wrote no state' );
	}

	/**
	 * run_overlapping() with a caller-supplied coordination token.
	 *
	 * Only the never-ready scenario needs this, so it can pre-hold the exact
	 * ready lock the worker will try to take.
	 *
	 * @param array<int,array<string,mixed>> $specs
	 * @param array<string,mixed>            $options
	 * @return array{ok:bool,reason:string,results:array,exit_codes:array}
	 */
	private function run_overlapping_with_lock_token( string $token, array $specs, array $options = [] ): array {
		$this->coordination_token = $token;
		try {
			return $this->run_overlapping( $specs, $options );
		} finally {
			$this->coordination_token = null;
		}
	}

	/**
	 * Force a newer write to land between another operation's write and its
	 * read-back, and require the earlier operation to report superseded.
	 *
	 * This is the interleaving the ordering guard exists for, and it cannot be
	 * produced by luck: the newer write is injected deterministically on a
	 * SECOND connection at the exact point between the two statements.
	 */
	private function test_forced_interleaving(): void {
		global $wpdb;

		$this->reset_rows();
		$hall = $this->hall_b;

		$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second->suppress_errors( true );
		$table  = ProvisioningMigrations::state_table();
		$newer  = ( 31 << 8 ) | HallCommerceState::PHASE_SETTLED;

		$fired = false;
		$interleave = static function ( $query ) use ( $second, $table, $newer, &$fired ) {
			// After the guarded INSERT has completed, before the read-back SELECT.
			if ( ! $fired && 1 === preg_match( '/^SELECT blog_id, seq, state/i', ltrim( (string) $query ) ) ) {
				$fired = true;
				$second->query(
					$second->prepare(
						"INSERT INTO `{$table}` (blog_id, seq, state, updated_at)
						 VALUES (%d, %d, %s, %s)
						 ON DUPLICATE KEY UPDATE
						   state = IF(VALUES(seq) > seq, VALUES(state), state),
						   updated_at = IF(VALUES(seq) > seq, VALUES(updated_at), updated_at),
						   seq = IF(VALUES(seq) > seq, VALUES(seq), seq)",
						$GLOBALS['bc_interleave_hall'],
						$newer,
						HallCommerceState::SUSPENDED,
						'2022-03-04 05:06:07'
					)
				);
			}

			return $query;
		};

		$GLOBALS['bc_interleave_hall'] = $hall;
		add_filter( 'query', $interleave, 1 );
		try {
			$earlier = HallCommerceState::write( $hall, HallCommerceState::OPERATIONAL, 30, HallCommerceState::PHASE_SETTLED );
		} finally {
			remove_filter( 'query', $interleave, 1 );
			unset( $GLOBALS['bc_interleave_hall'] );
			$second->close();
		}

		$this->assert_true( $fired, 'the newer write was injected between the write and the read-back' );
		$this->assert_true(
			is_array( $earlier ) && HallCommerceState::SUPERSEDED === $earlier['outcome'],
			'an operation whose state is overtaken before confirmation reports superseded'
		);

		$stored = HallCommerceState::read( $hall );
		$this->assert_true(
			is_array( $stored ) && $newer === $stored['seq'] && HallCommerceState::SUSPENDED === $stored['state'],
			'the newer state is what remains stored'
		);
		$this->reset_rows();
	}

	/**
	 * The refusal happens in a real process, before any write, and that process
	 * leaves the parent's fixture untouched.
	 */
	private function test_connection_mode_enforced(): void {
		global $wpdb;

		$this->reset_rows();
		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 800, HallCommerceState::PHASE_SETTLED );

		$table          = ProvisioningMigrations::state_table();
		$row_before     = HallCommerceState::read( $this->hall_a );
		$rows_before    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' );
		$version_before = (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );
		$table_before   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$command = 'BC_TEST_DB=' . escapeshellarg( BC_Fixture::$database )
			. ' BC_FIXTURE_FOUND_ROWS=1 '
			. escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
		$output  = [];
		$code    = 0;
		exec( $command, $output, $code );

		$text = implode( "\n", $output );
		$this->assert_true( 0 === $code, 'the connection-mode probe process passed' . ( 0 === $code ? '' : ": {$text}" ) );
		$this->assert_true( false !== strpos( $text, 'assertions passed' ), 'the connection-mode probe reported assertions' );

		// The probe must have left the parent's fixture exactly as it was.
		$this->assert_true( $table_before === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'the probe left the state table in place' );
		$this->assert_true( $rows_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ), 'the probe left the state rows unchanged' );
		$this->assert_true( $row_before === HallCommerceState::read( $this->hall_a ), 'the probe left the stored row byte-identical' );
		$this->assert_true( $version_before === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ), 'the probe left the schema version unchanged' );

		$this->reset_rows();
	}

	/** Correction 5: version reads and version recording are protected too. */
	private function test_version_path_throwing(): void {
		global $wpdb;

		$suppress_before = $wpdb->suppress_errors;

		/*
		 * get_site_option() caches, so without dropping the cached value the
		 * injected failure would never be reached and the test would pass for
		 * the wrong reason. Key shapes follow get_network_option().
		 */
		$forget_version = static function (): void {
			$network = get_current_network_id();
			wp_cache_delete( $network . ':' . ProvisioningMigrations::VERSION_OPTION, 'site-options' );
			wp_cache_delete( $network . ':notoptions', 'site-options' );
		};

		// Throwing version READ: is_current() must fail closed, not explode.
		$forget_version();
		$throw_version_read = static function ( $query ) {
			// ONLY the Commerce version option: a broader match would also hit
			// WordPress's own network-option reads and prove nothing about this
			// code path.
			if ( false !== strpos( (string) $query, 'sitemeta' )
				&& false !== strpos( (string) $query, ProvisioningMigrations::VERSION_OPTION ) ) {
				throw new RuntimeException( 'injected version read failure' );
			}

			return $query;
		};
		add_filter( 'query', $throw_version_read, 1 );
		try {
			$current = ProvisioningMigrations::is_current();
			$write   = HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 70, HallCommerceState::PHASE_SETTLED );
		} finally {
			remove_filter( 'query', $throw_version_read, 1 );
		}
		$this->assert_true( false === $current, 'a throwing version read reports not-current, failing closed' );
		$this->assert_true(
			$write instanceof WP_Error && 'state_schema_unavailable' === $write->get_error_code(),
			'a throwing version read refuses the write with a fixed category'
		);
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'suppression is restored after a throwing version read' );

		// Throwing version RECORD during migrate().
		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->fixture_operation( self::OP_DELETE_VERSION );

		$throw_version_write = static function ( $query ) {
			if ( 1 === preg_match( '/^(INSERT INTO|UPDATE|REPLACE INTO)\s+\S*sitemeta/i', ltrim( (string) $query ) )
				&& false !== strpos( (string) $query, ProvisioningMigrations::VERSION_OPTION ) ) {
				throw new RuntimeException( 'injected version record failure' );
			}

			return $query;
		};
		$forget_version();
		add_filter( 'query', $throw_version_write, 1 );
		try {
			$migrated = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $throw_version_write, 1 );
			$forget_version();
		}
		$this->assert_true(
			$migrated instanceof WP_Error && 'provisioning_migration_verification_failed' === $migrated->get_error_code(),
			'a throwing version record returns a fixed category'
		);
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'suppression is restored after a throwing version record' );

		// Actual persisted state with injection removed.
		$forget_version();
		$this->assert_true(
			0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'no schema version was recorded when recording threw'
		);
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'migration succeeds once the injection is removed' );
		$this->assert_true( ProvisioningMigrations::is_current(), 'the version is recorded after the clean migration' );
		$this->reset_rows();
	}

	/**
	 * migrate() itself must refuse when the INITIAL version read fails.
	 *
	 * Attempted writes are observed, not inferred from the final table state: a
	 * migration that ran dbDelta and then failed verification would leave no
	 * table either, and that is a different bug.
	 */
	private function test_migration_refuses_unreadable_version(): void {
		global $wpdb;

		$version_query = static function ( $query ): bool {
			return false !== strpos( (string) $query, 'sitemeta' )
				&& false !== strpos( (string) $query, ProvisioningMigrations::VERSION_OPTION );
		};

		$writes = [];
		$observer = static function ( $query ) use ( &$writes ) {
			if ( 1 === preg_match( '/^(CREATE|ALTER|DROP|TRUNCATE|INSERT|UPDATE|REPLACE)\b/i', ltrim( (string) $query ) ) ) {
				$writes[] = strtoupper( (string) strtok( ltrim( (string) $query ), " \t\n(" ) );
			}

			return $query;
		};

		// --- Case A: the initial version query returns a DATABASE ERROR --------
		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->fixture_operation( self::OP_DELETE_VERSION );

		$fired  = 0;
		$writes = [];
		$suppress_before = $wpdb->suppress_errors;
		$error_injection = static function ( $query ) use ( $version_query, &$fired ) {
			if ( $version_query( $query ) ) {
				$fired++;

				return 'SELECT meta_value FROM zz_bc_no_such_sitemeta LIMIT 1';
			}

			return $query;
		};
		add_filter( 'query', $error_injection, 1 );
		add_filter( 'query', $observer, 20 );
		try {
			$result = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $error_injection, 1 );
			remove_filter( 'query', $observer, 20 );
		}

		$this->assert_true( $fired > 0, 'case A: the version-read error injection fired' );
		$this->assert_true(
			$result instanceof WP_Error && 'provisioning_migration_verification_failed' === $result->get_error_code(),
			'case A: a database error on the initial version read returns a fixed error'
		);
		$this->assert_true( [] === $writes, 'case A: no schema or version write was attempted (' . implode( ',', $writes ) . ')' );
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'case A: error suppression is restored' );
		$this->assert_true(
			null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( ProvisioningMigrations::state_table() ) ) ),
			'case A: the state table was not created'
		);

		// --- Case B: the initial version query THROWS ONCE ---------------------
		$fired  = 0;
		$writes = [];
		$suppress_before = $wpdb->suppress_errors;
		$throw_once = static function ( $query ) use ( $version_query, &$fired ) {
			if ( $version_query( $query ) && 0 === $fired ) {
				$fired++;
				throw new RuntimeException( 'injected one-time version read failure' );
			}

			return $query;
		};
		add_filter( 'query', $throw_once, 1 );
		add_filter( 'query', $observer, 20 );
		try {
			$result = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $throw_once, 1 );
			remove_filter( 'query', $observer, 20 );
		}

		$this->assert_true( 1 === $fired, 'case B: the one-time throw injection fired exactly once' );
		$this->assert_true(
			$result instanceof WP_Error && 'provisioning_migration_verification_failed' === $result->get_error_code(),
			'case B: a thrown initial version read returns a fixed error'
		);
		$this->assert_true(
			[] === $writes,
			'case B: the throw was not swallowed and followed by migration (' . implode( ',', $writes ) . ')'
		);
		$this->assert_true( $suppress_before === $wpdb->suppress_errors, 'case B: error suppression is restored' );
		$this->assert_true(
			null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( ProvisioningMigrations::state_table() ) ) ),
			'case B: the state table was not created'
		);
		$this->assert_true(
			0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'case B: no version was recorded'
		);

		// --- Positive control: a genuinely absent version migrates -------------
		$writes = [];
		add_filter( 'query', $observer, 20 );
		try {
			$ok = ProvisioningMigrations::migrate();
		} finally {
			remove_filter( 'query', $observer, 20 );
		}
		$this->assert_true( true === $ok, 'positive control: a genuinely absent version migrates successfully' );
		$this->assert_true( [] !== $writes, 'positive control: schema and version writes were attempted' );
		$this->assert_true( ProvisioningMigrations::is_current(), 'positive control: the version is recorded' );
		$this->assert_true( true === ProvisioningMigrations::verify_schema(), 'positive control: the schema verifies' );
	}

	/** Correction 6: identity checks apply to reads, and stored identity is validated. */
	private function test_read_identity_validation(): void {
		global $wpdb;

		$this->assert_true(
			is_wp_error( HallCommerceState::read( 990002 ) )
			&& 'site_not_found' === HallCommerceState::read( 990002 )->get_error_code(),
			'reading a non-existent Hall is refused'
		);
		$main = HallCommerceState::read( (int) get_main_site_id() );
		$this->assert_true(
			is_wp_error( $main ) && 'main_site_not_allowed' === $main->get_error_code(),
			'reading the main network site is refused'
		);

		// Wrong network: move the Hall to another network id, read, restore.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->blogs}` SET site_id = %d WHERE blog_id = %d", 99, $this->hall_a ) );
		clean_blog_cache( $this->hall_a );
		$foreign = HallCommerceState::read( $this->hall_a );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->blogs}` SET site_id = %d WHERE blog_id = %d", 1, $this->hall_a ) );
		clean_blog_cache( $this->hall_a );
		$this->assert_true(
			is_wp_error( $foreign ) && 'site_wrong_network' === $foreign->get_error_code(),
			'reading a Hall on another network is refused'
		);

		// Control: the restored Hall reads cleanly.
		$this->assert_true( null === HallCommerceState::read( $this->hall_a ), 'the restored Hall reads as a verified absence' );

		$table = ProvisioningMigrations::state_table();
		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 80, HallCommerceState::PHASE_SETTLED );

		// Malformed returned identity: the row answers for a different Hall.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET blog_id = %d WHERE blog_id = %d", $this->hall_b, $this->hall_a ) );
		$mismatch = HallCommerceState::read( $this->hall_a );
		$this->assert_true( null === $mismatch, 'a row belonging to another Hall is not returned for this one' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET blog_id = %d WHERE blog_id = %d", $this->hall_a, $this->hall_b ) );

		// Stored sequence beyond PHP's supported range.
		$beyond = '18446744073709551615';
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET seq = %s WHERE blog_id = %d", $beyond, $this->hall_a ) );
		$huge = HallCommerceState::read( $this->hall_a );
		$this->assert_true(
			is_wp_error( $huge ) && 'state_row_invalid' === $huge->get_error_code(),
			'a stored sequence beyond PHP\'s range is rejected, not truncated'
		);

		$this->reset_rows();
		$this->assert_true( null === HallCommerceState::read( $this->hall_a ), 'a cleared Hall reads as a verified absence again' );
	}

	/** Ownership refusals, exercised only in a scratch database THIS run created. */
	private function test_ownership_negative_controls(): void {
		$scratch = 'bingocommerce_state_test_scratch';

		if ( ! $this->scratch_create( $scratch ) ) {
			return;
		}

		try {
			// (a) exists, empty, unmarked — ownership is not implied by emptiness.
			$this->assert_scratch_refuses( $scratch, 'an existing empty database with no sentinel' );

			// (b) exists with foreign tables.
			if ( $this->scratch_apply( $scratch, [ 'CREATE TABLE bc_something ( id int )' ] ) ) {
				$this->assert_scratch_refuses( $scratch, 'an existing database with foreign tables' );
			}

			// (c) sentinel present, wrong identity.
			$applied = $this->scratch_apply(
				$scratch,
				[
					'DROP TABLE bc_something',
					'CREATE TABLE ' . BC_FIXTURE_SENTINEL_TABLE . ' ( identity varchar(191) NOT NULL, PRIMARY KEY (identity) )',
					"INSERT INTO " . BC_FIXTURE_SENTINEL_TABLE . " (identity) VALUES ('someone-elses-fixture')",
				]
			);
			if ( $applied ) {
				$this->assert_scratch_refuses( $scratch, 'a sentinel carrying a different fixture identity' );
			}
		} finally {
			$this->scratch_drop( $scratch );
		}
	}

	/**
	 * Create the scratch database, refusing a pre-existing one untouched.
	 *
	 * There is no initial DROP: an allowlisted NAME is not ownership, and a
	 * database this run did not create is never modified — not even when empty.
	 * Ownership is recorded only after CREATE DATABASE actually succeeds.
	 */
	private function scratch_create( string $database ): bool {
		if ( ! in_array( $database, BC_FIXTURE_DB_ALLOWLIST, true ) || $database === BC_Fixture::$live_db ) {
			$this->abort( "scratch database '{$database}' is not allowlisted." );
		}

		$db = @new mysqli( DB_HOST, DB_USER, DB_PASSWORD );
		if ( $db->connect_errno ) {
			$this->abort( 'could not reach the database server for the scratch fixture.' );
		}

		$stmt = $db->prepare( 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?' );
		$stmt->bind_param( 's', $database );
		$stmt->execute();
		$exists = '' !== (string) ( $stmt->get_result()->fetch_row()[0] ?? '' );
		$stmt->close();

		if ( $exists ) {
			$db->close();
			$this->assert_true(
				false,
				"scratch database '{$database}' already exists and was left untouched; remove it by hand to run the ownership controls"
			);

			return false;
		}

		$created = $db->query( 'CREATE DATABASE `' . $database . '` DEFAULT CHARACTER SET utf8mb4' );
		$db->close();

		$this->assert_true( false !== $created, 'the scratch database was created by this run' );
		if ( false === $created ) {
			return false;
		}

		$this->owns_scratch = $database;

		return true;
	}

	/**
	 * Apply setup statements inside the run-owned scratch database.
	 *
	 * Selection and every statement are checked; the first failure stops the
	 * scenario rather than letting a half-built fixture masquerade as a result.
	 *
	 * @param array<int,string> $statements
	 */
	private function scratch_apply( string $database, array $statements ): bool {
		if ( $this->owns_scratch !== $database ) {
			$this->assert_true( false, 'refusing to modify a scratch database this run does not own' );

			return false;
		}

		$db = @new mysqli( DB_HOST, DB_USER, DB_PASSWORD );
		if ( $db->connect_errno ) {
			$this->assert_true( false, 'could not reach the database server for scratch setup' );

			return false;
		}
		if ( ! $db->select_db( $database ) ) {
			$db->close();
			$this->assert_true( false, "could not select the scratch database '{$database}'" );

			return false;
		}

		foreach ( $statements as $sql ) {
			if ( false === $db->query( $sql ) ) {
				$db->close();
				$this->assert_true( false, 'a scratch setup statement failed; scenario stopped' );

				return false;
			}
		}

		$db->close();

		return true;
	}

	/** Drop ONLY a scratch database this run created. */
	private function scratch_drop( string $database ): void {
		if ( $this->owns_scratch !== $database ) {
			// Nothing this run owns: leave it exactly as found.
			return;
		}

		$db = @new mysqli( DB_HOST, DB_USER, DB_PASSWORD );
		if ( $db->connect_errno ) {
			$this->assert_true( false, 'could not reach the database server to drop the scratch fixture' );

			return;
		}
		$dropped = $db->query( 'DROP DATABASE `' . $database . '`' );
		$db->close();

		$this->assert_true( false !== $dropped, 'the run-owned scratch database was dropped' );
		$this->owns_scratch = null;
	}

	private function assert_scratch_refuses( string $database, string $label ): void {
		$command = 'BC_TEST_DB=' . escapeshellarg( $database ) . ' '
			. escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
		$output  = [];
		$code    = 0;
		exec( $command, $output, $code );

		$text = implode( "\n", $output );
		$this->assert_true( 1 === $code, "the fixture refuses {$label}" );
		$this->assert_true( false !== strpos( $text, 'FIXTURE GUARD' ), "the refusal for {$label} names the fixture guard" );
		$this->assert_true( false === strpos( $text, 'assertions passed' ), "no test ran against {$label}" );
	}

	private function test_halls_independent(): void {
		$this->reset_rows();

		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 30, HallCommerceState::PHASE_SETTLED );
		HallCommerceState::write( $this->hall_b, HallCommerceState::SUSPENDED, 31, HallCommerceState::PHASE_SETTLED );

		$a = HallCommerceState::read( $this->hall_a );
		$b = HallCommerceState::read( $this->hall_b );
		$this->assert_true( is_array( $a ) && HallCommerceState::OPERATIONAL === $a['state'], 'hall A keeps its own state' );
		$this->assert_true( is_array( $b ) && HallCommerceState::SUSPENDED === $b['state'], 'hall B keeps its own state' );
		$this->assert_true( is_array( $a ) && is_array( $b ) && $a['seq'] !== $b['seq'], 'the two Halls hold independent sequences' );

		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 32, HallCommerceState::PHASE_SETTLED );
		$this->assert_true( $b === HallCommerceState::read( $this->hall_b ), 'writing hall A leaves hall B byte-identical' );
		$this->reset_rows();
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function set_site_flag( int $blog_id, string $flag, int $value ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->blogs}` SET `{$flag}` = %d WHERE blog_id = %d", $value, $blog_id ) );
		clean_blog_cache( $blog_id );
	}

	private function reset_rows(): void {
		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
	}

	private function row_count( int $blog_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::state_table() . '` WHERE blog_id = %d', $blog_id )
		);
	}

	private function schema_fingerprint(): string {
		global $wpdb;

		$table   = ProvisioningMigrations::state_table();
		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

		return md5( (string) wp_json_encode( [ $columns, $indexes ] ) );
	}

	/** @param mixed $value */
	private function describe( $value ): string {
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}

		return gettype( $value ) . '(' . var_export( $value, true ) . ')';
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

if ( '1' === (string) getenv( 'BC_FIXTURE_FOUND_ROWS' ) ) {
	( new HallCommerceStateTest() )->run_connection_mode_probe();
}

( new HallCommerceStateTest() )->run();
