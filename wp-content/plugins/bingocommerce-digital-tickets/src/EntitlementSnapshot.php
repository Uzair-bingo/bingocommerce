<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Observes confirmed payment; never changes Woo orders, payment or Catalog. */
final class EntitlementSnapshot {
	private const META = 'bc_purchase';

	public static function on_payment_complete( $order_id ): void {
		try {
			$result = self::capture( (int) $order_id );
		} catch ( \Throwable $error ) {
			$result = self::error( 'bc_entitlement_unavailable' );
		}
		if ( is_wp_error( $result ) ) {
			// Payment has already been saved by Woo. Do not throw, change its status
			// or encourage another charge. Recovery/reconciliation is a later slice.
			try {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( $result->get_error_code() . ': ' . $result->get_error_message(), [
						'source' => 'bingocommerce-digital-tickets', 'order_id' => (int) $order_id, 'site_id' => get_current_blog_id(),
					] );
				}
				do_action( 'bingocommerce_digital_tickets_snapshot_failed', $result, (int) $order_id, get_current_blog_id() );
			} catch ( \Throwable $error ) {
				// Even a failed diagnostic sink must not turn confirmed payment into
				// a failed gateway response. No customer or payment secrets are logged.
				error_log( sprintf( 'BingoCommerce entitlement %s site=%d order=%d', $result->get_error_code(), get_current_blog_id(), (int) $order_id ) );
			}
		}
	}

	/** The only production caller is the payment-complete observer. */
	private static function capture( int $order_id ): true|\WP_Error {
		if ( ! Migrations::is_hall() || ! function_exists( 'wc_get_order' ) ) { return self::error( 'bc_entitlement_context_unavailable' ); }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) { return self::error( 'bc_entitlement_order_unavailable' ); }
		$facts = $order->get_meta( self::META, true );
		$marked = '' !== $facts;
		foreach ( $order->get_items() as $item ) {
			$marked = $marked || '' !== $item->get_meta( self::META, true ) || '' !== $item->get_meta( 'bc_bingo_session', true ) || '' !== $item->get_meta( 'bc_package', true );
		}
		if ( ! $marked ) { return true; } // Independent merchandise, not an empty Bingo entitlement.
		if ( ! in_array( $order->get_created_via( 'edit' ), [ 'checkout', 'store-api' ], true ) ) { return self::error( 'bc_entitlement_order_origin_unsupported' ); }
		if ( (float) $order->get_total( 'edit' ) <= 0 ) { return self::error( 'bc_entitlement_zero_total_out_of_scope' ); }
		if ( ! $order->is_paid() || ! $order->get_date_paid( 'edit' ) || '' === $order->get_payment_method( 'edit' ) ) { return self::error( 'bc_entitlement_payment_unconfirmed' ); }
		if ( ! self::valid_facts( $order, $facts ) ) { return self::error( 'bc_entitlement_saved_facts_invalid' ); }
		if ( 'paper' !== $facts['package_type'] ) {
			$schema = Migrations::verify_current_site_schema();
			if ( is_wp_error( $schema ) ) { return $schema; }
			$blocked = EntitlementVoiding::enforce_block( $order_id, $facts );
			if ( false !== $blocked ) { return $blocked; }
		}
		$faces = $facts['digital_faces_by_game_type'] ?? null;
		if ( ! is_array( $faces ) ) { return self::error( 'bc_entitlement_totals_unknown' ); }
		foreach ( $faces as $game_type => $total ) {
			if ( ! self::positive( $game_type ) || ! self::positive( $total ) ) { return self::error( 'bc_entitlement_totals_unknown' ); }
		}
		if ( 'paper' === $facts['package_type'] && [] === $faces ) { return true; }
		if ( 'paper' === $facts['package_type'] || [] === $faces ) { return self::error( 'bc_entitlement_totals_unknown' ); }
		ksort( $faces, SORT_NUMERIC );
		$json = wp_json_encode( $faces, JSON_FORCE_OBJECT );
		if ( false === $json ) { return self::error( 'bc_entitlement_totals_unknown' ); }
		global $wpdb;
		$table = Migrations::table();
		$expected = [
			'order_id' => (string) $order_id, 'session_id' => (string) $facts['session_id'], 'package_id' => (string) $facts['package_id'],
			'selection_id' => $facts['selection_id'], 'snapshot_version' => '1', 'digital_faces_json' => $json,
		];
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $table, $order_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::error( 'bc_entitlement_unavailable' ); }
		if ( null === $existing ) {
			// One atomic insert containing the complete map. UNIQUE(order_id) is the
			// concurrency arbiter; there is deliberately no UPDATE/REPLACE/upsert.
			$quiet = $wpdb->suppress_errors( true );
			try {
				$wpdb->query( $wpdb->prepare(
					'INSERT INTO %i (order_id,session_id,package_id,selection_id,snapshot_version,digital_faces_json,status,confirmed_at) VALUES (%d,%d,%d,%s,%d,%s,%s,%s)',
					$table, $order_id, $facts['session_id'], $facts['package_id'], $facts['selection_id'], 1, $json, 'active', gmdate( 'Y-m-d H:i:s', $order->get_date_paid( 'edit' )->getTimestamp() )
				) );
			} finally { $wpdb->suppress_errors( $quiet ); }
			// Also resolves a concurrent winning insert or an ambiguous insert result.
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $table, $order_id ), ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $existing ) ) { return self::error( 'bc_entitlement_unavailable' ); }
		}
		// A refund/cancellation may have recorded its marker after our first read
		// but before this insert. Re-read after insertion and converge to void.
		$blocked = EntitlementVoiding::enforce_block( $order_id, $facts );
		if ( false !== $blocked ) { return $blocked; }
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $existing[ $key ] ?? '' ) !== $value ) { return self::error( 'bc_entitlement_snapshot_conflict' ); }
		}
		// Never rewrite snapshot data or reactivate a void record.
		return true;
	}

	/** Check the saved contract and current order links, without mutable Catalog reads. */
	public static function valid_facts( \WC_Order $order, mixed $facts ): bool {
		if ( ! is_array( $facts ) || 1 !== ( $facts['version'] ?? null ) || get_current_blog_id() !== ( $facts['site_id'] ?? null )
			|| ! is_string( $facts['selection_id'] ?? null ) || ! wp_is_uuid( $facts['selection_id'], 4 )
			|| ! self::positive( $facts['session_id'] ?? null ) || ! self::positive( $facts['package_id'] ?? null ) || ! self::positive( $facts['wc_product_id'] ?? null )
			|| 1 !== ( $facts['quantity'] ?? null ) || ! is_array( $facts['addons'] ?? null )
			|| ! in_array( $facts['package_type'] ?? null, [ 'paper', 'digital', 'hybrid' ], true ) ) { return false; }
		$date = is_string( $facts['session_date'] ?? null ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['session_date'] ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $facts['session_date'] ) { return false; }
		$expected = [ 'package:0' => [ $facts['wc_product_id'], 1 ] ];
		foreach ( $facts['addons'] as $addon ) {
			if ( ! is_array( $addon ) || ! self::positive( $addon['addon_id'] ?? null ) || ! self::positive( $addon['wc_product_id'] ?? null )
				|| ! self::positive( $addon['bingo_play_product_id'] ?? null ) || ! self::positive( $addon['quantity'] ?? null ) ) { return false; }
			$key = 'addon:' . $addon['addon_id'];
			if ( isset( $expected[ $key ] ) ) { return false; }
			$expected[ $key ] = [ $addon['wc_product_id'], $addon['quantity'] ];
		}
		$seen = [];
		foreach ( $order->get_items() as $item ) {
			$binding = $item->get_meta( self::META, true );
			if ( '' === $binding ) {
				if ( in_array( $item->get_product_id(), array_column( $expected, 0 ), true ) || '' !== $item->get_meta( 'bc_bingo_session', true ) || '' !== $item->get_meta( 'bc_package', true ) ) { return false; }
				continue;
			}
			if ( ! is_array( $binding ) ) { return false; }
			foreach ( [ 'version', 'site_id', 'selection_id', 'session_id', 'package_id' ] as $field ) {
				if ( ( $binding[ $field ] ?? null ) !== $facts[ $field ] ) { return false; }
			}
			if ( ! is_string( $binding['role'] ?? null ) || ! is_int( $binding['addon_id'] ?? null ) ) { return false; }
			$key = $binding['role'] . ':' . $binding['addon_id']; $match = $expected[ $key ] ?? null;
			if ( ! $match || isset( $seen[ $key ] ) || $item->get_product_id() !== $match[0] || 0 !== $item->get_variation_id()
				|| ( $binding['quantity'] ?? null ) !== $match[1] || (float) $item->get_quantity() !== (float) $match[1] ) { return false; }
			$seen[ $key ] = true;
		}
		return count( $seen ) === count( $expected );
	}

	private static function positive( mixed $value ): bool { return is_int( $value ) && $value > 0; }
	private static function error( string $code ): \WP_Error {
		if ( 'bc_entitlement_zero_total_out_of_scope' === $code ) {
			return new \WP_Error( $code, __( 'Zero-total Bingo checkouts are outside the paid-order snapshot slice. No digital entitlement was created.', 'bingocommerce-digital-tickets' ) );
		}
		return new \WP_Error( $code, __( 'The digital entitlement snapshot was not created. Review the saved order facts and payment evidence; do not charge the customer again or reconstruct totals from the current Catalog.', 'bingocommerce-digital-tickets' ) );
	}
}
