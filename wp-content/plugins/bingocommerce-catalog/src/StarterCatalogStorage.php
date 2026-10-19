<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 starter image storage: where published versions keep their frozen
 * image files, the image limits, and the storage checks.
 *
 * The frozen-file folder is chosen by the server only: the constant
 * BINGOCOMMERCE_STARTER_CATALOG_DIR when wp-config.php defines it, otherwise
 * wp-content/uploads/bingo-starter-catalog. It never comes from a request or
 * from a Hall's upload settings. Production shared storage or S3 (ADR-009)
 * must be confirmed before release.
 */
final class StarterCatalogStorage {

	public const ALLOWED_MIME_TYPES = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];

	/** Agreed defaults (stage 3): 5 MB and 4,000 px per side. */
	public const MAX_FILE_BYTES = 5242880;
	public const MAX_DIMENSION  = 4000;

	/**
	 * Space estimate per image: the original plus the sizes WordPress
	 * generates. An estimate, used only to refuse early.
	 */
	public const SIZE_ESTIMATE_FACTOR = 3;

	public static function base_dir(): string {
		if ( defined( 'BINGOCOMMERCE_STARTER_CATALOG_DIR' ) && is_string( BINGOCOMMERCE_STARTER_CATALOG_DIR ) && '' !== BINGOCOMMERCE_STARTER_CATALOG_DIR ) {
			return untrailingslashit( wp_normalize_path( BINGOCOMMERCE_STARTER_CATALOG_DIR ) );
		}

		return untrailingslashit( wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'uploads/bingo-starter-catalog' ) );
	}

	/**
	 * Absolute path of a frozen file from its stored relative path.
	 *
	 * @return string|\WP_Error
	 */
	public static function frozen_path( string $relative ) {
		if ( '' === $relative
			|| str_starts_with( $relative, '/' )
			|| str_contains( $relative, '..' )
			|| str_contains( $relative, '\\' )
			|| 1 !== preg_match( '#^[A-Za-z0-9._/-]+$#D', $relative ) ) {
			return new \WP_Error( 'starter_import_image_missing', __( 'A starter image could not be found. Please contact Bingo Innovation support.', 'bingocommerce-catalog' ) );
		}

		return self::base_dir() . '/' . $relative;
	}

	/** Is the Multisite per-site upload quota switched on? */
	public static function quota_enabled(): bool {
		return is_multisite() && ! get_site_option( 'upload_space_check_disabled' );
	}

	/**
	 * Refuse early when the estimated space for these image bytes is not
	 * available: the Hall's quota only when quota checking is on, and the
	 * disk when PHP can tell.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_space( int $bytes, string $directory ) {
		$needed = $bytes * self::SIZE_ESTIMATE_FACTOR;
		if ( $needed <= 0 ) {
			return true;
		}

		if ( self::quota_enabled() && function_exists( 'get_upload_space_available' ) && $needed > (int) get_upload_space_available() ) {
			return self::low_space();
		}

		if ( function_exists( 'disk_free_space' ) && is_dir( $directory ) ) {
			$free = disk_free_space( $directory );
			if ( is_float( $free ) && $needed > $free ) {
				return self::low_space();
			}
		}

		return true;
	}

	private static function low_space(): \WP_Error {
		return new \WP_Error( 'starter_import_storage_low', __( 'There is not enough upload space in this store for the starter product images.', 'bingocommerce-catalog' ) );
	}
}
