<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** BR-046: terminal status change only; never creates or rewrites a snapshot. */
final class EntitlementVoiding {
	public static function on_package_refunded( int $order_id, array $facts, int $refund_id ): void {
		self::handle( $order_id, $facts, 'package_refund', $refund_id );
	}

	public static function on_cancelled( $order_id ): void {
		self::handle( (int) $order_id, null, 'cancellation', 0 );
	}

	private static function handle( int $order_id, ?array $facts, string $cause, int $refund_id ): void {
		try { $result = self::void_existing( $order_id, $facts, $cause ); }
		catch ( \Throwable $error ) { $result = self::error(); }
		if ( ! is_wp_error( $result ) ) { return; }
		// Woo already persisted the refund/cancellation. A voiding failure must
		// not undo that record or make an already-successful refund look refused.
		try {
			wc_get_logger()->error( $result->get_error_code() . ': ' . $result->get_error_message(), [
				'source' => 'bingocommerce-digital-tickets', 'site_id' => get_current_blog_id(),
				'order_id' => $order_id, 'refund_id' => $refund_id, 'cause' => $cause,
			] );
			do_action( 'bingocommerce_digital_tickets_void_failed', $result, $order_id, get_current_blog_id(), $cause, $refund_id );
		} catch ( \Throwable $error ) {
			error_log( sprintf( 'BingoCommerce entitlement void failed site=%d order=%d cause=%s', get_current_blog_id(), $order_id, $cause ) );
		}
	}

	private static function void_existing( int $order_id, ?array $notified_facts, string $cause ): true|\WP_Error {
		if ( ! Migrations::is_hall() ) { return self::error(); }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) { return self::error(); }
		// Do not trust an event name or an order object supplied from another Hall.
		if ( 'cancellation' === $cause && ! $order->has_status( 'cancelled' ) ) { return self::error(); }
		$schema = Migrations::verify_current_site_schema();
		if ( is_wp_error( $schema ) ) { return $schema; }
		global $wpdb;
		$table = Migrations::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $table, $order_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::error(); }
		if ( null === $row ) { return true; } // Paper, merchandise, or not yet entitled: no tombstone/backfill.
		$facts = $order->get_meta( 'bc_purchase', true );
		if ( ! is_array( $facts ) || 1 !== ( $facts['version'] ?? null ) || get_current_blog_id() !== ( $facts['site_id'] ?? null )
			|| ! is_int( $facts['session_id'] ?? null ) || $facts['session_id'] < 1
			|| ! is_int( $facts['package_id'] ?? null ) || $facts['package_id'] < 1
			|| ! is_string( $facts['selection_id'] ?? null ) || ! wp_is_uuid( $facts['selection_id'], 4 )
			|| ( null !== $notified_facts && $notified_facts !== $facts ) ) { return self::error(); }
		foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) {
			if ( (string) $facts[ $key ] !== $row[ $key ] ) { return self::error(); }
		}
		if ( 'void' === $row['status'] ) { return true; }
		if ( 'active' !== $row['status'] ) { return self::error(); }
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET status = %s WHERE entitlement_id = %d AND order_id = %d AND session_id = %d AND package_id = %d AND selection_id = %s AND status = %s',
			$table, 'void', $row['entitlement_id'], $order_id, $facts['session_id'], $facts['package_id'], $facts['selection_id'], 'active'
		) );
		if ( false === $updated ) { return self::error(); }
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE entitlement_id = %d', $table, $row['entitlement_id'] ), ARRAY_A );
		$row['status'] = 'void';
		return '' === $wpdb->last_error && $after === $row ? true : self::error();
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'bc_entitlement_void_failed', __( 'The existing digital entitlement could not be voided. Review the Hall order and refund/cancellation evidence; do not repeat a payment or refund to repair entitlement state.', 'bingocommerce-digital-tickets' ) );
	}
}
