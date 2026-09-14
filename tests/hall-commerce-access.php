<?php

/**
 * CLI coverage for Hall Commerce access enforcement (HallCommerceAccess +
 * HallCommerceGate + the PortalSyncService boundary).
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_test php tests/hall-commerce-access.php
 *
 * WordPress boots against the isolated fixture — see
 * tests/fixtures/isolated-multisite.php. The development database is never
 * opened by the bootstrap, its identities are never borrowed, outbound HTTP is
 * blocked before any plugin can run, and persistent object caching is off.
 *
 * Every destructive statement re-verifies the connected database, the
 * allowlist, the fixture sentinel and the target name before it runs. The
 * fixture supplies only the MAIN site's per-blog tables, so this suite creates
 * the two Hall options tables it needs and drops only the ones it created.
 *
 * Marker rows are written HERE, as fixture data — lowercase UUID v4 values, the
 * contract an enrolling operation will write. The production code in this
 * increment never creates, deletes or backfills a marker; it only reads one.
 *
 * REQUEST ENFORCEMENT IS EXERCISED FOR REAL, not as fabricated arrays. The gate
 * skips the CLI SAPI by design, so tests/hall-commerce-access-request.php runs
 * each request in its own process — under php-cgi wherever available, so
 * enforce() runs on the genuine `plugins_loaded` hook and real response headers
 * are observable, and under CLI otherwise.
 */

// Set before the fixture boots: it makes wp-settings.php load the Portal
// Integration plugin as a network-active plugin, exactly as production does,
// and keeps the value identical between this process and its workers.
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

use BingoCommerce\PortalIntegration\HallCommerceAccess;
use BingoCommerce\PortalIntegration\HallCommerceGate;
use BingoCommerce\PortalIntegration\HallCommerceState;
use BingoCommerce\PortalIntegration\Migrations;
use BingoCommerce\PortalIntegration\Plugin;
use BingoCommerce\PortalIntegration\PortalSyncAdmin;
use BingoCommerce\PortalIntegration\PortalSyncService;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;

final class HallCommerceAccessTest {

	private int $assertions = 0;
	private int $failures   = 0;

	private int $hall_a = BC_FIXTURE_HALL_A;
	private int $hall_b = BC_FIXTURE_HALL_B;

	/** Tables THIS run created, and may therefore drop. */
	private bool $owns_state_table   = false;
	private bool $owns_mapping_table = false;
	/** @var array<int,bool> */
	private array $owns_hall_options = [];

	/** Outbound HTTP attempts observed, by the fixture-independent counter. */
	private int $http_attempts = 0;

	/** Users created by this run, for cleanup. @var array<string,int> */
	private array $users = [];

	/** A well-formed managed-Hall witness: the enrolling operation's UUID v4. */
	private const MARKER_A = '3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13';
	private const MARKER_B = 'c1d7e2b9-04a6-4f3c-b8d5-7e9a1c3f5b20';

	/** Absolute path to php-cgi, or '' when this machine has none. */
	private string $php_cgi = '';

	/** WooCommerce source paths, read only. */
	private string $wc_form_handler = '';
	private string $wc_ajax         = '';
	private string $wc_checkout     = '';

	/* ------------------------------------------------------------------ *
	 * Entry
	 * ------------------------------------------------------------------ */

