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
		if ( ! $result instanceof \WP_Error || ! LifecycleTransport::diagnostics_safe() ) { return; }
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
		$guard = null; $ok = false; $observe = null; $read_failed = false;
		try {
			// Bind context before Woo/schema preparation can execute callbacks.
			$guard = LifecycleTransport::capture( false );
			global $wpdb;
			$wpdb->last_error = '';
			$observe = static function ( $sql ) use ( $wpdb, &$read_failed ) { $read_failed = $read_failed || '' !== $wpdb->last_error; return $sql; };
			add_filter( 'query', $observe, PHP_INT_MIN );
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			$facts = $order->get_meta( 'bc_purchase', true );
			if ( 'cancellation' === $cause ) {
				if ( null !== $event_order || null !== $transition ) {
					// The persisted Woo transition is evidence even when an earlier
					// callback subsequently changed the same order object's status.
					if ( ! $event_order instanceof \WC_Order || $event_order->get_id() !== $order_id
						|| ! is_array( $transition ) || 'cancelled' !== ( $transition['to'] ?? null )
						|| ! is_string( $transition['from'] ?? null ) || '' === $transition['from'] || 'cancelled' === $transition['from']
						|| array_key_exists( 'status', $event_order->get_changes() )
						|| $event_order->get_meta( 'bc_purchase', true ) !== $facts ) { throw new LifecycleFailure( 'verification_unavailable' ); }
				} elseif ( ! $order->has_status( 'cancelled' ) ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			}
			if ( Migrations::verify_current_site_schema() instanceof \WP_Error ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			remove_filter( 'query', $observe, PHP_INT_MIN ); $observe = null;
			if ( $read_failed || '' !== $wpdb->last_error ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			$guard->prove( false );
			$guard->acquire();
			$marker = $guard->marker( $order_id );
			$row = $guard->entitlement( $order_id );
			if ( null === $row && null === $marker && '' === $facts ) { $ok = true; }
			else {
				if ( ! is_array( $facts ) || 1 !== ( $facts['version'] ?? null ) || get_current_blog_id() !== ( $facts['site_id'] ?? null )
					|| ! is_int( $facts['session_id'] ?? null ) || $facts['session_id'] < 1
					|| ! is_int( $facts['package_id'] ?? null ) || $facts['package_id'] < 1
					|| ! is_string( $facts['selection_id'] ?? null ) || 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $facts['selection_id'] )
					|| ( null !== $notified_facts && $notified_facts !== $facts )
					|| ( null !== $row && ! self::matches( $row, $facts ) )
					|| ( null !== $marker && ! self::matches( $marker, $facts ) ) ) { throw new LifecycleFailure( 'verification_unavailable' ); }
				if ( null === $row && null === $marker && 'paper' === ( $facts['package_type'] ?? null ) ) { $ok = true; }
				else {
					if ( null === $row && ! in_array( $facts['package_type'] ?? null, [ 'digital', 'hybrid' ], true ) ) { throw new LifecycleFailure( 'verification_unavailable' ); }
					// Separate autocommit marker first: no status failure can roll it back.
					$guard->insertMarker( $order_id, $facts, $cause, $refund_id );
					$ok = $guard->converge( $order_id, $facts );
				}
			}
		} catch ( \Throwable $error ) { $ok = false; }
		finally {
			if ( null !== $guard ) { $guard->close(); }
			if ( null !== $observe ) { remove_filter( 'query', $observe, PHP_INT_MIN ); }
		}
		return $ok ? true : self::error();
	}

	/**
	 * Read-only, for the customer order view: true when a void marker or a
	 * non-active entitlement exists for the order, null when that cannot be read.
	 * Never writes, converges or repairs anything.
	 */
	public static function display_blocked( int $order_id ): ?bool {
		if ( ! Migrations::is_hall() ) { return null; }
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT (SELECT COUNT(*) FROM %i WHERE order_id = %d) AS markers, (SELECT COUNT(*) FROM %i WHERE order_id = %d AND status <> %s) AS inactive',
				Migrations::voids_table(), $order_id, Migrations::table(), $order_id, 'active'
			), ARRAY_A );
		} catch ( \Throwable $error ) { return null; }
		finally { $wpdb->suppress_errors( $quiet ); }
		if ( '' !== $wpdb->last_error || ! is_array( $row ) ) { return null; }
		return '0' !== (string) $row['markers'] || '0' !== (string) $row['inactive'];
	}

	/** Snapshot calls this before AND after insert; neither path can clear a block. */
	public static function enforce_block( int $order_id, array $facts ): bool|\WP_Error {
		if ( LifecycleTransport::refuse_reentry() ) { return self::error(); }
		if ( get_current_blog_id() !== ( $facts['site_id'] ?? null ) ) { return self::error(); }
		$guard = null; $result = null;
		try {
			$guard = LifecycleTransport::capture( false );
			if ( Migrations::verify_current_site_schema() instanceof \WP_Error ) { throw new LifecycleFailure( 'verification_unavailable' ); }
			$guard->prove( false );
			$guard->acquire();
			$result = $guard->converge( $order_id, $facts );
		} catch ( \Throwable $error ) { $result = null; }
		finally { if ( null !== $guard ) { $guard->close(); } }
		return null === $result ? self::error() : $result;
	}

	private static function matches( array $row, array $facts ): bool {
		foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) {
			if ( ! isset( $facts[ $key ] ) || (string) $facts[ $key ] !== $row[ $key ] ) { return false; }
		}
		return true;
	}

	private static function error(): \WP_Error {
		if ( ! LifecycleTransport::diagnostics_safe() ) {
			$error = new \WP_Error();
			$error->errors = [ 'bc_entitlement_void_failed' => [ 'The digital entitlement void state could not be verified or recorded. Review the Hall order and refund/cancellation evidence; do not repeat a payment or refund to repair entitlement state.' ] ];
			return $error;
		}
		return new \WP_Error( 'bc_entitlement_void_failed', __( 'The digital entitlement void state could not be verified or recorded. Review the Hall order and refund/cancellation evidence; do not repeat a payment or refund to repair entitlement state.', 'bingocommerce-digital-tickets' ) );
	}
}
