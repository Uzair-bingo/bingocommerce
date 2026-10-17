<?php

/**
 * Homepage purchase step: verified dated Session -> one Package -> optional
 * add-ons -> the existing WooCommerce cart (ADR-045 / BR-084 addition 2026-10-02).
 * BC_TEST_DB=bingocommerce_state_recon php tests/homepage-session-purchase.php
 *
 *  1. The anonymous add-on listing (CustomerCartCatalog::addons() through the
 *     Sessions provider): eligibility by the existing addon() predicate,
 *     relationship prices on in-memory copies (an add-on product without a
 *     standalone price, another with its own sale price), ok/empty/partial/
 *     unavailable classification, each Package's "What's included" (its saved
 *     base composition, never its add-ons) and the current Hall's purchase
 *     settings.
 *  2. Rendering: the two-column layout (one radio group with each Package's
 *     contents; per-Package add-on card grids with search), no direct
 *     WooCommerce purchase shortcut, nothing usable or focusable without the
 *     script, and informational cards when purchase settings are unusable.
 *  3. The real Store API cart-extension route in-process with JSON bodies: every
 *     reachable outcome code (selection, Package limit, add-on relationship,
 *     electronic limit, native WooCommerce add failure, stale Session, nonce
 *     rejections, extension-route error), no partial lines, merchandise kept.
 *  4. Add-on price presentation: Store API cart prices without a false sale,
 *     genuine Package and merchandise sales, coupons (including "exclude sale
 *     items"), taxes and a cart restored from the session.
 *  5. Hall isolation with colliding local IDs, IDs that exist only in another
 *     Hall, a relationship of another local Package and foreign cart metadata.
 *  6. The ordinary Shop (real php-cgi requests: Shop page, product category and
 *     product search) lists merchandise but no Bingo Package or Play Product,
 *     by each Hall's own Catalog, including a product ID that is merchandise in
 *     one Hall and a Play Product in the other; direct-purchase guards kept.
 *  7. Cart Session labels (ADR-045 / BR-084 addition 2026-10-02): the one
 *     native "Bingo Session" item-data entry on a verified Package line —
 *     generated, legacy, long, multibyte, maximum-length and unsafe names, the
 *     display-only " — HH:MM" removal, translations; every malformed field,
 *     missing row, read failure and identity mismatch reaching the neutral
 *     entry; verification failures adding nothing; unexpected shapes; the
 *     classic cart, checkout review and mini-cart templates and the Store API
 *     (other filters' entries and variation details kept, one entry per
 *     Package line, identical output without the callback apart from that
 *     entry); Session A in the cart while Session B is viewed; colliding IDs
 *     and forged foreign-Hall metadata; guest and signed-in customers; cart
 *     state, checkout validation (also with the neutral entry) and Remove/Undo
 *     unchanged; saved order labels byte-identical and in their existing format.
 *
 * Optional: BC_SESSION_PURCHASE_BROWSER=1 with BC_NODE_EXECUTABLE,
 * BC_PLAYWRIGHT_MODULE, BC_CHROME_EXECUTABLE and BC_SESSION_PURCHASE_ARTIFACTS
 * (an existing /private/tmp directory) runs
 * tests/homepage-session-purchase-browser.cjs against real php-cgi requests that
 * boot these Halls directly (tests/homepage-session-purchase-request.php):
 * homepage, cart page (Hall A: the Cart block page WooCommerce's installer
 * creates; Hall B: the classic [woocommerce_cart] shortcode), Shop page, Store
 * API cart read and cart-extension POST, merchandise seeding and WooCommerce
 * cart fragments, with guest cookies carried between requests. For the cart
 * labels it also opens Hall A's Checkout block page and a Mini-Cart block page,
 * Hall B's classic checkout page (its review refresh included) and a disposable
 * customer's signed-in session. No checkout is submitted.
 *
 * Disposable token-owned Halls and actors only; persistent fixture Halls 2/3/4
 * and the main site's WooCommerce session table are compared before and after,
 * and the network electronic-limit setting is restored.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$_SERVER['SERVER_NAME'] = BC_FIXTURE_DOMAIN;
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use BingoCommerce\BingoThemeV1\SessionCalendar;
use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CustomerCartCatalog;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations as CatalogMigrations;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Catalog\ShopListing;
use BingoCommerce\Sessions\CartSelection;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\HomepageSessionCalendar;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\SessionResult;
use BingoCommerce\Sessions\SessionScheduleResult;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;

final class HomepageSessionPurchaseTest {
	private const THEME = 'bingocommerce-bingothemev1';
	private const OWNER = 'bc_session_purchase_test_owner';
	private const NY    = 'America/New_York';

	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;
	private string $group = '';
	/** @var callable */
	private $audit;
	private array $notes = [];

	public function run(): void {
		global $wpdb;
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$this->audit = static fn( array $event ): int => 1;
		$missing     = new stdClass();
		$limit       = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		$baseline    = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}
		$main_sessions = $this->main_sessions();
		try {
			update_site_option( ElectronicLimit::PLATFORM_OPTION, 48 );
			$a = $this->fixture( 'a' );
			$b = $this->fixture( 'b' );
			$this->listing( $a, $b );
			$this->rendering( $a );
			$this->store_api( $a, $b );
			$this->pricing( $a );
			$this->isolation( $a, $b );
			$this->shop( $a, $b );
			$labels = $this->label_fixtures( $a, $b );
			$this->labels( $a, $b, $labels );
			$this->browser( $a, $b, $labels );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
			if ( $limit === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $limit ); }
			$this->cleanup();
		}
		$this->group( 'Preservation' );
		wp_cache_delete( get_current_network_id() . ':' . ElectronicLimit::PLATFORM_OPTION, 'site-options' );
		wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
		$this->same( $limit, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'network electronic-limit setting restored' );
		$this->same( $main_sessions, $this->main_sessions(), 'main site WooCommerce session table unchanged (row contents compared, ' . ( null === $main_sessions ? 'table absent' : $main_sessions['rows'] . ' rows' ) . ')' );
		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" );
		}
		foreach ( $this->notes as $note ) {
			echo 'NOTE ' . $note . "\n";
		}
		printf( "Homepage Session purchase: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	/* ================================================================== *
	 * Fixture: two Halls built in the same order, so local IDs collide
	 * ================================================================== */

	/** @return array<string,mixed> */
	private function fixture( string $label ): array {
		global $wpdb;
		$site = $this->hall( $label );
		$b    = 'b' === $label;
		switch_to_blog( $site );
		wp_set_current_user( $this->admin );
		try {
			$game  = ( new GameTypeService( $this->audit ) )->create( 'Regular' );
			$game  = (int) $game->game_type['game_type_id'];
			$image = static fn( string $file ): int => self::image( $file );
			$plays = [
				'paper_base'    => $this->play( 'Paper sheets', 'paper', 1, null ),
				'digital_base'  => $this->play( 'Digital cards', 'digital', 6, $game ),
				// An add-on product with NO standalone price: only its relationships price it.
				'shared'        => $this->play( $b ? 'Hall B paper pack' : 'Extra paper pack', 'paper', 1, null, [ 'price' => '', 'image' => $image( 'paper.png' ) ] ),
				// Its own sale price must never decorate a relationship price.
				'chips'         => $this->play( 'Magnetic chips', 'paper', 1, null, [ 'price' => '5.00', 'sale' => '4.00', 'image' => $image( 'chips.png' ) ] ),
				'digital_extra' => $this->play( 'Extra digital cards', 'digital', 6, $game, [ 'image' => $image( 'tote.png' ) ] ),
				// No product image: its card shows the placeholder tile.
				'stock'         => $this->play( 'Dauber', 'paper', 1, null, [ 'stock' => 1 ] ),
				'broken'        => $this->play( 'Unchecked add-on', 'paper', 1, null ),
				// Base component only; its product is trashed below, so that Package's contents can't be verified.
				'classic_base'  => $this->play( 'Classic sheets', 'paper', 1, null ),
			];
			$packages = [
				'paper'       => $this->package( $b ? 'Hall B Night Pack' : 'Paper Night Pack', [ [ $plays['paper_base'], 1 ] ], [ 'price' => '10.00', 'image' => $image( 'paper.png' ) ] ),
				// A genuine sale on the Package's own product, which must still read as a sale.
				'digital'     => $this->package( 'Digital Night Pack', [ [ $plays['digital_base'], 6 ] ], [ 'price' => '20.00', 'sale' => '18.00', 'image' => $image( 'tote.png' ) ] ),
				'partial'     => $this->package( 'Hybrid Mix Pack with paper sheets and digital cards for the whole evening', [ [ $plays['paper_base'], 1 ], [ $plays['digital_base'], 1 ] ], [ 'price' => '15.00' ] ),
				'unavailable' => $this->package( 'Paper Classic Pack', [ [ $plays['classic_base'], 2 ] ], [ 'price' => '12.00' ] ),
				'none'        => $this->package( 'Paper Basic Pack', [ [ $plays['paper_base'], 1 ] ], [ 'price' => '8.00' ] ),
			];
			// Owned-fixture state the Catalog preserves: a base component whose linked product is in the trash.
			if ( ! wp_trash_post( $this->wc_product( $plays['classic_base'] ) ) ) { throw new RuntimeException( 'Trashed component fixture failed.' ); }
			$service   = new PackageAddonService( $this->audit );
			$relations = [
				// One underlying Play Product, two relationships with their own price and maximum.
				'paper_shared'   => $this->addon( $service, $packages['paper'], $plays['shared'], $b ? '9.50' : '1.50', $b ? 4 : 2 ),
				'paper_chips'    => $this->addon( $service, $packages['paper'], $plays['chips'], '3.00', 1 ),
				'digital_shared' => $this->addon( $service, $packages['digital'], $plays['shared'], '2.25', 5 ),
				'digital_extra'  => $this->addon( $service, $packages['digital'], $plays['digital_extra'], '4.00', 3 ),
				'digital_stock'  => $this->addon( $service, $packages['digital'], $plays['stock'], '1.00', 3 ),
				'partial_ok'     => $this->addon( $service, $packages['partial'], $plays['shared'], '0.75', 2 ),
				'partial_bad'    => $this->addon( $service, $packages['partial'], $plays['broken'], '1.00', 1 ),
				'unavailable'    => $this->addon( $service, $packages['unavailable'], $plays['broken'], '1.00', 1 ),
				'disabled'       => $this->addon( $service, $packages['paper'], $plays['stock'], '1.00', 1 ),
			];
			$this->disable( $service, $packages['paper'], $relations['disabled'] );
			// Owned-fixture corruption only: an unreadable relationship (production writers prevent it).
			foreach ( [ 'partial_bad', 'unavailable' ] as $key ) {
				$wpdb->update( CatalogMigrations::package_addons_table(), [ 'max_quantity' => 0 ], [ 'package_addon_id' => $relations[ $key ] ] );
			}
			$merch   = $this->product( 'Bingo dauber, blue', [ 'price' => '2.00', 'image' => $image( 'dauber.png' ) ] );
			$session = $this->session( 'Friday Night Bingo', 2, array_values( $packages ) );
			// One product category holding merchandise, a Package and a Play Product, for the Shop's category archive.
			$category = wp_insert_term( 'Bingo supplies', 'product_cat', [ 'slug' => 'bingo-supplies' ] );
			if ( is_wp_error( $category ) ) { throw new RuntimeException( 'Category fixture failed.' ); }
			foreach ( [ $merch, $this->package_product( $packages['paper'] ), $this->wc_product( $plays['chips'] ) ] as $product_id ) {
				wp_set_object_terms( $product_id, [ (int) $category['term_id'] ], 'product_cat' );
			}
			if ( $b ) {
				// Classic cart shortcode, for classic coverage.
				$cart = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cart', 'post_content' => '[woocommerce_cart]' ] );
				update_option( 'woocommerce_cart_page_id', (int) $cart );
			} else {
				// The pages WC_Install::install() creates when HallSiteProvisioner provisions a Hall.
				WC_Install::create_pages();
				$cart = wc_get_page_id( 'cart' );
			}
			$fixture = compact( 'site', 'game', 'plays', 'packages', 'relations', 'merch', 'session' ) + [
				'label' => $label, 'host' => get_site( $site )->domain, 'cart_page' => (int) $cart, 'cart_url' => wc_get_cart_url(),
				// The CLI process registered taxonomies for the main site, so the Hall's pretty category URL is built from WooCommerce's own setting.
				'category' => (int) $category['term_id'], 'category_url' => home_url( '/' . trim( (string) wc_get_permalink_structure()['category_rewrite_slug'], '/' ) . '/bingo-supplies/' ), 'shop_url' => (string) get_post_type_archive_link( 'product' ),
				'cart_mode' => has_block( 'woocommerce/cart', (int) $cart ) ? 'block' : ( has_shortcode( (string) get_post_field( 'post_content', (int) $cart ), 'woocommerce_cart' ) ? 'shortcode' : 'unknown' ),
				'date'  => self::day( self::NY, 2 ),
			];
			if ( $b ) {
				// Created after the shared construction, so these IDs exist only in Hall B.
				$fixture['only_relation'] = $this->addon( $service, $packages['paper'], $this->play( 'Hall B bonus', 'paper', 1, null ), '6.00', 1 );
				$fixture['only_session']  = $this->session( 'Saturday Bingo', 3, [ $packages['paper'] ] );
				$fixture['only_relation_unique'] = ! in_array( $fixture['only_relation'], array_values( $relations ), true );
			}
			return $fixture;
		} finally {
			wp_set_current_user( 0 );
			restore_current_blog();
		}
	}

	private function hall( string $label ): int {
		wp_set_current_user( $this->admin );
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'purchase-' . $label . '-' . strtolower( wp_generate_password( 8, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Purchase Hall ' . strtoupper( $label ), 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = (int) $site;
		update_blog_option( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			WC_Install::create_tables();
			WC_Install::create_roles();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				if ( class_exists( $schema ) ) { ( new $schema() )->register_tables( true ); }
			}
			\BingoCommerce\Core\Roles::sync_current_site();
			update_option( 'timezone_string', self::NY );
			// Owned disposable Hall: WooCommerce's own currency formatting, guest checkout.
			foreach ( [ 'woocommerce_currency' => 'USD', 'woocommerce_currency_pos' => 'left', 'woocommerce_price_thousand_sep' => ',', 'woocommerce_price_decimal_sep' => '.', 'woocommerce_price_num_decimals' => '2', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_manage_stock' => 'yes' ] as $option => $value ) {
				update_option( $option, $value );
			}
			switch_theme( self::THEME );
			if ( ! Migrations::is_current() || ! CatalogMigrations::is_current() ) { throw new RuntimeException( 'Disposable Hall schemas are not current.' ); }
		} finally {
			restore_current_blog();
		}
		return (int) $site;
	}

	private function own( int $site ): void {
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, self::OWNER ) ) {
			throw new RuntimeException( 'Fixture Hall ownership refused.' );
		}
	}

	private static function image( string $file ): int {
		// Attachment row and metadata only: nothing is written to the shared uploads directory.
		$id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Purchase fixture image', 'post_status' => 'inherit' ], 'purchase-fixture/' . $file );
		if ( ! $id ) { throw new RuntimeException( 'Image fixture failed.' ); }
		update_post_meta( $id, '_wp_attached_file', 'purchase-fixture/' . $file );
		wp_update_attachment_metadata( $id, [ 'width' => 900, 'height' => 900, 'file' => 'purchase-fixture/' . $file, 'sizes' => [] ] );
		return (int) $id;
	}

	private function product( string $name, array $options = [] ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $options['price'] ?? '10.00' );
		if ( isset( $options['sale'] ) ) { $product->set_sale_price( $options['sale'] ); }
		if ( isset( $options['image'] ) ) { $product->set_image_id( $options['image'] ); }
		if ( isset( $options['stock'] ) ) { $product->set_manage_stock( true ); $product->set_stock_quantity( $options['stock'] ); }
		$product->set_virtual( true );
		$id = (int) $product->save();
		if ( $id < 1 ) { throw new RuntimeException( 'Product fixture failed.' ); }
		return $id;
	}

	private function play( string $name, string $medium, int $faces, ?int $game, array $options = [] ): int {
		$result = ( new BingoPlayProductService( $this->audit ) )->create( $this->product( $name, $options ), $medium, $faces, $game );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Play Product fixture failed: ' . $result->get_error_code() ); }
		return (int) $result->play_product['bingo_play_product_id'];
	}

	/** @param array<int,array{0:int,1:int}> $components */
	private function package( string $name, array $components, array $options ): int {
		$result = ( new PackageService( $this->audit ) )->create(
			$this->product( $name, $options ),
			array_map( static fn( array $c ): array => [ 'bingo_play_product_id' => $c[0], 'quantity' => $c[1] ], $components )
		);
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Package fixture failed: ' . $result->get_error_code() ); }
		return (int) $result->package['package_id'];
	}

	private function addon( PackageAddonService $service, int $package, int $play, string $price, int $max ): int {
		$result = $service->create( $package, $play, $price, $max );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Add-on fixture failed: ' . $result->get_error_code() ); }
		return (int) $result->package_addon['package_addon_id'];
	}

	private function disable( PackageAddonService $service, int $package, int $relation ): void {
		foreach ( (array) $service->listForPackage( $package ) as $row ) {
			if ( $relation === (int) $row['package_addon_id'] ) {
				$result = $service->update( $relation, (string) $row['fingerprint'], (string) $row['unit_price'], (int) $row['max_quantity'], false );
				if ( ! is_wp_error( $result ) ) { return; }
			}
		}
		throw new RuntimeException( 'Disabled add-on fixture failed.' );
	}

	/** One generated, Sales-ON dated Session through the real schedule and sales services. */
	private function session( string $name, int $days, array $packages, string $time = '18:00' ): int {
		global $wpdb;
		static $next = 0;
		$template = 981000 + ( ++$next );
		$now      = current_time( 'mysql', true );
		$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => $template, 'name' => $name, 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
		$schedules = new SessionScheduleService( $this->audit );
		$schedule  = $schedules->create( $template, 'dates', $time, [ self::day( self::NY, $days ) ], $packages );
		if ( ! $schedule instanceof SessionScheduleResult || SessionScheduleResult::STATUS_OK !== $schedule->status ) { throw new RuntimeException( 'Schedule fixture failed.' ); }
		$schedules->generate( (int) $schedule->schedule['schedule_id'] );
		$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
		if ( ! ( new SessionService( $this->audit ) )->setAvailableForSale( $session, true ) instanceof SessionResult ) { throw new RuntimeException( 'Fixture sales ON failed.' ); }
		return $session;
	}

	private static function day( string $zone, int $days ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->setTime( 12, 0 )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/** One legacy (unscheduled) Sales-ON Session through the real SessionService writers. */
	private function legacy( string $name, int $days, array $packages ): int {
		$service = new SessionService( $this->audit );
		$created = $service->create( self::day( self::NY, $days ), $name, true );
		if ( ! $created instanceof SessionResult ) { throw new RuntimeException( 'Legacy Session fixture failed.' ); }
		$session = (int) $created->session['session_id'];
		foreach ( $packages as $package ) {
			if ( ! $service->assignPackage( $session, $package ) instanceof SessionResult ) { throw new RuntimeException( 'Legacy Package assignment failed.' ); }
		}
		return $session;
	}

	/* ================================================================== *
	 * 1. Listing and pricing
	 * ================================================================== */

	private function listing( array $a, array $b ): void {
		$this->group( 'Cart page mode' );
		$this->same( [], $this->bingocommerce_callbacks( [ 'woocommerce_create_pages', 'woocommerce_cart_shortcode_tag' ] ), 'no BingoCommerce callback changes WooCommerce’s installer pages (only WooCommerce’s own)' );
		$this->same( 'block', $a['cart_mode'], 'Hall A: WooCommerce’s installer (as HallSiteProvisioner runs it) creates a Cart BLOCK page' );
		$this->same( 'shortcode', $b['cart_mode'], 'Hall B: classic [woocommerce_cart] page for classic coverage' );
		$this->group( 'Add-on listing and relationship pricing' );
		switch_to_blog( $a['site'] );
		try {
			$r     = $a['relations'];
			$model = $this->model( [ 'bc_session' => (string) $a['session'] ] );
			$items = array_column( $model['packages']['items'], null, 'id' );
			$this->same( 'ok', $model['packages']['status'], 'Packages listed' );
			$addons = static fn( int $package ) => $items[ $package ]['addons'];
			$ids    = static fn( int $package ) => array_column( $addons( $package )['items'], 'id' );
			$this->same( [ 'ok', [ $r['paper_shared'], $r['paper_chips'] ] ], [ $addons( $a['packages']['paper'] )['status'], $ids( $a['packages']['paper'] ) ], 'Paper Package: eligible relationships in Manager order; the disabled one is absent' );
			$this->same( [ 'ok', [ $r['digital_shared'], $r['digital_extra'], $r['digital_stock'] ] ], [ $addons( $a['packages']['digital'] )['status'], $ids( $a['packages']['digital'] ) ], 'Digital Package: Paper and Digital relationships' );
			$this->same( [ 'partial', [ $r['partial_ok'] ] ], [ $addons( $a['packages']['partial'] )['status'], $ids( $a['packages']['partial'] ) ], 'one unreadable relationship: partial, verified relationship kept' );
			$this->same( [ 'status' => 'unavailable', 'items' => [] ], $addons( $a['packages']['unavailable'] ), 'only unreadable relationships: unavailable, never an empty list' );
			$this->same( [ 'status' => 'ok', 'items' => [] ], $addons( $a['packages']['none'] ), 'no relationships: a successful empty list' );
			$shared_paper   = $addons( $a['packages']['paper'] )['items'][0];
			$shared_digital = $addons( $a['packages']['digital'] )['items'][0];
			$this->same( [ 'Extra paper pack', 2, '$1.50' ], [ $shared_paper['name'], $shared_paper['max_quantity'], $this->text( $shared_paper['price_html'] ) ], 'shared Play Product in the Paper Package: its relationship name, maximum and price' );
			$this->same( [ 'Extra paper pack', 5, '$2.25' ], [ $shared_digital['name'], $shared_digital['max_quantity'], $this->text( $shared_digital['price_html'] ) ], 'same Play Product in the Digital Package: another relationship, maximum and price' );
			$this->ok( $shared_paper['id'] !== $shared_digital['id'], 'two relationships of one Play Product have their own relationship IDs' );
			$this->same( [ 'id', 'name', 'image_id', 'max_quantity', 'price_html' ], array_keys( $shared_paper ), 'presentation fields only: no WooCommerce product, Play Product or raw price' );
			$shared_product = wc_get_product( $this->wc_product( $a['plays']['shared'] ) );
			$this->same( [ '', '' ], [ $shared_product->get_regular_price( 'edit' ), $shared_product->get_price( 'edit' ) ], 'the add-on product without a standalone price stays without one' );
			$chips = $addons( $a['packages']['paper'] )['items'][1];
			$this->same( [ '$3.00', 0 ], [ $this->text( $chips['price_html'] ), substr_count( $chips['price_html'], '<del' ) ], 'relationship price shown without the product’s own sale strikethrough' );
			$chips_product = wc_get_product( $this->wc_product( $a['plays']['chips'] ) );
			$this->same( [ '5.00', '4.00' ], [ $chips_product->get_regular_price( 'edit' ), $chips_product->get_sale_price( 'edit' ) ], 'stored product prices untouched by the listing' );
			$this->ok( $shared_paper['image_id'] > 0 && wp_attachment_is_image( $shared_paper['image_id'] ), 'add-on image from its product' );

			$catalog = CustomerCartCatalog::addons( $a['packages']['paper'] );
			$this->same( [ $r['paper_shared'], $r['paper_chips'] ], array_column( $catalog['items'], 'package_addon_id' ), 'Catalog listing composes the existing addon() predicate' );
			$this->same( CustomerCartCatalog::addon( $a['packages']['paper'], $r['paper_shared'] ), $catalog['items'][0], 'listed relationship identical to the single-relationship read' );
			$this->ok( is_wp_error( CustomerCartCatalog::addons( 0 ) ), 'invalid Package ID: unavailable' );

			$purchase = $model['purchase'];
			$this->same( [ 'endpoint', 'cart_endpoint', 'namespace', 'nonce', 'cart_url' ], array_keys( (array) $purchase ), 'purchase settings supplied for a Session with listed Packages' );
			foreach ( [ 'endpoint', 'cart_endpoint', 'cart_url' ] as $key ) {
				$this->same( $a['host'], wp_parse_url( $purchase[ $key ], PHP_URL_HOST ), "{$key} is on the current Hall" );
			}
			$this->ok( str_ends_with( $purchase['endpoint'], '/wc/store/v1/cart/extensions' ) && str_ends_with( $purchase['cart_endpoint'], '/wc/store/v1/cart' ), 'existing WooCommerce cart-extension route and cart read' );
			$this->same( 'bingocommerce-selection', $purchase['namespace'], 'CustomerCart’s existing namespace' );
			$this->same( 1, wp_verify_nonce( $purchase['nonce'], 'wc_store_api' ), 'Store API nonce valid for this signed-out viewer' );
			$this->same( null, $this->model( [ 'bc_date' => $a['date'] ] )['purchase'], 'no purchase settings without a selected Session' );

			// Theme validation: purchase settings and add-on states.
			$calendar = SessionCalendar::model( $model );
			$this->ok( is_array( SessionCalendar::purchase( $calendar ) ), 'theme accepts the current Hall’s purchase settings' );
			foreach ( [
				'another host'   => [ 'endpoint' => 'https://other.example/wp-json/wc/store/v1/cart/extensions' ],
				'missing nonce'  => [ 'nonce' => '' ],
				'spaced nonce'   => [ 'nonce' => 'ab cd' ],
				'bad namespace'  => [ 'namespace' => 'Bad Namespace' ],
				'non-URL'        => [ 'cart_url' => 'javascript:alert(1)' ],
			] as $label => $change ) {
				$broken = $calendar;
				$broken['purchase'] = array_replace( $broken['purchase'], $change );
				$this->same( null, SessionCalendar::purchase( $broken ), "theme refuses purchase settings: {$label}" );
			}
			$unsafe = $calendar;
			$unsafe['session']['id'] = PHP_INT_MAX;
			$this->same( null, SessionCalendar::purchase( $unsafe ), 'theme refuses an ID the browser cannot carry exactly' );
			$item = $items[ $a['packages']['paper'] ];
			$this->same( 'ok', SessionCalendar::addons( $item )['state'], 'theme: verified add-ons' );
			$this->same( 'empty', SessionCalendar::addons( $items[ $a['packages']['none'] ] )['state'], 'theme: successful empty list' );
			$this->same( 'partial', SessionCalendar::addons( $items[ $a['packages']['partial'] ] )['state'], 'theme: partial list' );
			foreach ( [
				'missing'          => static function ( array $i ): array { unset( $i['addons'] ); return $i; },
				'unavailable'      => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'unavailable', 'items' => [] ] ] ),
				'partial, empty'   => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'partial', 'items' => [] ] ] ),
				'duplicate ID'     => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'ok', 'items' => [ $i['addons']['items'][0], $i['addons']['items'][0] ] ] ] ),
				'maximum 100'      => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'ok', 'items' => [ array_replace( $i['addons']['items'][0], [ 'max_quantity' => 100 ] ) ] ] ] ),
				'string ID'        => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'ok', 'items' => [ array_replace( $i['addons']['items'][0], [ 'id' => '7' ] ) ] ] ] ),
				'unknown status'   => static fn( array $i ): array => array_replace( $i, [ 'addons' => [ 'status' => 'fine', 'items' => [] ] ] ),
			] as $label => $mutate ) {
				$this->same( [ 'state' => 'unavailable', 'items' => [] ], SessionCalendar::addons( $mutate( $item ) ), "theme: {$label} add-on data is unavailable, never “no add-ons”" );
			}

			$this->group( 'What’s included (saved base composition)' );
			$this->same( [ 'id', 'type', 'name', 'image_id', 'price_html', 'contents', 'addons' ], array_keys( $item ), 'Package presentation fields: contents beside the add-ons, no Catalog IDs' );
			$contents = static fn( int $package ): array => $items[ $package ]['contents'];
			$this->same( [ 'status' => 'ok', 'items' => [ [ 'name' => 'Paper sheets', 'quantity' => 1, 'medium' => 'paper' ] ] ], $contents( $a['packages']['paper'] ), 'Paper Package: its base component’s name, configured quantity and medium' );
			$this->same( [ 'status' => 'ok', 'items' => [ [ 'name' => 'Digital cards', 'quantity' => 6, 'medium' => 'digital' ] ] ], $contents( $a['packages']['digital'] ), 'Digital Package: the configured quantity (6 units), never its 36 digital faces' );
			$this->same( [ 'status' => 'ok', 'items' => [ [ 'name' => 'Paper sheets', 'quantity' => 1, 'medium' => 'paper' ], [ 'name' => 'Digital cards', 'quantity' => 1, 'medium' => 'digital' ] ] ], $contents( $a['packages']['partial'] ), 'Hybrid Package: every base component, in the Catalog’s own order' );
			$this->same( [ 'status' => 'unavailable', 'items' => [] ], $contents( $a['packages']['unavailable'] ), 'a base component whose product is in the trash: unavailable, never a shorter list' );
			$this->same( 'unavailable', SessionCalendar::contents( $items[ $a['packages']['unavailable'] ] )['state'], 'theme: unavailable contents stay unavailable' );
			$named = array_merge( ...array_map( static fn( array $i ): array => array_column( $i['contents']['items'], 'name' ), array_values( $items ) ) );
			$this->same( [], array_values( array_intersect( $named, [ 'Extra paper pack', 'Magnetic chips', 'Extra digital cards', 'Dauber' ] ) ), 'optional add-ons are never part of any Package’s contents' );
			$this->same( 'ok', SessionCalendar::contents( $item )['state'], 'theme: verified contents' );
			$component = $item['contents']['items'][0];
			foreach ( [
				'missing'          => static function ( array $i ): array { unset( $i['contents'] ); return $i; },
				'unavailable'      => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'unavailable', 'items' => [] ] ] ),
				'empty, ok'        => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [] ] ] ),
				'partial'          => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'partial', 'items' => [ $component ] ] ] ),
				'zero quantity'    => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [ array_replace( $component, [ 'quantity' => 0 ] ) ] ] ] ),
				'string quantity'  => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [ array_replace( $component, [ 'quantity' => '1' ] ) ] ] ] ),
				'unknown medium'   => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [ array_replace( $component, [ 'medium' => 'hybrid' ] ) ] ] ] ),
				'blank name'       => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [ array_replace( $component, [ 'name' => '  ' ] ) ] ] ] ),
				'keyed list'       => static fn( array $i ): array => array_replace( $i, [ 'contents' => [ 'status' => 'ok', 'items' => [ 'x' => $component ] ] ] ),
			] as $label => $mutate ) {
				$this->same( [ 'state' => 'unavailable', 'items' => [] ], SessionCalendar::contents( $mutate( $item ) ), "theme: {$label} contents are unavailable, never a partial list" );
			}
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $b['site'] );
		try {
			$model = $this->model( [ 'bc_session' => (string) $b['session'] ] );
			$items = array_column( $model['packages']['items'], null, 'id' );
			$paper = $items[ $b['packages']['paper'] ]['addons']['items'];
			$this->same( [ $a['relations']['paper_shared'], 'Hall B paper pack', 4, '$9.50' ], [ $paper[0]['id'], $paper[0]['name'], $paper[0]['max_quantity'], $this->text( $paper[0]['price_html'] ) ], 'colliding relationship ID in Hall B: Hall B’s own name, maximum and price' );
			$this->same( $b['host'], wp_parse_url( $model['purchase']['endpoint'], PHP_URL_HOST ), 'Hall B purchase endpoint is on Hall B' );
		} finally {
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 2. Rendering
	 * ================================================================== */

	private function rendering( array $a ): void {
		$this->group( 'Rendering (signed out)' );
		switch_to_blog( $a['site'] );
		try {
			[ $xpath, $html ] = $this->render( [ 'bc_session' => (string) $a['session'] ] );
			$form = $xpath->query( '//div[@id="session-packages"]/form[@data-bingo-purchase]' )->item( 0 );
			$this->ok( $form instanceof DOMElement, 'purchase form inside the existing Package block' );
			$this->same( (string) $a['session'], $form->getAttribute( 'data-session-id' ), 'the verified Session ID' );
			$this->same( $a['host'], wp_parse_url( $form->getAttribute( 'data-endpoint' ), PHP_URL_HOST ), 'endpoint on the current Hall' );
			$this->same( [ 'class', 'data-bingo-purchase', 'novalidate', 'data-endpoint', 'data-cart-endpoint', 'data-namespace', 'data-nonce', 'data-cart-url', 'data-refresh-url', 'data-session-id', 'data-session-name', 'data-session-when' ], array_values( array_map( static fn( DOMAttr $attr ): string => $attr->name, iterator_to_array( $form->attributes ) ) ), 'form carries only route settings and the Session: no prices, Hall override or product IDs' );
			$this->same( '1', (string) $xpath->evaluate( 'count(//fieldset[@data-purchase-packages]/legend[normalize-space()="Choose a Package"])' ), 'native radio group with a (screen-reader) legend' );
			$this->same( 1, $xpath->query( '//form[@data-bingo-purchase]/p[contains(@class,"bc-purchase__intro")][@hidden][normalize-space()="Choose one Bingo Package. Add-ons are optional."]' )->length, 'intro above the choices, shown with the script' );
			$this->same( [ 0, 0, 0 ], [ $xpath->query( '//label[contains(@class,"addon-card__label")]//input' )->length, $xpath->query( '//label//input[@type="number"]' )->length, $xpath->query( '//label//button' )->length ], 'add-on checkbox, quantity and its buttons are outside the checkbox’s label' );
			$this->same( 2, $xpath->query( '//fieldset[@data-addons-for="' . $a['packages']['paper'] . '"]//ul[contains(@class,"bc-addons__grid")]/li[contains(@class,"addon-card")]' )->length, 'add-on image cards in a grid' );
			// Reference layout: a narrow column of Package choices and contents; a wide column with the selected Package, add-ons, then the summary.
			$layout = '//form[@data-bingo-purchase]/div[contains(@class,"bc-purchase__layout")]';
			$this->same( [ 'fieldset', 'div' ], array_map( static fn( DOMElement $e ): string => $e->nodeName, iterator_to_array( $xpath->query( $layout . '/*' ) ) ), 'two columns: the Package radio group, then the details column' );
			$this->same( [ 'bc-purchase__selected', 'bc-purchase__addons', 'bc-purchase__summary' ], array_map( static fn( DOMElement $e ): string => $e->getAttribute( 'class' ), iterator_to_array( $xpath->query( $layout . '/div[contains(@class,"bc-purchase__details")][@hidden]/*' ) ) ), 'details column (hidden until the script runs): selected Package, add-ons, then the summary with Add to cart below them' );
			$this->same( [ 1, 1, 1 ], [ $xpath->query( '//section[contains(@class,"bc-purchase__selected")]//h4[normalize-space()="Selected Package"]' )->length, $xpath->query( '//*[@data-selected-name][normalize-space()="No Package chosen yet"]' )->length, $xpath->query( '//*[@data-selected-badge][@hidden][normalize-space()="1 Package"]' )->length ], 'selected-Package panel: nothing chosen yet; the one-Package badge appears only after a choice' );
			$this->same( 1, $xpath->query( '//section[contains(@class,"bc-purchase__summary")]//button[@data-purchase-submit][contains(@class,"btn--primary")]' )->length, 'Add to cart in the dark summary' );

			// "What's included" in every Package card, outside its radio label, described by the radio.
			$card = static fn( int $package ): string => '//li[contains(concat(" ", normalize-space(@class), " "), " session-package ")][.//input[@name="bc_package"][@value="' . $package . '"]]';
			foreach ( $a['packages'] as $key => $package ) {
				$this->ok( 1 === $xpath->query( $card( $package ) . '/div[contains(@class,"session-package__contents")][@id="bc-package-' . $package . '-contents"]/p[normalize-space()="What’s included"]' )->length
					&& 0 === $xpath->query( $card( $package ) . '/label//div[contains(@class,"session-package__contents")]' )->length
					&& str_contains( $xpath->evaluate( 'string(' . $card( $package ) . '//input[@name="bc_package"]/@aria-describedby)' ), 'bc-package-' . $package . '-contents' ), "{$key}: “What’s included” in its own card, outside the label, describing its radio" );
			}
			$includes = static fn( int $package ): array => array_map( static fn( DOMElement $li ): string => trim( preg_replace( '/\s+/u', ' ', $li->textContent ) ), iterator_to_array( $xpath->query( $card( $package ) . '//li[contains(@class,"session-package__include")]' ) ) );
			$this->same( [ 'Paper sheets Paper · Quantity: 1' ], $includes( $a['packages']['paper'] ), 'Paper card: its saved component, configured quantity and Paper label' );
			$this->same( [ 'Digital cards Digital · Quantity: 6' ], $includes( $a['packages']['digital'] ), 'Digital card: the component quantity, with no digital-face count' );
			$this->same( [ 'Paper sheets Paper · Quantity: 1', 'Digital cards Digital · Quantity: 1' ], $includes( $a['packages']['partial'] ), 'Hybrid card: both components' );
			$this->same( [ [], 1 ], [ $includes( $a['packages']['unavailable'] ), $xpath->query( $card( $a['packages']['unavailable'] ) . '//p[normalize-space()="Package contents can’t be shown right now."]' )->length ], 'unverifiable contents: “Package contents can’t be shown right now.”, no list' );
			$contents_text = implode( ' ', array_map( static fn( DOMElement $d ): string => $d->textContent, iterator_to_array( $xpath->query( '//div[contains(@class,"session-package__contents")]' ) ) ) );
			$this->ok( ! preg_match( '/Extra paper pack|Magnetic chips|Extra digital cards|Dauber|faces|admission|per seat/i', $contents_text ), 'contents never list add-ons, digital faces, admission or “per seat”' );

			// Missing images keep their shape with a decorative placeholder.
			$this->same( [ 0, 1, 0 ], [ $xpath->query( $card( $a['packages']['partial'] ) . '//img' )->length, $xpath->query( $card( $a['packages']['partial'] ) . '//span[contains(@class,"session-package__thumb")][contains(@class,"is-placeholder")]/svg[@aria-hidden="true"]' )->length, $xpath->query( $card( $a['packages']['paper'] ) . '//span[contains(@class,"is-placeholder")]' )->length ], 'Package without an image: placeholder tile; with an image: its picture' );
			$stock_card = '//li[contains(@class,"addon-card")][.//input[@id="bc-addon-' . $a['packages']['digital'] . '-' . $a['relations']['digital_stock'] . '"]]';
			$this->same( [ 0, 1 ], [ $xpath->query( $stock_card . '//img' )->length, $xpath->query( $stock_card . '//span[contains(@class,"addon-card__media")][contains(@class,"is-placeholder")]/svg' )->length ], 'add-on without an image: placeholder media' );
			$this->ok( str_contains( $html, esc_html( 'Hybrid Mix Pack with paper sheets and digital cards for the whole evening' ) ), 'a long Package name is rendered in full' );

			// Quantity wording and controls.
			$paper_panel = '//fieldset[@data-addons-for="' . $a['packages']['paper'] . '"]';
			$this->same( [ 'Maximum quantity: 2', 'Maximum quantity: 1' ], array_map( static fn( DOMElement $s ): string => trim( $s->textContent ), iterator_to_array( $xpath->query( $paper_panel . '//span[contains(@class,"addon-card__range")]' ) ) ), '“Maximum quantity: N” includes add-ons with a maximum of one' );
			$this->same( 0, substr_count( $html, 'Up to ' ), 'no “Up to N” wording' );
			$this->same( 1, $xpath->query( $paper_panel . '/p[contains(@class,"bc-addons__note")][normalize-space()="Add-ons are optional. Select an add-on, then choose its quantity."]' )->length, 'add-ons are optional; quantity follows selection' );
			$steps = iterator_to_array( $xpath->query( $paper_panel . '//button[@data-step]' ) );
			$this->ok( 4 === count( $steps ) && array_reduce( $steps, static fn( bool $ok, DOMElement $b ): bool => $ok && 'button' === $b->getAttribute( 'type' ) && '-1' === $b->getAttribute( 'tabindex' ) && $b->hasAttribute( 'disabled' ) && ( str_ends_with( $b->getAttribute( 'aria-label' ), 'quantity of Extra paper pack' ) || str_ends_with( $b->getAttribute( 'aria-label' ), 'quantity of Magnetic chips' ) ), true ), 'stepper buttons: labelled, disabled until selected, outside the tab order (the field’s own arrow keys work)' );

			// Search: local name filtering for Packages with more than one add-on.
			$this->same( [ 1, 1, 'Search add-ons for Paper Night Pack by name' ], [
				$xpath->query( $paper_panel . '//input[@type="search"][@data-addon-search][@aria-controls="bc-addon-grid-' . $a['packages']['paper'] . '"]' )->length,
				$xpath->query( $paper_panel . '//p[@data-addons-no-match][@aria-live="polite"][normalize-space()=""]' )->length,
				trim( $xpath->evaluate( 'string(' . $paper_panel . '//label[@for="bc-addon-search-' . $a['packages']['paper'] . '"])' ) ),
			], 'search field with its own label and a polite, empty no-match message' );
			$this->same( [ 0, 0, 0 ], array_map( fn( string $key ): int => $xpath->query( '//fieldset[@data-addons-for="' . $a['packages'][ $key ] . '"]//input[@type="search"]' )->length, [ 'partial', 'unavailable', 'none' ] ), 'no search for one add-on, a failed list or an empty list' );
			$radios = iterator_to_array( $xpath->query( '//input[@type="radio"][@name="bc_package"]' ) );
			$this->same( array_map( 'strval', array_values( $a['packages'] ) ), array_map( static fn( DOMElement $r ): string => $r->getAttribute( 'value' ), $radios ), 'one radio per listed Package' );
			$this->same( 0, $xpath->query( '//input[@name="bc_package"][@checked]' )->length, 'nothing preselected' );
			$this->same( 0, $xpath->query( '//input[@type="number"][contains(@name,"package") or contains(@id,"package-quantity")]' )->length, 'no Package quantity control: quantity stays one' );
			foreach ( $a['packages'] as $key => $package ) {
				$panel = $xpath->query( '//fieldset[@data-addons-for="' . $package . '"]' )->item( 0 );
				$this->ok( $panel instanceof DOMElement && $panel->hasAttribute( 'hidden' ) && $panel->hasAttribute( 'disabled' ), "{$key}: its own add-on panel, hidden and disabled until chosen" );
			}
			$panel = static fn( int $package ): string => '//fieldset[@data-addons-for="' . $package . '"]';
			$this->same( [ (string) $a['relations']['paper_shared'], (string) $a['relations']['paper_chips'] ], array_map( static fn( DOMElement $c ): string => $c->getAttribute( 'data-addon-id' ), iterator_to_array( $xpath->query( $panel( $a['packages']['paper'] ) . '//input[@type="checkbox"]' ) ) ), 'Paper panel holds only the Paper Package’s relationship IDs' );
			$this->same( [ '2', '5' ], [ $xpath->evaluate( 'string(' . $panel( $a['packages']['paper'] ) . '//input[@type="number"]/@max)' ), $xpath->evaluate( 'string(' . $panel( $a['packages']['digital'] ) . '//input[@type="number"]/@max)' ) ], 'each relationship’s own maximum' );
			$this->same( 1, $xpath->query( $panel( $a['packages']['paper'] ) . '//li[2]//input[@type="number"][@min="0"][@max="1"][@value="0"]' )->length, 'maximum one: customer chooses a quantity starting at zero' );
			$this->same( $xpath->query( '//input[@data-addon-id]' )->length, $xpath->query( '//input[contains(@class,"addon-card__qty")][@min="0"][@value="0"][@disabled]' )->length, 'every add-on quantity starts at zero and disabled' );
			$this->same( [ 'ok', 'ok', 'partial', 'unavailable', 'empty' ], array_map( static fn( DOMElement $p ): string => $p->getAttribute( 'data-addons-state' ), iterator_to_array( $xpath->query( '//fieldset[@data-addons-for]' ) ) ), 'panel states' );
			$this->same( 1, $xpath->query( $panel( $a['packages']['none'] ) . '//p[normalize-space()="No add-ons are available for this Package."]' )->length, 'successful empty list wording' );
			$this->same( 1, $xpath->query( $panel( $a['packages']['partial'] ) . '//p[normalize-space()="Some add-ons for this Package couldn’t be checked right now."]' )->length, 'partial list wording' );
			$this->same( 1, $xpath->query( $panel( $a['packages']['unavailable'] ) . '//p[normalize-space()="Add-ons can’t be shown right now."]' )->length, 'failed list wording' );
			$this->same( 0, $xpath->query( $panel( $a['packages']['unavailable'] ) . '//*[contains(., "No add-ons are available")]' )->length, 'a failed list never claims there are none' );
			$this->same( 1, $xpath->query( '//*[@data-purchase-view-cart][@hidden][@href="' . $a['cart_url'] . '"]' )->length, 'View cart rendered for after success, hidden' );
			$this->same( 1, $xpath->query( '//p[normalize-space()="Your cart shows the total."]' )->length, 'no browser total: the cart shows it' );
			$this->same( 0, $xpath->query( '//div[@id="session-packages"]//*[contains(@href,"add-to-cart") or @name="add-to-cart" or @data-product_id or contains(@class,"add_to_cart_button") or contains(@class,"ajax_add_to_cart") or (self::form and @action)]' )->length, 'no direct WooCommerce add-to-cart shortcut' );
			$this->same( 0, $this->usable_controls( $xpath, '//div[@id="session-packages"]' ), 'without JavaScript nothing in the Package block can be used or focused' );
			$this->same( 1, $xpath->query( '//p[@data-purchase-nojs][not(@hidden)][normalize-space()="Turn on JavaScript to choose a Package and add it to your cart."]' )->length, 'no-JS note' );
			$this->same( 1, $xpath->query( '//div[@id="session-packages"]/following-sibling::p[@data-purchase-status][@aria-live="polite"]' )->length, 'result announcer outside the Package block' );
			$this->ok( ! str_contains( strtolower( $html ), 'per seat' ) && ! str_contains( strtolower( $html ), 'admission' ) && ! str_contains( $html, 'session materials' ), 'no reference placeholder wording (“per seat”, admission, session materials)' );

			// Unusable purchase settings: informational cards and a clear unavailable message.
			$double = static function ( $value ) {
				if ( is_array( $value ) && is_array( $value['purchase'] ?? null ) ) { $value['purchase']['endpoint'] = 'https://elsewhere.example/'; }
				return $value;
			};
			add_filter( HomepageSessionCalendar::FILTER, $double, 20 );
			try {
				[ $xpath ] = $this->render( [ 'bc_session' => (string) $a['session'] ] );
				$this->same( [ 0, 5, 1, 5 ], [
					$xpath->query( '//form[@data-bingo-purchase]' )->length,
					$xpath->query( '//div[@id="session-packages"]//li[contains(concat(" ", normalize-space(@class), " "), " session-package ")]' )->length,
					$xpath->query( '//div[@id="session-packages"]//p[normalize-space()="Purchasing isn’t available right now."]' )->length,
					$xpath->query( '//div[@id="session-packages"]//li[contains(concat(" ", normalize-space(@class), " "), " session-package ")]/div[contains(@class,"session-package__contents")]' )->length,
				], 'unusable purchase settings: informational cards, each with its contents, and a purchasing-unavailable message' );
				$this->same( 0, $xpath->query( '//p[@data-purchase-status]' )->length, 'no announcer without a purchase form' );
			} finally {
				remove_filter( HomepageSessionCalendar::FILTER, $double, 20 );
			}
		} finally {
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 3. Real Store API cart-extension route, in-process, JSON bodies
	 * ================================================================== */

	private function store_api( array $a, array $b ): void {
		$this->group( 'Store API cart-extension outcomes (signed out, JSON)' );
		switch_to_blog( $a['site'] );
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$p = $a['packages'];
			$r = $a['relations'];
			$s = $a['session'];
			$this->clear();
			$merch = WC()->cart->add_to_cart( $a['merch'], 1 );
			$this->ok( is_string( $merch ), 'ordinary merchandise in the cart first' );
			$this->render( [ 'bc_session' => (string) $s ] );
			$this->same( 1, count( $this->lines() ), 'rendering the Packages, their contents and add-ons adds nothing to the cart' );

			$ok = $this->extension( $this->selection( $s, $p['paper'], [ [ $r['paper_shared'], 2 ], [ $r['paper_chips'], 1 ] ] ) );
			$this->same( 200, $ok->get_status(), 'one Package with two add-ons: 200' );
			$lines = $this->lines();
			$this->same( 4, count( $lines ), 'merchandise, the Package and two separate add-on lines' );
			$this->same( [ '10.00', '1.50', '3.00' ], [ $lines[1]['price'], $lines[2]['price'], $lines[3]['price'] ], 'Package price, then each relationship’s own price (empty standalone price allowed)' );
			$this->same( [ 1, 2, 1 ], [ $lines[1]['quantity'], $lines[2]['quantity'], $lines[3]['quantity'] ], 'Package quantity one; add-on quantities as submitted' );
			$this->same( [ $s, $p['paper'], 'package' ], [ $lines[1]['meta']['session_id'], $lines[1]['meta']['package_id'], $lines[1]['meta']['role'] ], 'the exact Session stays attached to the Package line' );
			$this->ok( $this->success_shape( $ok, $s, $p['paper'], [ [ $r['paper_shared'], 2 ], [ $r['paper_chips'], 1 ] ] ), 'the response cart carries exactly the submitted selection (what the script confirms)' );
			$this->same( '', wc_get_product( $this->wc_product( $a['plays']['shared'] ) )->get_price( 'edit' ), 'stored add-on product price still empty after the add' );

			$limit = $this->extension( $this->selection( $s, $p['digital'], [] ) );
			$this->rejected( $limit, 'bc_cart_package_limit', 'Remove the existing Bingo Package before selecting another.', 4, 'existing Package in the cart' );

			$this->clear( $a['merch'] );
			$only = $this->extension( $this->selection( $s, $p['none'], [] ) );
			$this->same( [ 200, 2 ], [ $only->get_status(), count( $this->lines() ) ], 'Package-only purchase: 200, merchandise kept' );

			foreach ( [
				'quantity above the relationship maximum' => [ $this->selection( $s, $p['paper'], [ [ $r['paper_shared'], 3 ] ] ), 'bc_cart_selection_invalid' ],
				'zero quantity'                            => [ $this->selection( $s, $p['paper'], [ [ $r['paper_shared'], 0 ] ] ), 'bc_cart_selection_invalid' ],
				'fractional quantity'                      => [ $this->selection( $s, $p['paper'], [ [ $r['paper_shared'], 1.5 ] ] ), 'bc_cart_selection_invalid' ],
				'relationship of another local Package'    => [ $this->selection( $s, $p['paper'], [ [ $r['digital_shared'], 1 ] ] ), 'bc_cart_addon_ineligible' ],
				'disabled relationship'                    => [ $this->selection( $s, $p['paper'], [ [ $r['disabled'], 1 ] ] ), 'bc_cart_addon_ineligible' ],
				'unreadable relationship'                  => [ $this->selection( $s, $p['partial'], [ [ $r['partial_bad'], 1 ] ] ), 'bc_cart_catalog_unavailable' ],
				'over the electronic-face limit'           => [ $this->selection( $s, $p['digital'], [ [ $r['digital_extra'], 3 ] ] ), 'bc_electronic_limit_exceeded' ],
				'native WooCommerce stock refusal'         => [ $this->selection( $s, $p['digital'], [ [ $r['digital_stock'], 2 ] ] ), 'bc_cart_selection_invalid' ],
				'price, Hall or product field'             => [ [ 'session_id' => $s, 'package_id' => $p['paper'], 'addons' => [], 'price' => '0.00' ], 'bc_cart_selection_invalid' ],
			] as $label => [ $data, $code ] ) {
				$this->clear( $a['merch'] );
				wc_clear_notices();
				$response = $this->extension( $data );
				$this->same( [ 400, $code ], [ $response->get_status(), $response->get_data()['code'] ?? null ], "{$label}: {$code}" );
				$this->same( 1, count( $this->lines() ), "{$label}: no partial new lines, merchandise kept" );
				if ( 'native WooCommerce stock refusal' === $label ) {
					$this->native_refusal_notices( $s, $p, $r );
				}
			}
			$this->clear( $a['merch'] );
			delete_site_option( ElectronicLimit::PLATFORM_OPTION );
			$this->rejected( $this->extension( $this->selection( $s, $p['digital'], [] ) ), 'bc_electronic_limit_unavailable', 'Digital bingo can’t be purchased at this Hall right now. Please try again later, or choose a Paper Package.', 1, 'unreadable electronic-limit configuration' );
			$this->same( 200, $this->extension( $this->selection( $s, $p['paper'], [] ) )->get_status(), 'Paper selection does not depend on the electronic limit' );
			update_site_option( ElectronicLimit::PLATFORM_OPTION, 48 );

			$this->clear( $a['merch'] );
			wp_set_current_user( $this->admin );
			$off = ( new SessionService( $this->audit ) )->setAvailableForSale( $s, false );
			wp_set_current_user( 0 );
			try {
				$this->ok( $off instanceof SessionResult, 'Sales turned off after rendering' );
				$this->rejected( $this->extension( $this->selection( $s, $p['paper'], [] ) ), 'bc_cart_selection_invalid', CartSelection::error()->get_error_message(), 1, 'stale Session, no substitution' );
			} finally {
				wp_set_current_user( $this->admin );
				( new SessionService( $this->audit ) )->setAvailableForSale( $s, true );
				wp_set_current_user( 0 );
			}

			$this->clear( $a['merch'] );
			$missing = $this->store( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $this->selection( $s, $p['paper'], [] ) ], 'POST', null );
			$invalid = $this->store( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $this->selection( $s, $p['paper'], [] ) ], 'POST', 'deadbeef00' );
			$this->same( [ 401, 'woocommerce_rest_missing_nonce' ], [ $missing->get_status(), $missing->get_data()['code'] ?? null ], 'missing nonce: 401 woocommerce_rest_missing_nonce' );
			$this->same( [ 403, 'woocommerce_rest_invalid_nonce' ], [ $invalid->get_status(), $invalid->get_data()['code'] ?? null ], 'invalid nonce: 403 woocommerce_rest_invalid_nonce' );
			$this->same( 1, count( $this->lines() ), 'nonce rejections change nothing (rejected before the callback)' );
			$unregistered = $this->store( '/cart/extensions', [ 'namespace' => 'bingocommerce-missing', 'data' => [] ], 'POST' );
			$this->same( [ 400, 'woocommerce_rest_cart_extensions_error' ], [ $unregistered->get_status(), $unregistered->get_data()['code'] ?? null ], 'the extension route’s own error code (the script treats it as uncertain)' );
			$this->same( 200, $this->store( '/cart', null, 'GET', null )->get_status(), 'cart read needs no nonce' );
		} finally {
			$this->clear();
			wc_clear_notices();
			restore_current_blog();
		}
	}

	/**
	 * WC_Cart::add_to_cart() records its refusal as a session notice. CustomerCart::add()
	 * now drops exactly the notices appended during its failed attempt: the attempt
	 * leaves nothing behind, earlier notices survive unchanged, and a valid purchase
	 * in the same session then succeeds and validates.
	 */
	private function native_refusal_notices( int $s, array $p, array $r ): void {
		$this->same( [], wc_get_notices(), 'native refusal: its own WooCommerce notice is not left in the session' );
		$cart = $this->store( '/cart', null, 'GET', null );
		$this->same( [], (array) ( $cart->get_data()['errors'] ?? [] ), 'native refusal: a later Store API cart read reports no cart error' );
		$passed = true;
		try { ( new CartController() )->validate_cart(); } catch ( InvalidCartException $error ) { $passed = false; }
		$this->ok( $passed, 'native refusal: Store API cart validation (as at checkout) is not blocked afterwards' );
		$this->clear();
		wc_add_notice( 'An earlier, unrelated notice.', 'notice' );
		wc_add_notice( 'An earlier, unrelated error.', 'error' );
		$before = wc_get_notices();
		$refused = $this->extension( $this->selection( $s, $p['digital'], [ [ $r['digital_stock'], 2 ] ] ) );
		$this->same( [ 400, 'bc_cart_selection_invalid' ], [ $refused->get_status(), $refused->get_data()['code'] ?? null ], 'native refusal again, with earlier notices in the session' );
		$this->same( $before, wc_get_notices(), 'earlier unrelated notices (an error and a notice) are preserved exactly; only the attempt’s notice is gone' );
		wc_clear_notices();
		$this->same( 200, $this->extension( $this->selection( $s, $p['digital'], [ [ $r['digital_stock'], 1 ] ] ) )->get_status(), 'a valid purchase in the same session then succeeds' );
		$passed = true;
		try { ( new CartController() )->validate_cart(); } catch ( InvalidCartException $error ) { $passed = false; }
		$this->ok( $passed && 2 === count( $this->lines() ), 'and the resulting cart validates (Package and add-on lines)' );
		$this->clear();
	}

	/* ================================================================== *
	 * 4. Add-on price presentation
	 * ================================================================== */

	/**
	 * An add-on line's relationship price is its only price (CartSelection::relation_priced()):
	 * no false "previous price / Save" from the product's own stored prices, while genuine
	 * Package and merchandise sales, coupons, taxes and restored carts behave as WooCommerce's own.
	 */
	private function pricing( array $a ): void {
		$this->group( 'Add-on price presentation (Store API cart, sales, coupons, taxes, restored cart)' );
		switch_to_blog( $a['site'] );
		$options = [];
		foreach ( [ 'woocommerce_enable_coupons', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_cart', 'woocommerce_tax_display_shop', 'woocommerce_tax_based_on', 'woocommerce_default_country' ] as $option ) {
			$options[ $option ] = get_option( $option, null );
		}
		$rate = 0;
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$p        = $a['packages'];
			$r        = $a['relations'];
			$s        = $a['session'];
			$on_sale  = $this->product( 'Dauber marker, on sale', [ 'price' => '3.00', 'sale' => '2.50' ] );
			$stored   = static function ( int $id ): array { $x = wc_get_product( $id ); return [ $x->get_regular_price( 'edit' ), $x->get_sale_price( 'edit' ), $x->get_price( 'edit' ) ]; };
			$chips_wc = $this->wc_product( $a['plays']['chips'] );
			$extra_wc = $this->wc_product( $a['plays']['digital_extra'] );
			$before   = [ $stored( $chips_wc ), $stored( $extra_wc ) ];
			$paper    = 'package:' . $p['paper'];
			$chips    = 'addon:' . $r['paper_chips'];
			$shared   = 'addon:' . $r['paper_shared'];
			$merch    = 'merch:' . $on_sale;

			$this->clear( $on_sale );
			$this->same( 200, $this->extension( $this->selection( $s, $p['paper'], [ [ $r['paper_shared'], 2 ], [ $r['paper_chips'], 1 ] ] ) )->get_status(), 'merchandise on sale, then the Paper Package with two add-ons' );
			$prices = $this->store_prices();
			$this->same( [ '300', '300', '300' ], $prices[ $chips ] ?? null, 'Store API cart: an add-on whose product has its own 5.00/4.00 prices shows price = regular = sale = its 3.00 relationship price (no false “Save”)' );
			$this->same( [ '150', '150', '150' ], $prices[ $shared ] ?? null, 'Store API cart: an add-on product without a standalone price shows only its relationship price' );
			$this->same( [ '1000', '1000', '1000' ], $prices[ $paper ] ?? null, 'Store API cart: a Package without a sale is unchanged' );
			$this->same( [ '250', '300', '250' ], $prices[ $merch ] ?? null, 'Store API cart: ordinary merchandise keeps its genuine sale (previous price 3.00)' );
			$lines = $this->addon_lines();
			$this->same( [ [ '3.00', '3.00', '', false ] ], [ $lines[ $r['paper_chips'] ] ], 'cart line product (in memory): price and regular price are the relationship price, no sale price, not on sale' );
			$this->same( '$3.00', $this->text( WC()->cart->get_product_price( $this->line_product( $r['paper_chips'] ) ) ), 'classic cart line price: the relationship price' );
			$this->same( $before, [ $stored( $chips_wc ), $stored( $extra_wc ) ], 'the add-on products’ stored prices are untouched (5.00/4.00 and 10.00)' );

			// A cart restored from the WooCommerce session, as on the next request.
			$keys = array_keys( WC()->cart->get_cart() );
			WC()->cart->set_session();
			WC()->cart->set_cart_contents( [] );
			WC()->cart->get_cart_from_session();
			$this->same( $keys, array_keys( WC()->cart->get_cart() ), 'restored from the session: the same lines' );
			$this->same( [ $r['paper_shared'] => [ '1.50', '1.50', '', false ], $r['paper_chips'] => [ '3.00', '3.00', '', false ] ], $this->addon_lines(), 'restored add-on lines: relationship price only, not on sale' );
			$this->same( $prices, $this->store_prices(), 'restored: Store API prices identical, the merchandise sale still genuine' );

			// Coupons: a 10% coupon that excludes sale items. The relationship price is not a sale.
			update_option( 'woocommerce_enable_coupons', 'yes' );
			$coupon = new WC_Coupon();
			$coupon->set_code( 'purchase-' . strtolower( wp_generate_password( 8, false ) ) );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );
			$coupon->set_exclude_sale_items( true );
			$coupon->save();
			$this->ok( WC()->cart->apply_coupon( $coupon->get_code() ), '10% coupon excluding sale items applied' );
			WC()->cart->calculate_totals();
			$this->same( $this->sorted( [ $merch => '0.00', $paper => '1.00', $shared => '0.30', $chips => '0.30' ] ), $this->line_discounts(), 'coupon: the genuine merchandise sale is excluded; the Package and both add-ons (relationship prices, not sales) are discounted on those prices' );
			WC()->cart->remove_coupons();
			WC()->cart->calculate_totals();

			// Taxes: 10% on every line, prices entered without tax, displayed with it.
			foreach ( [ 'woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_display_cart' => 'incl', 'woocommerce_tax_display_shop' => 'incl', 'woocommerce_tax_based_on' => 'base', 'woocommerce_default_country' => 'US:NY' ] as $option => $value ) {
				update_option( $option, $value );
			}
			$rate = (int) WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'Purchase fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );
			WC()->cart->calculate_totals();
			$this->same( $this->sorted( [ $merch => '0.25', $paper => '1.00', $shared => '0.30', $chips => '0.30' ] ), $this->line_taxes(), 'taxes: each line taxed on its own price (add-ons on their relationship prices)' );
			$taxed = $this->store_prices();
			$this->same( [ [ '330', '330', '330' ], [ '1100', '1100', '1100' ], [ '275', '330', '275' ] ], [ $taxed[ $chips ] ?? null, $taxed[ $paper ] ?? null, $taxed[ $merch ] ?? null ], 'Store API cart with tax: add-on 3.30 with no false previous price; Package 11.00; merchandise sale 2.75 from 3.30' );
			$listed = array_column( $this->model( [ 'bc_session' => (string) $s ] )['packages']['items'], null, 'id' );
			$this->same( [ '$3.30', '$11.00' ], [ $this->text( $listed[ $p['paper'] ]['addons']['items'][1]['price_html'] ), $this->text( $listed[ $p['paper'] ]['price_html'] ) ], 'homepage listing with tax display: the add-on’s relationship price and the Package price, both with tax' );
			WC_Tax::_delete_tax_rate( $rate );
			$rate = 0;
			foreach ( $options as $option => $value ) {
				null === $value ? delete_option( $option ) : update_option( $option, $value );
			}

			// A genuine sale on a Package's own product still reads as a sale; its add-on does not.
			$this->clear();
			$this->same( 200, $this->extension( $this->selection( $s, $p['digital'], [ [ $r['digital_extra'], 1 ] ] ) )->get_status(), 'the Digital Package (its product on sale) with one add-on' );
			$prices = $this->store_prices();
			$this->same( [ [ '1800', '2000', '1800' ], [ '400', '400', '400' ] ], [ $prices[ 'package:' . $p['digital'] ] ?? null, $prices[ 'addon:' . $r['digital_extra'] ] ?? null ], 'Store API cart: the Package’s genuine sale (18.00 from 20.00) is kept; its add-on (product 10.00, relationship 4.00) shows 4.00 only' );
		} finally {
			if ( $rate ) { WC_Tax::_delete_tax_rate( $rate ); }
			foreach ( $options as $option => $value ) {
				null === $value ? delete_option( $option ) : update_option( $option, $value );
			}
			$this->clear();
			wc_clear_notices();
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 5. Hall isolation
	 * ================================================================== */

	private function isolation( array $a, array $b ): void {
		$this->group( 'Hall isolation' );
		$this->same( [ $a['session'], $a['packages'], $a['relations'] ], [ $b['session'], $b['packages'], $b['relations'] ], 'Hall-local Session, Package and relationship IDs collide' );
		$this->ok( $b['only_relation_unique'] && ! in_array( $b['only_session'], [ $a['session'] ], true ), 'Hall B has a relationship and a Session whose IDs exist only there' );
		$data = $this->selection( $a['session'], $a['packages']['paper'], [ [ $a['relations']['paper_shared'], 1 ] ] );
		foreach ( [ $a, $b ] as $hall ) {
			switch_to_blog( $hall['site'] );
			try {
				WC()->initialize_session();
				WC()->initialize_cart();
				$this->clear();
				$response = $this->extension( $data );
				$lines    = $this->lines();
				$this->same( [ 200, 'b' === $hall['label'] ? '9.50' : '1.50', $hall['site'] ], [ $response->get_status(), $lines[1]['price'] ?? null, $lines[0]['meta']['site_id'] ?? null ], "Hall {$hall['label']}: the same numeric request resolves to its own relationship price and site" );
			} finally {
				$this->clear();
				restore_current_blog();
			}
		}
		switch_to_blog( $a['site'] );
		try {
			$this->clear();
			$this->rejected( $this->extension( $this->selection( $a['session'], $a['packages']['paper'], [ [ $b['only_relation'], 1 ] ] ) ), 'bc_cart_addon_ineligible', 'This add-on is not available with the selected Bingo Package.', 0, 'relationship ID that exists only in Hall B' );
			$this->rejected( $this->extension( $this->selection( $b['only_session'], $a['packages']['paper'], [] ) ), 'bc_cart_selection_invalid', CartSelection::error()->get_error_message(), 0, 'Session ID that exists only in Hall B' );
			$this->same( 200, $this->extension( $this->selection( $a['session'], $a['packages']['paper'], [] ) )->get_status(), 'local selection accepted before the foreign metadata case' );
			$keys = array_keys( WC()->cart->cart_contents );
			WC()->cart->cart_contents[ $keys[0] ][ CartSelection::META ]['site_id'] = $b['site'];
			$this->ok( CartSelection::inspect( WC()->cart->cart_contents )['errors']->has_errors(), 'cart metadata naming another Hall’s site fails current validation' );
			$this->same( 'bc_cart_selection_invalid', $this->code( CustomerCart::add( $this->selection( $a['session'], $a['packages']['none'], [] ) ) ), 'a new add is refused while that line is in the cart' );
			$blocked = false;
			try { ( new CartController() )->validate_cart(); } catch ( InvalidCartException $error ) { $blocked = 409 === $error->getCode(); }
			$this->ok( $blocked, 'Store API cart validation (as at checkout) blocks the foreign line' );
		} finally {
			$this->clear();
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 6. The ordinary Shop listing (real php-cgi requests)
	 * ================================================================== */

	private function shop( array $a, array $b ): void {
		global $wpdb;
		$this->group( 'Shop listing (Hall-local Catalog membership)' );
		$this->ok( 100 === has_action( 'woocommerce_product_query', [ ShopListing::class, 'exclude' ] ), 'the listing filter is WooCommerce’s main product query only (Shop, product archives, product search)' );

		// One product ID that is free in both Halls: ordinary merchandise in Hall A, a Bingo Play Product in Hall B.
		$free = 10 + max( array_map( static fn( array $hall ): int => (int) $wpdb->get_var( 'SELECT MAX(ID) FROM ' . $wpdb->get_blog_prefix( $hall['site'] ) . 'posts' ), [ $a, $b ] ) );
		$collide = [];
		foreach ( [ $a, $b ] as $hall ) {
			switch_to_blog( $hall['site'] );
			wp_set_current_user( $this->admin );
			try {
				$collide[ $hall['label'] ] = $this->product_with_id( $free, 'a' === $hall['label'] ? 'Hall A colliding merchandise' : 'Hall B colliding Play Product', $hall['category'] );
				if ( 'b' === $hall['label'] && is_wp_error( ( new BingoPlayProductService( $this->audit ) )->create( $free, 'paper', 1, null ) ) ) { throw new RuntimeException( 'Colliding Play Product fixture failed.' ); }
			} finally {
				wp_set_current_user( 0 );
				restore_current_blog();
			}
		}
		$this->same( [ 'a' => $free, 'b' => $free ], $collide, "product ID {$free} exists in both Halls: merchandise in Hall A, a Play Product in Hall B" );

		$names = [];
		foreach ( [ $a, $b ] as $hall ) {
			$label = strtoupper( $hall['label'] );
			switch_to_blog( $hall['site'] );
			try {
				$catalog = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT wc_product_id FROM %i UNION SELECT wc_product_id FROM %i', CatalogMigrations::packages_table(), CatalogMigrations::play_products_table() ) ) );
				$listed  = ShopListing::bingo_product_ids();
				sort( $catalog );
				sort( $listed );
				$this->same( $catalog, $listed, "Hall {$label}: the exclusion is exactly its own Catalog’s Package and Play Product products (" . count( $listed ) . ', archived and trashed rows included)' );
				$this->same( 'a' === $hall['label'] ? false : true, in_array( $free, $listed, true ), "Hall {$label}: product {$free} " . ( 'a' === $hall['label'] ? 'is ordinary here' : 'is a Play Product here' ) );
				$bingo   = array_map( static fn( int $id ): string => (string) wc_get_product( $id )->get_name(), array_filter( $listed, static fn( int $id ): bool => wc_get_product( $id ) instanceof WC_Product && 'publish' === get_post_status( $id ) ) );
				$states  = $this->product_states( array_merge( $listed, [ $hall['merch'], $free ] ) );
				$names[ $hall['label'] ] = $bingo;
			} finally {
				restore_current_blog();
			}
			$merchandise = 'a' === $hall['label'] ? [ 'Bingo dauber, blue', 'Dauber marker, on sale', 'Hall A colliding merchandise' ] : [ 'Bingo dauber, blue' ];
			foreach ( [
				'Shop page'        => [ (string) wp_parse_url( $hall['shop_url'], PHP_URL_PATH ), '', $merchandise ],
				'product category' => [ (string) wp_parse_url( $hall['category_url'], PHP_URL_PATH ), '', 'a' === $hall['label'] ? [ 'Bingo dauber, blue', 'Hall A colliding merchandise' ] : [ 'Bingo dauber, blue' ] ],
				'product search'   => [ '/', 'post_type=product&s=dauber', 'a' === $hall['label'] ? [ 'Bingo dauber, blue', 'Dauber marker, on sale' ] : null ],
			] as $where => [ $path, $query, $expected ] ) {
				if ( null === $expected ) { continue; }
				[ $status, $titles, $html ] = $this->shop_titles( $hall, $path, $query );
				sort( $titles );
				sort( $expected );
				$this->same( [ 200, $expected ], [ $status, $titles ], "Hall {$label} {$where} ({$path}" . ( '' !== $query ? '?' . $query : '' ) . '): ordinary merchandise only' );
				$this->same( [], array_values( array_intersect( $titles, $bingo ) ), "Hall {$label} {$where}: no Bingo Package or Play Product listed" );
				if ( 'Shop page' === $where ) {
					$this->ok( str_contains( $html, 'data-product_id="' . $hall['merch'] . '"' ), "Hall {$label} Shop: the merchandise keeps its add-to-cart button" );
				}
			}
			switch_to_blog( $hall['site'] );
			try {
				$this->same( $states, $this->product_states( array_keys( $states ) ), "Hall {$label}: no product saved, unpublished, re-priced or re-stocked; catalog visibility unchanged" );
			} finally {
				restore_current_blog();
			}
		}
		$this->ok( in_array( 'Hall B colliding Play Product', $names['b'], true ) && ! in_array( 'Hall A colliding merchandise', $names['a'], true ), 'each Hall classifies the colliding ID by its own Catalog' );

		// The direct-purchase guards are unchanged, and merchandise stays purchasable.
		switch_to_blog( $a['site'] );
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$this->clear();
			$this->same( false, WC()->cart->add_to_cart( $this->package_product( $a['packages']['paper'] ), 1 ), 'a Package added directly (as a Shop button would) is still refused' );
			$added = $this->store( '/cart/add-item', [ 'id' => $this->wc_product( $a['plays']['chips'] ), 'quantity' => 1 ] );
			$this->same( [ 400, 'bc_cart_selection_invalid', 0 ], [ $added->get_status(), $added->get_data()['code'] ?? null, count( $this->lines() ) ], 'a Play Product added through the Store API is still refused; the cart is unchanged' );
			$this->ok( is_string( WC()->cart->add_to_cart( $free, 1 ) ) && is_string( WC()->cart->add_to_cart( $a['merch'], 1 ) ), 'ordinary merchandise (the colliding ID included) is still purchasable' );
			$listed = array_column( $this->model( [ 'bc_session' => (string) $a['session'] ] )['packages']['items'], null, 'id' );
			$this->same( [ 5, [ $a['relations']['paper_shared'], $a['relations']['paper_chips'] ] ], [ count( $listed ), array_column( $listed[ $a['packages']['paper'] ]['addons']['items'], 'id' ) ], 'the Session’s Package list and add-on list are unaffected by the Shop filter' );
		} finally {
			$this->clear();
			wc_clear_notices();
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 7. Cart Session labels (ADR-045 / BR-084 addition 2026-10-02)
	 * ================================================================== */

	/**
	 * Label Sessions in both Halls, created after the Shop checks so earlier listings are
	 * unchanged: generated Sessions at other times, a long name in a 30-character named
	 * zone, a multibyte name, a 191-character stored name, legacy Sessions (one whose
	 * name holds "6:30 PM", one with markup), a variable merchandise product, Hall B's
	 * classic checkout page (with a Mini-Cart block, so its Cart/Checkout fallback
	 * renders), a Hall A Mini-Cart page, and one disposable signed-in customer.
	 *
	 * @return array<string,mixed>
	 */
	private function label_fixtures( array $a, array $b ): array {
		global $wpdb;
		$long   = 'Saturday Super Jackpot Early Bird Special Session with Bonus Rounds and Prize Draws';
		$multi  = 'Noche de Bingo — Ñandú Café 東京 🎉';
		$unsafe = '<img src=x onerror=alert(1)> & "q" \'a\' <b>b</b>';
		$max    = rtrim( mb_substr( str_repeat( 'Grand Charity Bingo Marathon ', 8 ), 0, 183 ) );
		while ( mb_strlen( $max ) < 183 ) { $max .= 'x'; }
		$fixtures = [];
		foreach ( [ $a, $b ] as $hall ) {
			switch_to_blog( $hall['site'] );
			wp_set_current_user( $this->admin );
			try {
				$paper = [ $hall['packages']['paper'] ];
				$x     = [ 'friday' => [ $hall['session'], $hall['date'], 'Friday Night Bingo', '18:00', self::NY ] ];
				$make  = function ( string $name, int $days, ?string $time, string $zone = self::NY ) use ( $paper ): array {
					$date = self::day( self::NY, $days );
					if ( null === $time ) { return [ $this->legacy( $name, $days, $paper ), $date, $name, null, null ]; }
					update_option( 'timezone_string', $zone );
					try { return [ $this->session( $name, $days, $paper, $time ), $date, $name, $time, $zone ]; }
					finally { update_option( 'timezone_string', self::NY ); }
				};
				if ( 'a' === $hall['label'] ) {
					// Created first: its Hall-local ID collides with Hall B's Saturday Session.
					$x['star']   = $make( 'Star Evening', 4, '19:30' );
					$x['legacy'] = $make( 'Early Bird Bingo 6:30 PM', 5, null );
				} else {
					$row           = $wpdb->get_row( $wpdb->prepare( 'SELECT session_date FROM %i WHERE session_id = %d', Migrations::sessions_table(), $hall['only_session'] ), ARRAY_A );
					$x['saturday'] = [ $hall['only_session'], (string) $row['session_date'], 'Saturday Bingo', '18:00', self::NY ];
				}
				$x['long']      = $make( $long, 3, '18:30', 'America/North_Dakota/New_Salem' );
				$x['multibyte'] = $make( $multi, 6, '20:15' );
				$x['unsafe']    = $make( $unsafe, 7, null );
				if ( 'a' === $hall['label'] ) {
					$x['max'] = $make( $max, 8, '21:45' );
				}
				$sessions = [];
				foreach ( $x as $key => [ $id, $date, $name, $time, $zone ] ) {
					$sessions[ $key ] = [ 'id' => (int) $id, 'date' => $date, 'name' => $name, 'time' => $time, 'zone' => $zone, 'label' => self::label( $date, $name, $time, $zone ) ];
				}
				[ $parent, $variation ] = $this->variable( 'Bingo bag' );
				if ( 'b' === $hall['label'] ) {
					// Classic checkout, with a Mini-Cart block: on a Checkout page it renders WooCommerce's hidden fallback.
					$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Checkout', 'post_content' => "[woocommerce_checkout]\n\n<!-- wp:woocommerce/mini-cart /-->" ] );
					update_option( 'woocommerce_checkout_page_id', (int) $checkout );
					$minicart = 0;
				} else {
					$checkout = wc_get_page_id( 'checkout' );
					$minicart = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mini-Cart fixture', 'post_name' => 'mini-cart-fixture', 'post_content' => "<!-- wp:paragraph -->\n<p>Mini-Cart fixture page.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:woocommerce/mini-cart /-->" ] );
				}
				$fixtures[ $hall['label'] ] = [
					'sessions' => $sessions, 'variable' => $parent, 'variation' => $variation,
					'checkout_page' => (int) $checkout, 'checkout_url' => (string) get_permalink( (int) $checkout ),
					'checkout_mode' => has_block( 'woocommerce/checkout', (int) $checkout ) ? 'block' : ( has_shortcode( (string) get_post_field( 'post_content', (int) $checkout ), 'woocommerce_checkout' ) ? 'shortcode' : 'unknown' ),
					'minicart_page' => (int) $minicart, 'minicart_url' => $minicart ? (string) get_permalink( (int) $minicart ) : '',
				];
			} finally {
				wp_set_current_user( 0 );
				restore_current_blog();
			}
		}
		// One disposable customer, a member of Hall A only, marked as this run's own.
		switch_to_blog( $a['site'] );
		try {
			$customer = wp_insert_user( [ 'user_login' => 'bc_label_customer_' . strtolower( wp_generate_password( 10, false ) ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'label-customer-' . strtolower( wp_generate_password( 8, false ) ) . '@example.invalid', 'role' => 'customer' ] );
			if ( is_wp_error( $customer ) ) { throw new RuntimeException( 'Customer fixture failed.' ); }
			$this->users[] = (int) $customer;
			update_user_meta( (int) $customer, self::OWNER, $this->token );
		} finally {
			restore_current_blog();
		}
		$fixtures['customer'] = (int) $customer;
		return $fixtures;
	}

	/** The approved label for these stored inputs: `MM/DD/YYYY · HH:MM Zone · Name`, or `MM/DD/YYYY · Name` without structured time. */
	private static function label( string $date, string $name, ?string $time, ?string $zone ): string {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date )->format( 'm/d/Y' );
		return null === $time ? $day . ' · ' . $name : $day . ' · ' . $time . ' ' . $zone . ' · ' . $name;
	}

	/**
	 * @return array{0:int,1:int} A variable merchandise product and one variation. With three
	 * attributes WooCommerce's own title rule leaves them out of the variation's name, so the
	 * classic displays list them as variation details.
	 */
	private function variable( string $name ): array {
		$attributes = [];
		foreach ( [ 'Colour' => [ 'Blue', 'Red' ], 'Size' => [ 'Large', 'Small' ], 'Material' => [ 'Cotton', 'Canvas' ] ] as $label => $options ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $label );
			$attribute->set_options( $options );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$parent->set_attributes( $attributes );
		$parent_id = (int) $parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'colour' => 'Blue', 'size' => 'Large', 'material' => 'Cotton' ] );
		$variation->set_regular_price( '6.00' );
		$variation->set_virtual( true );
		$variation->set_status( 'publish' );
		$variation_id = (int) $variation->save();
		WC_Product_Variable::sync( $parent_id );
		if ( $parent_id < 1 || $variation_id < 1 ) { throw new RuntimeException( 'Variable product fixture failed.' ); }
		return [ $parent_id, $variation_id ];
	}

	private function labels( array $a, array $b, array $x ): void {
		$this->group( 'Cart Session labels: registration' );
		$this->same( 100, has_filter( 'woocommerce_get_item_data', [ CustomerCart::class, 'session_details' ] ), 'one read-only callback on WooCommerce’s native woocommerce_get_item_data, priority 100' );
		CustomerCart::register();
		$this->same( 1, $this->callback_count( 'woocommerce_get_item_data', [ CustomerCart::class, 'session_details' ] ), 'the existing registration guard keeps it registered once' );
		$this->same( [ 'a' => 'block', 'b' => 'shortcode' ], [ 'a' => $x['a']['checkout_mode'], 'b' => $x['b']['checkout_mode'] ], 'Hall A: WooCommerce’s installer Checkout BLOCK page; Hall B: a classic [woocommerce_checkout] page' );
		$this->label_format( $a, $b, $x );
		$this->label_validation( $a, $x );
		$this->label_displays( $a, $x );
		$this->label_switching( $a, $b, $x );
		$this->label_isolation( $a, $b, $x );
		$this->label_customers( $a, $x );
		$this->label_behaviour( $a, $x );
		$this->label_orders( $a, $x );
	}

	/** Generated and legacy text, terminal-suffix removal, translations, multibyte and maximum-length names. */
	private function label_format( array $a, array $b, array $x ): void {
		$this->group( 'Cart Session labels: text (signed out)' );
		$this->same( [ 'America/North_Dakota/New_Salem', 30 ], [ $x['a']['sessions']['long']['zone'], strlen( $x['a']['sessions']['long']['zone'] ) ], 'fixture: the long Session uses a 30-character named zone (the longest in this PHP’s list)' );
		foreach ( [ 'a' => $a, 'b' => $b ] as $label => $hall ) {
			switch_to_blog( $hall['site'] );
			try {
				wp_set_current_user( 0 );
				WC()->initialize_session();
				WC()->initialize_cart();
				foreach ( $x[ $label ]['sessions'] as $key => $s ) {
					$this->label_cart( $hall, $s['id'] );
					$store   = $this->store_lines();
					$classic = $this->classic_lines();
					$pkg     = 'package:' . $hall['packages']['paper'];
					$this->same( [ $this->entry( $s['label'] ) ], $store[ $pkg ]['item_data'] ?? null, "Hall {$label} {$key}: Store API item_data is one “Bingo Session” entry, escaped ({$s['label']})" );
					$this->same( [ [ 'Bingo Session', $s['label'] ] ], $classic[ $pkg ] ?? null, "Hall {$label} {$key}: classic item data shows the same text" );
					$this->same( [ [], [] ], [ $store[ 'addon:' . $hall['relations']['paper_shared'] ]['item_data'] ?? null, $store[ 'merch:' . $hall['merch'] ]['item_data'] ?? null ], "Hall {$label} {$key}: add-on and merchandise keep empty item data" );
					$this->same( [ [], [] ], [ $classic[ 'addon:' . $hall['relations']['paper_shared'] ] ?? null, $classic[ 'merch:' . $hall['merch'] ] ?? null ], "Hall {$label} {$key}: classic add-on and merchandise unchanged" );
					if ( 'unsafe' === $key ) {
						$html = wc_get_formatted_cart_item_data( $this->package_item() );
						$dom  = $this->xpath( $html );
						$this->ok( 0 === $dom->query( '//img|//b|//script' )->length && str_contains( $html, '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot; &#039;a&#039; &lt;b&gt;b&lt;/b&gt;' ), "Hall {$label}: markup in a Session name is escaped text, never elements" );
					}
				}
				$names = [];
				foreach ( $x[ $label ]['sessions'] as $key => $s ) {
					$names[ $key ] = $this->stored_name( $s['id'] );
				}
				$expected = array_map( static fn( array $s ): string => null === $s['time'] ? $s['name'] : rtrim( mb_substr( trim( preg_replace( '/\s+/u', ' ', $s['name'] ) ), 0, 183 ) ) . ' — ' . $s['time'], $x[ $label ]['sessions'] );
				$this->same( $expected, $names, "Hall {$label}: stored names unchanged (generated names keep their “ — HH:MM” ending; legacy names as written)" );
			} finally {
				$this->clear();
				restore_current_blog();
			}
		}
		$this->same( 191, mb_strlen( $this->in_hall( $a, fn(): string => $this->stored_name( $x['a']['sessions']['max']['id'] ) ) ), 'the maximum Session name (191 characters stored) still gets its label, without the ending' );
		foreach ( [ '00:00', '09:05', '18:30', '23:59' ] as $clock ) {
			$this->same( 'Any Name' . "\u{20}\u{2014}\u{20}" . $clock, \BingoCommerce\Sessions\ScheduleExpansion::occurrence_display_name( 'Any Name', $clock . ':00' ), "suffix drift guard: a generated name for {$clock} ends with exactly the “ — {$clock}” the label removes" );
		}

		$this->group( 'Cart Session labels: translations' );
		switch_to_blog( $a['site'] );
		$map = [
			'Bingo Session' => 'Sesión de bingo',
			'%1$s · %2$s %3$s · %4$s' => '%4$s — %1$s, %2$s (%3$s)',
			'%1$s · %2$s' => '<b>%2$s</b> el %1$s',
			'Session details can’t be shown right now.' => 'Los detalles de la sesión no se pueden mostrar ahora.',
		];
		$gettext = static fn( $translation, $text, $domain ) => 'bingocommerce-sessions' === $domain && isset( $map[ $text ] ) ? $map[ $text ] : $translation;
		add_filter( 'gettext', $gettext, 10, 3 );
		try {
			$star   = $x['a']['sessions']['star'];
			$legacy = $x['a']['sessions']['legacy'];
			$this->label_cart( $a, $star['id'] );
			$day = static fn( array $s ): string => DateTimeImmutable::createFromFormat( '!Y-m-d', $s['date'] )->format( 'm/d/Y' );
			$this->same( [ $this->entry( 'Star Evening — ' . $day( $star ) . ', 19:30 (America/New_York)', 'Sesión de bingo' ) ], $this->package_store_data(), 'translated heading and a reordered timed format (positional arguments)' );
			$this->label_cart( $a, $legacy['id'] );
			$this->same( [ $this->entry( '<b>Early Bird Bingo 6:30 PM</b> el ' . $day( $legacy ), 'Sesión de bingo' ) ], $this->package_store_data(), 'a translation containing markup is escaped with the rest of the value' );
			$this->same( [ [ 'Sesión de bingo', '<b>Early Bird Bingo 6:30 PM</b> el ' . $day( $legacy ) ] ], $this->classic_lines()[ 'package:' . $a['packages']['paper'] ], 'classic: the translated markup shows as text' );
			$this->with_label_row( 'fail', fn() => $this->same( [ $this->entry( 'Los detalles de la sesión no se pueden mostrar ahora.', 'Sesión de bingo' ) ], $this->package_store_data(), 'the neutral message is translated too' ) );
			$broken = static fn( $translation, $text, $domain ) => 'bingocommerce-sessions' === $domain && '%1$s · %2$s' === $text ? '%1$s %5$s' : $translation;
			remove_filter( 'gettext', $gettext, 10 );
			add_filter( 'gettext', $broken, 10, 3 );
			try {
				$warnings = $this->collect_warnings( fn() => $this->same( [ $this->entry( 'Session details can’t be shown right now.' ) ], $this->package_store_data(), 'a translation whose format cannot be filled (ArgumentCountError) falls back to the neutral message' ) );
				$this->same( [], $warnings, 'no PHP warning from the broken translation' );
			} finally {
				remove_filter( 'gettext', $broken, 10 );
			}
		} finally {
			remove_filter( 'gettext', $gettext, 10 );
			$this->clear();
			restore_current_blog();
		}
	}

	/** Malformed fields reach the neutral entry; verification failures add nothing; unexpected shapes pass through. */
	private function label_validation( array $a, array $x ): void {
		global $wpdb;
		$this->group( 'Cart Session labels: field validation (verified Package line)' );
		switch_to_blog( $a['site'] );
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$star = $x['a']['sessions']['star'];
			$this->label_cart( $a, $star['id'] );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT session_id, session_date, display_name, schedule_id, start_time_local, timezone_name, starts_at_utc FROM %i WHERE session_id = %d', Migrations::sessions_table(), $star['id'] ), ARRAY_A );
			$neutral = [ $this->entry( 'Session details can’t be shown right now.' ) ];
			$state   = $this->cart_state();
			$this->with_label_row( $row, fn() => $this->same( [ $this->entry( $star['label'] ) ], $this->package_store_data(), 'control: the stored row returned through the same read gives the label (the read is intercepted for the cases below)' ) );
			$next  = (int) substr( $row['session_date'], 0, 4 ) + 1;
			$cases = [
				'date 30 February'                  => [ 'session_date' => $next . '-02-30' ],
				'date with a trailing space'        => [ 'session_date' => $row['session_date'] . ' ' ],
				'date as MM/DD/YYYY'                => [ 'session_date' => DateTimeImmutable::createFromFormat( '!Y-m-d', $row['session_date'] )->format( 'm/d/Y' ) ],
				'date empty'                        => [ 'session_date' => '' ],
				'date NULL'                         => [ 'session_date' => null ],
				'time NULL (generated)'             => [ 'start_time_local' => null ],
				'time with seconds'                 => [ 'start_time_local' => '19:30:30' ],
				'time 25:00:00'                     => [ 'start_time_local' => '25:00:00' ],
				'time negative'                     => [ 'start_time_local' => '-01:00:00' ],
				'time without seconds'              => [ 'start_time_local' => '19:30' ],
				'time padded'                       => [ 'start_time_local' => ' 19:30:00' ],
				'zone NULL'                         => [ 'timezone_name' => null ],
				'zone empty'                        => [ 'timezone_name' => '' ],
				'zone abbreviation EST'             => [ 'timezone_name' => 'EST' ],
				'zone offset +05:00'                => [ 'timezone_name' => '+05:00' ],
				'zone unknown'                      => [ 'timezone_name' => 'Mars/Olympus_Mons' ],
				'zone padded'                       => [ 'timezone_name' => ' America/New_York' ],
				'zone backward-compatible link'     => [ 'timezone_name' => 'US/Eastern' ],
				'UTC start NULL'                    => [ 'starts_at_utc' => null ],
				'UTC start one minute off'          => [ 'starts_at_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $row['starts_at_utc'] . ' UTC' ) + 60 ) ],
				'UTC start in another format'       => [ 'starts_at_utc' => str_replace( ' ', 'T', $row['starts_at_utc'] ) . 'Z' ],
				'name empty'                        => [ 'display_name' => '' ],
				'name whitespace only'              => [ 'display_name' => '   ' ],
				'name no-break spaces only'         => [ 'display_name' => "\u{00A0}\u{00A0}" ],
				'name invalid UTF-8'                => [ 'display_name' => [ 'hex' => bin2hex( "Star \xC3\x28vening — 19:30" ) ] ],
				'name 192 characters'               => [ 'display_name' => str_repeat( 'y', 184 ) . ' — 19:30' ],
				'name without its time ending'      => [ 'display_name' => 'Star Evening' ],
				'name ending with another time'     => [ 'display_name' => 'Star Evening — 18:00' ],
				'name ending with a hyphen'         => [ 'display_name' => 'Star Evening - 19:30' ],
				'name that is only the ending'      => [ 'display_name' => ' — 19:30' ],
				'name of spaces and the ending'     => [ 'display_name' => '    — 19:30' ],
				'schedule 0'                        => [ 'schedule_id' => '0' ],
				'schedule negative'                 => [ 'schedule_id' => '-3' ],
				'schedule not a number'             => [ 'schedule_id' => 'abc' ],
				'schedule zero-padded'              => [ 'schedule_id' => '0' . $row['schedule_id'] ],
				'legacy row carrying a time'        => [ 'schedule_id' => null ],
				'legacy row carrying only a zone'   => [ 'schedule_id' => null, 'start_time_local' => null, 'starts_at_utc' => null ],
				'legacy row carrying only a UTC'    => [ 'schedule_id' => null, 'start_time_local' => null, 'timezone_name' => null ],
				'a different Session identity'      => [ 'session_id' => (string) ( $star['id'] + 1 ) ],
				'a zero-padded Session identity'    => [ 'session_id' => '0' . $star['id'] ],
			];
			foreach ( $cases as $label => $change ) {
				$warnings = $this->collect_warnings( fn() => $this->with_label_row( array_replace( $row, $change ), function () use ( $neutral, $label, $a ): void {
					$this->same( $neutral, $this->package_store_data(), "{$label}: the neutral entry, nothing invented" );
					$this->same( [ [ 'Bingo Session', 'Session details can’t be shown right now.' ] ], $this->classic_lines()[ 'package:' . $a['packages']['paper'] ], "{$label}: classic shows the neutral message" );
				} ) );
				$this->same( [], $warnings, "{$label}: no PHP warning or notice" );
			}
			foreach ( [ 'missing row' => null, 'read failure' => 'fail' ] as $label => $result ) {
				$warnings = $this->collect_warnings( fn() => $this->with_label_row( $result, fn() => $this->same( $neutral, $this->package_store_data(), "{$label}: the neutral entry" ) ) );
				$this->same( [], $warnings, "{$label}: no PHP warning or notice" );
			}
			$this->with_label_row( array_replace( $row, [ 'display_name' => 'Late — 21:00 — 19:30' ] ), fn() => $this->same( [ $this->entry( self::label( $row['session_date'], 'Late — 21:00', '19:30', self::NY ) ) ], $this->package_store_data(), 'only the exact terminal “ — 19:30” is removed; an earlier time-like text stays' ) );
			$this->with_label_row( array_replace( $row, [ 'schedule_id' => null, 'start_time_local' => null, 'timezone_name' => null, 'starts_at_utc' => null, 'display_name' => 'Legacy-shaped 7:15 PM' ] ), fn() => $this->same( [ $this->entry( self::label( $row['session_date'], 'Legacy-shaped 7:15 PM', null, null ) ) ], $this->package_store_data(), 'a legacy-shaped row: date and complete name, no time invented' ) );
			$this->same( $state, $this->cart_state(), 'after every malformed case: cart contents, hash, totals, notices, validation errors and session data unchanged' );

			$this->group( 'Cart Session labels: verification failures add nothing' );
			$parent = $this->package_key();
			$meta   = WC()->cart->cart_contents[ $parent ][ CartSelection::META ];
			$forged = [
				'another site ID'         => [ 'site_id' => get_current_blog_id() + 100000 ],
				'version 2'               => [ 'version' => 2 ],
				'string Session ID'       => [ 'session_id' => (string) $meta['session_id'] ],
				'invalid selection UUID'  => [ 'selection_id' => 'not-a-uuid' ],
				'unknown role'            => [ 'role' => 'bundle' ],
				'keyed add-on list'       => [ 'addon_ids' => [ 'x' => 1 ] ],
				'parent key on a Package' => [ 'parent_key' => str_repeat( 'a', 32 ) ],
				'Session of another Hall' => [ 'session_id' => 999999 ],
			];
			foreach ( $forged as $label => $change ) {
				WC()->cart->cart_contents[ $parent ][ CartSelection::META ] = array_replace( $meta, $change );
				$this->label_unverified( $label, $parent );
			}
			WC()->cart->cart_contents[ $parent ][ CartSelection::META ] = $meta;
			WC()->cart->cart_contents[ $parent ]['quantity'] = 2;
			$this->label_unverified( 'Package quantity 2', $parent );
			WC()->cart->cart_contents[ $parent ]['quantity'] = 1;
			$merch = $this->merch_key();
			WC()->cart->cart_contents[ $merch ][ CartSelection::META ] = $meta;
			$this->same( [], $this->store_lines()[ 'merch:' . $a['merch'] ]['item_data'] ?? null, 'merchandise line carrying a Package’s metadata: no entry' );
			unset( WC()->cart->cart_contents[ $merch ][ CartSelection::META ] );
			wp_set_current_user( $this->admin );
			$off = ( new SessionService( $this->audit ) )->setAvailableForSale( $star['id'], false );
			wp_set_current_user( 0 );
			try {
				$this->ok( $off instanceof SessionResult, 'fixture: the Session’s Sales turned OFF after it was added (real writer)' );
				$this->label_unverified( 'Session no longer for sale', $parent );
			} finally {
				wp_set_current_user( $this->admin );
				( new SessionService( $this->audit ) )->setAvailableForSale( $star['id'], true );
				wp_set_current_user( 0 );
			}
			$this->same( [ $this->entry( $star['label'] ) ], $this->package_store_data(), 'Sales back ON: the label returns' );

			$this->group( 'Cart Session labels: unexpected shapes' );
			$item     = $this->package_item();
			$warnings = $this->collect_warnings( function () use ( $item ): void {
				foreach ( [ 'a string' => 'x', 'null' => null, 'an integer' => 7, 'an object' => new ArrayObject( [ 1 ] ) ] as $label => $value ) {
					$this->ok( $value === CustomerCart::session_details( $value, $item ), "incoming item data as {$label}: returned unchanged" );
				}
				foreach ( [ 'not an array' => 'cart item', 'empty' => [], 'string metadata' => [ CartSelection::META => 'x' ], 'array Session ID' => [ CartSelection::META => [ 'session_id' => [ 1 ] ], 'product_id' => [ 2 ], 'quantity' => [ 1 ] ] ] as $label => $cart_item ) {
					$this->same( [ [ 'key' => 'Other', 'value' => 'kept' ] ], CustomerCart::session_details( [ [ 'key' => 'Other', 'value' => 'kept' ] ], $cart_item ), "cart item {$label}: existing metadata returned unchanged" );
				}
				$this->same( [], CustomerCart::session_details( [] ), 'one argument only: unchanged' );
				$string = static fn() => 'not an array';
				add_filter( 'woocommerce_get_item_data', $string, 50 );
				try {
					$this->same( '', wc_get_formatted_cart_item_data( $item ), 'an earlier filter returning a string: passed through, WooCommerce prints nothing' );
				} finally {
					remove_filter( 'woocommerce_get_item_data', $string, 50 );
				}
			} );
			$this->same( [], $warnings, 'unexpected shapes: no PHP warning or notice' );

			// Non-list item data (e.g. another filter's associative or sparse array) on this verified Package
			// line is returned exactly as received, before CartSelection::line() or any database read.
			$entry   = $this->entry( $star['label'] );
			$queries = 0;
			$count   = static function ( $sql ) use ( &$queries ) { ++$queries; return $sql; };
			$call    = function ( array $incoming ) use ( $item, $count, &$queries ): array {
				$result  = null;
				$queries = 0;
				add_filter( 'query', $count );
				try {
					$warnings = $this->collect_warnings( static function () use ( &$result, $incoming, $item ): void { $result = CustomerCart::session_details( $incoming, $item ); } );
				} finally {
					remove_filter( 'query', $count );
				}
				return [ $result, $warnings, $queries ];
			};
			foreach ( [
				'an associative array' => [ 'unexpected' => 'value' ],
				'a sparse array'       => [ 2 => [ 'key' => 'Other', 'value' => 'kept' ] ],
			] as $label => $incoming ) {
				[ $result, $warnings, $read ] = $call( $incoming );
				$this->same( $incoming, $result, "item data as {$label}: returned exactly as received, keys included" );
				$this->ok( is_array( $result ) && [] === array_filter( $result, static fn( $value ): bool => is_array( $value ) && 'Bingo Session' === ( $value['key'] ?? null ) ), "item data as {$label}: no Bingo Session entry appended" );
				$this->same( [], $warnings, "item data as {$label}: no PHP warning or notice" );
				$this->same( 0, $read, "item data as {$label}: no database query (returned before CartSelection::line())" );
			}
			// Control: a list keeps its entry and gets exactly one Bingo entry, after verification and the Session read.
			[ $result, $warnings, $read ] = $call( [ [ 'key' => 'Other', 'value' => 'kept' ] ] );
			$this->same( [ [ 'key' => 'Other', 'value' => 'kept' ], $entry ], $result, 'control, a list: its entry kept, exactly one Bingo Session entry appended' );
			$this->ok( [] === $warnings && $read > 0, "control, a list: no PHP warning; verification and the Session read queried the database ({$read} queries), so the counter observes those reads" );
		} finally {
			$this->clear();
			restore_current_blog();
		}
	}

	/** A line that fails CartSelection::line(): no Bingo entry in any display, and the existing validation result is unchanged. */
	private function label_unverified( string $label, string $key ): void {
		$this->same( [], $this->store_line( $key )['item_data'] ?? null, "{$label}: no Session details in the Store API" );
		$this->same( '', wc_get_formatted_cart_item_data( WC()->cart->cart_contents[ $key ] ), "{$label}: none in the classic displays" );
		$on  = [ CartSelection::inspect( WC()->cart->get_cart() )['errors']->get_error_codes(), $this->store_errors() ];
		$off = $this->without_labels( fn(): array => [ CartSelection::inspect( WC()->cart->get_cart() )['errors']->get_error_codes(), $this->store_errors() ] );
		$this->ok( [] !== $on[0] && $on === $off, "{$label}: existing validation still reports it, identically with and without the label callback (" . implode( ',', $on[0] ) . ')' );
	}

	/** Every native display: placement, one entry per Package, existing metadata and variation details, escaping. */
	private function label_displays( array $a, array $x ): void {
		$this->group( 'Cart Session labels: classic displays and Store API metadata' );
		switch_to_blog( $a['site'] );
		$before = static function ( $data, $item ) { if ( is_array( $data ) ) { $data[] = [ 'key' => 'Fixture before', 'value' => 'kept' ]; } return $data; };
		$after  = static function ( $data, $item ) { if ( is_array( $data ) ) { $data[] = [ 'key' => 'Fixture after', 'value' => 'kept' ]; } return $data; };
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$friday = $x['a']['sessions']['friday'];
			$this->label_cart( $a, $friday['id'] );
			WC()->cart->add_to_cart( $x['a']['variable'], 1, $x['a']['variation'], [ 'attribute_colour' => 'Blue', 'attribute_size' => 'Large', 'attribute_material' => 'Cotton' ] );
			WC()->cart->calculate_totals();
			// Classic lines are keyed by the parent product; Store API lines by the variation.
			$variation       = 'merch:' . $x['a']['variable'];
			$variation_store = 'merch:' . $x['a']['variation'];
			$details         = [ [ 'Colour', 'Blue' ], [ 'Size', 'Large' ], [ 'Material', 'Cotton' ] ];
			$classic         = $this->classic_lines();
			$this->same( $details, $classic[ $variation ] ?? null, 'variation line: WooCommerce’s own variation details (classic)' );
			$this->same( $classic[ $variation ], $this->without_labels( fn() => $this->classic_lines()[ $variation ] ?? null ), 'variation line: identical with and without the label callback' );
			$store = $this->store_lines();
			$this->same( [ [], [ [ 'raw_attribute' => 'attribute_colour', 'attribute' => 'Colour', 'value' => 'Blue' ], [ 'raw_attribute' => 'attribute_size', 'attribute' => 'Size', 'value' => 'Large' ], [ 'raw_attribute' => 'attribute_material', 'attribute' => 'Material', 'value' => 'Cotton' ] ] ], [ $store[ $variation_store ]['item_data'] ?? null, $store[ $variation_store ]['variation'] ?? null ], 'variation line: Store API variation details kept, no item data added' );

			// Displays rendered with and without the callback: the only difference is the Package's one entry, in its native place.
			$on  = $this->classic_templates();
			$off = $this->without_labels( fn(): array => $this->classic_templates() );
			$pkg = $this->package_name( $a );
			foreach ( [
				'classic cart'            => [ 'cart', '//tr[contains(@class,"cart_item")][.//td[contains(@class,"product-name")]/a[normalize-space()="' . $pkg . '"]]/td[contains(@class,"product-name")]', 'a[normalize-space()="' . $pkg . '"]', null ],
				'classic checkout review' => [ 'review', '//table[contains(@class,"woocommerce-checkout-review-order-table")]//tr[contains(@class,"cart_item")]/td[contains(@class,"product-name")][starts-with(normalize-space(),"' . $pkg . '")]', 'strong[contains(@class,"product-quantity")]', null ],
				'classic mini-cart'       => [ 'minicart', '//li[contains(@class,"mini_cart_item")][a[not(contains(@class,"remove"))][contains(normalize-space(),"' . $pkg . '")]]', 'a[not(contains(@class,"remove"))]', 'span[contains(@class,"quantity")]' ],
			] as $display => [ $template, $container, $previous, $next ] ) {
				$dom   = $this->xpath( $on[ $template ] );
				$cells = $dom->query( $container );
				$this->same( 1, $cells->length, "{$display}: one Package line found" );
				$cell = $cells->item( 0 );
				$dls  = $dom->query( 'dl[contains(@class,"variation")]', $cell );
				$this->same( [ 1, [ [ 'Bingo Session', $friday['label'] ] ] ], [ $dls->length, $dls->length ? $this->dl_pairs( $dls->item( 0 ) ) : null ], "{$display}: one native item-data list with the one Bingo Session entry" );
				$this->ok( $dls->length && 1 === $dom->query( 'preceding-sibling::' . $previous, $dls->item( 0 ) )->length && ( null === $next || 1 === $dom->query( 'following-sibling::' . $next, $dls->item( 0 ) )->length ), "{$display}: after the " . ( 'classic checkout review' === $display ? 'name and the quantity text' : 'name link' ) . ( null === $next ? '' : ', before the quantity and price text' ) );
				$this->same( 1, $dom->query( '//dl[contains(@class,"variation")]/dt[normalize-space()="Bingo Session:"]' )->length, "{$display}: exactly one Bingo Session entry in the whole display (add-on and merchandise lines have none)" );
				$this->same( $this->normalized( $off[ $template ] ), $this->normalized( $this->without_node( $on[ $template ], $container . '/dl[contains(@class,"variation")][dt[normalize-space()="Bingo Session:"]]' ) ), "{$display}: identical to the display without the callback once that one list is removed (prices, quantities, removal controls, totals unchanged)" );
			}
			$this->same( 0, $this->xpath( $on['review'] )->query( '//table[contains(@class,"woocommerce-checkout-review-order-table")]//a[contains(@class,"remove")]|//table[contains(@class,"woocommerce-checkout-review-order-table")]//input' )->length, 'classic checkout review: still no removal or quantity control' );

			// Store API: every line's complete item data; only the Package's entry differs from the response without the callback.
			$json_on  = $this->store_json();
			$json_off = $this->without_labels( fn(): array => $this->store_json() );
			$this->same( $json_off, $this->strip_label( $json_on ), 'Store API cart: identical to the response without the callback once the Package’s entry is removed (keys, quantities, limits, prices, totals, variation, extensions, errors)' );
			$items = [];
			foreach ( $json_on['items'] as $item ) { $items[ $this->store_key( $item ) ] = $item['item_data']; }
			$this->same( [ 'merch:' . $a['merch'] => [], 'package:' . $a['packages']['paper'] => [ $this->entry( $friday['label'] ) ], 'addon:' . $a['relations']['paper_shared'] => [], $variation_store => [] ], $items, 'Store API: complete item data for every line (merchandise, Package, add-on, variation)' );

			$this->group( 'Cart Session labels: existing metadata from other filters' );
			add_filter( 'woocommerce_get_item_data', $before, 5, 2 );
			add_filter( 'woocommerce_get_item_data', $after, 200, 2 );
			$note_before = [ 'key' => 'Fixture before', 'value' => 'kept' ];
			$note_after  = [ 'key' => 'Fixture after', 'value' => 'kept' ];
			$items = [];
			foreach ( $this->store_json()['items'] as $item ) { $items[ $this->store_key( $item ) ] = $item['item_data']; }
			$this->same( [ $note_before, $this->entry( $friday['label'] ), $note_after ], $items[ 'package:' . $a['packages']['paper'] ], 'Store API Package line: an earlier entry, the Bingo entry, then a later entry — appended once, nothing replaced or reordered' );
			$this->same( [ [ $note_before, $note_after ], [ $note_before, $note_after ], [ $note_before, $note_after ] ], [ $items[ 'addon:' . $a['relations']['paper_shared'] ], $items[ 'merch:' . $a['merch'] ], $items[ $variation_store ] ], 'Store API other lines: their entries unchanged, no Bingo entry' );
			$classic = $this->classic_lines();
			$this->same( [ [ 'Fixture before', 'kept' ], [ 'Bingo Session', $friday['label'] ], [ 'Fixture after', 'kept' ] ], $classic[ 'package:' . $a['packages']['paper'] ], 'classic Package line: the three entries in filter order' );
			$this->same( array_merge( $details, [ [ 'Fixture before', 'kept' ], [ 'Fixture after', 'kept' ] ] ), $classic[ $variation ], 'classic variation line: variation details first, then the other entries; no Bingo entry' );
			remove_filter( 'woocommerce_get_item_data', $before, 5 );
			remove_filter( 'woocommerce_get_item_data', $after, 200 );

			$this->group( 'Cart Session labels: one entry per verified Package line' );
			$item = $this->package_item();
			$this->same( [ $this->entry( $friday['label'] ) ], apply_filters( 'woocommerce_get_item_data', apply_filters( 'woocommerce_get_item_data', [], $item ), $item ), 'the filter applied twice to the same list still holds one Bingo entry' );
			// The existing one-Package rule: a second, otherwise valid Package line (forged in the cart) blocks the cart.
			$copy = $item;
			$copy[ CartSelection::META ]['selection_id'] = wp_generate_uuid4();
			$copy[ CartSelection::META ]['addon_ids']    = [];
			$copy['key'] = str_repeat( 'f', 32 );
			WC()->cart->cart_contents[ $copy['key'] ] = $copy;
			$json     = $this->store_json();
			$packages = array_values( array_filter( $json['items'], static fn( array $i ): bool => 'package' === ( $i['extensions']['bingocommerce-selection']['role'] ?? null ) ) );
			$this->ok( 2 === count( $packages ) && in_array( 'bc_cart_package_limit', CartSelection::inspect( WC()->cart->get_cart() )['errors']->get_error_codes(), true ) && [] !== $json['errors'], 'two Package lines: the existing one-Package rule blocks the cart' );
			$this->same( [ [ $this->entry( $friday['label'] ) ], [ $this->entry( $friday['label'] ) ] ], array_column( $packages, 'item_data' ), 'blocked cart: each verified Package line shows exactly one entry' );
			unset( WC()->cart->cart_contents[ $copy['key'] ] );
		} finally {
			remove_filter( 'woocommerce_get_item_data', $before, 5 );
			remove_filter( 'woocommerce_get_item_data', $after, 200 );
			$this->clear();
			restore_current_blog();
		}
	}

	/** Session A in the cart, then Session B viewed and the cart opened with B's parameters: the label stays A's. */
	private function label_switching( array $a, array $b, array $x ): void {
		$this->group( 'Cart Session labels: Session A in the cart, Session B viewed' );
		foreach ( [ 'a' => [ $a, 'friday', 'star' ], 'b' => [ $b, 'friday', 'saturday' ] ] as $label => [ $hall, $first, $second ] ) {
			switch_to_blog( $hall['site'] );
			$previous = [ $_GET, $_REQUEST ];
			try {
				wp_set_current_user( 0 );
				WC()->initialize_session();
				WC()->initialize_cart();
				$this->clear( $hall['merch'] );
				$s1 = $x[ $label ]['sessions'][ $first ];
				$s2 = $x[ $label ]['sessions'][ $second ];
				$this->same( 200, $this->extension( $this->selection( $s1['id'], $hall['packages']['paper'], [ [ $hall['relations']['paper_shared'], 1 ] ] ) )->get_status(), "Hall {$label}: Session A’s Package added through the real cart-extension route" );
				[ $xpath ] = $this->render( [ 'bc_session' => (string) $s2['id'] ] );
				$form = $xpath->query( '//form[@data-bingo-purchase]' )->item( 0 );
				$this->ok( $form instanceof DOMElement && (string) $s2['id'] === $form->getAttribute( 'data-session-id' ), "Hall {$label}: the homepage now shows Session B’s purchase step" );
				$_GET     = [ 'bc_session' => (string) $s2['id'], 'bc_date' => $s2['date'] ];
				$_REQUEST = $_GET;
				$this->same( [ $this->entry( $s1['label'] ) ], $this->package_store_data(), "Hall {$label}: with Session B’s parameters on the request, the Store API still names Session A ({$s1['label']})" );
				$this->same( [ [ 'Bingo Session', $s1['label'] ] ], $this->classic_lines()[ 'package:' . $hall['packages']['paper'] ] ?? null, "Hall {$label}: classic displays still name Session A" );
				$this->ok( ! str_contains( wp_json_encode( $this->store_json() ), $s2['name'] ), "Hall {$label}: Session B’s name appears nowhere in the cart" );
			} finally {
				[ $_GET, $_REQUEST ] = $previous;
				$this->clear();
				restore_current_blog();
			}
		}
	}

	/** Colliding Hall-local IDs, forged foreign-Hall metadata and the current Hall's own table. */
	private function label_isolation( array $a, array $b, array $x ): void {
		global $wpdb;
		$this->group( 'Cart Session labels: Hall isolation' );
		$star     = $x['a']['sessions']['star'];
		$saturday = $x['b']['sessions']['saturday'];
		$this->same( $star['id'], $saturday['id'], 'Hall-local Session IDs collide: Hall A “Star Evening” and Hall B “Saturday Bingo” share an ID' );
		$this->same( $x['a']['sessions']['legacy']['id'], $x['b']['sessions']['long']['id'], 'and another pair: a Hall A legacy Session and Hall B’s long-name Session' );
		foreach ( [ 'a' => [ $a, [ $star, $x['a']['sessions']['legacy'] ] ], 'b' => [ $b, [ $saturday, $x['b']['sessions']['long'] ] ] ] as $label => [ $hall, $sessions ] ) {
			switch_to_blog( $hall['site'] );
			$tables = [];
			$trace  = static function ( $sql ) use ( &$tables ) { if ( is_string( $sql ) && preg_match_all( '/`?(\w*bingo_sessions)`?/', $sql, $m ) ) { array_push( $tables, ...$m[1] ); } return $sql; };
			$switches = did_action( 'switch_blog' );
			try {
				wp_set_current_user( 0 );
				WC()->initialize_session();
				WC()->initialize_cart();
				foreach ( $sessions as $s ) {
					$this->clear();
					$this->same( 200, $this->extension( $this->selection( $s['id'], $hall['packages']['paper'], [] ) )->get_status(), "Hall {$label}: Session ID {$s['id']} added" );
					add_filter( 'query', $trace );
					try { $data = $this->package_store_data(); } finally { remove_filter( 'query', $trace ); }
					$this->same( [ $this->entry( $s['label'] ) ], $data, "Hall {$label}: Session ID {$s['id']} shows this Hall’s own Session ({$s['label']})" );
				}
				$this->same( [ $wpdb->prefix . 'bingo_sessions' ], array_values( array_unique( $tables ) ), "Hall {$label}: every Session read used this Hall’s own table ({$wpdb->prefix}bingo_sessions)" );
				$this->same( $switches, did_action( 'switch_blog' ), "Hall {$label}: no site switch while labelling" );
				$parent = $this->package_key();
				WC()->cart->cart_contents[ $parent ][ CartSelection::META ]['site_id'] = 'a' === $label ? $b['site'] : $a['site'];
				$this->same( [], $this->store_line( $parent )['item_data'] ?? null, "Hall {$label}: metadata naming the other Hall’s site: no Session details" );
				$this->same( '', wc_get_formatted_cart_item_data( WC()->cart->cart_contents[ $parent ] ), "Hall {$label}: none in the classic displays either" );
			} finally {
				$this->clear();
				restore_current_blog();
			}
		}
	}

	/** Guest and signed-in customers, including a persistent cart restored for the signed-in customer. */
	private function label_customers( array $a, array $x ): void {
		$this->group( 'Cart Session labels: guest and signed-in customers' );
		switch_to_blog( $a['site'] );
		try {
			$friday = $x['a']['sessions']['friday'];
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$this->clear();
			$this->same( 200, $this->extension( $this->selection( $friday['id'], $a['packages']['paper'], [] ) )->get_status(), 'guest: added through the cart-extension route' );
			$this->same( [ 0, [ $this->entry( $friday['label'] ) ] ], [ get_current_user_id(), $this->package_store_data() ], 'guest: the label' );
			$this->clear();
			wp_set_current_user( $x['customer'] );
			$this->ok( is_user_logged_in() && user_can( $x['customer'], 'customer' ) && ! current_user_can( 'bc_manage_products' ), 'signed in as the disposable customer (no Manager capability)' );
			$this->same( 200, $this->extension( $this->selection( $friday['id'], $a['packages']['paper'], [ [ $a['relations']['paper_shared'], 1 ] ] ) )->get_status(), 'signed-in customer: added with their own Store API nonce' );
			WC()->cart->calculate_totals();
			$this->same( [ $this->entry( $friday['label'] ) ], $this->package_store_data(), 'signed-in customer: the label (Store API)' );
			$this->same( [ [ 'Bingo Session', $friday['label'] ] ], $this->classic_lines()[ 'package:' . $a['packages']['paper'] ] ?? null, 'signed-in customer: the label (classic)' );
			$saved = get_user_meta( $x['customer'], '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
			$this->same( 2, count( (array) ( $saved['cart'] ?? [] ) ), 'signed-in customer: WooCommerce saved the persistent cart (Package and add-on)' );
			WC()->session->set( 'cart', null );
			WC()->cart->set_cart_contents( [] );
			WC()->cart->get_cart_from_session();
			$this->same( [ 2, [ $this->entry( $friday['label'] ) ] ], [ count( WC()->cart->get_cart() ), $this->package_store_data() ], 'signed-in customer: a cart restored from the persistent cart shows the same label' );
		} finally {
			$this->clear();
			// The browser stage signs this customer in afresh: no saved cart carried over from here.
			delete_user_meta( $x['customer'], '_woocommerce_persistent_cart_' . get_current_blog_id() );
			wp_set_current_user( 0 );
			restore_current_blog();
		}
	}

	/** Unchanged cart state, checkout validation (including with the neutral entry) and Remove/Undo. */
	private function label_behaviour( array $a, array $x ): void {
		$this->group( 'Cart Session labels: cart behaviour unchanged' );
		switch_to_blog( $a['site'] );
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$friday = $x['a']['sessions']['friday'];
			$parent = $this->label_cart( $a, $friday['id'] );
			$state  = $this->cart_state();
			foreach ( WC()->cart->get_cart() as $item ) { apply_filters( 'woocommerce_get_item_data', [], $item ); }
			$this->store_json();
			$this->classic_lines();
			$this->same( $state, $this->cart_state(), 'rendering every line’s item data (callback, Store API, classic) leaves contents, linkage, prices, quantities, hash, totals, notices, validation and session data unchanged' );
			$extensions = static fn( array $json ): array => array_column( $json['items'], 'extensions', 'key' );
			$this->same( $extensions( $this->without_labels( fn(): array => $this->store_json() ) ), $extensions( $this->store_json() ), 'Store API extension data (Session, Package, relationship, role, parent key) unchanged' );

			$outcome = function (): array {
				wc_clear_notices();
				$store = 'ok';
				try { ( new CartController() )->validate_cart(); } catch ( InvalidCartException $error ) { $store = implode( ',', $error->getError()->get_error_codes() ); }
				$checkout = new WP_Error();
				CustomerCart::checkout_errors( [], $checkout );
				// As the classic cart and checkout shortcodes do before rendering.
				do_action( 'woocommerce_check_cart_items' );
				$classic = wc_get_notices();
				wc_clear_notices();
				return [ $store, CartSelection::inspect( WC()->cart->get_cart() )['errors']->get_error_codes(), $checkout->get_error_codes(), $classic ];
			};
			$valid = $outcome();
			$this->same( [ 'ok', [], [], [] ], $valid, 'valid cart: Store API cart validation (as at checkout), classic checkout validation and classic cart checks pass' );
			$this->same( $valid, $this->without_labels( $outcome ), 'valid cart: identical without the label callback' );
			$this->same( $valid, $this->with_label_row( 'fail', $outcome ), 'valid cart while the neutral entry is displayed: identical — the fallback adds no checkout rule' );
			$this->with_label_row( 'fail', fn() => $this->same( [ $this->entry( 'Session details can’t be shown right now.' ) ], $this->package_store_data(), 'control: the neutral entry was being displayed' ) );
			wp_set_current_user( $this->admin );
			( new SessionService( $this->audit ) )->setAvailableForSale( $friday['id'], false );
			wp_set_current_user( 0 );
			try {
				$blocked = $outcome();
				$this->ok( 'ok' !== $blocked[0] && [] !== $blocked[1] && [] !== $blocked[3], 'invalid cart (Session OFF): still blocked by the existing validation' );
				$this->same( $blocked, $this->without_labels( $outcome ), 'invalid cart: the same result without the label callback' );
			} finally {
				wp_set_current_user( $this->admin );
				( new SessionService( $this->audit ) )->setAvailableForSale( $friday['id'], true );
				wp_set_current_user( 0 );
			}

			$this->group( 'Cart Session labels: Remove and Undo' );
			$child = $this->addon_key();
			$this->ok( WC()->cart->remove_cart_item( $child ), 'remove the add-on (existing behaviour)' );
			$this->same( [ 2, [ $this->entry( $friday['label'] ) ] ], [ count( WC()->cart->get_cart() ), $this->package_store_data() ], 'the Package keeps its label after its add-on is removed' );
			$this->ok( WC()->cart->restore_cart_item( $child ), 'Undo the add-on' );
			$this->ok( WC()->cart->remove_cart_item( $parent ), 'remove the Package' );
			$this->same( [ 'merch:' . $a['merch'] => [] ], array_map( static fn( array $line ): array => $line['item_data'], $this->store_lines() ), 'Package removal cascades its add-on (existing behaviour); only the merchandise remains, with no label' );
			$this->ok( WC()->cart->restore_cart_item( $parent ), 'Undo the Package' );
			$this->same( [ 3, [ $this->entry( $friday['label'] ) ] ], [ count( WC()->cart->get_cart() ), $this->package_store_data() ], 'Undo restores the Package with its add-on and the same label' );
		} finally {
			$this->clear();
			restore_current_blog();
		}
	}

	/** Order facts keep their existing format, and saved orders are byte-identical after every label render. */
	private function label_orders( array $a, array $x ): void {
		global $wpdb;
		$this->group( 'Cart Session labels: order facts unchanged' );
		switch_to_blog( $a['site'] );
		try {
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$friday = $x['a']['sessions']['friday'];
			$order  = $this->label_order( $a, $friday['id'] );
			$label  = DateTimeImmutable::createFromFormat( '!Y-m-d', $friday['date'] )->format( 'm/d/Y' ) . ' — Friday Night Bingo — 18:00 — 18:00 America/New_York';
			$this->same( [ $label, $label ], $this->order_session_labels( $order ), 'native order creation (WC_Checkout::create_order, no payment): every Bingo line saves the EXISTING “Bingo Session” format, time repeated as before' );
			$facts = wc_get_order( $order )->get_meta( \BingoCommerce\Sessions\CustomerOrder::META, true );
			$this->same( [ 'Friday Night Bingo — 18:00', '18:00:00', self::NY ], [ $facts['session_name'], $facts['start_time_local'], $facts['timezone_name'] ], 'saved purchase facts: the stored occurrence name (with its time), structured time and zone, as before' );
			$snapshot = $this->order_snapshot( $order );
			foreach ( [ 'star', 'legacy', 'long', 'unsafe' ] as $key ) {
				$this->label_cart( $a, $x['a']['sessions'][ $key ]['id'] );
				$this->store_json();
				$this->classic_templates();
				$this->with_label_row( 'fail', fn() => $this->store_json() );
			}
			$this->same( $snapshot, $this->order_snapshot( $order ), 'the saved order, its items and their metadata are byte-identical after every cart, checkout and mini-cart render (including neutral entries)' );
			$later = $this->label_order( $a, $x['a']['sessions']['star']['id'] );
			$star  = DateTimeImmutable::createFromFormat( '!Y-m-d', $x['a']['sessions']['star']['date'] )->format( 'm/d/Y' ) . ' — Star Evening — 19:30 — 19:30 America/New_York';
			$this->same( [ $star, $star ], $this->order_session_labels( $later ), 'an order recorded after the label renders still uses the existing format' );
			$this->ok( ! str_contains( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(meta_value) FROM %i WHERE meta_key = %s', $wpdb->prefix . 'woocommerce_order_itemmeta', 'bc_bingo_session' ) ), ' · ' ), 'no order item ever saves the cart label format' );
		} finally {
			$this->clear();
			restore_current_blog();
		}
	}

	private function label_order( array $hall, int $session ): int {
		$this->label_cart( $hall, $session );
		$id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'guest@example.invalid', 'billing_first_name' => 'Fixture', 'billing_last_name' => 'Customer', 'billing_country' => 'US', 'billing_state' => 'NY', 'billing_postcode' => '12207', 'billing_city' => 'Albany', 'billing_address_1' => '1 Test Street' ] );
		if ( ! is_int( $id ) || $id < 1 ) { throw new RuntimeException( 'Order fixture failed: ' . wp_json_encode( is_wp_error( $id ) ? $id->get_error_messages() : $id ) ); }
		$this->clear();
		return $id;
	}

	/** @return array<int,string> Each Bingo order line's saved "Bingo Session" metadata, as stored. */
	private function order_session_labels( int $order ): array {
		$labels = [];
		foreach ( wc_get_order( $order )->get_items() as $item ) {
			if ( '' !== $item->get_meta( \BingoCommerce\Sessions\CustomerOrder::META, true ) ) { $labels[] = (string) $item->get_meta( 'bc_bingo_session', true ); }
		}
		return $labels;
	}

	/** The order's stored rows, read directly: order record, order metadata, items and every item's metadata. */
	private function order_snapshot( int $order ): string {
		global $wpdb;
		$hpos  = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY order_item_id', $wpdb->prefix . 'woocommerce_order_items', $order ), ARRAY_A );
		$ids   = array_map( 'intval', array_column( $items, 'order_item_id' ) );
		$meta  = $ids ? $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta WHERE order_item_id IN (' . implode( ',', $ids ) . ') ORDER BY meta_id', ARRAY_A ) : [];
		$record = $hpos
			? [ $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wc_orders', $order ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $wpdb->prefix . 'wc_orders_meta', $order ), ARRAY_A ) ]
			: [ $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $order ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY meta_id', $wpdb->postmeta, $order ), ARRAY_A ) ];
		if ( '' !== $wpdb->last_error || [] === $items || [] === $meta ) { throw new RuntimeException( 'Order snapshot failed.' ); }
		return hash( 'sha256', serialize( [ $record, $items, $meta ] ) );
	}

	/* ---- Label helpers ---- */

	/** A fresh cart: merchandise, then this Session's Paper Package (and one add-on) through CustomerCart::add(). */
	private function label_cart( array $hall, int $session, bool $addon = true ): string {
		$this->clear( $hall['merch'] );
		$parent = CustomerCart::add( $this->selection( $session, $hall['packages']['paper'], $addon ? [ [ $hall['relations']['paper_shared'], 1 ] ] : [] ) );
		if ( ! is_string( $parent ) ) { throw new RuntimeException( 'Label cart fixture failed for Session ' . $session . ': ' . wp_json_encode( is_wp_error( $parent ) ? $parent->get_error_code() : $parent ) ); }
		WC()->cart->calculate_totals();
		return $parent;
	}

	/** The native item-data entry for this text, as WooCommerce receives it (key, value and display, escaped). */
	private function entry( string $text, string $key = 'Bingo Session' ): array {
		$value = esc_html( $text );
		return [ 'key' => esc_html( $key ), 'value' => $value, 'display' => $value ];
	}

	/** @return array<string,mixed> The Store API cart, as the Cart, Checkout and Mini-Cart blocks receive it (JSON round trip). */
	private function store_json(): array {
		return json_decode( (string) wp_json_encode( $this->store( '/cart', null, 'GET', null )->get_data() ), true );
	}

	private function store_key( array $item ): string {
		$x    = $item['extensions']['bingocommerce-selection'] ?? [];
		$role = $x['role'] ?? null;
		return 'package' === $role ? 'package:' . $x['package_id'] : ( 'addon' === $role ? 'addon:' . $x['addon_id'] : 'merch:' . $item['id'] );
	}

	/** @return array<string,array<string,mixed>> Store API lines keyed like line_key(). */
	private function store_lines(): array {
		$lines = [];
		foreach ( $this->store_json()['items'] ?? [] as $item ) { $lines[ $this->store_key( $item ) ] = $item; }
		return $lines;
	}

	/** @return array<int,mixed> The Store API cart's errors. */
	private function store_errors(): array {
		return (array) ( $this->store_json()['errors'] ?? [] );
	}

	/** The Store API line with this cart key, whatever its metadata now says. */
	private function store_line( string $key ): ?array {
		foreach ( $this->store_json()['items'] ?? [] as $item ) {
			if ( $key === $item['key'] ) { return $item; }
		}
		return null;
	}

	/** @return mixed The Package line's Store API item_data. */
	private function package_store_data() {
		return $this->store_line( $this->package_key() )['item_data'] ?? null;
	}

	/** @return array<string,array<int,array{0:string,1:string}>> Classic formatted item data (heading, text) per line, as wc_get_formatted_cart_item_data() prints it. */
	private function classic_lines(): array {
		$lines = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$html = wc_get_formatted_cart_item_data( $item );
			$dl   = '' === trim( $html ) ? null : $this->xpath( $html )->query( '//dl' )->item( 0 );
			$lines[ $this->line_key( $item ) ] = $dl instanceof DOMElement ? $this->dl_pairs( $dl ) : [];
		}
		return $lines;
	}

	/** @return array<int,array{0:string,1:string}> */
	private function dl_pairs( DOMElement $dl ): array {
		$pairs = [];
		$key   = null;
		foreach ( $dl->childNodes as $node ) {
			if ( ! $node instanceof DOMElement ) { continue; }
			if ( 'dt' === $node->nodeName ) { $key = rtrim( trim( $node->textContent ), ':' ); }
			elseif ( 'dd' === $node->nodeName ) { $pairs[] = [ (string) $key, trim( $node->textContent ) ]; }
		}
		return $pairs;
	}

	/** @return array<string,string> The classic cart, checkout review and mini-cart templates as WooCommerce renders them. */
	private function classic_templates(): array {
		wc_clear_notices();
		return [
			'cart'     => wc_get_template_html( 'cart/cart.php' ),
			'review'   => wc_get_template_html( 'checkout/review-order.php' ),
			'minicart' => wc_get_template_html( 'cart/mini-cart.php', [ 'list_class' => '' ] ),
		];
	}

	private function xpath( string $html ): DOMXPath {
		$dom      = new DOMDocument();
		$internal = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $internal ); }
		return new DOMXPath( $dom );
	}

	/** The rendered HTML with one element removed. */
	private function without_node( string $html, string $query ): string {
		$xpath = $this->xpath( $html );
		foreach ( iterator_to_array( $xpath->query( $query ) ) as $node ) { $node->parentNode->removeChild( $node ); }
		return (string) $xpath->document->saveHTML( $xpath->document->getElementsByTagName( 'body' )->item( 0 ) );
	}

	/**
	 * Markup compared without inter-tag whitespace (removing a node leaves its surrounding
	 * newlines) and with the classic quantity inputs' per-render uniqid() IDs made equal.
	 */
	private function normalized( string $html ): string {
		$xpath = $this->xpath( $html );
		$body  = (string) $xpath->document->saveHTML( $xpath->document->getElementsByTagName( 'body' )->item( 0 ) );
		return trim( (string) preg_replace( [ '/>\s+</', '/\s+/', '/quantity_[0-9a-f]{13}/' ], [ '><', ' ', 'quantity_ID' ], $body ) );
	}

	/** The Store API cart with only the Package lines' Bingo Session entries removed. */
	private function strip_label( array $json ): array {
		foreach ( $json['items'] as &$item ) {
			if ( 'package' === ( $item['extensions']['bingocommerce-selection']['role'] ?? null ) ) {
				$item['item_data'] = array_values( array_filter( $item['item_data'], static fn( array $e ): bool => 'Bingo Session' !== ( $e['key'] ?? null ) ) );
			}
		}
		unset( $item );
		return $json;
	}

	/** @return mixed $fn's result with the label callback removed. */
	private function without_labels( callable $fn ) {
		remove_filter( 'woocommerce_get_item_data', [ CustomerCart::class, 'session_details' ], 100 );
		try { return $fn(); }
		finally { add_filter( 'woocommerce_get_item_data', [ CustomerCart::class, 'session_details' ], 100, 2 ); }
	}

	/**
	 * Runs $fn while the label's own Session read returns $row as literal values (a row the
	 * column types might never store), no row (null) or a database error ('fail').
	 *
	 * @param array<string,mixed>|string|null $row
	 * @return mixed
	 */
	private function with_label_row( $row, callable $fn ) {
		global $wpdb;
		$read   = 'SELECT session_id, session_date, display_name, schedule_id, start_time_local, timezone_name, starts_at_utc FROM `' . Migrations::sessions_table() . '` WHERE session_id = ';
		$filter = static function ( $sql ) use ( $read, $row, $wpdb ) {
			if ( ! is_string( $sql ) || ! str_starts_with( $sql, $read ) ) { return $sql; }
			if ( 'fail' === $row ) { return 'SELECT session_id FROM `bc_label_fixture_missing_table`'; }
			if ( null === $row ) { return 'SELECT 1 AS session_id FROM DUAL WHERE 1 = 0'; }
			$columns = [];
			foreach ( [ 'session_id', 'session_date', 'display_name', 'schedule_id', 'start_time_local', 'timezone_name', 'starts_at_utc' ] as $column ) {
				$value     = $row[ $column ];
				$columns[] = ( null === $value ? 'NULL' : ( is_array( $value ) ? "X'" . $value['hex'] . "'" : $wpdb->prepare( '%s', $value ) ) ) . ' AS ' . $column;
			}
			return 'SELECT ' . implode( ', ', $columns );
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try { return $fn(); }
		finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $suppress ); }
	}

	/** @return array<int,string> PHP warnings, notices and deprecations raised while $fn ran. */
	private function collect_warnings( callable $fn ): array {
		$warnings = [];
		set_error_handler( static function ( int $level, string $message, string $file, int $line ) use ( &$warnings ): bool { $warnings[] = $message . ' @ ' . basename( $file ) . ':' . $line; return true; } );
		try { $fn(); }
		finally { restore_error_handler(); }
		return $warnings;
	}

	/** @return array<string,mixed> Cart state that rendering must never change. */
	private function cart_state(): array {
		$lines = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$copy = $item;
			unset( $copy['data'] );
			$lines[ $key ] = [ $copy, $item['data']->get_price(), $item['data']->get_regular_price(), $item['data']->get_sale_price() ];
		}
		return [
			'lines' => $lines, 'hash' => WC()->cart->get_cart_hash(), 'totals' => WC()->cart->get_totals(), 'notices' => wc_get_notices(),
			'errors' => CartSelection::inspect( WC()->cart->get_cart() )['errors']->get_error_codes(),
			'session' => WC()->session ? WC()->session->get_session_data() : null, 'removed' => array_keys( WC()->cart->get_removed_cart_contents() ),
		];
	}

	private function package_key(): string {
		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( 'package' === ( $item[ CartSelection::META ]['role'] ?? null ) ) { return (string) $key; }
		}
		throw new RuntimeException( 'No Package line.' );
	}

	private function addon_key(): string {
		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( 'addon' === ( $item[ CartSelection::META ]['role'] ?? null ) ) { return (string) $key; }
		}
		throw new RuntimeException( 'No add-on line.' );
	}

	private function merch_key(): string {
		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( ! isset( $item[ CartSelection::META ] ) ) { return (string) $key; }
		}
		throw new RuntimeException( 'No merchandise line.' );
	}

	private function package_item(): array {
		return WC()->cart->cart_contents[ $this->package_key() ];
	}

	private function package_name( array $hall ): string {
		return (string) wc_get_product( $this->package_product( $hall['packages']['paper'] ) )->get_name();
	}

	private function stored_name( int $session ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT display_name FROM %i WHERE session_id = %d', Migrations::sessions_table(), $session ) );
	}

	/** @return mixed $fn's result inside this Hall. */
	private function in_hall( array $hall, callable $fn ) {
		switch_to_blog( $hall['site'] );
		try { return $fn(); }
		finally { restore_current_blog(); }
	}

	private function callback_count( string $hook, array $callback ): int {
		$count = 0;
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $entry ) { $count += $entry['function'] === $callback ? 1 : 0; }
		}
		return $count;
	}

	/* ================================================================== *
	 * Optional browser check
	 * ================================================================== */

	private function browser( array $a, array $b, array $labels = [] ): void {
		$this->group( 'Browser (optional)' );
		if ( '1' !== getenv( 'BC_SESSION_PURCHASE_BROWSER' ) ) {
			$this->notes[] = 'Browser coverage SKIPPED: set BC_SESSION_PURCHASE_BROWSER=1 with BC_NODE_EXECUTABLE, BC_PLAYWRIGHT_MODULE, BC_CHROME_EXECUTABLE and BC_SESSION_PURCHASE_ARTIFACTS.';
			return;
		}
		$halls = [];
		foreach ( [ $a, $b ] as $hall ) {
			switch_to_blog( $hall['site'] );
			try {
				$model = $this->model( [ 'bc_session' => (string) $hall['session'] ] );
				$names = [];
				foreach ( $model['packages']['items'] as $item ) {
					$names[ $item['id'] ] = [ 'name' => $item['name'], 'price' => $this->text( $item['price_html'] ), 'contents' => $item['contents'], 'addons' => array_map( fn( array $x ): array => [ 'id' => $x['id'], 'name' => $x['name'], 'max' => $x['max_quantity'], 'price' => $this->text( $x['price_html'] ) ], $item['addons']['items'] ) ];
				}
				$bingo = array_values( array_filter( array_map( static fn( int $id ): string => ( $x = wc_get_product( $id ) ) instanceof WC_Product && 'publish' === $x->get_status() ? (string) $x->get_name() : '', ShopListing::bingo_product_ids() ) ) );
				$extra = $labels[ $hall['label'] ] ?? [];
				$halls[ $hall['label'] ] = [
					'site' => $hall['site'], 'host' => $hall['host'], 'cart_page' => $hall['cart_page'], 'cart_url' => $hall['cart_url'], 'cart_path' => (string) wp_parse_url( $hall['cart_url'], PHP_URL_PATH ),
					'shop_url' => $hall['shop_url'], 'shop_path' => (string) wp_parse_url( $hall['shop_url'], PHP_URL_PATH ), 'category_path' => (string) wp_parse_url( $hall['category_url'], PHP_URL_PATH ), 'bingo_products' => $bingo,
					'endpoint' => $model['purchase']['endpoint'], 'cart_endpoint' => $model['purchase']['cart_endpoint'], 'session' => $hall['session'], 'date' => $hall['date'],
					'packages' => $hall['packages'], 'relations' => $hall['relations'], 'merch' => $hall['merch'], 'names' => $names,
					'session_name' => $model['session']['name'], 'session_url' => $model['session']['packages_url'],
					'only_relation' => $hall['only_relation'] ?? null,
					// Cart Session labels: each label Session's ID, date and expected text; the checkout and Mini-Cart pages; the variation.
					'labels' => array_map( static fn( array $s ): array => [ 'id' => $s['id'], 'date' => $s['date'], 'name' => $s['name'], 'label' => $s['label'] ], $extra['sessions'] ?? [] ),
					'checkout_url' => $extra['checkout_url'] ?? '', 'checkout_path' => (string) wp_parse_url( (string) ( $extra['checkout_url'] ?? '' ), PHP_URL_PATH ), 'checkout_mode' => $extra['checkout_mode'] ?? '',
					'minicart_url' => $extra['minicart_url'] ?? '', 'minicart_path' => (string) wp_parse_url( (string) ( $extra['minicart_url'] ?? '' ), PHP_URL_PATH ),
					'variable' => $extra['variable'] ?? 0, 'variation' => $extra['variation'] ?? 0, 'package_name' => $this->package_name( $hall ),
					'neutral' => 'Session details can’t be shown right now.',
				];
				if ( 'a' === $hall['label'] && ! empty( $labels['customer'] ) ) {
					// The disposable customer's own login cookie for Hall A (a real session token, verified by WordPress on every request).
					$expires = time() + 2 * HOUR_IN_SECONDS;
					$token   = WP_Session_Tokens::get_instance( $labels['customer'] )->create( $expires );
					$halls['a']['customer'] = [ 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $labels['customer'], $expires, 'logged_in', $token ), 'expires' => $expires ];
				}
			} finally {
				restore_current_blog();
			}
		}
		$config = [
			'root' => rtrim( ABSPATH, '/' ), 'cgi' => dirname( PHP_BINARY ) . '/php-cgi', 'php' => PHP_BINARY,
			'worker' => __DIR__ . '/homepage-session-purchase-request.php', 'control' => __DIR__ . '/homepage-session-purchase-control.php',
			'owner' => $this->token, 'network' => BC_FIXTURE_DOMAIN, 'artifacts' => $this->artifacts(),
			'images' => [ 'paper.png' => 'product-bingo-paper.png', 'chips.png' => 'product-magnetic-chips.png', 'tote.png' => 'product-tote-bag.png', 'dauber.png' => 'product-dauber.png' ],
			'image_root' => dirname( ABSPATH ) . '/bingocommer-frontendstore/assets/images',
			'gate' => [ 'status' => \BingoCommerce\PortalIntegration\HallCommerceGate::STATUS, 'headers' => \BingoCommerce\PortalIntegration\HallCommerceGate::refusal_headers(), 'body' => \BingoCommerce\PortalIntegration\HallCommerceGate::body() ],
			'a' => $halls['a'], 'b' => $halls['b'],
		];
		$file = $this->artifacts() . '/purchase-browser-config.json';
		file_put_contents( $file, wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$node = (string) getenv( 'BC_NODE_EXECUTABLE' ) ?: 'node';
		$env  = [ 'PATH' => getenv( 'PATH' ), 'HOME' => getenv( 'HOME' ), 'BC_HSP_CONFIG' => $file, 'BC_PLAYWRIGHT_MODULE' => (string) getenv( 'BC_PLAYWRIGHT_MODULE' ), 'BC_CHROME_EXECUTABLE' => (string) getenv( 'BC_CHROME_EXECUTABLE' ), 'BC_TEST_DB' => 'bingocommerce_state_recon' ];
		[ $exit, $out, $err ] = $this->process( [ $node, __DIR__ . '/homepage-session-purchase-browser.cjs' ], $env, 2400 );
		echo $out;
		if ( '' !== trim( $err ) ) { fwrite( STDERR, $err ); }
		preg_match( '/Browser session purchase: (\d+) passed/', $out, $match );
		$this->ok( 0 === $exit && ! empty( $match[1] ), 'browser check passed (' . ( $match[1] ?? 0 ) . ' browser assertions)' );
		foreach ( [ $a, $b ] as $hall ) {
			global $wpdb;
			$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->get_blog_prefix( $hall['site'] ) . 'woocommerce_sessions' ) );
			$this->ok( $rows > 0, "Hall {$hall['label']}: guest carts persisted in the Hall’s own WooCommerce session table ({$rows} rows)" );
		}
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	private function model( array $query ): array {
		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		try { return HomepageSessionCalendar::model( $query ); }
		finally { wp_set_current_user( $previous ); }
	}

	/** @return array{0:DOMXPath,1:string} */
	private function render( array $query ): array {
		$previous_get  = $_GET;
		$previous_user = get_current_user_id();
		$_GET          = $query;
		wp_set_current_user( 0 );
		wp_set_template_globals();
		ob_start();
		try {
			include ABSPATH . 'wp-content/themes/' . self::THEME . '/front-page.php';
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = $previous_get;
			wp_set_current_user( $previous_user );
		}
		$dom      = new DOMDocument();
		$internal = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $internal ); }
		return [ new DOMXPath( $dom ), $html ];
	}

	/** @return array<int,string> Callbacks on these hooks that are defined in a BingoCommerce plugin or theme. */
	private function bingocommerce_callbacks( array $hooks ): array {
		$found = [];
		foreach ( $hooks as $hook ) {
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					$reflection = is_array( $function ) ? new ReflectionMethod( $function[0], $function[1] ) : ( is_string( $function ) && str_contains( $function, '::' ) ? new ReflectionMethod( $function ) : new ReflectionFunction( $function ) );
					if ( str_contains( (string) $reflection->getFileName(), '/bingocommerce-' ) ) { $found[] = $hook . ': ' . $reflection->getFileName(); }
				}
			}
		}
		return $found;
	}

	/** Controls usable or focusable without the script: neither hidden nor disabled, nor inside a hidden element or disabled fieldset. */
	private function usable_controls( DOMXPath $xpath, string $scope ): int {
		$usable = 0;
		foreach ( $xpath->query( $scope . '//*[self::input or self::button or self::select or self::textarea or self::a]' ) as $control ) {
			$blocked = $control->hasAttribute( 'hidden' ) || $control->hasAttribute( 'disabled' );
			for ( $node = $control->parentNode; ! $blocked && $node instanceof DOMElement; $node = $node->parentNode ) {
				$blocked = $node->hasAttribute( 'hidden' ) || ( 'fieldset' === $node->nodeName && $node->hasAttribute( 'disabled' ) );
			}
			$usable += $blocked ? 0 : 1;
		}
		return $usable;
	}

	/** @param array<int,array{0:int,1:mixed}> $addons */
	private function selection( int $session, int $package, array $addons ): array {
		return [ 'session_id' => $session, 'package_id' => $package, 'addons' => array_map( static fn( array $x ): array => [ 'package_addon_id' => $x[0], 'quantity' => $x[1] ], $addons ) ];
	}

	private function extension( array $data ): WP_REST_Response {
		return $this->store( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $data ] );
	}

	/** One real Store API request with a JSON body, as the browser sends it. */
	private function store( string $path, ?array $json, string $method = 'POST', ?string $nonce = 'valid' ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wc/store/v1' . $path );
		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $json ) );
		}
		if ( 'valid' === $nonce ) { $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) ); }
		elseif ( null !== $nonce ) { $request->set_header( 'Nonce', $nonce ); }
		$response = rest_get_server()->dispatch( $request );
		return $response instanceof WP_REST_Response ? $response : rest_ensure_response( $response );
	}

	/** @return array<int,array{price:string,quantity:int,meta:?array}> */
	private function lines(): array {
		$lines = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$lines[] = [ 'price' => (string) $item['data']->get_price(), 'quantity' => (int) $item['quantity'], 'meta' => $item[ CartSelection::META ] ?? null ];
		}
		return $lines;
	}

	/** Exactly the shape homepage-purchase.js confirms before reporting success. */
	private function success_shape( WP_REST_Response $response, int $session, int $package, array $addons ): bool {
		$data   = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		$parent = null;
		foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
			$x = $item['extensions']['bingocommerce-selection'] ?? null;
			if ( is_array( $x ) && 'package' === ( $x['role'] ?? null ) && $session === ( $x['session_id'] ?? null ) && $package === ( $x['package_id'] ?? null ) && 1 === ( $item['quantity'] ?? null ) ) { $parent = $item; }
		}
		if ( null === $parent ) { return false; }
		$children = array_values( array_filter( (array) $data['items'], static fn( $item ): bool => 'addon' === ( $item['extensions']['bingocommerce-selection']['role'] ?? null ) && $parent['key'] === ( $item['extensions']['bingocommerce-selection']['parent_key'] ?? null ) ) );
		$pairs    = array_map( static fn( array $item ): array => [ $item['extensions']['bingocommerce-selection']['addon_id'], $item['quantity'] ], $children );
		sort( $pairs );
		$expected = $addons;
		sort( $expected );
		return $expected === $pairs;
	}

	private function rejected( WP_REST_Response $response, string $code, string $message, int $lines, string $label ): void {
		$data = $response->get_data();
		$this->same( [ 400, $code, $message ], [ $response->get_status(), $data['code'] ?? null, $data['message'] ?? null ], "{$label}: 400 {$code} with its existing message" );
		$this->same( $lines, count( $this->lines() ), "{$label}: cart unchanged" );
	}

	private function clear( ?int $keep = null ): void {
		WC()->cart->empty_cart( false );
		wc_clear_notices();
		WC()->cart->cart_context = 'shortcode';
		if ( null !== $keep ) { WC()->cart->add_to_cart( $keep, 1 ); }
	}

	private function wc_product( int $play ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE bingo_play_product_id = %d', CatalogMigrations::play_products_table(), $play ) );
	}

	private function package_product( int $package ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE package_id = %d', CatalogMigrations::packages_table(), $package ) );
	}

	/** A published simple product created with an exact, unused post ID (WordPress's own `import_id`). */
	private function product_with_id( int $id, string $name, int $category ): int {
		$post = wp_insert_post( [ 'import_id' => $id, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => $name ], true );
		$product = is_wp_error( $post ) || $id !== (int) $post ? null : wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) { throw new RuntimeException( 'Colliding product fixture failed.' ); }
		$product->set_regular_price( '4.00' );
		$product->set_virtual( true );
		$product->save();
		wp_set_object_terms( $id, [ $category ], 'product_cat' );
		return $id;
	}

	/** @return array<int,?array<int,mixed>> Each product's saved status, catalog visibility, stock, prices and modification time. */
	private function product_states( array $ids ): array {
		$states = [];
		foreach ( array_unique( $ids ) as $id ) {
			clean_post_cache( $id );
			$x = wc_get_product( $id );
			$states[ $id ] = $x instanceof WC_Product ? [ $x->get_status( 'edit' ), $x->get_catalog_visibility( 'edit' ), $x->get_stock_status( 'edit' ), $x->get_stock_quantity( 'edit' ), $x->get_regular_price( 'edit' ), $x->get_sale_price( 'edit' ), $x->get_price( 'edit' ), $x->get_date_modified( 'edit' ) ? $x->get_date_modified( 'edit' )->getTimestamp() : null ] : null;
		}
		ksort( $states );
		return $states;
	}

	/** @return array{0:int,1:array<int,string>,2:string} One real php-cgi GET of a Shop listing: status, listed product titles and body. */
	private function shop_titles( array $hall, string $path, string $query ): array {
		[ $status, $body ] = $this->cgi( $hall, $path, $query );
		$dom      = new DOMDocument();
		$internal = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8">' . $body, LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $internal ); }
		$titles = array_map( static fn( DOMElement $h ): string => trim( $h->textContent ), iterator_to_array( ( new DOMXPath( $dom ) )->query( '//ul[contains(concat(" ", normalize-space(@class), " "), " products ")]/li//*[contains(concat(" ", normalize-space(@class), " "), " woocommerce-loop-product__title ")]' ) ) );
		return [ $status, $titles, $body ];
	}

	/** @return array{0:int,1:string} One real GET through tests/homepage-session-purchase-request.php, as the browser check sends it. */
	private function cgi( array $hall, string $path, string $query = '' ): array {
		$worker = __DIR__ . '/homepage-session-purchase-request.php';
		$env    = [
			'PATH' => (string) getenv( 'PATH' ), 'BC_TEST_DB' => 'bingocommerce_state_recon', 'BC_FIXTURE_CGI' => '1', 'BC_FIXTURE_WOOCOMMERCE' => '1',
			'BC_HSP_SITE' => (string) $hall['site'], 'BC_HSP_OWNER' => $this->token, 'BC_HSP_CART_PATH' => (string) wp_parse_url( $hall['cart_url'], PHP_URL_PATH ),
			'BC_HSP_SHOP_PATH' => (string) wp_parse_url( $hall['shop_url'], PHP_URL_PATH ), 'BC_HSP_CATEGORY_PATH' => (string) wp_parse_url( $hall['category_url'], PHP_URL_PATH ),
			'HTTP_HOST' => $hall['host'], 'SERVER_NAME' => $hall['host'], 'SERVER_PORT' => '80', 'REQUEST_URI' => $path . ( '' !== $query ? '?' . $query : '' ),
			'QUERY_STRING' => $query, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'SCRIPT_FILENAME' => $worker, 'REDIRECT_STATUS' => '1',
		];
		[ $exit, $out, $err ] = $this->process( [ dirname( PHP_BINARY ) . '/php-cgi', $worker ], $env, 180 );
		if ( 0 !== $exit ) { throw new RuntimeException( 'Shop request refused (' . $exit . '): ' . trim( $err ) ); }
		$split = strpos( $out, "\r\n\r\n" );
		$gap   = 4;
		if ( false === $split ) { $split = strpos( $out, "\n\n" ); $gap = 2; }
		$head = false === $split ? $out : substr( $out, 0, $split );
		return [ preg_match( '/^Status:\s*(\d{3})/mi', $head, $match ) ? (int) $match[1] : 200, false === $split ? '' : substr( $out, $split + $gap ) ];
	}

	/** @return array<string,array{0:string,1:string,2:string}> Store API price, regular_price and sale_price per cart line, as the Cart block reads them. */
	private function store_prices(): array {
		$data   = json_decode( (string) wp_json_encode( $this->store( '/cart', null, 'GET', null )->get_data() ), true );
		$prices = [];
		foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
			$x   = $item['extensions']['bingocommerce-selection'] ?? [];
			$key = 'addon' === ( $x['role'] ?? null ) ? 'addon:' . $x['addon_id'] : ( 'package' === ( $x['role'] ?? null ) ? 'package:' . $x['package_id'] : 'merch:' . $item['id'] );
			$prices[ $key ] = [ (string) $item['prices']['price'], (string) $item['prices']['regular_price'], (string) $item['prices']['sale_price'] ];
		}
		ksort( $prices );
		return $prices;
	}

	private function line_key( array $item ): string {
		$meta = $item[ CartSelection::META ] ?? [];
		if ( 'addon' === ( $meta['role'] ?? null ) ) { return 'addon:' . $meta['addon_id']; }
		return 'package' === ( $meta['role'] ?? null ) ? 'package:' . $meta['package_id'] : 'merch:' . $item['product_id'];
	}

	/** @return array<int,array{0:string,1:string,2:string,3:bool}> Each add-on line's in-memory product: price, regular price, sale price, on sale. */
	private function addon_lines(): array {
		$lines = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( 'addon' === ( $item[ CartSelection::META ]['role'] ?? null ) ) {
				$lines[ $item[ CartSelection::META ]['addon_id'] ] = [ (string) $item['data']->get_price(), (string) $item['data']->get_regular_price(), (string) $item['data']->get_sale_price(), $item['data']->is_on_sale() ];
			}
		}
		ksort( $lines );
		return $lines;
	}

	private function line_product( int $relation ): ?WC_Product {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( 'addon' === ( $item[ CartSelection::META ]['role'] ?? null ) && $relation === $item[ CartSelection::META ]['addon_id'] ) { return $item['data']; }
		}
		return null;
	}

	/** @return array<string,string> Coupon discount per cart line. */
	private function line_discounts(): array {
		$discounts = [];
		foreach ( WC()->cart->get_cart() as $item ) { $discounts[ $this->line_key( $item ) ] = wc_format_decimal( (float) $item['line_subtotal'] - (float) $item['line_total'], 2 ); }
		ksort( $discounts );
		return $discounts;
	}

	/** @return array<string,string> Tax per cart line. */
	private function line_taxes(): array {
		$taxes = [];
		foreach ( WC()->cart->get_cart() as $item ) { $taxes[ $this->line_key( $item ) ] = wc_format_decimal( (float) $item['line_tax'], 2 ); }
		ksort( $taxes );
		return $taxes;
	}

	private function sorted( array $value ): array {
		ksort( $value );
		return $value;
	}

	private function text( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** @param mixed $value */
	private function code( $value ): ?string {
		return is_wp_error( $value ) ? $value->get_error_code() : null;
	}

	/**
	 * A stable snapshot of the main site's WooCommerce session table: its row count
	 * and a hash of every row's full contents in a fixed order, or null when the
	 * table does not exist.
	 *
	 * @return array{rows:int,hash:string}|null
	 */
	private function main_sessions(): ?array {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'woocommerce_sessions';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return null; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY session_id', $table ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Main session snapshot failed.' ); }
		return [ 'rows' => count( $rows ), 'hash' => hash( 'sha256', serialize( $rows ) ) ];
	}

	private function artifacts(): string {
		$directory = (string) getenv( 'BC_SESSION_PURCHASE_ARTIFACTS' );
		if ( '' === $directory ) {
			$directory = '/private/tmp/bingocommerce-session-purchase-' . substr( $this->token, 0, 8 );
			if ( ! is_dir( $directory ) ) { mkdir( $directory, 0700, true ); }
		}
		$real = realpath( $directory );
		if ( false === $real || ! str_starts_with( $real, '/private/tmp/' ) ) { throw new RuntimeException( 'Artifacts require an existing /private/tmp directory.' ); }
		return $real;
	}

	private function process( array $command, array $env, int $timeout ): array {
		$process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch ' . basename( (string) $command[1] ) . '.' ); }
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out      = '';
		$err      = '';
		$deadline = microtime( true ) + $timeout;
		do {
			$out   .= (string) stream_get_contents( $pipes[1] );
			$err   .= (string) stream_get_contents( $pipes[2] );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) { break; }
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		if ( $status['running'] ) { proc_terminate( $process, 9 ); }
		$out .= (string) stream_get_contents( $pipes[1] );
		$err .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$closed = proc_close( $process );
		$exit   = $status['running'] ? 124 : ( $status['exitcode'] >= 0 ? $status['exitcode'] : $closed );
		return [ (int) $exit, $out, $err ];
	}

	private function snapshot( int $site ): array {
		global $wpdb;
		$result = [];
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows );
			sort( $encoded );
			$result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result );
		return $result;
	}

	private function cleanup(): void {
		global $wpdb;
		$this->group( 'Cleanup' );
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( $this->sites as $site ) {
			$this->own( $site );
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables removed (sessions included)' );
		}
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( $this->users as $user ) {
			if ( $user === $this->admin || $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Fixture customer ownership refused.' ); }
			wpmu_delete_user( $user );
			clean_user_cache( $user );
			$this->same( [ null, '0' ], [ $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->users, $user ) ), (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->usermeta, $user ) ) ], 'owned disposable customer and all its user metadata deleted (persistent cart and session tokens included)' );
		}
	}

	private function group( string $name ): void {
		$this->group = $name;
	}

	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) );
	}

	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS' : 'FAIL' ) . ' [' . $this->group . '] ' . $label . "\n";
	}
}

( new HomepageSessionPurchaseTest() )->run();
