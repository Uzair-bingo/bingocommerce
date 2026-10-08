<?php
/** Independent-process domain worker. All input uses stdin, never process arguments. */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { fwrite( STDERR, "Fixture refused.\n" ); exit( 1 ); }
$bc_qr_job = json_decode( stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
require __DIR__ . '/fixtures/qr-redemption.php';
if ( false === has_action( 'woocommerce_payment_complete', [ \BingoCommerce\DigitalTickets\EntitlementSnapshot::class, 'on_payment_complete' ] ) ) {
	\BingoCommerce\DigitalTickets\Plugin::init();
}
use BingoCommerce\DigitalTickets\LifecycleTransport;
use BingoCommerce\DigitalTickets\RedemptionContract;
use BingoCommerce\DigitalTickets\RedemptionService;

try {
	QRRedemptionFixture::assertDatabase();
	add_filter( 'pre_wp_mail', '__return_true' );
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP from fixtures.' ) );
	$bc_qr_site = $bc_qr_job['command']['site_id'];
	if ( ! is_int( $bc_qr_site ) || $bc_qr_site <= 4 || ! wp_is_uuid( $bc_qr_job['owner'], 4 )
		|| $bc_qr_job['owner'] !== get_blog_option( $bc_qr_site, QRRedemptionFixture::OWNER_OPTION ) ) { throw new RuntimeException( 'Fixture Hall refused.' ); }
	$bc_qr_dir = $bc_qr_job['barrier'] ?? null;
	if ( null !== $bc_qr_dir && ( ! is_string( $bc_qr_dir ) || $bc_qr_dir !== sys_get_temp_dir() . '/bc-qr-' . $bc_qr_job['owner'] || ! is_dir( $bc_qr_dir ) ) ) { throw new RuntimeException( 'Fixture barrier refused.' ); }
	switch_to_blog( $bc_qr_site );
	$bc_qr_mode = $bc_qr_job['mode'] ?? 'redeem';
	if ( null !== $bc_qr_dir && ! in_array( $bc_qr_mode, [ 'hold', 'row_hold', 'metadata_hold' ], true ) ) {
		file_put_contents( $bc_qr_dir . '/ready-' . (int) $bc_qr_job['index'], 'ready' );
		$bc_qr_deadline = microtime( true ) + 15;
		while ( ! is_file( $bc_qr_dir . '/go' ) && microtime( true ) < $bc_qr_deadline ) { usleep( 5000 ); }
		if ( ! is_file( $bc_qr_dir . '/go' ) ) { throw new RuntimeException( 'Fixture barrier timed out.' ); }
	}
	$bc_qr_guard = null;
	$bc_qr_started = hrtime( true );
	if ( in_array( $bc_qr_mode, [ 'row_hold', 'metadata_hold' ], true ) ) {
		global $wpdb;
		$bc_qr_table = \BingoCommerce\DigitalTickets\Migrations::redemptions_table();
		if ( 'row_hold' === $bc_qr_mode ) {
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT request_id FROM %i WHERE request_id=%s FOR UPDATE', $bc_qr_table, $bc_qr_job['command']['request_id'] ) );
		} else { $wpdb->query( $wpdb->prepare( 'LOCK TABLES %i WRITE', $bc_qr_table ) ); }
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture database blocker failed.' ); }
		file_put_contents( $bc_qr_dir . '/holding', 'ready' ); usleep( 9000000 );
		$wpdb->query( 'row_hold' === $bc_qr_mode ? 'ROLLBACK' : 'UNLOCK TABLES' );
		echo json_encode( [ 'kind' => 'held', 'elapsed_ms' => ( hrtime( true ) - $bc_qr_started ) / 1e6 ] ) . "\n";
	} elseif ( 'hold' === $bc_qr_mode || 'claim_exit' === $bc_qr_mode ) {
		$bc_qr_claim_identity = wp_generate_uuid4();
		$bc_qr_guard = LifecycleTransport::capture( true ); $bc_qr_guard->acquire();
		if ( 'claim_exit' === $bc_qr_mode ) {
			$bc_qr_guard->claim( $bc_qr_job['command'], RedemptionContract::intent( $bc_qr_job['command'] ), $bc_qr_claim_identity );
			// Deliberate process exit: pending intent survives without acceptance.
			echo json_encode( [ 'kind' => 'claimed' ] ) . "\n"; exit( 0 );
		}
		file_put_contents( $bc_qr_dir . '/holding', 'ready' );
		usleep( 9000000 ); $bc_qr_guard->close(); $bc_qr_guard = null;
		echo json_encode( [ 'kind' => 'held', 'elapsed_ms' => ( hrtime( true ) - $bc_qr_started ) / 1e6 ] ) . "\n";
	} elseif ( in_array( $bc_qr_mode, [ 'void', 'void_redelivery', 'payment', 'package_refund', 'payment_redelivery', 'inspect_order' ], true ) ) {
		$bc_qr_order = wc_get_order( $bc_qr_job['order_id'] );
		if ( ! $bc_qr_order instanceof WC_Order || $bc_qr_order->get_meta( QRRedemptionFixture::OWNER_OPTION ) !== $bc_qr_job['owner'] ) { throw new RuntimeException( 'Fixture order refused.' ); }
		$bc_qr_events = 0;
		add_action( 'woocommerce_payment_complete', static function ( $id ) use ( &$bc_qr_events, $bc_qr_job ): void {
			if ( (int) $id !== $bc_qr_job['order_id'] ) { return; }
			++$bc_qr_events;
			if ( isset( $bc_qr_job['payment_signal'] ) ) {
				global $wpdb;
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $bc_qr_job['payment_signal'] ) ) ) { throw new RuntimeException( 'Fixture payment signal failed.' ); }
			}
		}, 1 );
		if ( 'void' === $bc_qr_mode ) { $bc_qr_order->update_status( 'cancelled' ); }
		elseif ( 'void_redelivery' === $bc_qr_mode ) { \BingoCommerce\DigitalTickets\EntitlementVoiding::on_cancelled( $bc_qr_order->get_id() ); }
		elseif ( 'package_refund' === $bc_qr_mode ) {
			$bc_qr_lines = [];
			foreach ( $bc_qr_order->get_items() as $id => $item ) {
				if ( 'package' === ( $item->get_meta( 'bc_purchase', true )['role'] ?? null ) ) {
					$bc_qr_lines[ $id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => $item->get_taxes()['total'] ];
				}
			}
			if ( 1 !== count( $bc_qr_lines ) ) { throw new RuntimeException( 'Fixture Package refund line refused.' ); }
			$bc_qr_refund = wc_create_refund( [ 'order_id' => $bc_qr_order->get_id(), 'amount' => reset( $bc_qr_lines )['refund_total'],
				'line_items' => $bc_qr_lines, 'refund_payment' => false, 'restock_items' => false ] );
			if ( ! $bc_qr_refund instanceof WC_Order_Refund ) { throw new RuntimeException( 'Fixture Package refund failed.' ); }
		} elseif ( 'inspect_order' !== $bc_qr_mode ) {
			$bc_qr_order->payment_complete( 'fixture-no-money-' . $bc_qr_order->get_id() );
			if ( 'payment_redelivery' === $bc_qr_mode ) { do_action( 'woocommerce_payment_complete', $bc_qr_order->get_id(), 'fixture-redelivery' ); }
		}
		$bc_qr_items = [];
		foreach ( $bc_qr_order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon', 'tax' ] ) as $id => $item ) { $bc_qr_items[ $id ] = $item->get_data(); }
		echo json_encode( [ 'kind' => $bc_qr_mode, 'elapsed_ms' => ( hrtime( true ) - $bc_qr_started ) / 1e6,
			'payment_events' => $bc_qr_events, 'paid' => $bc_qr_order->is_paid(),
			'purchase' => $bc_qr_order->get_meta( 'bc_purchase', true ), 'items' => $bc_qr_items,
			'financial' => [ $bc_qr_order->get_currency(), $bc_qr_order->get_total(), $bc_qr_order->get_subtotal(), $bc_qr_order->get_discount_total(),
				$bc_qr_order->get_discount_tax(), $bc_qr_order->get_total_tax(), $bc_qr_order->get_shipping_total(), $bc_qr_order->get_shipping_tax() ],
			'refunds' => array_map( static fn( $refund ) => [ 'id' => $refund->get_id(), 'amount' => wc_format_decimal( $refund->get_amount(), 2 ) ], $bc_qr_order->get_refunds() ) ], JSON_THROW_ON_ERROR ) . "\n";
	} else {
		if ( in_array( $bc_qr_mode, [ 'change_context', 'change_context_restore', 'replace_connection', 'outer_transaction', 'autocommit_off', 'lifecycle_reentry', 'malformed_reentry', 'mismatched_void_reentry' ], true ) ) {
			$bc_qr_injected = false;
			add_filter( 'query', static function ( string $sql ) use ( &$bc_qr_injected, $bc_qr_mode, $bc_qr_job ): string {
				if ( ! $bc_qr_injected && str_contains( $sql, 'SHOW FULL COLUMNS' ) ) {
					$bc_qr_injected = true;
					global $wpdb;
					if ( in_array( $bc_qr_mode, [ 'change_context', 'change_context_restore' ], true ) ) {
						if ( $bc_qr_job['owner'] !== get_blog_option( $bc_qr_job['other_site'], QRRedemptionFixture::OWNER_OPTION ) ) { throw new RuntimeException( 'Other fixture Hall refused.' ); }
						switch_to_blog( $bc_qr_job['other_site'] );
						if ( 'change_context_restore' === $bc_qr_mode ) { restore_current_blog(); }
					} elseif ( 'replace_connection' === $bc_qr_mode ) { $wpdb->close(); $wpdb->db_connect( false ); }
					elseif ( 'lifecycle_reentry' === $bc_qr_mode ) { \BingoCommerce\DigitalTickets\EntitlementVoiding::enforce_block( $bc_qr_job['order_id'], $bc_qr_job['facts'] ); }
					elseif ( 'malformed_reentry' === $bc_qr_mode ) { RedemptionService::redeem( [] ); }
					elseif ( 'mismatched_void_reentry' === $bc_qr_mode ) {
						$wrong_facts = array_replace( $bc_qr_job['facts'], [ 'site_id' => $bc_qr_job['other_site'] ] );
						\BingoCommerce\DigitalTickets\EntitlementVoiding::enforce_block( $bc_qr_job['order_id'], $wrong_facts );
					}
					elseif ( 'autocommit_off' === $bc_qr_mode ) { $wpdb->query( 'SET autocommit=0' ); }
					else { $wpdb->query( 'START TRANSACTION' ); }
				}
				return $sql;
			}, PHP_INT_MIN );
		}
		$bc_qr_result = RedemptionService::redeem( $bc_qr_job['command'] );
		$bc_qr_elapsed = ( hrtime( true ) - $bc_qr_started ) / 1e6;
		if ( 'lost_response' === $bc_qr_mode && is_array( $bc_qr_result ) ) {
			// Server accepted, but no response payload reaches the parent/client.
			exit( 0 );
		}
		if ( in_array( $bc_qr_mode, [ 'outer_transaction', 'autocommit_off' ], true ) ) { $wpdb->query( 'ROLLBACK' ); $wpdb->query( 'SET autocommit=1' ); }
		echo json_encode( [ 'kind' => $bc_qr_result instanceof WP_Error ? 'error' : 'success',
			'result' => $bc_qr_result instanceof WP_Error ? $bc_qr_result->get_error_code() : $bc_qr_result,
			'elapsed_ms' => $bc_qr_elapsed, 'fault_injected' => $bc_qr_injected ?? false ], JSON_THROW_ON_ERROR ) . "\n";
	}
	while ( ms_is_switched() ) { restore_current_blog(); }
} catch ( Throwable $bc_qr_error ) {
	// Fixed diagnostic only: no SQL, QR, credentials or stack trace.
	fwrite( STDERR, 'QR persistence worker failed (' . get_class( $bc_qr_error ) . ").\n" ); exit( 1 );
}
