<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 starter product import engine for one Hall.
 *
 * start() runs the Start checks and records the start marker and receipt.
 * process_next() copies products one at a time, saving progress at each step,
 * until its time budget is used. Both take the Hall import lock, so only one
 * runs per Hall. Neither is called by any page yet (stage 5 adds the screen).
 *
 * Simple and variable products (product-level attributes only) are copied,
 * with their images. A request stops at a safe point once its time budget is
 * used, after doing at least one unit of work (a product, a variation or an
 * image), and the next request continues from there.
 *
 * Crash safety (ADR-049 sections 10 and 12): an item's or image's recovery
 * identifier is saved before the post is created and is that post's first
 * slug, and the post ID is saved as soon as creation returns. "Creation
 * started but no ID saved" is never guessed at: it becomes Needs attention.
 */
final class StarterImporter {

	/**
	 * Fires after each durable step: ( string $step, array $context ).
	 * A test seam only; nothing in production listens to it.
	 */
	public const CHECKPOINT_ACTION = 'bingocommerce_starter_import_checkpoint';

	public const DEFAULT_BUDGET_SECONDS = 5.0;

	private const RECOVERY_PREFIX = 'bcsi-';

	/** Outcome meaning "stopped at a safe point; continue in the next request". */
	private const PAUSED = 'paused';

	private ?StarterImportLock $lock = null;
	private float $deadline          = 0.0;
	private bool $worked             = false;

	/* ------------------------------------------------------------------ *
	 * Public API
	 * ------------------------------------------------------------------ */

	/**
	 * Start the Hall's one-time import, or return the existing one.
	 *
	 * @return array<string,mixed>|\WP_Error Import state.
	 */
	public function start() {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$lock = StarterImportLock::acquire_hall( $context['blog_id'] );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$schema = StarterImportMigrations::ensure_current_hall();
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			$receipt = StarterImportRepository::receipt();
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}
			if ( is_array( $receipt ) ) {
				return $this->state_from( $receipt );
			}

			if ( '' !== (string) get_option( StarterImportRepository::START_MARKER_OPTION, '' ) ) {
				return self::error( 'starter_import_repair_needed', 'The starter import history is missing. Please contact Bingo Innovation support.' );
			}

			if ( ! StarterImportMigrations::is_network_current() ) {
				return self::error( 'starter_import_unavailable', 'Starter products are not available yet.' );
			}
			if ( ! StarterCatalogVersions::imports_allowed() ) {
				return self::error( 'starter_import_unavailable', 'Starter product imports are turned off right now.' );
			}

			$version = StarterCatalogVersions::latest_published();
			if ( is_wp_error( $version ) ) {
				return $version;
			}
			if ( null === $version ) {
				return self::error( 'starter_import_unavailable', 'Starter products are not available yet.' );
			}
			$version_id = (int) $version['version_id'];

