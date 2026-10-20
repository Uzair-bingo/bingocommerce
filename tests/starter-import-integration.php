<?php

declare(strict_types=1);

/**
 * ADR-049 stage 6, slice 6.1: a Hall made by the real Enable Ecommerce path
 * imports the starter products, and a real suspension pauses the import.
 *
 * Run: BC_TEST_DB=bingocommerce_state_provisioning php tests/starter-import-integration.php
 *
 * Halls are created through the real ADR-042 machine endpoint (Application
 * Password proof, the orchestrator, the platform filter, the Portal pull with
 * only the snapshot answered by the fixture, readiness and Commerce state).
 * Proves:
 *   - Enable creates no starter import tables, and still succeeds (with a
 *     ready store) while another Hall's starter import tables are broken;
 *   - the new Hall's dashboard offers the starter products; its Hall Manager
 *     imports them through the page's own admin-ajax handler to completion
 *     (drafts, variations, images in the new Hall's uploads, no Bingo Catalog
 *     rows); the notice then goes; readiness and access are unchanged;
 *   - a Portal SSO Hall Manager account (portal-<installation>-<number>),
 *     created by the real SSO provisioning service, imports the same way;
 *   - Disable Ecommerce part-way through pauses everything for the Hall
 *     Manager and a Super Admin alike (page, Start, Continue, Resolve,
 *     notice) and changes nothing; Re-enable reuses the same site and
 *     Continue finishes with no duplicates.
 *
 * The provisioning fixture is left exactly as found: its seeded sites, its
 * network tables and network settings, and only the WooCommerce sessions that
 * were already there.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_provisioning' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: run from the CLI with BC_TEST_DB=bingocommerce_state_provisioning.\n" );
	exit( 1 );
}

$bc_starter_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-integration-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_starter_dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary starter image folder.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_starter_dir );

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

// Disposable Portal configuration, as in the provisioning suite: an
// unroutable host and a token that exists only in this process.
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'http://portal.fixture.invalid' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'fixture-disposable-token-0000000000000000' );

// The shared bootstrap seeds a fourth site, which this fixture must not gain,
// so the fixture is booted directly, as the provisioning suite does.
require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

foreach (
	[
		[ '\BingoCommerce\Core\Plugin', 'bingocommerce-core/bingocommerce-core.php' ],
		[ '\BingoCommerce\Catalog\Plugin', 'bingocommerce-catalog/bingocommerce-catalog.php' ],
		[ '\BingoCommerce\Sessions\Plugin', 'bingocommerce-sessions/bingocommerce-sessions.php' ],
	] as $bc_plugin
) {
	if ( ! class_exists( $bc_plugin[0] ) ) {
		require_once ABSPATH . 'wp-content/plugins/' . $bc_plugin[1];
	}
	// `plugins_loaded` has already fired; this is the same registration
	// production makes, at the only moment still available.
	if ( is_callable( [ $bc_plugin[0], 'init' ] ) ) {
		call_user_func( [ $bc_plugin[0], 'init' ] );
	}
}
require_once ABSPATH . 'wp-content/plugins/bingocommerce-platform/bingocommerce-platform.php';

use BingoCommerce\Catalog\Migrations as CatalogSchema;
use BingoCommerce\Catalog\StarterCatalogStorage;
use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImportAdmin as Admin;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportNotice as Notice;
use BingoCommerce\Catalog\StarterImportRepository as Repo;
use BingoCommerce\Core\HallStaffIdentity;
use BingoCommerce\Core\Migrations as CoreMigrations;
use BingoCommerce\Platform\Plugin as PlatformPlugin;
use BingoCommerce\PortalIntegration\HallCommerceAccess;
use BingoCommerce\PortalIntegration\Migrations as MappingMigrations;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;
use BingoCommerce\PortalIntegration\ProvisioningRestController as Controller;
use BingoCommerce\PortalIntegration\SetupReadiness;
use BingoCommerce\PortalIntegration\SsoMigrations;
use BingoCommerce\PortalIntegration\SsoProvisioningService;

final class StarterImportIntegrationTest {
	/** Portal Hall identities this run owns; unlike the provisioning suite's. */
	private const HALL_BASE = 971000;

	private const AVAILABLE = 'Bingo Innovation starter products are available for your store. You can import them once, as draft products.';
	private const PAUSED    = 'This Hall is not open right now, so importing is paused.';

	private int $passed = 0;
	private int $failed = 0;
	private int $admin  = 0;
	private int $machine = 0;
	private int $next_hall = 0;
	private int $next_source = 6000;
	private int $wc_session_high_water = 0;

	private bool $owns_admin               = false;
	private bool $owns_core_schema         = false;
	private bool $owns_mapping_schema      = false;
	private bool $owns_provisioning_schema = false;
	private bool $owns_sso_schema          = false;
	private bool $owns_starter_network     = false;
	private $original_site_admins          = null;

	private array $sites     = [];
	private array $users     = [];
	private array $snapshots = [];
	private array $network_before = [];

	public function run( string $starter_dir ): int {
		$this->isolated();
		$this->clean_start();

		try {
			$this->prepare();
			$this->seed_version();
			$second = $this->new_hall_imports();
			$this->sso_manager_imports( $second );
			$this->suspension();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			$this->teardown( $starter_dir );
		}

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * A new Hall imports; Enable does not depend on the importer
	 * ------------------------------------------------------------------ */

	/** @return array{0:int,1:int} The second Hall: [Portal Hall ID, blog ID]. */
	private function new_hall_imports(): array {
		global $wpdb;

		[ , $first ] = $this->enable( 'startera', 'Starter Hall A' );
		$this->same( [ [], false ], [ $this->starter_tables( $first ), get_blog_option( $first, StarterSchema::HALL_VERSION_OPTION ) ], 'Enable creates no starter import tables or schema version' );
		$this->same( [ true, HallCommerceAccess::ALLOW ], [ $this->ready( $first ), HallCommerceAccess::evaluate( $first )['access'] ], 'the new Hall is ready and allowed' );

		$manager = $this->hall_manager( 'mgr-a', $first );
		$this->same( self::AVAILABLE, $this->notice( $first, $manager ), 'the new Hall\'s dashboard offers the starter products to its Hall Manager' );
		$this->same( 'not_started', $this->page( $first, $manager )['kind'], 'Products → Starter Products offers Import' );

		$final = $this->import_to_end( $first, $manager );
		$this->same( 'complete', $final[0], 'the Hall Manager imports to completion through the page\'s handler: ' . $final[1] );
		$this->same( [ 2, 3, 4, 4 ], [ $this->count_posts( $first, 'product' ), $this->count_posts( $first, 'product_variation' ), $this->count_imported_media( $first ), $this->count_original_files( $first ) ], 'two products, three variations and four images, each image file once in the new Hall\'s uploads' );
		$this->same( [ 'draft' ], $this->in_site( $first, $manager, static fn() => $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_status FROM %i WHERE post_type = 'product'", $wpdb->posts ) ) ), 'every imported product is a Draft' );
		$this->same( [ 0, 0 ], $this->in_site( $first, $manager, static fn() => [ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', CatalogSchema::play_products_table() ) ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', CatalogSchema::packages_table() ) ) ] ), 'no Bingo Play Product or Package rows' );
		$this->same( null, $this->notice( $first, $manager ), 'the dashboard notice is gone once the import finished' );
		$this->same( [ true, HallCommerceAccess::ALLOW ], [ $this->ready( $first ), HallCommerceAccess::evaluate( $first )['access'] ], 'readiness and access are unchanged by the import' );

		// Break the first Hall's import storage, then enable another Hall.
		$this->in_site( $first, $this->admin, static fn() => $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', StarterSchema::import_products_table() ) ) );
		$this->same( 'unavailable', $this->page( $first, $manager )['kind'], 'the broken Hall\'s page reports the problem instead of importing' );
		$this->same( true, $this->ready( $first ), 'the broken import storage does not affect the Hall\'s readiness' );

		$second = $this->enable( 'starterb', 'Starter Hall B' );
		$this->same( [ true, [], HallCommerceAccess::ALLOW ], [ $this->ready( $second[1] ), $this->starter_tables( $second[1] ), HallCommerceAccess::evaluate( $second[1] )['access'] ], 'Enable still gives a ready, allowed store while another Hall\'s import storage is broken' );

		return $second;
	}

	/* ------------------------------------------------------------------ *
	 * A Portal SSO Hall Manager imports
	 * ------------------------------------------------------------------ */

	private function sso_manager_imports( array $second ): void {
		[ $hall, $blog ] = $second;

		$this->owns_sso_schema = ! $this->table_exists( SsoMigrations::links_table() );
		$this->same( true, true === SsoMigrations::migrate(), 'the Portal SSO schema is installed' );

		$identity = [
			'installation_id'        => 'starter6',
			'portal_user_id'         => 4101,
			'portal_user_created_at' => '2026-10-20T09:00:00Z',
			'portal_hall_id'         => $hall,
			'name'                   => 'Hall Super Admin',
			'email'                  => 'starter6-4101@example.invalid',
			'sso_reference'          => wp_generate_uuid4(),
		];
		switch_to_blog( $blog );
		try {
			$account = ( new SsoProvisioningService() )->provision( $identity, $blog, $hall, $identity['sso_reference'] );
		} finally {
			restore_current_blog();
		}
		$this->same( true, is_int( $account ) && $account > 0, 'the SSO service creates the Portal Hall Manager account' );
		if ( ! is_int( $account ) || $account < 1 ) {
			return;
		}
		$this->users[ $account ] = true;
		$login                   = (string) get_userdata( $account )->user_login;
		$this->same( [ 'portal-starter6-4101', true, true ], [ $login, HallStaffIdentity::is_portal_sso_username( $login ), $this->in_site( $blog, $account, static fn() => current_user_can( 'bc_manage_products' ) && ! is_super_admin() ) ], 'it is a portal-<installation>-<number> Hall Manager, not a Super Admin' );

		$this->same( self::AVAILABLE, $this->notice( $blog, $account ), 'the SSO Hall Manager sees the starter products notice' );
		$final = $this->import_to_end( $blog, $account );
		$this->same( [ 'complete', 2, 3, 4 ], [ $final[0], $this->count_posts( $blog, 'product' ), $this->count_posts( $blog, 'product_variation' ), $this->count_imported_media( $blog ) ], 'the SSO Hall Manager imports to completion' );
	}

	/* ------------------------------------------------------------------ *
	 * Disable Ecommerce part-way through, then Re-enable
	 * ------------------------------------------------------------------ */

	private function suspension(): void {
		global $wpdb;

		[ $hall, $blog ] = $this->enable( 'starterc', 'Starter Hall C' );
		$manager         = $this->hall_manager( 'mgr-c', $blog );

		$this->same( 'importing', $this->ajax( $blog, $manager, [ 'step' => 'start' ] )[0], 'the Hall Manager starts the import' );
		$this->in_site( $blog, $manager, static fn() => ( new StarterImporter() )->process_next( 0.0 ) );
		$this->in_site( $blog, $manager, static fn() => ( new StarterImporter() )->process_next( 0.0 ) );
		$before = $this->import_snapshot( $blog );
		$this->same( true, $before['posts'] > 0 && 'importing' === $before['receipt'], 'the import is part-way through' );

		[ $status, $body ] = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 2, 'desired_state' => 'suspended', 'site_slug' => null, 'hall_name' => null ] ) );
		$this->same( [ 200, 'applied', 'suspended', $blog ], [ $status, $body['outcome'] ?? null, $body['commerce_state'] ?? null, (int) ( $body['blog_id'] ?? 0 ) ], 'Disable Ecommerce is applied to the same site' );
		$this->same( HallCommerceAccess::BLOCK, HallCommerceAccess::evaluate( $blog )['access'], 'the Hall is now blocked' );

		foreach ( [ 'Hall Manager' => $manager, 'Super Admin' => $this->admin ] as $who => $user ) {
			$page = $this->page( $blog, $user );
			$this->same( [ 'unavailable', self::PAUSED ], [ $page['kind'], $page['text'] ], "suspended: the page says importing is paused ({$who})" );
			$this->same( [ 'error', 'starter_import_unavailable' ], $this->ajax( $blog, $user, [ 'step' => 'continue' ] ), "suspended: Continue is refused ({$who})" );
			$this->same( [ 'error', 'starter_import_unavailable' ], $this->ajax( $blog, $user, [ 'step' => 'start' ] ), "suspended: Start is refused ({$who})" );
		}
		$this->code( 'starter_import_unavailable', $this->in_site( $blog, $this->admin, static fn() => ( new StarterImporter() )->resolution() ), 'suspended: Resolve is refused, Super Admins included' );
		$this->same( null, $this->notice( $blog, $manager ), 'suspended: no dashboard notice' );
		$this->same( $before, $this->import_snapshot( $blog ), 'suspended: nothing in the store or the import changed' );

		$this->arm_snapshot( $hall );
		[ $status, $body ] = $this->dispatch( $hall, $this->payload( [ 'intent_sequence' => 3, 'site_slug' => null, 'hall_name' => null ] ) );
		$this->same( [ 200, 'applied', 'operational', $blog ], [ $status, $body['outcome'] ?? null, $body['commerce_state'] ?? null, (int) ( $body['blog_id'] ?? 0 ) ], 'Re-enable is applied and reuses the same site' );
		$this->same( 'importing', $this->page( $blog, $manager )['kind'], 're-enabled: the page offers Continue Import' );

		$final = $this->import_to_end( $blog, $manager, false );
		$this->same( 'complete', $final[0], 're-enabled: Continue finishes the import' );
		$this->same( [ 2, 3, 4, 4 ], [ $this->count_posts( $blog, 'product' ), $this->count_posts( $blog, 'product_variation' ), $this->count_imported_media( $blog ), $this->count_original_files( $blog ) ], 're-enabled: no duplicate product, variation, image or file' );
		$receipt = $this->in_site( $blog, $manager, static fn() => Repo::receipt() );
		$this->same( [ 2, 0 ], [ (int) $receipt['imported_products'], (int) $receipt['skipped_products'] ], 're-enabled: two imported, none skipped' );
		$this->same( true, $this->ready( $blog ), 're-enabled: the store is ready' );
		unset( $wpdb );
	}

	/* ------------------------------------------------------------------ *
	 * Starter version
	 * ------------------------------------------------------------------ */

	/** One variable product (three variations) and one simple product, four images. */
	private function seed_version(): void {
		global $wpdb;

		$this->owns_starter_network = true;
		$this->same( true, StarterSchema::ensure_network(), 'the starter network tables are ready' );
		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

		$now = current_time( 'mysql', true );
		$this->db( $wpdb->insert( StarterSchema::versions_table(), [ 'status' => Versions::STATUS_PUBLISHED, 'product_count' => 2, 'image_count' => 4, 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => '{"product_cat":[],"product_tag":[]}', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now ] ), 'seed version' );
		$version = (int) $wpdb->insert_id;

		$images = [];
		foreach ( [ 'a', 'b', 'c', 'd' ] as $index => $key ) {
			$relative = 'v' . $version . '/starter-six-' . $key . '.png';
			$absolute = StarterCatalogStorage::base_dir() . '/' . $relative;
			wp_mkdir_p( dirname( $absolute ) );
			$image = imagecreatetruecolor( 300, 300 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 40 * $index, 90, 160 ) );
			imagepng( $image, $absolute );
			imagedestroy( $image );
			$this->db( $wpdb->insert( StarterSchema::version_images_table(), [ 'version_id' => $version, 'source_attachment_id' => 96000 + $index, 'capture_status' => 'captured', 'file_path' => $relative, 'checksum' => hash_file( 'sha256', $absolute ), 'mime_type' => 'image/png', 'width' => 300, 'height' => 300, 'file_size' => filesize( $absolute ), 'alt_text' => 'Image ' . $key, 'created_at' => $now, 'updated_at' => $now ] ), 'seed version image' );
			$images[ $key ] = (int) $wpdb->insert_id;
		}

		$base   = [ 'description' => '', 'short_description' => '', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false ];
		$parent = ++$this->next_source;
		$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => $parent, 'product_type' => 'variable', 'position' => 1, 'capture_status' => 'captured', 'data' => wp_json_encode( [ 'name' => 'Starter Dauber', 'slug' => 'starter-dauber', 'regular_price' => '', 'image_id' => $images['a'], 'gallery_image_ids' => [ $images['a'], $images['b'] ], 'attributes' => [ [ 'name' => 'Color', 'options' => [ 'Blue', 'Red', 'Green' ], 'visible' => true, 'variation' => true, 'position' => 0 ] ], 'default_attributes' => [] ] + $base ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed variable product' );
		foreach ( [ [ 'Blue', '2.00', $images['a'], true ], [ 'Red', '2.50', $images['b'], true ], [ 'Green', '3.00', $images['c'], false ] ] as $index => [ $color, $price, $image_id, $enabled ] ) {
			$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->next_source, 'parent_source_id' => $parent, 'product_type' => 'variation', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( [ 'attributes' => [ 'color' => $color ], 'regular_price' => $price, 'sku' => '', 'manage_stock' => true, 'enabled' => $enabled, 'tax_class' => 'parent', 'description' => '', 'image_id' => $image_id ] ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed variation' );
		}
		$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->next_source, 'product_type' => 'simple', 'position' => 2, 'capture_status' => 'captured', 'data' => wp_json_encode( [ 'name' => 'Starter Marker', 'slug' => 'starter-marker', 'regular_price' => '4.25', 'image_id' => $images['d'], 'gallery_image_ids' => [] ] + $base ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed simple product' );
	}

	/* ------------------------------------------------------------------ *
	 * Enable Ecommerce through the real machine endpoint
	 * ------------------------------------------------------------------ */

	/** @return array{0:int,1:int} [Portal Hall ID, blog ID]. */
	private function enable( string $slug, string $name ): array {
		$hall = self::HALL_BASE + ( ++$this->next_hall );
		$this->arm_snapshot( $hall );
		[ $status, $body ] = $this->dispatch( $hall, $this->payload( [ 'site_slug' => $slug, 'hall_name' => $name ] ) );
		$blog = (int) ( is_array( $body ) ? ( $body['blog_id'] ?? 0 ) : 0 );
		if ( $blog > 3 ) {
			$this->sites[ $blog ] = true;
		}
		$this->same( [ 200, 'applied', 'operational', true ], [ $status, $body['outcome'] ?? null, $body['commerce_state'] ?? null, $blog > 3 ], "Enable Ecommerce creates {$name}" );
		if ( $blog <= 3 ) {
			throw new RuntimeException( 'Enable did not create a site.' );
		}

		return [ $hall, $blog ];
	}

	/** @return array{0:int,1:mixed} [HTTP status, body]. */
	private function dispatch( int $hall, array $payload ): array {
		$this->authenticate();
		$request = new WP_REST_Request( 'PUT', '/bingocommerce/v1/portal-halls/' . $hall . '/commerce' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $payload ) );
		$response = rest_get_server()->dispatch( $request );

		return [ (int) $response->get_status(), $response->get_data() ];
	}

	private function payload( array $overrides ): array {
		return array_merge(
			[
				'operation_id'    => strtolower( wp_generate_uuid4() ),
				'intent_sequence' => 1,
				'desired_state'   => 'operational',
				'site_slug'       => 'starterdefault',
				'hall_name'       => 'Starter Hall',
				'portal_actor_id' => 4242,
			],
			$overrides
		);
	}

	/** The fixture answers exactly this Hall's Portal snapshot URL. */
	private function arm_snapshot( int $hall ): void {
		$this->snapshots[ $hall ] = (string) wp_json_encode(
			[
				'api_version'  => 'v1',
				'generated_at' => '2026-10-20T10:00:00-04:00',
				'hall'         => [ 'id' => $hall, 'name' => 'Fixture Portal Hall ' . $hall, 'state' => 'active', 'updated_at' => null ],
				'game_types'   => [ [ 'id' => 7101, 'hall_id' => $hall, 'name' => 'Regular', 'updated_at' => null ] ],
				'sessions'     => [ [ 'id' => 8101, 'hall_id' => $hall, 'name' => 'Evening', 'status' => 'active', 'updated_at' => null ] ],
			]
		);
	}

	/** Valid Application Password proof for the machine account. */
	private function authenticate(): void {
		$this->become( $this->machine );
		$GLOBALS['wp_rest_application_password_uuid']   = '11111111-2222-4333-8444-555555555555';
		$GLOBALS['wp_rest_application_password_status'] = get_userdata( $this->machine );
	}

	private function become( int $user_id ): void {
		clean_user_cache( $user_id );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
	}

	/* ------------------------------------------------------------------ *
	 * The Hall's page, handler and notice
	 * ------------------------------------------------------------------ */

	/** Start (unless already started), then Continue until the import stops. */
	private function import_to_end( int $blog, int $user, bool $start = true ): array {
		$result = $start ? $this->ajax( $blog, $user, [ 'step' => 'start' ] ) : [ 'importing', '' ];
		for ( $i = 0; 'importing' === $result[0] && $i < 200; $i++ ) {
			$result = $this->ajax( $blog, $user, [ 'step' => 'continue' ] );
		}

		return $result;
	}

	/** @return array{0:string,1:string} [status, text] or ['error', code]. */
	private function ajax( int $blog, int $user, array $fields ): array {
		return $this->in_site(
			$blog,
			$user,
			static function () use ( $fields ): array {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				try {
					$result = Admin::handle_ajax( [ 'action' => Admin::AJAX_ACTION, '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_import:hall:' . get_current_blog_id() ) ] + $fields );

					return is_wp_error( $result ) ? [ 'error', $result->get_error_code() ] : [ (string) $result['status'], (string) $result['text'] ];
				} finally {
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
			}
		);
	}

	private function page( int $blog, int $user ): array {
		return $this->in_site( $blog, $user, static fn(): array => Admin::page_state() );
	}

	private function notice( int $blog, int $user ): ?string {
		return $this->in_site( $blog, $user, static fn(): ?string => Notice::message() );
	}

	private function in_site( int $blog, int $user, callable $work ) {
		$this->become( $user );
		switch_to_blog( $blog );
		try {
			return $work();
		} finally {
			restore_current_blog();
			$this->become( $this->admin );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	private function ready( int $blog ): bool {
		$readiness = SetupReadiness::evaluate( $blog );

		return is_array( $readiness ) && (bool) $readiness['ready'];
	}

	/** @return array<int,string> The Hall's starter import tables. */
	private function starter_tables( int $blog ): array {
		global $wpdb;

		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $blog ) . 'bingo_starter_' ) . '%' ) );
	}

	private function count_posts( int $blog, string $type ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status <> 'auto-draft'", $wpdb->get_blog_prefix( $blog ) . 'posts', $type ) );
	}

	/**
	 * The import's own media: its attachments keep their recovery identifier
	 * as their slug. WooCommerce's placeholder image is not counted; a
	 * Re-enable re-runs WooCommerce's installer, which adds a second one.
	 */
	private function count_imported_media( int $blog ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'attachment' AND post_name LIKE %s", $wpdb->get_blog_prefix( $blog ) . 'posts', $wpdb->esc_like( 'bcsi-' ) . '%' ) );
	}

	/** The import's original image files in the Hall's uploads (generated sizes excluded). */
	private function count_original_files( int $blog ): int {
		switch_to_blog( $blog );
		$base = (string) wp_upload_dir( null, false )['basedir'];
		restore_current_blog();
		$count = 0;
		if ( is_dir( $base ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() && str_starts_with( $file->getFilename(), 'starter-six-' ) && 1 !== preg_match( '/-\d+x\d+\.[a-z]+$/', $file->getFilename() ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/** Posts, files, the receipt and every item and image row. */
	private function import_snapshot( int $blog ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog );
		$items  = $wpdb->get_results( $wpdb->prepare( 'SELECT item_id, status, wc_product_id, updated_at FROM %i ORDER BY item_id', $prefix . StarterSchema::IMPORT_PRODUCTS_TABLE ), ARRAY_A );
		$images = $wpdb->get_results( $wpdb->prepare( 'SELECT import_image_id, status, attachment_id, updated_at FROM %i ORDER BY import_image_id', $prefix . StarterSchema::IMPORT_IMAGES_TABLE ), ARRAY_A );

		return [
			'posts'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $prefix . 'posts' ) ),
			'files'   => $this->count_original_files( $blog ),
			'receipt' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i', $prefix . StarterSchema::IMPORT_TABLE ) ),
			'rows'    => hash( 'sha256', serialize( [ $items, $images ] ) ),
		];
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** Network tables (no site prefix) and network settings, for the before/after check. */
	private function network_state(): array {
		global $wpdb;
		$tables = array_values(
			array_filter(
				(array) $wpdb->get_col( 'SHOW TABLES' ),
				static fn( string $table ): bool => 1 !== preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $table )
			)
		);
		$settings = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key NOT LIKE %s ORDER BY meta_key', $wpdb->sitemeta, $wpdb->esc_like( '_site_transient_' ) . '%' ) );
		sort( $tables );

		return [ 'tables' => $tables, 'settings' => $settings, 'sites' => array_map( 'intval', get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) ) ];
	}

	/* ------------------------------------------------------------------ *
	 * Isolation, preparation and teardown
	 * ------------------------------------------------------------------ */

	private function isolated(): void {
		global $wpdb;
		if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db || (string) $wpdb->get_var( 'SELECT DATABASE()' ) !== BC_Fixture::$database
			|| BC_FIXTURE_IDENTITY !== (string) $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' ) ) {
			fwrite( STDERR, "HARNESS GUARD: not connected to the sentinel-owned provisioning fixture.\n" );
			exit( 1 );
		}
		$this->same( true, defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && ! wp_using_ext_object_cache(), 'outbound HTTP is blocked and no persistent object cache is used' );
		add_filter( 'pre_wp_mail', '__return_true', 0 );
	}

	private function clean_start(): void {
		$extra = array_filter( array_map( 'intval', get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) ), static fn( int $id ): bool => $id > 3 );
		if ( [] !== $extra
			|| $this->table_exists( ProvisioningMigrations::operations_table() )
			|| $this->table_exists( StarterSchema::versions_table() )
			|| false !== get_site_option( StarterSchema::NETWORK_VERSION_OPTION, false )
			|| false !== get_site_option( SsoMigrations::VERSION_OPTION, false ) ) {
			fwrite( STDERR, "HARNESS GUARD: the provisioning fixture is not clean (extra sites or schema from an earlier run).\n" );
			exit( 1 );
		}
		$this->network_before        = $this->network_state();
		$this->wc_session_high_water = $this->wc_session_max_id();
	}

	private function prepare(): void {
		global $wpdb;

		$this->original_site_admins = get_site_option( 'site_admins', null );
		$this->admin                = (int) username_exists( 'fixture_admin' );
		if ( $this->admin < 1 ) {
			$created = wp_insert_user( [ 'user_login' => 'fixture_admin', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'fixture-admin@example.invalid', 'role' => '' ] );
			if ( is_wp_error( $created ) ) {
				throw new RuntimeException( 'The disposable Super Admin could not be created.' );
			}
			$this->admin      = (int) $created;
			$this->owns_admin = true;
			$this->users[ $this->admin ] = true;
		}
		$this->same( true, is_super_admin( $this->admin ), 'a Network Super Admin exists' );
		if ( ! defined( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID' ) ) {
			define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', $this->admin );
		}

		// The Portal machine account holds only the provisioning capability,
		// written directly as the provisioning suite's add_cap() would store it.
		$machine = wp_insert_user( [ 'user_login' => 'starter_portal_machine', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'starter-portal-machine@example.invalid', 'role' => '' ] );
		if ( is_wp_error( $machine ) ) {
			throw new RuntimeException( 'The machine account could not be created.' );
		}
		$this->machine                 = (int) $machine;
		$this->users[ $this->machine ] = true;
		$key                           = $wpdb->get_blog_prefix( get_main_site_id() ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $this->machine, 'meta_key' => $key ] );
		$this->db( $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $this->machine, 'meta_key' => $key, 'meta_value' => serialize( [ Controller::CAPABILITY => true ] ) ] ), 'machine capability' );
		clean_user_cache( $this->machine );

		$this->owns_core_schema = ! $this->table_exists( CoreMigrations::audit_log_table() );
		CoreMigrations::maybe_migrate_network();
		$this->owns_mapping_schema = ! $this->table_exists( MappingMigrations::hall_mappings_table() );
		$this->same( true, true === MappingMigrations::migrate_network(), 'the Hall mapping schema is installed' );
		$this->owns_provisioning_schema = true;
		$this->same( true, true === ProvisioningMigrations::migrate(), 'the provisioning schema is installed' );
		ProvisioningMigrations::flush_ready_cache();

		PlatformPlugin::init();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $args );
				foreach ( $this->snapshots as $hall => $body ) {
					if ( (string) $url === BINGOCOMMERCE_PORTAL_BASE_URL . '/integration/v1/halls/' . $hall . '/snapshot' ) {
						return [ 'headers' => [], 'body' => $body, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
					}
				}

				return $preempt;
			},
			2,
			3
		);
		Controller::register();
		do_action( 'rest_api_init', rest_get_server() );
		$this->authenticate();
		$this->same( true, Controller::has_application_password_proof() && current_user_can( Controller::CAPABILITY ), 'the machine account has Application Password proof and the provisioning capability' );
	}

	private function hall_manager( string $label, int $blog ): int {
		global $wpdb;
		$id = wp_insert_user( [ 'user_login' => 'starter-int-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Hall Manager creation failed.' );
		}
		$this->users[ (int) $id ] = true;
		$key                      = $wpdb->get_blog_prefix( $blog ) . 'capabilities';
		$this->db( $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ 'bc_hall_manager' => true ] ) ] ), 'Hall Manager role' );
		clean_user_cache( (int) $id );

		return (int) $id;
	}

	private function teardown( string $starter_dir ): void {
		global $wpdb;
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		if ( DB_NAME !== BC_Fixture::$database || BC_FIXTURE_IDENTITY !== (string) $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' ) ) {
			$this->same( true, false, 'teardown refused: not the fixture' );
			return;
		}
		$this->become( $this->admin );
		$this->remove_wc_session_residue();

		foreach ( array_keys( $this->sites ) as $blog ) {
			if ( $blog <= 3 ) {
				continue;
			}
			switch_to_blog( $blog );
			$uploads = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
			restore_current_blog();
			$prefix = $wpdb->get_blog_prefix( $blog );
			$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( $this->table_exists( CoreMigrations::audit_log_table() ) ) {
				$wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $blog ], [ '%d' ] );
			}
			wp_delete_site( $blog );
			foreach ( $tables as $table ) {
				if ( str_starts_with( $table, $prefix ) ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
				}
			}
			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $blog ], [ '%d' ] );
			$content = wp_normalize_path( WP_CONTENT_DIR );
			foreach ( [ $content . '/blogs.dir/' . $blog . '/files' => $content . '/blogs.dir/' . $blog, $content . '/uploads/sites/' . $blog => $content . '/uploads/sites/' . $blog ] as $expected => $folder ) {
				if ( $uploads === $expected ) {
					$this->remove_tree( $folder );
				}
			}
			$this->same( [ null, false ], [ get_site( $blog ), is_dir( $uploads ) ], "disposable Hall {$blog} and its uploads removed" );
		}

		foreach ( array_keys( $this->users ) as $user ) {
			if ( is_super_admin( $user ) && $this->owns_admin && $user === $this->admin ) {
				revoke_super_admin( $user );
			}
			wpmu_delete_user( $user );
		}
		if ( $this->owns_admin && null !== $this->original_site_admins ) {
			update_site_option( 'site_admins', $this->original_site_admins );
		}

		$drop = static function ( string $table ) use ( $wpdb ): void {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		};
		if ( $this->owns_starter_network ) {
			array_map( $drop, [ StarterSchema::versions_table(), StarterSchema::version_products_table(), StarterSchema::version_images_table() ] );
			delete_site_option( StarterSchema::NETWORK_VERSION_OPTION );
			delete_site_option( Versions::ALLOW_IMPORTS_OPTION );
		}
		if ( $this->owns_sso_schema ) {
			array_map( $drop, [ SsoMigrations::links_table(), SsoMigrations::attempts_table() ] );
			delete_site_option( SsoMigrations::VERSION_OPTION );
			SsoMigrations::flush_ready_cache();
		}
		if ( $this->owns_provisioning_schema ) {
			array_map( $drop, [ ProvisioningMigrations::operations_table(), ProvisioningMigrations::state_table() ] );
			delete_site_option( ProvisioningMigrations::VERSION_OPTION );
			ProvisioningMigrations::flush_ready_cache();
		}
		if ( $this->owns_mapping_schema ) {
			$drop( MappingMigrations::hall_mappings_table() );
			delete_site_option( MappingMigrations::NETWORK_VERSION_OPTION );
		}
		if ( $this->owns_core_schema ) {
			$drop( CoreMigrations::audit_log_table() );
			delete_site_option( CoreMigrations::NETWORK_VERSION_OPTION );
		}

		$this->remove_tree( $starter_dir );
		$this->same( false, is_dir( $starter_dir ), 'temporary frozen image folder removed' );
		$after = $this->network_state();
		$this->same( $this->network_before['sites'], $after['sites'], 'the fixture keeps exactly its seeded sites' );
		$this->same( $this->network_before['tables'], $after['tables'], 'the fixture\'s network tables are exactly as found' );
		$this->same( $this->network_before['settings'], $after['settings'], 'the fixture\'s network settings are exactly as found' );
		$this->same( [], array_filter( array_keys( $this->users ), static fn( int $user ): bool => get_userdata( $user ) instanceof WP_User ), 'every user this run created is removed' );
	}

	private function wc_session_max_id(): int {
		global $wpdb;
		$table = $wpdb->base_prefix . 'woocommerce_sessions';

		return $this->table_exists( $table ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(session_id), 0) FROM %i', $table ) ) : 0;
	}

	/** Only the WooCommerce guest sessions this run caused, also after WooCommerce's own shutdown save. */
	private function remove_wc_session_residue(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'woocommerce_sessions';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		$high = $this->wc_session_high_water;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_id > %d', $table, $high ) );
		add_action(
			'shutdown',
			static function () use ( $table, $high ): void {
				global $wpdb;
				if ( $wpdb instanceof wpdb && DB_NAME === BC_Fixture::$database && DB_NAME !== BC_Fixture::$live_db ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_id > %d', $table, $high ) );
				}
			},
			PHP_INT_MAX
		);
	}

	/** Remove a folder this run owns (a disposable Hall's uploads or the frozen folder). */
	private function remove_tree( string $directory ): void {
		$directory = wp_normalize_path( $directory );
		$content   = wp_normalize_path( WP_CONTENT_DIR );
		$owned     = str_starts_with( $directory, wp_normalize_path( rtrim( sys_get_temp_dir(), '/' ) ) . '/bc-starter-integration-' )
			|| 1 === preg_match( '#^' . preg_quote( $content, '#' ) . '/(blogs\.dir|uploads/sites)/(\d+)$#', $directory, $match ) && isset( $this->sites[ (int) $match[2] ] );
		if ( ! $owned || ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}

	/* ------------------------------------------------------------------ *
	 * Assertions
	 * ------------------------------------------------------------------ */

	private function db( $result, string $label ): void {
		if ( false === $result ) {
			throw new RuntimeException( 'Fixture SQL failed (' . $label . '): ' . $GLOBALS['wpdb']->last_error );
		}
	}

	private function code( string $expected, $actual, string $label ): void {
		$this->same( $expected, is_wp_error( $actual ) ? $actual->get_error_code() : ( is_array( $actual ) ? 'array' : var_export( $actual, true ) ), $label );
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

exit( ( new StarterImportIntegrationTest() )->run( $bc_starter_dir ) );
