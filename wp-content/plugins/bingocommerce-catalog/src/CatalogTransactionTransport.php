<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Catalog critical-serialization boundaries (ADR-032, ADR-033, ADR-034).
 *
 * Scope is deliberately tiny. Only four invariants justify this transport:
 *
 *   1. ADR-032 — WooCommerce-product exclusivity between bingo_play_products
 *      and bingo_packages, anchored on the current site's posts row;
 *   2. ADR-032 — Bingo Play Product -> Game Type reference integrity, anchored
 *      on the Game Type row that a future hard-delete writer will lock;
 *   3. ADR-033 — Bingo Package composition replacement, which must be
 *      all-or-nothing across bingo_package_components and the derived BR-061
 *      package_type on bingo_packages; and
 *   4. ADR-034 — Bingo Play Product medium reference integrity, where BR-069
 *      requires the Package-component reference check and the medium write to
 *      happen under one held bingo_play_products row lock.
 *
 * ADR-032 and ADR-033 are DISTINCT boundaries with distinct lock orders and
 * distinct statement sequences. They share this class only for the $active and
 * $untrusted guards, so neither can nest inside the other and a session poisoned
 * by one is poisoned for both. They are not one contract.
 *
 * Everything else — archive, faces-per-unit, clearing a Game Type — is a single
 * conditional statement through ordinary $wpdb and never enters this class. This
 * is NOT a general Catalog transaction mechanism.
 *
 * It accepts no callable. Each public entry point owns one fixed, reviewed
 * statement sequence and interprets an immutable plan object, so the complete
 * set of statements that can run between BEGIN and COMMIT/ROLLBACK is
 * enumerable here:
 *
 *   - BEGIN, COMMIT, ROLLBACK;
 *   - SELECT ... FOR UPDATE NOWAIT against the posts row, bingo_play_products,
 *     bingo_game_types, bingo_packages, and — for ADR-034's reference check
 *     only — bingo_package_components;
 *   - one PLAIN, unlocked SELECT of the current composition from
 *     bingo_package_components (ADR-033 step 2; see read_package_components()
 *     for the first-consistent-read invariant that keeps it correct);
 *   - INSERT and UPDATE against bingo_play_products;
 *   - narrow INSERT, single-row UPDATE, and DELETE against
 *     bingo_package_components;
 *   - UPDATE against bingo_packages.
 *
 * ADR-033 takes NO lock on bingo_package_components, so there is no
 * package_components -> play_products lock rank.
 *
 * Never inside the boundary: WooCommerce CRUD, WordPress or WooCommerce
 * metadata writes, BR-032/BR-065 projections, AuditLog, hooks, callbacks,
 * translation, emails, caches, UI effects, or logging calls.
 *
 * This is not ADR-025. There is no operation_id, no commit witness, no retry
 * token, no mandatory-audit atomicity, and no reconciliation protocol.
 */
final class CatalogTransactionTransport {

	public const COMMIT_OUTCOME_UNKNOWN = 'catalog_commit_outcome_unknown';
	public const WRITE_CONFLICT         = 'catalog_write_conflict';
	public const NESTED_USE             = 'catalog_transport_nested_use';
	public const STATE_UNTRUSTED        = 'catalog_transaction_state_untrusted';
	public const SCHEMA_INCONSISTENT    = 'catalog_schema_inconsistent';
	public const STATE_CHANGED          = 'catalog_current_state_changed';
	public const PACKAGE_REFERENCED     = 'play_product_package_referenced';
	public const COMPOSITION_STALE      = 'package_composition_stale';

	/** Implementation constant, not a business rule. */
	private const RETRY_ATTEMPTS = 3;

	/** Microsecond base backoff between definite lock-conflict retries. */
	private const RETRY_BACKOFF_US = [ 20000, 60000 ];

	/**
	 * Lock-wait timeout, deadlock, and NOWAIT-unavailable. All three are raised
	 * before COMMIT is issued, so the mutation is known not to have committed
	 * and a bounded retry is safe.
	 */
	private const LOCK_CONFLICT_ERRNOS = [ 1205, 1213, 3572 ];

	private const WOO_REJECTED_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * Bounded by the bingo_package_components.quantity column (int unsigned), so
	 * an out-of-range value is rejected deterministically here and never reaches
	 * MySQL to be truncated or to raise an out-of-range error mid-transaction.
	 * This is a storage bound, not a business maximum.
	 */
	private const COMPONENT_QUANTITY_MAX = 4294967295;

	/** The only values bingo_play_products.medium may hold (BR-039). */
	private const VALID_MEDIUMS = [ 'paper', 'digital' ];

	/** The only values bingo_play_products.status may hold. */
	private const VALID_PLAY_PRODUCT_STATUSES = [ 'active', 'archived' ];

	/**
	 * The only characters a derived Catalog table identifier may contain. This is
	 * the WordPress/BingoCommerce naming contract ($wpdb->prefix plus a class
	 * constant), not a general-purpose identifier grammar.
	 */
	private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

	/** Guards against supported nested entry within one PHP request. */
	private static bool $active = false;

	/**
	 * Set when a COMMIT acknowledgement was not clean, or a ROLLBACK failed.
	 * The captured session can no longer be trusted to carry another boundary
	 * in this request, so every later entry fails closed.
	 */
	private static bool $untrusted = false;

	private \mysqli $connection;
	private string $posts_table;
	private string $play_products_table;
	private string $game_types_table;
	private string $packages_table;
	private string $package_components_table;

