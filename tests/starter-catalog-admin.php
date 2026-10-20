<?php

declare(strict_types=1);

/**
 * ADR-049 stage 4, slice 4.3: the Publish, Resume and Discard controls on
 * Network Admin → Starter Catalog and their admin-ajax handler.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-admin.php
 *
 * Proves:
 *   - the handler and the page's script are registered, and the script with
 *     its nonce and admin-ajax address loads on this page only, for Super
 *     Admins only;
 *   - every request is re-checked: POST only, signed-in Super Admin with
 *     manage_network_options, the main site, a valid nonce, known fields, a
 *     known step and a well-formed version for Discard;
 *   - Publish, Resume and Discard run through the handler (blocked, Building,
 *     Published, "already being published", idle) and return only the
 *     expected fields; the JSON wrapper reports success and errors;
 *   - the page shows the right buttons for each state (idle, Building,
 *     Building without a saved selection, a Discarded version still to clean),
 *     all disabled until the script enables them.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" );
	exit( 1 );
}

$bc_frozen_dir = rtrim( sys_get_temp_dir(), '/' ) . '/bc-starter-admin-' . bin2hex( random_bytes( 6 ) );
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

use BingoCommerce\Catalog\StarterCatalogAdmin as Admin;
use BingoCommerce\Catalog\StarterCatalogMaster as Master;
use BingoCommerce\Catalog\StarterCatalogPublisher as Publisher;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;

final class StarterCatalogAdminTest {
	private const OWNER_META   = 'bc_starter_admin_test_owner';
	private const OWNER_OPTION = 'bc_starter_admin_test_owner';
	private const USER_OWNER   = 'bc_starter_admin_test_user_owner';
	private const FIELDS       = [ 'status', 'version_id', 'total', 'captured', 'problems', 'warnings' ];

	private string $token;
	private int $admin;
	private int $main;
	private int $manager = 0;
	private array $products = [];
	private array $sites    = [];
	private array $users    = [];
	private int $passed     = 0;
	private int $failed     = 0;

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
		$before     = $this->count_main();

		try {
			$this->same( 0, $before, 'fixture main site starts with no products' );
			$this->reset_network();
			$this->manager = $this->user( 'mgr', $this->main, 'shop_manager' );

			$this->registration();
			$this->script_loading();
			$this->request_checks();
			$this->flows();
			$this->json_wrapper();
			$this->page_states();
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
			$this->remove_tree( $frozen_dir );
		}

		$this->same( $before, $this->count_main(), 'main site left with no test products' );
		$this->same( false, is_dir( $frozen_dir ), 'temporary folder removed' );

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ */

	private function registration(): void {
		$this->same( 10, has_action( 'wp_ajax_' . Admin::AJAX_ACTION, [ Admin::class, 'ajax' ] ), 'admin-ajax handler registered for signed-in users' );
		$this->same( false, has_action( 'wp_ajax_nopriv_' . Admin::AJAX_ACTION ), 'never for visitors who are not signed in' );

		$this->as_network(
			$this->admin,
			function () {
				$GLOBALS['menu']             = [];
				$GLOBALS['admin_page_hooks'] = [];
				$GLOBALS['_registered_pages'] = [];
				$GLOBALS['_parent_pages']     = [];
				Admin::network_menu();
				$this->same( 10, has_action( 'load-toplevel_page_' . Admin::PAGE, [ Admin::class, 'load_page' ] ), 'the script loads from this page\'s own load hook' );
			}
		);
	}

	private function script_loading(): void {
		$this->as_network(
			$this->manager,
			function () {
				Admin::load_page();
				$this->same( [ false, false ], [ wp_script_is( Admin::SCRIPT, 'enqueued' ), wp_style_is( Admin::SCRIPT, 'enqueued' ) ], 'script and style not loaded for a user who is not a Super Admin' );
			}
		);
		$this->as_network(
			$this->admin,
			function () {
				Admin::load_page();
				$this->same( true, wp_script_is( Admin::SCRIPT, 'enqueued' ), 'loaded on the page for a Super Admin' );
				$this->same( true, str_ends_with( (string) wp_scripts()->registered[ Admin::SCRIPT ]->src, 'assets/starter-catalog-admin.js' ), 'the publishing script' );
				$inline = implode( "\n", array_filter( (array) wp_scripts()->get_data( Admin::SCRIPT, 'before' ), 'is_string' ) );
				$config = 1 === preg_match( '/window\.bcStarterCatalog = (\{.*\});/s', $inline, $match ) ? json_decode( $match[1], true ) : [];
				$this->same( [ get_admin_url( $this->main, 'admin-ajax.php' ), Admin::AJAX_ACTION ], [ $config['ajaxUrl'] ?? '', $config['action'] ?? '' ], 'settings: the main site\'s admin-ajax address and the action' );
				$this->same( 1, wp_verify_nonce( (string) ( $config['nonce'] ?? '' ), 'bingocommerce_starter_catalog_publish:network:' . get_current_network_id() ), 'settings: a valid publish nonce' );
				$this->same( true, wp_style_is( Admin::SCRIPT, 'enqueued' ), 'the page\'s own style is loaded with it' );
				ob_start();
				wp_styles()->do_item( Admin::SCRIPT );
				$printed = (string) ob_get_clean();
				$this->same( true, str_contains( $printed, '#bc-starter-publish [hidden] { display: none !important; }' ), 'it prints the rule that keeps hidden buttons hidden despite WordPress\'s button display rule' );
				wp_dequeue_script( Admin::SCRIPT );
				wp_deregister_script( Admin::SCRIPT );
				wp_dequeue_style( Admin::SCRIPT );
				wp_deregister_style( Admin::SCRIPT );
			}
		);
	}

	/* ------------------------------------------------------------------ */

	private function request_checks(): void {
		$this->only_include( [ $this->product( 'Request check' ) ] );

		$this->same( [ 'starter_catalog_method', 405 ], $this->refused( $this->admin, $this->main, [ 'step' => 'start' ], 'GET' ), 'GET is refused' );
		$this->same( [ 'starter_catalog_denied', 403 ], $this->refused( 0, $this->main, [ 'step' => 'start' ] ), 'not signed in: refused' );
		$this->same( [ 'starter_catalog_denied', 403 ], $this->refused( $this->manager, $this->main, [ 'step' => 'start' ] ), 'a main-site shop manager: refused' );
		$hall = $this->create_hall();
		$this->same( [ 'starter_catalog_site', 403 ], $this->refused( $this->admin, $hall, [ 'step' => 'start' ] ), 'a Super Admin on a Hall site: refused (main site only)' );
		$this->same( [ 'starter_catalog_nonce', 403 ], $this->refused( $this->admin, $this->main, [ 'step' => 'start', '_ajax_nonce' => 'bad' ] ), 'a bad nonce: refused' );
		$this->same( [ 'starter_catalog_nonce', 403 ], $this->refused( $this->admin, $this->main, [ 'step' => 'start', '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_catalog:network:' . get_current_network_id() ) ] ), 'the imports-switch nonce does not work here' );

		$cases = [
			'an unknown field'             => [ 'step' => 'start', 'blog_id' => '2' ],
			'an unknown step'              => [ 'step' => 'publish' ],
			'no step'                      => [],
			'Discard without a version'    => [ 'step' => 'discard' ],
			'Discard with a word'          => [ 'step' => 'discard', 'version' => 'abc' ],
			'Discard with version 0'       => [ 'step' => 'discard', 'version' => '0' ],
			'Discard with a negative'      => [ 'step' => 'discard', 'version' => '-1' ],
			'Discard with an array'        => [ 'step' => 'discard', 'version' => [ '1' ] ],
			'Start with a version'         => [ 'step' => 'start', 'version' => '1' ],
		];
		foreach ( $cases as $label => $fields ) {
			$this->same( [ 'starter_catalog_request', 400 ], $this->refused( $this->admin, $this->main, $fields ), "{$label}: refused" );
		}
		$this->same( 0, $this->version_count(), 'no refused request created a version' );
	}

	private function flows(): void {
		$published = $this->product( 'Published master', 'publish' );
		$this->only_include( [ $published ] );
		$blocked = $this->call( [ 'step' => 'start' ] );
		$this->same( self::FIELDS, is_array( $blocked ) ? array_keys( $blocked ) : [ $this->code( $blocked ) ], 'only the expected fields are returned' );
		$this->same( [ 'blocked', null, [ '"Starter admin test Published master" is Published; it must be Draft, Pending or Private.' ] ], is_array( $blocked ) ? [ $blocked['status'], $blocked['version_id'], $blocked['problems'] ] : [], 'Publish with a Published product: blocked, naming it, no version' );

		$first  = $this->product( 'First' );
		$second = $this->product( 'Second' );
		$this->only_include( [ $first, $second ] );
		$started = $this->call( [ 'step' => 'start' ] );
		$this->same( [ 'building', 2, 0 ], is_array( $started ) ? [ $started['status'], $started['total'], $started['captured'] ] : [ $this->code( $started ) ], 'Publish New Version: Building, two products' );
		$this->same( [ 'starter_catalog_build_exists' ], [ $this->code( $this->call( [ 'step' => 'start' ] ) ) ], 'a second Publish while one is building is refused' );

		$result = null;
		for ( $i = 0; $i < 10; $i++ ) {
			$result = $this->call( [ 'step' => 'continue' ] );
			if ( ! is_array( $result ) || 'building' !== $result['status'] ) {
				break;
			}
		}
		$this->same( [ 'published', 2, 2 ], is_array( $result ) ? [ $result['status'], $result['total'], $result['captured'] ] : [ $this->code( $result ) ], 'Resume until Published' );
		$this->same( 'idle', $this->call( [ 'step' => 'continue' ] )['status'] ?? '', 'Resume with nothing building: idle' );

		$building = $this->call( [ 'step' => 'start' ] );
		$version  = is_array( $building ) ? (int) $building['version_id'] : 0;
		$this->same( [ 'discarded', $version ], array_values( array_intersect_key( (array) $this->call( [ 'step' => 'discard', 'version' => (string) $version ] ), array_flip( [ 'status', 'version_id' ] ) ) ), 'Discard removes the Building version' );
		$this->same( 'starter_catalog_not_discardable', $this->code( $this->call( [ 'step' => 'discard', 'version' => (string) ( $version - 1 ) ] ) ), 'a published version cannot be discarded through the page' );
	}

	private function json_wrapper(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static fn() => static function () {
			throw new RuntimeException( 'ajax-done' );
		};
		add_filter( 'wp_die_ajax_handler', $die );
		try {
			$ok  = $this->ajax_output( [ 'step' => 'continue' ] );
			$bad = $this->ajax_output( [ 'step' => 'continue', '_ajax_nonce' => 'bad' ] );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$this->same( [ true, 'idle' ], [ $ok['success'] ?? null, $ok['data']['status'] ?? null ], 'the JSON response reports success with the outcome' );
		$this->same( [ false, 'starter_catalog_nonce', 'Verification failed. Reload this page and try again.' ], [ $bad['success'] ?? null, $bad['data']['code'] ?? null, $bad['data']['message'] ?? null ], 'and errors with a code and a plain message' );
	}

	/* ------------------------------------------------------------------ */

	private function page_states(): void {
		global $wpdb;

		$idle = $this->buttons( $this->render() );
		$this->same(
			[ 'start' => [ false, true ], 'continue' => [ true, true ], 'discard' => [ true, true, '0' ] ],
			[ 'start' => $idle['start'] ?? null, 'continue' => $idle['continue'] ?? null, 'discard' => $idle['discard'] ?? null ],
			'idle: only Publish New Version shows; every button starts disabled'
		);
		$html = $this->render();
		$this->same( [ true, true, true ], [ str_contains( $html, '<noscript>' ), str_contains( $html, 'role="status"' ), str_contains( $html, 'Publish makes a new numbered version' ) ], 'idle: a no-JavaScript note, a live status area and the explanation' );

		$this->only_include( [ $this->product( 'Third' ), $this->product( 'Fourth' ) ] );
		$version = (int) ( ( new Publisher() )->start()['version_id'] ?? 0 );
		$html    = $this->render();
		$state   = $this->buttons( $html );
		$this->same( [ [ true, true ], [ false, true ], [ false, true, (string) $version ] ], [ $state['start'] ?? null, $state['continue'] ?? null, $state['discard'] ?? null ], 'Building: Resume and Discard (for that version) show' );
		$this->same( true, str_contains( $html, "Version {$version} is being published: 0 of 2 products captured." ), 'Building: progress is shown' );
		( new Publisher() )->discard( $version );

		$now = current_time( 'mysql', true );
		$wpdb->insert( StarterSchema::versions_table(), [ 'status' => 'building', 'currency' => 'USD', 'prices_include_tax' => 'no', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] );
		$empty = (int) $wpdb->insert_id;
		$html  = $this->render();
		$state = $this->buttons( $html );
		$this->same( [ [ true, true ], [ true, true ], [ false, true, (string) $empty ] ], [ $state['start'] ?? null, $state['continue'] ?? null, $state['discard'] ?? null ], 'Building without a saved selection: Discard only' );
		$this->same( true, str_contains( $html, "Version {$empty} was started, but its product selection was not saved." ), 'and it says why' );
		$this->call( [ 'step' => 'discard', 'version' => (string) $empty ] );

		$wpdb->insert( StarterSchema::versions_table(), [ 'status' => 'discarded', 'currency' => 'USD', 'prices_include_tax' => 'no', 'created_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now ] );
		$leftover = (int) $wpdb->insert_id;
		$wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $leftover, 'source_product_id' => 1, 'product_type' => 'simple', 'position' => 1, 'capture_status' => 'pending', 'created_at' => $now, 'updated_at' => $now ] );
		$html = $this->render();
		$this->same( [ true, true, true ], [ str_contains( $html, "Version {$leftover} was discarded, but some of its rows or image files remain." ), (bool) preg_match( '#data-bc-publish="discard" data-bc-version="' . $leftover . '" disabled>Finish Discard#', $html ), str_contains( $html, '<p data-bc-cleanup="' . $leftover . '">' ) ], 'a Discarded version still to clean: Finish Discard for it, in a row marked with its version' );
		$this->same( 'discarded', $this->call( [ 'step' => 'discard', 'version' => (string) $leftover ] )['status'] ?? '', 'Finish Discard through the handler' );
		$this->same( false, str_contains( $this->render(), 'Finish Discard' ), 'then nothing is left to clean' );
		$this->same( 'Turn on new Hall imports', $this->first_form_button( $this->render() ), 'the imports switch is still the page\'s only form' );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Run the handler as a user on a site; returns [code, HTTP status] of the refusal. */
	private function refused( int $user, int $site, array $fields, string $method = 'POST' ): array {
		$result = $this->call( $fields, $user, $site, $method );
		$data   = is_wp_error( $result ) ? $result->get_error_data() : null;

		return [ is_wp_error( $result ) ? $result->get_error_code() : 'accepted', is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0 ];
	}

	/** @return array<string,mixed>|WP_Error */
	private function call( array $fields, ?int $user = null, ?int $site = null, string $method = 'POST' ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user ?? $this->admin );
		switch_to_blog( $site ?? $this->main );
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			$posted = $fields + [ 'action' => Admin::AJAX_ACTION ];
			if ( ! array_key_exists( '_ajax_nonce', $fields ) ) {
				$posted['_ajax_nonce'] = wp_create_nonce( 'bingocommerce_starter_catalog_publish:network:' . get_current_network_id() );
			}

			return Admin::handle_ajax( $posted );
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function ajax_output( array $fields ): array {
		switch_to_blog( $this->main );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = wp_slash( $fields + [ 'action' => Admin::AJAX_ACTION, '_ajax_nonce' => wp_create_nonce( 'bingocommerce_starter_catalog_publish:network:' . get_current_network_id() ) ] );
		ob_start();
		try {
			Admin::ajax();
		} catch ( RuntimeException $done ) {
			unset( $done );
		} finally {
			$output                    = (string) ob_get_clean();
			$_POST                     = [];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			restore_current_blog();
		}

		return (array) json_decode( $output, true );
	}

	private function render(): string {
		return (string) $this->as_network(
			$this->admin,
			function () {
				$_SERVER['REQUEST_METHOD'] = 'GET';
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

	/** @return array<string,array<int,mixed>> Main buttons: [hidden, disabled(, version)]. */
	private function buttons( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$out = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//*[@id="bc-starter-publish"]//button[@data-bc-publish]' ) as $button ) {
			$step = $button->getAttribute( 'data-bc-publish' );
			if ( 'discard' === $step && ! $button->hasAttribute( 'data-bc-confirm' ) ) {
				continue;
			}
			$out[ $step ] = [ $button->hasAttribute( 'hidden' ), $button->hasAttribute( 'disabled' ) ];
			if ( 'discard' === $step ) {
				$out[ $step ][] = $button->getAttribute( 'data-bc-version' );
			}
		}

		return $out;
	}

	private function first_form_button( string $html ): string {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		foreach ( $dom->getElementsByTagName( 'form' )->item( 0 )?->getElementsByTagName( 'input' ) ?? [] as $input ) {
			if ( 'submit' === $input->getAttribute( 'type' ) ) {
				return $input->getAttribute( 'value' );
			}
		}

		return '';
	}

	private function as_network( int $user, callable $work ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user );
		switch_to_blog( $this->main );
		set_current_screen( 'dashboard-network' );
		try {
			return $work();
		} finally {
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function product( string $name, string $status = 'draft' ): int {
		$previous = get_current_user_id();
		wp_set_current_user( $this->admin );
		switch_to_blog( $this->main );
		try {
			$product = new WC_Product_Simple();
			$product->set_name( 'Starter admin test ' . $name );
			$product->set_status( $status );
			$product->set_regular_price( '5.00' );
			$id = (int) $product->save();
			update_post_meta( $id, self::OWNER_META, $this->token );
			$this->products[ $id ] = true;

			return $id;
		} finally {
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function only_include( array $ids ): void {
		$previous = get_current_user_id();
		wp_set_current_user( $this->admin );
		switch_to_blog( $this->main );
		try {
			foreach ( array_keys( $this->products ) as $id ) {
				in_array( $id, $ids, true ) ? update_post_meta( $id, Master::META_KEY, Master::INCLUDED ) : delete_post_meta( $id, Master::META_KEY );
			}
		} finally {
			restore_current_blog();
			wp_set_current_user( $previous );
		}
	}

	private function version_count(): int {
		global $wpdb;
		StarterSchema::ensure_network();

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', StarterSchema::versions_table() ) );
	}

	private function count_main(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type IN ( 'product', 'product_variation' )", $wpdb->get_blog_prefix( $this->main ) . 'posts' ) );
	}

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-admin-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter admin disposable Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) {
			throw new RuntimeException( 'Disposable Hall creation failed.' );
		}
		$this->sites[ $site ] = true;
		update_blog_option( $site, self::OWNER_OPTION, $this->token );

		return (int) $site;
	}

	private function user( string $label, int $site, string $role ): int {
		global $wpdb;
		$id = wp_insert_user( [ 'user_login' => 'sca-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
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

		switch_to_blog( $this->main );
		try {
			$owned = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, self::OWNER_META, $this->token ) );
			foreach ( array_unique( array_merge( array_keys( $this->products ), array_map( 'intval', $owned ) ) ) as $id ) {
				wp_delete_post( (int) $id, true );
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
					$this->remove_tree( $folder );
				}
			}
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
		$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'action' => 'starter_catalog.version_published' ] );
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

exit( ( new StarterCatalogAdminTest() )->run( $bc_frozen_dir ) );
