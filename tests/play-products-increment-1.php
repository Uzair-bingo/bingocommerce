<?php

/**
 * CLI integration coverage for BingoCommerce Bingo Play Product Increment 1.
 *
 * Exercises the real database: the Catalog v1 -> v2 migration, the ADR-032
 * critical-serialization transport, the ordinary $wpdb conditional writers, and
 * genuine multi-process concurrency against local MySQL.
 *
 * Run from the repository root:
 * php tests/play-products-increment-1.php
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
require_once ABSPATH . 'wp-admin/includes/screen.php';
if ( ! class_exists( '\BingoCommerce\Catalog\BingoPlayProductService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CatalogPlayProductPlan;
use BingoCommerce\Catalog\CatalogTransactionTransport;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PackageAdmin;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Catalog\PlayProductAdmin;
use BingoCommerce\Catalog\PlayProductResult;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;

/**
 * COMMIT is never sent to the server, and the transport is told the
 * acknowledgement failed. This is the NOT-committed acknowledgement-failure
 * case: the transaction is still open and dies with the connection.
 */
final class BcUnacknowledgedCommitConnection extends mysqli {

	public function commit( int $flags = 0, ?string $name = null ): bool {
		return false;
	}
}

/**
 * COMMIT really is sent and really does commit, but the transport is told the
 * acknowledgement failed. This is the committed-but-unknown case, and it is the
 * reason current Catalog state can never be used to reconcile an ambiguous
 * COMMIT: the row exists, yet this specific operation cannot claim it.
 */
final class BcCommittedButUnknownConnection extends mysqli {

	public function commit( int $flags = 0, ?string $name = null ): bool {
		parent::commit( $flags, $name );

		return false;
	}
}

/**
 * A connection whose ROLLBACK never succeeds, counting how many transactions
 * were actually begun on it.
 */
final class BcRollbackFailureConnection extends mysqli {

	public int $begin_count = 0;

	public function begin_transaction( int $flags = 0, ?string $name = null ): bool {
		++$this->begin_count;

		return parent::begin_transaction( $flags, $name );
	}

	public function rollback( int $flags = 0, ?string $name = null ): bool {
		return false;
	}
}

final class PlayProductIncrementOneTest {

	private const SUNRISE = 2;
	private const TAMPA   = 3;
	private const PREFIX  = 'PPI1';

	/**
	 * Durable proof of suite ownership.
	 *
	 * A title prefix is NOT ownership: a Hall Manager may legitimately create a
	 * product called "PPI1 Anything". Every product this suite creates carries
	 * this post meta, so a previous interrupted run's artefacts can be identified
	 * conclusively rather than guessed at from their name.
	 */
	private const OWNER_META = '_bc_ppi1_test_artifact';

	private int $assertions = 0;
	private int $failures   = 0;
	private int $super_admin_id = 0;

	/** @var array<int,array<int,int>> site => wc product ids */
	private array $created_products = [];

	/** Every trigger name this suite may create. All of them live on disposable halls only. */
	private const SUITE_TRIGGERS = [ 'bc_pp_kill_probe', 'bc_pp_anchor_probe', 'bc_pp_slow_probe' ];

	/** @var array<int,array<int,int>> site => game type ids */
	private array $created_game_types = [];

	/** @var array<int,array<int,int>> site => auto-draft product IDs that existed before this run */
	private array $baseline_auto_drafts = [];

	/** @var array<int,array<int,string>> disposable site => the Catalog tables it owned */
	private array $disposable_catalog_tables = [];

	/**
	 * Every disposable hall this run created, by id.
	 *
	 * A disposable hall is the ONLY place this suite may perform structural SQL.
	 * Persistent Sunrise/Tampa/Orlando take ordinary domain DML against tracked
	 * fixture rows and nothing else, so a crash at any point leaves their schema
	 * exactly as it was.
	 *
	 * @var array<int,bool>
	 */
	private array $disposable_sites = [];

	/** Long-lived disposable hall for the trigger-driven concurrency scenarios. */
	private int $concurrency_hall = 0;

	/** @var array<int,int> the two disposable halls used for the shared-product-ID scenario */
	private array $shared_id_halls = [];

	/** @var array<int,bool> site => this run created the bingo_packages stub */
	private array $package_stub_created = [];

	/** @var array<int,array<int,int>> site => Play Product rows left alone because this suite does not own them */
	private array $foreign_play_products = [];

	/** Unique per-run token stamped on every artefact this run creates. */
	private string $run_token = '';

	/** @var array<int,array<int,string>> site => residue this suite refuses to delete */
	private array $unownable_residue = [];

	/** Disposable Multisite hall used for destructive migration testing. */
	private int $disposable_site_id = 0;

	/** @var array<int,string> */
	private array $notes = [];

	public function run(): void {
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );

		// The migration group drops bingo_play_products, which resets its
		// AUTO_INCREMENT. If a previous run was interrupted before cleanup, its
		// leftover rows would collide with freshly minted IDs and poison every
		// per-ID audit assertion. Purge first so the suite is self-healing.
		wp_set_current_user( $this->super_admin_id );
		$this->foreign_play_products = $this->purge_residue();
		$this->report_foreign_residue();
		$this->report_unownable_residue();
		$this->record_baseline_auto_drafts();

		$roles_before = $this->role_snapshot();

		try {
			$this->cleanup_safety();
			$this->migration_and_provisioning();
			$this->authorization();
			$this->creation();
			$this->medium_transitions();
			$this->game_type_rules();
			$this->lifecycle();
			$this->selection_reads();
			$this->audit_behavior();
			$this->tenant_isolation();
			$this->package_claim_behavior();
			$this->transport_fail_closed();
			$this->rollback_failure_never_retries();
			$this->no_translation_callbacks_inside_transaction();
			$this->ambiguous_commit();
			$this->concurrency();
			$this->woo_contention();
			$this->ordinary_writer_contention();
			$this->admin_surface();
		} finally {
			$this->cleanup();
		}

		$this->assert_same( $roles_before, $this->role_snapshot(), 'role/capability records unchanged by the suite' );

		foreach ( $this->notes as $note ) {
			echo "NOTE: {$note}\n";
		}

		if ( $this->failures > 0 ) {
			echo "FAIL: {$this->failures} of {$this->assertions} Bingo Play Product Increment 1 assertions failed.\n";
			exit( 1 );
		}

