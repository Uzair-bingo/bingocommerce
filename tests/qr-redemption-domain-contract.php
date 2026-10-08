<?php
/**
 * Pure redemption domain/control-flow checks: php tests/qr-redemption-domain-contract.php
 *
 * No WordPress boot, SQL connection, configuration, HTTP, file fixture or persistent
 * data mutation. WP/Woo, snapshot validation, Catalog mapping/limit, schema checks
 * and lifecycle persistence are explicit in-memory doubles. Production Contract,
 * Purchase and Service are exercised; this is NOT real concurrency, durability,
 * Application Password, database migration or crash-safety verification.
 */
declare(strict_types=1);

namespace {
	if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) || isset( $GLOBALS['wpdb'] ) ) {
		fwrite( STDERR, "Run this isolated harness directly with PHP; never include it in WordPress.\n" );
		exit( 1 );
	}
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	final class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = [] ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	/** Deliberately fails if implementation starts depending on current status/account. */
	class WC_Order {
		public string $origin = 'checkout';
		public mixed $paid_at = '2026-10-05 12:00:00';
		public string $payment = 'disposable-paid-gateway';
		public string $total = '25.00';
		public bool $facts_valid = true;
		public function __construct( public array $facts ) {}
		public function get_meta( string $key, bool $single ): array {
			stage_queries( 'meta' );
			if ( 'bc_purchase' !== $key || ! $single ) { throw new \RuntimeException( 'Unexpected metadata read.' ); }
			return $this->facts;
		}
		public function get_created_via( string $context ): string { stage_queries( 'origin' ); return $this->origin; }
		public function get_date_paid( string $context ): mixed { stage_queries( 'paid_at' ); return $this->paid_at; }
		public function get_payment_method( string $context ): string { stage_queries( 'payment' ); return $this->payment; }
		public function get_total( string $context ): string { stage_queries( 'total' ); return $this->total; }
		public function get_status(): never { throw new \RuntimeException( 'Current status must not control redemption.' ); }
		public function is_paid(): never { throw new \RuntimeException( 'Current paid status must not replace saved payment evidence.' ); }
		public function get_customer_id(): never { throw new \RuntimeException( 'Guest ownership must not require an account.' ); }
	}
	class WC_Order_Refund extends WC_Order {}

	final class RedemptionDatabaseDouble {
		public string $last_error = '';
		public bool $suppressed = false;
		public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
		public function prepare( string $query, mixed ...$values ): string { return $query; }
		public function get_row( string $query, string $mode ): ?array {
			stage_queries( 'locator' );
			$row = $GLOBALS['fixture']['row'];
			return null === $row ? null : $row + [ 'ticket_entitlement_id' => $GLOBALS['fixture']['ticket_reference'] ?? $row['entitlement_id'] ];
		}
	}

	function add_filter( string $tag, callable $callback, int $priority ): void {
		if ( 'query' !== $tag || PHP_INT_MIN !== $priority ) { throw new \RuntimeException( 'Unexpected hook.' ); }
		$GLOBALS['query_observers'][spl_object_id( $callback )] = $callback;
	}
	function remove_filter( string $tag, callable $callback, int $priority ): void { unset( $GLOBALS['query_observers'][spl_object_id( $callback )] ); }
	function stage_queries( string $stage ): void {
		global $wpdb;
		foreach ( $GLOBALS['fixture']['query_plan'][ $stage ] ?? [ false ] as $failure ) {
			// Mirrors WP's query filter preceding last_error reset. No SQL runs.
			foreach ( $GLOBALS['query_observers'] as $observer ) { $observer( 'MOCK READ ' . $stage ); }
			$wpdb->last_error = $failure ? 'PRIVATE simulated SQL error' : '';
		}
		if ( $stage === $GLOBALS['fixture']['throw_stage'] ) { throw new \RuntimeException( 'PRIVATE simulated driver exception' ); }
	}
	function wc_get_order( int $id ): mixed { stage_queries( 'order' ); return $GLOBALS['fixture']['order']; }
	function get_current_blog_id(): int { return $GLOBALS['fixture']['site_id']; }
	function wp_generate_uuid4(): string { return '87654321-4321-4321-8321-cba987654321'; }
}

