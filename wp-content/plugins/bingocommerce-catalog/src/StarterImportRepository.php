<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 Hall-side import records: the one-time receipt and the per-item
 * progress rows, in the current Hall's own tables.
 *
 * Writes go through $wpdb->insert()/update(), which prepare every value.
 * Reads separate "not found" (null) from a database error (WP_Error); an
 * error is never treated as "not found".
 */
final class StarterImportRepository {

	public const START_MARKER_OPTION = 'bc_starter_import_started';

	public const RECEIPT_IMPORTING       = 'importing';
	public const RECEIPT_FAILED          = 'failed';
	public const RECEIPT_NEEDS_ATTENTION = 'needs_attention';
	public const RECEIPT_COMPLETE        = 'complete';

	public const ITEM_CLAIMED         = 'claimed';
	public const ITEM_CREATING        = 'creating';
	public const ITEM_CREATED         = 'created';
	public const ITEM_COMPLETE        = 'complete';
	public const ITEM_SKIPPED         = 'skipped';
	public const ITEM_NEEDS_ATTENTION = 'needs_attention';

	private const RECEIPT_FIELDS = [
		'status'             => '%s',
		'total_products'     => '%d',
		'imported_products'  => '%d',
		'skipped_products'   => '%d',
		'last_error_code'    => '%s',
		'last_error_item_id' => '%d',
		'updated_at'         => '%s',
		'completed_at'       => '%s',
	];

	private const ITEM_FIELDS = [
		'status'        => '%s',
		'wc_product_id' => '%d',
		'decisions'     => '%s',
		'note'          => '%s',
		'updated_at'    => '%s',
		'completed_at'  => '%s',
	];

	/* ------------------------------------------------------------------ *
	 * Receipt
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|null|\WP_Error */
	public static function receipt() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY import_id ASC LIMIT 1', StarterImportMigrations::import_table() ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|\WP_Error The new receipt, read back. */
	public static function create_receipt( string $import_uuid, int $version_id, int $total_products, int $user_id ) {
		global $wpdb;

		$now      = self::now();
		$inserted = $wpdb->insert(
			StarterImportMigrations::import_table(),
			[
				'singleton'      => 1,
				'import_uuid'    => $import_uuid,
				'version_id'     => $version_id,
				'status'         => self::RECEIPT_IMPORTING,
				'total_products' => $total_products,
				'started_by'     => $user_id,
				'started_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ]
		);
		if ( 1 !== $inserted ) {
			return self::db_error();
		}

		$receipt = self::receipt();
		if ( ! is_array( $receipt ) || $import_uuid !== (string) $receipt['import_uuid'] ) {
			return self::db_error();
		}

		return $receipt;
	}

	/** @return true|\WP_Error */
	public static function update_receipt( int $import_id, array $fields ) {
		return self::update( StarterImportMigrations::import_table(), 'import_id', $import_id, $fields, self::RECEIPT_FIELDS );
	}

	/* ------------------------------------------------------------------ *
	 * Items
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|null|\WP_Error */
	public static function item( int $import_id, int $version_product_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE import_id = %d AND version_product_id = %d',
				StarterImportMigrations::import_products_table(),
				$import_id,
				$version_product_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Save an item's identity and recovery identifier (status "claimed").
	 *
	 * @return array<string,mixed>|\WP_Error The new item, read back.
	 */
	public static function insert_item( int $import_id, int $version_product_id, ?int $parent_item_id, string $product_type, int $position, string $recovery_slug ) {
		global $wpdb;

		$now  = self::now();
		$data = [
			'import_id'          => $import_id,
			'version_product_id' => $version_product_id,
			'product_type'       => $product_type,
			'position'           => $position,
			'status'             => self::ITEM_CLAIMED,
			'recovery_slug'      => $recovery_slug,
			'claimed_at'         => $now,
			'updated_at'         => $now,
		];
		$formats = [ '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ];
		if ( null !== $parent_item_id ) {
			$data['parent_item_id'] = $parent_item_id;
			$formats[]              = '%d';
		}

		if ( 1 !== $wpdb->insert( StarterImportMigrations::import_products_table(), $data, $formats ) ) {
			return self::db_error();
		}

		$item = self::item( $import_id, $version_product_id );
		if ( ! is_array( $item ) || $recovery_slug !== (string) $item['recovery_slug'] ) {
			return self::db_error();
		}

		return $item;
	}

	/** @return true|\WP_Error */
	public static function update_item( int $item_id, array $fields ) {
		return self::update( StarterImportMigrations::import_products_table(), 'item_id', $item_id, $fields, self::ITEM_FIELDS );
	}

	/**
	 * The next top-level version product that is not complete or skipped, in
	 * display order. Joins the network version table with this Hall's items;
	 * both live in the same database.
	 *
	 * @return array<string,mixed>|null|\WP_Error Version product row with
	 *         `item_id` (null when never claimed).
	 */
	public static function next_pending_top_level( int $import_id, int $version_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT vp.*, i.item_id AS item_id FROM %i vp'
				. ' LEFT JOIN %i i ON i.version_product_id = vp.version_product_id AND i.import_id = %d'
				. ' WHERE vp.version_id = %d AND vp.parent_source_id IS NULL'
				. ' AND ( i.item_id IS NULL OR i.status NOT IN ( %s, %s ) )'
				. ' ORDER BY vp.position ASC, vp.version_product_id ASC LIMIT 1',
				StarterImportMigrations::version_products_table(),
				StarterImportMigrations::import_products_table(),
				$import_id,
				$version_id,
				self::ITEM_COMPLETE,
				self::ITEM_SKIPPED
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/** @return array{imported:int,skipped:int}|\WP_Error Top-level counts. */
	public static function counts( int $import_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS total FROM %i WHERE import_id = %d AND parent_item_id IS NULL GROUP BY status',
				StarterImportMigrations::import_products_table(),
				$import_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		$counts = [ 'imported' => 0, 'skipped' => 0 ];
		foreach ( $rows as $row ) {
			if ( self::ITEM_COMPLETE === $row['status'] ) {
				$counts['imported'] = (int) $row['total'];
			} elseif ( self::ITEM_SKIPPED === $row['status'] ) {
				$counts['skipped'] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed>  $fields
	 * @param array<string,string> $allowed column => format
	 * @return true|\WP_Error
	 */
	private static function update( string $table, string $key, int $id, array $fields, array $allowed ) {
		global $wpdb;

		if ( $id < 1 || [] === $fields || [] !== array_diff_key( $fields, $allowed ) ) {
			return self::db_error();
		}

		$fields['updated_at'] = $fields['updated_at'] ?? self::now();
		$formats              = [];
		foreach ( array_keys( $fields ) as $column ) {
			$formats[] = $allowed[ $column ];
		}

		$updated = $wpdb->update( $table, $fields, [ $key => $id ], $formats, [ '%d' ] );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return true;
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function db_error(): \WP_Error {
		return new \WP_Error( 'starter_import_db_error', __( 'The starter import progress could not be saved or read. Please try again.', 'bingocommerce-catalog' ) );
	}
}