	public function run(): void {
		$this->wc_form_handler = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/class-wc-form-handler.php';
		$this->wc_ajax         = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/class-wc-ajax.php';
		$this->wc_checkout     = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/class-wc-checkout.php';
		$this->php_cgi         = $this->locate_php_cgi();

		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();
		$this->count_outbound_http();

		try {
			$this->prepare_hall_options();
			$this->create_test_users();

			$before = $this->fingerprint();

			$this->test_initial_deployment_without_schema();
			$this->test_marker_outcomes();
			$this->test_states_with_schema();
			$this->test_invalid_stored_rows();
			$this->test_unreadable_state_storage();
			$this->test_state_authoritative_without_version();
			$this->test_identity_failure_never_becomes_legacy();
			$this->test_identity_and_argument_handling();
			$this->test_marker_only_consulted_when_needed();
			$this->test_hall_isolation_and_switched_context();
			$this->test_no_cached_allow();
			$this->test_evaluation_writes_nothing();

			$this->test_gate_refuses_ordinary_requests();
			$this->test_gate_authentication_path();
			$this->test_gate_super_admin_recovery();
			$this->test_gate_staff_sessions_do_not_bypass();
			$this->test_gate_background_exemptions();
			$this->test_gate_payment_callbacks();
			$this->test_scheduler_nonce();
			$this->test_gate_mixed_shopping_requests();
			$this->test_detach_matches_installed_woocommerce();
			$this->test_detach_removes_handlers();
			$this->test_gate_is_registered_and_cli_safe();

			$this->test_real_request_enforcement();
			$this->test_sync_boundary();
			$this->test_admin_error_category();

			$this->test_guard_refusals();
			$this->test_data_preserved( $before );
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
		$this->assert_true( ! file_exists( WP_CONTENT_DIR . '/db.php' ), 'no database-routing drop-in is present' );
		$this->assert_true(
			get_site( $this->hall_a ) instanceof WP_Site && get_site( $this->hall_b ) instanceof WP_Site,
			'the fixture supplies two Hall sites'
		);
		$this->assert_true(
			! is_main_site( $this->hall_a ) && ! is_main_site( $this->hall_b ),
			'the fixture Hall sites are not the main site'
		);
		$this->assert_true(
			is_readable( $this->wc_form_handler ) && is_readable( $this->wc_ajax ) && is_readable( $this->wc_checkout ),
			'the installed WooCommerce form-handler, AJAX and checkout sources are readable'
		);
	}

	/**
	 * Refuse an inconsistent leftover fixture rather than destroying it.
	 */
	private function require_clean_starting_state(): void {
		$state   = ProvisioningMigrations::state_table();
		$version = (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );

		if ( $this->table_exists( $state ) || $version > 0 ) {
			$this->abort( 'a Commerce state table or schema version is already present. Refusing to adopt it.' );
		}
		if ( $this->table_exists( Migrations::hall_mappings_table() )
			|| (int) get_site_option( Migrations::NETWORK_VERSION_OPTION, 0 ) > 0 ) {
			$this->abort( 'a Hall mapping table or schema version is already present. Refusing to adopt it.' );
		}
		foreach ( [ $this->hall_a, $this->hall_b ] as $hall ) {
			if ( $this->table_exists( $this->options_table( $hall ) ) ) {
				$this->abort( "an options table for Hall {$hall} already exists. Refusing to adopt it." );
			}
		}
		foreach ( [ 'fixture_admin', 'fixture_hall_manager', 'fixture_game_operator' ] as $login ) {
			if ( get_user_by( 'login', $login ) instanceof WP_User ) {
				$this->abort( "the fixture user {$login} already exists. Refusing to adopt it." );
			}
		}
	}

	private function count_outbound_http(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt ) {
				$this->http_attempts++;

				return $preempt;
			},
			0
		);
	}

	/* ------------------------------------------------------------------ *
	 * Destructive-operation guard
	 * ------------------------------------------------------------------ */

	private const OP_DROP_STATE_TABLE    = 'drop_state_table';
	private const OP_DELETE_STATE_ROWS   = 'delete_state_rows';
	private const OP_DELETE_VERSION      = 'delete_version_option';
	private const OP_CREATE_HALL_OPTIONS = 'create_hall_options';
	private const OP_DROP_HALL_OPTIONS   = 'drop_hall_options';
	private const OP_SET_MARKER          = 'set_marker';
	private const OP_DELETE_MARKER       = 'delete_marker';
	private const OP_INSERT_RAW_STATE    = 'insert_raw_state';
	private const OP_DROP_MAPPING_TABLE  = 'drop_mapping_table';

	/**
	 * The ADR-042 provisioning-operations table.
	 *
	 * `ProvisioningMigrations::migrate()` installs the whole v2 schema, which is
	 * BOTH the commerce-state table and this one, so a run that migrates owns
	 * both and must remove both. Without this the operations table survives the
	 * run and the next harness to start — `portal-provisioning-foundation.php`,
	 * which correctly refuses to adopt a schema it did not create — aborts,
	 * making the suite order-dependent.
	 */
	private const OP_DROP_OPERATIONS_TABLE = 'drop_operations_table';
	private const OP_DELETE_MAPPING_VERSION = 'delete_mapping_version';
	private const OP_DELETE_FIXTURE_USER = 'delete_fixture_user';
	private const OP_SET_PERMALINKS      = 'set_permalink_structure';

	private const PERMITTED_OPERATIONS = [
		self::OP_DROP_STATE_TABLE,
		self::OP_DROP_OPERATIONS_TABLE,
		self::OP_DELETE_STATE_ROWS,
		self::OP_DELETE_VERSION,
		self::OP_CREATE_HALL_OPTIONS,
		self::OP_DROP_HALL_OPTIONS,
		self::OP_SET_MARKER,
		self::OP_DELETE_MARKER,
		self::OP_INSERT_RAW_STATE,
		self::OP_DROP_MAPPING_TABLE,
		self::OP_DELETE_MAPPING_VERSION,
		self::OP_DELETE_FIXTURE_USER,
		self::OP_SET_PERMALINKS,
	];

	/**
	 * Verify ownership, then perform one named fixture operation.
	 *
	 * The statement is built HERE, from the operation and validated arguments
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

		// Every Hall-scoped operation may only ever touch a fixture Hall.
		$hall = null;
		if ( array_key_exists( 'blog_id', $args ) ) {
			$hall = $args['blog_id'];
			if ( ! is_int( $hall ) || ! in_array( $hall, [ $this->hall_a, $this->hall_b ], true ) ) {
				return $refuse( 'the target Hall is not a fixture Hall' );
			}
		}

		$state_table = ProvisioningMigrations::state_table();
		if ( $state_table !== $wpdb->base_prefix . ProvisioningMigrations::STATE_TABLE ) {
			return $refuse( 'the state table name did not resolve as expected' );
		}

		switch ( $operation ) {
			case self::OP_DROP_STATE_TABLE:
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $state_table . '`' );
				break;

			case self::OP_DELETE_STATE_ROWS:
				$result = $wpdb->query( 'DELETE FROM `' . $state_table . '`' );
				break;

			case self::OP_DROP_OPERATIONS_TABLE:
				$operations = ProvisioningMigrations::operations_table();
				if ( $operations !== $wpdb->base_prefix . ProvisioningMigrations::OPERATIONS_TABLE ) {
					return $refuse( 'the operations table name did not resolve as expected' );
				}
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $operations . '`' );
				break;

			case self::OP_DELETE_VERSION:
				$result = delete_site_option( ProvisioningMigrations::VERSION_OPTION ) ? 1 : 0;
				break;

			case self::OP_DROP_MAPPING_TABLE:
				$mapping = Migrations::hall_mappings_table();
				if ( $mapping !== $wpdb->base_prefix . Migrations::HALL_MAPPINGS_TABLE ) {
					return $refuse( 'the mapping table name did not resolve as expected' );
				}
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $mapping . '`' );
				break;

			case self::OP_DELETE_MAPPING_VERSION:
				$result = delete_site_option( Migrations::NETWORK_VERSION_OPTION ) ? 1 : 0;
				break;

			case self::OP_CREATE_HALL_OPTIONS:
				// Structure copied from the fixture's own main-site options
				// table. No development table is read.
				$table  = $this->options_table( (int) $hall );
				$result = $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $table . '` LIKE `' . $wpdb->base_prefix . 'options`' );
				/*
				 * Seeded with autoloaded rows on purpose. wp_load_alloptions()
				 * falls back to "SELECT * FROM options" when its autoload query
				 * returns nothing, so a genuinely empty table would make every
				 * non-autoloaded option visible to get_option() and quietly
				 * invalidate the cached-miss scenario below.
				 */
				foreach (
					[
						'siteurl'            => 'http://hall.example.invalid',
						'home'               => 'http://hall.example.invalid',
						'blogname'           => 'Fixture Hall',
						// The wc-api rewrite endpoint only exists with a permalink
						// structure behind it, and the gate checks for one.
						'permalink_structure' => '/%postname%/',
					] as $name => $value
				) {
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO `{$table}` (option_name, option_value, autoload)
							 VALUES (%s, %s, 'yes')
							 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
							$name,
							$value
						)
					);
				}
				break;

			case self::OP_DROP_HALL_OPTIONS:
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $this->options_table( (int) $hall ) . '`' );
				break;

			case self::OP_SET_MARKER:
				if ( ! isset( $args['value'] ) || ! is_string( $args['value'] ) ) {
					return $refuse( 'the marker value is not a string' );
				}
				$table  = $this->options_table( (int) $hall );
				$result = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `{$table}` (option_name, option_value, autoload)
						 VALUES (%s, %s, 'no')
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
						HallCommerceAccess::MARKER_OPTION,
						$args['value']
					)
				);
				break;

			case self::OP_DELETE_MARKER:
				$table  = $this->options_table( (int) $hall );
				$result = $wpdb->query(
					$wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", HallCommerceAccess::MARKER_OPTION )
				);
				break;

			case self::OP_INSERT_RAW_STATE:
				foreach ( [ 'seq', 'state', 'updated_at' ] as $key ) {
					if ( ! isset( $args[ $key ] ) ) {
						return $refuse( "the raw state {$key} is missing" );
					}
				}
				if ( ! is_int( $args['seq'] ) || ! is_string( $args['state'] ) || ! is_string( $args['updated_at'] ) ) {
					return $refuse( 'the raw state arguments are not the expected types' );
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `{$state_table}` (blog_id, seq, state, updated_at)
						 VALUES (%d, %d, %s, %s)
						 ON DUPLICATE KEY UPDATE seq = VALUES(seq), state = VALUES(state), updated_at = VALUES(updated_at)",
						$hall,
						$args['seq'],
						$args['state'],
						$args['updated_at']
					)
				);
				break;

			case self::OP_SET_PERMALINKS:
				if ( ! isset( $args['value'] ) || ! is_string( $args['value'] ) ) {
					return $refuse( 'the permalink structure is not a string' );
				}
				$table  = $wpdb->base_prefix . 'options';
				$result = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `{$table}` (option_name, option_value, autoload)
						 VALUES ('permalink_structure', %s, 'yes')
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
						$args['value']
					)
				);
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'permalink_structure', 'options' );
				break;

			case self::OP_DELETE_FIXTURE_USER:
				/*
				 * Ownership is re-proven AT DELETION TIME, not inherited from the
				 * fact that this run once created a user. Three independent
				 * conditions, all checked against the database we are connected
				 * to right now: this run recorded the id, the stored login still
				 * matches that record, and the login still carries the fixture
				 * prefix. A user that fails any of them is left alone.
				 */
				if ( ! isset( $args['user_id'] ) || ! is_int( $args['user_id'] ) || $args['user_id'] < 1 ) {
					return $refuse( 'the user id is not an integer' );
				}
				$user_id = $args['user_id'];
				$login   = array_search( $user_id, $this->users, true );
				if ( ! is_string( $login ) ) {
					return $refuse( 'this run did not create that user' );
				}
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $user_id ) );
				if ( '' !== (string) $wpdb->last_error ) {
					return $refuse( 'the stored login could not be read' );
				}
				if ( null === $stored ) {
					return $refuse( 'the user no longer exists' );
				}
				if ( (string) $stored !== $login || 0 !== strpos( (string) $stored, 'fixture_' ) ) {
					return $refuse( 'the stored login does not match this run\'s fixture user' );
				}

				$meta_deleted = $wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user_id ], [ '%d' ] );
				$user_deleted = $wpdb->delete( $wpdb->users, [ 'ID' => $user_id ], [ '%d' ] );
				clean_user_cache( $user_id );

				// Both results are inspected: wpdb::delete() returns false on a
				// database error, and the user row must have gone.
				$result = ( false !== $meta_deleted && 1 === (int) $user_deleted ) ? 1 : false;
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
			false === $this->fixture_operation( 'drop_users_table', [], true ),
			'the guard refuses an operation outside the permitted set'
		);
		$this->assert_true(
			false === $this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => 1 ], true ),
			'the guard refuses a Hall-scoped operation aimed at the main site'
		);
		$this->assert_true(
			false === $this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => 99, 'value' => self::MARKER_A ], true ),
			'the guard refuses a Hall-scoped operation aimed at a foreign site'
		);
		$this->assert_true(
			false === $this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => 1 ], true ),
			'the guard refuses a marker value that is not a string'
		);
		$this->assert_true(
			$this->table_exists( $this->options_table( $this->hall_a ) ),
			'the refused operations left the Hall options table in place'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fixture setup
	 * ------------------------------------------------------------------ */

	private function prepare_hall_options(): void {
		foreach ( [ $this->hall_a, $this->hall_b ] as $hall ) {
			$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $hall ] );
			if ( ! $this->table_exists( $this->options_table( $hall ) ) ) {
				$this->abort( "the options table for Hall {$hall} could not be created." );
			}
			$this->owns_hall_options[ $hall ] = true;
		}
		$this->assert_true( true, 'both fixture Hall options tables were created by this run' );
	}

	private function create_test_users(): void {
		foreach (
			[
				'fixture_admin'         => 'fixture-admin@example.invalid',
				'fixture_hall_manager'  => 'fixture-hm@example.invalid',
				'fixture_game_operator' => 'fixture-go@example.invalid',
			] as $login => $email
		) {
			$user_id = wp_insert_user(
				[
					'user_login' => $login,
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 24, true, true ),
					'role'       => '',
				]
			);
			if ( is_wp_error( $user_id ) ) {
				$this->abort( "the fixture user {$login} could not be created." );
			}
			$this->users[ $login ] = (int) $user_id;
		}

		// Representative Hall-staff capability rows on Hall A. The gate never
		// reads them; that is exactly the point of asserting the refusal.
		update_user_meta( $this->users['fixture_hall_manager'], $this->hall_prefix( $this->hall_a ) . 'capabilities', [ 'bc_hall_manager' => true ] );
		update_user_meta( $this->users['fixture_game_operator'], $this->hall_prefix( $this->hall_a ) . 'capabilities', [ 'bc_game_operator' => true ] );

		$this->assert_true(
			in_array( 'fixture_admin', (array) get_super_admins(), true ),
			'the fixture network recognises fixture_admin as a Network Super Admin'
		);
		$this->assert_true(
			! is_super_admin( $this->users['fixture_hall_manager'] ) && ! is_super_admin( $this->users['fixture_game_operator'] ),
			'the Hall Manager and Game Operator are not Super Admins'
		);
	}

	/* ------------------------------------------------------------------ *
	 * 1. Shared access decision
	 * ------------------------------------------------------------------ */

	private function test_initial_deployment_without_schema(): void {
		$this->assert_true(
			! ProvisioningMigrations::is_current(),
			'initial deployment: no Commerce state schema is recorded'
		);
		$this->assert_true(
			! $this->table_exists( ProvisioningMigrations::state_table() ),
			'initial deployment: no Commerce state table exists'
		);

		foreach ( [ $this->hall_a, $this->hall_b ] as $hall ) {
			$this->assert_access(
				$hall,
				HallCommerceAccess::ALLOW,
				HallCommerceAccess::REASON_LEGACY,
				"initial deployment: Hall {$hall} keeps legacy access with no state table and no marker"
			);
		}

		// A managed marker with no state storage at all still blocks.
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => self::MARKER_A ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_STATE_UNAVAILABLE,
			'schema absent + valid marker blocks: schema-version absence alone is not allow'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => 'yes' ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_STATE_UNAVAILABLE,
			'schema absent + INVALID marker blocks too'
		);

		$this->assert_access(
			$this->hall_b,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'the marker on Hall A did not change Hall B'
		);

		// An unreadable marker with no usable state result blocks.
		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_UNDETERMINED,
			'schema absent + unreadable marker blocks as undetermined'
		);
		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'restoring the options table restores legacy access'
		);
	}

	private function test_marker_outcomes(): void {
		$hall = $this->hall_a;

		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
		$this->assert_same(
			HallCommerceAccess::MARKER_ABSENT,
			HallCommerceAccess::read_marker( $hall ),
			'marker read: a clean query with no row is a VERIFIED absence'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => self::MARKER_A ] );
		$this->assert_same(
			HallCommerceAccess::MARKER_VALID,
			HallCommerceAccess::read_marker( $hall ),
			'marker read: the recognised witness value is valid'
		);

		// A second, independently generated UUID v4 is equally valid: the
		// contract is the FORM, not one blessed value.
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => self::MARKER_B ] );
		$this->assert_same(
			HallCommerceAccess::MARKER_VALID,
			HallCommerceAccess::read_marker( $hall ),
			'marker read: any lowercase UUID v4 is a valid witness'
		);
		$this->assert_true(
			1 === preg_match( HallCommerceAccess::MARKER_PATTERN, wp_generate_uuid4() ),
			'marker read: the UUID WordPress generates for an operation satisfies the contract'
		);

		/*
		 * Every one of these is PRESENT AND INVALID, never a verified absence.
		 * The retired flag value and an uppercase UUID are called out by name
		 * because both are the kind of value that looks deliberate.
		 */
		$invalid = [
			''                                     => 'an empty string',
			'1'                                    => 'the retired flag value',
			'0'                                    => 'a zero',
			'yes'                                  => 'a word',
			'3F2A91C4-5D6B-4E7A-9C81-2B4D6E8F0A13' => 'an UPPERCASE UUID v4',
			'3f2a91c4-5d6b-4e7a-9c81-2B4D6E8F0A13' => 'a mixed-case UUID v4',
			'3f2a91c4-5d6b-1e7a-9c81-2b4d6e8f0a13' => 'a UUID v1',
			'3f2a91c4-5d6b-3e7a-9c81-2b4d6e8f0a13' => 'a UUID v3',
			'3f2a91c4-5d6b-5e7a-9c81-2b4d6e8f0a13' => 'a UUID v5',
			'00000000-0000-0000-0000-000000000000' => 'the nil UUID',
			'3f2a91c4-5d6b-4e7a-fc81-2b4d6e8f0a13' => 'a UUID with a wrong variant nibble',
			'3f2a91c45d6b4e7a9c812b4d6e8f0a13'     => 'an unhyphenated UUID',
			' 3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13' => 'a leading-space UUID',
			'3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13 ' => 'a trailing-space UUID',
			// PCRE's `$` also matches just before a final newline, so a pattern
			// anchored with `$` would have ACCEPTED these three.
			"3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13\n"   => 'a UUID with a trailing LF',
			"3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13\r\n" => 'a UUID with a trailing CRLF',
			"3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13\r"   => 'a UUID with a trailing CR',
			"\n3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13"   => 'a UUID with a leading LF',
			'3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a133' => 'an over-long UUID',
		];
		foreach ( $invalid as $value => $label ) {
			$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => (string) $value ] );
			$this->assert_same(
				HallCommerceAccess::MARKER_INVALID,
				HallCommerceAccess::read_marker( $hall ),
				"marker read: {$label} is present but INVALID, never absent"
			);
		}

		// Anchoring, stated as the property rather than as a list of cases.
		$this->assert_true(
			false === strpos( HallCommerceAccess::MARKER_PATTERN, '$' )
				&& false !== strpos( HallCommerceAccess::MARKER_PATTERN, '\\z' ),
			'the witness pattern terminates on \\z, not on $'
		);
		foreach ( [ "\n", "\r\n", "\r", ' ', "\t", "\0" ] as $suffix ) {
			$this->assert_true(
				! HallCommerceAccess::is_valid_marker( self::MARKER_A . $suffix ),
				'a valid UUID followed by ' . json_encode( $suffix ) . ' is rejected'
			);
		}

		// The predicate agrees with the stored-value outcomes above.
		$this->assert_true(
			HallCommerceAccess::is_valid_marker( self::MARKER_A )
				&& ! HallCommerceAccess::is_valid_marker( '1' )
				&& ! HallCommerceAccess::is_valid_marker( '' )
				&& ! HallCommerceAccess::is_valid_marker( strtoupper( self::MARKER_A ) )
				&& ! HallCommerceAccess::is_valid_marker( null )
				&& ! HallCommerceAccess::is_valid_marker( [ self::MARKER_A ] ),
			'the witness predicate accepts only a lowercase UUID v4 string'
		);

		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $hall ] );
		$this->assert_same(
			HallCommerceAccess::MARKER_UNREADABLE,
			HallCommerceAccess::read_marker( $hall ),
			'marker read: a missing options table is unreadable, not absent'
		);
		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $hall ] );

		foreach ( [ '2', 2, 2.0, true, null, 0, -1, [ 2 ] ] as $bad ) {
			if ( 2 === $bad ) {
				continue;
			}
			$this->assert_same(
				HallCommerceAccess::MARKER_UNREADABLE,
				HallCommerceAccess::read_marker( $bad ),
				'marker read: ' . $this->describe( $bad ) . ' is refused as an identifier'
			);
		}

		// A cached miss must not establish absence: prime WordPress's negative
		// option cache for the marker, then write it straight into storage.
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
		switch_to_blog( $hall );
		$cached_default = get_option( HallCommerceAccess::MARKER_OPTION, 'cache-miss' );
		restore_current_blog();
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => self::MARKER_A ] );
		switch_to_blog( $hall );
		$still_cached = get_option( HallCommerceAccess::MARKER_OPTION, 'cache-miss' );
		restore_current_blog();

		$this->assert_true( 'cache-miss' === $cached_default, 'the option cache was primed with a miss' );
		$this->assert_true( 'cache-miss' === $still_cached, 'get_option() still reports the stale cached miss' );
		$this->assert_same(
			HallCommerceAccess::MARKER_VALID,
			HallCommerceAccess::read_marker( $hall ),
			'the direct read sees the stored marker the option cache is still missing'
		);

		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
		wp_cache_flush();
	}

	private function test_states_with_schema(): void {
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the Commerce state schema migrates in the fixture' );
		$this->owns_state_table = true;
		$this->assert_true( ProvisioningMigrations::is_current(), 'the Commerce state schema is now current' );

		// No row, no marker.
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'schema present, no row, no marker: legacy access is preserved'
		);

		// No row, marker present.
		foreach ( [ self::MARKER_A => 'valid', '1' => 'invalid (the retired flag)' ] as $value => $label ) {
			$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => (string) $value ] );
			$this->assert_access(
				$this->hall_a,
				HallCommerceAccess::BLOCK,
				HallCommerceAccess::REASON_MANAGED_WITHOUT_STATE,
				"schema present, no row, {$label} marker: blocked"
			);
		}

		// No row, unreadable marker.
		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_UNDETERMINED,
			'schema present, no row, unreadable marker: blocked as undetermined'
		);
		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => self::MARKER_A ] );

		// Valid rows.
		$rows = [
			[ HallCommerceState::OPERATIONAL, HallCommerceState::PHASE_SETTLED, HallCommerceAccess::ALLOW, HallCommerceAccess::REASON_OPERATIONAL ],
			[ HallCommerceState::PROVISIONING, HallCommerceState::PHASE_TRANSITIONAL, HallCommerceAccess::BLOCK, HallCommerceAccess::REASON_PROVISIONING ],
			[ HallCommerceState::SUSPENDED, HallCommerceState::PHASE_SETTLED, HallCommerceAccess::BLOCK, HallCommerceAccess::REASON_SUSPENDED ],
		];
		$intent = 10;
		foreach ( $rows as [ $state, $phase, $access, $reason ] ) {
			$written = HallCommerceState::write( $this->hall_a, $state, $intent, $phase );
			$this->assert_true(
				is_array( $written ) && HallCommerceState::APPLIED === $written['outcome'],
				"a {$state} row was recorded for Hall A"
			);
			$verdict = HallCommerceAccess::evaluate( $this->hall_a );
			$this->assert_true(
				$access === $verdict['access'] && $reason === $verdict['reason'] && $state === $verdict['state'],
				"a valid {$state} row decides access: {$access}/{$reason}"
			);
			$intent++;
		}
	}

	private function test_invalid_stored_rows(): void {
		$hall = $this->hall_b;

		$malformed = [
			[ 'seq' => ( 7 << HallCommerceState::PHASE_BITS ) | HallCommerceState::PHASE_TRANSITIONAL, 'state' => HallCommerceState::OPERATIONAL, 'updated_at' => '2026-09-11 10:00:00', 'label' => 'state and phase disagree' ],
			[ 'seq' => ( 7 << HallCommerceState::PHASE_BITS ) | HallCommerceState::PHASE_SETTLED, 'state' => 'terminated', 'updated_at' => '2026-09-11 10:00:00', 'label' => 'an unrecognised stored state' ],
			[ 'seq' => ( 7 << HallCommerceState::PHASE_BITS ) | HallCommerceState::PHASE_SETTLED, 'state' => HallCommerceState::SUSPENDED, 'updated_at' => '0000-00-00 00:00:00', 'label' => 'a malformed stored timestamp' ],
		];

		foreach ( $malformed as $row ) {
			$this->fixture_operation(
				self::OP_INSERT_RAW_STATE,
				[ 'blog_id' => $hall, 'seq' => $row['seq'], 'state' => $row['state'], 'updated_at' => $row['updated_at'] ]
			);

			// Verified-absent marker: this is precisely the combination that
			// must NOT be classified as legacy.
			$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
			$this->assert_access(
				$hall,
				HallCommerceAccess::BLOCK,
				HallCommerceAccess::REASON_STATE_INVALID,
				"an invalid stored row ({$row['label']}) blocks and is never legacy"
			);

			// And with a marker present the answer is the same category.
			$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => self::MARKER_A ] );
			$this->assert_access(
				$hall,
				HallCommerceAccess::BLOCK,
				HallCommerceAccess::REASON_STATE_INVALID,
				"an invalid stored row ({$row['label']}) blocks with a marker present too"
			);
		}

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
	}

	private function test_unreadable_state_storage(): void {
		// Version still recorded, table gone: the read fails rather than
		// reporting a verified absence. The category is decided from the
		// STORAGE — the table is genuinely gone — and not from the version
		// option, which still claims the schema is installed.
		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->assert_true(
			ProvisioningMigrations::is_current(),
			'the schema version is still recorded after the table was removed'
		);
		$read = HallCommerceState::read( $this->hall_a );
		$this->assert_true(
			$read instanceof WP_Error && 'state_schema_unavailable' === $read->get_error_code(),
			'a missing table is reported as unavailable storage, not as an absence'
		);

		// A table that EXISTS but cannot be queried is a different category, and
		// is still not an absence.
		$this->fixture_operation( self::OP_DELETE_VERSION );
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the state table is restored for the unreadable-table case' );
		$break = static function ( $query ) {
			return 1 === preg_match( '/SELECT\s+blog_id,\s*seq/i', (string) $query )
				? 'SELECT blog_id FROM zz_bc_no_such_state_table'
				: $query;
		};
		add_filter( 'query', $break, 1 );
		try {
			$unreadable = HallCommerceState::read( $this->hall_a );
		} finally {
			remove_filter( 'query', $break, 1 );
		}
		$this->assert_true(
			$unreadable instanceof WP_Error && 'state_read_failed' === $unreadable->get_error_code(),
			'a present but unreadable table is reported as a read failure'
		);
		$this->fixture_operation( self::OP_DROP_STATE_TABLE );
		$this->fixture_operation( self::OP_DELETE_VERSION );

		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $this->hall_a ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'unreadable state storage + verified absent marker preserves legacy access'
		);

		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => self::MARKER_A ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_STATE_UNAVAILABLE,
			'unreadable state storage + managed marker blocks'
		);

		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_UNDETERMINED,
			'unreadable state storage + unreadable marker blocks'
		);
		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );

		// Restore the schema for the remaining tests.
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the state schema is rebuilt after the unreadable-storage test' );
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $this->hall_a ] );
	}

	/**
	 * An intact row decides access whatever the version metadata says.
	 *
	 * This is the regression the shared-reader correction exists for: while the
	 * recorded migration version gated reads, a genuinely SUSPENDED Hall whose
	 * version option was absent, unreadable or behind reported "schema
	 * unavailable", and a Hall with no marker was then served as legacy — open
	 * for business while its stored state said otherwise.
	 */
	private function test_state_authoritative_without_version(): void {
		$hall = $this->hall_a;

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );

		$version_states = [
			'absent'     => static function ( self $t ): void {
				$t->fixture_operation( self::OP_DELETE_VERSION );
			},
			'mismatched' => static function ( self $t ): void {
				update_site_option( ProvisioningMigrations::VERSION_OPTION, ProvisioningMigrations::DB_VERSION + 7 );
			},
			'behind'     => static function ( self $t ): void {
				update_site_option( ProvisioningMigrations::VERSION_OPTION, 0 );
			},
			'unreadable' => static function ( self $t ): void {
				// Left to the caller: simulated with a query filter, not a write.
				$t->fixture_operation( self::OP_DELETE_VERSION );
			},
		];

		$rows = [
			[ HallCommerceState::OPERATIONAL, HallCommerceState::PHASE_SETTLED, HallCommerceAccess::ALLOW, HallCommerceAccess::REASON_OPERATIONAL ],
			[ HallCommerceState::SUSPENDED, HallCommerceState::PHASE_SETTLED, HallCommerceAccess::BLOCK, HallCommerceAccess::REASON_SUSPENDED ],
			[ HallCommerceState::PROVISIONING, HallCommerceState::PHASE_TRANSITIONAL, HallCommerceAccess::BLOCK, HallCommerceAccess::REASON_PROVISIONING ],
		];

		$intent = 200;
		foreach ( $rows as [ $state, $phase, $access, $reason ] ) {
			foreach ( array_keys( $version_states ) as $label ) {
				// The row is written while the version is intact, because writes
				// keep their version gate on purpose.
				update_site_option( ProvisioningMigrations::VERSION_OPTION, ProvisioningMigrations::DB_VERSION );
				$written = HallCommerceState::write( $hall, $state, $intent, $phase );
				$this->assert_true(
					is_array( $written ) && in_array( $written['outcome'], [ HallCommerceState::APPLIED, HallCommerceState::ALREADY_APPLIED ], true ),
					"a {$state} row was recorded before breaking the version metadata"
				);
				$intent++;

				$version_states[ $label ]( $this );

				// The marker is VERIFIED ABSENT throughout: without the
				// correction this is exactly the combination that read as legacy.
				$this->assert_same(
					HallCommerceAccess::MARKER_ABSENT,
					HallCommerceAccess::read_marker( $hall ),
					"version {$label}: the witness is verified absent"
				);

				$filter = null;
				if ( 'unreadable' === $label ) {
					$filter = static function ( $query ) {
						return 1 === preg_match( '/FROM\s+\S*sitemeta/i', (string) $query )
							? 'SELECT 1 FROM zz_bc_no_such_sitemeta'
							: $query;
					};
					add_filter( 'query', $filter, 1 );
				}

				try {
					$read    = HallCommerceState::read( $hall );
					$verdict = HallCommerceAccess::evaluate( $hall );
				} finally {
					if ( null !== $filter ) {
						remove_filter( 'query', $filter, 1 );
					}
				}

				$this->assert_true(
					is_array( $read ) && $state === $read['state'],
					"version {$label}: the intact {$state} row is still readable"
				);
				$this->assert_true(
					$access === $verdict['access'] && $reason === $verdict['reason'],
					"version {$label}: a {$state} row decides {$access}/{$reason} with no marker"
					. ( $access === $verdict['access'] && $reason === $verdict['reason'] ? '' : " (got {$verdict['access']}/{$verdict['reason']})" )
				);
			}
		}

		// No migration or repair ran while all that was happening.
		update_site_option( ProvisioningMigrations::VERSION_OPTION, ProvisioningMigrations::DB_VERSION );
		$this->assert_true(
			$this->table_exists( ProvisioningMigrations::state_table() ),
			'the state table was never dropped or rebuilt by an access evaluation'
		);
		$this->assert_true(
			1 === $this->row_count( $hall ),
			'access evaluation neither created nor removed a state row'
		);

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
	}

	/**
	 * An identity failure must never reach the witness, let alone legacy access.
	 *
	 * HallCommerceState::read() reports `state_precondition_failed` when Hall
	 * identity validation THROWS — the site is missing, on another network, or
	 * simply could not be established. That is not "this Hall has no state"; it
	 * is "there is no proven Hall here". While that code was classified as
	 * unavailable STORAGE, the access layer consulted the witness for it, and a
	 * verified-absent witness then produced `allow / legacy`: an unidentifiable
	 * site served as an ordinary pre-Commerce Hall.
	 *
	 * The throw is injected through the installed `get_site` filter, which
	 * validate_identity() goes through, so the failure is the real code path
	 * rather than a simulated return value.
	 */
	private function test_identity_failure_never_becomes_legacy(): void {
		$hall = $this->hall_a;

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );

		// The options table is readable and the witness is genuinely absent:
		// precisely the combination that used to yield legacy access.
		$this->assert_same(
			HallCommerceAccess::MARKER_ABSENT,
			HallCommerceAccess::read_marker( $hall ),
			'identity regression: the witness is readable and verified absent'
		);
		$this->assert_access(
			$hall,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'identity regression: with identity intact this Hall IS legacy'
		);

		$throw = static function ( $site ) {
			throw new RuntimeException( 'forced identity failure' );
		};

		$scenarios = [
			'a verified absent witness' => null,
			'a valid witness'           => self::MARKER_A,
			'an invalid witness'        => 'not-a-uuid',
		];

		foreach ( $scenarios as $label => $marker ) {
			if ( null === $marker ) {
				$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $hall ] );
			} else {
				$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $hall, 'value' => $marker ] );
			}

			add_filter( 'get_site', $throw, 1 );
			try {
				$read    = HallCommerceState::read( $hall );
				$verdict = HallCommerceAccess::evaluate( $hall );
			} finally {
				remove_filter( 'get_site', $throw, 1 );
			}

			$this->assert_true(
				$read instanceof WP_Error && 'state_precondition_failed' === $read->get_error_code(),
				"identity regression ({$label}): the reader reports a precondition failure"
			);
			$this->assert_true(
				HallCommerceAccess::BLOCK === $verdict['access']
					&& HallCommerceAccess::REASON_UNDETERMINED === $verdict['reason'],
				"identity regression ({$label}): the Hall is BLOCKED as undetermined, never legacy"
					. ( HallCommerceAccess::BLOCK === $verdict['access'] ? '' : " (got {$verdict['access']}/{$verdict['reason']})" )
			);
			$this->assert_true(
				null === $verdict['marker'],
				"identity regression ({$label}): the witness was never consulted, so it could not change the answer"
			);
		}

		// And the same holds when the witness is UNREADABLE.
		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $hall ] );
		add_filter( 'get_site', $throw, 1 );
		try {
			$verdict = HallCommerceAccess::evaluate( $hall );
		} finally {
			remove_filter( 'get_site', $throw, 1 );
		}
		$this->assert_true(
			HallCommerceAccess::BLOCK === $verdict['access'] && null === $verdict['marker'],
			'identity regression (an unreadable witness): still blocked, still unconsulted'
		);
		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $hall ] );

		// The classification table itself must not list the identity code.
		$codes = ( new ReflectionClass( HallCommerceAccess::class ) )->getConstant( 'UNAVAILABLE_CODES' );
		$this->assert_true(
			is_array( $codes ) && ! in_array( 'state_precondition_failed', $codes, true ),
			'state_precondition_failed is not classified as unavailable state storage'
		);

		// Identity failures that do not throw are unchanged and still block.
		foreach ( [ 4242, 999999 ] as $missing ) {
			$this->assert_access(
				$missing,
				HallCommerceAccess::BLOCK,
				HallCommerceAccess::REASON_UNDETERMINED,
				"a site that does not exist ({$missing}) is blocked"
			);
		}

		// Recovery: the Hall is legacy again once identity resolves.
		$this->assert_access(
			$hall,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_LEGACY,
			'identity regression: an established legacy Hall is allowed again afterwards'
		);
	}

	private function test_identity_and_argument_handling(): void {
		$main = (int) get_main_site_id();
		$this->assert_access(
			$main,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_NOT_A_HALL,
			'the main network site is never a blocked Hall'
		);

		$this->assert_access(
			4242,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_UNDETERMINED,
			'a site that does not exist cannot be established and is blocked'
		);

		foreach ( [ '2', 2.0, 2.7, true, null, 0, -1, [ 2 ] ] as $bad ) {
			$verdict = HallCommerceAccess::evaluate( $bad );
			$this->assert_true(
				HallCommerceAccess::BLOCK === $verdict['access'] && HallCommerceAccess::REASON_UNDETERMINED === $verdict['reason'],
				'a non-integer Hall identifier ' . $this->describe( $bad ) . ' is blocked, never coerced'
			);
		}

		$this->assert_true(
			HallCommerceAccess::is_blocked( $this->hall_a ) === ( HallCommerceAccess::BLOCK === HallCommerceAccess::evaluate( $this->hall_a )['access'] ),
			'is_blocked() agrees with evaluate()'
		);
	}

	private function test_marker_only_consulted_when_needed(): void {
		// A valid row must decide alone. Proven by making any marker read
		// IMPOSSIBLE: if the policy consulted it, the answer would flip to
		// undetermined.
		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 40, HallCommerceState::PHASE_SETTLED );
		$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );

		$verdict = HallCommerceAccess::evaluate( $this->hall_a );
		$this->assert_true(
			HallCommerceAccess::ALLOW === $verdict['access']
				&& HallCommerceAccess::REASON_OPERATIONAL === $verdict['reason']
				&& null === $verdict['marker'],
			'an operational row allows without ever reading the marker'
		);

		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 41, HallCommerceState::PHASE_SETTLED );
		$verdict = HallCommerceAccess::evaluate( $this->hall_a );
		$this->assert_true(
			HallCommerceAccess::BLOCK === $verdict['access']
				&& HallCommerceAccess::REASON_SUSPENDED === $verdict['reason']
				&& null === $verdict['marker'],
			'a suspended row blocks without ever reading the marker'
		);

		$this->fixture_operation( self::OP_CREATE_HALL_OPTIONS, [ 'blog_id' => $this->hall_a ] );
	}

	private function test_hall_isolation_and_switched_context(): void {
		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 50, HallCommerceState::PHASE_SETTLED );
		HallCommerceState::write( $this->hall_b, HallCommerceState::OPERATIONAL, 50, HallCommerceState::PHASE_SETTLED );

		$expected = [
			$this->hall_a => [ HallCommerceAccess::BLOCK, HallCommerceAccess::REASON_SUSPENDED ],
			$this->hall_b => [ HallCommerceAccess::ALLOW, HallCommerceAccess::REASON_OPERATIONAL ],
		];

		foreach ( [ null, 1, $this->hall_a, $this->hall_b ] as $context ) {
			$label = null === $context ? 'no switch' : "switched to site {$context}";
			if ( null !== $context ) {
				switch_to_blog( $context );
			}
			foreach ( $expected as $hall => [ $access, $reason ] ) {
				$verdict = HallCommerceAccess::evaluate( $hall );
				$this->assert_true(
					$access === $verdict['access'] && $reason === $verdict['reason'],
					"Hall {$hall} evaluates to {$access}/{$reason} with {$label}"
				);
			}
			if ( null !== $context ) {
				restore_current_blog();
			}
		}

		// Markers are Hall-scoped too.
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => self::MARKER_A ] );
		$this->assert_same(
			HallCommerceAccess::MARKER_VALID,
			HallCommerceAccess::read_marker( $this->hall_a ),
			'Hall A carries the marker'
		);
		$this->assert_same(
			HallCommerceAccess::MARKER_ABSENT,
			HallCommerceAccess::read_marker( $this->hall_b ),
			'Hall B does not see Hall A\'s marker'
		);
		switch_to_blog( $this->hall_a );
		$this->assert_same(
			HallCommerceAccess::MARKER_ABSENT,
			HallCommerceAccess::read_marker( $this->hall_b ),
			'the marker read follows its argument, not the switched site context'
		);
		restore_current_blog();
	}

	private function test_no_cached_allow(): void {
		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 60, HallCommerceState::PHASE_SETTLED );
		$this->assert_access( $this->hall_a, HallCommerceAccess::ALLOW, HallCommerceAccess::REASON_OPERATIONAL, 'the Hall is allowed while operational' );

		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 61, HallCommerceState::PHASE_SETTLED );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::BLOCK,
			HallCommerceAccess::REASON_SUSPENDED,
			'the very next evaluation blocks: no allow decision is cached across a suspension'
		);

		HallCommerceState::write( $this->hall_a, HallCommerceState::OPERATIONAL, 62, HallCommerceState::PHASE_SETTLED );
		$this->assert_access(
			$this->hall_a,
			HallCommerceAccess::ALLOW,
			HallCommerceAccess::REASON_OPERATIONAL,
			'and lifting the suspension takes effect immediately as well'
		);
	}

	private function test_evaluation_writes_nothing(): void {
		$snapshot = $this->fingerprint();

		for ( $i = 0; $i < 5; $i++ ) {
			HallCommerceAccess::evaluate( $this->hall_a );
			HallCommerceAccess::evaluate( $this->hall_b );
			HallCommerceAccess::read_marker( $this->hall_a );
			HallCommerceGate::decide( $this->request( '/', [], [], [] ) );
		}

		$this->assert_fingerprints_equal( $snapshot, $this->fingerprint(), 'repeated evaluation' );
		$this->assert_true( 0 === $this->http_attempts, 'access evaluation makes no outbound request' );
	}

	/* ------------------------------------------------------------------ *
	 * 2. Request enforcement
	 * ------------------------------------------------------------------ */

	/**
	 * Build a real snapshot for a fabricated request.
	 *
	 * The superglobals and $pagenow are set, HallCommerceGate::snapshot() runs
	 * against them, and everything is restored — so snapshot()'s own parsing is
	 * exercised rather than hand-written. DOING_CRON and DOING_AJAX are
	 * constants and cannot be toggled per case, so those two fields are
	 * overridden afterwards.
	 *
	 * @param array<string,mixed> $get
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function request( string $uri, array $get = [], array $post = [], array $overrides = [] ): array {
		$saved = [ $_GET, $_POST, $_SERVER['REQUEST_URI'] ?? null, $_SERVER['SCRIPT_NAME'] ?? null, $GLOBALS['pagenow'] ?? null ];

		$_GET                   = $get;
		$_POST                  = $post;
		$_SERVER['REQUEST_URI'] = $uri;
		$path                   = explode( '?', $uri, 2 )[0];
		$_SERVER['SCRIPT_NAME'] = $path;
		$GLOBALS['pagenow']     = $overrides['pagenow'] ?? $this->pagenow_for( $path );

		$snapshot = HallCommerceGate::snapshot();

		/*
		 * WP_ADMIN is a constant, so is_admin() cannot vary per case inside one
		 * process. It is derived from the path here, exactly as the real entry
		 * points define it: everything under /wp-admin/ (including admin-ajax.php
		 * and admin-post.php) sets WP_ADMIN before wp-load.php. The worker-driven
		 * tests define the real constant in their own process instead.
		 */
		$snapshot['admin'] = 0 === strpos( $path, '/wp-admin' );

		[ $_GET, $_POST, $uri_saved, $script_saved, $pagenow_saved ] = $saved;
		foreach ( [ 'REQUEST_URI' => $uri_saved, 'SCRIPT_NAME' => $script_saved ] as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		if ( null === $pagenow_saved ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $pagenow_saved;
		}

		// Default: the resolver must never be reached. Cases that expect it
		// supply their own.
		$snapshot['super_admin'] = static function (): bool {
			return false;
		};

		foreach ( $overrides as $key => $value ) {
			if ( 'pagenow' === $key ) {
				$snapshot['pagenow'] = (string) $value;
				continue;
			}
			$snapshot[ $key ] = $value;
		}

		return $snapshot;
	}

	/**
	 * $pagenow exactly as wp-includes/vars.php derives it.
	 *
	 * The non-admin branch matches a trailing `<name>.php`, and anything else —
	 * including `/wp-admin/` itself, which the admin branch maps the same way —
	 * becomes the front controller.
	 */
	/**
	 * php-cgi beside the running PHP binary, or on PATH.
	 *
	 * Its presence is what allows enforcement to be exercised under a real
	 * non-CLI SAPI. Absence is reported rather than silently tolerated.
	 */
	private function locate_php_cgi(): string {
		$candidates = [ dirname( PHP_BINARY ) . '/php-cgi' ];
		$found      = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
		if ( '' !== $found ) {
			$candidates[] = $found;
		}

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private function pagenow_for( string $path ): string {
		return 1 === preg_match( '#([^/]+\.php)([?/].*?)?$#i', $path, $matches )
			? strtolower( $matches[1] )
			: 'index.php';
	}

	/** @param array<string,mixed> $request */
	private function assert_decision( array $request, string $action, string $reason, string $label ): void {
		$decision = HallCommerceGate::decide( $request );
		$this->assert_true(
			$action === $decision['action'] && $reason === $decision['reason'],
			"{$label} => {$action}/{$reason}" . ( $action === $decision['action'] && $reason === $decision['reason'] ? '' : " (got {$decision['action']}/{$decision['reason']})" )
		);
	}

	private function test_gate_refuses_ordinary_requests(): void {
		$cases = [
			'storefront home'            => [ '/', [], [] ],
			'a product page'             => [ '/product/bingo-paper-pack/', [], [] ],
			'the cart'                   => [ '/cart/', [], [] ],
			'the checkout'               => [ '/checkout/', [], [] ],
			'order-pay'                  => [ '/checkout/order-pay/1234/?key=wc_order_abc', [ 'key' => 'wc_order_abc' ], [] ],
			'order-received'             => [ '/checkout/order-received/1234/?key=wc_order_abc', [ 'key' => 'wc_order_abc' ], [] ],
			'my account'                 => [ '/my-account/', [], [] ],
			'account orders'             => [ '/my-account/orders/', [], [] ],
			'delete-payment-method'      => [ '/my-account/delete-payment-method/7/', [], [] ],
			'the Store API cart'         => [ '/wp-json/wc/store/v1/cart', [], [] ],
			'a Store API mutation'       => [ '/wp-json/wc/store/v1/cart/add-item', [], [ 'id' => 5 ] ],
			'the WordPress REST API'     => [ '/wp-json/wp/v2/posts', [], [] ],
			'the legacy REST namespace'  => [ '/wp-json/wc/v3/orders', [], [] ],
			'wp-admin'                   => [ '/wp-admin/', [], [] ],
			'a WooCommerce admin screen' => [ '/wp-admin/admin.php?page=wc-orders', [ 'page' => 'wc-orders' ], [] ],
			'the Portal Sync screen'     => [ '/wp-admin/admin.php?page=bc-portal-sync', [ 'page' => 'bc-portal-sync' ], [] ],
			'wp-signup'                  => [ '/wp-signup.php', [], [] ],
			'xmlrpc'                     => [ '/xmlrpc.php', [], [] ],
			'a feed'                     => [ '/feed/', [], [] ],
		];

		foreach ( $cases as $label => [ $uri, $get, $post ] ) {
			$this->assert_decision(
				$this->request( $uri, $get, $post ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"a blocked Hall refuses {$label}"
			);
		}

		// Direct purchase and form paths, not merely rendered pages.
		$direct = [
			'an add-to-cart link'      => [ '/?add-to-cart=5', [ 'add-to-cart' => '5' ], [] ],
			'an add-to-cart POST'      => [ '/product/x/', [], [ 'add-to-cart' => '5', 'quantity' => '2' ] ],
			'a cart update POST'       => [ '/cart/', [], [ 'update_cart' => 'Update cart' ] ],
			'a coupon POST'            => [ '/cart/', [], [ 'apply_coupon' => '1', 'coupon_code' => 'X' ] ],
			'a place-order POST'       => [ '/checkout/', [], [ 'woocommerce_checkout_place_order' => '1' ] ],
			'an order-pay POST'        => [ '/checkout/order-pay/1/', [ 'key' => 'k' ], [ 'woocommerce_pay' => '1' ] ],
			'a cancel-order link'      => [ '/?cancel_order=true', [ 'cancel_order' => 'true', 'order' => 'k', 'order_id' => '1' ], [] ],
			'a WooCommerce AJAX call'  => [ '/?wc-ajax=add_to_cart', [ 'wc-ajax' => 'add_to_cart' ], [] ],
			'a customer login POST'    => [ '/my-account/', [], [ 'login' => 'Log in', 'username' => 'u', 'password' => 'p' ] ],
			'a registration POST'      => [ '/my-account/', [], [ 'register' => 'Register', 'email' => 'a@b.invalid' ] ],
			'an account details POST'  => [ '/my-account/edit-account/', [], [ 'save-account-details-nonce' => 'n' ] ],
			'an admin-post submission' => [ '/wp-admin/admin-post.php', [], [ 'action' => 'bc_portal_sync' ] ],
			'an admin AJAX call'       => [ '/wp-admin/admin-ajax.php', [], [ 'action' => 'woocommerce_add_to_cart' ] ],
		];

		foreach ( $direct as $label => [ $uri, $get, $post ] ) {
			$decision = HallCommerceGate::decide( $this->request( $uri, $get, $post ) );
			$this->assert_true(
				HallCommerceGate::REFUSE === $decision['action'],
				"a blocked Hall refuses {$label}"
			);
		}

		// admin-ajax without the AJAX constant is still refused.
		$this->assert_decision(
			$this->request( '/wp-admin/admin-ajax.php', [ 'action' => HallCommerceGate::SCHEDULER_ACTION ], [] ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'the scheduler identifier outside an AJAX request'
		);
	}

	private function test_gate_authentication_path(): void {
		foreach ( [ '', 'login', 'logout', 'LOGIN' ] as $action ) {
			$get = '' === $action ? [] : [ 'action' => $action ];
			$this->assert_decision(
				$this->request( '/wp-login.php', $get, [] ),
				HallCommerceGate::EXEMPT,
				HallCommerceGate::REASON_AUTHENTICATION,
				"wp-login.php action='{$action}' stays reachable for recovery"
			);
		}

		// The login POST carries its action in $_POST.
		$this->assert_decision(
			$this->request( '/wp-login.php', [], [ 'log' => 'fixture_admin', 'pwd' => 'x' ] ),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_AUTHENTICATION,
			'the wp-login.php credential POST stays reachable'
		);

		foreach ( [ 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register', 'postpass', 'confirmaction' ] as $action ) {
			$this->assert_decision(
				$this->request( '/wp-login.php', [ 'action' => $action ], [] ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"wp-login.php action='{$action}' is refused as an account flow"
			);
		}

		// POST precedence matches wp-login.php's own $_REQUEST read.
		$this->assert_decision(
			$this->request( '/wp-login.php', [ 'action' => 'login' ], [ 'action' => 'lostpassword' ] ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'a POSTed action overrides the query string, as wp-login.php reads it'
		);

		$this->assert_decision(
			$this->request( '/wp-login.php?add-to-cart=5', [ 'add-to-cart' => '5' ], [] ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_MUTATION,
			'the authentication path carrying a shopping parameter'
		);
	}

	private function test_gate_super_admin_recovery(): void {
		$called  = 0;
		$request = $this->request(
			'/wp-admin/',
			[],
			[],
			[
				'super_admin' => function () use ( &$called ): bool {
					$called++;

					return true;
				},
			]
		);
		$this->assert_decision( $request, HallCommerceGate::RECOVERY, HallCommerceGate::REASON_SUPER_ADMIN, 'a Network Super Admin keeps recovery access' );
		$this->assert_true( 1 === $called, 'the Super Admin resolver was consulted exactly once' );

		// And it is NOT consulted on exempt or authentication paths.
		$called  = 0;
		$resolver = function () use ( &$called ): bool {
			$called++;

			return true;
		};
		HallCommerceGate::decide( $this->request( '/wp-login.php', [], [], [ 'super_admin' => $resolver ] ) );
		HallCommerceGate::decide( $this->request( '/wp-cron.php', [], [], [ 'doing_cron' => true, 'super_admin' => $resolver ] ) );
		$this->assert_true( 0 === $called, 'the current user is never resolved on an exempt or authentication path' );
	}

	private function test_gate_staff_sessions_do_not_bypass(): void {
		$real_resolver = static function (): bool {
			return function_exists( 'is_super_admin' ) && is_super_admin();
		};

		$cases = [
			'an anonymous visitor' => 0,
			'a Hall Manager'       => $this->users['fixture_hall_manager'],
			'a Game Operator'      => $this->users['fixture_game_operator'],
		];

		foreach ( $cases as $label => $user_id ) {
			wp_set_current_user( $user_id );
			$this->assert_decision(
				$this->request( '/wp-admin/', [], [], [ 'super_admin' => $real_resolver ] ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"an existing authenticated session for {$label} does not bypass the block"
			);
			$this->assert_decision(
				$this->request( '/checkout/', [], [], [ 'super_admin' => $real_resolver ] ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"{$label} is refused at the checkout"
			);
		}

		wp_set_current_user( $this->users['fixture_admin'] );
		$this->assert_true( is_super_admin(), 'the fixture Super Admin session is recognised' );
		$this->assert_decision(
			$this->request( '/wp-admin/', [], [], [ 'super_admin' => $real_resolver ] ),
			HallCommerceGate::RECOVERY,
			HallCommerceGate::REASON_SUPER_ADMIN,
			'a real Super Admin session resolves to recovery access'
		);

		wp_set_current_user( 0 );
	}

	private function test_gate_background_exemptions(): void {
		$this->assert_decision(
			$this->request( '/wp-cron.php?doing_wp_cron=1', [ 'doing_wp_cron' => '1' ], [], [ 'doing_cron' => true ] ),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_CRON,
			'WP-Cron stays reachable'
		);

		wp_set_current_user( 0 );
		$runner_nonce = wp_create_nonce( HallCommerceGate::SCHEDULER_ACTION );
		$this->assert_decision(
			$this->request(
				'/wp-admin/admin-ajax.php?action=' . HallCommerceGate::SCHEDULER_ACTION . '&nonce=' . $runner_nonce,
				[ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => $runner_nonce ],
				[],
				[ 'doing_ajax' => true ]
			),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_SCHEDULER,
			'the authenticated Action Scheduler queue runner stays reachable'
		);
		$this->assert_decision(
			$this->request(
				'/wp-admin/admin-ajax.php?action=' . HallCommerceGate::SCHEDULER_ACTION . '&nonce=abc',
				[ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => 'abc' ],
				[],
				[ 'doing_ajax' => true ]
			),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'the same identifier with a forged nonce is refused'
		);

		foreach ( [ 'as_async_request_queue_runner_x', 'wp_async_request', 'heartbeat', '', 'woocommerce_get_refreshed_fragments' ] as $action ) {
			$this->assert_decision(
				$this->request( '/wp-admin/admin-ajax.php', [ 'action' => $action, 'nonce' => $runner_nonce ], [], [ 'doing_ajax' => true ] ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"an AJAX action '{$action}' receives no runner exemption even with a valid runner nonce"
			);
		}

		// The identifier matches the installed Action Scheduler source.
		$runner = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/packages/action-scheduler/classes/ActionScheduler_AsyncRequest_QueueRunner.php';
		$source = is_readable( $runner ) ? (string) file_get_contents( $runner ) : '';
		$prefix = preg_match( "/\\\$prefix\s*=\s*'([^']+)'/", $source, $m ) ? $m[1] : '';
		$action = preg_match( "/\\\$action\s*=\s*'([^']+)'/", $source, $m ) ? $m[1] : '';
		$this->assert_true(
			'' !== $prefix && '' !== $action && HallCommerceGate::SCHEDULER_ACTION === $prefix . '_' . $action,
			'the exempted runner identifier matches the installed Action Scheduler source'
		);
	}

	private function test_gate_payment_callbacks(): void {
		$ipn = [
			'txn_id'         => '9BW12345',
			'payment_status' => 'Completed',
			'mc_gross'       => '25.00',
			'custom'         => '{"order_id":1234}',
			'quantity'       => '1',
			'item_number'    => '5',
			'receiver_email' => 'hall@example.invalid',
			'charset'        => 'UTF-8',
		];
		$wc = [ 'woocommerce' => true, 'permalinks' => true ];

		$forms = [
			'the query-string form'        => [ '/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ] ],
			'the lowercase form'           => [ '/?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ] ],
			'the permalink form'           => [ '/wc-api/WC_Gateway_Paypal/', [] ],
			'the permalink form unslashed' => [ '/wc-api/WC_Gateway_Paypal', [] ],
			'the index.php permalink form' => [ '/index.php/wc-api/WC_Gateway_Paypal/', [] ],
			'the percent-encoded form'     => [ '/wc-api/WC%5FGateway%5FPaypal/', [] ],
		];

		foreach ( $forms as $label => [ $uri, $get ] ) {
			$this->assert_decision(
				$this->request( $uri, $get, $ipn, $wc ),
				HallCommerceGate::EXEMPT,
				HallCommerceGate::REASON_PAYMENT,
				"a verified payment notification via {$label} is admitted with its own POST body"
			);
		}

		$this->assert_true(
			true,
			'an ordinary IPN body (txn_id, payment_status, quantity, custom) is not mistaken for a shopping mutation'
		);

		/*
		 * THE EXEMPTION IS BOUND TO DISPATCH. `parse_request` runs only inside
		 * WP::main(), which only the front controller calls, so none of these
		 * would ever reach the PayPal handler however they are decorated.
		 */
		$never_dispatches = [
			'the admin dashboard'          => [ '/wp-admin/?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'an admin screen'              => [ '/wp-admin/admin.php?page=wc-orders&wc-api=wc_gateway_paypal', [ 'page' => 'wc-orders', 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'admin-post.php'               => [ '/wp-admin/admin-post.php?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [ 'action' => 'bc_portal_sync' ], [] ],
			'an unrelated admin-ajax call' => [ '/wp-admin/admin-ajax.php?wc-api=wc_gateway_paypal', [ 'action' => 'heartbeat', 'wc-api' => 'wc_gateway_paypal' ], [], [ 'doing_ajax' => true ] ],
			'wp-login.php registration'    => [ '/wp-login.php?action=register&wc-api=wc_gateway_paypal', [ 'action' => 'register', 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'wp-login.php lost password'   => [ '/wp-login.php?action=lostpassword&wc-api=wc_gateway_paypal', [ 'action' => 'lostpassword', 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'wp-login.php password reset'  => [ '/wp-login.php?action=rp&wc-api=wc_gateway_paypal', [ 'action' => 'rp', 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'wp-signup.php'                => [ '/wp-signup.php?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'xmlrpc.php'                   => [ '/xmlrpc.php?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [ 'xmlrpc' => true ] ],
			'wp-cron.php with a POST body' => [ '/wp-cron.php?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'a REST route parameter'       => [ '/?wc-api=wc_gateway_paypal&rest_route=/wp/v2/posts', [ 'wc-api' => 'wc_gateway_paypal', 'rest_route' => '/wp/v2/posts' ], [], [] ],
			'a REST path'                  => [ '/wp-json/wc/store/v1/cart?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
			'a REST path via index.php'    => [ '/index.php/wp-json/wp/v2/posts?wc-api=wc_gateway_paypal', [ 'wc-api' => 'wc_gateway_paypal' ], [], [] ],
		];

		foreach ( $never_dispatches as $label => [ $uri, $get, $post, $extra ] ) {
			$decision = HallCommerceGate::decide( $this->request( $uri, $get, $post, $wc + $extra ) );
			$this->assert_true(
				HallCommerceGate::REFUSE === $decision['action'],
				"{$label} carrying an approved wc-api identifier is still refused"
					. ( HallCommerceGate::REFUSE === $decision['action'] ? '' : " (got {$decision['action']}/{$decision['reason']})" )
			);
		}

		// Conflicting query and path identifiers are refused rather than resolved.
		$this->assert_decision(
			$this->request( '/wc-api/WC_Gateway_Stripe/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ], [], $wc ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'a query identifier conflicting with the path identifier'
		);
		$this->assert_decision(
			$this->request( '/wc-api/WC_Gateway_Paypal/?wc-api=WC_Gateway_Stripe', [ 'wc-api' => 'WC_Gateway_Stripe' ], [], $wc ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'an unapproved query identifier over an approved path identifier'
		);
		$this->assert_decision(
			$this->request( '/wc-api/WC_Gateway_Paypal/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ], $ipn, $wc ),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_PAYMENT,
			'both forms naming the same approved callback'
		);

		// Extra path segments name a hook nothing listens on.
		$this->assert_decision(
			$this->request( '/wc-api/WC_Gateway_Paypal/extra/', [], [], $wc ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'a path with segments past the callback name'
		);

		// The path form cannot route without a permalink structure.
		$this->assert_decision(
			$this->request( '/wc-api/WC_Gateway_Paypal/', [], [], [ 'woocommerce' => true, 'permalinks' => false ] ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'the path form with no permalink structure behind it'
		);
		$this->assert_decision(
			$this->request( '/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ], $ipn, [ 'woocommerce' => true, 'permalinks' => false ] ),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_PAYMENT,
			'the query form still routes with plain permalinks'
		);

		foreach (
			[
				'WC_Gateway_Stripe',
				'wc_gateway_stripe',
				'WC_Gateway_Paypal_Express',
				'wc_gateway_paypa',
				'',
				'0',
			] as $identifier
		) {
			$this->assert_decision(
				$this->request( '/?wc-api=' . rawurlencode( $identifier ), [ 'wc-api' => $identifier ], [], $wc ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"an unknown callback identifier '{$identifier}' receives no exemption"
			);
		}

		$this->assert_decision(
			$this->request( '/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ], $ipn, [ 'woocommerce' => false, 'permalinks' => true ] ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'no callback is exempt when WooCommerce is not installed to dispatch it'
		);

		$this->assert_decision(
			$this->request( '/', [ 'wc-api' => [ 'WC_Gateway_Paypal' ] ], [], $wc ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'an array-valued wc-api parameter receives no exemption'
		);

		/*
		 * A notification carries a body. The installed handler opens with
		 * `! empty( $_POST )` and otherwise wp_die()s 500, so a bodyless request
		 * cannot be one and is refused rather than admitted to a certain failure.
		 */
		foreach (
			[
				'the query form'     => [ '/?wc-api=WC_Gateway_Paypal', [ 'wc-api' => 'WC_Gateway_Paypal' ] ],
				'the permalink form' => [ '/wc-api/WC_Gateway_Paypal/', [] ],
			] as $label => [ $uri, $get ]
		) {
			$this->assert_decision(
				$this->request( $uri, $get, [], $wc ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"a payment callback with no POST body via {$label} is refused"
			);
		}
		$handler = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-ipn-handler.php';
		$this->assert_true(
			false !== strpos( (string) file_get_contents( $handler ), 'if ( ! empty( $_POST ) && $this->validate_ipn() )' ),
			'  and that mirrors the installed handler\'s own first condition'
		);

		// A POSTed wc-api routes nothing: WooCommerce reads only $_GET.
		$this->assert_decision(
			$this->request( '/', [], [ 'wc-api' => 'WC_Gateway_Paypal' ], $wc ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'a POSTed wc-api parameter receives no exemption'
		);

		// The admitted identifier is the one the installed gateway registers.
		$handler = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-ipn-handler.php';
		$source  = is_readable( $handler ) ? (string) file_get_contents( $handler ) : '';
		$this->assert_true(
			false !== strpos( $source, "add_action( 'woocommerce_api_wc_gateway_paypal'" ),
			'the installed PayPal IPN handler registers woocommerce_api_wc_gateway_paypal'
		);
		$this->assert_true(
			[ 'wc_gateway_paypal' ] === HallCommerceGate::PAYMENT_CALLBACKS,
			'the payment allowlist contains exactly that one verified identifier'
		);

		// Its own validation is untouched: the handler still posts the payload
		// back to PayPal before it does anything with an order.
		$this->assert_true(
			false !== strpos( $source, 'function validate_ipn' ) && false !== strpos( $source, 'wp_safe_remote_post' ),
			'the installed IPN handler still validates the notification with PayPal itself'
		);

		// Installed but disabled for new checkout: the handler is still
		// registered, which is what keeps pending notifications working.
		$gateway = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/gateways/paypal/class-wc-gateway-paypal.php';
		$source  = is_readable( $gateway ) ? (string) file_get_contents( $gateway ) : '';
		$this->assert_true(
			1 === preg_match( '/is_valid_for_use\(\)\s*\)\s*\{\s*\$this->enabled\s*=\s*\'no\';\s*\}\s*else\s*\{\s*include_once[^}]*WC_Gateway_Paypal_IPN_Handler/s', $source ),
			'the installed gateway registers its IPN handler independently of the enabled setting'
		);

		// And the dispatch really does hang off parse_request, inside WP::main().
		$stub = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/src/Internal/Utilities/LegacyRestApiStub.php';
		$this->assert_true(
			is_readable( $stub )
				&& 1 === preg_match( "/add_action\(\s*'parse_request'\s*,\s*array\(\s*__CLASS__\s*,\s*'parse_legacy_rest_api_request'\s*\)\s*,\s*0\s*\)/", (string) file_get_contents( $stub ) ),
			'the wc-api dispatch is registered on parse_request, which only WP::main() reaches'
		);
		$wp_class = (string) file_get_contents( dirname( __DIR__ ) . '/wp-includes/class-wp.php' );
		$this->assert_true(
			false !== strpos( $wp_class, "do_action_ref_array( 'parse_request', array( &\$this ) );" )
				&& false !== strpos( $wp_class, '$parsed = $this->parse_request( $query_args );' ),
			'parse_request is fired inside WP::parse_request(), reached only through WP::main()'
		);
	}

	/**
	 * The Action Scheduler exemption must be authenticated, not merely named.
	 */
	private function test_scheduler_nonce(): void {
		$ajax  = [ 'doing_ajax' => true ];
		$runner = '/wp-admin/admin-ajax.php';

		// A real nonce, minted in the current user context, is what the runner's
		// own check_ajax_referer() would accept.
		wp_set_current_user( 0 );
		$valid = wp_create_nonce( HallCommerceGate::SCHEDULER_ACTION );

		$this->assert_decision(
			$this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => $valid ], [], $ajax ),
			HallCommerceGate::EXEMPT,
			HallCommerceGate::REASON_SCHEDULER,
			'the queue runner with a valid nonce'
		);

		$rejected = [
			'no nonce at all'          => [],
			'an empty nonce'           => [ 'nonce' => '' ],
			'an invalid nonce'         => [ 'nonce' => 'not-a-nonce' ],
			'a truncated valid nonce'  => [ 'nonce' => substr( $valid, 0, -1 ) ],
			'a nonce for a wrong action' => [ 'nonce' => wp_create_nonce( 'as_async_request_queue_runner_other' ) ],
			'a nonce for a WooCommerce action' => [ 'nonce' => wp_create_nonce( 'woocommerce-cart' ) ],
		];
		foreach ( $rejected as $label => $extra ) {
			$this->assert_decision(
				$this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION ] + $extra, [], $ajax ),
				HallCommerceGate::REFUSE,
				HallCommerceGate::REASON_BLOCKED,
				"the queue runner with {$label} is refused"
			);
		}

		// check_ajax_referer()'s own fallback parameters are honoured.
		foreach ( [ '_ajax_nonce', '_wpnonce' ] as $param ) {
			$this->assert_decision(
				$this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION, $param => $valid ], [], $ajax ),
				HallCommerceGate::EXEMPT,
				HallCommerceGate::REASON_SCHEDULER,
				"the queue runner authenticating through {$param}"
			);
		}

		// A valid nonce does not license shopping.
		$this->assert_decision(
			$this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => $valid, 'add-to-cart' => '5' ], [], $ajax ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_MUTATION,
			'the queue runner with a valid nonce and a shopping parameter'
		);

		// A nonce minted for another user does not verify as this one.
		wp_set_current_user( $this->users['fixture_hall_manager'] );
		$other_user_nonce = wp_create_nonce( HallCommerceGate::SCHEDULER_ACTION );
		wp_set_current_user( 0 );
		$this->assert_true(
			$other_user_nonce !== $valid,
			'a nonce is user-bound, so another session mints a different one'
		);
		$this->assert_decision(
			$this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => $other_user_nonce ], [], $ajax ),
			HallCommerceGate::REFUSE,
			HallCommerceGate::REASON_BLOCKED,
			'the queue runner presenting another session\'s nonce'
		);

		// The verifier the snapshot installs is wp_verify_nonce itself.
		$snapshot = $this->request( $runner, [ 'action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce' => $valid ], [], $ajax );
		$this->assert_true(
			is_callable( $snapshot['nonce_verifier'] )
				&& true === $snapshot['nonce_verifier']( $valid, HallCommerceGate::SCHEDULER_ACTION )
				&& false === $snapshot['nonce_verifier']( 'not-a-nonce', HallCommerceGate::SCHEDULER_ACTION ),
			'the snapshot installs a verifier that agrees with wp_verify_nonce()'
		);

		// The handler the exemption defers to still authenticates itself.
		$async = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/packages/action-scheduler/lib/WP_Async_Request.php';
		$source = is_readable( $async ) ? (string) file_get_contents( $async ) : '';
		$this->assert_true(
			false !== strpos( $source, "check_ajax_referer( \$this->identifier, 'nonce' );" ),
			'the installed runner verifies the same action with the same parameter name'
		);
		$this->assert_true(
			false !== strpos( $source, "'cookies'" ) && false !== strpos( $source, '$_COOKIE' ),
			'the installed runner forwards cookies, so the gate sees the same user context'
		);
		$runner = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/packages/action-scheduler/classes/ActionScheduler_AsyncRequest_QueueRunner.php';
		$this->assert_true(
			is_readable( $runner )
				&& false !== strpos( (string) file_get_contents( $runner ), "\$prefix = 'as';" ),
			'the runner identifier this gate authenticates is the installed one'
		);
		$this->assert_true(
			false === has_action( 'wp_ajax_' . HallCommerceGate::SCHEDULER_ACTION, [ HallCommerceGate::class, 'detach_shopping_handlers' ] ),
			'the gate attaches nothing to the runner\'s own handler'
		);
	}

	private function test_gate_mixed_shopping_requests(): void {
		$reflection = new ReflectionClass( HallCommerceGate::class );
		$params     = (array) $reflection->getConstant( 'MUTATION_PARAMS' );
		$this->assert_true( count( $params ) >= 20, 'the mutation-parameter list is populated' );

		wp_set_current_user( 0 );
		$runner_nonce = wp_create_nonce( HallCommerceGate::SCHEDULER_ACTION );

		/*
		 * Each context is set up so that WITHOUT the shopping parameter it would
		 * genuinely be exempt — the runner carries a real nonce, the payment
		 * callback carries a real notification body. Otherwise the refusal would
		 * prove nothing about the mutation screen.
		 */
		$ipn_body = [ 'txn_id' => 'MIXED-1', 'payment_status' => 'Completed' ];

		$exempt_contexts = [
			'WP-Cron'            => [ '/wp-cron.php', [ 'doing_cron' => true ], [] ],
			'the queue runner'   => [ '/wp-admin/admin-ajax.php', [ 'doing_ajax' => true ], [] ],
			'a payment callback' => [ '/?wc-api=WC_Gateway_Paypal', [ 'woocommerce' => true, 'permalinks' => true ], $ipn_body ],
			'wp-login.php'       => [ '/wp-login.php', [], [] ],
		];

		foreach ( $exempt_contexts as $label => [ $uri, $overrides, $base_post ] ) {
			// The context must be exempt on its own, or the matrix below is vacuous.
			$base_get = [];
			if ( 'the queue runner' === $label ) {
				$base_get['action'] = HallCommerceGate::SCHEDULER_ACTION;
				$base_get['nonce']  = $runner_nonce;
			}
			if ( 'a payment callback' === $label ) {
				$base_get['wc-api'] = 'WC_Gateway_Paypal';
			}
			$baseline = HallCommerceGate::decide( $this->request( $uri, $base_get, $base_post, $overrides ) );
			$this->assert_true(
				HallCommerceGate::EXEMPT === $baseline['action'],
				"{$label} is exempt on its own, so the mixed-request matrix is not vacuous"
			);

			foreach ( $params as $param ) {
				$in_get  = HallCommerceGate::decide( $this->request( $uri, $base_get + [ $param => '1' ], $base_post, $overrides ) );
				$in_post = HallCommerceGate::decide( $this->request( $uri, $base_get, $base_post + [ $param => '1' ], $overrides ) );

				$this->assert_true(
					HallCommerceGate::REFUSE === $in_get['action'] && HallCommerceGate::REASON_MUTATION === $in_get['reason']
						&& HallCommerceGate::REFUSE === $in_post['action'] && HallCommerceGate::REASON_MUTATION === $in_post['reason'],
					"{$label} carrying '{$param}' is refused before any mutation runs"
				);
			}
		}

		// Every trigger this suite names is a real trigger in the installed
		// WooCommerce source; nothing here was invented.
		$source  = (string) file_get_contents( $this->wc_form_handler );
		$source .= (string) file_get_contents( $this->wc_ajax );
		// WC_Checkout owns the checkout nonce that WC_Form_Handler validates.
		$source .= (string) file_get_contents( $this->wc_checkout );
		$missing = [];
		foreach ( $params as $param ) {
			if ( false === strpos( $source, "'" . $param . "'" ) ) {
				$missing[] = $param;
			}
		}
		$this->assert_true(
			[] === $missing,
			'every mutation trigger appears in the installed WooCommerce source: ' . implode( ', ', $missing )
		);

		// And the triggers the installed source actually gates on are covered.
		foreach (
			[
				'add-to-cart',
				'apply_coupon',
				'remove_coupon',
				'remove_item',
				'undo_item',
				'update_cart',
				'proceed',
				'woocommerce_checkout_place_order',
				'woocommerce_checkout_update_totals',
				'woocommerce_pay',
				'woocommerce_add_payment_method',
				'cancel_order',
				'login',
				'register',
				'wc_reset_password',
				'wc-ajax',
			] as $required
		) {
			$this->assert_true(
				in_array( $required, $params, true ),
				"the mutation list covers the installed trigger '{$required}'"
			);
		}
	}

	private function test_detach_matches_installed_woocommerce(): void {
		$source = (string) file_get_contents( $this->wc_form_handler );
		$init   = '';
		if ( 1 === preg_match( '/public static function init\(\)\s*\{(.*?)\n\t\}/s', $source, $m ) ) {
			$init = $m[1];
		}
		$this->assert_true( '' !== $init, 'WC_Form_Handler::init() was located in the installed source' );

		preg_match_all(
			"/add_action\(\s*'([a-z_]+)'\s*,\s*array\(\s*__CLASS__\s*,\s*'([a-z_]+)'\s*\)\s*(?:,\s*(\d+)\s*)?\)/",
			$init,
			$matches,
			PREG_SET_ORDER
		);

		$installed = [];
		foreach ( $matches as $match ) {
			$installed[] = [ $match[1], $match[2], isset( $match[3] ) && '' !== $match[3] ? (int) $match[3] : 10 ];
		}

		$reflection = new ReflectionClass( HallCommerceGate::class );
		$detached   = array_map(
			static function ( $entry ): array {
				return [ (string) $entry[0], (string) $entry[1], (int) $entry[2] ];
			},
			(array) $reflection->getConstant( 'DETACH_FORM_HANDLER' )
		);

		sort( $installed );
		sort( $detached );

		$this->assert_true( count( $installed ) >= 16, 'the installed WC_Form_Handler registers the expected number of callbacks' );
		$this->assert_true(
			$installed === $detached,
			'the detach table matches WC_Form_Handler::init() in the installed source exactly'
		);

		$ajax = (string) file_get_contents( $this->wc_ajax );
		$this->assert_true(
			1 === preg_match( "/add_action\(\s*'template_redirect'\s*,\s*array\(\s*__CLASS__\s*,\s*'do_wc_ajax'\s*\)\s*,\s*0\s*\)/", $ajax ),
			'WC_AJAX::do_wc_ajax is registered on template_redirect at priority 0 in the installed source'
		);

		// The ordering that makes the mixed-request screen necessary.
		$this->assert_true(
			false !== strpos( $init, "add_action( 'wp_loaded', array( __CLASS__, 'add_to_cart_action' ), 20 )" ),
			'add_to_cart_action runs on wp_loaded, which precedes the parse_request callback dispatch'
		);
		$stub = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/src/Internal/Utilities/LegacyRestApiStub.php';
		$this->assert_true(
			is_readable( $stub )
				&& 1 === preg_match( "/add_action\(\s*'parse_request'\s*,\s*array\(\s*__CLASS__\s*,\s*'parse_legacy_rest_api_request'\s*\)\s*,\s*0\s*\)/", (string) file_get_contents( $stub ) ),
			'the wc-api callback dispatch runs on parse_request, after wp_loaded'
		);
	}

	private function test_detach_removes_handlers(): void {
		$reflection = new ReflectionClass( HallCommerceGate::class );
		$detached   = (array) $reflection->getConstant( 'DETACH_FORM_HANDLER' );

		foreach ( $detached as [ $hook, $method, $priority ] ) {
			add_action( $hook, [ 'WC_Form_Handler', $method ], (int) $priority );
		}
		add_action( 'template_redirect', [ 'WC_AJAX', 'do_wc_ajax' ], 0 );
		add_action( 'wp_loaded', [ 'BC_Unrelated_Handler', 'keep_me' ], 20 );

		HallCommerceGate::detach_shopping_handlers();

		$still = [];
		foreach ( $detached as [ $hook, $method, $priority ] ) {
			if ( false !== has_action( $hook, [ 'WC_Form_Handler', $method ] ) ) {
				$still[] = "{$hook}:{$method}";
			}
		}
		$this->assert_true( [] === $still, 'every WooCommerce shopping handler is detached: ' . implode( ', ', $still ) );
		$this->assert_true(
			false === has_action( 'template_redirect', [ 'WC_AJAX', 'do_wc_ajax' ] ),
			'the WooCommerce AJAX endpoint dispatcher is detached'
		);
		$this->assert_true(
			false !== has_action( 'wp_loaded', [ 'BC_Unrelated_Handler', 'keep_me' ] ),
			'an unrelated callback on the same hook is left alone'
		);

		HallCommerceGate::detach_shopping_handlers();
		$this->assert_true( true, 'detaching twice is harmless' );

		remove_action( 'wp_loaded', [ 'BC_Unrelated_Handler', 'keep_me' ], 20 );
	}

	private function test_gate_is_registered_and_cli_safe(): void {
		$priority = has_action( 'plugins_loaded', [ HallCommerceGate::class, 'enforce' ] );
		$this->assert_true( false !== $priority, 'the gate is registered on plugins_loaded' );
		$this->assert_true(
			is_int( $priority ) && $priority === Plugin::ENFORCEMENT_PRIORITY && $priority < -1,
			'the gate runs ahead of WooCommerce, which hooks plugins_loaded at -1'
		);

		// The gate registers nothing that could apply a schema or fire a request.
		foreach ( [ 'init', 'admin_init', 'wp_loaded', 'wp_initialize_site' ] as $hook ) {
			$this->assert_true(
				false === has_action( $hook, [ HallCommerceGate::class, 'enforce' ] ),
				"the gate is not attached to {$hook}"
			);
		}
		$this->assert_true(
			false === has_action( 'plugins_loaded', [ ProvisioningMigrations::class, 'migrate' ] ),
			'the Commerce state migration is still not wired to any automatic path'
		);

		// Under CLI the gate returns without refusing, which is what lets every
		// harness in tests/ boot this plugin in-process.
		$before = $this->fingerprint();
		HallCommerceGate::enforce();
		$this->assert_true( 'cli' === PHP_SAPI, 'this harness runs under the CLI SAPI' );
		$this->assert_fingerprints_equal( $before, $this->fingerprint(), 'enforce() under CLI' );

		$body = HallCommerceGate::body();
		$this->assert_true(
			false !== strpos( $body, 'This store is temporarily unavailable. Please contact the Hall administrator.' ),
			'the refusal page carries the required message'
		);
		$this->assert_true(
			false === strpos( $body, '<?' ) && false !== strpos( $body, 'noindex' ),
			'the refusal page is self-contained and not indexable'
		);
		$this->assert_true( 503 > 500 && HallCommerceGate::RETRY_AFTER > 0, 'a positive Retry-After is configured' );
	}

	/* ------------------------------------------------------------------ *
	 * Real requests
	 * ------------------------------------------------------------------ */

	/**
	 * Run one real request in its own process and report what happened.
	 *
	 * Under php-cgi the SAPI is `cgi-fcgi`, so HallCommerceGate::enforce() does
	 * not take its CLI exit, the gate runs on the genuine `plugins_loaded` hook
	 * and real response headers land in stdout. Under CLI the worker drives
	 * handle_request() itself.
	 *
	 * @param array<string,mixed> $spec
	 * @return array{status:int,headers:array<string,string>,report:array<string,mixed>,exit:int,raw:string}
	 */
	private function real_request( array $spec ): array {
		$path  = (string) ( $spec['path'] ?? '/' );
		$query = (string) ( $spec['query'] ?? '' );
		$body  = (string) ( $spec['body'] ?? '' );
		$cgi   = ( $spec['sapi'] ?? 'cgi' ) === 'cgi' && '' !== $this->php_cgi;

		$env = [
			'PATH'                      => (string) getenv( 'PATH' ),
			'BC_TEST_DB'                => BC_Fixture::$database,
			'BC_FIXTURE_NETWORK_ACTIVE' => '1',
			'BC_REQUEST_USER'           => (string) ( $spec['user'] ?? '' ),
			'BC_REQUEST_CONSTANTS'      => implode( ',', (array) ( $spec['constants'] ?? [] ) ),
			'BC_REQUEST_NONCE_ACTION'   => (string) ( $spec['nonce_action'] ?? '' ),
			'BC_REQUEST_NONCE_PARAM'    => (string) ( $spec['nonce_param'] ?? '' ),
			'BC_REQUEST_WOOCOMMERCE'    => ! empty( $spec['woocommerce'] ) ? '1' : '',
			'BC_REQUEST_STALL'          => (string) ( (int) ( $spec['stall'] ?? 0 ) ),
			'HTTP_HOST'                 => (string) ( $spec['host'] ?? ( 'hall-a.' . BC_FIXTURE_DOMAIN ) ),
			'REQUEST_URI'               => $path . ( '' === $query ? '' : '?' . $query ),
			'QUERY_STRING'              => $query,
			'REQUEST_METHOD'            => '' === $body ? 'GET' : 'POST',
			'SCRIPT_NAME'               => (string) ( $spec['php_self'] ?? '/index.php' ),
			'PHP_SELF'                  => (string) ( $spec['php_self'] ?? '/index.php' ),
		];

		if ( $cgi ) {
			$env['BC_FIXTURE_CGI']  = '1';
			$env['REDIRECT_STATUS'] = '1';
			$env['SCRIPT_FILENAME'] = dirname( __DIR__ ) . '/tests/hall-commerce-access-request.php';
			if ( '' !== $body ) {
				$env['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
				$env['CONTENT_LENGTH'] = (string) strlen( $body );
			}
			$command = [ $this->php_cgi, dirname( __DIR__ ) . '/tests/hall-commerce-access-request.php' ];
		} else {
			$command = [ PHP_BINARY, dirname( __DIR__ ) . '/tests/hall-commerce-access-request.php' ];
		}

		$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$process     = proc_open( $command, $descriptors, $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) {
			$this->abort( 'a request worker could not be started.' );
		}

		$out            = '';
		$err            = '';
		$timed_out      = false;
		$exit_confirmed = true;
		$unreaped_pid   = 0;

		/*
		 * Bounded, non-blocking drain. stream_get_contents() on a blocking pipe
		 * would wait for a hung child forever, and so would proc_close(); a
		 * worker that overruns is terminated, escalated and reaped instead, and
		 * every pipe is released on every path.
		 */
		try {
			if ( '' !== $body && $cgi ) {
				fwrite( $pipes[0], $body );
			}
			fclose( $pipes[0] );
			unset( $pipes[0] );

			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$deadline = microtime( true ) + (float) ( $spec['deadline'] ?? 60.0 );
			while ( microtime( true ) < $deadline ) {
				$out .= (string) stream_get_contents( $pipes[1] );
				$err .= (string) stream_get_contents( $pipes[2] );

				$status = proc_get_status( $process );
				if ( is_array( $status ) && false === $status['running'] ) {
					$out .= (string) stream_get_contents( $pipes[1] );
					$err .= (string) stream_get_contents( $pipes[2] );
					break;
				}
				usleep( 10000 );
			}

			$status = proc_get_status( $process );
			if ( is_array( $status ) && true === $status['running'] ) {
				$timed_out = true;
				$pid       = (int) $status['pid'];

				/*
				 * Escalate, then CONFIRM. SIGKILL is uncatchable but not
				 * instantaneous, and proc_close() waits for a child that has not
				 * exited — so the wait happens here, bounded, and the reap only
				 * happens once exit is confirmed. A child that still cannot be
				 * confirmed is recorded as a failure and deliberately NOT
				 * proc_close()d, because that call is the unbounded one.
				 */
				foreach ( [ defined( 'SIGTERM' ) ? SIGTERM : 15, defined( 'SIGKILL' ) ? SIGKILL : 9 ] as $signal ) {
					proc_terminate( $process, $signal );
					$grace = microtime( true ) + 5.0;
					while ( microtime( true ) < $grace ) {
						$out .= (string) stream_get_contents( $pipes[1] );
						$err .= (string) stream_get_contents( $pipes[2] );
						$status = proc_get_status( $process );
						if ( is_array( $status ) && false === $status['running'] ) {
							break 2;
						}
						usleep( 20000 );
					}
				}

				$status = proc_get_status( $process );
				$exit_confirmed = is_array( $status ) && false === $status['running'];
				if ( ! $exit_confirmed ) {
					$unreaped_pid = $pid;
				}
			}

			// Final drain, after the process has stopped producing.
			$out .= (string) stream_get_contents( $pipes[1] );
			$err .= (string) stream_get_contents( $pipes[2] );
		} finally {
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			// Reap ONLY after confirmed exit; otherwise the handle is left for
			// the operator named in the failure rather than blocking here.
			$exit = $exit_confirmed ? (int) proc_close( $process ) : -1;
		}

		$status  = 200;
		$headers = [];
		if ( $cgi ) {
			$split = preg_split( "/\r?\n\r?\n/", $out, 2 );
			foreach ( preg_split( "/\r?\n/", (string) ( $split[0] ?? '' ) ) as $line ) {
				if ( 1 === preg_match( '/^([A-Za-z0-9\-]+):\s*(.*)$/', (string) $line, $m ) ) {
					$headers[ strtolower( $m[1] ) ] = $m[2];
				}
			}
			if ( isset( $headers['status'] ) && 1 === preg_match( '/^(\d{3})/', $headers['status'], $m ) ) {
				$status = (int) $m[1];
			}
		}

		$report = [];
		if ( 1 === preg_match( '/--BC-REPORT--(.*?)--END--/s', $out, $m ) ) {
			$decoded = json_decode( $m[1], true );
			$report  = is_array( $decoded ) ? $decoded : [];
		}

		return [
			'status'    => $status,
			'headers'   => $headers,
			'report'    => $report,
			'exit'      => (int) $exit,
			'stderr'    => $err,
			'timed_out' => $timed_out,
			'exit_confirmed' => $exit_confirmed,
			'unreaped_pid'   => $unreaped_pid,
			'raw'       => $out . $err,
		];
	}

	/**
	 * A worker result is only usable if the PROCESS behaved.
	 *
	 * Reaching `init` proves a request continued; it does not prove the process
	 * finished cleanly. Exit status, stderr and PHP/database noise are checked
	 * separately, so a worker that crashed after the gate cannot be read as
	 * evidence of anything.
	 *
	 * @param array<string,mixed> $run
	 */
	private function assert_worker_clean( array $run, string $label ): void {
		$this->assert_true( false === $run['timed_out'], "{$label}: the worker finished within its deadline" );
		$this->assert_true( true === $run['exit_confirmed'], "{$label}: the worker's exit was confirmed before reaping" );
		$this->assert_true( 0 === (int) $run['exit'], "{$label}: the worker exited 0 (got {$run['exit']})" );

		$stderr = trim( (string) $run['stderr'] );
		$this->assert_true(
			'' === $stderr,
			"{$label}: the worker wrote nothing to stderr" . ( '' === $stderr ? '' : ' — ' . substr( $stderr, 0, 200 ) )
		);

		$noise = [];
		foreach ( [ 'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'WordPress database error' ] as $needle ) {
			if ( false !== strpos( (string) $run['raw'], $needle ) ) {
				$noise[] = $needle;
			}
		}
		$this->assert_true( [] === $noise, "{$label}: no PHP or database errors (" . implode( ', ', $noise ) . ')' );

		$this->assert_true( [] !== $run['report'], "{$label}: the worker returned a structured report" );
	}

	/**
	 * Outbound HTTP is counted INSIDE the child, because the parent cannot see
	 * what another process attempted.
	 *
	 * @param array<string,mixed> $run
	 */
	private function assert_child_made_no_http( array $run, string $label ): void {
		$urls = (array) ( $run['report']['http_urls'] ?? [] );
		$this->assert_true(
			0 === (int) ( $run['report']['http_attempts'] ?? -1 ) && [] === $urls,
			"{$label}: the CHILD process attempted no outbound request" . ( [] === $urls ? '' : ': ' . implode( ', ', $urls ) )
		);
	}

	/**
	 * Enforcement, exercised as real requests rather than as decision inputs.
	 */
	private function test_real_request_enforcement(): void {
		$this->assert_true(
			'' !== $this->php_cgi,
			'php-cgi is available, so enforcement runs under a real non-CLI SAPI'
				. ( '' === $this->php_cgi ? ' — SKIPPED, CLI fallback only' : '' )
		);

		// Hall A suspended, Hall B operational, both markers present.
		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 300, HallCommerceState::PHASE_SETTLED );
		HallCommerceState::write( $this->hall_b, HallCommerceState::OPERATIONAL, 300, HallCommerceState::PHASE_SETTLED );
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_a, 'value' => self::MARKER_A ] );
		$this->fixture_operation( self::OP_SET_MARKER, [ 'blog_id' => $this->hall_b, 'value' => self::MARKER_B ] );
		$this->fixture_operation( self::OP_SET_PERMALINKS, [ 'value' => '/%postname%/' ] );

		$suspended = 'hall-a.' . BC_FIXTURE_DOMAIN;
		$control   = 'hall-b.' . BC_FIXTURE_DOMAIN;
		$before    = $this->fingerprint();

		// --- the blocked storefront, end to end -----------------------------
		$run = $this->real_request( [ 'host' => $suspended, 'path' => '/' ] );
		$this->assert_worker_clean( $run, 'the blocked storefront request' );
		$this->assert_child_made_no_http( $run, '  the blocked storefront request' );
		$this->assert_true( true === ( $run['report']['refused'] ?? false ), 'a suspended Hall refuses the storefront' );
		$this->assert_true(
			'This store is temporarily unavailable. Please contact the Hall administrator.'
				=== trim( (string) ( $run['report']['body_sample'] ?? '' ) ),
			'the refusal body is exactly the agreed maintenance message'
		);
		$this->assert_true( [] === ( $run['report']['reached'] ?? [ 'x' ] ), 'a refused request reaches no init, wp_loaded, wp or template_redirect' );

		if ( '' !== $this->php_cgi ) {
			$this->assert_same( 503, $run['status'], 'a refused request returns HTTP 503' );
			$this->assert_same( 'cgi-fcgi', (string) ( $run['report']['sapi'] ?? '' ), 'it ran under a real non-CLI SAPI' );
			$this->assert_true(
				var_export( PHP_INT_MIN, true ) === (string) ( $run['report']['gate_registered'] ?? '' ),
				'the gate was registered at PHP_INT_MIN by wp-settings.php loading the network-active plugin'
					. ' (got ' . (string) ( $run['report']['gate_registered'] ?? '' ) . ')'
			);
			$this->assert_true(
				false !== stripos( (string) ( $run['headers']['cache-control'] ?? '' ), 'no-store' ),
				'the refusal is sent no-store'
			);
			$this->assert_same( '900', (string) ( $run['headers']['retry-after'] ?? '' ), 'the refusal carries Retry-After' );
			$this->assert_true(
				false !== stripos( (string) ( $run['headers']['x-robots-tag'] ?? '' ), 'noindex' ),
				'the refusal is not indexable'
			);
			$this->assert_true(
				0 === stripos( (string) ( $run['headers']['content-type'] ?? '' ), 'text/html' ),
				'the refusal is served as HTML'
			);
		}

		// --- the item 1 bypass attempts -------------------------------------
		$bypasses = [
			'/wp-admin/ with an approved wc-api identifier' => [
				'path' => '/wp-admin/', 'query' => 'wc-api=wc_gateway_paypal',
				'php_self' => '/wp-admin/index.php', 'constants' => [ 'WP_ADMIN' ], 'woocommerce' => true,
			],
			'admin-post.php carrying the identifier' => [
				'path' => '/wp-admin/admin-post.php', 'query' => 'wc-api=wc_gateway_paypal',
				'php_self' => '/wp-admin/admin-post.php', 'constants' => [ 'WP_ADMIN' ], 'woocommerce' => true,
				'body' => 'action=bc_portal_sync',
			],
			'an unrelated admin-ajax action carrying the identifier' => [
				'path' => '/wp-admin/admin-ajax.php', 'query' => 'action=heartbeat&wc-api=wc_gateway_paypal',
				'php_self' => '/wp-admin/admin-ajax.php', 'constants' => [ 'WP_ADMIN', 'DOING_AJAX' ], 'woocommerce' => true,
			],
			'wp-login.php registration carrying the identifier' => [
				'path' => '/wp-login.php', 'query' => 'action=register&wc-api=wc_gateway_paypal',
				'php_self' => '/wp-login.php', 'woocommerce' => true,
			],
			'wp-login.php password recovery carrying the identifier' => [
				'path' => '/wp-login.php', 'query' => 'action=lostpassword&wc-api=wc_gateway_paypal',
				'php_self' => '/wp-login.php', 'woocommerce' => true,
			],
			'a checkout POST' => [
				'path' => '/checkout/', 'body' => 'woocommerce_checkout_place_order=1',
			],
			'an add-to-cart link' => [
				'path' => '/', 'query' => 'add-to-cart=5',
			],
			'the Store API' => [
				'path' => '/wp-json/wc/store/v1/cart/add-item', 'body' => 'id=5',
			],
			'the queue-runner identifier with a forged nonce' => [
				'path' => '/wp-admin/admin-ajax.php',
				'query' => 'action=' . HallCommerceGate::SCHEDULER_ACTION . '&nonce=forged',
				'php_self' => '/wp-admin/admin-ajax.php', 'constants' => [ 'WP_ADMIN', 'DOING_AJAX' ],
			],
			'the queue-runner identifier with no nonce' => [
				'path' => '/wp-admin/admin-ajax.php',
				'query' => 'action=' . HallCommerceGate::SCHEDULER_ACTION,
				'php_self' => '/wp-admin/admin-ajax.php', 'constants' => [ 'WP_ADMIN', 'DOING_AJAX' ],
			],
			'a payment callback carrying a shopping parameter' => [
				'path' => '/wc-api/WC_Gateway_Paypal/', 'query' => 'add-to-cart=5',
				'woocommerce' => true,
			],
		];

		foreach ( $bypasses as $label => $spec ) {
			$run = $this->real_request( [ 'host' => $suspended ] + $spec );
			$this->assert_worker_clean( $run, $label );
			$this->assert_child_made_no_http( $run, "  {$label}" );
			$this->assert_true(
				true === ( $run['report']['refused'] ?? false ),
				"a suspended Hall refuses {$label}"
			);
			$this->assert_true(
				[] === ( $run['report']['reached'] ?? [ 'x' ] ),
				"  {$label} ran no init or wp_loaded handler"
			);
			if ( '' !== $this->php_cgi ) {
				$this->assert_same( 503, $run['status'], "  {$label} returned HTTP 503" );
			}
		}

		// --- what must still work -------------------------------------------
		$allowed = [
			'a legitimate PayPal IPN callback' => [
				'host' => $suspended, 'path' => '/wc-api/WC_Gateway_Paypal/',
				'body' => 'txn_id=9BW12345&payment_status=Completed&quantity=1',
				'woocommerce' => true,
			],
			'the queue runner with a genuine nonce' => [
				'host' => $suspended, 'path' => '/wp-admin/admin-ajax.php',
				'query' => 'action=' . HallCommerceGate::SCHEDULER_ACTION,
				'php_self' => '/wp-admin/admin-ajax.php', 'constants' => [ 'WP_ADMIN', 'DOING_AJAX' ],
				'nonce_action' => HallCommerceGate::SCHEDULER_ACTION, 'nonce_param' => 'nonce',
			],
			'WP-Cron' => [
				'host' => $suspended, 'path' => '/wp-cron.php',
				'php_self' => '/wp-cron.php', 'constants' => [ 'DOING_CRON' ],
			],
			'the login form' => [
				'host' => $suspended, 'path' => '/wp-login.php', 'php_self' => '/wp-login.php',
			],
			'the control Hall storefront' => [ 'host' => $control, 'path' => '/' ],
			'the control Hall checkout' => [ 'host' => $control, 'path' => '/checkout/' ],
			'the main network site' => [ 'host' => BC_FIXTURE_DOMAIN, 'path' => '/' ],
		];

		foreach ( $allowed as $label => $spec ) {
			$run = $this->real_request( $spec );
			// Reaching `init` is not on its own proof of a healthy worker, so
			// the process itself is checked before the decision is believed.
			$this->assert_worker_clean( $run, $label );
			$this->assert_child_made_no_http( $run, "  {$label}" );
			$this->assert_true(
				false === ( $run['report']['refused'] ?? true ),
				"{$label} is not refused"
					. ( false === ( $run['report']['refused'] ?? true ) ? '' : ' — ' . substr( $run['raw'], -200 ) )
			);
			$this->assert_true(
				in_array( 'init', (array) ( $run['report']['reached'] ?? [] ), true )
					&& in_array( 'wp_loaded', (array) ( $run['report']['reached'] ?? [] ), true ),
				"  {$label} continued through init and wp_loaded"
			);
			$this->assert_same( 'continued', (string) ( $run['report']['outcome'] ?? '' ), "  {$label} ran to completion" );
		}

		// --- staff sessions and Super Admin recovery, as real requests -------
		foreach ( [ 'fixture_hall_manager', 'fixture_game_operator' ] as $login ) {
			$run = $this->real_request( [ 'host' => $suspended, 'path' => '/wp-admin/', 'php_self' => '/wp-admin/index.php', 'constants' => [ 'WP_ADMIN' ], 'user' => $login ] );
			$this->assert_worker_clean( $run, "the {$login} session request" );
			$this->assert_true(
				true === ( $run['report']['refused'] ?? false ) && (int) ( $run['report']['user'] ?? 0 ) > 0,
				"an authenticated {$login} session is still refused"
			);
		}

		$run = $this->real_request( [ 'host' => $suspended, 'path' => '/wp-admin/', 'php_self' => '/wp-admin/index.php', 'constants' => [ 'WP_ADMIN' ], 'user' => 'fixture_admin' ] );
		$this->assert_worker_clean( $run, 'the Super Admin recovery request' );
		$this->assert_true(
			false === ( $run['report']['refused'] ?? true ) && (int) ( $run['report']['user'] ?? 0 ) > 0,
			'a Network Super Admin keeps recovery access to the suspended Hall'
		);

		// --- nothing changed anywhere ---------------------------------------
		$this->assert_fingerprints_equal(
			$before,
			$this->fingerprint(),
			'the real-request pass',
			[ 'state', 'hall_options', 'main_options', 'sitemeta_other', 'blogs', 'users', 'usermeta' ]
		);
		/*
		 * Each worker's own count was asserted above, inside the process that
		 * would have made the request. This parent counter only ever observes
		 * THIS process, which is a separate and much weaker fact — it is
		 * asserted as such and never offered as evidence about the children.
		 */
		$this->assert_true( 0 === $this->http_attempts, 'this parent process attempted no outbound HTTP of its own' );
	}

	/* ------------------------------------------------------------------ *
	 * 4. Manual sync boundary
	 * ------------------------------------------------------------------ */

	private function test_sync_boundary(): void {
		$this->assert_true( true === Migrations::migrate_network(), 'the Hall mapping schema migrates in the fixture' );
		$this->owns_mapping_table = true;
		$this->assert_true( Migrations::is_current(), 'the Hall mapping schema is current, so synchronization reaches the lock' );

		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		HallCommerceState::write( $this->hall_a, HallCommerceState::SUSPENDED, 70, HallCommerceState::PHASE_SETTLED );
		HallCommerceState::write( $this->hall_b, HallCommerceState::OPERATIONAL, 70, HallCommerceState::PHASE_SETTLED );

		$attempts_before = $this->http_attempts;

		// Hold BOTH Halls' advisory locks from a second connection. A caller that
		// reached PortalSyncLock::acquire() would wait for its 5-second timeout
		// and report lock_busy; the blocked Hall must not.
		$holder = $this->hold_locks( [ $this->hall_a, $this->hall_b ] );

		$started = microtime( true );
		$blocked = PortalSyncService::synchronize( $this->hall_a );
		$elapsed = microtime( true ) - $started;

		$this->assert_true(
			$blocked instanceof WP_Error && 'hall_commerce_blocked' === $blocked->get_error_code(),
			'a direct synchronization of a blocked Hall is refused'
		);
		$this->assert_true(
			$elapsed < 1.0,
			sprintf( 'the refusal happened before lock acquisition (%.3fs, well under the 5s lock timeout)', $elapsed )
		);

		// Control: an allowed Hall DOES reach the lock, proving the refusal above
		// was the access decision and not some earlier unrelated failure.
		$started = microtime( true );
		$allowed = PortalSyncService::synchronize( $this->hall_b );
		$elapsed = microtime( true ) - $started;

		$this->assert_true(
			$allowed instanceof WP_Error && 'portal_sync_lock_timeout' === $allowed->get_error_code(),
			'an operational Hall passes the access check and reaches the advisory lock'
		);
		$this->assert_true( $elapsed >= 1.0, sprintf( 'the allowed Hall genuinely waited on the lock (%.3fs)', $elapsed ) );

		$this->release_locks( $holder );

		// Super Admin does not lift the synchronization restriction.
		wp_set_current_user( $this->users['fixture_admin'] );
		$this->assert_true( is_super_admin(), 'the Super Admin session is active for the next assertion' );
		$as_admin = PortalSyncService::synchronize( $this->hall_a );
		$this->assert_true(
			$as_admin instanceof WP_Error && 'hall_commerce_blocked' === $as_admin->get_error_code(),
			'recovery access does not override the synchronization restriction'
		);
		wp_set_current_user( 0 );

		// A legacy Hall is unaffected: it passes access and fails later, on the
		// mapping, exactly as it did before this increment.
		$this->fixture_operation( self::OP_DELETE_STATE_ROWS );
		$this->fixture_operation( self::OP_DELETE_MARKER, [ 'blog_id' => $this->hall_b ] );
		$legacy = PortalSyncService::synchronize( $this->hall_b );
		$this->assert_true(
			$legacy instanceof WP_Error && 'mapping_not_found' === $legacy->get_error_code(),
			'a legacy Hall synchronizes exactly as before and stops at its missing mapping'
		);

		// Provisioning blocks synchronization too.
		HallCommerceState::write( $this->hall_b, HallCommerceState::PROVISIONING, 80, HallCommerceState::PHASE_TRANSITIONAL );
		$provisioning = PortalSyncService::synchronize( $this->hall_b );
		$this->assert_true(
			$provisioning instanceof WP_Error && 'hall_commerce_blocked' === $provisioning->get_error_code(),
			'a provisioning Hall is refused synchronization'
		);

		$this->assert_true(
			$attempts_before === $this->http_attempts,
			'no outbound Portal request was attempted on any refused synchronization'
		);

		$this->assert_true(
			0 === (int) $GLOBALS['wpdb']->get_var(
				'SELECT COUNT(*) FROM `' . Migrations::hall_mappings_table() . '`'
			),
			'no Hall mapping row was created, changed or removed'
		);
	}

	/**
	 * Hold each Hall's PortalSyncLock name on a separate MySQL connection.
	 *
	 * The name is rebuilt from the same inputs PortalSyncLock uses, so this
	 * genuinely contends with it rather than with a lookalike.
	 *
	 * @param array<int,int> $halls
	 */
	private function hold_locks( array $halls ): mysqli {
		global $wpdb;

		mysqli_report( MYSQLI_REPORT_OFF );
		$holder = @new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		if ( $holder->connect_errno ) {
			$this->abort( 'the lock-holding connection could not be opened.' );
		}

		$discriminator = hash( 'sha256', DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id() );
		foreach ( $halls as $hall ) {
			$name = 'bc:ps:' . substr( $discriminator, 0, 32 ) . ':' . $hall;
			$stmt = $holder->prepare( 'SELECT GET_LOCK(?, 0)' );
			$stmt->bind_param( 's', $name );
			$stmt->execute();
			$acquired = (int) ( $stmt->get_result()->fetch_row()[0] ?? 0 );
			$stmt->close();
			if ( 1 !== $acquired ) {
				$this->abort( "the advisory lock for Hall {$hall} could not be pre-held." );
			}
		}

		return $holder;
	}

	private function release_locks( mysqli $holder ): void {
		$holder->query( 'DO RELEASE_ALL_LOCKS()' );
		$holder->close();
	}

	private function test_admin_error_category(): void {
		$reflection = new ReflectionClass( PortalSyncAdmin::class );
		$categories = (array) $reflection->getConstant( 'ERROR_CATEGORIES' );

		$this->assert_true(
			'commerce_blocked' === ( $categories['hall_commerce_blocked'] ?? '' ),
			'the admin screen maps hall_commerce_blocked to a fixed category'
		);

		$messages = $reflection->getMethod( 'error_messages' );
		$messages->setAccessible( true );
		$map = (array) $messages->invoke( null );

		$this->assert_true(
			isset( $map['commerce_blocked'] ) && '' !== $map['commerce_blocked'],
			'the category has a fixed, safe notice'
		);
		$this->assert_true(
			$map['commerce_blocked'] !== ( $map['unknown'] ?? '' ),
			'the notice is specific rather than falling back to the unknown text'
		);
		$this->assert_true(
			'commerce_blocked' === sanitize_key( 'commerce_blocked' ),
			'the category survives the redirect round-trip that sanitize_key() performs'
		);

		$category_for = $reflection->getMethod( 'category_for' );
		$category_for->setAccessible( true );
		$this->assert_same(
			'commerce_blocked',
			(string) $category_for->invoke( null, new WP_Error( 'hall_commerce_blocked', 'x' ) ),
			'the reducer returns the new category for the new code'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Data preservation and cleanup
	 * ------------------------------------------------------------------ */

	/** @param array<string,array<string,mixed>> $before */
	private function test_data_preserved( array $before ): void {
		/*
		 * Scoped on purpose. This suite deliberately writes state rows, markers
		 * and its own permalink structure; what it must NOT have touched is the
		 * network's site list, its users and their metadata, and the network
		 * options it does not own. Those are compared with full VALUES.
		 */
		$this->assert_fingerprints_equal(
			$before,
			$this->fingerprint(),
			'the whole suite',
			[ 'blogs', 'users', 'usermeta', 'sitemeta_other' ]
		);
		$this->assert_true( 0 === $this->http_attempts, 'this parent process attempted no outbound HTTP for the whole suite' );
	}

	/**
	 * Compare two fingerprints, failing on an unreadable capture either side.
	 *
	 * A failed query must never compare equal to another failed query: two empty
	 * result sets look identical, and "the database would not answer" is not
	 * evidence that nothing changed. Every capture therefore carries its own
	 * success flag, and both sides must have read successfully before the
	 * contents are compared at all.
	 *
	 * @param array<string,array<string,mixed>> $before
	 * @param array<string,array<string,mixed>> $after
	 */
	private function assert_fingerprints_equal( array $before, array $after, string $context, ?array $keys = null ): void {
		foreach ( $keys ?? array_keys( $before ) as $key ) {
			$b = $before[ $key ] ?? null;
			$a = $after[ $key ] ?? null;

			if ( ! is_array( $b ) || ! is_array( $a ) ) {
				$this->assert_true( false, "{$context}: the {$key} capture is missing" );
				continue;
			}
			if ( true !== ( $b['ok'] ?? false ) || true !== ( $a['ok'] ?? false ) ) {
				$this->assert_true(
					false,
					"{$context}: the {$key} capture could not be read, so preservation is UNPROVEN"
				);
				continue;
			}

			$this->assert_true(
				$b['data'] === $a['data'],
				"{$context}: {$key} is byte-identical to the baseline"
			);
		}
	}

	/**
	 * Run one preservation query, recording whether it actually succeeded.
	 *
	 * @param array<int,mixed> $args
	 * @return array{ok:bool,data:string}
	 */
	private function capture( string $sql, array $args = [] ): array {
		global $wpdb;

		$prepared = [] === $args ? $sql : $wpdb->prepare( $sql, ...$args );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return [ 'ok' => false, 'data' => '' ];
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			$rows  = $wpdb->get_results( $prepared, ARRAY_A );
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return [ 'ok' => false, 'data' => '' ];
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $rows ) ) {
			return [ 'ok' => false, 'data' => '' ];
		}

		return [ 'ok' => true, 'data' => (string) wp_json_encode( $rows ) ];
	}

	/**
	 * Capture a table's contents, or record WHY it could not be captured.
	 *
	 * A table that is genuinely absent is a fact worth comparing; a table whose
	 * existence could not be established is not, and must fail the comparison
	 * rather than masquerade as the former.
	 *
	 * @param array<int,mixed> $args
	 * @return array{ok:bool,data:string}
	 */
	private function capture_table( string $table, string $sql, array $args = [] ): array {
		$state = $this->table_state( $table );

		if ( self::TABLE_UNREADABLE === $state ) {
			return [ 'ok' => false, 'data' => '' ];
		}
		if ( self::TABLE_ABSENT === $state ) {
			return [ 'ok' => true, 'data' => 'absent' ];
		}

		return $this->capture( $sql, $args );
	}

	/**
	 * Everything this suite must leave untouched, VALUES included.
	 *
	 * Names alone would hide a rewritten option, a changed capability set or an
	 * altered email address, so every capture below selects the stored values
	 * as well. Transients are excluded by name because WordPress may create or
	 * expire one at any moment for reasons unrelated to this work.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fingerprint(): array {
		global $wpdb;

		$state = $this->capture_table(
			ProvisioningMigrations::state_table(),
			'SELECT blog_id, seq, state, updated_at FROM `' . ProvisioningMigrations::state_table() . '` ORDER BY blog_id'
		);

		/*
		 * Excluded by name, with evidence: booting WordPress for a site whose
		 * options table is new writes `cron` and the default `widget_*` set. That
		 * was measured, not assumed, and it is WordPress populating its own
		 * defaults — nothing this increment reads or writes. Everything else,
		 * including every `bc_` option, is compared with its stored value.
		 */
		$volatile = [ 'cron', $wpdb->esc_like( 'widget_' ) . '%', $wpdb->esc_like( '_transient_' ) . '%' ];

		$halls = [];
		foreach ( [ $this->hall_a, $this->hall_b ] as $hall ) {
			$table          = $this->options_table( $hall );
			$halls[ $hall ] = $this->capture_table(
				$table,
				"SELECT option_name, option_value, autoload FROM `{$table}`
				 WHERE option_name <> %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				 ORDER BY option_name",
				$volatile
			);
		}
		$halls_ok = true;
		foreach ( $halls as $entry ) {
			$halls_ok = $halls_ok && true === $entry['ok'];
		}

		$owned_meta   = [ ProvisioningMigrations::VERSION_OPTION, Migrations::NETWORK_VERSION_OPTION ];
		$placeholders = implode( ',', array_fill( 0, count( $owned_meta ), '%s' ) );
		$transients   = [ $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ];

		return [
			'state'          => $state,
			'hall_options'   => [
				'ok'   => $halls_ok,
				'data' => (string) wp_json_encode( array_map( static fn( array $e ): string => (string) $e['data'], $halls ) ),
			],
			'sitemeta_other' => $this->capture(
				"SELECT meta_key, meta_value FROM {$wpdb->sitemeta}
				 WHERE meta_key NOT IN ({$placeholders})
				   AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s
				 ORDER BY meta_key, meta_value",
				array_merge( $owned_meta, $transients )
			),
			'blogs'          => $this->capture( "SELECT blog_id, site_id, domain, path, registered, public, archived, mature, spam, deleted FROM {$wpdb->blogs} ORDER BY blog_id" ),
			'main_options'   => $this->capture(
				"SELECT option_name, option_value, autoload FROM {$wpdb->base_prefix}options
				 WHERE option_name <> %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				   AND option_name NOT LIKE %s
				 ORDER BY option_name",
				array_merge( $volatile, [ $wpdb->esc_like( '_site_transient_' ) . '%' ] )
			),
			// Every user field this suite claims it does not alter, not just the id.
			'users'          => $this->capture( "SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name FROM {$wpdb->users} ORDER BY ID" ),
			'usermeta'       => $this->capture( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY user_id, meta_key, meta_value" ),
		];
	}

	private function teardown(): void {
		wp_set_current_user( 0 );

		if ( $this->owns_state_table ) {
			$this->fixture_operation( self::OP_DROP_STATE_TABLE );
			// The v2 migration installs BOTH tables, so a run that owns one owns
			// the other; the version goes with them.
			$this->fixture_operation( self::OP_DROP_OPERATIONS_TABLE );
			$this->fixture_operation( self::OP_DELETE_VERSION );
		}
		if ( $this->owns_mapping_table ) {
			$this->fixture_operation( self::OP_DROP_MAPPING_TABLE );
			$this->fixture_operation( self::OP_DELETE_MAPPING_VERSION );
		}
		foreach ( array_keys( $this->owns_hall_options ) as $hall ) {
			$this->fixture_operation( self::OP_DROP_HALL_OPTIONS, [ 'blog_id' => (int) $hall ] );
		}

		// Fixture users go through the same guarded mechanism as every other
		// destructive operation, which re-proves ownership and the connected
		// database at deletion time and inspects both delete results.
		foreach ( $this->users as $login => $user_id ) {
			$removed = $this->fixture_operation( self::OP_DELETE_FIXTURE_USER, [ 'user_id' => (int) $user_id ] );
			$this->assert_true( $removed, "the fixture user {$login} was deleted through the guard" );
			if ( $removed ) {
				unset( $this->users[ $login ] );
			}
		}

		global $wpdb;

		$leftovers = [];
		if ( $this->table_exists( ProvisioningMigrations::state_table() ) ) {
			$leftovers[] = 'state table';
		}
		if ( $this->table_exists( ProvisioningMigrations::operations_table() ) ) {
			$leftovers[] = 'provisioning operations table';
		}
		if ( $this->table_exists( Migrations::hall_mappings_table() ) ) {
			$leftovers[] = 'mapping table';
		}
		foreach ( [ $this->hall_a, $this->hall_b ] as $hall ) {
			if ( $this->table_exists( $this->options_table( $hall ) ) ) {
				$leftovers[] = "Hall {$hall} options table";
			}
		}
		if ( (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ) > 0 ) {
			$leftovers[] = 'state schema version';
		}
		if ( (int) get_site_option( Migrations::NETWORK_VERSION_OPTION, 0 ) > 0 ) {
			$leftovers[] = 'mapping schema version';
		}
		$remaining = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'fixture\\_%'" );
		if ( null === $remaining || '' !== (string) $wpdb->last_error ) {
			$leftovers[] = 'fixture users (the check itself could not be read)';
		} elseif ( 0 !== (int) $remaining ) {
			$leftovers[] = 'fixture users';
		}
		$this->fixture_operation( self::OP_SET_PERMALINKS, [ 'value' => '' ] );

		$this->assert_true( [] === $leftovers, 'cleanup left nothing behind: ' . implode( ', ', $leftovers ) );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function assert_access( $blog_id, string $access, string $reason, string $label ): void {
		$verdict = HallCommerceAccess::evaluate( $blog_id );
		$ok      = $access === $verdict['access'] && $reason === $verdict['reason'];
		$this->assert_true(
			$ok,
			$label . ( $ok ? '' : " (got {$verdict['access']}/{$verdict['reason']})" )
		);
	}

	private function options_table( int $blog_id ): string {
		return $this->hall_prefix( $blog_id ) . 'options';
	}

	private function hall_prefix( int $blog_id ): string {
		global $wpdb;

		return $wpdb->base_prefix . $blog_id . '_';
	}

	private function row_count( int $blog_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . ProvisioningMigrations::state_table() . '` WHERE blog_id = %d', $blog_id )
		);
	}

	/* Table discovery is THREE-valued: a failed SHOW TABLES is not an absence. */
	private const TABLE_PRESENT    = 'present';
	private const TABLE_ABSENT     = 'absent';
	private const TABLE_UNREADABLE = 'unreadable';

	/**
	 * Can this table be seen, is it genuinely gone, or could we not ask?
	 *
	 * The third answer is the point. A `SHOW TABLES` that fails returns nothing,
	 * which is indistinguishable from a table that does not exist — and a
	 * preservation check that reads "could not ask" as "absent" quietly compares
	 * two unknowns and calls them equal.
	 */
	private function table_state( string $table ): string {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::TABLE_UNREADABLE;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error ) {
			return self::TABLE_UNREADABLE;
		}

		return null === $found ? self::TABLE_ABSENT : self::TABLE_PRESENT;
	}

	/** Convenience for setup paths, where an unreadable answer aborts anyway. */
	private function table_exists( string $table ): bool {
		$state = $this->table_state( $table );
		if ( self::TABLE_UNREADABLE === $state ) {
			$this->abort( "table discovery for {$table} could not be read." );
		}

		return self::TABLE_PRESENT === $state;
	}

	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		fwrite( STDERR, "Stopping before any further mutating test.\n" );
		exit( 1 );
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

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $label ): void {
		$this->assert_true(
			$expected === $actual,
			$label . ( $expected === $actual ? '' : ' (got ' . $this->describe( $actual ) . ')' )
		);
	}

	private function assert_true( bool $condition, string $label ): void {
		$this->assertions++;
		if ( ! $condition ) {
			$this->failures++;
			echo "FAIL: {$label}\n";

			return;
		}
		// Opt-in, because a passing run should be quiet by default; set
		// BC_VERBOSE=1 to have each satisfied assertion state itself.
		if ( '1' === (string) getenv( 'BC_VERBOSE' ) ) {
			echo "pass: {$label}\n";
		}
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		echo "\n{$passed}/{$this->assertions} assertions passed";
		echo 0 === $this->failures ? " — PASS\n" : " — {$this->failures} FAILED\n";
		exit( 0 === $this->failures ? 0 : 1 );
	}
}

( new HallCommerceAccessTest() )->run();
