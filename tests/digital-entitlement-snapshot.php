<?php
/**
 * Real guest Woo orders and payment_complete(), no payment transport.
 * BC_TEST_DB=bingocommerce_state_recon php tests/digital-entitlement-snapshot.php
 */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: isolated bingocommerce_state_recon is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\DigitalTickets\Migrations;
use BingoCommerce\DigitalTickets\Plugin;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\SessionService;

/** No network or money movement; exercise Woo's successful/failed refund branch. */
final class EntitlementFixtureRefundGateway extends WC_Payment_Gateway {
	public bool $succeed = true;
	public int $calls = 0;
	public function __construct() { $this->id = 'bc_entitlement_fixture'; $this->supports = [ 'refunds' ]; }
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		++$this->calls;
		return $this->succeed ? true : new WP_Error( 'fixture_refund_refused', 'Offline fixture refused refund.' );
	}
}

final class DigitalEntitlementSnapshotTest {
	private array $sites = [];
	private string $token;
	private int $admin;
	private int $passes = 0;
	private int $failures = 0;
	private array $errors = [];
	private array $void_errors = [];
	private array $address = [ 'first_name' => 'Fixture', 'last_name' => 'Customer', 'address_1' => '1 Test Street', 'city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US', 'email' => 'guest@example.invalid', 'phone' => '5185550100' ];

