<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce 11 cart adapters only; no order, payment or entitlement hooks. */
final class CustomerCart {
	private static bool $registered = false;
	private static bool $adding = false;
	private static bool $restoring = false;
	private static ?array $pending = null;
	private static ?array $store_update = null;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// BOTH WC_Cart and Store API CartController call this before inserting a line.
		add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'guard_add' ], 100, 4 );
		add_filter( 'woocommerce_product_get_price', [ self::class, 'pending_price' ], 100, 2 );
		add_filter( 'woocommerce_add_cart_item', [ self::class, 'price_new_item' ], 100 );
		add_filter( 'woocommerce_pre_remove_cart_item_from_session', [ self::class, 'prepare_restored_price' ], 100, 4 );
		add_action( 'woocommerce_cart_loaded_from_session', [ self::class, 'refresh' ], 100 );
		add_action( 'woocommerce_before_calculate_totals', [ self::class, 'refresh' ], 100 );
		add_action( 'woocommerce_check_cart_items', [ self::class, 'classic_errors' ], 100 );
		add_action( 'woocommerce_store_api_cart_errors', [ self::class, 'store_errors' ], 100, 2 );
		add_filter( 'woocommerce_update_cart_validation', [ self::class, 'validate_update' ], 100, 4 );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ self::class, 'quantity_updated' ], 100, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ self::class, 'maximum' ], 100, 3 );
		add_action( 'woocommerce_remove_cart_item', [ self::class, 'remove_children' ], 5, 2 );
		add_action( 'woocommerce_cart_item_restored', [ self::class, 'restored' ], 5, 2 );
		add_action( 'woocommerce_blocks_loaded', [ self::class, 'register_store_api' ] );
		add_filter( 'rest_endpoints', [ self::class, 'capture_store_quantity' ] );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) { self::register_store_api(); }
	}

	/**
	 * WC 11's update-item schema and route both call wc_stock_amount (intval by
	 * default). Preserve the pre-sanitization value without changing Woo's route,
	 * nonce/Cart-Token handling or ordinary-merchandise quantity semantics. A
	 * WeakMap also covers WP REST batch validation, which precedes dispatch.
	 */
	public static function capture_store_quantity( array $endpoints ): array {
		foreach ( [ '/wc/store/v1/cart/update-item', '/wc/store/cart/update-item' ] as $route ) {
			foreach ( $endpoints[ $route ] ?? [] as $index => $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['args']['quantity'], $endpoint['callback'] ) ) { continue; }
				$raw = new \WeakMap();
				$validator = $endpoint['args']['quantity']['validate_callback'] ?? 'rest_validate_request_arg';
				$callback = $endpoint['callback'];
				$endpoints[ $route ][ $index ]['args']['quantity']['validate_callback'] = static function ( $value, $request, $param ) use ( $raw, $validator ) {
					$raw[ $request ] = $value;
					return $validator( $value, $request, $param );
				};
				$endpoints[ $route ][ $index ]['callback'] = static function ( $request ) use ( $raw, $callback ) {
					$previous = self::$store_update;
					self::$store_update = [ 'key' => $request['key'], 'quantity' => $raw[ $request ] ?? $request['quantity'] ];
					try { return $callback( $request ); } finally { self::$store_update = $previous; }
				};
			}
		}
		return $endpoints;
	}

	public static function register_store_api(): void {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback( [ 'namespace' => 'bingocommerce-selection', 'callback' => [ self::class, 'store_selection' ] ] );
			woocommerce_store_api_register_endpoint_data( [
				'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace' => 'bingocommerce-selection',
				'data_callback' => [ self::class, 'item_data' ],
				'schema_callback' => [ self::class, 'item_schema' ],
				'schema_type' => ARRAY_A,
			] );
		}
	}

	/** Read-only linkage for a later renderer; no client-owned cart metadata. */
	public static function item_data( array $item ): array {
		$line = CartSelection::line( $item );
		if ( is_wp_error( $line ) || 'regular' === $line['kind'] ) { return []; }
		return array_intersect_key( $item[ CartSelection::META ], self::item_schema() );
	}

	public static function item_schema(): array {
		$schema = [];
		foreach ( [ 'session_id' => 'integer', 'package_id' => 'integer', 'addon_id' => 'integer', 'role' => 'string', 'parent_key' => 'string' ] as $key => $type ) {
			$schema[ $key ] = [ 'description' => $key, 'type' => $type, 'readonly' => true ];
		}
		return $schema;
	}

	/** Existing Woo /cart/extensions owns guest session, nonce/Cart-Token and Hall gate. */
	public static function store_selection( mixed $input ): void {
		$result = self::add( $input );
		if ( is_wp_error( $result ) ) {
			throw new \WC_REST_Exception( $result->get_error_code(), $result->get_error_message(), 400 );
		}
	}

	/**
	 * Server-side entry for the future storefront; same ordinary WC cart in classic/Store API.
	 * No client product IDs, Hall override, prices or cart-line metadata are accepted.
	 * @return string|\WP_Error Parent cart key, or no newly added selection on failure.
	 */
	public static function add( mixed $input ) {
		if ( self::$adding || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) { return CartSelection::error(); }
		$cart = WC()->cart;
		$contents = $cart->get_cart();
		$inspection = CartSelection::inspect( $contents );
		if ( $inspection['errors']->has_errors() ) { return CartSelection::error(); }
		foreach ( $inspection['lines'] as $line ) {
			if ( 'package' === $line['kind'] ) { return new \WP_Error( 'bc_cart_package_limit', __( 'Remove the existing Bingo Package before selecting another.', 'bingocommerce-sessions' ) ); }
		}
		$selection = CartSelection::resolve( $input );
		if ( is_wp_error( $selection ) ) { return $selection; }
		$meta = [
			'version' => 1, 'site_id' => get_current_blog_id(), 'selection_id' => wp_generate_uuid4(),
			'session_id' => $selection['session_id'], 'package_id' => $selection['package_id'],
			'role' => 'package', 'addon_id' => 0, 'parent_key' => '',
			'addon_ids' => array_keys( $selection['addons'] ),
		];
		self::$adding = true;
		try {
			$parent = self::add_line( $cart, $selection['wc_product_id'], 1, $meta );
			if ( ! $parent ) { throw new \RuntimeException(); }
			foreach ( $selection['addons'] as $addon ) {
				$child = array_merge( $meta, [ 'role' => 'addon', 'addon_id' => $addon['package_addon_id'], 'parent_key' => $parent ] );
				if ( ! self::add_line( $cart, $addon['wc_product_id'], $addon['quantity'], $child, $addon['unit_price'] ) ) { throw new \RuntimeException(); }
			}
			// Re-read after native add callbacks; a mid-add change must roll back this selection.
			if ( CartSelection::inspect( $cart->cart_contents )['errors']->has_errors() ) { throw new \RuntimeException(); }
		} catch ( \Throwable $error ) {
			foreach ( $cart->cart_contents as $key => $item ) {
				if ( ( $item[ CartSelection::META ]['selection_id'] ?? null ) === $meta['selection_id'] ) { $cart->remove_cart_item( $key ); }
			}
			// A failed add is not an undoable purchase selection.
			foreach ( $cart->removed_cart_contents as $key => $item ) {
				if ( ( $item[ CartSelection::META ]['selection_id'] ?? null ) === $meta['selection_id'] ) { unset( $cart->removed_cart_contents[ $key ] ); }
			}
			$parent = CartSelection::error();
		} finally {
			self::$pending = null;
			self::$adding = false;
		}
		$cart->calculate_totals();
		return $parent;
	}

	private static function add_line( \WC_Cart $cart, int $product_id, int $quantity, array $meta, ?string $price = null ) {
		self::$pending = [ 'product_id' => $product_id, 'quantity' => $quantity, 'meta' => $meta, 'price' => $price ];
		try {
			$key = $cart->add_to_cart( $product_id, $quantity, 0, [], [ CartSelection::META => $meta ] );
			return $key && (float) $quantity === (float) $cart->cart_contents[ $key ]['quantity'] ? $key : false;
		}
		finally { self::$pending = null; }
	}

	public static function guard_add( array $data, $product_id, $variation_id, $quantity ): array {
		$kind = CartSelection::classification( (int) $product_id, (int) $variation_id );
		if ( ! is_wp_error( $kind ) && 'regular' === $kind['kind'] && ! array_key_exists( CartSelection::META, $data ) ) { return $data; }
		$pending = self::$pending;
		if ( is_wp_error( $kind ) || ! $pending || $pending['product_id'] !== (int) $product_id || 0 !== (int) $variation_id || $pending['quantity'] !== (int) $quantity || $pending['meta'] !== ( $data[ CartSelection::META ] ?? null ) || get_current_blog_id() !== $pending['meta']['site_id'] ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bc_cart_selection_invalid', CartSelection::error()->get_error_message(), 400 );
		}
		return $data;
	}

	/** Price read is scoped to a validated add attempt, never a saved product mutation. */
	public static function pending_price( $price, $product ) {
		$pending = self::$pending;
		return $pending && null !== $pending['price'] && $pending['product_id'] === $product->get_id() && get_current_blog_id() === $pending['meta']['site_id'] ? $pending['price'] : $price;
	}

	public static function price_new_item( array $item ): array {
		if ( self::$pending && null !== self::$pending['price'] && self::$pending['meta'] === ( $item[ CartSelection::META ] ?? null ) ) {
			$item['data'] = clone $item['data'];
			$item['data']->set_price( self::$pending['price'] );
		}
		return $item;
	}

	/** Woo checks purchasability before its session-item filter. Supply only a proven relation price. */
	public static function prepare_restored_price( $remove, $key, $values, $product ) {
		if ( isset( $values[ CartSelection::META ] ) ) {
			$line = CartSelection::line( $values );
			if ( ! is_wp_error( $line ) && isset( $line['price'] ) ) { $product->set_price( $line['price'] ); }
		}
		return $remove;
	}

	public static function refresh( \WC_Cart $cart ): void {
		if ( self::$adding ) { return; }
		$inspection = CartSelection::inspect( $cart->cart_contents );
		foreach ( $inspection['lines'] as $key => $line ) {
			if ( isset( $line['product'] ) ) { $cart->cart_contents[ $key ]['data'] = $line['product']; }
		}
		if ( $inspection['errors']->has_errors() ) { return; }
		$children = [];
		foreach ( $cart->cart_contents as $key => $item ) {
			if ( 'addon' === $inspection['lines'][ $key ]['kind'] ) { $children[ $item[ CartSelection::META ]['parent_key'] ][ $key ] = $item; }
		}
		$ordered = [];
		foreach ( $cart->cart_contents as $key => $item ) {
			if ( 'addon' === $inspection['lines'][ $key ]['kind'] ) { continue; }
			$ordered[ $key ] = $item;
			$ordered += $children[ $key ] ?? [];
		}
		$cart->cart_contents = $ordered;
	}

	public static function classic_errors(): void {
		if ( ! WC()->cart ) { return; }
		$errors = CartSelection::inspect( WC()->cart->get_cart() )['errors'];
		if ( $errors->has_errors() ) { self::notice( $errors->get_error_message() ); }
	}

	public static function store_errors( \WP_Error $errors, \WC_Cart $cart ): void {
		$found = CartSelection::inspect( $cart->get_cart() )['errors'];
		if ( $found->has_errors() ) { $errors->add( 'bc_cart_invalid', $found->get_error_message() ); }
	}

	public static function validate_update( $passed, $key, $values, $quantity ): bool {
		$line = CartSelection::line( $values );
		if ( ! is_wp_error( $line ) && 'regular' === $line['kind'] ) { return (bool) $passed; }
		if ( isset( $values[ CartSelection::META ], $_POST['cart'][ $key ]['qty'] ) && ! self::whole_quantity( wp_unslash( $_POST['cart'][ $key ]['qty'] ) ) ) {
			self::notice( CartSelection::error()->get_error_message() );
			return false;
		}
		if ( ! $passed || 0 === (int) $quantity && 0.0 === (float) $quantity ) { return (bool) $passed; }
		$contents = WC()->cart->get_cart();
		$contents[ $key ]['quantity'] = $quantity;
		$found = CartSelection::inspect( $contents )['errors'];
		if ( $found->has_errors() ) { self::notice( $found->get_error_message() ); return false; }
		return true;
	}

	/** WC_Cart::set_quantity has no pre-validation hook; restore the old value if invalid. */
	public static function quantity_updated( $key, $quantity, $old_quantity, \WC_Cart $cart ): void {
		if ( self::$adding ) { return; }
		$line = CartSelection::line( $cart->cart_contents[ $key ] );
		if ( ! is_wp_error( $line ) && 'regular' === $line['kind'] ) { return; }
		$found = CartSelection::inspect( $cart->cart_contents )['errors'];
		if ( isset( $cart->cart_contents[ $key ][ CartSelection::META ] ) && self::$store_update && $key === self::$store_update['key'] && ! self::whole_quantity( self::$store_update['quantity'] ) ) { $found = CartSelection::error(); }
		$before = $cart->cart_contents;
		$before[ $key ]['quantity'] = $old_quantity;
		// Store API also calls set_quantity from its automatic normalization pass.
		// Never let normalization silently repair a forged/stale Bingo selection.
		if ( 'store-api' === $cart->cart_context && CartSelection::inspect( $before )['errors']->has_errors() ) { $found = CartSelection::error(); }
		if ( $found->has_errors() ) {
			$cart->cart_contents[ $key ]['quantity'] = $old_quantity;
			if ( 'store-api' === $cart->cart_context ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bc_cart_invalid', $found->get_error_message(), 400 ); }
			self::notice( $found->get_error_message() );
		}
	}

	public static function maximum( $maximum, $product, $item ) {
		if ( ! is_array( $item ) || ! isset( $item[ CartSelection::META ] ) ) { return $maximum; }
		$line = CartSelection::line( $item );
		if ( is_wp_error( $line ) ) { return $maximum; }
		$limit = 'package' === $line['kind'] ? 1 : ( $line['max_quantity'] ?? $maximum );
		return min( $maximum, $limit );
	}

	public static function remove_children( $parent_key, \WC_Cart $cart ): void {
		$meta = $cart->cart_contents[ $parent_key ][ CartSelection::META ] ?? [];
		if ( 'addon' === ( $meta['role'] ?? null ) && isset( $cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'] ) ) {
			$ids = $cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'];
			if ( is_array( $ids ) ) { $cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'] = array_values( array_diff( $ids, [ $meta['addon_id'] ] ) ); }
		}
		foreach ( $cart->cart_contents as $key => $item ) {
			if ( 'package' === ( $meta['role'] ?? null ) && 'addon' === ( $item[ CartSelection::META ]['role'] ?? null ) && $key !== $parent_key && ( $item[ CartSelection::META ]['parent_key'] ?? null ) === $parent_key ) { $cart->remove_cart_item( $key ); }
		}
	}

	/** Undo is not an authorization bypass, and never substitutes a different parent. */
	public static function restored( $key, \WC_Cart $cart ): void {
		if ( self::$restoring ) { return; }
		self::$restoring = true;
		try {
			$meta = $cart->cart_contents[ $key ][ CartSelection::META ] ?? [];
			if ( 'package' === ( $meta['role'] ?? null ) && is_array( $meta['addon_ids'] ?? null ) ) {
				foreach ( $cart->removed_cart_contents as $child_key => $item ) {
					$child = $item[ CartSelection::META ] ?? [];
					if ( ( $child['parent_key'] ?? null ) === $key && ( $child['selection_id'] ?? null ) === ( $meta['selection_id'] ?? null ) && in_array( $child['addon_id'] ?? null, $meta['addon_ids'] ?? [], true ) ) { $cart->restore_cart_item( $child_key ); }
				}
			} elseif ( 'addon' === ( $meta['role'] ?? null ) && isset( $cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'] ) ) {
				$ids = $cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'];
				if ( ! is_array( $ids ) ) { $ids = []; }
				$ids[] = $meta['addon_id'];
				$cart->cart_contents[ $meta['parent_key'] ][ CartSelection::META ]['addon_ids'] = array_values( array_unique( $ids ) );
			}
		} finally { self::$restoring = false; }
		if ( CartSelection::inspect( $cart->cart_contents )['errors']->has_errors() ) {
			$cart->remove_cart_item( $key );
			self::notice( CartSelection::error()->get_error_message() );
		}
		self::refresh( $cart );
	}

	private static function notice( string $message ): void {
		if ( ! wc_has_notice( $message, 'error' ) ) { wc_add_notice( $message, 'error' ); }
	}

	private static function whole_quantity( mixed $value ): bool {
		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) && is_numeric( $value ) && (float) $value === (float) (int) $value && $value >= 0;
	}
}
