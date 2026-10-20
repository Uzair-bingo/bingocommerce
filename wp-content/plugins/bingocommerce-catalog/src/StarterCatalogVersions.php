<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 read side of the published starter catalog versions.
 *
 * Halls only ever read versions; StarterCatalogPublisher writes them.
 * A Hall request reads these network tables directly, so it never switches to
 * the main site.
 *
 * Every read separates "not found" from a database error: an error is returned
 * as WP_Error and is never treated as an empty result.
 *
 * Captured product data (the `data` column, JSON) uses these keys:
 *   name, description, short_description, slug, regular_price, sku,
 *   categories (product_cat slugs), tags (product_tag slugs), tax_status,
 *   tax_class (slug, '' for Standard), manage_stock (bool),
 *   image_id and gallery_image_ids (version image IDs),
 *   attributes: [ {name, options[], visible, variation, position} ]
 *   (product-level attributes only; may be empty for a simple product).
 * A variable product (product_type "variable") also has
 *   default_attributes: { key: value }.
 * A variation (product_type "variation", parent_source_id = its parent's
 * source_product_id) has:
 *   attributes: { key: value } ('' means "Any"), regular_price, sku,
 *   manage_stock, enabled (bool), tax_class (slug, '' or 'parent'),
 *   description, image_id.
 * Attribute keys are sanitize_title() of the attribute name.
 * Publish also stores `_terms` (the product's category and tag entries) and
 * builds the version's `terms` column from them; the importer ignores it.
 * The version's `terms` column holds the category tree and tag list:
 *   { "product_cat": [ {slug, name, parent} ], "product_tag": [ {slug, name} ] }
 * where `parent` is a category slug or ''.
 */
final class StarterCatalogVersions {

	public const STATUS_BUILDING  = 'building';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DISCARDED = 'discarded';

	/** Network option: Platform Admin's "Allow new Hall imports" switch. Off unless set to '1'. */
	public const ALLOW_IMPORTS_OPTION = 'bc_starter_catalog_allow_imports';

	public static function imports_allowed(): bool {
		return '1' === (string) get_site_option( self::ALLOW_IMPORTS_OPTION, '0' );
	}

	/** @return array<string,mixed>|null|\WP_Error The latest published version. */
	public static function latest_published() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY version_id DESC LIMIT 1',
				StarterImportMigrations::versions_table(),
				self::STATUS_PUBLISHED
			),
			ARRAY_A
		);

		return self::row_or_error( $row );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public static function get( int $version_id ) {
		global $wpdb;

		if ( $version_id < 1 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d', StarterImportMigrations::versions_table(), $version_id ),
			ARRAY_A
		);

		return self::row_or_error( $row );
	}

	/** @return array{product_cat:array,product_tag:array} Decoded `terms` column. */
	public static function terms( array $version ): array {
		$terms = json_decode( (string) ( $version['terms'] ?? '' ), true );
		if ( ! is_array( $terms ) ) {
			return [ 'product_cat' => [], 'product_tag' => [] ];
		}

		return [
			'product_cat' => is_array( $terms['product_cat'] ?? null ) ? $terms['product_cat'] : [],
			'product_tag' => is_array( $terms['product_tag'] ?? null ) ? $terms['product_tag'] : [],
		];
	}

	/** @return int|\WP_Error Number of top-level products in a version. */
	public static function count_top_level_products( int $version_id ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE version_id = %d AND parent_source_id IS NULL',
				StarterImportMigrations::version_products_table(),
				$version_id
			)
		);
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return (int) $count;
	}

	/**
	 * A variable product's variations in display order.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function variations( int $version_id, int $parent_source_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE version_id = %d AND parent_source_id = %d ORDER BY position ASC, version_product_id ASC',
				StarterImportMigrations::version_products_table(),
				$version_id,
				$parent_source_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return $rows;
	}

	/** @return array<string,mixed>|null|\WP_Error One version image row. */
	public static function image( int $version_image_id ) {
		global $wpdb;

		if ( $version_image_id < 1 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE version_image_id = %d', StarterImportMigrations::version_images_table(), $version_image_id ),
			ARRAY_A
		);

		return self::row_or_error( $row );
	}

	/** @return int|\WP_Error Total bytes of a version's image files. */
	public static function total_image_bytes( int $version_id ) {
		global $wpdb;

		$total = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE( SUM( file_size ), 0 ) FROM %i WHERE version_id = %d', StarterImportMigrations::version_images_table(), $version_id )
		);
		if ( null === $total || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return (int) $total;
	}

	/**
	 * Tax class slugs used by a version's products and variations ('' and
	 * 'parent' excluded).
	 *
	 * @return array<int,string>|\WP_Error
	 */
	public static function required_tax_classes( int $version_id ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT data FROM %i WHERE version_id = %d', StarterImportMigrations::version_products_table(), $version_id )
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		$classes = [];
		foreach ( $rows as $json ) {
			$data  = json_decode( (string) $json, true );
			$class = is_array( $data ) ? (string) ( $data['tax_class'] ?? '' ) : '';
			if ( '' !== $class && 'parent' !== $class ) {
				$classes[ $class ] = true;
			}
		}
		$classes = array_keys( $classes );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Decode one version product row's `data` column.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function data( array $row ) {
		$data = json_decode( (string) ( $row['data'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'starter_import_version_invalid', __( 'A starter product in this version could not be read.', 'bingocommerce-catalog' ) );
		}

		return $data;
	}

	/**
	 * @param mixed $row
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private static function row_or_error( $row ) {
		global $wpdb;

		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	private static function db_error(): \WP_Error {
		return new \WP_Error( 'starter_import_db_error', __( 'The starter catalog could not be read. Please try again.', 'bingocommerce-catalog' ) );
	}
}