namespace BingoCommerce\DigitalTickets {
	/** The existing full order-line binding validator is explicitly mocked here. */
	final class EntitlementSnapshot {
		public static function valid_facts( \WC_Order $order, mixed $facts ): bool {
			\stage_queries( 'items' );
			\stage_queries( 'item_meta' );
			return $order->facts_valid;
		}
	}
	final class Migrations {
		public static function is_hall(): bool { return true; }
		public static function verify_current_site_schema(): true { return true; }
		public static function verify_redemption_schema(): true { return true; }
		public static function tickets_table(): string { return 'mock_tickets'; }
		public static function table(): string { return 'mock_entitlements'; }
	}
	final class LifecycleFailure extends \RuntimeException {}

	/** Protocol double only: no locking, SQL, connection or transaction exists. */
	final class LifecycleTransport {
		public static function diagnostics_safe(): bool { return true; }
		public static function refuse_reentry(): bool { return false; }
		public static function capture( bool $redemption ): self {
			$GLOBALS['fixture']['events'][] = 'capture';
			self::fault( 'open' );
			return new self();
		}
		public function acquire(): void { $GLOBALS['fixture']['events'][] = 'acquire'; }
		public function prove( bool $locked = true ): void { $GLOBALS['fixture']['events'][] = 'prove'; self::fault( 'prove' ); }
		public function locate( string $token ): ?array {
			\stage_queries( 'locator' );
			$row = $GLOBALS['fixture']['row'];
			if ( '' !== $GLOBALS['wpdb']->last_error || ( null !== $row && ( ! isset( $row['entitlement_id'] )
				|| (string) $row['entitlement_id'] !== (string) ( $GLOBALS['fixture']['ticket_reference'] ?? $row['entitlement_id'] ) ) ) ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			return $row;
		}
		public function close(): void { $GLOBALS['fixture']['events'][] = 'close'; }
		public function marker( int $order ): ?array { $GLOBALS['fixture']['events'][] = 'marker'; return $GLOBALS['fixture']['marker']; }
		public function ticket( string $token ): ?array { $GLOBALS['fixture']['events'][] = 'ticket'; return $GLOBALS['fixture']['row']; }
		public function request( string $id ): ?array { $GLOBALS['fixture']['events'][] = 'request'; return $GLOBALS['fixture']['requests'][ $id ] ?? null; }
		public function claim( array $command, string $intent, string $redemption ): void {
			self::fault( 'claim_before' );
			$GLOBALS['fixture']['requests'][ $command['request_id'] ] = [ 'state' => 'pending', 'intent_hash' => $intent,
				'player_tablet_id' => $command['player_tablet_id'], 'redemption_id' => $redemption ];
			self::fault( 'claim_after' );
		}
		public function accepted( int $entitlement ): ?array {
			foreach ( $GLOBALS['fixture']['requests'] as $request ) {
				if ( 'accepted' === $request['state'] && $entitlement === $request['accepted_entitlement_id'] ) { return $request; }
			}
			return null;
		}
		public function usage( int $session, string $tablet ): array {
			$rows = [];
			foreach ( $GLOBALS['fixture']['requests'] as $request ) {
				if ( 'accepted' === $request['state'] && $session === $request['session_id'] && $tablet === $request['player_tablet_id'] ) { $rows[] = $request; }
			}
			return array_merge( $rows, $GLOBALS['fixture']['prior_usage'] );
		}
		public function accept( string $id, string $intent, int $entitlement, int $session, string $faces, string $json ): void {
			self::fault( 'accept_before' );
			++$GLOBALS['fixture']['accept_calls'];
			$GLOBALS['fixture']['requests'][ $id ] += [ 'accepted_entitlement_id' => $entitlement, 'session_id' => $session,
				'contributions_json' => $faces, 'response_json' => $json ];
			$GLOBALS['fixture']['requests'][ $id ]['state'] = 'accepted';
			self::fault( 'accept_after' );
		}
		private static function fault( string $phase ): void {
			if ( $phase === $GLOBALS['fixture']['fault'] ) { throw new LifecycleFailure( 'write_outcome_unknown' ); }
		}
	}
}

namespace BingoCommerce\Catalog {
	final class ElectronicLimit {
		public static function effective_limit(): mixed { return $GLOBALS['fixture']['limit']; }
	}
	final class RedemptionGameTypes {
		public static function map( array $ids ): mixed { return $GLOBALS['fixture']['mapping']; }
	}
}

namespace {
	use BingoCommerce\DigitalTickets\RedemptionContract as Contract;
	use BingoCommerce\DigitalTickets\RedemptionPurchase as Purchase;
	use BingoCommerce\DigitalTickets\RedemptionService as Service;

