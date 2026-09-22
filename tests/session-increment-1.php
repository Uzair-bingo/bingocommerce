<?php

/**
 * CLI integration coverage for Session Increment 1.
 *
 * Exercises the real database: the hall-local Session schema and its migration,
 * authorization, hall isolation, Session validation/lifecycle, Package
 * assignment through the supported PackageService reads, the BR-051/BR-063
 * sales window, and ordinary ADR-023 auditing.
 *
 * HARNESS SAFETY:
 *  - persistent halls (Sunrise 2, Tampa 3, Orlando 4) receive ordinary domain
 *    DML against tracked fixture rows ONLY. No structural SQL ever targets them;
 *  - every structural/corruption scenario runs on a hall created by this run
 *    alone, behind assert_disposable_hall(), and a hall is destroyed only when
 *    this process recorded its id in its own registry;
 *  - Sessions are NOT Woo posts, so ownership uses an in-memory registry of
 *    exact ids PLUS a durable TEST-ONLY per-site option carrying a run token and
 *    the exact Session / Session-Package ids this run created. That option is
 *    evidence for those exact ids only — never for a name, date, prefix,
 *    timestamp or id range — and is deleted only after every marker-proven row
 *    for the run has been removed;
 *  - a row created before its id was durably recorded is UNPROVEN and is
 *    reported, never deleted;
 *  - every bc_audit_log delete is blog_id-scoped;
 *  - persistent-hall Session/assignment baselines are preserved, not emptied.
 *
 * ISOLATED DATABASE ONLY (ADR-043 §16 amendment).
 *
 * This suite APPLIES Sessions migrations to the halls it names — including, from
 * Sessions v3, three new tables and seven new `bingo_sessions` columns — and it
 * performs structural DDL on a disposable hall. It therefore must never boot the
 * development installation: ensure_persistent_schema() alone would write v3
 * structure into halls 2, 3 and 4.
 *
 * It boots tests/fixtures/wordpress-bootstrap.php with BC_TEST_DB instead. That
 * fixture is allowlisted, sentinel-owned, and refuses the development database
 * by name, so isolation is enforced by the fixture rather than by this file's
 * good intentions. The guard below fails closed BEFORE the bootstrap so the
 * refusal is legible rather than arriving as a fixture abort.
 *
 * The fixture supplies the same four-hall shape this suite was written against —
 * halls 2, 3 and 4 initialized, WooCommerce-installed and role-provisioned, with
 * disposable halls starting at blog 5 — so SUNRISE/TAMPA/ORLANDO below name
 * fixture halls of the same shape, not development halls. No production class is
 * copied or replaced: the real plugin code under wp-content/plugins is what runs.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/session-increment-1.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

/*
 * Fail closed BEFORE WordPress boots.
 *
 * `bingocommerce_state_test_scratch` is excluded deliberately: the ownership
 * CONTROLS in tests/hall-commerce-state.php require that name to be ABSENT when
 * they run, so no other suite may leave a database there.
 */
$bc_target = (string) getenv( 'BC_TEST_DB' );
if ( '' === $bc_target ) {
	fwrite( STDERR, "BC_TEST_DB is not set. This suite applies Sessions migrations and performs\n" );
	fwrite( STDERR, "structural DDL, so it must never run against the development database.\n" );
	fwrite( STDERR, "Name the isolated database explicitly:\n" );
	fwrite( STDERR, "  BC_TEST_DB=bingocommerce_state_test_suites php tests/session-increment-1.php\n" );
	exit( 1 );
}
if ( ! in_array( $bc_target, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "BC_TEST_DB='{$bc_target}' is not an isolated fixture database for this suite.\n" );
	exit( 1 );
}
unset( $bc_target );

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

/*
 * Last-resort proof, after the bootstrap, that the fixture really selected an
 * isolated database. The fixture already refuses the development name; this
 * asserts the outcome rather than trusting it.
 */
