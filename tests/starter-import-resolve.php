<?php

declare(strict_types=1);

/**
 * ADR-049 stage 5, slice 5.3: Super Admin Resolve (section 13), against real
 * WooCommerce, real image files and real crashed requests on disposable
 * fixture Halls.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-resolve.php
 *
 * Proves every Resolve row of ADR-049 section 19:
 *   - products: one Draft match of the right type with no Catalog row offers
 *     Use and completes that same product; no match, two matches, a trashed
 *     match, another status, another type or a Play Product / Package row
 *     (archived included) offer Skip only; classification metadata is
 *     ignored; a database error or a Catalog schema that is not current
 *     stops with nothing offered; a change after the page view is refused;
 *     Skip leaves the found product unchanged and the import continues;
 *   - variations: one match under the saved parent, enabled or disabled,
 *     offers Use; another parent, another status, a parent that is no longer
 *     the import's Draft, no or two matches offer Skip only; Skip saves
 *     "Variation Blue skipped";
 *   - images: one "inherit" match with the planned original and checksum
 *     offers Use and its sizes resume, also after a "-scaled" copy; a missing
 *     or changed file, another path, no, two or trashed matches offer Skip
 *     only; Skip saves a note and changes no media; a database error stops;
 *   - access and requests: Super Admins only, a blocked Hall refused, new
 *     imports switched off still allowed, a required reason, the Hall lock,
 *     the choice and reason recorded before anything changes (an audit
 *     failure changes nothing), and the page's Resolve box and admin-ajax
 *     step;
 *   - interrupted writes: for Use and Skip on a product, a variation and an
 *     image, a failed import status write changes nothing and a failed item
 *     write leaves the item waiting, so Resolve or Continue can always move
 *     the import on.
 *
 * Frozen starter images live in a temporary folder for this run only.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_starter_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-resolve-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_starter_dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary starter image folder.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_starter_dir );

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\Migrations as CatalogSchema;
use BingoCommerce\Catalog\StarterCatalogStorage;
use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImportAdmin as Admin;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportLock;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportRepository as Repo;
use BingoCommerce\PortalIntegration\HallCommerceAccess;

final class StarterImportResolveTest {
	private const OWNER_OPTION = 'bc_starter_import_test_owner';
	private const USER_OWNER   = 'bc_starter_import_test_user_owner';
	private const REASON       = 'Checked with the Hall: the crashed request made this one.';

	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $passed = 0;
	private int $failed = 0;
	private int $next_source = 7000;
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
		$old_screen           = $GLOBALS['current_screen'] ?? null;
		$old_method           = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$baseline             = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}

		try {
			update_site_option( 'upload_space_check_disabled', '1' );
			$this->reset_network();
			$this->same( true, StarterSchema::ensure_network(), 'network starter tables ready' );
			update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

			$this->product_matches();
			$this->product_outcomes();
			$this->variations();
			$this->images();
			$this->access_and_requests();
			$this->page_and_ajax();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			$GLOBALS['current_screen']  = $old_screen;
			$_SERVER['REQUEST_METHOD'] = $old_method;
			wp_set_current_user( $this->admin );
			$this->cleanup();
			$this->reset_network();
			if ( null === $this->network_before['upload_space_check_disabled'] ) {
				delete_site_option( 'upload_space_check_disabled' );
			} else {
				update_site_option( 'upload_space_check_disabled', $this->network_before['upload_space_check_disabled'] );
			}
			$this->remove_tree( $starter_dir, rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-resolve-' );
		}

		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site}: all table definitions and rows byte-identical" );
		}
		$this->same( false, is_dir( $starter_dir ), 'temporary frozen image folder removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ *
	 * Products: what Resolve offers
	 * ------------------------------------------------------------------ */

	private function product_matches(): void {
		$this->seed( [ $this->simple( [ 'name' => 'Alpha', 'slug' => 'alpha', 'sku' => 'AL-1' ] ), $this->simple( [ 'name' => 'Beta', 'slug' => 'beta' ] ) ], [] );
		$hall = $this->stuck( 'insert' );

		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$item  = $this->stuck_item();
				$slug  = (string) $item['recovery_slug'];
				$found = $this->post_named( 'product', $slug );
				$offer = ( new StarterImporter() )->resolution();
				$this->same( [ 'product', 'item:' . (int) $item['item_id'], 'Alpha', $found, '' ], is_array( $offer ) ? [ $offer['kind'], $offer['target'], $offer['label'], $offer['found'], $offer['why'] ] : $this->status( $offer ), 'one Draft match, right type, no Catalog row: Use is offered' );
				$this->same( [ 'found', 'kind', 'label', 'target', 'why' ], is_array( $offer ) ? $this->sorted_keys( $offer ) : [], 'the offer carries no internal fields' );
				$why = fn(): string => $this->why( ( new StarterImporter() )->resolution() );

				foreach ( [ 'pending', 'private', 'publish', 'future' ] as $status ) {
					$this->set_post( $found, [ 'post_status' => $status ] );
					$this->same( 'status', $why(), "one match that is {$status}: Skip only" );
				}
				$this->set_post( $found, [ 'post_status' => 'draft' ] );
				$this->same( 'use', $why(), 'back to Draft: Use is offered again (the offer is worked out fresh)' );

				wp_set_object_terms( $found, 'grouped', 'product_type' );
				$this->same( 'type', $why(), 'product type changed: Skip only' );
				wp_set_object_terms( $found, 'simple', 'product_type' );
				$this->same( 'use', $why(), 'type restored: Use again' );

				$play = ( new BingoPlayProductService() )->create( $found, 'paper', 1, null );
				$this->same( false, is_wp_error( $play ), 'the match becomes a Play Product' );
				$this->same( 'catalog', $why(), 'a row in bingo_play_products: Skip only' );
				$this->db( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d AND meta_key LIKE %s', $wpdb->postmeta, $found, $wpdb->esc_like( 'bc_' ) . '%' ) ), 'remove classification metadata' );
				clean_post_cache( $found );
				$this->same( 'catalog', $why(), 'a Catalog row but no metadata: Skip only' );
				$this->db( $wpdb->delete( CatalogSchema::play_products_table(), [ 'wc_product_id' => $found ] ), 'remove the Play Product row' );
				$this->same( 'use', $why(), 'no Catalog row: Use again' );

				$now = current_time( 'mysql', true );
				$this->db( $wpdb->insert( CatalogSchema::packages_table(), [ 'wc_product_id' => $found, 'package_type' => 'paper', 'status' => 'archived', 'created_at' => $now, 'updated_at' => $now, 'archived_at' => $now ] ), 'archived Package row' );
				$this->same( 'catalog', $why(), 'an archived row in bingo_packages: Skip only' );
				$this->db( $wpdb->delete( CatalogSchema::packages_table(), [ 'wc_product_id' => $found ] ), 'remove the Package row' );

				update_post_meta( $found, 'bc_product_kind', 'bingo_package' );
				$this->same( 'use', $why(), 'bc_product_kind set but no Catalog row: still eligible' );
				delete_post_meta( $found, 'bc_product_kind' );

				$this->set_post( $found, [ 'post_name' => strtoupper( $slug ) ] );
				$collation_matches = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d AND post_name = %s', $wpdb->posts, $found, $slug ) );
				$this->same( $collation_matches ? 'name' : 'none', $why(), 'a slug that only the database collation matches: Skip only (exact compare)' );
				$this->set_post( $found, [ 'post_name' => 'renamed-by-hall' ] );
				$this->same( 'none', $why(), 'no match: Skip only' );
				$this->set_post( $found, [ 'post_name' => $slug ] );

				$other = ( new WC_Product_Simple() )->save();
				$this->set_post( $other, [ 'post_name' => $slug ] );
				$this->same( 'many', $why(), 'two matches: Skip only' );
				$this->set_post( $other, [ 'post_name' => $slug . '__trashed', 'post_status' => 'trash' ] );
				$this->same( 'many', $why(), 'the Draft plus a trashed copy: two matches, Skip only' );
				wp_delete_post( $other, true );
				$this->same( 'use', $why(), 'one match again: Use' );

				$this->code( 'starter_import_db_error', $this->fault( 'AND post_name IN (', static fn() => ( new StarterImporter() )->resolution() ), 'a database error in the search: Resolve stops and offers nothing' );
				$this->code( 'starter_import_db_error', $this->fault( 'UNION ALL SELECT 1 FROM', static fn() => ( new StarterImporter() )->resolution() ), 'a database error in the Catalog check: Resolve stops and offers nothing' );
				update_option( CatalogSchema::VERSION_OPTION, CatalogSchema::DB_VERSION - 1 );
				$this->code( 'starter_import_unavailable', ( new StarterImporter() )->resolution(), 'Catalog schema not current: Resolve stops and offers nothing' );
				update_option( CatalogSchema::VERSION_OPTION, CatalogSchema::DB_VERSION );

				wp_trash_post( $found );
				$this->same( 'trashed', $why(), 'the match was trashed: Skip only' );
				wp_delete_post( $found, true );
				$this->same( 'none', $why(), 'the match was deleted: Skip only' );
			}
		);

		// A variable product that crashed before WooCommerce recorded its type
		// reads as simple: a different type, so Skip only. Once the type is
		// saved, Use is offered.
		$this->seed( [ $this->dauber() ], [] );
		foreach ( [ 'insert' => 'type', 'product_created' => 'use' ] as $step => $expected ) {
			$hall = $this->stuck( $step );
			$this->same( $expected, $this->in_hall( $hall, $this->admin, fn() => $this->why( ( new StarterImporter() )->resolution() ) ), "variable product crashed at {$step}: " . ( 'use' === $expected ? 'Use is offered' : 'Skip only (no type saved yet)' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Products: Use, Skip and a change after the page view
	 * ------------------------------------------------------------------ */

	private function product_outcomes(): void {
		$this->seed( [ $this->simple( [ 'name' => 'Alpha', 'slug' => 'alpha', 'sku' => 'AL-1' ] ), $this->simple( [ 'name' => 'Beta', 'slug' => 'beta' ] ) ], [] );

		$hall = $this->stuck( 'insert' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $hall ) {
				global $wpdb;
				$item  = $this->stuck_item();
				$found = $this->post_named( 'product', (string) $item['recovery_slug'] );
				$this->interrupted( 'product Use', 'item:' . (int) $item['item_id'], 'use', $found, StarterSchema::import_products_table() );
				update_site_option( Versions::ALLOW_IMPORTS_OPTION, '0' );
				$state = ( new StarterImporter() )->resolve( 'item:' . (int) $item['item_id'], 'use', $found, '  ' . self::REASON . "\n" );
				update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );
				$this->same( [ 'importing', null, null ], is_array( $state ) ? [ $state['status'], $state['last_error_code'], $state['last_error_item_id'] ] : $this->status( $state ), 'Use: the import continues, also with new imports switched off' );
				$row = $this->item_row( (int) $item['item_id'] );
				$this->same( [ Repo::ITEM_CREATED, $found ], [ $row['status'], (int) $row['wc_product_id'] ], 'Use saves the match\'s product ID' );

				$events = $this->resolved_events( $hall );
				$this->same( 1, count( $events ), 'one Resolve audit event' );
				$context = json_decode( (string) ( $events[0]['context'] ?? '' ), true );
				$this->same(
					[ (string) $this->admin, 'starter_import', 'product', 'item:' . (int) $item['item_id'], 'use', $found, self::REASON ],
					[ (string) ( $events[0]['actor_user_id'] ?? '' ), (string) ( $events[0]['target_type'] ?? '' ), $context['kind'] ?? null, $context['target'] ?? null, $context['choice'] ?? null, $context['found_id'] ?? null, $context['reason'] ?? null ],
					'the audit event records who, the item, the choice, the match and the trimmed reason'
				);

				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( [ 'complete', 2, 0 ], is_array( $state ) ? [ $state['status'], $state['imported_products'], $state['skipped_products'] ] : [ $this->status( $state ) ], 'after Use the import completes: two imported' );
				clean_post_cache( $found );
				$product = wc_get_product( $found );
				$this->same( [ 'draft', 'alpha', 'Alpha', 'AL-1', '10.00' ], $product instanceof WC_Product ? [ $product->get_status(), $product->get_slug(), $product->get_name(), $product->get_sku(), $product->get_regular_price() ] : [], 'the found product itself is finished: Draft, normal slug, name, SKU, price' );
				$this->same( [ Repo::ITEM_COMPLETE, 2 ], [ $this->item_row( (int) $item['item_id'] )['status'], $this->count_posts( 'product' ) ], 'the item completes with no duplicate product' );
			}
		);

		$hall = $this->stuck( 'insert' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				global $wpdb;
				$item   = $this->stuck_item();
				$found  = $this->post_named( 'product', (string) $item['recovery_slug'] );
				$this->interrupted( 'product Skip', 'item:' . (int) $item['item_id'], 'skip', $found, StarterSchema::import_products_table() );
				$before = $this->post_snapshot( $found );
				$state  = ( new StarterImporter() )->resolve( 'item:' . (int) $item['item_id'], 'skip', $found, self::REASON );
				$this->same( 'importing', $this->status( $state ), 'Skip: the import continues' );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( [ 'complete', 1, 1 ], is_array( $state ) ? [ $state['status'], $state['imported_products'], $state['skipped_products'] ] : [ $this->status( $state ) ], 'after Skip the import completes: one imported, one skipped' );
				$row = $this->item_row( (int) $item['item_id'] );
				$this->same( [ Repo::ITEM_SKIPPED, 'Its creation was interrupted and could not be confirmed, so it was skipped. Check your products and add it by hand if it is missing.' ], [ $row['status'], (string) $row['note'] ], 'the skipped item says why and what to check' );
				$this->same( $before, $this->post_snapshot( $found ), 'Skip leaves the found product and its data unchanged' );
				$this->same( 2, $this->count_posts( 'product' ), 'nothing else is created for the skipped item' );
				$notes = Admin::page_state()['notes'];
				$this->same( true, in_array( 'Alpha', array_column( $notes, 'label' ), true ), 'the completed page lists the skipped product\'s note' );
			}
		);

		$hall = $this->stuck( 'insert' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $hall ) {
				$item   = $this->stuck_item();
				$target = 'item:' . (int) $item['item_id'];
				$found  = $this->post_named( 'product', (string) $item['recovery_slug'] );
				$this->set_post( $found, [ 'post_status' => 'publish' ] );
				$this->code( 'starter_import_resolve_changed', ( new StarterImporter() )->resolve( $target, 'use', $found, self::REASON ), 'the match changed after the page view: Use is refused' );
				$this->code( 'starter_import_resolve_changed', ( new StarterImporter() )->resolve( $target, 'skip', $found, self::REASON ), 'Skip based on the old offer is refused too' );
				$this->code( 'starter_import_resolve_changed', ( new StarterImporter() )->resolve( $target, 'use', 0, self::REASON ), 'Use is refused when only Skip is offered' );
				$this->code( 'starter_import_resolve_changed', ( new StarterImporter() )->resolve( 'item:' . ( (int) $item['item_id'] + 1000 ), 'skip', 0, self::REASON ), 'another item is refused' );
				$this->code( 'starter_import_resolve_changed', ( new StarterImporter() )->resolve( 'image:' . (int) $item['item_id'], 'skip', 0, self::REASON ), 'an image target for a product is refused' );
				$this->same( [ Repo::ITEM_NEEDS_ATTENTION, Repo::RECEIPT_NEEDS_ATTENTION, 0, 'publish' ], [ $this->item_row( (int) $item['item_id'] )['status'], Repo::receipt()['status'], count( $this->resolved_events( $hall ) ), get_post_status( $found ) ], 'refused choices change nothing and record nothing' );
				$this->same( 'importing', $this->status( ( new StarterImporter() )->resolve( $target, 'skip', 0, self::REASON ) ), 'Skip with the current offer is accepted' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Variations
	 * ------------------------------------------------------------------ */

	private function variations(): void {
		$this->seed( [ $this->dauber() ], [] );

		$hall = $this->stuck( 'insert-variation' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$item   = $this->stuck_item();
				$slug   = (string) $item['recovery_slug'];
				$found  = $this->post_named( 'product_variation', $slug );
				$parent = (int) $this->parent_product_id( $item );
				$offer  = ( new StarterImporter() )->resolution();
				$this->same( [ 'variation', 'item:' . (int) $item['item_id'], 'Dauber, variation Blue', $found, '' ], is_array( $offer ) ? [ $offer['kind'], $offer['target'], $offer['label'], $offer['found'], $offer['why'] ] : $this->status( $offer ), 'one variation match under the saved parent, enabled: Use is offered' );
				$why = fn(): string => $this->why( ( new StarterImporter() )->resolution() );

				$this->set_post( $found, [ 'post_status' => 'private' ] );
				$this->same( 'use', $why(), 'the match is disabled (private): Use is still offered' );
				$this->set_post( $found, [ 'post_status' => 'draft' ] );
				$this->same( 'status', $why(), 'the match has another status: Skip only' );
				$this->set_post( $found, [ 'post_status' => 'trash' ] );
				$this->same( 'trashed', $why(), 'the match is trashed: Skip only' );
				$this->set_post( $found, [ 'post_status' => 'publish' ] );

				$elsewhere = ( new WC_Product_Variable() )->save();
				$this->set_post( $found, [ 'post_parent' => $elsewhere ] );
				$this->same( 'parent', $why(), 'the match is under another parent: Skip only' );
				$this->set_post( $found, [ 'post_parent' => $parent ] );
				wp_delete_post( $elsewhere, true );

				$this->set_post( $parent, [ 'post_status' => 'publish' ] );
				$this->same( 'parent_state', $why(), 'the parent is no longer the import\'s Draft: Skip only' );
				$this->set_post( $parent, [ 'post_status' => 'draft' ] );

				$this->set_post( $found, [ 'post_name' => 'renamed-by-hall' ] );
				$this->same( 'none', $why(), 'no variation match: Skip only' );
				$this->set_post( $found, [ 'post_name' => $slug ] );
				$copy = wp_insert_post( [ 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => 'Copy' ], true );
				$this->set_post( (int) $copy, [ 'post_name' => $slug ] );
				$this->same( 'many', $why(), 'two variation matches: Skip only' );
				wp_delete_post( (int) $copy, true );
				$this->code( 'starter_import_db_error', $this->fault( 'AND post_name IN (', static fn() => ( new StarterImporter() )->resolution() ), 'a database error in the variation search: stops' );

				$this->interrupted( 'variation Use', 'item:' . (int) $item['item_id'], 'use', $found, StarterSchema::import_products_table() );
				$state = ( new StarterImporter() )->resolve( 'item:' . (int) $item['item_id'], 'use', $found, self::REASON );
				$this->same( 'importing', $this->status( $state ), 'variation Use: the import continues' );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( 'complete', $this->status( $state ), 'after variation Use the import completes' );
				clean_post_cache( $found );
				$variation = wc_get_product( $found );
				$this->same( [ Repo::ITEM_COMPLETE, 'publish', '2.00', 3 ], [ $this->item_row( (int) $item['item_id'] )['status'], $variation instanceof WC_Product_Variation ? $variation->get_status() : null, $variation instanceof WC_Product_Variation ? $variation->get_regular_price() : null, $this->count_posts( 'product_variation' ) ], 'the found variation itself is finished; three variations, no duplicate' );
			}
		);

		$hall = $this->stuck( 'insert-variation' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$item   = $this->stuck_item();
				$found  = $this->post_named( 'product_variation', (string) $item['recovery_slug'] );
				$this->interrupted( 'variation Skip', 'item:' . (int) $item['item_id'], 'skip', $found, StarterSchema::import_products_table() );
				$before = $this->post_snapshot( $found );
				$this->same( 'importing', $this->status( ( new StarterImporter() )->resolve( 'item:' . (int) $item['item_id'], 'skip', $found, self::REASON ) ), 'variation Skip: the import continues' );
				$state = ( new StarterImporter() )->process_next( 600.0 );
				$this->same( [ 'complete', 1, 0 ], is_array( $state ) ? [ $state['status'], $state['imported_products'], $state['skipped_products'] ] : [ $this->status( $state ) ], 'after variation Skip the product still completes' );
				$note = 'Variation Blue skipped: its creation was interrupted and could not be confirmed.';
				$this->same( [ Repo::ITEM_SKIPPED, $note ], [ $this->item_row( (int) $item['item_id'] )['status'], (string) $this->item_row( (int) $item['item_id'] )['note'] ], 'the skipped variation says "Variation Blue skipped"' );
				$parent_note = (string) $this->item_row( (int) $item['parent_item_id'] )['note'];
				$this->same( true, str_contains( $parent_note, $note ), 'the product\'s notes include the skipped variation' );
				$this->same( $before, $this->post_snapshot( $found ), 'Skip leaves the found variation unchanged' );
				$this->same( 3, $this->count_posts( 'product_variation' ), 'the other two variations are imported; nothing replaces the skipped one' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Images
	 * ------------------------------------------------------------------ */

	private function images(): void {
		$pictured = fn() => $this->seed(
			[ $this->simple( [ 'name' => 'Pictured', 'slug' => 'pictured', 'image' => 'x', 'gallery' => [ 'x', 'y' ] ] ) ],
			[ 'x' => [ 'name' => 'photo-x', 'alt' => 'X' ], 'y' => [ 'name' => 'photo-y', 'alt' => 'Y' ] ]
		);

		$pictured();
		$hall = $this->stuck( 'insert-attachment' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$image   = $this->stuck_image();
				$slug    = (string) $image['recovery_slug'];
				$found   = $this->post_named( 'attachment', $slug );
				$planned = wp_normalize_path( wp_upload_dir()['basedir'] . '/' . $image['planned_path'] );
				$offer   = ( new StarterImporter() )->resolution();
				$this->same( [ 'image', 'image:' . (int) $image['import_image_id'], 'photo-x.png', $found, '' ], is_array( $offer ) ? [ $offer['kind'], $offer['target'], $offer['label'], $offer['found'], $offer['why'] ] : $this->status( $offer ), 'one inherit image match with the planned original and checksum: Use is offered' );
				$why = fn(): string => $this->why( ( new StarterImporter() )->resolution() );

				$attached = (string) get_post_meta( $found, '_wp_attached_file', true );
				update_post_meta( $found, '_wp_attached_file', 'elsewhere/photo-x.png' );
				$this->same( 'file', $why(), 'the image\'s path differs: Skip only' );
				update_post_meta( $found, '_wp_attached_file', $attached );
				$this->same( 'use', $why(), 'path restored: Use again' );

				$bytes = (string) file_get_contents( $planned );
				file_put_contents( $planned, $bytes . 'changed' );
				$this->same( 'file', $why(), 'the file\'s checksum differs: Skip only' );
				file_put_contents( $planned, $bytes );
				rename( $planned, $planned . '.away' );
				$this->same( 'file', $why(), 'the file is missing: Skip only' );
				rename( $planned . '.away', $planned );

				$this->set_post( $found, [ 'post_status' => 'private' ] );
				$this->same( 'status', $why(), 'the image match is not inherit: Skip only' );
				$this->set_post( $found, [ 'post_status' => 'trash', 'post_name' => $slug . '__trashed' ] );
				$this->same( 'trashed', $why(), 'the image match is trashed (as with MEDIA_TRASH on): Skip only' );
				$this->set_post( $found, [ 'post_status' => 'inherit', 'post_name' => 'renamed-by-hall' ] );
				$this->same( 'none', $why(), 'no image match: Skip only' );
				$this->set_post( $found, [ 'post_name' => $slug ] );
				$copy = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_title' => 'Copy' ], true );
				$this->set_post( (int) $copy, [ 'post_name' => $slug ] );
				$this->same( 'many', $why(), 'two image matches: Skip only' );
				wp_delete_post( (int) $copy, true );
				$this->code( 'starter_import_db_error', $this->fault( 'AND post_name IN (', static fn() => ( new StarterImporter() )->resolution() ), 'a database error in the image search: stops' );
				$this->same( 'use', $why(), 'everything restored: Use again' );

				$this->interrupted( 'image Use', 'image:' . (int) $image['import_image_id'], 'use', $found, StarterSchema::import_images_table() );
				$this->same( 'importing', $this->status( ( new StarterImporter() )->resolve( 'image:' . (int) $image['import_image_id'], 'use', $found, self::REASON ) ), 'image Use: the import continues' );
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'after image Use the import completes' );
				$product = $this->product_by_slug( 'pictured' );
				$this->same( [ Repo::IMAGE_COMPLETE, $found, [], $found, 2, 2 ], [ $this->image_row( (int) $image['import_image_id'] )['status'], (int) $this->image_row( (int) $image['import_image_id'] )['attachment_id'], wp_get_missing_image_subsizes( $found ), $product instanceof WC_Product ? (int) $product->get_image_id() : 0, $this->count_posts( 'attachment' ), $this->count_original_files() ], 'the found image is finished with every size; no second image or file' );
			}
		);

		$this->seed( [ $this->simple( [ 'name' => 'Large photo', 'slug' => 'large-photo', 'image' => 'big' ] ) ], [ 'big' => [ 'name' => 'large-photo', 'alt' => 'Large', 'size' => 2700 ] ] );
		$hall = $this->stuck( 'insert-attachment' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$image   = $this->stuck_image();
				$found   = $this->post_named( 'attachment', (string) $image['recovery_slug'] );
				$planned = wp_normalize_path( wp_upload_dir()['basedir'] . '/' . $image['planned_path'] );
				wp_create_image_subsizes( $planned, $found );
				clean_post_cache( $found );
				$this->same( true, str_contains( (string) get_attached_file( $found ), '-scaled.' ), 'WordPress made a "-scaled" copy of the found image' );
				$offer = ( new StarterImporter() )->resolution();
				$this->same( [ $found, '' ], is_array( $offer ) ? [ $offer['found'], $offer['why'] ] : $this->status( $offer ), 'the same image after a "-scaled" copy: Use is offered' );
				$this->same( 'importing', $this->status( ( new StarterImporter() )->resolve( (string) $offer['target'], 'use', $found, self::REASON ) ), 'scaled image Use: the import continues' );
				$this->same( [ 'complete', 1 ], [ $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), $this->count_posts( 'attachment' ) ], 'the scaled image completes with one attachment' );
			}
		);

		$pictured();
		$hall = $this->stuck( 'insert-attachment' );
		$this->in_hall(
			$hall,
			$this->admin,
			function () {
				$image   = $this->stuck_image();
				$found   = $this->post_named( 'attachment', (string) $image['recovery_slug'] );
				$planned = wp_normalize_path( wp_upload_dir()['basedir'] . '/' . $image['planned_path'] );
				$this->interrupted( 'image Skip', 'image:' . (int) $image['import_image_id'], 'skip', $found, StarterSchema::import_images_table() );
				$before  = [ $this->post_snapshot( $found ), hash_file( 'sha256', $planned ) ];
				$this->same( 'importing', $this->status( ( new StarterImporter() )->resolve( 'image:' . (int) $image['import_image_id'], 'skip', $found, self::REASON ) ), 'image Skip: the import continues' );
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'after image Skip the product completes' );
				$note = 'Image photo-x.png could not be confirmed after an interrupted import, so it was left out.';
				$row  = $this->image_row( (int) $image['import_image_id'] );
				$this->same( [ Repo::IMAGE_OMITTED, $note ], [ $row['status'], (string) $row['note'] ], 'the image is recorded as left out with a note' );
				$product_note = (string) $this->first_item()['note'];
				$this->same( [ true, false ], [ str_contains( $product_note, $note ), str_contains( $product_note, 'was deleted in this store' ) ], 'the product note gives the Resolve reason, not "deleted in this store"' );
				$this->same( $before, [ $this->post_snapshot( $found ), hash_file( 'sha256', $planned ) ], 'Skip leaves the found attachment, its data and its file unchanged' );
				$product = $this->product_by_slug( 'pictured' );
				$this->same( [ 0, 1, 2 ], [ $product instanceof WC_Product ? (int) $product->get_image_id() : -1, $product instanceof WC_Product ? count( $product->get_gallery_image_ids() ) : -1, $this->count_posts( 'attachment' ) ], 'the skipped image is left out; the other image is imported; no image is created for the skipped one' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Access and requests
	 * ------------------------------------------------------------------ */

	private function access_and_requests(): void {
		$this->seed( [ $this->simple( [ 'name' => 'Alpha', 'slug' => 'alpha' ] ) ], [] );
		$hall    = $this->stuck( 'insert' );
		$manager = $this->user( 'mgr', $hall, 'bc_hall_manager' );

		$target = '';
		$found  = 0;
		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( &$target, &$found ) {
				$item   = $this->stuck_item();
				$target = 'item:' . (int) $item['item_id'];
				$found  = $this->post_named( 'product', (string) $item['recovery_slug'] );
			}
		);

		$this->code( 'starter_import_not_allowed', $this->in_hall( $hall, $manager, static fn() => ( new StarterImporter() )->resolution() ), 'a Hall Manager gets no Resolve offer' );
		$this->code( 'starter_import_not_allowed', $this->in_hall( $hall, $manager, static fn() => ( new StarterImporter() )->resolve( $target, 'use', $found, self::REASON ) ), 'a Hall Manager cannot resolve' );

		$this->in_hall(
			$hall,
			$this->admin,
			function () use ( $hall, $target, $found ) {
				global $wpdb;
				$resolve = static fn( string $choice, string $reason, string $to = '', int $id = -2 ) => ( new StarterImporter() )->resolve( '' !== $to ? $to : $target, $choice, -2 === $id ? $found : $id, $reason );

				foreach ( [ 'empty' => '', 'blank' => "  \n ", 'markup only' => '<b></b>', '501 characters' => str_repeat( 'a', 501 ) ] as $label => $reason ) {
					$this->code( 'starter_import_resolve_reason', $resolve( 'use', $reason ), "a reason that is {$label} is refused" );
				}
				foreach ( [ 'unknown choice' => [ 'delete', $target, $found ], 'bad target' => [ 'use', 'item:abc', $found ], 'other kind' => [ 'use', 'post:5', $found ], 'negative ID' => [ 'use', $target, -1 ] ] as $label => [ $choice, $to, $id ] ) {
					$this->code( 'starter_import_resolve_invalid', $resolve( $choice, self::REASON, $to, $id ), "an invalid request ({$label}) is refused" );
				}

				$lock = StarterImportLock::acquire_hall( $hall );
				try {
					$this->code( StarterImportLock::REENTRANT, $resolve( 'use', self::REASON ), 'Resolve takes the same Hall lock as the import' );
				} finally {
					$lock->release();
				}

				$this->code( 'starter_import_audit_failed', $this->fault( 'INSERT INTO `' . \BingoCommerce\Core\Migrations::audit_log_table() . '`', static fn() => $resolve( 'use', self::REASON ) ), 'when the choice cannot be recorded, Resolve refuses' );

				update_blog_option( $hall, HallCommerceAccess::MARKER_OPTION, wp_generate_uuid4() );
				try {
					$this->code( 'starter_import_unavailable', ( new StarterImporter() )->resolution(), 'a blocked or suspended Hall gets no Resolve offer' );
					$this->code( 'starter_import_unavailable', $resolve( 'use', self::REASON ), 'a blocked or suspended Hall cannot resolve' );
				} finally {
					delete_blog_option( $hall, HallCommerceAccess::MARKER_OPTION );
				}

				$item = $this->stuck_item();
				$this->same( [ $target, Repo::RECEIPT_NEEDS_ATTENTION, 0, 'draft' ], [ is_array( $item ) ? 'item:' . (int) $item['item_id'] : null, Repo::receipt()['status'], count( $this->resolved_events( $hall ) ), get_post_status( $found ) ], 'every refusal left the item, the import, the audit log and the product unchanged' );

				$long  = str_repeat( 'é', StarterImporter::MAX_RESOLVE_REASON );
				$state = $resolve( 'use', $long );
				$this->same( 'importing', $this->status( $state ), 'a 500-character reason (multibyte) is accepted' );
				$context = json_decode( (string) ( $this->resolved_events( $hall )[0]['context'] ?? '' ), true );
				$this->same( $long, $context['reason'] ?? null, 'the full reason is recorded' );
				$this->same( 'complete', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), 'the import then completes' );
				$this->same( null, ( new StarterImporter() )->resolution(), 'a finished import offers no Resolve' );
				$this->code( 'starter_import_resolve_changed', $resolve( 'skip', self::REASON ), 'Resolve on a finished import is refused' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * The page and its admin-ajax step
	 * ------------------------------------------------------------------ */

	private function page_and_ajax(): void {
		$this->seed( [ $this->simple( [ 'name' => 'Alpha', 'slug' => 'alpha' ] ) ], [] );
		$hall    = $this->stuck( 'insert' );
		$manager = $this->user( 'mgr2', $hall, 'bc_hall_manager' );
		[ $target, $found ] = $this->in_hall(
			$hall,
			$this->admin,
			function (): array {
				$item = $this->stuck_item();

				return [ 'item:' . (int) $item['item_id'], $this->post_named( 'product', (string) $item['recovery_slug'] ) ];
			}
		);

		$box = $this->resolve_box( $this->render( $hall, $this->admin ) );
		$this->same(
			[ true, true, true, [ 'use' => [ $target, (string) $found, true, 'Use it and continue' ], 'skip' => [ $target, (string) $found, true, 'Skip this item' ] ], '500' ],
			[ $box['present'], str_contains( $box['text'], 'Alpha: its creation started, but its product ID was not saved.' ), str_contains( $box['text'], "One matching draft was found: #{$found}." ), $box['buttons'], $box['maxlength'] ],
			'a Super Admin sees the Resolve box: what happened, the match, a reason box, Use and Skip (disabled until the script runs)'
		);
		$html = $this->render( $hall, $manager );
		$this->same( [ false, true ], [ $this->resolve_box( $html )['present'], str_contains( $html, 'Reference: SI-' ) ], 'a Hall Manager sees only the support reference' );

		$this->in_hall( $hall, $this->admin, fn() => $this->set_post( $found, [ 'post_status' => 'publish' ] ) );
		$box = $this->resolve_box( $this->render( $hall, $this->admin ) );
		$this->same( [ [ 'skip' ], true ], [ array_keys( $box['buttons'] ), str_contains( $box['text'], 'The match\'s status has changed. Only Skip is possible; Skip leaves any match unchanged.' ) ], 'Skip only: no Use button, and the box says why' );
		$this->in_hall( $hall, $this->admin, fn() => $this->set_post( $found, [ 'post_status' => 'draft' ] ) );

		$fields = [ 'step' => 'resolve', 'target' => $target, 'choice' => 'use', 'found' => (string) $found, 'reason' => self::REASON ];
		$this->same( [ 'starter_import_denied', 403 ], $this->ajax( $hall, $manager, $fields ), 'admin-ajax: a Hall Manager cannot resolve' );
		$this->same( [ 'starter_import_request', 400 ], $this->ajax( $hall, $this->admin, $fields + [ 'extra' => '1' ] ), 'admin-ajax: an unexpected field is refused' );
		$this->same( [ 'starter_import_request', 400 ], $this->ajax( $hall, $this->admin, array_diff_key( $fields, [ 'reason' => true ] ) ), 'admin-ajax: a missing reason field is refused' );
		foreach ( [ 'abc', '01', '-1', '' ] as $bad ) {
			$this->same( [ 'starter_import_request', 400 ], $this->ajax( $hall, $this->admin, [ 'found' => $bad ] + $fields ), "admin-ajax: found \"{$bad}\" is refused" );
		}
		$this->same( [ 'starter_import_request', 400 ], $this->ajax( $hall, $this->admin, [ 'step' => 'start', 'reason' => 'x' ] ), 'admin-ajax: Resolve fields are refused on another step' );
		$this->same( [ 'starter_import_resolve_changed', 409, 'needs_attention' ], $this->ajax( $hall, $this->admin, [ 'found' => (string) ( $found + 1 ) ] + $fields, true ), 'admin-ajax: an out-of-date offer is refused and the page is told the import still needs attention' );
		$this->same( [ 'importing', 'Importing product 1 of 1.' ], $this->ajax( $hall, $this->admin, $fields ), 'admin-ajax: Resolve succeeds and the import continues' );
		$this->same( 'complete', $this->ajax( $hall, $this->admin, [ 'step' => 'continue' ] )[0], 'admin-ajax: the next Continue completes the import' );
	}

	/* ------------------------------------------------------------------ *
	 * Version data
	 * ------------------------------------------------------------------ */

	private function dauber(): array {
		return $this->variable(
			[
				'name'       => 'Dauber',
				'slug'       => 'dauber',
				'attributes' => [ [ 'name' => 'Color', 'options' => [ 'Blue', 'Red', 'Green' ], 'visible' => true, 'variation' => true, 'position' => 0 ] ],
				'variations' => [
					[ 'attributes' => [ 'color' => 'Blue' ], 'regular_price' => '2.00', 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => 'parent', 'description' => '' ],
					[ 'attributes' => [ 'color' => 'Red' ], 'regular_price' => '2.50', 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => 'parent', 'description' => '' ],
					[ 'attributes' => [ 'color' => 'Green' ], 'regular_price' => '3.00', 'sku' => '', 'manage_stock' => false, 'enabled' => false, 'tax_class' => 'parent', 'description' => '' ],
				],
			]
		);
	}

	private function simple( array $data ): array {
		return [ 'type' => 'simple' ] + $data + [ 'description' => '', 'short_description' => '', 'regular_price' => '10.00', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [] ];
	}

	private function variable( array $data ): array {
		return [ 'type' => 'variable' ] + $data + [ 'description' => '', 'short_description' => '', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'gallery' => [], 'default_attributes' => [] ];
	}

	/** Seed a published version (the latest) with real image files in the frozen folder. */
	private function seed( array $products, array $images ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->db( $wpdb->insert( StarterSchema::versions_table(), [ 'status' => Versions::STATUS_PUBLISHED, 'product_count' => count( $products ), 'image_count' => count( $images ), 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => '{"product_cat":[],"product_tag":[]}', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] ), 'seed version' );
		$version = (int) $wpdb->insert_id;

		$image_ids = [];
		$source    = 95000;
		foreach ( $images as $key => $spec ) {
			$relative = 'v' . $version . '/' . $spec['name'] . '.png';
			$absolute = StarterCatalogStorage::base_dir() . '/' . $relative;
			wp_mkdir_p( dirname( $absolute ) );
			$size  = (int) ( $spec['size'] ?? 200 );
			$image = imagecreatetruecolor( $size, $size );
			imagefill( $image, 0, 0, imagecolorallocate( $image, crc32( $key ) % 255, ( crc32( $key ) >> 8 ) % 255, ( crc32( $key ) >> 16 ) % 255 ) );
			imagepng( $image, $absolute );
			imagedestroy( $image );
			$this->db( $wpdb->insert( StarterSchema::version_images_table(), [ 'version_id' => $version, 'source_attachment_id' => ++$source, 'capture_status' => 'captured', 'file_path' => $relative, 'checksum' => hash_file( 'sha256', $absolute ), 'mime_type' => 'image/png', 'width' => $size, 'height' => $size, 'file_size' => filesize( $absolute ), 'alt_text' => (string) ( $spec['alt'] ?? '' ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version image' );
			$image_ids[ $key ] = (int) $wpdb->insert_id;
		}
		$id_for = static fn( string $key ): int => (int) ( $image_ids[ $key ] ?? 0 );

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
				$variation['image_id'] = 0;
				$this->db( $wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->next_source, 'parent_source_id' => $parent_source, 'product_type' => 'variation', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $variation ), 'created_at' => $now, 'updated_at' => $now ] ), 'seed version variation' );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Stuck imports and reads
	 * ------------------------------------------------------------------ */

	/** A new Hall whose import crashed at $step and now needs attention. */
	private function stuck( string $step ): int {
		$hall = $this->create_hall();
		$this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, $this->admin, $step ), "a request crashed at {$step}" );
		$state = $this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->process_next( 600.0 ) );
		$this->same( 'needs_attention', $this->status( $state ), "after the crash at {$step} the import needs attention" );

		return $hall;
	}

	/** "use" when Use is offered, otherwise why not, or the error code. */
	private function why( $offer ): string {
		if ( ! is_array( $offer ) ) {
			return $this->status( $offer );
		}

		return $offer['found'] > 0 && '' === $offer['why'] ? 'use' : (string) $offer['why'];
	}

	private function stuck_item(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY item_id ASC LIMIT 1', StarterSchema::import_products_table(), Repo::ITEM_NEEDS_ATTENTION ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function stuck_image(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY import_image_id ASC LIMIT 1', StarterSchema::import_images_table(), Repo::IMAGE_NEEDS_ATTENTION ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function item_row( int $item_id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE item_id = %d', StarterSchema::import_products_table(), $item_id ), ARRAY_A );
	}

	private function first_item(): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE parent_item_id IS NULL ORDER BY item_id ASC LIMIT 1', StarterSchema::import_products_table() ), ARRAY_A );
	}

	private function image_row( int $import_image_id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE import_image_id = %d', StarterSchema::import_images_table(), $import_image_id ), ARRAY_A );
	}

	private function parent_product_id( array $item ): int {
		return (int) $this->item_row( (int) $item['parent_item_id'] )['wc_product_id'];
	}

	/** The one post of this type with exactly this slug (0 when none). */
	private function post_named( string $type, string $slug ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s AND post_name = %s ORDER BY ID ASC LIMIT 1', $wpdb->posts, $type, $slug ) );
	}

	/** Change post columns directly, the way another request might. */
	private function set_post( int $post_id, array $fields ): void {
		global $wpdb;
		$this->db( $wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] ), 'update post' );
		clean_post_cache( $post_id );
	}

	/** The post row and all of its metadata. */
	private function post_snapshot( int $post_id ): string {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $post_id ), ARRAY_A );
		$meta = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d ORDER BY meta_id ASC', $wpdb->postmeta, $post_id ), ARRAY_A );

		return hash( 'sha256', serialize( [ $post, $meta ] ) );
	}

	/** @return array<int,array<string,mixed>> */
	private function resolved_events( int $hall ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT actor_user_id, target_type, target_id, context FROM %i WHERE blog_id = %d AND action = %s ORDER BY id ASC', \BingoCommerce\Core\Migrations::audit_log_table(), $hall, 'starter_import.resolved' ), ARRAY_A );
	}

	private function sorted_keys( array $array ): array {
		$keys = array_keys( $array );
		sort( $keys );

		return $keys;
	}

	/* ------------------------------------------------------------------ *
	 * Page and admin-ajax helpers
	 * ------------------------------------------------------------------ */

	private function render( int $hall, int $user ): string {
		return (string) $this->in_hall(
			$hall,
			$user,
			static function () {
				set_current_screen( 'dashboard' );
				ob_start();
				try {
					Admin::render();
					return (string) ob_get_contents();
				} finally {
					ob_end_clean();
				}
			}
		);
	}

	/** @return array{present:bool,text:string,buttons:array<string,array>,maxlength:string} */
	private function resolve_box( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$box   = $xpath->query( '//*[@id="bc-starter-import"]//*[@data-bc-resolve-box]' )->item( 0 );
		if ( ! $box instanceof DOMElement ) {
			return [ 'present' => false, 'text' => '', 'buttons' => [], 'maxlength' => '' ];
		}
		$buttons = [];
		foreach ( $xpath->query( './/button[@data-bc-resolve]', $box ) as $button ) {
			$buttons[ $button->getAttribute( 'data-bc-resolve' ) ] = [ $button->getAttribute( 'data-bc-target' ), $button->getAttribute( 'data-bc-found' ), $button->hasAttribute( 'disabled' ), trim( $button->textContent ) ];
		}
		$reason = $xpath->query( './/textarea[@data-bc-resolve-reason]', $box )->item( 0 );

		return [
			'present'   => true,
			'text'      => preg_replace( '/\s+/', ' ', $box->textContent ),
			'buttons'   => $buttons,
			'maxlength' => $reason instanceof DOMElement ? $reason->getAttribute( 'maxlength' ) : '',
		];
	}

	/**
	 * One admin-ajax request through the handler.
	 *
	 * @return array Success: [status, text]. Error: [code, HTTP status] (plus the import status with $with_import).
	 */
	private function ajax( int $hall, int $user, array $fields, bool $with_import = false ): array {
		return $this->in_hall(
			$hall,
			$user,
			static function () use ( $fields, $with_import ): array {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				try {
					$posted = [ 'action' => Admin::AJAX_ACTION, '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_import:hall:' . get_current_blog_id() ) ] + $fields;
					$result = Admin::handle_ajax( $posted );
					if ( is_wp_error( $result ) ) {
						$data = (array) $result->get_error_data();
						$out  = [ $result->get_error_code(), (int) ( $data['status'] ?? 0 ) ];

						return $with_import ? array_merge( $out, [ $data['import_status'] ?? null ] ) : $out;
					}

					return [ $result['status'], $result['text'] ];
				} finally {
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-resolve-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter resolve disposable Hall', 'user_id' => $this->admin ] );
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
		$id = wp_insert_user( [ 'user_login' => 'starter-resolve-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
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

	private function worker( int $hall, int $user, string $step ): int {
		$env = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_HALL'       => (string) $hall,
			'BC_WORKER_USER'       => (string) $user,
			'BC_WORKER_ACTION'     => 'process',
			'BC_WORKER_CRASH_STEP' => $step,
			'BC_WORKER_BUDGET'     => '600',
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

	/**
	 * An interrupted Resolve never strands the import. A failed write of the
	 * import's status changes nothing, and the same Resolve is offered again.
	 * A failed write of the item or image leaves it waiting: the import is
	 * already continuing, Continue stops at it, and Resolve is offered again.
	 */
	private function interrupted( string $label, string $target, string $choice, int $found, string $item_table ): void {
		$resolve = static fn() => ( new StarterImporter() )->resolve( $target, $choice, $found, self::REASON );
		$offered = static function (): array {
			$offer = ( new StarterImporter() )->resolution();

			return is_array( $offer ) ? [ $offer['target'], $offer['found'] ] : [];
		};

		$this->code( 'starter_import_db_error', $this->fault( 'UPDATE `' . StarterSchema::import_table() . '` SET', $resolve ), "{$label}: a failed import status write stops Resolve" );
		$this->same( [ Repo::RECEIPT_NEEDS_ATTENTION, [ $target, $found ] ], [ Repo::receipt()['status'], $offered() ], "{$label}: nothing changed, and the same Resolve is offered again" );

		$this->code( 'starter_import_db_error', $this->fault( 'UPDATE `' . $item_table . '` SET', $resolve ), "{$label}: a failed item write stops Resolve" );
		$this->same( Repo::RECEIPT_IMPORTING, Repo::receipt()['status'], "{$label}: the import is marked as continuing" );
		$this->same( 'needs_attention', $this->status( ( new StarterImporter() )->process_next( 600.0 ) ), "{$label}: Continue stops at the item that still needs attention" );
		$this->same( [ $target, $found ], $offered(), "{$label}: the same Resolve is offered again" );
	}

	/** Replace queries containing $needle with invalid SQL while $operation runs. */
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

	private function cleanup(): void {
		global $wpdb;
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( array_keys( $this->sites ) as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) {
				throw new RuntimeException( 'Cleanup ownership guard refused.' );
			}
			delete_blog_option( $site, HallCommerceAccess::MARKER_OPTION );
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

	private function status( $state ): string {
		return is_array( $state ) ? (string) ( $state['status'] ?? 'array' ) : ( is_wp_error( $state ) ? 'error:' . $state->get_error_code() . ' ' . $state->get_error_message() : var_export( $state, true ) );
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

exit( ( new StarterImportResolveTest() )->run( $bc_starter_dir ) );
