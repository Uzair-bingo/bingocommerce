<?php

declare(strict_types=1);

/**
 * ADR-049 stage 3, slice 3.3: variable products and images, with crash
 * recovery, against real WooCommerce and real image files on disposable
 * fixture Halls.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-media.php
 *
 * Proves:
 *   - catalogs shaped like the two real samples (15 and 13 colour
 *     variations, product-level "Color" attribute, shared images) import as
 *     Draft parents with exact variations, prices, stock and images, each
 *     image copied once, WooCommerce calculating the parent price and stock;
 *   - a request stops at safe points once its budget is used and the next
 *     request continues without duplicates;
 *   - a crash at every image and variation step either finishes without a
 *     duplicate or, when creation started without a saved ID, becomes Needs
 *     attention with nothing new created;
 *   - variation and image retry rules (deleted variation, skipped parent,
 *     image deleted by the Hall, missing or changed original, unsupported or
 *     oversized image), "-scaled" images, and upload quota on and off,
 *     including space used up after an image was planned (Retry stops
 *     before copying);
 *   - a simple product's product-level attributes are copied and verified;
 *   - an image whose sizes WordPress could not make, or that the server has
 *     no image editor for, stays unfinished with a clear reason, and Retry
 *     finishes the same attachment with every size.
 *
 * Frozen starter images live in a temporary folder for this run only
 * (BINGOCOMMERCE_STARTER_CATALOG_DIR), never in the real uploads folder.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_starter_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-media-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_starter_dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary starter image folder.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_starter_dir );

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/** The GD editor, unable to make sizes of one chosen width while $fail_width is set. */
final class BC_Starter_Failing_Image_Editor extends WP_Image_Editor_GD {
	public static ?int $fail_width = null;

	public function make_subsize( $size_data ) {
		if ( null !== self::$fail_width && (int) ( $size_data['width'] ?? 0 ) === self::$fail_width ) {
			return new WP_Error( 'bc_test_resize_failed', 'Test: this size could not be made.' );
		}

		return parent::make_subsize( $size_data );
	}
}

use BingoCommerce\Catalog\StarterCatalogStorage;
use BingoCommerce\Catalog\StarterCatalogVersions;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportRepository as Repo;

final class StarterImportMediaTest {
	private const OWNER_OPTION = 'bc_starter_import_test_owner';
	private const USER_OWNER   = 'bc_starter_import_test_user_owner';

	private const ON_COLORS  = [ 'Blue', 'Orange', 'Green', 'Yellow', 'Pink', 'Grey', 'Olive', 'Brown', 'Red', 'Purple', 'Black', 'Aqua', 'Lime', 'Tan', 'White' ];
	private const DAB_COLORS = [ 'Blue', 'Green', 'Magenta', 'Orange', 'Periwinkle', 'Pink', 'Purple', 'Red', 'Ruby Red', 'Aqua', 'Violet', 'Yellow', 'Assorted' ];

	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	private int $next_source = 5000;
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

