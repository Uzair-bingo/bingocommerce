<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative current-hall Bingo Play Product domain service (BR-039/ADR-031).
 *
 * The Catalog critical transport is entered only for the mutations that need its
 * serialization: create (Woo Package/Play claim exclusivity), the paths that
 * create or revalidate a Game Type reference, and — since ADR-034 — BOTH medium
 * transitions, because BR-069 requires the Package-component reference check and
 * the medium write to happen under one held bingo_play_products row lock.
 *
 * Archive, faces-per-unit, and clearing a Game Type remain one conditional
 * statement each through ordinary $wpdb, which keeps their final invariants
 * under concurrency without broadening the mysqli exception.
 */
final class BingoPlayProductService {

	public const MEDIUM_PAPER   = 'paper';
	public const MEDIUM_DIGITAL = 'digital';

	private const MEDIUMS = [ self::MEDIUM_PAPER, self::MEDIUM_DIGITAL ];

	/** Bounded by the faces_per_unit column so a large value is rejected, never truncated. */
	private const FACES_MAX = 4294967295;

	/** Statuses a linked WooCommerce product may hold and remain eligible. */
	private const WOO_ALLOWED_STATUSES = [ 'publish', 'draft', 'private', 'pending' ];

	/** Only top-level simple products are supported in Increment 1. */
	private const WOO_SUPPORTED_TYPES = [ 'simple' ];

	/** Restore may re-select its path once when the row flips underneath the first read. */
	private const RESTORE_PATH_SELECTIONS = 2;

	/** @var callable */
	private $audit_recorder;

