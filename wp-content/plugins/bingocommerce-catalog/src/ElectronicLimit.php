<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** BR-038/BR-042/BR-082: current reads only; no settings writers or counters. */
final class ElectronicLimit {
	public const PLATFORM_OPTION = 'bc_platform_electronic_maximum';
	public const HALL_OPTION = 'bc_electronic_limit_override';

	/** @return int|\WP_Error Configured limits must be positive whole numbers. */
	public static function effective_limit() {
		if ( ! HallContext::is_hall() ) { return self::configuration_error(); }
		$site = get_site( get_current_blog_id() );
		if ( ! $site instanceof \WP_Site || (int) $site->network_id < 1 ) { return self::configuration_error(); }
		global $wpdb;
		// Read storage afresh: option APIs/cache cannot distinguish a failed read
		// from an absent override and may retain an earlier platform maximum.
		$platform = $wpdb->get_col( $wpdb->prepare(
			'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 2',
			$wpdb->sitemeta, (int) $site->network_id, self::PLATFORM_OPTION
		) );
		if ( '' !== $wpdb->last_error || count( $platform ) !== 1 ) { return self::configuration_error(); }
		$maximum = self::integer( $platform[0] );
		if ( null === $maximum || $maximum < 1 ) { return self::configuration_error(); }
		$override = $wpdb->get_col( $wpdb->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s LIMIT 2', $wpdb->options, self::HALL_OPTION
		) );
		if ( '' !== $wpdb->last_error || count( $override ) > 1 ) { return self::configuration_error(); }
		if ( [] === $override ) { return $maximum; }
		$hall = self::integer( $override[0] );
		return null === $hall || $hall < 1 ? self::configuration_error() : min( $maximum, $hall );
	}

	/**
	 * Catalog contribution check, composed AFTER exact-occurrence eligibility.
	 * Only IDs/quantities are accepted; all face facts are read from this Hall.
	 * @param array<int,int> $addons Selected relationship IDs => quantities.
	 * @return array<int,int>|\WP_Error Digital faces keyed by Hall-local Game Type.
	 */
	public static function validate_selection( int $package_id, array $addons ) {
		$package = ( new PackageService() )->getForCustomerPackageEligibility( $package_id );
		if ( is_wp_error( $package ) ) { return $package; }
		if ( 'active' !== $package['status'] || empty( $package['components'] ) || ! empty( $package['has_unresolved_component'] ) ) { return self::dependency_error(); }
		$totals = [];
		foreach ( $package['components'] as $component ) {
			if ( 'active' !== $component['play_product_status'] || ! self::add_faces( $totals, $component, $component['quantity'] ) ) { return self::dependency_error(); }
		}
		foreach ( $addons as $id => $quantity ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $quantity ) || $quantity < 1 ) { return self::dependency_error(); }
			$addon = CustomerCartCatalog::addon( $package_id, $id );
			if ( is_wp_error( $addon ) ) { return $addon; }
			if ( $quantity > $addon['max_quantity'] || ! self::add_faces( $totals, $addon, $quantity ) ) { return self::dependency_error(); }
		}
		// Paper-only selections never read or depend on electronic configuration.
		if ( [] === $totals ) { return []; }
		global $wpdb;
		foreach ( $totals as $game_type_id => $total ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT game_type_id FROM %i WHERE game_type_id = %d', Migrations::game_types_table(), $game_type_id ) );
			if ( '' !== $wpdb->last_error || (string) $game_type_id !== (string) $found ) { return self::dependency_error(); }
		}
		$limit = self::effective_limit();
		if ( is_wp_error( $limit ) ) { return $limit; }
		foreach ( $totals as $game_type_id => $total ) {
			if ( $total > $limit ) {
				return new \WP_Error(
					'bc_electronic_limit_exceeded',
					__( 'Your selection exceeds this Hall’s digital bingo face limit. Reduce the digital add-ons or choose a different Package.', 'bingocommerce-catalog' ),
					[ 'game_type_id' => $game_type_id, 'faces' => $total, 'limit' => $limit ]
				);
			}
		}
		ksort( $totals );
		return $totals;
	}

	private static function add_faces( array &$totals, array $product, mixed $quantity ): bool {
		if ( ! is_int( $quantity ) || $quantity < 1 ) { return false; }
		if ( 'paper' === ( $product['medium'] ?? null ) ) { return true; }
		$faces = self::integer( $product['faces_per_unit'] ?? null );
		$game = self::integer( $product['game_type_id'] ?? null );
		if ( 'digital' !== ( $product['medium'] ?? null ) || null === $faces || $faces < 1 || null === $game || $game < 1 || $faces > intdiv( PHP_INT_MAX, $quantity ) ) { return false; }
		$contribution = $faces * $quantity;
		$previous = $totals[ $game ] ?? 0;
		if ( $previous > PHP_INT_MAX - $contribution ) { return false; }
		$totals[ $game ] = $previous + $contribution;
		return true;
	}

	private static function integer( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		$string = (string) $value;
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $string ) || strlen( $string ) > strlen( (string) PHP_INT_MAX ) ) { return null; }
		return (string) (int) $string === $string ? (int) $string : null;
	}

	private static function configuration_error(): \WP_Error {
		return new \WP_Error( 'bc_electronic_limit_unavailable', __( 'Digital bingo can’t be purchased at this Hall right now. Please try again later, or choose a Paper Package.', 'bingocommerce-catalog' ) );
	}

	private static function dependency_error(): \WP_Error {
		return new \WP_Error( 'bc_electronic_faces_unknown', __( 'We couldn’t confirm the digital bingo in this selection. Remove the Bingo Package from your cart, then choose an available Session and Package.', 'bingocommerce-catalog' ) );
	}
}
