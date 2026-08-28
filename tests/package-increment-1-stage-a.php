<?php

/**
 * CLI integration coverage for Package Increment 1 — Stage A
 * (Catalog v3 + transport foundation).
 *
 * Exercises the real database: the Catalog v2 -> v3 migration, v3 schema
 * verification, the retired Play Product absent-Package-table rollout bridge,
 * ADR-034 medium reference integrity in both directions, the shared ADR-032 /
 * ADR-033 transport guards, and genuine multi-process concurrency.
 *
 * Harness safety rules (inherited from the Play Product suite):
 *  - no unqualified DELETE/TRUNCATE/DROP against persistent hall data;
 *  - ownership is exact tracked IDs plus a durable post-meta marker, never a
 *    name or title;
 *  - every bc_audit_log delete is scoped by blog_id;
 *  - destructive migration runs only on a purpose-created disposable hall;
 *  - a Package stub table is dropped only when THIS run created it;
 *  - Sunrise/Tampa/Orlando schemas are asserted intact.
 *
 * Run from the repository root:
 * php tests/package-increment-1-stage-a.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
if ( ! class_exists( '\BingoCommerce\Catalog\BingoPlayProductService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CatalogPackageCompositionPlan;
use BingoCommerce\Catalog\CatalogPlayProductPlan;
use BingoCommerce\Catalog\CatalogTransactionTransport;
use BingoCommerce\Catalog\CatalogWriteOutcome;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PlayProductResult;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;

/** A real connection whose COMMIT commits but reports failure. */
final class BcStageACommittedUnknownConnection extends mysqli {
	public function commit( int $flags = 0, ?string $name = null ): bool {
		parent::commit( $flags, $name );
		return false;
	}
}

/** A real connection whose ROLLBACK never succeeds, counting begun transactions. */
final class BcStageARollbackFailureConnection extends mysqli {
	public int $begin_count = 0;
	public function begin_transaction( int $flags = 0, ?string $name = null ): bool {
		++$this->begin_count;
		return parent::begin_transaction( $flags, $name );
	}
	public function rollback( int $flags = 0, ?string $name = null ): bool {
		return false;
	}
}

final class PackageStageATest {

	private const SUNRISE = 2;
	private const TAMPA   = 3;
	private const ORLANDO = 4;
	private const PREFIX  = 'PKGA';
	private const OWNER_META = '_bc_pkga_test_artifact';

	/** Every trigger name this suite may create. All of them live on a disposable hall only. */
	private const SUITE_TRIGGERS = [ 'bc_pkga_block_component' ];

	/** The fingerprint of an empty composition. */
	private const EMPTY_FINGERPRINT = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	private int $super_admin_id = 0;
	private string $run_token = '';
	private string $started_at = '';
	private int $disposable_site_id = 0;

	/** @var array<int,array<int,int>> */
	private array $created_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_game_types = [];
	/** @var array<int,array<int,int>> */
	private array $created_play_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_packages = [];
	/** @var array<int,string> disposable site id => the Catalog table names it owned */
	private array $disposable_tables = [];

	/**
	 * Disposable halls created by this run, by id. The ONLY place this suite may
	 * perform structural SQL; persistent halls take ordinary domain DML only.
	 *
	 * @var array<int,bool>
	 */
	private array $disposable_sites = [];
	/** @var array<int,array<int,int>> */
	private array $baseline_auto_drafts = [];

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
    private array $groups = [];
	private array $notes = [];

	public function run(): void {
		$this->run_token      = 'pkga-' . wp_generate_password( 12, false );
		$this->started_at     = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );
		$this->record_baseline_auto_drafts();
		$roles_before = $this->role_snapshot();

		$disposable = $this->create_disposable_site();
		$this->ok( $disposable > 0, 'disposable hall created for every destructive schema test' );
		$this->ok(
			! in_array( $disposable, [ 1, self::SUNRISE, self::TAMPA, self::ORLANDO ], true ),
			'  and it is not a persistent hall'
		);

		try {
			$this->migration_group( $disposable );
			$this->rollout_bridge_group( $disposable );
			$this->composition_group( $disposable );
			$this->composition_concurrency_group( $disposable );
			$this->medium_transition_group();
			$this->concurrency_group();
			$this->transport_guard_group();
		} finally {
			$this->cleanup();
		}

		$this->group( 'Setup' );
		$this->same( $roles_before, $this->role_snapshot(), 'role/capability records unchanged by the suite' );

