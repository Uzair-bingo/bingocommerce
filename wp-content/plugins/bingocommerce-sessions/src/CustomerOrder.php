<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

use BingoCommerce\Catalog\CustomerCartCatalog;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact purchase facts through Woo CRUD. No payment, entitlement or financial setters. */
final class CustomerOrder {
	public const META = 'bc_purchase';
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// Store API's OrderController also uses WC_Checkout::create_order_line_items().
		add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'bind_line' ], 100, 4 );
		add_action( 'woocommerce_checkout_create_order', [ self::class, 'record' ], 100 );
		// Runs even when the Store API's cart hash did not require new line items.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ self::class, 'record' ], 100 );
		add_filter( 'woocommerce_order_item_display_meta_key', [ self::class, 'display_key' ], 10, 2 );
	}

	/** This binding is checked against the full current cart before recording facts. */
	public static function bind_line( \WC_Order_Item_Product $item, string $key, array $values, \WC_Order $order ): void {
		if ( ! isset( $values[ CartSelection::META ] ) ) { return; }
		$line = CartSelection::line( $values );
		if ( is_wp_error( $line ) ) { self::refuse(); }
		$meta = $values[ CartSelection::META ];
		$item->update_meta_data( self::META, self::binding( $meta, (int) $values['quantity'] ) );
	}

	/** Classic: before first save. Store API: after draft creation/update, before payment. */
	public static function record( \WC_Order $order ): void {
		if ( ! WC()->cart instanceof \WC_Cart ) { self::refuse(); }
		$cart = WC()->cart->get_cart();
		$inspection = CartSelection::inspect( $cart );
		if ( $inspection['errors']->has_errors() ) { self::refuse(); }
		$expected = [];
		$parent = null;
		foreach ( $inspection['lines'] as $key => $line ) {
			if ( 'regular' === $line['kind'] ) { continue; }
			$meta = $cart[ $key ][ CartSelection::META ];
			$expected[ self::line_key( $meta ) ] = $cart[ $key ];
			if ( 'package' === $line['kind'] ) { $parent = $cart[ $key ]; }
		}
		$existing = $order->get_meta( self::META, true );
		if ( is_array( $existing ) && $order->get_id() && ! $order->has_status( 'checkout-draft' ) ) {
			// Native Woo may resume a pending/failed order. Never refresh its recorded
			// facts from today's Catalog. This is not an existing-order payment policy.
			if ( null === $parent || ! self::same_selection( $existing, $parent, $expected ) ) { self::refuse(); }
			$facts = $existing;
		} else {
			$facts = null === $parent ? null : self::facts( $parent, $expected );
		}
		$matched = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$binding = $item->get_meta( self::META, true );
			if ( '' === $binding ) {
				$kind = CartSelection::classification( $item->get_product_id(), $item->get_variation_id() );
				if ( is_wp_error( $kind ) || 'regular' !== $kind['kind'] ) { self::refuse(); }
				continue;
			}
			if ( ! is_array( $binding ) ) { self::refuse(); }
			$key = self::line_key( $binding );
			$values = $expected[ $key ] ?? null;
			if ( ! $values || isset( $matched[ $key ] ) || self::binding( $values[ CartSelection::META ], (int) $values['quantity'] ) !== $binding
				|| $item->get_product_id() !== (int) $values['product_id'] || 0 !== $item->get_variation_id()
				|| (float) $item->get_quantity() !== (float) $values['quantity'] ) { self::refuse(); }
			$matched[ $key ] = true;
		}
		if ( count( $matched ) !== count( $expected ) ) { self::refuse(); }
		// Validate every line before changing any purchase metadata.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$binding = $item->get_meta( self::META, true );
			if ( ! is_array( $binding ) ) { continue; }
			$item->update_meta_data( 'bc_bingo_session', self::session_label( $facts ) );
			if ( 'addon' === $binding['role'] ) { $item->update_meta_data( 'bc_package', $facts['package_name'] ); }
		}
		if ( null === $facts ) { $order->delete_meta_data( self::META ); }
		else { $order->update_meta_data( self::META, $facts ); }
		// Store API has already saved the draft; save its changed item metadata too.
		// Classic owns its first save immediately after this hook.
		if ( $order->get_id() ) { $order->save(); }
	}

	private static function binding( array $meta, int $quantity ): array {
		return [
			'version' => 1, 'site_id' => $meta['site_id'], 'selection_id' => $meta['selection_id'],
			'session_id' => $meta['session_id'], 'package_id' => $meta['package_id'],
			'role' => $meta['role'], 'addon_id' => $meta['addon_id'], 'quantity' => $quantity,
		];
	}

	private static function line_key( array $meta ): string {
		return (string) ( $meta['role'] ?? '' ) . ':' . (string) ( $meta['addon_id'] ?? '' );
	}

	private static function same_selection( array $facts, array $parent, array $selected ): bool {
		foreach ( [ 'site_id', 'selection_id', 'session_id', 'package_id' ] as $key ) {
			if ( ( $facts[ $key ] ?? null ) !== $parent[ CartSelection::META ][ $key ] ) { return false; }
		}
		if ( ( $facts['wc_product_id'] ?? null ) !== (int) $parent['product_id'] ) { return false; }
		$addons = [];
		foreach ( $selected as $item ) {
			$meta = $item[ CartSelection::META ];
			if ( 'addon' === $meta['role'] ) { $addons[ $meta['addon_id'] ] = [ (int) $item['product_id'], (int) $item['quantity'] ]; }
		}
		$saved = [];
		foreach ( $facts['addons'] ?? [] as $addon ) { $saved[ $addon['addon_id'] ] = [ $addon['wc_product_id'], $addon['quantity'] ]; }
		ksort( $addons ); ksort( $saved );
		return $addons === $saved;
	}

	/** Read exact occurrence fields, never derive a time from its display name. */
	private static function facts( array $parent, array $selected ): array {
		global $wpdb;
		$meta = $parent[ CartSelection::META ];
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT session_id, session_date, display_name, schedule_id, start_time_local, timezone_name, starts_at_utc FROM %i WHERE session_id = %d',
			Migrations::sessions_table(), $meta['session_id']
		), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $row ) ) { self::refuse(); }
		$package = ( new PackageGateway() )->getForCustomerPackageEligibility( $meta['package_id'] );
		if ( ! is_array( $package ) ) { self::refuse(); }
		$product = wc_get_product( (int) $package['wc_product_id'] );
		if ( ! $product instanceof \WC_Product ) { self::refuse(); }
		$addons = [];
		foreach ( $selected as $values ) {
			$child = $values[ CartSelection::META ];
			if ( 'addon' !== $child['role'] ) { continue; }
			$addon = CustomerCartCatalog::addon( $meta['package_id'], $child['addon_id'] );
			if ( is_wp_error( $addon ) ) { self::refuse(); }
			$addons[] = [ 'addon_id' => $addon['package_addon_id'], 'bingo_play_product_id' => $addon['bingo_play_product_id'], 'wc_product_id' => $addon['wc_product_id'], 'quantity' => (int) $values['quantity'] ];
		}
		return [
			'version' => 1, 'site_id' => get_current_blog_id(), 'selection_id' => $meta['selection_id'],
			'session_id' => (int) $row['session_id'], 'session_date' => $row['session_date'], 'session_name' => $row['display_name'],
			'schedule_id' => null === $row['schedule_id'] ? null : (int) $row['schedule_id'],
			'start_time_local' => $row['start_time_local'], 'timezone_name' => $row['timezone_name'], 'starts_at_utc' => $row['starts_at_utc'],
			'package_id' => $meta['package_id'], 'wc_product_id' => (int) $package['wc_product_id'],
			'package_name' => $product->get_name(), 'package_type' => $package['package_type'], 'quantity' => 1, 'addons' => $addons,
		];
	}

	private static function session_label( array $facts ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['session_date'] );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $facts['session_date'] ) { self::refuse(); }
		$label = $date->format( 'm/d/Y' ) . ' — ' . $facts['session_name'];
		if ( null !== $facts['start_time_local'] ) { $label .= ' — ' . substr( $facts['start_time_local'], 0, 5 ) . ' ' . $facts['timezone_name']; }
		return $label;
	}

	public static function display_key( string $label, $meta ): string {
		if ( 'bc_bingo_session' === $meta->key ) { return __( 'Bingo Session', 'bingocommerce-sessions' ); }
		if ( 'bc_package' === $meta->key ) { return __( 'Add-on for', 'bingocommerce-sessions' ); }
		return $label;
	}

	private static function refuse(): never {
		throw new \Exception( __( 'The Bingo purchase could not be recorded. Please review the Session, Package and add-ons before checking out.', 'bingocommerce-sessions' ) );
	}
}
