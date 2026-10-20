<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 section 6: publishes the master catalog as a numbered, frozen
 * version. Network Super Admins only; every mutation takes the network
 * publish lock.
 *
 * start() checks every included product and, only when nothing blocks,
 * creates a Building version and saves the selection in ascending ID order
 * with ONE multi-row INSERT, so the selection is saved whole or not at all.
 * A Building version without selection rows comes only from an interrupted
 * Start: it is never resumed or published, only discarded.
 *
 * continue_build() captures products in small requests (at least one per
 * request): each product is checked again, its images are frozen (one row and
 * file per source image per version; a retry reuses the same row and
 * overwrites its fixed path), its variations are replaced, and the product is
 * marked captured. When none is pending, the final check runs: a product,
 * variation or frozen image that changed since capture marks that product
 * pending and stops ("… changed during publication. Select Resume …"); a
 * changed currency or tax setting stops for Discard. Only then does the
 * version become Published.
 *
 * discard() marks the version Discarded first, then removes its rows and
 * files; it can be repeated until cleanup is complete.
 */
final class StarterCatalogPublisher {

	/** Test seam: fires after each saved step. */
	public const CHECKPOINT_ACTION = 'bingocommerce_starter_catalog_checkpoint';

	public const DEFAULT_BUDGET_SECONDS = 5.0;

	public const STATUS_BUILDING  = 'building';
	public const STATUS_BLOCKED   = 'blocked';
	public const STATUS_CHANGED   = 'changed';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DISCARDED = 'discarded';
	public const STATUS_IDLE      = 'idle';

	private const PENDING  = 'pending';
	private const CAPTURED = 'captured';

	private ?StarterImportLock $lock = null;
	private float $deadline          = 0.0;

	/** Version image IDs frozen in this request, by version and source image. */
	private array $frozen = [];

