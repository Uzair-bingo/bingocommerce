<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3E orchestration: mapping -> fetch -> validate -> atomic application.
 *
 * The order below is the security boundary, not a convenience:
 *
 *   0. the shared Commerce access decision runs before anything else, so a
 *      blocked hall takes no lock, makes no Portal request, and writes nothing;
 *   1. the per-Hall advisory lock is taken next, so a second synchronization of
 *      the same hall never even issues a Portal request;
 *   2. the Portal Hall ID comes ONLY from the active HallMappingRepository row
 *      for the target site — never from a request, a payload, a hostname, or a
 *      name;
 *   3. the snapshot URL is built from that mapped ID by PortalHttpClient;
 *   4. PortalSnapshotValidator re-checks the Hall identity of the envelope AND of
 *      every child record against the same mapped ID;
 *   5. only the validator's reconstructed array reaches the plan; and
 *   6. the transport re-confirms the mapping under `FOR UPDATE` before it writes,
 *      because a mapping can be disabled or a site archived during a Portal
 *      round-trip.
 *
 * HTTP finishes before the transaction opens. That is structural: the transport
 * accepts no callable and owns its complete statement set, so no network call is
 * reachable from inside the boundary.
 *
 * This service registers no admin screen, no REST route, no cron event, and no
 * hook. Phase 3E is invoked directly — from the CLI harnesses today, and from
 * whatever trigger surface a later, separately reviewed increment adds.
 */
final class PortalSyncService {

	/** The ONLY message an unrestorable blog context logs. No stored value. */
	private const CONTEXT_NOT_RESTORED_MESSAGE = 'BingoCommerce provisioning synchronization could not restore the site context.';

	/** @var callable(int):(string|\WP_Error) */
	private $fetcher;

	private PortalSnapshotValidator $validator;

	/**
	 * @param callable(int):(string|\WP_Error)|null $fetcher Snapshot transport. The
	 *        default is the real PortalHttpClient; harnesses inject fixture bodies
	 *        so application behaviour can be proven without a live Portal.
	 */
	public function __construct( ?callable $fetcher = null, ?PortalSnapshotValidator $validator = null ) {
		$this->fetcher = $fetcher ?? static function ( int $portal_hall_id ) {
			return ( new PortalHttpClient() )->fetchHallSnapshot( $portal_hall_id );
		};
		$this->validator = $validator ?? new PortalSnapshotValidator();
	}

	/** Convenience entry point for CLI and operator invocation. @return PortalSyncResult|\WP_Error */
	public static function synchronize( int $blog_id ) {
		return ( new self() )->synchronizeHall( $blog_id );
	}

