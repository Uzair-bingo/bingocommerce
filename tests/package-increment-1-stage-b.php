<?php

/**
 * CLI integration coverage for Package Increment 1 — Stage B
 * (Package domain writer + Hall Manager administration).
 *
 * Exercises the real database: authorization, the ADR-032 Package-create
 * profile, BR-061 derivation, create atomicity, ADR-033 composition editing
 * through PackageService, BR-070 lifecycle, BR-049/ADR-031 Woo price authority,
 * BR-065 projection, real multi-process claim concurrency, and the shared
 * transport failure contract.
 *
 * HARNESS SAFETY (inherited from Stage A, not relaxed):
 *  - persistent halls (Sunrise 2, Tampa 3, Orlando 4) receive ordinary domain
 *    DML against tracked fixture rows ONLY. No CREATE/DROP/ALTER/RENAME/TRUNCATE
 *    and no trigger or AUTO_INCREMENT manipulation ever targets them;
 *  - every structural or corrupt-state scenario runs on a hall created by this
 *    run alone, behind assert_disposable_hall();
 *  - ownership is exact tracked ids plus a durable post-meta marker — never a
 *    name, title, prefix, code or timestamp;
 *  - every bc_audit_log delete is scoped by blog_id;
 *  - existing unowned residue is reported, never deleted.
 *
 * Run from the repository root:
 * php tests/package-increment-1-stage-b.php
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
if ( ! class_exists( '\BingoCommerce\Catalog\PackageService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CatalogPackagePlan;
use BingoCommerce\Catalog\CatalogTransactionTransport;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PackageResult;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Catalog\PlayProductResult;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Core\Roles;

/** A real connection whose COMMIT commits but reports failure. */
final class BcStageBCommittedUnknownConnection extends mysqli {
	public function commit( int $flags = 0, ?string $name = null ): bool {
		parent::commit( $flags, $name );
		return false;
	}
}

/** A real connection whose ROLLBACK never succeeds, counting begun transactions. */
final class BcStageBRollbackFailureConnection extends mysqli {
	public int $begin_count = 0;
	public function begin_transaction( int $flags = 0, ?string $name = null ): bool {
		++$this->begin_count;
		return parent::begin_transaction( $flags, $name );
	}
	public function rollback( int $flags = 0, ?string $name = null ): bool {
		return false;
	}
}

final class PackageStageBTest {

	private const SUNRISE = 2;
	private const TAMPA   = 3;
	private const ORLANDO = 4;
	private const PREFIX  = 'PKGB';
	private const OWNER_META = '_bc_pkgb_test_artifact';

	/** Every trigger name this suite may create. Disposable halls only. */
	private const SUITE_TRIGGERS = [ 'bc_pkgb_block_component' ];

	private int $super_admin_id = 0;
	private string $run_token = '';

	/** @var array<int,bool> */
	private array $disposable_sites = [];
	/** @var array<int,array<int,string>> */
	private array $disposable_catalog_tables = [];

	/** @var array<int,array<int,int>> */
	private array $created_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_game_types = [];
	/** @var array<int,array<int,int>> */
	private array $created_play_products = [];
	/** @var array<int,array<int,int>> */
	private array $created_packages = [];
	/** @var array<int,array<int,int>> */
	private array $baseline_auto_drafts = [];

	/**
	 * Canonical bingo_packages / bingo_package_components snapshots for every
	 * persistent hall, taken before any mutation.
	 *
	 * Legitimate pre-existing or manually created Package data MUST survive this
	 * suite. Asserting those tables are empty would both fail on real hall data
	 * and invite a future "clean it up" reflex, so the contract is preservation:
	 * the snapshot after cleanup must equal the snapshot before.
	 *
	 * @var array<int,array<string,array<int,array<string,mixed>>>>
	 */
	private array $baseline_packages = [];

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
	private array $groups = [];
	/** @var array<int,string> */
	private array $notes = [];