		echo "PASS: {$this->assertions} Bingo Play Product Increment 1 assertions.\n";
	}

	/* ==================================================================== *
	 * 0. Cleanup safety — MUST run before anything else touches a hall
	 * ==================================================================== */

	/**
	 * Prove the suite's cleanup cannot delete hall data it does not own.
	 *
	 * A previous revision of this suite ran an unqualified
	 * `DELETE FROM bingo_play_products` plus a hall-wide Play Product audit
	 * delete, and destroyed real manually-created rows. This regression exists so
	 * that can never regress silently: it plants an unowned sentinel, runs the
	 * real cleanup path, and requires the sentinel to survive intact.
	 */
	private function cleanup_safety(): void {
		global $wpdb;
		$this->group( 'Cleanup safety (unowned hall data must survive)' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->super_admin_id );
			$service = new BingoPlayProductService();
			$audit   = CoreMigrations::audit_log_table();

			// A sentinel deliberately NOT carrying this suite's prefix, so nothing
			// about it marks it as suite-owned.
			$sentinel_product = new WC_Product_Simple();
			$sentinel_product->set_name( 'ZZ Unowned Sentinel ' . wp_generate_password( 6, false ) );
			$sentinel_product->set_status( 'publish' );
			$sentinel_product->save();
			$sentinel_wc = (int) $sentinel_product->get_id();

			$gt_result   = ( new GameTypeService() )->create( 'ZZ Unowned Sentinel Type ' . wp_generate_password( 5, false ) );
			$sentinel_gt = is_wp_error( $gt_result ) ? 0 : (int) $gt_result->game_type['game_type_id'];
			$this->assert_true( $sentinel_gt > 0, 'unowned sentinel Game Type created' );

			$created = $service->create( $sentinel_wc, 'digital', 4, $sentinel_gt );
			$this->assert_status( 'play_product_created', $created, 'unowned sentinel Play Product created' );
			$sentinel_pp = (int) $created->play_product['bingo_play_product_id'];

			$sentinel_audit = count( $this->audit_actions( $sentinel_pp ) );
			$this->assert_true( $sentinel_audit > 0, 'unowned sentinel has an audit trail' );

			// A LEGITIMATE hall product whose title starts with this suite's prefix.
			// Naming must never authorize deletion: only the durable marker does,
			// and this product deliberately does not carry one.
			$legit = new WC_Product_Simple();
			$legit->set_name( 'PPI1 Legitimate Hall Product ' . wp_generate_password( 6, false ) );
			$legit->set_status( 'publish' );
			$legit->save();
			$legit_wc = (int) $legit->get_id();
			$this->assert_same( '', (string) get_post_meta( $legit_wc, self::OWNER_META, true ), 'the legitimate PPI1-named product carries no ownership marker' );

			$legit_created = $service->create( $legit_wc, 'paper', 3 );
			$this->assert_status( 'play_product_created', $legit_created, 'legitimate PPI1-named product linked as a Play Product' );
			$legit_pp = (int) $legit_created->play_product['bingo_play_product_id'];

			// And a genuinely owned residue row, which cleanup MUST remove.
			$owned_wc = $this->make_product( self::SUNRISE, 'cleanup owned residue' );
			$owned    = $service->create( $owned_wc, 'paper', 2 );
			$this->assert_status( 'play_product_created', $owned, 'suite-owned residue row created' );
			$owned_pp = (int) $owned->play_product['bingo_play_product_id'];

			restore_current_blog();

			// Run the real cleanup path.
			$foreign = $this->purge_residue();

			switch_to_blog( self::SUNRISE );
			$play_products = Migrations::play_products_table();

			$this->assert_same(
				1,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$play_products} WHERE bingo_play_product_id = %d", $sentinel_pp ) ),
				'UNOWNED sentinel Play Product row SURVIVED cleanup'
			);
			$this->assert_same( $sentinel_audit, count( $this->audit_actions( $sentinel_pp ) ), '  and its audit rows survived' );
			$this->assert_true( $sentinel_product instanceof WC_Product && null !== get_post( $sentinel_wc ), '  and its WooCommerce product survived' );
			$this->assert_same(
				1,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::game_types_table() . ' WHERE game_type_id = %d', $sentinel_gt ) ),
				'  and its Game Type survived'
			);
			$this->assert_true(
				in_array( $sentinel_pp, $foreign[ self::SUNRISE ] ?? [], true ),
				'  and cleanup reported it as foreign rather than deleting it'
			);

			$this->assert_same(
				1,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$play_products} WHERE bingo_play_product_id = %d", $legit_pp ) ),
				'LEGITIMATE "PPI1 Legitimate Hall Product" row SURVIVED cleanup — naming is not ownership'
			);
			$this->assert_true( null !== get_post( $legit_wc ), '  and its WooCommerce product survived' );
			$this->assert_true(
				in_array( $legit_pp, $foreign[ self::SUNRISE ] ?? [], true ),
				'  and cleanup reported it as foreign rather than deleting it'
			);

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$play_products} WHERE bingo_play_product_id = %d", $owned_pp ) ),
				'suite-OWNED residue row WAS removed by cleanup'
			);
			$this->assert_same( [], $this->audit_actions( $owned_pp ), '  and its audit rows were removed' );
			$this->assert_null( get_post( $owned_wc ), '  and its WooCommerce product was removed' );

			// Remove the sentinel explicitly, as this test's own responsibility.
			// bc_audit_log is network-wide and these target IDs are hall-local, so
			// both deletes MUST carry blog_id or they would remove another hall's
			// audit trail for the same numeric ID.
			$this->delete_audit_for( self::SUNRISE, 'bingo_play_product', $sentinel_pp );
			$wpdb->delete( $play_products, [ 'bingo_play_product_id' => $sentinel_pp ], [ '%d' ] );
			$this->delete_audit_for( self::SUNRISE, 'game_type', $sentinel_gt );
			$wpdb->delete( Migrations::game_types_table(), [ 'game_type_id' => $sentinel_gt ], [ '%d' ] );
			wp_delete_post( $sentinel_wc, true );

			$this->delete_audit_for( self::SUNRISE, 'bingo_play_product', $legit_pp );
			$wpdb->delete( $play_products, [ 'bingo_play_product_id' => $legit_pp ], [ '%d' ] );
			wp_delete_post( $legit_wc, true );

			$this->assert_null( get_post( $sentinel_wc ), 'the sentinel was removed explicitly by its own test' );
			$this->assert_null( get_post( $legit_wc ), 'the legitimate PPI1-named sentinel was removed explicitly by its own test' );

			$this->cross_hall_audit_isolation();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * bc_audit_log is network-wide; bingo_play_product_id is hall-local. Two halls
	 * can therefore hold the SAME numeric Play Product ID. Cleaning one hall must
	 * never remove the other hall's audit rows.
	 */
	private function cross_hall_audit_isolation(): void {
		global $wpdb;
		$audit = CoreMigrations::audit_log_table();

		// Same numeric target_id, deliberately, in both halls.
		$collision_id = 999001;
		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			$wpdb->insert(
				$audit,
				[
					'blog_id'       => $site_id,
					'actor_user_id' => $this->super_admin_id,
					'action'        => 'catalog.play_product.updated',
					'target_type'   => 'bingo_play_product',
					'target_id'     => (string) $collision_id,
					'created_at'    => current_time( 'mysql', true ),
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
		}

		$count = function ( int $site_id ) use ( $wpdb, $audit, $collision_id ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$audit} WHERE blog_id = %d AND target_type = %s AND target_id = %s",
					$site_id,
					'bingo_play_product',
					(string) $collision_id
				)
			);
		};

		$this->assert_same( 1, $count( self::SUNRISE ), 'colliding Play Product audit row planted on Sunrise' );
		$this->assert_same( 1, $count( self::TAMPA ), 'colliding Play Product audit row planted on Tampa' );

		// Clean the Sunrise one through the suite's own hall-scoped deleter.
		$this->delete_audit_for( self::SUNRISE, 'bingo_play_product', $collision_id );

		$this->assert_same( 0, $count( self::SUNRISE ), "Sunrise's audit row for the colliding ID was removed" );
		$this->assert_same( 1, $count( self::TAMPA ), "Tampa's audit row for the SAME numeric ID SURVIVED — cleanup is hall-scoped" );

		$this->delete_audit_for( self::TAMPA, 'bingo_play_product', $collision_id );
		$this->assert_same( 0, $count( self::TAMPA ), 'the Tampa fixture was removed explicitly by its own test' );
	}

	/* ==================================================================== *
	 * 1. Migration
	 * ==================================================================== */

	private function migration_and_provisioning(): void {
		global $wpdb;
		$this->group( 'Migration v1 -> v2 (disposable hall only)' );

		// Destructive migration scenarios DROP and structurally damage the
		// authoritative Play Product table. They therefore never touch Sunrise,
		// Tampa or Orlando: a hall created for this run alone absorbs them, so a
		// mid-test crash can only lose data that exists solely for the test.
		$disposable = $this->create_disposable_site();
		if ( $disposable < 1 ) {
			return;
		}
		$this->assert_true( $disposable > 4, "disposable migration hall created (site {$disposable})" );
		$this->assert_true(
			! in_array( $disposable, [ 1, self::SUNRISE, self::TAMPA, 4 ], true ),
			'  and it is not a persistent hall'
		);

		foreach ( [ $disposable ] as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$play_products = Migrations::play_products_table();

				// Safe: this hall exists only for this run, and the gate proves it.
				$this->disposable_ddl( $site_id, "DROP TABLE IF EXISTS `{$play_products}`" );
				update_option( Migrations::VERSION_OPTION, 1, false );
				$this->assert_false( Migrations::is_current(), "site {$site_id} at v1 reports stale schema" );
				$this->assert_error( 'catalog_migration_verification_failed', Migrations::verify_current_site_schema(), "site {$site_id} v1 fails current-version verification" );

				$result = Migrations::migrate_current_site();
				$this->assert_true( true === $result, "site {$site_id} v1 -> current migration succeeds" );
				$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), "site {$site_id} records the current Catalog DB version" );
				$this->assert_true( true === Migrations::verify_game_types_schema(), "site {$site_id} still verifies the applied Game Type schema" );
				$this->assert_true( true === Migrations::verify_play_products_schema(), "site {$site_id} verifies the Play Product schema" );

				// Exact schema.
				$create = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$play_products}`", ARRAY_N )[1] ?? '' );
				$this->assert_true( false !== stripos( $create, 'ENGINE=InnoDB' ), "site {$site_id} Play Product table is InnoDB" );
				$this->assert_true( false !== stripos( $create, 'utf8mb4' ), "site {$site_id} Play Product table is utf8mb4" );
				$this->assert_true( false === stripos( $create, 'blog_id' ), "site {$site_id} Play Product table carries no redundant blog_id" );
				$this->assert_true( false === stripos( $create, 'FOREIGN KEY' ), "site {$site_id} Play Product table has no physical foreign key" );

				$this->assert_true( true === Migrations::migrate_current_site(), "site {$site_id} current-version rerun is idempotent" );

				// Missing index repair + stale-version recovery.
				$this->disposable_ddl( $site_id, "ALTER TABLE `{$play_products}` DROP INDEX medium_status" );
				delete_option( Migrations::VERSION_OPTION );
				$this->assert_error( 'catalog_migration_verification_failed', Migrations::verify_current_site_schema(), "site {$site_id} missing Play Product index detected" );
				$this->assert_same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), "site {$site_id} failed verification leaves version stale" );
				$this->assert_true( true === Migrations::migrate_current_site(), "site {$site_id} dbDelta repairs the missing index" );
				$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), "site {$site_id} version persisted only after every schema verified" );
			} finally {
				restore_current_blog();
			}
		}

		// A verification failure must leave the version stale rather than claim
		// the current version.
		switch_to_blog( $disposable );
		try {
			$play_products = Migrations::play_products_table();
			$this->disposable_ddl( $disposable, "ALTER TABLE `{$play_products}` CHANGE COLUMN medium medium varchar(8) NOT NULL" );
			update_option( Migrations::VERSION_OPTION, 1, false );
			$broken = Migrations::migrate_current_site();
			// dbDelta widens the column back, so this asserts the repair path rather
			// than a permanent failure; the point is that the version tracks truth.
			$this->assert_true( true === $broken, 'incompatible column is repaired by dbDelta and then verified' );
			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), 'the version advances only with a verified schema' );

			// A failure that leaves the version stale: the table is gone at
			// verification time, so the current version must not be recorded.
			delete_option( Migrations::VERSION_OPTION );
			$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$play_products}`" );
			$this->assert_error( 'catalog_migration_verification_failed', Migrations::verify_current_site_schema(), 'missing Play Product table is detected' );
			$this->assert_same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'schema failure leaves the Catalog version stale' );
			$this->assert_true( true === Migrations::migrate_current_site(), 'dropped Play Product table is recreated' );
			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), 'version restored after recreation' );
		} finally {
			restore_current_blog();
		}

		// New-hall provisioning and the trusted network-sweep boundary.
		$before = get_current_blog_id();
		$this->assert_true( true === Migrations::provision_new_site( get_site( self::TAMPA ) ), 'new-hall provisioning path succeeds' );
		$this->assert_same( $before, get_current_blog_id(), 'new-hall provisioning restores blog context' );
		$this->assert_true( false !== has_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ] ), 'wp_initialize_site provisioning hook registered' );
		$this->assert_true( false !== has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_current_site' ] ), 'admin_init current-site self-heal registered' );
		$this->assert_true( false !== has_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ] ), 'network_admin_menu sweep registered' );
		$this->assert_false( has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ] ), 'network sweep is NOT registered on hall admin_init' );
		$this->assert_same( 1, $this->count_migration_registrations(), 'exactly one migration trigger path per lifecycle hook' );

		$this->network_sweep_boundary();
		$this->current_site_self_heal( $disposable );

		// Prove the persistent halls were untouched by the destructive group.
		foreach ( [ self::SUNRISE, self::TAMPA, 4 ] as $persistent ) {
			switch_to_blog( $persistent );
			$this->assert_true(
				Migrations::verify_current_site_schema() === true,
				"persistent hall {$persistent} schema is intact after the destructive migration group"
			);
			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION, 0 ), "persistent hall {$persistent} still reports the current version" );
			restore_current_blog();
		}

		$this->destroy_disposable_site( $disposable );
	}

	private function count_migration_registrations(): int {
		// One admin_init self-heal registration; a second trigger path would show
		// up here as an extra callback on the same hook.
		$hook  = $GLOBALS['wp_filter']['admin_init'] ?? null;
		$count = 0;
		if ( $hook instanceof WP_Hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && Migrations::class === ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) ) {
						++$count;
					}
				}
			}
		}

		return $count;
	}

	private function network_sweep_boundary(): void {
		global $wpdb;
		$manager = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$this->assert_true( $manager > 0, 'Hall Manager fixture exists on Sunrise' );

		$sweep_before = get_site_option( Migrations::NETWORK_SWEEP_OPTION, 0 );
		delete_site_option( Migrations::NETWORK_SWEEP_OPTION );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$this->assert_true( true === Migrations::maybe_migrate_network(), 'Hall Manager network sweep is a no-op' );
		$this->assert_same( 0, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION, 0 ), 'Hall Manager cannot advance the network sweep version' );
		restore_current_blog();

		// Trusted Super Admin network-administration path.
		wp_set_current_user( $this->super_admin_id );
		set_current_screen( 'dashboard-network' );
		$this->assert_true( true === Migrations::maybe_migrate_network(), 'trusted Super Admin network sweep succeeds' );
		$this->assert_same( Migrations::DB_VERSION, (int) get_site_option( Migrations::NETWORK_SWEEP_OPTION, 0 ), 'network sweep version advances to the current version' );
		set_current_screen( 'dashboard' );

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION, 0 ), "network sweep left site {$site_id} at the current version" );
			restore_current_blog();
		}

		if ( (int) $sweep_before > 0 ) {
			update_site_option( Migrations::NETWORK_SWEEP_OPTION, (int) $sweep_before );
		}
		update_site_option( Migrations::NETWORK_SWEEP_OPTION, Migrations::DB_VERSION );
	}

	private function current_site_self_heal( int $site_id ): void {
		switch_to_blog( $site_id );
		try {
			wp_set_current_user( $this->super_admin_id );
			update_option( Migrations::VERSION_OPTION, 1, false );
			$this->assert_true( true === Migrations::maybe_migrate_current_site(), 'current-hall self-heal runs when the version is stale' );
			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION ), 'current-hall self-heal reaches the current version' );
			$this->assert_true( true === Migrations::maybe_migrate_current_site(), 'current-hall self-heal is a no-op when current' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 2. Authorization
	 * ==================================================================== */

	private function authorization(): void {
		$this->group( 'Authorization' );

		$manager  = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$operator = $this->first_user_with_role( Roles::GAME_OPERATOR, self::SUNRISE );
		$ordinary = $this->ordinary_user_id();
		$product  = $this->make_product( self::SUNRISE, 'auth probe' );

		switch_to_blog( self::SUNRISE );
		try {
			$service = new BingoPlayProductService();

			wp_set_current_user( $operator );
			$this->assert_true( current_user_can( 'bc_manage_products' ) === false, 'Game Operator lacks bc_manage_products' );
			$this->assert_error( 'play_product_management_denied', $service->create( $product, 'paper', 1 ), 'Game Operator create denied' );
			$this->assert_error( 'play_product_management_denied', $service->listForAdministration(), 'Game Operator administrative read denied' );

			wp_set_current_user( $ordinary );
			$this->assert_error( 'play_product_management_denied', $service->create( $product, 'paper', 1 ), 'ordinary user create denied' );

			wp_set_current_user( 0 );
			$this->assert_error( 'authentication_required', $service->create( $product, 'paper', 1 ), 'anonymous create denied' );

			wp_set_current_user( $manager );
			$this->assert_true( current_user_can( 'bc_manage_products' ), 'Hall Manager holds bc_manage_products' );
			$created = $service->create( $product, 'paper', 1 );
			$this->assert_true( $created instanceof PlayProductResult, 'Hall Manager create succeeds' );

			wp_set_current_user( $this->super_admin_id );
			$this->assert_true( is_super_admin( $this->super_admin_id ), 'Super Admin override uses is_super_admin(), not a role name' );
			$faces = $service->updateFacesPerUnit( (int) $created->play_product['bingo_play_product_id'], 7 );
			$this->assert_true( $faces instanceof PlayProductResult, 'native Super Admin succeeds through the established override' );

			$this->delete_play_product( self::SUNRISE, (int) $created->play_product['bingo_play_product_id'] );
		} finally {
			restore_current_blog();
		}

		// Hall context is verified on every mutation.
		switch_to_blog( 1 );
		try {
			wp_set_current_user( $this->super_admin_id );
			$this->assert_error( 'invalid_hall_context', ( new BingoPlayProductService() )->create( 1, 'paper', 1 ), 'network main site is not a hall' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 3. Creation
	 * ==================================================================== */

	private function creation(): void {
		$this->group( 'Creation' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$active  = $this->make_game_type( self::SUNRISE, 'Regular' );

			$paper_plain = $this->make_product( self::SUNRISE, 'paper plain' );
			$result      = $service->create( $paper_plain, 'paper', 3 );
			$this->assert_status( 'play_product_created', $result, 'Paper without a Game Type is created' );
			$this->assert_null( $result->play_product['game_type_id'], '  and stores a NULL Game Type' );
			$this->assert_same( 'active', $result->play_product['status'], '  and starts active' );
			$this->assert_null( $result->play_product['archived_at'], '  with a NULL archived_at' );

			$paper_typed = $this->make_product( self::SUNRISE, 'paper typed' );
			$result      = $service->create( $paper_typed, 'paper', 1, $active );
			$this->assert_status( 'play_product_created', $result, 'Paper with an active Game Type is created' );
			$this->assert_same( $active, $result->play_product['game_type_id'], '  and stores the reference' );

			$digital = $this->make_product( self::SUNRISE, 'digital' );
			$result  = $service->create( $digital, 'digital', 3, $active );
			$this->assert_status( 'play_product_created', $result, 'Digital with an active Game Type is created' );

			$reject = $this->make_product( self::SUNRISE, 'reject probe' );
			$this->assert_error( 'game_type_required_for_digital', $service->create( $reject, 'digital', 3 ), 'Digital without a Game Type is rejected' );
			$this->assert_error( 'game_type_required_for_digital', $service->create( $reject, 'digital', 3, null ), 'Digital with a null Game Type is rejected' );
			$this->assert_error( 'invalid_faces_per_unit', $service->create( $reject, 'paper', 0 ), 'zero faces rejected' );
			$this->assert_error( 'invalid_faces_per_unit', $service->create( $reject, 'paper', -4 ), 'negative faces rejected' );
			$this->assert_error( 'invalid_faces_per_unit', $service->create( $reject, 'paper', 4294967296 ), 'faces beyond the column bound rejected' );
			$this->assert_error( 'invalid_medium', $service->create( $reject, 'hybrid', 1 ), 'unknown medium rejected' );
			$this->assert_error( 'invalid_medium', $service->create( $reject, '', 1 ), 'empty medium rejected' );
			$this->assert_error( 'invalid_wc_product_id', $service->create( 0, 'paper', 1 ), 'zero product ID rejected' );
			$this->assert_error( 'woo_product_missing', $service->create( 99999999, 'paper', 1 ), 'missing Woo product rejected' );

			$archived_type = $this->make_game_type( self::SUNRISE, 'Archived At Create' );
			( new GameTypeService() )->archive( $archived_type );
			$this->assert_error( 'game_type_not_active', $service->create( $reject, 'digital', 3, $archived_type ), 'archived Game Type rejected at create' );
			$this->assert_error( 'game_type_not_found', $service->create( $reject, 'digital', 3, 99999999 ), 'unknown Game Type rejected at create' );

			// WooCommerce eligibility.
			$trashed = $this->make_product( self::SUNRISE, 'trashed' );
			wp_trash_post( $trashed );
			$this->assert_error( 'woo_product_trashed', $service->create( $trashed, 'paper', 1 ), 'trashed Woo product rejected' );

			$auto_draft = $this->make_auto_draft_product( self::SUNRISE );
			$this->assert_error( 'woo_product_trashed', $service->create( $auto_draft, 'paper', 1 ), 'auto-draft Woo product rejected' );

			[ $variable, $variation ] = $this->make_variation( self::SUNRISE );
			$this->assert_error( 'woo_product_unsupported', $service->create( $variation, 'paper', 1 ), 'product variation rejected' );
			$this->assert_error( 'woo_product_unsupported', $service->create( $variable, 'paper', 1 ), 'variable top-level product rejected' );

			$grouped = $this->make_product( self::SUNRISE, 'grouped', 'WC_Product_Grouped' );
			$this->assert_error( 'woo_product_unsupported', $service->create( $grouped, 'paper', 1 ), 'grouped top-level product rejected' );

			$external = $this->make_product( self::SUNRISE, 'external', 'WC_Product_External' );
			$this->assert_error( 'woo_product_unsupported', $service->create( $external, 'paper', 1 ), 'external top-level product rejected' );

			// Non-published but allowed statuses.
			foreach ( [ 'draft', 'private', 'pending' ] as $status ) {
				$id = $this->make_product( self::SUNRISE, "status {$status}", 'WC_Product_Simple', $status );
				$this->assert_status( 'play_product_created', $service->create( $id, 'paper', 2 ), "{$status} Woo product is eligible" );
			}

			$this->assert_error( 'play_product_already_linked', $service->create( $paper_plain, 'paper', 1 ), 'duplicate wc_product_id rejected' );
			$this->assert_error( 'play_product_already_linked', $service->create( $paper_plain, 'digital', 5, $active ), 'duplicate rejected regardless of payload' );

			$row = $service->getByWooProductId( $paper_plain );
			$this->assert_same( 3, $row['faces_per_unit'], 'the rejected duplicate did not alter the existing row' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 4. Medium transitions
	 * ==================================================================== */

	private function medium_transitions(): void {
		$this->group( 'Medium transitions' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service  = new BingoPlayProductService();
			$types    = new GameTypeService();
			$active   = $this->make_game_type( self::SUNRISE, 'Winner Take All' );
			$archived = $this->make_game_type( self::SUNRISE, 'Red Hot Archived' );
			$types->archive( $archived );

			$id = $this->new_play_product( $service, 'paper transition', 'paper', 2 );

			$this->assert_error( 'game_type_required_for_digital', $service->updateMedium( $id, 'digital' ), 'Paper -> Digital without a Game Type is rejected' );
			$this->assert_same( 'paper', $service->getById( $id )['medium'], '  and never persists a partial transition' );
			$this->assert_error( 'game_type_not_active', $service->updateMedium( $id, 'digital', $archived ), 'Paper -> Digital with an archived Game Type is rejected' );
			$this->assert_same( 'paper', $service->getById( $id )['medium'], '  and still never persists digital' );
			$this->assert_error( 'game_type_not_found', $service->updateMedium( $id, 'digital', 99999999 ), 'Paper -> Digital with an unknown Game Type is rejected' );

			$result = $service->updateMedium( $id, 'digital', $active );
			$this->assert_status( 'play_product_medium_changed', $result, 'Paper -> Digital with an active Game Type succeeds' );
			$this->assert_same( 'digital', $result->play_product['medium'], '  medium is digital' );
			$this->assert_same( $active, $result->play_product['game_type_id'], '  and the Game Type is set in the same mutation' );

			$this->assert_error( 'game_type_not_applicable', $service->updateMedium( $id, 'paper', $active ), 'a Game Type argument is rejected when switching to Paper' );

			$result = $service->updateMedium( $id, 'paper' );
			$this->assert_status( 'play_product_medium_changed', $result, 'Digital -> Paper succeeds' );
			$this->assert_same( $active, $result->play_product['game_type_id'], '  and preserves the existing Game Type reference' );

			$this->assert_status( 'play_product_medium_unchanged', $service->updateMedium( $id, 'paper' ), 'repeated Digital -> Paper is a no-op' );
			$this->assert_status( 'play_product_medium_changed', $service->updateMedium( $id, 'digital', $active ), 'Paper -> Digital again is a real change' );
			$this->assert_status( 'play_product_medium_unchanged', $service->updateMedium( $id, 'digital', $active ), 'repeating the same Digital + Game Type is a no-op' );
			$this->assert_status( 'play_product_medium_changed', $service->updateMedium( $id, 'paper' ), 'returning the row to Paper for the remaining checks' );

			// A Paper row that already carries a reference must still be given an
			// explicit active Game Type to become Digital.
			$paper_with_ref = $this->new_play_product( $service, 'paper with ref', 'paper', 1, $active );
			$types->archive( $active );
			$this->assert_error( 'game_type_required_for_digital', $service->updateMedium( $paper_with_ref, 'digital' ), 'Paper -> Digital never inherits an implicit Game Type' );
			$this->assert_error( 'game_type_not_active', $service->updateMedium( $paper_with_ref, 'digital', $active ), '  and the now-archived reference cannot be reused for the transition' );
			$this->assert_same( 'paper', $service->getById( $paper_with_ref )['medium'], '  leaving the row Paper' );
			$this->assert_same( $active, $service->getById( $paper_with_ref )['game_type_id'], '  with its archived reference intact' );
			$types->restore( $active );

			$this->assert_error( 'invalid_medium', $service->updateMedium( $id, 'combo' ), 'invalid target medium rejected' );
			$this->assert_error( 'play_product_not_found', $service->updateMedium( 99999999, 'paper' ), 'unknown Play Product rejected' );

			$this->assert_no_digital_without_game_type( self::SUNRISE, 'no row is digital with a NULL Game Type after medium transitions' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 5. Game Type reference rules
	 * ==================================================================== */

	private function game_type_rules(): void {
		$this->group( 'Game Type references' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$types   = new GameTypeService();
			$first   = $this->make_game_type( self::SUNRISE, 'Assign One' );
			$second  = $this->make_game_type( self::SUNRISE, 'Assign Two' );
			$stale   = $this->make_game_type( self::SUNRISE, 'Assign Stale' );

			$id = $this->new_play_product( $service, 'assign target', 'paper', 4 );

			$this->assert_status( 'play_product_game_type_assigned', $service->assignGameType( $id, $first ), 'assigning an active Game Type succeeds' );
			$this->assert_status( 'play_product_game_type_unchanged', $service->assignGameType( $id, $first ), 'assigning the same Game Type is a no-op' );

			$types->archive( $second );
			$this->assert_error( 'game_type_not_active', $service->assignGameType( $id, $second ), 'assigning an archived Game Type is rejected' );
			$this->assert_same( $first, $service->getById( $id )['game_type_id'], '  and leaves the existing reference untouched' );
			$this->assert_error( 'game_type_not_found', $service->assignGameType( $id, 99999999 ), 'assigning an unknown Game Type is rejected' );
			$this->assert_error( 'invalid_game_type_id', $service->assignGameType( $id, 0 ), 'assigning Game Type 0 is rejected' );

			// Archiving a referenced Game Type preserves the reference, and the
			// same-value assignment is still a no-op afterwards.
			$types->archive( $first );
			$this->assert_same( $first, $service->getById( $id )['game_type_id'], 'archiving a Game Type preserves the existing reference' );
			$this->assert_status( 'play_product_game_type_unchanged', $service->assignGameType( $id, $first ), 'same assignment is a no-op even after that reference was archived' );
			$types->restore( $first );

			// Clear is Paper-only.
			$this->assert_status( 'play_product_game_type_cleared', $service->clearGameType( $id ), 'Paper Game Type clear succeeds' );
			$this->assert_null( $service->getById( $id )['game_type_id'], '  and the reference is NULL' );
			$this->assert_status( 'play_product_game_type_unchanged', $service->clearGameType( $id ), 'repeated clear is a no-op' );

			$digital = $this->new_play_product( $service, 'digital clear', 'digital', 3, $stale );
			$this->assert_error( 'game_type_required_for_digital', $service->clearGameType( $digital ), 'Digital Game Type clear is rejected' );
			$this->assert_same( $stale, $service->getById( $digital )['game_type_id'], '  and the reference survives the rejection' );

			// Cross-hall Game Type is not visible or usable.
			$tampa_type = $this->make_game_type( self::TAMPA, 'Tampa Only' );
			$this->assert_error( 'game_type_not_found', $service->assignGameType( $id, $tampa_type ), 'a Tampa Game Type cannot be assigned on Sunrise' );

			$this->assert_no_digital_without_game_type( self::SUNRISE, 'no row is digital with a NULL Game Type after reference changes' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 6. Lifecycle
	 * ==================================================================== */

	private function lifecycle(): void {
		$this->group( 'Lifecycle' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$type    = $this->make_game_type( self::SUNRISE, 'Lifecycle' );

			$product = $this->make_product( self::SUNRISE, 'lifecycle' );
			$created = $service->create( $product, 'digital', 6, $type );
			$id      = (int) $created->play_product['bingo_play_product_id'];

			$archived = $service->archive( $id );
			$this->assert_status( 'play_product_archived', $archived, 'archive succeeds' );
			$this->assert_same( 'archived', $archived->play_product['status'], '  status is archived' );
			$this->assert_true( null !== $archived->play_product['archived_at'], '  archived_at is set' );
			$this->assert_same( $product, $archived->play_product['wc_product_id'], '  linked product preserved' );
			$this->assert_same( 'digital', $archived->play_product['medium'], '  medium preserved' );
			$this->assert_same( 6, $archived->play_product['faces_per_unit'], '  faces preserved' );
			$this->assert_same( $type, $archived->play_product['game_type_id'], '  Game Type preserved' );

			$this->assert_status( 'play_product_already_archived', $service->archive( $id ), 'repeated archive is a no-op' );

			$restored = $service->restore( $id );
			$this->assert_status( 'play_product_restored', $restored, 'restore succeeds' );
			$this->assert_same( 'active', $restored->play_product['status'], '  status is active' );
			$this->assert_null( $restored->play_product['archived_at'], '  archived_at cleared' );
			$this->assert_status( 'play_product_already_active', $service->restore( $id ), 'repeated restore is a no-op' );

			// Restore fails while the linked product is unusable.
			$service->archive( $id );
			wp_trash_post( $product );
			$this->assert_error( 'woo_product_trashed', $service->restore( $id ), 'restore fails while the Woo product is trashed' );
			$this->assert_same( 'archived', $service->getById( $id )['status'], '  and the row stays archived' );

			wp_untrash_post( $product );
			wp_update_post( [ 'ID' => $product, 'post_status' => 'publish' ] );
			$this->assert_status( 'play_product_restored', $service->restore( $id ), 'restore succeeds once the Woo product is usable again' );

			// Repeated restore must NOT require Woo eligibility.
			wp_trash_post( $product );
			$this->assert_status( 'play_product_already_active', $service->restore( $id ), 'repeated restore on an active row does not demand Woo eligibility' );
			wp_untrash_post( $product );
			wp_update_post( [ 'ID' => $product, 'post_status' => 'publish' ] );

			// Unsupported type blocks restore.
			$service->archive( $id );
			$converted = wc_get_product( $product );
			wp_set_object_terms( $product, 'grouped', 'product_type' );
			wp_cache_flush();
			$this->assert_error( 'woo_product_unsupported', $service->restore( $id ), 'restore fails while the Woo product is no longer simple' );
			wp_set_object_terms( $product, 'simple', 'product_type' );
			wp_cache_flush();
			$this->assert_status( 'play_product_restored', $service->restore( $id ), 'restore succeeds once the type is simple again' );

			// Missing product blocks restore.
			$doomed  = $this->make_product( self::SUNRISE, 'doomed' );
			$doomed_pp = (int) $service->create( $doomed, 'paper', 1 )->play_product['bingo_play_product_id'];
			$service->archive( $doomed_pp );
			wp_delete_post( $doomed, true );
			$this->assert_error( 'woo_product_missing', $service->restore( $doomed_pp ), 'restore fails when the Woo product was permanently deleted' );
			$this->assert_same( 'archived', $service->getById( $doomed_pp )['status'], '  and the authoritative row is preserved, not detached' );
			$this->assert_same( $doomed, $service->getById( $doomed_pp )['wc_product_id'], '  keeping its original wc_product_id' );
			$this->assert_same( 'missing', $service->getById( $doomed_pp )['woo_state'], '  and administrative reads surface the broken link' );

			$this->assert_error( 'play_product_not_found', $service->archive( 99999999 ), 'archiving an unknown Play Product is rejected' );
			$this->assert_error( 'play_product_not_found', $service->restore( 99999999 ), 'restoring an unknown Play Product is rejected' );
			$this->assert_false( method_exists( $service, 'hardDelete' ), 'no hardDelete method exists' );
			$this->assert_false( method_exists( $service, 'relink' ), 'no relink method exists' );
			$this->assert_false( method_exists( $service, 'update' ), 'no generic update() method exists' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 7. Active package-selection reads
	 * ==================================================================== */

	private function selection_reads(): void {
		$this->group( 'Active package-selection reads' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$types   = new GameTypeService();
			$active  = $this->make_game_type( self::SUNRISE, 'Selection Active' );
			$doomed  = $this->make_game_type( self::SUNRISE, 'Selection Doomed' );

			$ok_paper   = $this->new_play_product( $service, 'sel paper', 'paper', 1 );
			$ok_digital = $this->new_play_product( $service, 'sel digital', 'digital', 3, $active );
			$archived   = $this->new_play_product( $service, 'sel archived', 'paper', 1 );
			$service->archive( $archived );

			$paper_doomed_ref = $this->new_play_product( $service, 'sel paper doomed', 'paper', 1, $doomed );
			$digital_doomed   = $this->new_play_product( $service, 'sel digital doomed', 'digital', 2, $doomed );

			$trashed_product = $this->make_product( self::SUNRISE, 'sel trashed' );
			$trashed_pp      = (int) $service->create( $trashed_product, 'paper', 1 )->play_product['bingo_play_product_id'];

			$gone_product = $this->make_product( self::SUNRISE, 'sel gone' );
			$gone_pp      = (int) $service->create( $gone_product, 'paper', 1 )->play_product['bingo_play_product_id'];

			$converted_product = $this->make_product( self::SUNRISE, 'sel converted' );
			$converted_pp      = (int) $service->create( $converted_product, 'paper', 1 )->play_product['bingo_play_product_id'];

			$selectable = $this->selection_ids( $service );
			$this->assert_true( in_array( $ok_paper, $selectable, true ), 'valid Paper is selectable' );
			$this->assert_true( in_array( $ok_digital, $selectable, true ), 'valid Digital is selectable' );
			$this->assert_false( in_array( $archived, $selectable, true ), 'archived Play Product is excluded' );

			$types->archive( $doomed );
			$selectable = $this->selection_ids( $service );
			$this->assert_false( in_array( $paper_doomed_ref, $selectable, true ), 'Paper with an archived referenced Game Type is excluded' );
			$this->assert_false( in_array( $digital_doomed, $selectable, true ), 'Digital with an archived referenced Game Type is excluded' );
			$this->assert_same( $doomed, $service->getById( $paper_doomed_ref )['game_type_id'], '  and filtering mutated nothing' );
			$this->assert_same( 'active', $service->getById( $digital_doomed )['status'], '  the row itself stays active' );

			wp_trash_post( $trashed_product );
			$this->assert_false( in_array( $trashed_pp, $this->selection_ids( $service ), true ), 'Play Product with a trashed Woo product is excluded' );
			$this->assert_same( 'trashed', $service->getById( $trashed_pp )['woo_state'], '  administrative read surfaces the trashed state' );

			wp_delete_post( $gone_product, true );
			$this->assert_false( in_array( $gone_pp, $this->selection_ids( $service ), true ), 'Play Product with a missing Woo product is excluded' );
			$this->assert_same( 'missing', $service->getById( $gone_pp )['woo_state'], '  administrative read surfaces the missing state' );

			wp_set_object_terms( $converted_product, 'grouped', 'product_type' );
			wp_cache_flush();
			$this->assert_false( in_array( $converted_pp, $this->selection_ids( $service ), true ), 'Play Product whose Woo type is no longer simple is excluded' );
			$this->assert_same( 'unsupported_type', $service->getById( $converted_pp )['woo_state'], '  administrative read surfaces the unsupported type' );

			$this->assert_no_digital_without_game_type( self::SUNRISE, 'selection reads never observe digital + NULL' );

			$admin_ids = array_column( $service->listForAdministration( 'all', 1, 200 ), 'bingo_play_product_id' );
			foreach ( [ $archived, $trashed_pp, $gone_pp, $converted_pp, $paper_doomed_ref ] as $preserved ) {
				$this->assert_true( in_array( $preserved, $admin_ids, true ), "excluded row {$preserved} is still visible to administration" );
			}

			$this->assert_error( 'invalid_status', $service->listForAdministration( 'deleted' ), 'invalid administrative status filter rejected' );
			$this->assert_same(
				[],
				array_values( array_filter( array_column( $service->listForAdministration( 'archived', 1, 200 ), 'status' ), static fn( $s ) => 'archived' !== $s ) ),
				'archived filter returns only archived rows'
			);
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 8. Audit
	 * ==================================================================== */

	private function audit_behavior(): void {
		global $wpdb;
		$this->group( 'Audit' );

		switch_to_blog( self::SUNRISE );
		try {
			$manager = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
			wp_set_current_user( $manager );
			$service = new BingoPlayProductService();
			$type    = $this->make_game_type( self::SUNRISE, 'Audit' );
			$other   = $this->make_game_type( self::SUNRISE, 'Audit Other' );

			$product = $this->make_product( self::SUNRISE, 'audit' );
			$created = $service->create( $product, 'paper', 2 );
			$id      = (int) $created->play_product['bingo_play_product_id'];

			$this->assert_same( [ 'catalog.play_product.created' ], $this->audit_actions( $id ), 'create emits exactly one created event' );

			$service->updateFacesPerUnit( $id, 5 );
			$service->assignGameType( $id, $type );
			$service->updateMedium( $id, 'digital', $other );
			$service->updateMedium( $id, 'paper' );
			$service->clearGameType( $id );
			$service->archive( $id );
			$service->restore( $id );

			$this->assert_same(
				[
					'catalog.play_product.created',
					'catalog.play_product.updated',
					'catalog.play_product.updated',
					'catalog.play_product.updated',
					'catalog.play_product.updated',
					'catalog.play_product.updated',
					'catalog.play_product.archived',
					'catalog.play_product.restored',
				],
				$this->audit_actions( $id ),
				'each confirmed mutation emits exactly one ordinary ADR-023 event'
			);

			$before_noops = count( $this->audit_actions( $id ) );
			$service->updateFacesPerUnit( $id, 5 );
			$current_type = (int) ( $service->getById( $id )['game_type_id'] ?? 0 );
			if ( $current_type > 0 ) {
				$service->assignGameType( $id, $current_type );
			}
			$service->clearGameType( $id );
			$service->archive( $id );
			$service->archive( $id );
			$service->restore( $id );
			$service->restore( $id );
			$this->assert_same( $before_noops + 2, count( $this->audit_actions( $id ) ), 'no-op operations emit no audit events; only the real archive and restore did' );

			$table = CoreMigrations::audit_log_table();
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT blog_id, actor_user_id, target_type, target_id, operation_id FROM {$table} WHERE blog_id = %d AND target_type = %s AND target_id = %s ORDER BY id LIMIT 1", self::SUNRISE, 'bingo_play_product', (string) $id ),
				ARRAY_A
			);
			$this->assert_same( self::SUNRISE, (int) $row['blog_id'], 'audit event carries the hall blog_id' );
			$this->assert_same( $manager, (int) $row['actor_user_id'], 'audit event carries the actor' );
			$this->assert_same( 'bingo_play_product', $row['target_type'], 'audit target_type is bingo_play_product' );
			$this->assert_same( (string) $id, (string) $row['target_id'], 'audit target_id is the Play Product ID' );
			$this->assert_null( $row['operation_id'], 'operation_id remains NULL for ordinary Catalog events' );

			$with_op = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND target_type = %s AND operation_id IS NOT NULL", self::SUNRISE, 'bingo_play_product' ) );
			$this->assert_same( 0, $with_op, 'no Bingo Play Product audit event carries an operation_id' );

			$update   = $wpdb->get_row(
				$wpdb->prepare( "SELECT previous_value, new_value FROM {$table} WHERE blog_id = %d AND target_type = %s AND target_id = %s AND action = %s ORDER BY id LIMIT 1", self::SUNRISE, 'bingo_play_product', (string) $id, 'catalog.play_product.updated' ),
				ARRAY_A
			);
			$previous = json_decode( (string) $update['previous_value'], true );
			$new      = json_decode( (string) $update['new_value'], true );
			$this->assert_same( 2, (int) $previous['faces_per_unit'], 'audit previous_value captures the pre-change faces' );
			$this->assert_same( 5, (int) $new['faces_per_unit'], 'audit new_value captures the post-change faces' );

			$failing        = new BingoPlayProductService( static fn( array $event ) => new WP_Error( 'audit_transport_down', 'forced audit failure' ) );
			$forced_product = $this->make_product( self::SUNRISE, 'audit forced' );
			$forced         = $failing->create( $forced_product, 'paper', 4 );
			$this->assert_true( $forced instanceof PlayProductResult, 'a failed audit still returns success for the committed mutation' );
			$this->assert_same( 'audit_write_failed', $forced->warnings[0]['code'] ?? '', '  with an audit_write_failed warning' );
			$this->assert_same( 'audit_transport_down', $forced->warnings[0]['audit_code'] ?? '', '  carrying the underlying audit code' );
			$forced_id = (int) $forced->play_product['bingo_play_product_id'];
			$this->assert_same( 4, $failing->getById( $forced_id )['faces_per_unit'], '  and the domain mutation is durably committed' );
			$this->assert_same( [], $this->audit_actions( $forced_id ), '  and no audit row was written' );

			$forced_archive = $failing->archive( $forced_id );
			$this->assert_status( 'play_product_archived', $forced_archive, 'a failed audit on archive still commits' );
			$this->assert_same( 'archived', $failing->getById( $forced_id )['status'], '  the archive is durable' );
			$this->assert_same( 'audit_write_failed', $forced_archive->warnings[0]['code'] ?? '', '  and the warning is surfaced' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 9. Tenant isolation
	 * ==================================================================== */

	private function tenant_isolation(): void {
		$this->group( 'Tenant isolation' );

		$manager_sunrise = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$manager_tampa   = $this->first_user_with_role( Roles::HALL_MANAGER, self::TAMPA );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager_sunrise );
		$sunrise_product = $this->make_product( self::SUNRISE, 'isolation' );
		$sunrise_pp      = (int) ( new BingoPlayProductService() )->create( $sunrise_product, 'paper', 3 )->play_product['bingo_play_product_id'];
		restore_current_blog();

		switch_to_blog( self::TAMPA );
		try {
			wp_set_current_user( $manager_tampa );
			$tampa = new BingoPlayProductService();

			$this->assert_error( 'play_product_not_found', $tampa->getById( $sunrise_pp ), 'Sunrise mapping is invisible to Tampa by ID' );
			$this->assert_error( 'play_product_not_found', $tampa->getByWooProductId( $sunrise_product ), 'Sunrise mapping is invisible to Tampa by Woo product ID' );

			$tampa_ids = array_column( $tampa->listForAdministration( 'all', 1, 200 ), 'wc_product_id' );
			$this->assert_false( in_array( $sunrise_product, $tampa_ids, true ), 'Sunrise product does not appear in Tampa administration' );

			$this->assert_error( 'play_product_not_found', $tampa->archive( $sunrise_pp ), 'Tampa cannot archive a Sunrise Play Product ID' );
			$this->assert_error( 'play_product_not_found', $tampa->updateFacesPerUnit( $sunrise_pp, 99 ), 'Tampa cannot mutate a Sunrise Play Product ID' );
			$this->assert_error( 'play_product_not_found', $tampa->restore( $sunrise_pp ), 'Tampa cannot restore a Sunrise Play Product ID' );
		} finally {
			restore_current_blog();
		}

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager_sunrise );
		$sunrise_after = ( new BingoPlayProductService() )->getById( $sunrise_pp );
		restore_current_blog();
		$this->assert_true( is_array( $sunrise_after ), 'the Sunrise row is still readable by its own hall' );
		$this->assert_same( 3, is_array( $sunrise_after ) ? $sunrise_after['faces_per_unit'] : null, 'the Sunrise row is untouched by Tampa attempts' );

		[ $shared_id, $hall_a, $hall_b ] = $this->reserve_shared_product_id();
		if ( $shared_id > 0 ) {
			$rows = [];
			foreach ( [ $hall_a, $hall_b ] as $site_id ) {
				switch_to_blog( $site_id );
				wp_set_current_user( $this->super_admin_id );
				$service = new BingoPlayProductService();
				$created = $service->create( $shared_id, 'paper', 1 );
				$this->assert_status( 'play_product_created', $created, "hall {$site_id} independently maps Woo product #{$shared_id}" );
				$rows[ $site_id ] = $service->getByWooProductId( $shared_id );
				restore_current_blog();
			}

			$this->assert_true( is_array( $rows[ $hall_a ] ) && is_array( $rows[ $hall_b ] ), 'both halls resolve their own row for the shared product ID' );
			$this->assert_same( $shared_id, (int) $rows[ $hall_a ]['wc_product_id'], 'the first hall points at the shared numeric ID' );
			$this->assert_same( $shared_id, (int) $rows[ $hall_b ]['wc_product_id'], 'the second hall points at the same numeric ID independently' );
			$this->assert_true(
				(int) $rows[ $hall_a ]['bingo_play_product_id'] > 0 && (int) $rows[ $hall_b ]['bingo_play_product_id'] > 0,
				'  and each hall holds its own authoritative row for it'
			);
		} else {
			$this->note( 'shared Woo product ID scenario skipped: a matching ID could not be reserved in both halls' );
		}
	}

	/* ==================================================================== *
	 * 10. Package claim exclusivity
	 * ==================================================================== */

	/**
	 * Every case here needs bingo_packages absent, malformed, or otherwise wrong,
	 * so the WHOLE group runs on a hall created for this run alone. A persistent
	 * hall's authoritative Package table is never renamed aside, dropped, or
	 * replaced with a stub: a crash mid-group would otherwise leave a real hall
	 * without it, and no finally/cleanup block can be relied on for that.
	 */
	private function package_claim_behavior(): void {
		global $wpdb;
		$this->group( 'Package claim exclusivity (disposable hall only)' );

		$disposable = $this->create_disposable_site();
		if ( $disposable < 1 ) {
			return;
		}
		$this->assert_true( $disposable > 4, "disposable Package-claim hall created (site {$disposable})" );
		$this->assert_true(
			! in_array( $disposable, [ 1, self::SUNRISE, self::TAMPA, 4 ], true ),
			'  and it is not a persistent hall'
		);

		switch_to_blog( $disposable );
		try {
			wp_set_current_user( $this->super_admin_id );
			$service  = new BingoPlayProductService();
			$packages = Migrations::packages_table();

			$this->assert_true( true === Migrations::verify_packages_schema(), 'the disposable hall starts from a verified v3 Package table' );

			// A. Absent — RETIRED BRIDGE. Before Catalog v3 an absent table meant
			// no Package claim was possible and creation proceeded. At v3 the
			// table is part of the verified schema contract, so its absence is a
			// schema fault and creation fails closed.
			$this->drop_disposable_package_table( $disposable );
			$absent_product = $this->make_product( $disposable, 'pkg absent' );
			$this->assert_error( 'catalog_schema_inconsistent', $service->create( $absent_product, 'paper', 1 ), 'an absent bingo_packages table fails closed at Catalog v3' );
			$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $absent_product ), '  and nothing was written' );

			// B. Present, well formed, and already claiming the product.
			$this->create_package_stub(
				$disposable,
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				 wc_product_id bigint(20) unsigned NOT NULL,
				 status varchar(16) NOT NULL DEFAULT 'active',
				 PRIMARY KEY (package_id),
				 UNIQUE KEY wc_product_unique (wc_product_id)"
			);
			$claimed_product = $this->make_product( $disposable, 'pkg claimed' );
			$wpdb->insert( $packages, [ 'wc_product_id' => $claimed_product, 'status' => 'active' ], [ '%d', '%s' ] );
			$this->assert_error( 'package_claim_exists', $service->create( $claimed_product, 'paper', 1 ), 'an existing Package claim rejects Play Product creation' );
			$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $claimed_product ), '  and no Play Product row was written' );

			$unclaimed_product = $this->make_product( $disposable, 'pkg unclaimed' );
			$this->assert_status( 'play_product_created', $service->create( $unclaimed_product, 'paper', 1 ), 'an unclaimed product is still creatable while bingo_packages exists' );

			// An archived Package row still claims its product.
			$archived_claim = $this->make_product( $disposable, 'pkg archived claim' );
			$wpdb->insert( $packages, [ 'wc_product_id' => $archived_claim, 'status' => 'archived' ], [ '%d', '%s' ] );
			$this->assert_error( 'package_claim_exists', $service->create( $archived_claim, 'paper', 1 ), 'an archived Package row still claims its product' );

			// C. Malformed — every unusable shape must fail closed.
			$malformed_product = $this->make_product( $disposable, 'pkg malformed' );

			$shapes = [
				'missing wc_product_id column' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, some_other_column varchar(32) NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (package_id)",
				'missing status column' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_unique (wc_product_id)",
				'missing package_id column' =>
					"wc_product_id bigint(20) unsigned NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (wc_product_id)",
				'wrong wc_product_id column type' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id varchar(32) NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_unique (wc_product_id)",
				'wrong status column type' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, status int NOT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_unique (wc_product_id)",
				'no wc_product_id index' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (package_id)",
				'non-unique wc_product_id index' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (package_id), KEY wc_product_lookup (wc_product_id)",
				'composite unique index that does not prove one product per Package' =>
					"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, status varchar(16) NOT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_status (wc_product_id,status)",
			];

			foreach ( $shapes as $label => $ddl ) {
				$this->drop_owned_package_stub( $disposable );
				$this->create_package_stub( $disposable, $ddl );
				$this->assert_error( 'catalog_schema_inconsistent', $service->create( $malformed_product, 'paper', 1 ), "a Package table with {$label} fails closed" );
				$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $malformed_product ), "  and nothing was written ({$label})" );
			}

			// D. The exact approved signature is accepted.
			$this->drop_owned_package_stub( $disposable );
			$this->create_package_stub(
				$disposable,
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				 wc_product_id bigint(20) unsigned NOT NULL,
				 package_type varchar(16) NOT NULL DEFAULT 'paper',
				 status varchar(16) NOT NULL DEFAULT 'active',
				 PRIMARY KEY (package_id),
				 UNIQUE KEY wc_product_unique (wc_product_id)"
			);
			$this->assert_status( 'play_product_created', $service->create( $malformed_product, 'paper', 1 ), 'the approved minimal Package signature is accepted and the unclaimed product is creatable' );

			$still_claimed = $this->make_product( $disposable, 'pkg still claimed' );
			$wpdb->insert( $packages, [ 'wc_product_id' => $still_claimed, 'status' => 'active' ], [ '%d', '%s' ] );
			$this->assert_error( 'package_claim_exists', $service->create( $still_claimed, 'paper', 1 ), 'the accepted signature still rejects an existing claim' );

			// E. The ordinary migration restores the authoritative shape.
			$this->drop_owned_package_stub( $disposable );
			$this->assert_true( true === Migrations::migrate_current_site(), 'migrate_current_site recreates the authoritative Package table' );
			$this->assert_true( true === Migrations::verify_packages_schema(), '  and it verifies against the v3 contract' );
			$this->assert_status( 'play_product_created', $service->create( $this->make_product( $disposable, 'pkg after stub' ), 'paper', 1 ), 'creation resumes against the real Package table' );

			// The admin selectors are convenience reads only, but both must hide
			// either kind of existing Catalog claim. The writer assertions above
			// remain the authoritative ADR-032 verification.
			$selector_package_product = $this->make_product( $disposable, 'selector package claim' );
			$selector_play_product    = $this->make_product( $disposable, 'selector play claim' );
			$selector_free_product    = $this->make_product( $disposable, 'selector free' );
			$now                      = current_time( 'mysql', true );
			$wpdb->insert(
				$packages,
				[
					'wc_product_id' => $selector_package_product,
					'package_type'  => 'paper',
					'status'        => 'active',
					'created_at'    => $now,
					'updated_at'    => $now,
				],
				[ '%d', '%s', '%s', '%s', '%s' ]
			);
			$this->assert_status( 'play_product_created', $service->create( $selector_play_product, 'paper', 1 ), 'selector fixture creates an authoritative Play Product claim' );

			$play_method = new ReflectionMethod( PlayProductAdmin::class, 'linkable_products' );
			$play_method->setAccessible( true );
			$play_choices = (array) $play_method->invoke( null, $service );

			$package_method = new ReflectionMethod( PackageAdmin::class, 'linkable_products' );
			$package_method->setAccessible( true );
			$package_choices = (array) $package_method->invoke( null, new PackageService() );

			foreach ( [ 'Play Product' => $play_choices, 'Package' => $package_choices ] as $selector => $choices ) {
				$this->assert_true( ! isset( $choices[ $selector_package_product ] ), "{$selector} selector excludes an existing Package claim" );
				$this->assert_true( ! isset( $choices[ $selector_play_product ] ), "{$selector} selector excludes an existing Play Product claim" );
				$this->assert_true( isset( $choices[ $selector_free_product ] ), "{$selector} selector retains an unclaimed eligible WooCommerce product" );
			}
		} finally {
			restore_current_blog();
		}

		// Persistent halls were never involved.
		foreach ( [ self::SUNRISE, self::TAMPA, 4 ] as $persistent ) {
			switch_to_blog( $persistent );
			$this->assert_true( true === Migrations::verify_packages_schema(), "persistent hall {$persistent} Package table untouched by the claim group" );
			restore_current_blog();
		}

		$this->destroy_disposable_site( $disposable );
	}

	/* ==================================================================== *
	 * 11. Transport fail-closed behaviour
	 * ==================================================================== */

	private function transport_fail_closed(): void {
		global $wpdb;
		$this->group( 'Transport fail-closed' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$product = $this->make_product( self::SUNRISE, 'fail closed' );
			$now     = current_time( 'mysql', true );
			$plan    = CatalogPlayProductPlan::forCreate( self::SUNRISE, $product, 'paper', 1, null, false, $now );

			// Incompatible mysqli handle.
			$saved       = $wpdb->dbh;
			$wpdb->dbh   = new stdClass();
			$incompatible = CatalogTransactionTransport::createWithProductClaim( $plan );
			$wpdb->dbh   = $saved;
			$this->assert_error( 'incompatible_database_handle', $incompatible, 'a non-mysqli handle fails closed' );

			$wpdb->dbh    = null;
			$null_handle  = CatalogTransactionTransport::createWithProductClaim( $plan );
			$wpdb->dbh    = $saved;
			$this->assert_error( 'incompatible_database_handle', $null_handle, 'a null handle fails closed' );
			$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $product ), 'no row was written by either fail-closed attempt' );

			// Nested entry into the supported boundary.
			$this->set_transport_flag( 'active', true );
			$nested = CatalogTransactionTransport::createWithProductClaim( $plan );
			$this->set_transport_flag( 'active', false );
			$this->assert_error( 'catalog_transport_nested_use', $nested, 'nested Catalog transport entry is rejected' );
			$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $product ), 'the nested attempt wrote nothing' );

			// A poisoned session refuses every later boundary in the request.
			$this->set_transport_flag( 'untrusted', true );
			$poisoned = CatalogTransactionTransport::createWithProductClaim( $plan );
			$this->set_transport_flag( 'untrusted', false );
			$this->assert_error( 'catalog_transaction_state_untrusted', $poisoned, 'an untrusted session refuses a new critical transaction' );

			// A transaction already open on the captured session.
			$wpdb->dbh->begin_transaction();
			$already = CatalogTransactionTransport::createWithProductClaim( $plan );
			$wpdb->dbh->rollback();
			$this->assert_error( 'catalog_transaction_state_untrusted', $already, 'an externally opened transaction on the captured session fails closed' );

			// Wrong hall context for the plan.
			$foreign = CatalogTransactionTransport::createWithProductClaim(
				CatalogPlayProductPlan::forCreate( self::TAMPA, $product, 'paper', 1, null, false, $now )
			);
			$this->assert_error( 'invalid_hall_context', $foreign, 'a plan built for another hall is rejected' );

			// A plan cannot be routed through the wrong lock profile.
			$mismatched = CatalogTransactionTransport::restoreWithWooRevalidation( $plan );
			$this->assert_error( 'catalog_transport_plan_mismatch', $mismatched, 'a create plan cannot enter the restore boundary' );

			// A derived table identifier outside the naming contract fails closed
			// before BEGIN, and is never silently rewritten into another table.
			$real_prefix   = $wpdb->prefix;
			$wpdb->prefix  = $real_prefix . 'bad-prefix.';
			$rejected      = CatalogTransactionTransport::createWithProductClaim(
				CatalogPlayProductPlan::forCreate( self::SUNRISE, $product, 'paper', 1, null, false, $now )
			);
			$wpdb->prefix  = $real_prefix;
			$this->assert_error( 'catalog_identifier_rejected', $rejected, 'an unsupported derived table identifier fails closed before BEGIN' );
			$this->assert_error( 'play_product_not_found', $service->getByWooProductId( $product ), '  and nothing was written anywhere' );

			$this->assert_status( 'play_product_created', $service->create( $product, 'paper', 1 ), 'the transport still works after every fail-closed path' );
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * 12. Ambiguous COMMIT
	 * ==================================================================== */

	private function ambiguous_commit(): void {
		global $wpdb;
		$this->group( 'Ambiguous COMMIT' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$product = $this->make_product( self::SUNRISE, 'ambiguous' );
			$audit   = CoreMigrations::audit_log_table();

			$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" );

			// A real connection to the same database whose COMMIT never returns a
			// clean acknowledgement. BEGIN, the locks, and the INSERT all run for
			// real; only the acknowledgement is lost, which is the exact shape of
			// an interrupted COMMIT.
			$saved      = $wpdb->dbh;
			$ambiguous  = new BcUnacknowledgedCommitConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
			$ambiguous->set_charset( 'utf8mb4' );
			$wpdb->dbh  = $ambiguous;
			$began      = microtime( true );
			$result     = $service->create( $product, 'paper', 3 );
			$elapsed    = microtime( true ) - $began;
			$wpdb->dbh  = $saved;
			$ambiguous->close();

			$this->assert_error( 'catalog_commit_outcome_unknown', $result, 'COMMIT never reached the server and the acknowledgement failed: catalog_commit_outcome_unknown' );
			$this->assert_false( $result instanceof PlayProductResult, 'an ambiguous COMMIT is never reported as success' );
			$this->assert_true( $elapsed < 1.0, sprintf( 'an ambiguous COMMIT returns promptly without retrying (%.3fs)', $elapsed ) );
			$this->assert_same( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" ), 'an ambiguous COMMIT emits no audit event' );

			// The session is now untrusted, which is the observable proof that the
			// transport did not quietly retry the mutation on the same handle.
			$this->assert_error(
				'catalog_transaction_state_untrusted',
				$service->create( $this->make_product( self::SUNRISE, 'after ambiguous' ), 'paper', 1 ),
				'after an ambiguous COMMIT the transport fails closed instead of retrying'
			);
			$this->set_transport_flag( 'untrusted', false );

			// Current Catalog state must never be used to infer the outcome. The
			// harness reads it only to report what actually happened.
			$landed = $service->getByWooProductId( $product );
			$this->assert_error( 'play_product_not_found', $landed, '  this case did NOT commit, so no row exists (an acknowledgement failure, not a lost ACK)' );

			$this->assert_status( 'play_product_created', $service->create( $this->make_product( self::SUNRISE, 'after recovery' ), 'paper', 1 ), 'ordinary mutations resume once the session is healthy' );

			$this->committed_but_unknown( $service );

			// A genuine mid-transaction connection kill: definitely not committed.
			$killed = $this->kill_during_transaction( $service );
			$this->note( 'mid-transaction KILL outcome: ' . $killed );
		} finally {
			restore_current_blog();
			$this->set_transport_flag( 'untrusted', false );
		}
	}

	/**
	 * The committed-but-unknown case: the server durably committed, but the
	 * transport was told the acknowledgement failed.
	 *
	 * This is the scenario that proves why ADR-032 forbids reconciling from
	 * current Catalog state. The row is present afterwards, yet the operation
	 * still cannot claim success, must not audit, and must not retry.
	 */
	private function committed_but_unknown( BingoPlayProductService $service ): void {
		global $wpdb;

		$product   = $this->make_product( self::SUNRISE, 'committed unknown' );
		$game_type = $this->make_game_type( self::SUNRISE, 'Committed Unknown' );
		$audit     = CoreMigrations::audit_log_table();

		$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" );

		$saved     = $wpdb->dbh;
		$seam      = new BcCommittedButUnknownConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$began     = microtime( true );
		$result    = ( new BingoPlayProductService() )->create( $product, 'digital', 3, $game_type );
		$elapsed   = microtime( true ) - $began;
		$wpdb->dbh = $saved;
		$seam->close();

		$this->assert_error( 'catalog_commit_outcome_unknown', $result, 'a committed transaction with a failed acknowledgement returns catalog_commit_outcome_unknown' );
		$this->assert_false( $result instanceof PlayProductResult, '  and is never reported as success' );
		$this->assert_true( $elapsed < 1.0, sprintf( '  and does not retry (%.3fs)', $elapsed ) );

		// Read the authoritative table directly: the service is untrusted now.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT bingo_play_product_id, medium, game_type_id FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $product ),
			ARRAY_A
		);
		$this->assert_true( is_array( $row ), '  the authoritative Play Product row DOES exist — the transaction really committed' );
		$this->assert_same( 'digital', is_array( $row ) ? $row['medium'] : null, '  with the committed medium' );

		$this->assert_same( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" ), '  and no catalog.play_product.created audit event was emitted' );
		if ( is_array( $row ) ) {
			$this->assert_same( [], $this->audit_actions( (int) $row['bingo_play_product_id'] ), '  no audit event exists for the committed row' );
		}

		$this->assert_error(
			'catalog_transaction_state_untrusted',
			( new BingoPlayProductService() )->create( $this->make_product( self::SUNRISE, 'after committed unknown' ), 'paper', 1 ),
			'  the transport stays poisoned for the rest of the request'
		);
		$this->set_transport_flag( 'untrusted', false );

		$this->note( 'committed-but-unknown: the row exists and no audit event does — current Catalog state can never reconcile an ambiguous COMMIT' );
	}

	/**
	 * A definite lock-conflict error whose ROLLBACK then fails must NOT be
	 * retried: a retry would issue BEGIN on a session whose transaction is still
	 * open.
	 */
	private function rollback_failure_never_retries(): void {
		global $wpdb;
		$this->group( 'Lock conflict followed by a failed ROLLBACK' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$product = $this->make_product( self::SUNRISE, 'rollback failure' );
			$audit   = CoreMigrations::audit_log_table();

			$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" );

			// Hold the Woo anchor row so the first NOWAIT lock fails with errno
			// 3572 — a definite, normally retryable pre-COMMIT lock conflict.
			$lock = $this->hold_row_lock( self::SUNRISE, 'posts', $product, 2500 );

			$saved     = $wpdb->dbh;
			$seam      = new BcRollbackFailureConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
			$seam->set_charset( 'utf8mb4' );
			$wpdb->dbh = $seam;
			$began     = microtime( true );
			$result    = ( new BingoPlayProductService() )->create( $product, 'paper', 2 );
			$elapsed   = microtime( true ) - $began;
			$begins    = $seam->begin_count;
			$wpdb->dbh = $saved;
			$seam->close();
			$this->release_row_lock( $lock );

			$this->assert_error( 'catalog_transaction_state_untrusted', $result, 'a failed ROLLBACK returns catalog_transaction_state_untrusted, not the lock-conflict code' );
			$this->assert_false( $result instanceof PlayProductResult, '  and never reports success' );
			$this->assert_same( 1, $begins, '  exactly ONE transaction was begun — the lock conflict was not retried' );
			$this->assert_true( $elapsed < 2.0, sprintf( '  and it returned without consuming the retry budget (%.3fs)', $elapsed ) );

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $product ) ),
				'  no DML landed'
			);
			$this->assert_same( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" ), '  and no audit event was emitted' );

			// The poisoned session must refuse every later boundary in this request.
			$this->assert_error(
				'catalog_transaction_state_untrusted',
				( new BingoPlayProductService() )->create( $this->make_product( self::SUNRISE, 'after rollback failure' ), 'paper', 1 ),
				'  a later Catalog boundary in the same request also fails closed'
			);

			$this->set_transport_flag( 'untrusted', false );
			$this->note( sprintf( 'rollback-failure path: 1 BEGIN, no retry, returned catalog_transaction_state_untrusted in %.3fs', $elapsed ) );
		} finally {
			restore_current_blog();
			$this->set_transport_flag( 'untrusted', false );
		}
	}

	/**
	 * ADR-032 forbids callbacks inside the critical transaction. Prove that a
	 * registered gettext filter is never invoked while the boundary is open,
	 * including on an in-transaction domain rejection.
	 */
	private function no_translation_callbacks_inside_transaction(): void {
		$this->group( 'No WordPress callbacks inside the transaction' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();

			// Build every fixture BEFORE the probe is registered. WordPress and
			// WooCommerce translate thousands of strings while loading products, and
			// the probe must observe the rejection paths, not the fixture setup.
			$product = $this->make_product( self::SUNRISE, 'gettext probe' );
			$this->assert_status( 'play_product_created', $service->create( $product, 'paper', 1 ), 'a linked product exists so the next create is rejected inside the transaction' );

			$archived = $this->make_game_type( self::SUNRISE, 'Gettext Archived' );
			( new GameTypeService() )->archive( $archived );
			$other = $this->make_product( self::SUNRISE, 'gettext probe two' );

			// Resolve the reflection handle ONCE. Doing it per callback makes the
			// probe cost dominate the whole suite.
			$active_flag = new ReflectionProperty( CatalogTransactionTransport::class, 'active' );
			$active_flag->setAccessible( true );

			$calls      = 0;
			$violations = 0;
			$probe      = static function ( $translation ) use ( &$calls, &$violations, $active_flag ) {
				++$calls;
				if ( true === $active_flag->getValue() ) {
					++$violations;
				}

				return $translation;
			};

			add_filter( 'gettext', $probe, 10, 1 );
			add_filter( 'gettext_bingocommerce-catalog', $probe, 10, 1 );

			// Two distinct in-transaction rejection shapes. Both are detected under
			// the locks, inside the open transaction, and both previously built
			// their message with __().
			$duplicate   = $service->create( $product, 'paper', 1 );
			$gt_rejected = $service->create( $other, 'digital', 3, $archived );

			// Any violation recorded up to here is a real ADR-032 breach.
			$violations_during_rejections = $violations;
			$calls_during_rejections      = $calls;

			// A service-level rejection, entirely outside any transaction.
			$outside = $service->create( $other, 'hybrid', 1 );

			// POSITIVE CONTROL: with the transport flag set, a translation call MUST
			// be recorded. Without this, violations === 0 could just mean the
			// detector never works.
			$this->set_transport_flag( 'active', true );
			__( 'positive control', 'bingocommerce-catalog' );
			$this->set_transport_flag( 'active', false );
			$control_violations = $violations;

			remove_filter( 'gettext', $probe, 10 );
			remove_filter( 'gettext_bingocommerce-catalog', $probe, 10 );

			$this->assert_error( 'play_product_already_linked', $duplicate, 'the duplicate is rejected from inside the transaction' );
			$this->assert_error( 'game_type_not_active', $gt_rejected, 'the archived Game Type is rejected from inside the transaction' );
			$this->assert_error( 'invalid_medium', $outside, 'a service-level rejection happens outside any transaction' );

			$this->assert_same( 0, $violations_during_rejections, 'NO gettext callback ran while a Catalog transaction was active' );
			$this->assert_true( $calls > 0, 'the probe is live: translation outside the boundary does invoke it' );
			$this->assert_true( $control_violations > 0, 'positive control: the detector DOES record a translation call made while the transport is active' );

			// Localization still reaches the Hall Manager, from the presentation
			// layer, after the boundary has closed.
			$rendered = PlayProductAdmin::error_message( $duplicate );
			$this->assert_true( '' !== $rendered, 'the presentation layer still produces a message for a transport error' );
			$this->assert_true(
				str_contains( $rendered, 'Bingo Play Product' ),
				'  and it is the localized presentation string, produced after the boundary closed'
			);

			$this->note( sprintf(
				'gettext probe: %d callback invocations during the two in-transaction rejections (0 expected, since the transport no longer translates), %d of them while a transaction was active; positive control recorded %d',
				$calls_during_rejections,
				$violations_during_rejections,
				$control_violations
			) );
		} finally {
			restore_current_blog();
			$this->set_transport_flag( 'active', false );
		}
	}

	/**
	 * Kill the captured session while a Catalog transaction holds its locks, then
	 * confirm nothing partial survives. COMMIT is never reached, so the contract
	 * is an ordinary failure rather than an unknown outcome.
	 */
	private function kill_during_transaction( BingoPlayProductService $service ): string {
		global $wpdb;

		$hall = $this->concurrency_hall();
		if ( $hall < 1 ) {
			return 'skipped (no disposable hall)';
		}

		// The whole scenario runs on a disposable hall, because it attaches a
		// SLEEP trigger to bingo_play_products and then KILLs the session
		// mid-transaction. A crash in that window must not be able to leave a
		// trigger on a persistent hall's authoritative table.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );

		$product = $this->make_product( $hall, 'killed' );
		$table   = Migrations::play_products_table();

		$this->drop_disposable_trigger( $hall, 'bc_pp_kill_probe' );
		$this->create_disposable_trigger(
			$hall,
			"CREATE TRIGGER bc_pp_kill_probe BEFORE INSERT ON `{$table}` FOR EACH ROW BEGIN IF NEW.faces_per_unit = 4241 THEN SET @x = (SELECT SLEEP(3)); END IF; END"
		);

		$connection_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );

		// A separate process kills the captured session while the INSERT is still
		// sleeping inside the trigger, so the transaction dies before COMMIT.
		$child = proc_open(
			[ PHP_BINARY, '-r', sprintf(
				'$c = new mysqli(%s, %s, %s, %s); usleep(400000); $c->query("KILL %d"); $c->close();',
				var_export( DB_HOST, true ),
				var_export( DB_USER, true ),
				var_export( DB_PASSWORD, true ),
				var_export( DB_NAME, true ),
				$connection_id
			) ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);

		$result = $service->create( $product, 'paper', 4241 );

		if ( is_resource( $child ) ) {
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $child );
		}

		$wpdb->dbh = null;
		$wpdb->check_connection( false );
		$this->drop_disposable_trigger( $hall, 'bc_pp_kill_probe' );

		$outcome = is_wp_error( $result ) ? $result->get_error_code() : $result->status;
		$this->assert_true(
			is_wp_error( $result ),
			'a session killed mid-transaction never reports success (' . $outcome . ')'
		);

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT bingo_play_product_id FROM `{$table}` WHERE wc_product_id = %d", $product ), ARRAY_A );
		$this->assert_true( ! is_array( $row ), 'a killed transaction leaves no partial Catalog row' );

		$this->set_transport_flag( 'untrusted', false );
		$this->set_transport_flag( 'active', false );
		restore_current_blog();

		return $outcome . ( is_array( $row ) ? ' (ROW PRESENT)' : ' (no row)' );
	}

	/* ==================================================================== *
	 * 13. Real multi-process concurrency
	 * ==================================================================== */

	private function concurrency(): void {
		$this->group( 'Concurrency (real processes, real MySQL)' );

		$manager = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );

		// A. Two real processes racing to claim the SAME WooCommerce product.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$contested = $this->make_product( self::SUNRISE, 'race contested' );
		restore_current_blog();

		$responses = $this->run_create_workers(
			[
				[ self::SUNRISE, $manager, $contested, 'paper', 3, 0 ],
				[ self::SUNRISE, $manager, $contested, 'paper', 5, 0 ],
			]
		);

		$statuses = array_column( $responses, 'status' );
		$winners  = count( array_filter( $statuses, static fn( $s ) => 'play_product_created' === $s ) );
		$this->assert_same( 1, $winners, 'exactly one process wins the same-product create race: ' . implode( ', ', $statuses ) );
		foreach ( $statuses as $status ) {
			$this->assert_true(
				in_array( $status, [ 'play_product_created', 'play_product_already_linked', 'catalog_write_conflict' ], true ),
				"race outcome is a defined result ({$status})"
			);
		}

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$this->assert_same( 1, $this->row_count_for_product( $contested ), 'exactly one authoritative row exists for the contested product' );
		restore_current_blog();

		// B. Different products proceed independently.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$independent_a = $this->make_product( self::SUNRISE, 'race independent a' );
		$independent_b = $this->make_product( self::SUNRISE, 'race independent b' );
		restore_current_blog();

		$responses = $this->run_create_workers(
			[
				[ self::SUNRISE, $manager, $independent_a, 'paper', 1, 0 ],
				[ self::SUNRISE, $manager, $independent_b, 'paper', 1, 0 ],
			]
		);
		$this->assert_same(
			[ 'play_product_created', 'play_product_created' ],
			array_column( $responses, 'status' ),
			'concurrent creates on different products both succeed'
		);

		// C. NOWAIT contention on the Woo anchor row, resolved inside the budget.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$brief = $this->make_product( self::SUNRISE, 'nowait brief' );
		restore_current_blog();

		$lock = $this->hold_row_lock( self::SUNRISE, 'posts', $brief, 30 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$began    = microtime( true );
		$retried  = ( new BingoPlayProductService() )->create( $brief, 'paper', 2 );
		$elapsed  = microtime( true ) - $began;
		restore_current_blog();
		$this->release_row_lock( $lock );
		$this->assert_status( 'play_product_created', $retried, sprintf( 'a briefly held Woo row lock is resolved by bounded retry (%.3fs)', $elapsed ) );
		$this->note( sprintf( 'bounded retry under a 30ms Woo-row lock completed in %.3fs', $elapsed ) );

		// D. Retry exhaustion on a lock held past the whole budget.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$blocked = $this->make_product( self::SUNRISE, 'nowait blocked' );
		restore_current_blog();

		$lock = $this->hold_row_lock( self::SUNRISE, 'posts', $blocked, 2500 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$began     = microtime( true );
		$exhausted = ( new BingoPlayProductService() )->create( $blocked, 'paper', 2 );
		$elapsed   = microtime( true ) - $began;
		$service   = new BingoPlayProductService();
		$after     = $service->getByWooProductId( $blocked );
		restore_current_blog();
		$this->release_row_lock( $lock );

		$this->assert_error( 'catalog_write_conflict', $exhausted, 'retry exhaustion returns catalog_write_conflict' );
		$this->assert_true( $elapsed < 5.0, sprintf( 'NOWAIT keeps the failure bounded rather than waiting on innodb_lock_wait_timeout (%.3fs)', $elapsed ) );
		$this->assert_error( 'play_product_not_found', $after, 'an exhausted retry writes nothing' );
		$this->note( sprintf( 'retry exhaustion under a 2500ms Woo-row lock returned catalog_write_conflict in %.3fs (default innodb_lock_wait_timeout is %ds)', $elapsed, (int) $this->lock_wait_timeout() ) );

		// E. The target Game Type row is genuinely locked for a new reference.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$service   = new BingoPlayProductService();
		$gt_locked = $this->make_game_type( self::SUNRISE, 'Locked Reference' );
		$target_pp = $this->new_play_product( $service, 'gt lock target', 'paper', 1 );
		restore_current_blog();

		$lock = $this->hold_row_lock( self::SUNRISE, 'game_type', $gt_locked, 2500 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$began      = microtime( true );
		$gt_blocked = ( new BingoPlayProductService() )->assignGameType( $target_pp, $gt_locked );
		$elapsed    = microtime( true ) - $began;
		$unchanged  = ( new BingoPlayProductService() )->getById( $target_pp );
		restore_current_blog();
		$this->release_row_lock( $lock );

		$this->assert_error( 'catalog_write_conflict', $gt_blocked, 'a held Game Type row lock blocks reference creation' );
		$this->assert_null( $unchanged['game_type_id'], '  and the reference was not written' );
		$this->assert_true( $elapsed < 5.0, sprintf( '  bounded by NOWAIT + retry budget (%.3fs)', $elapsed ) );
		$this->note( 'Game Type row lock serialization confirmed: assignGameType contends on the exact game_type row a future hard-delete writer will lock' );

		// F. Paper -> Digital also serializes on the Game Type row.
		$lock = $this->hold_row_lock( self::SUNRISE, 'game_type', $gt_locked, 2500 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$medium_blocked = ( new BingoPlayProductService() )->updateMedium( $target_pp, 'digital', $gt_locked );
		$still_paper    = ( new BingoPlayProductService() )->getById( $target_pp );
		restore_current_blog();
		$this->release_row_lock( $lock );
		$this->assert_error( 'catalog_write_conflict', $medium_blocked, 'Paper -> Digital contends on the same Game Type row' );
		$this->assert_same( 'paper', $still_paper['medium'], '  and never persists a partial transition' );
		$this->assert_null( $still_paper['game_type_id'], '  and never persists digital + NULL' );

		// G. Concurrent medium change and Game Type clear on the same row.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$this->concurrent_clear_versus_digital();
		restore_current_blog();
	}

	/**
	 * The ordinary $wpdb clear and the ADR-032 Paper -> Digital transition guard
	 * on `medium` inside their own statements, so no interleaving can persist
	 * digital + NULL. Run repeatedly against real MySQL.
	 */
	private function concurrent_clear_versus_digital(): void {
		$manager  = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );
		$type     = $this->make_game_type( self::SUNRISE, 'Race Clear' );
		$service  = new BingoPlayProductService();
		$outcomes = [];

		for ( $round = 0; $round < 8; ++$round ) {
			$id = $this->new_play_product( $service, "race clear {$round}", 'paper', 1, $type );

			// The worker runs the real ADR-032 Paper -> Digital transition while the
			// parent runs the ordinary $wpdb clear against the same row. These are
			// the two statements that could, if either dropped its guard, interleave
			// into medium = digital with a NULL Game Type.
			$start  = microtime( true ) + 0.35;
			$worker = proc_open(
				[ PHP_BINARY, __DIR__ . '/play-product-slow-mutation-worker.php', (string) self::SUNRISE, (string) $manager, (string) $id, (string) $type, sprintf( '%.6f', $start ), 'digital' ],
				[ 1 => [ 'pipe', 'w' ] ],
				$pipes
			);

			// Half the rounds let the clear land first, half let the ADR-032
			// transition land first, so both interleavings are exercised.
			$offset = ( $round % 2 ) ? 0.090 : -0.010;
			while ( microtime( true ) < $start + $offset ) {
				usleep( 100 );
			}
			$clear = $service->clearGameType( $id );

			$raw = trim( (string) stream_get_contents( $pipes[1] ) );
			fclose( $pipes[1] );
			proc_close( $worker );
			$transition = json_decode( $raw, true );

			$row        = $service->getById( $id );
			$outcomes[] = sprintf(
				'clear=%s/digital=%s => %s+%s',
				is_wp_error( $clear ) ? $clear->get_error_code() : $clear->status,
				$transition['status'] ?? '?',
				$row['medium'],
				null === $row['game_type_id'] ? 'NULL' : 'ref'
			);

			$this->assert_false(
				'digital' === $row['medium'] && null === $row['game_type_id'],
				"round {$round}: concurrent clear vs Paper -> Digital never persists digital + NULL"
			);
			$this->assert_true(
				'digital' !== $row['medium'] || null !== $row['game_type_id'],
				"round {$round}: every Digital row still carries a Game Type"
			);
			if ( 'digital' === $row['medium'] && is_wp_error( $clear ) ) {
				$this->assert_same(
					'game_type_required_for_digital',
					$clear->get_error_code(),
					"round {$round}: a clear that loses the race is rejected rather than clearing a Digital row"
				);
			}
		}

		$this->note( 'clear vs Paper -> Digital race outcomes: ' . implode( ' | ', $outcomes ) );
		$this->assert_no_digital_without_game_type( self::SUNRISE, 'no digital + NULL row exists after the concurrent races' );
	}

	/**
	 * Measure how long a live ADR-032 Catalog transaction can stall a real
	 * WooCommerce product administration save, because the serialization anchor
	 * is a WordPress-owned wp_posts row.
	 */
	private function woo_admin_blocked_by_catalog_transaction(): void {
		global $wpdb;

		// Disposable hall: this scenario attaches a SLEEP trigger to
		// bingo_play_products so a live ADR-032 transaction genuinely holds the
		// wp_posts anchor. The measured behaviour is identical on any hall, and
		// a crash here cannot leave a trigger on a persistent hall.
		$hall = $this->concurrency_hall();
		if ( $hall < 1 ) {
			return;
		}
		$manager = $this->super_admin_id;

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$product = $this->make_product( $hall, 'anchor hold' );
		$table   = Migrations::play_products_table();
		$this->drop_disposable_trigger( $hall, 'bc_pp_anchor_probe' );
		$this->create_disposable_trigger(
			$hall,
			"CREATE TRIGGER bc_pp_anchor_probe BEFORE INSERT ON `{$table}` FOR EACH ROW BEGIN IF NEW.faces_per_unit = 4240 THEN SET @x = (SELECT SLEEP(1)); END IF; END"
		);
		restore_current_blog();

		$start   = microtime( true ) + 0.4;
		$creator = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-create-worker.php', (string) $hall, (string) $manager, (string) $product, 'paper', '4240', '0', sprintf( '%.6f', $start ) ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		while ( microtime( true ) < $start + 0.25 ) {
			usleep( 500 );
		}
		$began   = microtime( true );
		$saved   = wc_get_product( $product );
		$outcome = 'unavailable';
		if ( $saved instanceof WC_Product ) {
			$saved->set_description( 'admin save during a live Catalog transaction' );
			$saved->save();
			$outcome = 'saved';
		}
		$admin_wait = microtime( true ) - $began;
		restore_current_blog();

		$raw = trim( (string) stream_get_contents( $pipes[1] ) );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $creator );
		$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
		$created = json_decode( (string) end( $lines ), true );

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$this->drop_disposable_trigger( $hall, 'bc_pp_anchor_probe' );
		$rows = $this->row_count_for_product( $product );
		restore_current_blog();

		$this->assert_same( 'saved', $outcome, 'a WooCommerce admin save completes while a Catalog transaction holds the anchor row' );
		$this->assert_true( $admin_wait < 30.0, sprintf( '  and its wait stays far inside innodb_lock_wait_timeout (%.3fs)', $admin_wait ) );
		$this->assert_true( $rows <= 1, '  and at most one authoritative Play Product row exists' );
		$this->note( sprintf(
			'WooCommerce admin save waited %.3fs while a live ADR-032 transaction held the wp_posts anchor for ~1s; the create returned %s. The anchor lock is held only for the transaction, so a stalled Catalog transaction would stall product administration for as long as it runs.',
			$admin_wait,
			$created['status'] ?? '?'
		) );
	}

	/* ==================================================================== *
	 * 14. wp_posts / WooCommerce administration contention
	 * ==================================================================== */

	private function woo_contention(): void {
		$this->group( 'wp_posts contention with WooCommerce administration' );

		$manager = $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE );

		// A. Creation racing a real WooCommerce product admin save.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$saved_product = $this->make_product( self::SUNRISE, 'contend save' );
		restore_current_blog();

		$start   = microtime( true ) + 0.35;
		$writer  = $this->start_woo_writer( self::SUNRISE, $manager, $saved_product, 'save', $start );
		$create  = $this->run_create_workers( [ [ self::SUNRISE, $manager, $saved_product, 'paper', 2, 0 ] ], $start )[0];
		$writer_result = $this->finish_worker( $writer );

		$this->assert_true(
			in_array( $create['status'], [ 'play_product_created', 'catalog_write_conflict' ], true ),
			"creation against a concurrent product save resolves to a defined outcome ({$create['status']})"
		);
		$this->assert_true( (float) $create['elapsed'] < 5.0, sprintf( '  and completes bounded (%.3fs)', (float) $create['elapsed'] ) );
		$this->note( sprintf( 'create vs WooCommerce admin save: create=%s in %.3fs, save=%s in %.3fs', $create['status'], (float) $create['elapsed'], $writer_result['outcome'] ?? '?', (float) ( $writer_result['elapsed'] ?? 0 ) ) );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$this->assert_true( $this->row_count_for_product( $saved_product ) <= 1, '  and never leaves more than one authoritative row' );
		restore_current_blog();

		// A2. A live Catalog transaction holding the anchor vs a real admin save.
		$this->woo_admin_blocked_by_catalog_transaction();

		// B. Creation racing a trash.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$trash_product = $this->make_product( self::SUNRISE, 'contend trash' );
		restore_current_blog();

		$start        = microtime( true ) + 0.35;
		$writer       = $this->start_woo_writer( self::SUNRISE, $manager, $trash_product, 'trash', $start );
		$create       = $this->run_create_workers( [ [ self::SUNRISE, $manager, $trash_product, 'paper', 2, 0 ] ], $start )[0];
		$writer_result = $this->finish_worker( $writer );

		$this->assert_true(
			in_array( $create['status'], [ 'play_product_created', 'woo_product_trashed', 'woo_product_missing', 'catalog_write_conflict' ], true ),
			"creation racing a trash resolves to a defined outcome ({$create['status']})"
		);
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$row = ( new BingoPlayProductService() )->getByWooProductId( $trash_product );
		$this->assert_true(
			is_wp_error( $row ) || in_array( $row['woo_state'], [ 'ok', 'trashed' ], true ),
			'  and any row that was created is preserved with its state surfaced, never silently archived'
		);
		if ( ! is_wp_error( $row ) ) {
			$this->assert_same( 'active', $row['status'], '  a trashed Woo product does not archive the authoritative row' );
		}
		restore_current_blog();
		$this->note( sprintf( 'create vs trash (create first): create=%s, writer=%s', $create['status'], $writer_result['outcome'] ?? '?' ) );

		// The reverse ordering: the trash lands before the create reaches its lock.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$trash_first = $this->make_product( self::SUNRISE, 'contend trash first' );
		restore_current_blog();

		$start         = microtime( true ) + 0.35;
		$writer        = $this->start_woo_writer( self::SUNRISE, $manager, $trash_first, 'trash', $start );
		$create        = $this->run_create_workers( [ [ self::SUNRISE, $manager, $trash_first, 'paper', 2, 0 ] ], $start + 0.25 )[0];
		$writer_result = $this->finish_worker( $writer );

		$this->assert_same( 'woo_product_trashed', $create['status'], 'a create that reaches the anchor after a trash is rejected' );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$this->assert_same( 0, $this->row_count_for_product( $trash_first ), '  and writes nothing' );
		restore_current_blog();
		$this->note( sprintf( 'create vs trash (trash first): create=%s, writer=%s', $create['status'], $writer_result['outcome'] ?? '?' ) );

		// C. Creation racing a permanent delete.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$delete_product = $this->make_product( self::SUNRISE, 'contend delete' );
		restore_current_blog();

		$start         = microtime( true ) + 0.35;
		$writer        = $this->start_woo_writer( self::SUNRISE, $manager, $delete_product, 'delete', $start );
		$create        = $this->run_create_workers( [ [ self::SUNRISE, $manager, $delete_product, 'paper', 2, 0 ] ], $start )[0];
		$writer_result = $this->finish_worker( $writer );
		$this->assert_true(
			in_array( $create['status'], [ 'play_product_created', 'woo_product_missing', 'woo_product_trashed', 'catalog_write_conflict' ], true ),
			"creation racing a permanent delete resolves to a defined outcome ({$create['status']})"
		);
		$this->note( sprintf( 'create vs permanent delete: create=%s, writer=%s', $create['status'], $writer_result['outcome'] ?? '?' ) );

		// D. Restore racing trash/untrash.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$service         = new BingoPlayProductService();
		$restore_product = $this->make_product( self::SUNRISE, 'contend restore' );
		$restore_pp      = (int) $service->create( $restore_product, 'paper', 1 )->play_product['bingo_play_product_id'];
		$service->archive( $restore_pp );
		restore_current_blog();

		$start  = microtime( true ) + 0.35;
		$writer = $this->start_woo_writer( self::SUNRISE, $manager, $restore_product, 'trash', $start );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		while ( microtime( true ) < $start ) {
			usleep( 200 );
		}
		$began    = microtime( true );
		$restored = ( new BingoPlayProductService() )->restore( $restore_pp );
		$elapsed  = microtime( true ) - $began;
		$final    = ( new BingoPlayProductService() )->getById( $restore_pp );
		restore_current_blog();
		$writer_result = $this->finish_worker( $writer );

		$code = is_wp_error( $restored ) ? $restored->get_error_code() : $restored->status;
		$this->assert_true(
			in_array( $code, [ 'play_product_restored', 'woo_product_trashed', 'catalog_write_conflict', 'catalog_current_state_changed' ], true ),
			"restore racing a trash resolves to a defined outcome ({$code})"
		);
		$this->assert_true( $elapsed < 5.0, sprintf( '  bounded completion (%.3fs)', $elapsed ) );
		$this->assert_true( in_array( $final['status'], [ 'active', 'archived' ], true ), '  and the row is left in a valid lifecycle state' );
		$this->note( sprintf( 'restore vs trash: restore=%s in %.3fs, writer=%s', $code, $elapsed, $writer_result['outcome'] ?? '?' ) );

		// Untrash then restore must succeed deterministically.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		if ( 'trash' === get_post_status( $restore_product ) ) {
			wp_untrash_post( $restore_product );
			wp_update_post( [ 'ID' => $restore_product, 'post_status' => 'publish' ] );
		}
		$service = new BingoPlayProductService();
		if ( 'archived' === $service->getById( $restore_pp )['status'] ) {
			$this->assert_status( 'play_product_restored', $service->restore( $restore_pp ), 'restore succeeds after the product is untrashed' );
		} else {
			$this->assert_status( 'play_product_already_active', $service->restore( $restore_pp ), 'restore is a no-op once the row is already active' );
		}
		restore_current_blog();
	}

	/* ==================================================================== *
	 * 15. Ordinary $wpdb writer vs a live ADR-032 row lock
	 * ==================================================================== */

	private function ordinary_writer_contention(): void {
		global $wpdb;
		$this->group( 'Ordinary $wpdb writer vs a live ADR-032 Play Product row lock' );

		// Disposable hall: a temporary SLEEP trigger on bingo_play_products is the
		// only way to make a real ADR-032 transaction hold the row lock long
		// enough to measure. A persistent hall must never carry that trigger.
		$hall = $this->concurrency_hall();
		if ( $hall < 1 ) {
			return;
		}
		$manager = $this->super_admin_id;

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$service = new BingoPlayProductService();
		$type    = $this->make_game_type( $hall, 'Writer Contention' );
		$id      = $this->new_play_product( $service, 'writer contention', 'paper', 4242 );
		$table   = Migrations::play_products_table();

		$this->drop_disposable_trigger( $hall, 'bc_pp_slow_probe' );
		$this->create_disposable_trigger(
			$hall,
			"CREATE TRIGGER bc_pp_slow_probe BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN IF OLD.faces_per_unit = 4242 AND NEW.faces_per_unit = 4242 THEN SET @x = (SELECT SLEEP(1)); END IF; END"
		);
		restore_current_blog();

		$start  = microtime( true ) + 0.4;
		$worker = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-slow-mutation-worker.php', (string) $hall, (string) $manager, (string) $id, (string) $type, sprintf( '%.6f', $start ) ],
			[ 1 => [ 'pipe', 'w' ] ],
			$pipes
		);

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		// Enter shortly AFTER the transaction has taken the row lock.
		while ( microtime( true ) < $start + 0.25 ) {
			usleep( 500 );
		}
		$began    = microtime( true );
		$ordinary = ( new BingoPlayProductService() )->updateFacesPerUnit( $id, 11 );
		$waited   = microtime( true ) - $began;
		restore_current_blog();

		$raw = trim( (string) stream_get_contents( $pipes[1] ) );
		fclose( $pipes[1] );
		proc_close( $worker );
		$transport = json_decode( $raw, true );

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$this->drop_disposable_trigger( $hall, 'bc_pp_slow_probe' );
		$final = ( new BingoPlayProductService() )->getById( $id );
		restore_current_blog();

		$code = is_wp_error( $ordinary ) ? $ordinary->get_error_code() : $ordinary->status;

		$this->assert_true(
			in_array( $code, [ 'play_product_faces_updated', 'catalog_current_state_changed', 'persistence_failed' ], true ),
			"the ordinary \$wpdb writer resolves to a defined outcome ({$code})"
		);
		$this->assert_true(
			'play_product_faces_updated' === $code ? 11 === $final['faces_per_unit'] : true,
			'  a reported success is durably applied'
		);
		$this->assert_true( in_array( $final['medium'], [ 'paper', 'digital' ], true ), '  the row is left in a valid state' );
		$this->assert_false( 'digital' === $final['medium'] && null === $final['game_type_id'], '  and never digital + NULL' );
		$this->assert_true( $waited < 30.0, sprintf( '  and the wait stayed well inside innodb_lock_wait_timeout (%.3fs)', $waited ) );

		$this->note( sprintf(
			'ordinary $wpdb writer waited %.3fs on the live ADR-032 row lock, then returned %s; the ADR-032 transaction returned %s in %.3fs. Final row: medium=%s faces=%d game_type=%s. Measured wait tracks how long the transaction holds the row, NOT a fixed bound: these UPDATE statements have no NOWAIT and would wait up to innodb_lock_wait_timeout (%ds) if a transaction stalled.',
			$waited,
			$code,
			$transport['status'] ?? '?',
			(float) ( $transport['elapsed'] ?? 0 ),
			$final['medium'],
			$final['faces_per_unit'],
			null === $final['game_type_id'] ? 'NULL' : (string) $final['game_type_id'],
			(int) $this->lock_wait_timeout()
		) );

		// Archive competing with the same live transport lock.
		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$service2 = new BingoPlayProductService();
		$id2      = $this->new_play_product( $service2, 'writer contention archive', 'paper', 4242 );
		$this->create_disposable_trigger(
			$hall,
			"CREATE TRIGGER bc_pp_slow_probe BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN IF OLD.faces_per_unit = 4242 AND NEW.faces_per_unit = 4242 THEN SET @x = (SELECT SLEEP(1)); END IF; END"
		);
		restore_current_blog();

		$start  = microtime( true ) + 0.4;
		$worker = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-slow-mutation-worker.php', (string) $hall, (string) $manager, (string) $id2, (string) $type, sprintf( '%.6f', $start ) ],
			[ 1 => [ 'pipe', 'w' ] ],
			$pipes
		);

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		while ( microtime( true ) < $start + 0.25 ) {
			usleep( 500 );
		}
		$began    = microtime( true );
		$archived = ( new BingoPlayProductService() )->archive( $id2 );
		$waited2  = microtime( true ) - $began;
		restore_current_blog();

		fclose( $pipes[1] );
		proc_close( $worker );

		switch_to_blog( $hall );
		wp_set_current_user( $manager );
		$this->drop_disposable_trigger( $hall, 'bc_pp_slow_probe' );
		$final2 = ( new BingoPlayProductService() )->getById( $id2 );
		restore_current_blog();

		$code2 = is_wp_error( $archived ) ? $archived->get_error_code() : $archived->status;
		$this->assert_true(
			in_array( $code2, [ 'play_product_archived', 'play_product_already_archived', 'catalog_current_state_changed', 'persistence_failed' ], true ),
			"the ordinary archive writer resolves to a defined outcome ({$code2})"
		);
		$this->assert_true(
			'play_product_archived' !== $code2 || 'archived' === $final2['status'],
			'  a reported archive is durably applied'
		);
		$this->note( sprintf( 'ordinary archive waited %.3fs on the live ADR-032 row lock and returned %s (final status: %s)', $waited2, $code2, $final2['status'] ) );
	}

	/* ==================================================================== *
	 * 16. Admin surface
	 * ==================================================================== */

	private function admin_surface(): void {
		$this->group( 'Admin surface' );

		switch_to_blog( self::SUNRISE );
		try {
			wp_set_current_user( $this->first_user_with_role( Roles::HALL_MANAGER, self::SUNRISE ) );
			$service = new BingoPlayProductService();
			$type    = $this->make_game_type( self::SUNRISE, 'Admin' );
			$product = $this->make_product( self::SUNRISE, 'admin' );

			$created = PlayProductAdmin::handle_request(
				[
					'bc_play_product_action' => 'create',
					'wc_product_id'          => (string) $product,
					'medium'                 => 'digital',
					'faces_per_unit'         => '3',
					'game_type_id'           => (string) $type,
				],
				$service
			);
			$this->assert_status( 'play_product_created', $created, 'admin create routes through the canonical service' );
			$id = (int) $created->play_product['bingo_play_product_id'];

			$this->assert_status( 'play_product_faces_updated', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'update_faces', 'bingo_play_product_id' => (string) $id, 'faces_per_unit' => '9' ], $service ), 'admin faces update routes through the service' );
			$this->assert_status( 'play_product_medium_changed', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'update_medium', 'bingo_play_product_id' => (string) $id, 'medium' => 'paper', 'game_type_id' => (string) $type ], $service ), 'admin Digital -> Paper drops the Game Type argument the service would reject' );
			$this->assert_status( 'play_product_game_type_cleared', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'clear_game_type', 'bingo_play_product_id' => (string) $id ], $service ), 'admin clear routes through the service' );
			$this->assert_status( 'play_product_archived', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'archive', 'bingo_play_product_id' => (string) $id ], $service ), 'admin archive routes through the service' );
			$this->assert_status( 'play_product_restored', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'restore', 'bingo_play_product_id' => (string) $id ], $service ), 'admin restore routes through the service' );

			$this->assert_error( 'invalid_action', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'hard_delete', 'bingo_play_product_id' => (string) $id ], $service ), 'no hard-delete admin action exists' );
			$this->assert_error( 'invalid_action', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'relink', 'bingo_play_product_id' => (string) $id ], $service ), 'no relink admin action exists' );
			$this->assert_error( 'invalid_game_type_id', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'assign_game_type', 'bingo_play_product_id' => (string) $id, 'game_type_id' => '0' ], $service ), 'admin assign requires a Game Type' );
			$this->assert_error( 'unexpected_scope_field', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'archive', 'bingo_play_product_id' => (string) $id, 'blog_id' => '3' ], $service ), 'a reserved scope field is rejected' );
			$this->assert_error( 'unexpected_scope_field', PlayProductAdmin::handle_request( [ 'bc_play_product_action' => 'archive', 'bingo_play_product_id' => (string) $id, 'status' => 'active' ], $service ), 'a reserved status field is rejected' );

			// wc_product_id is immutable: no admin action accepts it after creation.
			$other = $this->make_product( self::SUNRISE, 'admin relink target' );
			foreach ( [ 'update_medium', 'update_faces', 'assign_game_type', 'archive', 'restore' ] as $action ) {
				$before = $service->getById( $id )['wc_product_id'];
				PlayProductAdmin::handle_request(
					[ 'bc_play_product_action' => $action, 'bingo_play_product_id' => (string) $id, 'wc_product_id' => (string) $other, 'medium' => 'paper', 'faces_per_unit' => '2', 'game_type_id' => (string) $type ],
					$service
				);
				$this->assert_same( $before, $service->getById( $id )['wc_product_id'], "admin action {$action} cannot change the linked product" );
			}
		} finally {
			restore_current_blog();
		}
	}

	/* ==================================================================== *
	 * Fixtures and helpers
	 * ==================================================================== */

	private function make_product( int $site_id, string $label, string $class = 'WC_Product_Simple', string $status = 'publish' ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$product = new $class();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 6, false ) );
		$product->set_status( $status );
		$product->save();
		$id = (int) $product->get_id();

		update_post_meta( $id, self::OWNER_META, $this->run_token );
		$this->created_products[ $site_id ][] = $id;

		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	private function make_auto_draft_product( int $site_id ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$id = (int) wp_insert_post(
			[
				'post_title'  => self::PREFIX . ' auto draft',
				'post_type'   => 'product',
				'post_status' => 'auto-draft',
			]
		);
		wp_set_object_terms( $id, 'simple', 'product_type' );
		update_post_meta( $id, self::OWNER_META, $this->run_token );
		$this->created_products[ $site_id ][] = $id;

		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	/** @return array{0:int,1:int} [ variable product ID, variation ID ] */
	private function make_variation( int $site_id ): array {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$variable = new WC_Product_Variable();
		$variable->set_name( self::PREFIX . ' variable ' . wp_generate_password( 6, false ) );
		$variable->set_status( 'publish' );
		$variable->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( (int) $variable->get_id() );
		$variation->save();

		update_post_meta( (int) $variation->get_id(), self::OWNER_META, $this->run_token );
		update_post_meta( (int) $variable->get_id(), self::OWNER_META, $this->run_token );
		$this->created_products[ $site_id ][] = (int) $variation->get_id();
		$this->created_products[ $site_id ][] = (int) $variable->get_id();

		if ( $switched ) {
			restore_current_blog();
		}

		return [ (int) $variable->get_id(), (int) $variation->get_id() ];
	}

	private function make_game_type( int $site_id, string $name ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$previous_user = get_current_user_id();
		wp_set_current_user( $this->super_admin_id );

		$result = ( new GameTypeService() )->create( self::PREFIX . ' ' . $name . ' ' . wp_generate_password( 5, false ) );
		$id     = is_wp_error( $result ) ? 0 : (int) $result->game_type['game_type_id'];
		if ( $id > 0 ) {
			$this->created_game_types[ $site_id ][] = $id;
		}

		wp_set_current_user( $previous_user );
		if ( $switched ) {
			restore_current_blog();
		}

		return $id;
	}

	private function new_play_product( BingoPlayProductService $service, string $label, string $medium, int $faces, ?int $game_type_id = null ): int {
		$product = $this->make_product( get_current_blog_id(), $label );
		$result  = $service->create( $product, $medium, $faces, $game_type_id );

		if ( is_wp_error( $result ) ) {
			$this->assert_true( false, "fixture creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}

		return (int) $result->play_product['bingo_play_product_id'];
	}

	/** @return array<int,int> */
	private function selection_ids( BingoPlayProductService $service ): array {
		$rows = $service->listActiveForPackageSelection( 1, 200 );

		return is_wp_error( $rows ) ? [] : array_map( 'intval', array_column( $rows, 'bingo_play_product_id' ) );
	}

	/**
	 * @return array<int,string>
	 *
	 * bc_audit_log is network-wide while bingo_play_product_id is hall-local, so
	 * every audit read and delete in this suite is scoped by blog_id as well.
	 */
	private function audit_actions( int $play_product_id, ?int $site_id = null ): array {
		global $wpdb;
		$table   = CoreMigrations::audit_log_table();
		$site_id = $site_id ?? get_current_blog_id();

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action FROM {$table} WHERE blog_id = %d AND target_type = %s AND target_id = %s ORDER BY id",
					$site_id,
					'bingo_play_product',
					(string) $play_product_id
				)
			)
		);
	}

	/** Delete audit rows for one hall-local target. Always scoped by blog_id. */
	private function delete_audit_for( int $site_id, string $target_type, int $target_id ): void {
		global $wpdb;

		$wpdb->delete(
			CoreMigrations::audit_log_table(),
			[ 'blog_id' => $site_id, 'target_type' => $target_type, 'target_id' => (string) $target_id ],
			[ '%d', '%s', '%s' ]
		);
	}

	private function row_count_for_product( int $wc_product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $wc_product_id )
		);
	}

	private function assert_no_digital_without_game_type( int $site_id, string $message ): void {
		global $wpdb;
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . Migrations::play_products_table() . " WHERE medium = 'digital' AND game_type_id IS NULL"
		);

		if ( $switched ) {
			restore_current_blog();
		}

		$this->assert_same( 0, $count, $message );
	}

	private function lock_wait_timeout(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT @@innodb_lock_wait_timeout' );
	}

	/**
	 * Reserve one numeric ID that exists as a product in TWO halls at once, by
	 * raising each hall's posts AUTO_INCREMENT.
	 *
	 * Both halls are created for this run alone. An earlier revision did this on
	 * Sunrise and Tampa and relied on cleanup to restore their counters, which is
	 * a structural change to a persistent hall's posts table that a crash would
	 * leave behind. The scenario is unchanged: two independent halls, the same
	 * numeric WooCommerce product ID, proving Play Product rows never leak
	 * between them.
	 *
	 * @return array{0:int,1:int,2:int} shared product id (0 on failure), hall A, hall B
	 */
	private function reserve_shared_product_id(): array {
		global $wpdb;

		if ( [] === $this->shared_id_halls ) {
			$first  = $this->create_disposable_site();
			$second = $this->create_disposable_site();
			if ( $first < 1 || $second < 1 ) {
				return [ 0, 0, 0 ];
			}
			$this->shared_id_halls = [ $first, $second ];
		}

		[ $hall_a, $hall_b ] = $this->shared_id_halls;

		$ceiling = 0;
		foreach ( $this->shared_id_halls as $site_id ) {
			switch_to_blog( $site_id );
			$status  = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->posts ) ), ARRAY_A );
			$next    = max( (int) ( $status['Auto_increment'] ?? 0 ), 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM `{$wpdb->posts}`" ) );
			$ceiling = max( $ceiling, $next );
			restore_current_blog();
		}

		if ( $ceiling < 1 ) {
			return [ 0, $hall_a, $hall_b ];
		}

		/*
		 * Creating a product can mint an extra WordPress-managed auto-draft
		 * product, consuming an ID on one hall but not the other. Retry with a
		 * fresh target rather than skipping the scenario.
		 */
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$target = $ceiling + 5000 + ( $attempt * 1000 );

			foreach ( $this->shared_id_halls as $site_id ) {
				switch_to_blog( $site_id );
				// Disposable halls only. The gate aborts the run rather than
				// touching a persistent hall's posts table.
				$this->assert_disposable_hall( $site_id );
				$wpdb->query( "ALTER TABLE `{$wpdb->posts}` AUTO_INCREMENT = {$target}" );
				restore_current_blog();
			}

			$minted = [];
			foreach ( $this->shared_id_halls as $site_id ) {
				$minted[ $site_id ] = $this->make_product( $site_id, 'shared id' );
			}

			if ( $minted[ $hall_a ] === $minted[ $hall_b ] ) {
				return [ $minted[ $hall_a ], $hall_a, $hall_b ];
			}

			$ceiling = max( $minted[ $hall_a ], $minted[ $hall_b ] );
		}

		return [ 0, $hall_a, $hall_b ];
	}

	/**
	 * @param array<int,array{0:int,1:int,2:int,3:string,4:int,5:int}> $specs
	 * @return array<int,array<string,mixed>>
	 */
	private function run_create_workers( array $specs, ?float $start = null ): array {
		$start   = $start ?? microtime( true ) + 0.45;
		$worker  = __DIR__ . '/play-product-create-worker.php';
		$handles = [];
		$pipes   = [];

		foreach ( $specs as $key => $spec ) {
			[ $site_id, $actor, $product, $medium, $faces, $game_type ] = $spec;
			$handles[ $key ] = proc_open(
				[ PHP_BINARY, $worker, (string) $site_id, (string) $actor, (string) $product, $medium, (string) $faces, (string) $game_type, sprintf( '%.6f', $start ) ],
				[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
				$pipes[ $key ]
			);
		}

		$responses = [];
		foreach ( $handles as $key => $handle ) {
			$raw    = trim( (string) stream_get_contents( $pipes[ $key ][1] ) );
			$errors = trim( (string) stream_get_contents( $pipes[ $key ][2] ) );
			fclose( $pipes[ $key ][1] );
			fclose( $pipes[ $key ][2] );
			$exit = proc_close( $handle );

			$this->assert_same( 0, $exit, 'create worker exits cleanly: ' . $errors );
			$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
			$decoded = json_decode( (string) end( $lines ), true );
			$responses[ $key ] = is_array( $decoded ) ? $decoded : [ 'status' => 'worker_no_response', 'id' => 0, 'elapsed' => 0 ];
		}

		return $responses;
	}

	/** @return array{0:resource,1:array} */
	private function hold_row_lock( int $site_id, string $target, int $row_id, int $hold_ms ): array {
		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-row-lock-worker.php', (string) $site_id, $target, (string) $row_id, (string) $hold_ms ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);

		// Block until the worker confirms the lock is held.
		$line = fgets( $pipes[1] );
		$this->assert_true( is_string( $line ) && str_starts_with( $line, 'LOCKED' ), "row-lock worker acquired the {$target} lock" );

		return [ $handle, $pipes ];
	}

	/** @param array{0:resource,1:array} $lock */
	private function release_row_lock( array $lock ): void {
		[ $handle, $pipes ] = $lock;
		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $handle );
	}

	/** @return array{0:resource,1:array} */
	private function start_woo_writer( int $site_id, int $actor, int $product_id, string $action, float $start ): array {
		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-woo-writer-worker.php', (string) $site_id, (string) $actor, (string) $product_id, $action, sprintf( '%.6f', $start ) ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);

		return [ $handle, $pipes ];
	}

	/**
	 * @param array{0:resource,1:array} $worker
	 * @return array<string,mixed>
	 */
	private function finish_worker( array $worker ): array {
		[ $handle, $pipes ] = $worker;
		$raw = trim( (string) stream_get_contents( $pipes[1] ) );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $handle );

		$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
		$decoded = json_decode( (string) end( $lines ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/** White-box control of the transport's fail-closed guards. */
	private function set_transport_flag( string $property, bool $value ): void {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( null, $value );
	}

	private function first_user_with_role( string $role, int $site_id ): int {
		switch_to_blog( $site_id );
		$users = get_users( [ 'blog_id' => $site_id, 'role' => $role, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
		restore_current_blog();

		return isset( $users[0] ) ? (int) $users[0] : 0;
	}

	private function ordinary_user_id(): int {
		foreach ( get_users( [ 'blog_id' => 0, 'number' => 100, 'fields' => [ 'ID' ], 'orderby' => 'ID' ] ) as $user ) {
			$id = (int) $user->ID;
			if ( is_super_admin( $id ) ) {
				continue;
			}
			switch_to_blog( self::SUNRISE );
			$can = user_can( $id, 'bc_manage_products' );
			restore_current_blog();
			if ( ! $can ) {
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
		$snapshot = [];
		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );
			$snapshot[ $site_id ] = get_option( $GLOBALS['wpdb']->get_blog_prefix( $site_id ) . 'user_roles' );
			restore_current_blog();
		}

		return $snapshot;
	}

	private function delete_play_product( int $site_id, int $id ): void {
		global $wpdb;
		switch_to_blog( $site_id );
		$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => $id ], [ '%d' ] );
		$this->delete_audit_for( $site_id, 'bingo_play_product', $id );
		restore_current_blog();
	}

	/* ==================================================================== *
	 * Cleanup
	 * ==================================================================== */

	private function cleanup(): void {
		global $wpdb;

		wp_set_current_user( $this->super_admin_id );
		$this->set_transport_flag( 'active', false );
		$this->set_transport_flag( 'untrusted', false );

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );

			// No DROP TRIGGER here. This suite never attaches a trigger to a
			// persistent hall's tables, so there is nothing on Sunrise or Tampa to
			// drop, and issuing one as routine cleanup would imply otherwise. The
			// same applies to bingo_packages: at Catalog v3 that name belongs to
			// the authoritative hall table, and every stub lives on a disposable
			// hall.

			$play_products = Migrations::play_products_table();
			$products      = $this->created_products[ $site_id ] ?? [];
			if ( [] !== $products ) {
				$placeholders = implode( ',', array_fill( 0, count( $products ), '%d' ) );
				$ids          = (array) $wpdb->get_col( $wpdb->prepare( "SELECT bingo_play_product_id FROM {$play_products} WHERE wc_product_id IN ({$placeholders})", $products ) );
				foreach ( $ids as $id ) {
					$this->delete_audit_for( $site_id, 'bingo_play_product', (int) $id );
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$play_products} WHERE wc_product_id IN ({$placeholders})", $products ) );

				foreach ( $products as $product_id ) {
					wp_delete_post( (int) $product_id, true );
				}
			}

			foreach ( $this->created_game_types[ $site_id ] ?? [] as $game_type_id ) {
				$this->delete_audit_for( $site_id, 'game_type', (int) $game_type_id );
				$wpdb->delete( Migrations::game_types_table(), [ 'game_type_id' => (int) $game_type_id ], [ '%d' ] );
			}

			restore_current_blog();
		}

		// Every disposable hall this run created — the migration hall, the
		// Package-claim hall, the concurrency hall, and the two shared-product-ID
		// halls — together with all four of its Catalog tables and any trigger it
		// carried.
		$this->destroy_all_disposable_sites();

		// Ownership-scoped sweep for anything the tracked-ID pass missed. Auto-drafts
		// WordPress minted as a side effect are REPORTED, never deleted: appearing
		// after a baseline snapshot is existence timing, not ownership.
		$this->purge_residue();
		$this->report_unowned_auto_drafts();

		$this->verify_clean_state();
	}

	/** Destroy every disposable hall this run created, in creation order. */
	private function destroy_all_disposable_sites(): void {
		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->destroy_disposable_site( (int) $site_id );
		}

		$this->concurrency_hall = 0;
		$this->shared_id_halls  = [];
	}

	/**
	 * WordPress/WooCommerce mint an `auto-draft` product as a side effect of
	 * product creation. Recorded for REPORTING only: this suite never deletes a
	 * product because it appeared after a baseline snapshot, because existence
	 * timing is not ownership.
	 */
	/**
	 * Report Play Product rows this suite will not touch. An interrupted run that
	 * leaves unownable residue requires deliberate cleanup; the suite never
	 * guesses that a row is disposable.
	 */
	/**
	 * Residue this suite detected but refuses to delete. An interrupted prior run
	 * requires deliberate cleanup; the suite never guesses a row is disposable.
	 */
	private function report_unownable_residue(): void {
		foreach ( $this->unownable_residue as $site_id => $items ) {
			if ( [] === $items ) {
				continue;
			}
			$this->note( sprintf(
				'site %d: %d artefact(s) matched this suite\'s naming but carry NO ownership marker. They were LEFT UNTOUCHED and need deliberate cleanup: %s',
				$site_id,
				count( $items ),
				implode( '; ', $items )
			) );
		}
	}

	private function report_foreign_residue(): void {
		foreach ( $this->foreign_play_products as $site_id => $ids ) {
			if ( [] === $ids ) {
				continue;
			}
			$this->note( sprintf(
				'site %d: %d Play Product row(s) NOT owned by this suite were left untouched: %s',
				$site_id,
				count( $ids ),
				implode( ', ', array_map( static fn( $id ) => '#' . $id, $ids ) )
			) );
		}
	}

	private function record_baseline_auto_drafts(): void {
		global $wpdb;

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->baseline_auto_drafts[ $site_id ] = array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" )
			);
			restore_current_blog();
		}
	}

	/** Report, never delete, auto-drafts that appeared without this suite's marker. */
	private function report_unowned_auto_drafts(): void {
		global $wpdb;

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			if ( ! isset( $this->baseline_auto_drafts[ $site_id ] ) ) {
				continue;
			}

			switch_to_blog( $site_id );
			$current = array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" )
			);
			$new = array_values( array_diff( $current, $this->baseline_auto_drafts[ $site_id ] ) );
			restore_current_blog();

			if ( [] === $new ) {
				continue;
			}

			$this->note( sprintf(
				'site %d: %d auto-draft product(s) appeared during this run but carry NO ownership marker. They were LEFT UNTOUCHED: %s',
				$site_id,
				count( $new ),
				implode( ', ', array_map( static fn( $id ) => '#' . (int) $id, $new ) )
			) );
		}
	}

	/**
	 * Remove ONLY artefacts this suite can PROVE it owns.
	 *
	 * Two distinct authorities, never conflated:
	 *
	 *  - CURRENT RUN: exact IDs this process created and tracked.
	 *  - PRIOR INTERRUPTED RUN: only artefacts carrying the durable
	 *    OWNER_META marker this suite stamps on everything it creates.
	 *
	 * A `PPI1` title or Game Type name is treated purely as a DETECTION signal.
	 * It never authorizes deletion, because a Hall Manager may legitimately name
	 * a product "PPI1 Legitimate Hall Product". Anything detected but not proven
	 * is reported for deliberate cleanup and left completely untouched.
	 *
	 * There is no unqualified DELETE and no hall-wide audit delete anywhere here.
	 *
	 * @return array<int,array<int,int>> site => Play Product IDs left alone
	 */
	private function purge_residue(): array {
		global $wpdb;

		$like    = $wpdb->esc_like( self::PREFIX ) . '%';
		$foreign = [];

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );

			$play_products       = Migrations::play_products_table();
			$game_types          = Migrations::game_types_table();
			$foreign[ $site_id ] = [];

			// PROVEN ownership: the durable marker, never the title.
			$proven = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s",
						self::OWNER_META
					)
				)
			);

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $play_products ) ) ) === $play_products ) {
				if ( [] !== $proven ) {
					$in  = implode( ',', array_fill( 0, count( $proven ), '%d' ) );
					$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT bingo_play_product_id FROM {$play_products} WHERE wc_product_id IN ({$in})", $proven ) );
					foreach ( $ids as $id ) {
						$this->delete_audit_for( $site_id, 'bingo_play_product', (int) $id );
						$wpdb->delete( $play_products, [ 'bingo_play_product_id' => (int) $id ], [ '%d' ] );
					}
				}

				// Everything still present is, by definition, not ours.
				$foreign[ $site_id ] = array_map(
					'intval',
					(array) $wpdb->get_col( "SELECT bingo_play_product_id FROM {$play_products}" )
				);
			}

			// DETECTION ONLY for anything without the durable marker.
			$suspect_posts = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT ID, post_title FROM `{$wpdb->posts}` WHERE post_title LIKE %s", $like ),
				ARRAY_A
			);
			foreach ( $suspect_posts as $post ) {
				if ( in_array( (int) $post['ID'], $proven, true ) ) {
					continue;
				}
				$this->unownable_residue[ $site_id ][] = sprintf( 'WooCommerce product #%d "%s"', $post['ID'], $post['post_title'] );
			}

			// Game Types have no meta table, so a prior run's Game Types can never
			// be proven ours. They are reported, never deleted.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $game_types ) ) ) === $game_types ) {
				$suspect_types = (array) $wpdb->get_results(
					$wpdb->prepare( "SELECT game_type_id, name FROM {$game_types} WHERE name LIKE %s", $like ),
					ARRAY_A
				);
				$current = $this->created_game_types[ $site_id ] ?? [];
				foreach ( $suspect_types as $type ) {
					if ( in_array( (int) $type['game_type_id'], $current, true ) ) {
						continue;
					}
					$this->unownable_residue[ $site_id ][] = sprintf( 'Game Type #%d "%s"', $type['game_type_id'], $type['name'] );
				}
			}

			// Proven-owned products are safe to delete outright.
			foreach ( $proven as $post_id ) {
				wp_delete_post( $post_id, true );
			}

			restore_current_blog();
		}

		return $foreign;
	}

	/* ------------------------------------------------------------------ *
	 * Disposable hall for destructive migration testing
	 * ------------------------------------------------------------------ */

	/**
	 * Destructive migration scenarios DROP and structurally damage
	 * bingo_play_products. Running them against Sunrise/Tampa/Orlando risks
	 * permanent loss if the process dies mid-test, and snapshot/restore is not a
	 * safety mechanism because the restore may never execute.
	 *
	 * So they run on a purpose-created hall that exists only for this suite.
	 */
	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$domain = 'ppi1disposable' . wp_generate_password( 6, false ) . '.bingocommerce.test';
		$site   = wp_insert_site(
			[
				'domain'     => strtolower( $domain ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'PPI1 Disposable Migration Hall',
				'user_id'    => $this->super_admin_id,
			]
		);

		if ( is_wp_error( $site ) ) {
			$this->assert_true( false, 'disposable migration hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$this->disposable_site_id                    = (int) $site;
		$this->disposable_sites[ (int) $site ]       = true;

		// WooCommerce does not install its own tables on a site created this way,
		// and several disposable-hall groups create real products.
		if ( class_exists( 'WC_Install' ) ) {
			switch_to_blog( (int) $site );
			WC_Install::create_tables();
			restore_current_blog();
		}

		return (int) $site;
	}

	/**
	 * WordPress drops only the tables it knows about, so the hall-local Catalog
	 * tables and the three tables provisioned by the Network-Active Sessions plugin
	 * are removed explicitly. All belong to a site this PHP process created and
	 * recorded in $disposable_sites, so this is provably safe.
	 */
	private function destroy_disposable_site( int $site_id = 0 ): void {
		global $wpdb;

		$site_id = $site_id > 0 ? $site_id : $this->disposable_site_id;
		if ( $site_id < 1 || empty( $this->disposable_sites[ $site_id ] ) ) {
			return;
		}

		switch_to_blog( $site_id );

		// Suite-created triggers live on this hall's own Catalog tables, so they
		// disappear with the tables below. Dropping them explicitly first keeps
		// SHOW TRIGGERS clean even if a DROP TABLE is refused for any reason.
		foreach ( self::SUITE_TRIGGERS as $trigger ) {
			$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
		}

		// ALL FOUR hall-local Catalog v3 tables. WordPress does not know these
		// custom tables, so wp_delete_site() leaves every one of them behind.
		$catalog_tables = [
			Migrations::package_components_table(),
			Migrations::packages_table(),
			Migrations::play_products_table(),
			Migrations::game_types_table(),
		];

		// The Network-Active Sessions plugin provisions these three tables when this
		// process creates the disposable hall. Refuse to derive or drop anything
		// unless WordPress returns this exact disposable-site prefix.
		$expected_prefix = $wpdb->base_prefix . $site_id . '_';
		$site_prefix     = $wpdb->get_blog_prefix( $site_id );
		if ( $site_prefix !== $expected_prefix ) {
			fwrite( STDERR, "ABORT: disposable site {$site_id} has an unexpected table prefix.\n" );
			exit( 1 );
		}
		$session_tables = [
			$expected_prefix . 'bingo_session_packages',
			$expected_prefix . 'bingo_sessions',
			$expected_prefix . 'bingo_portal_session_templates',
			$expected_prefix . 'bingo_session_schedules',
			$expected_prefix . 'bingo_session_schedule_dates',
			$expected_prefix . 'bingo_session_schedule_packages',
		];
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . CoreMigrations::audit_log_table() . " WHERE blog_id = %d", $site_id ) );
		restore_current_blog();

		wp_delete_site( $site_id );

		foreach ( $catalog_tables as $table ) {
			// Exact, site-scoped identifier. Anything else is refused rather than
			// guessed at.
			if ( 1 === preg_match( '/^bc_' . $site_id . '_bingo_(package_components|packages|play_products|game_types)$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}
		foreach ( $session_tables as $table ) {
			if ( in_array( $table, [ $expected_prefix . 'bingo_session_packages', $expected_prefix . 'bingo_sessions', $expected_prefix . 'bingo_portal_session_templates', $expected_prefix . 'bingo_session_schedules', $expected_prefix . 'bingo_session_schedule_dates', $expected_prefix . 'bingo_session_schedule_packages' ], true ) ) {
				$this->disposable_ddl( $site_id, "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		$this->disposable_catalog_tables[ $site_id ] = $catalog_tables;
		unset( $this->disposable_sites[ $site_id ] );
		if ( $this->disposable_site_id === $site_id ) {
			$this->disposable_site_id = 0;
		}
	}

	/**
	 * Drop the Package stub ONLY when this run created it, and ONLY on a
	 * disposable hall.
	 */
	private function drop_owned_package_stub( int $site_id ): void {
		global $wpdb;

		if ( empty( $this->package_stub_created[ $site_id ] ) ) {
			return;
		}

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . Migrations::packages_table() . '`' );
		$this->package_stub_created[ $site_id ] = false;
	}

	/** Create a Package stub for the claim tests and record that we own it. */
	private function create_package_stub( int $site_id, string $columns ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$this->assert_no_real_package_table( $site_id );
		$table = Migrations::packages_table();
		$wpdb->query( "CREATE TABLE `{$table}` ( {$columns} ) ENGINE=InnoDB" );
		$this->package_stub_created[ $site_id ] = true;
	}

	/** Drop the disposable hall's own authoritative Package table for the absent-table case. */
	private function drop_disposable_package_table( int $site_id ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . Migrations::packages_table() . '`' );
		$this->package_stub_created[ $site_id ] = false;
	}

	/**
	 * The single gate that keeps every schema mutation in this suite off a
	 * persistent hall. Nothing may drop, create, rename or alter a Catalog table
	 * on any hall except the one created for this run.
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

	/** Structural DDL, permitted on a disposable hall only. */
	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
	}

	/**
	 * A disposable hall kept alive for every trigger-driven concurrency scenario.
	 *
	 * These scenarios need a real MySQL trigger to hold a genuine row lock for a
	 * measurable time. A trigger attached to a PERSISTENT hall's authoritative
	 * table would survive a crash and silently affect that hall afterwards, so
	 * the whole scenario runs here instead.
	 */
	private function concurrency_hall(): int {
		if ( $this->concurrency_hall > 0 ) {
			return $this->concurrency_hall;
		}

		$site_id = $this->create_disposable_site();
		if ( $site_id < 1 ) {
			return 0;
		}

		$this->concurrency_hall = $site_id;

		return $site_id;
	}

	/**
	 * Create a trigger, on a disposable hall only, and record it so teardown can
	 * prove it is gone.
	 */
	private function create_disposable_trigger( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
	}

	private function drop_disposable_trigger( int $site_id, string $trigger ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		if ( ! in_array( $trigger, self::SUITE_TRIGGERS, true ) ) {
			fwrite( STDERR, "ABORT: unknown trigger name {$trigger}.\n" );
			exit( 1 );
		}

		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
	}

	/** Refuse to stub over a bingo_packages table this suite did not create. */
	private function assert_no_real_package_table( int $site_id ): void {
		global $wpdb;

		if ( ! empty( $this->package_stub_created[ $site_id ] ) ) {
			return;
		}

		$table = Migrations::packages_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			fwrite(
				STDERR,
				"ABORT: {$table} already exists on site {$site_id} and was not created by this run.\n"
				. "This suite will not drop or modify a Bingo Package table it does not own.\n"
			);
			exit( 1 );
		}
	}

	private function verify_clean_state(): void {
		global $wpdb;
		$this->group( 'Cleanup verification' );

		foreach ( [ self::SUNRISE, self::TAMPA ] as $site_id ) {
			switch_to_blog( $site_id );

			$prefix = self::PREFIX;
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ),
				"site {$site_id}: no test products remain"
			);

			$leftovers = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' pp LEFT JOIN `' . $wpdb->posts . '` p ON p.ID = pp.wc_product_id WHERE p.ID IS NULL'
			);
			$this->assert_same( 0, $leftovers, "site {$site_id}: no orphaned Play Product rows remain" );

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::game_types_table() . ' WHERE name LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ),
				"site {$site_id}: no test Game Types remain"
			);

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND trigger_name LIKE %s', DB_NAME, 'bc_pp_%' ) ),
				"site {$site_id}: no test triggers remain"
			);

			$packages = Migrations::packages_table();
			$this->assert_true(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $packages ) ) ) === $packages,
				"site {$site_id}: the authoritative Catalog v3 Package table is present"
			);
			$this->assert_true( true === Migrations::verify_packages_schema(), "site {$site_id}: the Package table verifies against the v3 contract" );
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, 'bc\_%\_bingo\_%\_ppi1\_stash' ) ),
				"site {$site_id}: no renamed/stashed Catalog table pattern exists anywhere"
			);

			$this->assert_same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION, 0 ), "site {$site_id}: Catalog schema is at the current version" );
			$this->assert_true( true === Migrations::verify_current_site_schema(), "site {$site_id}: final Catalog schema verifies" );

			restore_current_blog();
		}

		$this->assert_same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND trigger_name LIKE %s', DB_NAME, 'bc_pp_%' ) ),
			'no BingoCommerce Play Product test triggers remain anywhere'
		);

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
			foreach ( $tables as $table ) {
				$this->assert_same(
					0,
					(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) ),
					"disposable hall {$site_id}: {$table} was removed"
				);
			}
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND event_object_table LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_' ) . '%' ) ),
				"disposable hall {$site_id}: no trigger remains attached to any of its tables"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_bingo_' ) . '%' ) ),
				"disposable hall {$site_id}: no hall-local Catalog table of any name survives"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) ),
				"disposable hall {$site_id}: no audit rows remain"
			);
		}

		$this->assert_same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->users}` WHERE user_login LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ),
			'no temporary users were created by this suite'
		);

		$this->assert_same(
			0,
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE object_type = \'USER LEVEL LOCK\'' ),
			'no advisory locks remain'
		);

		// Isolation sanity: neither hall can see the other's Play Product rows.
		switch_to_blog( self::SUNRISE );
		$sunrise_table = Migrations::play_products_table();
		restore_current_blog();
		switch_to_blog( self::TAMPA );
		$tampa_table = Migrations::play_products_table();
		restore_current_blog();
		$this->assert_true( $sunrise_table !== $tampa_table, 'Sunrise and Tampa use separate per-site Play Product tables' );
	}

	/* ==================================================================== *
	 * Assertions
	 * ==================================================================== */

	private function group( string $name ): void {
		echo "\n== {$name} ==\n";
	}

	private function note( string $message ): void {
		$this->notes[] = $message;
	}

	private function assert_true( bool $condition, string $message ): void {
		++$this->assertions;
		if ( $condition ) {
			echo "  ok  {$message}\n";

			return;
		}
		++$this->failures;
		echo "  FAIL {$message}\n";
	}

	private function assert_false( bool $condition, string $message ): void {
		$this->assert_true( ! $condition, $message );
	}

	private function assert_null( $value, string $message ): void {
		$this->assert_true( null === $value, $message );
	}

	private function assert_same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected === $actual ) {
			echo "  ok  {$message}\n";

			return;
		}
		++$this->failures;
		echo "  FAIL {$message}\n";
		echo '       expected: ' . var_export( $expected, true ) . "\n";
		echo '       actual:   ' . var_export( $actual, true ) . "\n";
	}

	/** @param mixed $result */
	private function assert_error( string $expected_code, $result, string $message ): void {
		++$this->assertions;
		$actual = is_wp_error( $result )
			? $result->get_error_code()
			: ( $result instanceof PlayProductResult ? 'RESULT:' . $result->status : gettype( $result ) );

		if ( $expected_code === $actual ) {
			echo "  ok  {$message}\n";

			return;
		}
		++$this->failures;
		echo "  FAIL {$message}\n";
		echo "       expected error: {$expected_code}\n";
		echo "       actual:         {$actual}\n";
	}

	/** @param mixed $result */
	private function assert_status( string $expected_status, $result, string $message ): void {
		++$this->assertions;
		$actual = $result instanceof PlayProductResult
			? $result->status
			: ( is_wp_error( $result ) ? 'ERROR:' . $result->get_error_code() : gettype( $result ) );

		if ( $expected_status === $actual ) {
			echo "  ok  {$message}\n";

			return;
		}
		++$this->failures;
		echo "  FAIL {$message}\n";
		echo "       expected status: {$expected_status}\n";
		echo "       actual:          {$actual}\n";
	}
}

( new PlayProductIncrementOneTest() )->run();
