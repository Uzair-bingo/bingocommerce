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
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
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

/** No payment transport: observes Woo's native automatic-refund call only. */
final class OrderFixtureRefundGateway extends WC_Payment_Gateway {
	public int $refund_calls = 0;
	public function __construct() { $this->id = 'bc_fixture_refund'; $this->supports = [ 'refunds' ]; }
	public function process_refund( $order_id, $amount = null, $reason = '' ) { ++$this->refund_calls; return true; }
}

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
		$missing = new stdClass(); $maximum = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		try {
			update_site_option( ElectronicLimit::PLATFORM_OPTION, '500' );
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
			$this->digital_recording( $a, $b );
			$this->refunds( $a, $b );
			$this->history( $a, $orders, $carts );
			$this->clear(); WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 );
			switch_to_blog( $b['site'] ); WC()->session = null; WC()->cart = null; WC()->customer = null;
			WC()->initialize_session(); WC()->initialize_cart();
			WC()->customer->set_props( array_combine( array_map( static fn( $key ) => 'billing_' . $key, array_keys( $this->address ) ), $this->address ) );
			$this->select( $b ); $expected = $this->cart_financials(); $id = $this->checkout( 'store' );
			$this->assert_order( $id, $b, $expected, 'Hall B with colliding local IDs' );
			$digital = $b['digital']['digital'] + $b;
			$this->select( $digital ); $expected = $this->cart_financials(); $id = $this->checkout( 'store' );
			$this->assert_order( $id, $digital, $expected, 'Hall B digital facts with colliding Game Type IDs' );
			$this->same( 0, get_current_user_id(), 'all purchase cases finish signed out' );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			if ( $maximum === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $maximum ); }
			$this->same( $maximum, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'fixture network limit restored' );
			$this->cleanup();
		}
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
		$this->same( $f['expected_faces'] ?? [], $facts['digital_faces_by_game_type'] ?? null, $path . ': digital faces recorded by Hall-local Game Type; Paper is an explicit empty map' );
		$this->same( [], array_intersect( [ 'limit', 'effective_limit', ElectronicLimit::PLATFORM_OPTION, ElectronicLimit::HALL_OPTION ], array_keys( $facts ) ), $path . ': no electronic-limit purchase fact' );
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
			$lines = [];
			foreach ( wc_get_order( $id )->get_items() as $item_id => $item ) {
				if ( is_array( $item->get_meta( CustomerOrder::META, true ) ) ) { $lines[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ]; }
			}
			wp_set_current_user( $this->admin );
			try { $this->same( true, $this->refund_request( 'direct', $id, $this->refund_amount( $lines ), $lines, false ), 'historical refund uses charged order lines after Catalog price/name changes' ); }
			finally { wp_set_current_user( 0 ); }
			$this->same( $before[ $id ], $this->order_snapshot( $id ), 'historical refund leaves original purchase facts and finances unchanged' );
		}
	}

	private function order_snapshot( int $id ): array {
		$order = wc_get_order( $id ); $rows = [];
		foreach ( $order->get_items() as $key => $item ) { $rows[ $key ] = [ $item->get_name(), $item->get_meta( CustomerOrder::META, true ), $this->item_financials( $item ), wc_display_item_meta( $item, [ 'echo' => false ] ) ]; }
		return [ $order->get_meta( CustomerOrder::META, true ), $order->get_total(), $order->get_discount_total(), $order->get_discount_tax(), $order->get_total_tax(), $rows ];
	}

	private function digital_recording( array $f, array $other ): void {
		global $wpdb;
		$other_before = $this->snapshot( $other['site'] );
		$this->same( $f['digital']['digital']['game_a'], $other['digital']['digital']['game_a'], 'digital order fixtures have colliding Hall-local Game Type IDs' );
		foreach ( $f['digital'] as $kind => $variant ) {
			$selection = $variant + $f;
			foreach ( [ 'classic', 'store' ] as $path ) {
				$this->select( $selection ); $expected = $this->cart_financials(); $id = $this->checkout( $path );
				$this->assert_order( $id, $selection, $expected, $kind . ' ' . $path );
				$before = $this->order_snapshot( $id ); $lines = [];
				foreach ( wc_get_order( $id )->get_items() as $item_id => $item ) {
					if ( is_array( $item->get_meta( CustomerOrder::META, true ) ) ) { $lines[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ]; }
				}
				wp_set_current_user( $this->admin );
				try {
					$partial = array_slice( $lines, 1, 1, true );
					$this->same( false, $this->refund_request( 'direct', $id, $this->refund_amount( $partial ), $partial, false ), 'digital order still rejects separate add-on refund' );
					$this->same( true, $this->refund_request( 'direct', $id, $this->refund_amount( $lines ), $lines, false ), 'digital order still permits whole-Package refund' );
				} finally { wp_set_current_user( 0 ); }
				$this->same( $before, $this->order_snapshot( $id ), 'refund retains recorded digital totals and original financial facts' );
			}
		}
		$selection = $f['digital']['digital'] + $f;
		foreach ( [ false, true ] as $older ) {
			foreach ( [ 'classic-pay', 'classic-resume', 'store-existing', 'store-cart' ] as $path ) {
				$this->select( $selection ); $cart = WC()->cart->get_cart(); $coupons = WC()->cart->get_applied_coupons();
				$id = $this->checkout( str_starts_with( $path, 'store' ) ? 'store' : 'classic' );
				if ( $older ) {
					$order = wc_get_order( $id ); $facts = $order->get_meta( CustomerOrder::META, true ); unset( $facts['digital_faces_by_game_type'] );
					$order->update_meta_data( CustomerOrder::META, $facts ); $order->save();
				}
				$before = $this->order_snapshot( $id );
				// Changes confined to token-owned fixture Catalog rows: faces, component
				// quantity and Game Type assignment change while cart inputs/prices do not.
				$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 7 ], [ 'bingo_play_product_id' => $selection['first_play'] ] );
				$wpdb->update( CatalogMigrations::play_products_table(), [ 'game_type_id' => $selection['game_a'] ], [ 'bingo_play_product_id' => $selection['second_play'] ] );
				$wpdb->update( CatalogMigrations::package_components_table(), [ 'quantity' => 4 ], [ 'package_id' => $selection['package'], 'bingo_play_product_id' => $selection['first_play'] ] );
				try {
					$this->same( [ $selection['game_a'] => 67 ], ElectronicLimit::validate_selection( $selection['package'], [ $selection['first'] => 2, $selection['second'] => 3 ] ), 'current Catalog produces different digital totals' );
					$this->same( $before, $this->order_snapshot( $id ), 'Catalog edits alone cannot rewrite placed digital facts' );
					$this->retry( $path, $id, $cart, $coupons );
					$after = $this->order_snapshot( $id );
					// Native classic resume rebuilds line IDs; compare their saved content.
					$before[5] = array_values( $before[5] ); $after[5] = array_values( $after[5] );
					$this->same( $before, $after, $path . ': native retry preserves facts, item contents, quantities, amounts, discounts and taxes' );
					$facts = wc_get_order( $id )->get_meta( CustomerOrder::META, true );
					$this->same( ! $older, array_key_exists( 'digital_faces_by_game_type', $facts ), $path . ': older missing totals stay missing; recorded totals survive' );
				} finally {
					$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => $selection['faces'] ], [ 'bingo_play_product_id' => $selection['first_play'] ] );
					$wpdb->update( CatalogMigrations::play_products_table(), [ 'game_type_id' => $selection['game_b'] ], [ 'bingo_play_product_id' => $selection['second_play'] ] );
					$wpdb->update( CatalogMigrations::package_components_table(), [ 'quantity' => 3 ], [ 'package_id' => $selection['package'], 'bingo_play_product_id' => $selection['first_play'] ] );
					$this->clear();
				}
			}
		}
		$this->select( $selection ); $cart = WC()->cart->get_cart(); $coupons = WC()->cart->get_applied_coupons(); $id = $this->checkout( 'classic' );
		$order = wc_get_order( $id ); $order->delete_meta_data( CustomerOrder::META ); $order->save();
		$this->retry( 'classic-resume', $id, $cart, $coupons );
		$this->same( false, array_key_exists( 'digital_faces_by_game_type', wc_get_order( $id )->get_meta( CustomerOrder::META, true ) ), 'placed order with no summary is not backfilled with digital totals' );
		$this->digital_drafts( $f );
		$this->same( $other_before, $this->snapshot( $other['site'] ), 'digital recording and retries leave colliding Hall B byte-identical' );
	}

	private function digital_drafts( array $f ): void {
		global $wpdb;
		$d = $f['digital']['digital']; $parent = $this->select( $d + $f ); $id = 0;
		$stop = static function ( $order ) use ( &$id ) { $id = $order->get_id(); throw new RouteException( 'bc_fixture_digital_draft', 'Unplaced fixture draft.', 418 ); };
		add_action( 'woocommerce_store_api_checkout_update_order_meta', $stop, 200 );
		try {
			$this->same( 418, $this->request( 'POST' )->get_status(), 'digital draft created through Store API' );
			$line_ids = array_keys( wc_get_order( $id )->get_items() ); WC()->session->set( 'store_api_draft_order', $id );
			$this->same( $d['expected_faces'], wc_get_order( $id )->get_meta( CustomerOrder::META, true )['digital_faces_by_game_type'], 'draft has initial validated digital totals' );
			$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 7 ], [ 'bingo_play_product_id' => $d['first_play'] ] );
			$this->same( 418, $this->request( 'POST' )->get_status(), 'unchanged-line draft rereads current faces' );
			$this->same( $line_ids, array_keys( wc_get_order( $id )->get_items() ), 'draft refresh did not require replacing line IDs' );
			$this->same( [ $d['game_a'] => 35, $d['game_b'] => 25 ], wc_get_order( $id )->get_meta( CustomerOrder::META, true )['digital_faces_by_game_type'], 'unplaced draft follows Catalog faces' );
			foreach ( WC()->cart->get_cart() as $key => $line ) { if ( $d['first'] === ( $line[ CartSelection::META ]['addon_id'] ?? null ) ) { WC()->cart->remove_cart_item( $key ); } }
			WC()->cart->calculate_totals();
			$this->same( 418, $this->request( 'POST' )->get_status(), 'digital draft updates after add-on removal' );
			$this->same( [ $d['game_a'] => 21, $d['game_b'] => 25 ], wc_get_order( $id )->get_meta( CustomerOrder::META, true )['digital_faces_by_game_type'], 'removed add-on no longer contributes draft faces' );
			$this->clear(); $h = $f['digital']['hybrid']; WC()->cart->add_to_cart( $f['merch'], 10 );
			CustomerCart::add( [ 'session_id' => $f['session'], 'package_id' => $h['package'], 'addons' => [ [ 'package_addon_id' => $h['first'], 'quantity' => 2 ], [ 'package_addon_id' => $h['second'], 'quantity' => 3 ], [ 'package_addon_id' => $h['paper_addon'], 'quantity' => 10 ] ] ] );
			WC()->cart->calculate_totals(); WC()->session->set( 'store_api_draft_order', $id );
			$this->same( 418, $this->request( 'POST' )->get_status(), 'draft changes to Hybrid with digital and Paper add-ons' );
			$facts = wc_get_order( $id )->get_meta( CustomerOrder::META, true );
			$this->same( $h['package'], $facts['package_id'], 'draft now records the replacement Package' );
			$this->same( [ $h['game_a'] => 35, $h['game_b'] => 25 ], $facts['digital_faces_by_game_type'], 'Hybrid Paper base and Paper add-on contribute no digital faces' );
			$parent = $this->select( $f ); WC()->session->set( 'store_api_draft_order', $id );
			$this->same( 418, $this->request( 'POST' )->get_status(), 'draft changes to Paper-only' );
			$this->same( [], wc_get_order( $id )->get_meta( CustomerOrder::META, true )['digital_faces_by_game_type'], 'Paper replacement clears former digital totals' );
			WC()->cart->remove_cart_item( $parent ); WC()->cart->calculate_totals();
			$this->same( 418, $this->request( 'POST' )->get_status(), 'draft removes Bingo selection' );
			$this->same( '', wc_get_order( $id )->get_meta( CustomerOrder::META, true ), 'merchandise-only draft has no digital purchase facts' );
		} finally {
			remove_action( 'woocommerce_store_api_checkout_update_order_meta', $stop, 200 );
			$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => $d['faces'] ], [ 'bingo_play_product_id' => $d['first_play'] ] );
			$this->clear();
		}
	}

	/** Actual Woo retry entry points, offline BACS. Only its final redirect is intercepted. */
	private function retry( string $path, int $id, array $cart, array $coupons ): void {
		$this->same( 0, get_current_user_id(), $path . ': retry is signed out' );
		$order = wc_get_order( $id ); $order->set_status( 'pending' ); $order->save();
		if ( in_array( $path, [ 'classic-resume', 'store-cart' ], true ) ) {
			WC()->cart->cart_contents = $cart;
			foreach ( $coupons as $coupon ) { WC()->cart->apply_coupon( $coupon ); }
			WC()->cart->calculate_totals();
			WC()->session->set( 'classic-resume' === $path ? 'order_awaiting_payment' : 'store_api_draft_order', $id );
			$this->same( $id, $this->checkout( 'classic-resume' === $path ? 'classic' : 'store' ), $path . ': native checkout reuses existing order' );
		} elseif ( 'store-existing' === $path ) {
			$route = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class )->get( 'checkout-order' );
			register_rest_route( 'wc/store/v1', $route->get_path(), $route->get_args(), true );
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout/' . $id );
			$request->set_body_params( [ 'key' => $order->get_order_key(), 'billing_email' => $order->get_billing_email(), 'billing_address' => $this->address, 'payment_method' => 'bacs' ] );
			$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
			$response = rest_get_server()->dispatch( $request );
			$this->same( 200, $response->get_status(), 'Store API existing-order offline payment ' . ( 200 === $response->get_status() ? '' : wp_json_encode( $response->get_data() ) ) );
		} else {
			global $wp;
			$post = $_POST; $get = $_GET; $request = $_REQUEST; $vars = $wp->query_vars; $level = ob_get_level(); $paid = 0;
			$stop = static function ( $result, $order_id ) use ( &$paid ) { $paid = (int) $order_id; throw new RuntimeException( 'BC_FIXTURE_RETRY_NO_REDIRECT' ); };
			add_filter( 'woocommerce_payment_successful_result', $stop, 100, 2 ); wc_clear_notices();
			try {
				$_POST = [ 'woocommerce_pay' => '1', 'payment_method' => 'bacs' ]; $_GET = [ 'key' => $order->get_order_key() ];
				$_REQUEST = $_POST + [ 'woocommerce-pay-nonce' => wp_create_nonce( 'woocommerce-pay' ) ]; $wp->query_vars['order-pay'] = $id;
				WC_Form_Handler::pay_action();
			} finally {
				while ( ob_get_level() > $level ) { ob_end_clean(); } $_POST = $post; $_GET = $get; $_REQUEST = $request; $wp->query_vars = $vars;
				remove_filter( 'woocommerce_payment_successful_result', $stop, 100 );
			}
			$this->same( $id, $paid, 'classic pay-for-order reaches offline gateway success' );
		}
		$this->same( 'on-hold', wc_get_order( $id )->get_status(), $path . ': offline retry completed without payment transport' );
		$this->clear();
	}

	private function refunds( array $f, array $other ): void {
		$gateway = new OrderFixtureRefundGateway();
		WC()->payment_gateways()->payment_gateways[] = $gateway;
		$other_before = $this->snapshot( $other['site'] );
		$saves = 0; $events = 0; $restocks = 0;
		$saved = static function () use ( &$saves ) { ++$saves; };
		$processed = static function () use ( &$events ) { ++$events; };
		$restock = static function ( $allowed ) use ( &$restocks ) { ++$restocks; return $allowed; };
		add_action( 'woocommerce_after_order_refund_object_save', $saved );
		add_action( 'woocommerce_refund_created', $processed );
		add_filter( 'woocommerce_can_restock_refunded_items', $restock );
		try {
			foreach ( [ 'direct', 'ajax', 'rest' ] as $path ) {
				$cases = [ 'full', 'manual', 'whole-order', 'package-only', 'addon-only', 'partial-quantity', 'partial-amount', 'missing-tax', 'merchandise', 'amount-only', 'full-amount-only', 'mismatched-amount' ];
				if ( 'direct' === $path ) { $cases = array_merge( $cases, [ 'foreign-hall', 'missing-link', 'unbound-duplicate', 'merchandise-overallocation', 'resumed', 'stale-ids', 'no-addons', 'tax-only', 'zero-price-addon', 'omitted-free-addon', 'subtotal-tax-rounding' ] ); }
				foreach ( $cases as $case ) {
					wp_set_current_user( 0 );
					$this->select( $f );
					if ( 'no-addons' === $case ) {
						foreach ( WC()->cart->get_cart() as $key => $line ) { if ( 'addon' === ( $line[ CartSelection::META ]['role'] ?? null ) ) { WC()->cart->remove_cart_item( $key ); } }
						WC()->cart->calculate_totals();
					}
					$cart = WC()->cart->get_cart(); $coupons = WC()->cart->get_applied_coupons();
					$id = $this->checkout( 'rest' === $path ? 'store' : 'classic' );
					$order = wc_get_order( $id );
					if ( in_array( $case, [ 'resumed', 'stale-ids' ], true ) ) {
						$old_ids = array_keys( $order->get_items() ); $order->set_status( 'pending' ); $order->save();
						WC()->cart->cart_contents = $cart;
						foreach ( $coupons as $coupon ) { WC()->cart->apply_coupon( $coupon ); }
						WC()->cart->calculate_totals(); WC()->session->set( 'order_awaiting_payment', $id );
						$this->same( $id, WC()->checkout()->create_order( [ 'payment_method' => 'bacs' ] ), 'refund fixture resumes real classic order' );
						$order = wc_get_order( $id );
						$this->ok( $old_ids !== array_keys( $order->get_items() ), 'native resume replaced item IDs before refund' );
						$this->clear();
					}
					wp_set_current_user( $this->admin );
					$order->set_payment_method( $gateway ); $order->save();
					$all = []; $unit = []; $merch = [];
					foreach ( $order->get_items() as $item_id => $item ) {
						$binding = $item->get_meta( CustomerOrder::META, true );
						if ( in_array( $case, [ 'zero-price-addon', 'omitted-free-addon' ], true ) && 'addon' === ( $binding['role'] ?? null ) ) { $item->set_total( 0 ); $item->set_subtotal( 0 ); $item->set_taxes( [ 'total' => [], 'subtotal' => [] ] ); $item->save(); }
						$all[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ];
						if ( is_array( $binding ) ) { $unit[ $item_id ] = $all[ $item_id ]; } else { $merch[ $item_id ] = $all[ $item_id ]; }
					}
					if ( in_array( $case, [ 'zero-price-addon', 'omitted-free-addon' ], true ) ) { $order->update_taxes(); $order->calculate_totals( false ); }
					$lines = 'whole-order' === $case ? $all : $unit;
					$parent = array_key_first( $unit ); $child = array_keys( $unit )[1] ?? null;
					if ( 'stale-ids' === $case ) { $lines = array_combine( $old_ids, array_values( $all ) ); }
					if ( 'omitted-free-addon' === $case ) { unset( $lines[ $child ] ); }
					if ( 'package-only' === $case ) { $lines = [ $parent => $unit[ $parent ] ]; }
					if ( 'addon-only' === $case ) { $lines = [ $child => $unit[ $child ] ]; }
					if ( 'partial-quantity' === $case ) { $lines[ $child ]['qty'] = 1; }
					if ( 'partial-amount' === $case ) { $lines[ $parent ]['refund_total'] = '1.00'; }
					if ( 'missing-tax' === $case ) { $lines[ $child ]['refund_tax'] = []; }
					if ( 'tax-only' === $case ) { foreach ( $lines as &$line ) { $line['qty'] = 0; $line['refund_total'] = 0; } unset( $line ); }
					if ( in_array( $case, [ 'merchandise', 'merchandise-overallocation' ], true ) ) {
						$lines = $merch;
						foreach ( $lines as &$line ) { $line['qty'] = 1; $line['refund_total'] = 'merchandise' === $case ? '1.80' : '30.00'; $line['refund_tax'] = array_map( static fn( $tax ) => (float) $tax / 10, $line['refund_tax'] ); } unset( $line );
					}
					if ( str_contains( $case, 'amount-only' ) ) { $lines = []; }
					if ( 'foreign-hall' === $case ) { $facts = $order->get_meta( CustomerOrder::META, true ); $facts['site_id'] = $other['site']; $order->update_meta_data( CustomerOrder::META, $facts ); $order->save(); }
					if ( 'missing-link' === $case ) { $item = $order->get_item( $child ); $item->delete_meta_data( CustomerOrder::META ); $item->save(); }
					if ( 'unbound-duplicate' === $case ) { $item = new WC_Order_Item_Product(); $item->set_product_id( $f['first_wc'] ); $item->set_quantity( 1 ); $order->add_item( $item ); $order->save(); }
					$rounding = get_option( 'woocommerce_tax_round_at_subtotal' );
					if ( 'subtotal-tax-rounding' === $case ) { update_option( 'woocommerce_tax_round_at_subtotal', 'yes' ); }
					$amount = $this->refund_amount( $lines );
					if ( in_array( $case, [ 'amount-only', 'mismatched-amount' ], true ) ) { $amount = '1.00'; }
					if ( 'full-amount-only' === $case ) { $amount = $order->get_total(); }
					$before = $this->order_snapshot( $id ); $status = $order->get_status();
					$before_calls = $gateway->refund_calls; $before_saves = $saves; $before_events = $events; $before_restocks = $restocks;
					$ok = $this->refund_request( $path, $id, $amount, $lines, 'manual' !== $case );
					$expected = in_array( $case, [ 'full', 'manual', 'whole-order', 'merchandise', 'resumed', 'no-addons', 'zero-price-addon', 'subtotal-tax-rounding' ], true );
					$label = $path . ' refund ' . $case;
					$this->same( $expected, $ok, $label . ': native result' );
					$this->same( $before, $this->order_snapshot( $id ), $label . ': original facts, current item IDs and charged values unchanged' );
					$this->same( $before_calls + ( $expected && 'manual' !== $case ? 1 : 0 ), $gateway->refund_calls, $label . ': offline gateway invoked only for valid automatic refund' );
					$this->same( $before_events + ( $expected ? 1 : 0 ), $events, $label . ': completed-refund event only for valid refund' );
					$this->same( $before_restocks + ( $expected ? 1 : 0 ), $restocks, $label . ': restocking reached only for valid refund' );
					$refunds = wc_get_order( $id )->get_refunds();
					$this->same( $expected ? 1 : 0, count( $refunds ), $label . ': persisted refund count' );
					if ( ! $expected ) { $this->same( $before_saves, $saves, $label . ': rejected before first refund save' ); $this->same( $status, wc_get_order( $id )->get_status(), $label . ': status unchanged on refusal' ); }
					else {
						$this->same( count( $lines ), count( $refunds[0]->get_items() ), $label . ': exact refund line count' );
						$this->same( $amount, wc_format_decimal( $refunds[0]->get_amount(), 2 ), $label . ': coupon-adjusted amount and taxes' );
						$actual = []; $wanted = [];
						foreach ( $refunds[0]->get_items() as $item ) { $actual[ (int) $item->get_meta( '_refunded_item_id', true ) ] = [ -(float) $item->get_quantity(), wc_format_decimal( -(float) $item->get_total(), 2 ), array_map( static fn( $tax ) => wc_format_decimal( -(float) $tax, 2 ), $item->get_taxes()['total'] ) ]; }
						foreach ( $lines as $item_id => $line ) { $wanted[ $item_id ] = [ (float) $line['qty'], wc_format_decimal( $line['refund_total'], 2 ), array_map( static fn( $tax ) => wc_format_decimal( $tax, 2 ), $line['refund_tax'] ) ]; }
						ksort( $actual ); ksort( $wanted );
						$this->same( $wanted, $actual, $label . ': persisted refunds retain exact current-line allocation, quantities, amounts and taxes' );
						if ( 'whole-order' !== $case ) {
							$followup = 'merchandise' === $case ? $unit : $merch;
							$this->same( true, $this->refund_request( 'direct', $id, $this->refund_amount( $followup ), $followup ), $label . ': other refund unit remains independent' );
						}
					}
					if ( false === $rounding ) { delete_option( 'woocommerce_tax_round_at_subtotal' ); } else { update_option( 'woocommerce_tax_round_at_subtotal', $rounding ); }
				}
			}
			$this->clear(); WC()->cart->add_to_cart( $f['merch'], 2 ); WC()->cart->calculate_totals(); wp_set_current_user( 0 );
			$id = $this->checkout( 'classic' ); wp_set_current_user( $this->admin );
			$order = wc_get_order( $id ); $order->set_payment_method( $gateway ); $order->save();
			$this->same( true, $this->refund_request( 'direct', $id, '1.00', [] ), 'ordinary-only order retains native amount-only refunds' );
			// A status edit is not a refund request: Woo saves the status first, then
			// ignores wc_create_refund's error. Document, do not rewrite that workflow.
			wp_set_current_user( 0 ); $this->select( $f ); $id = $this->checkout( 'classic' ); wp_set_current_user( $this->admin );
			$order = wc_get_order( $id ); $before_calls = $gateway->refund_calls; $before_saves = $saves;
			$order->update_status( 'refunded' );
			$this->same( 'refunded', wc_get_order( $id )->get_status(), 'status-only change limitation: Woo already persisted the status' );
			$this->same( [], wc_get_order( $id )->get_refunds(), 'status-only amount refund refused rather than silently bypassing itemization' );
			$this->same( $before_saves, $saves, 'status-only amount refund has no preliminary write' );
			$this->same( $before_calls, $gateway->refund_calls, 'status-only change does not call a gateway' );
			$this->same( $other_before, $this->snapshot( $other['site'] ), 'refunds leave second Hall with colliding local IDs unchanged' );
		} finally {
			remove_action( 'woocommerce_after_order_refund_object_save', $saved ); remove_action( 'woocommerce_refund_created', $processed ); remove_filter( 'woocommerce_can_restock_refunded_items', $restock ); wp_set_current_user( 0 );
		}
	}

	private function refund_amount( array $lines ): string {
		$amount = 0.0;
		foreach ( $lines as $line ) { foreach ( array_merge( [ $line['refund_total'] ], array_values( $line['refund_tax'] ) ) as $value ) { $amount += 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? (float) $value : (float) wc_format_decimal( $value, 2 ); } }
		return wc_format_decimal( $amount, 2 );
	}

	private function refund_request( string $path, int $id, string $amount, array $lines, bool $automatic = true ): bool {
		if ( 'direct' === $path ) {
			$result = wc_create_refund( [ 'order_id' => $id, 'amount' => $amount, 'line_items' => $lines, 'refund_payment' => $automatic, 'restock_items' => true ] );
			return $result instanceof WC_Order_Refund && $result->get_id() > 0;
		}
		if ( 'rest' === $path ) {
			$server = rest_get_server(); ( new WC_REST_Order_Refunds_Controller() )->register_routes();
			$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $id . '/refunds' );
			$public = [];
			foreach ( $lines as $item_id => $line ) { $taxes = []; foreach ( $line['refund_tax'] as $rate => $tax ) { $taxes[] = [ 'id' => $rate, 'refund_total' => $tax ]; } $public[] = [ 'id' => $item_id, 'quantity' => $line['qty'], 'refund_total' => $line['refund_total'], 'refund_tax' => $taxes ]; }
			$request->set_body_params( [ 'amount' => $amount, 'line_items' => $public, 'api_refund' => $automatic, 'api_restock' => true ] );
			return 201 === $server->dispatch( $request )->get_status();
		}
		$post = $_POST; $request = $_REQUEST; $level = ob_get_level(); $output = '';
		$die = static fn() => static function () { throw new Error( 'BC_FIXTURE_AJAX_FINISHED' ); };
		add_filter( 'wp_doing_ajax', '__return_true' ); add_filter( 'wp_die_ajax_handler', $die );
		try {
			$_POST = [ 'order_id' => $id, 'refund_amount' => $amount, 'refunded_amount' => wc_get_order( $id )->get_total_refunded(), 'api_refund' => $automatic ? 'true' : 'false', 'restock_refunded_items' => 'true', 'line_item_qtys' => wp_json_encode( array_map( static fn( $line ) => $line['qty'], $lines ) ), 'line_item_totals' => wp_json_encode( array_map( static fn( $line ) => $line['refund_total'], $lines ) ), 'line_item_tax_totals' => wp_json_encode( array_map( static fn( $line ) => $line['refund_tax'], $lines ) ) ];
			$_REQUEST = $_POST + [ 'security' => wp_create_nonce( 'order-item' ) ];
			WC_AJAX::refund_line_items();
		} catch ( Error $e ) { if ( 'BC_FIXTURE_AJAX_FINISHED' !== $e->getMessage() ) { throw $e; } }
		finally { while ( ob_get_level() > $level ) { $output = ob_get_clean() . $output; } $_POST = $post; $_REQUEST = $request; remove_filter( 'wp_doing_ajax', '__return_true' ); remove_filter( 'wp_die_ajax_handler', $die ); }
		return true === ( json_decode( $output, true )['success'] ?? null );
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
			$digital = $this->digital_fixture( $base, 'a' === $label ? 6 : 9 );
			$merch = $this->product( 'Ordinary dauber', '2.00' );
			$coupon = 'order-' . strtolower( wp_generate_password( 8, false ) );
			$c = new WC_Coupon(); $c->set_code( $coupon ); $c->set_discount_type( 'percent' ); $c->set_amount( '10' ); $c->save();
			$now = current_time( 'mysql', true );
			$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => 80003, 'name' => 'Order fixture Session', 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$schedules = new SessionScheduleService(); $schedule = $schedules->create( 80003, 'dates', '18:00', [ $date ], [ $package, $digital['digital']['package'], $digital['hybrid']['package'] ] );
			if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Fixture schedule failed.' ); }
			$schedules->generate( (int) $schedule->schedule['schedule_id'] );
			$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
			if ( is_wp_error( ( new SessionService() )->setAvailableForSale( $session, true ) ) ) { throw new RuntimeException( 'Fixture sales failed.' ); }
			$name = $wpdb->get_var( $wpdb->prepare( 'SELECT display_name FROM %i WHERE session_id = %d', Migrations::sessions_table(), $session ) );
			$service = new SessionService(); $created = $service->create( $date, 'Legacy 7pm label', false );
			$legacy = (int) $created->session['session_id']; $service->assignPackage( $legacy, $package ); $service->setAvailableForSale( $legacy, true );
			return compact( 'site', 'session', 'date', 'name', 'package', 'package_wc', 'first', 'second', 'first_play', 'second_play', 'first_wc', 'second_wc', 'merch', 'coupon', 'legacy', 'digital' );
		} finally { restore_current_blog(); }
	}
	private function digital_fixture( int $paper, int $faces ): array {
		$game_a = ( new GameTypeService() )->create( 'Regular' )->game_type['game_type_id'];
		$game_b = ( new GameTypeService() )->create( 'Winner' )->game_type['game_type_id'];
		$plays = new BingoPlayProductService();
		$first_wc = $this->product( 'Digital Regular', '50.00' ); $second_wc = $this->product( 'Digital Winner', '60.00' );
		$first_play = $plays->create( $first_wc, 'digital', $faces, $game_a )->play_product['bingo_play_product_id'];
		$second_play = $plays->create( $second_wc, 'digital', 5, $game_b )->play_product['bingo_play_product_id'];
		$result = [];
		foreach ( [ 'digital', 'hybrid' ] as $kind ) {
			$components = [ [ 'bingo_play_product_id' => $first_play, 'quantity' => 3 ], [ 'bingo_play_product_id' => $second_play, 'quantity' => 2 ] ];
			if ( 'hybrid' === $kind ) { $components[] = [ 'bingo_play_product_id' => $paper, 'quantity' => 10 ]; }
			$package_wc = $this->product( 'Fixture ' . $kind . ' Package', '20.00' );
			$package = ( new PackageService() )->create( $package_wc, $components )->package['package_id'];
			$addons = new PackageAddonService();
			$first = $addons->create( $package, $first_play, '3.25', 4 )->package_addon['package_addon_id'];
			$second = $addons->create( $package, $second_play, '6.50', 5 )->package_addon['package_addon_id'];
			$paper_addon = $addons->create( $package, $paper, '1.00', 10 )->package_addon['package_addon_id'];
			$expected_faces = [ $game_a => $faces * 5, $game_b => 25 ];
			$result[ $kind ] = compact( 'package', 'package_wc', 'first', 'second', 'first_play', 'second_play', 'first_wc', 'second_wc', 'game_a', 'game_b', 'faces', 'paper_addon', 'expected_faces' );
		}
		return $result;
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