	foreach ( [ 'RedemptionContract', 'RedemptionPurchase', 'RedemptionService' ] as $file ) {
		require ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/src/' . $file . '.php';
	}
	$assertions = 0;
	function expect( bool $value, string $message ): void {
		global $assertions;
		++$assertions;
		if ( ! $value ) { throw new \RuntimeException( $message ); }
	}
	function error_is( mixed $result, string $code, int $status ): bool {
		return $result instanceof WP_Error && $code === $result->get_error_code()
			&& $status === $result->get_error_data()['status'];
	}
	function reset_fixture(): array {
		$GLOBALS['query_observers'] = [];
		$GLOBALS['wpdb'] = new RedemptionDatabaseDouble();
		$facts = [ 'version' => 1, 'site_id' => 12, 'session_id' => 314, 'session_name' => 'Saved late Session',
			'session_date' => '2020-10-06', 'package_id' => 27, 'package_name' => 'Saved Hybrid', 'package_type' => 'hybrid',
			'selection_id' => '11111111-1111-4111-8111-111111111111', 'digital_faces_by_game_type' => [ 11 => 12, 22 => 5 ],
			'start_time_local' => null, 'timezone_name' => null, 'starts_at_utc' => null ];
		$row = [ 'entitlement_id' => 7, 'order_id' => 9, 'session_id' => 314, 'package_id' => 27,
			'selection_id' => $facts['selection_id'], 'snapshot_version' => 1, 'digital_faces_json' => '{"11":12,"22":5}',
			'status' => 'active', 'confirmed_at' => '2020-10-05 12:00:00' ];
		$GLOBALS['fixture'] = [ 'site_id' => 12, 'order' => new WC_Order( $facts ), 'row' => $row, 'query_plan' => [],
			'throw_stage' => null, 'mapping' => [ 11 => 8, 22 => 6 ], 'limit' => 20, 'marker' => null,
			'requests' => [], 'prior_usage' => [], 'events' => [], 'fault' => null, 'accept_calls' => 0 ];
		return [ 'site_id' => 12, 'portal_hall_id' => 42, 'request_id' => '12345678-1234-4123-8123-123456789abc',
			'player_tablet_id' => ' Tablet A ', 'caller_id' => 'Caller-1', 'qr' => 'BC1.12.' . str_repeat( 'a', 64 ) ];
	}
	function assert_no_acceptance( mixed $result, string $code, int $status ): void {
		expect( error_is( $result, $code, $status ), 'Expected safe ' . $code . '.' );
		expect( 0 === $GLOBALS['fixture']['accept_calls'], 'Failure must not accept anything.' );
	}