	public function run(): void {
		$this->run_token      = 'pkgb-' . wp_generate_password( 12, false );
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );
		$this->record_baseline_auto_drafts();
		$this->record_baseline_packages();
		$roles_before = $this->role_snapshot();
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			$this->note( sprintf(
				'hall %d Package baseline: %d Package row(s), %d component row(s) — these must survive untouched',
				$site_id,
				count( $this->baseline_packages[ $site_id ]['packages'] ),
				count( $this->baseline_packages[ $site_id ]['components'] )
			) );
		}

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'disposable hall created for every destructive scenario' );
		$this->ok( ! in_array( $hall, [ 1, self::SUNRISE, self::TAMPA, self::ORLANDO ], true ), '  and it is not a persistent hall' );

		try {
			$this->authorization_group();
			$this->create_group( $hall );
			$this->derivation_group( $hall );
			$this->create_atomicity_group( $hall );
			$this->composition_edit_group( $hall );
			$this->dependency_corruption_group( $hall );
			$this->lifecycle_group( $hall );
			$this->price_authority_group( $hall );
			$this->presentation_boundary_group( $hall );
			$this->claim_concurrency_group( $hall );
			$this->failure_contract_group( $hall );
		} finally {
			$this->cleanup();
		}

		$this->group( 'Setup' );
		$this->same( $roles_before, $this->role_snapshot(), 'role/capability records unchanged by the suite' );

		$this->report();
	}

	/* ================================================================== *
	 * A. Authorization
	 * ================================================================== */

	private function authorization_group(): void {
		$this->group( 'A. Authorization' );

		$manager  = $this->user_with_role( self::SUNRISE, Roles::HALL_MANAGER );
		$operator = $this->user_with_role( self::SUNRISE, Roles::GAME_OPERATOR );
		$this->ok( $manager > 0, 'Hall Manager fixture exists on Sunrise' );
		$this->ok( $operator > 0, 'Game Operator fixture exists on Sunrise' );

		switch_to_blog( self::SUNRISE );

		wp_set_current_user( $manager );
		$this->ok( ! is_wp_error( ( new PackageService() )->listForAdministration() ), 'Hall Manager may manage Bingo Packages' );

		wp_set_current_user( $operator );
		$this->is_error( 'package_management_denied', ( new PackageService() )->listForAdministration(), 'Game Operator is denied' );
		$this->is_error( 'package_management_denied', ( new PackageService() )->create( 1, [ [ 'bingo_play_product_id' => 1, 'quantity' => 1 ] ] ), '  and cannot create a Package' );
		$this->is_error( 'package_management_denied', ( new PackageService() )->archive( 1 ), '  and cannot archive one' );

		$subscriber = $this->ordinary_user();
		wp_set_current_user( $subscriber );
		$this->is_error( 'package_management_denied', ( new PackageService() )->listForAdministration(), 'an ordinary user is denied' );

		wp_set_current_user( 0 );
		$this->is_error( 'authentication_required', ( new PackageService() )->listForAdministration(), 'an anonymous request is denied' );

		wp_set_current_user( $this->super_admin_id );
		$this->ok( ! is_wp_error( ( new PackageService() )->listForAdministration() ), 'Super Admin overrides on the current hall' );
		restore_current_blog();

		// Cross-hall: a Sunrise Package id must be invisible and immutable from Tampa.
		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$service  = new PackageService();
		$play     = new BingoPlayProductService();
		$gt       = $this->make_game_type( self::SUNRISE, 'Auth' );
		$product  = $this->make_product( self::SUNRISE, 'authpp' );
		$pp       = (int) $play->create( $product, 'digital', 3, $gt )->play_product['bingo_play_product_id'];
		$this->created_play_products[ self::SUNRISE ][] = $pp;
		$created  = $service->create( $this->make_product( self::SUNRISE, 'authpkg' ), [ [ 'bingo_play_product_id' => $pp, 'quantity' => 1 ] ] );
		$this->is_status( 'package_created', $created, 'a Sunrise Package is created for the isolation check' );
		$sunrise_package = (int) $created->package['package_id'];
		$this->created_packages[ self::SUNRISE ][] = $sunrise_package;

		// Prove the preservation comparison is not vacuous: with this row present
		// the live snapshot MUST differ from the baseline, so the cleanup-time
		// equality assertion is a real round-trip through non-empty state.
		$live = $this->package_snapshot( self::SUNRISE );
		$this->ok( $live['packages'] !== $this->baseline_packages[ self::SUNRISE ]['packages'], 'the Package baseline comparison detects a difference while this run holds a Sunrise Package' );
		$this->ok( $live['components'] !== $this->baseline_packages[ self::SUNRISE ]['components'], '  and detects its component row too' );
		restore_current_blog();

		switch_to_blog( self::TAMPA );
		wp_set_current_user( $this->user_with_role( self::TAMPA, Roles::HALL_MANAGER ) );
		$tampa = new PackageService();
		$this->is_error( 'package_not_found', $tampa->getById( $sunrise_package ), 'Tampa cannot read a Sunrise Package id' );
		$this->is_error( 'package_not_found', $tampa->archive( $sunrise_package ), 'Tampa cannot archive a Sunrise Package id' );
		$this->is_error( 'package_not_found', $tampa->restore( $sunrise_package ), 'Tampa cannot restore a Sunrise Package id' );
		$this->is_error( 'package_not_found', $tampa->replaceComponents( $sunrise_package, str_repeat( 'a', 64 ), [ [ 'bingo_play_product_id' => $pp, 'quantity' => 1 ] ] ), 'Tampa cannot edit a Sunrise Package composition' );
		$ids = array_column( (array) $tampa->listForAdministration(), 'package_id' );
		$this->ok( ! in_array( $sunrise_package, $ids, true ), 'the Sunrise Package never appears in Tampa administration' );
		restore_current_blog();

		switch_to_blog( self::SUNRISE );
		wp_set_current_user( $manager );
		$still = ( new PackageService() )->getById( $sunrise_package );
		$this->same( 'active', is_array( $still ) ? $still['status'] : null, 'the Sunrise Package is untouched by every Tampa attempt' );
		restore_current_blog();

		wp_set_current_user( $this->super_admin_id );
	}

	/* ================================================================== *
	 * B. Create
	 * ================================================================== */

	private function create_group( int $hall ): void {
		global $wpdb;
		$this->group( 'B. Create' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$gt      = $this->make_game_type( $hall, 'Create' );

		$paper   = $this->make_play_product( $hall, $play, 'bPaper', 'paper', 2 );
		$digital = $this->make_play_product( $hall, $play, 'bDigital', 'digital', 3, $gt );

		$created = $service->create( $this->make_product( $hall, 'bPkg' ), [ [ 'bingo_play_product_id' => $paper, 'quantity' => 2 ] ] );
		$this->is_status( 'package_created', $created, 'a Package is created with its initial composition' );
		$package_id = (int) $created->package['package_id'];
		$this->created_packages[ $hall ][] = $package_id;
		$this->same( 1, count( $created->package['components'] ), '  and its composition is present in the same result' );
		$this->same( 'active', $created->package['status'], '  and it is active' );
		$this->same( [], $created->warnings, '  with no settlement warnings' );

		// BR-065 projection, written post-commit, outside the transaction.
		$this->same( 'package', (string) get_post_meta( (int) $created->package['wc_product_id'], PackageService::PRODUCT_KIND_META, true ), 'BR-065: bc_product_kind = package is projected onto the linked product' );
		$this->same( '', (string) get_post_meta( (int) $created->package['wc_product_id'], 'bc_package_relation', true ), '  and bc_package_relation is NOT written by this slice' );

		// The linked Woo product is immutable: no service entry point changes it.
		$this->ok( ! method_exists( PackageService::class, 'relink' ) && ! method_exists( PackageService::class, 'updateWooProduct' ), 'no relink entry point exists — the linked Woo product is immutable' );

		// Rejections.
		$this->is_error( 'package_requires_component', $service->create( $this->make_product( $hall, 'bEmpty' ), [] ), 'an initial composition is required (BR-067)' );
		$this->is_error( 'duplicate_component', $service->create( $this->make_product( $hall, 'bDup' ), [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ], [ 'bingo_play_product_id' => $paper, 'quantity' => 2 ] ] ), 'a duplicated component is rejected (BR-067)' );

		foreach ( [ 0, -1, 4294967296 ] as $bad ) {
			$this->is_error( 'invalid_component_quantity', $service->create( $this->make_product( $hall, 'bQty' ), [ [ 'bingo_play_product_id' => $paper, 'quantity' => $bad ] ] ), "quantity {$bad} is rejected" );
		}
		$this->is_error( 'invalid_component_set', $service->create( $this->make_product( $hall, 'bShape' ), [ [ 'bingo_play_product_id' => $paper ] ] ), 'a malformed component entry is rejected' );

		$this->is_error( 'play_product_not_found', $service->create( $this->make_product( $hall, 'bMissing' ), [ [ 'bingo_play_product_id' => 987654321, 'quantity' => 1 ] ] ), 'an unknown Bingo Play Product is rejected' );

		$archived = $this->make_play_product( $hall, $play, 'bArchived', 'paper', 2 );
		$this->is_status( 'play_product_archived', $play->archive( $archived ), 'a Bingo Play Product is archived for the create check' );
		$this->is_error( 'play_product_not_active', $service->create( $this->make_product( $hall, 'bArchPkg' ), [ [ 'bingo_play_product_id' => $archived, 'quantity' => 1 ] ] ), 'an archived Bingo Play Product cannot start a Package (BR-067)' );

		// Corrupt authoritative state fails closed, exactly as Stage A does.
		$corrupt = $this->make_play_product( $hall, $play, 'bCorrupt', 'paper', 2 );
		$products_table = Migrations::play_products_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$products_table} SET medium = 'holographic' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_error( 'catalog_schema_inconsistent', $service->create( $this->make_product( $hall, 'bCorruptPkg' ), [ [ 'bingo_play_product_id' => $corrupt, 'quantity' => 1 ] ] ), 'an unrecognised medium fails closed, never inferred as Paper' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$products_table} SET medium = 'paper', status = 'suspended' WHERE bingo_play_product_id = %d", $corrupt ) );
		$this->is_error( 'catalog_schema_inconsistent', $service->create( $this->make_product( $hall, 'bCorruptPkg2' ), [ [ 'bingo_play_product_id' => $corrupt, 'quantity' => 1 ] ] ), 'an unrecognised status fails closed, never inferred as archived' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$products_table} SET status = 'active' WHERE bingo_play_product_id = %d", $corrupt ) );

		// WooCommerce shell eligibility.
		$this->is_error( 'invalid_wc_product_id', $service->create( 0, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'a missing product id is rejected' );
		$this->is_error( 'woo_product_missing', $service->create( 987654321, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'an unknown WooCommerce product is rejected' );

		$trashed = $this->make_product( $hall, 'bTrashed' );
		wp_trash_post( $trashed );
		$this->is_error( 'woo_product_trashed', $service->create( $trashed, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'a trashed WooCommerce product is rejected' );

		$variable = new WC_Product_Variable();
		$variable->set_name( self::PREFIX . ' variable ' . wp_generate_password( 5, false ) );
		$variable->set_status( 'publish' );
		$variable->save();
		$variable_id = (int) $variable->get_id();
		update_post_meta( $variable_id, self::OWNER_META, $this->run_token );
		$this->created_products[ $hall ][] = $variable_id;
		$this->is_error( 'woo_product_unsupported', $service->create( $variable_id, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'a variable WooCommerce product is rejected' );

		// Claim exclusivity, both directions.
		$this->is_error( 'package_already_linked', $service->create( (int) $created->package['wc_product_id'], [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'a product already backing a Package is rejected' );
		$this->is_error( 'play_product_claim_exists', $service->create( $play->getById( $paper )['wc_product_id'], [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'a product already claimed by a Bingo Play Product is rejected' );

		$this->same( 0, $this->orphan_component_count( $hall ), 'no rejected create left an orphaned component row' );
		$this->same( 0, $this->componentless_package_count( $hall ), 'no rejected create left a component-less Package' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * C. Derivation
	 * ================================================================== */

	private function derivation_group( int $hall ): void {
		$this->group( 'C. Derivation' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$gt      = $this->make_game_type( $hall, 'Derive' );

		$paper_a   = $this->make_play_product( $hall, $play, 'cPaperA', 'paper', 2 );
		$paper_b   = $this->make_play_product( $hall, $play, 'cPaperB', 'paper', 1 );
		$digital_a = $this->make_play_product( $hall, $play, 'cDigA', 'digital', 3, $gt );
		$digital_b = $this->make_play_product( $hall, $play, 'cDigB', 'digital', 6, $gt );

		$paper_pkg = $this->create_package( $hall, $service, 'cPaper', [ [ $paper_a, 1 ], [ $paper_b, 2 ] ] );
		$this->same( 'paper', $this->package_type( $paper_pkg ), 'BR-061: every component Paper derives paper' );

		$digital_pkg = $this->create_package( $hall, $service, 'cDigital', [ [ $digital_a, 1 ], [ $digital_b, 2 ] ] );
		$this->same( 'digital', $this->package_type( $digital_pkg ), 'BR-061: every component Digital derives digital' );

		$hybrid_pkg = $this->create_package( $hall, $service, 'cHybrid', [ [ $paper_a, 1 ], [ $digital_a, 1 ] ] );
		$this->same( 'hybrid', $this->package_type( $hybrid_pkg ), 'BR-061: a mixture derives hybrid' );

		// A removed component contributes nothing.
		$edited = $service->replaceComponents( $hybrid_pkg, $service->compositionFingerprint( $hybrid_pkg ), [ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1 ] ] );
		$this->is_status( 'package_composition_updated', $edited, 'removing the Digital component is accepted' );
		$this->same( 'paper', $this->package_type( $hybrid_pkg ), '  and the removed Digital component does not contribute to the derived type' );

		// The caller cannot force a type.
		$this->ok( ! property_exists( CatalogPackagePlan::class, 'package_type' ), 'the create plan carries no caller-supplied package_type' );
		$forced = $service->create(
			$this->make_product( $hall, 'cForce' ),
			[ [ 'bingo_play_product_id' => $paper_a, 'quantity' => 1, 'package_type' => 'digital' ] ]
		);
		$this->is_status( 'package_created', $forced, 'an extra package_type key on a component is ignored, not honoured' );
		$this->created_packages[ $hall ][] = (int) $forced->package['package_id'];
		$this->same( 'paper', (string) $forced->package['package_type'], '  and the type is still derived from the composition' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * D. Create atomicity
	 * ================================================================== */

	private function create_atomicity_group( int $hall ): void {
		global $wpdb;
		$this->group( 'D. Create atomicity' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service    = new PackageService();
		$play       = new BingoPlayProductService();
		$paper      = $this->make_play_product( $hall, $play, 'dPaper', 'paper', 2 );
		$components = Migrations::package_components_table();
		$packages   = Migrations::packages_table();

		$packages_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$packages}" );
		$components_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$components}" );
		$shell             = $this->make_product( $hall, 'dAtomic' );
		$audit_before      = $this->audit_count( $hall, 'bingo_package' );

		// A SIGNAL trigger makes the component INSERT fail AFTER the Package row
		// has been inserted inside the same transaction — the only way to observe
		// that the Package insert is rolled back with it. Disposable hall only.
		$this->disposable_ddl(
			$hall,
			"CREATE TRIGGER bc_pkgb_block_component BEFORE INSERT ON `{$components}` FOR EACH ROW"
			. " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pkgb forced component insert failure'"
		);
		$blocked = $service->create( $shell, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] );
		$this->disposable_ddl( $hall, 'DROP TRIGGER IF EXISTS bc_pkgb_block_component' );

		$this->ok( is_wp_error( $blocked ), 'a failing component INSERT returns an error, not a partial success' );
		$this->same( $packages_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$packages}" ), '  the Package INSERT that preceded it was rolled back — no Package without its composition' );
		$this->same( $components_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$components}" ), '  and no component row survives without its Package' );
		$this->is_error( 'package_not_found', $service->getByWooProductId( $shell ), '  the product was never claimed' );
		$this->same( $audit_before, $this->audit_count( $hall, 'bingo_package' ), '  and NO audit event was emitted before a confirmed commit' );
		$this->same( '', (string) get_post_meta( $shell, PackageService::PRODUCT_KIND_META, true ), '  and NO BR-065 projection was written before a confirmed commit' );
		$this->same( false, $this->transport_untrusted(), '  and a clean ROLLBACK left the session trusted' );

		// The same create succeeds once the forced failure is gone.
		$recovered = $service->create( $shell, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] );
		$this->is_status( 'package_created', $recovered, 'the identical create succeeds after the forced failure is removed' );
		$this->created_packages[ $hall ][] = (int) $recovered->package['package_id'];
		$this->same( 'package', (string) get_post_meta( $shell, PackageService::PRODUCT_KIND_META, true ), '  and the projection is written only then' );
		$this->same( $audit_before + 1, $this->audit_count( $hall, 'bingo_package' ), '  and exactly one audit event follows the confirmed commit' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * E. Composition edit
	 * ================================================================== */

	private function composition_edit_group( int $hall ): void {
		$this->group( 'E. Composition edit' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$gt      = $this->make_game_type( $hall, 'Edit' );

		$a = $this->make_play_product( $hall, $play, 'ePaper', 'paper', 2 );
		$b = $this->make_play_product( $hall, $play, 'eDigital', 'digital', 3, $gt );
		$c = $this->make_play_product( $hall, $play, 'eExtra', 'paper', 1 );

		$package_id = $this->create_package( $hall, $service, 'ePkg', [ [ $a, 1 ] ] );
		$fingerprint = $service->compositionFingerprint( $package_id );
		$this->ok( is_string( $fingerprint ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $fingerprint ), 'the service exposes the canonical composition fingerprint' );

		// Add.
		$added = $service->replaceComponents( $package_id, $fingerprint, [ [ 'bingo_play_product_id' => $a, 'quantity' => 1 ], [ 'bingo_play_product_id' => $b, 'quantity' => 2 ] ] );
		$this->is_status( 'package_composition_updated', $added, 'a matching fingerprint adds a component' );
		$this->same( 'hybrid', $this->package_type( $package_id ), '  and the type is rederived' );

		// Stale.
		$this->is_error( 'package_composition_stale', $service->replaceComponents( $package_id, $fingerprint, [ [ 'bingo_play_product_id' => $a, 'quantity' => 5 ] ] ), 'the now-stale fingerprint is rejected' );
		$this->same( 2, count( $service->getById( $package_id )['components'] ), '  and the stale edit wrote nothing' );

		// Exact no-op.
		$before_rows = $this->component_rows( $package_id );
		$before_pkg  = $service->getById( $package_id );
		$audit_before = $this->audit_count( $hall, 'bingo_package' );
		$noop = $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $b, 'quantity' => 2 ], [ 'bingo_play_product_id' => $a, 'quantity' => 1 ] ] );
		$this->is_status( 'package_composition_unchanged', $noop, 'an identical composition is a verified no-op' );
		$this->same( $before_rows, $this->component_rows( $package_id ), '  no component DML ran: ids, quantities, sort_order and timestamps all survive' );
		$this->same( $before_pkg['updated_at'], $service->getById( $package_id )['updated_at'], '  bingo_packages.updated_at was not rewritten' );
		$this->same( $audit_before, $this->audit_count( $hall, 'bingo_package' ), '  and no mutation audit event was emitted' );

		// Quantity change and removal.
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 7 ], [ 'bingo_play_product_id' => $b, 'quantity' => 2 ] ] ), 'a quantity change is accepted' );
		$this->same( 7, $this->component_quantity( $package_id, $a ), '  and is stored' );
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 7 ] ] ), 'a component is removed' );
		$this->same( 1, count( $service->getById( $package_id )['components'] ), '  leaving one component' );
		$this->same( 'paper', $this->package_type( $package_id ), '  and the type follows the final set' );

		// Archived component rules.
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 7 ], [ 'bingo_play_product_id' => $c, 'quantity' => 3 ] ] ), 'a second component is added before archiving it' );
		$this->is_status( 'play_product_archived', $play->archive( $c ), 'the referenced Bingo Play Product is archived (BR-067 preserves the reference)' );
		$this->ok( true === $service->getById( $package_id )['has_archived_component'], 'administrative reads surface the archived component (BR-067 item 4)' );

		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 8 ], [ 'bingo_play_product_id' => $c, 'quantity' => 3 ] ] ), 'an archived component is RETAINED at its existing quantity' );
		$this->is_error( 'play_product_not_active', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 8 ], [ 'bingo_play_product_id' => $c, 'quantity' => 4 ] ] ), 'changing an archived component quantity is rejected' );
		$this->is_error( 'play_product_not_active', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 8 ], [ 'bingo_play_product_id' => $c, 'quantity' => 3 ], [ 'bingo_play_product_id' => $this->newly_archived( $hall, $play ), 'quantity' => 1 ] ] ), 'a NEWLY archived Bingo Play Product cannot be added' );
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 8 ] ] ), 'an archived component may be REMOVED' );

		// Empty and duplicate.
		$this->is_error( 'package_requires_component', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [] ), 'an empty composition is rejected (BR-067)' );
		$this->is_error( 'duplicate_component', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 1 ], [ 'bingo_play_product_id' => $a, 'quantity' => 2 ] ] ), 'a duplicated component is rejected' );

		// Archived Package.
		$this->is_status( 'package_archived', $service->archive( $package_id ), 'the Package is archived' );
		$this->is_error( 'package_archived', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 2 ] ] ), 'composition editing is rejected while archived (BR-068)' );
		$this->is_status( 'package_restored', $service->restore( $package_id ), 'the Package is restored' );
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $service->compositionFingerprint( $package_id ), [ [ 'bingo_play_product_id' => $a, 'quantity' => 2 ] ] ), '  and editing resumes' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * F. Current dependency corruption
	 * ================================================================== */

	private function dependency_corruption_group( int $hall ): void {
		global $wpdb;
		$this->group( 'F. Dependency corruption' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();

		$keep      = $this->make_play_product( $hall, $play, 'fKeep', 'paper', 2 );
		$vanishing = $this->make_play_product( $hall, $play, 'fVanishing', 'paper', 1 );
		$package_id = $this->create_package( $hall, $service, 'fPkg', [ [ $keep, 1 ], [ $vanishing, 2 ] ] );

		$before_rows = $this->component_rows( $package_id );
		$before_type = $this->package_type( $package_id );
		$fingerprint = $service->compositionFingerprint( $package_id );

		// The authoritative Play Product row disappears while its component row
		// survives. Disposable hall only.
		$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => $vanishing ], [ '%d' ] );
		$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE bingo_play_product_id = %d', $vanishing ) ), 'the dependency Play Product row is gone' );

		$removal = $service->replaceComponents( $package_id, $fingerprint, [ [ 'bingo_play_product_id' => $keep, 'quantity' => 1 ] ] );
		$this->is_error( 'play_product_not_found', $removal, 'an edit that REMOVES the broken reference still fails closed' );
		$this->same( $before_rows, $this->component_rows( $package_id ), '  the broken reference was NOT silently repaired' );
		$this->same( $before_type, $this->package_type( $package_id ), '  and package_type was not rewritten' );

		$retained = $service->replaceComponents( $package_id, $fingerprint, [ [ 'bingo_play_product_id' => $keep, 'quantity' => 1 ], [ 'bingo_play_product_id' => $vanishing, 'quantity' => 2 ] ] );
		$this->is_error( 'play_product_not_found', $retained, 'an edit that RETAINS the broken reference fails closed too' );
		$this->same( $before_rows, $this->component_rows( $package_id ), '  and still writes nothing' );

		$this->ok( true === $service->getById( $package_id )['has_unresolved_component'], 'administrative reads surface the unresolvable component' );

		// Restoring the authoritative row unblocks the same edit.
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Migrations::play_products_table(),
			[ 'bingo_play_product_id' => $vanishing, 'wc_product_id' => $this->make_product( $hall, 'fRevived' ), 'medium' => 'paper', 'faces_per_unit' => 1, 'game_type_id' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ],
			[ '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $package_id, $fingerprint, [ [ 'bingo_play_product_id' => $keep, 'quantity' => 1 ] ] ), 'the same removal succeeds once every dependency resolves again' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * G. Archive / restore
	 * ================================================================== */

	private function lifecycle_group( int $hall ): void {
		global $wpdb;
		$this->group( 'G. Archive / restore' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$gt      = $this->make_game_type( $hall, 'Life' );
		$paper   = $this->make_play_product( $hall, $play, 'gPaper', 'paper', 2 );
		$digital = $this->make_play_product( $hall, $play, 'gDigital', 'digital', 4, $gt );

		$package_id = $this->create_package( $hall, $service, 'gPkg', [ [ $paper, 2 ], [ $digital, 3 ] ] );
		$shell      = (int) $service->getById( $package_id )['wc_product_id'];
		$before     = $service->getById( $package_id );
		$audit_base = $this->audit_count( $hall, 'bingo_package' );

		$archived = $service->archive( $package_id );
		$this->is_status( 'package_archived', $archived, 'archive moves active -> archived (BR-070)' );
		$row = $service->getById( $package_id );
		$this->same( 'archived', $row['status'], '  status is archived' );
		$this->ok( null !== $row['archived_at'], '  archived_at is set' );
		$this->ok( $row['updated_at'] >= $before['updated_at'], '  updated_at advanced' );
		$this->same( $before['package_id'], $row['package_id'], '  package_id preserved' );
		$this->same( $before['wc_product_id'], $row['wc_product_id'], '  wc_product_id preserved' );
		$this->same( $before['package_type'], $row['package_type'], '  package_type preserved' );
		$this->same( $this->component_rows_of( $before ), $this->component_rows_of( $row ), '  composition preserved exactly' );
		$this->same( 'package', (string) get_post_meta( $shell, PackageService::PRODUCT_KIND_META, true ), '  bc_product_kind = package preserved' );
		$this->same( $audit_base + 1, $this->audit_count( $hall, 'bingo_package' ), '  and one catalog.package.archived event was emitted' );
		$this->same( 'catalog.package.archived', $this->last_audit_action( $hall, $package_id ), '  with the authorised action name' );

		$again = $service->archive( $package_id );
		$this->is_status( 'package_already_archived', $again, 'archiving an archived Package is a no-op' );
		$this->same( $audit_base + 1, $this->audit_count( $hall, 'bingo_package' ), '  and emits no audit event' );
		$this->same( $row['updated_at'], $service->getById( $package_id )['updated_at'], '  and does not rewrite updated_at' );

		$restored = $service->restore( $package_id );
		$this->is_status( 'package_restored', $restored, 'restore moves archived -> active (BR-070)' );
		$final = $service->getById( $package_id );
		$this->same( 'active', $final['status'], '  status is active' );
		$this->same( null, $final['archived_at'], '  archived_at is cleared' );
		$this->same( $before['package_id'], $final['package_id'], '  Package identity is preserved — restore creates no new identity' );
		$this->same( $before['wc_product_id'], $final['wc_product_id'], '  the Woo claim is preserved' );
		$this->same( $before['package_type'], $final['package_type'], '  package_type is NOT rederived' );
		$this->same( $this->component_rows_of( $before ), $this->component_rows_of( $final ), '  composition preserved exactly' );
		$this->same( 'catalog.package.restored', $this->last_audit_action( $hall, $package_id ), '  and catalog.package.restored was emitted' );

		$this->is_status( 'package_already_active', $service->restore( $package_id ), 'restoring an active Package is a no-op' );
		$this->same( $audit_base + 2, $this->audit_count( $hall, 'bingo_package' ), '  and emits no audit event' );

		// Restore never requires the component Play Products to be active (BR-068).
		$this->is_status( 'play_product_archived', $play->archive( $digital ), 'a component Bingo Play Product is archived' );
		$this->is_status( 'package_archived', $service->archive( $package_id ), 'the Package is archived while holding an archived component' );
		$this->is_status( 'package_restored', $service->restore( $package_id ), 'restore succeeds even though a component is archived (BR-068)' );
		$this->same( 2, count( $service->getById( $package_id )['components'] ), '  with its composition intact' );

		// Corrupt lifecycle value fails closed.
		$packages = Migrations::packages_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$packages} SET status = 'suspended' WHERE package_id = %d", $package_id ) );
		$this->is_error( 'catalog_schema_inconsistent', $service->archive( $package_id ), 'an unrecognised lifecycle value fails closed on archive' );
		$this->is_error( 'catalog_schema_inconsistent', $service->restore( $package_id ), '  and on restore' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$packages} SET status = 'active' WHERE package_id = %d", $package_id ) );

		// No hard delete exists in Stage B.
		$this->ok( ! method_exists( PackageService::class, 'delete' ) && ! method_exists( PackageService::class, 'hardDelete' ), 'no Package hard-delete entry point exists' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * H. Woo price authority
	 * ================================================================== */

	private function price_authority_group( int $hall ): void {
		global $wpdb;
		$this->group( 'H. Woo price authority' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();

		// Components carry their own Woo prices, deliberately different from the
		// Package shell's, so a summing bug would be visible.
		$component_a = $this->make_play_product( $hall, $play, 'hCompA', 'paper', 2, null, '7.00' );
		$component_b = $this->make_play_product( $hall, $play, 'hCompB', 'paper', 1, null, '11.00' );

		$shell      = $this->make_product( $hall, 'hShell', '40.00' );
		$created    = $service->create( $shell, [ [ 'bingo_play_product_id' => $component_a, 'quantity' => 2 ], [ 'bingo_play_product_id' => $component_b, 'quantity' => 3 ] ] );
		$this->is_status( 'package_created', $created, 'a Package is created over a priced Woo shell' );
		$package_id = (int) $created->package['package_id'];
		$this->created_packages[ $hall ][] = $package_id;

		$row = $service->getById( $package_id );
		$this->same( '40.00', (string) $row['woo_price']['price'], 'the Package price is READ from the linked WooCommerce product' );
		$this->ok( true === $row['woo_price']['available'], '  and is reported as available' );

		// 2x7 + 3x11 = 47.00 would be the summed value; it must never appear.
		$this->ok( '47.00' !== (string) $row['woo_price']['price'], 'component prices are NOT summed into the Package base price (BR-049)' );

		// Changing the Woo price changes what the Package reports, with no
		// Catalog write at all.
		$updated_at_before = $row['updated_at'];
		$product = wc_get_product( $shell );
		$product->set_regular_price( '55.00' );
		$product->save();
		$after = $service->getById( $package_id );
		$this->same( '55.00', (string) $after['woo_price']['price'], 'a WooCommerce price change is reflected immediately' );
		$this->same( $updated_at_before, $after['updated_at'], '  without touching the authoritative Catalog row' );

		// No price column exists on the authoritative table.
		$columns = array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Migrations::packages_table(), 0 ) );
		$this->same( [], array_values( array_filter( $columns, static fn( string $c ): bool => false !== strpos( $c, 'price' ) ) ), 'bingo_packages holds no price column — no second base-price authority exists' );
		$components_columns = array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Migrations::package_components_table(), 0 ) );
		$this->same( [], array_values( array_filter( $components_columns, static fn( string $c ): bool => false !== strpos( $c, 'price' ) ) ), 'bingo_package_components holds no price column either' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * K. Presentation boundary
	 * ================================================================== */

	/**
	 * The admin screen must agree with the domain: an unrecognised lifecycle
	 * value is never coerced to Archived, and a component whose authoritative
	 * Play Product row does not resolve is never presented as a retainable
	 * archived component.
	 */
	private function presentation_boundary_group( int $hall ): void {
		global $wpdb;
		$this->group( 'K. Presentation boundary' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service  = new PackageService();
		$play     = new BingoPlayProductService();
		$packages = Migrations::packages_table();

		$keep      = $this->make_play_product( $hall, $play, 'kKeep', 'paper', 2 );
		$archived  = $this->make_play_product( $hall, $play, 'kArchived', 'paper', 1 );
		$vanishing = $this->make_play_product( $hall, $play, 'kVanishing', 'paper', 1 );

		/* ---- lifecycle labelling and offered actions ---- */

		$package_id = $this->create_package( $hall, $service, 'kLifecycle', [ [ $keep, 1 ] ] );

		$this->same( 'Active', $this->status_label( 'active' ), 'active is labelled Active' );
		$this->same( 'Archived', $this->status_label( 'archived' ), 'archived is labelled Archived' );
		$this->same( 'Inconsistent state', $this->status_label( 'suspended' ), 'an unrecognised status is labelled Inconsistent state, NOT Archived' );
		$this->same( 'Inconsistent state', $this->status_label( '' ), 'an empty status is labelled Inconsistent state, NOT Archived' );
		$this->ok( true === $this->admin_is_known_status( 'active' ) && true === $this->admin_is_known_status( 'archived' ), 'both real lifecycle states are recognised' );
		$this->ok( false === $this->admin_is_known_status( 'suspended' ), 'an unrecognised status is not treated as a known state' );

		// The rendered screen offers Archive for active, Restore for archived, and
		// NEITHER for an unrecognised value. Every assertion is scoped to THIS
		// Package's own row: the hall holds other Packages whose buttons would
		// otherwise satisfy a whole-page search for the wrong reason.
		$active_row = $this->admin_row( $this->render_admin( $hall ), $package_id );
		$this->ok( false !== strpos( $active_row, 'value="archive"' ), 'an active Package offers Archive' );
		$this->ok( false === strpos( $active_row, 'value="restore"' ), '  and not Restore' );
		$this->ok( false !== strpos( $active_row, 'Edit composition' ), '  and offers Edit composition' );

		$this->is_status( 'package_archived', $service->archive( $package_id ), 'the Package is archived' );
		$archived_row = $this->admin_row( $this->render_admin( $hall ), $package_id );
		$this->ok( false !== strpos( $archived_row, 'value="restore"' ), 'an archived Package offers Restore' );
		$this->ok( false === strpos( $archived_row, 'value="archive"' ), '  and not Archive' );
		$this->ok( false === strpos( $archived_row, 'Edit composition' ), '  and offers no composition edit' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$packages} SET status = 'suspended' WHERE package_id = %d", $package_id ) );
		$broken_row = $this->admin_row( $this->render_admin( $hall ), $package_id );
		$this->ok( '' !== $broken_row, 'the inconsistent Package is still listed' );
		$this->ok( false !== strpos( $broken_row, 'Inconsistent state' ), 'an unrecognised status renders the inconsistent-state warning' );
		$this->ok( false === strpos( $broken_row, 'Archived' ), '  and is NEVER coerced to the Archived label' );
		$this->ok( false !== strpos( $broken_row, 'No lifecycle action is offered' ), '  and directs the Hall Manager to Platform Admin' );
		$this->ok( false === strpos( $broken_row, 'value="restore"' ), '  and offers NO Restore action' );
		$this->ok( false === strpos( $broken_row, 'value="archive"' ), '  and offers NO Archive action' );
		$this->ok( false === strpos( $broken_row, 'Edit composition' ), '  and offers NO composition edit' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$packages} SET status = 'active' WHERE package_id = %d", $package_id ) );

		/* ---- archived vs unresolved components ---- */

		$mixed_id = $this->create_package( $hall, $service, 'kMixed', [ [ $keep, 1 ], [ $archived, 2 ] ] );
		$this->is_status( 'play_product_archived', $play->archive( $archived ), 'one component Bingo Play Product is archived' );

		$mixed = $service->getById( $mixed_id );
		$this->ok( true === $mixed['has_archived_component'], 'an ARCHIVED component is reported as archived' );
		$this->ok( false === $mixed['has_unresolved_component'], '  and NOT as unresolved' );

		$archived_edit = $this->render_admin( $hall, $mixed_id );
		$this->ok( false !== strpos( $archived_edit, 'may be kept at its current quantity or removed' ), 'an archived component keeps the retain-or-remove wording' );
		$this->ok( false !== strpos( $archived_edit, 'Save composition' ), '  and the ordinary save workflow is still offered' );

		// Now break a DIFFERENT Package's dependency for real.
		$broken_id = $this->create_package( $hall, $service, 'kBroken', [ [ $keep, 1 ], [ $vanishing, 3 ] ] );
		$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => $vanishing ], [ '%d' ] );

		$broken = $service->getById( $broken_id );
		$this->ok( true === $broken['has_unresolved_component'], 'an UNRESOLVED component is reported as unresolved' );
		$this->ok( false === $broken['has_archived_component'], '  and NOT as archived' );

		$broken_edit = $this->render_admin( $hall, $broken_id );
		$this->ok( false !== strpos( $broken_edit, 'UNRESOLVED, no authoritative record exists' ), 'an unresolved component is labelled UNRESOLVED' );
		$this->ok( false === strpos( $broken_edit, 'may be kept at its current quantity or removed' ), '  and is NEVER labelled "Archived — may be kept … or removed"' );
		$this->ok( false !== strpos( $broken_edit, 'integrity fault' ), '  the screen names it a Catalog integrity fault' );
		$this->ok( false === strpos( $broken_edit, 'Save composition' ), '  and the ordinary composition-save workflow is withheld entirely' );
		$this->ok( false === strpos( $broken_edit, 'name="bc_package_action" value="replace_components"' ), '  with no replace_components form to submit' );

		// The list view withholds the edit link for the same reason.
		$list = $this->render_admin( $hall );
		$this->ok( false !== strpos( $list, 'INTEGRITY FAULT' ), 'the list view flags the integrity fault' );
		$this->ok( false === strpos( $list, "edit={$broken_id}" ), '  and offers no Edit composition link for that Package' );
		$this->ok( false !== strpos( $list, "edit={$mixed_id}" ), '  while the merely-archived-component Package remains editable' );

		// And the domain agrees: ADR-033 fails closed on it.
		$this->is_error( 'play_product_not_found', $service->replaceComponents( $broken_id, $service->compositionFingerprint( $broken_id ), [ [ 'bingo_play_product_id' => $keep, 'quantity' => 1 ] ] ), 'the domain refuses the repair the screen refused to offer' );

		/* ---- corrupt component medium is an integrity fault, never Paper ---- */

		$corrupt_component = $this->make_play_product( $hall, $play, 'kCorruptMedium', 'paper', 2 );
		$corrupt_pkg       = $this->create_package( $hall, $service, 'kCorruptPkg', [ [ $keep, 1 ], [ $corrupt_component, 2 ] ] );
		$products_table    = Migrations::play_products_table();

		$before_selector = $this->render_admin( $hall );
		$this->ok( false !== strpos( $before_selector, 'faces per unit' ), 'the selector renders normally before corruption' );

		// A RESOLVED row holding a medium that is neither paper nor digital.
		$wpdb->query( $wpdb->prepare( "UPDATE {$products_table} SET medium = 'holographic' WHERE bingo_play_product_id = %d", $corrupt_component ) );

		$corrupt_row = $service->getById( $corrupt_pkg );
		$this->ok( true === $corrupt_row['has_unresolved_component'], 'a corrupt component medium is reported as an integrity fault' );
		$this->ok( false === $corrupt_row['has_archived_component'], '  and NOT as an archived component' );

		$corrupt_edit = $this->render_admin( $hall, $corrupt_pkg );
		$this->ok( false !== strpos( $corrupt_edit, 'INTEGRITY FAULT, unrecognised medium' ), 'a) the corrupt component is labelled an unrecognised-medium integrity fault' );
		$this->ok( false === strpos( $corrupt_edit, 'holographic' ), '  and the raw corrupt value is never rendered' );
		$this->ok( false !== strpos( $corrupt_edit, 'integrity fault' ), 'b) an integrity fault is surfaced to the Hall Manager' );
		$this->ok( false !== strpos( $corrupt_edit, 'Contact Platform Admin' ), '  directing them to Platform Admin' );
		$this->ok( false === strpos( $corrupt_edit, 'Save composition' ), 'c) no composition-save workflow is offered' );
		$this->ok( false === strpos( $corrupt_edit, 'name="bc_package_action" value="replace_components"' ), '  and no replace_components form exists' );

		$corrupt_list = $this->render_admin( $hall );
		$corrupt_list_row = $this->admin_row( $corrupt_list, $corrupt_pkg );
		$this->ok( false !== strpos( $corrupt_list_row, 'INTEGRITY FAULT' ), '  the list view flags it too' );
		$this->ok( false === strpos( $corrupt_list_row, "edit={$corrupt_pkg}" ), '  and withholds its Edit composition link' );

		// d) never offered as a valid NEW component choice.
		$this->ok(
			false === strpos( $corrupt_list, 'components[' . $corrupt_component . '][include]' ),
			'd) a corrupt-medium Bingo Play Product is not offered as a selectable component'
		);
		$this->ok(
			false === strpos( $this->render_admin( $hall, $mixed_id ), 'components[' . $corrupt_component . '][include]' ),
			'  and not on another Package\'s edit form either'
		);

		// The label helper itself never infers Paper from "not digital".
		$this->same( 'Paper', $this->medium_label( 'paper' ), 'paper is labelled Paper' );
		$this->same( 'Digital', $this->medium_label( 'digital' ), 'digital is labelled Digital' );
		$this->same( 'UNRECOGNISED MEDIUM', $this->medium_label( 'holographic' ), 'an unrecognised medium is NEVER labelled Paper' );
		$this->same( 'UNRECOGNISED MEDIUM', $this->medium_label( null ), 'a null medium is NEVER labelled Paper' );
		$this->same( 'UNRECOGNISED MEDIUM', $this->medium_label( '' ), 'an empty medium is NEVER labelled Paper' );

		// Restore the disposable fixture state.
		$wpdb->query( $wpdb->prepare( "UPDATE {$products_table} SET medium = 'paper' WHERE bingo_play_product_id = %d", $corrupt_component ) );
		$this->ok( false === $service->getById( $corrupt_pkg )['has_unresolved_component'], 'the fixture is restored and the fault clears' );
		$this->ok( false !== strpos( $this->render_admin( $hall, $corrupt_pkg ), 'Save composition' ), '  and ordinary composition editing resumes' );

		/* ---- request integers are never absolute-valued ---- */

		$this->same( 5, $this->request_int( '5' ), 'a positive integer string parses as itself' );
		$this->same( -5, $this->request_int( '-5' ), 'a NEGATIVE integer stays negative — never absolute-valued into a valid quantity' );
		$this->same( 0, $this->request_int( 'abc' ), 'a non-numeric value becomes 0, which every validator rejects' );
		$this->same( 0, $this->request_int( '3.7' ), 'a non-integer value becomes 0' );
		$this->same( 0, $this->request_int( null ), 'a missing value becomes 0' );

		$crafted = $this->components_from_request( [
			'components' => [
				(string) $keep => [ 'include' => '1', 'quantity' => '-5' ],
			],
		] );
		$this->same( -5, (int) $crafted[0]['quantity'], 'a crafted negative quantity survives marshalling unchanged' );
		$this->is_error( 'invalid_component_quantity', $service->create( $this->make_product( $hall, 'kNegative' ), $crafted ), '  and is rejected deterministically by the domain' );

		$crafted_id = $this->components_from_request( [
			'components' => [
				'-7' => [ 'include' => '1', 'quantity' => '1' ],
			],
		] );
		$this->same( -7, (int) $crafted_id[0]['bingo_play_product_id'], 'a crafted negative component id survives marshalling unchanged' );
		$this->is_error( 'invalid_component_set', $service->create( $this->make_product( $hall, 'kNegativeId' ), $crafted_id ), '  and is rejected rather than repaired into a different Bingo Play Product' );

		$before_quantity = $this->component_quantity( $mixed_id, $keep );
		$this->is_error( 'invalid_component_quantity', $service->replaceComponents( $mixed_id, $service->compositionFingerprint( $mixed_id ), $crafted ), 'a crafted negative quantity is rejected on edit too' );
		$this->same( $before_quantity, $this->component_quantity( $mixed_id, $keep ), '  and no positive mutation was written' );

		/* ---- fail-visible reads ---- */

		$this->ok( is_wp_error( $this->listing_with_broken_component_table( $hall ) ), 'a failed component read propagates as WP_Error, never as an empty list' );

		restore_current_blog();
	}

	/** Render the admin screen and capture its markup. */
	private function render_admin( int $hall, int $edit = 0 ): string {
		$previous_get    = $_GET;
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		$_GET = [ 'post_type' => 'product', 'page' => 'bingocommerce-packages' ];
		if ( $edit > 0 ) {
			$_GET['edit'] = (string) $edit;
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		\BingoCommerce\Catalog\PackageAdmin::render();
		$markup = (string) ob_get_clean();

		$_GET                      = $previous_get;
		$_SERVER['REQUEST_METHOD'] = $previous_method;

		return $markup;
	}

	/**
	 * Isolate one Package's table row from the rendered listing, so an assertion
	 * about that Package cannot be satisfied by a different Package's markup.
	 */
	private function admin_row( string $markup, int $package_id ): string {
		foreach ( explode( '<tr>', $markup ) as $fragment ) {
			if ( 1 === preg_match( '/<td>#' . $package_id . '<\/td>/', $fragment ) ) {
				return $fragment;
			}
		}

		return '';
	}

	private function medium_label( $medium ): string {
		$method = new ReflectionMethod( \BingoCommerce\Catalog\PackageAdmin::class, 'medium_label' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $medium );
	}

	private function request_int( $value ): int {
		$method = new ReflectionMethod( \BingoCommerce\Catalog\PackageAdmin::class, 'request_int' );
		$method->setAccessible( true );

		return (int) $method->invoke( null, $value );
	}

	/** @param array<string,mixed> $post @return array<int,array<string,int>> */
	private function components_from_request( array $post ): array {
		$method = new ReflectionMethod( \BingoCommerce\Catalog\PackageAdmin::class, 'components_from_request' );
		$method->setAccessible( true );

		return (array) $method->invoke( null, $post );
	}

	private function status_label( string $status ): string {
		$method = new ReflectionMethod( \BingoCommerce\Catalog\PackageAdmin::class, 'status_label' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $status );
	}

	private function admin_is_known_status( string $status ): bool {
		$method = new ReflectionMethod( \BingoCommerce\Catalog\PackageAdmin::class, 'is_known_status' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $status );
	}

	/**
	 * Make the component table temporarily unreadable on the DISPOSABLE hall and
	 * confirm the listing surfaces the failure instead of an empty hall.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function listing_with_broken_component_table( int $hall ) {
		global $wpdb;

		$components = Migrations::package_components_table();
		$this->disposable_ddl( $hall, "RENAME TABLE `{$components}` TO `{$components}_pkgb_probe`" );
		$suppress = $wpdb->suppress_errors( true );
		$result   = ( new PackageService() )->listForAdministration();
		$wpdb->suppress_errors( $suppress );
		$this->disposable_ddl( $hall, "RENAME TABLE `{$components}_pkgb_probe` TO `{$components}`" );
		$wpdb->last_error = '';

		return $result;
	}

	/* ================================================================== *
	 * I. Claim concurrency
	 * ================================================================== */

	private function claim_concurrency_group( int $hall ): void {
		$this->group( 'I. Claim concurrency' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$play  = new BingoPlayProductService();
		$paper = $this->make_play_product( $hall, $play, 'iPaper', 'paper', 2 );
		$other = $this->make_play_product( $hall, $play, 'iOther', 'paper', 1 );
		$contested = $this->make_product( $hall, 'iContested' );
		restore_current_blog();

		// Two Package creates, same Woo product.
		$start   = (int) ( ( microtime( true ) + 1.2 ) * 1000000 );
		$workers = [
			$this->start_package_worker( $hall, $contested, [ [ $paper, 1 ] ], $start ),
			$this->start_package_worker( $hall, $contested, [ [ $other, 2 ] ], $start ),
		];
		$outcomes = [ $this->finish_worker( $workers[0] ), $this->finish_worker( $workers[1] ) ];

		$winners = array_values( array_filter( $outcomes, static fn( string $o ): bool => 0 === strpos( $o, 'package_created' ) ) );
		$this->same( 1, count( $winners ), 'two Package creates against one Woo product produce exactly ONE winner' );
		foreach ( $outcomes as $index => $outcome ) {
			$this->ok(
				0 === strpos( $outcome, 'package_created' ) || in_array( $outcome, [ 'package_already_linked', 'catalog_write_conflict' ], true ),
				"  writer {$index} ended in a recognised state ({$outcome})"
			);
		}

		switch_to_blog( $hall );
		$this->same( 1, $this->package_claim_count( $contested ), '  and exactly one authoritative Package row claims that product' );
		$this->same( 0, $this->play_claim_count( $contested ), '  with no Bingo Play Product row claiming it' );
		$this->track_package_for_product( $hall, $contested );
		restore_current_blog();
		$this->note( 'two Package creates, same product: ' . implode( ' | ', $outcomes ) );

		// Package create vs Play Product create, same Woo product.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$rival = $this->make_product( $hall, 'iRival' );
		restore_current_blog();

		$start   = (int) ( ( microtime( true ) + 1.2 ) * 1000000 );
		$package = $this->start_package_worker( $hall, $rival, [ [ $paper, 1 ] ], $start );
		$play_worker = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-create-worker.php', (string) $hall, (string) $this->super_admin_id, (string) $rival, 'paper', '2', '0', sprintf( '%.6f', $start / 1000000 ) ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$play_pipes
		);
		$package_outcome = $this->finish_worker( $package );
		$play_raw = trim( (string) stream_get_contents( $play_pipes[1] ) );
		stream_get_contents( $play_pipes[2] );
		fclose( $play_pipes[1] );
		fclose( $play_pipes[2] );
		proc_close( $play_worker );
		$play_lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $play_raw ) ) ) );
		$play_outcome = json_decode( (string) end( $play_lines ), true );
		$play_status  = (string) ( $play_outcome['status'] ?? $play_outcome['error'] ?? '?' );

		switch_to_blog( $hall );
		$package_claims = $this->package_claim_count( $rival );
		$play_claims    = $this->play_claim_count( $rival );
		$this->track_package_for_product( $hall, $rival );
		$this->track_play_product_for_product( $hall, $rival );
		restore_current_blog();

		$this->same( 1, $package_claims + $play_claims, 'Package create vs Play Product create: exactly ONE claim exists' );
		$this->ok( 0 === $package_claims || 0 === $play_claims, '  never a dual claim on the same WooCommerce product' );
		$this->note( "package create vs play product create: package={$package_outcome} play={$play_status} (package_claims={$package_claims}, play_claims={$play_claims})" );

		// Conflicting composition writes preserve ADR-033 serialization/staleness.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service   = new PackageService();
		$shared    = $this->create_package( $hall, $service, 'iShared', [ [ $paper, 1 ] ] );
		$stale_fp  = $service->compositionFingerprint( $shared );
		$this->is_status( 'package_composition_updated', $service->replaceComponents( $shared, $stale_fp, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 2 ] ] ), 'the first composition writer commits' );
		$this->is_error( 'package_composition_stale', $service->replaceComponents( $shared, $stale_fp, [ [ 'bingo_play_product_id' => $other, 'quantity' => 1 ] ] ), 'the second writer holding the older fingerprint is rejected, not silently overwritten' );
		$this->same( 2, $this->component_quantity( $shared, $paper ), '  and the first writer\'s edit survives' );
		restore_current_blog();
	}

	/* ================================================================== *
	 * J. Failure contract
	 * ================================================================== */

	private function failure_contract_group( int $hall ): void {
		global $wpdb;
		$this->group( 'J. Failure contract' );

		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$paper   = $this->make_play_product( $hall, $play, 'jPaper', 'paper', 2 );

		// Shared guards: the Package-create boundary honours both.
		$this->set_flag( 'active', true );
		$this->is_error( 'catalog_transport_nested_use', $service->create( $this->make_product( $hall, 'jNested' ), [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'Package create is refused while another Catalog boundary is active' );
		$this->set_flag( 'active', false );

		$this->set_flag( 'untrusted', true );
		$this->is_error( 'catalog_transaction_state_untrusted', $service->create( $this->make_product( $hall, 'jPoisoned' ), [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] ), 'Package create is refused on a poisoned session' );
		$this->set_flag( 'untrusted', false );

		// Bounded retry: a held Woo anchor exhausts the budget rather than waiting.
		$contended = $this->make_product( $hall, 'jContended' );
		restore_current_blog();
		$holder = $this->hold_posts_row( $hall, $contended, 2500 );
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$began    = microtime( true );
		$blocked  = $service->create( $contended, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] );
		$elapsed  = microtime( true ) - $began;
		restore_current_blog();
		$this->release_holder( $holder );
		$this->is_error( 'catalog_write_conflict', $blocked, 'a held Woo anchor exhausts the bounded retry budget' );
		$this->ok( $elapsed < 5.0, sprintf( '  bounded by NOWAIT + retry budget (%.3fs)', $elapsed ) );
		$this->same( [ 1205, 1213, 3572 ], ( new ReflectionClass( CatalogTransactionTransport::class ) )->getConstant( 'LOCK_CONFLICT_ERRNOS' ), '  over exactly errnos 1205, 1213 and 3572' );

		// Failed ROLLBACK poisons the shared session and is never retried.
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );

		// Every fixture is built BEFORE the seam is installed. Creating a Play
		// Product runs a full ADR-032 transaction of its own, which would
		// otherwise be counted against the seam's BEGIN tally.
		$rollback_component = $this->newly_archived( $hall, $play );
		$rollback_shell     = $this->make_product( $hall, 'jRollback' );

		$saved = $wpdb->dbh;
		$seam  = new BcStageBRollbackFailureConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$this->warm( $seam );
		$seam->begin_count = 0;
		// An archived component makes the sequence fail INSIDE the transaction.
		$poisoned = ( new PackageService() )->create( $rollback_shell, [ [ 'bingo_play_product_id' => $rollback_component, 'quantity' => 1 ] ] );
		$begins   = $seam->begin_count;
		$wpdb->dbh = $saved;
		$seam->close();
		$this->is_error( 'catalog_transaction_state_untrusted', $poisoned, 'a failed ROLLBACK returns catalog_transaction_state_untrusted' );
		$this->same( 1, $begins, '  exactly ONE transaction was begun — no retry after a failed rollback' );
		$this->same( true, $this->transport_untrusted(), '  and the shared session flag is poisoned' );
		$this->set_flag( 'untrusted', false );

		// Ambiguous COMMIT: committed, never reported as success, never audited,
		// never projected, never retried.
		$ambiguous_shell = $this->make_product( $hall, 'jAmbiguous' );
		$audit_before    = $this->audit_count( $hall, 'bingo_package' );
		$saved = $wpdb->dbh;
		$seam  = new BcStageBCommittedUnknownConnection( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$seam->set_charset( 'utf8mb4' );
		$wpdb->dbh = $seam;
		$this->warm( $seam );
		$began     = microtime( true );
		$ambiguous = ( new PackageService() )->create( $ambiguous_shell, [ [ 'bingo_play_product_id' => $paper, 'quantity' => 1 ] ] );
		$elapsed   = microtime( true ) - $began;
		$wpdb->dbh = $saved;
		$seam->close();

		$this->is_error( 'catalog_commit_outcome_unknown', $ambiguous, 'an unclean COMMIT acknowledgement returns catalog_commit_outcome_unknown' );
		$this->ok( $elapsed < 1.0, sprintf( '  and is not retried (%.3fs)', $elapsed ) );
		$this->same( 1, $this->package_claim_count( $ambiguous_shell ), '  the transaction really committed' );
		$this->same( $audit_before, $this->audit_count( $hall, 'bingo_package' ), '  and NO audit event was emitted from inferred state' );
		$this->same( '', (string) get_post_meta( $ambiguous_shell, PackageService::PRODUCT_KIND_META, true ), '  and NO BR-065 projection was written from inferred state' );
		$this->set_flag( 'untrusted', false );
		$this->track_package_for_product( $hall, $ambiguous_shell );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

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

	private function make_play_product( int $site_id, BingoPlayProductService $service, string $label, string $medium, int $faces, ?int $game_type = null, string $price = '5.00' ): int {
		$result = $service->create( $this->make_product( $site_id, $label, $price ), $medium, $faces, $game_type );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Play Product creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}
		$id = (int) $result->play_product['bingo_play_product_id'];
		$this->created_play_products[ $site_id ][] = $id;

		return $id;
	}

	private function newly_archived( int $site_id, BingoPlayProductService $service ): int {
		$id = $this->make_play_product( $site_id, $service, 'archivedFixture', 'paper', 1 );
		$service->archive( $id );

		return $id;
	}

	/** @param array<int,array{0:int,1:int}> $pairs */
	private function create_package( int $site_id, PackageService $service, string $label, array $pairs ): int {
		$components = [];
		foreach ( $pairs as $pair ) {
			$components[] = [ 'bingo_play_product_id' => (int) $pair[0], 'quantity' => (int) $pair[1] ];
		}

		$result = $service->create( $this->make_product( $site_id, $label ), $components );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Package creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}

		$id = (int) $result->package['package_id'];
		$this->created_packages[ $site_id ][] = $id;

		return $id;
	}

	/* ================================================================== *
	 * Reads
	 * ================================================================== */

	private function package_type( int $package_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT package_type FROM ' . Migrations::packages_table() . ' WHERE package_id = %d', $package_id ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function component_rows( int $package_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT package_component_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d ORDER BY bingo_play_product_id', $package_id ),
			ARRAY_A
		);
	}

	/** @param array<string,mixed> $package @return array<int,array<string,int>> */
	private function component_rows_of( array $package ): array {
		$rows = [];
		foreach ( $package['components'] as $component ) {
			$rows[] = [
				'bingo_play_product_id' => (int) $component['bingo_play_product_id'],
				'quantity'              => (int) $component['quantity'],
			];
		}

		return $rows;
	}

	private function component_quantity( int $package_id, int $play_product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT quantity FROM ' . Migrations::package_components_table() . ' WHERE package_id = %d AND bingo_play_product_id = %d', $package_id, $play_product_id ) );
	}

	private function package_claim_count( int $wc_product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', $wc_product_id ) );
	}

	private function play_claim_count( int $wc_product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $wc_product_id ) );
	}

	private function orphan_component_count( int $site_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Migrations::package_components_table() . ' c'
			. ' LEFT JOIN ' . Migrations::packages_table() . ' p ON p.package_id = c.package_id'
			. ' WHERE p.package_id IS NULL'
		);
	}

	private function componentless_package_count( int $site_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Migrations::packages_table() . ' p'
			. ' LEFT JOIN ' . Migrations::package_components_table() . ' c ON c.package_id = p.package_id'
			. ' WHERE c.package_id IS NULL'
		);
	}

	private function audit_count( int $site_id, string $target_type ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND target_type = %s',
				$site_id,
				$target_type
			)
		);
	}

	private function last_audit_action( int $site_id, int $package_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT action FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d AND target_type = %s AND target_id = %s ORDER BY id DESC LIMIT 1',
				$site_id,
				'bingo_package',
				(string) $package_id
			)
		);
	}

	private function track_package_for_product( int $site_id, int $wc_product_id ): void {
		global $wpdb;

		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT package_id FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', $wc_product_id ) );
		if ( $id > 0 ) {
			$this->created_packages[ $site_id ][] = $id;
		}
	}

	private function track_play_product_for_product( int $site_id, int $wc_product_id ): void {
		global $wpdb;

		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT bingo_play_product_id FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $wc_product_id ) );
		if ( $id > 0 ) {
			$this->created_play_products[ $site_id ][] = $id;
		}
	}

	/* ================================================================== *
	 * Workers
	 * ================================================================== */

	/** @param array<int,array{0:int,1:int}> $pairs @return array{0:mixed,1:array} */
	private function start_package_worker( int $site_id, int $wc_product_id, array $pairs, int $start_at ): array {
		$components = [];
		foreach ( $pairs as $pair ) {
			$components[] = (int) $pair[0] . ':' . (int) $pair[1];
		}

		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/package-create-worker.php', (string) $site_id, (string) $this->super_admin_id, (string) $wc_product_id, implode( ',', $components ), (string) $start_at ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$ready = fgets( $pipes[1] );
		$this->ok( is_string( $ready ) && 0 === strpos( $ready, 'READY' ), 'Package create worker booted' );

		return [ $handle, $pipes ];
	}

	/** @param array{0:mixed,1:array} $worker */
	private function finish_worker( array $worker ): string {
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

	/** @return array{0:mixed,1:array} */
	private function hold_posts_row( int $site_id, int $wc_product_id, int $hold_ms ): array {
		$handle = proc_open(
			[ PHP_BINARY, __DIR__ . '/play-product-row-lock-worker.php', (string) $site_id, 'posts', (string) $wc_product_id, (string) $hold_ms ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$ready = fgets( $pipes[1] );
		$this->ok( is_string( $ready ) && '' !== trim( (string) $ready ), 'the Woo anchor row lock is held by another process' );

		return [ $handle, $pipes ];
	}

	/** @param array{0:mixed,1:array} $holder */
	private function release_holder( array $holder ): void {
		[ $handle, $pipes ] = $holder;
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $handle );
	}

	/* ================================================================== *
	 * Disposable infrastructure
	 * ================================================================== */

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'pkgb' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'PKGB Disposable Hall',
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

	/**
	 * The single gate that keeps every structural mutation off a persistent hall.
	 * A crash at any point must leave Sunrise, Tampa and Orlando structurally
	 * exactly as they were.
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

	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
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
		// ALL FOUR hall-local Catalog v3 tables. WordPress does not know these
		// custom tables, so wp_delete_site() leaves every one of them behind.
		$catalog_tables = [
			Migrations::package_components_table(),
			Migrations::packages_table(),
			Migrations::play_products_table(),
			Migrations::game_types_table(),
		];
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CoreMigrations::audit_log_table() . ' WHERE blog_id = %d', $site_id ) );
		restore_current_blog();

		wp_delete_site( $site_id );

		foreach ( $catalog_tables as $table ) {
			// Exact, site-scoped identifier. Anything else is refused.
			if ( 1 === preg_match( '/^bc_' . $site_id . '_bingo_(package_components|packages|play_products|game_types)$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		}

		$this->disposable_catalog_tables[ $site_id ] = $catalog_tables;
		unset( $this->disposable_sites[ $site_id ] );
		unset( $this->created_products[ $site_id ], $this->created_game_types[ $site_id ], $this->created_play_products[ $site_id ], $this->created_packages[ $site_id ] );
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

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->destroy_disposable_site( (int) $site_id );
		}

		// A domain shape is NOT ownership proof. An interrupted earlier run may
		// have left a hall whose domain looks like this suite's; it is reported
		// and left alone, because nothing durable proves this process created it.
		$leftovers = [];
		foreach ( (array) $wpdb->get_results( "SELECT blog_id, domain FROM {$wpdb->blogs} WHERE domain LIKE 'pkgb%'", ARRAY_A ) as $row ) {
			$leftovers[] = '#' . (int) $row['blog_id'] . ' ' . (string) $row['domain'];
		}
		if ( [] !== $leftovers ) {
			$this->note( sprintf(
				'%d hall(s) with this suite\'s domain shape were NOT created by this run and were LEFT UNTOUCHED; they need deliberate cleanup: %s',
				count( $leftovers ),
				implode( ', ', $leftovers )
			) );
		}

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );

			foreach ( $this->created_packages[ $site_id ] ?? [] as $package_id ) {
				$wpdb->delete( Migrations::package_components_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_package', 'target_id' => (string) (int) $package_id ], [ '%d', '%s', '%s' ] );
				$wpdb->delete( Migrations::packages_table(), [ 'package_id' => (int) $package_id ], [ '%d' ] );
			}

			// Any Play Product row attached to a product this run created is ours,
			// whether or not its id was tracked.
			$owned = $this->created_play_products[ $site_id ] ?? [];
			foreach ( $this->created_products[ $site_id ] ?? [] as $product_id ) {
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT bingo_play_product_id FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', (int) $product_id ) ) as $attached ) {
					$owned[] = (int) $attached;
				}
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT package_id FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', (int) $product_id ) ) as $attached_package ) {
					$wpdb->delete( Migrations::package_components_table(), [ 'package_id' => (int) $attached_package ], [ '%d' ] );
					$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site_id, 'target_type' => 'bingo_package', 'target_id' => (string) (int) $attached_package ], [ '%d', '%s', '%s' ] );
					$wpdb->delete( Migrations::packages_table(), [ 'package_id' => (int) $attached_package ], [ '%d' ] );
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

			// Products are deleted ONLY by the durable ownership marker this run
			// stamped, which also covers every exact id it tracked. Nothing is
			// deleted because it appeared after a baseline snapshot: existence
			// timing is not ownership, and an incidental WordPress-minted
			// auto-draft is not this suite's to remove.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s", self::OWNER_META ) ) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
			$this->report_unowned_auto_drafts( $site_id );
			restore_current_blog();
		}

		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->same( Migrations::DB_VERSION, (int) get_option( Migrations::VERSION_OPTION, 0 ), "hall {$site_id} remains at the current Catalog version" );
			$this->ok( true === Migrations::verify_current_site_schema(), "hall {$site_id} schema verifies after cleanup" );
			$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key = %s", self::OWNER_META ) ), "hall {$site_id}: no marker-owned products remain" );
			// PRESERVATION, not emptiness. Pre-existing Package data — including
			// anything a Hall Manager created by hand — must come through this
			// suite byte-identical.
			$snapshot = $this->package_snapshot( $site_id );
			$this->same( $this->baseline_packages[ $site_id ]['packages'], $snapshot['packages'], "hall {$site_id}: the authoritative bingo_packages baseline is unchanged" );
			$this->same( $this->baseline_packages[ $site_id ]['components'], $snapshot['components'], "hall {$site_id}: the authoritative bingo_package_components baseline is unchanged" );
			restore_current_blog();
		}

		$this->same( [], $this->disposable_sites, 'every disposable hall this run created was destroyed' );
		$this->same(
			[],
			array_intersect( array_keys( $this->disposable_catalog_tables ), [ 1, self::SUNRISE, self::TAMPA, self::ORLANDO ] ),
			'no persistent hall was ever registered as disposable'
		);

		foreach ( $this->disposable_catalog_tables as $site_id => $tables ) {
			$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ), "disposable hall {$site_id}: the site record was removed" );
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( 'bc_' . (int) $site_id . '_bingo_' ) . '%' ) ),
				"disposable hall {$site_id}: no hall-local Catalog table survives"
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

		foreach ( array_keys( $this->disposable_catalog_tables ) as $site_id ) {
			$this->same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", (int) $site_id ) ),
				"disposable hall {$site_id} created by THIS run no longer exists"
			);
		}
		$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = %s AND trigger_name LIKE %s', DB_NAME, 'bc_pkgb_%' ) ), 'no Stage B test trigger remains anywhere' );
	}

	/* ================================================================== *
	 * Support
	 * ================================================================== */

	/**
	 * Recorded for REPORTING only. This suite never deletes a product because it
	 * appeared after a baseline snapshot — existence timing is not ownership.
	 */
	private function record_baseline_auto_drafts(): void {
		global $wpdb;
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			switch_to_blog( $site_id );
			$this->baseline_auto_drafts[ $site_id ] = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" ) );
			restore_current_blog();
		}
	}

	private function record_baseline_packages(): void {
		foreach ( [ self::SUNRISE, self::TAMPA, self::ORLANDO ] as $site_id ) {
			$this->baseline_packages[ $site_id ] = $this->package_snapshot( $site_id );
		}
	}

	/**
	 * A deterministic canonical snapshot, ordered by primary key, of both
	 * authoritative Package tables for one hall.
	 *
	 * @return array{packages:array<int,array<string,mixed>>,components:array<int,array<string,mixed>>}
	 */
	private function package_snapshot( int $site_id ): array {
		global $wpdb;

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		$snapshot = [
			'packages'   => (array) $wpdb->get_results(
				'SELECT package_id, wc_product_id, package_type, status, created_at, updated_at, archived_at FROM '
				. Migrations::packages_table() . ' ORDER BY package_id',
				ARRAY_A
			),
			'components' => (array) $wpdb->get_results(
				'SELECT package_component_id, package_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at FROM '
				. Migrations::package_components_table() . ' ORDER BY package_component_id',
				ARRAY_A
			),
		];

		if ( $switched ) {
			restore_current_blog();
		}

		return $snapshot;
	}

	/** Report, never delete, auto-drafts that appeared without this suite's marker. */
	private function report_unowned_auto_drafts( int $site_id ): void {
		global $wpdb;

		if ( ! isset( $this->baseline_auto_drafts[ $site_id ] ) ) {
			return;
		}

		$current = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_status = 'auto-draft'" ) );
		$new     = array_values( array_diff( $current, $this->baseline_auto_drafts[ $site_id ] ) );
		if ( [] === $new ) {
			return;
		}

		$this->note( sprintf(
			'site %d: %d auto-draft product(s) appeared during this run but carry NO ownership marker. They were LEFT UNTOUCHED: %s',
			$site_id,
			count( $new ),
			implode( ', ', array_map( static fn( $id ) => '#' . (int) $id, $new ) )
		) );
	}

	/**
	 * A brand-new mysqli connection has no performance_schema transaction row at
	 * all, so the transport's pre-BEGIN state probe correctly refuses it. One
	 * committed transaction gives the session the COMMITTED state every real
	 * request's connection already has.
	 */
	private function warm( mysqli $connection ): void {
		$connection->begin_transaction();
		$connection->commit();
	}

	private function transport_untrusted(): bool {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, 'untrusted' );
		$reflection->setAccessible( true );

		return (bool) $reflection->getValue();
	}

	private function set_flag( string $property, bool $value ): void {
		$reflection = new ReflectionProperty( CatalogTransactionTransport::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( null, $value );
	}

	private function user_with_role( int $site_id, string $role ): int {
		switch_to_blog( $site_id );
		$users = get_users( [ 'blog_id' => $site_id, 'role' => $role, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
		restore_current_blog();

		return isset( $users[0] ) ? (int) $users[0] : 0;
	}

	private function ordinary_user(): int {
		$users = get_users( [ 'blog_id' => 0, 'number' => 50, 'fields' => 'ID', 'orderby' => 'ID' ] );
		foreach ( $users as $id ) {
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
		$actual = is_wp_error( $result ) ? $result->get_error_code() : ( 'RESULT:' . $this->status_of( $result ) );
		$this->same( $expected, $actual, $message );
	}

	private function is_status( string $expected, $result, string $message ): void {
		$actual = is_wp_error( $result ) ? 'ERROR:' . $result->get_error_code() : $this->status_of( $result );
		$this->same( $expected, $actual, $message );
	}

	/**
	 * This suite drives PackageService AND the BingoPlayProductService fixtures
	 * it depends on, so assertions accept either domain result object.
	 */
	private function status_of( $result ): string {
		if ( $result instanceof PackageResult || $result instanceof PlayProductResult ) {
			return $result->status;
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

( new PackageStageBTest() )->run();
