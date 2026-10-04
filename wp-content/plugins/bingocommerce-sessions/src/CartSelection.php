<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

use BingoCommerce\Catalog\CustomerCartCatalog;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** BR-081: request/cart identifiers are claims, never purchase authority. */
final class CartSelection {
	public const META = 'bc_selection';

	/** Canonical positive integer input; do not turn negative/fractional IDs into valid IDs. */
	public static function integer( mixed $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return 0; }
		$string = (string) $value;
		if ( ! preg_match( '/\A[1-9][0-9]*\z/', $string ) || strlen( $string ) > strlen( (string) PHP_INT_MAX ) ) { return 0; }
		$value = (int) $string;
		return (string) $value === $string ? $value : 0;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function resolve( mixed $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'session_id', 'package_id', 'addons' ] ) ) { return self::error(); }
		$session_id = self::integer( $input['session_id'] ?? null );
		$package_id = self::integer( $input['package_id'] ?? null );
		$addons = $input['addons'] ?? [];
		if ( ! $session_id || ! $package_id || ! is_array( $addons ) || ! array_is_list( $addons ) ) { return self::error(); }
		$package = self::package( $session_id, $package_id );
		if ( is_wp_error( $package ) ) { return $package; }
		$selected = [];
		foreach ( $addons as $input_addon ) {
			if ( ! is_array( $input_addon ) || array_diff( array_keys( $input_addon ), [ 'package_addon_id', 'quantity' ] ) ) { return self::error(); }
			$id = self::integer( $input_addon['package_addon_id'] ?? null );
			$quantity = self::integer( $input_addon['quantity'] ?? null );
			if ( ! $id || ! $quantity || isset( $selected[ $id ] ) ) { return self::error(); }
			$addon = CustomerCartCatalog::addon( $package_id, $id );
			if ( is_wp_error( $addon ) ) { return $addon; }
			if ( $quantity > $addon['max_quantity'] ) { return self::error(); }
			$addon['quantity'] = $quantity;
			$selected[ $id ] = $addon;
		}
		$faces = self::electronic_faces( $package_id, array_column( $selected, 'quantity', 'package_addon_id' ) );
		if ( is_wp_error( $faces ) ) { return $faces; }
		return [ 'session_id' => $session_id, 'package_id' => $package_id, 'wc_product_id' => (int) $package['wc_product_id'], 'addons' => $selected ];
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function package( int $session_id, int $package_id ) {
		if ( ! class_exists( CustomerCartCatalog::class ) || ! class_exists( HallContext::class ) || ! HallContext::is_hall() || ! Migrations::is_current() ) { return self::error(); }
		$available = CustomerPackageEligibility::is_available( $session_id, $package_id );
		// An unreadable dependency is an established read failure, not a closed Session or Package.
		if ( is_wp_error( $available ) ) { return self::unverified(); }
		if ( true !== $available ) { return self::error(); }
		$package = ( new PackageGateway() )->getForCustomerPackageEligibility( $package_id );
		return is_array( $package ) ? $package : self::error();
	}

	/** Resolve parent AND variation classification: changing a shell's type is not a bypass. */
	public static function classification( int $product_id, int $variation_id = 0 ) {
		if ( ! class_exists( CustomerCartCatalog::class ) ) { return self::error(); }
		$kind = CustomerCartCatalog::classify( $product_id );
		if ( is_wp_error( $kind ) || ! $variation_id ) { return $kind; }
		$variation = CustomerCartCatalog::classify( $variation_id );
		if ( is_wp_error( $variation ) ) { return $variation; }
		return 'regular' === $kind['kind'] ? $variation : $kind;
	}

	/** Individual line check; inspect() additionally verifies group/parent/duplicate constraints. */
	public static function line( array $item ) {
		$kind = self::classification( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );
		if ( is_wp_error( $kind ) ) { return $kind; }
		if ( 'regular' === $kind['kind'] && ! array_key_exists( self::META, $item ) ) { return [ 'kind' => 'regular' ]; }
		$meta = $item[ self::META ] ?? null;
		if ( ! self::valid_meta( $meta ) || ! empty( $item['variation_id'] ) ) { return self::error(); }
		$package = self::package( $meta['session_id'], $meta['package_id'] );
		if ( is_wp_error( $package ) ) { return $package; }
		$quantity = $item['quantity'] ?? null;
		if ( ! is_numeric( $quantity ) || (float) $quantity !== (float) (int) $quantity || $quantity < 1 ) { return self::error(); }
		if ( 'package' === $meta['role'] ) {
			if ( 'package' !== $kind['kind'] || $kind['id'] !== $meta['package_id'] || 1 !== (int) $quantity || (int) $package['wc_product_id'] !== (int) $item['product_id'] ) { return self::error(); }
			return [ 'kind' => 'package', 'product_id' => (int) $package['wc_product_id'] ];
		}
		$addon = CustomerCartCatalog::addon( $meta['package_id'], $meta['addon_id'] );
		if ( is_wp_error( $addon ) ) { return $addon; }
		if ( 'play' !== $kind['kind'] || $kind['id'] !== $addon['bingo_play_product_id'] || $addon['wc_product_id'] !== (int) $item['product_id'] || $quantity > $addon['max_quantity'] ) { return self::error(); }
		return [ 'kind' => 'addon', 'product_id' => $addon['wc_product_id'], 'price' => $addon['unit_price'], 'max_quantity' => $addon['max_quantity'] ];
	}

	/** @return array{errors:\WP_Error,lines:array,digital_faces:?array} Fresh reads; nothing is cached across Hall switches. */
	public static function inspect( array $contents ): array {
		$errors = new \WP_Error();
		$lines = [];
		$packages = 0;
		$addons = [];
		$expected = [];
		$children = [];
		$package_id = 0;
		$selected_addons = [];
		$digital_faces = null;
		foreach ( $contents as $key => $item ) {
			$line = self::line( $item );
			if ( is_wp_error( $line ) ) {
				self::add_once( $errors, 'bc_cart_invalid', self::unreadable( $line ) ? self::unverified() : self::error() );
				// An add-on line that is still in the cart is reported here, never also as missing from its Package.
				$meta = $item[ self::META ] ?? null;
				if ( self::valid_meta( $meta ) && 'addon' === $meta['role'] ) { $children[ $meta['parent_key'] ][] = $meta['addon_id']; }
				continue;
			}
			if ( 'regular' !== $line['kind'] ) {
				$meta = $item[ self::META ];
				if ( 'package' === $line['kind'] ) {
					++$packages;
					$package_id = $meta['package_id'];
					$expected[ $key ] = $meta['addon_ids'];
				} else {
					$parent = $contents[ $meta['parent_key'] ] ?? [];
					$parent_meta = $parent[ self::META ] ?? null;
					if ( ! self::valid_meta( $parent_meta ) || 'package' !== $parent_meta['role'] || isset( $addons[ $meta['addon_id'] ] ) ) {
						self::add_once( $errors, 'bc_cart_invalid', self::error() );
						continue;
					}
					foreach ( [ 'site_id', 'selection_id', 'session_id', 'package_id' ] as $field ) {
						if ( $meta[ $field ] !== $parent_meta[ $field ] ) { self::add_once( $errors, 'bc_cart_invalid', self::error() ); }
					}
					$addons[ $meta['addon_id'] ] = true;
					$selected_addons[ $meta['addon_id'] ] = (int) $item['quantity'];
					$children[ $meta['parent_key'] ][] = $meta['addon_id'];
				}
				$product = wc_get_product( $line['product_id'] );
				if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
					self::add_once( $errors, 'bc_cart_invalid', self::error() );
					continue;
				}
				$product = clone $product;
				if ( isset( $line['price'] ) ) { self::relation_priced( $product, $line['price'] ); }
				if ( ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $item['quantity'] ) || ( $product->is_sold_individually() && 1 !== (int) $item['quantity'] ) ) {
					self::add_once( $errors, 'bc_cart_invalid', self::error() );
				}
				$line['product'] = $product;
			}
			$lines[ $key ] = $line;
		}
		if ( $packages > 1 ) { $errors->add( 'bc_cart_package_limit', __( 'Remove the existing Bingo Package before selecting another.', 'bingocommerce-sessions' ) ); }
		foreach ( $expected as $key => $ids ) {
			$actual = $children[ $key ] ?? [];
			sort( $ids ); sort( $actual );
			if ( $ids !== $actual ) { $errors->add( 'bc_cart_incomplete', __( 'Part of your Bingo selection is no longer in your cart. Remove the Bingo Package from your cart, then choose it again with the add-ons you want.', 'bingocommerce-sessions' ) ); }
		}
		if ( ! $errors->has_errors() && 1 === $packages ) {
			$faces = self::electronic_faces( $package_id, $selected_addons );
			if ( is_wp_error( $faces ) ) { $errors->merge_from( $faces ); }
			else { $digital_faces = $faces; }
		}
		return [ 'errors' => $errors, 'lines' => $lines, 'digital_faces' => $digital_faces ];
	}

	/**
	 * An add-on's relationship price as the ONLY price of an in-memory product copy
	 * (BR-082; addition 2026-10-02): its price and regular price, with no sale
	 * price. The product's own stored regular and sale prices do not apply to the
	 * relationship, so WooCommerce must not present it as a discount from them
	 * (the Cart block's previous price and "Save") or treat it as a sale item
	 * (coupon sale-item rules). Callers pass a copy; nothing is ever saved.
	 */
	public static function relation_priced( \WC_Product $product, string $price ): \WC_Product {
		$product->set_price( $price );
		$product->set_regular_price( $price );
		$product->set_sale_price( '' );
		return $product;
	}

	private static function electronic_faces( int $package_id, array $addons ) {
		return class_exists( ElectronicLimit::class ) ? ElectronicLimit::validate_selection( $package_id, $addons ) : self::error();
	}

	private static function valid_meta( mixed $meta ): bool {
		if ( ! is_array( $meta ) || 1 !== ( $meta['version'] ?? null ) || get_current_blog_id() !== ( $meta['site_id'] ?? null ) ) { return false; }
		foreach ( [ 'session_id', 'package_id' ] as $field ) {
			if ( ! is_int( $meta[ $field ] ?? null ) || $meta[ $field ] < 1 ) { return false; }
		}
		if ( ! is_string( $meta['selection_id'] ?? null ) || ! wp_is_uuid( $meta['selection_id'], 4 ) ) { return false; }
		$ids = $meta['addon_ids'] ?? null;
		$valid_ids = is_array( $ids ) && array_is_list( $ids ) && count( array_unique( $ids, SORT_REGULAR ) ) === count( $ids );
		if ( $valid_ids ) {
			foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { $valid_ids = false; break; } }
		}
		return ( 'package' === ( $meta['role'] ?? null ) && 0 === ( $meta['addon_id'] ?? null ) && '' === ( $meta['parent_key'] ?? null ) && $valid_ids )
			|| ( 'addon' === ( $meta['role'] ?? null ) && is_int( $meta['addon_id'] ?? null ) && $meta['addon_id'] > 0 && is_string( $meta['parent_key'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/', $meta['parent_key'] ) );
	}

	public static function error(): \WP_Error {
		return new \WP_Error( 'bc_cart_selection_invalid', __( 'This Bingo selection is no longer available. Remove the Bingo Package from your cart, then choose an available Session and Package.', 'bingocommerce-sessions' ) );
	}

	/** Same code as error(), so callers mapping codes are unaffected; only the wording says to try again. */
	private static function unverified(): \WP_Error {
		return new \WP_Error( 'bc_cart_selection_invalid', __( 'We couldn’t check your Bingo selection right now. Please try again in a moment.', 'bingocommerce-sessions' ), [ 'unverified' => true ] );
	}

	/** Only an established read failure; any other refusal reads as no longer available. */
	private static function unreadable( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		return ( is_array( $data ) && true === ( $data['unverified'] ?? null ) ) || 'bc_cart_catalog_unavailable' === $error->get_error_code();
	}

	/** Several lines failing for the same reason are one customer error. */
	private static function add_once( \WP_Error $errors, string $code, \WP_Error $cause ): void {
		$message = $cause->get_error_message();
		if ( ! in_array( $message, $errors->get_error_messages( $code ), true ) ) { $errors->add( $code, $message ); }
	}
}
