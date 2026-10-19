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
 * SLICE 3.2 SCOPE: simple products without images. A variable product or a
 * product with images stops the import with "not supported yet" instead of
 * being copied wrongly; slice 3.3 adds both.
 *
 * Crash safety (ADR-049 section 10): an item's recovery identifier is saved
 * before the product is created and is the product's first slug, and the
 * product ID is saved as soon as creation returns. "Creation started but no
 * product ID saved" is never guessed at: it becomes Needs attention.
 */
final class StarterImporter {

	/**
	 * Fires after each durable step: ( string $step, array $context ).
	 * A test seam only; nothing in production listens to it.
	 */
	public const CHECKPOINT_ACTION = 'bingocommerce_starter_import_checkpoint';

	public const DEFAULT_BUDGET_SECONDS = 5.0;

	private const RECOVERY_PREFIX = 'bcsi-';

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

			$settings = $this->check_store_settings( $version );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}
			$classes = $this->check_required_tax_classes( (int) $version['version_id'] );
			if ( is_wp_error( $classes ) ) {
				return $classes;
			}

			$total = StarterCatalogVersions::count_top_level_products( (int) $version['version_id'] );
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

			$receipt = StarterImportRepository::create_receipt( $import_uuid, (int) $version['version_id'], $total, $context['actor'] );
			if ( is_wp_error( $receipt ) ) {
				return $receipt;
			}

			$this->audit( 'started', $receipt, [ 'version_id' => (int) $version['version_id'], 'total_products' => $total ] );

			return $this->state_from( $receipt );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Copy products until the time budget is used. At least one product is
	 * attempted per call, so a budget of 0 processes exactly one.
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

			$deadline = microtime( true ) + max( 0.0, $budget_seconds );
			do {
				if ( ! $lock->is_still_held() ) {
					return self::error( 'starter_import_lock_lost', 'The starter import stopped safely. Please continue.' );
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
			} while ( microtime( true ) < $deadline );

			$current = StarterImportRepository::receipt();

			return is_array( $current ) ? $this->state_from( $current ) : self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
		} finally {
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
	 * One product
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|\WP_Error An item status (complete, skipped,
	 *         needs_attention) or the error that stopped the item.
	 */
	private function process_product( array $version_product, array $receipt, array $version ) {
		$data = StarterCatalogVersions::data( $version_product );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// Only for the slug fallback; never copied to the Hall's product.
		$data['source_product_id'] = (int) $version_product['source_product_id'];

		if ( 'simple' !== (string) $version_product['product_type'] ) {
			return self::error( 'starter_import_unsupported_item', 'This kind of starter product cannot be imported yet.' );
		}
		if ( ! empty( $data['image_id'] ) || ! empty( $data['gallery_image_ids'] ) ) {
			return self::error( 'starter_import_unsupported_item', 'Starter products with images cannot be imported yet.' );
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

			$item = StarterImportRepository::insert_item(
				$import_id,
				$version_product_id,
				null,
				'simple',
				(int) $version_product['position'],
				self::RECOVERY_PREFIX . bin2hex( random_bytes( 8 ) )
			);
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
				return $this->create( $item, $data, $version );

			case StarterImportRepository::ITEM_CREATING:
				if ( (int) $item['wc_product_id'] < 1 ) {
					// Creation started but its result was never recorded: never guess.
					$marked = StarterImportRepository::update_item( (int) $item['item_id'], [ 'status' => StarterImportRepository::ITEM_NEEDS_ATTENTION ] );

					return is_wp_error( $marked ) ? $marked : StarterImportRepository::ITEM_NEEDS_ATTENTION;
				}
				return $this->finish( $item, $data, $version );

			case StarterImportRepository::ITEM_CREATED:
				return $this->finish( $item, $data, $version );
		}

		return self::error( 'starter_import_db_error', 'The starter import progress could not be read. Please try again.' );
	}

	/** @return string|\WP_Error */
	private function create( array $item, array $data, array $version ) {
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
			$product = new \WC_Product_Simple();
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

		return $this->finish( $item, $data, $version );
	}

	/**
	 * Apply the remaining fields to the item's own product, verify, set its
	 * normal slug and complete it. Applies the Retry rules first.
	 *
	 * @return string|\WP_Error
	 */
	private function finish( array $item, array $data, array $version ) {
		$item_id    = (int) $item['item_id'];
		$product_id = (int) $item['wc_product_id'];

		// A failed read is an error, never "removed": only a confirmed missing
		// row may skip the item.
		$post_state = $this->read_post_state( $product_id );
		if ( is_wp_error( $post_state ) ) {
			return $post_state;
		}
		if ( null === $post_state || 'product' !== $post_state['post_type'] || 'trash' === $post_state['post_status'] ) {
			return $this->skip( $item_id, __( 'Removed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
		}

		$linked = $this->catalog_membership( $product_id );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		if ( $linked || 'draft' !== $post_state['post_status'] ) {
			return $this->skip( $item_id, __( 'Changed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
		}

		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			// The row exists, so a missing object is a read failure, not a change.
			return self::error( 'starter_import_db_error', 'The starter import could not read this product. Please try again.' );
		}
		if ( ! $product->is_type( 'simple' ) ) {
			return $this->skip( $item_id, __( 'Changed in this store before the import finished, so it was skipped.', 'bingocommerce-catalog' ) );
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

		$product->set_name( (string) $data['name'] );
		$product->set_description( (string) ( $data['description'] ?? '' ) );
		$product->set_short_description( (string) ( $data['short_description'] ?? '' ) );
		$product->set_regular_price( (string) ( $data['regular_price'] ?? '' ) );
		// Sale prices are not copied; a sale added to an unfinished draft is
		// replaced like any other edit (ADR-049 section 8 warning).
		$product->set_sale_price( '' );
		$product->set_date_on_sale_from( '' );
		$product->set_date_on_sale_to( '' );
		$product->set_status( 'draft' );
		$product->set_tax_status( (string) ( $data['tax_status'] ?? 'taxable' ) );
		$product->set_tax_class( (string) ( $data['tax_class'] ?? '' ) );
		$product->set_backorders( 'no' );
		if ( ! empty( $data['manage_stock'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 0 );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}
		$product->set_category_ids( $decisions['category_ids'] );
		$product->set_tag_ids( $decisions['tag_ids'] );

		try {
			$product->set_sku( $decisions['sku'] );
		} catch ( \WC_Data_Exception $exception ) {
			unset( $exception );
			// Taken by another product since the decision was made.
			$notes[]          = $this->sku_note( $decisions['sku'] );
			$decisions['sku'] = '';
			$product->set_sku( '' );
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
				'note'      => [] === $notes ? null : implode( "\n", array_unique( $notes ) ),
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
		$verified = $this->verify_fields( $product_id, $data, $decisions );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$this->checkpoint( 'verified', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		$slug    = $this->normal_slug( $data );
		$renamed = wp_update_post( [ 'ID' => $product_id, 'post_name' => $slug ], true );
		if ( is_wp_error( $renamed ) || (int) $renamed !== $product_id ) {
			return self::error( 'starter_import_save_failed', 'A starter product could not be saved. Please try again.' );
		}
		clean_post_cache( $product_id );
		$renamed_post = get_post( $product_id );
		if ( ! $renamed_post instanceof \WP_Post || sanitize_title( $slug ) !== $renamed_post->post_name ) {
			return self::error( 'starter_import_verify_failed', 'The imported product could not be verified. Please try again.' );
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
				'completed_at' => current_time( 'mysql', true ),
			]
		);
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		$this->checkpoint( 'item_completed', [ 'item_id' => $item_id, 'wc_product_id' => $product_id ] );

		return StarterImportRepository::ITEM_COMPLETE;
	}

	/**
	 * The SKU, category and tag choices for this Hall. Saved decisions from an
	 * earlier attempt are reused so a retry makes the same choices.
	 *
	 * @param array<int,string> $notes
	 * @return array{sku:string,category_ids:array<int,int>,tag_ids:array<int,int>}|\WP_Error
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
			return self::error( 'starter_import_verify_failed', 'The imported product could not be verified. Please try again.' );
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
	private function verify_fields( int $product_id, array $data, array $decisions ) {
		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return self::verify_error();
		}

		$tracked  = ! empty( $data['manage_stock'] );
		$quantity = $product->get_stock_quantity( 'edit' );

		$expected_categories = $decisions['category_ids'];
		if ( [] === $expected_categories && (int) get_option( 'default_product_cat', 0 ) > 0 ) {
			$expected_categories = [ (int) get_option( 'default_product_cat', 0 ) ];
		}

		$checks = [
			'status'        => 'draft' === $product->get_status( 'edit' ),
			'type'          => $product->is_type( 'simple' ),
			'regular_price' => (string) ( $data['regular_price'] ?? '' ) === (string) $product->get_regular_price( 'edit' ),
			'sale_price'    => '' === (string) $product->get_sale_price( 'edit' ),
			'sale_dates'    => null === $product->get_date_on_sale_from( 'edit' ) && null === $product->get_date_on_sale_to( 'edit' ),
			'sku'           => $decisions['sku'] === (string) $product->get_sku( 'edit' ),
			'manage_stock'  => $tracked === (bool) $product->get_manage_stock( 'edit' ),
			'stock'         => $tracked
				? ( null !== $quantity && 0 === (int) $quantity && 'outofstock' === $product->get_stock_status( 'edit' ) )
				: ( null === $quantity && 'instock' === $product->get_stock_status( 'edit' ) ),
			'backorders'    => 'no' === $product->get_backorders( 'edit' ),
			'tax_status'    => (string) ( $data['tax_status'] ?? 'taxable' ) === (string) $product->get_tax_status( 'edit' ),
			'tax_class'     => (string) ( $data['tax_class'] ?? '' ) === (string) $product->get_tax_class( 'edit' ),
			'categories'    => self::sorted( $expected_categories ) === self::sorted( $product->get_category_ids( 'edit' ) ),
			'tags'          => self::sorted( $decisions['tag_ids'] ) === self::sorted( $product->get_tag_ids( 'edit' ) ),
		];

		foreach ( $checks as $field => $passed ) {
			if ( ! $passed ) {
				return new \WP_Error( 'starter_import_verify_failed', __( 'The imported product could not be verified. Please try again.', 'bingocommerce-catalog' ), [ 'field' => $field ] );
			}
		}

		$linked = $this->catalog_membership( $product_id );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		if ( $linked ) {
			return new \WP_Error( 'starter_import_verify_failed', __( 'The imported product could not be verified. Please try again.', 'bingocommerce-catalog' ), [ 'field' => 'catalog_membership' ] );
		}

		return true;
	}

	/**
	 * The product row's type and status, read directly so a database error is
	 * told apart from a missing row (get_post() returns null for both).
	 *
	 * @return array{post_type:string,post_status:string}|null|\WP_Error Null
	 *         only when the row is confirmed absent.
	 */
	private function read_post_state( int $product_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ID, post_type, post_status FROM %i WHERE ID = %d', $wpdb->posts, $product_id ),
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

		if ( ! Migrations::is_current() || ! class_exists( '\WC_Product_Simple' ) || ! class_exists( '\WC_Tax' ) ) {
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
	 * Before a product is created or finished: its tax class exists and its
	 * name will not be empty once WordPress saves it.
	 *
	 * @return true|\WP_Error
	 */
	private function check_before_create( array $data ) {
		$class = (string) ( $data['tax_class'] ?? '' );
		if ( '' !== $class && ! in_array( $class, \WC_Tax::get_tax_class_slugs(), true ) ) {
			return self::missing_tax_class_error( [ $class ] );
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

	/** @return string|\WP_Error The skipped status, or the error saving it. */
	private function skip( int $item_id, string $note ) {
		$skipped = StarterImportRepository::update_item(
			$item_id,
			[
				'status'       => StarterImportRepository::ITEM_SKIPPED,
				'note'         => $note,
				'completed_at' => current_time( 'mysql', true ),
			]
		);

		return is_wp_error( $skipped ) ? $skipped : StarterImportRepository::ITEM_SKIPPED;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

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

		$discriminator = $event . '|' . wp_json_encode( $context );
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
				'operation_id'   => self::operation_id( (string) $receipt['import_uuid'], $discriminator ),
			]
		);
	}

	/** A deterministic UUID in version 4 layout for one import transition. */
	private static function operation_id( string $import_uuid, string $discriminator ): string {
		$hex     = hash( 'sha256', $import_uuid . '|' . $discriminator );
		$variant = dechex( 8 | ( hexdec( $hex[16] ) & 3 ) );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-' . $variant . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
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

	private static function verify_error(): \WP_Error {
		return self::error( 'starter_import_verify_failed', 'The imported product could not be verified. Please try again.' );
	}

	/** Fixed, plain messages: no SQL, paths or stored values beyond the given text. */
	private static function error( string $code, string $message, bool $translate = true ): \WP_Error {
		return new \WP_Error( $code, $translate ? __( $message, 'bingocommerce-catalog' ) : $message );
	}
}
