<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable, fully declarative description of one ADR-033 Package
 * composition replacement.
 *
 * Like CatalogPlayProductPlan this is data, never behaviour. The transport
 * interprets it with one fixed, reviewed statement sequence, so ADR-033's
 * boundary needs no callable and its complete statement set stays enumerable.
 */
final class CatalogPackageCompositionPlan {

	public const REPLACE_COMPOSITION = 'replace_composition';

	/**
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 */
	private function __construct(
		public readonly string $mutation,
		public readonly int $blog_id,
		public readonly int $package_id,
		public readonly string $expected_fingerprint,
		public readonly array $components,
		public readonly string $now
	) {}

	/**
	 * The component list carries ONLY the Bingo Play Product identity and its
	 * quantity. sort_order is deliberately not accepted: exposing it here would
	 * be an unreviewed reorder mutation, and Increment 1 ships no reorder API or
	 * UI. Existing rows keep their current sort_order; new rows start at 0.
	 *
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 */
	public static function forReplaceComposition(
		int $blog_id,
		int $package_id,
		string $expected_fingerprint,
		array $components,
		string $now
	): self {
		return new self( self::REPLACE_COMPOSITION, $blog_id, $package_id, $expected_fingerprint, $components, $now );
	}
}