	public function run(): void {
		$this->token = wp_generate_uuid4(); $this->admin = (int) username_exists( 'fixture_admin' );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP.' ) );
		add_action( 'bingocommerce_digital_tickets_snapshot_failed', function ( $error, $order, $site ): void { $this->errors[] = [ $error->get_error_code(), $order, $site ]; }, 10, 3 );
		add_action( 'bingocommerce_digital_tickets_void_failed', function ( $error, $order, $site ): void { $this->void_errors[] = [ $error->get_error_code(), $order, $site ]; }, 10, 3 );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->hall_snapshot( $site ); }
		$missing = new stdClass(); $maximum = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		try {
			update_site_option( ElectronicLimit::PLATFORM_OPTION, '500' );
			$a = $this->fixture( 'a' ); // Existing Hall before the new plugin registers.
			Plugin::init();
			switch_to_blog( $a['site'] );
			Migrations::maybe_migrate_current_site();
			$this->same( true, Migrations::verify_current_site_schema(), 'existing disposable Hall migrated and verified' );
			$this->same( Migrations::DB_VERSION, get_option( Migrations::VERSION_OPTION ), 'per-plugin Hall schema version' );
			$this->same( true, Migrations::migrate_current_site(), 'migration is idempotent' );
			restore_current_blog();
			$b = $this->fixture( 'b' ); // Normal wp_initialize_site provisioning.
			$this->same( $a['session'], $b['session'], 'Hall-local Session IDs collide' );
			$this->same( $a['digital']['package'], $b['digital']['package'], 'Hall-local Package IDs collide' );
			$this->same( array_keys( $a['faces'] ), array_keys( $b['faces'] ), 'Hall-local Game Type IDs collide' );
			$this->enter( $a['site'] );
			foreach ( [ 'classic', 'store' ] as $path ) {
				foreach ( [ 'digital', 'hybrid' ] as $kind ) {
					$id = $this->order( $a, $kind, $path ); $before = $this->order_snapshot( $id );
					$this->same( null, $this->row( $id ), "$path $kind unpaid creates no entitlement" );
					$this->confirm( $id );
					$row = $this->row( $id ); $facts = wc_get_order( $id )->get_meta( 'bc_purchase', true );
					$this->same( $a['faces'], json_decode( $row['digital_faces_json'] ?? '', true ), "$path $kind exact saved multiple-Game-Type map with add-ons" );
					foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) { $this->same( (string) $facts[ $key ], $row[ $key ] ?? null, "$path $kind exact $key" ); }
					$this->same( 'active', $row['status'] ?? null, "$path $kind active only after confirmation" );
					$this->same( $before, $this->order_snapshot( $id ), "$path $kind purchase facts, names, IDs, quantities, amounts, discounts, taxes unchanged" );
					$this->same( 4, count( wc_get_order( $id )->get_items() ), 'Package plus two add-ons plus independent merchandise' );
					$this->same( [], array_intersect( [ 'limit', 'effective_limit' ], array_keys( $row ?? [] ) ), 'no electronic limit in entitlement' );
					do_action( 'woocommerce_payment_complete', $id, 'duplicate-not-authority' );
					wc_get_order( $id )->payment_complete( 'native-repeat' );
					$this->same( $row, $this->row( $id ), 'repeated event and native repeat preserve every snapshot field' );
					$this->same( true, Migrations::migrate_current_site(), 'migration after entitlement creation succeeds' );
					$this->same( $row, $this->row( $id ), 'schema rerun preserves snapshot' );
				}
			}
			$this->same( [], $this->errors, 'normal paid Digital/Hybrid cases have no snapshot errors' );
			$this->upgrade( $a );
			$this->unknown_and_excluded( $a, $b );
			$this->storage_failures( $a );
			$this->voiding( $a, $b );
			$this->event_ordering( $a, $b );
			$this->earlier_cancellation_payment( $a, $b );
			$this->history( $a );
			$this->isolation( $a, $b );
			$this->same( 0, get_current_user_id(), 'all purchase cases run genuinely signed out' );
		} catch ( Throwable $error ) {
			++$this->failures; fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
		} finally {
			if ( $maximum === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $maximum ); }
			$this->same( $maximum, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'fixture network option restored' );
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->hall_snapshot( $site ), "persistent fixture Hall $site byte-identical" ); }
		printf( "Digital entitlement snapshot: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function unknown_and_excluded( array $a, array $b ): void {
		foreach ( [ 'classic', 'store' ] as $path ) {
			foreach ( [ 'paper', 'merch' ] as $kind ) {
				$id = $this->order( $a, $kind, $path ); $before = $this->order_snapshot( $id ); $errors = count( $this->errors );
				$this->confirm( $id );
				$this->same( null, $this->row( $id ), "$path $kind produces no digital entitlement" );
				$this->same( $errors, count( $this->errors ), "$path $kind is not an unknown-totals error" );
				$this->same( $before, $this->order_snapshot( $id ), "$path $kind order unchanged" );
			}
			$id = $this->order( $a, 'digital', $path, true );
			$this->error( $id, 'bc_entitlement_zero_total_out_of_scope' );
			$this->same( null, $this->row( $id ), "$path zero-dollar payment_complete explicitly excluded" );
		}
		$game = array_key_first( $a['faces'] );
		foreach ( [ null, [], 'bad', [ $game => 0 ], [ $game => '0' ], [ $game => '6' ], [ $game => -1 ], [ $game => 1.5 ], [ $game => true ], [ 0 => 6 ], [ 'bad' => 6 ], [ '01' => 6 ] ] as $index => $totals ) {
			$id = $this->order( $a ); $order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
			if ( null === $totals ) { unset( $facts['digital_faces_by_game_type'] ); }
			else { $facts['digital_faces_by_game_type'] = $totals; }
			$order->update_meta_data( 'bc_purchase', $facts ); $order->save(); $before = $this->order_snapshot( $id );
			$this->confirm( $id ); $this->error( $id, 'bc_entitlement_totals_unknown' );
			$this->same( null, $this->row( $id ), "missing/malformed totals $index create no record" );
			$this->same( $before, $this->order_snapshot( $id ), "refused snapshot $index leaves persisted order untouched" );
		}
		foreach ( [ 'missing_summary', 'foreign_hall', 'bad_link', 'wrong_quantity', 'paper_with_faces', 'digital_empty', 'admin_origin' ] as $case ) {
			$id = $this->order( $a ); $order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
			if ( 'foreign_hall' === $case ) { $facts['site_id'] = $b['site']; }
			if ( 'paper_with_faces' === $case ) { $facts['package_type'] = 'paper'; }
			if ( 'digital_empty' === $case ) { $facts['digital_faces_by_game_type'] = []; }
			if ( 'admin_origin' === $case ) { $order->set_created_via( 'admin' ); }
			$order->update_meta_data( 'bc_purchase', $facts );
			if ( 'missing_summary' === $case ) { $order->delete_meta_data( 'bc_purchase' ); }
			if ( in_array( $case, [ 'bad_link', 'wrong_quantity' ], true ) ) {
				foreach ( $order->get_items() as $item ) {
					if ( 'addon' !== ( $item->get_meta( 'bc_purchase', true )['role'] ?? null ) ) { continue; }
					if ( 'bad_link' === $case ) { $item->delete_meta_data( 'bc_purchase' ); }
					else { $item->set_quantity( 99 ); }
					$item->save(); break;
				}
			}
			$order->save(); $before = $this->order_snapshot( $id ); $this->confirm( $id );
			$code = match ( $case ) { 'paper_with_faces', 'digital_empty' => 'bc_entitlement_totals_unknown', 'admin_origin' => 'bc_entitlement_order_origin_unsupported', default => 'bc_entitlement_saved_facts_invalid' };
			$this->error( $id, $code ); $this->same( null, $this->row( $id ), "$case fails closed" );
			$this->same( $before, $this->order_snapshot( $id ), "$case leaves order facts and money untouched" );
		}
		foreach ( [ 'pending', 'failed', 'cancelled', 'checkout-draft' ] as $status ) {
			$id = $this->order( $a ); $order = wc_get_order( $id ); $order->set_status( $status ); $order->save();
			do_action( 'woocommerce_payment_complete', $id ); $this->error( $id, 'bc_entitlement_payment_unconfirmed' );
			$this->same( null, $this->row( $id ), "$status spoofed event cannot snapshot unpaid order" );
		}
		$id = $this->order( $a ); wc_get_order( $id )->update_status( 'processing' );
		$this->same( null, $this->row( $id ), 'paid-looking status alone never activates snapshot' );
	}

	private function storage_failures( array $a ): void {
		global $wpdb;
		$id = $this->order( $a ); $before = $this->order_snapshot( $id );
		$fail = static function ( $sql ) { return str_starts_with( $sql, 'INSERT INTO `' . Migrations::table() . '`' ) ? 'SELECT * FROM bc_fixture_deliberately_absent_entitlement_table' : $sql; };
		add_filter( 'query', $fail );
		try { $this->confirm( $id ); } finally { remove_filter( 'query', $fail ); }
		$this->error( $id, 'bc_entitlement_unavailable' ); $this->same( null, $this->row( $id ), 'failed insert creates no partial snapshot' );
		$this->same( $before, $this->order_snapshot( $id ), 'storage failure does not corrupt order' );
		do_action( 'woocommerce_payment_complete', $id );
		$this->same( $a['faces'], json_decode( $this->row( $id )['digital_faces_json'], true ), 'explicit event redelivery after storage recovery inserts once' );
		$id = $this->order( $a ); $nested = false;
		$race = static function ( $sql ) use ( $id, &$nested ) {
			if ( ! $nested && str_starts_with( $sql, 'INSERT INTO `' . Migrations::table() . '`' ) ) { $nested = true; do_action( 'woocommerce_payment_complete', $id ); }
			return $sql;
		};
		$errors = count( $this->errors ); add_filter( 'query', $race );
		try { $this->confirm( $id ); } finally { remove_filter( 'query', $race ); }
		$this->same( true, $nested, 'interleaved second delivery wins insert after first delivery read' );
		$this->same( $errors, count( $this->errors ), 'duplicate-key loser verifies winning snapshot without an error' );
		$this->same( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Migrations::table(), $id ) ), 'unique order index admits exactly one row' );
		$id = $this->order( $a );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX order_unique', Migrations::table() ) );
		try { $this->confirm( $id ); $this->error( $id, 'bc_entitlement_schema_unavailable' ); $this->same( null, $this->row( $id ), 'missing unique arbiter fails closed' ); }
		finally { $this->same( true, Migrations::migrate_current_site(), 'disposable schema repaired with owner migration' ); }
		$id = $this->order( $a ); $order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
		unset( $facts['digital_faces_by_game_type'] ); $order->update_meta_data( 'bc_purchase', $facts ); $order->save();
		$broken_sink = static function (): void { throw new RuntimeException( 'Fixture diagnostic failure' ); };
		add_action( 'bingocommerce_digital_tickets_snapshot_failed', $broken_sink, 20 );
		try { $this->confirm( $id ); } finally { remove_action( 'bingocommerce_digital_tickets_snapshot_failed', $broken_sink, 20 ); }
		$this->same( null, $this->row( $id ), 'broken diagnostic sink does not fabricate an entitlement or fail payment' );
	}

	private function history( array $a ): void {
		global $wpdb;
		$id = $this->order( $a ); $facts = wc_get_order( $id )->get_meta( 'bc_purchase', true );
		wp_set_current_user( $this->admin );
		$this->same( false, is_wp_error( ( new BingoPlayProductService() )->updateFacesPerUnit( $a['first_play'], 40 ) ), 'later authoritative Catalog edit succeeds' );
		wp_set_current_user( 0 );
		$this->confirm( $id ); $row = $this->row( $id );
		$this->same( $facts['digital_faces_by_game_type'], json_decode( $row['digital_faces_json'], true ), 'first payment confirmation copies historical totals after Catalog edit' );
		$reads = [];
		$watch = static function ( $sql ) use ( &$reads ) { if ( preg_match( '/\b(?:SELECT|SHOW)\b.*bingo_(?:play_products|packages|package_components|game_types|sessions)/is', $sql ) ) { $reads[] = $sql; } return $sql; };
		add_filter( 'query', $watch );
		try { do_action( 'woocommerce_payment_complete', $id ); } finally { remove_filter( 'query', $watch ); }
		$this->same( [], $reads, 'snapshot event does not read mutable Catalog or Session state' );
		$this->same( $row, $this->row( $id ), 'later Catalog edit and duplicate leave existing snapshot byte-identical' );
		$order = wc_get_order( $id ); $facts['digital_faces_by_game_type'][ array_key_first( $a['faces'] ) ]++;
		$order->update_meta_data( 'bc_purchase', $facts ); $order->save();
		do_action( 'woocommerce_payment_complete', $id ); $this->error( $id, 'bc_entitlement_snapshot_conflict' );
		$this->same( $row, $this->row( $id ), 'changed saved totals cannot overwrite existing entitlement' );
		// Model a future void without implementing a production voiding writer.
		$facts['digital_faces_by_game_type'] = $a['faces']; $order->update_meta_data( 'bc_purchase', $facts ); $order->save();
		$wpdb->update( Migrations::table(), [ 'status' => 'void' ], [ 'order_id' => $id ] );
		$errors = count( $this->errors );
		do_action( 'woocommerce_payment_complete', $id );
		$this->same( $errors, count( $this->errors ), 'matching repeat recognizes existing future void without reinsertion' );
		$this->same( 'void', $this->row( $id )['status'], 'event never reactivates a future void record' );
	}

	private function upgrade( array $a ): void {
		global $wpdb;
		if ( $a['site'] !== get_current_blog_id() || $this->token !== get_option( 'bc_entitlement_test_owner' ) ) { throw new RuntimeException( 'Upgrade fixture ownership guard refused.' ); }
		$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY order_id', Migrations::table() ), ARRAY_A );
		$this->same( 4, count( $before ), 'upgrade starts with populated schema-v1 entitlement table' );
		$this->same( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Migrations::voids_table() ) ), 'upgrade fixture has no markers to discard' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', Migrations::voids_table() ) );
		update_option( Migrations::VERSION_OPTION, 1 );
		$this->same( true, Migrations::migrate_current_site(), 'schema-v1 upgrade creates indexed void marker table' );
		$this->same( 2, get_option( Migrations::VERSION_OPTION ), 'schema-v2 marker version persisted' );
		$this->same( true, Migrations::migrate_current_site(), 'schema-v2 upgrade is repeatable' );
		$this->same( $before, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY order_id', Migrations::table() ), ARRAY_A ), 'upgrade preserves all paid snapshot fields' );
	}

	private function event_ordering( array $a, array $b ): void {
		$b_before = $this->hall_snapshot( $b['site'] );
		$errors = count( $this->errors ); $void_errors = count( $this->void_errors );
		foreach ( [ 'classic', 'store' ] as $path ) {
			foreach ( [ 'package_refund', 'cancellation' ] as $cause ) {
				foreach ( [ false, true ] as $payment_first ) {
					$label = "$path $cause " . ( $payment_first ? 'payment first' : 'void first' );
					$id = $this->order( $a, 'hybrid', $path ); $before = $this->order_snapshot( $id );
					if ( $payment_first ) { $this->confirm( $id ); }
					$row = $this->row( $id ); $this->native_void( $id, $cause, 'store' === $path );
					$marker = $this->marker( $id ); $facts = wc_get_order( $id )->get_meta( 'bc_purchase', true );
					foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) { $this->same( (string) $facts[ $key ], $marker[ $key ] ?? null, "$label marker exact $key" ); }
					$this->same( $cause, $marker['cause'] ?? null, "$label durable terminal cause" );
					$this->same( [], array_intersect( [ 'digital_faces_json', 'confirmed_at' ], array_keys( $marker ?? [] ) ), 'marker invents neither digital faces nor payment confirmation' );
					if ( null !== $row ) { $row['status'] = 'void'; }
					$this->same( $row, $this->row( $id ), "$label snapshot absent or unchanged except void status" );
					// Woo may accept payment from cancelled/on-hold. Observe it; never alter that behavior.
					$this->confirm( $id );
					do_action( 'woocommerce_payment_complete', $id );
					wc_get_order( $id )->payment_complete( 'native-repeat' );
					$this->same( $row, $this->row( $id ), "$label late and repeated payment cannot create/reactivate entitlement" );
					$this->same( $marker, $this->marker( $id ), "$label repeated payment preserves terminal marker" );
					$this->same( $before, $this->order_snapshot( $id ), "$label lines, saved facts, amounts, coupons, taxes untouched" );
				}
			}
			foreach ( [ 'merchandise', 'rejected' ] as $case ) {
				$id = $this->order( $a, 'digital', $path ); $before = $this->order_snapshot( $id );
				$lines = $this->refund_lines( wc_get_order( $id ), 'rejected' === $case );
				if ( 'rejected' === $case ) { $key = array_key_last( $lines ); $lines = [ $key => $lines[ $key ] ]; }
				wp_set_current_user( $this->admin );
				try { $refund = $this->refund( $id, $lines, false, 'store' === $path ); }
				finally { wp_set_current_user( 0 ); }
				$this->same( 'merchandise' === $case, $refund instanceof WC_Order_Refund, "$path $case native refund result before payment" );
				$this->same( null, $this->marker( $id ), "$path $case cannot block future entitlement" );
				$this->confirm( $id );
				$this->same( 'active', $this->row( $id )['status'] ?? null, "$path $case later payment still creates active entitlement" );
				$this->same( $before, $this->order_snapshot( $id ), "$path $case preserves original purchase and amounts" );
			}
		}
		// Controlled interleavings use real Woo events, not mocks of our observers.
		foreach ( [ 'package_refund', 'cancellation' ] as $cause ) {
			foreach ( [ 'snapshot_insert', 'marker_insert' ] as $pause ) {
				$id = $this->order( $a ); $before = $this->order_snapshot( $id ); $nested = false;
				$table = 'snapshot_insert' === $pause ? Migrations::table() : Migrations::voids_table();
				$race = function ( $sql ) use ( $id, $cause, $pause, $table, &$nested ) {
					if ( ! $nested && str_starts_with( $sql, 'INSERT INTO `' . $table . '`' ) ) {
						$nested = true;
						if ( 'snapshot_insert' === $pause ) { $this->native_void( $id, $cause ); }
						else { $this->confirm( $id ); }
					}
					return $sql;
				};
				add_filter( 'query', $race );
				try {
					if ( 'snapshot_insert' === $pause ) {
						$this->same( true, wc_get_order( $id )->payment_complete( 'fixture-interleaved' ), 'native payment call succeeds during interleaving' );
						$this->same( 'cancellation' === $cause ? 'cancelled' : 'processing', wc_get_order( $id )->get_status(), 'nested native status change is not overwritten by entitlement observers' );
					}
					else { $this->native_void( $id, $cause ); }
				} finally { remove_filter( 'query', $race ); }
				$this->same( true, $nested, "$cause interleaves at $pause" );
				$row = $this->row( $id ); $marker = $this->marker( $id );
				$this->same( 'void', $row['status'] ?? null, "$cause $pause converges to void after both observers finish" );
				$this->same( $a['faces'], json_decode( $row['digital_faces_json'] ?? '', true ), 'interleaving retains exact saved digital snapshot' );
				$this->same( $cause, $marker['cause'] ?? null, 'interleaving retains terminal evidence' );
				// Cancellation during snapshot insertion can leave Woo cancelled; native payment may resume it.
				$this->confirm( $id ); do_action( 'woocommerce_payment_complete', $id );
				$this->same( $row, $this->row( $id ), 'interleaved repeated payment cannot reactivate' );
				$this->same( $marker, $this->marker( $id ), 'interleaved repeated payment cannot overwrite marker' );
				$this->same( $before, $this->order_snapshot( $id ), 'interleaving leaves Woo purchase/financial record untouched' );
			}
		}
		$this->same( $errors, count( $this->errors ), 'ordered and interleaved events have no snapshot failures' );
		$this->same( $void_errors, count( $this->void_errors ), 'ordered and interleaved events have no void failures' );
		foreach ( [ null, [ array_key_first( $a['faces'] ) => 0 ] ] as $totals ) {
			$id = $this->order( $a ); $order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
			if ( null === $totals ) { unset( $facts['digital_faces_by_game_type'] ); }
			else { $facts['digital_faces_by_game_type'] = $totals; }
			$order->update_meta_data( 'bc_purchase', $facts ); $order->save(); $before = $this->order_snapshot( $id );
			$this->native_void( $id, 'cancellation' ); $this->confirm( $id ); do_action( 'woocommerce_payment_complete', $id );
			$this->same( null, $this->row( $id ), 'unknown saved faces remain absent, never an empty or fabricated snapshot' );
			$this->same( 'cancellation', $this->marker( $id )['cause'] ?? null, 'terminal marker needs identity, not invented face totals' );
			$this->same( $before, $this->order_snapshot( $id ), 'unknown saved totals preserved as-is' );
		}
		// A marker read error must not be interpreted as absence/permission to activate.
		global $wpdb;
		$id = $this->order( $a ); $this->native_void( $id, 'cancellation' ); $marker = $this->marker( $id );
		$fail = static function ( $sql ) { return str_starts_with( $sql, 'SELECT * FROM `' . Migrations::voids_table() . '` WHERE order_id' ) ? 'SELECT * FROM bc_fixture_absent_marker_table' : $sql; };
		$quiet = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $this->confirm( $id ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $quiet ); }
		$this->error( $id, 'bc_entitlement_void_failed' );
		$this->same( null, $this->row( $id ), 'unreadable terminal evidence fails closed without an entitlement' );
		do_action( 'woocommerce_payment_complete', $id );
		$this->same( null, $this->row( $id ), 'redelivery after read recovery stays blocked' );
		$this->same( $marker, $this->marker( $id ), 'read failure and redelivery leave original marker intact' );
		$this->same( $b_before, $this->hall_snapshot( $b['site'] ), 'all event orderings leave other Hall byte-identical' );
	}

	private function earlier_cancellation_payment( array $a, array $b ): void {
		$b_before = $this->hall_snapshot( $b['site'] ); $errors = count( $this->void_errors );
		foreach ( [ 'classic', 'store' ] as $path ) {
			foreach ( [ 'reloaded', 'event-object' ] as $source ) {
				$id = $this->order( $a, 'hybrid', $path ); $before = $this->order_snapshot( $id );
				$this->same( null, $this->row( $id ), "$path $source starts without entitlement" );
				$this->same( null, $this->marker( $id ), "$path $source starts without marker" );
				$calls = 0; $paid_row = null; $transition_seen = null;
				$early = function ( $order_id, $event_order, $transition ) use ( $id, $source, &$calls, &$paid_row, &$transition_seen ): void {
					if ( $id !== (int) $order_id ) { return; }
					++$calls; $transition_seen = $transition;
					$this->same( 'cancelled', wc_get_order( $id )->get_status(), 'cancellation persisted before priority-5 callback' );
					$paying_order = 'event-object' === $source ? $event_order : wc_get_order( $id );
					$this->same( true, $paying_order->payment_complete( 'fixture-early-payment' ), 'earlier callback uses successful native payment_complete' );
					$paid_row = $this->row( $id );
					$this->same( 'active', $paid_row['status'] ?? null, 'payment created active snapshot before cancellation observer' );
					$this->same( null, $this->marker( $id ), 'cancellation marker not yet written at earlier callback' );
				};
				add_action( 'woocommerce_order_status_cancelled', $early, 5, 3 );
				try { $this->native_void( $id, 'cancellation' ); }
				finally { remove_action( 'woocommerce_order_status_cancelled', $early, 5 ); }
				$this->same( 1, $calls, 'temporary earlier callback ran once' );
				$this->same( 'cancelled', $transition_seen['to'] ?? null, 'original transition remains cancellation after nested payment' );
				$this->same( 'processing', wc_get_order( $id )->get_status(), 'native nested payment status remains unchanged' );
				$row = $this->row( $id ); $marker = $this->marker( $id );
				printf( "EARLY CANCELLATION %s %s: entitlement=%s marker=%s\n", $path, $source, $row['status'] ?? 'absent', $marker['cause'] ?? 'absent' );
				$paid_row['status'] = 'void';
				$this->same( $paid_row, $row, 'saved cancellation voids snapshot created by earlier callback; all other fields unchanged' );
				$this->same( 'cancellation', $marker['cause'] ?? null, 'saved cancellation retained despite current paid status' );
				do_action( 'woocommerce_payment_complete', $id ); wc_get_order( $id )->payment_complete( 'fixture-repeat' );
				$this->same( $paid_row, $this->row( $id ), 'repeat payment cannot reactivate earlier-callback cancellation' );
				$this->same( $marker, $this->marker( $id ), 'repeat payment preserves cancellation evidence' );
				$this->same( $before, $this->order_snapshot( $id ), 'earlier-callback cancellation leaves purchase facts and financials untouched' );
			}
		}
		$this->same( $errors, count( $this->void_errors ), 'saved cancellation remains valid evidence after earlier callback payment' );
		// Consuming transition evidence must not accept an ID-only/malformed signal,
		// another Hall's object, or an unsaved cancellation status.
		foreach ( [ 'id-only', 'wrong-transition', 'wrong-id', 'foreign-facts', 'unsaved-status', 'failed-save' ] as $case ) {
			$id = $this->order( $a ); $this->confirm( $id ); $row = $this->row( $id ); $before = $this->order_snapshot( $id );
			// Woo order cloning is shallow: read separate metadata before deliberately corrupting fixture input.
			$event_order = clone wc_get_order( $id ); $event_order->read_meta_data( true );
			$transition = [ 'from' => 'processing', 'to' => 'cancelled' ];
			if ( 'wrong-transition' === $case ) { $transition['to'] = 'processing'; }
			if ( 'wrong-id' === $case ) { $event_order->set_id( $id + 1000 ); }
			if ( 'foreign-facts' === $case ) { $facts = $event_order->get_meta( 'bc_purchase', true ); $facts['site_id'] = $b['site']; $event_order->update_meta_data( 'bc_purchase', $facts ); }
			if ( 'unsaved-status' === $case ) { $event_order->set_status( 'cancelled' ); }
			if ( 'failed-save' === $case ) {
				$fail = static function ( $order ) use ( $id ): void { if ( $order->get_id() === $id && $order->has_status( 'cancelled' ) ) { throw new RuntimeException( 'Fixture refused cancellation persistence.' ); } };
				add_action( 'woocommerce_before_order_object_save', $fail );
				try { $event_order->update_status( 'cancelled' ); }
				finally { remove_action( 'woocommerce_before_order_object_save', $fail ); }
			} elseif ( 'id-only' === $case ) { do_action( 'woocommerce_order_status_cancelled', $id ); }
			else { do_action( 'woocommerce_order_status_cancelled', $id, $event_order, $transition ); }
			$this->same( null, $this->marker( $id ), "$case cannot manufacture cancellation evidence" );
			$this->same( $row, $this->row( $id ), "$case leaves original entitlement unchanged" );
			$this->same( 'processing', wc_get_order( $id )->get_status(), "$case leaves saved Woo status unchanged" );
			$this->same( $before, $this->order_snapshot( $id ), "$case leaves saved purchase/financial record unchanged" );
			$this->same( [ 'bc_entitlement_void_failed', $id, $a['site'] ], end( $this->void_errors ), "$case reports current-Hall evidence failure" );
		}
		$this->same( $b_before, $this->hall_snapshot( $b['site'] ), 'earlier-callback cancellation leaves other Hall byte-identical' );
	}

	private function native_void( int $id, string $cause, bool $rest = false ): void {
		wp_set_current_user( $this->admin );
		try {
			if ( 'cancellation' === $cause ) {
				$this->same( true, wc_get_order( $id )->update_status( 'cancelled' ), 'native saved cancellation succeeds' );
			} else {
				$refund = $this->refund( $id, $this->refund_lines( wc_get_order( $id ), true ), false, $rest );
				$this->same( true, $refund instanceof WC_Order_Refund, 'native manual whole-Package refund succeeds without money transport' );
				$this->same( 1, count( wc_get_order( $id )->get_refunds() ), 'native whole-Package refund persists' );
			}
		} finally { wp_set_current_user( 0 ); }
	}

	private function isolation( array $a, array $b ): void {
		$this->enter( $b['site'] ); $a_before = $this->hall_snapshot( $a['site'] );
		$this->same( true, Migrations::verify_current_site_schema(), 'new Hall automatically provisioned' );
		$id = $this->order( $b, 'digital', 'store' ); $this->confirm( $id ); $row = $this->row( $id );
		$this->same( $b['faces'], json_decode( $row['digital_faces_json'], true ), 'Hall B uses its own colliding Game Type IDs and distinct totals' );
		wc_get_order( $id )->update_status( 'cancelled' ); $row['status'] = 'void';
		$this->same( $row, $this->row( $id ), 'Hall B native cancellation changes only its own entitlement status' );
		$this->same( $a_before, $this->hall_snapshot( $a['site'] ), 'Hall B payment leaves Hall A byte-identical' );
		$this->enter( $a['site'] ); $b_before = $this->hall_snapshot( $b['site'] );
		do_action( 'woocommerce_payment_complete', $id ); // Current Hall only; never switch to the order's claimed site.
		do_action( 'woocommerce_order_status_cancelled', $id );
		$this->same( $b_before, $this->hall_snapshot( $b['site'] ), 'Hall A event cannot read/write Hall B record' );
	}

	private function voiding( array $a, array $b ): void {
		global $wpdb;
		$this->enter( $b['site'] ); $b_id = $this->order( $b ); $this->confirm( $b_id );
		$this->enter( $a['site'] ); $b_before = $this->hall_snapshot( $b['site'] );
		$this->same( true, null !== $this->row( $b_id ), 'void isolation includes colliding Woo order IDs with active snapshots' );
		$gateway = new EntitlementFixtureRefundGateway(); WC()->payment_gateways()->payment_gateways[] = $gateway;
		$errors = count( $this->void_errors );
		foreach ( [ 'classic', 'store' ] as $path ) {
			foreach ( [ 'whole-package', 'whole-order', 'merchandise', 'addon-only', 'partial', 'amount-only', 'gateway-failure', 'cancel', 'status-only-refunded' ] as $case ) {
				$id = $this->order( $a, 'hybrid', $path ); $this->confirm( $id );
				$order = wc_get_order( $id ); $row = $this->row( $id ); $before = $this->order_snapshot( $id );
				$this->same( true, (float) $order->get_discount_total() > 0 && (float) $order->get_total_tax() > 0, 'void fixture includes coupons and tax' );
				wp_set_current_user( $this->admin );
				if ( 'cancel' === $case ) {
					$this->same( true, $order->update_status( 'cancelled' ), "$path native cancellation persists" );
					$this->same( 'cancelled', wc_get_order( $id )->get_status(), 'cancelled status is persisted before observer' );
					$row['status'] = 'void'; $this->same( $row, $this->row( $id ), 'cancellation changes only entitlement status' );
					do_action( 'woocommerce_order_status_cancelled', $id );
					$this->same( $row, $this->row( $id ), 'repeated cancellation is harmless' );
				} elseif ( 'status-only-refunded' === $case ) {
					$order->update_status( 'refunded' );
					$this->same( 'refunded', wc_get_order( $id )->get_status(), 'documented status-only limitation remains' );
					$this->same( [], wc_get_order( $id )->get_refunds(), 'status-only amount refund is refused' );
					$this->same( $row, $this->row( $id ), 'refunded status without successful itemized refund does not void' );
				} else {
					$unit = $this->refund_lines( $order, true ); $merch = $this->refund_lines( $order, false );
					$lines = match ( $case ) { 'whole-order' => $unit + $merch, 'merchandise' => $merch, 'amount-only' => [], default => $unit };
					if ( 'addon-only' === $case ) { $child = array_key_last( $unit ); $lines = [ $child => $unit[ $child ] ]; }
					if ( 'partial' === $case ) { $lines[ array_key_first( $unit ) ]['refund_total'] = '1.00'; }
					$order->set_payment_method( $gateway ); $order->save(); $gateway->succeed = 'gateway-failure' !== $case;
					$result = $this->refund( $id, $lines, true, 'store' === $path );
					$accepted = in_array( $case, [ 'whole-package', 'whole-order', 'merchandise' ], true );
					$this->same( $accepted, $result instanceof WC_Order_Refund, "$path $case native refund result" );
					$this->same( $accepted ? 1 : 0, count( wc_get_order( $id )->get_refunds() ), "$case persisted native refund count" );
					if ( in_array( $case, [ 'whole-package', 'whole-order' ], true ) ) { $row['status'] = 'void'; }
					$this->same( $row, $this->row( $id ), "$case exact snapshot/state after native result" );
					if ( $accepted ) {
						$this->same( true, $result->get_refunded_payment(), 'successful offline-gateway refund flag persisted' );
						do_action( 'woocommerce_refund_created', $result->get_id(), [ 'order_id' => $id, 'refund_payment' => true ] );
						$this->same( $row, $this->row( $id ), 'duplicate completed-refund event changes nothing' );
						if ( 'merchandise' === $case ) {
							$followup = $this->refund( $id, $unit, false, 'store' === $path );
							$this->same( true, $followup instanceof WC_Order_Refund, 'later manual whole-Package refund succeeds independently' );
							$row['status'] = 'void'; $this->same( $row, $this->row( $id ), 'later Package refund voids original snapshot' );
						}
						if ( 'whole-order' !== $case ) {
							do_action( 'woocommerce_payment_complete', $id );
							$this->same( $row, $this->row( $id ), 'unchanged payment observer cannot reactivate refunded Package' );
						}
					}
				}
				$this->same( $before, $this->order_snapshot( $id ), "$path $case original order facts, lines and finances untouched" );
				wp_set_current_user( 0 );
			}
		}
		$this->same( $errors, count( $this->void_errors ), 'normal refunds and cancellation produce no void errors' );
		// Current Catalog is irrelevant, even when the purchased component is archived.
		$id = $this->order( $a ); $this->confirm( $id ); $row = $this->row( $id );
		wp_set_current_user( $this->admin );
		$this->same( false, is_wp_error( ( new BingoPlayProductService() )->archive( $a['first_play'] ) ), 'later Catalog archive succeeds' );
		try {
			$reads = []; $watch = static function ( $sql ) use ( &$reads ) { if ( preg_match( '/\b(?:SELECT|SHOW)\b.*bingo_(?:play_products|packages|package_components|game_types|sessions)/is', $sql ) ) { $reads[] = $sql; } return $sql; };
			add_filter( 'query', $watch );
			try { $result = $this->refund( $id, $this->refund_lines( wc_get_order( $id ), true ) ); }
			finally { remove_filter( 'query', $watch ); }
			$this->same( true, $result instanceof WC_Order_Refund, 'whole-Package refund succeeds after Catalog archive' );
			$this->same( [], $reads, 'refund and void never read current Catalog/Session state' );
			$row['status'] = 'void'; $this->same( $row, $this->row( $id ), 'historical snapshot survives Catalog archive and refund' );
		} finally {
			$this->same( false, is_wp_error( ( new BingoPlayProductService() )->restore( $a['first_play'] ) ), 'fixture Catalog component restored' );
			wp_set_current_user( 0 );
		}
		foreach ( [ 'paper', 'merch', 'digital' ] as $kind ) {
			$id = $this->order( $a, $kind ); // No payment for Digital: specifically no existing entitlement.
			wc_get_order( $id )->update_status( 'cancelled' );
			$this->same( null, $this->row( $id ), "$kind cancellation never invents an entitlement" );
			$this->same( 'digital' === $kind, null !== $this->marker( $id ), "$kind cancellation records a marker only for a digital selection" );
		}
		// A missing snapshot on an otherwise valid paid order must not be invented by refund.
		$id = $this->order( $a ); $this->confirm( $id );
		$wpdb->delete( Migrations::table(), [ 'order_id' => $id ], [ '%d' ] );
		wp_set_current_user( $this->admin ); $result = $this->refund( $id, $this->refund_lines( wc_get_order( $id ), true ) ); wp_set_current_user( 0 );
		$this->same( true, $result instanceof WC_Order_Refund, 'valid refund without an entitlement remains allowed' );
		$this->same( null, $this->row( $id ), 'refund never backfills missing entitlement' );
		$this->same( 'package_refund', $this->marker( $id )['cause'] ?? null, 'refund records block despite missing paid snapshot' );
		// Identity mismatch does not target another Hall or another selection.
		foreach ( [ 'site_id', 'package_id', 'selection_id' ] as $field ) {
			$id = $this->order( $a ); $this->confirm( $id ); $row = $this->row( $id ); $order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
			$facts[ $field ] = match ( $field ) { 'site_id' => $b['site'], 'selection_id' => wp_generate_uuid4(), default => $facts[ $field ] + 100 };
			$order->update_meta_data( 'bc_purchase', $facts ); $order->save(); $order->update_status( 'cancelled' );
			$this->same( $row, $this->row( $id ), "$field mismatch leaves snapshot unchanged and reports review" );
			$this->same( [ 'bc_entitlement_void_failed', $id, $a['site'] ], end( $this->void_errors ), 'void error identifies current Hall/order' );
		}
		// Failed status write must not undo a successful native refund.
		$id = $this->order( $a ); $this->confirm( $id ); $row = $this->row( $id );
		$fail = static function ( $sql ) { return str_starts_with( $sql, 'UPDATE `' . Migrations::table() . '` SET status' ) ? 'SELECT * FROM bc_fixture_absent_void_table' : $sql; };
		wp_set_current_user( $this->admin ); $quiet = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $result = $this->refund( $id, $this->refund_lines( wc_get_order( $id ), true ) ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $quiet ); wp_set_current_user( 0 ); }
		$this->same( true, $result instanceof WC_Order_Refund, 'void storage failure leaves successful refund intact' );
		$this->same( 1, count( wc_get_order( $id )->get_refunds() ), 'successful refund not deleted after void error' );
		$this->same( $row, $this->row( $id ), 'failed status write preserves original snapshot' );
		$this->same( [ 'bc_entitlement_void_failed', $id, $a['site'] ], end( $this->void_errors ), 'storage failure is reported' );
		do_action( 'woocommerce_refund_created', $result->get_id(), [ 'order_id' => $id, 'refund_payment' => false ] );
		$row['status'] = 'void'; $this->same( $row, $this->row( $id ), 'redelivery after storage recovery voids without another refund' );
		$this->same( $b_before, $this->hall_snapshot( $b['site'] ), 'all Hall A refunds/cancellations leave active colliding Hall B byte-identical' );
	}

	private function refund_lines( WC_Order $order, bool $bingo ): array {
		$lines = [];
		foreach ( $order->get_items() as $id => $item ) {
			if ( $bingo !== is_array( $item->get_meta( 'bc_purchase', true ) ) ) { continue; }
			$lines[ $id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ];
		}
		return $lines;
	}
	private function refund( int $id, array $lines, bool $automatic = false, bool $rest = false ): WC_Order_Refund|WP_Error {
		$amount = 0.0;
		foreach ( $lines as $line ) { foreach ( array_merge( [ $line['refund_total'] ], array_values( $line['refund_tax'] ) ) as $value ) { $amount += (float) wc_format_decimal( $value, 2 ); } }
		$amount = [] === $lines ? '1.00' : wc_format_decimal( $amount, 2 );
		if ( ! $rest ) { return wc_create_refund( [ 'order_id' => $id, 'amount' => $amount, 'line_items' => $lines, 'refund_payment' => $automatic ] ); }
		$server = rest_get_server(); ( new WC_REST_Order_Refunds_Controller() )->register_routes();
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $id . '/refunds' ); $public = [];
		foreach ( $lines as $item_id => $line ) {
			$taxes = []; foreach ( $line['refund_tax'] as $rate => $tax ) { $taxes[] = [ 'id' => $rate, 'refund_total' => $tax ]; }
			$public[] = [ 'id' => $item_id, 'quantity' => $line['qty'], 'refund_total' => $line['refund_total'], 'refund_tax' => $taxes ];
		}
		$request->set_body_params( [ 'amount' => $amount, 'line_items' => $public, 'api_refund' => $automatic ] ); $response = $server->dispatch( $request );
		return 201 === $response->get_status() ? wc_get_order( (int) $response->get_data()['id'] ) : new WP_Error( 'fixture_rest_refund_failed', wp_json_encode( $response->get_data() ) );
	}

	private function confirm( int $id ): void {
		$this->same( true, wc_get_order( $id )->payment_complete( 'fixture-confirmed-' . $id ), 'native payment_complete succeeds without payment transport' );
		$this->same( true, wc_get_order( $id )->is_paid(), 'payment stays confirmed even if snapshot refused' );
	}
	private function error( int $id, string $code ): void { $this->same( [ $code, $id, get_current_blog_id() ], end( $this->errors ), 'precise Hall/order-scoped diagnostic ' . $code ); }
	private function row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Migrations::table(), $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Entitlement readback failed.' ); }
		return $row;
	}
	private function marker( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Migrations::voids_table(), $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Void marker readback failed.' ); }
		return $row;
	}

	private function order( array $f, string $kind = 'digital', string $path = 'classic', bool $free = false ): int {
		WC()->cart->empty_cart( false ); wc_clear_notices(); WC()->cart->cart_context = 'shortcode';
		WC()->session->set( 'store_api_draft_order', 0 ); WC()->session->set( 'order_awaiting_payment', 0 );
		$this->same( 0, get_current_user_id(), 'guest at order creation' );
		WC()->cart->add_to_cart( $f['merch'], 10 );
		if ( 'merch' !== $kind ) {
			$package = $f[ $kind ]; $addons = [];
			foreach ( $package['addons'] as $index => $addon ) { $addons[] = [ 'package_addon_id' => $addon, 'quantity' => $index + 1 ]; }
			$result = CustomerCart::add( [ 'session_id' => $f['session'], 'package_id' => $package['package'], 'addons' => $addons ] );
			if ( ! is_string( $result ) ) { throw new RuntimeException( 'Fixture selection failed: ' . wp_json_encode( $result ) ); }
		}
		WC()->cart->apply_coupon( $free ? $f['free_coupon'] : $f['coupon'] ); WC()->cart->calculate_totals();
		if ( 'store' === $path ) {
			$controller = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class );
			$route = $controller->get( 'checkout' ); register_rest_route( 'wc/store/v1', '/checkout', $route->get_args(), true );
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_body_params( [ 'billing_address' => $this->address, 'payment_method' => 'bacs' ] ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
			$response = rest_get_server()->dispatch( $request );
			if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'Store checkout failed: ' . wp_json_encode( $response->get_data() ) ); }
			$id = (int) $response->get_data()['order_id'];
		} else {
			$data = [ 'payment_method' => 'bacs' ]; foreach ( $this->address as $key => $value ) { $data[ 'billing_' . $key ] = $value; }
			$id = WC()->checkout()->create_order( $data );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
			WC()->payment_gateways()->payment_gateways()['bacs']->process_payment( $id );
		}
		if ( $free ) {
			$this->same( 0.0, (float) wc_get_order( $id )->get_total(), "$path actual 100%-coupon checkout totals zero" );
			$this->same( true, wc_get_order( $id )->is_paid(), "$path native zero-total completion remains unchanged" );
		} else { $this->same( 'on-hold', wc_get_order( $id )->get_status(), "$path native order awaits BACS; no online payment falsely confirmed" ); }
		$this->same( $path === 'store' ? 'store-api' : 'checkout', wc_get_order( $id )->get_created_via(), 'native online order provenance' );
		WC()->cart->empty_cart( false ); return (int) $id;
	}

	private function fixture( string $label ): array {
		wp_set_current_user( $this->admin );
		$site = wp_insert_site( [ 'domain' => 'entitlement-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Entitlement Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site; switch_to_blog( $site ); update_option( 'bc_entitlement_test_owner', $this->token );
		try {
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			foreach ( [ 'woocommerce_custom_orders_table_enabled' => 'yes', 'woocommerce_custom_orders_table_data_sync_enabled' => 'no', 'timezone_string' => 'America/New_York', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_based_on' => 'billing', 'woocommerce_default_country' => 'US:NY', 'woocommerce_currency' => 'USD', 'woocommerce_enable_coupons' => 'yes' ] as $key => $value ) { update_option( $key, $value ); }
			update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Fixture offline transfer' ] );
			foreach ( [ 'background_color' => '#ffffff', 'body_background_color' => '#ffffff', 'base_color' => '#555555', 'text_color' => '#222222', 'footer_text_color' => '#444444' ] as $key => $value ) { update_option( 'woocommerce_email_' . $key, $value ); }
			WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'US', 'tax_rate_state' => 'NY', 'tax_rate' => '8.0000', 'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_class' => '' ] );
			$game_a = ( new GameTypeService() )->create( 'Regular' )->game_type['game_type_id']; $game_b = ( new GameTypeService() )->create( 'Winner' )->game_type['game_type_id'];
			$plays = new BingoPlayProductService(); $n = 'a' === $label ? 6 : 9;
			$first_play = $plays->create( $this->product( 'Digital Regular', '60.00' ), 'digital', $n, $game_a )->play_product['bingo_play_product_id'];
			$second_play = $plays->create( $this->product( 'Digital Winner', '50.00' ), 'digital', 5, $game_b )->play_product['bingo_play_product_id'];
			$paper_play = $plays->create( $this->product( 'Paper', '1.00' ), 'paper', 100, null )->play_product['bingo_play_product_id'];
			$result = [];
			foreach ( [ 'digital', 'hybrid', 'paper' ] as $kind ) {
				$components = 'paper' === $kind ? [] : [ [ 'bingo_play_product_id' => $first_play, 'quantity' => 2 ], [ 'bingo_play_product_id' => $second_play, 'quantity' => 3 ] ];
				if ( 'digital' !== $kind ) { $components[] = [ 'bingo_play_product_id' => $paper_play, 'quantity' => 5 ]; }
				$package = ( new PackageService() )->create( $this->product( $kind . ' Package', '20.00' ), $components )->package['package_id'];
				$addons = [];
				if ( 'paper' !== $kind ) { foreach ( [ $first_play, $second_play ] as $play ) { $addons[] = ( new PackageAddonService() )->create( $package, $play, '3.25', 4 )->package_addon['package_addon_id']; } }
				$result[ $kind ] = compact( 'package', 'addons' );
			}
			$service = new SessionService(); $date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$session = $service->create( $date, 'Entitlement test occurrence', false )->session['session_id'];
			foreach ( $result as $package ) { $service->assignPackage( $session, $package['package'] ); } $service->setAvailableForSale( $session, true );
			$merch = $this->product( 'Independent dauber', '2.00' ); $coupon = 'entitlement-' . strtolower( wp_generate_password( 8, false ) );
			$c = new WC_Coupon(); $c->set_code( $coupon ); $c->set_discount_type( 'percent' ); $c->set_amount( '10' ); $c->save();
			$free_coupon = $coupon . '-free'; $c = new WC_Coupon(); $c->set_code( $free_coupon ); $c->set_discount_type( 'percent' ); $c->set_amount( '100' ); $c->save();
			$faces = [ $game_a => $n * 3, $game_b => 25 ];
			return $result + compact( 'site', 'session', 'first_play', 'faces', 'merch', 'coupon', 'free_coupon' );
		} finally { restore_current_blog(); }
	}
	private function product( string $name, string $price ): int {
		$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_virtual( true ); $p->set_tax_status( 'taxable' ); return $p->save();
	}
	private function enter( int $site ): void {
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
		switch_to_blog( $site ); wp_set_current_user( 0 ); WC()->session = null; WC()->cart = null; WC()->customer = null;
		WC()->initialize_session(); WC()->initialize_cart();
		WC()->customer->set_props( array_combine( array_map( static fn( $key ) => 'billing_' . $key, array_keys( $this->address ) ), $this->address ) );
	}
	private function order_snapshot( int $id ): array {
		$order = wc_get_order( $id ); $items = [];
		foreach ( $order->get_items( [ 'line_item', 'coupon', 'tax' ] ) as $key => $item ) { $items[ $key ] = $item->get_data(); }
		return [ $order->get_meta( 'bc_purchase', true ), serialize( $items ), $order->get_total(), $order->get_discount_total(), $order->get_discount_tax(), $order->get_total_tax() ];
	}
	private function hall_snapshot( int $site ): array {
		global $wpdb; $result = [];
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Hall snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}
	private function cleanup(): void {
		global $wpdb;
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); } wp_set_current_user( $this->admin );
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_entitlement_test_owner' ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site ); $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] ); wp_delete_site( $site );
			foreach ( $tables as $table ) { if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables removed' );
		}
	}
	private function same( mixed $expected, mixed $actual, string $label ): void {
		$pass = $expected === $actual;
		if ( $pass ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . ( $pass ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) . "\n";
	}
}
( new DigitalEntitlementSnapshotTest() )->run();
