<?php

declare(strict_types=1);

/**
 * ADR-049 stage 4, slice 4.1: master selection, the Super-Admin-only Include
 * flag, and the Network Admin Starter Catalog page with its imports switch.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-master.php
 *
 * Proves (the non-Super-Admin is a real main-site shop manager):
 *   - the "Include in starter catalog" checkbox shows only to Super Admins on
 *     the main site, and saves only from its own form with a valid nonce;
 *   - on the main site a non-Super-Admin cannot add, change or delete the
 *     flag by any path: metadata functions, row-ID writes (including renaming
 *     a row to or from the key), WooCommerce metadata saves and a real
 *     WooCommerce REST request; other metadata still saves; a Super Admin can;
 *   - permanently deleting a flagged product leaves no orphan flag row;
 *   - Hall sites are not guarded (narrow scope);
 *   - the selection lists included products in any status except trash,
 *     ascending by ID, and a failed read is an error, never an empty list;
 *   - the page is Super Admin only, creates the network tables on request,
 *     reports a broken current schema for repair without recreating it, shows
 *     the latest version, and turns new Hall imports on and off.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Catalog\StarterCatalogAdmin;
use BingoCommerce\Catalog\StarterCatalogMaster as Master;
use BingoCommerce\Catalog\StarterCatalogVersions;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;

final class StarterCatalogMasterTest {
	private const OWNER_META   = 'bc_starter_catalog_test_owner';
	private const OTHER_META   = 'bc_starter_catalog_test_other';
	private const OWNER_OPTION = 'bc_starter_catalog_test_owner';
	private const USER_OWNER   = 'bc_starter_catalog_test_user_owner';

	private string $token;
	private int $admin;
	private int $site_admin = 0;
	private int $main;
	private array $products = [];
	private array $sites = [];
	private array $users = [];
	private int $passed = 0;
	private int $failed = 0;

	public function run(): int {
		global $wpdb;

		$this->token = wp_generate_password( 24, false, false );
		$this->main  = (int) get_main_site_id();
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		$this->same( true, is_super_admin(), 'fixture Network Super Admin' );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( (string) $message ) ); } );

		$before = [
			'flag_rows' => $this->count_flag_rows(),
			'products'  => $this->count_main( 'product' ),
			'imports'   => get_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, null ),
		];
		$old_screen = $GLOBALS['current_screen'] ?? null;
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		try {
			$this->same( [ 0, 0 ], [ $before['flag_rows'], $before['products'] ], 'fixture main site starts with no products and no Include flags' );
			$this->site_admin = $this->user( 'shop-manager', $this->main, 'shop_manager' );
			$this->same( [ false, true ], [ is_super_admin( $this->site_admin ), $this->on_main( $this->site_admin, fn() => current_user_can( 'edit_products' ) && current_user_can( 'edit_others_products' ) ) ], 'the main-site shop manager can edit products but is not a Super Admin' );

			$this->registration();
			$this->selection();
			$this->checkbox();
			$this->guard_non_super_admin();
			$this->spelling_variants();
			$this->guard_super_admin();
			$this->permanent_deletion();
			$this->failed_post_delete();
			$this->duplicate();
			$this->hall_not_guarded();
			$this->page();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			$GLOBALS['current_screen']  = $old_screen;
			$_SERVER['REQUEST_METHOD'] = $old_method;
			$_POST                     = [];
			wp_set_current_user( $this->admin );
			$this->cleanup();
			$this->reset_network();
			if ( null === $before['imports'] ) {
				delete_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION );
			} else {
				update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, $before['imports'] );
			}
		}

		$this->same( [ $before['flag_rows'], $before['products'] ], [ $this->count_flag_rows(), $this->count_main( 'product' ) ], 'main site left with no test products or Include flags' );
		$this->same( $before['imports'], get_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, null ), 'imports switch restored' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ */

	private function registration(): void {
		$this->same( 10, has_action( 'post_submitbox_misc_actions', [ Master::class, 'render_checkbox' ] ), 'checkbox hooked into the Publish box' );
		$this->same( 10, has_action( 'save_post_product', [ Master::class, 'save_checkbox' ] ), 'checkbox save hooked' );
		foreach ( [ 'add_post_metadata' => 'guard_add', 'update_post_metadata' => 'guard_update', 'delete_post_metadata' => 'guard_delete', 'update_post_metadata_by_mid' => 'guard_update_by_mid', 'delete_post_metadata_by_mid' => 'guard_delete_by_mid' ] as $hook => $method ) {
			$this->same( 10, has_filter( $hook, [ Master::class, $method ] ), "guard on {$hook}" );
		}
		$this->same( 10, has_action( 'network_admin_menu', [ StarterCatalogAdmin::class, 'network_menu' ] ), 'Starter Catalog page registered in Network Admin' );
		$this->same( [ 10, 10 ], [ has_action( 'delete_post', [ Master::class, 'deleted_post' ] ), has_action( 'deleted_post', [ Master::class, 'deleted_post' ] ) ], 'deletion marker cleared on delete_post and deleted_post' );
		$this->same( 10, has_filter( 'woocommerce_duplicate_product_exclude_meta', [ Master::class, 'exclude_from_duplicate' ] ), 'flag excluded from WooCommerce Duplicate' );
	}

	private function selection(): void {
		$this->on_main(
			$this->admin,
			function () {
				$this->same( [], Master::selected_ids(), 'nothing selected on an empty main site' );
				$private = $this->product( 'Private', 'private', true );
				$draft   = $this->product( 'Draft', 'draft', true );
				$publish = $this->product( 'Published', 'publish', true );
				$pending = $this->product( 'Pending', 'pending', true );
				$trashed = $this->product( 'Trashed', 'draft', true );
				wp_trash_post( $trashed );
				$no      = $this->product( 'Flag no', 'draft', false );
				update_post_meta( $no, Master::META_KEY, 'no' );
				$this->product( 'Unflagged', 'draft', false );
				$post = (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Not a product' ] );
				update_post_meta( $post, Master::META_KEY, Master::INCLUDED );

				$expected = [ $private, $draft, $publish, $pending ];
				sort( $expected );
				$this->same( $expected, Master::selected_ids(), 'included products in any status except trash, ascending by ID; "no", unflagged and non-products left out' );

				wp_delete_post( $post, true );
				$this->with_fault(
					'SELECT DISTINCT p.ID FROM',
					fn() => $this->same( 'starter_catalog_db_error', $this->code( Master::selected_ids() ), 'a failed selection read is an error, never an empty list' )
				);

				foreach ( $this->products as $id => $_ ) {
					wp_delete_post( $id, true );
				}
				$this->products = [];
				$this->same( [], Master::selected_ids(), 'selection empty again after cleanup' );

				// Rows written by any other path: only the exact key and value count.
				global $wpdb;
				$rows = [ 'BC_STARTER_CATALOG_INCLUDE' => 'yes', Master::META_KEY . ' ' => 'yes', "bc_starter_catalog_include\u{200B}" => 'yes', Master::META_KEY => 'YES', Master::META_KEY . '#' => 'yes ' ];
				$ids  = [];
				foreach ( $rows as $key => $value ) {
					$id    = $this->product( 'Variant row', 'draft', false );
					$ids[] = $id;
					$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $id, 'meta_key' => rtrim( (string) $key, '#' ), 'meta_value' => $value ] );
				}
				$exact = $this->product( 'Exact row', 'draft', true );
				$this->same( count( $rows ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s AND post_id IN ( ' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ' )', array_merge( [ $wpdb->postmeta, Master::META_KEY, Master::INCLUDED ], $ids ) ) ), 'a plain SQL comparison would match every variant row (case, trailing space, zero-width, value case and spacing)' );
				$this->same( [ $exact ], Master::selected_ids(), 'the selection counts only the exact key and value' );

				foreach ( $this->products as $id => $_ ) {
					wp_delete_post( $id, true );
				}
				$this->products = [];
			}
		);
	}

	private function checkbox(): void {
		$this->on_main(
			$this->admin,
			function () {
				$product = $this->product( 'Checkbox', 'draft', false );
				$html    = $this->render_checkbox( $product );
				$this->same( true, str_contains( $html, 'name="bc_starter_catalog_include"' ) && str_contains( $html, '_bc_starter_catalog_include_nonce' ), 'Super Admin on the main site sees the checkbox with its nonce' );
				$this->same( false, str_contains( $html, 'checked' ), 'unchecked while not included' );

				$this->save_form( $product, true, true );
				$this->same( [ 'yes' ], $this->flag_rows( $product ), 'checking the box with a valid nonce includes the product' );
				$this->same( true, str_contains( $this->render_checkbox( $product ), "checked='checked'" ), 'the checkbox shows as checked' );

				$this->save_form( $product, false, false );
				$this->same( [ 'yes' ], $this->flag_rows( $product ), 'an invalid nonce changes nothing' );
				$this->save_form( $product, false, null );
				$this->same( [ 'yes' ], $this->flag_rows( $product ), 'a save without the checkbox form (quick edit, REST) changes nothing' );
				$this->save_form( $product, false, true );
				$this->same( [], $this->flag_rows( $product ), 'unchecking with a valid nonce removes the flag' );

				$post = (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Not a product' ] );
				$this->same( '', $this->render_checkbox( $post ), 'no checkbox on other post types' );
				wp_delete_post( $post, true );
			}
		);

		$this->on_main(
			$this->site_admin,
			function () {
				$product = $this->product( 'Checkbox admin', 'draft', false, $this->admin );
				$this->same( '', $this->render_checkbox( $product ), 'a main-site shop manager who is not a Super Admin sees no checkbox' );
				$this->save_form( $product, true, true );
				$this->same( [], $this->flag_rows( $product ), 'their own valid nonce still cannot include the product' );
			}
		);

		$hall = $this->create_hall();
		$this->in_site(
			$hall,
			$this->admin,
			function () {
				$post = (int) wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Hall product' ] );
				$this->same( '', $this->render_checkbox( $post ), 'no checkbox on a Hall, even for a Super Admin' );
			}
		);
	}

	private function guard_non_super_admin(): void {
		[ $flagged, $plain ] = $this->on_main(
			$this->admin,
			function () {
				$flagged = $this->product( 'Flagged', 'draft', true );
				$plain   = $this->product( 'Plain', 'draft', false );
				update_post_meta( $plain, self::OTHER_META, 'start' );

				return [ $flagged, $plain ];
			}
		);
		$flag_mid  = $this->flag_mid( $flagged );
		$other_mid = (int) $this->on_main( $this->admin, fn() => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $GLOBALS['wpdb']->postmeta, $plain, self::OTHER_META ) ) );

		$this->on_main(
			$this->site_admin,
			function () use ( $flagged, $plain, $flag_mid, $other_mid ) {
				$this->same( false, add_post_meta( $plain, Master::META_KEY, 'yes' ), 'non-Super-Admin: add refused' );
				$this->same( false, update_post_meta( $plain, Master::META_KEY, 'yes' ), 'non-Super-Admin: update of a missing flag refused' );
				$this->same( false, update_post_meta( $flagged, Master::META_KEY, 'no' ), 'non-Super-Admin: change refused' );
				$this->same( false, delete_post_meta( $flagged, Master::META_KEY ), 'non-Super-Admin: delete refused' );
				$this->same( false, delete_post_meta_by_key( Master::META_KEY ), 'non-Super-Admin: delete-everywhere refused' );
				$this->same( false, update_metadata_by_mid( 'post', $flag_mid, 'no' ), 'non-Super-Admin: change by row ID refused' );
				$this->same( false, update_metadata_by_mid( 'post', $flag_mid, 'yes', 'bc_renamed_key' ), 'non-Super-Admin: renaming the flag row away refused' );
				$this->same( false, update_metadata_by_mid( 'post', $other_mid, 'yes', Master::META_KEY ), 'non-Super-Admin: renaming another row into the flag refused' );
				$this->same( false, delete_metadata_by_mid( 'post', $flag_mid ), 'non-Super-Admin: delete by row ID refused' );

				$product = wc_get_product( $plain );
				$product->update_meta_data( Master::META_KEY, 'yes' );
				$product->save();
				$flagged_product = wc_get_product( $flagged );
				$flagged_product->delete_meta_data( Master::META_KEY );
				$flagged_product->save();
				$flagged_product = wc_get_product( $flagged );
				$flagged_product->update_meta_data( Master::META_KEY, 'no' );
				$flagged_product->save();

				$response = rest_do_request( $this->rest_update( $plain, [ [ 'key' => Master::META_KEY, 'value' => 'yes' ], [ 'key' => self::OTHER_META, 'value' => 'from-rest' ] ] ) );
				$this->same( 200, $response->get_status(), 'WooCommerce REST update by the main-site shop manager is accepted' );
				$this->same( 'from-rest', $this->meta_value( $plain, self::OTHER_META ), 'the same REST request saves other metadata' );

				$this->same( true, update_post_meta( $plain, self::OTHER_META, 'changed' ) !== false, 'other metadata keys are not guarded' );
			}
		);

		$this->same( [], $this->flag_rows( $plain ), 'after every refused path (functions, WooCommerce save, WooCommerce REST): still not included' );
		$this->same( [ 'yes' ], $this->flag_rows( $flagged ), 'after every refused path: the included product is still included, unchanged' );
		$this->same( Master::META_KEY, (string) $this->on_main( $this->admin, fn() => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT meta_key FROM %i WHERE meta_id = %d', $GLOBALS['wpdb']->postmeta, $flag_mid ) ) ), 'the flag row was not renamed' );
		$this->same( self::OTHER_META, (string) $this->on_main( $this->admin, fn() => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT meta_key FROM %i WHERE meta_id = %d', $GLOBALS['wpdb']->postmeta, $other_mid ) ) ), 'the other row was not renamed into the flag' );

		$this->on_main(
			0,
			fn() => $this->same( false, update_post_meta( $plain, Master::META_KEY, 'yes' ), 'no signed-in user (cron, WP-CLI without --user): refused' )
		);
		$this->same( [], $this->flag_rows( $plain ), 'still not included after the anonymous attempt' );
	}

	/** Spellings MySQL treats as the same key are refused; MySQL decides, not PHP. */
	private function spelling_variants(): void {
		global $wpdb;

		[ $flagged, $plain, $other_mid ] = $this->on_main(
			$this->admin,
			function () {
				global $wpdb;
				$flagged = $this->product( 'Variant flagged', 'draft', true );
				$plain   = $this->product( 'Variant plain', 'draft', false );
				update_post_meta( $plain, self::OTHER_META, 'start' );

				return [ $flagged, $plain, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $plain, self::OTHER_META ) ) ];
			}
		);

		$equal     = [ 'BC_STARTER_CATALOG_INCLUDE', 'Bc_Starter_Catalog_Include', Master::META_KEY . ' ', 'bc_stärter_catalog_include', "bc_starter_catalog_include\u{200B}", 'ｂc_starter_catalog_include' ];
		$different = [ ' ' . Master::META_KEY, 'bc-starter-catalog-include' ];
		$verdict   = static fn( string $key ): string => (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT %s = %s', $key, Master::META_KEY ) );

		$this->on_main(
			$this->site_admin,
			function () use ( $equal, $different, $verdict, $flagged, $plain, $other_mid ) {
				foreach ( $equal as $key ) {
					$label = wp_json_encode( $key );
					$this->same( '1', $verdict( $key ), "MySQL treats {$label} as the flag key" );
					$this->same(
						[ false, false, false, false, false, false ],
						[
							add_post_meta( $plain, $key, 'yes' ),
							update_post_meta( $plain, $key, 'yes' ),
							update_post_meta( $flagged, $key, 'no' ),
							delete_post_meta( $flagged, $key ),
							delete_post_meta_by_key( $key ),
							update_metadata_by_mid( 'post', $other_mid, 'yes', $key ),
						],
						"{$label}: add, update, change, delete, delete-everywhere and rename-into all refused"
					);
				}

				$meta = array_map( static fn( string $key ): array => [ 'key' => $key, 'value' => 'yes' ], $equal );
				$response = rest_do_request( $this->rest_update( $plain, array_merge( $meta, [ [ 'key' => self::OTHER_META, 'value' => 'variants' ] ] ) ) );
				$this->same( [ 200, 'variants' ], [ $response->get_status(), $this->meta_value( $plain, self::OTHER_META ) ], 'WooCommerce REST with every variant spelling: accepted, other metadata saved' );

				foreach ( $different as $key ) {
					$label = wp_json_encode( $key );
					$this->same( '0', $verdict( $key ), "MySQL treats {$label} as a different key" );
					$this->same( true, is_int( add_post_meta( $plain, $key, 'yes' ) ), "{$label}: not the flag, so not refused" );
					delete_post_meta( $plain, $key );
				}
			}
		);

		$this->same( 0, $this->flag_rows_matching( $plain ), 'no row on the plain product that MySQL would read as the flag' );
		$this->same( [ 'yes' ], $this->flag_rows( $flagged ), 'the included product\'s exact flag row is unchanged' );
		$this->same( 1, $this->flag_rows_matching( $flagged ), 'and it has no extra variant row' );
		$this->same( false, in_array( $plain, (array) $this->on_main( $this->admin, fn() => Master::selected_ids() ), true ), 'the plain product is not included' );
		$this->same( self::OTHER_META, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_id = %d', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', $other_mid ) ), 'the other row was not renamed into a variant' );
	}

	/** wp_delete_post() can stop after delete_post; the marker must not outlive it. */
	private function failed_post_delete(): void {
		global $wpdb;

		$product = $this->on_main( $this->admin, fn() => $this->product( 'Delete fails', 'draft', true ) );
		$this->on_main(
			$this->site_admin,
			function () use ( $product ) {
				global $wpdb;
				$this->with_fault(
					'DELETE FROM `' . $wpdb->posts . '` WHERE `ID` = ' . $product,
					fn() => $this->same( false, wp_delete_post( $product, true ), 'the post row delete fails after delete_post (simulated)' )
				);
			}
		);
		$this->same( [ true, [] ], [ null !== get_blog_post( $this->main, $product ), $this->flag_rows( $product ) ], 'the post remains; its metadata was already deleted' );

		$this->on_main(
			$this->admin,
			function () use ( $product ) {
				update_post_meta( $product, self::OWNER_META, $this->token );
				update_post_meta( $product, Master::META_KEY, Master::INCLUDED );
			}
		);
		$this->on_main(
			$this->site_admin,
			fn() => $this->same( false, delete_post_meta( $product, Master::META_KEY ), 'afterwards the deletion exception no longer applies to that post' )
		);
		$this->same( [ 'yes' ], $this->flag_rows( $product ), 'its flag is still there' );
	}

	private function duplicate(): void {
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';

		$this->on_main(
			$this->admin,
			function () {
				$original = $this->product( 'Duplicated', 'draft', true );
				$copy     = ( new WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $original ) );
				$copy_id  = $copy instanceof WC_Product ? (int) $copy->get_id() : 0;
				if ( $copy_id > 0 ) {
					$this->products[ $copy_id ] = true;
				}
				$this->same( [ true, 'draft' ], [ $copy_id > 0, $copy_id > 0 ? get_post_status( $copy_id ) : '' ], 'a Super Admin duplicates an included product (the copy is a Draft)' );
				$this->same( [ [], [ 'yes' ] ], [ $this->flag_rows( $copy_id ), $this->flag_rows( $original ) ], 'the copy is not included; the original still is' );
				$selected = (array) Master::selected_ids();
				$this->same( [ true, false ], [ in_array( $original, $selected, true ), in_array( $copy_id, $selected, true ) ], 'only the original is selected' );
			}
		);
	}

	private function guard_super_admin(): void {
		$this->on_main(
			$this->admin,
			function () {
				$plain = $this->product( 'Super Admin paths', 'draft', false );
				$this->same( true, false !== add_post_meta( $plain, Master::META_KEY, 'yes' ), 'Super Admin: add allowed' );
				$this->same( true, update_post_meta( $plain, Master::META_KEY, 'no' ), 'Super Admin: change allowed' );
				$this->same( true, delete_post_meta( $plain, Master::META_KEY ), 'Super Admin: delete allowed' );

				$product = wc_get_product( $plain );
				$product->update_meta_data( Master::META_KEY, 'yes' );
				$product->save();
				$this->same( [ 'yes' ], $this->flag_rows( $plain ), 'Super Admin: WooCommerce save includes the product' );
				$product = wc_get_product( $plain );
				$product->delete_meta_data( Master::META_KEY );
				$product->save();
				$this->same( [], $this->flag_rows( $plain ), 'Super Admin: WooCommerce save removes the flag' );

				$response = rest_do_request( $this->rest_update( $plain, [ [ 'key' => Master::META_KEY, 'value' => 'yes' ] ] ) );
				$this->same( [ 200, [ 'yes' ] ], [ $response->get_status(), $this->flag_rows( $plain ) ], 'Super Admin: WooCommerce REST includes the product' );
			}
		);
	}

	private function permanent_deletion(): void {
		[ $by_post, $by_wc, $kept ] = $this->on_main(
			$this->admin,
			fn() => [ $this->product( 'Delete by post', 'draft', true ), $this->product( 'Delete by WooCommerce', 'draft', true ), $this->product( 'Kept', 'draft', true ) ]
		);

		$this->on_main(
			$this->site_admin,
			function () use ( $by_post, $by_wc, $kept ) {
				$this->same( true, wp_delete_post( $by_post, true ) instanceof WP_Post, 'a main-site shop manager permanently deletes an included product' );
				$product = wc_get_product( $by_wc );
				$this->same( true, $product instanceof WC_Product && $product->delete( true ), 'the same through WooCommerce' );
				$this->same( false, delete_post_meta( $kept, Master::META_KEY ), 'afterwards, removing the flag from another product is still refused' );
				wp_trash_post( $kept );
			}
		);

		foreach ( [ $by_post, $by_wc ] as $id ) {
			unset( $this->products[ $id ] );
			$this->same( [], $this->flag_rows( $id ), "deleted product {$id}: no orphan Include flag row left" );
		}
		$this->same( [ 'yes' ], $this->flag_rows( $kept ), 'trashing keeps the flag (the product can be restored)' );
	}

	private function hall_not_guarded(): void {
		$hall       = $this->create_hall();
		$hall_admin = $this->user( 'hall-admin', $hall, 'administrator' );
		$this->in_site(
			$hall,
			$hall_admin,
			function () {
				$post = (int) wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Hall product' ] );
				$this->same( false, is_super_admin(), 'the Hall administrator is not a Super Admin' );
				$this->same( true, false !== update_post_meta( $post, Master::META_KEY, 'yes' ), 'Hall sites are not guarded: the key means nothing there' );
			}
		);
	}

	private function page(): void {
		global $wpdb;

		$this->reset_network();
		$this->same( false, (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', StarterSchema::versions_table() ) ), 'network tables absent before the page is opened' );

		$this->on_main(
			$this->site_admin,
			function () {
				set_current_screen( 'dashboard-network' );
				$this->same( false, StarterCatalogAdmin::allowed(), 'page refused for a main-site shop manager who is not a Super Admin' );
				$this->same( 'wp_die: You are not authorized to manage the starter catalog.', $this->render_page_error(), 'opening it as them stops with 403' );
			}
		);
		$this->on_main(
			$this->admin,
			function () {
				set_current_screen( 'dashboard' );
				$this->same( false, StarterCatalogAdmin::allowed(), 'page refused outside Network Admin' );
			}
		);

		$this->on_main(
			$this->admin,
			function () {
				global $wpdb;
				set_current_screen( 'dashboard-network' );
				$this->same( true, StarterCatalogAdmin::allowed(), 'page allowed for a Super Admin in Network Admin' );

				$html = $this->render_page();
				$this->same( true, StarterSchema::is_network_current() && (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', StarterSchema::version_images_table() ) ), 'opening the page creates the network tables' );
				$this->same( true, str_contains( $html, 'None published yet.' ), 'no version published yet' );
				$this->same( true, str_contains( $html, esc_url( get_admin_url( get_main_site_id(), 'edit.php?post_type=product' ) ) ), 'links to the main site Products list' );
				$count = count( (array) Master::selected_ids() );
				$this->same( (string) $count, $this->included_shown( $html ), 'the page shows the included product count (trashed products not counted)' );
				$this->product( 'Counted', 'draft', true );
				$this->same( (string) ( $count + 1 ), $this->included_shown( $this->render_page() ), 'a newly included product is counted' );

				$now = current_time( 'mysql', true );
				$wpdb->insert( StarterSchema::versions_table(), [ 'status' => StarterCatalogVersions::STATUS_PUBLISHED, 'product_count' => 24, 'image_count' => 0, 'currency' => 'USD', 'prices_include_tax' => 'no', 'created_by' => $this->admin, 'published_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => '2026-10-19 18:05:00' ] );
				$version = (int) $wpdb->insert_id;
				$expected = sprintf( 'Version %d: 24 products, published %s by %s.', $version, get_date_from_gmt( '2026-10-19 18:05:00', 'm/d/Y g:i a' ), get_userdata( $this->admin )->display_name );
				$this->same( true, str_contains( $this->render_page(), esc_html( $expected ) ), 'shows the latest published version with an MM/DD/YYYY date and the publisher' );
				$wpdb->insert( StarterSchema::versions_table(), [ 'status' => StarterCatalogVersions::STATUS_PUBLISHED, 'product_count' => 1, 'image_count' => 0, 'currency' => 'USD', 'prices_include_tax' => 'no', 'created_by' => $this->admin, 'published_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => '2026-10-19 18:10:00' ] );
				$single = $this->render_page();
				$this->same( [ true, false ], [ str_contains( $single, ': 1 product, published' ), str_contains( $single, '1 products' ) ], 'one product reads "1 product", not "1 products"' );

				// Imports switch: post the page's own form back.
				$this->same( false, StarterCatalogVersions::imports_allowed(), 'new Hall imports off by default' );
				$html = $this->render_page();
				$this->same( true, str_contains( $html, 'Turn on new Hall imports' ), 'the page offers Turn on' );
				$on   = $this->post_page_form( $html );
				$this->same( [ true, true ], [ StarterCatalogVersions::imports_allowed(), str_contains( $on, 'New Hall imports are turned on.' ) && str_contains( $on, 'Turn off new Hall imports' ) ], 'Turn on saves and the page then offers Turn off' );
				$off  = $this->post_page_form( $on );
				$this->same( [ false, true ], [ StarterCatalogVersions::imports_allowed(), str_contains( $off, 'New Hall imports are turned off.' ) ], 'Turn off saves' );

				$nonce = wp_create_nonce( 'bingocommerce_starter_catalog:network:' . get_current_network_id() );
				$cases = [
					'bad nonce'     => [ [ '_wpnonce' => 'bad', 'bc_starter_catalog_action' => 'imports_on' ], 'starter_catalog_nonce' ],
					'unknown field' => [ [ '_wpnonce' => $nonce, 'bc_starter_catalog_action' => 'imports_on', 'blog_id' => '2' ], 'starter_catalog_request' ],
					'bad action'    => [ [ '_wpnonce' => $nonce, 'bc_starter_catalog_action' => 'publish' ], 'starter_catalog_request' ],
				];
				foreach ( $cases as $label => [ $posted, $code ] ) {
					$_SERVER['REQUEST_METHOD'] = 'POST';
					$this->same( [ $code, false ], [ $this->code( StarterCatalogAdmin::handle_request( $posted ) ), StarterCatalogVersions::imports_allowed() ], "switch refused ({$label}); nothing changed" );
				}
				$_SERVER['REQUEST_METHOD'] = 'GET';
				$this->same( 'starter_catalog_request', $this->code( StarterCatalogAdmin::handle_request( [ '_wpnonce' => $nonce, 'bc_starter_catalog_action' => 'imports_on' ] ) ), 'switch refused for a GET request' );
				$this->same( false, StarterCatalogVersions::imports_allowed(), 'still off' );

				// A current schema with a missing table is reported, never recreated.
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', StarterSchema::version_images_table() ) );
				$state = StarterCatalogAdmin::page_state();
				$this->same( 'starter_import_schema_repair_needed', $this->code( $state['schema'] ), 'a broken current schema is reported for repair' );
				$this->same( false, (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', StarterSchema::version_images_table() ) ), 'the missing table is not silently recreated' );
				$this->same( true, str_contains( $this->render_page(), 'The starter import storage needs repair.' ), 'the page shows the repair message' );
			}
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function render_checkbox( int $post_id ): string {
		ob_start();
		try {
			Master::render_checkbox( get_post( $post_id ) );
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** @param bool|null $valid_nonce null: no checkbox form fields at all. */
	private function save_form( int $product_id, bool $checked, ?bool $valid_nonce ): void {
		$_POST = [];
		if ( null !== $valid_nonce ) {
			$_POST['_bc_starter_catalog_include_nonce'] = $valid_nonce ? wp_create_nonce( 'bingocommerce_starter_catalog_include:' . $product_id ) : 'invalid';
			if ( $checked ) {
				$_POST['bc_starter_catalog_include'] = 'yes';
			}
		}
		try {
			wp_update_post( [ 'ID' => $product_id, 'post_title' => 'Saved ' . wp_generate_password( 6, false, false ) ] );
		} finally {
			$_POST = [];
		}
	}

	private function render_page(): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		try {
			StarterCatalogAdmin::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function included_shown( string $html ): string {
		return 1 === preg_match( '#Included products</th>\s*<td>\s*(\d+)#', $html, $match ) ? $match[1] : 'not shown';
	}

	private function render_page_error(): string {
		try {
			$this->render_page();
		} catch ( RuntimeException $error ) {
			return $error->getMessage();
		}

		return 'rendered';
	}

	/** Submit the page's form exactly as rendered. */
	private function post_page_form( string $html ): string {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$posted = [];
		foreach ( $dom->getElementsByTagName( 'form' )->item( 0 )->getElementsByTagName( 'input' ) as $input ) {
			if ( $input->hasAttribute( 'name' ) ) {
				$posted[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = wp_slash( $posted );
		ob_start();
		try {
			StarterCatalogAdmin::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_POST                     = [];
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
	}

	private function rest_update( int $product_id, array $meta ): WP_REST_Request {
		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $product_id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'meta_data' => $meta ] ) );

		return $request;
	}

	private function product( string $name, string $status, bool $included, ?int $as = null ): int {
		$run = function () use ( $name, $status, $included ): int {
			$product = new WC_Product_Simple();
			$product->set_name( 'Starter master test ' . $name );
			$product->set_status( $status );
			$product->set_regular_price( '5.00' );
			$id = (int) $product->save();
			if ( $id < 1 ) {
				throw new RuntimeException( 'Product fixture failed.' );
			}
			update_post_meta( $id, self::OWNER_META, $this->token );
			if ( $included ) {
				update_post_meta( $id, Master::META_KEY, Master::INCLUDED );
			}

			return $id;
		};
		$id = null === $as || $as === get_current_user_id() ? $run() : $this->on_main( $as, $run );
		$this->products[ $id ] = true;

		return $id;
	}

	private function on_main( int $user, callable $work ) {
		return $this->in_site( $this->main, $user, $work );
	}

	private function in_site( int $site, int $user, callable $work ) {
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

	/** Flag values read straight from the database, bypassing every cache. */
	private function flag_rows( int $post_id ): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', $post_id, Master::META_KEY ) );
	}

	/** Rows MySQL would read as the flag for this post (collation comparison). */
	private function flag_rows_matching( int $post_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', $post_id, Master::META_KEY ) );
	}

	private function flag_mid( int $post_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', $post_id, Master::META_KEY ) );
	}

	private function meta_value( int $post_id, string $key ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', $post_id, $key ) );
	}

	private function count_flag_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->get_blog_prefix( $this->main ) . 'postmeta', Master::META_KEY ) );
	}

	private function count_main( string $type ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->get_blog_prefix( $this->main ) . 'posts', $type ) );
	}

	private function with_fault( string $needle, callable $work ): void {
		global $wpdb;
		$fault    = static fn( string $query ): string => str_contains( $query, $needle ) ? 'SELECT broken_column_for_fault FROM no_such_table_for_fault' : $query;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			$work();
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $suppress );
		}
	}

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-master-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter master disposable Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) {
			throw new RuntimeException( 'Disposable Hall creation failed.' );
		}
		$this->sites[ $site ] = true;
		update_blog_option( $site, self::OWNER_OPTION, $this->token );

		return (int) $site;
	}

	private function user( string $label, int $site, string $role ): int {
		global $wpdb;
		$id = wp_insert_user( [ 'user_login' => 'scm-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Actor creation failed.' );
		}
		$this->users[] = (int) $id;
		update_user_meta( $id, self::USER_OWNER, $this->token );
		if ( $site !== $this->main && ( ! isset( $this->sites[ $site ] ) || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) ) {
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

	private function cleanup(): void {
		global $wpdb;

		wp_set_current_user( $this->admin );
		switch_to_blog( $this->main );
		try {
			$owned = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, self::OWNER_META, $this->token ) );
			foreach ( array_unique( array_merge( array_map( 'intval', $owned ), array_keys( $this->products ) ) ) as $id ) {
				if ( $this->token === get_post_meta( $id, self::OWNER_META, true ) ) {
					wp_delete_post( (int) $id, true );
				}
			}
		} finally {
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
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( str_starts_with( $table, $prefix ) ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
				}
			}
			$content = wp_normalize_path( WP_CONTENT_DIR );
			foreach ( [ $content . '/blogs.dir/' . $site . '/files' => $content . '/blogs.dir/' . $site, $content . '/uploads/sites/' . $site => $content . '/uploads/sites/' . $site ] as $expected => $folder ) {
				if ( $uploads === $expected && is_dir( $folder ) ) {
					$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
					foreach ( $items as $item ) {
						$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
					}
					rmdir( $folder );
				}
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
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

exit( ( new StarterCatalogMasterTest() )->run() );
