<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Internal identity-only reader. Its caller supplies an authenticated Hall context. */
final class RedemptionGameTypes {
	/** @return array<int,int>|\WP_Error Local snapshot IDs => stable Portal IDs. */
	public static function map( array $ids ): array|\WP_Error {
		if ( ! HallContext::is_hall() || [] === $ids ) { return self::error(); }
		foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return self::error(); } }
		$ids = array_values( array_unique( $ids ) );
		global $wpdb;
		$table = Migrations::game_types_table();
		$slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT game_type_id,portal_game_type_id FROM %i WHERE game_type_id IN ($slots)", $table, ...$ids ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== count( $ids ) ) { return self::error(); }
		$result = []; $seen = [];
		foreach ( $rows as $row ) {
			$portal = $row['portal_game_type_id'];
			if ( ! is_string( $portal ) || ! preg_match( '/\A[1-9][0-9]*\z/', $portal ) || (string) (int) $portal !== $portal || isset( $seen[ $portal ] ) ) { return self::error(); }
			$result[ (int) $row['game_type_id'] ] = (int) $portal; $seen[ $portal ] = true;
		}
		ksort( $result, SORT_NUMERIC );
		return $result;
	}

	private static function error(): \WP_Error { return new \WP_Error( 'mapping_unavailable', 'Required Game Type mapping is unavailable.', [ 'status' => 503, 'retryable' => true ] ); }
}
