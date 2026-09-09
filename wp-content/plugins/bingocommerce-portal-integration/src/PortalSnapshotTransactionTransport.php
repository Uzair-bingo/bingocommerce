<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Phase 3E Portal snapshot application boundary (ADR-040).
 *
 * One invariant justifies this transport: a validated Portal Hall snapshot must
 * apply to the hall's Game Type replica AND its Session-template replica
 * ALL-OR-NOTHING, together with the sync-state marker that records the
 * application. Nothing else in BingoCommerce writes across those two domains at
 * once, and ADR-039 requires that exhausting the imported-code candidate family
 * reject the complete application "without partial domain changes" — a guarantee
 * only a transaction can make, because code collisions are detectable only at
 * INSERT time against `code_unique`.
 *
 * This is NOT ADR-032/ADR-033. Those boundaries own WooCommerce-product
 * exclusivity, Play Product reference integrity, and Package composition; they
 * have their own lock orders and their own statement sequences, and this class
 * shares none of them. It is also not ADR-025: there is no operation_id, no
 * commit witness, no retry token, and no mandatory-audit atomicity.
 *
 * Like both approved transports it accepts NO callable. One fixed, reviewed
 * statement sequence interprets an immutable plan, so the complete set of
 * statements that can run between BEGIN and COMMIT is enumerable here:
 *
 *   - BEGIN, COMMIT, ROLLBACK;
 *   - SELECT ... FOR UPDATE against bingo_portal_hall_mappings (one row),
 *     bingo_game_types (linked rows), bingo_portal_session_templates (all rows),
 *     and {$prefix}options (the one marker row);
 *   - SELECT ... FOR SHARE against one wp_blogs row;
 *   - INSERT and UPDATE against bingo_game_types;
 *   - INSERT and UPDATE against bingo_portal_session_templates; and
 *   - INSERT and UPDATE against {$prefix}options, for the marker row only.
 *
 * Never inside the boundary: HTTP, WordPress or WooCommerce CRUD, option/meta
 * APIs, object-cache writes, AuditLog, hooks, callbacks, translation, or
 * logging. `$wpdb` is never used between BEGIN and COMMIT either — it shares
 * this exact connection, so a `query` filter callback would execute inside the
 * open transaction. is_wp_error() is likewise never used anywhere in this class:
 * it fires the `is_wp_error_instance` action for every error it inspects, and
 * this class inspects errors on every in-transaction rejection path. Every check
 * is `instanceof \WP_Error`.
 *
 * Before BEGIN, every participating table is verified to exist and to be InnoDB.
 * A MyISAM or MEMORY participant would ignore the transaction outright and turn
 * the all-or-nothing guarantee silently false.
 *
 * Commerce is untouched. This class issues no statement against bingo_sessions,
 * bingo_session_packages, bingo_packages, bingo_package_components,
 * bingo_play_products, posts, or any WooCommerce table.
 */
final class PortalSnapshotTransactionTransport {

	public const COMMIT_OUTCOME_UNKNOWN = 'portal_sync_commit_outcome_unknown';
	public const NESTED_USE             = 'portal_sync_transport_nested_use';
	public const STATE_UNTRUSTED        = 'portal_sync_transaction_state_untrusted';
	public const LOCK_LOST              = 'portal_sync_lock_lost';
	public const MAPPING_CHANGED        = 'portal_sync_mapping_changed';
	public const SNAPSHOT_STALE         = 'portal_snapshot_stale';
	public const GAME_TYPE_MISSING      = 'portal_game_type_missing';
	public const CODE_EXHAUSTED         = 'portal_game_type_code_exhausted';
	public const PERSISTENCE_FAILED     = 'portal_sync_persistence_failed';
	public const SYNC_STATE_INVALID     = 'portal_sync_state_invalid';
	public const ENGINE_UNSUPPORTED     = 'portal_sync_storage_engine_unsupported';

	/** ADR-039: `portal-gt-<id>`, then `-2` through `-32`. */
	private const CODE_CANDIDATE_LIMIT = 32;

	/** MySQL duplicate-entry. */
	private const ERRNO_DUPLICATE_ENTRY = 1062;

	/**
	 * The ONLY constraint identifiers permitted to leave this class.
	 *
	 * A raw MySQL error message quotes the offending VALUE — for a Session
	 * template that is the Portal-supplied name, and for a Game Type it is an
	 * imported code — alongside schema detail and the fully qualified index name.
	 * ADR-037's disclosure rule extends to the apply path, so no raw message ever
	 * reaches returned error data, a log, or a caller.
	 *
	 * Code-collision detection still needs to know WHICH index a duplicate-entry
	 * violated. The raw message is inspected once, inside execute_write(), and
	 * reduced to one of these fixed identifiers; anything unrecognised becomes no
	 * identifier at all. The message itself is discarded at that point.
	 */
	private const ALLOWLISTED_CONSTRAINTS = [
		'code_unique',
		'portal_game_type_unique',
		'local_name_unique',
		'portal_session_unique',
		'option_name',
		'PRIMARY',
	];

	/** Every table ADR-040 locks or writes. All must exist and be InnoDB. */
	private const REQUIRED_INNODB_TABLES = [
		'hall_mappings_table',
		'blogs_table',
		'game_types_table',
		'session_templates_table',
		'options_table',
	];

	/**
	 * The only characters a derived table identifier may contain. This is the
	 * WordPress/BingoCommerce naming contract ($wpdb prefix plus a class
	 * constant), not a general-purpose identifier grammar.
	 */
	private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

	/** Guards against unsupported nested entry within one PHP request. */
	private static bool $active = false;

	/**
	 * Set when a COMMIT acknowledgement was not clean, or a ROLLBACK failed. The
	 * captured session can no longer be trusted to carry another boundary in this
	 * request, so every later entry fails closed.
	 */
	private static bool $untrusted = false;