	$command = reset_fixture();
	expect( true === Contract::validate( $command ), 'Canonical request accepted.' );
	foreach ( [ '', ' ' . $command['qr'], $command['qr'] . "\n", str_replace( 'BC1', 'bc1', $command['qr'] ),
		'BC1.012.' . str_repeat( 'a', 64 ), 'BC1.0.' . str_repeat( 'a', 64 ), 'BC1.12.' . str_repeat( 'A', 64 ),
		'BC1.12.' . str_repeat( 'a', 63 ), 'BC1.12.' . str_repeat( 'a', 65 ), null, [] ] as $qr ) {
		expect( error_is( Contract::validate( array_replace( $command, [ 'qr' => $qr ] ) ), 'malformed_qr', 400 ), 'Strict QR grammar.' );
	}
	expect( error_is( Contract::validate( array_replace( $command, [ 'qr' => 'BC1.13.' . str_repeat( 'a', 64 ) ] ) ), 'wrong_hall', 403 ), 'QR cannot select another Hall.' );
	foreach ( [ strtoupper( $command['request_id'] ), ' ' . $command['request_id'], '12345678-1234-5123-8123-123456789abc', '', null ] as $id ) {
		expect( error_is( Contract::validate( array_replace( $command, [ 'request_id' => $id ] ) ), 'invalid_request', 400 ), 'Canonical lower UUID v4 required.' );
	}
	foreach ( [ 'site_id' => '12', 'portal_hall_id' => 0, 'operating_commerce_session_id' => '314' ] as $key => $value ) {
		expect( error_is( Contract::validate( array_replace( $command, [ $key => $value ] ) ), 'invalid_request', 400 ), 'Native positive trusted identity required.' );
	}
	foreach ( [ ' ', 'Tablet', ' Tablet ', str_repeat( 'é', 64 ) ] as $tablet ) {
		expect( Contract::opaque( $tablet, 128 ), 'Opaque valid bytes preserved.' );
		$copy = array_replace( $command, [ 'player_tablet_id' => $tablet ] );
		expect( true === Contract::validate( $copy ) && $tablet === $copy['player_tablet_id'], 'Validation must not normalize tablet identity.' );
	}
	foreach ( [ '', str_repeat( 'é', 65 ), "tab\nlet", "tab\u{0085}let", "\xff", 7, null ] as $tablet ) {
		expect( ! Contract::opaque( $tablet, 128 ), 'Empty/overflow/control/non-UTF8 identity refused.' );
	}
	expect( Contract::opaque( str_repeat( 'c', 191 ), 191 ) && ! Contract::opaque( str_repeat( 'c', 192 ), 191 ), 'Caller identity separately bounded.' );
	$intent = Contract::intent( $command );
	expect( $intent === Contract::intent( array_replace( $command, [ 'caller_id' => 'Caller-2' ] ) ), 'Authorized relay change does not change tablet intent.' );
	foreach ( [ 'site_id' => 13, 'portal_hall_id' => 43, 'player_tablet_id' => 'Tablet A', 'qr' => 'BC1.12.' . str_repeat( 'b', 64 ), 'operating_commerce_session_id' => 314 ] as $key => $value ) {
		expect( $intent !== Contract::intent( array_replace( $command, [ $key => $value ] ) ), 'Intent separates ' . $key . '.' );
	}
	expect( $intent === Contract::intent( array_replace( $command, [ 'request_id' => '22345678-1234-4123-8123-123456789abc' ] ) ), 'Request key is separate from immutable intent fingerprint.' );

	expect( [ 11 => 12, 22 => 5 ] === Contract::faces( [ 22 => 5, 11 => 12 ] ), 'Frozen faces use canonical integer identities/order.' );
	foreach ( [ null, [], [ 0 => 12 ], [ 11 => 0 ], [ 11 => -1 ], [ 11 => 1.5 ], [ 11 => '12' ], [ '011' => 12 ] ] as $faces ) {
		expect( null === Contract::faces( $faces ), 'Unreadable/invalid contributions are never zero.' );
	}
	$incoming = [ 11 => 12, 22 => 5 ];
	expect( Contract::within_limit( $incoming, [ [ 11 => 8, 22 => 15 ], [ 99 => 1000 ] ], 20 ), 'Each Game Type checked independently; unrelated types do not subtract allowance.' );
	expect( ! Contract::within_limit( $incoming, [ [ 11 => 8, 22 => 16 ] ], 20 ), 'One exceeded Game Type rejects the entire receipt.' );
	expect( [ 11 => 12, 22 => 5 ] === $incoming, 'Limit calculation never mutates the incoming contribution.' );
	expect( ! Contract::within_limit( [ 11 => 1 ], [ [ 11 => PHP_INT_MAX ] ], PHP_INT_MAX ), 'Overflow fails closed.' );
	expect( Contract::within_limit( [ 11 => PHP_INT_MAX - 1 ], [ [ 11 => 1 ] ], PHP_INT_MAX ), 'Exact integer ceiling remains valid.' );
	expect( ! Contract::within_limit( [ 11 => 21 ], [], 20 ), 'A single oversized receipt is rejected.' );