		$this->network_before = [
			'upload_space_check_disabled' => get_site_option( 'upload_space_check_disabled', null ),
		];
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}

		try {
			update_site_option( 'upload_space_check_disabled', '1' );
			$this->reset_network();
			$this->same( true, StarterSchema::ensure_network(), 'network starter tables ready' );
			update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, '1' );

			$this->real_shaped_catalog();
			$this->pausing();
			$this->crash_recovery();
			$this->variation_retry_rules();
			$this->image_rules();
			$this->scaled_image();
			$this->quota();
			$this->simple_attributes();
			$this->image_sizes();
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
			$this->remove_tree( $starter_dir, rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-media-' );
		}

		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		$this->same( false, is_dir( $starter_dir ), 'temporary frozen image folder removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * The two real-shaped products
	 * ------------------------------------------------------------------ */

	private function real_shaped_catalog(): void {
		$images = [];
		foreach ( self::ON_COLORS as $i => $color ) {
			$images[ 'on' . $i ] = [ 'name' => '1on' . str_replace( ' ', '', $color ), 'alt' => "1on {$color}" ];
		}
		foreach ( self::DAB_COLORS as $i => $color ) {
			$images[ 'dab' . $i ] = [ 'name' => str_replace( ' ', '_', $color ) . '_Dab-O-Ink_Dauber', 'alt' => "Dab O Ink {$color}" ];
		}

		$on_variations = [];
		foreach ( self::ON_COLORS as $i => $color ) {
			$on_variations[] = [
				'attributes'    => [ 'color' => $color ],
				'regular_price' => '36.15',
				'sku'           => sprintf( 'S01%02dBSP9', $i + 1 ),
				'manage_stock'  => true,
				'enabled'       => true,
				'tax_class'     => 'parent',
				'description'   => 0 === $i ? '<p>Ink "quote" it\'s C:\\temp</p>' : '',
				'image'         => 'on' . $i,
			];
		}
		$dab_variations = [];
		foreach ( self::DAB_COLORS as $i => $color ) {
			$assorted         = 'Assorted' === $color;
			$dab_variations[] = [
				'attributes'    => [ 'color' => $color ],
				'regular_price' => $assorted ? '13.12' : '1.06',
				'sku'           => '830D-' . strtoupper( substr( str_replace( ' ', '', $color ), 0, 2 ) ) . $i,
				'manage_stock'  => ! $assorted,
				'enabled'       => true,
				'tax_class'     => 'parent',
				'description'   => '',
				'image'         => 'dab' . $i,
			];
		}

		$version = $this->seed(
			[
				$this->variable(
					[
						'name'              => '1on Bingo Paper By The Case 9,000 Sheets',
						'slug'              => '1on-bingo-paper-by-the-case-9000-sheets',
						'description'       => 'Discover premium 1on Bingo Paper with <strong>same color per case</strong>.',
						'sku'               => '1on-bingo-paper-by-the-case',
						'categories'        => [ 'bingo-sets' ],
						'tags'              => [ 'sg', 'sgbd' ],
						'image'             => 'on0',
						'gallery'           => array_map( static fn( int $i ): string => 'on' . $i, range( 1, 14 ) ),
						'attributes'        => [ [ 'name' => 'Color', 'options' => self::ON_COLORS, 'visible' => true, 'variation' => true, 'position' => 0 ] ],
						'variations'        => $on_variations,
					]
				),
				$this->variable(
					[
						'name'               => 'Dab O Ink 3 Ounce By the Bottle',
						'slug'               => 'dab-o-ink-3-ounce-by-the-bottle',
						'description'        => "\n<h2>Dab O Ink Bingo Dauber</h2>\n<ul>\n<li>Vibrant, long-lasting bingo ink</li>\n</ul>",
						'sku'                => 'dab-o-ink-3-ounce-by-the-bottle',
						'categories'         => [ 'bingo-sets' ],
						'tags'               => [ 'sdab' ],
						'image'              => 'dab0',
						'gallery'            => array_map( static fn( int $i ): string => 'dab' . $i, range( 1, 12 ) ),
						'attributes'         => [ [ 'name' => 'Color', 'options' => self::DAB_COLORS, 'visible' => true, 'variation' => true, 'position' => 0 ] ],
						'default_attributes' => [ 'color' => 'Blue' ],
						'variations'         => $dab_variations,
					]
				),
			],
			$images,
			[ 'product_cat' => [ [ 'slug' => 'bingo-sets', 'name' => 'Bingo Sets', 'parent' => '' ] ], 'product_tag' => [ [ 'slug' => 'sg', 'name' => 'SG' ], [ 'slug' => 'sgbd', 'name' => 'SGBD' ], [ 'slug' => 'sdab', 'name' => 'SDAB' ] ] ]
		);

		$hall    = $this->create_hall();
		$manager = $this->user( 'manager', $hall, 'bc_hall_manager' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$existing = new WC_Product_Simple();
				$existing->set_name( 'Star Bingo Hall paper' );
				$existing->set_status( 'publish' );
				$existing->set_sku( 'S0101BSP9' );
				$existing->save();
			}
		);

		$this->in_hall(
			$hall,
			$manager,
			function () use ( $version, $manager ) {
				global $wpdb;
				$this->same( 'importing', $this->status( ( new StarterImporter() )->start() ), 'Hall Manager starts the import' );
				$done = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( 'complete', $this->status( $done ), 'the real-shaped catalog imports completely' );
				$this->same( [ 2, 0 ], is_array( $done ) ? [ $done['imported_products'], $done['skipped_products'] ] : [], 'two products imported, none skipped' );

				$this->same( 2, $this->count_posts( 'product' ) - 1, 'two new parent products (plus the Hall\'s own)' );
				$this->same( 28, $this->count_posts( 'product_variation' ), 'all 28 variations' );
				$this->same( 28, $this->count_posts( 'attachment' ), '28 images: each shared image copied once' );

				$on  = $this->product_by_slug( '1on-bingo-paper-by-the-case-9000-sheets' );
				$dab = $this->product_by_slug( 'dab-o-ink-3-ounce-by-the-bottle' );
				$this->same( true, $on instanceof WC_Product_Variable && $dab instanceof WC_Product_Variable, 'both are variable products found by their normal slugs' );
				if ( ! $on instanceof WC_Product_Variable || ! $dab instanceof WC_Product_Variable ) {
					return;
				}

				foreach ( [ $on, $dab ] as $parent ) {
					$this->same( 'draft', $parent->get_status(), "{$parent->get_slug()}: draft" );
					$this->same( $manager, (int) get_post_field( 'post_author', $parent->get_id() ), "{$parent->get_slug()}: author is the Hall Manager" );
					$attribute = $parent->get_attributes()['color'] ?? null;
					$this->same( true, $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() && $attribute->get_visible() && $attribute->get_variation(), "{$parent->get_slug()}: product-level Color attribute, visible, used for variations" );
					$this->same( '', (string) $parent->get_meta( 'bc_product_kind', true, 'edit' ), "{$parent->get_slug()}: no bc_product_kind" );
				}
				$this->same( self::ON_COLORS, $on->get_attributes()['color']->get_options(), '1on: all 15 colour options in order' );
				$this->same( [ 'color' => 'Blue' ], $dab->get_default_attributes(), 'Dab O Ink: default attribute copied' );
				$this->same( '1on-bingo-paper-by-the-case', $on->get_sku(), '1on: parent SKU' );

				$on_image = (int) $on->get_image_id();
				$this->same( true, $on_image > 0 && 'attachment' === get_post_type( $on_image ), '1on: featured image is a Hall attachment' );
				$this->same( 14, count( $on->get_gallery_image_ids() ), '1on: 14 gallery images, in order' );
				$this->same( '1on Blue', (string) get_post_meta( $on_image, '_wp_attachment_image_alt', true ), '1on: alt text copied' );
				$planned = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT planned_path FROM %i WHERE attachment_id = %d', StarterSchema::import_images_table(), $on_image ) );
				$this->same( wp_normalize_path( wp_upload_dir()['basedir'] . '/' . $planned ), wp_normalize_path( (string) wp_get_original_image_path( $on_image ) ), '1on: the image file is the Hall-owned planned path' );
				$meta = wp_get_attachment_metadata( $on_image );
				$this->same( true, is_array( $meta ) && ! empty( $meta['sizes'] ), '1on: WordPress generated image sizes' );

				$on_variations = $this->variations( $on->get_id() );
				$this->same( 15, count( $on_variations ), '1on: 15 variations' );
				$this->same( range( 1, 15 ), array_map( static fn( WC_Product_Variation $v ): int => $v->get_menu_order(), $on_variations ), '1on: variations in source order' );
				$blue = $on_variations[0];
				$this->same( [ 'color' => 'Blue' ], $blue->get_attributes(), '1on Blue: attribute value' );
				$this->same( [ '36.15', 'publish', 'parent' ], [ $blue->get_regular_price(), $blue->get_status(), $blue->get_tax_class( 'edit' ) ], '1on Blue: price, enabled, tax class "same as parent"' );
				$this->same( [ true, 0, 'outofstock' ], [ $blue->get_manage_stock(), $blue->get_stock_quantity(), $blue->get_stock_status() ], '1on Blue: tracked stock starts at 0' );
				$this->same( '', $blue->get_sku( 'edit' ), '1on Blue: own SKU left blank because the Hall already uses S0101BSP9' );
				$note = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM %i WHERE wc_product_id = %d', StarterSchema::import_products_table(), $blue->get_id() ) );
				$this->same( true, str_contains( $note, 'S0101BSP9' ), '1on Blue: note names the SKU conflict' );
				$this->same( 'S0102BSP9', $on_variations[1]->get_sku( 'edit' ), '1on Orange: SKU copied' );
				$this->same( $on_image, (int) $blue->get_image_id(), '1on Blue: variation image reuses the same Hall attachment as the parent' );
				$this->same( true, str_contains( (string) get_post_meta( $blue->get_id(), '_variation_description', true ), 'C:temp' ), '1on Blue: variation description stored as WordPress saves metadata' );

				$this->same( [ '36.15', '36.15' ], [ (string) $on->get_variation_price( 'min' ), (string) $on->get_variation_price( 'max' ) ], '1on: WooCommerce calculates the parent price' );
				$this->same( 'outofstock', $on->get_stock_status(), '1on: parent out of stock while every variation is at 0' );

				$dab_variations = $this->variations( $dab->get_id() );
				$this->same( 13, count( $dab_variations ), 'Dab O Ink: 13 variations' );
				$assorted = $dab_variations[12];
				$this->same( [ 'Assorted', '13.12', false, null, 'instock' ], [ $assorted->get_attributes()['color'] ?? '', $assorted->get_regular_price(), $assorted->get_manage_stock( 'edit' ), $assorted->get_stock_quantity(), $assorted->get_stock_status() ], 'Dab O Ink Assorted: untracked, in stock, no quantity, $13.12' );
				$this->same( [ '1.06', '13.12' ], [ (string) $dab->get_variation_price( 'min' ), (string) $dab->get_variation_price( 'max' ) ], 'Dab O Ink: parent price range $1.06–$13.12' );
				$this->same( 'instock', $dab->get_stock_status(), 'Dab O Ink: parent in stock because Assorted is' );

				$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'product' AND post_name LIKE %s", $wpdb->posts, $wpdb->esc_like( 'bcsi-' ) . '%' ) ), 'no recovery slug left on a parent product' );

				$before = [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ];
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Continue on a complete import changes nothing' );
				$this->same( $before, [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ], 'no new products, variations or images' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Pausing at safe points
	 * ------------------------------------------------------------------ */

	private function pausing(): void {
		$this->small_version();
		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				( new StarterImporter() )->start();
				$paused = 0;
				$final  = null;
				for ( $i = 0; $i < 40; $i++ ) {
					$final = ( new StarterImporter() )->process_next( 0.0 );
					if ( 'importing' === $this->status( $final ) ) {
						++$paused;
						continue;
					}
					break;
				}
				$this->same( 'complete', $this->status( $final ), 'a zero budget still finishes after enough requests' );
				$this->same( true, $paused >= 5, 'the import stopped at safe points between images and variations' );
				$this->same( [ 1, 3, 3 ], [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ], 'one parent, three variations, three images after pausing' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Crash recovery for images and variations
	 * ------------------------------------------------------------------ */

	private function crash_recovery(): void {
		$this->small_version();

		$finishes = [ 'image_planned', 'image_file_written', 'image_id_saved', 'image_completed', 'fields_applied', 'verified', 'variation_claimed', 'variation_id_saved', 'variation_fields_applied', 'variation_completed', 'variations_done', 'parent_synced', 'slug_set', 'item_completed' ];
		$attention = [
			'image_creation_starting'     => [ 0, 0 ],
			'insert-attachment'           => [ 1, 0 ],
			'image_created'               => [ 1, 0 ],
			'variation_creation_starting' => [ 3, 0 ],
			'insert-variation'            => [ 3, 1 ],
			'variation_created'           => [ 3, 1 ],
		];

		foreach ( array_merge( $finishes, array_keys( $attention ) ) as $step ) {
			$hall = $this->create_hall();
			$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
			$this->same( 9, $this->worker( $hall, $this->admin, 'process', $step, 600.0 ), "worker crashed at {$step}" );

			$this->in_hall(
				$hall,
				$this->admin,
				function () use ( $step, $finishes, $attention ) {
					global $wpdb;
					$state = ( new StarterImporter() )->process_next( 600.0 );
					if ( in_array( $step, $finishes, true ) ) {
						$this->same( 'complete', $this->status( $state ), "after a crash at {$step}, the import finishes" );
						$this->same( [ 1, 3, 3 ], [ $this->count_posts( 'product' ), $this->count_posts( 'product_variation' ), $this->count_posts( 'attachment' ) ], "after a crash at {$step}: one parent, three variations, three images" );
						$this->same( 3, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', StarterSchema::import_images_table(), Repo::IMAGE_COMPLETE ) ), "after a crash at {$step}, all three images complete" );
						$this->same( 3, $this->count_original_files(), "after a crash at {$step}, exactly three original image files on disk" );
					} else {
						[ $images, $variations ] = $attention[ $step ];
						$this->same( 'needs_attention', $this->status( $state ), "a crash at {$step} becomes Needs attention" );
						$this->same( [ $images, $variations ], [ $this->count_posts( 'attachment' ), $this->count_posts( 'product_variation' ) ], "a crash at {$step} creates nothing more" );
						$again = ( new StarterImporter() )->process_next( 600.0 );
						$this->same( 'needs_attention', $this->status( $again ), "Continue after {$step} still waits for Resolve" );
						$this->same( [ $images, $variations ], [ $this->count_posts( 'attachment' ), $this->count_posts( 'product_variation' ) ], "Continue after {$step} never creates another image or variation" );
					}
				}
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Variation retry rules
	 * ------------------------------------------------------------------ */

	private function variation_retry_rules(): void {
		$this->small_version();

		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'variation_id_saved', 600.0 ), 'stopped after the first variation was created' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$variation = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_product_id FROM %i WHERE product_type = 'variation' ORDER BY item_id ASC LIMIT 1", StarterSchema::import_products_table() ) );
				wp_delete_post( $variation, true );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( 'complete', $this->status( $state ), 'a variation deleted while importing: the import completes' );
				$this->same( null, get_post( $variation ), 'the deleted variation is not brought back' );
				$this->same( 2, $this->count_posts( 'product_variation' ), 'the other two variations are imported' );
				$parent_note = (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM %i WHERE product_type = 'variable'", StarterSchema::import_products_table() ) );
				$this->same( true, str_contains( $parent_note, 'Small Blue' ), 'the parent completes with an omission note for the skipped variation' );
				$this->same( [ 1, 0 ], is_array( $state ) ? [ $state['imported_products'], $state['skipped_products'] ] : [], 'the parent still counts as imported' );
			}
		);

		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'variation_id_saved', 600.0 ), 'stopped after the first variation was created (parent case)' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$parent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_product_id FROM %i WHERE product_type = 'variable'", StarterSchema::import_products_table() ) );
				wp_trash_post( $parent );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( 'complete', $this->status( $state ), 'a parent trashed while importing: the import completes' );
				$this->same( [ 0, 1 ], is_array( $state ) ? [ $state['imported_products'], $state['skipped_products'] ] : [], 'the parent is skipped' );
				$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE product_type = 'variation' AND status NOT IN ( %s )", StarterSchema::import_products_table(), Repo::ITEM_SKIPPED ) ), 'its variation items are skipped too' );
				$this->same( 1, $this->count_posts( 'product_variation' ), 'no further variations are created under a skipped parent' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Image rules
	 * ------------------------------------------------------------------ */

	private function image_rules(): void {
		$image_version = function ( array $overrides = [] ): array {
			return $this->seed(
				[ $this->simple( [ 'name' => 'Pictured', 'slug' => 'pictured', 'image' => 'x', 'gallery' => [ 'x', 'y' ] ] ) ],
				[ 'x' => [ 'name' => 'photo-x', 'alt' => 'X' ] + ( $overrides['x'] ?? [] ), 'y' => [ 'name' => 'photo-y', 'alt' => 'Y' ] + ( $overrides['y'] ?? [] ) ],
				[]
			);
		};

		$version = $image_version();
		$hall    = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'image_completed', 0.0 ), 'stopped right after the first image completed' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$first = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attachment_id FROM %i ORDER BY import_image_id ASC LIMIT 1', StarterSchema::import_images_table() ) );
				wp_delete_attachment( $first, true );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( 'complete', $this->status( $state ), 'an image the Hall deleted: the product still completes' );
				$product = $this->product_by_slug( 'pictured' );
				$this->same( [ 0, 1 ], $product instanceof WC_Product ? [ (int) $product->get_image_id(), count( $product->get_gallery_image_ids() ) ] : [], 'the deleted image is left out; the other image stays' );
				$this->same( Repo::IMAGE_OMITTED, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i ORDER BY import_image_id ASC LIMIT 1', StarterSchema::import_images_table() ) ), 'the image is recorded as left out' );
				$note = (string) $wpdb->get_var( $wpdb->prepare( "SELECT note FROM %i WHERE product_type = 'simple'", StarterSchema::import_products_table() ) );
				$this->same( true, str_contains( $note, 'photo-x' ), 'the product note names the left-out image' );
				$this->same( 1, $this->count_posts( 'attachment' ), 'the deleted image is not brought back' );
			}
		);

		$version = $image_version();
		$frozen  = StarterCatalogStorage::frozen_path( (string) $version['images']['y']['file_path'] );
		$saved   = (string) file_get_contents( $frozen );
		unlink( $frozen );
		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $frozen, $saved ) {
				( new StarterImporter() )->start();
				$this->code( 'starter_import_image_missing', ( new StarterImporter() )->process_next( 600.0 ), 'a missing original stops the import with a clear reason' );
				$this->same( 1, $this->count_posts( 'attachment' ), 'nothing is created for the missing image' );
				file_put_contents( $frozen, $saved . 'changed' );
				$this->code( 'starter_import_image_missing', ( new StarterImporter() )->process_next( 600.0 ), 'a changed original (checksum differs) also stops it' );
				file_put_contents( $frozen, $saved );
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Retry completes once the original is back' );
				$this->same( 2, $this->count_posts( 'attachment' ), 'two images after the retry, no duplicates' );
			}
		);

		foreach ( [ 'unsupported type' => [ 'mime_type' => 'image/bmp' ], 'oversized file' => [ 'file_size' => StarterCatalogStorage::MAX_FILE_BYTES + 1 ], 'oversized dimensions' => [ 'width' => StarterCatalogStorage::MAX_DIMENSION + 1 ] ] as $label => $row ) {
			$version = $image_version();
			$this->set_version_image( (int) $version['images']['y']['version_image_id'], $row );
			$hall = $this->create_hall();
			$this->in_hall(
				$hall,
				$this->admin,
				function () use ( $label ) {
					( new StarterImporter() )->start();
					$this->code( 'starter_import_image_unsupported', ( new StarterImporter() )->process_next( 600.0 ), "an {$label} is refused" );
					$this->same( 1, $this->count_posts( 'attachment' ), "nothing is created for the {$label}" );
				}
			);
		}
	}

	private function scaled_image(): void {
		$this->seed(
			[ $this->simple( [ 'name' => 'Large photo', 'slug' => 'large-photo', 'image' => 'big' ] ) ],
			[ 'big' => [ 'name' => 'large-photo', 'alt' => 'Large', 'size' => 2700 ] ],
			[]
		);
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'image_id_saved', 600.0 ), 'stopped after saving the large image\'s attachment ID' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'the large image finishes on Retry' );
				$attachment = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attachment_id FROM %i', StarterSchema::import_images_table() ) );
				$this->same( true, str_contains( (string) get_attached_file( $attachment ), '-scaled.' ), 'WordPress made a "-scaled" copy' );
				$planned = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT planned_path FROM %i', StarterSchema::import_images_table() ) );
				$this->same( wp_normalize_path( wp_upload_dir()['basedir'] . '/' . $planned ), wp_normalize_path( (string) wp_get_original_image_path( $attachment ) ), 'the original stays at the planned path' );
				$this->same( 1, $this->count_posts( 'attachment' ), 'one attachment for the large image' );
			}
		);
	}

	private function quota(): void {
		$this->seed(
			[ $this->simple( [ 'name' => 'Quota', 'slug' => 'quota', 'image' => 'q' ] ) ],
			[ 'q' => [ 'name' => 'quota-photo', 'alt' => 'Q' ] ],
			[]
		);

		$hall = $this->create_hall();
		update_blog_option( $hall, 'blog_upload_space', '0.0001' );
		update_site_option( 'upload_space_check_disabled', '0' );
		$this->in_hall( $hall, $this->admin, fn() => $this->code( 'starter_import_storage_low', ( new StarterImporter() )->start(), 'quota on and too small: Start refuses before anything' ) );

		update_site_option( 'upload_space_check_disabled', '1' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				( new StarterImporter() )->start();
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'quota off: the same tiny allowance does not block the import' );
				$this->same( 1, $this->count_posts( 'attachment' ), 'quota off: the image is written' );
			}
		);

		// Planned while there was space; the space is used up before Retry.
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'image_planned', 600.0 ), 'stopped right after the image was planned' );
		update_blog_option( $hall, 'blog_upload_space', '0' );
		update_site_option( 'upload_space_check_disabled', '0' );
		try {
			$this->in_hall(
				$hall,
				$this->admin,
				function () {
					global $wpdb;
					$this->code( 'starter_import_storage_low', ( new StarterImporter() )->process_next( 600.0 ), 'quota used up after planning: Retry stops before copying' );
					$this->same( Repo::IMAGE_PLANNED, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i', StarterSchema::import_images_table() ) ), 'the image is still only planned' );
					$this->same( [ 0, 0 ], [ $this->count_original_files(), $this->count_posts( 'attachment' ) ], 'no file copied and no image record created' );
				}
			);
		} finally {
			update_site_option( 'upload_space_check_disabled', '1' );
		}
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Retry completes once space is available' );
				$this->same( [ 1, 1 ], [ $this->count_original_files(), $this->count_posts( 'attachment' ) ], 'one file and one image record after Retry' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Simple-product attributes
	 * ------------------------------------------------------------------ */

	private function simple_attributes(): void {
		$this->seed(
			[
				$this->simple(
					[
						'name'       => 'Paper pack',
						'slug'       => 'paper-pack',
						'attributes' => [
							[ 'name' => 'Material', 'options' => [ 'Paper' ], 'visible' => true, 'variation' => false, 'position' => 0 ],
							[ 'name' => 'Sheets per pack', 'options' => [ '500', '1000' ], 'visible' => false, 'variation' => false, 'position' => 1 ],
						],
					]
				),
			],
			[],
			[]
		);

		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				// The attributes do not reach the database on the first save.
				$strip = static function ( $product_id ): void {
					delete_post_meta( (int) $product_id, '_product_attributes' );
				};
				add_action( 'woocommerce_update_product', $strip, 99 );
				try {
					( new StarterImporter() )->start();
					$result = ( new StarterImporter() )->process_next( 600.0 );
				} finally {
					remove_action( 'woocommerce_update_product', $strip, 99 );
				}
				$this->same( [ 'starter_import_verify_failed', 'attributes' ], is_wp_error( $result ) ? [ $result->get_error_code(), $result->get_error_data()['field'] ?? '' ] : [ $this->status( $result ) ], 'a simple product whose attributes did not save stops at verification' );

				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Retry completes the simple product' );
				$this->same( 1, $this->count_posts( 'product' ), 'one simple product, no duplicate' );
				$product    = $this->product_by_slug( 'paper-pack' );
				$attributes = $product instanceof WC_Product_Simple ? $product->get_attributes( 'edit' ) : [];
				$this->same( [ 'material', 'sheets-per-pack' ], array_keys( $attributes ), 'simple product: both product-level attributes copied' );
				$shape = static fn( $a ): array => $a instanceof WC_Product_Attribute ? [ $a->get_name(), $a->get_options(), $a->get_visible(), $a->get_variation(), $a->is_taxonomy(), $a->get_position() ] : [];
				$this->same( [ 'Material', [ 'Paper' ], true, false, false, 0 ], $shape( $attributes['material'] ?? null ), 'Material: Paper, visible, not for variations, product-level' );
				$this->same( [ 'Sheets per pack', [ '500', '1000' ], false, false, false, 1 ], $shape( $attributes['sheets-per-pack'] ?? null ), 'Sheets per pack: options in order, hidden, position 1' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Image sizes WordPress could not make
	 * ------------------------------------------------------------------ */

	private function image_sizes(): void {
		$this->seed(
			[ $this->simple( [ 'name' => 'Resized', 'slug' => 'resized', 'image' => 'r' ] ) ],
			[ 'r' => [ 'name' => 'resize-photo', 'alt' => 'R', 'size' => 400 ] ],
			[]
		);
		$image_row = static function (): array {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, attachment_id FROM %i', StarterSchema::import_images_table() ), ARRAY_A );

			return is_array( $row ) ? [ (string) $row['status'], (int) $row['attachment_id'] ] : [ '', 0 ];
		};

		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $image_row ) {
				$thumbnail   = (int) get_option( 'thumbnail_size_w' );
				$use_failing = static fn( array $editors ): array => array_merge( [ BC_Starter_Failing_Image_Editor::class ], $editors );
				add_filter( 'wp_image_editors', $use_failing );
				BC_Starter_Failing_Image_Editor::$fail_width = $thumbnail;
				try {
					( new StarterImporter() )->start();
					$result = ( new StarterImporter() )->process_next( 600.0 );
				} finally {
					BC_Starter_Failing_Image_Editor::$fail_width = null;
					remove_filter( 'wp_image_editors', $use_failing );
				}
				$this->code( 'starter_import_image_failed', $result, 'a size WordPress could not make stops the import' );

				[ $status, $attachment ] = $image_row();
				$meta                    = wp_get_attachment_metadata( $attachment );
				$this->same( true, $attachment > 0 && is_array( $meta ) && ! empty( $meta['sizes'] ), 'WordPress still saved metadata with the other sizes' );
				$this->same( true, $thumbnail > 0 && isset( wp_get_missing_image_subsizes( $attachment )['thumbnail'] ), 'the thumbnail size is missing' );
				$this->same( Repo::IMAGE_CREATED, $status, 'the image is not marked complete while a size is missing' );

				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Retry completes once WordPress can make the size' );
				$this->same( [ Repo::IMAGE_COMPLETE, $attachment ], $image_row(), 'Retry finished the same attachment' );
				$this->same( [ 1, 1 ], [ $this->count_posts( 'attachment' ), $this->count_original_files() ], 'no second image record or original file' );
				$this->same( [], wp_get_missing_image_subsizes( $attachment ), 'every size that fits the image now exists' );
				$meta = wp_get_attachment_metadata( $attachment );
				$this->same( true, is_array( $meta ) && is_file( dirname( (string) get_attached_file( $attachment ) ) . '/' . ( $meta['sizes']['thumbnail']['file'] ?? '-' ) ), 'the thumbnail file is on disk' );
				$product = $this->product_by_slug( 'resized' );
				$this->same( $attachment, $product instanceof WC_Product ? (int) $product->get_image_id() : 0, 'the product uses that image' );
			}
		);

		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $image_row ) {
				add_filter( 'wp_image_editors', '__return_empty_array' );
				try {
					( new StarterImporter() )->start();
					$result = ( new StarterImporter() )->process_next( 600.0 );
				} finally {
					remove_filter( 'wp_image_editors', '__return_empty_array' );
				}
				$this->code( 'starter_import_image_support_missing', $result, 'no image editor for the type: the import stops with a clear server message' );
				[ $status, $attachment ] = $image_row();
				$this->same( [ Repo::IMAGE_CREATED, '' ], [ $status, get_post_meta( $attachment, '_wp_attachment_metadata', true ) ], 'the image stays unfinished and no size-less metadata is saved' );

				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'Retry completes once the server can process the image' );
				$this->same( [ Repo::IMAGE_COMPLETE, $attachment ], $image_row(), 'Retry finished the same attachment (no editor case)' );
				$this->same( [ 1, 1 ], [ $this->count_posts( 'attachment' ), $this->count_original_files() ], 'no second image record or original file (no editor case)' );
				$this->same( [], wp_get_missing_image_subsizes( $attachment ), 'every size that fits the image exists after Retry (no editor case)' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Version data
	 * ------------------------------------------------------------------ */

	/** One variable product, three variations, three images (Any and disabled included). */
	private function small_version(): array {
		return $this->seed(
			[
				$this->variable(
					[
						'name'       => 'Small dauber',
						'slug'       => 'small-dauber',
						'image'      => 'a',
						'gallery'    => [ 'a', 'b', 'c' ],
						'attributes' => [ [ 'name' => 'Color', 'options' => [ 'Small Blue', 'Small Red', 'Small Green' ], 'visible' => true, 'variation' => true, 'position' => 0 ] ],
						'variations' => [
							[ 'attributes' => [ 'color' => 'Small Blue' ], 'regular_price' => '2.00', 'sku' => '', 'manage_stock' => true, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image' => 'a' ],
							[ 'attributes' => [ 'color' => 'Small Red' ], 'regular_price' => '2.50', 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => '', 'description' => '<p>Red</p>', 'image' => 'b' ],
							[ 'attributes' => [ 'color' => '' ], 'regular_price' => '3.00', 'sku' => '', 'manage_stock' => false, 'enabled' => false, 'tax_class' => 'parent', 'description' => '', 'image' => 'c' ],
						],
					]
				),
			],
			[ 'a' => [ 'name' => 'small-a', 'alt' => 'A' ], 'b' => [ 'name' => 'small-b', 'alt' => 'B' ], 'c' => [ 'name' => 'small-c', 'alt' => 'C' ] ],
			[]
		);
	}

	private function simple( array $data ): array {
		return [ 'type' => 'simple' ] + $data + [ 'description' => '', 'short_description' => '', 'regular_price' => '10.00', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [] ];
	}

	private function variable( array $data ): array {
		return [ 'type' => 'variable' ] + $data + [ 'description' => '', 'short_description' => '', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [], 'default_attributes' => [] ];
	}

	/**
	 * Seed a published version with real image files in the frozen folder.
	 *
	 * @return array{version_id:int,images:array<string,array<string,mixed>>}
	 */
	private function seed( array $products, array $images, array $terms ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->db( $wpdb->insert( StarterSchema::versions_table(), [ 'status' => StarterCatalogVersions::STATUS_PUBLISHED, 'product_count' => count( $products ), 'image_count' => count( $images ), 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => wp_json_encode( $terms ), 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] ), 'seed version' );
		$version = (int) $wpdb->insert_id;

		$image_rows = [];
		$source     = 90000;
		foreach ( $images as $key => $spec ) {
			$relative = 'v' . $version . '/' . $spec['name'] . '.png';
			$absolute = StarterCatalogStorage::base_dir() . '/' . $relative;
			wp_mkdir_p( dirname( $absolute ) );
			$size  = (int) ( $spec['size'] ?? 200 );
			$image = imagecreatetruecolor( $size, $size );
			imagefill( $image, 0, 0, imagecolorallocate( $image, crc32( $key ) % 255, ( crc32( $key ) >> 8 ) % 255, ( crc32( $key ) >> 16 ) % 255 ) );
			imagepng( $image, $absolute );
			imagedestroy( $image );
			$row = [ 'version_id' => $version, 'source_attachment_id' => ++$source, 'capture_status' => 'captured', 'file_path' => $relative, 'checksum' => hash_file( 'sha256', $absolute ), 'mime_type' => 'image/png', 'width' => $size, 'height' => $size, 'file_size' => filesize( $absolute ), 'alt_text' => (string) ( $spec['alt'] ?? '' ), 'created_at' => $now, 'updated_at' => $now ];
			$this->db( $wpdb->insert( StarterSchema::version_images_table(), $row ), 'seed version image' );
			$image_rows[ $key ] = $row + [ 'version_image_id' => (int) $wpdb->insert_id ];
		}
		$id_for = static fn( string $key ): int => (int) ( $image_rows[ $key ]['version_image_id'] ?? 0 );

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
				'image_id'          => isset( $product['image'] ) ? $id_for( $product['image'] ) : 0,
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
				$variation['image_id'] = $id_for( (string) $variation['image'] );
				unset( $variation['image'] );
				$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->next_source, 'parent_source_id' => $parent_source, 'product_type' => 'variation', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $variation ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version variation' );
			}
		}

		return [ 'version_id' => $version, 'images' => $image_rows ];
	}

	private function set_version_image( int $version_image_id, array $fields ): void {
		global $wpdb;
		$this->db( $wpdb->update( StarterSchema::version_images_table(), $fields, [ 'version_image_id' => $version_image_id ] ), 'adjust version image' );
	}

	/* ------------------------------------------------------------------ *
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-media-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter media disposable Hall', 'user_id' => $this->admin ] );
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
			WC_Tax::create_tax_class( 'Reduced rate' );
			WC_Tax::create_tax_class( 'Zero rate' );
		} finally {
			restore_current_blog();
		}

		return (int) $site;
	}

	private function user( string $label, int $site, string $role ): int {
		global $wpdb;
		$id = wp_insert_user( [ 'user_login' => 'starter-media-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
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
		wp_set_current_user( $user );
		switch_to_blog( $site );
		try {
			return $work();
		} finally {
			restore_current_blog();
			wp_set_current_user( $this->admin );
		}
	}

	private function worker( int $hall, int $user, string $action, string $step, float $budget ): int {
		$env = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_HALL'       => (string) $hall,
			'BC_WORKER_USER'       => (string) $user,
			'BC_WORKER_ACTION'     => $action,
			'BC_WORKER_CRASH_STEP' => $step,
			'BC_WORKER_BUDGET'     => (string) $budget,
			'BC_STARTER_DIR'       => StarterCatalogStorage::base_dir(),
			'PATH'                 => (string) getenv( 'PATH' ),
		];
		$pipes  = [];
		$handle = proc_open( [ PHP_BINARY, __DIR__ . '/starter-import-recovery-worker.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $handle ) ) {
			return -1;
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $handle );
		if ( 9 !== $code ) {
			echo "worker output ({$step}): " . trim( $stdout . ' ' . $stderr ) . "\n";
		}

		return $code;
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
			// WordPress deletes the site's files with the site but leaves its
			// folder; remove it only when it is exactly this site's folder in
			// either Multisite layout.
			$content = wp_normalize_path( WP_CONTENT_DIR );
			if ( $uploads === $content . '/blogs.dir/' . $site . '/files' ) {
				$this->remove_tree( $content . '/blogs.dir/' . $site, $content . '/blogs.dir/' . $site );
			} elseif ( $uploads === $content . '/uploads/sites/' . $site ) {
				$this->remove_tree( $uploads, $uploads );
			}
			$this->same( false, is_dir( $uploads ), 'owned disposable Hall upload folder removed' );
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
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
		delete_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION );
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

	/** @return array<int,WC_Product_Variation> In menu order. */
	private function variations( int $parent_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product_variation' AND post_parent = %d ORDER BY menu_order ASC, ID ASC", $wpdb->posts, $parent_id ) );
		$out = [];
		foreach ( $ids as $id ) {
			clean_post_cache( (int) $id );
			$variation = wc_get_product( (int) $id );
			if ( $variation instanceof WC_Product_Variation ) {
				$out[] = $variation;
			}
		}

		return $out;
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

	private function status( $state ): string {
		return is_array( $state ) ? (string) $state['status'] : ( is_wp_error( $state ) ? 'error:' . $state->get_error_code() . ' ' . wp_json_encode( $state->get_error_data() ) : var_export( $state, true ) );
	}

	private function code( string $expected, $actual, string $label ): void {
		$this->same( $expected, is_wp_error( $actual ) ? $actual->get_error_code() : $this->status( $actual ), $label );
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

exit( ( new StarterImportMediaTest() )->run( $bc_starter_dir ) );
