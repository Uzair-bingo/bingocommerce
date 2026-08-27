<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-032's narrow Catalog critical-serialization boundary.
 *
 * Scope is deliberately tiny. Only two invariants justify this transport:
 *
 *   1. WooCommerce-product exclusivity between bingo_play_products and the
 *      future bingo_packages, anchored on the current site's posts row; and
 *   2. Bingo Play Product -> Game Type reference integrity, anchored on the
 *      Game Type row that a future hard-delete writer will lock.
 *
 * Everything else — archive, faces-per-unit, Digital -> Paper, clearing a Game
 * Type — is a single conditional statement through ordinary $wpdb and never
 * enters this class. This is NOT a general Catalog transaction mechanism.
 *
 * It accepts no callable. Each public entry point owns one fixed, reviewed
 * statement sequence and interprets an immutable CatalogPlayProductPlan, so the
 * complete set of statements that can run between BEGIN and COMMIT/ROLLBACK is
 * enumerable here: BEGIN, COMMIT, ROLLBACK, SELECT ... FOR UPDATE NOWAIT, and
 * INSERT/UPDATE against bingo_play_products.
 *
 * Never inside the boundary: WooCommerce CRUD, WordPress or WooCommerce
 * metadata writes, BR-032/BR-065 projections, AuditLog, hooks, callbacks,
 * emails, caches, UI effects, or logging calls.
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

	/** ADR-032 lock order 2 — Woo row, Play Product row, Game Type row, write. */
	public static function restoreWithWooRevalidation( CatalogPlayProductPlan $plan ) {
		return self::run( $plan, CatalogPlayProductPlan::RESTORE );
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
			'posts_table'         => $wpdb->prefix . 'posts',
			'play_products_table' => Migrations::play_products_table(),
			'game_types_table'    => Migrations::game_types_table(),
			'packages_table'      => Migrations::packages_table(),
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

	/** @return true|\WP_Error */
	private function execute_write( string $sql, string $types, array $params ) {
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

			if ( $affected < 1 ) {
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
