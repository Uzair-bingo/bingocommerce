<?php

/**
 * Actual guest Woo orders, offline BACS only, in token-owned disposable Halls.
 * BC_TEST_DB=bingocommerce_state_recon php tests/customer-order-recording.php
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Catalog\Migrations as CatalogMigrations;
use BingoCommerce\Sessions\CartSelection;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\CustomerOrder;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class CustomerOrderRecordingTest {
	private array $sites = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;
	private array $address = [ 'first_name' => 'Fixture', 'last_name' => 'Customer', 'address_1' => '1 Test Street', 'city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US', 'email' => 'guest@example.invalid', 'phone' => '5185550100' ];

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' ); $this->token = wp_generate_uuid4();
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP in order fixtures.' ) );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		try {
			$a = $this->fixture( 'a' ); $b = $this->fixture( 'b' );
			switch_to_blog( $a['site'] ); wp_set_current_user( 0 );
			WC()->initialize_session(); WC()->initialize_cart();
			WC()->customer->set_props( array_combine( array_map( static fn( $key ) => 'billing_' . $key, array_keys( $this->address ) ), $this->address ) );
			$this->same( 0, get_current_user_id(), 'purchase cases run signed out' );
			$this->same( false, current_user_can( 'bc_manage_products' ), 'guest cannot manage Catalog' );
			$this->same( true, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'disposable Hall uses HPOS' );
			$this->same( $a['package'], $b['package'], 'Hall-local Package IDs collide' );
			$this->same( $a['session'], $b['session'], 'Hall-local Session IDs collide' );
			$orders = []; $carts = [];
			foreach ( [ 'classic', 'store' ] as $path ) {
				$this->select( $a );
				$expected = $this->cart_financials();
				$contents = WC()->cart->get_cart();
				$id = $this->checkout( $path );
				$orders[] = $id;
				$carts[ $id ] = $contents;
				$this->assert_order( $id, $a, $expected, $path );
			}
			$this->draft_updates( $a );
			$this->legacy( $a );
			$this->guards( $a, $b );
			$this->history( $a, $orders, $carts );
			$this->clear(); WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 );
			switch_to_blog( $b['site'] ); WC()->session = null; WC()->cart = null; WC()->customer = null;
			WC()->initialize_session(); WC()->initialize_cart();
			WC()->customer->set_props( array_combine( array_map( static fn( $key ) => 'billing_' . $key, array_keys( $this->address ) ), $this->address ) );
			$this->select( $b ); $expected = $this->cart_financials(); $id = $this->checkout( 'store' );
			$this->assert_order( $id, $b, $expected, 'Hall B with colliding local IDs' );
			$this->same( 0, get_current_user_id(), 'all purchase cases finish signed out' );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally { $this->cleanup(); }
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Order recording: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function select( array $f, ?int $session = null ): string {
		$this->clear();
		WC()->cart->add_to_cart( $f['merch'], 10 );
		$parent = CustomerCart::add( [ 'session_id' => $session ?? $f['session'], 'package_id' => $f['package'], 'addons' => [
			[ 'package_addon_id' => $f['first'], 'quantity' => 2 ], [ 'package_addon_id' => $f['second'], 'quantity' => 3 ],
		] ] );
		if ( ! is_string( $parent ) ) { throw new RuntimeException( 'Selection fixture failed: ' . wp_json_encode( $parent ) ); }
		WC()->cart->apply_coupon( $f['coupon'] ); WC()->cart->calculate_totals();
		return $parent;
	}

	private function checkout( string $path ): int {
		$this->same( 0, get_current_user_id(), $path . ': guest at real checkout entry' );
		if ( 'store' === $path ) {
			$response = $this->request( 'POST' ); $data = $response->get_data();
			$this->same( 200, $response->get_status(), 'Store API completes offline checkout ' . ( 200 === $response->get_status() ? '' : wp_json_encode( $data ) ) );
			$id = (int) ( $data['order_id'] ?? 0 );
		} else {
			WC()->cart->cart_context = 'shortcode'; wc_clear_notices(); $id = 0;
			$post = $_POST; $request = $_REQUEST;
			$stop_redirect = static function ( $result, $order_id ) use ( &$id ) {
				$id = (int) $order_id;
				// Offline gateway has returned success and persisted the real order.
				throw new RuntimeException( 'BC_FIXTURE_OFFLINE_SUCCESS_NO_REDIRECT' );
			};
			add_filter( 'woocommerce_payment_successful_result', $stop_redirect, 100, 2 );
			try {
				$_POST = [ 'terms' => '1', 'payment_method' => 'bacs' ];
				foreach ( $this->address as $key => $value ) { $_POST[ 'billing_' . $key ] = $value; }
				$_REQUEST = $_POST + [ 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ) ];
				WC()->checkout()->process_checkout();
			} finally { $_POST = $post; $_REQUEST = $request; remove_filter( 'woocommerce_payment_successful_result', $stop_redirect, 100 ); }
			$this->ok( $id > 0, 'classic offline gateway completed and returned real order ID ' . ( $id ? '' : wp_json_encode( wc_get_notices() ) ) );
		}
		if ( $id < 1 ) { throw new RuntimeException( 'Actual checkout produced no order.' ); }
		$this->same( 'on-hold', wc_get_order( $id )->get_status(), $path . ': BACS awaits offline payment; no card charged' );
		$this->clear();
		return $id;
	}

	private function assert_order( int $id, array $f, array $expected, string $path ): void {
		$order = wc_get_order( $id ); $facts = $order->get_meta( CustomerOrder::META, true );
		$this->same( [], WC()->cart->get_cart(), $path . ': cart is gone before order readback' );
		$this->same( 4, count( $order->get_items() ), $path . ': one Package, two separate add-ons and merchandise persist' );
		$this->same( $f['site'], $facts['site_id'], $path . ': Hall site recorded' );
		$this->same( $f['session'], $facts['session_id'], $path . ': exact dated Session recorded' );
		$this->same( $f['date'], $facts['session_date'], $path . ': exact date recorded' );
		$this->same( $f['name'], $facts['session_name'], $path . ': authoritative occurrence name recorded' );
		$this->same( '18:00:00', $facts['start_time_local'], $path . ': structured time recorded' );
		$this->same( 'America/New_York', $facts['timezone_name'], $path . ': captured occurrence timezone recorded' );
		$this->same( $f['package'], $facts['package_id'], $path . ': authoritative Package recorded' );
		$this->same( [
			[ 'addon_id' => $f['first'], 'bingo_play_product_id' => $f['first_play'], 'wc_product_id' => $f['first_wc'], 'quantity' => 2 ],
			[ 'addon_id' => $f['second'], 'bingo_play_product_id' => $f['second_play'], 'wc_product_id' => $f['second_wc'], 'quantity' => 3 ],
		], $facts['addons'], $path . ': authoritative distinct relationships, Play Products and quantities recorded' );
		$actual = [];
		foreach ( $order->get_items() as $item ) {
			$actual[ $item->get_product_id() ] = $this->item_financials( $item );
			$binding = $item->get_meta( CustomerOrder::META, true );
			if ( $item->get_product_id() === $f['merch'] ) {
				$this->same( '', $binding, $path . ': ordinary merchandise has no Bingo linkage' );
				$this->same( '', $item->get_meta( 'bc_bingo_session', true ), $path . ': merchandise has no Session label' );
				continue;
			}
			$this->same( $facts['selection_id'], $binding['selection_id'], $path . ': line linked by persistent selection UUID within order' );
			$this->same( $facts['site_id'], $binding['site_id'], $path . ': line Hall matches order' );
			$this->same( $facts['package_id'], $binding['package_id'], $path . ': line Package matches order' );
			$this->same( (int) $item->get_quantity(), $binding['quantity'], $path . ': recorded quantity matches native line' );
			$this->same( false, array_key_exists( 'parent_key', $binding ), $path . ': historical linkage does not use cart keys' );
			$html = wc_display_item_meta( $item, [ 'echo' => false ] );
			$this->ok( str_contains( $html, 'Bingo Session' ) && str_contains( $html, ( new DateTimeImmutable( $f['date'] ) )->format( 'm/d/Y' ) ), $path . ': normal Woo item display includes Session date' );
			$this->ok( ! str_contains( $html, 'bc_purchase' ) && ! str_contains( $html, $binding['selection_id'] ), $path . ': technical metadata is not printed' );
			if ( 'addon' === $binding['role'] ) {
				$this->ok( str_contains( $html, 'Add-on for' ) && str_contains( $html, $facts['package_name'] ), $path . ': add-on receipt identifies parent Package' );
				$this->ok( in_array( $binding['addon_id'], [ $f['first'], $f['second'] ], true ), $path . ': child relationship persisted on child line' );
			}
		}
		ksort( $actual ); ksort( $expected );
		$this->same( $expected, $actual, $path . ': native charged subtotals, discounts, taxes and quantities match Woo cart' );
		$this->ok( (float) $order->get_discount_total() > 0 && (float) $order->get_total_tax() > 0, $path . ': assertions include a real coupon discount and nonzero taxes' );
		$email = wc_get_email_order_items( $order, [ 'plain_text' => true, 'sent_to_admin' => false ] );
		$this->ok( str_contains( $email, 'Bingo Session' ) && str_contains( $email, 'Add-on for' ), $path . ': normal Woo plain receipt contains Session and itemized parent labels' );
	}

	private function draft_updates( array $f ): void {
		global $wpdb;
		$parent = $this->select( $f );
		$id = 0;
		$stop = static function ( $order ) use ( &$id ) { $id = $order->get_id(); throw new RouteException( 'bc_fixture_draft', 'Persisted draft before payment.', 418 ); };
		add_action( 'woocommerce_store_api_checkout_update_order_meta', $stop, 200 );
		try {
			$this->same( 418, $this->request( 'POST' )->get_status(), 'real Store POST persists a draft before fixture stops payment' );
			$draft = wc_get_order( $id ); $line_ids = array_keys( $draft->get_items() );
			$this->same( 'checkout-draft', $draft->get_status(), 'draft remains unplaced' );
			WC()->session->set( 'store_api_draft_order', $id );
			$wpdb->update( Migrations::sessions_table(), [ 'display_name' => 'Draft updated name' ], [ 'session_id' => $f['session'] ] );
			$this->same( 418, $this->request( 'POST' )->get_status(), 'second native draft update' );
			$draft = wc_get_order( $id );
			$this->same( $line_ids, array_keys( $draft->get_items() ), 'same-hash draft reuses native line IDs' );
			$this->same( 'Draft updated name', $draft->get_meta( CustomerOrder::META, true )['session_name'], 'same-hash draft refreshes occurrence facts' );
			WC()->cart->remove_cart_item( $parent ); WC()->cart->calculate_totals();
			$this->same( 418, $this->request( 'POST' )->get_status(), 'draft updates after Package removed' );
			$draft = wc_get_order( $id );
			$this->same( '', $draft->get_meta( CustomerOrder::META, true ), 'Package removal clears draft order facts' );
			$this->same( 1, count( $draft->get_items() ), 'only independent merchandise remains in draft' );
			$remaining = array_values( $draft->get_items() )[0];
			$this->same( '', $remaining->get_meta( CustomerOrder::META, true ), 'merchandise does not inherit stale linkage' );
			CustomerCart::add( [ 'session_id' => $f['legacy'], 'package_id' => $f['package'], 'addons' => [] ] );
			WC()->cart->calculate_totals();
			$this->same( 418, $this->request( 'POST' )->get_status(), 'draft updates to another exact Session selection' );
			$draft = wc_get_order( $id );
			$this->same( $f['legacy'], $draft->get_meta( CustomerOrder::META, true )['session_id'], 'new draft selection replaces prior Session identity' );
			$this->same( [], $draft->get_meta( CustomerOrder::META, true )['addons'], 'new draft selection has no stale add-ons' );
		} finally {
			remove_action( 'woocommerce_store_api_checkout_update_order_meta', $stop, 200 );
			$wpdb->update( Migrations::sessions_table(), [ 'display_name' => $f['name'] ], [ 'session_id' => $f['session'] ] );
			$this->clear();
		}
	}

	private function legacy( array $f ): void {
		$this->select( $f, $f['legacy'] );
		$id = $this->checkout( 'store' ); $facts = wc_get_order( $id )->get_meta( CustomerOrder::META, true );
		$this->same( $f['legacy'], $facts['session_id'], 'legacy Session retained exactly' );
		foreach ( [ 'schedule_id', 'start_time_local', 'timezone_name', 'starts_at_utc' ] as $field ) { $this->same( null, $facts[ $field ], 'legacy nullable ' . $field . ' retained; no parsed time' ); }
	}

	private function guards( array $f, array $b ): void {
		global $wpdb;
		foreach ( [ 'missing', 'altered', 'unreadable', 'hall' ] as $case ) {
			$this->select( $f );
			if ( 'hall' === $case ) {
				foreach ( WC()->cart->cart_contents as &$item ) { if ( isset( $item[ CartSelection::META ] ) ) { $item[ CartSelection::META ]['site_id'] = $b['site']; } } unset( $item );
				$this->same( 409, $this->request( 'POST' )->get_status(), 'cross-Hall selection rejected before order creation' );
				continue;
			}
			$change = static function ( $order ) use ( $case ) {
				foreach ( $order->get_items() as $key => $item ) {
					if ( 'addon' === ( $item->get_meta( CustomerOrder::META, true )['role'] ?? null ) ) {
						if ( 'missing' === $case ) {
							// New classic items have temporary keys, not persistent item IDs.
							$remaining = $order->get_items(); unset( $remaining[ $key ] );
							$order->remove_order_items( 'line_item' );
							foreach ( $remaining as $keep ) { $order->add_item( $keep ); }
						}
						if ( 'altered' === $case ) { $item->set_quantity( 9 ); }
						break;
					}
				}
			};
			$break = static fn( $sql ) => str_starts_with( $sql, 'SELECT session_id, session_date, display_name, schedule_id, start_time_local' ) ? 'SELECT * FROM bc_fixture_unreadable_order_facts' : $sql;
			add_action( 'woocommerce_checkout_create_order', $change, 99 );
			if ( 'unreadable' === $case ) { add_filter( 'query', $break ); }
			$old = $wpdb->suppress_errors( true );
			try {
				$result = WC()->checkout()->create_order( [ 'payment_method' => 'bacs' ] );
				$this->ok( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'The Bingo purchase could not be recorded.' ), $case . ': actual classic order creation refuses invalid recorded facts' );
			} finally { remove_action( 'woocommerce_checkout_create_order', $change, 99 ); remove_filter( 'query', $break ); $wpdb->suppress_errors( $old ); }
		}
		$this->clear();
	}

	private function history( array $f, array $orders, array $carts ): void {
		global $wpdb;
		$before = [];
		foreach ( $orders as $id ) { $before[ $id ] = $this->order_snapshot( $id ); }
		wp_set_current_user( $this->admin );
		try {
			$p = wc_get_product( $f['package_wc'] ); $p->set_name( 'Later Package name' ); $p->set_regular_price( '99.00' ); $p->save();
			$p = wc_get_product( $f['first_wc'] ); $p->set_name( 'Later add-on name' ); $p->set_regular_price( '70.00' ); $p->save();
			$wpdb->update( CatalogMigrations::package_addons_table(), [ 'unit_price' => '88.00' ], [ 'package_addon_id' => $f['first'] ] );
			$wpdb->update( Migrations::sessions_table(), [ 'display_name' => 'Later Session name' ], [ 'session_id' => $f['session'] ] );
		} finally { wp_set_current_user( 0 ); }
		foreach ( $orders as $id ) {
			$this->same( $before[ $id ], $this->order_snapshot( $id ), 'order ' . $id . ' facts, native names, charged amounts/discounts/taxes and receipt remain unchanged after Catalog/Session edits' );
			WC()->cart->cart_contents = $carts[ $id ];
			CustomerOrder::record( wc_get_order( $id ) );
			$this->same( $before[ $id ], $this->order_snapshot( $id ), 'non-draft recording preserves saved facts despite still-eligible mutable Catalog changes' );
			$this->clear();
		}
	}

	private function order_snapshot( int $id ): array {
		$order = wc_get_order( $id ); $rows = [];
		foreach ( $order->get_items() as $key => $item ) { $rows[ $key ] = [ $item->get_name(), $item->get_meta( CustomerOrder::META, true ), $this->item_financials( $item ), wc_display_item_meta( $item, [ 'echo' => false ] ) ]; }
		return [ $order->get_meta( CustomerOrder::META, true ), $order->get_total(), $order->get_discount_total(), $order->get_discount_tax(), $order->get_total_tax(), $rows ];
	}
	private function item_financials( WC_Order_Item_Product $item ): array {
		$taxes = $item->get_taxes(); ksort( $taxes );
		return [ (int) $item->get_quantity(), wc_format_decimal( $item->get_subtotal(), 2 ), wc_format_decimal( $item->get_total(), 2 ), wc_format_decimal( $item->get_subtotal_tax(), 2 ), wc_format_decimal( $item->get_total_tax(), 2 ), $taxes ];
	}
	private function cart_financials(): array {
		$rows = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$taxes = array_map( static fn( $values ) => array_map( 'wc_format_decimal', $values ), $item['line_tax_data'] );
			ksort( $taxes );
			$rows[ $item['product_id'] ] = [ (int) $item['quantity'], wc_format_decimal( $item['line_subtotal'], 2 ), wc_format_decimal( $item['line_total'], 2 ), wc_format_decimal( $item['line_subtotal_tax'], 2 ), wc_format_decimal( $item['line_tax'], 2 ), $taxes ];
		}
		return $rows;
	}
	private function request( string $method ): WP_REST_Response {
		// A fresh native route instance models separate HTTP requests in one CLI process.
		$server = rest_get_server();
		$controller = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class );
		$route = $controller->get( 'checkout' ); register_rest_route( 'wc/store/v1', '/checkout', $route->get_args(), true );
		$request = new WP_REST_Request( $method, '/wc/store/v1/checkout' );
		$request->set_body_params( [ 'billing_address' => $this->address, 'payment_method' => 'bacs' ] );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		return $server->dispatch( $request );
	}
	private function clear(): void {
		WC()->cart->empty_cart( false ); WC()->cart->cart_context = 'shortcode'; wc_clear_notices();
		WC()->session->set( 'store_api_draft_order', 0 ); WC()->session->set( 'order_awaiting_payment', 0 );
	}

	private function fixture( string $label ): array {
		global $wpdb;
		wp_set_current_user( $this->admin );
		$site = wp_insert_site( [ 'domain' => 'orders-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Order Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site; switch_to_blog( $site ); update_option( 'bc_order_test_owner', $this->token );
		try {
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
			update_option( 'timezone_string', 'America/New_York' ); update_option( 'woocommerce_enable_guest_checkout', 'yes' );
			update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Fixture offline transfer' ] );
			update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' );
			update_option( 'woocommerce_tax_based_on', 'billing' ); update_option( 'woocommerce_default_country', 'US:NY' );
			update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_enable_coupons', 'yes' );
			foreach ( [ 'background_color' => '#ffffff', 'body_background_color' => '#ffffff', 'base_color' => '#555555', 'text_color' => '#222222', 'footer_text_color' => '#444444' ] as $key => $value ) { update_option( 'woocommerce_email_' . $key, $value ); }
			WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'US', 'tax_rate_state' => 'NY', 'tax_rate' => '8.0000', 'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_class' => '' ] );
			$plays = new BingoPlayProductService();
			$base = $plays->create( $this->product( 'Base Paper', '1.00' ), 'paper', 6, null )->play_product['bingo_play_product_id'];
			$first_wc = $this->product( 'Paper Extra One', '50.00' ); $second_wc = $this->product( 'Paper Extra Two', '60.00' );
			$first_play = $plays->create( $first_wc, 'paper', 3, null )->play_product['bingo_play_product_id'];
			$second_play = $plays->create( $second_wc, 'paper', 9, null )->play_product['bingo_play_product_id'];
			$package_wc = $this->product( 'Fixture Paper Package', '20.00' );
			$package = ( new PackageService() )->create( $package_wc, [ [ 'bingo_play_product_id' => $base, 'quantity' => 1 ] ] )->package['package_id'];
			$addons = new PackageAddonService();
			$first = $addons->create( $package, $first_play, '3.25', 4 )->package_addon['package_addon_id'];
			$second = $addons->create( $package, $second_play, '6.50', 5 )->package_addon['package_addon_id'];
			$merch = $this->product( 'Ordinary dauber', '2.00' );
			$coupon = 'order-' . strtolower( wp_generate_password( 8, false ) );
			$c = new WC_Coupon(); $c->set_code( $coupon ); $c->set_discount_type( 'percent' ); $c->set_amount( '10' ); $c->save();
			$now = current_time( 'mysql', true );
			$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => 80003, 'name' => 'Order fixture Session', 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$schedules = new SessionScheduleService(); $schedule = $schedules->create( 80003, 'dates', '18:00', [ $date ], [ $package ] );
			if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Fixture schedule failed.' ); }
			$schedules->generate( (int) $schedule->schedule['schedule_id'] );
			$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
			if ( is_wp_error( ( new SessionService() )->setAvailableForSale( $session, true ) ) ) { throw new RuntimeException( 'Fixture sales failed.' ); }
			$name = $wpdb->get_var( $wpdb->prepare( 'SELECT display_name FROM %i WHERE session_id = %d', Migrations::sessions_table(), $session ) );
			$service = new SessionService(); $created = $service->create( $date, 'Legacy 7pm label', false );
			$legacy = (int) $created->session['session_id']; $service->assignPackage( $legacy, $package ); $service->setAvailableForSale( $legacy, true );
			return compact( 'site', 'session', 'date', 'name', 'package', 'package_wc', 'first', 'second', 'first_play', 'second_play', 'first_wc', 'second_wc', 'merch', 'coupon', 'legacy' );
		} finally { restore_current_blog(); }
	}
	private function product( string $name, string $price ): int {
		$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_virtual( true ); $p->set_tax_status( 'taxable' ); return $p->save();
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
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_order_test_owner' ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
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
( new CustomerOrderRecordingTest() )->run();