			$settings = $this->check_store_settings( $version );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}
			$classes = $this->check_required_tax_classes( $version_id );
			if ( is_wp_error( $classes ) ) {
				return $classes;
			}
			$storage = $this->check_estimated_image_storage( $version_id );
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}

			$total = StarterCatalogVersions::count_top_level_products( $version_id );
			if ( is_wp_error( $total ) ) {
				return $total;
			}
			if ( $total < 1 ) {
				return self::error( 'starter_import_unavailable', 'Starter products are not available yet.' );
			}

			$import_uuid = wp_generate_uuid4();
			update_option( StarterImportRepository::START_MARKER_OPTION, $import_uuid );
			if ( $import_uuid !== (string) get_option( StarterImportRepository::START_MARKER_OPTION, '' ) ) {
				return self::error( 'starter_import_db_error', 'The starter import could not be started. Please try again.' );
			}
			$this->checkpoint( 'start_marker_saved', [ 'import_uuid' => $import_uuid ] );

			$receipt = StarterImportRepository::create_receipt( $import_uuid, $version_id, $total, $context['actor'] );
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}

			$this->audit( 'started', $receipt, [ 'version_id' => $version_id, 'total_products' => $total ] );

			return $this->state_from( $receipt );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Copy products until the time budget is used. At least one unit of work
	 * is done per call, so a budget of 0 does exactly one unit.
	 *
	 * @return array<string,mixed>|\WP_Error Import state, or the error that
	 *         stopped this request.
	 */
	public function process_next( float $budget_seconds = self::DEFAULT_BUDGET_SECONDS ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$lock = StarterImportLock::acquire_hall( $context['blog_id'] );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$this->lock     = $lock;
		$this->deadline = microtime( true ) + max( 0.0, $budget_seconds );
		$this->worked   = false;

		try {
			$schema = StarterImportMigrations::ensure_current_hall();
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}

			$receipt = StarterImportRepository::receipt();
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}
			if ( null === $receipt ) {
				return self::error( 'starter_import_not_started', 'The starter import has not started.' );
			}
			if ( in_array( $receipt['status'], [ StarterImportRepository::RECEIPT_COMPLETE, StarterImportRepository::RECEIPT_NEEDS_ATTENTION ], true ) ) {
				return $this->state_from( $receipt );
			}

			$version = StarterCatalogVersions::get( (int) $receipt['version_id'] );
			if ( is_wp_error( $version ) ) {
				return $version;
			}
			if ( null === $version || StarterCatalogVersions::STATUS_PUBLISHED !== $version['status'] ) {
				return $this->fail( $receipt, self::error( 'starter_import_version_missing', 'The starter catalog version for this import is missing. Please contact Bingo Innovation support.' ), null );
			}

			$settings = $this->check_store_settings( $version );
			if ( is_wp_error( $settings ) ) {
				return $this->fail( $receipt, $settings, null );
			}

			if ( StarterImportRepository::RECEIPT_IMPORTING !== $receipt['status'] ) {
				$resumed = StarterImportRepository::update_receipt(
					(int) $receipt['import_id'],
					[
						'status'             => StarterImportRepository::RECEIPT_IMPORTING,
						'last_error_code'    => null,
						'last_error_item_id' => null,
					]
				);
				if ( is_wp_error( $resumed ) ) {
					return $resumed;
				}
			}

			do {
				if ( ! $lock->is_still_held() ) {
					return self::lock_lost();
				}

				$next = StarterImportRepository::next_pending_top_level( (int) $receipt['import_id'], (int) $version['version_id'] );
				if ( is_wp_error( $next ) ) {
					return $this->fail( $receipt, $next, null );
				}
				if ( null === $next ) {
					return $this->complete( $receipt );
				}

				$outcome = $this->process_product( $next, $receipt, $version );
				if ( is_wp_error( $outcome ) ) {
					return $this->fail( $receipt, $outcome, $this->item_id_for( $receipt, $next ) );
				}
				if ( StarterImportRepository::ITEM_NEEDS_ATTENTION === $outcome ) {
					return $this->needs_attention( $receipt, $this->item_id_for( $receipt, $next ) );
				}

				$counted = $this->refresh_counts( $receipt );
				if ( is_wp_error( $counted ) ) {
					return $counted;
				}
				if ( self::PAUSED === $outcome ) {
					break;
				}
			} while ( microtime( true ) < $this->deadline );

			$current = StarterImportRepository::receipt();

			return is_array( $current ) ? $this->state_from( $current ) : self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
		} finally {
			$this->lock = null;
			$lock->release();
		}
	}

	/** @return array<string,mixed>|null|\WP_Error Current state, or null when never started. */
	public function state() {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( ! StarterImportMigrations::is_hall_current() ) {
			return null;
		}

		$receipt = StarterImportRepository::receipt();
		if ( is_wp_error( $receipt ) || null === $receipt ) {
			return $receipt;
		}

		return $this->state_from( $receipt );
	}

	/* ------------------------------------------------------------------ *
	 * One top-level product
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|\WP_Error complete, skipped, needs_attention or paused,
	 *         or the error that stopped the item.
	 */
	private function process_product( array $version_product, array $receipt, array $version ) {
		$data = StarterCatalogVersions::data( $version_product );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// Only for the slug fallback; never copied to the Hall's product.
		$data['source_product_id'] = (int) $version_product['source_product_id'];

		$type = (string) $version_product['product_type'];
		if ( ! in_array( $type, [ 'simple', 'variable' ], true ) ) {
			return self::error( 'starter_import_unsupported_item', 'This kind of starter product cannot be imported.' );
		}

		$import_id          = (int) $receipt['import_id'];
		$version_product_id = (int) $version_product['version_product_id'];

		$item = StarterImportRepository::item( $import_id, $version_product_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		if ( null === $item ) {
			$ready = $this->check_before_create( $data );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$item = StarterImportRepository::insert_item( $import_id, $version_product_id, null, $type, (int) $version_product['position'], self::recovery_slug() );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->checkpoint( 'item_claimed', [ 'item_id' => (int) $item['item_id'] ] );
		}

		switch ( (string) $item['status'] ) {
			case StarterImportRepository::ITEM_COMPLETE:
			case StarterImportRepository::ITEM_SKIPPED:
			case StarterImportRepository::ITEM_NEEDS_ATTENTION:
				return (string) $item['status'];

			case StarterImportRepository::ITEM_CLAIMED:
				// Creation never started, so creating now cannot duplicate anything.
				return $this->create( $item, $data, $receipt, $version, $version_product );

			case StarterImportRepository::ITEM_CREATING:
				if ( (int) $item['wc_product_id'] < 1 ) {
					// Creation started but its result was never recorded: never guess.
					$marked = StarterImportRepository::update_item( (int) $item['item_id'], [ 'status' => StarterImportRepository::ITEM_NEEDS_ATTENTION ] );

					return is_wp_error( $marked ) ? $marked : StarterImportRepository::ITEM_NEEDS_ATTENTION;
				}
				return $this->finish( $item, $data, $receipt, $version, $version_product );

			case StarterImportRepository::ITEM_CREATED:
				return $this->finish( $item, $data, $receipt, $version, $version_product );
		}

		return self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return string|\WP_Error */
	private function create( array $item, array $data, array $receipt, array $version, array $version_product ) {
		$ready = $this->check_before_create( $data );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$item_id  = (int) $item['item_id'];
		$starting = StarterImportRepository::update_item( $item_id, [ 'status' => StarterImportRepository::ITEM_CREATING ] );
		if ( is_wp_error( $starting ) ) {
			return $starting;
		}
		$this->checkpoint( 'creation_starting', [ 'item_id' => $item_id ] );

		try {
			$product = 'variable' === (string) $item['product_type'] ? new \WC_Product_Variable() : new \WC_Product_Simple();
			$product->set_name( (string) $data['name'] );
			$product->set_status( 'draft' );
			$product->set_slug( (string) $item['recovery_slug'] );
			$product_id = (int) $product->save();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$product_id = 0;
		}
		if ( $product_id < 1 ) {
			return self::error( 'starter_import_save_failed', 'A starter product could not be created. Please try again.' );
		}
		$this->checkpoint( 'product_created', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$saved = StarterImportRepository::update_item(
			$item_id,
			[
				'status'        => StarterImportRepository::ITEM_CREATED,
				'wc_product_id' => $product_id,
			]
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->checkpoint( 'product_id_saved', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$item['status']        = StarterImportRepository::ITEM_CREATED;
		$item['wc_product_id'] = $product_id;

		return $this->finish( $item, $data, $receipt, $version, $version_product );
	}

	/**
	 * Apply the remaining fields to the item's own product, copy its images
	 * and (for a variable product) its variations, verify, set its normal
	 * slug and complete it. Applies the Retry rules first.
	 *
	 * @return string|\WP_Error
	 */
	private function finish( array $item, array $data, array $receipt, array $version, array $version_product ) {
		$item_id    = (int) $item['item_id'];
		$product_id = (int) $item['wc_product_id'];
		$type       = (string) $item['product_type'];

		// A failed read is an error, never "removed": only a confirmed missing
		// row may skip the item.
		$post_state = $this->read_post_state( $product_id );
		if ( is_wp_error( $post_state ) ) {
			return $post_state;
		}
		if ( null === $post_state || 'product' !== $post_state['post_type'] || 'trash' === $post_state['post_status'] ) {
			return $this->skip( $item, __( 'Removed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
		}

		$linked = $this->catalog_membership( $product_id );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		if ( $linked || 'draft' !== $post_state['post_status'] ) {
			return $this->skip( $item, __( 'Changed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
		}

		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			// The row exists, so a missing object is a read failure, not a change.
			return self::error( 'starter_import_db_error', 'The starter import could not read this product. Please try again.' );
		}
		if ( ! $product->is_type( $type ) ) {
			return $this->skip( $item, __( 'Changed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
		}

		$ready = $this->check_before_create( $data );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$expected_text = $this->expected_text( $product_id, $data );

		$notes     = [];
		$decisions = $this->decisions( $item, $data, $version, $product_id, $notes );
		if ( is_wp_error( $decisions ) ) {
			return $decisions;
		}

		$images = $this->product_images( $receipt, $data, $notes );
		if ( ! is_array( $images ) ) {
			return $images;
		}
		$decisions['image_id']          = $images['image_id'];
		$decisions['gallery_image_ids'] = $images['gallery_image_ids'];

		$product->set_name( (string) $data['name'] );
		$product->set_description( (string) ( $data['description'] ?? '' ) );
		$product->set_short_description( (string) ( $data['short_description'] ?? '' ) );
		// Sale prices are not copied; a sale added to an unfinished draft is
		// replaced like any other edit (ADR-049 section 8 warning).
		$product->set_sale_price( '' );
		$product->set_date_on_sale_from( '' );
		$product->set_date_on_sale_to( '' );
		$product->set_status( 'draft' );
		$product->set_tax_status( (string) ( $data['tax_status'] ?? 'taxable' ) );
		$product->set_tax_class( (string) ( $data['tax_class'] ?? '' ) );
		$product->set_backorders( 'no' );
		$product->set_category_ids( $decisions['category_ids'] );
		$product->set_tag_ids( $decisions['tag_ids'] );
		$product->set_image_id( $decisions['image_id'] );
		$product->set_gallery_image_ids( $decisions['gallery_image_ids'] );
		// Product-level attributes are copied for simple and variable products
		// alike (ADR-049 section 7).
		$product->set_attributes( $this->build_attributes( $data ) );

		if ( 'variable' === $type ) {
			$product->set_default_attributes( self::string_map( $data['default_attributes'] ?? [] ) );
			if ( ! empty( $data['manage_stock'] ) ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( 0 );
			} else {
				$product->set_manage_stock( false );
			}
		} else {
			$product->set_regular_price( (string) ( $data['regular_price'] ?? '' ) );
			if ( ! empty( $data['manage_stock'] ) ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( 0 );
			} else {
				$product->set_manage_stock( false );
				$product->set_stock_status( 'instock' );
			}
		}

		$sku_note = $this->apply_sku( $product, $decisions );
		if ( null !== $sku_note ) {
			$notes[] = $sku_note;
		}

		try {
			$saved = (int) $product->save();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$saved = 0;
		}
		if ( $saved !== $product_id ) {
			return self::error( 'starter_import_save_failed', 'A starter product could not be saved. Please try again.' );
		}
		$this->checkpoint( 'fields_applied', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$recorded = StarterImportRepository::update_item(
			$item_id,
			[
				'decisions' => wp_json_encode( $decisions ),
				'note'      => self::join_notes( $notes ),
			]
		);
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}
		$this->checkpoint( 'decisions_saved', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$text = $this->verify_text( $product_id, $expected_text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$verified = $this->verify_fields( $product_id, $type, $data, $decisions );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$this->checkpoint( 'verified', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		if ( 'variable' === $type ) {
			$variations = $this->process_variations( $item, $version_product, $receipt, $version, $product_id );
			if ( ! is_array( $variations ) ) {
				return $variations;
			}
			$notes = array_merge( $notes, $variations );
			$this->checkpoint( 'variations_done', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

			\WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			$verified = $this->verify_fields( $product_id, $type, $data, $decisions );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$this->checkpoint( 'parent_synced', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );
		}

		$slug    = $this->normal_slug( $data );
		$renamed = wp_update_post( [ 'ID' => $product_id, 'post_name' => $slug ], true );
		if ( is_wp_error( $renamed ) || (int) $renamed !== $product_id ) {
			return self::error( 'starter_import_save_failed', 'A starter product could not be saved. Please try again.' );
		}
		clean_post_cache( $product_id );
		$renamed_post = get_post( $product_id );
		if ( ! $renamed_post instanceof \WP_Post || sanitize_title( $slug ) !== $renamed_post->post_name ) {
			return self::verify_error( 'slug' );
		}
		$text = $this->verify_text( $product_id, $expected_text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$this->checkpoint( 'slug_set', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$completed = StarterImportRepository::update_item(
			$item_id,
			[
				'status'       => StarterImportRepository::ITEM_COMPLETE,
				'note'         => self::join_notes( $notes ),
				'completed_at' => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		$this->worked = true;
		$this->checkpoint( 'item_completed', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		return StarterImportRepository::ITEM_COMPLETE;
	}

	/* ------------------------------------------------------------------ *
	 * Variations
	 * ------------------------------------------------------------------ */

	/**
	 * Copy every variation of one variable product, in display order.
	 *
	 * @return array<int,string>|string|\WP_Error Omission notes when every
	 *         variation is complete or skipped; otherwise paused,
	 *         needs_attention or the error that stopped it.
	 */
	private function process_variations( array $parent_item, array $parent_version_product, array $receipt, array $version, int $parent_product_id ) {
		$rows = StarterCatalogVersions::variations( (int) $version['version_id'], (int) $parent_version_product['source_product_id'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( [] === $rows ) {
			return self::error( 'starter_import_version_invalid', 'A starter product in this version has no variations.' );
		}

		$notes = [];
		foreach ( $rows as $row ) {
			if ( $this->should_pause() ) {
				return self::PAUSED;
			}
			if ( null === $this->lock || ! $this->lock->is_still_held() ) {
				return self::lock_lost();
			}

			$outcome = $this->process_variation( $row, $parent_item, $receipt, $parent_product_id );
			if ( is_wp_error( $outcome ) || in_array( $outcome, [ self::PAUSED, StarterImportRepository::ITEM_NEEDS_ATTENTION ], true ) ) {
				return $outcome;
			}
			if ( StarterImportRepository::ITEM_SKIPPED === $outcome ) {
				$skipped = StarterImportRepository::item( (int) $receipt['import_id'], (int) $row['version_product_id'] );
				if ( is_array( $skipped ) && '' !== (string) $skipped['note'] ) {
					$notes[] = (string) $skipped['note'];
				}
			}
		}

		return $notes;
	}

	/** @return string|\WP_Error */
	private function process_variation( array $row, array $parent_item, array $receipt, int $parent_product_id ) {
		$data = StarterCatalogVersions::data( $row );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$import_id = (int) $receipt['import_id'];
		$item      = StarterImportRepository::item( $import_id, (int) $row['version_product_id'] );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		if ( null === $item ) {
			$ready = $this->check_tax_class( (string) ( $data['tax_class'] ?? '' ) );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$item = StarterImportRepository::insert_item( $import_id, (int) $row['version_product_id'], (int) $parent_item['item_id'], 'variation', (int) $row['position'], self::recovery_slug() );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$this->checkpoint( 'variation_claimed', [ 'item_id' => (int) $item['item_id'] ] );
		}

		switch ( (string) $item['status'] ) {
			case StarterImportRepository::ITEM_COMPLETE:
			case StarterImportRepository::ITEM_SKIPPED:
			case StarterImportRepository::ITEM_NEEDS_ATTENTION:
				return (string) $item['status'];

			case StarterImportRepository::ITEM_CLAIMED:
				return $this->create_variation( $item, $data, $row, $receipt, $parent_product_id );

			case StarterImportRepository::ITEM_CREATING:
				if ( (int) $item['wc_product_id'] < 1 ) {
					$marked = StarterImportRepository::update_item( (int) $item['item_id'], [ 'status' => StarterImportRepository::ITEM_NEEDS_ATTENTION ] );

					return is_wp_error( $marked ) ? $marked : StarterImportRepository::ITEM_NEEDS_ATTENTION;
				}
				return $this->finish_variation( $item, $data, $row, $receipt, $parent_product_id );

			case StarterImportRepository::ITEM_CREATED:
				return $this->finish_variation( $item, $data, $row, $receipt, $parent_product_id );
		}

		return self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return string|\WP_Error */
	private function create_variation( array $item, array $data, array $row, array $receipt, int $parent_product_id ) {
		$ready = $this->check_tax_class( (string) ( $data['tax_class'] ?? '' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$item_id  = (int) $item['item_id'];
		$starting = StarterImportRepository::update_item( $item_id, [ 'status' => StarterImportRepository::ITEM_CREATING ] );
		if ( is_wp_error( $starting ) ) {
			return $starting;
		}
		$this->checkpoint( 'variation_creation_starting', [ 'item_id' => $item_id ] );

		try {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_product_id );
			$variation->set_status( empty( $data['enabled'] ) ? 'private' : 'publish' );
			$variation->set_slug( (string) $item['recovery_slug'] );
			$variation_id = (int) $variation->save();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$variation_id = 0;
		}
		if ( $variation_id < 1 ) {
			return self::error( 'starter_import_save_failed', 'A starter product variation could not be created. Please try again.' );
		}
		$this->checkpoint( 'variation_created', [ 'item_id' => $item_id, 'wc_product_id' => $variation_id ] );

		$saved = StarterImportRepository::update_item(
			$item_id,
			[
				'status'        => StarterImportRepository::ITEM_CREATED,
				'wc_product_id' => $variation_id,
			]
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->checkpoint( 'variation_id_saved', [ 'item_id' => $item_id, 'wc_product_id' => $variation_id ] );

		$item['status']        = StarterImportRepository::ITEM_CREATED;
		$item['wc_product_id'] = $variation_id;

		return $this->finish_variation( $item, $data, $row, $receipt, $parent_product_id );
	}

	/** @return string|\WP_Error */
	private function finish_variation( array $item, array $data, array $row, array $receipt, int $parent_product_id ) {
		$item_id      = (int) $item['item_id'];
		$variation_id = (int) $item['wc_product_id'];

		$state = $this->read_post_state( $variation_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( null === $state || 'product_variation' !== $state['post_type'] || 'trash' === $state['post_status'] || $parent_product_id !== $state['post_parent'] ) {
			/* translators: %s: variation attributes, for example "Blue". */
			$note = sprintf( __( 'Variation %s was removed or moved in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ), self::variation_label( $data ) );

			return $this->skip( $item, $note );
		}

		clean_post_cache( $variation_id );
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return self::error( 'starter_import_db_error', 'The starter import could not read this product variation. Please try again.' );
		}

		$ready = $this->check_tax_class( (string) ( $data['tax_class'] ?? '' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$notes = [];
		$saved = json_decode( (string) ( $item['decisions'] ?? '' ), true );
		if ( is_array( $saved ) && array_key_exists( 'sku', $saved ) ) {
			$sku = (string) $saved['sku'];
			if ( '' !== (string) ( $item['note'] ?? '' ) ) {
				$notes = explode( "\n", (string) $item['note'] );
			}
		} else {
			$sku = (string) ( $data['sku'] ?? '' );
			if ( '' !== $sku && ! wc_product_has_unique_sku( $variation_id, $sku ) ) {
				$notes[] = $this->sku_note( $sku );
				$sku     = '';
			}
		}

		$image_id = 0;
		if ( (int) ( $data['image_id'] ?? 0 ) > 0 ) {
			$image = $this->ensure_image( $receipt, (int) $data['image_id'] );
			if ( is_wp_error( $image ) || in_array( $image, [ self::PAUSED, StarterImportRepository::ITEM_NEEDS_ATTENTION ], true ) ) {
				return $image;
			}
			if ( StarterImportRepository::IMAGE_OMITTED === $image ) {
				$notes[] = $this->image_note( (int) $data['image_id'] );
			} else {
				$image_id = (int) $image;
			}
		}

		$decisions = [ 'sku' => $sku, 'image_id' => $image_id ];
		$tracked   = ! empty( $data['manage_stock'] );

		$variation->set_attributes( self::string_map( $data['attributes'] ?? [] ) );
		$variation->set_menu_order( (int) $row['position'] );
		$variation->set_status( empty( $data['enabled'] ) ? 'private' : 'publish' );
		$variation->set_regular_price( (string) ( $data['regular_price'] ?? '' ) );
		$variation->set_sale_price( '' );
		$variation->set_date_on_sale_from( '' );
		$variation->set_date_on_sale_to( '' );
		$variation->set_tax_class( (string) ( $data['tax_class'] ?? 'parent' ) );
		$variation->set_description( (string) ( $data['description'] ?? '' ) );
		$variation->set_image_id( $image_id );
		$variation->set_backorders( 'no' );
		if ( $tracked ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 0 );
		} else {
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
		}
		$sku_note = $this->apply_sku( $variation, $decisions );
		if ( null !== $sku_note ) {
			$notes[] = $sku_note;
		}

		try {
			$result = (int) $variation->save();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$result = 0;
		}
		if ( $result !== $variation_id ) {
			return self::error( 'starter_import_save_failed', 'A starter product variation could not be saved. Please try again.' );
		}
		$this->checkpoint( 'variation_fields_applied', [ 'item_id' => $item_id, 'wc_product_id' => $variation_id ] );

		$recorded = StarterImportRepository::update_item(
			$item_id,
			[
				'decisions' => wp_json_encode( $decisions ),
				'note'      => self::join_notes( $notes ),
			]
		);
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		$verified = $this->verify_variation( $variation_id, $parent_product_id, (int) $row['position'], $data, $decisions );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$completed = StarterImportRepository::update_item(
			$item_id,
			[
				'status'       => StarterImportRepository::ITEM_COMPLETE,
				'completed_at' => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		$this->worked = true;
		$this->checkpoint( 'variation_completed', [ 'item_id' => $item_id, 'wc_product_id' => $variation_id ] );

		return StarterImportRepository::ITEM_COMPLETE;
	}

	/**
	 * Build the product-level (custom) attributes. Global attributes are
	 * refused at Publish, so every attribute here is product-level.
	 *
	 * @return array<int,\WC_Product_Attribute>
	 */
	private function build_attributes( array $data ): array {
		$attributes = [];
		foreach ( (array) ( $data['attributes'] ?? [] ) as $raw ) {
			if ( ! is_array( $raw ) || '' === (string) ( $raw['name'] ?? '' ) ) {
				continue;
			}
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( (string) $raw['name'] );
			$attribute->set_options( array_map( 'strval', (array) ( $raw['options'] ?? [] ) ) );
			$attribute->set_position( (int) ( $raw['position'] ?? 0 ) );
			$attribute->set_visible( ! empty( $raw['visible'] ) );
			$attribute->set_variation( ! empty( $raw['variation'] ) );
			$attributes[] = $attribute;
		}

		return $attributes;
	}

	/* ------------------------------------------------------------------ *
	 * Images
	 * ------------------------------------------------------------------ */

	/**
	 * Copy a product's featured and gallery images (each once per import).
	 *
	 * @param array<int,string> $notes
	 * @return array{image_id:int,gallery_image_ids:array<int,int>}|string|\WP_Error
	 */
	private function product_images( array $receipt, array $data, array &$notes ) {
		$featured = (int) ( $data['image_id'] ?? 0 );
		$gallery  = array_values( array_filter( array_map( 'intval', (array) ( $data['gallery_image_ids'] ?? [] ) ) ) );

		$resolved = [];
		foreach ( array_unique( array_filter( array_merge( [ $featured ], $gallery ) ) ) as $version_image_id ) {
			$image = $this->ensure_image( $receipt, $version_image_id );
			if ( is_wp_error( $image ) || in_array( $image, [ self::PAUSED, StarterImportRepository::ITEM_NEEDS_ATTENTION ], true ) ) {
				return $image;
			}
			if ( StarterImportRepository::IMAGE_OMITTED === $image ) {
				$notes[] = $this->image_note( $version_image_id );
				continue;
			}
			$resolved[ $version_image_id ] = (int) $image;
		}

		$gallery_ids = [];
		foreach ( $gallery as $version_image_id ) {
			if ( isset( $resolved[ $version_image_id ] ) ) {
				$gallery_ids[] = $resolved[ $version_image_id ];
			}
		}

		return [
			'image_id'          => $featured > 0 && isset( $resolved[ $featured ] ) ? $resolved[ $featured ] : 0,
			'gallery_image_ids' => array_values( array_unique( $gallery_ids ) ),
		];
	}

	/**
	 * Make sure one version image exists in this Hall, following ADR-049
	 * section 12. Each step is saved before the next.
	 *
	 * @return int|string|\WP_Error Attachment ID, "omitted", "paused",
	 *         "needs_attention", or the error that stopped it.
	 */
	private function ensure_image( array $receipt, int $version_image_id ) {
		$import_id = (int) $receipt['import_id'];
		$row       = StarterImportRepository::image( $import_id, $version_image_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( null === $row ) {
			if ( $this->should_pause() ) {
				return self::PAUSED;
			}
			$row = $this->plan_image( $import_id, $version_image_id );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
		}

		$status = (string) $row['status'];
		if ( StarterImportRepository::IMAGE_COMPLETE === $status ) {
			// Completed media is reused without rewriting; media the Hall
			// deleted afterwards is left out with a note.
			$attachment_id = (int) $row['attachment_id'];
			$state         = $this->read_post_state( $attachment_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( null !== $state && 'attachment' === $state['post_type'] && 'trash' !== $state['post_status'] ) {
				return $attachment_id;
			}
			$omitted = StarterImportRepository::update_image( (int) $row['import_image_id'], [ 'status' => StarterImportRepository::IMAGE_OMITTED, 'note' => $this->image_note( $version_image_id ) ] );

			return is_wp_error( $omitted ) ? $omitted : StarterImportRepository::IMAGE_OMITTED;
		}
		if ( in_array( $status, [ StarterImportRepository::IMAGE_OMITTED, StarterImportRepository::IMAGE_NEEDS_ATTENTION ], true ) ) {
			return StarterImportRepository::IMAGE_OMITTED === $status ? StarterImportRepository::IMAGE_OMITTED : StarterImportRepository::ITEM_NEEDS_ATTENTION;
		}

		$version_image = $this->checked_version_image( $version_image_id );
		if ( is_wp_error( $version_image ) ) {
			return $version_image;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return self::error( 'starter_import_image_failed', 'The store upload folder is not available.' );
		}
		$absolute = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) . '/' . (string) $row['planned_path'] );

		if ( StarterImportRepository::IMAGE_PLANNED === $status ) {
			// Space is checked right before every write, including a resumed
			// one: the Hall may have used up its space since this was planned.
			$space = StarterCatalogStorage::check_space( (int) $version_image['file_size'], (string) $uploads['basedir'] );
			if ( is_wp_error( $space ) ) {
				return $space;
			}

			// The planned name carries a unique recovery token, so any file
			// already there is this import's own unfinished copy.
			$written = $this->write_image_file( $version_image, $absolute );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$saved = StarterImportRepository::update_image( (int) $row['import_image_id'], [ 'status' => StarterImportRepository::IMAGE_FILE_WRITTEN ] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$this->checkpoint( 'image_file_written', [ 'import_image_id' => (int) $row['import_image_id'] ] );
			$status = StarterImportRepository::IMAGE_FILE_WRITTEN;
		}

		if ( StarterImportRepository::IMAGE_FILE_WRITTEN === $status ) {
			$starting = StarterImportRepository::update_image( (int) $row['import_image_id'], [ 'status' => StarterImportRepository::IMAGE_CREATING ] );
			if ( is_wp_error( $starting ) ) {
				return $starting;
			}
			$this->checkpoint( 'image_creation_starting', [ 'import_image_id' => (int) $row['import_image_id'] ] );

			$attachment_id = wp_insert_attachment(
				[
					'post_mime_type' => (string) $version_image['mime_type'],
					'post_title'     => sanitize_text_field( pathinfo( (string) $version_image['file_path'], PATHINFO_FILENAME ) ),
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_name'      => (string) $row['recovery_slug'],
				],
				$absolute,
				0,
				true
			);
			if ( is_wp_error( $attachment_id ) || (int) $attachment_id < 1 ) {
				return self::error( 'starter_import_image_failed', 'A starter image could not be added to this store. Please try again.' );
			}
			$attachment_id = (int) $attachment_id;
			$this->checkpoint( 'image_created', [ 'import_image_id' => (int) $row['import_image_id'], 'attachment_id' => $attachment_id ] );

			$saved = StarterImportRepository::update_image(
				(int) $row['import_image_id'],
				[
					'status'        => StarterImportRepository::IMAGE_CREATED,
					'attachment_id' => $attachment_id,
				]
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$this->checkpoint( 'image_id_saved', [ 'import_image_id' => (int) $row['import_image_id'], 'attachment_id' => $attachment_id ] );
			$row['attachment_id'] = $attachment_id;
			$status               = StarterImportRepository::IMAGE_CREATED;
		}

		if ( StarterImportRepository::IMAGE_CREATING === $status ) {
			// Creation started but its result was never recorded: never guess.
			$marked = StarterImportRepository::update_image( (int) $row['import_image_id'], [ 'status' => StarterImportRepository::IMAGE_NEEDS_ATTENTION ] );

			return is_wp_error( $marked ) ? $marked : StarterImportRepository::ITEM_NEEDS_ATTENTION;
		}

		if ( StarterImportRepository::IMAGE_CREATED === $status ) {
			return $this->finish_image( $row, $version_image, $absolute );
		}

		return self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return array<string,mixed>|\WP_Error The new planned image row. */
	private function plan_image( int $import_id, int $version_image_id ) {
		$version_image = $this->checked_version_image( $version_image_id );
		if ( is_wp_error( $version_image ) ) {
			return $version_image;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return self::error( 'starter_import_image_failed', 'The store upload folder is not available.' );
		}

		$token     = bin2hex( random_bytes( 8 ) );
		$name      = sanitize_file_name( pathinfo( (string) $version_image['file_path'], PATHINFO_FILENAME ) );
		$extension = strtolower( pathinfo( (string) $version_image['file_path'], PATHINFO_EXTENSION ) );
		$name      = '' !== $name ? $name : 'starter-image';
		$relative  = ltrim( (string) $uploads['subdir'], '/' );
		$relative  = ( '' !== $relative ? $relative . '/' : '' ) . $name . '-' . $token . '.' . $extension;

		$row = StarterImportRepository::insert_image( $import_id, $version_image_id, $relative, self::RECOVERY_PREFIX . $token );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->checkpoint( 'image_planned', [ 'import_image_id' => (int) $row['import_image_id'] ] );

		return $row;
	}

	/**
	 * The version image, checked: allowed type, size and dimensions, and a
	 * frozen file whose checksum still matches.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function checked_version_image( int $version_image_id ) {
		$version_image = StarterCatalogVersions::image( $version_image_id );
		if ( is_wp_error( $version_image ) ) {
			return $version_image;
		}
		if ( null === $version_image ) {
			return self::error( 'starter_import_image_missing', 'A starter image could not be found. Please contact Bingo Innovation support.' );
		}

		if ( ! in_array( (string) $version_image['mime_type'], StarterCatalogStorage::ALLOWED_MIME_TYPES, true )
			|| ! in_array( strtolower( pathinfo( (string) $version_image['file_path'], PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true ) ) {
			return self::error( 'starter_import_image_unsupported', 'A starter image has a file type this store does not accept.' );
		}
		if ( (int) $version_image['file_size'] < 1 || (int) $version_image['file_size'] > StarterCatalogStorage::MAX_FILE_BYTES
			|| (int) $version_image['width'] > StarterCatalogStorage::MAX_DIMENSION || (int) $version_image['height'] > StarterCatalogStorage::MAX_DIMENSION ) {
			return self::error( 'starter_import_image_unsupported', 'A starter image is larger than this store accepts.' );
		}

		$frozen = StarterCatalogStorage::frozen_path( (string) $version_image['file_path'] );
		if ( is_wp_error( $frozen ) ) {
			return $frozen;
		}
		if ( ! is_file( $frozen ) || ! hash_equals( (string) $version_image['checksum'], (string) hash_file( 'sha256', $frozen ) ) ) {
			return self::error( 'starter_import_image_missing', 'A starter image could not be found. Please contact Bingo Innovation support.' );
		}
		$version_image['frozen_path'] = $frozen;

		return $version_image;
	}

	/** @return true|\WP_Error */
	private function write_image_file( array $version_image, string $absolute ) {
		if ( ! wp_mkdir_p( dirname( $absolute ) ) ) {
			return self::error( 'starter_import_image_failed', 'A starter image could not be saved in this store. Please try again.' );
		}
		if ( ! @copy( (string) $version_image['frozen_path'], $absolute ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
			return self::error( 'starter_import_image_failed', 'A starter image could not be saved in this store. Please try again.' );
		}

		// Same permissions WordPress gives an uploaded file.
		$stat = stat( dirname( $absolute ) );
		if ( is_array( $stat ) ) {
			chmod( $absolute, $stat['mode'] & 0000666 );
		}

		if ( ! hash_equals( (string) $version_image['checksum'], (string) hash_file( 'sha256', $absolute ) ) ) {
			return self::error( 'starter_import_image_failed', 'A starter image was not saved correctly. Please try again.' );
		}

		return true;
	}

	/**
	 * Alt text, image sizes (resumable), storage cache, then verify.
	 *
	 * @return int|\WP_Error The attachment ID.
	 */
	private function finish_image( array $row, array $version_image, string $absolute ) {
		$attachment_id = (int) $row['attachment_id'];

		$state = $this->read_post_state( $attachment_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( null === $state || 'attachment' !== $state['post_type'] ) {
			$marked = StarterImportRepository::update_image( (int) $row['import_image_id'], [ 'status' => StarterImportRepository::IMAGE_NEEDS_ATTENTION ] );

			return is_wp_error( $marked ) ? $marked : StarterImportRepository::ITEM_NEEDS_ATTENTION;
		}

		$alt = (string) ( $version_image['alt_text'] ?? '' );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt ) ) );
		} else {
			delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
		}

		// Without an image editor for this type WordPress makes no sizes. Stop
		// before it saves size-less metadata, so Retry after the server is
		// fixed runs WordPress's normal first processing for this attachment.
		if ( ! wp_image_editor_supports( [ 'mime_type' => (string) $version_image['mime_type'] ] ) ) {
			return self::error( 'starter_import_image_support_missing', 'This store\'s server cannot process this type of image. Please ask Bingo Innovation support to fix the server\'s image support, then select Retry.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_update_image_subsizes( $attachment_id );
		if ( is_wp_error( $meta ) || ! is_array( $meta ) ) {
			return self::error( 'starter_import_image_failed', 'A starter image could not be processed. Please try again.' );
		}
		if ( function_exists( 'clean_dirsize_cache' ) ) {
			clean_dirsize_cache( $absolute );
		}

		clean_post_cache( $attachment_id );
		// WordPress returns metadata even when its image editor could not make
		// a size (image.php:471–495), so check what it saved. The image stays
		// "created"; Retry makes only the missing sizes for this attachment.
		if ( ! $this->image_sizes_ready( $attachment_id ) ) {
			return self::error( 'starter_import_image_failed', 'A starter image could not be processed. Please try again.' );
		}

		$original = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $original ) || wp_normalize_path( $original ) !== $absolute || ! is_file( $absolute )
			|| ! hash_equals( (string) $version_image['checksum'], (string) hash_file( 'sha256', $absolute ) ) ) {
			return self::error( 'starter_import_image_failed', 'A starter image could not be verified. Please try again.' );
		}

		$completed = StarterImportRepository::update_image(
			(int) $row['import_image_id'],
			[
				'status'       => StarterImportRepository::IMAGE_COMPLETE,
				'completed_at' => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		$this->worked = true;
		$this->checkpoint( 'image_completed', [ 'import_image_id' => (int) $row['import_image_id'], 'attachment_id' => $attachment_id ] );

		return $attachment_id;
	}

	/**
	 * WordPress saved usable metadata for the attachment and made every
	 * registered size that fits the image.
	 */
	private function image_sizes_ready( int $attachment_id ): bool {
		$meta = wp_get_attachment_metadata( $attachment_id, true );
		if ( ! is_array( $meta ) || (int) ( $meta['width'] ?? 0 ) < 1 || (int) ( $meta['height'] ?? 0 ) < 1 || ! is_array( $meta['sizes'] ?? null )
			|| '' === (string) ( $meta['file'] ?? '' ) || (string) $meta['file'] !== (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) {
			return false;
		}

		return [] === wp_get_missing_image_subsizes( $attachment_id );
	}

	/* ------------------------------------------------------------------ *
	 * Decisions
	 * ------------------------------------------------------------------ */

	/**
	 * The SKU, category and tag choices for this Hall. Saved decisions from an
	 * earlier attempt are reused so a retry makes the same choices.
	 *
	 * @param array<int,string> $notes
	 * @return array<string,mixed>|\WP_Error
	 */
	private function decisions( array $item, array $data, array $version, int $product_id, array &$notes ) {
		$saved = json_decode( (string) ( $item['decisions'] ?? '' ), true );
		if ( is_array( $saved ) && isset( $saved['sku'], $saved['category_ids'], $saved['tag_ids'] ) ) {
			if ( '' !== (string) ( $item['note'] ?? '' ) ) {
				$notes = array_merge( $notes, explode( "\n", (string) $item['note'] ) );
			}

			return [
				'sku'          => (string) $saved['sku'],
				'category_ids' => array_map( 'intval', (array) $saved['category_ids'] ),
				'tag_ids'      => array_map( 'intval', (array) $saved['tag_ids'] ),
			];
		}

		$sku = (string) ( $data['sku'] ?? '' );
		if ( '' !== $sku && ! wc_product_has_unique_sku( $product_id, $sku ) ) {
			$notes[] = $this->sku_note( $sku );
			$sku     = '';
		}

		$terms = StarterCatalogVersions::terms( $version );

		$category_ids = [];
		foreach ( (array) ( $data['categories'] ?? [] ) as $slug ) {
			$term_id = $this->resolve_category( (string) $slug, $terms['product_cat'], $notes, 0 );
			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
			$category_ids[] = $term_id;
		}

		$tag_ids = [];
		foreach ( (array) ( $data['tags'] ?? [] ) as $slug ) {
			$term_id = $this->resolve_tag( (string) $slug, $terms['product_tag'] );
			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
			$tag_ids[] = $term_id;
		}

		return [
			'sku'          => $sku,
			'category_ids' => array_values( array_unique( $category_ids ) ),
			'tag_ids'      => array_values( array_unique( $tag_ids ) ),
		];
	}

	/**
	 * Apply the decided SKU; if another product took it meanwhile, leave it
	 * blank and return a note.
	 */
	private function apply_sku( \WC_Product $product, array &$decisions ): ?string {
		try {
			$product->set_sku( (string) $decisions['sku'] );

			return null;
		} catch ( \WC_Data_Exception $exception ) {
			unset( $exception );
			$note             = $this->sku_note( (string) $decisions['sku'] );
			$decisions['sku'] = '';
			$product->set_sku( '' );

			return $note;
		}
	}

	/**
	 * Reuse a Hall category with the same slug without changing it; otherwise
	 * create it under its version parent.
	 *
	 * @param array<int,array<string,string>> $tree
	 * @param array<int,string>               $notes
	 * @return int|\WP_Error
	 */
	private function resolve_category( string $slug, array $tree, array &$notes, int $depth ) {
		if ( '' === $slug || $depth > 20 ) {
			return self::error( 'starter_import_version_invalid', 'A starter product category could not be read.' );
		}

		$entry = [ 'name' => $slug, 'parent' => '' ];
		foreach ( $tree as $candidate ) {
			if ( is_array( $candidate ) && $slug === (string) ( $candidate['slug'] ?? '' ) ) {
				$entry = [ 'name' => (string) ( $candidate['name'] ?? $slug ), 'parent' => (string) ( $candidate['parent'] ?? '' ) ];
				break;
			}
		}

		$existing = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $existing instanceof \WP_Term ) {
			$parent      = (int) $existing->parent > 0 ? get_term( (int) $existing->parent, 'product_cat' ) : null;
			$parent_slug = $parent instanceof \WP_Term ? $parent->slug : '';
			if ( $parent_slug !== $entry['parent'] ) {
				/* translators: %s: category name. */
				$notes[] = sprintf( __( 'Category "%s" already exists in this store under a different parent; your existing category was kept.', 'bingocommerce-catalog' ), $existing->name );
			}

			return (int) $existing->term_id;
		}

		$parent_id = 0;
		if ( '' !== $entry['parent'] ) {
			$parent_id = $this->resolve_category( $entry['parent'], $tree, $notes, $depth + 1 );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
		}

		return $this->insert_term( $entry['name'], 'product_cat', $slug, $parent_id );
	}

	/**
	 * @param array<int,array<string,string>> $tags
	 * @return int|\WP_Error
	 */
	private function resolve_tag( string $slug, array $tags ) {
		if ( '' === $slug ) {
			return self::error( 'starter_import_version_invalid', 'A starter product tag could not be read.' );
		}

		$existing = get_term_by( 'slug', $slug, 'product_tag' );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}

		$name = $slug;
		foreach ( $tags as $candidate ) {
			if ( is_array( $candidate ) && $slug === (string) ( $candidate['slug'] ?? '' ) ) {
				$name = (string) ( $candidate['name'] ?? $slug );
				break;
			}
		}

		return $this->insert_term( $name, 'product_tag', $slug, 0 );
	}

	/** @return int|\WP_Error */
	private function insert_term( string $name, string $taxonomy, string $slug, int $parent_id ) {
		$created = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug, 'parent' => $parent_id ] );
		if ( is_wp_error( $created ) ) {
			$existing = (int) $created->get_error_data( 'term_exists' );
			if ( 'term_exists' === $created->get_error_code() && $existing > 0 ) {
				return $existing;
			}

			return self::error( 'starter_import_term_failed', 'A category or tag could not be created. Please try again.' );
		}

		return (int) $created['term_id'];
	}

	/* ------------------------------------------------------------------ *
	 * Verification
	 * ------------------------------------------------------------------ */

	/**
	 * Each text field's expected stored value, computed with WordPress's own
	 * save sanitization for the current user (ADR-049 section 11).
	 *
	 * @return array<string,string>
	 */
	private function expected_text( int $product_id, array $data ): array {
		$fields = [
			'post_title'   => (string) ( $data['name'] ?? '' ),
			'post_content' => (string) ( $data['description'] ?? '' ),
			'post_excerpt' => (string) ( $data['short_description'] ?? '' ),
		];

		$expected = [];
		foreach ( $fields as $field => $value ) {
			$expected[ $field ] = (string) wp_unslash( sanitize_post_field( $field, $value, $product_id, 'db' ) );
		}

		return $expected;
	}

	/**
	 * @param array<string,string> $expected
	 * @return true|\WP_Error
	 */
	private function verify_text( int $product_id, array $expected ) {
		clean_post_cache( $product_id );
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::verify_error( 'post' );
		}

		foreach ( $expected as $field => $value ) {
			if ( $value !== (string) $post->$field ) {
				return self::error( 'starter_import_text_mismatch', 'Saved text differs from what WordPress should store.' );
			}
		}

		return true;
	}

	/**
	 * Structured fields compared exactly with the saved import decisions.
	 *
	 * @return true|\WP_Error
	 */
	private function verify_fields( int $product_id, string $type, array $data, array $decisions ) {
		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return self::verify_error( 'product' );
		}

		$tracked  = ! empty( $data['manage_stock'] );
		$quantity = $product->get_stock_quantity( 'edit' );

		$expected_categories = $decisions['category_ids'];
		if ( [] === $expected_categories && (int) get_option( 'default_product_cat', 0 ) > 0 ) {
			$expected_categories = [ (int) get_option( 'default_product_cat', 0 ) ];
		}

		$checks = [
			'status'       => 'draft' === $product->get_status( 'edit' ),
			'type'         => $product->is_type( $type ),
			'sale_price'   => '' === (string) $product->get_sale_price( 'edit' ),
			'sale_dates'   => null === $product->get_date_on_sale_from( 'edit' ) && null === $product->get_date_on_sale_to( 'edit' ),
			'sku'          => $decisions['sku'] === (string) $product->get_sku( 'edit' ),
			'manage_stock' => $tracked === (bool) $product->get_manage_stock( 'edit' ),
			'backorders'   => 'no' === $product->get_backorders( 'edit' ),
			'tax_status'   => (string) ( $data['tax_status'] ?? 'taxable' ) === (string) $product->get_tax_status( 'edit' ),
			'tax_class'    => (string) ( $data['tax_class'] ?? '' ) === (string) $product->get_tax_class( 'edit' ),
			'categories'   => self::sorted( $expected_categories ) === self::sorted( $product->get_category_ids( 'edit' ) ),
			'tags'         => self::sorted( $decisions['tag_ids'] ) === self::sorted( $product->get_tag_ids( 'edit' ) ),
			'image'        => (int) $decisions['image_id'] === (int) $product->get_image_id( 'edit' ),
			'gallery'      => array_map( 'intval', $decisions['gallery_image_ids'] ) === array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ),
			'attributes'   => $this->attributes_match( $product, $data ),
		];

		if ( 'variable' === $type ) {
			$checks['stock']    = ! $tracked || ( null !== $quantity && 0 === (int) $quantity );
			$checks['defaults'] = self::sorted_map( self::string_map( $data['default_attributes'] ?? [] ) ) === self::sorted_map( self::string_map( $product->get_default_attributes( 'edit' ) ) );
		} else {
			$checks['regular_price'] = (string) ( $data['regular_price'] ?? '' ) === (string) $product->get_regular_price( 'edit' );
			$checks['stock']         = $tracked
				? ( null !== $quantity && 0 === (int) $quantity && 'outofstock' === $product->get_stock_status( 'edit' ) )
				: ( null === $quantity && 'instock' === $product->get_stock_status( 'edit' ) );
		}

		foreach ( $checks as $field => $passed ) {
			if ( ! $passed ) {
				return self::verify_error( $field );
			}
		}

		$linked = $this->catalog_membership( $product_id );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		if ( $linked ) {
			return self::verify_error( 'catalog_membership' );
		}

		return true;
	}

	private function attributes_match( \WC_Product $product, array $data ): bool {
		$expected = [];
		foreach ( $this->build_attributes( $data ) as $attribute ) {
			$expected[ sanitize_title( $attribute->get_name() ) ] = [ $attribute->get_name(), $attribute->get_options(), $attribute->get_position(), $attribute->get_visible(), $attribute->get_variation(), false ];
		}

		$actual = [];
		foreach ( $product->get_attributes( 'edit' ) as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				return false;
			}
			$actual[ (string) $key ] = [ $attribute->get_name(), $attribute->get_options(), (int) $attribute->get_position(), (bool) $attribute->get_visible(), (bool) $attribute->get_variation(), $attribute->is_taxonomy() ];
		}

		ksort( $expected );
		ksort( $actual );

		return $expected === $actual;
	}

	/**
	 * A variation's text and structured fields. Its title is generated by
	 * WooCommerce, so it is only required to be non-empty (ADR-049 s. 11).
	 *
	 * @return true|\WP_Error
	 */
	private function verify_variation( int $variation_id, int $parent_product_id, int $position, array $data, array $decisions ) {
		clean_post_cache( $variation_id );
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return self::verify_error( 'variation' );
		}

		$expected_description = (string) sanitize_meta( '_variation_description', wp_unslash( (string) ( $data['description'] ?? '' ) ), 'post', 'product_variation' );
		if ( $expected_description !== (string) get_post_meta( $variation_id, '_variation_description', true ) ) {
			return self::error( 'starter_import_text_mismatch', 'Saved text differs from what WordPress should store.' );
		}

		$tracked  = ! empty( $data['manage_stock'] );
		$quantity = $variation->get_stock_quantity( 'edit' );

		$checks = [
			'title'         => '' !== trim( (string) get_post_field( 'post_title', $variation_id ) ),
			'parent'        => $parent_product_id === (int) $variation->get_parent_id( 'edit' ),
			'menu_order'    => $position === (int) $variation->get_menu_order( 'edit' ),
			'attributes'    => self::sorted_map( self::string_map( $data['attributes'] ?? [] ) ) === self::sorted_map( self::string_map( $variation->get_attributes( 'edit' ) ) ),
			'status'        => ( empty( $data['enabled'] ) ? 'private' : 'publish' ) === $variation->get_status( 'edit' ),
			'regular_price' => (string) ( $data['regular_price'] ?? '' ) === (string) $variation->get_regular_price( 'edit' ),
			'sale_price'    => '' === (string) $variation->get_sale_price( 'edit' ),
			'sale_dates'    => null === $variation->get_date_on_sale_from( 'edit' ) && null === $variation->get_date_on_sale_to( 'edit' ),
			'sku'           => (string) $decisions['sku'] === (string) $variation->get_sku( 'edit' ),
			'manage_stock'  => $tracked === (bool) $variation->get_manage_stock( 'edit' ),
			'stock'         => $tracked
				? ( null !== $quantity && 0 === (int) $quantity && 'outofstock' === $variation->get_stock_status( 'edit' ) )
				: ( null === $quantity && 'instock' === $variation->get_stock_status( 'edit' ) ),
			'backorders'    => 'no' === $variation->get_backorders( 'edit' ),
			'tax_class'     => (string) ( $data['tax_class'] ?? 'parent' ) === (string) $variation->get_tax_class( 'edit' ),
			'image'         => (int) $decisions['image_id'] === (int) $variation->get_image_id( 'edit' ),
		];

		foreach ( $checks as $field => $passed ) {
			if ( ! $passed ) {
				return self::verify_error( 'variation_' . $field );
			}
		}

		return true;
	}

	/**
	 * The row's type, status and parent, read directly so a database error is
	 * told apart from a missing row (get_post() returns null for both).
	 *
	 * @return array{post_type:string,post_status:string,post_parent:int}|null|\WP_Error
	 *         Null only when the row is confirmed absent.
	 */
	private function read_post_state( int $post_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ID, post_type, post_status, post_parent FROM %i WHERE ID = %d', $wpdb->posts, $post_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'starter_import_db_error', 'The starter import could not read this product. Please try again.' );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'post_type'   => (string) $row['post_type'],
			'post_status' => (string) $row['post_status'],
			'post_parent' => (int) $row['post_parent'],
		];
	}

	/**
	 * Is this WooCommerce product used by a Bingo Play Product or Package?
	 * Read from the Hall's authoritative Catalog tables, never from
	 * classification metadata.
	 *
	 * @return bool|\WP_Error
	 */
	private function catalog_membership( int $product_id ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE wc_product_id = %d UNION ALL SELECT 1 FROM %i WHERE wc_product_id = %d LIMIT 1',
				Migrations::play_products_table(),
				$product_id,
				Migrations::packages_table(),
				$product_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'starter_import_db_error', 'The starter import could not check this product. Please try again.' );
		}

		return null !== $found;
	}

	/* ------------------------------------------------------------------ *
	 * Checks
	 * ------------------------------------------------------------------ */

	/** @return array{blog_id:int,actor:int}|\WP_Error */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return self::error( 'starter_import_not_allowed', 'You must be signed in to import starter products.' );
		}
		if ( ! class_exists( HallContext::class ) || ! HallContext::is_hall() ) {
			return self::error( 'starter_import_unavailable', 'Starter products can be imported only on a Hall site.' );
		}

		$actor = (int) get_current_user_id();
		if ( ! is_super_admin( $actor ) && ! current_user_can( 'bc_manage_products' ) ) {
			return self::error( 'starter_import_not_allowed', 'You are not allowed to import starter products for this Hall.' );
		}

		$blog_id = (int) get_current_blog_id();

		// Read-only Hall access check, guarded: without the evaluator, importing
		// is unavailable rather than treated as legacy access (ADR-049 s. 15).
		if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallCommerceAccess' ) ) {
			return self::error( 'starter_import_unavailable', 'Importing is unavailable right now.' );
		}
		if ( \BingoCommerce\PortalIntegration\HallCommerceAccess::is_blocked( $blog_id ) ) {
			return self::error( 'starter_import_unavailable', 'This Hall is not open right now, so importing is paused.' );
		}

		if ( ! Migrations::is_current() || ! class_exists( '\WC_Product_Simple' ) || ! class_exists( '\WC_Product_Variable' ) || ! class_exists( '\WC_Tax' ) ) {
			return self::error( 'starter_import_unavailable', 'This store is not ready for importing yet.' );
		}

		return [ 'blog_id' => $blog_id, 'actor' => $actor ];
	}

	/** @return true|\WP_Error */
	private function check_store_settings( array $version ) {
		$version_currency = (string) ( $version['currency'] ?? '' );
		$store_currency   = (string) get_option( 'woocommerce_currency', '' );
		if ( '' === $version_currency || $store_currency !== $version_currency ) {
			return self::error(
				'starter_import_currency_mismatch',
				/* translators: 1: store currency, 2: starter catalog currency. */
				sprintf( __( 'This store uses %1$s, but the starter products are priced in %2$s.', 'bingocommerce-catalog' ), $store_currency, $version_currency ),
				false
			);
		}

		if ( (string) get_option( 'woocommerce_prices_include_tax', 'no' ) !== (string) ( $version['prices_include_tax'] ?? '' ) ) {
			return self::error( 'starter_import_tax_setting_mismatch', 'This store and the starter products disagree on whether prices include tax.' );
		}

		return true;
	}

	/** @return true|\WP_Error */
	private function check_required_tax_classes( int $version_id ) {
		$required = StarterCatalogVersions::required_tax_classes( $version_id );
		if ( is_wp_error( $required ) ) {
			return $required;
		}

		$missing = array_values( array_diff( $required, \WC_Tax::get_tax_class_slugs() ) );
		if ( [] !== $missing ) {
			return self::missing_tax_class_error( $missing );
		}

		return true;
	}

	/**
	 * Start-time estimate of the space a version's images need: the Hall's
	 * quota only when quota checking is on, and the disk when PHP can tell.
	 *
	 * @return true|\WP_Error
	 */
	private function check_estimated_image_storage( int $version_id ) {
		$bytes = StarterCatalogVersions::total_image_bytes( $version_id );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$uploads = wp_upload_dir( null, false );

		return StarterCatalogStorage::check_space( $bytes, (string) ( $uploads['basedir'] ?? '' ) );
	}

	/** @return true|\WP_Error */
	private function check_tax_class( string $class ) {
		if ( '' !== $class && 'parent' !== $class && ! in_array( $class, \WC_Tax::get_tax_class_slugs(), true ) ) {
			return self::missing_tax_class_error( [ $class ] );
		}

		return true;
	}

	/**
	 * Before a product is created or finished: its tax class exists and its
	 * name will not be empty once WordPress saves it.
	 *
	 * @return true|\WP_Error
	 */
	private function check_before_create( array $data ) {
		$class = $this->check_tax_class( (string) ( $data['tax_class'] ?? '' ) );
		if ( is_wp_error( $class ) ) {
			return $class;
		}

		$name = $this->expected_text( 0, $data )['post_title'];
		if ( '' === trim( $name ) ) {
			return self::error( 'starter_import_name_empty', 'A starter product has no usable name.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Receipt transitions
	 * ------------------------------------------------------------------ */

	/** @return \WP_Error The error, after it is recorded on the receipt. */
	private function fail( array $receipt, \WP_Error $error, ?int $item_id ): \WP_Error {
		// Always written, including null, so an earlier item's ID never stays
		// attached to a later store-level failure.
		$saved = StarterImportRepository::update_receipt(
			(int) $receipt['import_id'],
			[
				'status'             => StarterImportRepository::RECEIPT_FAILED,
				'last_error_code'    => $error->get_error_code(),
				'last_error_item_id' => $item_id,
			]
		);
		if ( ! is_wp_error( $saved ) ) {
			$this->audit( 'failed', $receipt, [ 'error_code' => $error->get_error_code(), 'item_id' => $item_id ] );
		}

		return $error;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function needs_attention( array $receipt, ?int $item_id ) {
		$saved = StarterImportRepository::update_receipt(
			(int) $receipt['import_id'],
			[
				'status'             => StarterImportRepository::RECEIPT_NEEDS_ATTENTION,
				'last_error_code'    => null,
				'last_error_item_id' => $item_id,
			]
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->audit( 'needs_attention', $receipt, [ 'item_id' => $item_id ] );

		$current = StarterImportRepository::receipt();

		return is_array( $current ) ? $this->state_from( $current ) : self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function complete( array $receipt ) {
		$counts = StarterImportRepository::counts( (int) $receipt['import_id'] );
		if ( is_wp_error( $counts ) ) {
			return $counts;
		}

		$saved = StarterImportRepository::update_receipt(
			(int) $receipt['import_id'],
			[
				'status'             => StarterImportRepository::RECEIPT_COMPLETE,
				'imported_products'  => $counts['imported'],
				'skipped_products'   => $counts['skipped'],
				'last_error_code'    => null,
				'last_error_item_id' => null,
				'completed_at'       => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->audit( 'completed', $receipt, [ 'imported_products' => $counts['imported'], 'skipped_products' => $counts['skipped'] ] );

		$current = StarterImportRepository::receipt();

		return is_array( $current ) ? $this->state_from( $current ) : self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return true|\WP_Error */
	private function refresh_counts( array $receipt ) {
		$counts = StarterImportRepository::counts( (int) $receipt['import_id'] );
		if ( is_wp_error( $counts ) ) {
			return $counts;
		}

		return StarterImportRepository::update_receipt(
			(int) $receipt['import_id'],
			[
				'imported_products' => $counts['imported'],
				'skipped_products'  => $counts['skipped'],
			]
		);
	}

	/**
	 * Skip an item with a note. A skipped variable product's unfinished
	 * variations are skipped with it.
	 *
	 * @return string|\WP_Error The skipped status, or the error saving it.
	 */
	private function skip( array $item, string $note ) {
		$item_id = (int) $item['item_id'];
		$skipped = StarterImportRepository::update_item(
			$item_id,
			[
				'status'       => StarterImportRepository::ITEM_SKIPPED,
				'note'         => $note,
				'completed_at' => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $skipped ) ) {
			return $skipped;
		}

		if ( 'variable' === (string) $item['product_type'] ) {
			$children = StarterImportRepository::skip_children( (int) $item['import_id'], $item_id, $note );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
		}

		$this->worked = true;

		return StarterImportRepository::ITEM_SKIPPED;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Stop at a safe point once the budget is used, after some real work. */
	private function should_pause(): bool {
		return $this->worked && microtime( true ) >= $this->deadline;
	}

	/** @return array<string,mixed> */
	private function state_from( array $receipt ): array {
		return [
			'import_id'          => (int) $receipt['import_id'],
			'version_id'         => (int) $receipt['version_id'],
			'status'             => (string) $receipt['status'],
			'total_products'     => (int) $receipt['total_products'],
			'imported_products'  => (int) $receipt['imported_products'],
			'skipped_products'   => (int) $receipt['skipped_products'],
			'last_error_code'    => null === $receipt['last_error_code'] ? null : (string) $receipt['last_error_code'],
			'last_error_item_id' => null === $receipt['last_error_item_id'] ? null : (int) $receipt['last_error_item_id'],
			'started_at'         => (string) $receipt['started_at'],
			'completed_at'       => null === $receipt['completed_at'] ? null : (string) $receipt['completed_at'],
		];
	}

	private function item_id_for( array $receipt, array $version_product ): ?int {
		$item = StarterImportRepository::item( (int) $receipt['import_id'], (int) $version_product['version_product_id'] );

		return is_array( $item ) ? (int) $item['item_id'] : null;
	}

	/** The version's slug, or the agreed fallback when it is empty. */
	private function normal_slug( array $data ): string {
		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );

		return '' !== $slug ? $slug : 'starter-product-' . (int) ( $data['source_product_id'] ?? 0 );
	}

	private function sku_note( string $sku ): string {
		/* translators: %s: SKU. */
		return sprintf( __( 'SKU %s is already used in this store, so this product was added without a SKU.', 'bingocommerce-catalog' ), $sku );
	}

	private function image_note( int $version_image_id ): string {
		$version_image = StarterCatalogVersions::image( $version_image_id );
		$name          = is_array( $version_image ) ? wp_basename( (string) $version_image['file_path'] ) : (string) $version_image_id;

		/* translators: %s: image file name. */
		return sprintf( __( 'Image %s was deleted in this store, so it was left out.', 'bingocommerce-catalog' ), $name );
	}

	private function checkpoint( string $step, array $context ): void {
		do_action( self::CHECKPOINT_ACTION, $step, $context );
	}

	/**
	 * One audit event per transition. The operation ID is derived from the
	 * import and the transition, so a repeated transition collides with the
	 * audit table's unique key instead of duplicating. An audit failure never
	 * repeats import work.
	 */
	private function audit( string $event, array $receipt, array $context ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		$operation_id = self::operation_id( (string) $receipt['import_uuid'], $event . '|' . wp_json_encode( $context ) );
		// Already recorded (for example the same failure again): skip rather
		// than let the unique key reject a duplicate insert.
		if ( is_array( AuditLog::find_operation_witness( $operation_id ) ) ) {
			return;
		}

		AuditLog::record(
			[
				'blog_id'        => (int) get_current_blog_id(),
				'actor_user_id'  => get_current_user_id() > 0 ? (int) get_current_user_id() : null,
				'action'         => 'starter_import.' . $event,
				'target_type'    => 'starter_import',
				'target_id'      => (int) $receipt['import_id'],
				'previous_value' => null,
				'new_value'      => null,
				'context'        => $context + [ 'version_id' => (int) $receipt['version_id'] ],
				'operation_id'   => $operation_id,
			]
		);
	}

	/** A deterministic UUID in version 4 layout for one import transition. */
	private static function operation_id( string $import_uuid, string $discriminator ): string {
		$hex     = hash( 'sha256', $import_uuid . '|' . $discriminator );
		$variant = dechex( 8 | ( hexdec( $hex[16] ) & 3 ) );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-' . $variant . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}

	private static function recovery_slug(): string {
		return self::RECOVERY_PREFIX . bin2hex( random_bytes( 8 ) );
	}

	private static function variation_label( array $data ): string {
		$values = array_filter( array_map( 'strval', (array) ( $data['attributes'] ?? [] ) ), static fn( string $value ): bool => '' !== $value );

		return [] === $values ? __( 'Any', 'bingocommerce-catalog' ) : implode( ', ', $values );
	}

	/** @param array<int,string> $notes */
	private static function join_notes( array $notes ): ?string {
		$notes = array_values( array_unique( array_filter( array_map( 'strval', $notes ), static fn( string $note ): bool => '' !== $note ) ) );

		return [] === $notes ? null : implode( "\n", $notes );
	}

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private static function string_map( $value ): array {
		$map = [];
		foreach ( (array) $value as $key => $item ) {
			$map[ (string) $key ] = (string) $item;
		}

		return $map;
	}

	/** @param array<string,string> $map */
	private static function sorted_map( array $map ): array {
		ksort( $map );

		return $map;
	}

	/**
	 * @param array<int,string> $classes
	 */
	private static function missing_tax_class_error( array $classes ): \WP_Error {
		return self::error(
			'starter_import_tax_class_missing',
			/* translators: %s: tax class slugs. */
			sprintf( __( 'Add the tax class %s in WooCommerce > Settings > Tax, then try again.', 'bingocommerce-catalog' ), implode( ', ', $classes ) ),
			false
		);
	}

	/** @param array<int,int|string> $ids */
	private static function sorted( array $ids ): array {
		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return $ids;
	}

	private static function verify_error( string $field ): \WP_Error {
		return new \WP_Error( 'starter_import_verify_failed', __( 'The imported product could not be verified. Please try again.', 'bingocommerce-catalog' ), [ 'field' => $field ] );
	}

	private static function lock_lost(): \WP_Error {
		return self::error( 'starter_import_lock_lost', 'The starter import stopped safely. Please continue.' );
	}

	/** Fixed, plain messages: no SQL, paths or stored values beyond the given text. */
	private static function error( string $code, string $message, bool $translate = true ): \WP_Error {
		return new \WP_Error( $code, $translate ? __( $message, 'bingocommerce-catalog' ) : $message );
	}
}
