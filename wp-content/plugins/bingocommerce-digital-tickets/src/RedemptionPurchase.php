<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reads only a paid snapshot and its saved purchase. No Catalog composition. */
final class RedemptionPurchase {
	public static function read( array $entitlement ): array|\WP_Error {
		global $wpdb;
		$wpdb->last_error = '';
		// Woo can issue several reads, and a later successful query clears
		// last_error. Observe the previous query before that reset, then inspect
		// the final query too. This preparation runs outside lifecycle protection.
		$failed = false;
		$observe = static function ( $sql ) use ( $wpdb, &$failed ) {
			$failed = $failed || '' !== $wpdb->last_error;
			return $sql;
		};
		add_filter( 'query', $observe, PHP_INT_MIN );
		try {
			$result = self::load( $entitlement );
			return $failed || '' !== $wpdb->last_error ? RedemptionContract::error( 'verification_unavailable' ) : $result;
		} finally { remove_filter( 'query', $observe, PHP_INT_MIN ); }
	}

	private static function load( array $entitlement ): array|\WP_Error {
		global $wpdb;
		try {
			$order = wc_get_order( (int) $entitlement['order_id'] );
			if ( '' !== $wpdb->last_error ) { return RedemptionContract::error( 'verification_unavailable' ); }
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			$facts = $order->get_meta( 'bc_purchase', true );
			$valid = EntitlementSnapshot::valid_facts( $order, $facts );
			if ( '' !== $wpdb->last_error ) { return RedemptionContract::error( 'verification_unavailable' ); }
			if ( ! $valid || ! in_array( $facts['package_type'], [ 'digital', 'hybrid' ], true )
				|| ! in_array( $order->get_created_via( 'edit' ), [ 'checkout', 'store-api' ], true )
				|| ! $order->get_date_paid( 'edit' ) || '' === $order->get_payment_method( 'edit' ) || (float) $order->get_total( 'edit' ) <= 0 ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $field ) {
				if ( (string) $facts[ $field ] !== (string) $entitlement[ $field ] ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			}
			if ( '1' !== (string) $entitlement['snapshot_version'] || ! self::date( (string) $entitlement['confirmed_at'], 'Y-m-d H:i:s' ) ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			$faces = RedemptionContract::faces( json_decode( $entitlement['digital_faces_json'], true, 512, JSON_THROW_ON_ERROR ) );
			if ( null === $faces || $faces !== RedemptionContract::faces( $facts['digital_faces_by_game_type'] ?? null ) ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			foreach ( [ 'session_name', 'package_name' ] as $field ) {
				if ( ! RedemptionContract::opaque( $facts[ $field ] ?? null, 4096 ) || '' === trim( $facts[ $field ] ) ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			}
			foreach ( [ 'start_time_local', 'timezone_name', 'starts_at_utc' ] as $field ) {
				if ( ! array_key_exists( $field, $facts ) ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			}
			$time = $facts['start_time_local']; $zone = $facts['timezone_name']; $utc = $facts['starts_at_utc'];
			if ( null !== $time || null !== $zone || null !== $utc ) {
				if ( ! is_string( $time ) || ! self::date( $time, 'H:i:s' ) || ! is_string( $zone )
					|| ! in_array( $zone, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true )
					|| ! is_string( $utc ) || ! self::date( $utc, 'Y-m-d H:i:s' ) ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
				$instant = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
				if ( $instant->setTimezone( new \DateTimeZone( $zone ) )->format( 'Y-m-d H:i:s' ) !== $facts['session_date'] . ' ' . $time ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
			}
			if ( '' !== $wpdb->last_error ) { return RedemptionContract::error( 'verification_unavailable' ); }
			return [
				'session' => [ 'commerce_session_id' => $facts['session_id'], 'name' => $facts['session_name'], 'date' => $facts['session_date'], 'start_time_local' => $time, 'timezone_name' => $zone, 'starts_at_utc' => null === $utc ? null : str_replace( ' ', 'T', $utc ) . 'Z' ],
				'package' => [ 'commerce_package_id' => $facts['package_id'], 'name' => $facts['package_name'] ],
				'faces' => $faces,
			];
		} catch ( \JsonException | \ValueError $error ) { return RedemptionContract::error( 'saved_purchase_invalid' ); }
		catch ( \Throwable $error ) { return RedemptionContract::error( 'verification_unavailable' ); }
	}

	private static function date( string $value, string $format ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( $format ) === $value;
	}
}
