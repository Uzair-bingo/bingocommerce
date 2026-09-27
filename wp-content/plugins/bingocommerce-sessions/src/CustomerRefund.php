<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** BR-046 validation and successful-unit notification. Woo owns refund processing. */
final class CustomerRefund {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// update_taxes() saves before woocommerce_create_refund in Woo 11.0.1.
		add_action( 'woocommerce_before_order_refund_object_save', [ self::class, 'validate' ] );
		// WC_Abstract_Order::save() catches the early veto. Throw here as well so
		// wc_create_refund returns its normal WP_Error before gateway/restock/events.
		add_action( 'woocommerce_create_refund', [ self::class, 'validate' ] );
		add_action( 'woocommerce_refund_created', [ self::class, 'notify_package_refunded' ], 10, 2 );
	}

	/** Post-success only: publish a saved whole-Package refund, never a proposed one. */
	public static function notify_package_refunded( $refund_id, array $args ): void {
		try {
			// Existing-refund rewrites are outside the native new-refund contract.
			if ( ! empty( $args['refund_id'] ) ) { return; }
			$refund = wc_get_order( (int) $refund_id );
			if ( ! $refund instanceof \WC_Order_Refund || $refund->get_id() < 1
				|| $refund->get_parent_id() !== (int) ( $args['order_id'] ?? 0 )
				|| ( ! empty( $args['refund_payment'] ) && ! $refund->get_refunded_payment() ) ) { return; }
			$order = wc_get_order( $refund->get_parent_id() );
			if ( ! $order instanceof \WC_Order ) { return; }
			$unit = self::unit( $order );
			if ( [] === $unit ) { return; }
			// Reuse the same BR-046 validator against persisted amounts/links. It
			// excludes this refund from prior history and never consults Catalog.
			self::validate( $refund );
			$allocation = self::allocation( $refund, $order->get_items( [ 'line_item', 'fee', 'shipping' ] ) );
			if ( [] === array_intersect_key( $unit, $allocation ) ) { return; }
			do_action( 'bingocommerce_package_refunded', $order->get_id(), $order->get_meta( CustomerOrder::META, true ), $refund->get_id() );
		} catch ( \Throwable $error ) {
			// Throwing after gateway success lets wc_create_refund delete the saved
			// refund. Report the missed notification without changing that outcome.
			try {
				wc_get_logger()->error( 'bc_package_refund_notification_failed', [ 'source' => 'bingocommerce-sessions', 'site_id' => get_current_blog_id(), 'refund_id' => (int) $refund_id ] );
			} catch ( \Throwable $logging_error ) {
				error_log( sprintf( 'BingoCommerce package refund notification failed site=%d refund=%d', get_current_blog_id(), (int) $refund_id ) );
			}
		}
	}

	public static function validate( \WC_Order_Refund $refund ): void {
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order instanceof \WC_Order ) { return; }
		$unit = self::unit( $order );
		if ( [] === $unit ) { return; }
		$items = $order->get_items( [ 'line_item', 'fee', 'shipping' ] );
		$requested = self::allocation( $refund, $items );
		$prior = [];
		foreach ( $order->get_refunds() as $previous ) {
			if ( $previous->get_id() === $refund->get_id() ) { continue; }
			foreach ( self::allocation( $previous, $items ) as $id => $values ) {
				$prior[ $id ] ??= [ 'qty' => 0.0, 'total' => 0.0, 'taxes' => [] ];
				$prior[ $id ]['qty'] += $values['qty'];
				$prior[ $id ]['total'] += $values['total'];
				foreach ( $values['taxes'] as $rate => $tax ) { $prior[ $id ]['taxes'][ $rate ] = ( $prior[ $id ]['taxes'][ $rate ] ?? 0.0 ) + $tax; }
			}
		}
		foreach ( $requested as $id => $values ) {
			$original = self::values( $items[ $id ], false );
			$used = $prior[ $id ] ?? [ 'qty' => 0.0, 'total' => 0.0, 'taxes' => [] ];
			if ( $values['qty'] + $used['qty'] > $original['qty']
				|| self::money( $values['total'] + $used['total'] ) > self::money( $original['total'] ) ) { self::refuse(); }
			foreach ( $values['taxes'] as $rate => $tax ) {
				if ( ! array_key_exists( $rate, $original['taxes'] )
					|| self::money( $tax + ( $used['taxes'][ $rate ] ?? 0.0 ) ) > self::money( $original['taxes'][ $rate ] ) ) { self::refuse(); }
			}
		}
		if ( [] === array_intersect_key( $requested, $unit ) ) { return; }
		foreach ( $unit as $id => $item ) {
			// One refund contains the entire commercial unit, including free children.
			if ( ! isset( $requested[ $id ] ) || isset( $prior[ $id ] ) ) { self::refuse(); }
			$original = self::values( $item, false );
			$values = $requested[ $id ];
			if ( $values['qty'] !== $original['qty'] || self::money( $values['total'] ) !== self::money( $original['total'] ) ) { self::refuse(); }
			foreach ( $original['taxes'] as $rate => $tax ) {
				if ( self::money( $tax ) !== self::money( $values['taxes'][ $rate ] ?? 0.0 ) ) { self::refuse(); }
			}
		}
	}

	/** Re-resolve today's order item IDs from BR-083 links, never from Catalog. */
	private static function unit( \WC_Order $order ): array {
		$facts = $order->get_meta( CustomerOrder::META, true );
		$items = $order->get_items( 'line_item' );
		$marked = '' !== $facts;
		foreach ( $items as $item ) {
			$marked = $marked || '' !== $item->get_meta( CustomerOrder::META, true ) || '' !== $item->get_meta( 'bc_bingo_session', true ) || '' !== $item->get_meta( 'bc_package', true );
		}
		if ( ! $marked ) { return []; }
		if ( ! is_array( $facts ) || 1 !== ( $facts['version'] ?? null ) || get_current_blog_id() !== ( $facts['site_id'] ?? null )
			|| ! is_string( $facts['selection_id'] ?? null ) || ! wp_is_uuid( $facts['selection_id'], 4 )
			|| ! self::positive_id( $facts['session_id'] ?? null ) || ! self::positive_id( $facts['package_id'] ?? null )
			|| ! self::positive_id( $facts['wc_product_id'] ?? null ) || 1 !== ( $facts['quantity'] ?? null ) || ! is_array( $facts['addons'] ?? null ) ) { self::refuse(); }
		$expected = [ 'package:0' => [ $facts['wc_product_id'], 1 ] ];
		foreach ( $facts['addons'] as $addon ) {
			if ( ! is_array( $addon ) || ! self::positive_id( $addon['addon_id'] ?? null ) || ! self::positive_id( $addon['wc_product_id'] ?? null )
				|| ! self::positive_id( $addon['bingo_play_product_id'] ?? null ) || ! self::positive_id( $addon['quantity'] ?? null ) ) { self::refuse(); }
			$key = 'addon:' . $addon['addon_id'];
			if ( isset( $expected[ $key ] ) ) { self::refuse(); }
			$expected[ $key ] = [ $addon['wc_product_id'], $addon['quantity'] ];
		}
		$unit = []; $seen = [];
		foreach ( $items as $id => $item ) {
			$binding = $item->get_meta( CustomerOrder::META, true );
			if ( '' === $binding ) {
				if ( in_array( $item->get_product_id(), array_column( $expected, 0 ), true )
					|| '' !== $item->get_meta( 'bc_bingo_session', true ) || '' !== $item->get_meta( 'bc_package', true ) ) { self::refuse(); }
				continue;
			}
			if ( ! is_array( $binding ) ) { self::refuse(); }
			foreach ( [ 'version', 'site_id', 'selection_id', 'session_id', 'package_id' ] as $field ) {
				if ( ( $binding[ $field ] ?? null ) !== $facts[ $field ] ) { self::refuse(); }
			}
			if ( ! is_string( $binding['role'] ?? null ) || ! is_int( $binding['addon_id'] ?? null ) ) { self::refuse(); }
			$key = $binding['role'] . ':' . $binding['addon_id'];
			$match = $expected[ $key ] ?? null;
			if ( ! $match || isset( $seen[ $key ] ) || $match[0] !== $item->get_product_id() || 0 !== $item->get_variation_id()
				|| $match[1] !== ( $binding['quantity'] ?? null ) || (float) $match[1] !== (float) $item->get_quantity() ) { self::refuse(); }
			$seen[ $key ] = true; $unit[ $id ] = $item;
		}
		if ( count( $seen ) !== count( $expected ) ) { self::refuse(); }
		return $unit;
	}

	/** Every monetary refund on a Bingo order must be allocated to current lines. */
	private static function allocation( \WC_Order_Refund $refund, array $items ): array {
		$result = []; $amount = 0.0;
		$round_at_subtotal = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
		foreach ( $refund->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $item ) {
			$id = $item->get_meta( '_refunded_item_id', true );
			if ( ! is_numeric( $id ) || (string) (int) $id !== (string) $id || ! isset( $items[ (int) $id ] ) || isset( $result[ (int) $id ] ) ) { self::refuse(); }
			$values = self::values( $item, true );
			$result[ (int) $id ] = $values;
			foreach ( array_merge( [ $values['total'] ], array_values( $values['taxes'] ) ) as $value ) {
				$amount += $round_at_subtotal ? $value : self::money( $value );
			}
		}
		if ( [] === $result || self::money( self::number( $refund->get_amount() ) ) !== self::money( $amount ) ) { self::refuse(); }
		return $result;
	}

	private static function values( \WC_Order_Item $item, bool $negative ): array {
		$sign = $negative ? -1 : 1;
		$quantity = $item instanceof \WC_Order_Item_Product ? self::number( $item->get_quantity(), $sign ) : 0.0;
		$taxes = [];
		foreach ( $item->get_taxes()['total'] as $rate => $tax ) { $taxes[ $rate ] = self::number( $tax, $sign ); }
		return [ 'qty' => $quantity, 'total' => self::number( $item->get_total(), $sign ), 'taxes' => $taxes ];
	}

	private static function number( mixed $value, int $sign = 1 ): float {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value * $sign < 0 ) { self::refuse(); }
		return (float) $value * $sign;
	}

	private static function money( float $value ): float {
		return (float) wc_format_decimal( $value, wc_get_price_decimals() );
	}

	private static function positive_id( mixed $value ): bool { return is_int( $value ) && $value > 0; }

	private static function refuse(): never {
		throw new \Exception( __( 'Refund the complete Bingo Package and all its add-ons together, with their full quantities, discounted line amounts and taxes. Refund merchandise on its own itemized lines. Missing or inconsistent purchase links and amount-only Bingo refunds require review.', 'bingocommerce-sessions' ) );
	}
}