	private \mysqli $connection;
	private string $hall_mappings_table;
	private string $blogs_table;
	private string $game_types_table;
	private string $session_templates_table;
	private string $options_table;

	/** Unquoted names, kept for the information_schema storage-engine probe. */
	private array $raw_identifiers = [];

	private int $game_types_created            = 0;
	private int $game_types_renamed            = 0;
	private int $session_templates_created     = 0;
	private int $session_templates_updated     = 0;
	private int $session_templates_not_current = 0;

	/**
	 * Apply one validated snapshot atomically.
	 *
	 * The caller MUST already hold the per-Hall advisory lock, and it MUST have
	 * completed the Portal HTTP request and validation before calling: no network
	 * call is reachable from here, and none may be issued while the transaction
	 * is open.
	 *
	 * @return PortalSyncResult|\WP_Error
	 */
	public static function apply( PortalSnapshotApplicationPlan $plan, PortalSyncLock $lock ) {
		if ( self::$untrusted ) {
			return self::error( self::STATE_UNTRUSTED, 'The database session can no longer be trusted for a Portal synchronization transaction.' );
		}
		if ( self::$active ) {
			return self::error( self::NESTED_USE, 'A Portal synchronization transaction is already in progress.' );
		}

		$transport = new self();
		$captured  = $transport->capture( $plan, $lock );
		if ( $captured instanceof \WP_Error ) {
			return $captured;
		}

		return $transport->attempt( $plan );
	}

