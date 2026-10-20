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

	public const IMAGE_PLANNED         = 'planned';
	public const IMAGE_FILE_WRITTEN    = 'file_written';
	public const IMAGE_CREATING        = 'creating';
	public const IMAGE_CREATED         = 'attachment_created';
	public const IMAGE_COMPLETE        = 'complete';
	public const IMAGE_OMITTED         = 'omitted';
	public const IMAGE_NEEDS_ATTENTION = 'needs_attention';

	private const IMAGE_FIELDS = [
		'status'        => '%s',
		'attachment_id' => '%d',
		'note'          => '%s',
		'updated_at'    => '%s',
		'completed_at'  => '%s',
	];

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

	/**
	 * Mark a skipped parent's unfinished variation items skipped too.
	 *
	 * @return true|\WP_Error
	 */
	public static function skip_children( int $import_id, int $parent_item_id, string $note ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, note = %s, updated_at = %s, completed_at = %s'
				. ' WHERE import_id = %d AND parent_item_id = %d AND status NOT IN ( %s, %s )',
				StarterImportMigrations::import_products_table(),
				self::ITEM_SKIPPED,
				$note,
				self::now(),
				self::now(),
				$import_id,
				$parent_item_id,
				self::ITEM_COMPLETE,
				self::ITEM_SKIPPED
			)
		);
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Images
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|null|\WP_Error */
	public static function image( int $import_id, int $version_image_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE import_id = %d AND version_image_id = %d',
				StarterImportMigrations::import_images_table(),
				$import_id,
				$version_image_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Save an image's planned Hall path and recovery identifier (status
	 * "planned") before any file is written.
	 *
	 * @return array<string,mixed>|\WP_Error The new row, read back.
	 */
	public static function insert_image( int $import_id, int $version_image_id, string $planned_path, string $recovery_slug ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			StarterImportMigrations::import_images_table(),
			[
				'import_id'        => $import_id,
				'version_image_id' => $version_image_id,
				'status'           => self::IMAGE_PLANNED,
				'planned_path'     => $planned_path,
				'recovery_slug'    => $recovery_slug,
				'updated_at'       => self::now(),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		if ( 1 !== $inserted ) {
			return self::db_error();
		}

		$row = self::image( $import_id, $version_image_id );
		if ( ! is_array( $row ) || $recovery_slug !== (string) $row['recovery_slug'] ) {
			return self::db_error();
		}

		return $row;
	}

	/** @return true|\WP_Error */
	public static function update_image( int $import_image_id, array $fields ) {
		return self::update( StarterImportMigrations::import_images_table(), 'import_image_id', $import_image_id, $fields, self::IMAGE_FIELDS );
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
	 * Reads for the Starter Products page
	 * ------------------------------------------------------------------ */

	/**
	 * Where the import is: the next unfinished product in selection order and,
	 * for a variable product, which of its variations comes next.
	 *
	 * @return array{position:int,total:int,variation:int,variations:int}|null|\WP_Error Null when no product is left.
	 */
	public static function progress( int $import_id, int $version_id ) {
		global $wpdb;

		$next = self::next_pending_top_level( $import_id, $version_id );
		if ( is_wp_error( $next ) || null === $next ) {
			return $next;
		}
		$total = StarterCatalogVersions::count_top_level_products( $version_id );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		$variation  = 0;
		$variations = 0;
		if ( 'variable' === $next['product_type'] ) {
			$variations = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id = %d AND parent_source_id = %d', StarterImportMigrations::version_products_table(), $version_id, (int) $next['source_product_id'] )
			);
			if ( null === $variations || '' !== (string) $wpdb->last_error ) {
				return self::db_error();
			}
			$variations = (int) $variations;

			$done = 0;
			if ( null !== $next['item_id'] ) {
				$done = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE import_id = %d AND parent_item_id = %d AND status IN ( %s, %s )',
						StarterImportMigrations::import_products_table(),
						$import_id,
						(int) $next['item_id'],
						self::ITEM_COMPLETE,
						self::ITEM_SKIPPED
					)
				);
				if ( null === $done || '' !== (string) $wpdb->last_error ) {
					return self::db_error();
				}
			}
			$variation = min( (int) $done + 1, $variations );
		}

		return [
			'position'   => (int) $next['position'],
			'total'      => (int) $total,
			'variation'  => $variation,
			'variations' => $variations,
		];
	}

	/**
	 * Every item of the import with a readable label ("Dab O Ink", or
	 * "Dab O Ink, variation Blue"), its saved note and its group (the
	 * product's item ID, shared by its variations), in selection order with
	 * each variation after its product.
	 *
	 * @return array<int,array{item_id:int,group:int,label:string,note:string}>|\WP_Error
	 */
	public static function labelled_items( int $import_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.item_id, i.parent_item_id, i.note, vp.data, vp.position FROM %i i INNER JOIN %i vp ON vp.version_product_id = i.version_product_id WHERE i.import_id = %d ORDER BY vp.position ASC, i.item_id ASC',
				StarterImportMigrations::import_products_table(),
				StarterImportMigrations::version_products_table(),
				$import_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		$data     = [];
		$position = [];
		foreach ( $rows as $row ) {
			$decoded                           = json_decode( (string) $row['data'], true );
			$data[ (int) $row['item_id'] ]     = is_array( $decoded ) ? $decoded : [];
			$position[ (int) $row['item_id'] ] = (int) $row['position'];
		}

		$items = [];
		foreach ( $rows as $row ) {
			$id     = (int) $row['item_id'];
			$parent = null !== $row['parent_item_id'] ? (int) $row['parent_item_id'] : null;
			if ( null === $parent ) {
				$label = (string) ( $data[ $id ]['name'] ?? '' );
				$order = [ $position[ $id ], 0 ];
			} else {
				$values = array_filter( array_map( 'strval', (array) ( $data[ $id ]['attributes'] ?? [] ) ), static fn( string $value ): bool => '' !== $value );
				/* translators: 1: product name, 2: variation attribute values. */
				$label = sprintf( __( '%1$s, variation %2$s', 'bingocommerce-catalog' ), (string) ( $data[ $parent ]['name'] ?? '' ), [] !== $values ? implode( ' / ', $values ) : __( 'Any', 'bingocommerce-catalog' ) );
				$order = [ $position[ $parent ] ?? 0, $position[ $id ] ];
			}
			$items[] = [
				'item_id' => $id,
				'group'   => $parent ?? $id,
				'label'   => $label,
				'note'    => (string) ( $row['note'] ?? '' ),
				'order'   => $order,
			];
		}
		// Each variation directly after its product.
		usort( $items, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] ?: $a['item_id'] <=> $b['item_id'] );

		return array_map(
			static fn( array $item ): array => [ 'item_id' => $item['item_id'], 'group' => $item['group'], 'label' => $item['label'], 'note' => $item['note'] ],
			$items
		);
	}

	/**
	 * Is this product one the import has not finished? Used for the "still
	 * being imported" warning on its edit screen.
	 *
	 * @return bool|\WP_Error
	 */
	public static function is_unfinished_product( int $wc_product_id ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE wc_product_id = %d AND status NOT IN ( %s, %s ) LIMIT 1',
				StarterImportMigrations::import_products_table(),
				$wc_product_id,
				self::ITEM_COMPLETE,
				self::ITEM_SKIPPED
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return null !== $found;
	}

	/* ------------------------------------------------------------------ *
	 * Reads for Resolve
	 * ------------------------------------------------------------------ */

	/**
	 * The import's first product or variation that needs attention, with its
	 * parent's product ID (`parent_wc_product_id`) and its version data
	 * (`version_data`).
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public static function item_needing_attention( int $import_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT i.*, p.wc_product_id AS parent_wc_product_id, vp.data AS version_data FROM %i i'
				. ' INNER JOIN %i vp ON vp.version_product_id = i.version_product_id'
				. ' LEFT JOIN %i p ON p.item_id = i.parent_item_id'
				. ' WHERE i.import_id = %d AND i.status = %s ORDER BY i.item_id ASC LIMIT 1',
				StarterImportMigrations::import_products_table(),
				StarterImportMigrations::version_products_table(),
				StarterImportMigrations::import_products_table(),
				$import_id,
				self::ITEM_NEEDS_ATTENTION
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null|\WP_Error The import's first image that needs attention. */
	public static function image_needing_attention( int $import_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE import_id = %d AND status = %s ORDER BY import_image_id ASC LIMIT 1',
				StarterImportMigrations::import_images_table(),
				$import_id,
				self::IMAGE_NEEDS_ATTENTION
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
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
