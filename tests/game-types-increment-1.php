<?php

/**
 * CLI integration coverage for BingoCommerce Game Type Increment 1.
 *
 * Run from the repository root:
 * php tests/game-types-increment-1.php
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
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
if ( ! class_exists( '\BingoCommerce\Catalog\GameTypeService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}

use BingoCommerce\Catalog\GameTypeResult;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;

final class GameTypeIncrementOneTest {

	private const SUNRISE = 2;
	private const TAMPA = 3;
	private const PREFIX = 'GTI1';

	private int $assertions = 0;
	private int $super_admin_id = 0;

	/** Every trigger name this suite may create. All of them live on a disposable hall only. */
	private const SUITE_TRIGGERS = [ 'bc_gti1_force_code' ];

	/** @var array<int,array<int,int>> */
	private array $created_ids = [];

	/**
	 * Disposable halls created by this run, by id.
	 *
	 * The ONLY place this suite may perform structural SQL. Persistent Sunrise
	 * and Tampa take ordinary domain DML against tracked fixture rows and nothing
	 * else, so a crash at any point leaves their schema exactly as it was.
	 *
	 * @var array<int,bool>
	 */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> disposable site => the Catalog tables it owned */
	private array $disposable_catalog_tables = [];

	private int $schema_hall = 0;

	public function run(): void {
		$this->verify_runtime();
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );

		try {
			$this->migration_and_provisioning();
			$this->authorization();
			$this->creation_and_code_generation();
			$this->name_uniqueness();
			$this->rename_lifecycle_and_reads();
			$this->audit_behavior();
			$this->tenant_isolation();
			$this->concurrency();
		} finally {
			$this->cleanup();
		}

		echo "PASS: {$this->assertions} Game Type Increment 1 assertions.\n";
	}

	private function verify_runtime(): void {
		$this->assert_true( extension_loaded( 'intl' ), 'Intl loaded' );
		$this->assert_true( class_exists( 'Normalizer' ), 'Normalizer available' );
		$this->assert_true( class_exists( 'IntlChar' ), 'IntlChar available' );
		$this->assert_true( function_exists( 'mb_convert_case' ), 'mb_convert_case available' );
		$this->assert_true( defined( 'MB_CASE_FOLD' ), 'MB_CASE_FOLD available' );
		$this->assert_true( 1 === preg_match( '/^\p{L}+$/u', 'é中' ), 'PCRE Unicode properties work' );
	}

	private function migration_and_provisioning(): void {
		global $wpdb;

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );
			try {
				delete_option( Migrations::VERSION_OPTION );
				$result = Migrations::migrate_current_site();
				$this->assert_true( true === $result, "site {$site_id} migration succeeds" );
				$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), "site {$site_id} version stored" );
				$this->assert_true( true === Migrations::verify_current_site_schema(), "site {$site_id} schema verified" );

				$table = Migrations::game_types_table();
				$name  = $wpdb->get_row( "SHOW FULL COLUMNS FROM `{$table}` LIKE 'name'", ARRAY_A );
				$this->assert_true( is_array( $name ) && str_starts_with( strtolower( (string) $name['Collation'] ), 'utf8mb4_' ), "site {$site_id} inherited utf8mb4 name collation" );
				$this->assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONVERT(%s USING utf8mb4) COLLATE {$name['Collation']} = CONVERT(%s USING utf8mb4) COLLATE {$name['Collation']}", 'Regular', 'regular' ) ), "site {$site_id} case-insensitive collation" );
				$this->assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONVERT(%s USING utf8mb4) COLLATE {$name['Collation']} = CONVERT(%s USING utf8mb4) COLLATE {$name['Collation']}", 'Cafe', 'Café' ) ), "site {$site_id} accent-insensitive collation" );

				$this->assert_true( true === Migrations::migrate_current_site(), "site {$site_id} migration rerun is idempotent" );
			} finally {
				restore_current_blog();
			}
		}

		// The index-damage/repair scenario structurally alters bingo_game_types,
		// so it runs on a hall created for this run alone. A crash mid-scenario
		// must never leave a persistent hall missing an index.
		$this->assert_missing_index_is_repaired();

		$before = get_current_blog_id();
		$result = Migrations::provision_new_site( get_site( self::TAMPA ) );
		$this->assert_true( true === $result, 'new-site hook migration path succeeds' );
		$this->assert_same( $before, get_current_blog_id(), 'new-site hook restores blog context' );
		$this->assert_true( false !== has_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ] ), 'new-site provisioning hook registered' );
		$this->assert_failed_provisioning_restores_context();
		$this->assert_network_sweep_boundary();
	}

	private function assert_network_sweep_boundary(): void {
		$this->assert_true( false === has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ] ), 'network sweep is not registered on hall admin_init' );
		$this->assert_true( false !== has_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ] ), 'network sweep is registered on network-admin lifecycle' );

		$manager  = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$operator = $this->first_user_with_role( Roles::GAME_OPERATOR, self::SUNRISE );
		$this->assert_true( $manager > 0, 'network-boundary Hall Manager fixture exists' );
		$this->assert_true( $operator > 0, 'network-boundary Game Operator fixture exists' );

		$suffix     = strtolower( wp_generate_password( 8, false, false ) );
		$subscriber = wp_create_user( 'gti1-sweep-' . $suffix, wp_generate_password( 24 ), 'gti1-sweep-' . $suffix . '@test.local' );
		$this->assert_true( ! is_wp_error( $subscriber ), 'network-boundary ordinary-user fixture created' );
		add_user_to_blog( self::SUNRISE, (int) $subscriber, 'subscriber' );

		try {
			foreach (
				[
					'Hall Manager'  => $manager,
					'Game Operator' => $operator,
					'ordinary user' => (int) $subscriber,
				] as $label => $actor_id
			) {
				delete_site_option( Migrations::NETWORK_SWEEP_OPTION );
				$this->actor( self::SUNRISE, $actor_id );
				set_current_screen( 'dashboard' );
				$this->assert_true( ! is_network_admin(), $label . ' fixture uses hall-admin context' );
				$this->assert_true( true === Migrations::maybe_migrate_network(), $label . ' network sweep request is a safe no-op' );
				$this->assert_same( 0, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION, 0 ), $label . ' cannot persist network sweep state' );

				set_current_screen( 'dashboard-network' );
				$this->assert_true( is_network_admin(), $label . ' forged fixture uses network-admin context' );
				$this->assert_true( true === Migrations::maybe_migrate_network(), $label . ' forged network-admin sweep request is a safe no-op' );
				$this->assert_same( 0, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION, 0 ), $label . ' cannot sweep even with forged network-admin context' );
			}
		} finally {
			remove_user_from_blog( (int) $subscriber, self::SUNRISE );
			wpmu_delete_user( (int) $subscriber );
		}

		delete_site_option( Migrations::NETWORK_SWEEP_OPTION );
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		wp_set_current_user( $this->super_admin_id );
		set_current_screen( 'dashboard-network' );
		$this->assert_true( is_network_admin(), 'trusted Super Admin fixture uses network-admin context' );
		$this->assert_true( is_super_admin( $this->super_admin_id ), 'trusted Super Admin fixture uses established platform override' );
		$this->assert_true( true === Migrations::maybe_migrate_network(), 'trusted network Super Admin existing-site sweep succeeds' );
		$this->assert_same( Migrations::DB_VERSION, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION ), 'network sweep state stored after success' );
		set_current_screen( 'dashboard' );
	}

	private function authorization(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$hm_role = get_role( Roles::HALL_MANAGER );
		$go_role = get_role( Roles::GAME_OPERATOR );
		$this->assert_true( $hm_role && ! empty( $hm_role->capabilities['bc_manage_products'] ), 'Hall Manager role carries bc_manage_products' );
		$this->assert_true( $go_role && empty( $go_role->capabilities['bc_manage_products'] ), 'Game Operator role lacks bc_manage_products' );

		$manager = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$operator = $this->first_user_with_role( Roles::GAME_OPERATOR, self::SUNRISE );
		$this->assert_true( $manager > 0, 'Hall Manager authorization fixture exists' );
		$this->assert_true( $operator > 0, 'Game Operator authorization fixture exists' );

		$this->actor( self::SUNRISE, $manager );
		$this->assert_true( current_user_can( 'bc_manage_products' ), 'Hall Manager current_user_can prerequisite works' );
		$r = $this->service()->listForAdministration();
		$this->assert_true( is_array( $r ), 'Hall Manager can list Game Types' );

		$this->actor( self::SUNRISE, $operator );
		$this->assert_true( ! current_user_can( 'bc_manage_products' ), 'Game Operator current_user_can denied' );
		$this->assert_error( 'game_type_management_denied', $this->service()->listForAdministration(), 'Game Operator service denied' );

		$suffix     = strtolower( wp_generate_password( 8, false, false ) );
		$subscriber = wp_create_user( 'gti1-' . $suffix, wp_generate_password( 24 ), 'gti1-' . $suffix . '@test.local' );
		$this->assert_true( ! is_wp_error( $subscriber ), 'unauthorized-user fixture created' );
		add_user_to_blog( self::SUNRISE, (int) $subscriber, 'subscriber' );
		$this->actor( self::SUNRISE, (int) $subscriber );
		$this->assert_error( 'game_type_management_denied', $this->service()->listForAdministration(), 'ordinary user and role-name-free authorization denied' );
		remove_user_from_blog( (int) $subscriber, self::SUNRISE );
		wpmu_delete_user( (int) $subscriber );

		$this->actor( self::SUNRISE, $this->super_admin_id );
		$this->assert_true( is_super_admin( $this->super_admin_id ) && current_user_can( 'bc_manage_products' ), 'native Super Admin override uses established WordPress behavior' );
		$this->assert_true( is_array( $this->service()->listForAdministration() ), 'Super Admin can manage current hall' );
	}

	private function creation_and_code_generation(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$cases = [
			self::PREFIX . ' Regular'       => 'gti1-regular',
			self::PREFIX . ' Mixed CASE'    => 'gti1-mixed-case',
			self::PREFIX . ' Many   Spaces' => 'gti1-many-spaces',
			self::PREFIX . ' Winner!!!Take' => 'gti1-winner-take',
			self::PREFIX . ' Café'          => 'gti1-cafe',
			self::PREFIX . ' 中文'          => 'gti1-u4e2d-u6587',
		];
		foreach ( $cases as $name => $expected_code ) {
			$result = $this->create( self::SUNRISE, $name );
			$this->assert_result( 'game_type_created', $result, "create {$name}" );
			$this->assert_same( $expected_code, $result->game_type['code'], "code for {$name}" );
		}

		$emoji = $this->create( self::SUNRISE, '🎱' );
		$this->assert_result( 'game_type_created', $emoji, 'emoji-only creation' );
		$this->assert_same( 'game-type', $emoji->game_type['code'], 'empty normalized code fallback' );

		$long = $this->create( self::SUNRISE, self::PREFIX . ' ' . str_repeat( 'a', 150 ) );
		$this->assert_result( 'game_type_created', $long, 'long code source created' );
		$this->assert_same( 64, strlen( $long->game_type['code'] ), 'code truncates to 64 ASCII characters' );

		$first_collision = $this->create( self::SUNRISE, self::PREFIX . ' Collision Code' );
		$second_collision = $this->create( self::SUNRISE, self::PREFIX . ' Collision-Code' );
		$this->assert_result( 'game_type_created', $first_collision, 'collision base created' );
		$this->assert_result( 'game_type_created', $second_collision, 'collision suffix created' );
		$this->assert_same( $first_collision->game_type['code'] . '-2', $second_collision->game_type['code'], 'lowest collision suffix allocated' );

		$this->assert_error( 'invalid_name', $this->service()->create( '' ), 'empty name rejected' );
		$this->assert_error( 'invalid_name', $this->service()->create( " \u{00A0} " ), 'Unicode-whitespace-only name rejected' );
		$this->assert_error( 'invalid_name', $this->service()->create( "Bad\nName" ), 'control character rejected' );
		$this->assert_error( 'invalid_name', $this->service()->create( "Bad\u{200D}Name" ), 'format-control character rejected' );
		$this->assert_error( 'name_too_long', $this->service()->create( str_repeat( '名', 161 ) ), 'name over 160 scalars rejected' );
	}

	private function name_uniqueness(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$base = $this->create( self::SUNRISE, self::PREFIX . ' Unique Regular' );
		$this->assert_result( 'game_type_created', $base, 'uniqueness base' );
		$this->assert_error( 'duplicate_name', $this->service()->create( self::PREFIX . ' Unique Regular' ), 'exact duplicate rejected' );
		$this->assert_error( 'duplicate_name', $this->service()->create( strtolower( self::PREFIX . ' Unique Regular' ) ), 'case-equivalent duplicate rejected' );
		$this->assert_error( 'duplicate_name', $this->service()->create( '  ' . self::PREFIX . ' Unique Regular  ' ), 'edge-whitespace-equivalent duplicate rejected' );
		$this->assert_error( 'duplicate_name', $this->service()->create( self::PREFIX . ' Unique   Regular' ), 'internal-whitespace-equivalent duplicate rejected' );

		$accent = $this->create( self::SUNRISE, self::PREFIX . ' Accent Café' );
		$this->assert_result( 'game_type_created', $accent, 'accent base' );
		$this->assert_error( 'duplicate_name', $this->service()->create( self::PREFIX . ' Accent Cafe' ), 'accent-equivalent duplicate follows installed collation' );
	}

	private function rename_lifecycle_and_reads(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$first  = $this->create( self::SUNRISE, self::PREFIX . ' Rename One' );
		$second = $this->create( self::SUNRISE, self::PREFIX . ' Rename Two' );
		$code   = $first->game_type['code'];

		$renamed = $this->service()->rename( $first->game_type['game_type_id'], self::PREFIX . ' Renamed Active' );
		$this->assert_result( 'game_type_renamed', $renamed, 'active rename' );
		$this->assert_same( $code, $renamed->game_type['code'], 'rename preserves code' );
		$this->assert_error( 'duplicate_name', $this->service()->rename( $first->game_type['game_type_id'], $second->game_type['name'] ), 'rename duplicate rejected' );

		$noop = $this->service()->rename( $first->game_type['game_type_id'], '  ' . self::PREFIX . ' Renamed   Active  ' );
		$this->assert_result( 'game_type_name_unchanged', $noop, 'canonical same-name rename no-op' );

		$archived = $this->service()->archive( $first->game_type['game_type_id'] );
		$this->assert_result( 'game_type_archived', $archived, 'archive' );
		$this->assert_same( 'archived', $archived->game_type['status'], 'archive status' );
		$this->assert_true( null !== $archived->game_type['archived_at'], 'archive timestamp set' );
		$this->assert_same( $archived->game_type['updated_at'], $archived->game_type['archived_at'], 'archive/update timestamps match' );
		$this->assert_same( $code, $archived->game_type['code'], 'archive preserves code' );

		$repeat_archive = $this->service()->archive( $first->game_type['game_type_id'] );
		$this->assert_result( 'game_type_already_archived', $repeat_archive, 'repeated archive no-op' );

		$renamed_archived = $this->service()->rename( $first->game_type['game_type_id'], self::PREFIX . ' Renamed Archived' );
		$this->assert_result( 'game_type_renamed', $renamed_archived, 'archived rename' );
		$this->assert_same( $code, $renamed_archived->game_type['code'], 'archived rename preserves code' );

		$active = $this->service()->listActiveForPlayProductSelection();
		$this->assert_true( is_array( $active ) && ! in_array( $first->game_type['game_type_id'], array_column( $active, 'game_type_id' ), true ), 'archived excluded from active selection' );
		$all = $this->service()->listForAdministration( 'all' );
		$this->assert_true( is_array( $all ) && in_array( $first->game_type['game_type_id'], array_column( $all, 'game_type_id' ), true ), 'archived retained in administration' );

		$restored = $this->service()->restore( $first->game_type['game_type_id'] );
		$this->assert_result( 'game_type_restored', $restored, 'restore' );
		$this->assert_same( 'active', $restored->game_type['status'], 'restore status' );
		$this->assert_same( null, $restored->game_type['archived_at'], 'restore clears archived_at' );
		$this->assert_same( $code, $restored->game_type['code'], 'restore preserves code' );
		$this->assert_result( 'game_type_already_active', $this->service()->restore( $first->game_type['game_type_id'] ), 'repeated restore no-op' );

		$this->assert_same( $first->game_type['game_type_id'], $this->service()->getById( $first->game_type['game_type_id'] )['game_type_id'], 'getById' );
		$this->assert_same( $first->game_type['game_type_id'], $this->service()->getByCode( $code )['game_type_id'], 'getByCode' );
	}

	private function audit_behavior(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$created = $this->create( self::SUNRISE, self::PREFIX . ' Audit' );
		$this->assert_result( 'game_type_created', $created, 'audit fixture create' );
		$id = $created->game_type['game_type_id'];
		$this->assert_same( 1, $this->audit_count( 'catalog.game_type.created', $id ), 'create audit recorded' );

		$renamed = $this->service()->rename( $id, self::PREFIX . ' Audit Renamed' );
		$this->assert_result( 'game_type_renamed', $renamed, 'audit rename' );
		$this->assert_same( 1, $this->audit_count( 'catalog.game_type.renamed', $id ), 'rename audit recorded' );
		$this->service()->archive( $id );
		$this->assert_same( 1, $this->audit_count( 'catalog.game_type.archived', $id ), 'archive audit recorded' );
		$this->service()->restore( $id );
		$this->assert_same( 1, $this->audit_count( 'catalog.game_type.restored', $id ), 'restore audit recorded' );

		$before = $this->audit_count_all( $id );
		$this->service()->rename( $id, self::PREFIX . ' Audit Renamed' );
		$this->service()->restore( $id );
		$this->assert_same( $before, $this->audit_count_all( $id ), 'no audit for successful no-ops' );

		$failing = new GameTypeService(
			static fn( array $event ) => new WP_Error( 'forced_audit_failure', 'Forced ordinary audit failure.' )
		);
		$result = $failing->create( self::PREFIX . ' Audit Warning' );
		$this->track( self::SUNRISE, $result );
		$this->assert_result( 'game_type_created', $result, 'domain succeeds when ordinary audit fails' );
		$this->assert_same( 'audit_write_failed', $result->warnings[0]['code'] ?? '', 'audit failure warning surfaced' );
		$this->assert_true( $this->service()->getById( $result->game_type['game_type_id'] )['game_type_id'] === $result->game_type['game_type_id'], 'audit failure does not undo domain row' );
	}

	private function tenant_isolation(): void {
		$sunrise = $this->create( self::SUNRISE, self::PREFIX . ' Hall Isolation' );
		$tampa   = $this->create( self::TAMPA, self::PREFIX . ' Hall Isolation' );
		$this->assert_result( 'game_type_created', $sunrise, 'Sunrise isolated create' );
		$this->assert_result( 'game_type_created', $tampa, 'Tampa same-name create' );
		$this->assert_same( $sunrise->game_type['code'], $tampa->game_type['code'], 'same code allowed independently per hall' );

		$this->actor( self::TAMPA, $this->super_admin_id );
		$rows = $this->service()->listForAdministration();
		$this->assert_true( is_array( $rows ), 'Tampa list succeeds' );
		$this->assert_true( 1 === count( array_filter( $rows, fn( array $row ) => self::PREFIX . ' Hall Isolation' === $row['name'] ) ), 'Tampa sees only its local same-name row' );

		$unique_sunrise = $this->create( self::SUNRISE, self::PREFIX . ' Sunrise Only' );
		$this->actor( self::TAMPA, $this->super_admin_id );
		$this->assert_true( 0 === count( array_filter( $this->service()->listForAdministration(), fn( array $row ) => self::PREFIX . ' Sunrise Only' === $row['name'] ) ), 'Sunrise Game Type invisible from Tampa' );
		if ( ! $this->local_id_exists( self::TAMPA, $unique_sunrise->game_type['game_type_id'] ) ) {
			$this->assert_error( 'game_type_not_found', $this->service()->rename( $unique_sunrise->game_type['game_type_id'], self::PREFIX . ' Forged Cross Hall' ), 'Tampa cannot mutate absent Sunrise ID' );
		}
	}

	private function concurrency(): void {
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$this->assert_race_capture_is_id_only();
		$same = $this->run_create_race( [ self::PREFIX . ' Concurrent Same', self::PREFIX . ' Concurrent Same' ] );
		sort( $same );
		$this->assert_true( 1 === count( array_filter( $same, fn( string $v ) => str_starts_with( $v, 'game_type_created:' ) ) ), 'concurrent same-name has one winner' );
		$this->assert_true( in_array( 'ERROR:duplicate_name', $same, true ), 'concurrent same-name loser is duplicate_name' );

		$codes = $this->run_create_race( [ self::PREFIX . ' Concurrent/Code', self::PREFIX . ' Concurrent Code' ] );
		$this->assert_true( 2 === count( array_filter( $codes, fn( string $v ) => str_starts_with( $v, 'game_type_created:' ) ) ), 'concurrent code collision creates both distinct names' );
		$actual_codes = array_map( fn( string $v ) => (string) ( explode( ':', $v )[2] ?? '' ), $codes );
		$this->assert_same( 2, count( array_unique( $actual_codes ) ), 'concurrent code collision resolves to unique codes' );

		// The forced code-collision scenario attaches a trigger to
		// bingo_game_types, so it runs on a disposable hall. The behaviour under
		// test — bounded code-allocation retries exhausting deterministically —
		// is identical on any hall.
		global $wpdb;
		$hall = $this->schema_hall();
		if ( $hall < 1 ) {
			return;
		}

		$forced = $this->create( $hall, self::PREFIX . ' Forced Code' );
		$this->assert_result( 'game_type_created', $forced, 'forced-collision fixture' );

		$this->actor( $hall, $this->super_admin_id );
		$table = Migrations::game_types_table();
		$this->disposable_ddl( $hall, 'DROP TRIGGER IF EXISTS bc_gti1_force_code' );
		$this->disposable_ddl( $hall, "CREATE TRIGGER bc_gti1_force_code BEFORE INSERT ON `{$table}` FOR EACH ROW SET NEW.code = '" . esc_sql( $forced->game_type['code'] ) . "'" );
		try {
			$this->assert_error( 'code_allocation_conflict', $this->service()->create( self::PREFIX . ' Retry Budget' ), 'bounded code-collision retries exhaust deterministically' );
		} finally {
			$this->disposable_ddl( $hall, 'DROP TRIGGER IF EXISTS bc_gti1_force_code' );
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
		}
	}

	/** @return array<int,string> */
	/**
	 * Cleanup-safety regression for the concurrency ownership protocol.
	 *
	 * A previous revision captured ownership by querying the fixture NAME after
	 * the race, so a legitimate pre-existing Sunrise Game Type sharing that name
	 * would have been registered and deleted by cleanup once the worker returned
	 * duplicate_name. This plants exactly that row, runs the real race path
	 * against it, and requires the planted row to be neither captured nor
	 * touched. The row is removed afterwards by this test, as its own
	 * responsibility.
	 */
	private function assert_race_capture_is_id_only(): void {
		global $wpdb;

		$name = self::PREFIX . ' Ownership Sentinel ' . wp_generate_password( 6, false );

		// Planted directly through the service and DELIBERATELY NOT tracked, so
		// it stands in for a legitimate hall row this run does not own.
		$this->actor( self::SUNRISE, $this->super_admin_id );
		$planted = $this->service()->create( $name );
		$this->assert_result( 'game_type_created', $planted, 'unowned ownership-sentinel Game Type planted' );
		if ( ! $planted instanceof GameTypeResult ) {
			return;
		}
		$sentinel_id = (int) $planted->game_type['game_type_id'];

		$tracked_before = $this->created_ids[ self::SUNRISE ] ?? [];

		// The real race path, against a name that already exists. The worker must
		// lose and therefore contribute no owned id.
		$results = $this->run_create_race( [ $name ] );
		$this->assert_same( [ 'ERROR:duplicate_name' ], $results, 'a worker racing an existing name loses with duplicate_name' );

		$tracked_after = $this->created_ids[ self::SUNRISE ] ?? [];
		$this->assert_same( $tracked_before, $tracked_after, 'a losing worker registers NO id for cleanup' );
		$this->assert_true( ! in_array( $sentinel_id, $tracked_after, true ), 'the pre-existing row sharing the fixture name was never captured' );

		$this->actor( self::SUNRISE, $this->super_admin_id );
		$this->assert_same(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::game_types_table() . ' WHERE game_type_id = %d', $sentinel_id ) ),
			'the unowned row survived the race path intact'
		);

		// Removed explicitly by this test, never by name-based cleanup.
		$wpdb->delete( Migrations::game_types_table(), [ 'game_type_id' => $sentinel_id ], [ '%d' ] );
		$wpdb->delete(
			CoreMigrations::audit_log_table(),
			[ 'blog_id' => self::SUNRISE, 'target_type' => 'game_type', 'target_id' => (string) $sentinel_id ],
			[ '%d', '%s', '%s' ]
		);
		$this->assert_same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::game_types_table() . ' WHERE game_type_id = %d', $sentinel_id ) ),
			'the sentinel was removed explicitly by its own test'
		);
	}

	private function run_create_race( array $names ): array {
		$start = microtime( true ) + 0.7;
		$root = dirname( __DIR__ );
		$worker = __DIR__ . '/game-type-create-worker.php';
		$processes = [];
		$pipes = [];

		foreach ( $names as $key => $name ) {
			$processes[ $key ] = proc_open(
				[ PHP_BINARY, $worker, (string) self::SUNRISE, (string) $this->super_admin_id, $name, (string) $start ],
				[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
				$pipes[ $key ],
				$root
			);
		}

		$results = [];
		foreach ( $processes as $key => $process ) {
			$output = stream_get_contents( $pipes[ $key ][1] );
			$error  = stream_get_contents( $pipes[ $key ][2] );
			fclose( $pipes[ $key ][1] );
			fclose( $pipes[ $key ][2] );
			$exit = proc_close( $process );
			$this->assert_same( 0, $exit, 'concurrency worker exits cleanly: ' . trim( $error ) );
			$results[] = trim( $output );
		}

		// OWNERSHIP PROTOCOL: the only ids this run registers for cleanup are the
		// ones a worker reported after IT successfully created the row. A worker
		// that lost the race (duplicate_name, or any other error) contributes
		// nothing, so a legitimate pre-existing Game Type that merely shares the
		// fixture name is never captured and never deleted. Name, code, prefix
		// and timestamp are NOT ownership proof and are not consulted.
		foreach ( $results as $line ) {
			if ( 1 === preg_match( '/^game_type_created:(\d+):/', $line, $matches ) ) {
				$this->created_ids[ self::SUNRISE ][] = (int) $matches[1];
			}
		}

		return $results;
	}

	/**
	 * Damage the Game Type name collation so provisioning verification fails,
	 * and prove the blog context is restored anyway.
	 *
	 * Runs on a disposable hall. An earlier revision damaged TAMPA's collation
	 * and relied on a finally block to put it back, which a crash would leave
	 * broken on a persistent hall.
	 */
	private function assert_failed_provisioning_restores_context(): void {
		global $wpdb;

		$hall = $this->schema_hall();
		if ( $hall < 1 ) {
			return;
		}

		$this->actor( $hall, $this->super_admin_id );
		$table          = Migrations::game_types_table();
		$table_status   = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		$good_collation = (string) ( $table_status['Collation'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/i', $good_collation ) ) {
			$this->assert_true( false, 'could not read the disposable hall Game Type collation' );

			return;
		}
		$this->disposable_ddl( $hall, "ALTER TABLE `{$table}` MODIFY name varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL" );
		delete_option( Migrations::VERSION_OPTION );

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		switch_to_blog( self::SUNRISE );
		$before = get_current_blog_id();
		try {
			$result = Migrations::provision_new_site( get_site( $hall ) );
			$this->assert_error( 'catalog_migration_verification_failed', $result, 'incompatible collation fails provisioning verification' );
			$this->assert_same( $before, get_current_blog_id(), 'failed provisioning restores blog context' );
		} finally {
			restore_current_blog();
		}

		// Repair the disposable hall so the later structural scenarios still have
		// a usable table. Its schema is disposable either way.
		switch_to_blog( $hall );
		try {
			$this->disposable_ddl( $hall, "ALTER TABLE `{$table}` MODIFY name varchar(160) CHARACTER SET utf8mb4 COLLATE {$good_collation} NOT NULL" );
			$this->assert_true( true === Migrations::migrate_current_site(), 'failed-provisioning fixture recovers idempotently' );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Damage and repair the Game Type index on a disposable hall.
	 *
	 * Identical behaviour to the previous persistent-hall version: drop the
	 * status_name index, prove verification fails and leaves the version stale,
	 * then prove dbDelta repairs it.
	 */
	private function assert_missing_index_is_repaired(): void {
		global $wpdb;

		$hall = $this->schema_hall();
		if ( $hall < 1 ) {
			return;
		}

		switch_to_blog( $hall );
		try {
			$table = Migrations::game_types_table();
			$this->disposable_ddl( $hall, "ALTER TABLE `{$table}` DROP INDEX status_name" );
			delete_option( Migrations::VERSION_OPTION );
			$this->assert_error( 'catalog_migration_verification_failed', Migrations::verify_current_site_schema(), "disposable hall {$hall} missing index detected" );
			$this->assert_same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), "disposable hall {$hall} failed verification leaves version stale" );
			$this->assert_true( true === Migrations::migrate_current_site(), "disposable hall {$hall} dbDelta repairs missing index" );
			$this->assert_true( true === Migrations::verify_current_site_schema(), "disposable hall {$hall} verifies after repair" );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * A disposable hall kept alive for every structural Game Type scenario.
	 */
	/** Structural DDL, permitted on a disposable hall only. */
	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
	}

	private function schema_hall(): int {
		if ( $this->schema_hall > 0 ) {
			return $this->schema_hall;
		}

		$site_id = $this->create_disposable_site();
		if ( $site_id > 0 ) {
			$this->schema_hall = $site_id;
		}

		return $site_id;
	}

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'gti1disposable' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'GTI1 Disposable Schema Hall',
				'user_id'    => $this->super_admin_id,
			]
		);

		if ( is_wp_error( $site ) ) {
			$this->assert_true( false, 'disposable schema hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$this->disposable_sites[ (int) $site ] = true;

		return (int) $site;
	}

	/**
	 * The single gate that keeps every structural mutation in this suite off a
	 * persistent hall.
	 */
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

	private function destroy_disposable_site( int $site_id ): void {
		global $wpdb;

		if ( $site_id < 1 || empty( $this->disposable_sites[ $site_id ] ) ) {
			return;
		}

		switch_to_blog( $site_id );
		foreach ( self::SUITE_TRIGGERS as $trigger ) {
			$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
		}
		// Include the Session tables provisioned for this run's disposable hall.
		// WordPress does not remove these custom tables when deleting the site.
		$catalog_tables = [
			Migrations::package_addons_table(),
			Migrations::package_components_table(),
			Migrations::packages_table(),
			Migrations::play_products_table(),
			Migrations::game_types_table(),
			$wpdb->prefix . 'bingo_sessions',
			$wpdb->prefix . 'bingo_session_packages',
			$wpdb->prefix . 'bingo_portal_session_templates',
			$wpdb->prefix . 'bingo_session_schedules',
			$wpdb->prefix . 'bingo_session_schedule_dates',
			$wpdb->prefix . 'bingo_session_schedule_packages',
		];
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) );
		restore_current_blog();

		wp_delete_site( $site_id );

		foreach ( $catalog_tables as $table ) {
			// Exact, site-scoped identifier. Anything else is refused.
			if ( 1 === preg_match( '/^bc_' . $site_id . '_bingo_(package_addons|package_components|packages|play_products|game_types|sessions|session_packages|portal_session_templates|session_schedules|session_schedule_dates|session_schedule_packages)$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		$this->disposable_catalog_tables[ $site_id ] = $catalog_tables;
		unset( $this->disposable_sites[ $site_id ] );
		unset( $this->created_ids[ $site_id ] );
	}

	private function destroy_all_disposable_sites(): void {
		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->destroy_disposable_site( (int) $site_id );
		}

		$this->schema_hall = 0;
	}

	private function verify_disposable_teardown(): void {
		global $wpdb;

		$this->assert_same( [], $this->disposable_sites, 'every disposable hall this run created was destroyed' );
		$this->assert_same(
			[],
			array_intersect( array_keys( $this->disposable_catalog_tables ), [ 1, self::SUNRISE, self::TAMPA, 4 ] ),
			'no persistent hall was ever registered as disposable'
		);

		foreach ( $this->disposable_catalog_tables as $site_id => $tables ) {
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ),
				"disposable hall {$site_id}: the site record was removed"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_bingo_' ) . '%' ) ),
				"disposable hall {$site_id}: no hall-local Catalog table survives"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND event_object_table LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_' ) . '%' ) ),
				"disposable hall {$site_id}: no trigger remains attached to any of its tables"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) ),
				"disposable hall {$site_id}: no audit rows remain"
			);
		}

		$this->assert_same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND trigger_name LIKE %s', DB_NAME, 'bc_gti1_%' ) ),
			'no Game Type test trigger remains anywhere'
		);
		$this->assert_same(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE domain LIKE 'gti1disposable%'" ),
			'no disposable Game Type hall remains'
		);
	}

	private function cleanup(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		global $wpdb;

		// No DROP TRIGGER against a persistent hall. This suite never attaches a
		// trigger to Sunrise or Tampa, so there is nothing there to drop, and
		// issuing one as routine cleanup would imply otherwise. Disposable halls
		// drop their own triggers inside destroy_disposable_site().
		$this->destroy_all_disposable_sites();

		foreach ( $this->created_ids as $site_id => $ids ) {
			switch_to_blog( $site_id );
			try {
				$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
				if ( [] !== $ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Migrations::game_types_table() . " WHERE game_type_id IN ({$placeholders})", $ids ) );
				}
			} finally {
				restore_current_blog();
			}
		}

		$this->verify_disposable_teardown();
	}

	private function service(): GameTypeService {
		return new GameTypeService();
	}

	/** @return GameTypeResult|WP_Error */
	private function create( int $site_id, string $name ) {
		$this->actor( $site_id, $this->super_admin_id );
		$result = $this->service()->create( $name );
		$this->track( $site_id, $result );

		return $result;
	}

	/** @param GameTypeResult|WP_Error $result */
	private function track( int $site_id, $result ): void {
		if ( $result instanceof GameTypeResult ) {
			$this->created_ids[ $site_id ][] = (int) $result->game_type['game_type_id'];
		}
	}

	private function actor( int $site_id, int $user_id ): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		switch_to_blog( $site_id );
		wp_set_current_user( $user_id );
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

	private function first_user_with_role( string $role, int $site_id ): int {
		$this->actor( $site_id, $this->super_admin_id );
		$ids = get_users( [ 'role' => $role, 'number' => 1, 'fields' => 'ID' ] );

		return $ids ? (int) $ids[0] : 0;
	}

	private function local_id_exists( int $site_id, int $id ): bool {
		$this->actor( $site_id, $this->super_admin_id );
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::game_types_table() . ' WHERE game_type_id = %d', $id ) ) > 0;
	}

	private function audit_count( string $action, int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND action = %s AND target_type = %s AND target_id = %s',
				get_current_blog_id(),
				$action,
				'game_type',
				(string) $id
			)
		);
	}

	private function audit_count_all( int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND target_type = %s AND target_id = %s AND action LIKE %s',
				get_current_blog_id(),
				'game_type',
				(string) $id,
				'catalog.game_type.%'
			)
		);
	}

	private function assert_true( bool $condition, string $label ): void {
		++$this->assertions;
		if ( ! $condition ) {
			throw new RuntimeException( "FAIL: {$label}" );
		}
	}

	/** @param mixed $expected @param mixed $actual */
	private function assert_same( $expected, $actual, string $label ): void {
		$this->assert_true( $expected === $actual, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
	}

	/** @param mixed $actual */
	private function assert_error( string $code, $actual, string $label ): void {
		$this->assert_true( is_wp_error( $actual ) && $code === $actual->get_error_code(), $label . ' (got ' . $this->describe( $actual ) . ')' );
	}

	/** @param mixed $actual */
	private function assert_result( string $status, $actual, string $label ): void {
		$this->assert_true( $actual instanceof GameTypeResult && $status === $actual->status, $label . ' (got ' . $this->describe( $actual ) . ')' );
	}

	/** @param mixed $value */
	private function describe( $value ): string {
		if ( is_wp_error( $value ) ) {
			return 'WP_Error:' . $value->get_error_code() . ':' . $value->get_error_message();
		}
		if ( $value instanceof GameTypeResult ) {
			return 'GameTypeResult:' . $value->status;
		}

		return get_debug_type( $value );
	}
}

( new GameTypeIncrementOneTest() )->run();
