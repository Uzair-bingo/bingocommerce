<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of one confirmed ADR-032 Catalog transaction.
 *
 * `mutated` is false when the transaction verified, under its locks, that the
 * requested change was already true. Nothing was written, so the caller must
 * report a no-op and emit no audit event.
 */
final class CatalogWriteOutcome {

	/**
	 * @param int                      $bingo_play_product_id The SUBJECT id of the
	 *        boundary that produced this outcome. For the Play Product profiles
	 *        that is the bingo_play_product_id; for the Package profiles it is the
	 *        package_id. The name is historical and predates the Package
	 *        boundaries.
	 * @param array<string,mixed>|null $before_row Row as locked, before any write.
	 */
	public function __construct(
		public readonly int $bingo_play_product_id,
		public readonly bool $mutated,
		public readonly ?array $before_row
	) {}
}
