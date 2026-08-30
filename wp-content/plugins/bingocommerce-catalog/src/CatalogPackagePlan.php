<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable, fully declarative description of one Bingo Package creation.
 *
 * Like the other Catalog plans this is data, never behaviour. The transport
 * interprets it with one fixed, reviewed statement sequence — the ADR-032
 * Package-create profile — so the complete statement set stays enumerable and
 * the boundary needs no callable.
 *
 * ADR-033 explicitly EXCLUDES creation: a Package may never persist as an active
 * zero-component object (BR-067), so its initial component rows are inserted
 * inside this single ADR-032 boundary. That allowance is for creation only and
 * does not authorise later composition replacement to use ADR-032.
 *
 * `package_type` is deliberately ABSENT. BR-061 derives it from the `medium`
 * values read from the component rows while they are locked inside the
 * transaction; a caller-supplied type would be an unlocked, advisory value
 * masquerading as authority.
 */
final class CatalogPackagePlan {

	public const CREATE_PACKAGE = 'create_package';

	/**
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 */
	private function __construct(
		public readonly string $mutation,
		public readonly int $blog_id,
		public readonly int $wc_product_id,
		public readonly array $components,
		public readonly string $now
	) {}

	/**
	 * The component list carries ONLY Bingo Play Product identity and quantity.
	 * `sort_order` is not accepted: Increment 1 ships no reorder surface, and new
	 * component rows start at 0.
	 *
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 */
	public static function forCreate( int $blog_id, int $wc_product_id, array $components, string $now ): self {
		return new self( self::CREATE_PACKAGE, $blog_id, $wc_product_id, $components, $now );
	}
}
