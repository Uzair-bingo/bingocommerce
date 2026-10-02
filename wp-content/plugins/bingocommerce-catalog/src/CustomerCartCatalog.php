<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** BR-081 anonymous, current-Hall reads only. Never repairs projections or writes. */
final class CustomerCartCatalog {
	/** @return array{kind:string,id:int}|\WP_Error */
	public static function classify( int $wc_product_id ) {
		if ( ! self::ready() || $wc_product_id < 1 ) {
			return self::unavailable();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT 'package' AS kind, package_id AS id FROM %i WHERE wc_product_id = %d
			 UNION ALL SELECT 'play' AS kind, bingo_play_product_id AS id FROM %i WHERE wc_product_id = %d",
			Migrations::packages_table(), $wc_product_id, Migrations::play_products_table(), $wc_product_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error || count( $rows ) > 1 ) {
			return self::unavailable();
		}
		return [] === $rows ? [ 'kind' => 'regular', 'id' => 0 ] : [ 'kind' => $rows[0]['kind'], 'id' => (int) $rows[0]['id'] ];
	}

	/** @return array<string,mixed>|\WP_Error Only the requested, currently eligible relationship. */
	public static function addon( int $package_id, int $addon_id ) {
		if ( ! self::ready() ) {
			return self::unavailable();
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package_id = %d AND package_addon_id = %d', Migrations::package_addons_table(), $package_id, $addon_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return self::unavailable(); }
		if ( ! is_array( $row ) ) { return self::ineligible(); }
		if ( ! CatalogPackageAddonPlan::validRelationship( $row ) ) { return self::unavailable(); }
		$package = $wpdb->get_row( $wpdb->prepare( 'SELECT package_id, wc_product_id, package_type, status FROM %i WHERE package_id = %d', Migrations::packages_table(), $package_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $package ) || ! CatalogPackageAddonPlan::validPackage( $package ) ) { return self::unavailable(); }
		$product = $wpdb->get_row( $wpdb->prepare( 'SELECT bingo_play_product_id, wc_product_id, medium, faces_per_unit, game_type_id, status FROM %i WHERE bingo_play_product_id = %d', Migrations::play_products_table(), $row['bingo_play_product_id'] ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $product ) || ! CatalogPackageAddonPlan::validPlayProduct( $product ) ) { return self::unavailable(); }
		if ( 'active' !== $package['status'] || 'active' !== $product['status'] || 1 !== (int) $row['enabled'] || ! CatalogPackageAddonPlan::compatible( $package['package_type'], $product['medium'] ) ) {
			return self::ineligible();
		}
		return [
			'package_addon_id' => (int) $row['package_addon_id'],
			'package_id' => $package_id,
			'bingo_play_product_id' => (int) $product['bingo_play_product_id'],
			'wc_product_id' => (int) $product['wc_product_id'],
			'unit_price' => $row['unit_price'],
			'max_quantity' => (int) $row['max_quantity'],
			'medium' => $product['medium'],
			'faces_per_unit' => (int) $product['faces_per_unit'],
			'game_type_id' => null === $product['game_type_id'] ? null : (int) $product['game_type_id'],
		];
	}

	/**
	 * Every currently eligible relationship of one Package, in the Manager's
	 * order, for the customer listing. Each row is decided by addon() itself, so
	 * the listing can never offer a relationship the cart would refuse on these
	 * grounds. Ordinary ineligibility is skipped; an unreadable row is counted.
	 *
	 * @return array{items:array<int,array<string,mixed>>,errors:int}|\WP_Error
	 */
	public static function addons( int $package_id ) {
		if ( ! self::ready() || $package_id < 1 ) {
			return self::unavailable();
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT package_addon_id FROM %i WHERE package_id = %d ORDER BY sort_order, package_addon_id', Migrations::package_addons_table(), $package_id ) );
		if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) { return self::unavailable(); }
		$items = [];
		$errors = 0;
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $id ) ) {
				++$errors;
				continue;
			}
			$addon = self::addon( $package_id, (int) $id );
			if ( ! is_wp_error( $addon ) ) {
				$items[] = $addon;
			} elseif ( 'bc_cart_addon_ineligible' !== $addon->get_error_code() ) {
				++$errors;
			}
		}
		return [ 'items' => $items, 'errors' => $errors ];
	}

	private static function ready(): bool {
		return class_exists( HallContext::class ) && HallContext::is_hall() && Migrations::is_current();
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'bc_cart_catalog_unavailable', __( 'The Hall Catalog could not be verified. Please try again before purchasing.', 'bingocommerce-catalog' ) );
	}

	private static function ineligible(): \WP_Error {
		return new \WP_Error( 'bc_cart_addon_ineligible', __( 'This add-on is not available with the selected Bingo Package.', 'bingocommerce-catalog' ) );
	}
}
