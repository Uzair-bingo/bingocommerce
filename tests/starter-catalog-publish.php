<?php

declare(strict_types=1);

/**
 * ADR-049 stage 4, slice 4.2: the Publish engine.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-publish.php
 *
 * Proves:
 *   - Start checks every included product and creates nothing when one blocks;
 *     every ADR-049 section 6 blocking rule is listed with the product's name;
 *     cleaning warnings never block;
 *   - a round trip: products shaped like the two real samples plus a simple
 *     product with attributes, categories and tags are published, then the
 *     slice 3 importer imports that version into a Hall and every field
 *     matches the main site;
 *   - the selection is saved whole: a crash right after the version row leaves
 *     a version that can only be discarded;
 *   - a crash at every capture and publication step resumes without duplicate
 *     rows or files, and image row IDs never change on retry;
 *   - a product, variation or frozen image changed during publication stops
 *     with "… changed during publication. Select Resume …", is marked pending
 *     and is captured again on Resume (an invalid one shows its problem);
 *   - a changed currency stops for Discard; Discard can be interrupted and
 *     repeated; a Discarded or Building version is never offered to Halls;
 *   - Resume uses the saved selection; one product per request with a zero
 *     budget; the publish lock and Super Admin authorization apply.
 *
 * The main site's uploads and the frozen folder are temporary folders for this
 * run only, never the real uploads folder.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_run_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-publish-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_run_dir . '/frozen', 0700, true ) || ! mkdir( $bc_run_dir . '/main-uploads', 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary folders.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_run_dir . '/frozen' );

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use BingoCommerce\Catalog\StarterCatalogMaster as Master;
use BingoCommerce\Catalog\StarterCatalogPublisher as Publisher;
use BingoCommerce\Catalog\StarterCatalogStorage;
use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportLock;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;

final class StarterCatalogPublishTest {
	private const OWNER_META   = 'bc_starter_publish_test_owner';
	private const OWNER_OPTION = 'bc_starter_publish_test_owner';
	private const USER_OWNER   = 'bc_starter_publish_test_user_owner';

	private string $token;
	private string $run_dir;
	private string $main_uploads;
	private int $admin;
	private int $main;
	private array $products    = [];
	private array $attachments = [];
	private array $terms       = [];
	private array $attributes  = [];
	private array $sites       = [];
	private array $users       = [];
	private int $passed        = 0;
	private int $failed        = 0;

	public function run( string $run_dir ): int {
		global $wpdb;

		$this->token        = strtolower( substr( wp_generate_password( 24, false, false ), 0, 8 ) );
		$this->run_dir      = $run_dir;
		$this->main_uploads = $run_dir . '/main-uploads';
		$this->main         = (int) get_main_site_id();
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->same( wp_normalize_path( $run_dir . '/frozen' ), StarterCatalogStorage::base_dir(), 'frozen images use this run\'s temporary folder' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'upload_dir', [ $this, 'main_upload_dir' ] );

		$before = [
			'products' => $this->count_main( 'product' ) + $this->count_main( 'product_variation' ) + $this->count_main( 'attachment' ),
			'imports'  => get_site_option( Versions::ALLOW_IMPORTS_OPTION, null ),
			'currency' => get_blog_option( $this->main, 'woocommerce_currency' ),
		];

		try {
			$this->same( 0, $this->count_main( 'product' ) + $this->count_main( 'product_variation' ), 'fixture main site starts with no products or variations (its WooCommerce placeholder images stay)' );
			$this->reset_network();
			update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

			$this->blockers();
			$this->round_trip();
			$this->slugs_and_categories();
			$this->category_problems();
			$this->interrupted_start();
			$this->crashes();
			$this->read_failures();
			$this->variation_delete_guard();
			$this->reused_publisher();
			$this->changed_during_publication();
			$this->settings_changed();
			$this->discard();
			$this->selection_lock_and_access();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wp_set_current_user( $this->admin );
			update_blog_option( $this->main, 'woocommerce_currency', $before['currency'] );
			$this->cleanup();
			$this->reset_network();
			null === $before['imports'] ? delete_site_option( Versions::ALLOW_IMPORTS_OPTION ) : update_site_option( Versions::ALLOW_IMPORTS_OPTION, $before['imports'] );
			remove_filter( 'upload_dir', [ $this, 'main_upload_dir' ] );
			$this->remove_tree( $run_dir, rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-publish-' );
		}

		$this->same( $before['products'], $this->count_main( 'product' ) + $this->count_main( 'product_variation' ) + $this->count_main( 'attachment' ), 'main site left with no test products, variations or images' );
		$this->same( false, is_dir( $run_dir ), 'temporary folders removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/** The main site's uploads go to this run's temporary folder. */
	public function main_upload_dir( array $dirs ): array {
		if ( (int) get_current_blog_id() !== $this->main ) {
			return $dirs;
		}
		$dirs['basedir'] = $this->main_uploads;
		$dirs['baseurl'] = 'https://uploads.invalid';
		$dirs['path']    = $this->main_uploads . $dirs['subdir'];
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}

	/* ------------------------------------------------------------------ *
	 * Blocking rules and warnings
	 * ------------------------------------------------------------------ */

	private function blockers(): void {
		$this->on_main(
			function () {
				global $wpdb;
				$versions = fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', StarterSchema::versions_table() ) );
				StarterSchema::ensure_network();

				$this->expect_blocked( [], 'No products are included in the starter catalog.', 'nothing included' );

				$good    = $this->simple( [ 'name' => 'Good product' ] );
				$main    = wp_parse_url( get_home_url( $this->main ), PHP_URL_HOST );
				$big     = $this->attachment( 'too-wide', 4001, 10 );
				$heavy   = $this->attachment( 'too-heavy', 20, 20, 'png', 5300000 );
				$bmp     = $this->attachment( 'bitmap', 20, 20, 'bmp' );
				$gone    = $this->attachment( 'gone', 20, 20 );
				wp_delete_file( (string) get_attached_file( $gone ) );
				$global  = $this->global_attribute();

				$cases = [
					'Published status'           => [ [ 'name' => 'Published one', 'status' => 'publish' ], '"Published one" is Published; it must be Draft, Pending or Private.' ],
					'downloadable'               => [ [ 'name' => 'Download one', 'downloadable' => true ], '"Download one" is downloadable.' ],
					'empty cleaned name'         => [ [ 'name' => '<span></span>' ], 'has no usable name.' ],
					'negative price'             => [ [ 'name' => 'Negative', 'raw_price' => '-5' ], '"Negative" has the price "-5", which is not a valid price.' ],
					'missing image file'         => [ [ 'name' => 'Missing image', 'image' => $gone ], "\"Missing image\" uses image #{$gone}, which is missing." ],
					'BMP image'                  => [ [ 'name' => 'Bitmap', 'image' => $bmp ], '"Bitmap" uses the image "bitmap.bmp", which is not a JPEG, PNG, WebP or GIF image.' ],
					'image over 4,000 px'        => [ [ 'name' => 'Wide', 'gallery' => [ $big ] ], '"Wide" uses the image "too-wide.png", which is larger than 4,000 pixels on a side.' ],
					'image over 5 MB'            => [ [ 'name' => 'Heavy', 'image' => $heavy ], '"Heavy" uses the image "too-heavy.png", which is larger than 5 MB.' ],
					'embedded image'             => [ [ 'name' => 'Embedded', 'description' => '<p>Look <img src="https://example.org/a.png" alt=""></p>' ], '"Embedded" description contains an embedded image.' ],
					'link to the main site'      => [ [ 'name' => 'Linked', 'short' => '<a href="https://' . $main . '/shop/">Shop</a>' ], '"Linked" short description links to the main site.' ],
					'shortcode'                  => [ [ 'name' => 'Shortcoded', 'description' => 'Best sellers: [products limit="4"]' ], '"Shortcoded" description contains a shortcode.' ],
					'global attribute'           => [ [ 'name' => 'Global', 'global_attribute' => $global ], '"Global" uses the global attribute "' ],
					'grouped product'            => [ [ 'name' => 'Grouped one', 'grouped' => true ], '"Grouped one" is not a simple or variable product.' ],
				];
				foreach ( $cases as $label => [ $args, $message ] ) {
					$id = $this->simple( $args + [ 'include' => false ] );
					$this->only_include( [ $id ] );
					$this->expect_blocked( [ $id ], $message, $label );
				}

				$variable = $this->variable( [ 'name' => 'No variations', 'variations' => [] ] );
				$this->only_include( [ $variable ] );
				$this->expect_blocked( [ $variable ], '"No variations" has no variations.', 'variable product with no variations' );

				$bad_variations = $this->variable(
					[
						'name'       => 'Bad variations',
						'variations' => [
							[ 'color' => 'Red', 'downloadable' => true ],
							[ 'color' => 'Blue', 'raw_price' => '-1' ],
							[ 'color' => 'Green', 'status' => 'draft' ],
							[ 'color' => 'Pink', 'image' => $gone ],
						],
					]
				);
				$this->only_include( [ $bad_variations ] );
				$result = ( new Publisher() )->start();
				$problems = is_array( $result ) ? $result['problems'] : [];
				$this->same(
					[ true, true, true, true ],
					[
						in_array( '"Bad variations, variation Red" is downloadable.', $problems, true ),
						in_array( '"Bad variations, variation Blue" has the price "-1", which is not a valid price.', $problems, true ),
						in_array( '"Bad variations, variation Green" is neither enabled nor disabled.', $problems, true ),
						in_array( "\"Bad variations, variation Pink\" uses image #{$gone}, which is missing.", $problems, true ),
					],
					'every variation problem is listed: downloadable, price, status, image'
				);

				$this->only_include( [ $good, $variable ] );
				$both = ( new Publisher() )->start();
				$this->same( [ 'blocked', 1 ], [ $both['status'] ?? '', count( $both['problems'] ?? [] ) ], 'a good and a bad product: blocked, listing only the bad one' );
				$this->same( 0, $versions(), 'no version was created by any blocked Start' );

				$warned = $this->simple( [ 'name' => 'Warned <script>x</script>', 'description' => '<p>Hi</p><script>alert(1)</script>' ] );
				$this->only_include( [ $warned ] );
				$started = ( new Publisher() )->start();
				$this->same( 'building', $started['status'] ?? '', 'cleaning warnings do not block' );
				$this->same( [ '"Warned x" name lost content WordPress does not allow.', '"Warned x" description lost content WordPress does not allow.' ], $started['warnings'] ?? [], 'warnings name the product and field' );
				$published = $this->finish();
				$data      = $this->top_data( (int) $started['version_id'] )[0] ?? [];
				$this->same( [ 'published', 'Warned x', '<p>Hi</p>alert(1)' ], [ $published['status'] ?? '', $data['name'] ?? '', $data['description'] ?? '' ], 'the version saves the cleaned name and description' );
				$this->only_include( [] );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Round trip: publish, then import into a Hall
	 * ------------------------------------------------------------------ */

	private function round_trip(): void {
		$expected = $this->on_main(
			function () {
				$supplies = $this->term( 'Supplies', 'product_cat' );
				$sets     = $this->term( 'Bingo Sets', 'product_cat', $supplies );
				$tag      = $this->term( 'Daubers', 'product_tag' );

				$on_colors  = [ 'Blue', 'Orange', 'Green', 'Yellow', 'Pink', 'Grey', 'Olive', 'Brown', 'Red', 'Purple', 'Black', 'Aqua', 'Lime', 'Tan', 'White' ];
				$dab_colors = [ 'Blue', 'Green', 'Magenta', 'Orange', 'Periwinkle', 'Pink', 'Purple', 'Red', 'Ruby Red', 'Aqua', 'Violet', 'Yellow', 'Assorted' ];
				$on_images  = array_map( fn( string $c ): int => $this->attachment( '1on' . str_replace( ' ', '', $c ), 60, 60, 'png', 0, "1on {$c}" ), $on_colors );
				$dab_images = array_map( fn( string $c ): int => $this->attachment( str_replace( ' ', '_', $c ) . '_Dab-O-Ink', 60, 60, 'png', 0, "Dab O Ink {$c}" ), $dab_colors );

				$on = $this->variable(
					[
						'name'        => '1on Bingo Paper By The Case 9,000 Sheets',
						'slug'        => 'publish-test-1on-' . $this->token,
						'description' => 'Premium 1on Bingo Paper with <strong>same color per case</strong>.',
						'sku'         => '1on-case-' . $this->token,
						'image'       => $on_images[0],
						'gallery'     => array_slice( $on_images, 1 ),
						'categories'  => [ $sets ],
						'tags'        => [ $tag ],
						'variations'  => array_map( fn( string $c, int $i ): array => [ 'color' => $c, 'price' => '36.15', 'sku' => sprintf( 'S01%02dB-%s', $i + 1, $this->token ), 'stock' => true, 'tax_class' => 'parent', 'image' => $on_images[ $i ], 'description' => 0 === $i ? '<p>Ink "quote" it\'s</p>' : '' ], $on_colors, array_keys( $on_colors ) ),
					]
				);
				$dab = $this->variable(
					[
						'name'       => 'Dab O Ink 3 Ounce By the Bottle',
						'description' => "\n<h2>Dab O Ink Bingo Dauber</h2>\n<ul>\n<li>Vibrant ink</li>\n</ul>",
						'image'      => $dab_images[0],
						'gallery'    => array_slice( $dab_images, 1 ),
						'categories' => [ $sets ],
						'defaults'   => [ 'color' => 'Blue' ],
						'variations' => array_map( fn( string $c, int $i ): array => [ 'color' => $c, 'price' => 'Assorted' === $c ? '13.12' : '1.06', 'stock' => 'Assorted' !== $c, 'tax_class' => 'parent', 'image' => $dab_images[ $i ] ], $dab_colors, array_keys( $dab_colors ) ),
					]
				);
				$simple = $this->simple(
					[
						'name'        => 'Paper pack',
						'slug'        => 'publish-test-paper-' . $this->token,
						'short'       => 'Pack of <em>500</em>',
						'price'       => '4.50',
						'sku'         => 'paper-' . $this->token,
						'stock'       => true,
						'tax_status'  => 'shipping',
						'categories'  => [ $supplies ],
						'tags'        => [ $tag ],
						'attributes'  => [ [ 'Material', [ 'Paper' ], true ], [ 'Sheets', [ '500', '1000' ], false ] ],
						'image'       => $on_images[3],
					]
				);
				$this->only_include( [ $on, $dab, $simple ] );

				$started = ( new Publisher() )->start();
				$this->same( [ 'building', 3, 0, [] ], [ $started['status'] ?? '', $started['total'] ?? 0, $started['captured'] ?? -1, $started['problems'] ?? null ], 'Start: three products selected, nothing captured yet' );
				$this->same( true, (int) ( Versions::latest_published()['version_id'] ?? 0 ) !== (int) $started['version_id'], 'a Building version is not offered to Halls' );

				$steps = [];
				for ( $i = 0; $i < 10; $i++ ) {
					$state   = ( new Publisher() )->continue_build( 0.0 );
					$steps[] = ( $state['status'] ?? 'error' ) . ':' . ( $state['captured'] ?? '' );
					if ( 'building' !== ( $state['status'] ?? '' ) ) {
						break;
					}
				}
				$this->same( [ 'building:1', 'building:2', 'building:3', 'published:3' ], $steps, 'a zero budget captures one product per request, then publishes' );

				return [ 'on' => $on, 'dab' => $dab, 'simple' => $simple, 'version' => (int) $started['version_id'], 'images' => count( $on_images ) + count( $dab_images ) ];
			}
		);
		if ( ! is_array( $expected ) ) {
			return;
		}

		$this->check_version( $expected );
		$this->check_import( $expected );
	}

	private function check_version( array $expected ): void {
		global $wpdb;

		$version = Versions::get( $expected['version'] );
		$this->same( [ 'published', 3, $expected['images'], 'USD', 'no', $this->admin ], [ $version['status'] ?? '', (int) ( $version['product_count'] ?? 0 ), (int) ( $version['image_count'] ?? 0 ), $version['currency'] ?? '', $version['prices_include_tax'] ?? '', (int) ( $version['published_by'] ?? 0 ) ], 'the version is Published with its counts, currency, tax setting and publisher' );
		$this->same( $expected['version'], (int) ( Versions::latest_published()['version_id'] ?? 0 ), 'it is now the latest published version' );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d', StarterSchema::version_products_table(), $expected['version'] ), ARRAY_A );
		$top  = array_values( array_filter( $rows, static fn( array $r ): bool => null === $r['parent_source_id'] ) );
		$this->same( [ 3, 28 ], [ count( $top ), count( $rows ) - count( $top ) ], 'three product rows and 28 variation rows' );
		$this->same( [ 1, 2, 3 ], array_map( 'intval', array_column( $top, 'position' ) ), 'products keep the saved order' );
		$this->same( [ 'captured' ], array_values( array_unique( array_column( $rows, 'capture_status' ) ) ), 'every row captured' );

		$images = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d', StarterSchema::version_images_table(), $expected['version'] ), ARRAY_A );
		$this->same( $expected['images'], count( $images ), 'one image row per source image, shared images copied once' );
		$files_ok = true;
		foreach ( $images as $image ) {
			$file      = StarterCatalogStorage::frozen_path( (string) $image['file_path'] );
			$files_ok  = $files_ok && is_string( $file ) && is_file( $file ) && hash_equals( (string) $image['checksum'], (string) hash_file( 'sha256', $file ) )
				&& 1 === preg_match( '#^v' . $expected['version'] . '/' . (int) $image['source_attachment_id'] . '/[A-Za-z0-9._-]+\.png$#', (string) $image['file_path'] );
		}
		$this->same( true, $files_ok, 'every frozen file is at v<version>/<image>/<name>.png with its checksum' );
		$this->same( 0, $this->count_files( StarterCatalogStorage::base_dir(), '.part' ), 'no temporary copies left' );

		$terms = Versions::terms( $version );
		$cats  = array_column( $terms['product_cat'], 'parent', 'slug' );
		$this->same( [ 'supplies-' . $this->token => '', 'bingo-sets-' . $this->token => 'supplies-' . $this->token ], [ 'supplies-' . $this->token => $cats[ 'supplies-' . $this->token ] ?? 'missing', 'bingo-sets-' . $this->token => $cats[ 'bingo-sets-' . $this->token ] ?? 'missing' ], 'the version keeps the category tree' );
		$this->same( [ 'daubers-' . $this->token ], array_column( $terms['product_tag'], 'slug' ), 'and the tag list' );

		$audit = $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id, actor_user_id FROM %i WHERE action = %s AND target_id = %d', \BingoCommerce\Core\Migrations::audit_log_table(), 'starter_catalog.version_published', $expected['version'] ), ARRAY_A );
		$this->same( [ [ 'blog_id' => null, 'actor_user_id' => (string) $this->admin ] ], $audit, 'one audit event, network-scoped (blog_id null), by the publisher' );
	}

	private function check_import( array $expected ): void {
		$hall = $this->create_hall();
		$this->in_site(
			$hall,
			function () {
				( new StarterImporter() )->start();
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'the slice 3 importer imports the published version into a Hall' );
			}
		);

		$pairs = [ [ $expected['on'], 'publish-test-1on-' . $this->token, [ 15, 14 ] ], [ $expected['dab'], '', [ 13, 12 ] ], [ $expected['simple'], 'publish-test-paper-' . $this->token, [ 0, 0 ] ] ];
		foreach ( $pairs as [ $source_id, $slug, $shape ] ) {
			$source = $this->on_main( fn() => $this->snapshot_product( $source_id ) );
			if ( '' === $slug ) {
				$slug = sanitize_title( (string) $source['name'] );
			}
			$copy = $this->in_site( $hall, fn() => $this->snapshot_product( $this->product_id_by_slug( $slug ) ) );
			unset( $source['status'], $copy['status'] );
			$this->same( $shape, [ count( $copy['variations'] ?? [] ), count( $copy['gallery'] ?? [] ) ], "Hall copy of \"{$source['name']}\" has its variations and gallery" );
			$this->same( $source, $copy, "Hall copy of \"{$source['name']}\" matches the main site field for field" );
		}
	}

	/** Every field the import copies, in comparable form (images by alt text). */
	private function snapshot_product( int $id ): array {
		clean_post_cache( $id );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return [ 'missing' => $id ];
		}
		$alt      = static fn( int $image ): string => $image > 0 ? (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) : '';
		$category = static function ( int $term_id ): string {
			$term   = get_term( $term_id, 'product_cat' );
			$parent = $term instanceof WP_Term && $term->parent > 0 ? get_term( $term->parent, 'product_cat' ) : null;

			return ( $term instanceof WP_Term ? $term->slug : '?' ) . '<' . ( $parent instanceof WP_Term ? $parent->slug : '' );
		};
		$tags     = array_map( static fn( int $t ): string => (string) get_term( $t, 'product_tag' )->slug, $product->get_tag_ids( 'edit' ) );
		sort( $tags );
		$snap = [
			'type'        => $product->get_type(),
			'status'      => $product->get_status( 'edit' ),
			'name'        => $product->get_name( 'edit' ),
			'description' => $product->get_description( 'edit' ),
			'short'       => $product->get_short_description( 'edit' ),
			'price'       => $product->get_regular_price( 'edit' ),
			'sku'         => $product->get_sku( 'edit' ),
			'tax'         => [ $product->get_tax_status( 'edit' ), $product->get_tax_class( 'edit' ) ],
			'stock'       => (bool) $product->get_manage_stock( 'edit' ),
			'categories'  => array_map( $category, $product->get_category_ids( 'edit' ) ),
			'tags'        => $tags,
			'image'       => $alt( (int) $product->get_image_id( 'edit' ) ),
			'gallery'     => array_map( $alt, array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) ),
			'attributes'  => array_map( static fn( WC_Product_Attribute $a ): array => [ $a->get_name(), $a->get_options(), $a->get_visible(), $a->get_variation(), $a->get_position(), $a->is_taxonomy() ], array_values( $product->get_attributes( 'edit' ) ) ),
			'defaults'    => $product->get_default_attributes( 'edit' ),
			'variations'  => [],
		];
		if ( $product instanceof WC_Product_Variable ) {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY menu_order ASC, ID ASC", $wpdb->posts, $id ) );
			foreach ( $ids as $variation_id ) {
				clean_post_cache( (int) $variation_id );
				$v                    = wc_get_product( (int) $variation_id );
				$snap['variations'][] = [ $v->get_attributes( 'edit' ), $v->get_regular_price( 'edit' ), $v->get_sku( 'edit' ), $v->get_status( 'edit' ), $v->get_tax_class( 'edit' ), (bool) $v->get_manage_stock( 'edit' ), $v->get_description( 'edit' ), $alt( (int) $v->get_image_id( 'edit' ) ) ];
			}
		}

		return $snap;
	}

	/* ------------------------------------------------------------------ *
	 * Crashes
	 * ------------------------------------------------------------------ */

	/** The selection is saved whole: a crash after the version row leaves only Discard. */
	private function interrupted_start(): void {
		$this->on_main( fn() => $this->only_include( $this->small_catalog() ) );
		$this->same( 9, $this->worker( 'start', 'version_created' ), 'Start crashed right after creating the version row' );
		$this->on_main(
			function () {
				$state = Publisher::state();
				$this->same( [ true, false, 0 ], [ is_array( $state['building'] ?? null ), $state['building']['resumable'] ?? null, $state['building']['total'] ?? -1 ], 'a Building version with no selection rows is not resumable' );
				$this->same( 'starter_catalog_selection_missing', $this->code( ( new Publisher() )->continue_build( 600.0 ) ), 'Resume refuses it: "Discard it and publish again"' );
				$this->same( 'starter_catalog_build_exists', $this->code( ( new Publisher() )->start() ), 'a new Start waits for it to be discarded' );
				$this->same( 'discarded', ( new Publisher() )->discard( (int) $state['building']['version_id'] )['status'] ?? '', 'Discard removes it' );
				$this->same( 'building', ( new Publisher() )->start()['status'] ?? '', 'then Start works' );
				$this->same( 'published', $this->finish()['status'] ?? '', 'and publishes' );
			}
		);
	}

	private function crashes(): void {
		global $wpdb;

		foreach ( [ 'image_copied', 'image_saved', 'variations_saved', 'product_captured', 'finalize_checked' ] as $step ) {
			$version = $this->on_main( fn() => (int) ( ( new Publisher() )->start()['version_id'] ?? 0 ) );
			$this->same( 9, $this->worker( 'continue', $step, 600.0 ), "worker crashed at {$step}" );
			$ids_before = $wpdb->get_col( $wpdb->prepare( 'SELECT version_image_id FROM %i WHERE version_id = %d ORDER BY version_image_id', StarterSchema::version_images_table(), $version ) );
			$result     = $this->on_main( fn() => $this->finish() );
			$this->same( 'published', $result['status'] ?? $this->code( $result ), "after a crash at {$step}, Resume publishes" );
			$this->same( [ 2, 3, 3 ], $this->version_counts( $version ), "after a crash at {$step}: two products, three variations, three images (no duplicates)" );
			$ids_after = $wpdb->get_col( $wpdb->prepare( 'SELECT version_image_id FROM %i WHERE version_id = %d ORDER BY version_image_id', StarterSchema::version_images_table(), $version ) );
			$this->same( true, [] === array_diff( $ids_before, $ids_after ), "after a crash at {$step}: image rows kept their IDs" );
			$this->same( [ 3, 0 ], [ $this->count_files( StarterCatalogStorage::base_dir() . '/v' . $version, '' ), $this->count_files( StarterCatalogStorage::base_dir() . '/v' . $version, '.part' ) ], "after a crash at {$step}: three frozen files, no temporary copies" );
		}

		$version = $this->on_main( fn() => (int) ( ( new Publisher() )->start()['version_id'] ?? 0 ) );
		$this->same( 9, $this->worker( 'continue', 'published', 600.0 ), 'worker crashed right after publication' );
		$this->on_main(
			function () use ( $version ) {
				$this->same( 'published', Versions::get( $version )['status'] ?? '', 'the version is published' );
				$this->same( 'idle', ( new Publisher() )->continue_build( 600.0 )['status'] ?? '', 'nothing left to resume' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Changed during publication
	 * ------------------------------------------------------------------ */

	private function changed_during_publication(): void {
		$this->on_main(
			function () {
				global $wpdb;
				[ $variable, $simple ] = $this->small_catalog();

				// A product edited after it was captured.
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				( new Publisher() )->continue_build( 0.0 );
				$wpdb->update( $wpdb->posts, [ 'post_title' => 'Small dauber renamed', 'post_modified_gmt' => '2001-01-01 00:00:00' ], [ 'ID' => $variable ] );
				clean_post_cache( $variable );
				$changed = ( new Publisher() )->continue_build( 0.0 );
				$this->same( [ 'changed', [ 'Small dauber changed during publication. Select Resume to capture it again.' ] ], [ $changed['status'] ?? '', $changed['problems'] ?? [] ], 'an edited product stops publication with the agreed message' );
				$this->same( 'pending', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT capture_status FROM %i WHERE version_id = %d AND source_product_id = %d', StarterSchema::version_products_table(), $version, $variable ) ), 'it is marked pending' );
				$this->same( [ 'Small dauber', 'Small simple' ], array_column( $this->top_data( $version ), 'name' ), 'the saved selection and order are kept' );
				$this->same( 'published', $this->finish()['status'] ?? '', 'Resume captures it again and publishes' );
				$this->same( 'Small dauber renamed', $this->top_data( $version )[0]['name'] ?? '', 'the version has the edited name' );
				$wpdb->update( $wpdb->posts, [ 'post_title' => 'Small dauber' ], [ 'ID' => $variable ] );
				clean_post_cache( $variable );

				// A variation added, then one removed, after its product was
				// captured; the parent's own row does not change either time.
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				$purple = $this->add_variation( $variable, 'Small Purple' );
				( new Publisher() )->continue_build( 0.0 );
				$this->same( 'changed', ( new Publisher() )->continue_build( 0.0 )['status'] ?? '', 'a variation added during publication stops it (parent row unchanged)' );
				$this->same( 'published', $this->finish()['status'] ?? '', 'Resume publishes' );
				$this->same( [ 2, 4, 4 ], $this->version_counts( $version ), 'with the new variation and its image' );

				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				$wpdb->update( $wpdb->posts, [ 'post_status' => 'trash' ], [ 'ID' => $purple ] );
				clean_post_cache( $purple );
				( new Publisher() )->continue_build( 0.0 );
				$this->same( 'changed', ( new Publisher() )->continue_build( 0.0 )['status'] ?? '', 'a variation removed during publication stops it (parent row unchanged)' );
				$this->same( 'published', $this->finish()['status'] ?? '', 'Resume publishes' );
				$this->same( [ 2, 3, 3 ], $this->version_counts( $version ), 'without that variation; its image is no longer in the version' );

				// A product that became invalid shows its problem on Resume.
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				( new Publisher() )->continue_build( 0.0 );
				$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish', 'post_modified_gmt' => '2001-01-02 00:00:00' ], [ 'ID' => $simple ] );
				clean_post_cache( $simple );
				$this->same( 'changed', ( new Publisher() )->continue_build( 0.0 )['status'] ?? '', 'a product published during publication stops it' );
				$blocked = ( new Publisher() )->continue_build( 0.0 );
				$this->same( [ 'blocked', [ '"Small simple" is Published; it must be Draft, Pending or Private.' ] ], [ $blocked['status'] ?? '', $blocked['problems'] ?? [] ], 'Resume shows the validation problem' );
				$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $simple ] );
				clean_post_cache( $simple );
				$this->same( 'published', $this->finish()['status'] ?? '', 'once fixed, Resume publishes' );

				// A frozen image changed after capture.
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				( new Publisher() )->continue_build( 0.0 );
				$image = $wpdb->get_row( $wpdb->prepare( 'SELECT version_image_id, file_path FROM %i WHERE version_id = %d ORDER BY version_image_id LIMIT 1', StarterSchema::version_images_table(), $version ), ARRAY_A );
				file_put_contents( StarterCatalogStorage::frozen_path( (string) $image['file_path'] ), 'tampered' );
				$this->same( 'changed', ( new Publisher() )->continue_build( 0.0 )['status'] ?? '', 'a changed frozen image stops publication' );
				$this->same( 'published', $this->finish()['status'] ?? '', 'Resume copies it again and publishes' );
				$this->same( (int) $image['version_image_id'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT version_image_id FROM %i WHERE version_id = %d AND file_path = %s', StarterSchema::version_images_table(), $version, $image['file_path'] ) ), 'the image row kept its ID' );
				$this->same( [ 2, 3, 3 ], $this->version_counts( $version ), 'nothing duplicated' );
			}
		);
	}

	private function settings_changed(): void {
		$this->on_main(
			function () {
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );
				( new Publisher() )->continue_build( 0.0 );
				update_option( 'woocommerce_currency', 'CAD' );
				$this->same( 'starter_catalog_settings_changed', $this->code( ( new Publisher() )->continue_build( 0.0 ) ), 'a currency change during publication stops it for Discard' );
				$this->same( 'building', Versions::get( $version )['status'] ?? '', 'the version is not published' );
				update_option( 'woocommerce_currency', 'USD' );
				$this->same( 'discarded', ( new Publisher() )->discard( $version )['status'] ?? '', 'it is discarded' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Discard
	 * ------------------------------------------------------------------ */

	private function discard(): void {
		global $wpdb;

		$version = $this->on_main(
			function () {
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				( new Publisher() )->continue_build( 0.0 );

				return $version;
			}
		);
		$this->same( [ true, true ], [ $this->version_counts( $version )[0] > 0, is_dir( StarterCatalogStorage::base_dir() . '/v' . $version ) ], 'a partly captured version has rows and files' );
		$this->same( 9, $this->worker( 'discard', 'discard_marked', 0.0, $version ), 'Discard crashed right after marking the version Discarded' );

		$this->on_main(
			function () use ( $version ) {
				global $wpdb;
				$this->same( 'discarded', Versions::get( $version )['status'] ?? '', 'the version is Discarded although its rows remain' );
				$state = Publisher::state();
				$this->same( [ null, [ $version ] ], is_array( $state ) ? [ $state['building'], $state['cleanup'] ] : [ 'error' ], 'it is never resumable; the page can offer Finish Discard' );
				$this->same( 'idle', ( new Publisher() )->continue_build( 600.0 )['status'] ?? '', 'Resume has nothing to publish' );
				$this->same( 'discarded', ( new Publisher() )->discard( $version )['status'] ?? '', 'Finish Discard completes the cleanup' );
				$this->same( [ [ 0, 0, 0 ], false, [] ], [ $this->version_counts( $version ), is_dir( StarterCatalogStorage::base_dir() . '/v' . $version ), Publisher::state()['cleanup'] ?? null ], 'no rows, no folder, nothing left to clean' );
				$this->same( 'discarded', ( new Publisher() )->discard( $version )['status'] ?? '', 'repeating Discard is harmless' );

				$published = (int) ( Versions::latest_published()['version_id'] ?? 0 );
				$this->same( 'starter_catalog_not_discardable', $this->code( ( new Publisher() )->discard( $published ) ), 'a published version cannot be discarded' );
				$this->same( 'published', Versions::get( $published )['status'] ?? '', 'it stays published' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Saved selection, lock and access
	 * ------------------------------------------------------------------ */

	private function selection_lock_and_access(): void {
		$this->on_main(
			function () {
				[ $variable, $simple ] = $this->small_catalog();
				$late                  = $this->simple( [ 'name' => 'Late addition' ] );
				$this->only_include( [ $variable, $simple ] );

				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				$this->only_include( [ $simple, $late ] );
				$this->same( 'published', $this->finish()['status'] ?? '', 'publication continues after the flags change' );
				$this->same( [ 'Small dauber', 'Small simple' ], array_column( $this->top_data( $version ), 'name' ), 'Resume used the saved selection: an unticked product stays, a newly ticked one is not added' );
			}
		);

		$lock = StarterImportLock::acquire_publish();
		$this->same( true, $lock instanceof StarterImportLock, 'this process holds the publish lock' );
		$this->same( 0, $this->worker( 'start', '' ), 'a second request runs' );
		$this->same( 'starter_import_already_running', $this->last_worker_result, 'and gets "already running"' );
		if ( $lock instanceof StarterImportLock ) {
			$lock->release();
		}

		$manager = $this->user( 'mgr', $this->main, 'shop_manager' );
		$this->in_site(
			$this->main,
			function () {
				$this->same(
					[ 'starter_catalog_denied', 'starter_catalog_denied', 'starter_catalog_denied' ],
					[ $this->code( ( new Publisher() )->start() ), $this->code( ( new Publisher() )->continue_build() ), $this->code( ( new Publisher() )->discard( 1 ) ) ],
					'a main-site shop manager cannot start, resume or discard'
				);
			},
			$manager
		);
	}

	/* ------------------------------------------------------------------ *
	 * Review fixes: slugs, category ancestry, failed reads, the variation
	 * DELETE and the per-call image cache
	 * ------------------------------------------------------------------ */

	/** Slug fallback and valid category trees, published and imported. */
	private function slugs_and_categories(): void {
		$setup = $this->on_main(
			function () {
				$root   = $this->term( 'Shared root', 'product_cat' );
				$left   = $this->term( 'Left branch', 'product_cat', $root );
				$right  = $this->term( 'Right branch', 'product_cat', $root );
				$parent = 0;
				for ( $level = 0; $level <= StarterImporter::MAX_CATEGORY_DEPTH; $level++ ) {
					$parent = $this->term( 'Level ' . $level, 'product_cat', $parent );
				}

				$marks = $this->simple( [ 'name' => '???', 'categories' => [ $left, $right ], 'include' => false ] );
				$plain = $this->simple( [ 'name' => 'Ordinary draft ' . $this->token, 'categories' => [ $parent ], 'include' => false ] );
				$named = $this->simple( [ 'name' => 'Has a slug', 'slug' => 'publish-test-explicit-' . $this->token, 'include' => false ] );
				$this->only_include( [ $marks, $plain, $named ] );

				$this->same( 21, count( $this->ancestry( $parent ) ), 'the deepest accepted chain: a category and ' . StarterImporter::MAX_CATEGORY_DEPTH . ' ancestors' );
				$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				$this->same( 'published', $this->finish()['status'] ?? '', 'a shared ancestor and the deepest accepted chain publish' );
				$slugs = array_column( $this->top_data( $version ), 'slug' );
				$this->same( [ 'starter-product-' . $marks, 'ordinary-draft-' . $this->token, 'publish-test-explicit-' . $this->token ], $slugs, 'frozen slugs: starter-product-<source ID> for "???", from the name, the source slug' );
				$cats = array_column( Versions::terms( Versions::get( $version ) )['product_cat'], 'slug' );
				$this->same( 1, count( array_keys( $cats, 'shared-root-' . $this->token, true ) ), 'the shared ancestor is saved once' );

				return [ 'marks' => $marks, 'plain' => $plain, 'named' => $named, 'deep' => $parent, 'slugs' => $slugs ];
			}
		);
		if ( ! is_array( $setup ) ) {
			return;
		}

		$hall = $this->create_hall();
		$this->in_site( $hall, fn() => [ ( new StarterImporter() )->start(), $this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'the Hall imports that version' ) ] );
		foreach ( [ 'marks', 'plain', 'named' ] as $index => $key ) {
			$source = $this->on_main( fn() => $this->snapshot_product( $setup[ $key ] ) );
			$copy   = $this->in_site( $hall, fn() => $this->snapshot_product( $this->product_id_by_slug( $setup['slugs'][ $index ] ) ) );
			unset( $source['status'], $copy['status'] );
			$this->same( $source, $copy, "imported by its frozen slug {$setup['slugs'][ $index ]}, matching the main site" );
		}
		$main_chain = $this->on_main( fn() => $this->ancestry( $setup['deep'] ) );
		$hall_chain = $this->in_site( $hall, fn() => $this->ancestry( (int) ( wc_get_product( $this->product_id_by_slug( $setup['slugs'][1] ) )->get_category_ids( 'edit' )[0] ?? 0 ) ) );
		$this->same( $main_chain, $hall_chain, 'the Hall has the whole 21-level category chain' );
	}

	/** Category chains the importer cannot rebuild are refused before publication. */
	private function category_problems(): void {
		$this->on_main(
			function () {
				global $wpdb;

				$parent = 0;
				for ( $level = 0; $level <= StarterImporter::MAX_CATEGORY_DEPTH + 1; $level++ ) {
					$parent = $this->term( 'Too deep ' . $level, 'product_cat', $parent );
				}
				$id = $this->simple( [ 'name' => 'Too deep', 'categories' => [ $parent ], 'include' => false ] );
				$this->only_include( [ $id ] );
				$this->expect_blocked( [ $id ], '"Too deep" is in the category "too-deep-21-' . $this->token . '", which is nested more than 20 levels deep.', 'one level beyond the deepest accepted chain' );

				$x  = $this->term( 'Loop x', 'product_cat' );
				$y  = $this->term( 'Loop y', 'product_cat', $x );
				$id = $this->simple( [ 'name' => 'Looped', 'categories' => [ $y ], 'include' => false ] );
				$this->set_term_parent( $x, $y );
				try {
					$this->only_include( [ $id ] );
					$this->expect_blocked( [ $id ], '"Looped" is in the category "loop-y-' . $this->token . '", whose parent categories form a loop.', 'a parent loop' );
				} finally {
					$this->set_term_parent( $x, 0 );
				}

				$z  = $this->term( 'Orphan z', 'product_cat' );
				$id = $this->simple( [ 'name' => 'Orphaned', 'categories' => [ $z ], 'include' => false ] );
				$this->set_term_parent( $z, 999999999 );
				try {
					$this->only_include( [ $id ] );
					$this->expect_blocked( [ $id ], '"Orphaned" is in the category "orphan-z-' . $this->token . '", whose parent category #999999999 could not be read.', 'a parent that cannot be read' );
				} finally {
					$this->set_term_parent( $z, 0 );
				}
				$this->only_include( [] );
			}
		);
	}

	/** A failed read stops safely: nothing marked, removed or published. */
	private function read_failures(): void {
		$this->on_main(
			function () {
				global $wpdb;
				$products = StarterSchema::version_products_table();
				$images   = StarterSchema::version_images_table();
				$cases    = [
					'final check: product rows (then a successful image read)' => static fn( string $q, int $v ): bool => $q === sprintf( 'SELECT * FROM `%s` WHERE version_id = %d ORDER BY position ASC, version_product_id ASC', $products, $v ),
					'final check: image rows'                                  => static fn( string $q, int $v ): bool => $q === sprintf( 'SELECT * FROM `%s` WHERE version_id = %d', $images, $v ),
					'final check: current products'                            => static fn( string $q ): bool => str_starts_with( $q, 'SELECT ID, post_type, post_status, post_modified_gmt FROM' ),
					'final check: current variations'                          => static fn( string $q ): bool => str_starts_with( $q, 'SELECT ID, post_parent FROM' ),
				];
				foreach ( $cases as $label => $matcher ) {
					$this->small_catalog();
					$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
					( new Publisher() )->continue_build( 0.0 );
					( new Publisher() )->continue_build( 0.0 );
					$before = $this->build_snapshot( $version );

					$result = $this->with_fault( static fn( string $q ): bool => $matcher( $q, $version ), fn() => ( new Publisher() )->continue_build( 600.0 ) );
					$this->same( 'starter_catalog_db_error', $this->code( $result ), "{$label}: a failed read stops with an error" );
					$this->same( $before, $this->build_snapshot( $version ), "{$label}: still Building; product rows, image rows and frozen files unchanged" );
					$this->same( 'published', $this->finish()['status'] ?? '', "{$label}: Resume publishes once the fault is gone" );
				}

				$versions = StarterSchema::versions_table();
				foreach (
					[
						'discarded versions with rows' => static fn( string $q ): bool => str_starts_with( $q, 'SELECT v.version_id FROM' ),
						'all discarded versions'       => static fn( string $q ): bool => $q === sprintf( "SELECT version_id FROM `%s` WHERE status = 'discarded'", $versions ),
					] as $label => $matcher
				) {
					$this->same( 'starter_catalog_db_error', $this->code( $this->with_fault( $matcher, static fn() => Publisher::state() ) ), "state(): a failed read of {$label} is an error, not an empty list" );
				}
			}
		);
	}

	/** Replacing variation rows never deletes a saved top-level selection row. */
	private function variation_delete_guard(): void {
		$this->on_main(
			function () {
				global $wpdb;
				[ $variable ] = $this->small_catalog();
				$version      = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
				$variation    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ( 'publish', 'private' ) ORDER BY menu_order ASC, ID ASC LIMIT 1", $wpdb->posts, $variable ) );
				$now          = current_time( 'mysql', true );
				$wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => $variation, 'product_type' => 'simple', 'position' => 99, 'capture_status' => 'pending', 'created_at' => $now, 'updated_at' => $now ] );
				$rows = fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id = %d AND parent_source_id IS NULL', StarterSchema::version_products_table(), $version ) );
				$this->same( 3, $rows(), 'a saved top-level row shares its source ID with one of the variations' );

				$result = $this->with_fault( static fn(): bool => false, fn() => ( new Publisher() )->continue_build( 600.0 ) );
				$this->same( 'starter_catalog_db_error', $this->code( $result ), 'the conflict fails safely' );
				$this->same( 3, $rows(), 'the saved selection is unchanged: the top-level row was not deleted' );
				$this->same( 'discarded', ( new Publisher() )->discard( $version )['status'] ?? '', 'the version is discarded' );
			}
		);
	}

	/** One Publisher object across calls re-checks frozen images every call. */
	private function reused_publisher(): void {
		$this->on_main(
			function () {
				global $wpdb;
				$this->small_catalog();
				$publisher = new Publisher();
				$version   = (int) ( $publisher->start()['version_id'] ?? 0 );
				$publisher->continue_build( 0.0 );
				$publisher->continue_build( 0.0 );
				$image = $wpdb->get_row( $wpdb->prepare( 'SELECT file_path, checksum FROM %i WHERE version_id = %d ORDER BY version_image_id LIMIT 1', StarterSchema::version_images_table(), $version ), ARRAY_A );
				$file  = StarterCatalogStorage::frozen_path( (string) $image['file_path'] );
				file_put_contents( $file, 'tampered' );
				$this->same( 'changed', $publisher->continue_build( 0.0 )['status'] ?? '', 'same object: the tampered frozen image stops publication' );

				$result = null;
				for ( $i = 0; $i < 5; $i++ ) {
					$result = $publisher->continue_build( 600.0 );
					if ( ! is_array( $result ) || 'building' !== $result['status'] ) {
						break;
					}
				}
				$this->same( 'published', is_array( $result ) ? $result['status'] : $this->code( $result ), 'same object: Resume repairs the image instead of trusting the earlier call' );
				$this->same( (string) $image['checksum'], (string) hash_file( 'sha256', $file ), 'the frozen file is the source image again' );
			}
		);
	}

	/** @return array<int,string> Category slugs from the term up to its root. */
	private function ancestry( int $term_id ): array {
		$slugs = [];
		$term  = get_term( $term_id, 'product_cat' );
		while ( $term instanceof WP_Term && count( $slugs ) < 40 ) {
			$slugs[] = $term->slug;
			$term    = $term->parent > 0 ? get_term( (int) $term->parent, 'product_cat' ) : null;
		}

		return $slugs;
	}

	private function set_term_parent( int $term_id, int $parent ): void {
		global $wpdb;
		$wpdb->update( $wpdb->term_taxonomy, [ 'parent' => $parent ], [ 'term_id' => $term_id, 'taxonomy' => 'product_cat' ] );
		clean_term_cache( $term_id, 'product_cat' );
	}

	/** Version status, product and image rows, and frozen files. */
	private function build_snapshot( int $version ): array {
		global $wpdb;

		return [
			Versions::get( $version )['status'] ?? '',
			$wpdb->get_results( $wpdb->prepare( 'SELECT version_product_id, capture_status, data FROM %i WHERE version_id = %d ORDER BY version_product_id', StarterSchema::version_products_table(), $version ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT version_image_id, file_path, checksum FROM %i WHERE version_id = %d ORDER BY version_image_id', StarterSchema::version_images_table(), $version ), ARRAY_A ),
			$this->count_files( StarterCatalogStorage::base_dir() . '/v' . $version, '' ),
		];
	}

	/** Run work while queries the matcher picks fail, with database errors quiet. */
	private function with_fault( callable $matcher, callable $work ) {
		global $wpdb;
		$fault    = static fn( string $query ): string => $matcher( $query ) ? 'SELECT broken_column_for_fault FROM no_such_table_for_fault' : $query;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			return $work();
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/** @return array{0:int,1:int} One variable product (three variations, three images) and one simple product. */
	private function small_catalog(): array {
		static $catalog = null;
		if ( null === $catalog ) {
			$catalog = $this->on_main(
				function () {
					$images   = [ $this->attachment( 'small-a', 40, 40 ), $this->attachment( 'small-b', 40, 40 ), $this->attachment( 'small-c', 40, 40 ) ];
					$variable = $this->variable(
						[
							'name'       => 'Small dauber',
							'image'      => $images[0],
							'gallery'    => [ $images[1] ],
							'variations' => [ [ 'color' => 'Small Blue', 'image' => $images[0] ], [ 'color' => 'Small Red', 'image' => $images[1] ], [ 'color' => 'Small Green', 'image' => $images[2], 'status' => 'private' ] ],
						]
					);

					return [ $variable, $this->simple( [ 'name' => 'Small simple', 'image' => $images[2] ] ) ];
				}
			);
		}
		$this->on_main( fn() => $this->only_include( $catalog ) );

		return $catalog;
	}

	private function simple( array $args ): int {
		$product = ! empty( $args['grouped'] ) ? new WC_Product_Grouped() : new WC_Product_Simple();
		$product->set_name( (string) $args['name'] );
		$product->set_status( (string) ( $args['status'] ?? 'draft' ) );
		$product->set_slug( (string) ( $args['slug'] ?? '' ) );
		$product->set_description( (string) ( $args['description'] ?? '' ) );
		$product->set_short_description( (string) ( $args['short'] ?? '' ) );
		if ( ! $product instanceof WC_Product_Grouped ) {
			$product->set_regular_price( (string) ( $args['price'] ?? '10.00' ) );
		}
		$product->set_sku( (string) ( $args['sku'] ?? '' ) );
		$product->set_tax_status( (string) ( $args['tax_status'] ?? 'taxable' ) );
		$product->set_manage_stock( ! empty( $args['stock'] ) );
		$product->set_downloadable( ! empty( $args['downloadable'] ) );
		$product->set_image_id( (int) ( $args['image'] ?? 0 ) );
		$product->set_gallery_image_ids( (array) ( $args['gallery'] ?? [] ) );
		$product->set_category_ids( (array) ( $args['categories'] ?? [] ) );
		$product->set_tag_ids( (array) ( $args['tags'] ?? [] ) );
		$attributes = [];
		foreach ( (array) ( $args['attributes'] ?? [] ) as $position => [ $name, $options, $visible ] ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_visible( $visible );
			$attribute->set_position( $position );
			$attributes[] = $attribute;
		}
		if ( isset( $args['global_attribute'] ) ) {
			$attributes[] = $args['global_attribute'];
		}
		$product->set_attributes( $attributes );

		return $this->saved( $product, $args );
	}

	private function variable( array $args ): int {
		$product = new WC_Product_Variable();
		$product->set_name( (string) $args['name'] );
		$product->set_status( 'draft' );
		$product->set_slug( (string) ( $args['slug'] ?? '' ) );
		$product->set_description( (string) ( $args['description'] ?? '' ) );
		$product->set_sku( (string) ( $args['sku'] ?? '' ) );
		$product->set_image_id( (int) ( $args['image'] ?? 0 ) );
		$product->set_gallery_image_ids( (array) ( $args['gallery'] ?? [] ) );
		$product->set_category_ids( (array) ( $args['categories'] ?? [] ) );
		$product->set_tag_ids( (array) ( $args['tags'] ?? [] ) );
		$colors    = array_map( static fn( array $v ): string => (string) $v['color'], (array) $args['variations'] );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Color' );
		$attribute->set_options( [] !== $colors ? $colors : [ 'None' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		$product->set_default_attributes( (array) ( $args['defaults'] ?? [] ) );
		$parent = $this->saved( $product, $args );

		foreach ( (array) $args['variations'] as $index => $spec ) {
			$this->variation( $parent, $spec, $index + 1 );
		}

		return $parent;
	}

	private function variation( int $parent, array $spec, int $position ): int {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent );
		$variation->set_attributes( [ 'color' => (string) $spec['color'] ] );
		$variation->set_regular_price( (string) ( $spec['price'] ?? '2.00' ) );
		$variation->set_sku( (string) ( $spec['sku'] ?? '' ) );
		$variation->set_manage_stock( ! empty( $spec['stock'] ) );
		$variation->set_status( (string) ( $spec['status'] ?? 'publish' ) );
		$variation->set_downloadable( ! empty( $spec['downloadable'] ) );
		$variation->set_tax_class( (string) ( $spec['tax_class'] ?? 'parent' ) );
		$variation->set_description( (string) ( $spec['description'] ?? '' ) );
		$variation->set_image_id( (int) ( $spec['image'] ?? 0 ) );
		$variation->set_menu_order( $position );
		$id = (int) $variation->save();
		if ( isset( $spec['raw_price'] ) ) {
			update_post_meta( $id, '_regular_price', $spec['raw_price'] );
		}
		$this->products[ $id ] = true;

		return $id;
	}

	/** A new variation; the parent's own row is left exactly as it was. */
	private function add_variation( int $parent, string $color ): int {
		global $wpdb;
		$modified = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_modified_gmt FROM %i WHERE ID = %d', $wpdb->posts, $parent ) );
		$id       = $this->variation( $parent, [ 'color' => $color, 'image' => $this->attachment( 'small-d', 40, 40 ) ], 9 );
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $modified ], [ 'ID' => $parent ] );
		clean_post_cache( $parent );

		return $id;
	}

	private function saved( WC_Product $product, array $args ): int {
		$id = (int) $product->save();
		if ( $id < 1 ) {
			throw new RuntimeException( 'Product fixture failed.' );
		}
		$this->products[ $id ] = true;
		update_post_meta( $id, self::OWNER_META, $this->token );
		if ( isset( $args['raw_price'] ) ) {
			update_post_meta( $id, '_regular_price', $args['raw_price'] );
		}
		if ( $args['include'] ?? true ) {
			update_post_meta( $id, Master::META_KEY, Master::INCLUDED );
		}
		clean_post_cache( $id );

		return $id;
	}

	private function attachment( string $name, int $width, int $height, string $type = 'png', int $pad_to = 0, string $alt = '' ): int {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['path'] );
		$file  = $uploads['path'] . '/' . $name . '.' . $type;
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, imagecolorallocate( $image, crc32( $name ) % 255, 90, 160 ) );
		'bmp' === $type ? imagebmp( $image, $file ) : imagepng( $image, $file );
		imagedestroy( $image );
		if ( $pad_to > 0 ) {
			file_put_contents( $file, str_repeat( "\0", $pad_to ), FILE_APPEND );
		}
		$id = wp_insert_attachment( [ 'post_mime_type' => 'bmp' === $type ? 'image/bmp' : 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ], $file );
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new RuntimeException( 'Attachment fixture failed.' );
		}
		$this->attachments[ $id ] = true;
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	private function term( string $name, string $taxonomy, int $parent = 0 ): int {
		$created = wp_insert_term( $name, $taxonomy, [ 'slug' => sanitize_title( $name ) . '-' . $this->token, 'parent' => $parent ] );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( 'Term fixture failed.' );
		}
		$this->terms[] = [ (int) $created['term_id'], $taxonomy ];

		return (int) $created['term_id'];
	}

	private function global_attribute(): WC_Product_Attribute {
		$slug = 'bctc' . strtolower( $this->token );
		$id   = wc_create_attribute( [ 'name' => 'BC Test Color', 'slug' => $slug ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Global attribute fixture failed.' );
		}
		$this->attributes[] = (int) $id;
		$taxonomy           = wc_attribute_taxonomy_name( $slug );
		register_taxonomy( $taxonomy, 'product' );
		$term = wp_insert_term( 'Red', $taxonomy );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( (int) $id );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( [ (int) $term['term_id'] ] );
		$attribute->set_visible( true );

		return $attribute;
	}

	private function only_include( array $ids ): void {
		foreach ( array_keys( $this->products ) as $id ) {
			if ( 'product' !== get_post_type( $id ) ) {
				continue;
			}
			in_array( $id, $ids, true ) ? update_post_meta( $id, Master::META_KEY, Master::INCLUDED ) : delete_post_meta( $id, Master::META_KEY );
		}
	}

	private function expect_blocked( array $ids, string $message, string $label ): void {
		$result = ( new Publisher() )->start();
		$found  = false;
		foreach ( is_array( $result ) ? (array) $result['problems'] : [] as $problem ) {
			$found = $found || str_starts_with( $problem, $message ) || str_contains( $problem, $message );
		}
		$this->same( [ 'blocked', true ], [ is_array( $result ) ? $result['status'] : $this->code( $result ), $found ], "blocked ({$label}): " . $message );
		if ( ! $found ) {
			echo '  problems were: ' . wp_json_encode( is_array( $result ) ? $result['problems'] : $this->code( $result ) ) . "\n";
		}
	}

	/** Resume until published (or anything else). */
	private function finish() {
		$result = null;
		for ( $i = 0; $i < 40; $i++ ) {
			$result = ( new Publisher() )->continue_build( 600.0 );
			if ( ! is_array( $result ) || 'building' !== $result['status'] ) {
				break;
			}
		}

		return $result;
	}

	/** @return array<int,array<string,mixed>> Top-level data in saved order. */
	private function top_data( int $version ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM %i WHERE version_id = %d AND parent_source_id IS NULL ORDER BY position ASC', StarterSchema::version_products_table(), $version ) );

		return array_map( static fn( $json ): array => (array) json_decode( (string) $json, true ), $rows );
	}

	/** @return array{0:int,1:int,2:int} Products, variations and image rows. */
	private function version_counts( int $version ): array {
		global $wpdb;
		$top  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id = %d AND parent_source_id IS NULL', StarterSchema::version_products_table(), $version ) );
		$all  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id = %d', StarterSchema::version_products_table(), $version ) );
		$imgs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id = %d', StarterSchema::version_images_table(), $version ) );

		return [ $top, $all - $top, $imgs ];
	}

	private string $last_worker_result = '';

	private function worker( string $action, string $step, float $budget = 0.0, int $version = 0 ): int {
		$env    = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_USER'       => (string) $this->admin,
			'BC_WORKER_ACTION'     => $action,
			'BC_WORKER_CRASH_STEP' => $step,
			'BC_WORKER_BUDGET'     => (string) $budget,
			'BC_WORKER_VERSION'    => (string) $version,
			'BC_STARTER_DIR'       => StarterCatalogStorage::base_dir(),
			'BC_MAIN_UPLOADS'      => $this->main_uploads,
			'PATH'                 => (string) getenv( 'PATH' ),
		];
		$pipes  = [];
		$handle = proc_open( [ PHP_BINARY, __DIR__ . '/starter-catalog-publish-worker.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $handle ) ) {
			return -1;
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code                     = proc_close( $handle );
		$decoded                  = json_decode( trim( (string) strrchr( "\n" . $stdout, "\n" ) ), true );
		$this->last_worker_result = is_array( $decoded ) ? (string) ( $decoded['result'] ?? '' ) : '';
		if ( 9 !== $code && 0 !== $code ) {
			echo "worker output ({$action} {$step}): " . trim( $stdout . ' ' . $stderr ) . "\n";
		}

		return $code;
	}

	private function on_main( callable $work ) {
		return $this->in_site( $this->main, $work );
	}

	private function in_site( int $site, callable $work, ?int $user = null ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user ?? $this->admin );
		switch_to_blog( $site );
		try {
			return $work();
		} finally {
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function product_id_by_slug( string $slug ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_name = %s", $wpdb->posts, $slug ) );
	}

	private function count_main( string $type ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->get_blog_prefix( $this->main ) . 'posts', $type ) );
	}

	private function count_files( string $folder, string $suffix ): int {
		if ( ! is_dir( $folder ) ) {
			return 0;
		}
		$count = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && ( '' === $suffix ? ! str_ends_with( $file->getFilename(), '.part' ) : str_ends_with( $file->getFilename(), $suffix ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-publish-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter publish disposable Hall', 'user_id' => $this->admin ] );
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
		$id = wp_insert_user( [ 'user_login' => 'scp-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Actor creation failed.' );
		}
		$this->users[] = (int) $id;
		update_user_meta( $id, self::USER_OWNER, $this->token );
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
		$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] );
		clean_user_cache( $id );

		return (int) $id;
	}

	private function cleanup(): void {
		global $wpdb;

		$this->on_main(
			function () {
				global $wpdb;
				foreach ( array_keys( $this->products ) as $id ) {
					wp_delete_post( (int) $id, true );
				}
				$owned = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, self::OWNER_META, $this->token ) );
				foreach ( $owned as $id ) {
					wp_delete_post( (int) $id, true );
				}
				foreach ( array_keys( $this->attachments ) as $id ) {
					wp_delete_attachment( (int) $id, true );
				}
				// Parents first, so no loop or missing parent remains while deleting.
				foreach ( $this->terms as [ $term_id, $taxonomy ] ) {
					$wpdb->update( $wpdb->term_taxonomy, [ 'parent' => 0 ], [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
					clean_term_cache( $term_id, $taxonomy );
				}
				foreach ( $this->terms as [ $term_id, $taxonomy ] ) {
					wp_delete_term( $term_id, $taxonomy );
				}
				foreach ( $this->attributes as $attribute_id ) {
					wc_delete_attribute( $attribute_id );
				}
			}
		);

		foreach ( array_keys( $this->sites ) as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) {
				throw new RuntimeException( 'Cleanup ownership guard refused.' );
			}
			switch_to_blog( $site );
			$uploads = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
			restore_current_blog();
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( str_starts_with( $table, $prefix ) ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
				}
			}
			$content = wp_normalize_path( WP_CONTENT_DIR );
			if ( $uploads === $content . '/blogs.dir/' . $site . '/files' ) {
				$this->remove_tree( $content . '/blogs.dir/' . $site, $content . '/blogs.dir/' . $site );
			} elseif ( $uploads === $content . '/uploads/sites/' . $site ) {
				$this->remove_tree( $uploads, $uploads );
			}
			$this->same( false, is_dir( $uploads ), 'owned disposable Hall upload folder removed' );
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
		$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'action' => 'starter_catalog.version_published' ] );
	}

	/** The network starter tables are created only by these suites in the fixture. */
	private function reset_network(): void {
		global $wpdb;
		if ( ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) {
			throw new RuntimeException( 'Fixture guard refused the network table reset.' );
		}
		foreach ( [ StarterSchema::VERSIONS_TABLE, StarterSchema::VERSION_PRODUCTS_TABLE, StarterSchema::VERSION_IMAGES_TABLE ] as $suffix ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . $suffix ) );
		}
		delete_site_option( StarterSchema::NETWORK_VERSION_OPTION );
	}

	private function remove_tree( string $directory, string $expected_prefix ): void {
		$directory = wp_normalize_path( $directory );
		if ( '' === $directory || ! str_starts_with( $directory, wp_normalize_path( $expected_prefix ) ) || ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}

	private function status( $state ): string {
		return is_array( $state ) ? (string) $state['status'] : ( is_wp_error( $state ) ? 'error:' . $state->get_error_code() : var_export( $state, true ) );
	}

	/** @param mixed $value */
	private function code( $value ): string {
		return is_wp_error( $value ) ? $value->get_error_code() : 'not an error: ' . wp_json_encode( $value );
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

exit( ( new StarterCatalogPublishTest() )->run( $bc_run_dir ) );
