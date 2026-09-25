<?php

/**
 * BR-081 real WooCommerce guest-cart integration. No mocks of domain services.
 * BC_TEST_DB=bingocommerce_state_recon php tests/customer-cart-increment-1.php
 * All writes/cleanup are restricted to token-owned disposable fixture Halls.
 */
if ( 'cli' !== PHP_SAPI || ! in_array( getenv( 'BC_TEST_DB' ), [ 'bingocommerce_state_recon', 'bingocommerce_state_test_suites' ], true ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and an isolated suite database are required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CustomerCartCatalog;
use BingoCommerce\Catalog\Migrations as CatalogMigrations;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Sessions\CartSelection;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\CustomerPackageEligibility;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;

final class CustomerCartIncrementOneTest {
	private array $sites = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		try {
			$a = $this->fixture( 'A', '3.25' );
			$b = $this->fixture( 'B', '8.50' );
			$b_before = $this->snapshot( $b['site'] );
			switch_to_blog( $a['site'] );
			wp_set_current_user( 0 );
			WC()->initialize_session();
			WC()->initialize_cart();
			$this->same( 0, get_current_user_id(), 'customer really signed out' );
			$this->same( false, current_user_can( 'bc_manage_products' ), 'guest has no Manager capability' );
			$this->same( true, CustomerPackageEligibility::is_available( $a['session'], $a['package'] ), 'eligible generated occurrence is preserved' );
			$this->ok( is_wp_error( ( new PackageAddonService() )->listForPackage( $a['package'] ) ), 'management add-on read remains denied' );
			$this->ok( is_wp_error( ( new PackageService() )->getById( $a['package'] ) ), 'management Package read remains denied' );
			$this->selection_cases( $a );
			$this->restoration_cases( $a );
			$this->store_api_cases( $a );
			$this->same( $a['package'], $b['package'], 'two Halls have colliding Package IDs' );
			$this->same( $a['session'], $b['session'], 'two Halls have colliding occurrence IDs' );
			$this->same( $a['addon'], $b['addon'], 'two Halls have colliding relationship IDs' );
			$this->same( $a['addon_wc'], $b['addon_wc'], 'two Halls have colliding Woo product IDs' );
			$this->clear();
			CustomerCart::add( $this->input( $a ) );
			$cart_a = WC()->cart->cart_contents;
			switch_to_blog( $b['site'] );
			$this->ok( CartSelection::inspect( $cart_a )['errors']->has_errors(), 'Hall A cart rejected in Hall B despite colliding IDs' );
			$this->same( '8.50', CustomerCartCatalog::addon( $b['package'], $b['addon'] )['unit_price'], 'Hall B resolves only its relationship price' );
			restore_current_blog();
			$this->same( '3.25', CustomerCartCatalog::addon( $a['package'], $a['addon'] )['unit_price'], 'switch back resolves Hall A without cached B values' );
			$this->ok( is_wp_error( CartSelection::resolve( $this->input( $a ) + [ 'site_id' => $b['site'] ] ) ), 'request cannot select a different Hall' );
			$this->ok( is_wp_error( CustomerCartCatalog::addon( $a['package'], $b['only_b_addon'] ) ), 'relationship existing only in Hall B not found in Hall A' );
			$this->same( $b_before, $this->snapshot( $b['site'] ), 'Hall B remains byte-identical' );
			$this->two_addon_removal_and_undo( $a );
			$this->same( 0, get_current_user_id(), 'all purchase cases finished signed out' );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Customer cart: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function selection_cases( array $f ): void {
		$this->clear();
		$merch = WC()->cart->add_to_cart( $f['merch'], 10 );
		$this->ok( is_string( $merch ), '10 ordinary daubers allowed independently' );
		$this->same( false, WC()->cart->add_to_cart( $f['package_wc'] ), 'classic/direct WC Package add without selection denied' );
		$this->same( false, WC()->cart->add_to_cart( $f['addon_wc'] ), 'Play Product direct add denied despite regular projection' );
		$this->same( false, WC()->cart->add_to_cart( $f['base_wc'] ), 'base-only Play Product cannot be bought alone' );
		$request_before = $_REQUEST;
		try {
			foreach ( [ $f['package_wc'], $f['addon_wc'] ] as $product ) {
				$_REQUEST = [ 'add-to-cart' => (string) $product, 'quantity' => '1' ];
				WC_Form_Handler::add_to_cart_action();
				$this->same( [ $merch ], array_keys( WC()->cart->cart_contents ), 'real classic form handler refuses direct Bingo add' );
			}
		} finally { $_REQUEST = $request_before; }
		$this->ok( wc_get_product( $f['addon_wc'] )->is_purchasable(), 'Play Product not globally made non-purchasable' );
		foreach ( [
			'unassigned Package' => array_replace( $this->input( $f ), [ 'package_id' => $f['unassigned'] ] ),
			'missing occurrence' => array_replace( $this->input( $f ), [ 'session_id' => 999999 ] ),
			'fractional ID' => array_replace( $this->input( $f ), [ 'session_id' => 1.5 ] ),
			'excess add-on' => $this->input( $f, 4 ),
			'fractional add-on' => $this->input( $f, 1.5 ),
			'zero add-on' => $this->input( $f, 0 ),
			'price injection' => array_replace( $this->input( $f ), [ 'price' => '0.00' ] ),
			'duplicate add-on' => array_replace( $this->input( $f ), [ 'addons' => [ $this->input( $f )['addons'][0], $this->input( $f )['addons'][0] ] ] ),
		] as $label => $input ) {
			$this->ok( is_wp_error( CustomerCart::add( $input ) ), $label . ' rejected' );
			$this->same( [ $merch ], array_keys( WC()->cart->cart_contents ), $label . ' leaves merchandise intact' );
		}
		$this->manager( fn() => ( new BingoPlayProductService() )->archive( $f['base'] ) );
		$this->ok( is_wp_error( CustomerCart::add( $this->input( $f ) ) ), 'archived required base rejected under BR-080' );
		$this->manager( fn() => ( new BingoPlayProductService() )->restore( $f['base'] ) );
		$parent = CustomerCart::add( $this->input( $f, 2 ) );
		$this->ok( is_string( $parent ), 'restored component permits guest selection' );
		$keys = array_keys( WC()->cart->cart_contents ); $child = end( $keys );
		$this->same( [ $merch, $parent, $child ], $keys, 'itemized add-on follows its Package; merchandise independent' );
		$this->same( $parent, WC()->cart->cart_contents[ $child ][ CartSelection::META ]['parent_key'], 'add-on linked to exact parent key' );
		$this->same( 1, WC()->cart->cart_contents[ $parent ]['quantity'], 'Package quantity one' );
		$this->same( '10.00', WC()->cart->cart_contents[ $parent ]['data']->get_price(), 'Package uses current Woo price' );
		$this->same( '3.25', WC()->cart->cart_contents[ $child ]['data']->get_price(), 'add-on uses relationship price, not Woo sale price' );
		$this->same( '1', wc_get_product( $f['addon_wc'] )->get_price(), 'standalone Woo price unmodified' );
		$this->ok( ! CartSelection::inspect( WC()->cart->get_cart() )['errors']->has_errors(), 'Paper Package with Paper add-on and merchandise is valid' );
		$this->ok( is_wp_error( CustomerCart::add( $this->input( $f ) ) ), 'duplicate same Package rejected' );
		$this->ok( is_wp_error( CustomerCart::add( array_replace( $this->input( $f ), [ 'package_id' => $f['other'], 'addons' => [] ] ) ) ), 'different Package rejected while first present' );
		$this->same( false, apply_filters( 'woocommerce_update_cart_validation', true, $parent, WC()->cart->cart_contents[ $parent ], 2 ), 'classic update rejects Package quantity two' );
		$post_before = $_POST;
		try {
			$_POST['cart'][ $child ]['qty'] = '1.5';
			$this->same( false, apply_filters( 'woocommerce_update_cart_validation', true, $child, WC()->cart->cart_contents[ $child ], 1 ), 'classic raw fraction cannot be truncated into a valid quantity' );
		} finally { $_POST = $post_before; }
		WC()->cart->set_quantity( $child, 4 );
		$this->same( 2, WC()->cart->cart_contents[ $child ]['quantity'], 'direct quantity setter cannot exceed relation maximum' );
		$this->change_addon( $f, '4.75', 3, true );
		WC()->cart->calculate_totals();
		$this->same( '4.75', WC()->cart->cart_contents[ $child ]['data']->get_price(), 'totals refresh current authoritative add-on price' );
		$this->manager( function () use ( $f ) { $p = wc_get_product( $f['package_wc'] ); $p->set_regular_price( '12.00' ); $p->save(); } );
		WC()->cart->calculate_totals();
		$this->same( '12.00', WC()->cart->cart_contents[ $parent ]['data']->get_price(), 'totals refresh current Package price' );
		$this->change_addon( $f, '3.25', 1, true );
		$this->ok( CartSelection::inspect( WC()->cart->get_cart() )['errors']->has_errors(), 'lowered maximum makes existing selection invalid' );
		( new CartController() )->normalize_cart();
		$this->same( 2, WC()->cart->cart_contents[ $child ]['quantity'], 'Store API normalization does not silently shrink stale selected add-ons' );
		$this->change_addon( $f, '3.25', 3, false );
		$this->ok( CartSelection::inspect( WC()->cart->get_cart() )['errors']->has_errors(), 'disabled relationship invalidates cart' );
		WC()->cart->set_quantity( $merch, 11 );
		$this->same( 11, WC()->cart->cart_contents[ $merch ]['quantity'], 'merchandise quantity independent of a stale Bingo selection' );
		$this->change_addon( $f, '3.25', 3, true );
		WC()->cart->remove_cart_item( $parent );
		$this->same( [ $merch ], array_keys( WC()->cart->cart_contents ), 'parent removal cascades add-ons only' );
		WC()->cart->restore_cart_item( $parent );
		$this->ok( isset( WC()->cart->cart_contents[ $parent ], WC()->cart->cart_contents[ $child ] ), 'eligible parent undo restores its complete removed selection' );
		WC()->cart->remove_cart_item( $child );
		$this->ok( ! CartSelection::inspect( WC()->cart->cart_contents )['errors']->has_errors(), 'explicit optional add-on removal updates parent selection manifest' );
		$shirt = WC()->cart->add_to_cart( $f['shirt'], 1 );
		WC()->cart->restore_cart_item( $child );
		$this->ok( ! CartSelection::inspect( WC()->cart->cart_contents )['errors']->has_errors(), 'eligible child undo restores parent selection manifest' );
		$this->same( [ $merch, $parent, $child, $shirt ], array_keys( WC()->cart->cart_contents ), 'restored add-on remains beneath parent, with daubers and shirt independent' );
		WC()->cart->remove_cart_item( $shirt );
		WC()->cart->remove_cart_item( $parent );
		WC()->cart->restore_cart_item( $child );
		$this->same( [ $merch ], array_keys( WC()->cart->cart_contents ), 'orphan add-on undo rejected' );
		$replacement = CustomerCart::add( [ 'session_id' => $f['session'], 'package_id' => $f['other'], 'addons' => [] ] );
		$this->ok( is_string( $replacement ), 'replacement allowed after explicit removal' );
		WC()->cart->restore_cart_item( $parent );
		$this->same( [ $merch, $replacement ], array_keys( WC()->cart->cart_contents ), 'old Package undo cannot create second Package' );
		$this->clear();
		// Inject incompatible stored state only in this owned fixture; production writers prevent it.
		global $wpdb;
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'medium' => 'digital', 'game_type_id' => $f['game_type'] ], [ 'bingo_play_product_id' => $f['addon_play'] ] );
		$this->ok( is_wp_error( CustomerCart::add( $this->input( $f ) ) ), 'Paper Package rejects Digital add-on even with enabled relationship' );
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'medium' => 'paper', 'game_type_id' => null ], [ 'bingo_play_product_id' => $f['addon_play'] ] );
		$break = static fn( $sql ) => str_contains( $sql, 'UNION ALL SELECT' ) ? 'SELECT * FROM bc_cart_missing_dependency' : $sql;
		$previous = $wpdb->suppress_errors( true ); add_filter( 'query', $break );
		try {
			$this->same( false, WC()->cart->add_to_cart( $f['addon_wc'] ), 'unreadable classification fails closed, never regular fallback' );
			$this->ok( is_wp_error( CartSelection::classification( $f['merch'] ) ), 'unknown Catalog does not misclassify merchandise' );
		} finally { remove_filter( 'query', $break ); $wpdb->suppress_errors( $previous ); }
		$this->manager( function () use ( $f ) { $p = wc_get_product( $f['addon_wc'] ); $p->set_stock_status( 'outofstock' ); $p->save(); } );
		$this->ok( is_wp_error( CustomerCart::add( $this->input( $f ) ) ), 'native stock failure rejects full selection' );
		$this->same( [], WC()->cart->cart_contents, 'failed child add rolls back new Package' );
		$this->same( [], WC()->cart->removed_cart_contents, 'failed add cannot be undone into cart' );
		$this->manager( function () use ( $f ) { $p = wc_get_product( $f['addon_wc'] ); $p->set_stock_status( 'instock' ); $p->set_regular_price( '' ); $p->set_sale_price( '' ); $p->save(); } );
		$this->ok( is_string( CustomerCart::add( $this->input( $f ) ) ), 'relationship price permits add-on with no standalone Woo price' );
		$this->same( '', wc_get_product( $f['addon_wc'] )->get_price(), 'empty saved Woo price remains untouched' );
		$this->clear();
		$this->manager( function () use ( $f ) { $p = wc_get_product( $f['addon_wc'] ); $p->set_sold_individually( true ); $p->save(); } );
		$this->ok( is_wp_error( CustomerCart::add( $this->input( $f, 2 ) ) ), 'Woo sold-individually clamping cannot silently change selected quantity' );
		$this->same( [], WC()->cart->cart_contents, 'clamped add rollback leaves no partial selection' );
		$this->manager( function () use ( $f ) { $p = wc_get_product( $f['addon_wc'] ); $p->set_sold_individually( false ); $p->save(); } );
	}

	private function two_addon_removal_and_undo( array $f ): void {
		$this->clear();
		// Add the second relationship only after the existing cross-Hall ID checks.
		[ $second_wc, $second_addon ] = $this->manager( function () use ( $f ) {
			$product = $this->product();
			$play = ( new BingoPlayProductService() )->create( $product, 'paper', 1, null );
			if ( is_wp_error( $play ) ) { throw new RuntimeException( 'Second Paper Play Product fixture failed.' ); }
			$addon = ( new PackageAddonService() )->create( $f['package'], $play->play_product['bingo_play_product_id'], '6.50', 3 );
			if ( is_wp_error( $addon ) ) { throw new RuntimeException( 'Second Paper add-on fixture failed.' ); }
			return [ $product, (int) $addon->package_addon['package_addon_id'] ];
		} );
		$this->same( 0, get_current_user_id(), 'two-add-on scenario runs signed out' );
		$merch = WC()->cart->add_to_cart( $f['merch'], 10 );
		$input = $this->input( $f, 2 );
		$input['addons'][] = [ 'package_addon_id' => $second_addon, 'quantity' => 3 ];
		$parent = CustomerCart::add( $input );
		$this->ok( is_string( $parent ), 'guest adds two distinct eligible Paper add-ons to one Paper Package' );
		if ( ! is_string( $parent ) ) { throw new RuntimeException( 'Two-add-on selection failed.' ); }
		$children = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( 'addon' === ( $item[ CartSelection::META ]['role'] ?? null ) ) { $children[ $item[ CartSelection::META ]['addon_id'] ] = $key; }
		}
		$this->same( [ $f['addon'], $second_addon ], array_keys( $children ), 'each selected relationship has its own cart line' );
		if ( 2 !== count( $children ) ) { throw new RuntimeException( 'Expected two separate add-on lines.' ); }
		$first = $children[ $f['addon'] ]; $second = $children[ $second_addon ];
		$shirt = WC()->cart->add_to_cart( $f['shirt'], 1 );
		$this->same( [ $merch, $parent, $first, $second, $shirt ], array_keys( WC()->cart->get_cart() ), 'both add-ons appear beneath their Package between independent merchandise' );
		$expected = [
			$merch => [ $f['merch'], 10, '10.00', null, null ],
			$parent => [ $f['package_wc'], 1, wc_format_decimal( wc_get_product( $f['package_wc'] )->get_price(), 2 ), '', 0 ],
			$first => [ $f['addon_wc'], 2, '3.25', $parent, $f['addon'] ],
			$second => [ $second_wc, 3, '6.50', $parent, $second_addon ],
			$shirt => [ $f['shirt'], 1, '10.00', null, null ],
		];
		$assert_cart = function ( array $expected, string $label ): void {
			WC()->cart->calculate_totals();
			do_action( 'woocommerce_check_cart_items' );
			$this->same( 0, wc_notice_count( 'error' ), $label . ': real Woo cart validation accepts the remaining selection' );
			$actual = [];
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				$actual[ $key ] = [ $item['product_id'], $item['quantity'], wc_format_decimal( $item['data']->get_price(), 2 ), $item[ CartSelection::META ]['parent_key'] ?? null, $item[ CartSelection::META ]['addon_id'] ?? null ];
			}
			ksort( $expected ); ksort( $actual );
			$this->same( $expected, $actual, $label . ': exact lines, relationship prices, quantities and merchandise preserved' );
		};
		$assert_cart( $expected, 'both add-ons selected' );
		$this->same( true, WC()->cart->remove_cart_item( $first ), 'Woo removes only the first add-on' );
		$assert_cart( array_diff_key( $expected, [ $first => true ] ), 'first add-on removed' );
		$this->same( true, WC()->cart->restore_cart_item( $first ), 'Woo undoes first add-on removal' );
		$assert_cart( $expected, 'first add-on restored alongside second' );
		$this->same( true, WC()->cart->remove_cart_item( $parent ), 'Woo removes Package and both dependent add-ons' );
		$assert_cart( array_intersect_key( $expected, [ $merch => true, $shirt => true ] ), 'Package removed' );
		$this->same( true, WC()->cart->restore_cart_item( $parent ), 'Woo undoes Package removal' );
		$assert_cart( $expected, 'Package and both add-ons restored' );
	}

	private function restoration_cases( array $f ): void {
		$this->clear();
		$parent = CustomerCart::add( $this->input( $f, 2 ) );
		$stored = ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session();
		$this->reload( $stored );
		$this->same( 2, count( WC()->cart->cart_contents ), 'real WC session restore retains Package and no-standalone-price add-on' );
		$this->ok( ! CartSelection::inspect( WC()->cart->cart_contents )['errors']->has_errors(), 'restored valid selection still validates signed out' );
		$missing_child = [ $parent => $stored[ $parent ] ];
		$this->reload( $missing_child );
		$this->blocked( 'missing selected add-on cannot silently become a Package-only cart' );
		$this->reload( $stored );
		$forged = $stored; unset( $forged[ $parent ] );
		$this->reload( $forged );
		$this->blocked( 'orphan restored add-on' );
		$this->reload( $stored );
		$forged = $stored; unset( $forged[ $parent ][ CartSelection::META ] );
		$this->reload( $forged );
		$this->blocked( 'restored naked Package' );
		$forged = $stored;
		foreach ( $forged as &$item ) { if ( 'addon' === $item[ CartSelection::META ]['role'] ) { unset( $item[ CartSelection::META ] ); } } unset( $item );
		$this->reload( $forged ); $this->blocked( 'restored naked Play Product or its incomplete parent' );
		$forged = $stored; $forged[ $parent ]['quantity'] = 2;
		$this->reload( $forged ); $this->blocked( 'restored multi-quantity Package' );
		$forged = $stored; $copy = $forged[ $parent ]; $copy['key'] = md5( 'second-package' ); $forged[ $copy['key'] ] = $copy;
		$this->reload( $forged ); $this->blocked( 'two restored Package lines' );
		$this->reload( $stored );
		$this->manager( fn() => ( new SessionService() )->setAvailableForSale( $f['session'], false ) );
		$this->blocked( 'sales OFF stale cart' );
		$this->manager( fn() => ( new SessionService() )->setAvailableForSale( $f['session'], true ) );
		$this->manager( fn() => ( new BingoPlayProductService() )->archive( $f['base'] ) );
		$this->blocked( 'archived base stale cart' );
		$this->manager( fn() => ( new BingoPlayProductService() )->restore( $f['base'] ) );
		global $wpdb;
		$break = static fn( $sql ) => str_contains( $sql, 'bingo_package_addons' ) && str_starts_with( $sql, 'SELECT' ) ? 'SELECT * FROM bc_cart_missing_dependency' : $sql;
		$previous = $wpdb->suppress_errors( true ); add_filter( 'query', $break );
		try {
			$this->reload( $stored );
			$this->blocked( 'unreadable add-on on real session load' );
		} finally { remove_filter( 'query', $break ); $wpdb->suppress_errors( $previous ); }
		$this->reload( $stored );
		$forged = $stored;
		foreach ( $forged as &$item ) { if ( 'addon' === $item[ CartSelection::META ]['role'] ) { $item['quantity'] = 1.5; } } unset( $item );
		$this->reload( $forged ); WC()->cart->cart_context = 'store-api';
		( new CartController() )->normalize_cart();
		$this->blocked( 'Store API cannot normalize a restored fractional selection into validity' );
		$this->clear();
	}

	private function store_api_cases( array $f ): void {
		$this->clear();
		$missing = $this->request( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $this->input( $f ) ], false );
		$this->same( 401, $missing->get_status(), 'real Store API route rejects missing nonce' );
		$this->same( [], WC()->cart->cart_contents, 'missing nonce caused no cart mutation' );
		foreach ( [ $f['package_wc'], $f['addon_wc'] ] as $product ) {
			$response = $this->request( '/cart/add-item', [ 'id' => $product, 'quantity' => 1 ] );
			$this->same( 400, $response->get_status(), 'Store API direct Bingo add rejected' );
		}
		$response = $this->request( '/cart/add-item', [ 'id' => $f['merch'], 'quantity' => 10 ] );
		$this->same( 201, $response->get_status(), 'Store API ordinary merchandise allowed' );
		$response = $this->request( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $this->input( $f, 2 ) ] );
		$this->same( 200, $response->get_status(), 'guest selection accepted through real cart-extension route' );
		$items = json_decode( wp_json_encode( $response->get_data() ), true )['items'] ?? [];
		$this->same( 3, count( $items ), 'Store API response includes three separate cart lines' );
		if ( 3 !== count( $items ) ) { throw new RuntimeException( wp_json_encode( $response->get_data() ) ); }
		$parent = $items[1]['key']; $child = $items[2]['key'];
		$this->same( $parent, $items[2]['extensions']['bingocommerce-selection']['parent_key'] ?? null, 'Store API exposes exact parent linkage read-only' );
		foreach ( [ [ $parent, 2 ], [ $child, 4 ], [ $child, 1.5 ] ] as [ $key, $quantity ] ) {
			$this->same( 400, $this->request( '/cart/update-item', [ 'key' => $key, 'quantity' => $quantity ] )->get_status(), 'Store API rejects invalid quantity' );
		}
		$alias = new WP_REST_Request( 'POST', '/wc/store/cart/update-item' );
		$alias->set_body_params( [ 'key' => $child, 'quantity' => 1.5 ] ); $alias->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$this->same( 400, rest_get_server()->dispatch( $alias )->get_status(), 'unversioned Store API alias also rejects fractional quantity' );
		$batch = new WP_REST_Request( 'POST', '/batch/v1' );
		$batch->set_body_params( [ 'requests' => [ [ 'method' => 'POST', 'path' => '/wc/store/v1/cart/update-item', 'headers' => [ 'Nonce' => wp_create_nonce( 'wc_store_api' ) ], 'body' => [ 'key' => $child, 'quantity' => 1.5 ] ] ] ] );
		$batch_data = json_decode( wp_json_encode( rest_get_server()->dispatch( $batch )->get_data() ), true );
		$this->same( 400, $batch_data['responses'][0]['status'] ?? null, 'REST batch preserves and rejects original fractional quantity' );
		$this->same( 200, $this->request( '/cart/update-item', [ 'key' => $child, 'quantity' => 3 ] )->get_status(), 'Store API valid add-on quantity accepted' );
		$this->same( 200, $this->request( '/cart/remove-item', [ 'key' => $parent ] )->get_status(), 'Store API parent removal accepted' );
		$this->same( 1, count( WC()->cart->cart_contents ), 'Store API cascade leaves only merchandise' );
	}

	private function blocked( string $label ): void {
		wc_clear_notices(); do_action( 'woocommerce_check_cart_items' );
		$this->ok( wc_notice_count( 'error' ) > 0, $label . ' blocked in classic validation' );
		$errors = new WP_Error(); do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
		$this->ok( $errors->has_errors(), $label . ' blocked in Store API validation' );
	}
	private function request( string $route, array $data, bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1' . $route );
		$request->set_body_params( $data );
		if ( $nonce ) { $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) ); }
		return rest_get_server()->dispatch( $request );
	}
	private function reload( array $stored ): void {
		WC()->cart->cart_contents = []; WC()->session->set( 'cart', $stored ); WC()->session->set( 'cart_totals', null );
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
	}
	private function clear(): void { WC()->cart->empty_cart( false ); wc_clear_notices(); WC()->cart->cart_context = 'shortcode'; }
	private function input( array $f, mixed $quantity = 1 ): array { return [ 'session_id' => $f['session'], 'package_id' => $f['package'], 'addons' => [ [ 'package_addon_id' => $f['addon'], 'quantity' => $quantity ] ] ]; }
	private function manager( callable $callback ): mixed {
		wp_set_current_user( $this->admin );
		try { return $callback(); } finally { wp_set_current_user( 0 ); }
	}
	private function change_addon( array $f, string $price, int $maximum, bool $enabled ): void {
		$this->manager( function () use ( $f, $price, $maximum, $enabled ) {
			$service = new PackageAddonService(); $row = $service->listForPackage( $f['package'] )[0];
			if ( is_wp_error( $service->update( $f['addon'], $row['fingerprint'], $price, $maximum, $enabled ) ) ) { throw new RuntimeException( 'Fixture add-on update failed.' ); }
		} );
	}

	private function fixture( string $label, string $price ): array {
		global $wpdb;
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'cart-' . strtolower( $label ) . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Cart Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[ $site ] = true; switch_to_blog( $site ); update_option( 'bc_cart_test_owner', $this->token );
		try {
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			update_option( 'timezone_string', 'America/New_York' );
			$base_wc = $this->product(); $base = ( new BingoPlayProductService() )->create( $base_wc, 'paper', 2, null )->play_product['bingo_play_product_id'];
			$package_wc = $this->product(); $packages = new PackageService();
			$package = $packages->create( $package_wc, [ [ 'bingo_play_product_id' => $base, 'quantity' => 1 ] ] )->package['package_id'];
			$other = $packages->create( $this->product(), [ [ 'bingo_play_product_id' => $base, 'quantity' => 1 ] ] )->package['package_id'];
			$unassigned = $packages->create( $this->product(), [ [ 'bingo_play_product_id' => $base, 'quantity' => 1 ] ] )->package['package_id'];
			$addon_wc = $this->product(); $addon_play = ( new BingoPlayProductService() )->create( $addon_wc, 'paper', 1, null )->play_product['bingo_play_product_id'];
			$addon = ( new PackageAddonService() )->create( $package, $addon_play, $price, 3 )->package_addon['package_addon_id'];
			$p = wc_get_product( $addon_wc ); $p->set_sale_price( '1' ); $p->update_meta_data( 'bc_product_kind', 'regular' ); $p->update_meta_data( 'bc_package_relation', 'none' ); $p->save();
			$merch = $this->product(); $shirt = $this->product();
			$game_type = ( new \BingoCommerce\Catalog\GameTypeService() )->create( 'Fixture Game' )->game_type['game_type_id'];
			$now = current_time( 'mysql', true );
			$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => 80001, 'name' => 'Cart fixture', 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$schedules = new SessionScheduleService();
			$schedule = $schedules->create( 80001, 'dates', '18:00', [ $date ], [ $package, $other ] );
			if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Fixture schedule creation failed.' ); }
			$schedules->generate( (int) $schedule->schedule['schedule_id'] );
			$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
			if ( is_wp_error( ( new SessionService() )->setAvailableForSale( $session, true ) ) ) { throw new RuntimeException( 'Fixture sales enable failed.' ); }
			$only_b_addon = 0;
			if ( 'B' === $label ) { $only_b_addon = ( new PackageAddonService() )->create( $other, $addon_play, '6.00', 1 )->package_addon['package_addon_id']; }
			return array_map( 'intval', compact( 'site', 'base', 'base_wc', 'package', 'package_wc', 'other', 'unassigned', 'addon', 'addon_wc', 'addon_play', 'merch', 'shirt', 'game_type', 'session', 'only_b_addon' ) );
		} finally { restore_current_blog(); }
	}
	private function product(): int {
		$p = new WC_Product_Simple(); $p->set_name( 'Cart fixture ' . wp_generate_password( 5, false ) ); $p->set_status( 'publish' ); $p->set_regular_price( '10.00' ); $p->set_virtual( true ); return $p->save();
	}
	private function snapshot( int $site ): array {
		global $wpdb; $result = [];
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}
	private function cleanup(): void {
		global $wpdb;
		if ( WC()->cart ) { WC()->cart->empty_cart( false ); }
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( array_keys( $this->sites ) as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_cart_test_owner' ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables cleaned up' );
		}
	}
	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) );
	}
	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	}
}
( new CustomerCartIncrementOneTest() )->run();