	/** ADR-032 lock order 1 — Woo row, Game Type row, claim checks, insert. */
	public static function createWithProductClaim( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::CREATE );
	}

	/** ADR-032 lock order 3 — Play Product row, Game Type row, write. */
	public static function assignGameTypeReference( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::ASSIGN_GAME_TYPE );
	}

	/** ADR-032 lock order 3 — Play Product row, Game Type row, write. */
	public static function transitionToDigital( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::PAPER_TO_DIGITAL );
	}

	/**
	 * ADR-034 — Play Product row, Package-component reference check, write.
	 *
	 * Digital -> Paper previously executed as one ordinary $wpdb conditional
	 * statement. BR-069 requires the reference check and the write to occur under
	 * one held row lock, so the ACTUAL transition now runs here.
	 */
	public static function transitionToPaper( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::DIGITAL_TO_PAPER );
	}

	/** ADR-032 lock order 2 — Woo row, Play Product row, Game Type row, write. */
	public static function restoreWithWooRevalidation( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::RESTORE );
	}

	/**
	 * ADR-033 — Package composition replacement, all-or-nothing.
	 *
	 * A DISTINCT boundary from ADR-032, sharing this class's $active and
	 * $untrusted guards so neither boundary can nest inside the other and a
	 * session poisoned by one is poisoned for both.
	 *
	 * NOTE FOR THE NEXT SLICE: no public PackageService composition writer exists
	 * yet, so nothing in wp-admin or REST can reach this entry point. It is the
	 * transaction machinery only.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	public static function replacePackageComposition( CatalogPackageCompositionPlan $plan ) {
		if ( CatalogPackageCompositionPlan::REPLACE_COMPOSITION !== $plan->mutation ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan does not match the requested boundary.' );
		}

		$shape = self::assert_composition_plan_shape( $plan );
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}

		if ( self::$untrusted ) {
			return self::error( self::STATE_UNTRUSTED, 'The Catalog database session can no longer be trusted for a critical transaction.' );
		}

		if ( self::$active ) {
			return self::error( self::NESTED_USE, 'A Catalog critical transaction is already in progress.' );
		}

		$transport = new self();
		$captured  = $transport->capture( $plan->blog_id );
		if ( is_wp_error( $captured ) ) {
			return $captured;
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$outcome = $transport->attempt_composition( $plan );

			if ( ! is_wp_error( $outcome ) ) {
				return $outcome;
			}

			if ( self::$untrusted || ! self::is_lock_conflict( $outcome ) ) {
				return $outcome;
			}

			if ( $attempt >= self::RETRY_ATTEMPTS ) {
				return self::error(
					self::WRITE_CONFLICT,
					'The Catalog change could not be serialized against concurrent activity. Reload and try again.',
					[ 'attempts' => $attempt, 'db_errno' => $outcome->get_error_data()['db_errno'] ?? 0 ]
				);
			}

			$base = self::RETRY_BACKOFF_US[ min( $attempt - 1, count( self::RETRY_BACKOFF_US ) - 1 ) ];
			usleep( (int) ( $base * ( 0.5 + ( random_int( 0, 1000 ) / 1000 ) ) ) );
		}
	}

	/**
	 * Reject a structurally malformed composition plan BEFORE BEGIN.
	 *
	 * This is plan SHAPE only — the values the caller assembled — never
	 * authoritative state. Package existence, lifecycle, the stale-composition
	 * fingerprint, duplicate components, Play Product existence and the archived
	 * rules all remain inside the transaction under their locks, where they
	 * belong.
	 *
	 * It exists because a missing or non-integer inner-array field would
	 * otherwise raise a PHP warning while the transaction is open, invoking the
	 * error handler inside a boundary that must stay callback-free, and would
	 * then coerce a nonsensical value into a bound parameter. Failing closed here
	 * costs nothing and keeps the enumerable statement set honest.
	 *
	 * This is NOT a generic validator or a callback API: it accepts no callable,
	 * inspects one immutable plan object, and is used by this entry point alone.
	 *
	 * @return true|\WP_Error
	 */
	private static function assert_composition_plan_shape( CatalogPackageCompositionPlan $plan ) {
		if ( $plan->blog_id < 1 ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan carries no hall context.' );
		}

		if ( $plan->package_id < 1 ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan carries no Bingo Package identity.' );
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $plan->now ) ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan carries an unusable timestamp.' );
		}

		if ( ! array_is_list( $plan->components ) ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Bingo Package composition must be a plain list of components.' );
		}

		foreach ( $plan->components as $component ) {
			if ( ! is_array( $component ) ) {
				return self::error( 'catalog_transport_plan_mismatch', 'A Bingo Package composition entry is not a component.' );
			}
			if ( ! array_key_exists( 'bingo_play_product_id', $component ) || ! array_key_exists( 'quantity', $component ) ) {
				return self::error( 'catalog_transport_plan_mismatch', 'A Bingo Package composition entry is missing a required field.' );
			}
			// Actual integers. The caller owns the cast; this boundary does not
			// coerce, because a coerced value would silently become a different
			// component or a different quantity.
			if ( ! is_int( $component['bingo_play_product_id'] ) || ! is_int( $component['quantity'] ) ) {
				return self::error( 'catalog_transport_plan_mismatch', 'A Bingo Package composition entry carries a non-integer field.' );
			}
			if ( $component['bingo_play_product_id'] < 1 ) {
				return self::error( 'catalog_transport_plan_mismatch', 'A Bingo Package composition entry references an invalid Bingo Play Product.' );
			}
			if ( $component['quantity'] < 1 || $component['quantity'] > self::COMPONENT_QUANTITY_MAX ) {
				return self::error(
					'invalid_component_quantity',
					'A Bingo Package component quantity must be a whole number between 1 and 4294967295.',
					[ 'bingo_play_product_id' => $component['bingo_play_product_id'] ]
				);
			}
		}

		return true;
	}

	/**
	 * The canonical composition fingerprint (ADR-033 stale-edit detection).
	 *
	 * Computed identically when an edit form is rendered and again inside the
	 * transaction before any write. Canonicalized by ascending
	 * bingo_play_product_id, taking only that ID and quantity as base-10
	 * integers, so an administrative reorder or a row rewrite preserving the same
	 * components and quantities does not invalidate a pending edit. The result is
	 * opaque: compare for equality only, never parse or order it.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	public static function compositionFingerprint( array $rows ): string {
		$canonical = [];
		foreach ( $rows as $row ) {
			$canonical[ (int) $row['bingo_play_product_id'] ] = (int) $row['quantity'];
		}
		ksort( $canonical, SORT_NUMERIC );

		$serialized = '';
		foreach ( $canonical as $play_product_id => $quantity ) {
			$serialized .= $play_product_id . ':' . $quantity . ';';
		}

		return hash( 'sha256', $serialized );
	}

	/**
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private static function run( CatalogPlayProductPlan $plan, string $expected_mutation ) {
		if ( $plan->mutation !== $expected_mutation ) {
			return self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan does not match the requested boundary.' );
		}

		if ( self::$untrusted ) {
			return self::error( self::STATE_UNTRUSTED, 'The Catalog database session can no longer be trusted for a critical transaction.' );
		}

		if ( self::$active ) {
			return self::error( self::NESTED_USE, 'A Catalog critical transaction is already in progress.' );
		}

		$transport = new self();
		$captured  = $transport->capture( $plan->blog_id );
		if ( is_wp_error( $captured ) ) {
			return $captured;
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$outcome = $transport->attempt( $plan );

			if ( ! is_wp_error( $outcome ) ) {
				return $outcome;
			}

			// A poisoned session must never carry another BEGIN, whatever the
			// error looked like.
			if ( self::$untrusted || ! self::is_lock_conflict( $outcome ) ) {
				return $outcome;
			}

			if ( $attempt >= self::RETRY_ATTEMPTS ) {
				return self::error(
					self::WRITE_CONFLICT,
					'The Catalog change could not be serialized against concurrent activity. Reload and try again.',
					[ 'attempts' => $attempt, 'db_errno' => $outcome->get_error_data()['db_errno'] ?? 0 ]
				);
			}

			$base = self::RETRY_BACKOFF_US[ min( $attempt - 1, count( self::RETRY_BACKOFF_US ) - 1 ) ];
			usleep( (int) ( $base * ( 0.5 + ( random_int( 0, 1000 ) / 1000 ) ) ) );
		}
	}

	/** @return CatalogWriteOutcome|\WP_Error */
	private function attempt( CatalogPlayProductPlan $plan ) {
		$begun = $this->begin();
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}

		self::$active = true;

		try {
			switch ( $plan->mutation ) {
				case CatalogPlayProductPlan::CREATE:
					$outcome = $this->create_sequence( $plan );
					break;
				case CatalogPlayProductPlan::ASSIGN_GAME_TYPE:
					$outcome = $this->assign_game_type_sequence( $plan );
					break;
				case CatalogPlayProductPlan::PAPER_TO_DIGITAL:
					$outcome = $this->to_digital_sequence( $plan );
					break;
				case CatalogPlayProductPlan::DIGITAL_TO_PAPER:
					$outcome = $this->to_paper_sequence( $plan );
					break;
				case CatalogPlayProductPlan::RESTORE:
					$outcome = $this->restore_sequence( $plan );
					break;
				default:
					$outcome = self::error( 'catalog_transport_plan_mismatch', 'The Catalog write plan is not supported.' );
			}

			if ( is_wp_error( $outcome ) ) {
				// COMMIT was never issued, so nothing can be committed. A lock
				// timeout or NOWAIT rejection rolls back only the statement, so
				// the explicit ROLLBACK matters; after a deadlock it is a no-op.
				if ( ! $this->rollback() ) {
					/*
					 * The session could not be returned to a known state. The
					 * original error may look like a definite lock conflict, but
					 * retrying would issue BEGIN on a session whose transaction is
					 * still open, so the lock-conflict shape MUST NOT survive here.
					 * Replacing it with the untrusted code is what stops run()
					 * from retrying, since that code carries no db_errno.
					 */
					self::$untrusted = true;

					return self::error(
						self::STATE_UNTRUSTED,
						'The Catalog transaction could not be rolled back, so the database session is no longer trustworthy.',
						[ 'original_code' => $outcome->get_error_code() ]
					);
				}

				return $outcome;
			}

			if ( true !== $this->commit() ) {
				/*
				 * ADR-032: once COMMIT has been issued and its acknowledgement is
				 * not clean, the outcome is unknown and stays unknown. No
				 * performance_schema probe, no transaction-state inspection, no
				 * Catalog read, no retry, and no audit event may follow. Current
				 * state could have been produced by a different operation and is
				 * not proof about this one.
				 */
				self::$untrusted = true;
				$this->rollback_quietly();

				return self::error(
					self::COMMIT_OUTCOME_UNKNOWN,
					'The Catalog change was submitted but its result could not be confirmed. Reload this screen and check the current state before retrying.'
				);
			}

			return $outcome;
		} finally {
			self::$active = false;
		}
	}

	/**
	 * ADR-033's transaction attempt. Shares begin/commit/rollback, the $active
	 * guard, and the $untrusted poison flag with the ADR-032 attempt loop.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function attempt_composition( CatalogPackageCompositionPlan $plan ) {
		$begun = $this->begin();
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}

		self::$active = true;

		try {
			$outcome = $this->replace_composition_sequence( $plan );

			if ( is_wp_error( $outcome ) ) {
				if ( ! $this->rollback() ) {
					self::$untrusted = true;

					return self::error(
						self::STATE_UNTRUSTED,
						'The Catalog transaction could not be rolled back, so the database session is no longer trustworthy.',
						[ 'original_code' => $outcome->get_error_code() ]
					);
				}

				return $outcome;
			}

			if ( true !== $this->commit() ) {
				self::$untrusted = true;
				$this->rollback_quietly();

				return self::error(
					self::COMMIT_OUTCOME_UNKNOWN,
					'The Catalog change was submitted but its result could not be confirmed. Reload this screen and check the current state before retrying.'
				);
			}

			return $outcome;
		} finally {
			self::$active = false;
		}
	}

	/**
	 * ADR-033 sequence: lock the parent Package row -> plain first-consistent
	 * read of the current composition -> fingerprint -> dependency union
	 * (current UNION requested) -> lock those Play Product rows ascending ->
	 * verify EVERY dependency exists -> validate the locked rows' medium/status
	 * -> validate the requested components -> component DML -> package_type.
	 *
	 * The parent bingo_packages row is the serialization anchor for conforming
	 * composition writers. bingo_package_components is NEVER locked here, and
	 * therefore no package_components -> play_products lock rank exists.
	 *
	 * Every read inside is re-executed on each retry attempt; nothing is cached
	 * across attempts, so the stale-composition fingerprint is always evaluated
	 * against state read after the fresh BEGIN.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function replace_composition_sequence( CatalogPackageCompositionPlan $plan ) {
		$package = $this->lock_package_row( $plan->package_id );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( [] === $package ) {
			return self::error( 'package_not_found', 'The Bingo Package was not found.' );
		}
		if ( 'archived' !== $package['status'] && 'active' !== $package['status'] ) {
			return self::error( self::STATE_CHANGED, 'The Bingo Package is in an unexpected lifecycle state.' );
		}
		if ( 'archived' === $package['status'] ) {
			// BR-068: composition mutation is rejected while archived.
			return self::error( 'package_archived', 'Restore the Bingo Package before editing its composition.' );
		}

		$current = $this->read_package_components( $plan->package_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( self::compositionFingerprint( $current ) !== $plan->expected_fingerprint ) {
			return self::error( self::COMPOSITION_STALE, 'The Bingo Package composition changed while it was being edited. Reload and try again.' );
		}

		$requested = [];
		foreach ( $plan->components as $component ) {
			$id = (int) $component['bingo_play_product_id'];
			if ( isset( $requested[ $id ] ) ) {
				// BR-067: quantities are never silently merged.
				return self::error( 'duplicate_component', 'A Bingo Play Product may appear only once in a Package composition.' );
			}

			$quantity = (int) $component['quantity'];
			if ( $quantity < 1 || $quantity > self::COMPONENT_QUANTITY_MAX ) {
				// Defence in depth. assert_composition_plan_shape() already rejected
				// this before BEGIN; repeating it here keeps the invariant local to
				// the sequence that depends on it, and an out-of-range value can
				// never reach MySQL to be truncated or to raise a mid-transaction
				// error.
				return self::error(
					'invalid_component_quantity',
					'A Bingo Package component quantity must be a whole number between 1 and 4294967295.',
					[ 'bingo_play_product_id' => $id ]
				);
			}

			// sort_order is deliberately NOT accepted from the plan. Existing rows
			// keep the order they already have; new rows start at 0. No reorder
			// surface exists in Increment 1.
			$requested[ $id ] = $quantity;
		}

		if ( [] === $requested ) {
			// BR-067: a Package may never persist as an active zero-component object.
			return self::error( 'package_requires_component', 'A Bingo Package must contain at least one Bingo Play Product.' );
		}

		$existing = [];
		foreach ( $current as $row ) {
			$existing[ (int) $row['bingo_play_product_id'] ] = [
				'package_component_id' => (int) $row['package_component_id'],
				'quantity'             => (int) $row['quantity'],
			];
		}

		// Dependency set is the UNION of requested and currently referenced IDs:
		// a row being removed must also be held still for the mutation's duration.
		$dependency = array_values( array_unique( array_merge( array_keys( $requested ), array_keys( $existing ) ) ) );
		sort( $dependency, SORT_NUMERIC );

		$play_products = $this->lock_play_product_rows( $dependency );
		if ( is_wp_error( $play_products ) ) {
			return $play_products;
		}

		// EVERY dependency must resolve to an authoritative row — the currently
		// referenced ones as well as the requested ones. A current component
		// pointing at a Play Product row that no longer exists is a Catalog
		// integrity fault, and it is NOT silently repaired just because this edit
		// happens to remove it: doing so would let a corrupt reference disappear
		// without anyone deciding that it should, and would derive package_type
		// from an incomplete picture of what the Package actually contained.
		foreach ( $dependency as $id ) {
			if ( ! isset( $play_products[ $id ] ) ) {
				return self::error(
					'play_product_not_found',
					'A Bingo Play Product this Package depends on was not found in this hall.',
					[ 'bingo_play_product_id' => (int) $id ]
				);
			}
		}

		// Fail closed on corrupt authoritative state BEFORE any component DML.
		// An unexpected medium or status is a Catalog integrity fault, not a
		// value to coerce: treating "not digital" as Paper or "not active" as
		// archived would silently derive a wrong BR-061 package_type or admit a
		// row whose real lifecycle is unknown.
		foreach ( $play_products as $id => $row ) {
			if ( ! in_array( (string) $row['medium'], self::VALID_MEDIUMS, true ) ) {
				return self::error(
					self::SCHEMA_INCONSISTENT,
					'A referenced Bingo Play Product holds an unrecognised medium value.',
					[ 'bingo_play_product_id' => (int) $id ]
				);
			}
			if ( ! in_array( (string) $row['status'], self::VALID_PLAY_PRODUCT_STATUSES, true ) ) {
				return self::error(
					self::SCHEMA_INCONSISTENT,
					'A referenced Bingo Play Product holds an unrecognised status value.',
					[ 'bingo_play_product_id' => (int) $id ]
				);
			}
		}

		foreach ( $requested as $id => $quantity ) {
			if ( ! isset( $play_products[ $id ] ) ) {
				return self::error( 'play_product_not_found', 'A referenced Bingo Play Product was not found in this hall.' );
			}

			$was_existing = array_key_exists( $id, $existing );
			$is_archived  = 'archived' === $play_products[ $id ]['status'];

			if ( $is_archived && ! $was_existing ) {
				// BR-067: a new reference must be active.
				return self::error( 'play_product_not_active', 'An archived Bingo Play Product cannot be added to a Package.' );
			}
			if ( $is_archived && $was_existing && $existing[ $id ]['quantity'] !== $quantity ) {
				// ADR-033: retention is preservation; changing how much of an
				// inactive product a Package contains is a new authoring decision.
				return self::error( 'play_product_not_active', 'The quantity of an archived Bingo Play Product cannot be changed.' );
			}
		}

		// Mutation-aware DML. An exact identical component ID + quantity set
		// performs NO component statement at all, so package_component_id,
		// created_at, updated_at and sort_order all survive untouched and the
		// caller can recognise a verified no-op and skip its audit event.
		$removed = array_values( array_diff( array_keys( $existing ), array_keys( $requested ) ) );
		$added   = array_values( array_diff( array_keys( $requested ), array_keys( $existing ) ) );
		$changed = [];
		foreach ( $requested as $id => $quantity ) {
			if ( array_key_exists( $id, $existing ) && $existing[ $id ]['quantity'] !== $quantity ) {
				$changed[ $id ] = $quantity;
			}
		}

		if ( [] !== $removed ) {
			sort( $removed, SORT_NUMERIC );
			$placeholders = implode( ',', array_fill( 0, count( $removed ), '?' ) );
			$params       = array_merge( [ $plan->package_id ], $removed );
			$deleted      = $this->execute_write(
				"DELETE FROM {$this->package_components_table}"
				. " WHERE package_id = ? AND bingo_play_product_id IN ({$placeholders})",
				str_repeat( 'i', count( $params ) ),
				$params
			);
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		sort( $added, SORT_NUMERIC );
		foreach ( $added as $id ) {
			$inserted = $this->execute_write(
				"INSERT INTO {$this->package_components_table}"
				. ' (package_id, bingo_play_product_id, quantity, sort_order, created_at, updated_at)'
				. ' VALUES (?, ?, ?, 0, ?, ?)',
				'iiiss',
				[ $plan->package_id, $id, $requested[ $id ], $plan->now, $plan->now ]
			);
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
		}

		ksort( $changed, SORT_NUMERIC );
		foreach ( $changed as $id => $quantity ) {
			// Narrow: quantity and updated_at only. sort_order is never rewritten.
			$updated = $this->execute_write(
				"UPDATE {$this->package_components_table} SET quantity = ?, updated_at = ?"
				. ' WHERE package_component_id = ?',
				'isi',
				[ $quantity, $plan->now, $existing[ $id ]['package_component_id'] ]
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$components_changed = [] !== $removed || [] !== $added || [] !== $changed;

		// BR-061. Derived ONLY from the FINAL REQUESTED set, using medium values
		// read under the locks acquired above. A component being removed is locked
		// but contributes nothing.
		$has_paper   = false;
		$has_digital = false;
		foreach ( array_keys( $requested ) as $id ) {
			if ( 'digital' === $play_products[ $id ]['medium'] ) {
				$has_digital = true;
			} else {
				$has_paper = true;
			}
		}
		$package_type = $has_paper && $has_digital ? 'hybrid' : ( $has_digital ? 'digital' : 'paper' );

		// The parent row is written only when the composition actually changed, or
		// when the stored type no longer matches what the composition derives. An
		// exact no-op leaves bingo_packages.updated_at alone.
		$type_write_needed = $components_changed || (string) $package['package_type'] !== $package_type;
		if ( $type_write_needed ) {
			$typed = $this->execute_write(
				"UPDATE {$this->packages_table} SET package_type = ?, updated_at = ? WHERE package_id = ?",
				'ssi',
				[ $package_type, $plan->now, $plan->package_id ],
				// The row is held under this transaction's lock, so it certainly
				// exists; 0 affected rows here means only that package_type and
				// updated_at already held these exact values.
				true
			);
			if ( is_wp_error( $typed ) ) {
				return $typed;
			}
		}

		return new CatalogWriteOutcome(
			$plan->package_id,
			$type_write_needed,
			[ 'components' => $current, 'package' => $package, 'package_type' => $package_type ]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fixed statement sequences (ADR-032 canonical lock order)
	 * ------------------------------------------------------------------ */

	/**
	 * Lock order 1: Woo product row -> target Game Type row -> authoritative
	 * claim checks -> insert.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function create_sequence( CatalogPlayProductPlan $plan ) {
		$post = $this->lock_posts_row( $plan->wc_product_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$eligible = self::assert_woo_row_eligible( $post );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		if ( null !== $plan->target_game_type_id ) {
			$game_type = $this->lock_active_game_type_row( $plan->target_game_type_id );
			if ( is_wp_error( $game_type ) ) {
				return $game_type;
			}
		}

		$claimed = $this->lock_play_product_by_wc_product( $plan->wc_product_id );
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}
		if ( [] !== $claimed ) {
			return self::error( 'play_product_already_linked', 'That WooCommerce product is already a Bingo Play Product.' );
		}

		if ( $plan->check_package_claim ) {
			$package = $this->lock_package_claim( $plan->wc_product_id );
			if ( is_wp_error( $package ) ) {
				return $package;
			}
			if ( [] !== $package ) {
				return self::error( 'package_claim_exists', 'That WooCommerce product is already linked to a Bingo Package.' );
			}
		}

		$inserted = $this->execute_write(
			"INSERT INTO {$this->play_products_table}"
			. ' (wc_product_id, medium, faces_per_unit, game_type_id, status, created_at, updated_at, archived_at)'
			. " VALUES (?, ?, ?, ?, 'active', ?, ?, NULL)",
			'isiiss',
			[ $plan->wc_product_id, $plan->medium, $plan->faces_per_unit, $plan->target_game_type_id, $plan->now, $plan->now ]
		);
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		$new_id = (int) $this->connection->insert_id;
		if ( $new_id < 1 ) {
			return self::error( 'persistence_failed', 'The Bingo Play Product could not be created.' );
		}

		return new CatalogWriteOutcome( $new_id, true, null );
	}

	/**
	 * Lock order 3: Play Product row -> target Game Type row -> write.
	 *
	 * The Game Type row lock is the point of this boundary: a future Game Type
	 * hard-delete writer locks the same row, so it can never delete a Game Type
	 * while a reference to it is being created here.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function assign_game_type_sequence( CatalogPlayProductPlan $plan ) {
		$row = $this->lock_play_product_row( $plan->bingo_play_product_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( [] === $row ) {
			return self::error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		$current = null === $row['game_type_id'] ? null : (int) $row['game_type_id'];
		if ( $current === $plan->target_game_type_id ) {
			// Authoritative no-op. Deliberately does NOT re-check the existing
			// reference's status: an assignment that changes nothing must not be
			// rejected merely because that Game Type was archived afterwards.
			return new CatalogWriteOutcome( $plan->bingo_play_product_id, false, $row );
		}

		$game_type = $this->lock_active_game_type_row( (int) $plan->target_game_type_id );
		if ( is_wp_error( $game_type ) ) {
			return $game_type;
		}

		$written = $this->execute_write(
			"UPDATE {$this->play_products_table} SET game_type_id = ?, updated_at = ? WHERE bingo_play_product_id = ?",
			'isi',
			[ $plan->target_game_type_id, $plan->now, $plan->bingo_play_product_id ]
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return new CatalogWriteOutcome( $plan->bingo_play_product_id, true, $row );
	}

	/**
	 * Lock order 3: Play Product row -> target Game Type row -> write.
	 *
	 * `medium` and `game_type_id` move in ONE statement after the Game Type has
	 * been verified active under its row lock, so a persisted
	 * `medium = digital AND game_type_id IS NULL` is unreachable.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function to_digital_sequence( CatalogPlayProductPlan $plan ) {
		$row = $this->lock_play_product_row( $plan->bingo_play_product_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( [] === $row ) {
			return self::error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		$current = null === $row['game_type_id'] ? null : (int) $row['game_type_id'];
		if ( 'digital' === $row['medium'] && $current === $plan->target_game_type_id ) {
			return new CatalogWriteOutcome( $plan->bingo_play_product_id, false, $row );
		}

		$game_type = $this->lock_active_game_type_row( (int) $plan->target_game_type_id );
		if ( is_wp_error( $game_type ) ) {
			return $game_type;
		}

		// BR-069 / ADR-034 applies ONLY to an actual Paper <-> Digital transition.
		// A row that is already Digital and is merely being pointed at a different
		// Game Type is a permitted mutation on a Package-referenced Play Product,
		// so the reference check is skipped for it.
		if ( 'digital' !== $row['medium'] ) {
			$referenced = $this->assert_not_package_referenced( $plan->bingo_play_product_id );
			if ( is_wp_error( $referenced ) ) {
				return $referenced;
			}
		}

		$written = $this->execute_write(
			"UPDATE {$this->play_products_table} SET medium = 'digital', game_type_id = ?, updated_at = ? WHERE bingo_play_product_id = ?",
			'isi',
			[ $plan->target_game_type_id, $plan->now, $plan->bingo_play_product_id ]
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return new CatalogWriteOutcome( $plan->bingo_play_product_id, true, $row );
	}

	/**
	 * ADR-034 lock order: Play Product row -> Package-component references -> write.
	 *
	 * `game_type_id` is deliberately absent from the SET list. BR-039 preserves
	 * the existing Game Type reference when a Digital product becomes Paper; a
	 * Hall Manager clears it later, explicitly.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function to_paper_sequence( CatalogPlayProductPlan $plan ) {
		$row = $this->lock_play_product_row( $plan->bingo_play_product_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( [] === $row ) {
			return self::error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		if ( 'paper' === $row['medium'] ) {
			// Verified no-op under lock. BR-069 blocks an ACTUAL transition, so a
			// Package-referenced row that is already Paper is never rejected here.
			return new CatalogWriteOutcome( $plan->bingo_play_product_id, false, $row );
		}

		$referenced = $this->assert_not_package_referenced( $plan->bingo_play_product_id );
		if ( is_wp_error( $referenced ) ) {
			return $referenced;
		}

		$written = $this->execute_write(
			"UPDATE {$this->play_products_table} SET medium = 'paper', updated_at = ?"
			. ' WHERE bingo_play_product_id = ? AND medium = ?',
			'sis',
			[ $plan->now, $plan->bingo_play_product_id, 'digital' ]
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return new CatalogWriteOutcome( $plan->bingo_play_product_id, true, $row );
	}

	/**
	 * Lock order 2: Woo product row -> Play Product row -> referenced Game Type
	 * row -> write.
	 *
	 * The Woo row is acquired FIRST and unconditionally. The service only routes
	 * an apparently-archived row here; if the row turns out to be active under
	 * the lock, this returns the no-op rather than reaching back for a
	 * lower-ranked lock.
	 *
	 * @return CatalogWriteOutcome|\WP_Error
	 */
	private function restore_sequence( CatalogPlayProductPlan $plan ) {
		$post = $this->lock_posts_row( $plan->wc_product_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$eligible = self::assert_woo_row_eligible( $post );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$row = $this->lock_play_product_row( $plan->bingo_play_product_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( [] === $row ) {
			return self::error( 'play_product_not_found', 'The Bingo Play Product was not found.' );
		}

		if ( (int) $row['wc_product_id'] !== $plan->wc_product_id ) {
			// The linked product is immutable, so this can only mean the plan was
			// built from a stale read. Fail rather than validate the wrong row.
			return self::error( self::STATE_CHANGED, 'The Bingo Play Product changed while it was being restored. Reload and try again.' );
		}

		if ( 'active' === $row['status'] ) {
			return new CatalogWriteOutcome( $plan->bingo_play_product_id, false, $row );
		}

		if ( null !== $row['game_type_id'] ) {
			$game_type = $this->lock_game_type_row( (int) $row['game_type_id'] );
			if ( is_wp_error( $game_type ) ) {
				return $game_type;
			}

			// An archived Game Type does NOT block restore: archiving preserves
			// existing references, and listActiveForPackageSelection() is what
			// keeps such a row out of new Package construction.
			if ( [] === $game_type && 'digital' === $row['medium'] ) {
				return self::error( 'game_type_not_found', 'The referenced Game Type no longer exists, so this Digital Play Product cannot be restored.' );
			}
		}

		$written = $this->execute_write(
			"UPDATE {$this->play_products_table} SET status = 'active', archived_at = NULL, updated_at = ?"
			. " WHERE bingo_play_product_id = ? AND status = 'archived'",
			'si',
			[ $plan->now, $plan->bingo_play_product_id ]
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return new CatalogWriteOutcome( $plan->bingo_play_product_id, true, $row );
	}

	/* ------------------------------------------------------------------ *
	 * Row locks — every one is SELECT ... FOR UPDATE NOWAIT
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	private function lock_posts_row( int $wc_product_id ) {
		return $this->select_one(
			"SELECT ID, post_type, post_status, post_parent FROM {$this->posts_table} WHERE ID = ? FOR UPDATE NOWAIT",
			'i',
			[ $wc_product_id ]
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function lock_play_product_row( int $bingo_play_product_id ) {
		return $this->select_one(
			'SELECT bingo_play_product_id, wc_product_id, medium, faces_per_unit, game_type_id, status,'
			. " created_at, updated_at, archived_at FROM {$this->play_products_table}"
			. ' WHERE bingo_play_product_id = ? FOR UPDATE NOWAIT',
			'i',
			[ $bingo_play_product_id ]
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function lock_play_product_by_wc_product( int $wc_product_id ) {
		return $this->select_one(
			"SELECT bingo_play_product_id FROM {$this->play_products_table} WHERE wc_product_id = ? FOR UPDATE NOWAIT",
			'i',
			[ $wc_product_id ]
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function lock_game_type_row( int $game_type_id ) {
		return $this->select_one(
			"SELECT game_type_id, status FROM {$this->game_types_table} WHERE game_type_id = ? FOR UPDATE NOWAIT",
			'i',
			[ $game_type_id ]
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function lock_active_game_type_row( int $game_type_id ) {
		$row = $this->lock_game_type_row( $game_type_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( [] === $row ) {
			return self::error( 'game_type_not_found', 'The selected Game Type was not found in this hall.' );
		}
		if ( 'active' !== $row['status'] ) {
			return self::error( 'game_type_not_active', 'The selected Game Type is archived and cannot be assigned.' );
		}

		return $row;
	}

	/**
	 * BR-069 / ADR-034 Package-reference check.
	 *
	 * Deliberately a LOCKING read, never a plain SELECT: a plain read is correct
	 * only while it is the transaction's first consistent read, and that
	 * precondition is not structurally guaranteed. A current read observes the
	 * latest committed component state regardless of snapshot timing or isolation
	 * level. Index-backed through bingo_package_components.play_product
	 * (bingo_play_product_id), and narrow — it reads package_id only and never
	 * locks bingo_packages rows merely to test existence. The Play Product row,
	 * already held by the caller, remains the serialization anchor.
	 *
	 * ANY reference blocks, including one belonging to an ARCHIVED Package:
	 * BR-067 preserves archived composition, so those rows still pin the medium.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_not_package_referenced( int $bingo_play_product_id ) {
		$rows = $this->select_all(
			"SELECT package_id FROM {$this->package_components_table}"
			. ' WHERE bingo_play_product_id = ? ORDER BY package_id FOR UPDATE NOWAIT',
			'i',
			[ $bingo_play_product_id ]
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( [] === $rows ) {
			return true;
		}

		$package_ids = [];
		foreach ( $rows as $row ) {
			$package_ids[] = (int) $row['package_id'];
		}

		// The referencing Package IDs travel in error data so the presentation
		// layer can name them (BR-069). No translation happens inside the
		// boundary; the message is a developer-facing string.
		return self::error(
			self::PACKAGE_REFERENCED,
			'The medium cannot change while this Bingo Play Product is used by a Bingo Package.',
			[ 'package_ids' => array_values( array_unique( $package_ids ) ) ]
		);
	}

	/** ADR-033 rank 1: the parent Package row. @return array<string,mixed>|\WP_Error */
	private function lock_package_row( int $package_id ) {
		return $this->select_one(
			'SELECT package_id, wc_product_id, package_type, status FROM ' . $this->packages_table
			. ' WHERE package_id = ? FOR UPDATE NOWAIT',
			'i',
			[ $package_id ]
		);
	}

	/**
	 * ADR-033 step 2: the Package's current composition, read WITHOUT a lock.
	 *
	 * STANDING INVARIANT — ANY FUTURE CHANGE TO THIS METHOD'S CALLING SEQUENCE
	 * MUST PRESERVE IT: this must remain the transaction's FIRST consistent
	 * read. replace_composition_sequence() is a fixed, non-branching statement
	 * sequence — BEGIN, lock the parent bingo_packages row, then this read — so
	 * under REPEATABLE READ the snapshot is opened here, and under READ
	 * COMMITTED each statement reads the latest committed state. Either way this
	 * read observes committed reality at the moment it runs, and the parent
	 * Package row lock already excludes every conforming composition writer for
	 * the rest of the transaction. No component lock is needed, and none is
	 * taken.
	 *
	 * If a future profile introduces ANY consistent read before this one — a
	 * preflight SELECT, a branch, a shared helper that reads first — this read
	 * must become `FOR UPDATE NOWAIT` again, because the snapshot would then
	 * have been opened earlier and could hide a committed composition change.
	 *
	 * This is structurally different from ADR-034's Package-reference check,
	 * which stays a locking read precisely because its calling sequence is NOT
	 * guaranteed to keep it first: it is invoked from two different mutation
	 * profiles, and to_digital_sequence() already performs a Game Type read
	 * before it.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function read_package_components( int $package_id ) {
		return $this->select_all(
			'SELECT package_component_id, bingo_play_product_id, quantity, sort_order FROM '
			. $this->package_components_table
			. ' WHERE package_id = ? ORDER BY bingo_play_product_id',
			'i',
			[ $package_id ]
		);
	}

	/**
	 * ADR-033 rank 3: every dependency Play Product row, locked in ASCENDING
	 * bingo_play_product_id order so concurrent composition writers agree on
	 * acquisition order.
	 *
	 * @param array<int,int> $ids Must already be sorted ascending.
	 * @return array<int,array<string,mixed>>|\WP_Error Keyed by bingo_play_product_id.
	 */
	private function lock_play_product_rows( array $ids ) {
		if ( [] === $ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$rows         = $this->select_all(
			'SELECT bingo_play_product_id, medium, status FROM ' . $this->play_products_table
			. " WHERE bingo_play_product_id IN ({$placeholders})"
			. ' ORDER BY bingo_play_product_id FOR UPDATE NOWAIT',
			str_repeat( 'i', count( $ids ) ),
			$ids
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$by_id = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['bingo_play_product_id'] ] = $row;
		}

		return $by_id;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function lock_package_claim( int $wc_product_id ) {
		return $this->select_one(
			"SELECT package_id FROM {$this->packages_table} WHERE wc_product_id = ? FOR UPDATE NOWAIT",
			'i',
			[ $wc_product_id ]
		);
	}

	/**
	 * @param array<string,mixed> $post
	 * @return true|\WP_Error
	 */
	private static function assert_woo_row_eligible( array $post ) {
		if ( [] === $post ) {
			return self::error( 'woo_product_missing', 'The linked WooCommerce product no longer exists in this hall.' );
		}
		if ( 'product' !== $post['post_type'] || 0 !== (int) $post['post_parent'] ) {
			return self::error( 'woo_product_unsupported', 'Only top-level WooCommerce products are supported.' );
		}
		if ( in_array( (string) $post['post_status'], self::WOO_REJECTED_STATUSES, true ) ) {
			return self::error( 'woo_product_trashed', 'The linked WooCommerce product is in the trash or is an unsaved draft.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Connection capture and transaction control
	 * ------------------------------------------------------------------ */

	/** @return true|\WP_Error */
	private function capture( int $blog_id ) {
		global $wpdb;

		if ( get_current_blog_id() !== $blog_id ) {
			return self::error( 'invalid_hall_context', 'The Catalog transaction is not running in its own hall context.' );
		}

		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli ) {
			return self::error( 'incompatible_database_handle', 'Catalog mutations require the approved mysqli transport.' );
		}

		$this->connection = $wpdb->dbh;

		$identifiers = [
			'posts_table'              => $wpdb->prefix . 'posts',
			'play_products_table'      => Migrations::play_products_table(),
			'game_types_table'         => Migrations::game_types_table(),
			'packages_table'           => Migrations::packages_table(),
			'package_components_table' => Migrations::package_components_table(),
		];

		foreach ( $identifiers as $property => $identifier ) {
			// These are derived from $wpdb->prefix plus class constants, so they
			// are trusted by provenance. ADR-032 is fail-closed regardless: a
			// derived name outside the WordPress/BingoCommerce naming contract is
			// rejected before BEGIN rather than sanitized into a different table.
			if ( 1 !== preg_match( self::IDENTIFIER_PATTERN, $identifier ) ) {
				return self::error(
					'catalog_identifier_rejected',
					'A derived Catalog table identifier is outside the supported naming contract.',
					[ 'identifier' => $identifier ]
				);
			}

			$this->$property = self::quote_identifier( $identifier );
		}

		return $this->assert_connection_state();
	}

	/**
	 * Verify, on the server, that the captured session can carry this boundary.
	 *
	 * ADR-032 forbids a fabricated PHP-side transaction-state detector. This uses
	 * the server's own transaction instrumentation, which was verified on the
	 * installed MySQL to report ACTIVE for an externally opened transaction both
	 * before and after row access. information_schema.innodb_trx is deliberately
	 * NOT used: it was measured returning a false negative on this server while a
	 * transaction was open and holding a real FOR UPDATE row lock.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_connection_state() {
		$state = $this->select_one(
			'SELECT DATABASE() AS database_name,'
			. ' @@autocommit AS autocommit,'
			. ' @@global.read_only AS read_only,'
			. ' @@global.super_read_only AS super_read_only,'
			. ' @@performance_schema AS ps_enabled,'
			. " (SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME = 'events_transactions_current' AND ENABLED = 'YES') AS ps_consumer,"
			. " (SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME = 'transaction' AND ENABLED = 'YES') AS ps_instrument,"
			. " COALESCE((SELECT state FROM performance_schema.events_transactions_current WHERE thread_id = PS_CURRENT_THREAD_ID() LIMIT 1), 'UNAVAILABLE') AS trx_state"
		);

		if ( is_wp_error( $state ) || [] === $state ) {
			return self::error( self::STATE_UNTRUSTED, 'The Catalog database session state could not be verified.' );
		}

		if ( DB_NAME !== (string) $state['database_name'] || (bool) $state['read_only'] || (bool) $state['super_read_only'] ) {
			return self::error( self::STATE_UNTRUSTED, 'The authoritative Catalog database writer is unavailable.' );
		}

		if ( 1 !== (int) $state['autocommit'] ) {
			return self::error( self::STATE_UNTRUSTED, 'The Catalog database session is already inside an implicit transaction.' );
		}

		if (
			1 !== (int) $state['ps_enabled']
			|| 1 !== (int) $state['ps_consumer']
			|| 1 !== (int) $state['ps_instrument']
		) {
			return self::error( self::STATE_UNTRUSTED, 'The Catalog transaction-state instrumentation is unavailable.' );
		}

		// NONE covers a session whose first statement is this probe.
		if ( ! in_array( (string) $state['trx_state'], [ 'COMMITTED', 'ROLLED BACK', 'NONE' ], true ) ) {
			return self::error( self::STATE_UNTRUSTED, 'A database transaction is already open on the Catalog session.' );
		}

		return true;
	}

	/** @return true|\WP_Error */
	private function begin() {
		try {
			return $this->connection->begin_transaction()
				? true
				: self::error( 'transaction_start_failed', 'The Catalog transaction could not start.' );
		} catch ( \Throwable $throwable ) {
			return self::error( 'transaction_start_failed', 'The Catalog transaction could not start.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	private function rollback(): bool {
		try {
			return $this->connection->rollback();
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Connection hygiene after an unclean COMMIT acknowledgement. The result is
	 * discarded on purpose: it must never influence the reported outcome, which
	 * stays catalog_commit_outcome_unknown.
	 */
	private function rollback_quietly(): void {
		try {
			$this->connection->rollback();
		} catch ( \Throwable $throwable ) {
			// Intentionally ignored — see the method docblock.
		}
	}

	/** @return true|\WP_Error */
	private function commit() {
		try {
			return $this->connection->commit()
				? true
				: self::error( self::COMMIT_OUTCOME_UNKNOWN, 'The Catalog commit acknowledgement was not clean.' );
		} catch ( \Throwable $throwable ) {
			return self::error( self::COMMIT_OUTCOME_UNKNOWN, 'The Catalog commit acknowledgement was not clean.' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Native prepared statement helpers
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error Row, or [] when no row matched. */
	private function select_one( string $sql, string $types = '', array $params = [] ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return self::error( 'catalog_prepare_failed', 'A required Catalog statement could not be prepared.', [ 'db_errno' => (int) $this->connection->errno ] );
			}

			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				$error = self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$result = $statement->get_result();
			if ( false === $result ) {
				$error = self::error( 'catalog_statement_failed', 'A required Catalog result could not be read.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$row = $result->fetch_assoc();
			$result->free();
			$statement->close();

			return is_array( $row ) ? $row : [];
		} catch ( \mysqli_sql_exception $exception ) {
			return self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $exception->getCode() ] );
		} catch ( \Throwable $throwable ) {
			return self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/** @return array<int,array<string,mixed>>|\WP_Error Rows, or [] when none matched. */
	private function select_all( string $sql, string $types = '', array $params = [] ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return self::error( 'catalog_prepare_failed', 'A required Catalog statement could not be prepared.', [ 'db_errno' => (int) $this->connection->errno ] );
			}

			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				$error = self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$result = $statement->get_result();
			if ( false === $result ) {
				$error = self::error( 'catalog_statement_failed', 'A required Catalog result could not be read.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$rows = $result->fetch_all( MYSQLI_ASSOC );
			$result->free();
			$statement->close();

			return is_array( $rows ) ? $rows : [];
		} catch ( \mysqli_sql_exception $exception ) {
			return self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $exception->getCode() ] );
		} catch ( \Throwable $throwable ) {
			return self::error( 'catalog_statement_failed', 'A required Catalog statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/**
	 * @param bool $allow_unchanged Accept 0 affected rows. Used only where the
	 *                              target row is already held under this
	 *                              transaction's lock, so 0 affected rows proves
	 *                              the values were already correct rather than
	 *                              that the row moved underneath the write.
	 * @return true|\WP_Error
	 */
	private function execute_write( string $sql, string $types, array $params, bool $allow_unchanged = false ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return self::error( 'catalog_prepare_failed', 'A Catalog write statement could not be prepared.', [ 'db_errno' => (int) $this->connection->errno ] );
			}

			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				$error = self::error( 'persistence_failed', 'A Catalog write statement failed.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$affected = (int) $statement->affected_rows;
			$statement->close();

			if ( $affected < 1 && ! $allow_unchanged ) {
				return self::error( self::STATE_CHANGED, 'The Bingo Play Product changed while it was being updated. Reload and try again.' );
			}

			return true;
		} catch ( \mysqli_sql_exception $exception ) {
			return self::error( 'persistence_failed', 'A Catalog write statement failed.', [ 'db_errno' => (int) $exception->getCode() ] );
		} catch ( \Throwable $throwable ) {
			return self::error( 'persistence_failed', 'A Catalog write statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/** @return true|\WP_Error */
	private static function bind( \mysqli_stmt $statement, string $types, array $params ) {
		if ( '' === $types ) {
			return [] === $params ? true : self::error( 'catalog_bind_failed', 'Catalog statement parameters are invalid.' );
		}

		if ( strlen( $types ) !== count( $params ) ) {
			return self::error( 'catalog_bind_failed', 'Catalog statement parameters are invalid.' );
		}

		$references = [];
		foreach ( $params as $key => $value ) {
			$params[ $key ]     = $value;
			$references[ $key ] = &$params[ $key ];
		}

		return $statement->bind_param( $types, ...$references )
			? true
			: self::error( 'catalog_bind_failed', 'Catalog statement parameters are invalid.' );
	}

	private static function is_lock_conflict( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		$code = is_array( $data ) ? (int) ( $data['db_errno'] ?? 0 ) : 0;

		return in_array( $code, self::LOCK_CONFLICT_ERRNOS, true );
	}

	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Build a transport error WITHOUT invoking any WordPress callback.
	 *
	 * __(), _x(), and translate() all fire the `gettext` / `gettext_{$domain}`
	 * filters, and this method is reachable from inside the critical transaction
	 * on every in-transaction rejection. ADR-032 forbids hooks and callbacks
	 * between BEGIN and COMMIT/ROLLBACK, so the message stays an untranslated
	 * developer-facing string here. Localization is the presentation layer's job,
	 * after the boundary has closed — see PlayProductAdmin::error_message().
	 *
	 * WP_Error's constructor performs no hook dispatch, so it is safe.
	 */
	private static function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, $data + [ 'status' => $code ] );
	}
}
