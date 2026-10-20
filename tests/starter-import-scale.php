<?php

declare(strict_types=1);

/**
 * ADR-049 stage 6, slice 6.2: a legacy Hall with existing data, two Halls
 * importing at the same time, larger catalogs and image timing, against real
 * WooCommerce and real image files on disposable fixture Halls.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-scale.php
 *
 * Proves:
 *   - a legacy Hall that already has products (one using SKU S0101BSP9), a
 *     category under another parent, a Bingo Play Product, a Package and
 *     media: after the import every existing post, metadata row, term,
 *     relationship, Catalog row and file is byte-identical (term counts may
 *     change); the SKU and category notes appear; the Shop still leaves out
 *     the Play Product and Package; an imported product the Manager
 *     publishes shows in the Shop and can be added to the cart, while a
 *     Package still cannot;
 *   - two Halls importing at the same time in separate processes each end
 *     with exactly their own products, rows, audit events and files;
 *   - a second request on the same Hall gets "already running" and changes
 *     nothing; the import then finishes without duplicates;
 *   - 24-product and 100-product catalogs import through the page's own
 *     request loop with exact counts and no duplicates, every request ending
 *     within the time budget plus one unit of work;
 *   - image timing: 28 images shaped like the real samples (2000 x 2000 PNG,
 *     up to about 1.3 MB) are measured per image and per request (printed
 *     as TIMING lines), and every request still ends within the budget plus
 *     one image;
 *   - WooCommerce's attribute lookup table indexes global attributes only,
 *     so the starter products' product-level attributes are not in it
 *     (ADR-049 section 20 open check).
 *
 * Frozen starter images live in a temporary folder for this run only.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_starter_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-scale-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_starter_dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary starter image folder.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_starter_dir );

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator;
use Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore;
use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\Migrations as CatalogSchema;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Catalog\StarterCatalogStorage;
use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImportAdmin as Admin;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportLock;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportRepository as Repo;

final class StarterImportScaleTest {
	private const OWNER_OPTION = 'bc_starter_import_test_owner';
	private const USER_OWNER   = 'bc_starter_import_test_user_owner';

	/** Allowance for the one unit of work a request may finish after its budget (small images). */
	private const UNIT_ALLOWANCE = 2.0;

	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	private int $next_source = 30000;
	private array $network_before = [];

	public function run( string $starter_dir ): int {
		global $wpdb;

		$this->token = wp_generate_password( 24, false, false );
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->same( wp_normalize_path( $starter_dir ), StarterCatalogStorage::base_dir(), 'frozen images use this run\'s temporary folder' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->network_before = [ 'upload_space_check_disabled' => get_site_option( 'upload_space_check_disabled', null ) ];
		$baseline             = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}

		try {
			update_site_option( 'upload_space_check_disabled', '1' );
			$this->reset_network();
			$this->same( true, StarterSchema::ensure_network(), 'network starter tables ready' );
			update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

			$this->legacy_hall();
			$this->two_halls_at_once();
			$this->same_hall_twice();
			$this->catalog_size( 24, 6, 3, 6 );
			$this->catalog_size( 100, 10, 2, 10 );
			$this->image_timing();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wp_set_current_user( $this->admin );
			$this->cleanup();
			$this->reset_network();
			if ( null === $this->network_before['upload_space_check_disabled'] ) {
				delete_site_option( 'upload_space_check_disabled' );
			} else {
				update_site_option( 'upload_space_check_disabled', $this->network_before['upload_space_check_disabled'] );
			}
			$this->remove_tree( $starter_dir, rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-scale-' );
		}

		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		$this->same( false, is_dir( $starter_dir ), 'temporary frozen image folder removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * A legacy Hall with existing data
	 * ------------------------------------------------------------------ */

	private function legacy_hall(): void {
		$colors = [ 'Blue' => 'S0101BSP9', 'Orange' => 'S0102BSP9', 'Green' => 'S0103BSP9' ];
		$this->seed(
			[
				$this->variable(
					[
						'name'       => '1on Bingo Paper',
						'slug'       => '1on-bingo-paper',
						'categories' => [ 'bingo-sets' ],
						'image'      => 'p',
						'attributes' => [ [ 'name' => 'Color', 'options' => array_keys( $colors ), 'visible' => true, 'variation' => true, 'position' => 0 ] ],
						'variations' => array_map(
							static fn( string $color, string $sku ): array => [ 'attributes' => [ 'color' => $color ], 'regular_price' => '36.15', 'sku' => $sku, 'manage_stock' => true, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image' => 'p' ],
							array_keys( $colors ),
							$colors
						),
					]
				),
				$this->simple( [ 'name' => 'Starter Marker', 'slug' => 'starter-marker', 'regular_price' => '4.25', 'image' => 'm', 'categories' => [ 'bingo-sets' ] ] ),
			],
			[ 'p' => [ 'name' => 'starter-paper' ], 'm' => [ 'name' => 'starter-marker' ] ],
			[ 'product_cat' => [ [ 'slug' => 'bingo-sets', 'name' => 'Bingo Sets', 'parent' => '' ] ], 'product_tag' => [] ]
		);

		$hall    = $this->create_hall();
		$manager = $this->user( 'legacy', $hall, 'bc_hall_manager' );
		$legacy  = $this->in_hall( $hall, $this->admin, fn(): array => $this->legacy_data() );
		$this->same( true, $legacy['play'] > 0 && $legacy['package'] > 0, 'the legacy Hall has a Bingo Play Product and a Package' );
		$before = $this->in_hall( $hall, $this->admin, fn(): array => $this->legacy_snapshot( $legacy['posts'], $legacy['terms'] ) );
		$shop   = $this->in_hall( $hall, $this->admin, fn(): array => $this->shop_ids() );
		$this->same( [ $legacy['paper'], $legacy['dauber'] ], $shop, 'before the import the Shop lists the Hall\'s own merchandise, not its Play Product or Package' );

		[ $final ] = $this->timed_import( $hall, $manager );
		$this->same( 'complete', $final[0], 'the legacy Hall imports to completion: ' . $final[1] );
		$after = $this->in_hall( $hall, $this->admin, fn(): array => $this->legacy_snapshot( $legacy['posts'], $legacy['terms'] ) );
		foreach ( array_keys( $before ) as $part ) {
			$this->same( $before[ $part ], $after[ $part ], "legacy data unchanged by the import: {$part}" );
		}

		$this->in_hall(
			$hall,
			$manager,
			function () use ( $legacy ) {
				$notes = implode( "\n", array_column( Admin::page_state()['notes'], 'note' ) );
				$this->same( [ true, true ], [ str_contains( $notes, 'SKU S0101BSP9 is already used in this store' ), str_contains( $notes, 'already exists in this store under a different parent; your existing category was kept.' ) ], 'the SKU conflict and the existing category are noted' );
				$paper = $this->product_by_slug( '1on-bingo-paper' );
				$this->same( [ $legacy['category'] ], $paper instanceof WC_Product ? $paper->get_category_ids() : [], 'the imported product uses the Hall\'s existing category' );

				$marker = $this->product_by_slug( 'starter-marker' );
				$this->same( [ false ], [ in_array( $marker instanceof WC_Product ? $marker->get_id() : 0, $this->shop_ids(), true ) ], 'an imported Draft is not in the Shop' );
				wp_update_post( [ 'ID' => $marker->get_id(), 'post_status' => 'publish' ] );
				clean_post_cache( $marker->get_id() );
				$marker = wc_get_product( $marker->get_id() );
				$this->same( [ $legacy['paper'], $legacy['dauber'], $marker->get_id() ], $this->shop_ids(), 'once the Manager publishes it, the imported product is in the Shop; the Play Product and Package are still left out' );
				$this->same( [ true, '4.25' ], [ $marker->is_purchasable(), (string) $marker->get_price() ], 'the published imported product can be bought at its price' );
				$this->same( [], $this->cart_guard( $marker->get_id() ), 'the cart accepts the imported product like any ordinary product' );
				$this->same( 'refused', $this->cart_guard( $legacy['package'] ), 'the cart still refuses a Package added outside the Session purchase step' );
			}
		);

		// ADR-049 section 20: does WooCommerce's attribute lookup table index
		// product-level attributes? Built directly, so scheduling plays no part.
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				// WooCommerce's shared lookup objects keep the table name of the site
				// they were built on (the main site here), so this test Hall has no
				// lookup table of its own: create it from WooCommerce's own definition
				// and build a lookup store for this Hall.
				$regenerator = wc_get_container()->get( DataRegenerator::class );
				dbDelta( str_replace( $regenerator->get_lookup_table_name(), $wpdb->prefix . 'wc_product_attributes_lookup', $regenerator->get_table_creation_sql() ) );
				$store  = new LookupDataStore();
				$paper  = $this->product_by_slug( '1on-bingo-paper' );
				$rows   = static fn( int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_or_parent_id = %d', $wpdb->prefix . 'wc_product_attributes_lookup', $id ) );
				// For a variable product this also covers every variation.
				$store->create_data_for_product( $paper );

				$attribute = wc_create_attribute( [ 'name' => 'Starter Size', 'slug' => 'stsize' ] );
				register_taxonomy( 'pa_stsize', [ 'product' ] );
				$term    = wp_insert_term( 'Large', 'pa_stsize' );
				$control = new WC_Product_Simple();
				$global  = new WC_Product_Attribute();
				$global->set_id( (int) $attribute );
				$global->set_name( 'pa_stsize' );
				$global->set_options( [ (int) $term['term_id'] ] );
				$global->set_visible( true );
				$control->set_name( 'Lookup control' );
				$control->set_attributes( [ $global ] );
				$control_id = (int) $control->save();
				$store->create_data_for_product( wc_get_product( $control_id ) );

				$this->same( [ 0, true ], [ $rows( $paper->get_id() ), $rows( $control_id ) > 0 ], 'WooCommerce\'s attribute lookup table indexes a global attribute but not the starter products\' product-level Color' );
			}
		);
	}

	/**
	 * Products (one using SKU S0101BSP9, a variable one), a category under
	 * another parent, a Play Product, a Package and media.
	 *
	 * @return array{posts:array<int,int>,terms:array<int,int>,paper:int,dauber:int,play:int,package:int,category:int}
	 */
	private function legacy_data(): array {
		$parent   = wp_insert_term( 'Hall Supplies', 'product_cat', [ 'slug' => 'hall-supplies' ] );
		$category = wp_insert_term( 'Bingo Sets', 'product_cat', [ 'slug' => 'bingo-sets', 'parent' => (int) $parent['term_id'] ] );

		$uploads = wp_upload_dir();
		$file    = $uploads['path'] . '/hall-logo.png';
		$image   = imagecreatetruecolor( 120, 120 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 30, 30 ) );
		imagepng( $image, $file );
		imagedestroy( $image );
		$media = (int) wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Hall logo', 'post_status' => 'inherit' ], $file, 0, true );
		wp_update_attachment_metadata( $media, wp_generate_attachment_metadata( $media, $file ) );

		$paper = new WC_Product_Simple();
		$paper->set_name( 'Star Bingo Hall paper' );
		$paper->set_status( 'publish' );
		$paper->set_sku( 'S0101BSP9' );
		$paper->set_regular_price( '30.00' );
		$paper->set_category_ids( [ (int) $category['term_id'] ] );
		$paper->set_image_id( $media );
		$paper_id = (int) $paper->save();

		$size = new WC_Product_Attribute();
		$size->set_id( 0 );
		$size->set_name( 'Size' );
		$size->set_options( [ 'Small', 'Large' ] );
		$size->set_visible( true );
		$size->set_variation( true );
		$dauber = new WC_Product_Variable();
		$dauber->set_name( 'Hall Dauber' );
		$dauber->set_status( 'publish' );
		$dauber->set_attributes( [ $size ] );
		$dauber_id  = (int) $dauber->save();
		$variations = [];
		foreach ( [ 'Small' => '1.50', 'Large' => '2.50' ] as $option => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $dauber_id );
			$variation->set_attributes( [ 'size' => $option ] );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$variations[] = (int) $variation->save();
		}
		WC_Product_Variable::sync( $dauber_id );

		$plays      = new BingoPlayProductService();
		$play_wc    = $this->simple_product( 'Hall Paper Pack', '1.00' );
		$play       = (int) $plays->create( $play_wc, 'paper', 6, null )->play_product['bingo_play_product_id'];
		$package_wc = $this->simple_product( 'Hall Evening Package', '20.00' );
		( new PackageService() )->create( $package_wc, [ [ 'bingo_play_product_id' => $play, 'quantity' => 1 ] ] );

		global $wpdb;
		$terms = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT term_id FROM %i', $wpdb->terms ) ) );

		return [
			'posts'    => array_merge( [ $media, $paper_id, $dauber_id, $play_wc, $package_wc ], $variations ),
			'terms'    => $terms,
			'paper'    => $paper_id,
			'dauber'   => $dauber_id,
			'play'     => $play,
			'package'  => $package_wc,
			'category' => (int) $category['term_id'],
		];
	}

	private function simple_product( string $name, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );

		return (int) $product->save();
	}

	/**
	 * Every existing post row and its metadata, terms (without their counts),
	 * relationships, the Catalog tables and every upload file.
	 *
	 * @param array<int,int> $posts
	 * @param array<int,int> $terms
	 * @return array<string,string>
	 */
	private function legacy_snapshot( array $posts, array $terms ): array {
		global $wpdb;
		$posts = array_map( 'intval', $posts );
		$terms = array_map( 'intval', $terms );
		$in    = static fn( array $ids ): string => implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$hash  = static fn( $rows ): string => hash( 'sha256', serialize( $rows ) );
		$files = [];
		$base  = (string) wp_upload_dir( null, false )['basedir'];
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && ! str_contains( $file->getFilename(), 'starter-' ) ) {
				$files[ substr( $file->getPathname(), strlen( $base ) ) ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
		ksort( $files );

		$out = [
			// Only placeholders are joined into the SQL; every value is prepared.
			'posts'         => $hash( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ID IN (' . $in( $posts ) . ') ORDER BY ID', $wpdb->posts, ...$posts ), ARRAY_A ) ),
			'post metadata' => $hash( $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_key, meta_value FROM %i WHERE post_id IN (' . $in( $posts ) . ') ORDER BY meta_id', $wpdb->postmeta, ...$posts ), ARRAY_A ) ),
			'terms'         => $hash( $wpdb->get_results( $wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE t.term_id IN (' . $in( $terms ) . ') ORDER BY t.term_id, tt.taxonomy', $wpdb->terms, $wpdb->term_taxonomy, ...$terms ), ARRAY_A ) ),
			'relationships' => $hash( $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, term_taxonomy_id FROM %i WHERE object_id IN (' . $in( $posts ) . ') ORDER BY object_id, term_taxonomy_id', $wpdb->term_relationships, ...$posts ), ARRAY_A ) ),
			'files'         => $hash( $files ),
		];
		foreach ( [ CatalogSchema::play_products_table(), CatalogSchema::packages_table(), CatalogSchema::package_components_table(), CatalogSchema::game_types_table() ] as $table ) {
			$out[ 'Catalog ' . substr( $table, strlen( $wpdb->prefix ) ) ] = $hash( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) );
		}

		return $out;
	}

	/** Product IDs WooCommerce's main product query lists, after ShopListing. */
	private function shop_ids(): array {
		$query = new WP_Query();
		foreach ( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] as $key => $value ) {
			$query->set( $key, $value );
		}
		do_action( 'woocommerce_product_query', $query, null );

		return array_map( 'intval', $query->get_posts() );
	}

	/** What the cart's add-item guard does with one product: its data, or "refused". */
	private function cart_guard( int $product_id ) {
		try {
			return apply_filters( 'woocommerce_add_cart_item_data', [], $product_id, 0, 1 );
		} catch ( Throwable $refused ) {
			unset( $refused );

			return 'refused';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Two Halls at once, and the same Hall twice
	 * ------------------------------------------------------------------ */

	private function two_halls_at_once(): void {
		global $wpdb;
		$this->medium_version();
		$halls = [ $this->create_hall(), $this->create_hall() ];
		foreach ( $halls as $hall ) {
			$this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->start() );
		}

		$runs = [];
		foreach ( $halls as $hall ) {
			$runs[ $hall ] = $this->launch_worker( $hall, 'none' );
		}
		$finished = [];
		foreach ( $runs as $hall => $run ) {
			$finished[ $hall ] = $this->finish_worker( $run );
		}
		$this->same( [ 'complete', 'complete' ], array_values( array_map( static fn( array $done ): string => $done['result'], $finished ) ), 'both Halls finish their imports in separate processes' );
		// Each Hall's own saved times (whole seconds): when its first item was
		// claimed and its last item finished. One after the other gives no overlap.
		$spans   = array_map( fn( int $hall ): array => $this->in_hall( $hall, $this->admin, static fn(): array => array_map( 'intval', (array) $wpdb->get_row( $wpdb->prepare( 'SELECT UNIX_TIMESTAMP( MIN( claimed_at ) ) AS first, UNIX_TIMESTAMP( MAX( completed_at ) ) AS last FROM %i', StarterSchema::import_products_table() ), ARRAY_A ) ) ), $halls );
		$overlap = min( $spans[0]['last'], $spans[1]['last'] ) - max( $spans[0]['first'], $spans[1]['first'] );
		$this->same( true, $overlap >= 1, 'the two imports ran at the same time (their working periods overlap by ' . $overlap . ' s)' );

		foreach ( $halls as $index => $hall ) {
			$other = $halls[ 1 - $index ];
			$this->in_hall(
				$hall,
				$this->admin,
				function () use ( $hall, $other ) {
					global $wpdb;
					$this->same( [ 4, 6, 6, 6 ], [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ), $this->count_original_files() ], "Hall {$hall}: exactly its own 4 products, 6 variations and 6 images" );
					$missing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i i LEFT JOIN %i p ON p.ID = i.wc_product_id WHERE p.ID IS NULL', StarterSchema::import_products_table(), $wpdb->posts ) );
					$this->same( 0, $missing, "Hall {$hall}: every import record points to a product in this Hall" );
					$base    = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
					$outside = 0;
					foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'attachment'", $wpdb->posts ) ) as $attachment ) {
						$path = wp_normalize_path( (string) get_attached_file( (int) $attachment ) );
						$outside += str_starts_with( $path, $base . '/' ) && is_file( $path ) ? 0 : 1;
					}
					$this->same( 0, $outside, "Hall {$hall}: every image file is in this Hall's own uploads" );
					$events = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM %i WHERE blog_id = %d AND action LIKE %s ORDER BY id', \BingoCommerce\Core\Migrations::audit_log_table(), $hall, 'starter_import.%' ) );
					$this->same( [ 'starter_import.started', 'starter_import.completed' ], $events, "Hall {$hall}: exactly its own start and completion audit events" );
					unset( $other );
				}
			);
		}
		unset( $wpdb );
	}

	private function same_hall_twice(): void {
		$this->medium_version();
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->start() );
		$before = $this->in_hall( $hall, $this->admin, fn(): array => [ $this->count_posts( 'product' ), $this->count_posts( 'attachment' ), Repo::receipt()['updated_at'] ] );

		$lock = $this->in_hall( $hall, $this->admin, static fn() => StarterImportLock::acquire_hall( $hall ) );
		$this->same( true, $lock instanceof StarterImportLock, 'one request holds the Hall\'s import lock' );
		try {
			$second = $this->finish_worker( $this->launch_worker( $hall, 'none' ) );
			$this->same( StarterImportLock::ALREADY_RUNNING, $second['result'], 'a second request on the same Hall (another process) gets "already running"' );
			$this->same( $before, $this->in_hall( $hall, $this->admin, fn(): array => [ $this->count_posts( 'product' ), $this->count_posts( 'attachment' ), Repo::receipt()['updated_at'] ] ), 'and changes nothing' );
		} finally {
			if ( $lock instanceof StarterImportLock ) {
				$lock->release();
			}
		}

		$this->same( 'complete', $this->finish_worker( $this->launch_worker( $hall, 'none' ) )['result'], 'once the first request ends, the import finishes' );
		$this->same( [ 4, 6, 6 ], $this->in_hall( $hall, $this->admin, fn(): array => [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ] ), 'no duplicates after the refused request' );
	}

	/** Two variable products (three variations each, one image per variation) and two simple ones. */
	private function medium_version(): void {
		$images   = [];
		$products = [];
		foreach ( [ 'Ink', 'Paper' ] as $p => $name ) {
			$variations = [];
			foreach ( [ 'Red', 'Blue', 'Green' ] as $v => $color ) {
				$key            = strtolower( $name . $color );
				$images[ $key ] = [ 'name' => 'medium-' . $key, 'size' => 1500, 'noise' => 400 ];
				$variations[]   = [ 'attributes' => [ 'color' => $color ], 'regular_price' => sprintf( '%d.%02d', $p + 1, $v * 25 ), 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image' => $key ];
			}
			$products[] = $this->variable( [ 'name' => "Medium {$name}", 'slug' => 'medium-' . strtolower( $name ), 'image' => strtolower( $name ) . 'red', 'attributes' => [ [ 'name' => 'Color', 'options' => [ 'Red', 'Blue', 'Green' ], 'visible' => true, 'variation' => true, 'position' => 0 ] ], 'variations' => $variations ] );
		}
		$products[] = $this->simple( [ 'name' => 'Medium Marker', 'slug' => 'medium-marker' ] );
		$products[] = $this->simple( [ 'name' => 'Medium Board', 'slug' => 'medium-board' ] );
		$this->seed( $products, $images, [] );
	}

	/* ------------------------------------------------------------------ *
	 * Larger catalogs
	 * ------------------------------------------------------------------ */

	private function catalog_size( int $total, int $variable, int $variations_each, int $images ): void {
		$image_specs = [];
		for ( $i = 0; $i < $images; $i++ ) {
			$image_specs[ 'i' . $i ] = [ 'name' => sprintf( 'size%d-%02d', $total, $i ), 'size' => 300 ];
		}
		$products = [];
		for ( $p = 0; $p < $total; $p++ ) {
			$image = $p < $images ? 'i' . $p : null;
			if ( $p < $variable ) {
				$options    = array_map( static fn( int $v ): string => 'Option ' . ( $v + 1 ), range( 0, $variations_each - 1 ) );
				$products[] = $this->variable(
					[
						'name'       => sprintf( 'Catalog %d variable %02d', $total, $p ),
						'slug'       => sprintf( 'catalog-%d-variable-%02d', $total, $p ),
						'image'      => $image,
						'attributes' => [ [ 'name' => 'Option', 'options' => $options, 'visible' => true, 'variation' => true, 'position' => 0 ] ],
						'variations' => array_map( static fn( string $option ): array => [ 'attributes' => [ 'option' => $option ], 'regular_price' => '3.00', 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image' => null ], $options ),
					]
				);
			} else {
				$products[] = $this->simple( [ 'name' => sprintf( 'Catalog %d simple %03d', $total, $p ), 'slug' => sprintf( 'catalog-%d-simple-%03d', $total, $p ), 'image' => $image, 'sku' => sprintf( 'CAT%d-%03d', $total, $p ) ] );
			}
		}
		$this->seed( $products, $image_specs, [] );

		$hall    = $this->create_hall();
		$manager = $this->user( 'size' . $total, $hall, 'bc_hall_manager' );
		[ $final, $times ] = $this->timed_import( $hall, $manager );
		$this->same( 'complete', $final[0], "{$total} products: the import completes through the page's request loop" );
		$this->in_hall(
			$hall,
			$manager,
			function () use ( $total, $variable, $variations_each, $images ) {
				global $wpdb;
				$receipt = Repo::receipt();
				$this->same( [ $total, 0 ], [ (int) $receipt['imported_products'], (int) $receipt['skipped_products'] ], "{$total} products: all imported, none skipped" );
				$this->same( [ $total, $total, $variable * $variations_each, $images ], [ $this->count_posts( 'product' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_name) FROM %i WHERE post_type = 'product'", $wpdb->posts ) ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ], "{$total} products: exact counts, every product slug distinct, no duplicates" );
			}
		);
		$this->budget_respected( $times, self::UNIT_ALLOWANCE, "{$total} products" );
		echo sprintf( "TIMING %d products: %d requests, longest %.2f s, total %.2f s\n", $total, count( $times ), max( $times ), array_sum( $times ) );
	}

	/* ------------------------------------------------------------------ *
	 * Image timing
	 * ------------------------------------------------------------------ */

	private function image_timing(): void {
		$images   = [];
		$products = [];
		foreach ( [ [ 'Timing Paper', 15 ], [ 'Timing Ink', 13 ] ] as [ $name, $count ] ) {
			$variations = [];
			$keys       = [];
			for ( $v = 0; $v < $count; $v++ ) {
				$key            = strtolower( str_replace( ' ', '', $name ) ) . $v;
				$keys[]         = $key;
				// 2000 x 2000 like the real samples (28 PNGs, 6.3 MB in all, the
				// largest 1.34 MB): the noisy area makes one image per product
				// about 1.3 MB and the others about 0.1 MB.
				$images[ $key ] = [ 'name' => 'timing-' . $key, 'size' => 2000, 'noise' => 0 === $v ? 660 : 200 ];
				$variations[]   = [ 'attributes' => [ 'color' => 'Color ' . $v ], 'regular_price' => '1.06', 'sku' => '', 'manage_stock' => true, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image' => $key ];
			}
			$products[] = $this->variable( [ 'name' => $name, 'slug' => sanitize_title( $name ), 'image' => $keys[0], 'gallery' => array_slice( $keys, 1 ), 'attributes' => [ [ 'name' => 'Color', 'options' => array_map( static fn( int $v ): string => 'Color ' . $v, range( 0, $count - 1 ) ), 'visible' => true, 'variation' => true, 'position' => 0 ] ], 'variations' => $variations ] );
		}
		$version = $this->seed( $products, $images, [] );
		$sizes   = array_column( $version['images'], 'file_size' );

		$hall    = $this->create_hall();
		$manager = $this->user( 'timing', $hall, 'bc_hall_manager' );
		$subsize = $this->in_hall( $hall, $manager, static fn(): array => array_keys( wp_get_registered_image_subsizes() ) );
		$started = [];
		$image   = [];
		$watch   = static function ( string $step, array $context ) use ( &$started, &$image ): void {
			if ( 'image_planned' === $step ) {
				$started[ (int) $context['import_image_id'] ] = microtime( true );
			} elseif ( 'image_completed' === $step && isset( $started[ (int) $context['import_image_id'] ] ) ) {
				$image[] = microtime( true ) - $started[ (int) $context['import_image_id'] ];
			}
		};
		add_action( StarterImporter::CHECKPOINT_ACTION, $watch, 10, 2 );
		try {
			[ $final, $times ] = $this->timed_import( $hall, $manager );
		} finally {
			remove_action( StarterImporter::CHECKPOINT_ACTION, $watch, 10 );
		}
		$this->same( 'complete', $final[0], 'the 28-image catalog imports completely' );
		$this->same( [ 28, 28 ], [ count( $image ), $this->in_hall( $hall, $manager, fn(): int => $this->count_posts( 'attachment' ) ) ], 'all 28 images were processed and measured, each once' );
		if ( [] === $image ) {
			return;
		}
		sort( $image );
		$this->budget_respected( $times, max( $image ) + 0.5, '28 images' );

		$median = $image[ intdiv( count( $image ), 2 ) ];
		echo sprintf(
			"TIMING images: %d images of 2000x2000 PNG, %.2f-%.2f MB (%.1f MB in all); per image (copy, media record, %d sizes: %s; editor %s): median %.2f s, slowest %.2f s, total %.1f s\n",
			count( $image ),
			min( $sizes ) / 1048576,
			max( $sizes ) / 1048576,
			array_sum( $sizes ) / 1048576,
			count( $subsize ),
			implode( ', ', $subsize ),
			str_replace( 'WP_Image_Editor_', '', (string) _wp_image_editor_choose( [ 'mime_type' => 'image/png' ] ) ),
			$median,
			max( $image ),
			array_sum( $image )
		);
		echo sprintf( "TIMING requests: %d requests with a %.0f s budget, longest %.2f s, total %.1f s\n", count( $times ), StarterImporter::DEFAULT_BUDGET_SECONDS, max( $times ), array_sum( $times ) );
	}

	/** Every request ends within the budget plus one unit of work. */
	private function budget_respected( array $times, float $allowance, string $label ): void {
		$limit = StarterImporter::DEFAULT_BUDGET_SECONDS + $allowance;
		$this->same( [], array_values( array_filter( $times, static fn( float $seconds ): bool => $seconds > $limit ) ), sprintf( '%s: every request ends within the %.0f s budget plus one unit of work (%.1f s)', $label, StarterImporter::DEFAULT_BUDGET_SECONDS, $limit ) );
	}

	/* ------------------------------------------------------------------ *
	 * Version data
	 * ------------------------------------------------------------------ */

	private function simple( array $data ): array {
		return [ 'type' => 'simple' ] + $data + [ 'description' => '', 'short_description' => '', 'regular_price' => '10.00', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [] ];
	}

	private function variable( array $data ): array {
		return [ 'type' => 'variable' ] + $data + [ 'description' => '', 'short_description' => '', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [], 'default_attributes' => [] ];
	}

	/**
	 * Seed a published version (the latest) with real image files in the
	 * frozen folder. An image spec may set its square size and a noisy area
	 * (side in pixels) that makes the file larger.
	 *
	 * @return array{version_id:int,images:array<string,array<string,mixed>>}
	 */
	private function seed( array $products, array $images, array $terms ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->db( $wpdb->insert( StarterSchema::versions_table(), [ 'status' => Versions::STATUS_PUBLISHED, 'product_count' => count( $products ), 'image_count' => count( $images ), 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => wp_json_encode( $terms + [ 'product_cat' => [], 'product_tag' => [] ] ), 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now ] ), 'seed version' );
		$version = (int) $wpdb->insert_id;

		$rows   = [];
		$source = 80000;
		foreach ( $images as $key => $spec ) {
			$relative = 'v' . $version . '/' . $spec['name'] . '.png';
			$absolute = StarterCatalogStorage::base_dir() . '/' . $relative;
			wp_mkdir_p( dirname( $absolute ) );
			$size  = (int) ( $spec['size'] ?? 200 );
			$image = imagecreatetruecolor( $size, $size );
			imagefill( $image, 0, 0, imagecolorallocate( $image, crc32( $key ) % 255, ( crc32( $key ) >> 8 ) % 255, ( crc32( $key ) >> 16 ) % 255 ) );
			$noise = (int) ( $spec['noise'] ?? 0 );
			if ( $noise > 0 ) {
				mt_srand( crc32( $key ) );
				for ( $y = 0; $y < $noise; $y++ ) {
					for ( $x = 0; $x < $noise; $x++ ) {
						imagesetpixel( $image, $x, $y, ( mt_rand( 0, 255 ) << 16 ) | ( mt_rand( 0, 255 ) << 8 ) | mt_rand( 0, 255 ) );
					}
				}
			}
			imagepng( $image, $absolute );
			imagedestroy( $image );
			$row = [ 'version_id' => $version, 'source_attachment_id' => ++$source, 'capture_status' => 'captured', 'file_path' => $relative, 'checksum' => hash_file( 'sha256', $absolute ), 'mime_type' => 'image/png', 'width' => $size, 'height' => $size, 'file_size' => filesize( $absolute ), 'alt_text' => (string) ( $spec['alt'] ?? '' ), 'created_at' => $now, 'updated_at' => $now ];
			$this->db( $wpdb->insert( StarterSchema::version_images_table(), $row ), 'seed version image' );
			$rows[ $key ] = $row + [ 'version_image_id' => (int) $wpdb->insert_id ];
		}
		$id_for = static fn( ?string $key ): int => null === $key ? 0 : (int) ( $rows[ $key ]['version_image_id'] ?? 0 );

		foreach ( array_values( $products ) as $position => $product ) {
			$parent_source = ++$this->next_source;
			$data          = [
				'name'              => $product['name'],
				'description'       => $product['description'],
				'short_description' => $product['short_description'],
				'slug'              => $product['slug'],
				'regular_price'     => (string) ( $product['regular_price'] ?? '' ),
				'sku'               => $product['sku'],
				'categories'        => $product['categories'],
				'tags'              => $product['tags'],
				'tax_status'        => $product['tax_status'],
				'tax_class'         => $product['tax_class'],
				'manage_stock'      => $product['manage_stock'],
				'image_id'          => $id_for( $product['image'] ?? null ),
				'gallery_image_ids' => array_map( $id_for, $product['gallery'] ),
			];
			if ( isset( $product['attributes'] ) ) {
				$data['attributes'] = $product['attributes'];
			}
			if ( 'variable' === $product['type'] ) {
				$data['default_attributes'] = $product['default_attributes'];
			}
			$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => $parent_source, 'product_type' => $product['type'], 'position' => $position + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $data ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version product' );

			foreach ( (array) ( $product['variations'] ?? [] ) as $index => $variation ) {
				$variation['image_id'] = $id_for( $variation['image'] ?? null );
				unset( $variation['image'] );
				$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->next_source, 'parent_source_id' => $parent_source, 'product_type' => 'variation', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $variation ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version variation' );
			}
		}

		return [ 'version_id' => $version, 'images' => $rows ];
	}

	/* ------------------------------------------------------------------ *
	 * The page's request loop and workers
	 * ------------------------------------------------------------------ */

	/** @return array{0:array{0:string,1:string},1:array<int,float>} The final result and each request's seconds. */
	private function timed_import( int $hall, int $user ): array {
		$times  = [];
		$result = $this->timed_ajax( $hall, $user, 'start', $times );
		for ( $i = 0; 'importing' === $result[0] && $i < 500; $i++ ) {
			$result = $this->timed_ajax( $hall, $user, 'continue', $times );
		}

		return [ $result, $times ];
	}

	private function timed_ajax( int $hall, int $user, string $step, array &$times ): array {
		return $this->in_hall(
			$hall,
			$user,
			static function () use ( $step, &$times ): array {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				try {
					$started = microtime( true );
					$result  = Admin::handle_ajax( [ 'action' => Admin::AJAX_ACTION, '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_import:hall:' . get_current_blog_id() ), 'step' => $step ] );
					$times[] = microtime( true ) - $started;

					return is_wp_error( $result ) ? [ 'error', $result->get_error_code() . ' ' . $result->get_error_message() ] : [ (string) $result['status'], (string) $result['text'] ];
				} finally {
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
			}
		);
	}

	/** Start one import process (the crash worker with a step that never happens). */
	private function launch_worker( int $hall, string $step ): array {
		$env    = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_HALL'       => (string) $hall,
			'BC_WORKER_USER'       => (string) $this->admin,
			'BC_WORKER_ACTION'     => 'process',
			'BC_WORKER_CRASH_STEP' => $step,
			'BC_WORKER_BUDGET'     => '600',
			'BC_STARTER_DIR'       => StarterCatalogStorage::base_dir(),
			'PATH'                 => (string) getenv( 'PATH' ),
		];
		$pipes  = [];
		$handle = proc_open( [ PHP_BINARY, __DIR__ . '/starter-import-recovery-worker.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $handle ) ) {
			throw new RuntimeException( 'Worker could not start.' );
		}

		return [ 'handle' => $handle, 'pipes' => $pipes, 'started' => microtime( true ) ];
	}

	/** @return array{result:string,ended:float} */
	private function finish_worker( array $run ): array {
		$stdout = (string) stream_get_contents( $run['pipes'][1] );
		$stderr = (string) stream_get_contents( $run['pipes'][2] );
		$ended  = microtime( true );
		fclose( $run['pipes'][1] );
		fclose( $run['pipes'][2] );
		$code    = proc_close( $run['handle'] );
		$decoded = json_decode( (string) strrchr( "\n" . trim( $stdout ), "\n" ), true );
		if ( 0 !== $code || ! is_array( $decoded ) ) {
			echo 'worker output: ' . trim( $stdout . ' ' . $stderr ) . "\n";
		}

		return [ 'result' => is_array( $decoded ) ? (string) $decoded['result'] : 'exit ' . $code, 'ended' => $ended ];
	}

	/* ------------------------------------------------------------------ *
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-scale-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter scale disposable Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) {
			throw new RuntimeException( 'Disposable Hall creation failed.' );
		}
		$this->sites[ $site ] = true;
		switch_to_blog( $site );
		try {
			update_option( self::OWNER_OPTION, $this->token );
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				( new $schema() )->register_tables( true );
			}
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'woocommerce_prices_include_tax', 'no' );
		} finally {
			restore_current_blog();
		}

		return (int) $site;
	}

	private function user( string $label, int $site, string $role ): int {
		global $wpdb;
		$id = wp_insert_user( [ 'user_login' => 'starter-scale-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Actor creation failed.' );
		}
		$this->users[] = (int) $id;
		update_user_meta( $id, self::USER_OWNER, $this->token );
		if ( ! isset( $this->sites[ $site ] ) || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) {
			throw new RuntimeException( 'Role fixture ownership refused.' );
		}
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
		if ( 1 !== $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] ) ) {
			throw new RuntimeException( 'Role fixture failed.' );
		}
		clean_user_cache( $id );

		return (int) $id;
	}

	private function in_hall( int $site, int $user, callable $work ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user );
		switch_to_blog( $site );
		try {
			return $work();
		} finally {
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function cleanup(): void {
		global $wpdb;
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( array_keys( $this->sites ) as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) {
				throw new RuntimeException( 'Cleanup ownership guard refused.' );
			}
			switch_to_blog( $site );
			$uploads = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
			restore_current_blog();
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Owned table discovery failed.' );
			}
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) {
					throw new RuntimeException( 'Owned table cleanup failed.' );
				}
			}
			$content = wp_normalize_path( WP_CONTENT_DIR );
			if ( $uploads === $content . '/blogs.dir/' . $site . '/files' ) {
				$this->remove_tree( $content . '/blogs.dir/' . $site, $content . '/blogs.dir/' . $site );
			} elseif ( $uploads === $content . '/uploads/sites/' . $site ) {
				$this->remove_tree( $uploads, $uploads );
			}
			$this->same( [ false, null ], [ is_dir( $uploads ), get_site( $site ) ], 'owned disposable Hall and its upload folder removed' );
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
	}

	/** Remove a folder only when it is exactly the expected owned path. */
	private function remove_tree( string $directory, string $expected_prefix ): void {
		$directory = wp_normalize_path( $directory );
		if ( '' === $directory || ! str_starts_with( $directory, wp_normalize_path( $expected_prefix ) ) || ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}

	/** The network tables are created only by these suites in the fixture. */
	private function reset_network(): void {
		global $wpdb;
		if ( ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture guard refused the network table reset.' );
		}
		foreach ( [ StarterSchema::VERSIONS_TABLE, StarterSchema::VERSION_PRODUCTS_TABLE, StarterSchema::VERSION_IMAGES_TABLE ] as $suffix ) {
			$this->db( $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $suffix ) ), 'drop network table' );
		}
		delete_site_option( StarterSchema::NETWORK_VERSION_OPTION );
		delete_site_option( Versions::ALLOW_IMPORTS_OPTION );
	}

	private function count_posts( string $type ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status <> 'auto-draft'", $wpdb->posts, $type ) );
	}

	/** Original image files in this Hall's uploads (generated sizes excluded). */
	private function count_original_files(): int {
		$base  = wp_upload_dir( null, false )['basedir'];
		$count = 0;
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 1 !== preg_match( '/-\d+x\d+\.[a-z]+$/', $file->getFilename() ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function product_by_slug( string $slug ): ?WC_Product {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_name = %s", $wpdb->posts, $slug ) );
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
		$product = $id > 0 ? wc_get_product( $id ) : null;

		return $product instanceof WC_Product ? $product : null;
	}

	/** Hash every Hall-local table's exact DDL and sorted serialized rows. */
	private function snapshot( int $site ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$result = [];
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Snapshot read failed.' );
			}
			$encoded = array_map( 'serialize', $rows );
			sort( $encoded, SORT_STRING );
			$ddl              = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			$result[ $table ] = hash( 'sha256', ( is_array( $ddl ) ? $ddl[1] : '' ) . serialize( $encoded ) );
		}
		ksort( $result );

		return $result;
	}

	private function db( $result, string $label ): void {
		if ( false === $result ) {
			throw new RuntimeException( 'Fixture SQL failed (' . $label . '): ' . $GLOBALS['wpdb']->last_error );
		}
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

exit( ( new StarterImportScaleTest() )->run( $bc_starter_dir ) );
