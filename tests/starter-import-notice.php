<?php

declare(strict_types=1);

/**
 * ADR-049 stage 5, slice 5.2: the Hall dashboard notice.
 *
 * Run: BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-notice.php
 *
 * Proves:
 *   - the notice shows on a Hall's dashboard only, to Hall Managers and Super
 *     Admins, when products are available and nothing is imported, or when an
 *     import started but is not finished (also with new imports off); never
 *     when finished, nothing is published, imports are off before starting,
 *     the Hall is blocked, the import history needs repair, on other screens,
 *     on the main site or for a Game Operator;
 *   - rendering the dashboard never creates the import tables;
 *   - Dismiss is a POST with its own nonce and saves a per-user, per-Hall
 *     setting: another user and another Hall still see the notice; bad
 *     nonces, GET requests, a Game Operator and the main site are refused;
 *     the handler returns to the dashboard with a 303 redirect.
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

use BingoCommerce\Catalog\StarterCatalogVersions as Versions;
use BingoCommerce\Catalog\StarterImportAdmin;
use BingoCommerce\Catalog\StarterImporter;
use BingoCommerce\Catalog\StarterImportMigrations as StarterSchema;
use BingoCommerce\Catalog\StarterImportNotice as Notice;
use BingoCommerce\Catalog\StarterImportRepository as Repo;
use BingoCommerce\PortalIntegration\HallCommerceAccess;

final class StarterImportNoticeTest {
	private const OWNER_OPTION = 'bc_starter_notice_test_owner';
	private const USER_OWNER   = 'bc_starter_notice_test_user_owner';
	private const AVAILABLE    = 'Bingo Innovation starter products are available for your store. You can import them once, as draft products.';
	private const UNFINISHED   = 'Your Bingo Innovation starter product import isn\'t finished.';

	private string $token;
	private int $admin;
	private int $main;
	private array $sites = [];
	private array $users = [];
	private int $passed  = 0;
	private int $failed  = 0;

	public function run(): int {
		global $wpdb;

		$this->token = strtolower( wp_generate_password( 8, false, false ) );
		$this->main  = (int) get_main_site_id();
		$this->same( true, in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) && DB_NAME !== BC_Fixture::$live_db, 'isolated database, never development' );
		$this->same( BC_FIXTURE_IDENTITY, $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ), 'fixture sentinel ownership proven' );
		$this->admin = (int) username_exists( 'fixture_admin' );
		wp_set_current_user( $this->admin );
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( (string) $message ) ); } );

		$old_screen = $GLOBALS['current_screen'] ?? null;
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$imports    = get_site_option( Versions::ALLOW_IMPORTS_OPTION, null );

		try {
			$this->reset_network();
			StarterSchema::ensure_network();
			update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

			$this->registration();
			$this->not_started();
			$this->dismissal();
			$this->unfinished();
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
			null === $imports ? delete_site_option( Versions::ALLOW_IMPORTS_OPTION ) : update_site_option( Versions::ALLOW_IMPORTS_OPTION, $imports );
		}

		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";

		return $this->failed > 0 ? 1 : 0;
	}

	/* ------------------------------------------------------------------ */

	private function registration(): void {
		$this->same( 10, has_action( 'admin_notices', [ Notice::class, 'render' ] ), 'notice registered' );
		$this->same( [ 10, false ], [ has_action( 'admin_post_' . Notice::DISMISS_ACTION, [ Notice::class, 'dismiss' ] ), has_action( 'admin_post_nopriv_' . Notice::DISMISS_ACTION ) ], 'Dismiss for signed-in users only' );
	}

	private function not_started(): void {
		global $wpdb;

		$hall     = $this->create_hall();
		$manager  = $this->user( 'mgr', $hall, 'bc_hall_manager' );
		$operator = $this->user( 'op', $hall, 'bc_game_operator' );

		$this->same( '', $this->notice( $hall, $manager ), 'nothing published: no notice' );
		$this->version();
		$html = $this->notice( $hall, $manager );
		$this->same( true, str_contains( $html, self::AVAILABLE ), 'products available and nothing imported: the notice shows' );
		$this->same( true, str_contains( $html, 'href="' . esc_url( $this->in_site( $hall, $manager, static fn() => admin_url( 'edit.php?post_type=product&page=' . StarterImportAdmin::PAGE ) ) ) . '"' ), 'it links to Products → Starter Products' );
		$this->same( [ 'POST', true ], $this->dismiss_form( $hall, $manager, $html ), 'Dismiss is a POST form with a valid nonce for this Hall' );
		$this->same( false, (bool) $this->in_site( $hall, $manager, static fn() => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', StarterSchema::import_table() ) ) ), 'showing the notice did not create the import tables' );

		$this->same( '', $this->notice( $hall, $manager, 'edit-product' ), 'not on other screens' );
		$this->same( '', $this->notice( $hall, $operator ), 'not for a Game Operator' );
		$this->same( true, str_contains( $this->notice( $hall, $this->admin ), self::AVAILABLE ), 'a Super Admin sees it on the Hall' );
		$this->same( '', $this->notice( $this->main, $this->admin ), 'never on the main site' );

		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '0' );
		$this->same( '', $this->notice( $hall, $manager ), 'new imports switched off and nothing started: no notice' );
		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

		update_blog_option( $hall, HallCommerceAccess::MARKER_OPTION, wp_generate_uuid4() );
		try {
			$this->same( [ true, '' ], [ HallCommerceAccess::is_blocked( $hall ), $this->notice( $hall, $manager ) ], 'a Hall whose Commerce access is blocked (as when suspended): no notice' );
		} finally {
			delete_blog_option( $hall, HallCommerceAccess::MARKER_OPTION );
		}

		$this->in_site( $hall, $this->admin, static fn() => update_option( Repo::START_MARKER_OPTION, 'lost-receipt' ) );
		$this->same( '', $this->notice( $hall, $manager ), 'import history needs repair: no "available" notice' );
		$this->in_site( $hall, $this->admin, static fn() => delete_option( Repo::START_MARKER_OPTION ) );
		$this->same( true, str_contains( $this->notice( $hall, $manager ), self::AVAILABLE ), 'back again once repaired' );
	}

	private function dismissal(): void {
		$hall     = $this->create_hall();
		$other    = $this->create_hall();
		$first    = $this->user( 'first', $hall, 'bc_hall_manager' );
		$second   = $this->user( 'second', $hall, 'bc_hall_manager' );
		$operator = $this->user( 'op2', $hall, 'bc_game_operator' );
		$this->grant( $first, $other, 'bc_hall_manager' );

		$this->same( 'starter_import_notice_nonce', $this->code( $this->dismiss( $hall, $second, 'bad' ) ), 'a bad nonce is refused' );
		$this->same( 'starter_import_notice_nonce', $this->code( $this->dismiss( $hall, $second, 'other-hall' ) ), 'another Hall\'s nonce is refused' );
		$this->same( 'starter_import_notice_denied', $this->code( $this->dismiss( $hall, $second, null, 'GET' ) ), 'GET is refused' );
		$this->same( 'starter_import_notice_denied', $this->code( $this->dismiss( $hall, $operator ) ), 'a Game Operator is refused' );
		$this->same( 'starter_import_notice_denied', $this->code( $this->dismiss( $this->main, $this->admin ) ), 'the main site is refused' );
		$this->same( true, str_contains( $this->notice( $hall, $second ), self::AVAILABLE ), 'after refused attempts the notice still shows' );

		$this->same( true, $this->dismiss( $hall, $first ), 'the first Manager dismisses it' );
		$this->same( '', $this->notice( $hall, $first ), 'it no longer shows for them on this Hall' );
		$this->same( true, str_contains( $this->notice( $hall, $second ), self::AVAILABLE ), 'another Manager of the same Hall still sees it' );
		$this->same( true, str_contains( $this->notice( $other, $first ), self::AVAILABLE ), 'the same Manager still sees it on another Hall' );

		$redirect = $this->in_site(
			$other,
			$first,
			static function () {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_POST                     = wp_slash( [ 'action' => Notice::DISMISS_ACTION, '_wpnonce' => wp_create_nonce( 'bingocommerce_starter_import_notice:hall:' . get_current_blog_id() ) ] );
				$stop                      = static function ( $location, $status ) {
					throw new RuntimeException( 'redirect ' . $status . ' ' . $location );
				};
				add_filter( 'wp_redirect', $stop, 10, 2 );
				try {
					Notice::dismiss();
					return 'no redirect';
				} catch ( RuntimeException $redirect ) {
					return $redirect->getMessage();
				} finally {
					remove_filter( 'wp_redirect', $stop, 10 );
					$_POST                     = [];
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
			}
		);
		$this->same( 'redirect 303 ' . $this->in_site( $other, $first, static fn() => admin_url() ), $redirect, 'the Dismiss handler returns to the dashboard with a 303 redirect' );
		$this->same( '', $this->notice( $other, $first ), 'and it is dismissed there too' );
	}

	private function unfinished(): void {
		$hall    = $this->create_hall();
		$manager = $this->user( 'mgr3', $hall, 'bc_hall_manager' );
		$this->in_site( $hall, $manager, static fn() => ( new StarterImporter() )->start() );
		$this->in_site( $hall, $manager, static fn() => ( new StarterImporter() )->process_next( 0.0 ) );
		$receipt = $this->in_site( $hall, $manager, static fn() => Repo::receipt() );
		$this->same( 'importing', $receipt['status'] ?? '', 'an import is under way' );
		$this->same( true, str_contains( $this->notice( $hall, $manager ), esc_html( self::UNFINISHED ) ), 'an unfinished import: the notice says so' );

		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '0' );
		$this->same( true, str_contains( $this->notice( $hall, $manager ), esc_html( self::UNFINISHED ) ), 'it still shows with new imports switched off (a started import can continue)' );
		update_site_option( Versions::ALLOW_IMPORTS_OPTION, '1' );

		foreach ( [ Repo::RECEIPT_FAILED => true, Repo::RECEIPT_NEEDS_ATTENTION => true, Repo::RECEIPT_COMPLETE => false ] as $status => $shown ) {
			$this->in_site( $hall, $manager, static fn() => Repo::update_receipt( (int) $receipt['import_id'], [ 'status' => $status ] ) );
			$this->same( $shown, str_contains( $this->notice( $hall, $manager ), esc_html( self::UNFINISHED ) ), $shown ? "{$status}: the notice shows" : 'a finished import: no notice' );
		}
		$this->same( '', $this->notice( $hall, $manager ), 'and no "available" notice either' );

		$this->in_site( $hall, $manager, static fn() => Repo::update_receipt( (int) $receipt['import_id'], [ 'status' => Repo::RECEIPT_FAILED ] ) );
		$this->same( true, $this->dismiss( $hall, $manager ), 'dismissed while the import is unfinished' );
		$this->same( '', $this->notice( $hall, $manager ), 'a dismissal also hides the unfinished reminder' );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function notice( int $site, int $user, string $screen = 'dashboard' ): string {
		return trim(
			(string) $this->in_site(
				$site,
				$user,
				static function () use ( $screen ) {
					set_current_screen( $screen );
					ob_start();
					try {
						Notice::render();
						return (string) ob_get_contents();
					} finally {
						ob_end_clean();
					}
				}
			)
		);
	}

	/** @return array{0:string,1:bool} The form's method and whether its nonce is valid here. */
	private function dismiss_form( int $site, int $user, string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$form   = $dom->getElementsByTagName( 'form' )->item( 0 );
		$fields = [];
		foreach ( $form ? $form->getElementsByTagName( 'input' ) : [] as $input ) {
			$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}
		$valid = (bool) $this->in_site( $site, $user, static fn() => wp_verify_nonce( (string) ( $fields['_wpnonce'] ?? '' ), 'bingocommerce_starter_import_notice:hall:' . get_current_blog_id() ) );

		return [ strtoupper( $form ? $form->getAttribute( 'method' ) : '' ), $valid && Notice::DISMISS_ACTION === ( $fields['action'] ?? '' ) ];
	}

	/** @return true|WP_Error */
	private function dismiss( int $site, int $user, ?string $nonce = null, string $method = 'POST' ) {
		return $this->in_site(
			$site,
			$user,
			static function () use ( $nonce, $method ) {
				$_SERVER['REQUEST_METHOD'] = $method;
				try {
					$value = match ( $nonce ) {
						null         => wp_create_nonce( 'bingocommerce_starter_import_notice:hall:' . get_current_blog_id() ),
						'other-hall' => wp_create_nonce( 'bingocommerce_starter_import_notice:hall:' . ( get_current_blog_id() + 1 ) ),
						default      => $nonce,
					};

					return Notice::handle_dismiss( [ 'action' => Notice::DISMISS_ACTION, '_wpnonce' => $value ] );
				} finally {
					$_SERVER['REQUEST_METHOD'] = 'GET';
				}
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

	private function version(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( StarterSchema::versions_table(), [ 'status' => 'published', 'product_count' => 2, 'image_count' => 0, 'currency' => 'USD', 'prices_include_tax' => 'no', 'terms' => '{"product_cat":[],"product_tag":[]}', 'created_by' => $this->admin, 'published_by' => $this->admin, 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now ] );
		$version = (int) $wpdb->insert_id;
		foreach ( [ 'Notice one', 'Notice two' ] as $index => $name ) {
			$wpdb->insert( StarterSchema::version_products_table(), [ 'version_id' => $version, 'source_product_id' => 8100 + $index, 'product_type' => 'simple', 'position' => $index + 1, 'capture_status' => 'captured', 'data' => wp_json_encode( [ 'name' => $name, 'description' => '', 'short_description' => '', 'slug' => sanitize_title( $name ) . '-' . $this->token, 'regular_price' => '5.00', 'sku' => '', 'categories' => [], 'tags' => [], 'tax_status' => 'taxable', 'tax_class' => '', 'manage_stock' => false, 'image_id' => 0, 'gallery_image_ids' => [], 'attributes' => [] ] ), 'created_at' => $now, 'updated_at' => $now ] );
		}
	}

	private function create_hall(): int {
		$site = wp_insert_site( [ 'domain' => 'starter-notice-' . strtolower( wp_generate_password( 10, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Starter notice disposable Hall', 'user_id' => $this->admin ] );
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
		$id = wp_insert_user( [ 'user_login' => 'sin-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Actor creation failed.' );
		}
		$this->users[] = (int) $id;
		update_user_meta( $id, self::USER_OWNER, $this->token );
		$this->grant( (int) $id, $site, $role );

		return (int) $id;
	}

	private function grant( int $user, int $site, string $role ): void {
		global $wpdb;
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key ] );
		$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] );
		clean_user_cache( $user );
	}

	private function cleanup(): void {
		global $wpdb;
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
		}
		foreach ( $this->users as $user ) {
			if ( $this->token === get_user_meta( $user, self::USER_OWNER, true ) ) {
				wpmu_delete_user( $user );
			}
		}
		// fixture_admin is kept: remove any dismissal a failed or mutated run saved for it.
		foreach ( array_merge( [ $this->main ], array_keys( $this->sites ) ) as $site ) {
			delete_user_meta( $this->admin, $wpdb->get_blog_prefix( $site ) . Notice::DISMISSED_OPTION );
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

exit( ( new StarterImportNoticeTest() )->run() );
