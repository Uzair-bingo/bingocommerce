<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** BR-046: durable terminal marker plus status change; never invents a snapshot. */
final class EntitlementVoiding {
	public static function on_package_refunded( int $order_id, array $facts, int $refund_id ): void {
		self::handle( $order_id, $facts, 'package_refund', $refund_id );
	}

	public static function on_cancelled( $order_id, $event_order = null, $transition = null ): void {
		self::handle( (int) $order_id, null, 'cancellation', 0, $event_order, $transition );
	}

	private static function handle( int $order_id, ?array $facts, string $cause, int $refund_id, mixed $event_order = null, mixed $transition = null ): void {
		try { $result = self::record_void( $order_id, $facts, $cause, $refund_id, $event_order, $transition ); }
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

	private static function record_void( int $order_id, ?array $notified_facts, string $cause, int $refund_id, mixed $event_order, mixed $transition ): true|\WP_Error {
		if ( ! Migrations::is_hall() ) { return self::error(); }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) { return self::error(); }
		$facts = $order->get_meta( 'bc_purchase', true );
		if ( 'cancellation' === $cause ) {
			if ( null !== $event_order || null !== $transition ) {
				// Woo retains the transition array across earlier callbacks, even if
				// native payment changes this same order object and persisted status.
				// Bind that evidence to the current Hall's reloaded purchase facts.
				if ( ! $event_order instanceof \WC_Order || $event_order->get_id() !== $order_id
					|| ! is_array( $transition ) || 'cancelled' !== ( $transition['to'] ?? null )
					|| ! is_string( $transition['from'] ?? null ) || '' === $transition['from'] || 'cancelled' === $transition['from']
					|| array_key_exists( 'status', $event_order->get_changes() )
					|| $event_order->get_meta( 'bc_purchase', true ) !== $facts ) { return self::error(); }
			} elseif ( ! $order->has_status( 'cancelled' ) ) {
				// ID-only redelivery has no transition evidence: require saved status.
				return self::error();
			}
		}
		$schema = Migrations::verify_current_site_schema();
		if ( is_wp_error( $schema ) ) { return $schema; }
		global $wpdb;
		$table = Migrations::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $table, $order_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::error(); }
		if ( null === $row && '' === $facts ) { return true; } // Unmarked merchandise.
		if ( ! is_array( $facts ) || 1 !== ( $facts['version'] ?? null ) || get_current_blog_id() !== ( $facts['site_id'] ?? null )
			|| ! is_int( $facts['session_id'] ?? null ) || $facts['session_id'] < 1
			|| ! is_int( $facts['package_id'] ?? null ) || $facts['package_id'] < 1
			|| ! is_string( $facts['selection_id'] ?? null ) || ! wp_is_uuid( $facts['selection_id'], 4 )
			|| ( null !== $notified_facts && $notified_facts !== $facts ) ) { return self::error(); }
		if ( null === $row && 'paper' === ( $facts['package_type'] ?? null ) ) { return true; }
		if ( null === $row && ! in_array( $facts['package_type'] ?? null, [ 'digital', 'hybrid' ], true ) ) { return self::error(); }
		if ( null !== $row && ! self::matches( $row, $facts ) ) { return self::error(); }
		// Persist revocation BEFORE looking again for an entitlement. This marker
		// has no faces/payment timestamp and is never an invented paid snapshot.
		$quiet = $wpdb->suppress_errors( true );
		try {
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (order_id,session_id,package_id,selection_id,cause,refund_id,voided_at) VALUES (%d,%d,%d,%s,%s,%d,%s)',
				Migrations::voids_table(), $order_id, $facts['session_id'], $facts['package_id'], $facts['selection_id'], $cause, $refund_id, gmdate( 'Y-m-d H:i:s' )
			) );
		} finally { $wpdb->suppress_errors( $quiet ); }
		// Duplicate insert is harmless; readback must prove a matching marker.
		$result = self::enforce_block( $order_id, $facts );
		return false === $result ? self::error() : $result;
	}

	/** Snapshot calls this before AND after insert; neither path can clear a block. */
	public static function enforce_block( int $order_id, array $facts ): bool|\WP_Error {
		if ( ! Migrations::is_hall() || get_current_blog_id() !== ( $facts['site_id'] ?? null ) ) { return self::error(); }
		global $wpdb;
		$block = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Migrations::voids_table(), $order_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::error(); }
		if ( null === $block ) { return false; }
		if ( ! self::matches( $block, $facts ) ) { return self::error(); }
		$table = Migrations::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $table, $order_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::error(); }
		if ( null === $row ) { return true; } // Blocked without manufacturing an entitlement.
		if ( ! self::matches( $row, $facts ) ) { return self::error(); }
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

	private static function matches( array $row, array $facts ): bool {
		foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) {
			if ( ! isset( $facts[ $key ] ) || (string) $facts[ $key ] !== $row[ $key ] ) { return false; }
		}
		return true;
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'bc_entitlement_void_failed', __( 'The digital entitlement void state could not be verified or recorded. Review the Hall order and refund/cancellation evidence; do not repeat a payment or refund to repair entitlement state.', 'bingocommerce-digital-tickets' ) );
	}
}
