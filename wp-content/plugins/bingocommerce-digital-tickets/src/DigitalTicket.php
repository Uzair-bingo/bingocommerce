<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Opaque, stable Hall-local reference. No redemption or payment mutation. */
final class DigitalTicket {
	public static function on_payment_complete( $order_id ): void {
		try {
			self::issue( (int) $order_id );
		} catch ( \Throwable $error ) {
			// Payment is already confirmed. Never fail its response or log the token.
			error_log( sprintf( 'BingoCommerce QR unavailable site=%d order=%d', get_current_blog_id(), (int) $order_id ) );
		}
	}

	private static function issue( int $order_id ): void {
		if ( ! Migrations::is_hall() || is_wp_error( Migrations::verify_current_site_schema() ) ) { return; }
		$entitlement = self::active_entitlement( $order_id );
		if ( ! $entitlement ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund
			|| ! in_array( $order->get_created_via( 'edit' ), [ 'checkout', 'store-api' ], true )
			|| (float) $order->get_total( 'edit' ) <= 0 || ! $order->is_paid()
			|| ! $order->get_date_paid( 'edit' ) || '' === $order->get_payment_method( 'edit' ) ) { return; }
		$facts = $order->get_meta( 'bc_purchase', true );
		if ( ! EntitlementSnapshot::valid_facts( $order, $facts ) || 'paper' === $facts['package_type'] ) { return; }
		foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) {
			if ( (string) $facts[ $key ] !== $entitlement[ $key ] ) { return; }
		}
		$faces = $facts['digital_faces_by_game_type'] ?? null;
		if ( ! is_array( $faces ) ) { return; }
		ksort( $faces, SORT_NUMERIC );
		if ( $faces !== $entitlement['digital_faces_by_game_type'] ) { return; }
		if ( null !== self::reference_for_order( $order_id ) ) { return; }
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			// The unique entitlement key arbitrates repeated/concurrent events. No
			// overwrite/upsert. Recheck status AND marker in the insertion statement.
			$wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (entitlement_id,token,issued_at) SELECT e.entitlement_id,%s,%s FROM %i e LEFT JOIN %i v ON v.order_id=e.order_id WHERE e.entitlement_id=%d AND e.status=%s AND v.order_id IS NULL',
				Migrations::tickets_table(), bin2hex( random_bytes( 32 ) ), gmdate( 'Y-m-d H:i:s' ), Migrations::table(), Migrations::voids_table(), (int) $entitlement['entitlement_id'], 'active'
			) );
		} finally { $wpdb->suppress_errors( $quiet ); }
		// Includes a concurrent winner; also hides a ticket if voiding intervened.
		if ( null === self::reference_for_order( $order_id ) ) {
			throw new \RuntimeException( 'Ticket not available after issuance.' );
		}
	}

	/** Internal read boundary, NOT customer authorization or an Android endpoint. */
	public static function reference_for_order( int $order_id ): ?string {
		$row = self::active_entitlement( $order_id );
		if ( ! $row || ! is_string( $row['token'] ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $row['token'] ) ) { return null; }
		return 'BC1.' . get_current_blog_id() . '.' . $row['token'];
	}

	/** Read-only server resolution; the Hall hint NEVER selects/switches a Hall. */
	public static function resolve( string $reference ): ?array {
		if ( ! preg_match( '/\ABC1\.([1-9][0-9]*)\.([a-f0-9]{64})\z/', $reference, $parts ) || (string) get_current_blog_id() !== $parts[1] ) { return null; }
		$row = self::active_entitlement( null, $parts[2] );
		if ( ! $row ) { return null; }
		unset( $row['token'], $row['digital_faces_json'] );
		return $row;
	}

	private static function active_entitlement( ?int $order_id, ?string $token = null ): ?array {
		if ( ! Migrations::is_hall() ) { return null; }
		global $wpdb;
		// No cached eligibility: even a marker whose status UPDATE failed blocks
		// every lookup. Any marker for the order blocks, including inconsistent ones.
		$quiet = $wpdb->suppress_errors( true );
		try {
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT e.*,t.token FROM %i e LEFT JOIN %i t ON t.entitlement_id=e.entitlement_id LEFT JOIN %i v ON v.order_id=e.order_id WHERE ' . ( null === $token ? 'e.order_id=%d' : 't.token=%s' ) . ' AND e.status=%s AND v.order_id IS NULL',
				Migrations::table(), Migrations::tickets_table(), Migrations::voids_table(), $token ?? $order_id, 'active'
			), ARRAY_A );
		} catch ( \Throwable $error ) { return null; }
		finally { $wpdb->suppress_errors( $quiet ); }
		if ( '' !== $wpdb->last_error || ! is_array( $row ) || '1' !== (string) $row['snapshot_version'] || ! wp_is_uuid( $row['selection_id'], 4 ) ) { return null; }
		foreach ( [ 'entitlement_id', 'order_id', 'session_id', 'package_id' ] as $key ) {
			if ( ! preg_match( '/\A[1-9][0-9]*\z/', (string) $row[ $key ] ) ) { return null; }
		}
		// The snapshot, never today's Catalog, is the digital authority.
		$faces = json_decode( $row['digital_faces_json'], true );
		if ( ! is_array( $faces ) || [] === $faces ) { return null; }
		foreach ( $faces as $game_type => $total ) {
			if ( ! is_int( $game_type ) || $game_type < 1 || ! is_int( $total ) || $total < 1 ) { return null; }
		}
		ksort( $faces, SORT_NUMERIC ); $row['digital_faces_by_game_type'] = $faces;
		return $row;
	}
}
