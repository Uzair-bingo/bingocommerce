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
 *
 * Optional: BC_SESSION_PURCHASE_BROWSER=1 with BC_NODE_EXECUTABLE,
 * BC_PLAYWRIGHT_MODULE, BC_CHROME_EXECUTABLE and BC_SESSION_PURCHASE_ARTIFACTS
 * (an existing /private/tmp directory) runs
 * tests/homepage-session-purchase-browser.cjs against real php-cgi requests that
 * boot these Halls directly (tests/homepage-session-purchase-request.php):
 * homepage, cart page (Hall A: the Cart block page WooCommerce's installer
 * creates; Hall B: the classic [woocommerce_cart] shortcode), Shop page, Store
 * API cart read and cart-extension POST, merchandise seeding and WooCommerce
 * cart fragments, with guest cookies carried between requests. No checkout is
 * run.
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
			$this->browser( $a, $b );
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
	private function session( string $name, int $days, array $packages ): int {
		global $wpdb;
		static $next = 0;
		$template = 981000 + ( ++$next );
		$now      = current_time( 'mysql', true );
		$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => $template, 'name' => $name, 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
		$schedules = new SessionScheduleService( $this->audit );
		$schedule  = $schedules->create( $template, 'dates', '18:00', [ self::day( self::NY, $days ) ], $packages );
		if ( ! $schedule instanceof SessionScheduleResult || SessionScheduleResult::STATUS_OK !== $schedule->status ) { throw new RuntimeException( 'Schedule fixture failed.' ); }
		$schedules->generate( (int) $schedule->schedule['schedule_id'] );
		$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
		if ( ! ( new SessionService( $this->audit ) )->setAvailableForSale( $session, true ) instanceof SessionResult ) { throw new RuntimeException( 'Fixture sales ON failed.' ); }
		return $session;
	}

	private static function day( string $zone, int $days ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->setTime( 12, 0 )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
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
			$this->same( [ 'Maximum quantity: 2', 'Quantity: 1' ], array_map( static fn( DOMElement $s ): string => trim( $s->textContent ), iterator_to_array( $xpath->query( $paper_panel . '//span[contains(@class,"addon-card__range")]' ) ) ), '“Maximum quantity: N”; a maximum of one shows a fixed “Quantity: 1”' );
			$this->same( 0, substr_count( $html, 'Up to ' ), 'no “Up to N” wording' );
			$this->same( 1, $xpath->query( $paper_panel . '/p[contains(@class,"bc-addons__note")][normalize-space()="Add-ons are optional. Select an add-on, then choose its quantity."]' )->length, 'add-ons are optional; quantity follows selection' );
			$steps = iterator_to_array( $xpath->query( $paper_panel . '//button[@data-step]' ) );
			$this->ok( 2 === count( $steps ) && array_reduce( $steps, static fn( bool $ok, DOMElement $b ): bool => $ok && 'button' === $b->getAttribute( 'type' ) && '-1' === $b->getAttribute( 'tabindex' ) && $b->hasAttribute( 'disabled' ) && str_ends_with( $b->getAttribute( 'aria-label' ), 'quantity of Extra paper pack' ), true ), 'stepper buttons: labelled, disabled until selected, outside the tab order (the field’s own arrow keys work)' );

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
			$this->same( 0, $xpath->query( $panel( $a['packages']['paper'] ) . '//li[2]//input[@type="number"]' )->length, 'maximum one: no quantity field' );
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
			$this->rejected( $this->extension( $this->selection( $s, $p['digital'], [] ) ), 'bc_electronic_limit_unavailable', 'The Hall electronic-face limit could not be verified. Digital selections cannot be purchased until the configuration is corrected.', 1, 'unreadable electronic-limit configuration' );
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
	 * Optional browser check
	 * ================================================================== */

	private function browser( array $a, array $b ): void {
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
				$halls[ $hall['label'] ] = [
					'site' => $hall['site'], 'host' => $hall['host'], 'cart_page' => $hall['cart_page'], 'cart_url' => $hall['cart_url'], 'cart_path' => (string) wp_parse_url( $hall['cart_url'], PHP_URL_PATH ),
					'shop_url' => $hall['shop_url'], 'shop_path' => (string) wp_parse_url( $hall['shop_url'], PHP_URL_PATH ), 'category_path' => (string) wp_parse_url( $hall['category_url'], PHP_URL_PATH ), 'bingo_products' => $bingo,
					'endpoint' => $model['purchase']['endpoint'], 'cart_endpoint' => $model['purchase']['cart_endpoint'], 'session' => $hall['session'], 'date' => $hall['date'],
					'packages' => $hall['packages'], 'relations' => $hall['relations'], 'merch' => $hall['merch'], 'names' => $names,
					'session_name' => $model['session']['name'], 'session_url' => $model['session']['packages_url'],
					'only_relation' => $hall['only_relation'] ?? null,
				];
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