	/* ------------------------------------------------------------------ *
	 * Start
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function start() {
		return $this->locked(
			function () {
				$building = self::building_version();
				if ( is_wp_error( $building ) ) {
					return $building;
				}
				if ( null !== $building ) {
					return self::error( 'starter_catalog_build_exists', 'A version is already being published. Resume or Discard it first.' );
				}

				$ids = StarterCatalogMaster::selected_ids();
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				if ( [] === $ids ) {
					return self::outcome( self::STATUS_BLOCKED, null, [ __( 'No products are included in the starter catalog.', 'bingocommerce-catalog' ) ] );
				}

				$problems = [];
				$warnings = [];
				$types    = [];
				foreach ( $ids as $id ) {
					$inspected   = StarterCatalogChecks::inspect( $id );
					$problems    = array_merge( $problems, $inspected['problems'] );
					$warnings    = array_merge( $warnings, $inspected['warnings'] );
					$types[ $id ] = '' !== $inspected['type'] ? $inspected['type'] : 'simple';
				}

				$settings = self::main_settings();
				if ( '' === $settings['currency'] ) {
					$problems[] = __( 'The main site has no store currency.', 'bingocommerce-catalog' );
				}
				if ( [] !== $problems ) {
					return self::outcome( self::STATUS_BLOCKED, null, $problems, $warnings );
				}

				return $this->create_version( $ids, $types, $settings, $warnings );
			}
		);
	}

	/**
	 * @param array<int,int>             $ids
	 * @param array<int,string>          $types
	 * @param array{currency:string,prices_include_tax:string} $settings
	 * @param array<int,string>          $warnings
	 * @return array<string,mixed>|\WP_Error
	 */
	private function create_version( array $ids, array $types, array $settings, array $warnings ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			StarterImportMigrations::versions_table(),
			[
				'status'             => StarterCatalogVersions::STATUS_BUILDING,
				'product_count'      => 0,
				'image_count'        => 0,
				'currency'           => $settings['currency'],
				'prices_include_tax' => $settings['prices_include_tax'],
				'created_by'         => get_current_user_id(),
				'created_at'         => $now,
				'updated_at'         => $now,
			]
		);
		$version_id = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || $version_id < 1 ) {
			return self::db_error();
		}
		$this->checkpoint( 'version_created', [ 'version_id' => $version_id ] );

		// The whole selection in one statement: saved completely or not at all.
		$rows   = [];
		$values = [ StarterImportMigrations::version_products_table() ];
		foreach ( array_values( $ids ) as $index => $id ) {
			$rows[] = '( %d, %d, NULL, %s, %d, %s, %s, %s )';
			array_push( $values, $version_id, $id, $types[ $id ], $index + 1, self::PENDING, $now, $now );
		}
		$saved = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( version_id, source_product_id, parent_source_id, product_type, position, capture_status, created_at, updated_at ) VALUES ' . implode( ', ', $rows ),
				$values
			)
		);
		if ( count( $ids ) !== (int) $saved || '' !== (string) $wpdb->last_error ) {
			$this->mark_discarded( $version_id );

			return self::error( 'starter_catalog_selection_failed', 'The selection could not be saved. Please publish again.' );
		}
		$this->checkpoint( 'selection_saved', [ 'version_id' => $version_id ] );

		return self::outcome( self::STATUS_BUILDING, $version_id, [], $warnings, count( $ids ), 0 );
	}

	/* ------------------------------------------------------------------ *
	 * Continue (Resume)
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function continue_build( float $budget_seconds = self::DEFAULT_BUDGET_SECONDS ) {
		return $this->locked(
			function () use ( $budget_seconds ) {
				// Image IDs remembered from an earlier call are not trusted; the
				// database-backed reuse check runs again.
				$this->frozen = [];

				$version = self::building_version();
				if ( is_wp_error( $version ) ) {
					return $version;
				}
				if ( null === $version ) {
					return self::outcome( self::STATUS_IDLE, null );
				}
				$version_id = (int) $version['version_id'];

				$progress = self::progress( $version_id );
				if ( is_wp_error( $progress ) ) {
					return $progress;
				}
				if ( 0 === $progress['total'] ) {
					return self::error( 'starter_catalog_selection_missing', 'This version\'s selection was not saved. Discard it and publish again.' );
				}

				$this->deadline = microtime( true ) + max( 0.0, $budget_seconds );
				$warnings       = [];
				$worked         = false;

				while ( true ) {
					if ( ! $this->lock->is_still_held() ) {
						return self::error( StarterImportLock::LOCK_FAILED, 'The publish lock was lost. Please try again.' );
					}

					$row = self::next_pending( $version_id );
					if ( is_wp_error( $row ) ) {
						return $row;
					}
					if ( null === $row ) {
						return $this->finalize( $version, $warnings );
					}
					if ( $worked && microtime( true ) >= $this->deadline ) {
						break;
					}

					$captured = $this->capture_product( $version_id, $row );
					if ( is_wp_error( $captured ) ) {
						return $captured;
					}
					$warnings = array_merge( $warnings, $captured['warnings'] );
					if ( [] !== $captured['problems'] ) {
						$progress = self::progress( $version_id );

						return self::outcome( self::STATUS_BLOCKED, $version_id, $captured['problems'], $warnings, is_array( $progress ) ? $progress['total'] : 0, is_array( $progress ) ? $progress['captured'] : 0 );
					}
					$worked = true;
					$this->checkpoint( 'product_captured', [ 'version_id' => $version_id, 'source_product_id' => (int) $row['source_product_id'] ] );

					if ( microtime( true ) >= $this->deadline ) {
						break;
					}
				}

				$progress = self::progress( $version_id );
				if ( is_wp_error( $progress ) ) {
					return $progress;
				}

				return self::outcome( self::STATUS_BUILDING, $version_id, [], $warnings, $progress['total'], $progress['captured'] );
			}
		);
	}

	/**
	 * Check the product again, freeze its images, replace its variation rows
	 * and mark it captured.
	 *
	 * @param array<string,mixed> $row
	 * @return array{problems:array<int,string>,warnings:array<int,string>}|\WP_Error
	 */
	private function capture_product( int $version_id, array $row ) {
		global $wpdb;

		$source_id = (int) $row['source_product_id'];
		$inspected = StarterCatalogChecks::inspect( $source_id );
		if ( [] !== $inspected['problems'] ) {
			return [ 'problems' => $inspected['problems'], 'warnings' => $inspected['warnings'] ];
		}

		$frozen = [];
		foreach ( $inspected['images'] as $attachment_id => $info ) {
			$image = $this->freeze_image( $version_id, $info );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			$frozen[ (int) $attachment_id ] = $image;
		}

		$data                      = $inspected['data'];
		$data['image_id']          = $frozen[ $inspected['featured'] ] ?? 0;
		$data['gallery_image_ids'] = array_values( array_filter( array_map( static fn( int $id ): int => $frozen[ $id ] ?? 0, $inspected['gallery'] ) ) );

		// Replace this product's variation rows (and any variation row of the
		// same variations under another parent). Only variation rows: a saved
		// top-level selection row is never deleted here; a conflict makes the
		// insert below fail instead.
		$table        = StarterImportMigrations::version_products_table();
		$children_ids = array_map( static fn( array $variation ): int => (int) $variation['id'], $inspected['variations'] );
		$deleted      = [] === $children_ids
			? $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE version_id = %d AND parent_source_id = %d', $table, $version_id, $source_id ) )
			: $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE version_id = %d AND ( parent_source_id = %d OR ( parent_source_id IS NOT NULL AND source_product_id IN ( ' . implode( ', ', array_fill( 0, count( $children_ids ), '%d' ) ) . ' ) ) )', array_merge( [ $table, $version_id, $source_id ], $children_ids ) ) );
		if ( false === $deleted ) {
			return self::db_error();
		}

		if ( [] !== $inspected['variations'] ) {
			$now    = current_time( 'mysql', true );
			$rows   = [];
			$values = [ $table ];
			foreach ( $inspected['variations'] as $variation ) {
				$variation_data             = $variation['data'];
				$variation_data['image_id'] = $frozen[ (int) $variation['image'] ] ?? 0;
				$json                       = wp_json_encode( $variation_data );
				if ( false === $json ) {
					return self::error( 'starter_catalog_capture_failed', 'A starter product could not be saved in the version. Please try again.' );
				}
				$rows[] = '( %d, %d, %d, %s, %d, %s, %s, %s, %s, %s )';
				array_push( $values, $version_id, (int) $variation['id'], $source_id, 'variation', (int) $variation['position'], self::CAPTURED, (string) $variation['modified'], $json, $now, $now );
			}
			$saved = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i ( version_id, source_product_id, parent_source_id, product_type, position, capture_status, source_modified_gmt, data, created_at, updated_at ) VALUES ' . implode( ', ', $rows ),
					$values
				)
			);
			if ( count( $rows ) !== (int) $saved || '' !== (string) $wpdb->last_error ) {
				return self::db_error();
			}
		}
		$this->checkpoint( 'variations_saved', [ 'version_id' => $version_id, 'source_product_id' => $source_id ] );

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return self::error( 'starter_catalog_capture_failed', 'A starter product could not be saved in the version. Please try again.' );
		}
		$updated = $wpdb->update(
			$table,
			[
				'product_type'        => $inspected['type'],
				'capture_status'      => self::CAPTURED,
				'source_modified_gmt' => $inspected['modified'],
				'data'                => $json,
				'updated_at'          => current_time( 'mysql', true ),
			],
			[ 'version_product_id' => (int) $row['version_product_id'] ]
		);
		if ( false === $updated ) {
			return self::db_error();
		}

		return [ 'problems' => [], 'warnings' => $inspected['warnings'] ];
	}

	/**
	 * Freeze one source image into this version: one row and one file per
	 * source image. A retry reuses the same row (its ID never changes) and
	 * overwrites its fixed path.
	 *
	 * @param array<string,mixed> $info From StarterCatalogChecks.
	 * @return int|\WP_Error The version image ID.
	 */
	private function freeze_image( int $version_id, array $info ) {
		global $wpdb;

		$attachment_id = (int) $info['attachment_id'];
		if ( isset( $this->frozen[ $version_id ][ $attachment_id ] ) ) {
			return $this->frozen[ $version_id ][ $attachment_id ];
		}

		$relative = sprintf( 'v%d/%d/%s.%s', $version_id, $attachment_id, $info['name'], $info['extension'] );
		$absolute = StarterCatalogStorage::frozen_path( $relative );
		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}
		$checksum = hash_file( 'sha256', (string) $info['file'] );
		if ( ! is_string( $checksum ) ) {
			return self::error( 'starter_catalog_image_failed', 'A starter image could not be read. Please try again.' );
		}

		$table = StarterImportMigrations::version_images_table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d AND source_attachment_id = %d', $table, $version_id, $attachment_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		$reusable = is_array( $row ) && self::CAPTURED === $row['capture_status'] && $relative === $row['file_path']
			&& hash_equals( (string) $row['checksum'], $checksum ) && is_file( $absolute ) && hash_equals( $checksum, (string) hash_file( 'sha256', $absolute ) );
		if ( $reusable && (string) $row['alt_text'] !== (string) $info['alt'] ) {
			if ( false === $wpdb->update( $table, [ 'alt_text' => (string) $info['alt'], 'updated_at' => current_time( 'mysql', true ) ], [ 'version_image_id' => (int) $row['version_image_id'] ] ) ) {
				return self::db_error();
			}
		}
		if ( ! $reusable ) {
			$written = $this->write_frozen_file( (string) $info['file'], $absolute, $checksum, (int) $info['size'] );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$this->checkpoint( 'image_copied', [ 'version_id' => $version_id, 'source_attachment_id' => $attachment_id ] );

			$fields = [
				'capture_status' => self::CAPTURED,
				'file_path'      => $relative,
				'checksum'       => $checksum,
				'mime_type'      => (string) $info['mime'],
				'width'          => (int) $info['width'],
				'height'         => (int) $info['height'],
				'file_size'      => (int) $info['size'],
				'alt_text'       => (string) $info['alt'],
				'updated_at'     => current_time( 'mysql', true ),
			];
			if ( is_array( $row ) ) {
				if ( false === $wpdb->update( $table, $fields, [ 'version_image_id' => (int) $row['version_image_id'] ] ) ) {
					return self::db_error();
				}
				if ( (string) $row['file_path'] !== $relative ) {
					$this->remove_frozen_file( $version_id, (string) $row['file_path'] );
				}
			} else {
				$fields += [
					'version_id'           => $version_id,
					'source_attachment_id' => $attachment_id,
					'created_at'           => current_time( 'mysql', true ),
				];
				if ( 1 !== $wpdb->insert( $table, $fields ) ) {
					return self::db_error();
				}
				$row = [ 'version_image_id' => (int) $wpdb->insert_id ];
			}
			$this->checkpoint( 'image_saved', [ 'version_id' => $version_id, 'source_attachment_id' => $attachment_id ] );
		}

		$this->frozen[ $version_id ][ $attachment_id ] = (int) $row['version_image_id'];

		return (int) $row['version_image_id'];
	}

	/**
	 * Copy through a temporary file, check it, then move it into place, so the
	 * fixed path never holds a partial file.
	 *
	 * @return true|\WP_Error
	 */
	private function write_frozen_file( string $source, string $absolute, string $checksum, int $bytes ) {
		$folder = dirname( $absolute );
		if ( ! wp_mkdir_p( $folder ) ) {
			return self::error( 'starter_catalog_storage_failed', 'The starter catalog image folder is not writable.' );
		}
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unknown amount is not an error.
			if ( is_float( $free ) && $bytes > $free ) {
				return self::error( 'starter_catalog_storage_low', 'There is not enough disk space for the starter catalog images.' );
			}
		}

		$partial = $absolute . '.part';
		if ( ! @copy( $source, $partial ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
			return self::error( 'starter_catalog_storage_failed', 'A starter image could not be saved. Please try again.' );
		}
		if ( ! hash_equals( $checksum, (string) hash_file( 'sha256', $partial ) ) ) {
			wp_delete_file( $partial );

			return self::error( 'starter_catalog_image_failed', 'A starter image changed while it was being copied. Please try again.' );
		}
		if ( ! @rename( $partial, $absolute ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
			wp_delete_file( $partial );

			return self::error( 'starter_catalog_storage_failed', 'A starter image could not be saved. Please try again.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Final check and publication
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $version
	 * @param array<int,string>   $warnings
	 * @return array<string,mixed>|\WP_Error
	 */
	private function finalize( array $version, array $warnings ) {
		global $wpdb;

		// Each read is checked straight away: a failed read returns an empty
		// array, and the next query clears the error. A failed read must stop
		// here, before anything is marked, removed or published.
		$version_id = (int) $version['version_id'];
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d ORDER BY position ASC, version_product_id ASC', StarterImportMigrations::version_products_table(), $version_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		$images = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id = %d', StarterImportMigrations::version_images_table(), $version_id ), ARRAY_A );
		if ( ! is_array( $images ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		$top      = [];
		$children = [];
		foreach ( $rows as $row ) {
			if ( null === $row['parent_source_id'] ) {
				$top[] = $row;
			} else {
				$children[ (int) $row['parent_source_id'] ][] = $row;
			}
		}
		$images = array_column( $images, null, 'version_image_id' );

		$current = self::current_posts( $top, $children );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$changed    = [];
		$referenced = [];
		$intact     = [];
		foreach ( $top as $row ) {
			$data = json_decode( (string) $row['data'], true );
			$data = is_array( $data ) ? $data : [];
			$id   = (int) $row['source_product_id'];
			$kids = $children[ $id ] ?? [];

			$product_images = array_merge( [ (int) ( $data['image_id'] ?? 0 ) ], array_map( 'intval', (array) ( $data['gallery_image_ids'] ?? [] ) ) );
			foreach ( $kids as $kid ) {
				$kid_data         = json_decode( (string) $kid['data'], true );
				$product_images[] = is_array( $kid_data ) ? (int) ( $kid_data['image_id'] ?? 0 ) : 0;
			}
			$product_images = array_values( array_unique( array_filter( $product_images ) ) );
			foreach ( $product_images as $image_id ) {
				$referenced[ $image_id ] = true;
			}

			if ( self::CAPTURED !== $row['capture_status'] || self::post_changed( $current['posts'][ $id ] ?? null, 'product', (string) $row['source_modified_gmt'] )
				|| ! self::children_unchanged( $kids, $current['children'][ $id ] ?? [], $current['posts'] )
				|| ! self::images_intact( $product_images, $images, $intact ) ) {
				$changed[ (int) $row['version_product_id'] ] = '' !== (string) ( $data['name'] ?? '' ) ? (string) $data['name'] : sprintf( 'Product #%d', $id );
			}
		}

		if ( [] !== $changed ) {
			$ids    = array_keys( $changed );
			$marked = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET capture_status = %s, updated_at = %s WHERE version_product_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
					array_merge( [ StarterImportMigrations::version_products_table(), self::PENDING, current_time( 'mysql', true ) ], $ids )
				)
			);
			if ( false === $marked ) {
				return self::db_error();
			}
			$messages = array_map(
				/* translators: %s: product name. */
				static fn( string $name ): string => sprintf( __( '%s changed during publication. Select Resume to capture it again.', 'bingocommerce-catalog' ), $name ),
				array_values( $changed )
			);
			$progress = self::progress( $version_id );

			return self::outcome( self::STATUS_CHANGED, $version_id, $messages, $warnings, is_array( $progress ) ? $progress['total'] : 0, is_array( $progress ) ? $progress['captured'] : 0 );
		}

		$settings = self::main_settings();
		if ( $settings['currency'] !== (string) $version['currency'] || $settings['prices_include_tax'] !== (string) $version['prices_include_tax'] ) {
			return self::error( 'starter_catalog_settings_changed', 'The main site\'s currency or "prices include tax" setting changed during publication. Discard this version and publish again.' );
		}
		$this->checkpoint( 'finalize_checked', [ 'version_id' => $version_id ] );

		// Images no product references any more (a product's image changed
		// before it was captured again) are removed; nothing points at them.
		foreach ( $images as $image_id => $image ) {
			if ( ! isset( $referenced[ (int) $image_id ] ) ) {
				if ( false === $wpdb->delete( StarterImportMigrations::version_images_table(), [ 'version_image_id' => (int) $image_id ] ) ) {
					return self::db_error();
				}
				$this->remove_frozen_file( $version_id, (string) $image['file_path'] );
			}
		}
		$this->remove_partial_files( $version_id );

		$terms     = self::merged_terms( $top );
		$published = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, product_count = %d, image_count = %d, terms = %s, published_by = %d, published_at = %s, updated_at = %s WHERE version_id = %d AND status = %s',
				StarterImportMigrations::versions_table(),
				StarterCatalogVersions::STATUS_PUBLISHED,
				count( $top ),
				count( $referenced ),
				(string) wp_json_encode( $terms ),
				get_current_user_id(),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$version_id,
				StarterCatalogVersions::STATUS_BUILDING
			)
		);
		if ( 1 !== $published ) {
			return self::db_error();
		}
		$this->checkpoint( 'published', [ 'version_id' => $version_id ] );

		self::audit_published( $version_id, (string) $version['created_at'], count( $top ), count( $referenced ) );

		return self::outcome( self::STATUS_PUBLISHED, $version_id, [], $warnings, count( $top ), count( $top ) );
	}

	/**
	 * The main site's current rows for every captured product and variation,
	 * and each product's current (not trashed) variations.
	 *
	 * @return array{posts:array<int,array<string,mixed>>,children:array<int,array<int,int>>}|\WP_Error
	 */
	private static function current_posts( array $top, array $children ) {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( get_main_site_id() );
		$ids    = array_map( static fn( array $row ): int => (int) $row['source_product_id'], $top );
		foreach ( $children as $kids ) {
			foreach ( $kids as $kid ) {
				$ids[] = (int) $kid['source_product_id'];
			}
		}
		$top_ids = array_map( static fn( array $row ): int => (int) $row['source_product_id'], $top );
		$out     = [ 'posts' => [], 'children' => [] ];
		if ( [] === $top_ids ) {
			return $out;
		}

		$posts = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ID, post_type, post_status, post_modified_gmt FROM %i WHERE ID IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )', array_merge( [ $prefix . 'posts' ], $ids ) ),
			ARRAY_A
		);
		if ( ! is_array( $posts ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		$kids = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ID, post_parent FROM %i WHERE post_type = %s AND post_status NOT IN ( %s, %s ) AND post_parent IN ( ' . implode( ', ', array_fill( 0, count( $top_ids ), '%d' ) ) . ' )', array_merge( [ $prefix . 'posts', 'product_variation', 'trash', 'auto-draft' ], $top_ids ) ),
			ARRAY_A
		);
		if ( ! is_array( $kids ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		foreach ( $posts as $post ) {
			$out['posts'][ (int) $post['ID'] ] = $post;
		}
		foreach ( $kids as $kid ) {
			$out['children'][ (int) $kid['post_parent'] ][] = (int) $kid['ID'];
		}

		return $out;
	}

	/** @param array<string,mixed>|null $post */
	private static function post_changed( ?array $post, string $type, string $captured_modified ): bool {
		return null === $post || $type !== $post['post_type'] || 'trash' === $post['post_status'] || (string) $post['post_modified_gmt'] !== $captured_modified;
	}

	/**
	 * @param array<int,array<string,mixed>> $captured
	 * @param array<int,int>                 $current_ids
	 * @param array<int,array<string,mixed>> $posts
	 */
	private static function children_unchanged( array $captured, array $current_ids, array $posts ): bool {
		$captured_ids = array_map( static fn( array $row ): int => (int) $row['source_product_id'], $captured );
		sort( $captured_ids );
		sort( $current_ids );
		if ( $captured_ids !== $current_ids ) {
			return false;
		}
		foreach ( $captured as $row ) {
			if ( self::post_changed( $posts[ (int) $row['source_product_id'] ] ?? null, 'product_variation', (string) $row['source_modified_gmt'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every image the product uses is captured and its frozen file is intact.
	 * Each file is checked once per final check, however many products use it.
	 *
	 * @param array<int,int>                 $image_ids
	 * @param array<int,array<string,mixed>> $images
	 * @param array<int,bool>                $intact Results so far, by image ID.
	 */
	private static function images_intact( array $image_ids, array $images, array &$intact ): bool {
		foreach ( $image_ids as $image_id ) {
			if ( ! isset( $intact[ $image_id ] ) ) {
				$image = $images[ $image_id ] ?? null;
				$file  = is_array( $image ) ? StarterCatalogStorage::frozen_path( (string) $image['file_path'] ) : null;

				$intact[ $image_id ] = is_array( $image ) && self::CAPTURED === $image['capture_status'] && is_string( $file ) && is_file( $file )
					&& hash_equals( (string) $image['checksum'], (string) hash_file( 'sha256', $file ) );
			}
			if ( ! $intact[ $image_id ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The version's category tree and tag list, from the captured products.
	 *
	 * @param array<int,array<string,mixed>> $top
	 * @return array{product_cat:array<int,array<string,string>>,product_tag:array<int,array<string,string>>}
	 */
	private static function merged_terms( array $top ): array {
		$merged = [ 'product_cat' => [], 'product_tag' => [] ];
		foreach ( $top as $row ) {
			$data = json_decode( (string) $row['data'], true );
			foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
				foreach ( (array) ( $data['_terms'][ $taxonomy ] ?? [] ) as $entry ) {
					if ( is_array( $entry ) && '' !== (string) ( $entry['slug'] ?? '' ) && ! isset( $merged[ $taxonomy ][ $entry['slug'] ] ) ) {
						$merged[ $taxonomy ][ (string) $entry['slug'] ] = array_map( 'strval', $entry );
					}
				}
			}
		}

		return [
			'product_cat' => array_values( $merged['product_cat'] ),
			'product_tag' => array_values( $merged['product_tag'] ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Discard
	 * ------------------------------------------------------------------ */

	/**
	 * Discard a Building version, or finish cleaning up a Discarded one.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function discard( int $version_id ) {
		return $this->locked(
			function () use ( $version_id ) {
				global $wpdb;

				$version = StarterCatalogVersions::get( $version_id );
				if ( is_wp_error( $version ) ) {
					return $version;
				}
				if ( null === $version || ! in_array( $version['status'], [ StarterCatalogVersions::STATUS_BUILDING, StarterCatalogVersions::STATUS_DISCARDED ], true ) ) {
					return self::error( 'starter_catalog_not_discardable', 'Only a version that is being published can be discarded.' );
				}

				// Discarded first, so a partly cleaned version can never be
				// resumed or published.
				if ( StarterCatalogVersions::STATUS_BUILDING === $version['status'] && ! $this->mark_discarded( $version_id ) ) {
					return self::db_error();
				}
				$this->checkpoint( 'discard_marked', [ 'version_id' => $version_id ] );

				foreach ( [ StarterImportMigrations::version_products_table(), StarterImportMigrations::version_images_table() ] as $table ) {
					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE version_id = %d', $table, $version_id ) ) ) {
						return self::db_error();
					}
				}
				$this->checkpoint( 'discard_rows_deleted', [ 'version_id' => $version_id ] );

				if ( ! self::remove_version_folder( $version_id ) ) {
					return self::error( 'starter_catalog_storage_failed', 'Some image files of the discarded version could not be removed. Select Finish Discard to try again.' );
				}

				return self::outcome( self::STATUS_DISCARDED, $version_id );
			}
		);
	}

	private function mark_discarded( int $version_id ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE version_id = %d AND status = %s',
				StarterImportMigrations::versions_table(),
				StarterCatalogVersions::STATUS_DISCARDED,
				current_time( 'mysql', true ),
				$version_id,
				StarterCatalogVersions::STATUS_BUILDING
			)
		);
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE version_id = %d', StarterImportMigrations::versions_table(), $version_id ) );

		return StarterCatalogVersions::STATUS_DISCARDED === $status;
	}

	/* ------------------------------------------------------------------ *
	 * State (read only)
	 * ------------------------------------------------------------------ */

	/**
	 * The Building version's progress and Discarded versions still to clean.
	 *
	 * @return array{building:array<string,mixed>|null,cleanup:array<int,int>}|\WP_Error
	 */
	public static function state() {
		global $wpdb;

		$building = self::building_version();
		if ( is_wp_error( $building ) ) {
			return $building;
		}

		$out = [ 'building' => null, 'cleanup' => [] ];
		if ( null !== $building ) {
			$progress = self::progress( (int) $building['version_id'] );
			if ( is_wp_error( $progress ) ) {
				return $progress;
			}
			$out['building'] = [
				'version_id' => (int) $building['version_id'],
				'total'      => $progress['total'],
				'captured'   => $progress['captured'],
				'resumable'  => $progress['total'] > 0,
			];
		}

		$discarded = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT v.version_id FROM %i v WHERE v.status = %s AND ( EXISTS ( SELECT 1 FROM %i p WHERE p.version_id = v.version_id ) OR EXISTS ( SELECT 1 FROM %i i WHERE i.version_id = v.version_id ) )',
				StarterImportMigrations::versions_table(),
				StarterCatalogVersions::STATUS_DISCARDED,
				StarterImportMigrations::version_products_table(),
				StarterImportMigrations::version_images_table()
			)
		);
		if ( ! is_array( $discarded ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		$all = $wpdb->get_col( $wpdb->prepare( 'SELECT version_id FROM %i WHERE status = %s', StarterImportMigrations::versions_table(), StarterCatalogVersions::STATUS_DISCARDED ) );
		if ( ! is_array( $all ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		foreach ( array_map( 'intval', $all ) as $version_id ) {
			if ( in_array( (string) $version_id, $discarded, true ) || is_dir( self::version_folder( $version_id ) ) ) {
				$out['cleanup'][] = $version_id;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Authorize, take the publish lock and prepare the network tables, then
	 * run the work; the lock is always released.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function locked( callable $work ) {
		if ( ! is_multisite() || ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) {
			return self::error( 'starter_catalog_denied', 'You are not authorized to publish the starter catalog.' );
		}

		$lock = StarterImportLock::acquire_publish();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$this->lock = $lock;

		try {
			$schema = StarterImportMigrations::ensure_network();
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			return $work();
		} finally {
			$this->lock = null;
			$lock->release();
		}
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private static function building_version() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY version_id DESC LIMIT 1', StarterImportMigrations::versions_table(), StarterCatalogVersions::STATUS_BUILDING ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/** @return array{total:int,captured:int}|\WP_Error Top-level products. */
	private static function progress( int $version_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COALESCE( SUM( capture_status = %s ), 0 ) AS captured FROM %i WHERE version_id = %d AND parent_source_id IS NULL',
				self::CAPTURED,
				StarterImportMigrations::version_products_table(),
				$version_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return [ 'total' => (int) $row['total'], 'captured' => (int) $row['captured'] ];
	}

	/** @return array<string,mixed>|null|\WP_Error The next pending product, in selection order. */
	private static function next_pending( int $version_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE version_id = %d AND parent_source_id IS NULL AND capture_status = %s ORDER BY position ASC, version_product_id ASC LIMIT 1',
				StarterImportMigrations::version_products_table(),
				$version_id,
				self::PENDING
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return is_array( $row ) ? $row : null;
	}

	/** @return array{currency:string,prices_include_tax:string} The main site's store settings. */
	private static function main_settings(): array {
		$main = get_main_site_id();

		return [
			'currency'           => (string) get_blog_option( $main, 'woocommerce_currency', '' ),
			'prices_include_tax' => (string) get_blog_option( $main, 'woocommerce_prices_include_tax', 'no' ),
		];
	}

	private static function version_folder( int $version_id ): string {
		return StarterCatalogStorage::base_dir() . '/v' . $version_id;
	}

	/** Remove one frozen file, only when it lies inside this version's folder. */
	private function remove_frozen_file( int $version_id, string $relative ): void {
		$file = StarterCatalogStorage::frozen_path( $relative );
		if ( ! is_wp_error( $file ) && str_starts_with( $file, self::version_folder( $version_id ) . '/' ) && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/** Leftover temporary copies from interrupted requests. */
	private function remove_partial_files( int $version_id ): void {
		$folder = self::version_folder( $version_id );
		if ( ! is_dir( $folder ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isFile() && ! $item->isLink() && str_ends_with( $item->getFilename(), '.part' ) ) {
				wp_delete_file( $item->getPathname() );
			}
		}
	}

	/** Remove a version's own folder (`v<id>` under the frozen folder). */
	private static function remove_version_folder( int $version_id ): bool {
		$folder = self::version_folder( $version_id );
		if ( $version_id < 1 || ! is_dir( $folder ) ) {
			return true;
		}

		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
			}
		}
		@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.

		return ! is_dir( $folder );
	}

	private static function audit_published( int $version_id, string $created_at, int $product_count, int $image_count ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		$operation_id = self::operation_id( 'starter_catalog_version:' . $version_id . '|' . $created_at . '|published' );
		if ( is_array( AuditLog::find_operation_witness( $operation_id ) ) ) {
			return;
		}

		AuditLog::record(
			[
				'blog_id'        => null,
				'actor_user_id'  => get_current_user_id() > 0 ? (int) get_current_user_id() : null,
				'action'         => 'starter_catalog.version_published',
				'target_type'    => 'starter_catalog_version',
				'target_id'      => $version_id,
				'previous_value' => null,
				'new_value'      => null,
				'context'        => [
					'product_count' => $product_count,
					'image_count'   => $image_count,
				],
				'operation_id'   => $operation_id,
			]
		);
	}

	/** A deterministic UUID in version 4 layout. */
	private static function operation_id( string $discriminator ): string {
		$hex     = hash( 'sha256', $discriminator );
		$variant = dechex( 8 | ( hexdec( $hex[16] ) & 3 ) );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-' . $variant . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}

	private function checkpoint( string $step, array $context ): void {
		do_action( self::CHECKPOINT_ACTION, $step, $context );
	}

	/**
	 * @param array<int,string> $problems
	 * @param array<int,string> $warnings
	 * @return array<string,mixed>
	 */
	private static function outcome( string $status, ?int $version_id, array $problems = [], array $warnings = [], int $total = 0, int $captured = 0 ): array {
		return [
			'status'     => $status,
			'version_id' => $version_id,
			'total'      => $total,
			'captured'   => $captured,
			'problems'   => array_values( $problems ),
			'warnings'   => array_values( array_unique( $warnings ) ),
		];
	}

	private static function db_error(): \WP_Error {
		return self::error( 'starter_catalog_db_error', 'The starter catalog could not be saved or read. Please try again.' );
	}

	/** Fixed messages only: no SQL, table names or paths. */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ) );
	}
}
