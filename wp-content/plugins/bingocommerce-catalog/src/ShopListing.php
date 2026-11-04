<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ordinary Shop lists ordinary merchandise only (ADR-045 / BR-084 addition
 * 2026-10-02). Bingo Packages and Bingo Play Products are bought through a dated
 * Session's purchase step, where CustomerCart validates the whole selection, so
 * they are left out of WooCommerce's main product query: the Shop page, product
 * category, tag and attribute archives, and product search on the Shop.
 *
 * Membership is the current Hall's own Catalog rows — every Package and Bingo
 * Play Product, active or archived — never product names or the BR-065
 * compatibility metadata. Listing only: nothing is saved, unpublished or
 * re-priced; catalog visibility, stock and purchasability are unchanged; other
 * product queries (administration, Catalog reads, the Session purchase step)
 * are untouched; and CustomerCart's direct-purchase guard still refuses a Bingo
 * product added any other way. When the Catalog cannot be read the listing is
 * left exactly as WooCommerce built it (the guard still applies), so a Catalog
 * fault never empties the Shop.
 */
final class ShopListing {

	public static function register(): void {
		// Late, after other changes to WooCommerce's product query.
		add_action( 'woocommerce_product_query', [ self::class, 'exclude' ], 100 );
		add_filter( 'register_block_type_args', [ self::class, 'newest_products_block' ], 10, 2 );
	}

	/** @param mixed $query WooCommerce's main product query. */
	public static function exclude( $query ): void {
		if ( $query instanceof \WP_Query ) {
			self::leave_out( $query, self::bingo_product_ids() );
		}
	}

	/**
	 * WooCommerce's Newest Products block ("New in store" on the empty Cart page)
	 * leaves out the current Hall's Packages; Play Products stay as WooCommerce
	 * lists them there. The block runs its own query, not the main product query,
	 * and caches the results under a hash of that query's variables, so the
	 * exclusion is set while the query is parsed, before the hash is taken.
	 *
	 * @param mixed $args Block type arguments.
	 * @param mixed $name Block type name.
	 * @return mixed
	 */
	public static function newest_products_block( $args, $name ) {
		if ( 'woocommerce/product-new' !== $name || ! is_array( $args ) || ! is_callable( $args['render_callback'] ?? null ) ) {
			return $args;
		}
		$render                  = $args['render_callback'];
		$args['render_callback'] = static function ( ...$params ) use ( $render ) {
			$ids   = self::bingo_product_ids( play_products: false );
			$leave = static function ( $query ) use ( $ids ): void {
				if ( $query instanceof \WP_Query && 'product' === $query->get( 'post_type' ) ) {
					self::leave_out( $query, $ids );
				}
			};
			add_action( 'parse_query', $leave );
			try {
				return $render( ...$params );
			} finally {
				remove_action( 'parse_query', $leave );
			}
		};
		return $args;
	}

	/** @param array<int,int> $ids */
	private static function leave_out( \WP_Query $query, array $ids ): void {
		if ( [] === $ids ) {
			return;
		}

		// WP_Query ignores post__not_in whenever post__in is set, so narrow that list instead.
		$in = array_values( array_filter( array_map( 'intval', (array) $query->get( 'post__in' ) ) ) );
		if ( [] !== $in ) {
			$kept = array_values( array_diff( $in, $ids ) );
			$query->set( 'post__in', [] === $kept ? [ 0 ] : $kept );
			return;
		}
		$not_in = array_map( 'intval', (array) $query->get( 'post__not_in' ) );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $not_in, $ids ) ) ) );
	}

	/**
	 * The current Hall's Package and (unless $play_products is false) Bingo Play
	 * Product WooCommerce product IDs, from its own per-site tables; an empty list
	 * when they cannot be read.
	 *
	 * @return array<int,int>
	 */
	public static function bingo_product_ids( bool $play_products = true ): array {
		if ( ! class_exists( HallContext::class ) || ! HallContext::is_hall() || ! Migrations::is_current() ) {
			return [];
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			$play_products
				? $wpdb->prepare( 'SELECT wc_product_id FROM %i UNION SELECT wc_product_id FROM %i', Migrations::packages_table(), Migrations::play_products_table() )
				: $wpdb->prepare( 'SELECT wc_product_id FROM %i', Migrations::packages_table() )
		);
		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return [];
		}

		return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
	}
}