	$purchase = Purchase::read( $GLOBALS['fixture']['row'] );
	expect( is_array( $purchase ) && null === $purchase['session']['start_time_local'] && null === $purchase['session']['timezone_name'] && null === $purchase['session']['starts_at_utc'], 'Legitimate all-null recorded timing preserved.' );
	expect( '2020-10-06' === $purchase['session']['date'], 'Past occurrence is not expired by a new policy.' );
	expect( [] === $GLOBALS['query_observers'], 'Query observer removed after success.' );
	foreach ( [ 'checkout', 'store-api' ] as $origin ) {
		$GLOBALS['fixture']['order']->origin = $origin;
		expect( is_array( Purchase::read( $GLOBALS['fixture']['row'] ) ), 'Paid Processing/guest facts do not call current status/is_paid/customer checks.' );
	}
	$GLOBALS['fixture']['order']->facts['start_time_local'] = '18:00:00';
	$GLOBALS['fixture']['order']->facts['timezone_name'] = 'America/New_York';
	$GLOBALS['fixture']['order']->facts['starts_at_utc'] = '2020-10-06 22:00:00';
	$purchase = Purchase::read( $GLOBALS['fixture']['row'] );
	expect( is_array( $purchase ) && '2020-10-06T22:00:00Z' === $purchase['session']['starts_at_utc'], 'Recorded timing converted only at the wire boundary.' );
	foreach ( [ 'start_time_local' => '25:00:00', 'timezone_name' => 'Not/AZone', 'starts_at_utc' => '2020-10-06 23:00:00' ] as $key => $value ) {
		$old = $GLOBALS['fixture']['order']->facts[ $key ]; $GLOBALS['fixture']['order']->facts[ $key ] = $value;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Invalid or inconsistent recorded timing refused.' );
		$GLOBALS['fixture']['order']->facts[ $key ] = $old;
	}
	foreach ( [ 'session_name' => '', 'package_name' => '   ', 'package_type' => 'paper', 'session_id' => 315, 'package_id' => 28, 'selection_id' => 'other', 'digital_faces_by_game_type' => [ 11 => 13, 22 => 5 ] ] as $key => $value ) {
		reset_fixture(); $GLOBALS['fixture']['order']->facts[ $key ] = $value;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Frozen identity/detail mismatch refused: ' . $key );
	}
	foreach ( [ 'start_time_local', 'timezone_name', 'starts_at_utc' ] as $key ) {
		reset_fixture(); unset( $GLOBALS['fixture']['order']->facts[ $key ] );
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Missing timing key is not a saved null.' );
	}
	foreach ( [ 'snapshot_version' => 2, 'confirmed_at' => '2020-02-30 12:00:00', 'digital_faces_json' => 'broken' ] as $key => $value ) {
		reset_fixture(); $GLOBALS['fixture']['row'][ $key ] = $value;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Invalid entitlement payload refused.' );
	}
	foreach ( [ 'origin' => 'admin', 'paid_at' => null, 'payment' => '', 'total' => '0.00', 'facts_valid' => false ] as $key => $value ) {
		reset_fixture(); $GLOBALS['fixture']['order']->$key = $value;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Existing payment/issuance evidence preserved.' );
	}
	foreach ( [ null, new \stdClass(), new WC_Order_Refund( [] ) ] as $order ) {
		reset_fixture(); $GLOBALS['fixture']['order'] = $order;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'saved_purchase_invalid', 422 ), 'Missing/non-order/refund order refused after successful read.' );
	}
	foreach ( [ 'meta', 'items', 'item_meta' ] as $stage ) {
		reset_fixture(); $GLOBALS['fixture']['query_plan'][ $stage ] = [ true, false ];
		$result = Purchase::read( $GLOBALS['fixture']['row'] );
		expect( error_is( $result, 'verification_unavailable', 503 ), 'Earlier ' . $stage . ' failure survives later successful read.' );
		expect( '' === $GLOBALS['wpdb']->last_error, 'Final query success alone must not hide prior failure.' );
		expect( [] === $GLOBALS['query_observers'], 'Failure removes the temporary observer.' );
		expect( ! str_contains( $result->get_error_message(), 'PRIVATE' ), 'No raw query/exception text escapes.' );
	}
	reset_fixture(); $GLOBALS['fixture']['query_plan']['meta'] = [ true, false ]; $GLOBALS['fixture']['order']->facts_valid = false;
	expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'verification_unavailable', 503 ), 'Read failure overrides apparent invalid facts; never a false422.' );
	foreach ( [ 'order', 'meta', 'item_meta', 'total' ] as $stage ) {
		reset_fixture(); $GLOBALS['fixture']['throw_stage'] = $stage;
		expect( error_is( Purchase::read( $GLOBALS['fixture']['row'] ), 'verification_unavailable', 503 ), 'Driver exception is unavailable.' );
		expect( [] === $GLOBALS['query_observers'], 'Exception removes temporary observer.' );
	}

	$command = reset_fixture();
	$response = Service::redeem( $command );
	expect( is_array( $response ) && false === $response['recovered'] && 1 === $GLOBALS['fixture']['accept_calls'], 'One new acceptance in protocol double.' );
	expect( [ [ 'portal_game_type_id' => 6, 'faces' => 5 ], [ 'portal_game_type_id' => 8, 'faces' => 12 ] ] === $response['digital_faces_by_game_type'], 'Only frozen digital totals mapped to verified Portal IDs; no component reconstruction.' );
	expect( [] === array_intersect( [ 'qr', 'order_id', 'caller_id', 'billing', 'api_key' ], array_keys( $response ) ), 'Response excludes private and relay-only fields.' );
	$events = $GLOBALS['fixture']['events']; $marker = array_search( 'marker', $events, true );
	expect( 'capture' === $events[0] && [ 'marker', 'ticket', 'request' ] === array_slice( $events, $marker, 3 ), 'Context capture precedes preparation; lifecycle read order remains marker/ticket/request.' );
	expect( 'close' === end( $GLOBALS['fixture']['events'] ), 'Lifecycle guard closes on acceptance.' );
	$GLOBALS['fixture']['mapping'] = Contract::error( 'mapping_unavailable' ); $GLOBALS['fixture']['limit'] = null;
	$GLOBALS['fixture']['order']->facts['package_name'] = 'Changed Catalog-like label';
	$recovery = Service::redeem( array_replace( $command, [ 'caller_id' => 'Caller-2' ] ) );
	expect( is_array( $recovery ) && true === $recovery['recovered'] && 1 === $GLOBALS['fixture']['accept_calls'], 'Original accepted request recovers across authorized Callers without adding faces.' );
	$expected = $response; $expected['recovered'] = true;
	expect( $expected === $recovery, 'Recovery uses stored response, not current mutable details/mappings/limit.' );
	$fresh = array_replace( $command, [ 'request_id' => '22345678-1234-4123-8123-123456789abc' ] );
	expect( error_is( Service::redeem( $fresh ), 'already_redeemed', 409 ), 'Fresh scan rejected even on original tablet.' );
	expect( 1 === $GLOBALS['fixture']['accept_calls'], 'Fresh duplicate adds no allowance.' );
	expect( error_is( Service::redeem( array_replace( $command, [ 'player_tablet_id' => 'Other tablet' ] ) ), 'request_id_conflict', 409 ), 'Conflicting request identity cannot recover.' );
	$GLOBALS['fixture']['marker'] = [ 'order_id' => 9 ];
	expect( error_is( Service::redeem( $command ), 'ticket_voided', 410 ), 'Terminal marker blocks original recovery even if entitlement still active.' );
	expect( 1 === $GLOBALS['fixture']['accept_calls'], 'Later void does not erase accepted contribution history.' );

	foreach ( [ 'claim_before', 'claim_after', 'accept_before', 'accept_after' ] as $fault ) {
		$command = reset_fixture(); $GLOBALS['fixture']['fault'] = $fault;
		expect( error_is( Service::redeem( $command ), 'write_outcome_unknown', 503 ), 'Uncertain phase fails safely: ' . $fault );
		$GLOBALS['fixture']['fault'] = null;
		$retry = Service::redeem( $command );
		expect( is_array( $retry ) && ( 'accept_after' === $fault ) === $retry['recovered'], 'Same intent resolves the known in-memory result after ' . $fault );
		expect( 1 === $GLOBALS['fixture']['accept_calls'], 'Fault/recovery path calls acceptance once: ' . $fault );
	}
	$command = reset_fixture(); $GLOBALS['fixture']['marker'] = [ 'order_id' => 9 ];
	assert_no_acceptance( Service::redeem( $command ), 'ticket_voided', 410 );
	$command = reset_fixture(); $GLOBALS['fixture']['row']['status'] = 'void';
	assert_no_acceptance( Service::redeem( $command ), 'ticket_voided', 410 );
	$command = reset_fixture(); $GLOBALS['fixture']['row'] = null;
	assert_no_acceptance( Service::redeem( $command ), 'ticket_not_found', 404 );
	$command = reset_fixture(); $GLOBALS['fixture']['row']['entitlement_id'] = null; $GLOBALS['fixture']['ticket_reference'] = 7;
	assert_no_acceptance( Service::redeem( $command ), 'verification_unavailable', 503 );
	expect( ! in_array( 'acquire', $GLOBALS['fixture']['events'], true ) && 'close' === end( $GLOBALS['fixture']['events'] ), 'Orphan ticket releases captured context before any lock or write.' );
	$command = reset_fixture(); $GLOBALS['fixture']['query_plan']['locator'] = [ true ];
	assert_no_acceptance( Service::redeem( $command ), 'verification_unavailable', 503 );
	$command = reset_fixture(); $GLOBALS['fixture']['mapping'] = Contract::error( 'mapping_unavailable' );
	assert_no_acceptance( Service::redeem( $command ), 'mapping_unavailable', 503 );
	foreach ( [ null, 0, '20', Contract::error( 'verification_unavailable' ) ] as $limit ) {
		$command = reset_fixture(); $GLOBALS['fixture']['limit'] = $limit;
		assert_no_acceptance( Service::redeem( $command ), 'verification_unavailable', 503 );
	}
	$command = reset_fixture(); $GLOBALS['fixture']['prior_usage'] = [ [ 'contributions_json' => '{"11":9,"22":0}' ] ];
	assert_no_acceptance( Service::redeem( $command ), 'verification_unavailable', 503 );
	$command = reset_fixture(); $GLOBALS['fixture']['prior_usage'] = [ [ 'contributions_json' => '{"11":9,"22":1}' ] ];
	assert_no_acceptance( Service::redeem( $command ), 'electronic_limit_exceeded', 409 );
	$command = reset_fixture(); $command['operating_commerce_session_id'] = 315;
	assert_no_acceptance( Service::redeem( $command ), 'wrong_session', 409 );
	$command = reset_fixture(); $GLOBALS['fixture']['order']->facts_valid = false;
	assert_no_acceptance( Service::redeem( $command ), 'saved_purchase_invalid', 422 );

	// Exercise the actual Service guard before its accept call, not just a mirrored constant.
	$command = reset_fixture(); $faces = []; $mapping = [];
	for ( $id = 1; $id <= 9000; ++$id ) { $faces[ $id ] = 1; $mapping[ $id ] = $id; }
	$GLOBALS['fixture']['row']['digital_faces_json'] = json_encode( $faces, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR );
	$GLOBALS['fixture']['order']->facts['digital_faces_by_game_type'] = $faces;
	$GLOBALS['fixture']['mapping'] = $mapping;
	$prepared = Purchase::read( $GLOBALS['fixture']['row'] );
	$wire = json_encode( Contract::response( $command, $prepared, $mapping, wp_generate_uuid4() ), JSON_THROW_ON_ERROR );
	expect( strlen( $wire ) > Contract::MAX_RESPONSE_BYTES, 'Oversized success fixture crosses complete serialized wire bound.' );
	assert_no_acceptance( Service::redeem( $command ), 'saved_purchase_invalid', 422 );
	$command = reset_fixture(); $faces = []; $mapping = [];
	for ( $id = 1; $id <= 1001; ++$id ) { $faces[ $id ] = 1; $mapping[ $id ] = $id; }
	$GLOBALS['fixture']['row']['digital_faces_json'] = json_encode( $faces, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR );
	$GLOBALS['fixture']['order']->facts['digital_faces_by_game_type'] = $faces; $GLOBALS['fixture']['mapping'] = $mapping;
	$result = Service::redeem( $command );
	expect( is_array( $result ) && 1001 === count( $result['digital_faces_by_game_type'] ), 'No arbitrary1000 Game Type limit beneath the response-byte ceiling.' );
	expect( [] === $GLOBALS['query_observers'], 'Harness leaves no query observer behind.' );
	echo "PASS: {$assertions} pure redemption domain assertions (WP/Woo/snapshot validator/Catalog/schema/lifecycle persistence mocked; no SQL, data changes or concurrency claim).\n";
}
