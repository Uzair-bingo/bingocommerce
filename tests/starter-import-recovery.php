<?php

declare(strict_types=1);

/**
 * ADR-049 stage 3, slice 3.2: the starter import engine for simple products,
 * with crash recovery, against real WooCommerce on disposable fixture Halls.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-recovery.php
 *
 * Proves:
 *   - Start checks refuse without binding the Hall or creating products
 *     (imports off, no published version, currency, tax setting, missing tax
 *     class, wrong user, main site);
 *   - a full import as a Hall Manager and as a Super Admin: drafts with the
 *     agreed fields, the SKU-conflict and existing-category notes, text equal
 *     to what WordPress stores for that user, no Bingo data, no repeat import;
 *   - a crash at every durable step either finishes without a duplicate or,
 *     for "creation started but no product ID saved", becomes Needs attention
 *     without creating anything new; a crash after the start marker needs
 *     repair;
 *   - the Retry rules for a saved product ID (trashed, deleted, published,
 *     linked, edited) and that completed products are never touched again;
 *   - text verification failures (a rewriting hook, a failed write, an empty
 *     name) stop the item, and a retry completes it;
 *   - audit events once per transition, and other Halls unchanged.
 *
 * Writes only to token-owned disposable Halls and the starter network tables,
 * which only these suites create in the fixture.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\StarterCatalogVersions;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportRepository as Repo;

final class StarterImportRecoveryTest {
	private const OWNER_OPTION = 'bc_starter_import_test_owner';
	private const USER_OWNER   = 'bc_starter_import_test_user_owner';

	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;

	public function run(): int {
		global $wpdb;

		$this->token = wp_generate_password( 24, false, false );
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );

		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}

		try {
			$this->reset_network();
			$this->same( true, StarterSchema::ensure_network(), 'network starter tables ready' );
			$this->same( false, StarterCatalogVersions::imports_allowed(), 'imports are off until a Platform Admin turns them on' );
			update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, '1' );

			$this->start_checks();
			$main = $this->seed_version( $this->main_products(), $this->main_terms() );
			$this->full_import_as_manager( $main );
			$this->full_import_as_super_admin();
			$this->crash_recovery();
			$this->retry_rules();
			$this->failed_reads_and_error_fields();
			$this->text_failures();
			$this->not_started();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wp_set_current_user( $this->admin );
			$this->cleanup();
			$this->reset_network();
		}

		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		$this->same( false, get_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, false ), 'the imports switch is removed after the run' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Start checks
	 * ------------------------------------------------------------------ */

	private function start_checks(): void {
		$hall = $this->create_hall();

		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $hall ) {
				$this->code( 'starter_import_unavailable', ( new StarterImporter() )->start(), 'no published version: unavailable' );

				$this->seed_version( [ $this->product( [ 'slug' => 'building-only' ] ) ], [], StarterCatalogVersions::STATUS_BUILDING );
				$this->code( 'starter_import_unavailable', ( new StarterImporter() )->start(), 'a building version is never offered' );

				$this->seed_version( [ $this->product( [ 'slug' => 'needs-food-class', 'tax_class' => 'food' ] ) ], [] );
				$missing = ( new StarterImporter() )->start();
				$this->code( 'starter_import_tax_class_missing', $missing, 'a missing tax class is refused before any product' );
				$this->same( true, is_wp_error( $missing ) && str_contains( $missing->get_error_message(), 'food' ), 'the missing tax class is named' );

				$this->seed_version( [ $this->product( [ 'slug' => 'plain' ] ) ], [] );

				update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, '0' );
				$this->code( 'starter_import_unavailable', ( new StarterImporter() )->start(), 'imports turned off: unavailable' );
				update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, '1' );

				update_option( 'woocommerce_currency', 'EUR' );
				$this->code( 'starter_import_currency_mismatch', ( new StarterImporter() )->start(), 'currency mismatch refused' );
				update_option( 'woocommerce_currency', 'USD' );

				update_option( 'woocommerce_prices_include_tax', 'yes' );
				$this->code( 'starter_import_tax_setting_mismatch', ( new StarterImporter() )->start(), '"prices include tax" mismatch refused' );
				update_option( 'woocommerce_prices_include_tax', 'no' );

				$operator = $this->user( 'operator', $hall, 'bc_game_operator' );
				wp_set_current_user( $operator );
				$this->code( 'starter_import_not_allowed', ( new StarterImporter() )->start(), 'a Game Operator is refused' );
				wp_set_current_user( 0 );
				$this->code( 'starter_import_not_allowed', ( new StarterImporter() )->start(), 'a signed-out request is refused' );
				wp_set_current_user( $this->admin );

				$this->same( null, Repo::receipt(), 'failed checks leave no import receipt' );
				$this->same( '', (string) get_option( Repo::START_MARKER_OPTION, '' ), 'failed checks leave no start marker' );
				$this->same( 0, $this->count_products(), 'failed checks create no products' );
			}
		);

		switch_to_blog( (int) get_main_site_id() );
		try {
			$this->code( 'starter_import_unavailable', ( new StarterImporter() )->start(), 'the main site is refused' );
		} finally {
			restore_current_blog();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Full imports
	 * ------------------------------------------------------------------ */

	private function full_import_as_manager( int $version_id ): void {
		global $wpdb;

		$hall    = $this->create_hall();
		$other   = $this->create_hall();
		$manager = $this->user( 'manager', $hall, 'bc_hall_manager' );

		$existing_sku = 0;
		$snacks       = 0;
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( &$existing_sku, &$snacks ) {
				$product = new WC_Product_Simple();
				$product->set_name( 'Existing Hall product' );
				$product->set_status( 'publish' );
				$product->set_sku( 'DUP-1' );
				$existing_sku = (int) $product->save();
				$term         = wp_insert_term( 'Snacks', 'product_cat', [ 'slug' => 'snacks' ] );
				$snacks       = is_array( $term ) ? (int) $term['term_id'] : 0;
			}
		);
		$other_before = $this->snapshot( $other );

		$this->in_hall(
			$hall,
			$manager,
			function () use ( $version_id, $existing_sku, $snacks, $manager ) {
				global $wpdb;
				$before = $this->product_ids();

				$state = ( new StarterImporter() )->start();
				$this->same( 'importing', is_array( $state ) ? $state['status'] : $state, 'Hall Manager starts the import' );
				$this->same( $version_id, is_array( $state ) ? $state['version_id'] : 0, 'the latest published version is locked in' );
				$this->same( 3, is_array( $state ) ? $state['total_products'] : 0, 'three products to import' );
				$receipt = Repo::receipt();
				$this->same( (string) $receipt['import_uuid'], (string) get_option( Repo::START_MARKER_OPTION ), 'start marker matches the receipt' );

				$again = ( new StarterImporter() )->start();
				$this->same( (int) $receipt['import_id'], is_array( $again ) ? $again['import_id'] : 0, 'a second Start returns the same import' );

				$done = ( new StarterImporter() )->process_next( 60.0 );
				$this->same( 'complete', is_array( $done ) ? $done['status'] : $this->describe( $done ), 'import completes' );
				$this->same( [ 3, 0 ], is_array( $done ) ? [ $done['imported_products'], $done['skipped_products'] ] : [], 'three imported, none skipped' );

				$new = array_values( array_diff( $this->product_ids(), $before ) );
				$this->same( 3, count( $new ), 'exactly three new products' );

				$p1 = $this->product_by_slug( 'daubers-and-co' );
				$p2 = $this->product_by_slug( 'plain-bag' );
				$p3 = $this->product_by_slug( 'popcorn' );
				$this->same( true, $p1 instanceof WC_Product && $p2 instanceof WC_Product && $p3 instanceof WC_Product, 'all three found by their normal slugs' );
				if ( ! $p1 instanceof WC_Product || ! $p2 instanceof WC_Product || ! $p3 instanceof WC_Product ) {
					return;
				}

				foreach ( [ $p1, $p2, $p3 ] as $p ) {
					$this->same( 'draft', $p->get_status(), "{$p->get_slug()} is a draft" );
					$this->same( $manager, (int) get_post_field( 'post_author', $p->get_id() ), "{$p->get_slug()} author is the Hall Manager" );
					$this->same( '', (string) $p->get_meta( 'bc_product_kind', true, 'edit' ), "{$p->get_slug()} has no bc_product_kind" );
					$this->same( '', (string) $p->get_meta( 'bc_package_relation', true, 'edit' ), "{$p->get_slug()} has no bc_package_relation" );
					$this->same( '', (string) $p->get_sale_price( 'edit' ), "{$p->get_slug()} has no sale price" );
					$this->same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE wc_product_id = %d UNION ALL SELECT 1 FROM %i WHERE wc_product_id = %d', \BingoCommerce\Catalog\Migrations::play_products_table(), $p->get_id(), \BingoCommerce\Catalog\Migrations::packages_table(), $p->get_id() ) ), "{$p->get_slug()} has no Catalog row" );
				}

				$this->same( '36.15', $p1->get_regular_price( 'edit' ), 'P1 regular price exact' );
				$this->same( 'DAUB-01', $p1->get_sku( 'edit' ), 'P1 SKU copied' );
				$this->same( [ true, 0, 'outofstock', 'no' ], [ $p1->get_manage_stock(), $p1->get_stock_quantity(), $p1->get_stock_status(), $p1->get_backorders() ], 'P1 tracked stock starts at 0, out of stock, no backorders' );
				$content = (string) get_post_field( 'post_content', $p1->get_id() );
				$this->same( false, str_contains( $content, 'onclick' ), 'P1 disallowed attribute removed for a Hall Manager' );
				$this->same( false, str_contains( $content, '<iframe' ), 'P1 disallowed tag removed for a Hall Manager' );
				$this->same( true, str_contains( $content, '<strong>' ), 'P1 allowed HTML kept' );
				$this->same( true, str_contains( $content, 'C:temp' ), 'P1 one level of backslashes removed, as WordPress stores it (and as expected by verification)' );
				$this->same( '', (string) get_post_field( 'post_excerpt', $p2->get_id() ), 'P2 blank short description stays blank' );
				$supplies = get_term_by( 'slug', 'supplies', 'product_cat' );
				$bingo    = get_term_by( 'slug', 'bingo-supplies', 'product_cat' );
				$this->same( true, $supplies instanceof WP_Term && $bingo instanceof WP_Term && (int) $bingo->parent === (int) $supplies->term_id, 'P1 category created under its version parent' );
				$this->same( [ $bingo instanceof WP_Term ? (int) $bingo->term_id : -1 ], array_map( 'intval', $p1->get_category_ids() ), 'P1 categories' );
				$sg = get_term_by( 'slug', 'sg', 'product_tag' );
				$this->same( [ $sg instanceof WP_Term ? (int) $sg->term_id : -1 ], array_map( 'intval', $p1->get_tag_ids() ), 'P1 tags created and assigned' );

				$this->same( '', $p2->get_regular_price( 'edit' ), 'P2 blank price stays blank' );
				$this->same( '', $p2->get_sku( 'edit' ), 'P2 has no SKU' );
				$this->same( [ false, null, 'instock' ], [ $p2->get_manage_stock(), $p2->get_stock_quantity(), $p2->get_stock_status() ], 'P2 untracked stock starts in stock' );
				$this->same( 'reduced-rate', $p2->get_tax_class( 'edit' ), 'P2 tax class matched by slug' );

				$this->same( '5.50', $p3->get_regular_price( 'edit' ), 'P3 price keeps its trailing zero' );
				$this->same( '', $p3->get_sku( 'edit' ), 'P3 SKU left blank on conflict' );
				$this->same( 'DUP-1', ( wc_get_product( $existing_sku ) )->get_sku(), 'the existing Hall product keeps its SKU' );
				$this->same( [ $snacks ], array_map( 'intval', $p3->get_category_ids() ), 'P3 reuses the existing Hall category' );
				$this->same( 0, (int) get_term( $snacks, 'product_cat' )->parent, 'the existing category keeps its own parent' );

				$notes = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM %i WHERE wc_product_id = %d', StarterSchema::import_products_table(), $p3->get_id() ) );
				$this->same( true, str_contains( $notes, 'DUP-1' ), 'P3 note names the SKU conflict' );
				$this->same( true, str_contains( $notes, 'Snacks' ), 'P3 note names the category with a different parent' );

				$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'product' AND post_name LIKE %s", $wpdb->posts, $wpdb->esc_like( 'bcsi-' ) . '%' ) ), 'no recovery slug is left on any product' );

				$repeat = ( new StarterImporter() )->process_next( 60.0 );
				$this->same( 'complete', is_array( $repeat ) ? $repeat['status'] : $this->describe( $repeat ), 'Continue on a complete import changes nothing' );
				$this->same( 3, count( array_diff( $this->product_ids(), $before ) ), 'still exactly three new products' );

				$p1->set_name( 'Edited by the Hall' );
				$p1->save();
				wp_delete_post( $p2->get_id(), true );
				( new StarterImporter() )->process_next( 60.0 );
				( new StarterImporter() )->start();
				$this->same( 'Edited by the Hall', get_the_title( $p1->get_id() ), 'a completed product edited by the Hall is never refreshed' );
				$this->same( null, get_post( $p2->get_id() ), 'a completed product deleted by the Hall is never brought back' );

				$this->same( 1, $this->audit_count( 'starter_import.started' ), 'one started audit event' );
				$this->same( 1, $this->audit_count( 'starter_import.completed' ), 'one completed audit event, even after repeats' );
			}
		);

		$this->same( $other_before, $this->snapshot( $other ), 'another Hall is byte-identical during this import' );
	}

	private function full_import_as_super_admin(): void {
		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				( new StarterImporter() )->start();
				$done = ( new StarterImporter() )->process_next( 60.0 );
				$this->same( 'complete', is_array( $done ) ? $done['status'] : $this->describe( $done ), 'Super Admin import completes' );
				$p1 = $this->product_by_slug( 'daubers-and-co' );
				$this->same( true, $p1 instanceof WC_Product && str_contains( (string) get_post_field( 'post_content', $p1->get_id() ), 'onclick' ), 'a Super Admin keeps the markup a Hall Manager cannot save, and text still verifies' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Crash recovery
	 * ------------------------------------------------------------------ */

	private function crash_recovery(): void {
		global $wpdb;

		$this->seed_version(
			[
				$this->product( [ 'name' => 'Crash one', 'slug' => 'crash-one', 'description' => '<p>One</p>', 'sku' => 'CR-1', 'manage_stock' => true ] ),
				$this->product( [ 'name' => 'Crash two', 'slug' => 'crash-two', 'sku' => 'CR-2' ] ),
			],
			[]
		);

		$finishes = [ 'item_claimed', 'product_id_saved', 'fields_applied', 'decisions_saved', 'verified', 'slug_set', 'item_completed' ];
		$attention = [ 'creation_starting' => 0, 'insert' => 1, 'product_created' => 1 ];

		foreach ( array_merge( $finishes, array_keys( $attention ) ) as $step ) {
			$hall = $this->create_hall();
			$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );

			$code = $this->worker( $hall, $this->admin, 'process', $step );
			$this->same( 9, $code, "worker crashed at {$step}" );

			$this->in_hall(
				$hall,
				$this->admin,
				function () use ( $step, $finishes, $attention ) {
					global $wpdb;
					$state = ( new StarterImporter() )->process_next( 60.0 );
					if ( in_array( $step, $finishes, true ) ) {
						$this->same( 'complete', is_array( $state ) ? $state['status'] : $this->describe( $state ), "after a crash at {$step}, the import finishes" );
						$this->same( 2, $this->count_products(), "after a crash at {$step}, exactly two products exist" );
						$this->same( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', StarterSchema::import_products_table(), Repo::ITEM_COMPLETE ) ), "after a crash at {$step}, both items complete" );
						$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'product' AND post_name LIKE %s", $wpdb->posts, $wpdb->esc_like( 'bcsi-' ) . '%' ) ), "after a crash at {$step}, no recovery slug remains" );
					} else {
						$this->same( 'needs_attention', is_array( $state ) ? $state['status'] : $this->describe( $state ), "a crash at {$step} becomes Needs attention" );
						$this->same( $attention[ $step ], $this->count_products(), "a crash at {$step} creates nothing more" );
						$again = ( new StarterImporter() )->process_next( 60.0 );
						$this->same( 'needs_attention', is_array( $again ) ? $again['status'] : $this->describe( $again ), "Continue after {$step} still waits for Resolve" );
						$this->same( $attention[ $step ], $this->count_products(), "Continue after {$step} never creates another product" );
					}
				}
			);
		}

		$hall = $this->create_hall();
		$code = $this->worker( $hall, $this->admin, 'start', 'start_marker_saved' );
		$this->same( 9, $code, 'worker crashed right after the start marker' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$this->code( 'starter_import_repair_needed', ( new StarterImporter() )->start(), 'a marker without a receipt needs repair' );
				$this->same( null, Repo::receipt(), 'no receipt was created' );
				$this->same( 0, $this->count_products(), 'no products were created' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Retry rules for a saved product ID
	 * ------------------------------------------------------------------ */

	private function retry_rules(): void {
		$cases = [
			'trashed'   => static fn( int $id ) => wp_trash_post( $id ),
			'deleted'   => static fn( int $id ) => wp_delete_post( $id, true ),
			'published' => static fn( int $id ) => wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] ),
			'linked'    => static fn( int $id ) => ( new BingoPlayProductService() )->create( $id, 'paper', 1, null ),
			'edited'    => static fn( int $id ) => wp_update_post( [ 'ID' => $id, 'post_title' => 'Changed while importing' ] ),
			'sale'      => static function ( int $id ): void {
				$product = wc_get_product( $id );
				$product->set_regular_price( '10.00' );
				$product->set_sale_price( '5.00' );
				$product->set_date_on_sale_from( '2026-01-01' );
				$product->set_date_on_sale_to( '2030-01-01' );
				$product->save();
			},
		];

		foreach ( $cases as $case => $change ) {
			$hall = $this->create_hall();
			$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
			$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'product_id_saved' ), "{$case}: worker stopped after saving the product ID" );

			$this->in_hall(
				$hall,
				$this->admin,
				function () use ( $case, $change ) {
					global $wpdb;
					$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY item_id ASC LIMIT 1', StarterSchema::import_products_table() ), ARRAY_A );
					$id   = (int) $item['wc_product_id'];
					$change( $id );

					$state = ( new StarterImporter() )->process_next( 60.0 );
					$this->same( 'complete', is_array( $state ) ? $state['status'] : $this->describe( $state ), "{$case}: the import still completes" );
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, note FROM %i WHERE item_id = %d', StarterSchema::import_products_table(), (int) $item['item_id'] ), ARRAY_A );

					if ( 'sale' === $case ) {
						$this->same( Repo::ITEM_COMPLETE, $row['status'], 'sale added to an unfinished draft: finished' );
						clean_post_cache( $id );
						$product = wc_get_product( $id );
						$this->same( [ '10.00', '', null, null ], [ $product->get_regular_price( 'edit' ), $product->get_sale_price( 'edit' ), $product->get_date_on_sale_from( 'edit' ), $product->get_date_on_sale_to( 'edit' ) ], 'sale added to an unfinished draft: sale price and dates cleared, regular price as published' );
						$this->same( [ 2, 0 ], is_array( $state ) ? [ $state['imported_products'], $state['skipped_products'] ] : [], 'sale: two imported' );
						return;
					}

					if ( 'edited' === $case ) {
						$this->same( Repo::ITEM_COMPLETE, $row['status'], 'edited unfinished draft: finished' );
						$this->same( 'Crash one', get_the_title( $id ), 'edited unfinished draft: the edit is replaced, as warned' );
						$this->same( [ 2, 0 ], is_array( $state ) ? [ $state['imported_products'], $state['skipped_products'] ] : [], 'edited: two imported' );
						return;
					}

					$this->same( Repo::ITEM_SKIPPED, $row['status'], "{$case}: the item is skipped" );
					$this->same( true, '' !== (string) $row['note'], "{$case}: a note explains the skip" );
					$this->same( [ 1, 1 ], is_array( $state ) ? [ $state['imported_products'], $state['skipped_products'] ] : [], "{$case}: one imported, one skipped" );
					$post = get_post( $id );
					if ( 'deleted' === $case ) {
						$this->same( null, $post, 'deleted: not brought back' );
					} elseif ( 'trashed' === $case ) {
						$this->same( 'trash', $post instanceof WP_Post ? $post->post_status : null, 'trashed: left in the trash' );
					} elseif ( 'published' === $case ) {
						$this->same( 'publish', $post instanceof WP_Post ? $post->post_status : null, 'published: left as the Hall published it' );
					}
				}
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Failed reads and error fields on the receipt
	 * ------------------------------------------------------------------ */

	private function failed_reads_and_error_fields(): void {
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'product_id_saved' ), 'read failure: worker stopped after saving the first product ID' );

		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$items = StarterSchema::import_products_table();
				$first = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY item_id ASC LIMIT 1', $items ), ARRAY_A );

				$failed = $this->fault( 'SELECT ID, post_type, post_status, post_parent FROM `' . $wpdb->posts . '`', static fn() => ( new StarterImporter() )->process_next( 60.0 ) );
				$this->code( 'starter_import_db_error', $failed, 'a failed product read stops the item instead of skipping it' );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE item_id = %d', $items, (int) $first['item_id'] ), ARRAY_A );
				$this->same( Repo::ITEM_CREATED, $row['status'], 'the product is not marked removed after a failed read' );
				$receipt = Repo::receipt();
				$this->same( [ Repo::RECEIPT_FAILED, 'starter_import_db_error', (int) $first['item_id'] ], [ $receipt['status'], $receipt['last_error_code'], (int) $receipt['last_error_item_id'] ], 'the failure names the item' );

				// Straight after the item failure, with no successful resume in
				// between: the store-level failure must not keep the item's ID.
				update_option( 'woocommerce_currency', 'EUR' );
				$store = ( new StarterImporter() )->process_next( 60.0 );
				update_option( 'woocommerce_currency', 'USD' );
				$this->code( 'starter_import_currency_mismatch', $store, 'a store-level check fails right after the item failure' );
				$receipt = Repo::receipt();
				$this->same( [ 'starter_import_currency_mismatch', null ], [ $receipt['last_error_code'], $receipt['last_error_item_id'] ], 'a store-level failure after an item failure names no item' );

				$this->db( $wpdb->update( StarterSchema::import_table(), [ 'last_error_code' => 'starter_import_db_error', 'last_error_item_id' => (int) $first['item_id'] ], [ 'singleton' => 1 ] ), 'restore the item failure' );
				$state = ( new StarterImporter() )->process_next( 0.0 );
				$this->same( [ 'importing', null, null ], is_array( $state ) ? [ $state['status'], $state['last_error_code'], $state['last_error_item_id'] ] : [ $this->describe( $state ) ], 'resuming clears the old error while products remain' );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE item_id = %d', $items, (int) $first['item_id'] ), ARRAY_A );
				$this->same( [ Repo::ITEM_COMPLETE, (int) $first['wc_product_id'] ], [ $row['status'], (int) $row['wc_product_id'] ], 'Retry finishes the same product' );
				$this->same( 1, $this->count_products(), 'no duplicate after the failed read' );
			}
		);

		$this->same( 9, $this->worker( $hall, $this->admin, 'process', 'creation_starting' ), 'worker stopped while creating the second product' );

		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				// A stale code on an "importing" receipt, so the Needs attention
				// transition itself must clear it.
				$this->db( $wpdb->update( StarterSchema::import_table(), [ 'status' => Repo::RECEIPT_IMPORTING, 'last_error_code' => 'starter_import_stale_test' ], [ 'singleton' => 1 ] ), 'stale error code' );
				$state  = ( new StarterImporter() )->process_next( 60.0 );
				$second = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY item_id DESC LIMIT 1', StarterSchema::import_products_table() ), ARRAY_A );
				$this->same( [ 'needs_attention', null, (int) $second['item_id'] ], is_array( $state ) ? [ $state['status'], $state['last_error_code'], $state['last_error_item_id'] ] : [ $this->describe( $state ) ], 'Needs attention clears the old error code and names its item' );
				$this->same( 1, $this->count_products(), 'Needs attention created nothing new' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Text verification failures
	 * ------------------------------------------------------------------ */

	private function text_failures(): void {
		global $wpdb;

		$this->seed_version( [ $this->product( [ 'name' => 'Rewrite me', 'slug' => 'rewrite-me', 'description' => '<p>Original</p>' ] ) ], [] );

		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				( new StarterImporter() )->start();
				$rewrite = static function ( array $data ): array {
					if ( 'product' === ( $data['post_type'] ?? '' ) && str_contains( (string) ( $data['post_content'] ?? '' ), 'Original' ) ) {
						$data['post_content'] .= ' [rewritten]';
					}

					return $data;
				};
				add_filter( 'wp_insert_post_data', $rewrite, 99 );
				$failed = ( new StarterImporter() )->process_next( 60.0 );
				remove_filter( 'wp_insert_post_data', $rewrite, 99 );
				$this->code( 'starter_import_text_mismatch', $failed, 'a hook that rewrites saved text stops the item' );
				$this->same( Repo::RECEIPT_FAILED, (string) Repo::receipt()['status'], 'the import is marked failed' );
				$retry = ( new StarterImporter() )->process_next( 60.0 );
				$this->same( 'complete', is_array( $retry ) ? $retry['status'] : $this->describe( $retry ), 'Retry without the hook completes' );
				$this->same( 1, $this->count_products(), 'still one product' );
			}
		);

		$hall = $this->create_hall();
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				( new StarterImporter() )->start();
				$failed = $this->fault( 'UPDATE `' . $wpdb->posts . '` SET', static fn() => ( new StarterImporter() )->process_next( 60.0 ) );
				$this->code( 'starter_import_text_mismatch', $failed, 'a failed product text write is caught by verification' );
				$retry = ( new StarterImporter() )->process_next( 60.0 );
				$this->same( 'complete', is_array( $retry ) ? $retry['status'] : $this->describe( $retry ), 'Retry after a failed write completes' );
				$this->same( 1, $this->count_products(), 'no duplicate after a failed write' );
			}
		);

		$this->seed_version( [ $this->product( [ 'name' => '<script></script>', 'slug' => 'empty-name' ] ) ], [] );
		$hall    = $this->create_hall();
		$manager = $this->user( 'manager-empty', $hall, 'bc_hall_manager' );
		$this->in_hall(
			$hall,
			$manager,
			function () {
				global $wpdb;
				( new StarterImporter() )->start();
				$this->code( 'starter_import_name_empty', ( new StarterImporter() )->process_next( 60.0 ), 'a name that cleans to empty is stopped before saving' );
				$this->same( 0, $this->count_products(), 'no product created for an empty name' );
				$this->same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', StarterSchema::import_products_table() ) ), 'no item claimed for an empty name' );
			}
		);
	}

	private function not_started(): void {
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, fn() => $this->code( 'starter_import_not_started', ( new StarterImporter() )->process_next( 0.0 ), 'Continue without Start is refused' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Version data
	 * ------------------------------------------------------------------ */

	private function main_products(): array {
		return [
			$this->product(
				[
					'name'              => 'Daubers & Co "Classic"',
					'slug'              => 'daubers-and-co',
					'description'       => '<p onclick="steal()">Bright <strong>ink</strong> &amp; more &nbsp;&#8217;s &#150; it\'s C:\\temp</p><iframe src="https://example.invalid"></iframe>',
					'short_description' => '<em>Best seller</em>',
					'regular_price'     => '36.15',
					'sku'               => 'DAUB-01',
					'categories'        => [ 'bingo-supplies' ],
					'tags'              => [ 'sg' ],
					'manage_stock'      => true,
				]
			),
			$this->product(
				[
					'name'          => 'Plain bag',
					'slug'          => 'plain-bag',
					'regular_price' => '',
					'tax_class'     => 'reduced-rate',
				]
			),
			$this->product(
				[
					'name'          => 'Popcorn',
					'slug'          => 'popcorn',
					'regular_price' => '5.50',
					'sku'           => 'DUP-1',
					'categories'    => [ 'snacks' ],
					'tags'          => [ 'sg', 'food' ],
				]
			),
		];
	}

	private function main_terms(): array {
		return [
			'product_cat' => [
				[ 'slug' => 'supplies', 'name' => 'Supplies', 'parent' => '' ],
				[ 'slug' => 'bingo-supplies', 'name' => 'Bingo supplies', 'parent' => 'supplies' ],
				[ 'slug' => 'food', 'name' => 'Food', 'parent' => '' ],
				[ 'slug' => 'snacks', 'name' => 'Snacks', 'parent' => 'food' ],
			],
			'product_tag' => [
				[ 'slug' => 'sg', 'name' => 'SG' ],
				[ 'slug' => 'food', 'name' => 'Food' ],
			],
		];
	}

	private function product( array $overrides ): array {
		return $overrides + [
			'name'              => 'Starter product',
			'description'       => '',
			'short_description' => '',
			'slug'              => 'starter-product',
			'regular_price'     => '10.00',
			'sku'               => '',
			'categories'        => [],
			'tags'              => [],
			'tax_status'        => 'taxable',
			'tax_class'         => '',
			'manage_stock'      => false,
		];
	}

	private function seed_version( array $products, array $terms, string $status = StarterCatalogVersions::STATUS_PUBLISHED ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->db( $wpdb->insert( StarterSchema::versions_table(), [ 'status' => $status, 'product_count' => count( $products ), 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => wp_json_encode( $terms ), 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] ), 'seed version' );
		$version = (int) $wpdb->insert_id;
		foreach ( array_values( $products ) as $position => $data ) {
			$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => 1000 + $position, 'product_type' => 'simple', 'position' => $position + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $data ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version product' );
		}

		return $version;
	}

	/* ------------------------------------------------------------------ *
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter import disposable Hall', 'user_id' => $this->admin ] );
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
		$id = wp_insert_user( [ 'user_login' => 'starter-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
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

	private function worker( int $hall, int $user, string $action, string $step ): int {
		$env = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_HALL'       => (string) $hall,
			'BC_WORKER_USER'       => (string) $user,
			'BC_WORKER_ACTION'     => $action,
			'BC_WORKER_CRASH_STEP' => $step,
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
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables cleaned up' );
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
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

	private function fault( string $needle, callable $operation ) {
		global $wpdb;
		$hits   = 0;
		$filter = static function ( string $query ) use ( $needle, &$hits ): string {
			if ( str_contains( $query, $needle ) ) {
				++$hits;

				return 'INVALID starter_import_fixture_injected_failure';
			}

			return $query;
		};
		$prior = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return $operation();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $prior );
			$this->same( true, $hits > 0, 'failure injection actually exercised its target' );
		}
	}

	private function count_products(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'product' AND post_status <> 'auto-draft'", $wpdb->posts ) );
	}

	/** @return array<int,int> */
	private function product_ids(): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_status <> 'auto-draft'", $wpdb->posts ) ) );
	}

	private function product_by_slug( string $slug ): ?WC_Product {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_name = %s", $wpdb->posts, $slug ) );
		clean_post_cache( $id );
		$product = $id > 0 ? wc_get_product( $id ) : null;

		return $product instanceof WC_Product ? $product : null;
	}

	private function audit_count( string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND action = %s', \BingoCommerce\Core\Migrations::audit_log_table(), get_current_blog_id(), $action ) );
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

	private function describe( $value ): string {
		return is_wp_error( $value ) ? 'error:' . $value->get_error_code() . ' ' . wp_json_encode( $value->get_error_data() ) : var_export( $value, true );
	}

	private function code( string $expected, $actual, string $label ): void {
		$this->same( $expected, is_wp_error( $actual ) ? $actual->get_error_code() : $this->describe( $actual ), $label );
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

exit( ( new StarterImportRecoveryTest() )->run() );