	/**
	 * The ADR-042 trusted provisioning full pull.
	 *
	 * WHY THIS EXISTS AT ALL. synchronizeHall() refuses a Hall whose
	 * HallCommerceState is `provisioning` or `suspended`, and that refusal is
	 * correct: it is a policy about the Hall, so no capability and no caller may
	 * lift it. But the enable path must synchronize a Hall that is
	 * `provisioning` BY CONSTRUCTION — the transitional state is written before
	 * the pull, and the site stays archived until the pull, readiness and
	 * operational state have all succeeded. There is no order of the committed
	 * steps in which the ordinary gate could be satisfied.
	 *
	 * WHY THIS IS NOT A "SKIP THE ACCESS CHECK" FLAG. The gate is not bypassed
	 * by an argument; it is replaced by a STRONGER proof that a caller cannot
	 * fabricate:
	 *
	 *   - the Hall provisioning mutex must be held for THIS `portal_hall_id`,
	 *     and the SERVER must still confirm it;
	 *   - the operation row must still carry this exact `operation_id` /
	 *     `intent_sequence` fence;
	 *   - the mapping must be this Hall's own active mapping for this
	 *     `blog_id`, resolved through the repository — never from an argument;
	 *   - the site's `bc_commerce_managed` witness must equal the operation's
	 *     permanent `site_ownership_id`.
	 *
	 * LOCK ORDER IS PRESERVED. The provisioning mutex is already held by the
	 * caller; PortalSyncLock is acquired here, SECOND, exactly as ADR-042
	 * requires. ProvisioningMutex::acquire() independently refuses the reverse
	 * order, so neither direction depends on this comment.
	 *
	 * THE FENCE IS RE-PROVEN AFTER HTTP AND IMMEDIATELY BEFORE APPLICATION. A
	 * Portal round-trip takes seconds, which is long enough for a newer Disable
	 * to supersede this command; applying a snapshot for a command that is no
	 * longer current would write Portal data into a Hall that has since been
	 * suspended.
	 *
	 * EVERYTHING ELSE IS THE COMMITTED PATH. The fetcher, the validator, the
	 * plan and the transaction transport are the same objects the manual screen
	 * uses, and synchronizeHall() itself is untouched.
	 *
	 * @param array{operation_id:string,intent_sequence:int} $fence
	 * @return PortalSyncResult|\WP_Error
	 */
	public function synchronizeForProvisioning(
		ProvisioningMutex $mutex,
		int $portal_hall_id,
		int $blog_id,
		string $site_ownership_id,
		array $fence
	) {
		if ( ! is_multisite() ) {
			return self::error( 'multisite_required', 'Portal synchronization requires WordPress Multisite.' );
		}
		if ( $portal_hall_id < 1 || $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The provisioning synchronization target is invalid.' );
		}
		if ( ! Migrations::is_current() ) {
			return self::error( 'mapping_schema_unavailable', 'The Portal Hall mapping schema is unavailable.' );
		}

		// Proof BEFORE the sync lock, so a stale command never even takes it.
		$current = ProvisioningOperationRepository::guard( $mutex, $portal_hall_id, $fence, false );
		if ( $current instanceof \WP_Error ) {
			return $current;
		}

		$mapping = ( new HallMappingRepository() )->findActiveByBlogIdForProvisioning(
			$mutex,
			$portal_hall_id,
			$blog_id,
			$site_ownership_id
		);
		if ( $mapping instanceof \WP_Error ) {
			return $mapping;
		}
		if ( null === $mapping ) {
			return self::error( 'mapping_not_found', 'No active Portal Hall mapping exists for this site.' );
		}
		if ( (int) $mapping['portal_hall_id'] !== $portal_hall_id ) {
			// The site is mapped, but to a different Hall. Never synchronize
			// one Hall's Portal data into another Hall's replica.
			return self::error( 'mapping_not_found', 'The Portal Hall mapping does not match this Hall.' );
		}

		// Rank 1, taken only after the provisioning mutex is already held.
		$lock = PortalSyncLock::acquire( $blog_id );
		if ( $lock instanceof \WP_Error ) {
			return $lock;
		}

		try {
			return $this->run_provisioning( $mutex, $portal_hall_id, $blog_id, $fence, $lock );
		} finally {
			$lock->release();
		}
	}