	/**
	 * A definite lock conflict is NOT retried here.
	 *
	 * A snapshot application is not user-interactive and is idempotent by
	 * construction, so re-invoking the whole flow — including a fresh fetch — is
	 * simpler and strictly safer than retrying a boundary whose session state has
	 * to be re-proven. The caller reports the failure and the operator or the next
	 * scheduled pull runs it again.
	 *
	 * @return PortalSyncResult|\WP_Error
	 */
	private function attempt( PortalSnapshotApplicationPlan $plan ) {
		$begun = $this->begin();
		if ( $begun instanceof \WP_Error ) {
			return $begun;
		}

		self::$active = true;

		try {
			$outcome = $this->apply_sequence( $plan );

			if ( $outcome instanceof \WP_Error ) {
				// COMMIT was never issued, so nothing can be committed. A lock
				// timeout rolls back only the statement, so the explicit ROLLBACK
				// matters; after a deadlock it is a no-op.
				if ( ! $this->rollback() ) {
					self::$untrusted = true;

					return self::error(
						self::STATE_UNTRUSTED,
						'The Portal synchronization transaction could not be rolled back, so the database session is no longer trustworthy.',
						[ 'original_code' => $outcome->get_error_code() ]
					);
				}

				return $outcome;
			}

			if ( true !== $this->commit() ) {
				/*
				 * Once COMMIT has been issued and its acknowledgement is not clean,
				 * the outcome is unknown and stays unknown. No transaction-state
				 * probe, no Catalog or Session read, no marker read, and no retry
				 * may follow: current state could have been produced by a different
				 * operation and is not proof about this one.
				 */
				self::$untrusted = true;
				$this->rollback_quietly();

				return self::error(
					self::COMMIT_OUTCOME_UNKNOWN,
					'The Portal snapshot was submitted but its result could not be confirmed. Re-read the hall state before synchronizing again.'
				);
			}

			return $outcome;
		} finally {
			self::$active = false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * The fixed statement sequence
	 * ------------------------------------------------------------------ */

	/**
	 * Lock order is fixed and total:
	 *
	 *   mapping -> Game Types -> Session templates -> sync state
	 *
	 * Every lock is acquired as a READ, in that order, BEFORE the first write.
	 * Every rejection — mapping mismatch, stale snapshot, missing Game Type —
	 * therefore happens while the transaction has written nothing at all, and the
	 * ROLLBACK that follows has nothing to undo.
	 *
	 * The stale check deliberately precedes the ADR-038 absence gate. A genuinely
	 * older snapshot may legitimately lack a Game Type that Portal created later,
	 * and reporting that as an integrity violation would be wrong.
	 *
	 * @return PortalSyncResult|\WP_Error
	 */
	private function apply_sequence( PortalSnapshotApplicationPlan $plan ) {
		// --- Lock rank 1: the trusted Hall mapping ------------------------------
		$mapping = $this->lock_mapping_row( $plan );
		if ( $mapping instanceof \WP_Error ) {
			return $mapping;
		}

		// --- Lock rank 2: existing Portal-linked Game Type rows -----------------
		$linked = $this->lock_linked_game_types();
		if ( $linked instanceof \WP_Error ) {
			return $linked;
		}

		// --- Lock rank 3: the Session-template replica --------------------------
		$templates = $this->lock_session_templates();
		if ( $templates instanceof \WP_Error ) {
			return $templates;
		}

		// --- Lock rank 4: the sync-state marker ---------------------------------
		$marker = $this->lock_sync_state_row( $plan );
		if ( $marker instanceof \WP_Error ) {
			return $marker;
		}

		if ( PortalSyncState::isStrictlyOlder( $plan->generated_at, $marker['generated_at'] ) ) {
			return self::error(
				self::SNAPSHOT_STALE,
				'The Portal snapshot is older than the last successfully applied snapshot for this hall.'
			);
		}

		// --- ADR-038 absence gate: reject the whole application, zero writes -----
		$gate = $this->assert_no_linked_game_type_missing( $linked, $plan->game_types );
		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}

		// --- Writes -------------------------------------------------------------
		$game_types = $this->apply_game_types( $linked, $plan );
		if ( $game_types instanceof \WP_Error ) {
			return $game_types;
		}

		$sessions = $this->apply_session_templates( $templates, $plan );
		if ( $sessions instanceof \WP_Error ) {
			return $sessions;
		}

		$state = $this->write_sync_state( $marker, $plan );
		if ( $state instanceof \WP_Error ) {
			return $state;
		}

		return new PortalSyncResult(
			PortalSyncResult::APPLIED,
			$plan->portal_hall_id,
			$plan->blog_id,
			$plan->generated_at,
			$this->game_types_created,
			$this->game_types_renamed,
			$this->session_templates_created,
			$this->session_templates_updated,
			$this->session_templates_not_current
		);
	}

	/**
	 * Lock rank 1. Re-confirm the trusted Hall mapping under the transaction.
	 *
	 * The service already resolved and validated this mapping before the fetch,
	 * but a Platform Admin can disable a mapping, or a site can be archived or
	 * deleted, in the seconds a Portal round-trip takes. Every fact the tenancy
	 * decision rests on is therefore re-read here, from the database, on the
	 * captured connection — not from WordPress caches or the earlier result.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function lock_mapping_row( PortalSnapshotApplicationPlan $plan ) {
		$mapping = $this->select_one(
			'SELECT portal_hall_id, blog_id, status FROM ' . $this->hall_mappings_table
			. ' WHERE portal_hall_id = ? FOR UPDATE',
			'i',
			[ $plan->portal_hall_id ]
		);
		if ( $mapping instanceof \WP_Error ) {
			return $mapping;
		}
		if ( [] === $mapping ) {
			return self::error( self::MAPPING_CHANGED, 'The Portal Hall mapping no longer exists.' );
		}
		if (
			(int) $mapping['portal_hall_id'] !== $plan->portal_hall_id
			|| (int) $mapping['blog_id'] !== $plan->blog_id
		) {
			return self::error( self::MAPPING_CHANGED, 'The Portal Hall mapping no longer matches this hall.' );
		}
		if ( HallMappingRepository::STATUS_ACTIVE !== (string) $mapping['status'] ) {
			return self::error( self::MAPPING_CHANGED, 'The Portal Hall mapping is no longer active.' );
		}

		// FOR SHARE, not a plain read: a consistent read here would open the
		// REPEATABLE READ snapshot and could hide a committed site change, and a
		// FOR UPDATE would block ordinary site administration for no benefit. This
		// row only has to stay valid, not stay ours to write.
		$site = $this->select_one(
			'SELECT blog_id, site_id, archived, deleted, spam FROM ' . $this->blogs_table
			. ' WHERE blog_id = ? FOR SHARE',
			'i',
			[ $plan->blog_id ]
		);
		if ( $site instanceof \WP_Error ) {
			return $site;
		}
		if ( [] === $site ) {
			return self::error( self::MAPPING_CHANGED, 'The mapped WordPress site no longer exists.' );
		}
		if ( (int) $site['site_id'] !== $plan->network_id ) {
			return self::error( self::MAPPING_CHANGED, 'The mapped WordPress site no longer belongs to this network.' );
		}
		if ( (int) $site['blog_id'] === $plan->main_site_id ) {
			return self::error( self::MAPPING_CHANGED, 'The mapped WordPress site is the main network site.' );
		}
		if ( 0 !== (int) $site['archived'] || 0 !== (int) $site['deleted'] || 0 !== (int) $site['spam'] ) {
			return self::error( self::MAPPING_CHANGED, 'The mapped WordPress site is archived, deleted, or flagged as spam.' );
		}

		return $mapping;
	}

	/**
	 * Lock rank 2, in ascending Portal ID order so concurrent writers agree on
	 * acquisition order.
	 *
	 * Local rows (`portal_game_type_id IS NULL`) are deliberately NOT locked. This
	 * transport never reads, matches, merges, or writes them, and locking them
	 * would serialize ordinary hall Game Type administration behind Portal pulls.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error Keyed by portal_game_type_id.
	 */
	private function lock_linked_game_types() {
		$rows = $this->select_all(
			'SELECT game_type_id, portal_game_type_id, code, name FROM ' . $this->game_types_table
			. ' WHERE portal_game_type_id IS NOT NULL ORDER BY portal_game_type_id FOR UPDATE'
		);
		if ( $rows instanceof \WP_Error ) {
			return $rows;
		}

		$by_portal_id = [];
		foreach ( $rows as $row ) {
			$portal_id = (int) $row['portal_game_type_id'];
			if ( $portal_id < 1 ) {
				return self::error( self::PERSISTENCE_FAILED, 'A linked Game Type row carries an unusable Portal identity.' );
			}
			$by_portal_id[ $portal_id ] = $row;
		}

		return $by_portal_id;
	}

	/**
	 * Lock rank 3, in ascending Portal ID order.
	 *
	 * The whole replica is locked because absence is decided by set difference:
	 * a row missing from the snapshot must be marked not-current, so every row has
	 * to be under the transaction, not just the ones the snapshot names.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error Keyed by portal_session_id.
	 */
	private function lock_session_templates() {
		$rows = $this->select_all(
			'SELECT session_template_id, portal_session_id, name, portal_status, is_current, not_current_at FROM '
			. $this->session_templates_table . ' ORDER BY portal_session_id FOR UPDATE'
		);
		if ( $rows instanceof \WP_Error ) {
			return $rows;
		}

		$by_portal_id = [];
		foreach ( $rows as $row ) {
			$portal_id = (int) $row['portal_session_id'];
			if ( $portal_id < 1 ) {
				return self::error( self::PERSISTENCE_FAILED, 'A Session-template replica row carries an unusable Portal identity.' );
			}
			$by_portal_id[ $portal_id ] = $row;
		}

		return $by_portal_id;
	}

	/**
	 * Lock rank 4. The single marker row, by its unique option_name.
	 *
	 * On the FIRST synchronization for a hall the row does not exist yet, so
	 * InnoDB takes a gap lock on the unique index instead of a record lock. That
	 * is a one-time event per hall, and the transaction holding it is
	 * milliseconds long because the Portal round-trip already finished. Every
	 * later synchronization locks an existing record and takes no gap at all.
	 *
	 * An ABSENT row is the only "no previous state" case. A marker that EXISTS but
	 * cannot be decoded, carries a timestamp that is not the Portal Atom
	 * serialization, or records a different Portal Hall is rejected outright with
	 * zero writes. Silently treating any of those as absent would bypass
	 * stale-order protection precisely when the ordering record is least
	 * trustworthy, letting an arbitrarily old snapshot overwrite Portal-owned
	 * names. A wrong Hall ID in particular means the marker and the hall's tables
	 * disagree about tenancy, which is never something to write through.
	 *
	 * @return array{option_id:int|null,generated_at:string|null}|\WP_Error
	 */
	private function lock_sync_state_row( PortalSnapshotApplicationPlan $plan ) {
		$row = $this->select_one(
			'SELECT option_id, option_value FROM ' . $this->options_table
			. ' WHERE option_name = ? FOR UPDATE',
			's',
			[ PortalSyncState::OPTION_NAME ]
		);
		if ( $row instanceof \WP_Error ) {
			return $row;
		}
		if ( [] === $row ) {
			return [ 'option_id' => null, 'generated_at' => null ];
		}

		$decoded = PortalSyncState::decode( (string) $row['option_value'] );
		if ( null === $decoded ) {
			return self::error(
				self::SYNC_STATE_INVALID,
				'The stored Portal synchronization marker for this hall is malformed and cannot establish snapshot ordering.'
			);
		}
		if ( ! PortalSyncState::isPortalTimestamp( $decoded['generated_at'] ) ) {
			return self::error(
				self::SYNC_STATE_INVALID,
				'The stored Portal synchronization marker for this hall carries an unusable generation timestamp.'
			);
		}
		if ( $decoded['portal_hall_id'] !== $plan->portal_hall_id ) {
			return self::error(
				self::SYNC_STATE_INVALID,
				'The stored Portal synchronization marker for this hall records a different Portal Hall.'
			);
		}

		return [
			'option_id'    => (int) $row['option_id'],
			'generated_at' => $decoded['generated_at'],
		];
	}

	/**
	 * ADR-038. Portal Game Type deletion is prohibited in v1 and Archive/Restore
	 * is not part of v1, so a previously linked Portal Game Type missing from a
	 * later complete snapshot is a contract violation, not a lifecycle event.
	 *
	 * An authoritative EMPTY `game_types` collection does not override this: if
	 * the hall has any linked row, the empty collection rejects the application.
	 *
	 * Rejection is total and writes nothing. Missing Game Types are never deleted,
	 * archived, unlinked, or marked not-current, and names and codes are never
	 * consulted to decide whether a record disappeared.
	 *
	 * @param array<int,array<string,mixed>> $linked
	 * @param array<int,array<string,mixed>> $snapshot_game_types
	 * @return true|\WP_Error
	 */
	private function assert_no_linked_game_type_missing( array $linked, array $snapshot_game_types ) {
		$present = [];
		foreach ( $snapshot_game_types as $row ) {
			$present[ (int) $row['id'] ] = true;
		}

		$missing = [];
		foreach ( array_keys( $linked ) as $portal_id ) {
			if ( ! isset( $present[ $portal_id ] ) ) {
				$missing[] = $portal_id;
			}
		}

		if ( [] !== $missing ) {
			sort( $missing );

			return self::error(
				self::GAME_TYPE_MISSING,
				'The Portal snapshot omits a Portal Game Type this hall has already imported.',
				[ 'missing_count' => count( $missing ), 'missing_portal_game_type_ids' => $missing ]
			);
		}

		return true;
	}

	/**
	 * Insert new imported Game Types and apply Portal renames.
	 *
	 * Identity is `portal_game_type_id` within this hall's own table, and nothing
	 * else. A name or a code NEVER links, merges, adopts, or overwrites a row, so
	 * duplicate Portal names with different Portal IDs stay separate rows.
	 *
	 * @param array<int,array<string,mixed>> $linked
	 * @return true|\WP_Error
	 */
	private function apply_game_types( array $linked, PortalSnapshotApplicationPlan $plan ) {
		foreach ( $plan->game_types as $game_type ) {
			$portal_id = (int) $game_type['id'];
			$name      = (string) $game_type['name'];

			if ( ! isset( $linked[ $portal_id ] ) ) {
				$inserted = $this->insert_game_type( $portal_id, $name, $plan->now );
				if ( $inserted instanceof \WP_Error ) {
					return $inserted;
				}
				++$this->game_types_created;
				continue;
			}

			// Byte comparison in PHP, never in SQL: a collation that treats two
			// different names as equal would hide a real Portal rename.
			if ( (string) $linked[ $portal_id ]['name'] === $name ) {
				continue;
			}

			// The local ID, the allocated code, the local status, and archived_at
			// are all untouched. `local_name_key` is forced to NULL because a
			// Portal-linked row must never occupy the local name namespace.
			$updated = $this->execute_write(
				'UPDATE ' . $this->game_types_table
				. ' SET name = ?, local_name_key = NULL, updated_at = ? WHERE portal_game_type_id = ?',
				'ssi',
				[ $name, $plan->now, $portal_id ]
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
			++$this->game_types_renamed;
		}

		return true;
	}

	/**
	 * ADR-039 imported-code allocation.
	 *
	 * Candidates are `portal-gt-<id>`, then `portal-gt-<id>-2` through
	 * `portal-gt-<id>-32`, tried in order. The DATABASE is the collision
	 * authority: each candidate is attempted as a real INSERT rather than probed
	 * with a pre-read, because a pre-read would race
	 * `GameTypeService::create()` running outside this transaction. A duplicate-key
	 * failure rolls back only the failed statement, so iterating candidates inside
	 * the open transaction is safe.
	 *
	 * The namespace is not reserved and existing local codes are never adopted,
	 * overwritten, or renamed. Exhausting all 32 candidates rejects the complete
	 * application.
	 *
	 * @return true|\WP_Error
	 */
	private function insert_game_type( int $portal_game_type_id, string $name, string $now ) {
		$sql = 'INSERT INTO ' . $this->game_types_table
			. ' (portal_game_type_id, code, name, local_name_key, status, created_at, updated_at, archived_at)'
			. " VALUES (?, ?, ?, NULL, 'active', ?, ?, NULL)";

		for ( $candidate = 1; $candidate <= self::CODE_CANDIDATE_LIMIT; ++$candidate ) {
			$code = 1 === $candidate
				? 'portal-gt-' . $portal_game_type_id
				: 'portal-gt-' . $portal_game_type_id . '-' . $candidate;

			$written = $this->execute_write( $sql, 'issss', [ $portal_game_type_id, $code, $name, $now, $now ] );
			if ( ! $written instanceof \WP_Error ) {
				return true;
			}

			if ( $this->is_duplicate_of( $written, 'code_unique' ) ) {
				continue;
			}

			// A portal_game_type_unique collision is impossible here: every linked
			// row is locked and this Portal ID was absent from that set. If it
			// happens anyway it is a genuine integrity violation, not a candidate
			// collision, and must not be retried under a different code.
			return $written;
		}

		return self::error(
			self::CODE_EXHAUSTED,
			'No imported Game Type code candidate was available for a Portal Game Type.',
			[ 'candidates_tried' => self::CODE_CANDIDATE_LIMIT ]
		);
	}

	/**
	 * Upsert the Session-template replica by `portal_session_id`.
	 *
	 * Presence in the snapshot sets currentness; ABSENCE never deletes. Portal
	 * `portal_status` is the source attribute and stays independent of
	 * `is_current`: a present draft template is `is_current = 1` with
	 * `portal_status = 'draft'`, and neither value is ever derived from the other.
	 *
	 * `created_at` is preserved on every path. `not_current_at` marks the CURRENT
	 * absence: it is stamped when a present row goes missing, cleared when the row
	 * reappears, and left completely alone while a row simply stays missing, so a
	 * repeated snapshot never rewrites the timestamp.
	 *
	 * Not one statement here touches bingo_sessions or bingo_session_packages.
	 * Commerce Sessions, dated occurrences, and Package assignments are
	 * BingoCommerce-authoritative and outside this boundary entirely.
	 *
	 * @param array<int,array<string,mixed>> $existing
	 * @return true|\WP_Error
	 */
	private function apply_session_templates( array $existing, PortalSnapshotApplicationPlan $plan ) {
		$present = [];

		foreach ( $plan->sessions as $session ) {
			$portal_id = (int) $session['id'];
			$name      = (string) $session['name'];
			$status    = (string) $session['status'];

			$present[ $portal_id ] = true;

			if ( ! isset( $existing[ $portal_id ] ) ) {
				$inserted = $this->execute_write(
					'INSERT INTO ' . $this->session_templates_table
					. ' (portal_session_id, name, portal_status, is_current, created_at, updated_at, not_current_at)'
					. ' VALUES (?, ?, ?, 1, ?, ?, NULL)',
					'issss',
					[ $portal_id, $name, $status, $plan->now, $plan->now ]
				);
				if ( $inserted instanceof \WP_Error ) {
					return $inserted;
				}
				++$this->session_templates_created;
				continue;
			}

			$row = $existing[ $portal_id ];
			$needs_write = (string) $row['name'] !== $name
				|| (string) $row['portal_status'] !== $status
				|| 1 !== (int) $row['is_current']
				|| null !== $row['not_current_at'];

			if ( ! $needs_write ) {
				continue;
			}

			// One statement covers a plain attribute change and a reappearance.
			// Clearing not_current_at is a no-op for a row that never went missing,
			// and for one that did it starts the record over, so a LATER absence
			// stamps a fresh transition instead of keeping the first one forever.
			$updated = $this->execute_write(
				'UPDATE ' . $this->session_templates_table
				. ' SET name = ?, portal_status = ?, is_current = 1, not_current_at = NULL, updated_at = ?'
				. ' WHERE portal_session_id = ?',
				'sssi',
				[ $name, $status, $plan->now, $portal_id ]
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
			++$this->session_templates_updated;
		}

		foreach ( $existing as $portal_id => $row ) {
			if ( isset( $present[ $portal_id ] ) ) {
				continue;
			}

			// Already not current: write NOTHING. Re-stamping not_current_at on
			// every pull would destroy the record of when the template actually
			// disappeared, and would make a repeated identical snapshot mutate rows.
			if ( 1 !== (int) $row['is_current'] ) {
				continue;
			}

			$updated = $this->execute_write(
				'UPDATE ' . $this->session_templates_table
				. ' SET is_current = 0, not_current_at = ?, updated_at = ? WHERE portal_session_id = ?',
				'ssi',
				[ $plan->now, $plan->now, $portal_id ]
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
			++$this->session_templates_not_current;
		}

		return true;
	}

	/**
	 * Record the last successfully applied snapshot, inside the same transaction.
	 *
	 * The marker moves if and only if the domain changes commit, so a rolled-back
	 * or rejected application leaves no trace of a sync-state write. The row is
	 * created here rather than pre-created outside the transaction, so a failed
	 * application never leaves an empty marker behind either.
	 *
	 * @param array{option_id:int|null,generated_at:string|null} $marker
	 * @return true|\WP_Error
	 */
	private function write_sync_state( array $marker, PortalSnapshotApplicationPlan $plan ) {
		$encoded = PortalSyncState::encode( $plan->portal_hall_id, $plan->generated_at, $plan->now );
		if ( $encoded instanceof \WP_Error ) {
			return $encoded;
		}

		if ( null === $marker['option_id'] ) {
			return $this->execute_write(
				'INSERT INTO ' . $this->options_table . ' (option_name, option_value, autoload) VALUES (?, ?, ?)',
				'sss',
				[ PortalSyncState::OPTION_NAME, $encoded, PortalSyncState::AUTOLOAD_VALUE ]
			);
		}

		return $this->execute_write(
			'UPDATE ' . $this->options_table . ' SET option_value = ?, autoload = ? WHERE option_id = ?',
			'ssi',
			[ $encoded, PortalSyncState::AUTOLOAD_VALUE, $marker['option_id'] ],
			true
		);
	}

	/* ------------------------------------------------------------------ *
	 * Connection capture and transaction control
	 * ------------------------------------------------------------------ */

	/** @return true|\WP_Error */
	private function capture( PortalSnapshotApplicationPlan $plan, PortalSyncLock $lock ) {
		global $wpdb;

		if ( get_current_blog_id() !== $plan->blog_id ) {
			return self::error( 'invalid_hall_context', 'The Portal synchronization is not running in its own hall context.' );
		}

		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli ) {
			return self::error( 'incompatible_database_handle', 'Portal snapshot application requires the approved mysqli transport.' );
		}

		$this->connection = $wpdb->dbh;

		if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) || ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
			return self::error( 'portal_sync_domain_unavailable', 'The Catalog and Session replica schemas are unavailable.' );
		}

		$identifiers = [
			'hall_mappings_table'     => Migrations::hall_mappings_table(),
			'blogs_table'             => $wpdb->blogs,
			'game_types_table'        => \BingoCommerce\Catalog\Migrations::game_types_table(),
			'session_templates_table' => \BingoCommerce\Sessions\Migrations::portal_session_templates_table(),
			'options_table'           => $wpdb->options,
		];

		// Derived from $wpdb prefixes plus class constants, so trusted by
		// provenance. This stays fail-closed regardless: a derived name outside
		// the naming contract is rejected before BEGIN rather than sanitized into
		// a different table.
		foreach ( $identifiers as $property => $identifier ) {
			if ( 1 !== preg_match( self::IDENTIFIER_PATTERN, $identifier ) ) {
				return self::error(
					'portal_sync_identifier_rejected',
					'A derived table identifier is outside the supported naming contract.',
					[ 'identifier' => $identifier ]
				);
			}

			$this->raw_identifiers[ $property ] = $identifier;
			$this->$property                   = self::quote_identifier( $identifier );
		}

		// The per-site tables must belong to the hall this plan targets. Catching a
		// forgotten or mismatched switch_to_blog() here is the difference between a
		// rejected sync and writing one hall's Portal data into another's tables.
		$expected_prefix = $wpdb->get_blog_prefix( $plan->blog_id );
		if (
			$expected_prefix . 'bingo_game_types' !== $identifiers['game_types_table']
			|| $expected_prefix . 'bingo_portal_session_templates' !== $identifiers['session_templates_table']
			|| $expected_prefix . 'options' !== $identifiers['options_table']
		) {
			return self::error( 'invalid_hall_context', 'The resolved hall tables do not belong to the target hall.' );
		}

		$state = $this->assert_connection_state();
		if ( $state instanceof \WP_Error ) {
			return $state;
		}

		$engines = $this->assert_storage_engines();
		if ( $engines instanceof \WP_Error ) {
			return $engines;
		}

		// The advisory lock is held on THIS session. If $wpdb reconnected between
		// acquisition and now, MySQL released it silently and another request may
		// already be synchronizing this hall.
		if ( ! $lock->isStillHeld() || $lock->connectionId() !== (int) $state['connection_id'] ) {
			return self::error( self::LOCK_LOST, 'The Portal synchronization lock is no longer held on this database session.' );
		}

		return true;
	}

	/**
	 * Every table this boundary locks or writes must exist and be InnoDB.
	 *
	 * Runs BEFORE BEGIN, so a failure is trivially zero-write. This is not
	 * defensive decoration: MyISAM and MEMORY silently ignore transactions
	 * entirely. A single non-InnoDB participant would accept every INSERT and
	 * UPDATE, ignore ROLLBACK, and leave the "all-or-nothing" guarantee that
	 * justifies this transport quietly false — a half-applied snapshot reported as
	 * a clean rejection. ADR-039's requirement that code exhaustion produce no
	 * partial domain changes would fail the same way.
	 *
	 * A missing table fails here too, rather than surfacing later as an opaque
	 * mid-transaction statement error.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_storage_engines() {
		$names = [];
		foreach ( self::REQUIRED_INNODB_TABLES as $property ) {
			$name = $this->raw_identifiers[ $property ] ?? '';
			if ( '' === $name ) {
				return self::error( self::ENGINE_UNSUPPORTED, 'A required Portal synchronization table could not be resolved.' );
			}
			$names[] = $name;
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '?' ) );
		$rows         = $this->select_all(
			'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'
			. " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
			str_repeat( 's', count( $names ) ),
			$names
		);
		if ( $rows instanceof \WP_Error ) {
			return self::error( self::ENGINE_UNSUPPORTED, 'The Portal synchronization storage engines could not be verified.' );
		}

		$engines = [];
		foreach ( $rows as $row ) {
			$engines[ (string) $row['TABLE_NAME'] ] = strtolower( (string) ( $row['ENGINE'] ?? '' ) );
		}

		$unsupported = [];
		foreach ( $names as $name ) {
			if ( ! isset( $engines[ $name ] ) || 'innodb' !== $engines[ $name ] ) {
				$unsupported[] = $name;
			}
		}

		if ( [] !== $unsupported ) {
			// Derived table names only: no MySQL message, no row data.
			return self::error(
				self::ENGINE_UNSUPPORTED,
				'A table required for Portal snapshot application is missing or is not InnoDB, so the application cannot be atomic.',
				[ 'tables' => $unsupported ]
			);
		}

		return true;
	}

	/**
	 * Verify, on the server, that the captured session can carry this boundary.
	 *
	 * This uses the server's own transaction instrumentation rather than a
	 * fabricated PHP-side detector, following ADR-032.
	 * information_schema.innodb_trx is deliberately NOT used: it was measured
	 * returning a false negative on this server while a transaction was open and
	 * holding a real FOR UPDATE row lock.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function assert_connection_state() {
		$state = $this->select_one(
			'SELECT DATABASE() AS database_name,'
			. ' CONNECTION_ID() AS connection_id,'
			. ' @@autocommit AS autocommit,'
			. ' @@global.read_only AS read_only,'
			. ' @@global.super_read_only AS super_read_only,'
			. ' @@performance_schema AS ps_enabled,'
			. " (SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME = 'events_transactions_current' AND ENABLED = 'YES') AS ps_consumer,"
			. " (SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME = 'transaction' AND ENABLED = 'YES') AS ps_instrument,"
			. " COALESCE((SELECT state FROM performance_schema.events_transactions_current WHERE thread_id = PS_CURRENT_THREAD_ID() LIMIT 1), 'UNAVAILABLE') AS trx_state"
		);

		if ( $state instanceof \WP_Error || [] === $state ) {
			return self::error( self::STATE_UNTRUSTED, 'The database session state could not be verified.' );
		}

		if ( DB_NAME !== (string) $state['database_name'] || (bool) $state['read_only'] || (bool) $state['super_read_only'] ) {
			return self::error( self::STATE_UNTRUSTED, 'The authoritative database writer is unavailable.' );
		}

		if ( 1 !== (int) $state['autocommit'] ) {
			return self::error( self::STATE_UNTRUSTED, 'The database session is already inside an implicit transaction.' );
		}

		if (
			1 !== (int) $state['ps_enabled']
			|| 1 !== (int) $state['ps_consumer']
			|| 1 !== (int) $state['ps_instrument']
		) {
			return self::error( self::STATE_UNTRUSTED, 'The transaction-state instrumentation is unavailable.' );
		}

		// COMMITTED is the expected state here: the advisory lock acquisition and
		// the mapping reads that precede this probe each ran as their own implicit
		// autocommit transaction.
		if ( ! in_array( (string) $state['trx_state'], [ 'COMMITTED', 'ROLLED BACK', 'NONE' ], true ) ) {
			return self::error( self::STATE_UNTRUSTED, 'A database transaction is already open on this session.' );
		}

		return $state;
	}

	/** @return true|\WP_Error */
	private function begin() {
		$isolation = $this->execute_without_result( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		if ( $isolation instanceof \WP_Error ) {
			return self::error( 'portal_sync_transaction_start_failed', 'The transaction isolation level could not be set.' );
		}

		try {
			return $this->connection->begin_transaction()
				? true
				: self::error( 'portal_sync_transaction_start_failed', 'The Portal synchronization transaction could not start.' );
		} catch ( \Throwable $throwable ) {
			return self::error(
				'portal_sync_transaction_start_failed',
				'The Portal synchronization transaction could not start.',
				[ 'db_errno' => (int) $throwable->getCode() ]
			);
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
	 * stays portal_sync_commit_outcome_unknown.
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
				: self::error( self::COMMIT_OUTCOME_UNKNOWN, 'The commit acknowledgement was not clean.' );
		} catch ( \Throwable $throwable ) {
			return self::error( self::COMMIT_OUTCOME_UNKNOWN, 'The commit acknowledgement was not clean.' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Native prepared statement helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<int,mixed> $params
	 * @return array<string,mixed>|\WP_Error Row, or [] when no row matched.
	 */
	private function select_one( string $sql, string $types = '', array $params = [] ) {
		$rows = $this->select_all( $sql, $types, $params );
		if ( $rows instanceof \WP_Error ) {
			return $rows;
		}

		return $rows[0] ?? [];
	}

	/**
	 * @param array<int,mixed> $params
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function select_all( string $sql, string $types = '', array $params = [] ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return self::error(
					'portal_sync_prepare_failed',
					'A required statement could not be prepared.',
					[ 'db_errno' => (int) $this->connection->errno ]
				);
			}

			$bound = self::bind( $statement, $types, $params );
			if ( $bound instanceof \WP_Error ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				$error = self::error( self::PERSISTENCE_FAILED, 'A required statement failed.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$result = $statement->get_result();
			if ( false === $result ) {
				$error = self::error( self::PERSISTENCE_FAILED, 'A required result could not be read.', [ 'db_errno' => (int) $statement->errno ] );
				$statement->close();
				return $error;
			}

			$rows = $result->fetch_all( MYSQLI_ASSOC );
			$result->free();
			$statement->close();

			return is_array( $rows ) ? $rows : [];
		} catch ( \mysqli_sql_exception $exception ) {
			return self::error( self::PERSISTENCE_FAILED, 'A required statement failed.', [ 'db_errno' => (int) $exception->getCode() ] );
		} catch ( \Throwable $throwable ) {
			return self::error( self::PERSISTENCE_FAILED, 'A required statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/**
	 * @param array<int,mixed> $params
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
				return self::error(
					'portal_sync_prepare_failed',
					'A write statement could not be prepared.',
					[ 'db_errno' => (int) $this->connection->errno ]
				);
			}

			$bound = self::bind( $statement, $types, $params );
			if ( $bound instanceof \WP_Error ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				// The raw message is reduced to an allowlisted identifier HERE and
				// discarded. Nothing downstream ever sees it.
				$error = self::write_error( (int) $statement->errno, (string) $statement->error );
				$statement->close();
				return $error;
			}

			$affected = (int) $statement->affected_rows;
			$statement->close();

			if ( $affected < 1 && ! $allow_unchanged ) {
				return self::error( self::PERSISTENCE_FAILED, 'A write statement changed no row.' );
			}

			return true;
		} catch ( \mysqli_sql_exception $exception ) {
			return self::write_error( (int) $exception->getCode(), (string) $exception->getMessage() );
		} catch ( \Throwable $throwable ) {
			return self::write_error( (int) $throwable->getCode(), (string) $throwable->getMessage() );
		}
	}

	/**
	 * The ONE place a raw MySQL message is read, and the only place it dies.
	 *
	 * A duplicate-entry message looks like
	 * `Duplicate entry 'Evening Session' for key 'bc_2_x.portal_session_unique'`,
	 * so it quotes the offending value — a Portal name or an imported code — plus
	 * the schema-qualified index. Only the numeric errno and, when the message
	 * names one, an ALLOWLISTED constraint identifier survive into the returned
	 * error. An unrecognised index contributes nothing at all, so a constraint
	 * this class does not own (a hall-local probe index, a future column) can
	 * never leak its name either.
	 */
	private static function write_error( int $errno, string $raw_message ): \WP_Error {
		$data = [ 'db_errno' => $errno ];

		foreach ( self::ALLOWLISTED_CONSTRAINTS as $constraint ) {
			if ( false !== stripos( $raw_message, $constraint ) ) {
				$data['constraint'] = $constraint;
				break;
			}
		}

		return self::error( self::PERSISTENCE_FAILED, 'A write statement failed.', $data );
	}

	/** @return true|\WP_Error */
	private function execute_without_result( string $sql ) {
		try {
			return false !== $this->connection->query( $sql )
				? true
				: self::error( self::PERSISTENCE_FAILED, 'A required session statement failed.', [ 'db_errno' => (int) $this->connection->errno ] );
		} catch ( \Throwable $throwable ) {
			return self::error( self::PERSISTENCE_FAILED, 'A required session statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/**
	 * Is this failure a duplicate-entry violation of one specific named index?
	 *
	 * Both halves matter. The errno alone cannot distinguish a code collision —
	 * which must advance to the next candidate — from a `portal_game_type_unique`
	 * violation, which is an integrity failure that must abort.
	 *
	 * This reads the ALLOWLISTED identifier that write_error() already distilled,
	 * never a raw message, so collision detection works without a MySQL string
	 * existing anywhere outside that one function.
	 */
	private function is_duplicate_of( \WP_Error $error, string $index ): bool {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || self::ERRNO_DUPLICATE_ENTRY !== (int) ( $data['db_errno'] ?? 0 ) ) {
			return false;
		}

		return $index === ( $data['constraint'] ?? '' );
	}

	/**
	 * @param array<int,mixed> $params
	 * @return true|\WP_Error
	 */
	private static function bind( \mysqli_stmt $statement, string $types, array $params ) {
		if ( '' === $types ) {
			return [] === $params ? true : self::error( 'portal_sync_bind_failed', 'Statement parameters are invalid.' );
		}

		if ( strlen( $types ) !== count( $params ) ) {
			return self::error( 'portal_sync_bind_failed', 'Statement parameters are invalid.' );
		}

		$references = [];
		foreach ( $params as $key => $value ) {
			$params[ $key ]     = $value;
			$references[ $key ] = &$params[ $key ];
		}

		return $statement->bind_param( $types, ...$references )
			? true
			: self::error( 'portal_sync_bind_failed', 'Statement parameters are invalid.' );
	}

	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Build a transport error WITHOUT invoking any WordPress callback.
	 *
	 * __() and its relatives fire the `gettext` filters, and this method is
	 * reachable from inside the critical transaction on every in-transaction
	 * rejection. Hooks and callbacks are forbidden between BEGIN and
	 * COMMIT/ROLLBACK, so the message stays an untranslated developer-facing
	 * string. Localization belongs to the presentation layer, after the boundary
	 * has closed. WP_Error's constructor performs no hook dispatch, so it is safe.
	 *
	 * For the same reason this class tests every result with `instanceof
	 * \WP_Error` and NEVER with is_wp_error(): that helper fires the
	 * `is_wp_error_instance` action on every error it inspects, which would run
	 * arbitrary third-party callbacks on the captured session while the
	 * transaction is open — the same hazard as the `query` and `gettext` filters.
	 *
	 * Returned data is limited to values this class generated: a numeric MySQL
	 * errno, an allowlisted constraint identifier, derived table names, Portal
	 * IDs, and counts. No raw MySQL message, raw body, Portal name, credential, or
	 * upstream response reaches an error, its data, or a log.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, $data + [ 'status' => $code ] );
	}

	/** Test-only reset of the request-scoped guards. */
	public static function resetGuardsForTesting(): void {
		if ( ! defined( 'WP_CLI' ) && 'cli' !== PHP_SAPI ) {
			return;
		}

		self::$active    = false;
		self::$untrusted = false;
	}
}
