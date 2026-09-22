<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Immutable ADR-044 request data and its shared, callback-free canonical encoding. */
final class CatalogPackageAddonPlan {
	public const CREATE = 'create_package_addon';
	public const UPDATE = 'update_package_addon';
	public const REMOVE = 'remove_package_addon';
	public const PRICE_PATTERN = '/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/';

	private function __construct(
		public readonly string $mutation,
		public readonly int $blog_id,
		public readonly int $package_addon_id,
		public readonly int $package_id,
		public readonly int $bingo_play_product_id,
		public readonly string $unit_price,
		public readonly int $max_quantity,
		public readonly bool $enabled,
		public readonly string $expected_fingerprint,
		public readonly string $now
	) {}

	public static function forCreate( int $blog_id, int $package_id, int $bingo_play_product_id, string $unit_price, int $max_quantity, string $now ): self {
		return new self( self::CREATE, $blog_id, 0, $package_id, $bingo_play_product_id, $unit_price, $max_quantity, true, '', $now );
	}

	public static function forUpdate( int $blog_id, int $package_addon_id, int $package_id, int $bingo_play_product_id, string $expected_fingerprint, string $unit_price, int $max_quantity, bool $enabled, string $now ): self {
		return new self( self::UPDATE, $blog_id, $package_addon_id, $package_id, $bingo_play_product_id, $unit_price, $max_quantity, $enabled, $expected_fingerprint, $now );
	}

	public static function forRemove( int $blog_id, int $package_addon_id, int $package_id, int $bingo_play_product_id, string $expected_fingerprint, string $now ): self {
		return new self( self::REMOVE, $blog_id, $package_addon_id, $package_id, $bingo_play_product_id, '', 0, false, $expected_fingerprint, $now );
	}

	/** Validated database scalars only; the same encoding serves listings and locked rechecks. */
	public static function fingerprint( array $row ): string {
		$values = [ 'bc-package-addon-fingerprint-v1' ];
		foreach ( [ 'package_addon_id', 'package_id', 'bingo_play_product_id', 'unit_price', 'max_quantity', 'enabled' ] as $key ) {
			$values[] = (string) $row[ $key ];
		}
		$payload = '';
		foreach ( $values as $value ) {
			$payload .= strlen( $value ) . ':' . $value;
		}
		return hash( 'sha256', $payload );
	}

	/** Accept native mysqli integers or canonical wpdb integer strings, never lossy casts. */
	private static function stored_integer( array $row, string $key, int $minimum, int $maximum ): bool {
		$value = $row[ $key ] ?? null;
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return false;
		}
		$string = (string) $value;
		$bound = (string) $maximum;
		return 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $string )
			&& ( strlen( $string ) < strlen( $bound ) || ( strlen( $string ) === strlen( $bound ) && strcmp( $string, $bound ) <= 0 ) )
			&& (int) $value >= $minimum;
	}

	/** Pure integrity predicates: never coerce corrupt stored state into a valid value. */
	public static function validRelationship( array $row ): bool {
		foreach ( [ 'package_addon_id', 'package_id', 'bingo_play_product_id' ] as $key ) {
			if ( ! self::stored_integer( $row, $key, 1, PHP_INT_MAX ) ) { return false; }
		}
		return is_string( $row['unit_price'] ?? null )
			&& 1 === preg_match( self::PRICE_PATTERN, $row['unit_price'] )
			&& self::stored_integer( $row, 'max_quantity', 1, 99 )
			&& self::stored_integer( $row, 'enabled', 0, 1 )
			&& self::stored_integer( $row, 'sort_order', 0, 4294967295 )
			&& is_string( $row['created_at'] ?? null )
			&& is_string( $row['updated_at'] ?? null )
			&& self::validTimestamp( $row['created_at'] ?? '' )
			&& self::validTimestamp( $row['updated_at'] ?? '' );
	}

	public static function validPackage( array $row ): bool {
		return self::stored_integer( $row, 'package_id', 1, PHP_INT_MAX )
			&& self::stored_integer( $row, 'wc_product_id', 1, PHP_INT_MAX )
			&& in_array( $row['package_type'] ?? null, [ 'paper', 'digital', 'hybrid' ], true )
			&& in_array( $row['status'] ?? null, [ 'active', 'archived' ], true );
	}

	public static function validPlayProduct( array $row ): bool {
		return self::stored_integer( $row, 'bingo_play_product_id', 1, PHP_INT_MAX )
			&& self::stored_integer( $row, 'wc_product_id', 1, PHP_INT_MAX )
			&& self::stored_integer( $row, 'faces_per_unit', 1, 4294967295 )
			&& in_array( $row['medium'] ?? null, [ 'paper', 'digital' ], true )
			&& in_array( $row['status'] ?? null, [ 'active', 'archived' ], true )
			&& array_key_exists( 'game_type_id', $row )
			&& ( null === $row['game_type_id'] ? 'paper' === $row['medium'] : self::stored_integer( $row, 'game_type_id', 1, PHP_INT_MAX ) );
	}

	public static function compatible( string $package_type, string $medium ): bool {
		return in_array( $package_type, [ 'paper', 'digital', 'hybrid' ], true )
			&& in_array( $medium, [ 'paper', 'digital' ], true )
			&& ( 'paper' !== $package_type || 'paper' === $medium );
	}

	public static function validTimestamp( string $value ): bool {
		if ( 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/', $value, $parts ) ) {
			return false;
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] < 24 && (int) $parts[5] < 60 && (int) $parts[6] < 60;
	}
}