	/** @return PortalSyncResult|\WP_Error */
	private function run_provisioning(
		ProvisioningMutex $mutex,
		int $portal_hall_id,
		int $blog_id,
		array $fence,
		PortalSyncLock $lock
	) {
		global $wpdb;

		$network_id   = get_current_network_id();
		$main_site_id = (int) get_main_site_id( $network_id );

		/*
		 * THE STACK DEPTH IS RECORDED BEFORE THE SWITCH, AND THE try WRAPS THE
		 * SWITCH ITSELF.
		 *
		 * switch_to_blog() pushes onto $GLOBALS['_wp_switched_stack'] as its
		 * FIRST action, changes $wpdb, $blog_id and $table_prefix next, and only
		 * then fires the `switch_blog` action. A callback on that action that
		 * throws therefore leaves the context ALREADY MOVED and the stack
		 * ALREADY PUSHED — while a `try` opened after switch_to_blog() returns
		 * has not begun, so nothing would restore it and the rest of the request
		 * would run against the Hall's tables believing it was on the network
		 * site.
		 *
		 * Unwinding by DEPTH rather than by a boolean also means only the
		 * switches THIS operation introduced are popped: an outer switched
		 * context the caller established is preserved exactly.
		 */
		$entry_blog_id = get_current_blog_id();
		$entry_depth   = self::switch_depth();
		$entry_prefix  = ( $wpdb instanceof \wpdb ) ? (string) $wpdb->prefix : '';

		/*
		 * THE RESULT IS CALCULATED, THEN THE CONTEXT IS RESTORED, AND ONLY THEN
		 * IS ANYTHING RETURNED.
		 *
		 * `return` from inside a `try` whose `finally` merely LOGS a failed
		 * restoration hands the caller a success while $wpdb may still be
		 * pointing at the Hall's tables — and this method's caller goes on to
		 * write Commerce state, clear `archived` and confirm enforcement. The
		 * restoration outcome has to be able to change the answer, so the answer
		 * is not committed to until restoration has been proven.
		 */
		$outcome = null;

		try {
			switch_to_blog( $blog_id );

			$outcome = $this->apply_provisioning_snapshot( $mutex, $portal_hall_id, $blog_id, $fence, $lock, (int) $network_id, $main_site_id );
		} catch ( \Throwable $throwable ) {
			/*
			 * This method's declared boundary is PortalSyncResult|WP_Error, so a
			 * Throwable from a `switch_blog` callback, from the fetcher, from
			 * validation or from the transport is contained here rather than
			 * crossing it. The exception is never read: its message and trace can
			 * quote SQL, paths and stored values.
			 */
			unset( $throwable );
			$outcome = self::error( 'portal_sync_context_unverified', 'The provisioning synchronization could not be completed.' );
		}

		/*
		 * Restoration unwinds ONLY the switches this operation introduced, one
		 * CONTAINED attempt at a time — an outer switched context the caller
		 * established is preserved exactly. See restore_entry_context().
		 */
		$restored = self::restore_entry_context( $entry_depth, $entry_blog_id, $entry_prefix );

		if ( ! $restored ) {
			// One fixed line, no stored value, no identifier, no exception text.
			error_log( self::CONTEXT_NOT_RESTORED_MESSAGE );

			/*
			 * UNCERTAIN CONTEXT IS NEVER A SUCCESSFUL SYNCHRONIZATION, and it is
			 * not an ordinary `sync_failed` either: provisioning work may already
			 * have been applied, and `sync_failed` invites Portal to retry as
			 * though the Portal were the problem. The orchestrator maps this code
			 * through its currentness rules, which answer `unverified` once
			 * anything may have changed.
			 */
			return self::error( 'portal_sync_context_unverified', 'The provisioning synchronization context could not be restored.' );
		}

		return $outcome;
	}

	/**
	 * The provisioning snapshot sequence, run with the Hall already switched to.
	 *
	 * Split out so run_provisioning() can own the switch, the containment and
	 * the restoration proof in one place, without a `return` from inside the
	 * protected region deciding the answer before restoration has been checked.
	 *
	 * @param array{operation_id:string,intent_sequence:int} $fence
	 * @return PortalSyncResult|\WP_Error
	 */
	private function apply_provisioning_snapshot(
		ProvisioningMutex $mutex,
		int $portal_hall_id,
		int $blog_id,
		array $fence,
		PortalSyncLock $lock,
		int $network_id,
		int $main_site_id
	) {
		$schema = $this->assert_snapshot_schemas();
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}

		// --- Portal round-trip, entirely outside the transaction --------------
		$body = call_user_func( $this->fetcher, $portal_hall_id );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		if ( ! is_string( $body ) ) {
			return self::error( 'portal_snapshot_unavailable', 'The Portal snapshot response was unusable.' );
		}

		$snapshot = $this->validator->validate( $body, $portal_hall_id );
		if ( $snapshot instanceof \WP_Error ) {
			return $snapshot;
		}
		unset( $body );

		// RE-PROVEN AFTER HTTP. Seconds have passed; a newer Disable may have
		// superseded this command while the Portal was answering.
		$still_current = ProvisioningOperationRepository::guard( $mutex, $portal_hall_id, $fence, false );
		if ( $still_current instanceof \WP_Error ) {
			return $still_current;
		}

		$plan = PortalSnapshotApplicationPlan::forProvisioning(
			$mutex,
			$blog_id,
			$portal_hall_id,
			$network_id,
			$main_site_id,
			$snapshot,
			current_time( 'mysql', true )
		);
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		// RE-PROVEN IMMEDIATELY BEFORE APPLICATION, with nothing but plan
		// construction between this check and the transaction.
		$final = ProvisioningOperationRepository::guard( $mutex, $portal_hall_id, $fence, false );
		if ( $final instanceof \WP_Error ) {
			return $final;
		}

