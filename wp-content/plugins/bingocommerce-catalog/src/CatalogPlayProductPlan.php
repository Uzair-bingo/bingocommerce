<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable, fully declarative description of one approved Catalog critical
 * mutation (ADR-032).
 *
 * This is deliberately data, never behaviour. CatalogTransactionTransport
 * interprets it with a fixed, reviewed statement sequence, so the transport
 * never accepts a callable and its complete set of possible statements stays
 * enumerable at review time.
 */
final class CatalogPlayProductPlan {

	public const CREATE          = 'create';
	public const ASSIGN_GAME_TYPE = 'assign_game_type';
	public const PAPER_TO_DIGITAL = 'paper_to_digital';
	public const RESTORE          = 'restore';

	private function __construct(
		public readonly string $mutation,
		public readonly int $blog_id,
		public readonly int $wc_product_id,
		public readonly int $bingo_play_product_id,
		public readonly ?int $target_game_type_id,
		public readonly ?string $medium,
		public readonly ?int $faces_per_unit,
		public readonly bool $check_package_claim,
		public readonly string $now
	) {}

	public static function forCreate(
		int $blog_id,
		int $wc_product_id,
		string $medium,
		int $faces_per_unit,
		?int $game_type_id,
		bool $check_package_claim,
		string $now
	): self {
		return new self( self::CREATE, $blog_id, $wc_product_id, 0, $game_type_id, $medium, $faces_per_unit, $check_package_claim, $now );
	}

	public static function forAssignGameType( int $blog_id, int $bingo_play_product_id, int $game_type_id, string $now ): self {
		return new self( self::ASSIGN_GAME_TYPE, $blog_id, 0, $bingo_play_product_id, $game_type_id, null, null, false, $now );
	}

	public static function forPaperToDigital( int $blog_id, int $bingo_play_product_id, int $game_type_id, string $now ): self {
		return new self( self::PAPER_TO_DIGITAL, $blog_id, 0, $bingo_play_product_id, $game_type_id, 'digital', null, false, $now );
	}

	public static function forRestore( int $blog_id, int $bingo_play_product_id, int $wc_product_id, string $now ): self {
		return new self( self::RESTORE, $blog_id, $wc_product_id, $bingo_play_product_id, null, null, null, false, $now );
	}
}
