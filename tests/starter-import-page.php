<?php

declare(strict_types=1);

/**
 * ADR-049 stage 5, slice 5.1: the Hall's Products → Starter Products page,
 * its admin-ajax handler and the "still being imported" warning.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-page.php
 *
 * Proves:
 *   - the page, handler, warning and script are registered; the page exists
 *     on Hall sites only, for Hall Managers and Super Admins; the script, its
 *     nonce and the page's hidden-button rule load on this page only;
 *   - every page state (ADR-049 section 8): unavailable with each reason
 *     (nothing published, imports off, history missing, Hall blocked), not
 *     started, importing with the current variation, failed with a plain
 *     message and the affected product, needs attention with a support
 *     reference, complete with date, counts and notes; the right buttons for
 *     each, all disabled until the script enables them;
 *   - every request is re-checked (POST, rights, Hall site, nonce, fields,
 *     step); Import and Continue run through the handler to completion; a
 *     failed step reports the receipt's status so the page offers Retry; the
 *     JSON wrapper reports success and errors;
 *   - an unfinished imported product's edit screen warns that changes may be
 *     replaced; a finished or unrelated product's does not.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_frozen_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-page-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $bc_frozen_dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create the temporary folder.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_STARTER_CATALOG_DIR', $bc_frozen_dir );

putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImportAdmin as Admin;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportRepository as Repo;
use BingoCommerce\PortalIntegration\HallCommerceAccess;

final class StarterImportPageTest {
	private const OWNER_OPTION = 'bc_starter_page_test_owner';
	private const USER_OWNER   = 'bc_starter_page_test_user_owner';

	private string $token;
	private int $admin;
	private int $main;
	private array $sites   = [];
	private array $users   = [];
	private int $source    = 7000;
	private int $passed    = 0;
	private int $failed    = 0;

	public function run( string $frozen_dir ): int {
		global $wpdb;

		$this->token = strtolower( wp_generate_password( 8, false, false ) );
		$this->main  = (int) get_main_site_id();
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( (string) $message ) ); } );

		$old_screen = $GLOBALS['current_screen'] ?? null;
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$imports    = get_site_option( Versions::ALLOW_IMPORTS_OPTION, null );

		try {
			$this->reset_network();
			StarterSchema::ensure_network();

			$this->registration();
			$this->unavailable_and_not_started();
			$this->import_through_handler();
			$this->importing_and_needs_attention();
			$this->failed();
			$this->notes_display();
			$this->request_checks();
			$this->json_wrapper();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', $error->getMessage() . ' @' . $error->getFile() . ':' . $error->getLine(), 'suite completed' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			$GLOBALS['current_screen']  = $old_screen;
			$_SERVER['REQUEST_METHOD'] = $old_method;
			$_POST                     = [];
			$_GET                      = [];
			wp_set_current_user( $this->admin );
			$this->cleanup();
			$this->reset_network();
			null === $imports ? delete_site_option( Versions::ALLOW_IMPORTS_OPTION ) : update_site_option( Versions::ALLOW_IMPORTS_OPTION, $imports );
			$this->remove_tree( $frozen_dir );
		}
		$this->same( false, is_dir( $frozen_dir ), 'temporary folder removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ */

	private function registration(): void {
		$this->same( 10, has_action( 'admin_menu', [ Admin::class, 'hall_menu' ] ), 'page registered' );
		$this->same( [ 10, false ], [ has_action( 'wp_ajax_' . Admin::AJAX_ACTION, [ Admin::class, 'ajax' ] ), has_action( 'wp_ajax_nopriv_' . Admin::AJAX_ACTION ) ], 'handler for signed-in users only' );
		$this->same( 10, has_action( 'admin_notices', [ Admin::class, 'edit_screen_warning' ] ), 'edit-screen warning registered' );

		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr', $hall, 'bc_hall_manager' );
		$this->in_hall(
			$hall,
			$manager,
			function () {
				set_current_screen( 'dashboard' );
				$GLOBALS['menu']              = [];
				$GLOBALS['submenu']           = [];
				$GLOBALS['admin_page_hooks']  = [ 'edit.php?post_type=product' => 'products' ];
				$GLOBALS['_registered_pages'] = [];
				$GLOBALS['_parent_pages']     = [];
				Admin::hall_menu();
				$hook = get_plugin_page_hookname( Admin::PAGE, 'edit.php?post_type=product' );
				$this->same( 10, has_action( 'load-' . $hook, [ Admin::class, 'load_page' ] ), 'Products → Starter Products loads its script from its own load hook' );

				Admin::load_page();
				$this->same( [ true, true ], [ wp_script_is( Admin::SCRIPT, 'enqueued' ), wp_style_is( Admin::SCRIPT, 'enqueued' ) ], 'script and style load for a Hall Manager' );
				$inline = implode( "\n", array_filter( (array) wp_scripts()->get_data( Admin::SCRIPT, 'before' ), 'is_string' ) );
				$config = 1 === preg_match( '/window\.bcStarterImport = (\{.*\});/s', $inline, $match ) ? json_decode( $match[1], true ) : [];
				$this->same( [ admin_url( 'admin-ajax.php' ), Admin::AJAX_ACTION, 1 ], [ $config['ajaxUrl'] ?? '', $config['action'] ?? '', wp_verify_nonce( (string) ( $config['nonce'] ?? '' ), 'bingocommerce_starter_import:hall:' . get_current_blog_id() ) ], 'settings: this Hall\'s admin-ajax address, the action and a valid nonce' );
				ob_start();
				wp_styles()->do_item( Admin::SCRIPT );
				$this->same( true, str_contains( (string) ob_get_clean(), Admin::HIDDEN_CSS ), 'the page rule keeps hidden buttons hidden' );
				wp_dequeue_script( Admin::SCRIPT );
				wp_deregister_script( Admin::SCRIPT );
				wp_dequeue_style( Admin::SCRIPT );
				wp_deregister_style( Admin::SCRIPT );
			}
		);

		$operator = $this->user( 'op', $hall, 'bc_game_operator' );
		$this->in_hall(
			$hall,
			$operator,
			function () {
				set_current_screen( 'dashboard' );
				$this->same( false, Admin::allowed(), 'a Game Operator has no Starter Products page' );
				Admin::load_page();
				$this->same( false, wp_script_is( Admin::SCRIPT, 'enqueued' ), 'and no script' );
			}
		);
		$this->in_site(
			$this->main,
			$this->admin,
			function () {
				set_current_screen( 'dashboard' );
				$this->same( false, Admin::allowed(), 'no Starter Products page on the main site, even for a Super Admin' );
			}
		);
	}

	private function unavailable_and_not_started(): void {
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr2', $hall, 'bc_hall_manager' );

		$this->same( [ 'unavailable', 'Starter products are not available yet.' ], $this->state( $hall, $manager ), 'nothing published: unavailable' );
		$this->version( [ $this->simple( 'Alpha' ), $this->simple( 'Beta' ) ] );
		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '0' );
		$this->same( [ 'unavailable', 'Starter product imports are turned off right now.' ], $this->state( $hall, $manager ), 'new imports switched off: unavailable' );
		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );
		$this->same( [ 'not_started', '2 starter products are available.' ], $this->state( $hall, $manager ), 'not started: the number available' );
		$buttons = $this->buttons( $this->render( $hall, $manager ) );
		$this->same( [ 'start' => [ false, true ], 'continue' => [ true, true ] ], $buttons, 'not started: only Import Starter Products shows; buttons start disabled' );
		$this->same( true, str_contains( $this->render( $hall, $manager ), '<noscript>' ), 'a note for browsers without JavaScript' );

		$this->in_site( $hall, $this->admin, static fn() => update_option( Repo::START_MARKER_OPTION, 'lost-receipt' ) );
		$this->same( [ 'unavailable', 'The starter import history is missing. Please contact Bingo Innovation support.' ], $this->state( $hall, $manager ), 'start marker without a receipt: repair, no import offered' );
		$this->in_site( $hall, $this->admin, static fn() => delete_option( Repo::START_MARKER_OPTION ) );

		$this->suspend( $hall );
		try {
			$this->same( [ 'unavailable', 'This Hall is not open right now, so importing is paused.' ], $this->state( $hall, $manager ), 'a Hall whose Commerce access is blocked (as when suspended): importing is paused' );
			$this->same( 'starter_import_unavailable', $this->code( $this->call( $hall, $this->admin, [ 'step' => 'start' ] ) ), 'paused for Super Admins too' );
		} finally {
			$this->unsuspend( $hall );
		}
		$this->same( 'not_started', $this->state( $hall, $manager )[0], 'available again after re-enable' );
	}

	private function import_through_handler(): void {
		global $wpdb;

		$this->version( [ $this->simple( 'Gamma', 'GAMMA-' . $this->token ), $this->simple( 'Delta' ) ] );
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr3', $hall, 'bc_hall_manager' );
		$this->in_site(
			$hall,
			$this->admin,
			function () {
				$existing = new WC_Product_Simple();
				$existing->set_name( 'Hall own product' );
				$existing->set_sku( 'GAMMA-' . $this->token );
				$existing->save();
			}
		);

		$started = $this->call( $hall, $manager, [ 'step' => 'start' ] );
		$this->same( [ 'status', 'text' ], is_array( $started ) ? array_keys( $started ) : [ $this->code( $started ) ], 'only status and text are returned' );
		$this->same( [ 'importing', 'Importing product 1 of 2.' ], is_array( $started ) ? [ $started['status'], $started['text'] ] : [], 'Import Starter Products: importing, with progress' );
		$result = null;
		for ( $i = 0; $i < 20; $i++ ) {
			$result = $this->call( $hall, $manager, [ 'step' => 'continue' ] );
			if ( ! is_array( $result ) || 'importing' !== $result['status'] ) {
				break;
			}
		}
		$today = $this->in_site( $hall, $manager, static fn() => get_date_from_gmt( current_time( 'mysql', true ), 'm/d/Y' ) );
		$this->same( [ 'complete', "Import finished on {$today}: 2 products imported, 0 skipped." ], is_array( $result ) ? [ $result['status'], $result['text'] ] : [ $this->code( $result ) ], 'Continue until finished (MM/DD/YYYY date)' );

		$state = $this->in_hall( $hall, $manager, static fn() => Admin::page_state() );
		$this->same( [ [ 'label' => 'Gamma', 'note' => 'SKU GAMMA-' . $this->token . ' is already used in this store, so this product was added without a SKU.' ] ], array_map( static fn( array $n ): array => [ 'label' => $n['label'], 'note' => $n['note'] ], $state['notes'] ), 'complete: the notes name the product (SKU conflict)' );
		$html = $this->render( $hall, $manager );
		$this->same( [ true, true, [ 'start' => [ true, true ], 'continue' => [ true, true ] ] ], [ str_contains( $html, 'View Products' ), str_contains( $html, '<strong>Gamma:</strong>' ), $this->buttons( $html ) ], 'complete: notes, View Products, no import buttons' );
		$this->same( 'complete', $this->call( $hall, $manager, [ 'step' => 'start' ] )['status'] ?? '', 'a second Import on a finished Hall changes nothing' );
		$this->same( [ true, 'complete' ], $this->locked_error( $hall, $manager, 'start' ), 'an error on a finished Hall reports "complete", so the page hides every import button' );

		$product = (int) $this->in_site( $hall, $manager, fn() => $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_title = %s", $wpdb->posts, 'Gamma' ) ) );
		$this->same( '', $this->edit_screen( $hall, $manager, $product ), 'a finished imported product: no warning' );
	}

	private function importing_and_needs_attention(): void {
		global $wpdb;

		$this->version( [ $this->variable( 'Dauber', [ 'Blue', 'Red', 'Green' ] ), $this->simple( 'Epsilon' ) ] );
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr4', $hall, 'bc_hall_manager' );
		$this->in_hall( $hall, $manager, static fn() => ( new StarterImporter() )->start() );
		$text = '';
		for ( $i = 0; $i < 12 && ! str_contains( $text, 'variation 2 of 3' ); $i++ ) {
			$this->in_hall( $hall, $manager, static fn() => ( new StarterImporter() )->process_next( 0.0 ) );
			$text = $this->state( $hall, $manager )[1];
		}
		$this->same( [ 'importing', 'Importing product 1 of 2 (variation 2 of 3).' ], $this->state( $hall, $manager ), 'importing: the current product and variation' );
		$html = $this->render( $hall, $manager );
		$this->same( [ true, true, [ 'start' => [ true, true ], 'continue' => [ false, true ] ] ], [ str_contains( $html, 'So far: 0 imported, 0 skipped.' ), str_contains( $html, 'may be replaced if you edit them before the import finishes' ), $this->buttons( $html ) ], 'importing: counts, the warning and Continue Import' );
		$this->same( true, str_contains( $html, '>Continue Import</button>' ), 'the button reads Continue Import' );

		$parent = (int) $this->in_site( $hall, $manager, fn() => $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_title = %s", $wpdb->posts, 'Dauber' ) ) );
		$this->same( 'This product is still being imported. Changes may be replaced.', $this->edit_screen( $hall, $manager, $parent ), 'an unfinished imported product: the edit screen warns' );
		$own = (int) $this->in_site( $hall, $this->admin, static fn() => ( new WC_Product_Simple() )->save() );
		$this->same( '', $this->edit_screen( $hall, $manager, $own ), 'a product the import did not make: no warning' );

		// Needs attention: a request dies after creating a product, before saving its ID.
		$this->version( [ $this->simple( 'Zeta' ) ] );
		$stuck = $this->create_hall();
		$this->in_hall( $stuck, $this->admin, static fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $stuck, 'insert' ), 'a request crashed right after creating a product' );
		$this->in_hall( $stuck, $this->admin, static fn() => ( new StarterImporter() )->process_next( 600.0 ) );
		$receipt = $this->in_site( $stuck, $this->admin, static fn() => Repo::receipt() );
		$this->same( [ 'needs_attention', sprintf( 'The import needs attention from Bingo Innovation support. Reference: SI-%d-%d-%d.', $stuck, (int) $receipt['import_id'], (int) $receipt['last_error_item_id'] ) ], $this->state( $stuck, $this->admin ), 'needs attention: a support reference' );
		$this->same( [ 'start' => [ true, true ], 'continue' => [ true, true ] ], $this->buttons( $this->render( $stuck, $this->admin ) ), 'needs attention: no Continue (Resolve comes first)' );
		$this->same( [ true, 'needs_attention' ], $this->locked_error( $stuck, $this->admin, 'continue' ), 'an error on an import that needs attention reports it, so the page hides every import button' );
	}

	private function failed(): void {
		$this->version( [ $this->simple( 'Broken image', '', 999999 ) ] );
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr5', $hall, 'bc_hall_manager' );

		$started = $this->call( $hall, $manager, [ 'step' => 'start' ] );
		$this->same( 'importing', $started['status'] ?? $this->code( $started ), 'Import starts' );
		$error = $this->call( $hall, $manager, [ 'step' => 'continue' ] );
		$data  = is_wp_error( $error ) ? $error->get_error_data() : [];
		$this->same( [ 'starter_import_image_missing', 409, 'failed' ], [ $this->code( $error ), $data['status'] ?? 0, $data['import_status'] ?? null ], 'a failed step reports the receipt status, so the page offers Retry' );
		$this->same( [ 'failed', 'Part of the starter catalog could not be found. Please contact Bingo Innovation support. Affected product: Broken image.' ], $this->state( $hall, $manager ), 'after a reload: a plain message for the saved code, and the affected product' );
		$html = $this->render( $hall, $manager );
		$this->same( [ [ 'start' => [ true, true ], 'continue' => [ false, true ] ], true ], [ $this->buttons( $html ), str_contains( $html, '>Retry Remaining Products</button>' ) ], 'failed: Retry Remaining Products' );
		$this->same( 'The import stopped. Please try again.', Admin::failure_message( 'starter_import_something_new' ), 'an unknown code still gets a plain message' );

		$second = $this->call( $hall, $manager, [ 'step' => 'start' ] );
		$this->same( [ 'failed', 'Part of the starter catalog could not be found. Please contact Bingo Innovation support. Affected product: Broken image.' ], is_array( $second ) ? [ $second['status'], $second['text'] ] : [ $this->code( $second ) ], 'Start from a second tab after the failure: the saved failure message and affected product (the page then offers Retry)' );
	}

	private function request_checks(): void {
		$this->version( [ $this->simple( 'Eta' ) ] );
		$hall     = $this->create_hall();
		$manager  = $this->user( 'mgr6', $hall, 'bc_hall_manager' );
		$operator = $this->user( 'op2', $hall, 'bc_game_operator' );

		$cases = [
			'GET'                         => [ $manager, $hall, [ 'step' => 'start' ], 'GET', 'starter_import_method', 405 ],
			'not signed in'               => [ 0, $hall, [ 'step' => 'start' ], 'POST', 'starter_import_denied', 403 ],
			'a Game Operator'             => [ $operator, $hall, [ 'step' => 'start' ], 'POST', 'starter_import_denied', 403 ],
			'the main site'               => [ $this->admin, $this->main, [ 'step' => 'start' ], 'POST', 'starter_import_site', 403 ],
			'a bad nonce'                 => [ $manager, $hall, [ 'step' => 'start', '_ajax_nonce' => 'bad' ], 'POST', 'starter_import_nonce', 403 ],
			'another Hall\'s nonce'       => [ $manager, $hall, [ 'step' => 'start', '_ajax_nonce' => 'other-hall' ], 'POST', 'starter_import_nonce', 403 ],
			'an unknown field'            => [ $manager, $hall, [ 'step' => 'start', 'blog_id' => '2' ], 'POST', 'starter_import_request', 400 ],
			'an unknown step'             => [ $manager, $hall, [ 'step' => 'publish' ], 'POST', 'starter_import_request', 400 ],
			'no step'                     => [ $manager, $hall, [], 'POST', 'starter_import_request', 400 ],
		];
		foreach ( $cases as $label => [ $user, $site, $fields, $method, $code, $status ] ) {
			$result = $this->call( $site, $user, $fields, $method );
			$data   = is_wp_error( $result ) ? $result->get_error_data() : [];
			$this->same( [ $code, $status ], [ $this->code( $result ), (int) ( $data['status'] ?? 0 ) ], "{$label}: refused" );
		}
		$this->same( null, $this->in_site( $hall, $this->admin, static fn() => StarterSchema::is_hall_current() ? Repo::receipt() : null ), 'no refused request started an import' );
	}

	private function json_wrapper(): void {
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr7', $hall, 'bc_hall_manager' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static fn() => static function () {
			throw new RuntimeException( 'ajax-done' );
		};
		add_filter( 'wp_die_ajax_handler', $die );
		try {
			$ok  = $this->ajax_output( $hall, $manager, [ 'step' => 'start' ] );
			$bad = $this->ajax_output( $hall, $manager, [ 'step' => 'start', '_ajax_nonce' => 'bad' ] );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$this->same( [ true, 'importing' ], [ $ok['success'] ?? null, $ok['data']['status'] ?? null ], 'the JSON response reports success with the status and text' );
		$this->same( [ false, 'starter_import_nonce', 'Verification failed. Reload this page and try again.', null ], [ $bad['success'] ?? null, $bad['data']['code'] ?? null, $bad['data']['message'] ?? null, array_key_exists( 'import_status', (array) ( $bad['data'] ?? [] ) ) ? $bad['data']['import_status'] : 'missing' ], 'and errors with a code, a plain message and the receipt status' );
	}

	/**
	 * Notes as the importer really saves them: one product with two warnings,
	 * a second product with the same category warning, a skipped variation
	 * (its note is saved on the variation and on its product), a variation
	 * SKU conflict, and a skipped product (its note copied to its variation).
	 */
	private function notes_display(): void {
		global $wpdb;
		$t        = $this->token;
		$category = 'Category "Shared cat" already exists in this store under a different parent; your existing category was kept.';
		$terms    = [ 'product_cat' => [ [ 'slug' => "parent-cat-$t", 'name' => 'Parent cat', 'parent' => '' ], [ 'slug' => "shared-cat-$t", 'name' => 'Shared cat', 'parent' => "parent-cat-$t" ] ], 'product_tag' => [] ];
		$dauber   = $this->variable( 'Notes dauber', [ 'Blue', 'Red', 'Green' ] );
		$dauber['variations'][1]['sku'] = "NV-$t";
		$this->version( [ $this->simple( 'Notes simple', "NS-$t", 0, [ "shared-cat-$t" ] ), $this->simple( 'Other simple', '', 0, [ "shared-cat-$t" ] ), $dauber ], $terms );

		$hall = $this->create_hall();
		$this->in_site(
			$hall,
			$this->admin,
			static function () use ( $t ) {
				foreach ( [ "NS-$t", "NV-$t" ] as $sku ) {
					$own = new WC_Product_Simple();
					$own->set_name( 'Hall own ' . $sku );
					$own->set_sku( $sku );
					$own->save();
				}
				wp_insert_term( 'Shared cat', 'product_cat', [ 'slug' => "shared-cat-$t" ] );
			}
		);
		$this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $hall, 'variation_id_saved' ), 'a request stopped right after the first variation (Blue) was created' );
		$this->in_site(
			$hall,
			$this->admin,
			static function () use ( $wpdb ) {
				$blue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_product_id FROM %i WHERE product_type = 'variation' ORDER BY item_id ASC LIMIT 1", StarterSchema::import_products_table() ) );
				wp_delete_post( $blue, true );
			}
		);
		$this->same( 'complete', $this->in_hall( $hall, $this->admin, static fn() => ( new StarterImporter() )->process_next( 600.0 ) )['status'] ?? '', 'the import finishes' );

		$skip_blue = 'Variation Blue was removed or moved in this store before the import finished, so it was skipped.';
		$saved     = $this->in_site( $hall, $this->admin, static fn() => $wpdb->get_col( $wpdb->prepare( 'SELECT note FROM %i WHERE note IS NOT NULL', StarterSchema::import_products_table() ) ) );
		$this->same( 2, count( array_filter( $saved, static fn( string $note ): bool => str_contains( $note, $skip_blue ) ) ), 'the importer saved the skipped variation\'s note twice (variation and product); saved notes are unchanged' );

		$notes = $this->in_hall( $hall, $this->admin, static fn() => Admin::page_state() )['notes'];
		$this->same(
			[
				[ 'label' => 'Notes simple', 'note' => "SKU NS-$t is already used in this store, so this product was added without a SKU." ],
				[ 'label' => 'Notes simple', 'note' => $category ],
				[ 'label' => 'Other simple', 'note' => $category ],
				[ 'label' => 'Notes dauber', 'note' => $skip_blue ],
				[ 'label' => 'Notes dauber, variation Red', 'note' => "SKU NV-$t is already used in this store, so this product was added without a SKU." ],
			],
			$notes,
			'one bullet per line; the repeated variation note once; the variation SKU warning kept; an unrelated product keeps its own identical warning'
		);
		$html = $this->render( $hall, $this->admin );
		$this->same( [ 5, true ], [ substr_count( $html, '<li><strong>' ), str_contains( $html, '<li><strong>Notes simple:</strong> Category &quot;Shared cat&quot; already exists' ) ], 'five readable bullets (the saved order kept), each label and note escaped' );

		// A product skipped after its first variation was created: its note is
		// copied to that variation.
		$this->version( [ $this->variable( 'Skipped dauber', [ 'One', 'Two', 'Three' ] ) ] );
		$second = $this->create_hall();
		$this->in_hall( $second, $this->admin, static fn() => ( new StarterImporter() )->start() );
		$this->same( 9, $this->worker( $second, 'variation_id_saved' ), 'a request stopped right after the first variation was created (second Hall)' );
		$this->in_site(
			$second,
			$this->admin,
			static function () use ( $wpdb ) {
				wp_trash_post( (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_product_id FROM %i WHERE product_type = 'variable'", StarterSchema::import_products_table() ) ) );
			}
		);
		$this->in_hall( $second, $this->admin, static fn() => ( new StarterImporter() )->process_next( 600.0 ) );
		$removed = 'Removed in this store before the import finished, so it was skipped.';
		$saved   = $this->in_site( $second, $this->admin, static fn() => $wpdb->get_col( $wpdb->prepare( 'SELECT note FROM %i WHERE note = %s', StarterSchema::import_products_table(), $removed ) ) );
		$this->same( 2, count( $saved ), 'the importer copied the skipped product\'s note to its variation' );
		$this->same( [ [ 'label' => 'Skipped dauber', 'note' => $removed ] ], $this->in_hall( $second, $this->admin, static fn() => Admin::page_state() )['notes'], 'the copied note appears once' );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** @return array{0:string,1:string} The page state's kind and main text. */
	private function state( int $hall, int $user ): array {
		$state = $this->in_hall( $hall, $user, static fn() => Admin::page_state() );

		return [ $state['kind'], $state['text'] ];
	}

	/**
	 * An error while the Hall's import lock is held, as when another tab is
	 * running: [is an error, the receipt status it reports].
	 */
	private function locked_error( int $hall, int $user, string $step ): array {
		$lock = \BingoCommerce\Catalog\StarterImportLock::acquire_hall( $hall );
		try {
			$result = $this->call( $hall, $user, [ 'step' => $step ] );
		} finally {
			if ( $lock instanceof \BingoCommerce\Catalog\StarterImportLock ) {
				$lock->release();
			}
		}
		$data = is_wp_error( $result ) ? $result->get_error_data() : [];

		return [ is_wp_error( $result ), $data['import_status'] ?? null ];
	}

	/** @return array<string,mixed>|WP_Error */
	private function call( int $site, int $user, array $fields, string $method = 'POST' ) {
		return $this->in_site(
			$site,
			$user,
			static function () use ( $fields, $method ) {
				$_SERVER['REQUEST_METHOD'] = $method;
				try {
					$posted = $fields + [ 'action' => Admin::AJAX_ACTION ];
					if ( ! array_key_exists( '_ajax_nonce', $fields ) ) {
						$posted['_ajax_nonce'] = wp_create_nonce( 'bingocommerce_starter_import:hall:' . get_current_blog_id() );
					} elseif ( 'other-hall' === $fields['_ajax_nonce'] ) {
						$posted['_ajax_nonce'] = wp_create_nonce( 'bingocommerce_starter_import:hall:' . ( get_current_blog_id() + 1 ) );
					}

					return Admin::handle_ajax( $posted );
				} finally {
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
			}
		);
	}

	private function ajax_output( int $hall, int $user, array $fields ): array {
		return (array) $this->in_site(
			$hall,
			$user,
			static function () use ( $fields ) {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_POST                     = wp_slash( $fields + [ 'action' => Admin::AJAX_ACTION, '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_import:hall:' . get_current_blog_id() ) ] );
				ob_start();
				try {
					Admin::ajax();
				} catch ( RuntimeException $done ) {
					unset( $done );
				} finally {
					$output                    = (string) ob_get_clean();
					$_POST                     = [];
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}

				return (array) json_decode( $output, true );
			}
		);
	}

	private function render( int $hall, int $user ): string {
		return (string) $this->in_hall(
			$hall,
			$user,
			static function () {
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

	private function edit_screen( int $hall, int $user, int $post_id ): string {
		return trim(
			wp_strip_all_tags(
				(string) $this->in_site(
					$hall,
					$user,
					static function () use ( $post_id ) {
						set_current_screen( 'product' );
						$_GET['post'] = (string) $post_id;
						ob_start();
						try {
							Admin::edit_screen_warning();
							return (string) ob_get_contents();
						} finally {
							ob_end_clean();
							unset( $_GET['post'] );
						}
					}
				)
			)
		);
	}

	/** @return array<string,array{0:bool,1:bool}> Buttons: [hidden, disabled]. */
	private function buttons( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$out = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//*[@id="bc-starter-import"]//button[@data-bc-import]' ) as $button ) {
			$out[ $button->getAttribute( 'data-bc-import' ) ] = [ $button->hasAttribute( 'hidden' ), $button->hasAttribute( 'disabled' ) ];
		}

		return $out;
	}

	private function in_hall( int $hall, int $user, callable $work ) {
		return $this->in_site(
			$hall,
			$user,
			static function () use ( $work ) {
				set_current_screen( 'dashboard' );
				$_SERVER['REQUEST_METHOD'] = 'GET';

				return $work();
			}
		);
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

	/**
	 * Block the Hall's Commerce access the way the Portal does: a Hall marked
	 * as Portal-managed whose Commerce state cannot be read is blocked, through
	 * the same HallCommerceAccess::is_blocked() check a suspended Hall takes.
	 * (This fixture database has no Portal provisioning tables to hold a
	 * "suspended" row.)
	 */
	private function suspend( int $hall ): void {
		update_blog_option( $hall, HallCommerceAccess::MARKER_OPTION, wp_generate_uuid4() );
		if ( ! HallCommerceAccess::is_blocked( $hall ) ) {
			throw new RuntimeException( 'Blocked-Hall fixture failed.' );
		}
	}

	private function unsuspend( int $hall ): void {
		delete_blog_option( $hall, HallCommerceAccess::MARKER_OPTION );
	}

	private function worker( int $hall, string $step ): int {
		$env    = [
			'BC_TEST_DB'           => BC_Fixture::$database,
			'BC_WORKER_HALL'       => (string) $hall,
			'BC_WORKER_USER'       => (string) $this->admin,
			'BC_WORKER_ACTION'     => 'process',
			'BC_WORKER_CRASH_STEP' => $step,
			'BC_WORKER_BUDGET'     => '600',
			'BC_STARTER_DIR'       => BINGOCOMMERCE_STARTER_CATALOG_DIR,
			'PATH'                 => (string) getenv( 'PATH' ),
		];
		$pipes  = [];
		$handle = proc_open( [ PHP_BINARY, __DIR__ . '/starter-import-recovery-worker.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $handle ) ) {
			return -1;
		}
		$out = (string) stream_get_contents( $pipes[1] ) . ' ' . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $handle );
		if ( 9 !== $code ) {
			echo 'worker output: ' . trim( $out ) . "\n";
		}

		return $code;
	}

	/* ------------------------------------------------------------------ *
	 * Versions (published directly into the network tables)
	 * ------------------------------------------------------------------ */

	private function simple( string $name, string $sku = '', int $image = 0, array $categories = [] ): array {
		return [
			'type' => 'simple',
			'data' => [ 'name' => $name, 'description' => '', 'short_description' => '', 'slug' => sanitize_title( $name ) . '-' . $this->token, 'regular_price' => '5.00', 'sku' => $sku, 'categories' => $categories, 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'image_id' => $image, 'gallery_image_ids' => [], 'attributes' => [] ],
		];
	}

	private function variable( string $name, array $colors ): array {
		return [
			'type'       => 'variable',
			'data'       => [ 'name' => $name, 'description' => '', 'short_description' => '', 'slug' => sanitize_title( $name ) . '-' . $this->token, 'regular_price' => '', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'image_id' => 0, 'gallery_image_ids' => [], 'attributes' => [ [ 'name' => 'Color', 'options' => $colors, 'visible' => true, 'variation' => true, 'position' => 0 ] ], 'default_attributes' => [] ],
			'variations' => array_map( static fn( string $c ): array => [ 'attributes' => [ 'color' => $c ], 'regular_price' => '2.00', 'sku' => '', 'manage_stock' => false, 'enabled' => true, 'tax_class' => 'parent', 'description' => '', 'image_id' => 0 ], $colors ),
		];
	}

	private function version( array $products, array $terms = [ 'product_cat' => [], 'product_tag' => [] ] ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( StarterSchema::versions_table(), [ 'status' => 'published', 'product_count' => count( $products ), 'image_count' => 0, 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => wp_json_encode( $terms ), 'created_by' => $this->admin, 'published_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now ] );
		$version = (int) $wpdb->insert_id;
		foreach ( array_values( $products ) as $position => $product ) {
			$parent = ++$this->source;
			$wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => $parent, 'product_type' => $product['type'], 'position' => $position + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $product['data'] ), 'created_at' => $now, 'updated_at' => $now ] );
			foreach ( (array) ( $product['variations'] ?? [] ) as $index => $variation ) {
				$wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => ++$this->source, 'parent_source_id' => $parent, 'product_type' => 'variation', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( $variation ), 'created_at' => $now, 'updated_at' => $now ] );
			}
		}
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Version fixture failed.' );
		}

		return $version;
	}

	/* ------------------------------------------------------------------ *
	 * Fixture lifecycle
	 * ------------------------------------------------------------------ */

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-page-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter page disposable Hall', 'user_id' => $this->admin ] );
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
		$id = wp_insert_user( [ 'user_login' => 'sip-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
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
		foreach ( array_keys( $this->sites ) as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER_OPTION ) ) {
				throw new RuntimeException( 'Cleanup ownership guard refused.' );
			}
			$this->unsuspend( $site );
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
					$this->remove_tree( $folder );
				}
			}
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
	}

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

	private function remove_tree( string $directory ): void {
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
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

exit( ( new StarterImportPageTest() )->run( $bc_frozen_dir ) );