		// --- Atomic application ----------------------------------------------
		$result = PortalSnapshotTransactionTransport::apply( $plan, $lock );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		PortalSyncState::invalidateCache();

		return $result;
	}

	/**
	 * Synchronize one mapped hall from the Portal.
	 *
	 * @return PortalSyncResult|\WP_Error
	 */
	public function synchronizeHall( int $blog_id ) {
		if ( ! is_multisite() ) {
			return self::error( 'multisite_required', 'Portal synchronization requires WordPress Multisite.' );
		}
		if ( $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		/*
		 * Commerce access, BEFORE the advisory lock and before any Portal
		 * request. A blocked Hall must not synchronize through the admin button
		 * or a direct service call, and Network Super Admin recovery access does
		 * not lift that restriction: this is a policy about the Hall, not about
		 * the caller, so no capability is consulted here. Operational and legacy
		 * Halls are unaffected and reach exactly the checks they always did.
		 */
		if ( HallCommerceAccess::BLOCK === HallCommerceAccess::evaluate( $blog_id )['access'] ) {
			return self::error( 'hall_commerce_blocked', 'Portal synchronization is unavailable while this hall is blocked.' );
		}

		if ( ! Migrations::is_current() ) {
			return self::error( 'mapping_schema_unavailable', 'The Portal Hall mapping schema is unavailable.' );
		}

		// Rank 0. Held across mapping resolution, fetch, validation, and
		// application, and released in finally.
		$lock = PortalSyncLock::acquire( $blog_id );
		if ( $lock instanceof \WP_Error ) {
			return $lock;
		}

		try {
			return $this->run( $blog_id, $lock );
		} finally {
			$lock->release();
		}
	}

	/** @return PortalSyncResult|\WP_Error */
	private function run( int $blog_id, PortalSyncLock $lock ) {
		$mapping = ( new HallMappingRepository() )->findActiveByBlogId( $blog_id );
		if ( $mapping instanceof \WP_Error ) {
			return $mapping;
		}
		if ( null === $mapping ) {
			return self::error( 'mapping_not_found', 'No active Portal Hall mapping exists for this site.' );
		}

		$portal_hall_id = (int) $mapping['portal_hall_id'];
		$network_id     = get_current_network_id();
		$main_site_id   = (int) get_main_site_id( $network_id );

		switch_to_blog( $blog_id );

		try {
			$schema = $this->assert_snapshot_schemas();
			if ( $schema instanceof \WP_Error ) {
				return $schema;
			}

			// --- Portal round-trip, entirely outside the transaction --------------
			$body = call_user_func( $this->fetcher, $portal_hall_id );
			if ( $body instanceof \WP_Error ) {
				return $body;
			}
			if ( ! is_string( $body ) ) {
				return self::error( 'portal_snapshot_unavailable', 'The Portal snapshot response was unusable.' );
			}

			$snapshot = $this->validator->validate( $body, $portal_hall_id );
			if ( $snapshot instanceof \WP_Error ) {
				return $snapshot;
			}
			unset( $body );

			$plan = PortalSnapshotApplicationPlan::fromValidatedSnapshot(
				$blog_id,
				$portal_hall_id,
				(int) $network_id,
				$main_site_id,
				$snapshot,
				current_time( 'mysql', true )
			);
			if ( $plan instanceof \WP_Error ) {
				return $plan;
			}

			// --- Atomic application ----------------------------------------------
			$result = PortalSnapshotTransactionTransport::apply( $plan, $lock );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			// Only after a CONFIRMED commit. The marker row may have been created
			// inside the transaction, and a prior get_option() miss would otherwise
			// keep it invisible behind the negative 'notoptions' cache entry.
			PortalSyncState::invalidateCache();

			return $result;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The depth of WordPress's own switched-blog stack.
	 *
	 * Read from the global rather than tracked locally, because that global is
	 * what restore_current_blog() actually pops.
	 */
	private static function switch_depth(): int {
		return is_array( $GLOBALS['_wp_switched_stack'] ?? null )
			? count( $GLOBALS['_wp_switched_stack'] )
			: 0;
	}

	/**
	 * Unwind to the recorded entry context, ONE CONTAINED ATTEMPT AT A TIME.
	 *
	 * A single `try` around the whole unwind loop was wrong in a specific way.
	 * restore_current_blog() POPS THE STACK FIRST, re-points `$wpdb`, `$blog_id`
	 * and `$table_prefix` next, and fires `switch_blog` LAST — so a callback on
	 * that action which throws has already consumed one level. Catching that
	 * outside the loop abandoned every remaining level: with two or more
	 * switches open, the request carried on with `$wpdb` still pointing at the
	 * Hall while the method reported only that restoration was unproven.
	 *
	 * So every attempt is contained on its own. The depth is recorded BEFORE
	 * each attempt and re-read AFTER it returns OR throws, and unwinding
	 * continues only while the depth is making valid progress toward the entry
	 * depth. It stops — safely — on no progress, on underflow past the entry
	 * depth, on a stack global that is no longer a list, and on an exhausted
	 * budget of the levels that existed when restoration began. `$budget` is
	 * fixed before the first attempt precisely so a stack that somebody else is
	 * concurrently growing cannot turn this into an unbounded loop.
	 *
	 * A THROW STILL FAILS THE OPERATION, even when the unwind afterwards lands
	 * exactly on the entry facts. `switch_blog` is where the object cache,
	 * WooCommerce and Action Scheduler re-point themselves; one of them raised
	 * an exception mid-restore, and three globals agreeing does not prove that
	 * the rest of the request is safe. Unwinding continues past the throw all
	 * the same, because leaving levels open would be worse than either.
	 *
	 * NOTHING IS EVER ASSIGNED HERE. No stack is replaced, no `$GLOBALS['blog_id']`
	 * or `$GLOBALS['switched']` is written, no `$wpdb->set_blog_id()` is called.
	 * Forcing the globals would manufacture the very agreement this proof exists
	 * to test, and would leave every other subsystem still switched.
	 *
	 * @return bool True only when no attempt threw AND the stack depth, the blog
	 *              id and the table prefix all agree with the entry facts.
	 */
	private static function restore_entry_context( int $entry_depth, int $entry_blog_id, string $entry_prefix ): bool {
		global $wpdb;

		$threw  = false;
		$budget = self::switch_depth() - $entry_depth;

		for ( $attempt = 0; $attempt < $budget; $attempt++ ) {
			if ( ! self::switch_stack_is_list() ) {
				// No longer something restore_current_blog() can pop. Stop
				// rather than call into it again.
				return false;
			}

			$before = self::switch_depth();
			if ( $before <= $entry_depth ) {
				// Already back. Nothing of this operation's is left to pop.
				break;
			}

			try {
				restore_current_blog();
			} catch ( \Throwable $throwable ) {
				// Never read: its message and trace can quote SQL, paths and
				// stored values. It is latched, not re-raised.
				unset( $throwable );
				$threw = true;
			}

			$after = self::switch_depth();
			if ( $after >= $before || $after < $entry_depth ) {
				// No progress at all, or unwound PAST where this operation
				// started — which would be popping a caller's own context.
				return false;
			}
		}

		if ( $threw ) {
			return false;
		}

		return self::switch_depth() === $entry_depth
			&& self::switch_stack_is_list()
			&& get_current_blog_id() === $entry_blog_id
			&& ( ! $wpdb instanceof \wpdb || (string) $wpdb->prefix === $entry_prefix );
	}

	/**
	 * Is WordPress's switched-blog stack still something that can be popped?
	 *
	 * Absent is valid and means depth zero: `switch_to_blog()` creates the
	 * global on its first use, so a request that has never switched has none.
	 * Present-but-not-an-array is NOT valid — restore_current_blog() would call
	 * array_pop() on it.
	 */
	private static function switch_stack_is_list(): bool {
		return ! array_key_exists( '_wp_switched_stack', $GLOBALS )
			|| is_array( $GLOBALS['_wp_switched_stack'] );
	}

	/**
	 * Structural readiness of EXACTLY what a snapshot touches (ADR-043 §11.3).
	 *
	 * WHY THIS IS NOT A VERSION COMPARISON ANY MORE.
	 *
	 * The previous gate asked `Catalog\Migrations::is_current()` and
	 * `Sessions\Migrations::is_current()`, which compare a whole PLUGIN's
	 * recorded schema version against the version its code declares. That reads
	 * as caution and is actually the opposite: it couples Portal synchronization
	 * to every future migration in either plugin, whether or not synchronization
	 * touches what changed.
	 *
	 * ADR-043 makes that concrete. It raises `Sessions\Migrations::DB_VERSION`
	 * to 3 for three new schedule tables and seven new `bingo_sessions` columns —
	 * and a snapshot touches NONE of them. Under the old gate, the moment that
	 * constant changed, every hall still recorded at version 2 would have been
	 * refused synchronization until it migrated, for a schema change with no
	 * bearing on the data being synchronized. Halls would have stopped receiving
	 * Portal updates because of a table they do not use.
	 *
	 * So the question asked here is the one that actually matters: is the
	 * structure this transport WRITES INTO intact? Three things, and nothing
	 * else:
	 *
	 *  1. the Game Type replica — `…/PortalSnapshotTransactionTransport.php:406`,
	 *     `:591-594`, `:623-624`;
	 *  2. the Portal Session-template replica — `:675-750`;
	 *  3. sync-state storage, which is the per-site option
	 *     `bc_portal_snapshot_sync_state` (`…/PortalSyncState.php:31`) and not a
	 *     custom table. It needs no check here: the transport already asserts the
	 *     options table exists and is InnoDB through `REQUIRED_INNODB_TABLES`
	 *     (`…:101-107`).
	 *
	 * STILL FAIL-CLOSED, and in the same direction as before. Damaged required
	 * replica structure refuses the sync; an unavailable schema is never read as
	 * "this hall has no Game Types", which under ADR-038 is the difference
	 * between a rejected sync and a silent integrity decision. What changed is
	 * the SCOPE of the question, not its strictness — damage to the v3 schedule
	 * structures is now correctly out of scope, and damage to a replica is still
	 * fatal.
	 *
	 * STRUCTURE ONLY — THE STORAGE ENGINE IS DELIBERATELY NOT CHECKED HERE.
	 * `PortalSnapshotTransactionTransport` already asserts InnoDB for both
	 * replicas through `REQUIRED_INNODB_TABLES` (`…:101-107`), and it does so
	 * AFTER the fetch and immediately before `BEGIN`, reporting its own
	 * `portal_sync_storage_engine_unsupported`. Checking the engine here as well
	 * would move that decision ahead of the fetch and replace a specific error
	 * code with a vaguer one, changing behaviour this ADR does not set out to
	 * change. §11.3's own table says the same thing about sync-state storage:
	 * "the transport already asserts" it. So the structure-only verifiers are
	 * used, and the engine stays with its owner.
	 *
	 * READ-ONLY. Both verifiers issue `SHOW TABLES`, `SHOW FULL COLUMNS`,
	 * `SHOW INDEX`, a collation comparison and one indexed `COUNT(*)`. Neither
	 * runs `dbDelta()`, creates or alters a table, or advances a version marker:
	 * this is a read path, and a read path that repaired schema as a side effect
	 * would migrate a hall nobody asked to migrate. A test asserts this by
	 * intercepting every statement the path emits.
	 *
	 * Precedent: `SetupReadiness::check_domain_schema()`
	 * (`…/SetupReadiness.php:725-740`) already works this way — structure first,
	 * version marker second.
	 *
	 * @return true|\WP_Error
	 */
	public function assert_snapshot_schemas() {
		if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) || ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
			return self::error( 'portal_sync_domain_unavailable', 'The Catalog and Session plugins are required for Portal synchronization.' );
		}

		/*
		 * The Game Type replica. verify_game_types_schema() also proves the
		 * obsolete `name_unique` index is gone and that every stored
		 * `local_name_key` matches its row identity — both properties the
		 * transport's conflict handling depends on, so neither is dropped merely
		 * because this gate got narrower.
		 */
		$game_types = \BingoCommerce\Catalog\Migrations::verify_game_types_structure_for_sync();
		if ( is_wp_error( $game_types ) ) {
			return self::error( 'portal_sync_schema_unavailable', 'The Game Type replica structure is not ready for this hall.' );
		}

		$templates = \BingoCommerce\Sessions\Migrations::verify_portal_session_templates_structure();
		if ( is_wp_error( $templates ) ) {
			return self::error( 'portal_sync_schema_unavailable', 'The Portal Session Template replica structure is not ready for this hall.' );
		}

		return true;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ), [ 'status' => $code ] );
	}
}
