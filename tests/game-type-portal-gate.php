<?php

/**
 * CLI integration coverage for the Hall Manager UX correction's Game Type
 * Portal gate: a hall authoritatively connected to Portal cannot render or
 * POST a local Create Game Type, while a genuinely non-Portal/legacy hall's
 * behavior is completely unchanged.
 *
 * The enforcement under test lives in GameTypeService::create() itself —
 * GameTypeAdmin hiding the create form is a courtesy, not the gate — so this
 * suite exercises BOTH the real admin screen (including a forged POST) and a
 * direct GameTypeService::create() call, against the SAME
 * HallMappingRepository::findActiveByBlogId() signal Portal Sync itself
 * relies on.
 *
 * ISOLATED DATABASE ONLY. This suite creates disposable Multisite sites, so it
 * must never boot the development installation.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_recon php tests/game-type-portal-gate.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$bc_target = (string) getenv( 'BC_TEST_DB' );
if ( '' === $bc_target ) {
	fwrite( STDERR, "BC_TEST_DB is not set. This suite creates disposable sites, so it must\n" );
	fwrite( STDERR, "never run against the development database.\n" );
	fwrite( STDERR, "  BC_TEST_DB=bingocommerce_state_recon php tests/game-type-portal-gate.php\n" );
	exit( 1 );
}
if ( ! in_array( $bc_target, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "BC_TEST_DB='{$bc_target}' is not an isolated fixture database for this suite.\n" );
	exit( 1 );
}
unset( $bc_target );

$_SERVER['REQUEST_URI']    = '/wp-admin/edit.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! defined( 'DB_NAME' ) || ! in_array( DB_NAME, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "Refusing to run: the active database is not an isolated fixture.\n" );
	exit( 1 );
}
if ( ! class_exists( '\BingoCommerce\Catalog\GameTypeService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallMappingRepository' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}
if ( false === has_action( 'admin_init', [ \BingoCommerce\PortalIntegration\Migrations::class, 'maybe_migrate_network' ] ) ) {
	\BingoCommerce\PortalIntegration\Plugin::init();
}
// The one network-wide mapping table this plugin owns is normally migrated on
// `admin_init`; this CLI run never fires that hook, so it is migrated
// directly instead — the same approach tests/portal-integration-foundation.php
// uses for the same reason.
\BingoCommerce\PortalIntegration\Migrations::migrate_network();

use BingoCommerce\Catalog\GameTypeAdmin;
use BingoCommerce\Catalog\GameTypeResult;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations as CatalogMigrations;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations as PortalMigrations;

/** A captured wp_die(), so a nonce or authorization refusal never aborts the run. */
final class GameTypeGateAdminDied extends \RuntimeException {}

final class GameTypePortalGateTest {

	private const PERSISTENT   = [ 2, 3, 4 ];
	private const PREFIX       = 'GTPG1';
	private const NONCE_ACTION = 'bingocommerce_game_type_mutation';

	private int $super_admin_id = 0;

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
	private array $groups = [];

	public function run(): void {
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );

		add_filter( 'wp_die_handler', [ $this, 'die_handler' ], 1 );

		try {
			$this->portal_connected_group();
			$this->legacy_group();
		} finally {
			remove_filter( 'wp_die_handler', [ $this, 'die_handler' ], 1 );
			$this->cleanup();
		}