		$this->report();
	}

	/* ================================================================== *
	 * GROUP: Migration
	 * ================================================================== */

	private function migration_group( int $disposable ): void {
		global $wpdb;
		$this->group( 'Migration' );

		$this->same( 3, Migrations::DB_VERSION, 'Catalog DB_VERSION is 3' );

		// Persistent halls run ONLY the production self-heal path, which is the
		// same idempotent dbDelta + verify that admin_init runs. Nothing here
		// renames, drops, alters or stubs a persistent hall's authoritative
		// tables; every destructive schema case below runs on the disposable hall.
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->ok( true === Migrations::migrate_current_site(), "persistent hall {$site_id} migrates to v3" );
			$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), "hall {$site_id} records version 3" );
			$this->ok( true === Migrations::verify_current_site_schema(), "hall {$site_id} full v3 schema verifies" );
			$this->ok( true === Migrations::migrate_current_site(), "hall {$site_id} v3 rerun is idempotent" );
			restore_current_blog();
		}

		// Sunrise/Tampa table independence.
		switch_to_blog( self::SUNRISE );
		$sunrise = [ Migrations::packages_table(), Migrations::package_components_table() ];
		restore_current_blog();
		switch_to_blog( self::TAMPA );
		$tampa = [ Migrations::packages_table(), Migrations::package_components_table() ];
		restore_current_blog();
		$this->ok( $sunrise[0] !== $tampa[0] && $sunrise[1] !== $tampa[1], 'Sunrise and Tampa use independent per-site Package tables' );
		$this->ok( false === strpos( $sunrise[0], 'blog_id' ), 'Package table name carries no blog_id' );

		// Everything destructive happens on the disposable hall only.
		if ( $disposable < 1 ) {
			return;
		}

		switch_to_blog( $disposable );
		$packages   = Migrations::packages_table();
		$components = Migrations::package_components_table();

		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'new hall provisions straight to v3' );
		$this->ok( true === Migrations::verify_current_site_schema(), 'new hall v3 schema verifies' );

		// Genuine v2 -> v3.
		$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$components}`" );
		$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$packages}`" );
		update_option( Migrations::VERSION_OPTION, 2, false );
		$this->is_error( 'catalog_migration_verification_failed', Migrations::verify_current_site_schema(), 'a v2 hall fails v3 verification' );
		$this->ok( true === Migrations::migrate_current_site(), 'v2 -> v3 migration succeeds' );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), 'v2 -> v3 records version 3' );

		// Exact index-shape verification.
		$create = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$packages}`", ARRAY_N )[1] ?? '' );
		$this->ok( false !== stripos( $create, 'ENGINE=InnoDB' ), 'bingo_packages is InnoDB' );
		$this->ok( false === stripos( $create, 'FOREIGN KEY' ), 'bingo_packages has no physical foreign key' );
		$this->ok( false === stripos( $create, 'blog_id' ), 'bingo_packages has no blog_id column' );
		$create_c = (string) ( $wpdb->get_row( "SHOW CREATE TABLE `{$components}`", ARRAY_N )[1] ?? '' );
		$this->ok( false !== stripos( $create_c, 'ENGINE=InnoDB' ), 'bingo_package_components is InnoDB' );
		$this->ok( false === stripos( $create_c, 'FOREIGN KEY' ), 'bingo_package_components has no physical foreign key' );
		$this->ok( false === stripos( $create_c, 'blog_id' ), 'bingo_package_components has no blog_id column' );

		// Malformed shapes must fail verification, and must NOT advance the version.
		$shapes = [
			'missing wc_product_unique index' =>
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, package_type varchar(16) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (package_id), KEY status_package_type (status,package_type)",
			'COMPOSITE UNIQUE wc_product_id' =>
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, package_type varchar(16) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_unique (wc_product_id,status), KEY status_package_type (status,package_type)",
			'non-unique wc_product_id index' =>
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, package_type varchar(16) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (package_id), KEY wc_product_unique (wc_product_id), KEY status_package_type (status,package_type)",
			'missing package_type column' =>
				"package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, wc_product_id bigint(20) unsigned NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL, archived_at datetime DEFAULT NULL, PRIMARY KEY (package_id), UNIQUE KEY wc_product_unique (wc_product_id), KEY status_package_type (status,package_type)",
		];
		foreach ( $shapes as $label => $ddl ) {
			$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$packages}`" );
			$this->disposable_ddl( $disposable, "CREATE TABLE `{$packages}` ( {$ddl} ) ENGINE=InnoDB" );
			delete_option( Migrations::VERSION_OPTION );
			$this->is_error( 'catalog_migration_verification_failed', Migrations::verify_packages_schema(), "bingo_packages with {$label} fails verification" );
			$this->same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), "  version not advanced ({$label})" );
		}

		$component_shapes = [
			'missing play_product index' =>
				'package_component_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, package_id bigint(20) unsigned NOT NULL, bingo_play_product_id bigint(20) unsigned NOT NULL, quantity int(10) unsigned NOT NULL, sort_order int(10) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (package_component_id), UNIQUE KEY package_play_product_unique (package_id,bingo_play_product_id), KEY package_sort (package_id,sort_order)',
			'non-unique package/play-product key' =>
				'package_component_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, package_id bigint(20) unsigned NOT NULL, bingo_play_product_id bigint(20) unsigned NOT NULL, quantity int(10) unsigned NOT NULL, sort_order int(10) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (package_component_id), KEY package_play_product_unique (package_id,bingo_play_product_id), KEY package_sort (package_id,sort_order), KEY play_product (bingo_play_product_id)',
			'missing quantity column' =>
				'package_component_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, package_id bigint(20) unsigned NOT NULL, bingo_play_product_id bigint(20) unsigned NOT NULL, sort_order int(10) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (package_component_id), UNIQUE KEY package_play_product_unique (package_id,bingo_play_product_id), KEY package_sort (package_id,sort_order), KEY play_product (bingo_play_product_id)',
		];
		foreach ( $component_shapes as $label => $ddl ) {
			$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$components}`" );
			$this->disposable_ddl( $disposable, "CREATE TABLE `{$components}` ( {$ddl} ) ENGINE=InnoDB" );
			delete_option( Migrations::VERSION_OPTION );
			$this->is_error( 'catalog_migration_verification_failed', Migrations::verify_package_components_schema(), "bingo_package_components with {$label} fails verification" );
			$this->same( 0, (int) get_option( Migrations::VERSION_OPTION, 0 ), "  version not advanced ({$label})" );
		}

		// A full migrate() repairs everything and only then persists v3.
		$this->ok( true === Migrations::migrate_current_site(), 'migrate repairs both malformed tables' );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), 'version 3 persisted only after every schema verified' );

		// Existing-hall self-heal from a stale version.
		update_option( Migrations::VERSION_OPTION, 2, false );
		$this->ok( true === Migrations::maybe_migrate_current_site(), 'stale hall self-heals through the existing admin_init path' );
		$this->same( 3, (int) get_option( Migrations::VERSION_OPTION ), 'self-heal reaches v3' );
		restore_current_blog();

		// Exactly one migration trigger per lifecycle hook: no second registration.
		$this->same( 1, $this->count_admin_init_migration_callbacks(), 'exactly one admin_init migration callback is registered' );
		$this->ok( false !== has_action( 'network_admin_menu', [ Migrations::class, 'maybe_migrate_network' ] ), 'network_admin_menu sweep still registered' );
		$this->ok( false !== has_action( 'wp_initialize_site', [ Migrations::class, 'provision_new_site' ] ), 'wp_initialize_site provisioning still registered' );
		$this->ok( false === has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ] ), 'network sweep is NOT on hall admin_init' );

		// Persistent halls untouched by the destructive group.
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->ok( true === Migrations::verify_current_site_schema(), "persistent hall {$site_id} schema intact after destructive migration group" );
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * GROUP: Rollout bridge
	 * ================================================================== */

	private function rollout_bridge_group( int $disposable ): void {
		global $wpdb;
		$this->group( 'Rollout bridge' );

		if ( $disposable < 1 ) {
			return;
		}

		// EVERY case in this group needs the Package table absent, malformed, or
		// otherwise wrong, so the whole group runs on the disposable hall. A
		// persistent hall's authoritative bingo_packages table is never renamed
		// aside, dropped, or replaced with a stub — a crash mid-group would
		// otherwise leave a real hall without it.
		switch_to_blog( $disposable );
		wp_set_current_user( $this->super_admin_id );
		$service  = new BingoPlayProductService();
		$packages = Migrations::packages_table();

		$this->ok( true === Migrations::verify_packages_schema(), 'the disposable hall starts from a verified v3 Package table' );

		// v3 + valid table: the authoritative Package claim IS consulted.
		$claimed_product = $this->make_product( $disposable, 'claimed' );
		$now             = current_time( 'mysql', true );
		$wpdb->insert( $packages, [ 'wc_product_id' => $claimed_product, 'package_type' => 'paper', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ], [ '%d', '%s', '%s', '%s', '%s' ] );
		$this->is_error( 'package_claim_exists', $service->create( $claimed_product, 'paper', 1 ), 'v3 + valid Package table: an existing Package claim rejects Play Product creation' );
		$this->is_error( 'play_product_not_found', $service->getByWooProductId( $claimed_product ), '  and no dual-claim Play Product row was written' );

		// v3 + absent table: FAIL CLOSED (the retired bridge must not skip the check).
		$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$packages}`" );
		$absent_product = $this->make_product( $disposable, 'absent' );
		$this->is_error( 'catalog_schema_inconsistent', $service->create( $absent_product, 'paper', 1 ), 'v3 + ABSENT Package table fails closed' );
		$this->same( 0, $this->play_product_rows_for( $absent_product ), '  and writes nothing' );

		// v3 + malformed table: FAIL CLOSED.
		$this->disposable_ddl( $disposable, "CREATE TABLE `{$packages}` ( package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, some_other varchar(16) NOT NULL, PRIMARY KEY (package_id) ) ENGINE=InnoDB" );
		$malformed_product = $this->make_product( $disposable, 'malformed' );
		$this->is_error( 'catalog_schema_inconsistent', $service->create( $malformed_product, 'paper', 1 ), 'v3 + MALFORMED Package table fails closed' );
		$this->same( 0, $this->play_product_rows_for( $malformed_product ), '  and writes nothing' );

		// Pre-v3 compatibility path is unreachable, because authorize() gates on v3.
		update_option( Migrations::VERSION_OPTION, 2, false );
		$pre_v3 = $service->create( $this->make_product( $disposable, 'prev3' ), 'paper', 1 );
		$this->is_error( 'catalog_schema_unavailable', $pre_v3, 'a pre-v3 hall refuses every Play Product mutation before the bridge is consulted' );

		// The ordinary migration repairs the malformed table and restores v3.
		$this->disposable_ddl( $disposable, "DROP TABLE IF EXISTS `{$packages}`" );
		$this->ok( true === Migrations::migrate_current_site(), 'migrate_current_site repairs the Package table' );
		$this->ok( true === Migrations::verify_packages_schema(), '  and the repaired table verifies' );

		$resumed = $service->create( $this->make_product( $disposable, 'ok' ), 'paper', 1 );
		$this->is_status( 'play_product_created', $resumed, 'creation resumes once v3 and a valid Package table are present' );
		if ( $resumed instanceof PlayProductResult ) {
			$this->created_play_products[ $disposable ][] = (int) $resumed->play_product['bingo_play_product_id'];
		}

		restore_current_blog();

		// The persistent halls were never involved in any of the above.
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->ok( true === Migrations::verify_packages_schema(), "persistent hall {$site_id} Package table untouched by the rollout-bridge group" );
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * GROUP: ADR-034 medium transitions
	 * ================================================================== */

	private function medium_transition_group(): void {
		global $wpdb;
		$this->group( 'ADR-034 medium transitions' );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$service = new BingoPlayProductService();
		$game_type = $this->make_game_type( self::SUNRISE, 'Regular' );

		// Unreferenced: both directions succeed.
		$free = $this->make_play_product( $service, 'free', 'digital', 3, $game_type );
		$this->is_status( 'play_product_medium_changed', $service->updateMedium( $free, 'paper' ), 'unreferenced Digital -> Paper succeeds' );
		$this->same( $game_type, $service->getById( $free )['game_type_id'], '  and preserves game_type_id (BR-039)' );
		$this->is_status( 'play_product_medium_changed', $service->updateMedium( $free, 'digital', $game_type ), 'unreferenced Paper -> Digital succeeds' );

		// Referenced by an ACTIVE Package.
		$active_pkg = $this->make_package( self::SUNRISE, 'active' );
		$referenced = $this->make_play_product( $service, 'ref', 'digital', 3, $game_type );
		$this->add_component( self::SUNRISE, $active_pkg, $referenced );

		$audit_before = $this->audit_count( self::SUNRISE, $referenced );
		$rejected     = $service->updateMedium( $referenced, 'paper' );
		$this->is_error( 'play_product_package_referenced', $rejected, 'referenced Digital -> Paper is rejected' );
		$this->ok( is_wp_error( $rejected ) && in_array( $active_pkg, (array) ( $rejected->get_error_data()['package_ids'] ?? [] ), true ), '  and the rejection names the referencing Package (BR-069)' );
		$this->same( 'digital', $service->getById( $referenced )['medium'], '  no mutation occurred' );
		$this->same( $audit_before, $this->audit_count( self::SUNRISE, $referenced ), '  and no audit event was emitted' );

		// Referenced true no-op is still a no-op, and audits nothing.
		$this->is_status( 'play_product_medium_unchanged', $service->updateMedium( $referenced, 'digital', $game_type ), 'referenced true no-op still no-ops' );
		$this->same( $audit_before, $this->audit_count( self::SUNRISE, $referenced ), '  and emits no audit event' );

		// Permitted mutations on a referenced Play Product (BR-069 allow-list).
		$other_type = $this->make_game_type( self::SUNRISE, 'WTA' );
		$this->is_status( 'play_product_game_type_assigned', $service->assignGameType( $referenced, $other_type ), 'referenced Game Type reassignment is permitted' );
		$this->is_status( 'play_product_faces_updated', $service->updateFacesPerUnit( $referenced, 7 ), 'referenced faces_per_unit change is permitted' );
		$this->is_status( 'play_product_archived', $service->archive( $referenced ), 'referenced archive is permitted' );
		$this->is_status( 'play_product_restored', $service->restore( $referenced ), 'referenced restore is permitted' );

		// Paper -> Digital while referenced.
		$paper_ref = $this->make_play_product( $service, 'paperref', 'paper', 2 );
		$this->add_component( self::SUNRISE, $active_pkg, $paper_ref );
		$this->is_error( 'play_product_package_referenced', $service->updateMedium( $paper_ref, 'digital', $game_type ), 'referenced Paper -> Digital is rejected' );
		$this->same( 'paper', $service->getById( $paper_ref )['medium'], '  no mutation occurred' );

		// An ARCHIVED Package reference blocks too (BR-067 preserves composition).
		$archived_pkg = $this->make_package( self::SUNRISE, 'archived', 'archived' );
		$arch_ref     = $this->make_play_product( $service, 'archref', 'digital', 3, $game_type );
		$this->add_component( self::SUNRISE, $archived_pkg, $arch_ref );
		$this->is_error( 'play_product_package_referenced', $service->updateMedium( $arch_ref, 'paper' ), 'an ARCHIVED Package reference also blocks the medium change' );

		// Multiple references.
		$second_pkg = $this->make_package( self::SUNRISE, 'second' );
		$this->add_component( self::SUNRISE, $second_pkg, $arch_ref );
		$multi = $service->updateMedium( $arch_ref, 'paper' );
		$this->is_error( 'play_product_package_referenced', $multi, 'multiple Package references block the medium change' );
		$this->same( 2, count( (array) ( $multi->get_error_data()['package_ids'] ?? [] ) ), '  and all referencing Packages are reported' );

		// A successful actual transition DOES audit, after commit.
		$before_audit = $this->audit_count( self::SUNRISE, $free );
		$this->is_status( 'play_product_medium_changed', $service->updateMedium( $free, 'paper' ), 'an actual transition succeeds when unreferenced' );
		$this->same( $before_audit + 1, $this->audit_count( self::SUNRISE, $free ), '  and emits exactly one audit event after commit' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * GROUP: ADR-033 composition
	 * ================================================================== */

	/**
	 * Runs entirely on the disposable hall: it needs deliberately corrupt
	 * authoritative rows and a SIGNAL trigger to force mid-DML failure, neither
	 * of which may ever be applied to a persistent hall.
	 */
	private function composition_group( int $disposable ): void {
		global $wpdb;
		$this->group( 'ADR-033 composition' );

		if ( $disposable < 1 ) {
			return;
		}

		switch_to_blog( $disposable );
		wp_set_current_user( $this->super_admin_id );
		$service    = new BingoPlayProductService();
		$game_type  = $this->make_game_type( $disposable, 'Comp' );
		$components = Migrations::package_components_table();
		$packages   = Migrations::packages_table();

		$paper_a   = $this->make_play_product( $service, 'compPaperA', 'paper', 2 );
		$paper_b   = $this->make_play_product( $service, 'compPaperB', 'paper', 3 );
		$digital_a = $this->make_play_product( $service, 'compDigA', 'digital', 4, $game_type );
		$digital_b = $this->make_play_product( $service, 'compDigB', 'digital', 5, $game_type );

		/* -------------------- lifecycle rejections -------------------- */

		$missing = $this->replace( $disposable, 987654321, self::EMPTY_FINGERPRINT, [ [ $paper_a, 1 ] ] );
		$this->is_error( 'package_not_found', $missing, 'an unknown Package id is rejected' );

		$archived_pkg = $this->make_package( $disposable, 'archived', 'archived' );
		$this->is_error( 'package_archived', $this->replace( $disposable, $archived_pkg, self::EMPTY_FINGERPRINT, [ [ $paper_a, 1 ] ] ), 'an archived Package rejects composition edits (BR-068)' );
		$this->same( 0, $this->component_count( $archived_pkg ), '  and nothing was written' );

		$bad_status = $this->make_package( $disposable, 'badstatus', 'active' );
		$wpdb->update( $packages, [ 'status' => 'weird' ], [ 'package_id' => $bad_status ], [ '%s' ], [ '%d' ] );
		$this->is_error( 'catalog_current_state_changed', $this->replace( $disposable, $bad_status, self::EMPTY_FINGERPRINT, [ [ $paper_a, 1 ] ] ), 'an unrecognised Package status fails closed' );

		/* -------------------- plan shape rejections -------------------- */

		$pkg = $this->make_package( $disposable, 'main' );

		$this->is_error( 'duplicate_component', $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ $paper_a, 1 ], [ $paper_a, 2 ] ] ), 'a duplicated Bingo Play Product is rejected (BR-067)' );
		$this->is_error( 'package_requires_component', $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [] ), 'an empty composition is rejected (BR-067)' );
		$this->is_error( 'play_product_not_found', $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 987654321, 1 ] ] ), 'an unknown Bingo Play Product is rejected' );
		$this->same( 0, $this->component_count( $pkg ), 'no rejected plan wrote a component row' );

		/* -------------------- malformed plan shape (rejected before BEGIN) -------------------- */

		$now = current_time( 'mysql', true );
		$malformed = [
			'blog_id 0'                     => [ 0, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ], $now ],
			'package_id 0'                  => [ $disposable, 0, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ], $now ],
			'malformed timestamp'           => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ], 'not-a-datetime' ],
			'components not a list'         => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ 7 => [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ], $now ],
			'component not an array'        => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ 'nonsense' ], $now ],
			'missing bingo_play_product_id' => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'quantity' => 1 ] ], $now ],
			'missing quantity'              => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a ] ], $now ],
			'string play product id'        => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => (string) $paper_a, 'quantity' => 1 ] ], $now ],
			'string quantity'               => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => '1' ] ], $now ],
			'float quantity'                => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1.5 ] ], $now ],
			'null play product id'          => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => null, 'quantity' => 1 ] ], $now ],
			'array quantity'                => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => [ 1 ] ] ], $now ],
			'zero play product id'          => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => 0, 'quantity' => 1 ] ], $now ],
			'negative play product id'      => [ $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => -5, 'quantity' => 1 ] ], $now ],
		];

		$notices = 0;
		$previous = set_error_handler(
			static function ( $errno, $errstr ) use ( &$notices ) {
				if ( false !== strpos( (string) $errstr, 'Undefined array key' ) || false !== strpos( (string) $errstr, 'Array to string' ) ) {
					++$notices;
				}

				return true;
			},
			E_ALL
		);
		foreach ( $malformed as $label => $args ) {
			$outcome = CatalogTransactionTransport::replacePackageComposition(
				CatalogPackageCompositionPlan::forReplaceComposition( $args[0], $args[1], $args[2], $args[3], $args[4] )
			);
			$this->is_error( 'catalog_transport_plan_mismatch', $outcome, "malformed plan rejected before BEGIN: {$label}" );
		}
		set_error_handler( $previous );

		$this->same( 0, $notices, 'no malformed plan raised a PHP warning or notice' );
		$this->same( 0, $this->component_count( $pkg ), 'no malformed plan wrote a component row' );
		$this->same( false, $this->transport_untrusted(), 'a pre-BEGIN plan rejection never poisons the session' );

		/* -------------------- quantity storage bound -------------------- */

		foreach ( [ 0, -1, -4294967295, 4294967296, 9999999999 ] as $bad ) {
			$this->is_error( 'invalid_component_quantity', $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ $paper_a, $bad ] ] ), "quantity {$bad} is rejected before any DML" );
		}
		$this->same( 0, $this->component_count( $pkg ), 'no out-of-range quantity reached MySQL' );

		$this->is_ok( $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ $paper_a, 1 ] ] ), 'quantity 1 is accepted' );
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 4294967295 ] ] ), 'quantity 4294967295 is accepted' );
		$this->same( 4294967295, (int) $wpdb->get_var( $wpdb->prepare( "SELECT quantity FROM {$components} WHERE package_id = %d AND bingo_play_product_id = %d", $pkg, $paper_a ) ), '  and is stored exactly' );
		$this->is_error( 'invalid_component_quantity', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 4294967296 ] ] ), 'quantity 4294967296 is rejected' );
		$this->same( 4294967295, (int) $wpdb->get_var( $wpdb->prepare( "SELECT quantity FROM {$components} WHERE package_id = %d AND bingo_play_product_id = %d", $pkg, $paper_a ) ), '  and left the stored value untouched' );

		/* -------------------- stale fingerprint -------------------- */

		$stale = $this->fingerprint( $pkg );
		$this->is_ok( $this->replace( $disposable, $pkg, $stale, [ [ $paper_a, 2 ] ] ), 'a matching fingerprint is accepted' );
		$this->is_error( 'package_composition_stale', $this->replace( $disposable, $pkg, $stale, [ [ $paper_a, 3 ] ] ), 'a now-stale fingerprint is rejected' );
		$this->same( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT quantity FROM {$components} WHERE package_id = %d AND bingo_play_product_id = %d", $pkg, $paper_a ) ), '  and the stale edit wrote nothing' );

		// The fingerprint ignores sort_order and row identity, and depends only on
		// the ascending id:quantity set.
		$this->same(
			CatalogTransactionTransport::compositionFingerprint( [ [ 'bingo_play_product_id' => 9, 'quantity' => 1 ], [ 'bingo_play_product_id' => 4, 'quantity' => 2 ] ] ),
			CatalogTransactionTransport::compositionFingerprint( [ [ 'bingo_play_product_id' => 4, 'quantity' => 2 ], [ 'bingo_play_product_id' => 9, 'quantity' => 1 ] ] ),
			'the fingerprint is order-independent'
		);
		$this->ok(
			CatalogTransactionTransport::compositionFingerprint( [ [ 'bingo_play_product_id' => 4, 'quantity' => 2 ] ] )
			!== CatalogTransactionTransport::compositionFingerprint( [ [ 'bingo_play_product_id' => 4, 'quantity' => 3 ] ] ),
			'the fingerprint changes with quantity'
		);

		/* -------------------- BR-061 derivation -------------------- */

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $paper_b, 2 ] ] ), 'a Paper-only composition is accepted' );
		$this->same( 'paper', $this->package_type( $pkg ), 'BR-061: Paper only derives paper' );

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $digital_a, 1 ], [ $digital_b, 2 ] ] ), 'a Digital-only composition is accepted' );
		$this->same( 'digital', $this->package_type( $pkg ), 'BR-061: Digital only derives digital' );

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $digital_a, 1 ] ] ), 'a mixed composition is accepted' );
		$this->same( 'hybrid', $this->package_type( $pkg ), 'BR-061: mixed derives hybrid' );

		// A removed component is LOCKED but contributes nothing to the type.
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ] ] ), 'removing the Digital component is accepted' );
		$this->same( 'paper', $this->package_type( $pkg ), 'BR-061: a removed Digital component does not contribute to the derived type' );
		$this->same( 1, $this->component_count( $pkg ), '  and the removed row is gone' );

		/* -------------------- exact no-op semantics -------------------- */

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $digital_a, 3 ] ] ), 'a two-component composition is written' );
		$wpdb->update( $components, [ 'sort_order' => 7 ], [ 'package_id' => $pkg, 'bingo_play_product_id' => $digital_a ], [ '%d' ], [ '%d', '%d' ] );
		$before = $this->component_rows( $pkg );
		$before_package = $wpdb->get_row( $wpdb->prepare( "SELECT package_type, updated_at FROM {$packages} WHERE package_id = %d", $pkg ), ARRAY_A );

		$noop = $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $digital_a, 3 ], [ $paper_a, 1 ] ] );
		$this->is_ok( $noop, 'an identical composition succeeds' );
		$this->ok( $noop instanceof CatalogWriteOutcome && false === $noop->mutated, '  and reports mutated = false' );
		$this->same( $before, $this->component_rows( $pkg ), '  performing NO component DML: ids, quantities, sort_order, created_at and updated_at all survive' );
		$this->same( $before_package, $wpdb->get_row( $wpdb->prepare( "SELECT package_type, updated_at FROM {$packages} WHERE package_id = %d", $pkg ), ARRAY_A ), '  and bingo_packages.updated_at is not rewritten' );

		// A quantity change touches only that row: sort_order and the sibling row
		// are preserved.
		$sibling_before = $this->component_row( $pkg, $paper_a );
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $digital_a, 4 ] ] ), 'a single quantity change is accepted' );
		$changed_row = $this->component_row( $pkg, $digital_a );
		$this->same( 4, (int) $changed_row['quantity'], '  the quantity changed' );
		$this->same( 7, (int) $changed_row['sort_order'], '  sort_order was NOT rewritten' );
		$this->same( $sibling_before, $this->component_row( $pkg, $paper_a ), '  and the untouched sibling row is byte-identical' );

		// A new component starts at sort_order 0 and the plan cannot say otherwise.
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $digital_a, 4 ], [ $paper_b, 1 ] ] ), 'adding a component is accepted' );
		$this->same( 0, (int) $this->component_row( $pkg, $paper_b )['sort_order'], '  a new component starts at sort_order 0' );
		$this->same( 7, (int) $this->component_row( $pkg, $digital_a )['sort_order'], '  and an existing sort_order still survives the add' );

		/* -------------------- archived Play Product rules -------------------- */

		$retained = $this->make_play_product( $service, 'compRetained', 'paper', 2 );
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 1 ], [ $retained, 5 ] ] ), 'a composition containing the soon-archived product is written' );
		$this->is_status( 'play_product_archived', $service->archive( $retained ), 'the referenced Bingo Play Product is archived (BR-067 preserves the reference)' );

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $retained, 5 ] ] ), 'an archived component is RETAINED at its existing quantity' );
		$this->same( 5, (int) $this->component_row( $pkg, $retained )['quantity'], '  unchanged' );

		$this->is_error( 'play_product_not_active', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $retained, 6 ] ] ), 'changing an archived component quantity is rejected' );
		$this->same( 5, (int) $this->component_row( $pkg, $retained )['quantity'], '  and nothing was written' );

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $retained, 5 ], [ $digital_b, 1 ] ] ), 'a NEW ACTIVE component may be added alongside a retained archived one' );
		$this->same( 5, (int) $this->component_row( $pkg, $retained )['quantity'], '  and the archived component is still held at its existing quantity' );
		$this->same( 'hybrid', $this->package_type( $pkg ), '  BR-061 still derives from the full requested set, archived rows included' );

		$newly_archived = $this->make_play_product( $service, 'compNewArch', 'paper', 2 );
		$this->is_status( 'play_product_archived', $service->archive( $newly_archived ), 'an unreferenced product is archived' );
		$this->is_error( 'play_product_not_active', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $retained, 5 ], [ $newly_archived, 1 ] ] ), 'an archived Bingo Play Product cannot be ADDED' );

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ] ] ), 'an archived component can be REMOVED' );
		$this->same( 0, $this->component_count_for( $pkg, $retained ), '  and its row is gone' );

		/* -------------------- corrupt authoritative state -------------------- */

		$corrupt = $this->make_play_product( $service, 'compCorrupt', 'paper', 2 );
		$play_products_table = Migrations::play_products_table();
		$type_before = $this->package_type( $pkg );

		$wpdb->query( $wpdb->prepare( "UPDATE {$play_products_table} SET medium = 'holographic' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_error( 'catalog_schema_inconsistent', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $corrupt, 1 ] ] ), 'an unrecognised medium fails closed instead of being treated as Paper' );
		$this->same( $type_before, $this->package_type( $pkg ), '  and package_type was not rewritten' );
		$this->same( 0, $this->component_count_for( $pkg, $corrupt ), '  and no component row was created' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$play_products_table} SET medium = 'paper', status = 'suspended' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_error( 'catalog_schema_inconsistent', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $corrupt, 1 ] ] ), 'an unrecognised status fails closed instead of being treated as archived' );
		$this->same( $type_before, $this->package_type( $pkg ), '  and package_type was not rewritten' );

		// Corruption on a row that is only being REMOVED is still caught, because
		// the whole locked dependency union is validated.
		$wpdb->query( $wpdb->prepare( "UPDATE {$play_products_table} SET medium = 'paper', status = 'active' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $corrupt, 1 ] ] ), 'the repaired product can join the composition' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$play_products_table} SET medium = 'holographic' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_error( 'catalog_schema_inconsistent', $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ] ] ), 'corruption on a component being REMOVED is also caught' );
		$this->same( 2, $this->component_count( $pkg ), '  and the removal did not happen' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$play_products_table} SET medium = 'paper' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ] ] ), 'the removal succeeds once the row is valid again' );

		/* -------------------- DML rollback integrity -------------------- */

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $paper_b, 1 ] ] ), 'a two-component baseline for the rollback test' );
		$rollback_before = $this->component_rows( $pkg );
		$package_before  = $wpdb->get_row( $wpdb->prepare( "SELECT package_type, updated_at FROM {$packages} WHERE package_id = %d", $pkg ), ARRAY_A );

		// A SIGNAL trigger makes the INSERT fail AFTER the DELETE has executed
		// inside the same transaction, which is the only way to observe that the
		// DELETE is rolled back with it. Disposable hall only.
		$this->disposable_ddl(
			$disposable,
			"CREATE TRIGGER bc_pkga_block_component BEFORE INSERT ON `{$components}` FOR EACH ROW"
			. " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pkga forced component insert failure'"
		);
		$blocked = $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $paper_a, 2 ], [ $digital_a, 1 ] ] );
		$this->disposable_ddl( $disposable, 'DROP TRIGGER IF EXISTS bc_pkga_block_component' );

		$this->ok( is_wp_error( $blocked ), 'a mid-DML failure returns an error, not a partial success' );
		$this->same( $rollback_before, $this->component_rows( $pkg ), '  and the DELETE that preceded it was rolled back completely' );
		$this->same( $package_before, $wpdb->get_row( $wpdb->prepare( "SELECT package_type, updated_at FROM {$packages} WHERE package_id = %d", $pkg ), ARRAY_A ), '  and bingo_packages was not written' );
		$this->same( false, $this->transport_untrusted(), '  and a clean ROLLBACK left the session trusted' );

		/* -------------------- transport failure modes on THIS boundary -------------------- */

		$saved = $wpdb->dbh;
		$seam  = new BcStageARollbackFailureConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$this->warm( $seam );
		$seam->begin_count = 0;
		$poisoned  = CatalogTransactionTransport::replacePackageComposition(
			CatalogPackageCompositionPlan::forReplaceComposition( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ], current_time( 'mysql', true ) )
		);
		$begins    = $seam->begin_count;
		$wpdb->dbh = $saved;
		$seam->close();
		$this->is_error( 'catalog_transaction_state_untrusted', $poisoned, 'a failed ROLLBACK on the ADR-033 boundary returns catalog_transaction_state_untrusted' );
		$this->same( 1, $begins, '  exactly ONE transaction was begun — no retry after a failed rollback' );
		$this->same( true, $this->transport_untrusted(), '  and the shared session flag is poisoned' );
		$this->set_flag( 'untrusted', false );

		$ambiguous_before = $this->component_rows( $pkg );
		$saved = $wpdb->dbh;
		$seam  = new BcStageACommittedUnknownConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$this->warm( $seam );
		$began     = microtime( true );
		$ambiguous = CatalogTransactionTransport::replacePackageComposition(
			CatalogPackageCompositionPlan::forReplaceComposition( $disposable, $pkg, CatalogTransactionTransport::compositionFingerprint( $ambiguous_before ), [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 9 ] ], current_time( 'mysql', true ) )
		);
		$elapsed   = microtime( true ) - $began;
		$wpdb->dbh = $saved;
		$seam->close();
		$this->is_error( 'catalog_commit_outcome_unknown', $ambiguous, 'an unclean COMMIT on the ADR-033 boundary is reported as unknown' );
		$this->ok( $elapsed < 1.0, sprintf( '  and is not retried (%.3fs)', $elapsed ) );
		$this->same( 9, (int) $this->component_row( $pkg, $paper_a )['quantity'], '  the transaction really committed' );
		$this->set_flag( 'untrusted', false );

		$this->same( [ 1205, 1213, 3572 ], $this->transport_constant( 'LOCK_CONFLICT_ERRNOS' ), 'the retryable errno set is exactly 1205, 1213 and 3572' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * GROUP: ADR-033 concurrency
	 * ================================================================== */

	private function composition_concurrency_group( int $disposable ): void {
		global $wpdb;
		$this->group( 'ADR-033 concurrency' );

		if ( $disposable < 1 ) {
			return;
		}

		switch_to_blog( $disposable );
		wp_set_current_user( $this->super_admin_id );
		$service   = new BingoPlayProductService();
		$game_type = $this->make_game_type( $disposable, 'CompConc' );

		$a = $this->make_play_product( $service, 'ccA', 'paper', 1 );
		$b = $this->make_play_product( $service, 'ccB', 'paper', 1 );
		$c = $this->make_play_product( $service, 'ccC', 'digital', 1, $game_type );
		$pkg = $this->make_package( $disposable, 'conc' );

		/* ---- the locked dependency set really is the UNION ---- */

		$this->is_ok( $this->replace( $disposable, $pkg, self::EMPTY_FINGERPRINT, [ [ $a, 1 ], [ $b, 1 ] ] ), 'baseline composition {A,B} written' );
		restore_current_blog();

		// B is only being REMOVED by the plan below. If the union were not locked,
		// this held lock would not be contended at all.
		$holder = $this->start_fixture( $disposable, $b, $pkg, 'none', 900 );
		switch_to_blog( $disposable );
		$began    = microtime( true );
		$removal  = $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $a, 1 ] ] );
		$elapsed  = microtime( true ) - $began;
		restore_current_blog();
		$this->finish_fixture( $holder );
		$this->is_error( 'catalog_write_conflict', $removal, 'a component being REMOVED is part of the locked dependency union' );
		$this->ok( $elapsed < 5.0, sprintf( '  bounded by NOWAIT + retry budget (%.3fs)', $elapsed ) );
		switch_to_blog( $disposable );
		$this->same( 2, $this->component_count( $pkg ), '  and the conflicted attempt wrote nothing' );
		restore_current_blog();

		// C is only being ADDED.
		$holder = $this->start_fixture( $disposable, $c, $pkg, 'none', 900 );
		switch_to_blog( $disposable );
		$addition = $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $a, 1 ], [ $b, 1 ], [ $c, 1 ] ] );
		restore_current_blog();
		$this->finish_fixture( $holder );
		$this->is_error( 'catalog_write_conflict', $addition, 'a component being ADDED is part of the locked dependency union' );
		switch_to_blog( $disposable );
		$this->same( 2, $this->component_count( $pkg ), '  and the conflicted attempt wrote nothing' );

		/* ---- ascending acquisition order (source-level guard) ---- */

		$source = $this->method_source( 'lock_play_product_rows' );
		$this->ok( false !== strpos( $source, 'ORDER BY bingo_play_product_id' ), 'the dependency lock statement orders by bingo_play_product_id (source guard)' );
		$sequence = $this->method_source( 'replace_composition_sequence' );
		$this->ok( false !== strpos( $sequence, 'sort( $dependency, SORT_NUMERIC )' ), 'the dependency union is sorted ascending before locking (source guard)' );
		$this->ok( false === strpos( $sequence, 'FOR UPDATE' ) || false === strpos( $this->method_source( 'read_package_components' ), 'FOR UPDATE' ), 'the current-composition read takes no lock (source guard)' );

		/* ---- the fingerprint is recomputed on EVERY retry ---- */

		$this->is_ok( $this->replace( $disposable, $pkg, $this->fingerprint( $pkg ), [ [ $a, 1 ] ] ), 'reset to {A}' );
		$stale = $this->fingerprint( $pkg );
		restore_current_blog();

		// The worker holds B's Play Product row AND inserts (pkg, B) before it
		// commits. Attempt 1 therefore conflicts on B; by the retry the committed
		// composition is {A,B}, so the plan's fingerprint is stale. A cached
		// first-attempt fingerprint would have matched and the write would have
		// gone through.
		$worker = $this->start_fixture( $disposable, $b, $pkg, 'insert', 25 );
		switch_to_blog( $disposable );
		$retried = $this->replace( $disposable, $pkg, $stale, [ [ $a, 1 ], [ $b, 2 ] ] );
		restore_current_blog();
		$this->finish_fixture( $worker );
		$code = $this->code_of( $retried );
		$this->ok( $retried instanceof WP_Error, "the retry never writes against a stale fingerprint ({$code})" );
		$this->ok( in_array( $code, [ 'package_composition_stale', 'catalog_write_conflict' ], true ), "  and reports a recognised serialization outcome ({$code})" );
		switch_to_blog( $disposable );
		$this->same( 1, (int) $this->component_row( $pkg, $b )['quantity'], '  the concurrent writer\'s component survived at ITS quantity, not the plan\'s' );
		$this->note( "ADR-033 retry re-read outcome: {$code}" );

		/* ---- two conforming composition writers ---- */

		$pkg_two = $this->make_package( $disposable, 'conc2' );
		$this->is_ok( $this->replace( $disposable, $pkg_two, self::EMPTY_FINGERPRINT, [ [ $a, 1 ] ] ), 'second Package seeded with the shared component A' );
		$fingerprint_one = $this->fingerprint( $pkg );
		$fingerprint_two = $this->fingerprint( $pkg_two );
		restore_current_blog();

		$start   = (int) ( ( microtime( true ) + 1.2 ) * 1000000 );
		$workers = [
			$this->start_composition_worker( $disposable, $pkg, $fingerprint_one, [ [ $a, 3 ], [ $c, 1 ] ], $start ),
			$this->start_composition_worker( $disposable, $pkg_two, $fingerprint_two, [ [ $c, 2 ], [ $a, 4 ] ], $start ),
		];
		$outcomes = [ $this->finish_composition_worker( $workers[0] ), $this->finish_composition_worker( $workers[1] ) ];

		foreach ( $outcomes as $index => $outcome ) {
			$this->ok(
				in_array( $outcome, [ 'ok mutated', 'ok unchanged', 'catalog_write_conflict -', 'package_composition_stale -' ], true ),
				"two conforming composition writers: writer {$index} ended in a recognised state ({$outcome})"
			);
		}

		switch_to_blog( $disposable );
		foreach ( [ $pkg, $pkg_two ] as $package_id ) {
			$rows  = $this->component_rows( $package_id );
			$ids   = [];
			foreach ( $rows as $row ) {
				$ids[] = (int) $row['bingo_play_product_id'];
			}
			$this->same( count( $ids ), count( array_unique( $ids ) ), "package {$package_id}: no duplicate component rows after the race" );
			$this->ok( [] !== $rows, "package {$package_id}: never left with zero components" );
			$expected = $this->derive_type( $ids, [ $a => 'paper', $b => 'paper', $c => 'digital' ] );
			$this->same( $expected, $this->package_type( $package_id ), "package {$package_id}: package_type matches its FINAL committed composition" );
		}
		$this->note( 'two conforming composition writers: ' . implode( ' | ', $outcomes ) );

		restore_current_blog();
	}

	/** @param array<int,int> $ids */
	private function derive_type( array $ids, array $mediums ): string {
		$paper   = false;
		$digital = false;
		foreach ( $ids as $id ) {
			if ( 'digital' === ( $mediums[ $id ] ?? 'paper' ) ) {
				$digital = true;
			} else {
				$paper = true;
			}
		}

		return $paper && $digital ? 'hybrid' : ( $digital ? 'digital' : 'paper' );
	}

	private function method_source( string $method ): string {
		$reflection = new ReflectionMethod( CatalogTransactionTransport::class, $method );
		$lines      = file( (string) $reflection->getFileName() );

		return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}

	/** @param array<int,array{0:int,1:int}> $pairs */
	private function start_composition_worker( int $site_id, int $package_id, string $fingerprint, array $pairs, int $start_at ): array {
		$components = [];
		foreach ( $pairs as $pair ) {
			$components[] = (int) $pair[0] . ':' . (int) $pair[1];
		}

		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/package-composition-worker.php', (string) $site_id, (string) $package_id, $fingerprint, [] === $components ? 'empty' : implode( ',', $components ), (string) $start_at ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$ready = fgets( $pipes[1] );
		$this->ok( is_string( $ready ) && 0 === strpos( $ready, 'READY' ), 'composition worker booted' );

		return [ $handle, $pipes ];
	}

	private function finish_composition_worker( array $worker ): string {
		[ $handle, $pipes ] = $worker;
		$out = (string) stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $handle );

		if ( 1 === preg_match( '/^OUTCOME (.+)$/m', $out, $matches ) ) {
			return trim( $matches[1] );
		}

		return 'NO OUTCOME';
	}

	/* ================================================================== *
	 * GROUP: Concurrency
	 * ================================================================== */

	private function concurrency_group(): void {
		$this->group( 'Concurrency' );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$service   = new BingoPlayProductService();
		$game_type = $this->make_game_type( self::SUNRISE, 'Conc' );
		$package   = $this->make_package( self::SUNRISE, 'conc' );

		// SCENARIO B — a Package reference appears while the medium is changing.
		//
		// B1 is the deterministic ordering: the reference writer COMMITS first,
		// so the medium writer must observe it and reject.
		$b1 = $this->make_play_product( $service, 'scenB1', 'digital', 3, $game_type );
		restore_current_blog();
		$this->finish_fixture( $this->start_fixture( self::SUNRISE, $b1, $package, 'insert', 0 ) );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$this->is_error( 'play_product_package_referenced', ( new BingoPlayProductService() )->updateMedium( $b1, 'paper' ), 'B1: a committed Package reference rejects the medium change' );
		$this->same( 'digital', ( new BingoPlayProductService() )->getById( $b1 )['medium'], '  B1: medium unchanged' );

		// B2 is the real race: the reference writer still holds the anchor. The
		// transport takes the anchor with FOR UPDATE NOWAIT and never waits, so
		// the correct outcome is a bounded rejection — never a success built on a
		// reference read the other writer had not finished.
		$b2 = $this->make_play_product( $service, 'scenB2', 'digital', 3, $game_type );
		restore_current_blog();
		$worker = $this->start_fixture( self::SUNRISE, $b2, $package, 'insert', 700 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$began   = microtime( true );
		$outcome = ( new BingoPlayProductService() )->updateMedium( $b2, 'paper' );
		$waited  = microtime( true ) - $began;
		$medium  = ( new BingoPlayProductService() )->getById( $b2 )['medium'];
		restore_current_blog();
		$this->finish_fixture( $worker );
		$code = $this->code_of( $outcome );
		$this->ok( in_array( $code, [ 'catalog_write_conflict', 'play_product_package_referenced' ], true ), "B2: a held anchor yields a bounded rejection, not a stale success ({$code})" );
		$this->same( 'digital', $medium, '  B2: medium unchanged' );
		$this->ok( $waited < 1.0, sprintf( '  B2: the medium writer never waited out the 700ms hold (%.3fs)', $waited ) );
		$this->note( sprintf( 'scenario B2: outcome=%s after %.3fs against a 700ms anchor hold', $code, $waited ) );

		// SCENARIO A — medium writer first; the reference writer then sees the new medium.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$a = $this->make_play_product( $service, 'scenA', 'digital', 3, $game_type );
		$this->is_status( 'play_product_medium_changed', ( new BingoPlayProductService() )->updateMedium( $a, 'paper' ), 'A: unreferenced medium change commits first' );
		restore_current_blog();
		$worker = $this->start_fixture( self::SUNRISE, $a, $package, 'insert', 0 );
		$result = $this->finish_fixture( $worker );
		$this->ok( false !== strpos( $result, 'READY paper' ), 'A: the later Package-reference writer observes the NEW medium under lock' );

		// SCENARIO C — the last reference is removed while the medium changes.
		//
		// C1: the removal COMMITS first, so the medium change is now permitted.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$c1 = $this->make_play_product( $service, 'scenC1', 'digital', 3, $game_type );
		$this->add_component( self::SUNRISE, $package, $c1 );
		restore_current_blog();
		$this->finish_fixture( $this->start_fixture( self::SUNRISE, $c1, $package, 'delete', 0 ) );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$this->same( 0, $this->component_rows_for( self::SUNRISE, $c1 ), 'C1: the last Package reference was removed and committed' );
		$this->is_status( 'play_product_medium_changed', ( new BingoPlayProductService() )->updateMedium( $c1, 'paper' ), 'C1: the medium change is permitted once the reference is gone' );

		// C2: the removal is UNCOMMITTED and still holds the anchor. The medium
		// writer must not act on a removal that could still roll back.
		$c2 = $this->make_play_product( $service, 'scenC2', 'digital', 3, $game_type );
		$this->add_component( self::SUNRISE, $package, $c2 );
		restore_current_blog();
		$worker  = $this->start_fixture( self::SUNRISE, $c2, $package, 'delete', 600 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$outcome = ( new BingoPlayProductService() )->updateMedium( $c2, 'paper' );
		$medium  = ( new BingoPlayProductService() )->getById( $c2 )['medium'];
		$refs    = $this->component_rows_for( self::SUNRISE, $c2 );
		restore_current_blog();
		$this->finish_fixture( $worker );
		$code = $this->code_of( $outcome );
		$this->ok( in_array( $code, [ 'catalog_write_conflict', 'play_product_package_referenced' ], true ), "C2: an uncommitted removal never unlocks the medium change ({$code})" );
		$this->same( 'digital', $medium, '  C2: medium unchanged' );
		$this->same( 1, $refs, '  C2: the uncommitted removal was invisible to the medium writer' );
		$this->note( "scenario C2: outcome={$code} against a 600ms uncommitted removal" );

		// SCENARIO D — genuine race, varied timing. The forbidden end state is a
		// Package reference standing over a medium that changed under it, which
		// would make a future BR-061 package_type derivation stale.
		$outcomes = [];
		for ( $i = 0; $i < 6; $i++ ) {
			switch_to_blog( self::SUNRISE );
			wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
			$d = $this->make_play_product( $service, "scenD{$i}", 'digital', 3, $game_type );
			restore_current_blog();
			$worker = $this->start_fixture( self::SUNRISE, $d, $package, 'insert', [ 0, 5, 40, 0, 120, 5 ][ $i ] );
			switch_to_blog( self::SUNRISE );
			wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
			$outcome = ( new BingoPlayProductService() )->updateMedium( $d, 'paper' );
			restore_current_blog();
			$this->finish_fixture( $worker );
			switch_to_blog( self::SUNRISE );
			$medium = ( new BingoPlayProductService() )->getById( $d )['medium'];
			$refs   = $this->component_rows_for( self::SUNRISE, $d );
			restore_current_blog();
			$code       = $this->code_of( $outcome );
			$outcomes[] = "{$code}/{$medium}/refs={$refs}";
			$this->ok(
				! ( $refs > 0 && 'paper' === $medium ),
				"D round {$i}: never ends with a Package reference over a changed medium ({$code}/{$medium}/refs={$refs})"
			);
			$this->ok(
				'play_product_medium_changed' !== $code || 0 === $refs,
				"D round {$i}: a reported medium change is never contradicted by a surviving reference"
			);
		}
		$this->note( 'scenario D outcomes: ' . implode( ' | ', $outcomes ) );
	}

	/* ================================================================== *
	 * GROUP: Transport guards
	 * ================================================================== */

	private function transport_guard_group(): void {
		global $wpdb;
		$this->group( 'Transport guards' );

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$service   = new BingoPlayProductService();
		$game_type = $this->make_game_type( self::SUNRISE, 'Guard' );
		$subject   = $this->make_play_product( $service, 'guard', 'digital', 3, $game_type );
		$plan      = CatalogPlayProductPlan::forDigitalToPaper( self::SUNRISE, $subject, current_time( 'mysql', true ) );
		$composition = CatalogPackageCompositionPlan::forReplaceComposition( self::SUNRISE, 1, 'x', [], current_time( 'mysql', true ) );

		// Shared $active guard, in BOTH nesting directions.
		$this->set_flag( 'active', true );
		$this->is_error( 'catalog_transport_nested_use', CatalogTransactionTransport::transitionToPaper( $plan ), 'ADR-032 entry is refused while an ADR-033 boundary is active (shared $active)' );
		$this->is_error( 'catalog_transport_nested_use', CatalogTransactionTransport::replacePackageComposition( $composition ), 'ADR-033 entry is refused while an ADR-032 boundary is active (shared $active)' );
		$this->set_flag( 'active', false );

		// Shared $untrusted flag, in BOTH directions.
		$this->set_flag( 'untrusted', true );
		$this->is_error( 'catalog_transaction_state_untrusted', CatalogTransactionTransport::transitionToPaper( $plan ), 'a poisoned session refuses the ADR-032 boundary' );
		$this->is_error( 'catalog_transaction_state_untrusted', CatalogTransactionTransport::replacePackageComposition( $composition ), 'the SAME poisoned flag refuses the ADR-033 boundary' );
		$this->set_flag( 'untrusted', false );

		$reflection = new ReflectionClass( CatalogTransactionTransport::class );
		$this->ok(
			$reflection->getProperty( 'active' )->isStatic() && $reflection->getProperty( 'untrusted' )->isStatic(),
			'$active and $untrusted are single shared statics, not per-boundary state'
		);

		// A session whose transaction state cannot be verified is refused BEFORE
		// BEGIN, and does NOT poison the shared flag — nothing was begun.
		$cold      = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$saved     = $wpdb->dbh;
		$wpdb->dbh = $cold;
		$cold_result = CatalogTransactionTransport::transitionToPaper( $plan );
		$wpdb->dbh   = $saved;
		$cold->close();
		$this->is_error( 'catalog_transaction_state_untrusted', $cold_result, 'an unverifiable session state is refused before BEGIN' );
		$this->same( false, $this->transport_untrusted(), '  and a pre-BEGIN refusal does not poison the shared session flag' );

		// Failed ROLLBACK poisons the shared session and is never retried.
		$referenced_pkg = $this->make_package( self::SUNRISE, 'guardpkg' );
		$this->add_component( self::SUNRISE, $referenced_pkg, $subject );
		$saved     = $wpdb->dbh;
		$seam      = new BcStageARollbackFailureConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		// The service's own pre-transaction reads run on the seam first, exactly
		// as they do in production; a connection that has carried no transaction
		// at all cannot report a transaction state and is refused before BEGIN
		// (see the cold-session assertion above).
		$this->warm( $seam );
		$seam->begin_count = 0;
		$poisoned  = ( new BingoPlayProductService() )->updateMedium( $subject, 'paper' );
		$begins    = $seam->begin_count;
		$wpdb->dbh = $saved;
		$seam->close();
		$this->is_error( 'catalog_transaction_state_untrusted', $poisoned, 'a failed ROLLBACK returns catalog_transaction_state_untrusted' );
		$this->same( 1, $begins, '  exactly ONE transaction was begun — the rejection was not retried' );
		$this->is_error( 'catalog_transaction_state_untrusted', CatalogTransactionTransport::replacePackageComposition( $composition ), '  and the poison is visible to the ADR-033 boundary' );
		$this->set_flag( 'untrusted', false );

		// Ambiguous COMMIT: committed, but never reported as success, never retried, never audited.
		$unknown_subject = $this->make_play_product( $service, 'unknown', 'digital', 3, $game_type );
		$audit_before    = $this->audit_count( self::SUNRISE, $unknown_subject );
		$saved           = $wpdb->dbh;
		$seam            = new BcStageACommittedUnknownConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$began     = microtime( true );
		$ambiguous = ( new BingoPlayProductService() )->updateMedium( $unknown_subject, 'paper' );
		$elapsed   = microtime( true ) - $began;
		$wpdb->dbh = $saved;
		$seam->close();
		$this->is_error( 'catalog_commit_outcome_unknown', $ambiguous, 'an unclean COMMIT acknowledgement returns catalog_commit_outcome_unknown' );
		$this->ok( $elapsed < 1.0, sprintf( '  and is not retried (%.3fs)', $elapsed ) );
		$this->same( 'paper', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT medium FROM ' . Migrations::play_products_table() . ' WHERE bingo_play_product_id = %d', $unknown_subject ) ), '  the transaction really committed' );
		$this->same( $audit_before, $this->audit_count( self::SUNRISE, $unknown_subject ), '  and NO audit event was emitted for it' );
		$this->set_flag( 'untrusted', false );

		// Retryable lock conflict: a held anchor drives the bounded retry to exhaustion.
		$conflict = $this->make_play_product( $service, 'conflict', 'digital', 3, $game_type );
		restore_current_blog();
		$holder = $this->start_fixture( self::SUNRISE, $conflict, $referenced_pkg, 'none', 2500 );
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$began    = microtime( true );
		$blocked  = ( new BingoPlayProductService() )->updateMedium( $conflict, 'paper' );
		$conflict_elapsed = microtime( true ) - $began;
		restore_current_blog();
		$this->finish_fixture( $holder );
		$this->is_error( 'catalog_write_conflict', $blocked, 'a held anchor exhausts the bounded retry budget as catalog_write_conflict' );
		$this->ok( $conflict_elapsed < 5.0, sprintf( '  bounded by NOWAIT + retry budget (%.3fs)', $conflict_elapsed ) );

		// Callback freedom: no gettext callback and no `query` filter inside the boundary.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $this->hall_manager( self::SUNRISE ) );
		$violations = 0;
		$queries    = 0;
		$test       = $this;
		$gettext    = static function ( $t ) use ( &$violations, $test ) {
			if ( $test->transport_is_active() ) {
				++$violations;
			}
			return $t;
		};
		$query_probe = static function ( $q ) use ( &$queries, $test ) {
			if ( $test->transport_is_active() ) {
				++$queries;
			}
			return $q;
		};
		add_filter( 'gettext', $gettext, 10, 1 );
		add_filter( 'gettext_bingocommerce-catalog', $gettext, 10, 1 );
		add_filter( 'query', $query_probe, 10, 1 );
		( new BingoPlayProductService() )->updateMedium( $subject, 'paper' ); // rejected in-transaction
		$free_subject = $this->make_play_product( $service, 'cbfree', 'digital', 3, $game_type );
		( new BingoPlayProductService() )->updateMedium( $free_subject, 'paper' ); // succeeds in-transaction
		remove_filter( 'gettext', $gettext, 10 );
		remove_filter( 'gettext_bingocommerce-catalog', $gettext, 10 );
		remove_filter( 'query', $query_probe, 10 );
		$this->same( 0, $violations, 'no gettext callback ran while a Catalog transaction was active' );
		$this->same( 0, $queries, 'the `query` filter was never invoked by direct-mysqli transaction statements' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * ADR-033 helpers
	 * ================================================================== */

	/**
	 * @param array<int,array{0:int,1:int}> $pairs id => quantity pairs
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function replace( int $blog_id, int $package_id, string $fingerprint, array $pairs ) {
		$components = [];
		foreach ( $pairs as $pair ) {
			$components[] = [ 'bingo_play_product_id' => (int) $pair[0], 'quantity' => (int) $pair[1] ];
		}

		return CatalogTransactionTransport::replacePackageComposition(
			CatalogPackageCompositionPlan::forReplaceComposition(
				$blog_id,
				$package_id,
				$fingerprint,
				$components,
				current_time( 'mysql', true )
			)
		);
	}

	private function fingerprint( int $package_id ): string {
		return CatalogTransactionTransport::compositionFingerprint( $this->component_rows( $package_id ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function component_rows( int $package_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT package_component_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d ORDER BY bingo_play_product_id', $package_id ),
			ARRAY_A
		);
	}

	/** @return array<string,mixed> */
	private function component_row( int $package_id, int $play_product_id ): array {
		global $wpdb;

		return (array) $wpdb->get_row(
			$wpdb->prepare( 'SELECT package_component_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d AND bingo_play_product_id = %d', $package_id, $play_product_id ),
			ARRAY_A
		);
	}

	private function component_count( int $package_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d', $package_id ) );
	}

	private function component_count_for( int $package_id, int $play_product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d AND bingo_play_product_id = %d', $package_id, $play_product_id ) );
	}

	private function package_type( int $package_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT package_type FROM ' . Migrations::packages_table() . ' WHERE package_id = %d', $package_id ) );
	}

	/** @return mixed */
	private function transport_constant( string $name ) {
		return ( new ReflectionClass( CatalogTransactionTransport::class ) )->getConstant( $name );
	}

	private function is_ok( $result, string $message ): void {
		if ( $result instanceof CatalogWriteOutcome ) {
			$this->ok( true, $message );

			return;
		}

		$this->same( 'CatalogWriteOutcome', is_wp_error( $result ) ? 'ERROR:' . $result->get_error_code() . ' — ' . $result->get_error_message() : gettype( $result ), $message );
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	private function make_product( int $site_id, string $label ): int {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$product = new WC_Product_Simple();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->save();
		$id = (int) $product->get_id();
		update_post_meta( $id, self::OWNER_META, $this->run_token );
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

	private function make_play_product( BingoPlayProductService $service, string $label, string $medium, int $faces, ?int $game_type = null ): int {
		$product = $this->make_product( get_current_blog_id(), $label );
		$result  = $service->create( $product, $medium, $faces, $game_type );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Play Product creation failed for {$label}: " . $result->get_error_code() );
			return 0;
		}
		$id = (int) $result->play_product['bingo_play_product_id'];
		$this->created_play_products[ get_current_blog_id() ][] = $id;
		return $id;
	}

	/** Planted directly: Stage A has no Package writer, and must not create one. */
	private function make_package( int $site_id, string $label, string $status = 'active' ): int {
		global $wpdb;
		$product = $this->make_product( $site_id, 'pkg ' . $label );
		$now     = current_time( 'mysql', true );
		$wpdb->insert(
			Migrations::packages_table(),
			[ 'wc_product_id' => $product, 'package_type' => 'digital', 'status' => $status, 'created_at' => $now, 'updated_at' => $now ],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);
		$id = (int) $wpdb->insert_id;
		$this->created_packages[ $site_id ][] = $id;
		return $id;
	}

	private function add_component( int $site_id, int $package_id, int $play_product_id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Migrations::package_components_table(),
			[ 'package_id' => $package_id, 'bingo_play_product_id' => $play_product_id, 'quantity' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now ],
			[ '%d', '%d', '%d', '%d', '%s', '%s' ]
		);
	}

	private function component_rows_for( int $site_id, int $play_product_id ): int {
		global $wpdb;
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::package_components_table() . ' WHERE bingo_play_product_id = %d', $play_product_id ) );
		if ( $switched ) {
			restore_current_blog();
		}
		return $count;
	}

	private function play_product_rows_for( int $wc_product_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $wc_product_id ) );
	}

	private function audit_count( int $site_id, int $play_product_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND target_type = %s AND target_id = %s',
				$site_id,
				'bingo_play_product',
				(string) $play_product_id
			)
		);
	}

	/** @return array{0:resource,1:array} */
	private function start_fixture( int $site_id, int $play_product_id, int $package_id, string $action, int $hold_ms ): array {
		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/package-reference-fixture-worker.php', (string) $site_id, (string) $play_product_id, (string) $package_id, $action, (string) $hold_ms ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$line = fgets( $pipes[1] );
		$this->ok( is_string( $line ) && 0 === strpos( $line, 'READY' ), 'reference fixture acquired the Play Product anchor lock' );
		return [ $handle, $pipes, (string) $line ];
	}

	/** @param array{0:resource,1:array} $worker */
	private function finish_fixture( array $worker ): string {
		[ $handle, $pipes, $ready ] = $worker;
		$rest = (string) stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $handle );
		return $ready . $rest;
	}

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'pkga' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'PKGA Disposable Migration Hall',
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->ok( false, 'disposable migration hall could not be created: ' . $site->get_error_message() );
			return 0;
		}
		$this->disposable_site_id              = (int) $site;
		$this->disposable_sites[ (int) $site ] = true;

		// WooCommerce does not install its own tables on a site created this way,
		// and the product fixtures below write to them. Without this the run is
		// buried in wc_product_meta_lookup / actionscheduler_actions errors.
		if ( class_exists( 'WC_Install' ) ) {
			switch_to_blog( (int) $site );
			WC_Install::create_tables();
			restore_current_blog();
		}

		return (int) $site;
	}

	private function destroy_disposable_site(): void {
		global $wpdb;
		if ( $this->disposable_site_id < 1 ) {
			return;
		}
		$site_id = $this->disposable_site_id;
		switch_to_blog( $site_id );
		foreach ( self::SUITE_TRIGGERS as $trigger ) {
			$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
		}
		// All FOUR hall-local Catalog v3 tables. WordPress does not know these
		// custom tables, so wp_delete_site() leaves every one of them behind.
		$tables = [ Migrations::package_components_table(), Migrations::packages_table(), Migrations::play_products_table(), Migrations::game_types_table() ];
		$this->disposable_tables[ $site_id ] = $tables;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) );
		restore_current_blog();
		wp_delete_site( $site_id );
		foreach ( $tables as $table ) {
			if ( 1 === preg_match( '/^bc_' . $site_id . '_bingo_[a-z_]+$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}
		unset( $this->disposable_sites[ $site_id ] );
		$this->disposable_site_id = 0;
	}

	/**
	 * The single gate that keeps every structural mutation in this suite off a
	 * persistent hall. A crash at any point must leave Sunrise, Tampa and
	 * Orlando structurally exactly as they were.
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

	private function count_admin_init_migration_callbacks(): int {
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

	private function record_baseline_auto_drafts(): void {
		global $wpdb;
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->baseline_auto_drafts[ $site_id ] = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" ) );
			restore_current_blog();
		}
	}

	/**
	 * A brand-new mysqli connection has no performance_schema transaction row at
	 * all, so the transport's pre-BEGIN state probe correctly refuses it. One
	 * committed transaction gives the session the COMMITTED state that every
	 * real request's connection already has by the time a Catalog write runs.
	 */
	private function warm( mysqli $connection ): void {
		$connection->begin_transaction();
		$connection->commit();
	}

	private function code_of( $result ): string {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_code();
		}

		return $result instanceof PlayProductResult ? $result->status : gettype( $result );
	}

	public function transport_untrusted(): bool {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, 'untrusted' );
		$reflection->setAccessible( true );

		return (bool) $reflection->getValue();
	}

	public function transport_is_active(): bool {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, 'active' );
		$reflection->setAccessible( true );
		return (bool) $reflection->getValue();
	}

	private function set_flag( string $property, bool $value ): void {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( null, $value );
	}

	private function hall_manager( int $site_id ): int {
		switch_to_blog( $site_id );
		$users = get_users( [ 'blog_id' => $site_id, 'role' => Roles::HALL_MANAGER, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
		restore_current_blog();
		return isset( $users[0] ) ? (int) $users[0] : 0;
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
	 * Cleanup — exact tracked IDs and the durable marker only
	 * ================================================================== */

	private function cleanup(): void {
		global $wpdb;
		$this->group( 'Cleanup verification' );

		wp_set_current_user( $this->super_admin_id );
		$this->set_flag( 'active', false );
		$this->set_flag( 'untrusted', false );
		$this->destroy_disposable_site();

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );

			foreach ( $this->created_packages[ $site_id ] ?? [] as $package_id ) {
				$wpdb->delete( Migrations::package_components_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
				$wpdb->delete( Migrations::packages_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
			}
			// Any Play Product row attached to a product this run created is ours,
			// whether or not its ID was tracked. Without this, deleting the product
			// post below would leave an orphaned authoritative row behind.
			$owned = $this->created_play_products[ $site_id ] ?? [];
			foreach ( $this->created_products[ $site_id ] ?? [] as $product_id ) {
				$attached = $wpdb->get_col( $wpdb->prepare( 'SELECT bingo_play_product_id FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', (int) $product_id ) );
				foreach ( (array) $attached as $attached_id ) {
					$owned[] = (int) $attached_id;
				}
			}

			foreach ( array_unique( $owned ) as $play_product_id ) {
				$wpdb->delete( Migrations::package_components_table(), [ 'bingo_play_product_id' => (int) $play_product_id ], [ '%d' ] );
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_play_product', 'target_id' => (string) (int) $play_product_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => (int) $play_product_id ], [ '%d' ] );
			}
			foreach ( $this->created_game_types[ $site_id ] ?? [] as $game_type_id ) {
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'game_type', 'target_id' => (string) (int) $game_type_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( Migrations::game_types_table(), [ 'game_type_id' => (int) $game_type_id ], [ '%d' ] );
			}

			// Marker-proven products, plus any auto-draft this run caused.
			$proven = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s", self::OWNER_META ) ) );
			foreach ( $proven as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			if ( isset( $this->baseline_auto_drafts[ $site_id ] ) ) {
				$current = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" ) );
				foreach ( array_diff( $current, $this->baseline_auto_drafts[ $site_id ] ) as $post_id ) {
					wp_delete_post( (int) $post_id, true );
				}
			}
			restore_current_blog();
		}

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->same( 3, (int) get_option( Migrations::VERSION_OPTION, 0 ), "hall {$site_id} remains at Catalog v3" );
			$this->ok( true === Migrations::verify_current_site_schema(), "hall {$site_id} schema verifies after cleanup" );
			$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key = %s", self::OWNER_META ) ), "hall {$site_id}: no marker-owned products remain" );
			$this->same(
				0,
				(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' pp LEFT JOIN `' . $wpdb->posts . '` p ON p.ID = pp.wc_product_id WHERE p.ID IS NULL AND pp.created_at >= \'' . esc_sql( $this->started_at ) . '\'' ),
				"hall {$site_id}: this run left no orphaned Play Product rows"
			);
			$this->same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::packages_table() ), "hall {$site_id}: no Package rows remain" );
			$this->same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::package_components_table() ), "hall {$site_id}: no Package component rows remain" );
			restore_current_blog();
		}

		// An interrupted earlier run can leave its own disposable hall behind. The
		// domain shape is created ONLY by this suite, and the id range excludes
		// every persistent hall, so sweeping them is safe and keeps the assertion
		// below meaningful.
		foreach ( (array) $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain REGEXP '^pkga[a-z0-9]+\\.bingocommerce\\.test$'" ) as $stray ) {
			$stray = (int) $stray;
			if ( $stray > 4 ) {
				$this->disposable_site_id = $stray;
				$this->destroy_disposable_site();
			}
		}

		$this->same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE domain LIKE 'pkga%'" ), 'no disposable hall remains' );

		foreach ( $this->disposable_tables as $site_id => $tables ) {
			foreach ( $tables as $table ) {
				$this->same(
					0,
					(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) ),
					"disposable hall {$site_id}: {$table} was removed"
				);
			}
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_bingo_' ) . '%' ) ),
				"disposable hall {$site_id}: no hall-local Catalog table of any name survives"
			);
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND event_object_table LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_' ) . '%' ) ),
				"disposable hall {$site_id}: no trigger remains attached to any of its tables"
			);
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) ),
				"disposable hall {$site_id}: no audit rows remain"
			);
		}

		$this->same( [], $this->disposable_sites, 'every disposable hall this run created was destroyed' );
		$this->same(
			[],
			array_intersect( array_keys( $this->disposable_tables ), [ 1, self::SUNRISE, self::TAMPA, self::ORLANDO ] ),
			'no persistent hall was ever registered as disposable'
		);
		$this->same(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND trigger_name LIKE %s', DB_NAME, 'bc_pkga_%' ) ),
			'no Stage A test trigger remains anywhere'
		);
		$this->same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE object_type = 'USER LEVEL LOCK'" ), 'no advisory locks remain' );
		$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s', DB_NAME ) ), 'no triggers remain' );
		$this->same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->users}` WHERE user_login LIKE 'PKGA%'" ), 'no temporary users remain' );
	}

	/* ================================================================== *
	 * Assertions with per-group accounting
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
		$actual = is_wp_error( $result )
			? $result->get_error_code()
			: ( $result instanceof PlayProductResult ? 'RESULT:' . $result->status : gettype( $result ) );
		$this->same( $expected, $actual, $message );
	}

	private function is_status( string $expected, $result, string $message ): void {
		$actual = $result instanceof PlayProductResult
			? $result->status
			: ( is_wp_error( $result ) ? 'ERROR:' . $result->get_error_code() : gettype( $result ) );
		$this->same( $expected, $actual, $message );
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
		echo "  " . str_repeat( '-', 60 ) . "\n";
		printf( "  %-32s %3d assertions   %s\n", 'TOTAL', $pass + $fail, $fail > 0 ? "FAIL ({$fail})" : 'PASS' );
		foreach ( $this->notes as $note ) {
			echo "NOTE: {$note}\n";
		}
		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

( new PackageStageATest() )->run();