	public function __construct( ?callable $audit_recorder = null ) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
	}

	/* ------------------------------------------------------------------ *
	 * Mutations
	 * ------------------------------------------------------------------ */

	/** @return PlayProductResult|\WP_Error */
	public function create( int $wc_product_id, string $medium, int $faces_per_unit, ?int $game_type_id = null ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$medium = $this->canonicalize_medium( $medium );
		if ( is_wp_error( $medium ) ) {
			return $medium;
		}

		$faces = $this->assert_faces( $faces_per_unit );
		if ( is_wp_error( $faces ) ) {
			return $faces;
		}

		if ( $wc_product_id < 1 ) {
			return $this->error( 'invalid_wc_product_id', 'The WooCommerce product ID is invalid.' );
		}

		if ( self::MEDIUM_DIGITAL === $medium && ( null === $game_type_id || $game_type_id < 1 ) ) {
			return $this->error( 'game_type_required_for_digital', 'A Digital Bingo Play Product must reference one Game Type.' );
		}
		if ( null !== $game_type_id && $game_type_id < 1 ) {
			return $this->error( 'invalid_game_type_id', 'The Game Type ID is invalid.' );
		}

		// WooCommerce CRUD validation happens here, before BEGIN. Only the minimal
		// posts-row revalidation ADR-032 permits happens under the lock.
		$eligible = $this->assert_woo_product_eligible( $wc_product_id );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		// SHOW TABLES / SHOW COLUMNS are not permitted statements inside the
		// ADR-032 boundary, so the Package table is probed here instead.
		$check_package_claim = $this->package_claim_is_checkable();
		if ( is_wp_error( $check_package_claim ) ) {
			return $check_package_claim;
		}

		$plan = CatalogPlayProductPlan::forCreate(
			$context['blog_id'],
			$wc_product_id,
			$medium,
			$faces,
			$game_type_id,
			$check_package_claim,
			current_time( 'mysql', true )
		);

		$outcome = CatalogTransactionTransport::createWithProductClaim( $plan );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		// create() can never be a verified no-op, so it settles as a mutation.
		return $this->settle_direct( $outcome->bingo_play_product_id, 'play_product_created', 'catalog.play_product.created', $context, null );
	}

	/** @return PlayProductResult|\WP_Error */
	public function updateMedium( int $bingo_play_product_id, string $new_medium, ?int $game_type_id_for_digital = null ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$new_medium = $this->canonicalize_medium( $new_medium );
		if ( is_wp_error( $new_medium ) ) {
			return $new_medium;
		}

		$before = $this->require_row( $bingo_play_product_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( self::MEDIUM_PAPER === $new_medium ) {
			if ( null !== $game_type_id_for_digital ) {
				return $this->error( 'game_type_not_applicable', 'A Game Type cannot be supplied when switching a Bingo Play Product to Paper.' );
			}

			return $this->transition_to_paper( $before, $context );
		}

		if ( null === $game_type_id_for_digital || $game_type_id_for_digital < 1 ) {
			// BR-039: Digital always references exactly one Game Type, and BR-037
			// requires it to be an explicit choice — never inherited from whatever
			// reference a Paper row happened to carry, which may be archived.
			return $this->error( 'game_type_required_for_digital', 'Choose an active Game Type for the Digital Bingo Play Product.' );
		}

		if (
			self::MEDIUM_DIGITAL === $before['medium']
			&& (int) $game_type_id_for_digital === (int) ( $before['game_type_id'] ?? 0 )
		) {
			return new PlayProductResult( 'play_product_medium_unchanged', $before );
		}

		$outcome = CatalogTransactionTransport::transitionToDigital(
			CatalogPlayProductPlan::forPaperToDigital(
				$context['blog_id'],
				$bingo_play_product_id,
				$game_type_id_for_digital,
				current_time( 'mysql', true )
			)
		);
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( ! $outcome->mutated ) {
			// Another writer reached the same target state first. Under the lock
			// this is a verified no-op, so it reports as unchanged and audits
			// nothing rather than claiming a change it did not make.
			$current = $this->read_by_id( $bingo_play_product_id );

			return is_wp_error( $current )
				? $current
				: new PlayProductResult( 'play_product_medium_unchanged', $current );
		}

		return $this->settle_direct( $outcome->bingo_play_product_id, 'play_product_medium_changed', 'catalog.play_product.updated', $context, $outcome->before_row ?? $before );
	}

	/**
	 * Digital -> Paper.
	 *
	 * ADR-034 moved this out of the ordinary single-statement $wpdb path. BR-069
	 * requires the Package-reference check and the medium write to happen under
	 * one held bingo_play_products row lock, which a lone conditional UPDATE
	 * cannot provide. The Game Type reference is preserved by the transport,
	 * which omits game_type_id from its SET list (BR-039).
	 *
	 * @param array<string,mixed>    $before
	 * @param array<string,int|bool> $context
	 * @return PlayProductResult|\WP_Error
	 */
	private function transition_to_paper( array $before, array $context ) {
		$id = (int) $before['bingo_play_product_id'];

		if ( self::MEDIUM_PAPER === $before['medium'] ) {
			// True no-op. BR-069 blocks an ACTUAL transition only, so this is not
			// rejected even when the Play Product is Package-referenced, and it
			// emits no audit event.
			return new PlayProductResult( 'play_product_medium_unchanged', $before );
		}

		$outcome = CatalogTransactionTransport::transitionToPaper(
			CatalogPlayProductPlan::forDigitalToPaper(
				$context['blog_id'],
				$id,
				current_time( 'mysql', true )
			)
		);
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( ! $outcome->mutated ) {
			$current = $this->read_by_id( $id );

			return is_wp_error( $current )
				? $current
				: new PlayProductResult( 'play_product_medium_unchanged', $current );
		}

		return $this->settle_direct( $id, 'play_product_medium_changed', 'catalog.play_product.updated', $context, $outcome->before_row ?? $before );
	}

	/** @return PlayProductResult|\WP_Error */
	public function updateFacesPerUnit( int $bingo_play_product_id, int $faces_per_unit ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$faces = $this->assert_faces( $faces_per_unit );
		if ( is_wp_error( $faces ) ) {
			return $faces;
		}

		$before = $this->require_row( $bingo_play_product_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $faces === (int) $before['faces_per_unit'] ) {
			return new PlayProductResult( 'play_product_faces_unchanged', $before );
		}

		$table = Migrations::play_products_table();
		// Compare-and-set on the observed value: an unchanged, possibly archived
		// Game Type reference is deliberately not revalidated here.
		$affected = $this->conditional_update(
			"UPDATE {$table} SET faces_per_unit = %d, updated_at = %s WHERE bingo_play_product_id = %d AND faces_per_unit = %d",
			[ $faces, current_time( 'mysql', true ), $bingo_play_product_id, (int) $before['faces_per_unit'] ]
		);
		if ( is_wp_error( $affected ) ) {
			return $affected;
		}
		if ( 0 === $affected ) {
			return $this->resolve_lost_update( $bingo_play_product_id );
		}

		return $this->settle_direct( $bingo_play_product_id, 'play_product_faces_updated', 'catalog.play_product.updated', $context, $before );
	}

	/** @return PlayProductResult|\WP_Error */
	public function assignGameType( int $bingo_play_product_id, int $game_type_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $game_type_id < 1 ) {
			return $this->error( 'invalid_game_type_id', 'The Game Type ID is invalid.' );
		}

		$before = $this->require_row( $bingo_play_product_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		// Fast path for an assignment that changes nothing. It creates no new
		// reference, so it needs neither the ADR-032 boundary nor a status check
		// on an existing reference that may since have been archived.
		if ( $game_type_id === (int) ( $before['game_type_id'] ?? 0 ) ) {
			return new PlayProductResult( 'play_product_game_type_unchanged', $before );
		}

		$outcome = CatalogTransactionTransport::assignGameTypeReference(
			CatalogPlayProductPlan::forAssignGameType(
				$context['blog_id'],
				$bingo_play_product_id,
				$game_type_id,
				current_time( 'mysql', true )
			)
		);
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( ! $outcome->mutated ) {
			return new PlayProductResult( 'play_product_game_type_unchanged', $outcome->before_row ?? $before );
		}

		return $this->settle_direct( $outcome->bingo_play_product_id, 'play_product_game_type_assigned', 'catalog.play_product.updated', $context, $outcome->before_row ?? $before );
	}

	/** @return PlayProductResult|\WP_Error */
	public function clearGameType( int $bingo_play_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $bingo_play_product_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( self::MEDIUM_DIGITAL === $before['medium'] ) {
			return $this->error( 'game_type_required_for_digital', 'A Digital Bingo Play Product must keep its Game Type.' );
		}

		if ( null === $before['game_type_id'] ) {
			return new PlayProductResult( 'play_product_game_type_unchanged', $before );
		}

		$table = Migrations::play_products_table();
		// The medium predicate lives INSIDE the statement, so a concurrent
		// Paper -> Digital transition cannot interleave into digital + NULL:
		// both statements contend for the same InnoDB row lock, and whichever
		// loses finds its predicate false and affects no rows.
		$affected = $this->conditional_update(
			"UPDATE {$table} SET game_type_id = NULL, updated_at = %s"
			. " WHERE bingo_play_product_id = %d AND medium = 'paper' AND game_type_id IS NOT NULL",
			[ current_time( 'mysql', true ), $bingo_play_product_id ]
		);
		if ( is_wp_error( $affected ) ) {
			return $affected;
		}

		if ( 0 === $affected ) {
			$current = $this->read_by_id( $bingo_play_product_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( self::MEDIUM_DIGITAL === $current['medium'] ) {
				return $this->error( 'game_type_required_for_digital', 'A Digital Bingo Play Product must keep its Game Type.' );
			}
			if ( null === $current['game_type_id'] ) {
				return new PlayProductResult( 'play_product_game_type_unchanged', $current );
			}

			return $this->error( CatalogTransactionTransport::STATE_CHANGED, 'The Bingo Play Product changed while it was being updated. Reload and try again.' );
		}

		return $this->settle_direct( $bingo_play_product_id, 'play_product_game_type_cleared', 'catalog.play_product.updated', $context, $before );
	}

	/** @return PlayProductResult|\WP_Error */
	public function archive( int $bingo_play_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $bingo_play_product_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( 'archived' === $before['status'] ) {
			return new PlayProductResult( 'play_product_already_archived', $before );
		}

		$table = Migrations::play_products_table();
		$now   = current_time( 'mysql', true );
		// Atomic active -> archived transition. Every other column, including the
		// Game Type reference, is preserved.
		$affected = $this->conditional_update(
			"UPDATE {$table} SET status = 'archived', archived_at = %s, updated_at = %s"
			. " WHERE bingo_play_product_id = %d AND status = 'active'",
			[ $now, $now, $bingo_play_product_id ]
		);
		if ( is_wp_error( $affected ) ) {
			return $affected;
		}

		if ( 0 === $affected ) {
			$current = $this->read_by_id( $bingo_play_product_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			return 'archived' === $current['status']
				? new PlayProductResult( 'play_product_already_archived', $current )
				: $this->error( CatalogTransactionTransport::STATE_CHANGED, 'The Bingo Play Product changed while it was being archived. Reload and try again.' );
		}

		return $this->settle_direct( $bingo_play_product_id, 'play_product_archived', 'catalog.play_product.archived', $context, $before );
	}

	/**
	 * Restore has two paths so that a repeated restore never demands WooCommerce
	 * eligibility, and so the ADR-032 lock order is never inverted.
	 *
	 * @return PlayProductResult|\WP_Error
	 */
	public function restore( int $bingo_play_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		for ( $selection = 1; $selection <= self::RESTORE_PATH_SELECTIONS; ++$selection ) {
			$before = $this->require_row( $bingo_play_product_id );
			if ( is_wp_error( $before ) ) {
				return $before;
			}

			if ( 'active' === $before['status'] ) {
				// PATH A. No transaction, no locks, no WooCommerce revalidation.
				// One confirming re-read narrows the window; if the row has since
				// been archived, fall through and re-select into Path B.
				$confirmed = $this->require_row( $bingo_play_product_id );
				if ( is_wp_error( $confirmed ) ) {
					return $confirmed;
				}
				if ( 'active' === $confirmed['status'] ) {
					return new PlayProductResult( 'play_product_already_active', $confirmed );
				}

				continue;
			}

			// PATH B. Woo eligibility is validated here, before BEGIN; the
			// transport then locks the Woo row FIRST and only afterwards the Play
			// Product row, so the canonical order is never reversed.
			$eligible = $this->assert_woo_product_eligible( (int) $before['wc_product_id'] );
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}

			$outcome = CatalogTransactionTransport::restoreWithWooRevalidation(
				CatalogPlayProductPlan::forRestore(
					$context['blog_id'],
					$bingo_play_product_id,
					(int) $before['wc_product_id'],
					current_time( 'mysql', true )
				)
			);
			if ( is_wp_error( $outcome ) ) {
				return $outcome;
			}

			if ( ! $outcome->mutated ) {
				// The row was restored by someone else between the read and the
				// lock. Resolved under both locks, without reaching backwards.
				return new PlayProductResult( 'play_product_already_active', $outcome->before_row ?? $before );
			}

			return $this->settle_direct( $outcome->bingo_play_product_id, 'play_product_restored', 'catalog.play_product.restored', $context, $outcome->before_row ?? $before );
		}

		return $this->error(
			CatalogTransactionTransport::STATE_CHANGED,
			'The Bingo Play Product status changed repeatedly while it was being restored. Reload and try again.'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function getById( int $bingo_play_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $this->require_row( $bingo_play_product_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function getByWooProductId( int $wc_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $wc_product_id < 1 ) {
			return $this->error( 'invalid_wc_product_id', 'The WooCommerce product ID is invalid.' );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d',
				$wc_product_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		return $this->map_row( $row );
	}

	/**
	 * WooCommerce products this hall may still link as a Bingo Play Product.
	 *
	 * Convenience only. ADR-032 claim exclusivity remains authoritative inside
	 * the serialized writer; this read prevents already-claimed products from
	 * being presented as ordinary create choices.
	 *
	 * @return array<int,int>|\WP_Error
	 */
	public function selectableWooProductIds( int $limit = 200 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$ids = wc_get_products(
			[
				'type'   => 'simple',
				'status' => self::WOO_ALLOWED_STATUSES,
				'limit'  => max( 1, min( 500, $limit ) ),
				'return' => 'ids',
				'parent' => 0,
			]
		);
		if ( ! is_array( $ids ) ) {
			return [];
		}

		global $wpdb;
		$selectable = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;

			$play_claim = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $id )
			);
			$package_claim = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', $id )
			);
			if ( $play_claim > 0 || $package_claim > 0 ) {
				continue;
			}

			$selectable[] = $id;
		}

		return $selectable;
	}

	/**
	 * Administrative listing. Rows are never mutated while reading, and a broken
	 * WooCommerce link is surfaced through `woo_state` rather than hidden.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function listForAdministration( string $status = 'all', int $page = 1, int $per_page = 50 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			return $this->error( 'invalid_status', 'The Bingo Play Product status filter is invalid.' );
		}

		global $wpdb;
		$table    = Migrations::play_products_table();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( 'all' === $status ) {
			$sql = $wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY bingo_play_product_id LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status = %s ORDER BY bingo_play_product_id LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return array_map( [ $this, 'map_row' ], $rows );
	}

	/**
	 * Candidates that are valid RIGHT NOW for building a new Bingo Package.
	 *
	 * Excludes archived rows, rows whose referenced Game Type is missing or
	 * archived (Paper included), Digital rows without a Game Type, and rows whose
	 * WooCommerce product is missing, trashed, or no longer a top-level simple
	 * product. Nothing is mutated while filtering; historical rows stay intact.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function listActiveForPackageSelection( int $page = 1, int $per_page = 100 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		global $wpdb;
		$table      = Migrations::play_products_table();
		$game_types = Migrations::game_types_table();
		$page       = max( 1, $page );
		$per_page   = max( 1, min( 200, $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . self::prefixed_columns( 'pp' ) . " FROM {$table} pp"
				. " LEFT JOIN {$game_types} gt ON gt.game_type_id = pp.game_type_id"
				. " WHERE pp.status = 'active'"
				. " AND ( pp.medium <> 'digital' OR pp.game_type_id IS NOT NULL )"
				. " AND ( pp.game_type_id IS NULL OR ( gt.game_type_id IS NOT NULL AND gt.status = 'active' ) )"
				. ' ORDER BY pp.bingo_play_product_id LIMIT %d OFFSET %d',
				$per_page,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$selectable = [];
		foreach ( $rows as $row ) {
			$mapped = $this->map_row( $row );
			if ( 'ok' === $mapped['woo_state'] ) {
				$selectable[] = $mapped;
			}
		}

		return $selectable;
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	private const COLUMNS = 'bingo_play_product_id, wc_product_id, medium, faces_per_unit, game_type_id, status, created_at, updated_at, archived_at';

	private static function prefixed_columns( string $alias ): string {
		return $alias . '.' . str_replace( ', ', ', ' . $alias . '.', self::COLUMNS );
	}

	/** @return array<string,int|bool>|\WP_Error */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Bingo Play Products.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Play Products may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );
		if ( ! $is_super && ! current_user_can( 'bc_manage_products' ) ) {
			return $this->error( 'play_product_management_denied', 'You are not authorized to manage Bingo Play Products for this hall.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.' );
		}

		return [
			'blog_id'        => get_current_blog_id(),
			'actor_user_id'  => $actor_id,
			'actor_is_super' => $is_super,
		];
	}

	/** @return string|\WP_Error */
	private function canonicalize_medium( string $medium ) {
		$medium = strtolower( trim( $medium ) );

		return in_array( $medium, self::MEDIUMS, true )
			? $medium
			: $this->error( 'invalid_medium', 'The Bingo Play Product medium must be Paper or Digital.' );
	}

	/** @return int|\WP_Error */
	private function assert_faces( int $faces_per_unit ) {
		if ( $faces_per_unit < 1 || $faces_per_unit > self::FACES_MAX ) {
			return $this->error( 'invalid_faces_per_unit', 'Faces per unit must be a positive whole number.' );
		}

		return $faces_per_unit;
	}

	/**
	 * WooCommerce-side eligibility. Increment 1 accepts only top-level simple
	 * products; variations, missing products, trash, and auto-drafts are rejected.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_woo_product_eligible( int $wc_product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->error( 'woocommerce_unavailable', 'WooCommerce is not available for this hall.' );
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $this->error( 'woo_product_missing', 'The WooCommerce product was not found in this hall.' );
		}

		if ( $product instanceof \WC_Product_Variation || (int) $product->get_parent_id() > 0 ) {
			return $this->error( 'woo_product_unsupported', 'Product variations cannot be linked as Bingo Play Products.' );
		}

		// wc_get_product() happily returns trashed and auto-draft products, so the
		// status is checked explicitly rather than inferred from the object.
		if ( ! in_array( (string) $product->get_status(), self::WOO_ALLOWED_STATUSES, true ) ) {
			return $this->error( 'woo_product_trashed', 'The WooCommerce product is in the trash or is an unsaved draft.' );
		}

		if ( ! in_array( (string) $product->get_type(), self::WOO_SUPPORTED_TYPES, true ) ) {
			return $this->error( 'woo_product_unsupported', 'Only simple WooCommerce products are supported in this release.' );
		}

		return true;
	}

	/**
	 * Decide whether a Package claim check can run inside the transaction.
	 *
	 * ROLLOUT BRIDGE — RETIRED AT CATALOG v3.
	 *
	 * Play Product Increment 1 shipped a temporary compatibility path: when
	 * bingo_packages did not exist, no Package claim was possible, so creation
	 * proceeded on the Woo product-row lock alone. Catalog v3 creates that table
	 * and verifies it before persisting the version, so from v3 onward an absent
	 * or malformed table is a schema fault, never a reason to skip the claim.
	 *
	 * The absent-table compatibility path is therefore allowed under exactly ONE
	 * condition: the hall has NOT yet reached Catalog v3. Because authorize()
	 * requires Migrations::is_current() before any mutation runs, and
	 * is_current() now means v3, no supported Play Product writer can actually
	 * reach that path any more. It is retained only so a pre-v3 hall degrades to
	 * a refusal rather than a fatal, and it is unreachable in normal operation.
	 *
	 * This closes the deployment-time race the previous revision warned about: a
	 * request that observed an absent table can no longer create a Play Product
	 * link, because it cannot pass authorize() at v3, and it cannot be at v3
	 * while the table is missing.
	 *
	 * The probe runs here, before BEGIN, because SHOW TABLES and SHOW COLUMNS are
	 * not permitted statements inside the ADR-032 boundary.
	 *
	 * @return bool|\WP_Error True to run the claim check, false only for a
	 *                        pre-v3 hall, WP_Error when the table is unusable.
	 */
	private function package_claim_is_checkable() {
		global $wpdb;

		$table = Migrations::packages_table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			if ( Migrations::is_current() ) {
				// Fail closed: at v3 the table is part of the verified contract.
				return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package table is missing from this hall\'s Catalog schema.' );
			}

			return false;
		}

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package table could not be inspected.' );
		}

		$by_name = [];
		foreach ( $columns as $column ) {
			$by_name[ (string) $column['Field'] ] = strtolower( (string) $column['Type'] );
		}

		// ADR-031's minimal authoritative Package signature.
		$required = [
			'package_id'    => '/^(?:big)?int(?:\(\d+\))?(?: unsigned)?$/',
			'wc_product_id' => '/^(?:big)?int(?:\(\d+\))?(?: unsigned)?$/',
			'status'        => '/^varchar\(\d+\)$/',
		];
		foreach ( $required as $column => $pattern ) {
			if ( ! isset( $by_name[ $column ] ) || 1 !== preg_match( $pattern, $by_name[ $column ] ) ) {
				return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package table is not a usable authoritative Package claim source.' );
			}
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== (string) $wpdb->last_error ) {
			return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package table indexes could not be inspected.' );
		}

		$by_index = [];
		foreach ( $indexes as $index ) {
			$key = (string) $index['Key_name'];
			$by_index[ $key ]['unique'] = 0 === (int) $index['Non_unique'];
			$by_index[ $key ]['columns'][ (int) $index['Seq_in_index'] ] = (string) $index['Column_name'];
		}

		foreach ( $by_index as $index ) {
			ksort( $index['columns'] );
			// ADR-031 requires one product to be claimable by at most one Package,
			// so only a single-column UNIQUE index on wc_product_id proves the
			// invariant. A non-unique index would still let two Package rows claim
			// the same product, and a composite unique key would only constrain the
			// combination. The leading-column requirement additionally keeps the
			// in-transaction claim lock a precise row lock rather than a scan.
			if ( $index['unique'] && [ 'wc_product_id' ] === array_values( $index['columns'] ) ) {
				return true;
			}
		}

		return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package table has no unique wc_product_id index.' );
	}

	/**
	 * @param array<int,mixed> $args
	 * @return int|\WP_Error Rows affected.
	 */
	private function conditional_update( string $sql, array $args ) {
		global $wpdb;

		$affected = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return (int) $affected;
	}

	/** @return \WP_Error */
	private function resolve_lost_update( int $bingo_play_product_id ) {
		$current = $this->read_by_id( $bingo_play_product_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		return $this->error( CatalogTransactionTransport::STATE_CHANGED, 'The Bingo Play Product changed while it was being updated. Reload and try again.' );
	}

	/**
	 * Post-commit settlement for a CONFIRMED mutation.
	 *
	 * Callers must resolve a verified no-op (CatalogWriteOutcome::$mutated false)
	 * before reaching this method: it always emits the ordinary ADR-023 audit
	 * event, so passing a no-op here would record a change that never happened.
	 *
	 * @param array<string,int|bool>   $context
	 * @param array<string,mixed>|null $before
	 * @return PlayProductResult|\WP_Error
	 */
	private function settle_direct( int $bingo_play_product_id, string $status, string $action, array $context, ?array $before ) {
		$after  = $this->read_by_id( $bingo_play_product_id );
		$result = new PlayProductResult(
			$status,
			is_wp_error( $after ) ? ( $before ?? [ 'bingo_play_product_id' => $bingo_play_product_id ] ) : $after
		);

		if ( is_wp_error( $after ) ) {
			// The mutation is durably committed; only the confirming read failed.
			$result->warnings[] = [
				'code'    => 'state_read_failed',
				'message' => $after->get_error_message(),
			];
		}

		return $this->record_audit(
			$result,
			$action,
			$context,
			$before,
			is_wp_error( $after ) ? null : $after,
			$bingo_play_product_id
		);
	}

	/**
	 * @param array<string,int|bool>   $context
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 */
	private function record_audit(
		PlayProductResult $result,
		string $action,
		array $context,
		?array $before,
		?array $after,
		int $bingo_play_product_id
	): PlayProductResult {
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => $action,
			'target_type'    => 'bingo_play_product',
			'target_id'      => $bingo_play_product_id,
			'previous_value' => null === $before ? null : $this->audit_payload( $before ),
			'new_value'      => null === $after ? null : $this->audit_payload( $after ),
			'context'        => [
				'bingo_play_product_id' => $bingo_play_product_id,
				'wc_product_id'         => (int) ( ( $after ?? $before )['wc_product_id'] ?? 0 ),
			],
		];

		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$error_code = $audit_result->get_error_code();
			error_log( sprintf( 'BingoCommerce Catalog audit failure for %s on Bingo Play Product %d: %s', $action, $bingo_play_product_id, $error_code ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $error_code,
				'message'    => $audit_result->get_error_message(),
			];
		} elseif ( ! is_int( $audit_result ) || $audit_result < 1 ) {
			error_log( sprintf( 'BingoCommerce Catalog audit returned an invalid result for %s on Bingo Play Product %d.', $action, $bingo_play_product_id ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => 'invalid_audit_result',
				'message'    => 'The audit service returned an invalid result.',
			];
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function audit_payload( array $row ): array {
		return [
			'bingo_play_product_id' => (int) $row['bingo_play_product_id'],
			'wc_product_id'         => (int) $row['wc_product_id'],
			'medium'                => $row['medium'],
			'faces_per_unit'        => (int) $row['faces_per_unit'],
			'game_type_id'          => null === $row['game_type_id'] ? null : (int) $row['game_type_id'],
			'status'                => $row['status'],
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	private function require_row( int $bingo_play_product_id ) {
		if ( $bingo_play_product_id < 1 ) {
			return $this->error( 'invalid_play_product_id', 'The Bingo Play Product ID is invalid.' );
		}

		return $this->read_by_id( $bingo_play_product_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_by_id( int $bingo_play_product_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::play_products_table() . ' WHERE bingo_play_product_id = %d',
				$bingo_play_product_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		return $this->map_row( $row );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function map_row( array $row ): array {
		$row['bingo_play_product_id'] = (int) $row['bingo_play_product_id'];
		$row['wc_product_id']         = (int) $row['wc_product_id'];
		$row['faces_per_unit']        = (int) $row['faces_per_unit'];
		$row['game_type_id']          = null === $row['game_type_id'] ? null : (int) $row['game_type_id'];
		$row['woo_state']             = $this->woo_state( $row['wc_product_id'] );

		return $row;
	}

	/**
	 * Report the linked product's current usability without ever mutating the
	 * authoritative row. A broken link is surfaced, never silently repaired.
	 */
	private function woo_state( int $wc_product_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'unavailable';
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof \WC_Product ) {
			return 'missing';
		}
		if ( ! in_array( (string) $product->get_status(), self::WOO_ALLOWED_STATUSES, true ) ) {
			return 'trashed';
		}
		if (
			$product instanceof \WC_Product_Variation
			|| (int) $product->get_parent_id() > 0
			|| ! in_array( (string) $product->get_type(), self::WOO_SUPPORTED_TYPES, true )
		) {
			return 'unsupported_type';
		}

		return 'ok';
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Bingo Play Product change could not be persisted.', 'bingocommerce-catalog' ),
			[ 'db_error' => $db_error ]
		);
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ), [ 'status' => $code ] );
	}
}