if ( ! defined( 'DB_NAME' ) || ! in_array( DB_NAME, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "Refusing to run: the active database is not an isolated fixture.\n" );
	exit( 1 );
}
if ( ! class_exists( '\BingoCommerce\Catalog\PackageService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( ! class_exists( '\BingoCommerce\Sessions\SessionService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Sessions\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Sessions\Plugin::init();
}

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\PackageGateway;
use BingoCommerce\Sessions\SessionResult;
use BingoCommerce\Sessions\SessionService;

final class SessionIncrementOneTest {

	private const SUNRISE = 2;
	private const TAMPA   = 3;
	private const ORLANDO = 4;
	private const PREFIX  = 'SESN1';

	/** Durable TEST-ONLY per-site ownership registry. Never read by production code. */
	private const OWNERSHIP_OPTION = 'bc_sesn1_test_ownership';

	private int $super_admin_id = 0;
	private string $run_token = '';

	/** @var array<int,bool> */
	private array $disposable_sites = [];
	/** @var array<int,array<int,string>> */
	private array $disposable_tables = [];

	/** @var array<int,array<int,int>> in-memory current-run session ids */
	private array $created_sessions = [];
	/** @var array<int,array<int,int>> in-memory current-run relationship ids */
	private array $created_relations = [];
	/** @var array<int,array<int,int>> */
	private array $created_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_game_types = [];
	/** @var array<int,array<int,int>> */
	private array $created_play_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_packages = [];

	/** @var array<int,array<string,array<int,array<string,mixed>>>> */
	private array $baseline_sessions = [];

	private string $foreign_token = '';
	private int $foreign_product_id = 0;
	private int $foreign_owned_session = 0;
	/** @var array<string,array{sessions:array<int,int>,relations:array<int,int>}> pre-existing foreign runs */
	private array $preexisting_runs = [];

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
	private array $groups = [];
	/** @var array<int,string> */
	private array $notes = [];

	public function run(): void {
		$this->run_token      = 'sesn1-' . wp_generate_password( 12, false );
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );

		$this->ensure_persistent_schema();
		$this->ensure_role_fixtures();
		$this->record_baseline_sessions();
		$roles_before = $this->role_snapshot();

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'disposable hall created for destructive scenarios' );
		$this->ok( ! in_array( $hall, [ 1, self::SUNRISE, self::TAMPA, self::ORLANDO ], true ), '  and it is not a persistent hall' );

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			$this->note( sprintf(
				'hall %d Session baseline: %d Session row(s), %d assignment row(s) — these must survive untouched',
				$site_id,
				count( $this->baseline_sessions[ $site_id ]['sessions'] ),
				count( $this->baseline_sessions[ $site_id ]['relations'] )
			) );
		}

		try {
			$this->migration_group( $hall );
			$this->authorization_group();
			$this->isolation_group();
			$this->create_validation_group( $hall );
			$this->edit_group( $hall );
			$this->lifecycle_group( $hall );
			$this->assignment_group( $hall );
			$this->sales_window_group( $hall );
			$this->audit_group( $hall );
			$this->dependency_group();
			$this->combined_update_group( $hall );
			$this->plant_foreign_ownership();
		} finally {
			$this->cleanup();
		}

		$this->group( 'Setup' );
		$this->same( $roles_before, $this->role_snapshot(), 'role/capability records unchanged by the suite' );

		$this->report();
	}

	/* ================================================================== *
	 * A. Migration / provisioning
	 * ================================================================== */

	private function migration_group( int $hall ): void {
		global $wpdb;
		$this->group( 'A. Migration' );

		$this->same( 3, Migrations::DB_VERSION, 'Session DB_VERSION is 3' );
		$this->same( 'bc_sessions_db_version', Migrations::VERSION_OPTION, 'the per-site version option is bc_sessions_db_version' );

		// Sessions must NOT live in the Catalog migration.
		$catalog = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/src/Migrations.php' );
		$this->ok( false === strpos( (string) $catalog, 'bingo_sessions' ), 'Catalog\\Migrations does not own bingo_sessions' );
		$this->ok( false === strpos( (string) $catalog, 'bingo_session_packages' ), 'Catalog\\Migrations does not own bingo_session_packages' );
		$this->ok( false === strpos( (string) $catalog, 'bingo_portal_session_templates' ), 'Catalog\\Migrations does not own bingo_portal_session_templates' );

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->ok( true === Migrations::migrate_current_site(), "persistent hall {$site_id} migrates" );
			$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), "hall {$site_id} records version 3" );
			$this->ok( true === Migrations::verify_current_site_schema(), "hall {$site_id} schema verifies" );
			$this->ok( true === Migrations::migrate_current_site(), "hall {$site_id} rerun is idempotent" );
			restore_current_blog();
		}

		// New-hall provisioning happened automatically when the disposable hall
		// was created through wp_insert_site().
		switch_to_blog( $hall );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'a newly created hall provisions to version 3 automatically' );
		$this->ok( true === Migrations::verify_current_site_schema(), '  and its schema verifies' );

		$sessions  = Migrations::sessions_table();
		$relations = Migrations::session_packages_table();
		$this->ok( false === strpos( $sessions, 'blog_id' ), 'the Session table name carries no blog_id' );

		$create = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$sessions}`", ARRAY_N )[1] ?? '' );
		$this->ok( false !== stripos( $create, 'ENGINE=InnoDB' ), 'bingo_sessions is InnoDB' );
		$this->ok( false === stripos( $create, 'FOREIGN KEY' ), 'bingo_sessions has no physical foreign key' );
		$this->ok( false === stripos( $create, 'blog_id' ), 'bingo_sessions has no blog_id column' );
		// ADR-043 amendment. BR-073 SUPERSEDES BR-050 for STRUCTURED COMMERCE TIME
		// only: a generated occurrence now stores start_time_local, timezone_name
		// and starts_at_utc. BR-050's prohibition on PARSING time out of a display
		// name is untouched and is asserted separately.
		$this->ok( false !== stripos( $create, 'start_time_local' ), 'bingo_sessions carries structured start_time_local (ADR-043 §2.4)' );
		$this->ok( false !== stripos( $create, 'starts_at_utc' ), '  and the stable starts_at_utc instant' );
		$this->ok( false !== stripos( $create, 'timezone_name' ), '  and the zone that produced it' );
		$create_r = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$relations}`", ARRAY_N )[1] ?? '' );
		$this->ok( false !== stripos( $create_r, 'ENGINE=InnoDB' ), 'bingo_session_packages is InnoDB' );
		$this->ok( false === stripos( $create_r, 'FOREIGN KEY' ), 'bingo_session_packages has no physical foreign key' );

		// Malformed shapes must fail verification and must NOT advance the version.
		$shapes = [
			'missing session_date_display_name unique' =>
				"session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_date date NOT NULL, display_name varchar(191) NOT NULL, available_for_sale tinyint(1) unsigned NOT NULL DEFAULT 0, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (session_id), KEY status_sale_date (status,available_for_sale,session_date), KEY session_date (session_date)",
			'non-unique date/name key' =>
				"session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_date date NOT NULL, display_name varchar(191) NOT NULL, available_for_sale tinyint(1) unsigned NOT NULL DEFAULT 0, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (session_id), KEY session_date_display_name (session_date,display_name), KEY status_sale_date (status,available_for_sale,session_date), KEY session_date (session_date)",
			'missing available_for_sale column' =>
				"session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_date date NOT NULL, display_name varchar(191) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (session_id), UNIQUE KEY session_date_display_name (session_date,display_name), KEY status_sale_date (status,session_date), KEY session_date (session_date)",
			'wrong available_for_sale default' =>
				"session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_date date NOT NULL, display_name varchar(191) NOT NULL, available_for_sale tinyint(1) unsigned NOT NULL DEFAULT 1, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (session_id), UNIQUE KEY session_date_display_name (session_date,display_name), KEY status_sale_date (status,available_for_sale,session_date), KEY session_date (session_date)",
		];
		foreach ( $shapes as $label => $ddl ) {
			$this->disposable_ddl( $hall, "DROP TABLE IF EXISTS `{$sessions}`" );
			$this->disposable_ddl( $hall, "CREATE TABLE `{$sessions}` ( {$ddl} ) ENGINE=InnoDB" );
			delete_option( Migrations::VERSION_OPTION );
			$this->is_error( 'sessions_migration_verification_failed', Migrations::verify_sessions_schema(), "bingo_sessions with {$label} fails verification" );
			$this->same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), "  version not advanced ({$label})" );
		}

		$this->disposable_ddl( $hall, "DROP TABLE IF EXISTS `{$relations}`" );
		$this->disposable_ddl( $hall, "CREATE TABLE `{$relations}` ( session_package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_id bigint(20) unsigned NOT NULL, package_id bigint(20) unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (session_package_id), KEY session_package_unique (session_id,package_id), KEY package_session (package_id,session_id) ) ENGINE=InnoDB" );
		$this->is_error( 'sessions_migration_verification_failed', Migrations::verify_session_packages_schema(), 'a NON-UNIQUE (session_id, package_id) key fails verification' );

		// dbDelta() adds a missing index but cannot convert an existing non-unique
		// KEY into a UNIQUE KEY, so recovery from a wrong-uniqueness shape means
		// dropping the table first — which is what an operator would do, and what
		// the Catalog suite does for the same class of damage.
		$this->disposable_ddl( $hall, "DROP TABLE IF EXISTS `{$relations}`" );
		$this->disposable_ddl( $hall, "DROP TABLE IF EXISTS `{$sessions}`" );
		$this->ok( true === Migrations::migrate_current_site(), 'migrate recreates the dated Session tables and verifies all three Session tables' );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), 'version persisted only after every schema verified' );

		// Existing-hall self-heal through the registered admin_init path.
		update_option( Migrations::VERSION_OPTION, 0, false );
		$this->ok( true === Migrations::maybe_migrate_current_site(), 'a stale hall self-heals' );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), '  and reaches version 3' );
		restore_current_blog();

		$this->ok( false !== has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_current_site' ] ), 'admin_init self-heal is registered' );
		$this->ok( false !== has_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ] ), 'network_admin_menu sweep is registered' );
		$this->ok( false !== has_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ] ), 'wp_initialize_site provisioning is registered' );
		$this->ok( false === has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ] ), 'the network sweep is NOT on hall admin_init' );

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->ok( true === Migrations::verify_current_site_schema(), "persistent hall {$site_id} schema intact after the destructive group" );
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * B. Authorization
	 * ================================================================== */

	private function authorization_group(): void {
		$this->group( 'B. Authorization' );

		$manager  = $this->user_with_role( self::SUNRISE, Roles::HALL_MANAGER );
		$operator = $this->user_with_role( self::SUNRISE, Roles::GAME_OPERATOR );
		$this->ok( $manager > 0, 'Hall Manager fixture exists on Sunrise' );
		$this->ok( $operator > 0, 'Game Operator fixture exists on Sunrise' );

		switch_to_blog( self::SUNRISE );

		wp_set_current_user( $manager );
		$this->ok( user_can( $manager, 'bc_manage_sessions' ), 'Hall Manager holds bc_manage_sessions' );
		$this->ok( ! is_wp_error( ( new SessionService() )->listForAdministration() ), 'Hall Manager may manage Sessions' );

		wp_set_current_user( $operator );
		$this->ok( user_can( $operator, 'bc_view_sessions' ), 'Game Operator holds bc_view_sessions' );
		$this->ok( ! user_can( $operator, 'bc_manage_sessions' ), '  but NOT bc_manage_sessions' );
		$service = new SessionService();
		$this->is_error( 'session_management_denied', $service->listForAdministration(), 'Game Operator is denied the administration surface' );
		$this->is_error( 'session_management_denied', $service->create( '2030-01-01', 'X' ), '  and cannot create a Session' );
		$this->is_error( 'session_management_denied', $service->archive( 1 ), '  and cannot archive one' );
		$this->is_error( 'session_management_denied', $service->assignPackage( 1, 1 ), '  and cannot assign a Package' );

		wp_set_current_user( $this->ordinary_user() );
		$this->is_error( 'session_management_denied', ( new SessionService() )->listForAdministration(), 'an ordinary user is denied' );

		wp_set_current_user( 0 );
		$this->is_error( 'authentication_required', ( new SessionService() )->listForAdministration(), 'an anonymous request is denied' );

		wp_set_current_user( $this->super_admin_id );
		$this->ok( ! is_wp_error( ( new SessionService() )->listForAdministration() ), 'Super Admin overrides on the current hall' );
		restore_current_blog();

		// Main site is not a hall.
		switch_to_blog( 1 );
		wp_set_current_user( $this->super_admin_id );
		$this->is_error( 'invalid_hall_context', ( new SessionService() )->listForAdministration(), 'the network main site is not a hall' );
		restore_current_blog();

		wp_set_current_user( $this->super_admin_id );
	}

	/* ================================================================== *
	 * C. Hall isolation
	 * ================================================================== */

	private function isolation_group(): void {
		$this->group( 'C. Hall isolation' );

		$sunrise_session = $this->make_session( self::SUNRISE, '2030-03-05', 'Isolation Evening – 7:00 PM' );
		$sunrise_package = $this->make_package( self::SUNRISE, 'isolation' );
		$this->ok( $sunrise_session > 0 && $sunrise_package > 0, 'a Sunrise Session and Package exist for the isolation check' );

		switch_to_blog( self::SUNRISE );
		$assigned = ( new SessionService() )->assignPackage( $sunrise_session, $sunrise_package );
		$this->is_status( 'session_package_assigned', $assigned, 'the Sunrise Package is assigned to the Sunrise Session' );
		$this->track_relations( self::SUNRISE, $sunrise_session );
		restore_current_blog();

		switch_to_blog( self::TAMPA );
		wp_set_current_user( $this->user_with_role( self::TAMPA, Roles::HALL_MANAGER ) );
		$tampa = new SessionService();
		$this->is_error( 'session_not_found', $tampa->getById( $sunrise_session ), 'Tampa cannot read a Sunrise Session id' );
		$this->is_error( 'session_not_found', $tampa->archive( $sunrise_session ), 'Tampa cannot archive a Sunrise Session id' );
		$this->is_error( 'session_not_found', $tampa->rename( $sunrise_session, 'Forged' ), 'Tampa cannot rename a Sunrise Session id' );
		$this->is_error( 'session_not_found', $tampa->assignPackage( $sunrise_session, $sunrise_package ), 'Tampa cannot assign to a Sunrise Session id' );
		$ids = array_column( (array) $tampa->listForAdministration(), 'session_id' );
		$this->ok( ! in_array( $sunrise_session, $ids, true ), 'the Sunrise Session never appears in Tampa administration' );

		// A Tampa Session cannot borrow a Sunrise Package id.
		$tampa_session = $this->make_session( self::TAMPA, '2030-03-05', 'Tampa Isolation' );
		$this->is_error( 'package_not_found', $tampa->assignPackage( $tampa_session, $sunrise_package ), 'a Sunrise Package id does not resolve for a Tampa Session' );
		restore_current_blog();

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->super_admin_id );
		$still = ( new SessionService() )->getById( $sunrise_session );
		$this->same( 'active', is_array( $still ) ? $still['status'] : null, 'the Sunrise Session is untouched by every Tampa attempt' );
		$this->same( 1, count( $still['assignments'] ), '  and keeps its assignment' );
		restore_current_blog();

		wp_set_current_user( $this->super_admin_id );
	}

	/* ================================================================== *
	 * D. Create validation
	 * ================================================================== */

	private function create_validation_group( int $hall ): void {
		$this->group( 'D. Create validation' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$created = $service->create( '2030-04-10', '  Friday   Evening – 7:00 PM  ', true );
		$this->is_status( 'session_created', $created, 'a Session is created' );
		$this->track_session( $hall, (int) $created->session['session_id'] );
		$this->same( 'Friday Evening – 7:00 PM', $created->session['display_name'], '  the display name is whitespace-normalised, and its time text is preserved verbatim' );
		$this->same( '2030-04-10', $created->session['session_date'], '  the calendar date is stored literally' );
		$this->same( true, $created->session['available_for_sale'], '  Available for Sale is honoured' );
		$this->same( 'active', $created->session['status'], '  and it starts active' );
		$this->same( [], $created->warnings, '  with no settlement warnings' );

		$default = $service->create( '2030-04-11', 'Default Availability' );
		$this->is_status( 'session_created', $default, 'a Session created without the flag defaults OFF' );
		$this->track_session( $hall, (int) $default->session['session_id'] );
		$this->same( false, $default->session['available_for_sale'], '  available_for_sale defaults to OFF' );

		foreach ( [ '2030-4-1', '30-04-01', '2030/04/01', 'tomorrow', '', '2030-02-30', '2030-13-01' ] as $bad ) {
			$this->is_error( 'invalid_session_date', $service->create( $bad, 'Bad Date ' . $bad ), "date '{$bad}' is rejected" );
		}

		foreach ( [ '', '   ' ] as $bad ) {
			$this->is_error( 'invalid_display_name', $service->create( '2030-05-01', $bad ), 'an empty display name is rejected' );
		}
		$this->is_error( 'invalid_display_name', $service->create( '2030-05-01', str_repeat( 'a', 192 ) ), 'an over-long display name is rejected' );

		// UNIQUE (session_date, display_name).
		$this->is_error( 'duplicate_session', $service->create( '2030-04-10', 'Friday Evening – 7:00 PM' ), 'the same date and display name is rejected' );
		$this->is_error( 'duplicate_session', $service->create( '2030-04-10', '  Friday Evening – 7:00 PM ' ), '  including after normalisation' );
		$same_name_other_date = $service->create( '2030-04-12', 'Friday Evening – 7:00 PM' );
		$this->is_status( 'session_created', $same_name_other_date, 'the same display name on a DIFFERENT date is allowed' );
		$this->track_session( $hall, (int) $same_name_other_date->session['session_id'] );

		// Reads.
		$fetched = $service->getById( (int) $created->session['session_id'] );
		$this->same( '2030-04-10', is_array( $fetched ) ? $fetched['session_date'] : null, 'getById returns the Session' );
		$this->is_error( 'session_not_found', $service->getById( 987654321 ), 'an unknown Session id is not found' );

		$listed = $service->listForAdministration( 'all' );
		$this->ok( is_array( $listed ) && count( $listed ) >= 3, 'listForAdministration returns this hall\'s Sessions' );
		$this->ok( is_array( $service->listForAdministration( 'active' ) ), '  and filters by status' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * E. Rename / reschedule / availability
	 * ================================================================== */

	private function edit_group( int $hall ): void {
		$this->group( 'E. Edit' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$id = $this->make_session( $hall, '2030-06-01', 'Edit Target' );

		$this->is_status( 'session_renamed', $service->rename( $id, 'Edit Target – 8:00 PM' ), 'rename succeeds' );
		$this->same( 'Edit Target – 8:00 PM', $service->getById( $id )['display_name'], '  and is stored' );
		$this->is_status( 'session_name_unchanged', $service->rename( $id, 'Edit Target – 8:00 PM' ), 'renaming to the same value is a verified no-op' );
		$this->is_error( 'invalid_display_name', $service->rename( $id, '' ), 'renaming to empty is rejected' );

		$this->is_status( 'session_rescheduled', $service->reschedule( $id, '2030-06-02' ), 'reschedule succeeds' );
		$this->same( '2030-06-02', $service->getById( $id )['session_date'], '  and is stored' );
		$this->is_status( 'session_date_unchanged', $service->reschedule( $id, '2030-06-02' ), 'rescheduling to the same date is a verified no-op' );
		$this->is_error( 'invalid_session_date', $service->reschedule( $id, '2030-06-31' ), 'rescheduling to an unreal date is rejected' );

		$this->is_status( 'session_availability_changed', $service->setAvailableForSale( $id, true ), 'availability ON succeeds' );
		$this->same( true, $service->getById( $id )['available_for_sale'], '  and is stored' );
		$this->is_status( 'session_availability_unchanged', $service->setAvailableForSale( $id, true ), 'setting the same value is a verified no-op' );
		$this->is_status( 'session_availability_changed', $service->setAvailableForSale( $id, false ), 'availability OFF succeeds' );

		// Renaming into an existing (date, name) pair collides.
		$other = $this->make_session( $hall, '2030-06-02', 'Occupied Name' );
		$occupied = $service->getById( $other )['display_name'];
		$this->is_error( 'duplicate_session', $service->rename( $id, $occupied ), 'renaming into an existing date/name pair is rejected' );
		$this->same( 'Edit Target – 8:00 PM', $service->getById( $id )['display_name'], '  and nothing was written' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * F. Lifecycle
	 * ================================================================== */

	private function lifecycle_group( int $hall ): void {
		global $wpdb;
		$this->group( 'F. Lifecycle' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$id      = $this->make_session( $hall, '2030-07-01', 'Lifecycle Session – 6:30 PM' );
		$package = $this->make_package( $hall, 'lifecycle' );
		$this->is_status( 'session_package_assigned', $service->assignPackage( $id, $package ), 'a Package is assigned before archiving' );
		$this->track_relations( $hall, $id );

		$before = $service->getById( $id );

		$archived = $service->archive( $id );
		$this->is_status( 'session_archived', $archived, 'archive moves active -> archived' );
		$row = $service->getById( $id );
		$this->same( 'archived', $row['status'], '  status is archived' );
		$this->ok( null !== $row['archived_at'], '  archived_at is set' );
		$this->same( $before['session_id'], $row['session_id'], '  session_id preserved' );
		$this->same( $before['session_date'], $row['session_date'], '  session_date preserved' );
		$this->same( $before['display_name'], $row['display_name'], '  display_name preserved' );
		$this->same( $before['assignments'], $row['assignments'], '  every Package assignment preserved' );

		$this->is_status( 'session_already_archived', $service->archive( $id ), 'archiving an archived Session is a verified no-op' );

		// Everything is refused while archived.
		$this->is_error( 'session_not_active', $service->rename( $id, 'Renamed While Archived' ), 'rename is refused while archived' );
		$this->is_error( 'session_not_active', $service->reschedule( $id, '2030-07-02' ), 'reschedule is refused while archived' );
		$this->is_error( 'session_not_active', $service->setAvailableForSale( $id, true ), 'availability mutation is refused while archived' );
		$this->is_error( 'session_not_active', $service->assignPackage( $id, $this->make_package( $hall, 'lifecycle2' ) ), 'assignment is refused while archived' );
		$this->is_error( 'session_not_active', $service->removePackage( $id, $package ), 'removal is refused while archived' );
		$this->same( $before['assignments'], $service->getById( $id )['assignments'], '  and no assignment changed' );

		$restored = $service->restore( $id );
		$this->is_status( 'session_restored', $restored, 'restore moves archived -> active' );
		$final = $service->getById( $id );
		$this->same( 'active', $final['status'], '  status is active' );
		$this->same( null, $final['archived_at'], '  archived_at is cleared' );
		$this->same( $before['session_id'], $final['session_id'], '  identity preserved — restore creates no new Session' );
		$this->same( $before['assignments'], $final['assignments'], '  assignments returned unchanged' );
		$this->is_status( 'session_already_active', $service->restore( $id ), 'restoring an active Session is a verified no-op' );

		// Corrupt lifecycle fails closed.
		$table = Migrations::sessions_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'suspended' WHERE session_id = %d", $id ) );
		$this->is_error( 'session_state_inconsistent', $service->archive( $id ), 'an unrecognised lifecycle value fails closed on archive' );
		$this->is_error( 'session_state_inconsistent', $service->restore( $id ), '  and on restore' );
		$this->is_error( 'session_state_inconsistent', $service->rename( $id, 'X' ), '  and on rename' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active' WHERE session_id = %d", $id ) );

		$this->ok( ! method_exists( SessionService::class, 'delete' ) && ! method_exists( SessionService::class, 'hardDelete' ), 'no Session hard-delete entry point exists' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * G. Package assignment
	 * ================================================================== */

	private function assignment_group( int $hall ): void {
		global $wpdb;
		$this->group( 'G. Package assignment' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service  = new SessionService();
		$packages = new PackageService();
		$play     = new BingoPlayProductService();

		$session = $this->make_session( $hall, '2030-08-01', 'Assignment Session' );
		$package = $this->make_package( $hall, 'assign' );

		$assigned = $service->assignPackage( $session, $package );
		$this->is_status( 'session_package_assigned', $assigned, 'an active Package is assigned' );
		$this->track_relations( $hall, $session );
		$this->same( [ $package ], $service->getById( $session )['assigned_package_ids'], '  and appears in the Session read' );

		// Duplicate is a verified no-op resolved by re-read, not a second row.
		$this->is_status( 'session_package_already_assigned', $service->assignPackage( $session, $package ), 'a duplicate assignment is a verified no-op' );
		$this->same( 1, $this->relation_count( $session ), '  and creates no second relationship row' );
		$this->same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d AND package_id = %d', $session, $package ) ), '  the UNIQUE constraint held' );

		// Rejections.
		$this->is_error( 'invalid_package_id', $service->assignPackage( $session, 0 ), 'a zero Package id is rejected' );
		$this->is_error( 'invalid_package_id', $service->assignPackage( $session, -7 ), 'a negative Package id is rejected, not absolute-valued' );
		$this->is_error( 'package_not_found', $service->assignPackage( $session, 987654321 ), 'an unknown Package is rejected' );

		$archived_package = $this->make_package( $hall, 'archivedpkg' );
		$this->ok( ! is_wp_error( $packages->archive( $archived_package ) ), 'a Package is archived for the rejection check' );
		$this->is_error( 'package_not_active', $service->assignPackage( $session, $archived_package ), 'an ARCHIVED Package cannot be newly assigned' );

		// A Package containing an archived COMPONENT is still assignable.
		$gt        = $this->make_game_type( $hall, 'AssignGT' );
		$keep      = $this->make_play_product( $hall, $play, 'keepComp', 'paper', 2 );
		$archivedc = $this->make_play_product( $hall, $play, 'archComp', 'digital', 3, $gt );
		$mixed     = $this->make_package_from( $hall, 'mixed', [ [ $keep, 1 ], [ $archivedc, 2 ] ] );
		$this->ok( ! is_wp_error( $play->archive( $archivedc ) ), 'one component Play Product is archived' );
		$mixed_row = $packages->getById( $mixed );
		$this->same( true, $mixed_row['has_archived_component'], '  the Package reports an archived component' );
		$this->same( false, $mixed_row['has_unresolved_component'], '  and no integrity fault' );
		$this->is_status( 'session_package_assigned', $service->assignPackage( $session, $mixed ), 'an ACTIVE Package containing an archived component IS assignable' );
		$this->track_relations( $hall, $session );

		// An unresolved component is an integrity fault and blocks assignment.
		$vanishing = $this->make_play_product( $hall, $play, 'vanishComp', 'paper', 1 );
		$broken    = $this->make_package_from( $hall, 'broken', [ [ $keep, 1 ], [ $vanishing, 1 ] ] );
		$wpdb->delete( \BingoCommerce\Catalog\Migrations::play_products_table(), [ 'bingo_play_product_id' => $vanishing ], [ '%d' ] );
		$this->same( true, $packages->getById( $broken )['has_unresolved_component'], 'the Package now reports an integrity fault' );
		$this->is_error( 'package_integrity_fault', $service->assignPackage( $session, $broken ), 'a Package with an unresolved component cannot be assigned' );

		// Corrupt Package lifecycle fails closed.
		$corrupt = $this->make_package( $hall, 'corruptpkg' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \BingoCommerce\Catalog\Migrations::packages_table() . " SET status = 'suspended' WHERE package_id = %d", $corrupt ) );
		$this->is_error( 'package_state_inconsistent', $service->assignPackage( $session, $corrupt ), 'a Package with an unrecognised lifecycle value cannot be assigned' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \BingoCommerce\Catalog\Migrations::packages_table() . " SET status = 'active' WHERE package_id = %d", $corrupt ) );

		// Assignments survive a later Package archive.
		$survivor = $this->make_package( $hall, 'survivor' );
		$this->is_status( 'session_package_assigned', $service->assignPackage( $session, $survivor ), 'a Package is assigned before it is archived' );
		$this->track_relations( $hall, $session );
		$this->ok( ! is_wp_error( $packages->archive( $survivor ) ), 'that Package is then archived' );
		$this->ok( in_array( $survivor, $service->getById( $session )['assigned_package_ids'], true ), '  the assignment SURVIVES the Package archive — no silent detach' );
		$this->ok( ! is_wp_error( $packages->restore( $survivor ) ), 'the Package is restored' );
		$this->ok( in_array( $survivor, $service->getById( $session )['assigned_package_ids'], true ), '  and the relationship needs no recreation' );

		// Removal.
		$this->is_status( 'session_package_removed', $service->removePackage( $session, $survivor ), 'a Package is removed' );
		$this->ok( ! in_array( $survivor, $service->getById( $session )['assigned_package_ids'], true ), '  and is gone' );
		$this->is_status( 'session_package_not_assigned', $service->removePackage( $session, $survivor ), 'removing an unassigned Package is a verified no-op' );

		// assignablePackages excludes assigned, archived and faulted Packages.
		$assignable = $service->assignablePackages( $session );
		$this->ok( is_array( $assignable ), 'assignablePackages returns a list' );
		$ids = array_column( (array) $assignable, 'package_id' );
		$this->ok( ! in_array( $package, $ids, true ), '  already-assigned Packages are excluded' );
		$this->ok( ! in_array( $archived_package, $ids, true ), '  archived Packages are excluded' );
		$this->ok( ! in_array( $broken, $ids, true ), '  integrity-faulted Packages are excluded' );
		$this->ok( in_array( $survivor, $ids, true ), '  and a valid unassigned Package is offered' );

		// Sessions never touch Catalog tables directly.
		$service_source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionService.php' );
		$this->ok( false === strpos( $service_source, 'Catalog\\Migrations' ), 'SessionService never references Catalog\\Migrations at all' );
		$this->ok( false === strpos( $service_source, 'Migrations::packages_table' ), 'SessionService never reads the Catalog packages table' );
		$this->ok( false === strpos( $service_source, 'package_components_table' ), 'SessionService never reads the Catalog components table' );
		$this->ok( 1 === preg_match( '/Migrations::session(s|_packages)_table/', $service_source ), '  it uses only its own Session tables' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * H. Sales window (BR-051 / BR-063)
	 * ================================================================== */

	private function sales_window_group( int $hall ): void {
		$this->group( 'H. Sales window' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$today = SessionService::hall_local_today();
		$this->ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ), 'hall_local_today is a calendar date' );

		// The window is expressed around the SESSION DATE D:
		//     D - 30 <= hall_local_today <= D
		// so a Session dated N days from today is on sale when 0 <= N <= 30.
		// 30, not 5: the window widened from 5 to 30 calendar days, and every
		// numeric case below is the corrected boundary, not the old one.
		$cases = [
			-1 => [ false, 'dated YESTERDAY: hall_local_today is D+1, past the inclusive end' ],
			0  => [ true,  'dated TODAY: hall_local_today is exactly D' ],
			1  => [ true,  'dated tomorrow: inside the window' ],
			29 => [ true,  'dated D where today is D-29: inside the window' ],
			30 => [ true,  'dated D where today is exactly D-30: the inclusive opening boundary' ],
			31 => [ false, 'dated D where today is D-31: one day before the window opens' ],
			60 => [ false, 'dated far in the future: outside the window' ],
		];
		foreach ( $cases as $offset => [ $expected, $description ] ) {
			$date = ( new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) ) )->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
			$id   = $this->make_session( $hall, $date, sprintf( 'Window %+d', $offset ), true );
			$this->same( $expected, $service->isAvailableForSaleToday( $id ), sprintf( '%s => %s', $description, $expected ? 'ON SALE' : 'not on sale' ) );
			$this->same( $expected, $service->getById( $id )['available_for_sale_today'], sprintf( '  and the decorated read agrees (%+d days)', $offset ) );
		}

		// Available for Sale OFF inside the window.
		$inside = ( new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) ) )->modify( '+2 days' )->format( 'Y-m-d' );
		$off    = $this->make_session( $hall, $inside, 'Window Off', false );
		$this->same( false, $service->isAvailableForSaleToday( $off ), 'inside the window but Available for Sale OFF => not on sale' );
		$this->is_status( 'session_availability_changed', $service->setAvailableForSale( $off, true ), 'turning it ON' );
		$this->same( true, $service->isAvailableForSaleToday( $off ), '  makes it on sale' );

		// ON does not bypass the window. +60 days is unambiguously outside a
		// 30-day window, unlike the old test's +30 — which used to be well
		// past a 5-day window but would now be the boundary itself.
		$far = $this->make_session( $hall, ( new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) ) )->modify( '+60 days' )->format( 'Y-m-d' ), 'Window Far', true );
		$this->same( false, $service->isAvailableForSaleToday( $far ), 'Available for Sale ON does NOT bypass the 30-day window' );

		// Archived is never on sale.
		$this->is_status( 'session_archived', $service->archive( $off ), 'the in-window Session is archived' );
		$this->same( false, $service->isAvailableForSaleToday( $off ), 'an archived Session is never on sale' );
		$this->is_status( 'session_restored', $service->restore( $off ), '  and restoring brings it back' );
		$this->same( true, $service->isAvailableForSaleToday( $off ), '  on sale again' );

		// Corrupt dates fail closed rather than normalising into a different day.
		$corrupt_dates = [ '2030-02-30', '0000-00-00', '0000-01-01', '2030-13-01', '2030-1-1', '2030-01-1', '', 'tomorrow', '2030-00-01', '2030-01-00' ];
		foreach ( $corrupt_dates as $bad ) {
			$this->same(
				false,
				SessionService::evaluate_available_today( [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => $bad ], '2030-09-05' ),
				"a corrupt stored session_date '{$bad}' is never on sale"
			);
		}
		$this->same( false, SessionService::evaluate_available_today( [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => '2030-09-10' ], '2030-02-30' ), 'a corrupt hall_local_today is never on sale' );
		$this->same( false, SessionService::evaluate_available_today( [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => '2030-09-10' ], '0000-00-00' ), '  including 0000-00-00' );

		// Read-time validity equals write-time validity.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$writer = new SessionService();
		foreach ( [ '2030-02-30', '0000-00-00', '0000-01-01', '2030-13-01', '2030-1-1' ] as $bad ) {
			$this->is_error( 'invalid_session_date', $writer->create( $bad, 'Write Validity ' . $bad ), "the writer rejects '{$bad}' exactly as the evaluator does" );
		}
		restore_current_blog();
		switch_to_blog( $hall );

		// The supported sales-window helper, used by presentation.
		$this->same( '2030-08-11', SessionService::salesWindowStartDate( '2030-09-10' ), 'salesWindowStartDate returns D-30 for a valid date' );
		foreach ( [ '2030-02-30', '0000-00-00', '0000-01-01', '2030-13-01', 'tomorrow', '' ] as $bad ) {
			$this->same( null, SessionService::salesWindowStartDate( $bad ), "salesWindowStartDate returns null for corrupt '{$bad}'" );
		}

		// Presentation: a corrupt stored date must not throw or normalise, and an
		// unknown lifecycle value must never be described as Archived.
		$explain = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'window_explanation' );
		$explain->setAccessible( true );
		$label = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'status_label' );
		$label->setAccessible( true );

		$corrupt_row = [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => '2030-02-30' ];
		$explained   = (string) $explain->invoke( null, $corrupt_row );
		$this->ok( false !== strpos( $explained, 'Session date is invalid' ), 'a corrupt stored Session date renders a fail-closed message' );
		$this->ok( false === strpos( $explained, '2030-03-02' ), '  and is never normalised into a different date' );
		$this->ok( false === strpos( $explained, 'Sales window' ), '  and never shows a plausible-looking window' );

		$unknown = (string) $explain->invoke( null, [ 'status' => 'suspended', 'available_for_sale' => true, 'session_date' => '2030-09-10' ] );
		$this->ok( false !== strpos( $unknown, 'unrecognised lifecycle value' ), 'an unknown status renders an inconsistent-state explanation' );
		$this->ok( false === strpos( $unknown, 'Archived Sessions are never on sale' ), '  and is NEVER described as Archived' );
		$this->same( 'Inconsistent state', (string) $label->invoke( null, 'suspended' ), 'an unknown status is labelled Inconsistent state, not Archived' );
		$this->same( 'Archived', (string) $label->invoke( null, 'archived' ), 'a genuinely archived Session is labelled Archived' );
		$this->same( 'Active', (string) $label->invoke( null, 'active' ), 'an active Session is labelled Active' );

		$archived_explained = (string) $explain->invoke( null, [ 'status' => 'archived', 'available_for_sale' => true, 'session_date' => '2030-09-10' ] );
		$this->ok( false !== strpos( $archived_explained, 'Archived Sessions are never on sale' ), 'an archived Session explains itself as archived' );
		$window_explained = (string) $explain->invoke( null, [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => '2030-09-10' ] );
		$this->ok( false !== strpos( $window_explained, 'Sales window 08/11/2030 to 09/10/2030' ), 'sales-window start and Session date render as MM/DD/YYYY' );
		$this->ok( 1 === preg_match( '/hall-local today is [0-9]{2}\/[0-9]{2}\/[0-9]{4}\./', $window_explained ), 'sales-window hall-local today renders as MM/DD/YYYY' );

		$admin_source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionAdmin.php' );
		$this->ok( false === strpos( $admin_source, 'DateTimeImmutable' ), 'SessionAdmin never constructs a date from an authoritative stored value' );
		$this->ok( false === strpos( $admin_source, 'strtotime(' ), 'SessionAdmin never uses strtotime() for date input or display' );

		/*
		 * The pure evaluator is timezone-driven, not server-driven. Every date
		 * below is fixed and computed by hand (2030-09-10 minus 29/30/31
		 * days), so this proves the exact 30-day boundary without depending on
		 * wall-clock "today" at all — the required-boundary assertion the
		 * sales-window change is verified against.
		 */
		$row = [ 'status' => 'active', 'available_for_sale' => true, 'session_date' => '2030-09-10' ];
		$this->same( true, SessionService::evaluate_available_today( $row, '2030-08-12' ), '29 days before D: inside the window' );
		$this->same( true, SessionService::evaluate_available_today( $row, '2030-08-11' ), 'boundary D-30 is inclusive: the window opens' );
		$this->same( false, SessionService::evaluate_available_today( $row, '2030-08-10' ), 'D-31 is outside: one day before the window opens' );
		$this->same( true, SessionService::evaluate_available_today( $row, '2030-09-10' ), 'boundary D is inclusive' );
		$this->same( false, SessionService::evaluate_available_today( $row, '2030-09-11' ), 'D+1 is outside' );

		// A hall whose timezone puts "today" on a different calendar date than UTC
		// resolves against the HALL date.
		$original = get_option( 'timezone_string' );
		$original_offset = get_option( 'gmt_offset' );
		update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14
		$kiritimati = SessionService::hall_local_today();
		update_option( 'timezone_string', 'Pacific/Niue' ); // UTC-11
		$niue = SessionService::hall_local_today();
		update_option( 'timezone_string', is_string( $original ) ? $original : '' );
		if ( ! is_string( $original ) || '' === $original ) {
			update_option( 'gmt_offset', $original_offset );
		}
		$this->ok( $kiritimati >= $niue, 'hall_local_today follows the hall timezone, not the server' );
		$this->note( "timezone-sensitive hall-local dates: UTC+14 => {$kiritimati}, UTC-11 => {$niue}" );

		// No display-name time is ever parsed.
		$named = $this->make_session( $hall, ( new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) ) )->modify( '+3 days' )->format( 'Y-m-d' ), 'Late Night – 11:59 PM', true );
		$this->same( true, $service->isAvailableForSaleToday( $named ), 'a display-name time never affects eligibility' );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionService.php' );
		$this->ok( false === strpos( $source, 'strtotime( $row[\'display_name\']' ), 'display_name is never parsed as a time (source guard)' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * I. Audit
	 * ================================================================== */

	private function audit_group( int $hall ): void {
		$this->group( 'I. Audit' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$before = $this->audit_count( $hall );
		$id     = $this->make_session( $hall, '2030-10-01', 'Audit Session' );
		$this->same( $before + 1, $this->audit_count( $hall ), 'create emits exactly one audit event' );
		$this->same( 'sessions.session.created', $this->last_audit_action( $hall, $id ), '  named sessions.session.created' );
		$this->same( 'bingo_session', $this->last_audit_target_type( $hall, $id ), '  with target_type bingo_session' );

		$service->rename( $id, 'Audit Session – 9:00 PM' );
		$this->same( 'sessions.session.renamed', $this->last_audit_action( $hall, $id ), 'rename emits sessions.session.renamed' );
		$service->reschedule( $id, '2030-10-02' );
		$this->same( 'sessions.session.rescheduled', $this->last_audit_action( $hall, $id ), 'reschedule emits sessions.session.rescheduled' );
		$service->setAvailableForSale( $id, true );
		$this->same( 'sessions.session.availability_changed', $this->last_audit_action( $hall, $id ), 'availability emits sessions.session.availability_changed' );

		$package = $this->make_package( $hall, 'audit' );
		$service->assignPackage( $id, $package );
		$this->track_relations( $hall, $id );
		$this->same( 'sessions.session.package_assigned', $this->last_audit_action( $hall, $id ), 'assignment emits sessions.session.package_assigned' );
		$context = json_decode( (string) $this->last_audit_context( $hall, $id ), true );
		$this->same( $package, (int) ( $context['package_id'] ?? 0 ), '  and its context carries package_id' );
		$this->ok( ! array_key_exists( 'session_id', (array) $context ), '  and NEVER session_id, which AuditLog rejects as sensitive' );

		$service->removePackage( $id, $package );
		$this->same( 'sessions.session.package_removed', $this->last_audit_action( $hall, $id ), 'removal emits sessions.session.package_removed' );

		$service->archive( $id );
		$this->same( 'sessions.session.archived', $this->last_audit_action( $hall, $id ), 'archive emits sessions.session.archived' );
		$service->restore( $id );
		$this->same( 'sessions.session.restored', $this->last_audit_action( $hall, $id ), 'restore emits sessions.session.restored' );

		// No-ops emit nothing.
		$count = $this->audit_count( $hall );
		$service->rename( $id, 'Audit Session – 9:00 PM' );
		$service->setAvailableForSale( $id, true );
		$service->restore( $id );
		$service->removePackage( $id, $package );
		$this->same( $count, $this->audit_count( $hall ), 'verified no-ops emit NO audit event' );

		// No ADR-025 identity.
		$this->same( null, $this->last_audit_operation_id( $hall, $id ), 'Session audit events carry no operation_id' );

		// A failing audit recorder leaves the mutation committed and warns.
		$failing = new SessionService(
			static fn( array $event ) => new WP_Error( 'forced_audit_failure', 'forced' ),
			null
		);
		$outcome = $failing->rename( $id, 'Audit Session – 10:00 PM' );
		$this->is_status( 'session_renamed', $outcome, 'a failed audit still returns success for the committed mutation' );
		$this->same( 'audit_write_failed', $outcome->warnings[0]['code'] ?? '', '  with an audit_write_failed warning' );
		$this->same( 'Audit Session – 10:00 PM', $service->getById( $id )['display_name'], '  and the mutation is durably committed' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * J. Dependency behaviour
	 * ================================================================== */

	private function dependency_group(): void {
		$this->group( 'J. Dependency' );

		$this->ok( PackageGateway::is_available(), 'the Catalog PackageService dependency is present in this run' );
		$this->same( [], \BingoCommerce\Sessions\Plugin::missing_dependencies(), 'no dependency is reported missing' );

		// The gateway's eligibility contract, exercised directly against a
		// realistic supported-read shape.
		$base = [
			'package_id'   => 5,
			'status'       => 'active',
			'package_type' => 'paper',
			'woo_state'    => 'ok',
			'components'   => [
				[ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'active' ],
			],
		];
		$this->ok( is_wp_error( PackageGateway::assert_assignable( array_merge( $base, [ 'status' => 'archived' ] ) ) ), 'an archived Package is not assignable' );
		$this->ok( is_wp_error( PackageGateway::assert_assignable( array_merge( $base, [ 'status' => 'suspended' ] ) ) ), 'an unrecognised Package status is not assignable' );
		$this->ok( is_wp_error( PackageGateway::assert_assignable( array_merge( $base, [ 'has_unresolved_component' => true ] ) ) ), 'an integrity-faulted Package is not assignable' );
		$this->ok( is_wp_error( PackageGateway::assert_assignable( array_merge( $base, [ 'components' => [] ] ) ) ), 'a zero-component Package is not assignable' );
		$this->ok( is_wp_error( PackageGateway::assert_assignable( array_merge( $base, [ 'woo_state' => 'missing' ] ) ) ), 'a Package whose Woo product is missing is not assignable' );
		$this->same(
			true,
			PackageGateway::assert_assignable( array_merge( $base, [
				'has_archived_component' => true,
				'components'             => [ [ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'archived' ] ],
			] ) ),
			'an active Package with an ARCHIVED COMPONENT IS assignable'
		);

		// Deep component/type integrity, from supported-read data only.
		$valid = [
			'package_id'   => 5,
			'status'       => 'active',
			'package_type' => 'paper',
			'woo_state'    => 'ok',
			'components'   => [
				[ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'active' ],
			],
		];
		$this->same( true, PackageGateway::assert_assignable( $valid ), 'a fully valid Paper Package is assignable' );

		$corrupt = [
			'invalid medium'                => [ 'components' => [ [ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'holographic', 'play_product_status' => 'active' ] ] ],
			'null medium'                   => [ 'components' => [ [ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => null, 'play_product_status' => 'active' ] ] ],
			'invalid component status'      => [ 'components' => [ [ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'suspended' ] ] ],
			'zero quantity'                 => [ 'components' => [ [ 'bingo_play_product_id' => 1, 'quantity' => 0, 'medium' => 'paper', 'play_product_status' => 'active' ] ] ],
			'negative quantity'             => [ 'components' => [ [ 'bingo_play_product_id' => 1, 'quantity' => -3, 'medium' => 'paper', 'play_product_status' => 'active' ] ] ],
			'invalid play product id'       => [ 'components' => [ [ 'bingo_play_product_id' => 0, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'active' ] ] ],
			'duplicate play product'        => [ 'components' => [
				[ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'active' ],
				[ 'bingo_play_product_id' => 1, 'quantity' => 3, 'medium' => 'paper', 'play_product_status' => 'active' ],
			] ],
			'invalid package_type'          => [ 'package_type' => 'combo' ],
			'missing package_id'            => [ 'package_id' => 0 ],
			'empty components'              => [ 'components' => [] ],
		];
		foreach ( $corrupt as $label => $override ) {
			$package = array_merge( $valid, $override );
			$this->ok( is_wp_error( PackageGateway::assert_assignable( $package ) ), "corrupt Package rejected: {$label}" );
		}

		// package_type must agree with the derived composition.
		$mismatch = array_merge( $valid, [ 'package_type' => 'digital' ] );
		$this->is_error( 'package_integrity_fault', PackageGateway::assert_assignable( $mismatch ), 'a package_type that disagrees with its composition fails closed' );
		$hybrid_mismatch = array_merge( $valid, [
			'package_type' => 'paper',
			'components'   => [
				[ 'bingo_play_product_id' => 1, 'quantity' => 1, 'medium' => 'paper', 'play_product_status' => 'active' ],
				[ 'bingo_play_product_id' => 2, 'quantity' => 1, 'medium' => 'digital', 'play_product_status' => 'active' ],
			],
		] );
		$this->is_error( 'package_integrity_fault', PackageGateway::assert_assignable( $hybrid_mismatch ), 'a mixed composition stored as paper fails closed' );
		$this->same( true, PackageGateway::assert_assignable( array_merge( $hybrid_mismatch, [ 'package_type' => 'hybrid' ] ) ), '  and is assignable once the type agrees' );

		// An ARCHIVED component is still allowed.
		$archived_component = array_merge( $valid, [
			'has_archived_component' => true,
			'components'             => [ [ 'bingo_play_product_id' => 1, 'quantity' => 2, 'medium' => 'paper', 'play_product_status' => 'archived' ] ],
		] );
		$this->same( true, PackageGateway::assert_assignable( $archived_component ), 'an ARCHIVED component is still assignable' );

		// A dependency failure is never "no Packages, therefore fine".
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/PackageGateway.php' );
		$this->ok( false !== strpos( $source, 'return $rows;' ), 'the gateway propagates a Catalog read error rather than returning an empty list' );
		$this->ok( false !== strpos( $source, 'catalog_unavailable' ), 'an absent Catalog plugin fails closed with catalog_unavailable' );

		// Admin must not swallow Package read failures.
		$admin = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionAdmin.php' );
		$this->ok( false !== strpos( $admin, '$assignable_error' ), 'the edit screen retains an assignablePackages() WP_Error' );
		$this->ok( false !== strpos( $admin, 'This is NOT the same as having none available' ), '  and never shows "no Packages available" when the read failed' );
		$this->ok( false !== strpos( $admin, "'package_not_found' === \$package->get_error_code()" ), 'only package_not_found degrades to an UNRESOLVED assigned Package' );
		$this->ok( false !== strpos( $admin, 'return $package;' ), '  and any other Package read error propagates as WP_Error' );

		// Bootstrap timing: dependency verification is deferred to plugins_loaded.
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/Plugin.php' );
		$this->ok( false !== strpos( $plugin, "add_action( 'plugins_loaded'" ), 'dependency verification runs on plugins_loaded, not at include time' );
	}

	/* ================================================================== *
	 * K. Combined atomic update
	 * ================================================================== */

	private function combined_update_group( int $hall ): void {
		$this->group( 'K. Combined update' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();

		$id     = $this->make_session( $hall, '2030-11-01', 'Combined Target', false );
		$before = $service->getById( $id );

		// One mutation applies all three fields.
		$updated = $service->updateDetails( $id, '2030-11-05', self::PREFIX . ' Combined Renamed – 8:00 PM', true );
		$this->is_status( 'session_updated', $updated, 'a combined date/name/availability edit succeeds as ONE mutation' );
		$after = $service->getById( $id );
		$this->same( '2030-11-05', $after['session_date'], '  the date changed' );
		$this->same( self::PREFIX . ' Combined Renamed – 8:00 PM', $after['display_name'], '  the display name changed' );
		$this->same( true, $after['available_for_sale'], '  and availability changed' );
		$this->same( 'sessions.session.updated', $this->last_audit_action( $hall, $id ), '  emitting sessions.session.updated' );

		// Exact no-op.
		$count = $this->audit_count( $hall );
		$noop  = $service->updateDetails( $id, '2030-11-05', self::PREFIX . ' Combined Renamed – 8:00 PM', true );
		$this->is_status( 'session_unchanged', $noop, 'an exact combined no-op reports unchanged' );
		$this->same( $count, $this->audit_count( $hall ), '  and emits NO mutation audit' );

		// Duplicate target: nothing at all changes.
		$occupied     = $this->make_session( $hall, '2030-11-09', 'Combined Occupied' );
		$occupied_row = $service->getById( $occupied );
		$snapshot     = $service->getById( $id );
		$clash        = $service->updateDetails( $id, $occupied_row['session_date'], $occupied_row['display_name'], false );
		$this->is_error( 'duplicate_session', $clash, 'a combined edit into an existing date/name pair is rejected' );
		$current = $service->getById( $id );
		$this->same( $snapshot['session_date'], $current['session_date'], '  the date was NOT changed' );
		$this->same( $snapshot['display_name'], $current['display_name'], '  the display name was NOT changed' );
		$this->same( $snapshot['available_for_sale'], $current['available_for_sale'], '  and availability was NOT changed — no partial save' );

		// Validation happens before any DML.
		$this->is_error( 'invalid_session_date', $service->updateDetails( $id, '2030-02-30', 'Valid Name', false ), 'an unreal date is rejected' );
		$this->same( $snapshot['display_name'], $service->getById( $id )['display_name'], '  with nothing written' );
		$this->is_error( 'invalid_display_name', $service->updateDetails( $id, '2030-11-06', '', false ), 'an empty display name is rejected' );
		$this->same( $snapshot['session_date'], $service->getById( $id )['session_date'], '  with nothing written' );

		// Archived Session: nothing changes.
		$this->is_status( 'session_archived', $service->archive( $id ), 'the Session is archived' );
		$archived_snapshot = $service->getById( $id );
		$this->is_error( 'session_not_active', $service->updateDetails( $id, '2030-12-25', self::PREFIX . ' Archived Edit', true ), 'a combined edit is refused while archived' );
		$after_archived = $service->getById( $id );
		$this->same( $archived_snapshot['session_date'], $after_archived['session_date'], '  the date was NOT changed' );
		$this->same( $archived_snapshot['display_name'], $after_archived['display_name'], '  the display name was NOT changed' );
		$this->same( $archived_snapshot['available_for_sale'], $after_archived['available_for_sale'], '  and availability was NOT changed' );

		// The admin form routes through the single writer, not the chain.
		$admin = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionAdmin.php' );
		$this->ok( false !== strpos( $admin, '$service->updateDetails(' ), 'the combined Save form calls updateDetails()' );
		$this->ok( false === strpos( $admin, 'handle_update' ), '  and the chained rename/reschedule/availability helper is gone' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * L. Ownership safety
	 * ================================================================== */

	/**
	 * Plant a FOREIGN run token and a FOREIGN marked product, then let the real
	 * cleanup path run. Both must survive byte-for-byte while this run's own
	 * artifacts are removed.
	 */
	private function plant_foreign_ownership(): void {
		$this->group( 'L. Ownership safety' );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->super_admin_id );

		// The durable registry may ALREADY hold evidence from a real interrupted
		// run. Snapshot every foreign entry byte-for-byte first, so this scenario
		// proves preservation against reality rather than assuming an empty start.
		$runs = $this->read_all_runs( self::SUNRISE );
		unset( $runs[ $this->run_token ] );
		$this->preexisting_runs = $runs;
		if ( [] !== $this->preexisting_runs ) {
			$this->note( sprintf(
				'site %d: %d pre-existing foreign ownership entr(ies) were present before this scenario: %s',
				self::SUNRISE,
				count( $this->preexisting_runs ),
				implode( ', ', array_keys( $this->preexisting_runs ) )
			) );
		}

		// A synthetic foreign durable ownership entry, alongside this run's.
		$foreign_token = 'sesn1-FOREIGN-' . wp_generate_password( 8, false );
		$runs          = $this->read_all_runs( self::SUNRISE );
		$runs[ $foreign_token ] = [ 'sessions' => [ 987001, 987002 ], 'relations' => [ 987101 ] ];
		$this->write_all_runs( self::SUNRISE, $runs );

		// A foreign marked product, NOT tracked by this run.
		$foreign_product = new WC_Product_Simple();
		$foreign_product->set_name( self::PREFIX . ' Foreign Run Product ' . wp_generate_password( 6, false ) );
		$foreign_product->set_status( 'publish' );
		$foreign_product->save();
		$foreign_product_id = (int) $foreign_product->get_id();
		update_post_meta( $foreign_product_id, '_bc_sesn1_product', 'sesn1-SOME-OTHER-RUN' );

		// A Session this run DOES own, to prove cleanup still works.
		$owned = $this->make_session( self::SUNRISE, '2031-01-15', 'Ownership Owned' );

		$this->ok( $foreign_product_id > 0 && $owned > 0, 'a foreign ownership entry, a foreign marked product and an owned Session are planted' );
		$this->ok( isset( $this->read_all_runs( self::SUNRISE )[ $foreign_token ] ), 'the foreign run entry is stored alongside this run\'s' );
		$mine = $this->read_ownership( self::SUNRISE );
		$this->ok( is_array( $mine ), 'read_ownership returns THIS run\'s entry' );
		$this->ok( ! in_array( 987001, $mine['sessions'] ?? [], true ), '  and never the foreign run\'s ids' );
		$this->ok( in_array( $owned, $mine['sessions'] ?? [], true ), '  but does include this run\'s own Session' );

		// A failed assignment read must not read as "nothing is assigned".
		$this->assert_read_failure_propagates();

		$this->foreign_token         = $foreign_token;
		$this->foreign_product_id    = $foreign_product_id;
		$this->foreign_owned_session = $owned;

		restore_current_blog();
	}

	/**
	 * Structural failure injection, on the CURRENT-RUN DISPOSABLE hall only.
	 *
	 * Renaming the assignment table aside makes every relationship read fail, so
	 * the fail-closed paths can be observed rather than argued about.
	 */
	private function assert_read_failure_propagates(): void {
		global $wpdb;

		$hall = 0;
		foreach ( array_keys( $this->disposable_sites ) as $candidate ) {
			$hall = (int) $candidate;
			break;
		}
		if ( $hall < 1 ) {
			$this->note( 'read-failure injection skipped: no current-run disposable hall is available' );

			return;
		}

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new SessionService();
		$session = $this->make_session( $hall, '2031-02-02', 'Read Failure Target' );

		$relations = Migrations::session_packages_table();
		$this->disposable_ddl( $hall, "RENAME TABLE `{$relations}` TO `{$relations}_sesn1_probe`" );
		$suppress = $wpdb->suppress_errors( true );

		$assignable = $service->assignablePackages( $session );
		$read       = $service->getById( $session );
		$removal    = $service->removePackage( $session, 987654321 );

		$wpdb->suppress_errors( $suppress );
		$this->disposable_ddl( $hall, "RENAME TABLE `{$relations}_sesn1_probe` TO `{$relations}`" );
		$wpdb->last_error = '';

		$this->ok( is_wp_error( $assignable ), 'a failed assignment read propagates from assignablePackages(), never becoming []' );
		$this->ok( is_wp_error( $read ), 'a failed assignment read propagates from getById(), never becoming an empty composition' );
		$this->ok( is_wp_error( $removal ), 'a failed relationship read propagates from removePackage() rather than reporting a no-op' );
		$this->same( 'persistence_failed', is_wp_error( $removal ) ? $removal->get_error_code() : '', '  as persistence_failed' );

		// The table came back; ordinary behaviour resumes.
		$this->ok( ! is_wp_error( $service->getById( $session ) ), 'ordinary reads resume once the table is restored' );

		restore_current_blog();
	}

	/** Verified inside cleanup(), after the real cleanup path has run. */
	private function assert_foreign_ownership_survived(): void {
		global $wpdb;

		if ( '' === $this->foreign_token ) {
			return;
		}

		switch_to_blog( self::SUNRISE );

		$runs = $this->read_all_runs( self::SUNRISE );
		$this->ok( isset( $runs[ $this->foreign_token ] ), 'the FOREIGN run ownership entry survived cleanup' );
		$this->same( [ 987001, 987002 ], $runs[ $this->foreign_token ]['sessions'] ?? [], '  byte-for-byte, sessions intact' );
		$this->same( [ 987101 ], $runs[ $this->foreign_token ]['relations'] ?? [], '  byte-for-byte, relations intact' );
		$this->ok( ! isset( $runs[ $this->run_token ] ), '  while THIS run\'s entry was removed' );

		$this->ok( null !== get_post( $this->foreign_product_id ), 'the FOREIGN marked product survived cleanup' );
		$this->same( 'sesn1-SOME-OTHER-RUN', (string) get_post_meta( $this->foreign_product_id, '_bc_sesn1_product', true ), '  with its marker unchanged' );

		$this->same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d', $this->foreign_owned_session ) ),
			'  while this run\'s own Session WAS removed'
		);

		// Any REAL prior-run evidence must be byte-for-byte unchanged too.
		foreach ( $this->preexisting_runs as $token => $entry ) {
			$this->same( $entry, $runs[ $token ] ?? null, "the pre-existing foreign ownership entry {$token} survived unchanged" );
		}

		// This scenario removes ONLY the synthetic token and product it planted.
		wp_delete_post( $this->foreign_product_id, true );
		$runs = $this->read_all_runs( self::SUNRISE );
		unset( $runs[ $this->foreign_token ] );
		$this->write_all_runs( self::SUNRISE, $runs );
		$this->ok( null === get_post( $this->foreign_product_id ), 'the synthetic foreign product was removed by its own test, by exact id' );
		$this->same( $this->preexisting_runs, $this->read_all_runs( self::SUNRISE ), '  and the registry equals the pre-test foreign-run snapshot exactly' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	private function ensure_persistent_schema(): void {
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			Migrations::migrate_current_site();
			restore_current_blog();
		}
	}

	/**
	 * Hall role users, recreated inside the isolated fixture.
	 *
	 * The development installation this suite was written against has long had a
	 * Hall Manager and a Game Operator on every hall. A fresh fixture database has
	 * none, so the authorization group would measure a missing fixture rather than
	 * the capability model under test.
	 *
	 * The capability row is planted DIRECTLY, exactly as
	 * tests/hall-staff-create-invite.php's plant_caps()/plant_raw() helpers do,
	 * because that is the only way to seed one: ADR-025's HallStaffWriterGuard
	 * deliberately refuses add_user_to_blog() and every other unsupported writer
	 * channel for a hall role, and refusing them is the behaviour under test
	 * elsewhere. Nothing is copied or replaced — the role slugs and the capability
	 * model come from the real Roles class, and every authorization assertion
	 * still runs against it.
	 *
	 * Fixture INFRASTRUCTURE: deterministic logins, created only when absent,
	 * never granted Super Admin, left in place like the bootstrap's
	 * `fixture_admin`.
	 */
	private function ensure_role_fixtures(): void {
		global $wpdb;

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			Roles::sync_current_site();
			restore_current_blog();

			foreach ( [ Roles::HALL_MANAGER => 'manager', Roles::GAME_OPERATOR => 'operator' ] as $role => $slug ) {
				/*
				 * Self-healing, and deliberately NOT a get_users( role ) probe.
				 *
				 * get_users() matches the serialized usermeta row, so a user whose
				 * capability row is present but SHADOWED by an earlier empty one still
				 * matches while holding no capability at all. The question worth asking
				 * is the one the suite asks: does some user on this hall actually hold
				 * the capability the role grants?
				 */
				$capability = Roles::HALL_MANAGER === $role ? 'bc_manage_sessions' : 'bc_view_sessions';
				$holder     = 0;
				foreach ( get_users( [ 'blog_id' => $site_id, 'role' => $role, 'number' => 5, 'fields' => 'ID' ] ) as $candidate ) {
					if ( user_can( (int) $candidate, $capability ) ) {
						$holder = (int) $candidate;
						break;
					}
				}
				if ( $holder > 0 ) {
					continue;
				}

				$login = sprintf( 'bc_fixture_%s_%d', $slug, $site_id );
				$user  = get_user_by( 'login', $login );
				$id    = $user instanceof WP_User ? (int) $user->ID : 0;
				if ( $id < 1 ) {
					$created = wp_insert_user(
						[
							'user_login' => $login,
							'user_email' => $login . '@fixture.bingocommerce.test',
							'user_pass'  => wp_generate_password( 24, true, true ),
							'role'       => '',
						]
					);
					$id = is_wp_error( $created ) ? 0 : (int) $created;
				}
				if ( $id < 1 ) {
					continue;
				}

				// Exactly ONE capability row per key. wp_insert_user() and any earlier
				// attempt can leave an empty `a:0:{}` row behind, and get_metadata()
				// returns the FIRST match — so a stale empty row would shadow this one
				// and the user would silently hold no role at all.
				$caps_key = $wpdb->get_blog_prefix( $site_id ) . 'capabilities';
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $caps_key ], [ '%d', '%s' ] );
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %s)",
						$id,
						$caps_key,
						serialize( [ $role => true ] )
					)
				);
				clean_user_cache( $id );
			}
		}

		if ( ! get_user_by( 'login', 'bc_fixture_ordinary' ) instanceof WP_User ) {
			wp_insert_user(
				[
					'user_login' => 'bc_fixture_ordinary',
					'user_email' => 'bc_fixture_ordinary@fixture.bingocommerce.test',
					'user_pass'  => wp_generate_password( 24, true, true ),
					'role'       => '',
				]
			);
		}
	}

	private function make_session( int $site_id, string $date, string $name, bool $available = false ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $this->super_admin_id );

		$result = ( new SessionService() )->create( $date, self::PREFIX . ' ' . $name, $available );
		$id     = $result instanceof SessionResult ? (int) $result->session['session_id'] : 0;
		if ( $id > 0 ) {
			$this->track_session( $site_id, $id );
		} else {
			$this->ok( false, "fixture Session creation failed for {$name}: " . ( is_wp_error( $result ) ? $result->get_error_code() : 'unknown' ) );
		}

		wp_set_current_user( $previous );
		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	private function make_product( int $site_id, string $label, string $price = '10.00' ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$product = new WC_Product_Simple();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->save();
		$id = (int) $product->get_id();
		update_post_meta( $id, '_bc_sesn1_product', $this->run_token );
		$this->created_products[ $site_id ][] = $id;
		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	private function make_game_type( int $site_id, string $name ): int {
		$previous = get_current_user_id();
		wp_set_current_user( $this->super_admin_id );
		$result = ( new GameTypeService() )->create( self::PREFIX . ' ' . $name . ' ' . wp_generate_password( 5, false ) );
		wp_set_current_user( $previous );
		$id = is_wp_error( $result ) ? 0 : (int) $result->game_type['game_type_id'];
		if ( $id > 0 ) {
			$this->created_game_types[ $site_id ][] = $id;
		}

		return $id;
	}

	private function make_play_product( int $site_id, BingoPlayProductService $service, string $label, string $medium, int $faces, ?int $game_type = null ): int {
		$result = $service->create( $this->make_product( $site_id, $label ), $medium, $faces, $game_type );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Play Product creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}
		$id = (int) $result->play_product['bingo_play_product_id'];
		$this->created_play_products[ $site_id ][] = $id;

		return $id;
	}

	private function make_package( int $site_id, string $label ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$previous = get_current_user_id();
		wp_set_current_user( $this->super_admin_id );

		$play = new BingoPlayProductService();
		$id   = $this->make_package_from( $site_id, $label, [ [ $this->make_play_product( $site_id, $play, $label . 'pp', 'paper', 2 ), 1 ] ] );

		wp_set_current_user( $previous );
		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	/** @param array<int,array{0:int,1:int}> $pairs */
	private function make_package_from( int $site_id, string $label, array $pairs ): int {
		$components = [];
		foreach ( $pairs as $pair ) {
			$components[] = [ 'bingo_play_product_id' => (int) $pair[0], 'quantity' => (int) $pair[1] ];
		}

		$result = ( new PackageService() )->create( $this->make_product( $site_id, 'pkg ' . $label ), $components );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Package creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}

		$id = (int) $result->package['package_id'];
		$this->created_packages[ $site_id ][] = $id;

		return $id;
	}

	/* ================================================================== *
	 * Ownership registry — exact ids only, in memory AND durably
	 * ================================================================== */

	private function track_session( int $site_id, int $session_id ): void {
		$this->created_sessions[ $site_id ][] = $session_id;
		$this->persist_ownership( $site_id );
	}

	/** Record every relationship row this run created for a Session it owns. */
	private function track_relations( int $site_id, int $session_id ): void {
		global $wpdb;

		if ( ! in_array( $session_id, $this->created_sessions[ $site_id ] ?? [], true ) ) {
			return;
		}

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT session_package_id FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d', $session_id ) ) as $relation_id ) {
			$relation_id = (int) $relation_id;
			if ( ! in_array( $relation_id, $this->created_relations[ $site_id ] ?? [], true ) ) {
				$this->created_relations[ $site_id ][] = $relation_id;
			}
		}
		if ( $switched ) {
			restore_current_blog();
		}

		$this->persist_ownership( $site_id );
	}

	/**
	 * The durable half of the registry. It is evidence for these EXACT ids only,
	 * never for a name, date, prefix, timestamp or numeric range. A row created
	 * before its id lands here is unproven and is reported, never deleted.
	 */
	private function persist_ownership( int $site_id ): void {
		$runs = $this->read_all_runs( $site_id );

		// Update ONLY this run's entry. Every other token — including one left by
		// an interrupted earlier run — is preserved byte-for-byte.
		$runs[ $this->run_token ] = [
			'sessions'  => array_values( array_unique( $this->created_sessions[ $site_id ] ?? [] ) ),
			'relations' => array_values( array_unique( $this->created_relations[ $site_id ] ?? [] ) ),
		];

		$this->write_all_runs( $site_id, $runs );
	}

	/** @return array<string,array{sessions:array<int,int>,relations:array<int,int>}> */
	private function read_all_runs( int $site_id ): array {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$stored = get_option( self::OWNERSHIP_OPTION, null );
		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! is_array( $stored ) || ! isset( $stored['runs'] ) || ! is_array( $stored['runs'] ) ) {
			return [];
		}

		$runs = [];
		foreach ( $stored['runs'] as $token => $entry ) {
			if ( ! is_string( $token ) || ! is_array( $entry ) ) {
				continue;
			}
			$runs[ $token ] = [
				'sessions'  => array_map( 'intval', (array) ( $entry['sessions'] ?? [] ) ),
				'relations' => array_map( 'intval', (array) ( $entry['relations'] ?? [] ) ),
			];
		}

		return $runs;
	}

	/** @param array<string,array{sessions:array<int,int>,relations:array<int,int>}> $runs */
	private function write_all_runs( int $site_id, array $runs ): void {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		if ( [] === $runs ) {
			// The whole option goes only when NO run entry remains.
			delete_option( self::OWNERSHIP_OPTION );
		} else {
			update_option( self::OWNERSHIP_OPTION, [ 'runs' => $runs ], false );
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * THIS run's durable entry, and only this run's.
	 *
	 * A stored token that is not this process's token is never current-run
	 * ownership, however plausible it looks.
	 *
	 * @return array{sessions:array<int,int>,relations:array<int,int>}|null
	 */
	private function read_ownership( int $site_id ): ?array {
		$runs = $this->read_all_runs( $site_id );

		return $runs[ $this->run_token ] ?? null;
	}

	/* ================================================================== *
	 * Disposable infrastructure
	 * ================================================================== */

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'sesn1' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'SESN1 Disposable Hall',
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->ok( false, 'disposable hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$this->disposable_sites[ (int) $site ] = true;

		if ( class_exists( 'WC_Install' ) ) {
			switch_to_blog( (int) $site );
			WC_Install::create_tables();
			restore_current_blog();
		}

		return (int) $site;
	}

	/** The single gate that keeps every structural mutation off a persistent hall. */
	private function assert_disposable_hall( int $site_id ): void {
		if ( $site_id > 4 && ! empty( $this->disposable_sites[ $site_id ] ) ) {
			return;
		}

		fwrite(
			STDERR,
			"ABORT: refusing a structural schema mutation on site {$site_id}.\n"
			. "Only a disposable hall created by this run may be structurally modified.\n"
		);
		exit( 1 );
	}

	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
	}

	private function destroy_disposable_site( int $site_id ): void {
		global $wpdb;

		// The current-run registry is the ONLY authority. A domain shape or id
		// range is never ownership.
		if ( $site_id < 1 || empty( $this->disposable_sites[ $site_id ] ) ) {
			return;
		}

		switch_to_blog( $site_id );
		$tables = [
			// ADR-043 Sessions v3.
			Migrations::schedule_packages_table(),
			Migrations::schedule_dates_table(),
			Migrations::schedules_table(),
			Migrations::portal_session_templates_table(),
			Migrations::session_packages_table(),
			Migrations::sessions_table(),
			\BingoCommerce\Catalog\Migrations::package_addons_table(),
			\BingoCommerce\Catalog\Migrations::package_components_table(),
			\BingoCommerce\Catalog\Migrations::packages_table(),
			\BingoCommerce\Catalog\Migrations::play_products_table(),
			\BingoCommerce\Catalog\Migrations::game_types_table(),
		];
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) );
		restore_current_blog();

		wp_delete_site( $site_id );

		foreach ( $tables as $table ) {
			if ( 1 === preg_match( '/^bc_' . $site_id . '_bingo_(portal_session_templates|session_packages|sessions|session_schedules|session_schedule_dates|session_schedule_packages|package_addons|package_components|packages|play_products|game_types)$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		$this->disposable_tables[ $site_id ] = $tables;
		unset( $this->disposable_sites[ $site_id ] );
		unset( $this->created_sessions[ $site_id ], $this->created_relations[ $site_id ], $this->created_products[ $site_id ], $this->created_game_types[ $site_id ], $this->created_play_products[ $site_id ], $this->created_packages[ $site_id ] );
	}

	/* ================================================================== *
	 * Cleanup
	 * ================================================================== */

	private function cleanup(): void {
		global $wpdb;
		$this->group( 'Cleanup verification' );

		wp_set_current_user( $this->super_admin_id );

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->destroy_disposable_site( (int) $site_id );
		}

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );

			$ownership        = $this->read_ownership( $site_id );
			$proven_sessions  = null === $ownership ? [] : $ownership['sessions'];
			$proven_relations = null === $ownership ? [] : $ownership['relations'];

			// A foreign run token is report-only and survives untouched.
			$foreign_tokens = array_values( array_diff( array_keys( $this->read_all_runs( $site_id ) ), [ $this->run_token ] ) );
			if ( [] !== $foreign_tokens ) {
				$this->note( sprintf(
					'site %d: %d ownership entr(ies) belong to another run and were LEFT UNTOUCHED: %s',
					$site_id,
					count( $foreign_tokens ),
					implode( ', ', $foreign_tokens )
				) );
			}

			// Report anything this run created in memory but never got to record
			// durably. Unproven is unproven.
			$unproven = array_values( array_diff( $this->created_sessions[ $site_id ] ?? [], $proven_sessions ) );
			if ( [] !== $unproven ) {
				$this->note( sprintf(
					'site %d: %d Session(s) were created by this run but never durably recorded, so they are UNPROVEN and were LEFT UNTOUCHED: %s',
					$site_id,
					count( $unproven ),
					implode( ', ', array_map( static fn( $id ) => '#' . (int) $id, $unproven ) )
				) );
			}

			foreach ( $proven_relations as $relation_id ) {
				$wpdb->delete( Migrations::session_packages_table(), [ 'session_package_id' => (int) $relation_id ], [ '%d' ] );
			}
			foreach ( $proven_sessions as $session_id ) {
				// Any relationship still hanging off a marker-proven Session is
				// this run's too, since the Session itself is proven.
				$wpdb->delete( Migrations::session_packages_table(), [ 'session_id' => (int) $session_id ], [ '%d' ] );
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_session', 'target_id' => (string) (int) $session_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( Migrations::sessions_table(), [ 'session_id' => (int) $session_id ], [ '%d' ] );
			}

			$remaining = 0;
			foreach ( $proven_sessions as $session_id ) {
				$remaining += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d', (int) $session_id ) );
			}
			$this->same( 0, $remaining, "hall {$site_id}: every marker-proven Session was removed" );

			// Remove ONLY this run's entry, and only once every row it proves is
			// gone. The option itself disappears only when no entry remains.
			if ( 0 === $remaining && [] === $unproven ) {
				$runs = $this->read_all_runs( $site_id );
				unset( $runs[ $this->run_token ] );
				$this->write_all_runs( $site_id, $runs );
			}

			// Catalog fixtures this run created on a persistent hall.
			foreach ( $this->created_packages[ $site_id ] ?? [] as $package_id ) {
				$wpdb->delete( \BingoCommerce\Catalog\Migrations::package_components_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_package', 'target_id' => (string) (int) $package_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( \BingoCommerce\Catalog\Migrations::packages_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
			}
			$owned = $this->created_play_products[ $site_id ] ?? [];
			foreach ( $this->created_products[ $site_id ] ?? [] as $product_id ) {
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT bingo_play_product_id FROM ' . \BingoCommerce\Catalog\Migrations::play_products_table() . ' WHERE wc_product_id = %d', (int) $product_id ) ) as $attached ) {
					$owned[] = (int) $attached;
				}
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT package_id FROM ' . \BingoCommerce\Catalog\Migrations::packages_table() . ' WHERE wc_product_id = %d', (int) $product_id ) ) as $attached_package ) {
					$wpdb->delete( \BingoCommerce\Catalog\Migrations::package_components_table(), [ 'package_id' => (int) $attached_package ], [ '%d' ] );
					$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_package', 'target_id' => (string) (int) $attached_package ], [ '%d', '%s', '%s' ] );
					$wpdb->delete( \BingoCommerce\Catalog\Migrations::packages_table(), [ 'package_id' => (int) $attached_package ], [ '%d' ] );
				}
			}
			foreach ( array_unique( $owned ) as $play_product_id ) {
				$wpdb->delete( \BingoCommerce\Catalog\Migrations::package_components_table(), [ 'bingo_play_product_id' => (int) $play_product_id ], [ '%d' ] );
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_play_product', 'target_id' => (string) (int) $play_product_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( \BingoCommerce\Catalog\Migrations::play_products_table(), [ 'bingo_play_product_id' => (int) $play_product_id ], [ '%d' ] );
			}
			foreach ( $this->created_game_types[ $site_id ] ?? [] as $game_type_id ) {
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'game_type', 'target_id' => (string) (int) $game_type_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( \BingoCommerce\Catalog\Migrations::game_types_table(), [ 'game_type_id' => (int) $game_type_id ], [ '%d' ] );
			}
			// Products: exact current-run tracked ids, plus marker recovery that
			// requires BOTH the marker key AND this run's token. A prior run's
			// marked product is never touched.
			$product_ids = array_map( 'intval', $this->created_products[ $site_id ] ?? [] );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s AND meta_value = %s", '_bc_sesn1_product', $this->run_token ) ) as $post_id ) {
				$product_ids[] = (int) $post_id;
			}
			foreach ( array_values( array_unique( $product_ids ) ) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}

			$foreign_products = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s AND meta_value <> %s", '_bc_sesn1_product', $this->run_token ) );
			if ( [] !== $foreign_products ) {
				$this->note( sprintf(
					'site %d: %d marked product(s) belong to another run and were LEFT UNTOUCHED: %s',
					$site_id,
					count( $foreign_products ),
					implode( ', ', array_map( static fn( $id ) => '#' . (int) $id, $foreign_products ) )
				) );
			}

			restore_current_blog();
		}

		// PRESERVATION, not emptiness.
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->same( 3, (int) get_option( Migrations::VERSION_OPTION, 0 ), "hall {$site_id} remains at Session v3" );
			$this->ok( true === Migrations::verify_current_site_schema(), "hall {$site_id} Session schema verifies after cleanup" );
			$snapshot = $this->session_snapshot( $site_id );
			$this->same( $this->baseline_sessions[ $site_id ]['sessions'], $snapshot['sessions'], "hall {$site_id}: the bingo_sessions baseline is unchanged" );
			$this->same( $this->baseline_sessions[ $site_id ]['relations'], $snapshot['relations'], "hall {$site_id}: the bingo_session_packages baseline is unchanged" );
			$this->ok( null === $this->read_ownership( $site_id ), "hall {$site_id}: THIS run's durable ownership entry was removed" );
			restore_current_blog();
		}

		// Disposable teardown proof.
		$this->same( [], $this->disposable_sites, 'every disposable hall this run created was destroyed' );
		foreach ( $this->disposable_tables as $site_id => $tables ) {
			$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", (int) $site_id ) ), "disposable hall {$site_id}: the site record was removed" );
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_bingo_' ) . '%' ) ),
				"disposable hall {$site_id}: no hall-local Catalog or Session table survives"
			);
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', (int) $site_id ) ),
				"disposable hall {$site_id}: no audit rows remain"
			);
		}

		$this->assert_foreign_ownership_survived();

		// A domain shape is not ownership: report, never destroy.
		$leftovers = [];
		foreach ( (array) $wpdb->get_results( "SELECT blog_id, domain FROM {$wpdb->blogs} WHERE domain LIKE 'sesn1%'", ARRAY_A ) as $row ) {
			$leftovers[] = '#' . (int) $row['blog_id'] . ' ' . (string) $row['domain'];
		}
		if ( [] !== $leftovers ) {
			$this->note( sprintf(
				'%d hall(s) with this suite\'s domain shape were NOT created by this run and were LEFT UNTOUCHED: %s',
				count( $leftovers ),
				implode( ', ', $leftovers )
			) );
		}
	}

	/* ================================================================== *
	 * Support
	 * ================================================================== */

	private function record_baseline_sessions(): void {
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			$this->baseline_sessions[ $site_id ] = $this->session_snapshot( $site_id );
		}
	}

	/** @return array{sessions:array<int,array<string,mixed>>,relations:array<int,array<string,mixed>>} */
	private function session_snapshot( int $site_id ): array {
		global $wpdb;

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$snapshot = [
			'sessions'  => (array) $wpdb->get_results(
				'SELECT session_id, session_date, display_name, available_for_sale, status, created_at, updated_at, archived_at FROM '
				. Migrations::sessions_table() . ' ORDER BY session_id',
				ARRAY_A
			),
			'relations' => (array) $wpdb->get_results(
				'SELECT session_package_id, session_id, package_id, created_at FROM '
				. Migrations::session_packages_table() . ' ORDER BY session_package_id',
				ARRAY_A
			),
		];

		if ( $switched ) {
			restore_current_blog();
		}

		return $snapshot;
	}

	private function relation_count( int $session_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d', $session_id ) );
	}

	private function audit_count( int $site_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND target_type = %s', $site_id, 'bingo_session' )
		);
	}

	private function last_audit_column( int $site_id, int $session_id, string $column ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$column} FROM " . CoreMigrations::audit_log_table()
				. ' WHERE blog_id = %d AND target_type = %s AND target_id = %s ORDER BY id DESC LIMIT 1',
				$site_id,
				'bingo_session',
				(string) $session_id
			)
		);
	}

	private function last_audit_action( int $site_id, int $session_id ): string {
		return (string) $this->last_audit_column( $site_id, $session_id, 'action' );
	}

	private function last_audit_target_type( int $site_id, int $session_id ): string {
		return (string) $this->last_audit_column( $site_id, $session_id, 'target_type' );
	}

	private function last_audit_context( int $site_id, int $session_id ): string {
		return (string) $this->last_audit_column( $site_id, $session_id, 'context' );
	}

	private function last_audit_operation_id( int $site_id, int $session_id ) {
		return $this->last_audit_column( $site_id, $session_id, 'operation_id' );
	}

	private function user_with_role( int $site_id, string $role ): int {
		switch_to_blog( $site_id );
		$users = get_users( [ 'blog_id' => $site_id, 'role' => $role, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
		restore_current_blog();

		return isset( $users[0] ) ? (int) $users[0] : 0;
	}

	private function ordinary_user(): int {
		foreach ( get_users( [ 'blog_id' => 0, 'number' => 50, 'fields' => 'ID', 'orderby' => 'ID' ] ) as $id ) {
			$id = (int) $id;
			if ( is_super_admin( $id ) ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( $user instanceof WP_User && ! in_array( Roles::HALL_MANAGER, (array) $user->roles, true ) && ! in_array( Roles::GAME_OPERATOR, (array) $user->roles, true ) ) {
				return $id;
			}
		}

		return 0;
	}

	private function find_super_admin_id(): int {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/** @return array<int,mixed> */
	private function role_snapshot(): array {
		global $wpdb;
		$snapshot = [];
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$snapshot[ $site_id ] = get_option( $wpdb->get_blog_prefix( $site_id ) . 'user_roles' );
			restore_current_blog();
		}

		return $snapshot;
	}

	/* ================================================================== *
	 * Assertions
	 * ================================================================== */

	private function group( string $name ): void {
		$this->group = $name;
		if ( ! isset( $this->groups[ $name ] ) ) {
			$this->groups[ $name ] = [ 'pass' => 0, 'fail' => 0 ];
			echo "\n== {$name} ==\n";
		}
	}

	private function note( string $message ): void {
		$this->notes[] = $message;
	}

	private function ok( bool $condition, string $message ): void {
		if ( $condition ) {
			++$this->groups[ $this->group ]['pass'];
			echo "  ok  {$message}\n";

			return;
		}
		++$this->groups[ $this->group ]['fail'];
		echo "  FAIL {$message}\n";
	}

	private function same( $expected, $actual, string $message ): void {
		if ( $expected === $actual ) {
			++$this->groups[ $this->group ]['pass'];
			echo "  ok  {$message}\n";

			return;
		}
		++$this->groups[ $this->group ]['fail'];
		echo "  FAIL {$message}\n";
		echo '       expected: ' . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
	}

	private function is_error( string $expected, $result, string $message ): void {
		$actual = is_wp_error( $result ) ? $result->get_error_code() : ( 'RESULT:' . $this->status_of( $result ) );
		$this->same( $expected, $actual, $message );
	}

	private function is_status( string $expected, $result, string $message ): void {
		$actual = is_wp_error( $result ) ? 'ERROR:' . $result->get_error_code() : $this->status_of( $result );
		$this->same( $expected, $actual, $message );
	}

	private function status_of( $result ): string {
		if ( $result instanceof SessionResult ) {
			return $result->status;
		}
		if ( is_object( $result ) && property_exists( $result, 'status' ) ) {
			return (string) $result->status;
		}

		return gettype( $result );
	}

	private function report(): void {
		echo "\n================ RESULTS BY GROUP ================\n";
		$pass = 0;
		$fail = 0;
		foreach ( $this->groups as $name => $counts ) {
			$pass += $counts['pass'];
			$fail += $counts['fail'];
			printf( "  %-32s %3d assertions   %s\n", $name, $counts['pass'] + $counts['fail'], $counts['fail'] > 0 ? "FAIL ({$counts['fail']})" : 'PASS' );
		}
		echo '  ' . str_repeat( '-', 60 ) . "\n";
		printf( "  %-32s %3d assertions   %s\n", 'TOTAL', $pass + $fail, $fail > 0 ? "FAIL ({$fail})" : 'PASS' );
		foreach ( $this->notes as $note ) {
			echo "NOTE: {$note}\n";
		}
		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

( new SessionIncrementOneTest() )->run();