		$this->report();
	}

	/* ================================================================== *
	 * A Hall authoritatively connected to Portal
	 * ================================================================== */

	private function portal_connected_group(): void {
		$this->group( 'Portal-connected Hall' );

		$hall = $this->create_disposable_site();
		$this->bind_to_portal( $hall );
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );

		$this->ok( GameTypeService::is_hall_portal_connected(), 'is_hall_portal_connected() reports true for a bound hall' );

		/* --- GET: the create form is absent; the Portal-managed notice is present. --- */
		$html = $this->render_get();
		$this->ok(
			false !== strpos( $html, 'Game Types are managed in Bingo Portal and updated through Portal Sync.' ),
			'the exact required Portal-managed message is shown'
		);
		$this->ok( false === strpos( $html, 'id="bc-game-type-name"' ), 'the Create Game Type name field is NOT rendered' );
		$this->ok( false === strpos( $html, 'Create Game Type</span' ), '  and no Create Game Type submit control renders as a span-wrapped label' );
		$this->ok( false === strpos( $html, 'value="create"' ), '  and no form posts bc_game_type_action=create at all' );

		/* --- A FORGED POST is refused, not just hidden. --- */
		$before = $this->service()->listForAdministration( 'all' );
		$this->ok( is_array( $before ) && [] === $before, 'no Game Type exists yet in this hall' );

		$forged = $this->render_post(
			[
				'bc_game_type_action' => 'create',
				'name'                => self::PREFIX . ' Forged Bingo',
			]
		);
		$this->ok(
			false !== strpos( $forged, 'Game Types are managed in Bingo Portal and updated through Portal Sync. This hall cannot create one locally.' ),
			'the forged POST is refused with the exact enforcement message, not silently accepted'
		);
		$this->ok( false !== strpos( $forged, 'notice-error' ), '  rendered as an error notice' );

		$after = $this->service()->listForAdministration( 'all' );
		$this->same( [], is_array( $after ) ? $after : [ 'not-an-array' ], 'no Game Type row exists after the forged POST — the refusal is not cosmetic' );

		/* --- The refusal is enforced in the service, not only in the admin screen. --- */
		$direct = ( new GameTypeService() )->create( self::PREFIX . ' Direct Bingo' );
		$this->ok( is_wp_error( $direct ), 'GameTypeService::create() itself refuses, independent of GameTypeAdmin' );
		$this->same( 'game_type_portal_managed', is_wp_error( $direct ) ? $direct->get_error_code() : '', '  carrying the documented error code' );

		$still_empty = $this->service()->listForAdministration( 'all' );
		$this->same( [], is_array( $still_empty ) ? $still_empty : [ 'not-an-array' ], '  and still no row was written' );

		/*
		 * (2, continued) An existing SYNCED Game Type — seeded directly, as
		 * Portal Sync's own insert_game_type() transport would, never through
		 * create() — remains fully manageable. The gate sits in create() only;
		 * rename/archive/restore are untouched, so a connected hall can still
		 * maintain the names/lifecycle of what Portal Sync already wrote.
		 */
		$synced_id = $this->seed_synced_game_type( self::PREFIX . ' Synced Bingo' );
		$this->ok( $synced_id > 0, 'a Portal-synced Game Type fixture exists (seeded the way Portal Sync writes it, not via create())' );

		$renamed = ( new GameTypeService() )->rename( $synced_id, self::PREFIX . ' Synced Bingo Renamed' );
		$this->ok(
			$renamed instanceof GameTypeResult && 'game_type_renamed' === $renamed->status,
			'  rename() still works on a connected hall\'s existing synced Game Type'
		);

		$archived = ( new GameTypeService() )->archive( $synced_id );
		$this->ok(
			$archived instanceof GameTypeResult && 'game_type_archived' === $archived->status,
			'  and archive() still works too'
		);

		$restored = ( new GameTypeService() )->restore( $synced_id );
		$this->ok(
			$restored instanceof GameTypeResult && 'game_type_restored' === $restored->status,
			'  and restore() still works too'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * A genuinely non-Portal / legacy Hall — unchanged behavior
	 * ================================================================== */

	private function legacy_group(): void {
		$this->group( 'Legacy hall (unchanged)' );

		$hall = $this->create_disposable_site();
		// Deliberately NOT bound to Portal — no HallMappingRepository row at all.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );

		$this->ok( ! GameTypeService::is_hall_portal_connected(), 'is_hall_portal_connected() reports false for an unmapped hall' );

		$html = $this->render_get();
		$this->ok( false === strpos( $html, 'Game Types are managed in Bingo Portal' ), 'the Portal-managed notice is absent' );
		$this->ok( false !== strpos( $html, 'id="bc-game-type-name"' ), 'the Create Game Type name field renders normally' );
		$this->ok( false !== strpos( $html, 'name="bc_game_type_action" value="create"' ), '  with its normal create control' );

		$created = $this->render_post(
			[
				'bc_game_type_action' => 'create',
				'name'                => self::PREFIX . ' Legacy Bingo',
			]
		);
		$this->ok( false !== strpos( $created, 'Game Type created.' ), 'a genuine create POST succeeds exactly as before' );
		$this->ok( false !== strpos( $created, 'notice-success' ), '  as a success notice' );

		$rows = $this->service()->listForAdministration( 'all' );
		$this->same( 1, is_array( $rows ) ? count( $rows ) : -1, '  and exactly one Game Type row now exists' );
		$this->same(
			self::PREFIX . ' Legacy Bingo',
			is_array( $rows ) && [] !== $rows ? (string) $rows[0]['name'] : '',
			'  with the posted name'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * Screen drivers
	 * ================================================================== */

	private function service(): GameTypeService {
		return new GameTypeService();
	}

	private function render_get(): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];

		ob_start();
		try {
			GameTypeAdmin::render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/** @param array<string,mixed> $post */
	private function render_post( array $post ): string {
		$post['_wpnonce'] = wp_create_nonce( self::NONCE_ACTION );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = [];
		$_POST                     = $post;
		$_REQUEST                  = $post;

		ob_start();
		try {
			GameTypeAdmin::render();
		} catch ( GameTypeGateAdminDied $died ) {
			ob_get_clean();

			return 'DIED: ' . $died->getMessage();
		} finally {
			$html = ob_get_clean() ?: '';
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_REQUEST                  = [];

		return $html;
	}

	/** @internal Public because WordPress needs a callable. */
	public function die_handler(): callable {
		return static function ( $message ) {
			throw new GameTypeGateAdminDied( is_string( $message ) ? $message : 'wp_die' );
		};
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	private function bind_to_portal( int $blog_id ): void {
		static $next = 0;
		++$next;
		$portal_hall_id = 970000 + $next;

		$mapping = ( new HallMappingRepository() )->bind( $portal_hall_id, $blog_id );
		$this->ok( ! is_wp_error( $mapping ), 'fixture: hall bound to a Portal Hall id' );
	}

	private function seed_synced_game_type( string $name ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			CatalogMigrations::game_types_table(),
			[
				'code'            => 'SYN' . wp_generate_password( 6, false ),
				'name'            => $name,
				'local_name_key'  => $name,
				'status'          => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
				'archived_at'     => null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', null ]
		);

		return (int) $wpdb->insert_id;
	}

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'gtpg1' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'GTPG1 Disposable Hall',
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->ok( false, 'disposable hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$this->disposable_sites[ (int) $site ] = true;

		return (int) $site;
	}

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( in_array( $site_id, self::PERSISTENT, true ) || 1 === $site_id ) {
				$this->ok( false, "refusing to delete non-disposable site {$site_id}" );
				continue;
			}
			/*
			 * The Portal Hall mapping this suite binds is a NETWORK-WIDE row
			 * (blog_id => portal_hall_id), not per-site data, so deleting the
			 * site alone leaves it behind — and it would then collide with the
			 * next run's disposable site reusing the same portal_hall_id
			 * counter. Same cleanup this suite's own precedent uses
			 * (tests/portal-integration-foundation.php).
			 */
			$wpdb->delete( PortalMigrations::hall_mappings_table(), [ 'blog_id' => $site_id ], [ '%d' ] );
			if ( get_site( $site_id ) instanceof \WP_Site ) {
				wp_delete_site( $site_id );
			}
		}
		$this->disposable_sites = [];
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

	/* ================================================================== *
	 * Reporting
	 * ================================================================== */

	private function group( string $name ): void {
		$this->group = $name;
		if ( ! isset( $this->groups[ $name ] ) ) {
			$this->groups[ $name ] = [ 'pass' => 0, 'fail' => 0 ];
		}
	}

	private function ok( bool $condition, string $message ): void {
		if ( $condition ) {
			++$this->groups[ $this->group ]['pass'];

			return;
		}

		++$this->groups[ $this->group ]['fail'];
		fwrite( STDERR, "FAIL [{$this->group}] {$message}\n" );
	}

	/** @param mixed $expected @param mixed $actual */
	private function same( $expected, $actual, string $message ): void {
		$this->ok(
			$expected === $actual,
			$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
		);
	}

	private function report(): void {
		$pass = 0;
		$fail = 0;

		echo str_repeat( '-', 72 ), "\n";
		foreach ( $this->groups as $name => $counts ) {
			$pass += $counts['pass'];
			$fail += $counts['fail'];
			printf(
				"  %-34s %4d assertions   %s\n",
				$name,
				$counts['pass'] + $counts['fail'],
				0 === $counts['fail'] ? 'PASS' : 'FAIL (' . $counts['fail'] . ')'
			);
		}
		echo str_repeat( '-', 72 ), "\n";
		printf( "  %-34s %4d assertions   %s\n", 'TOTAL', $pass + $fail, 0 === $fail ? 'PASS' : 'FAIL' );

		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

( new GameTypePortalGateTest() )->run();
