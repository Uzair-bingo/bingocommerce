<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ADR-042 provisioning state machine.
 *
 * IT OWNS SEQUENCING AND NOTHING ELSE'S JOB. Authentication, route registration
 * and manual request validation belong to ProvisioningRestController; the ledger
 * belongs to ProvisioningOperationRepository; WordPress site creation and
 * per-site initialization belong to `bingocommerce-platform` behind one filter;
 * the disclosure surface belongs to ProvisioningResponse; the terminal audit
 * belongs to ProvisioningAudit. This class decides ORDER, and proves at each
 * step that it is still allowed to take the next one.
 *
 * THE TWO INVARIANTS EVERYTHING ELSE HANGS OFF:
 *
 *   1. THE MUTEX AND THE FENCE ARE RE-PROVEN IMMEDIATELY BEFORE EVERY
 *      IRREVERSIBLE OR EXTERNALLY VISIBLE MUTATION. Not once at the top: a
 *      Portal round-trip and a WooCommerce install take seconds, and a newer
 *      Disable can arrive inside either. `guard()` asks the SERVER whether the
 *      lock is still held and re-reads the row, so neither answer is inferred.
 *
 *   2. THE FAILURE MAPPING DEPENDS ON WHETHER ANYTHING MAY ALREADY HAVE
 *      CHANGED. Before the first mutation a lost proof is `lock_unavailable` —
 *      honest, and safely retryable. After it, the same loss is `unverified`,
 *      because telling Portal "nothing happened" when a site may exist is the
 *      one lie that produces a second site.
 *
 * IT NEVER DELETES AND NEVER REPAIRS. No path removes a site, un-maps a Hall,
 * rewrites an ownership marker, re-points a mapping, re-archives an older site,
 * or resolves a mapping disagreement. Every one of those is an explicit operator
 * procedure, and a machine endpoint that "fixed" them would destroy the evidence
 * an operator needs.
 */
final class ProvisioningOrchestrator {

	/* Mapping inspection outcomes. Distinct on purpose: see inspect_mapping(). */
	private const MAPPING_EXACT      = 'exact';
	private const MAPPING_ABSENT     = 'absent';
	private const MAPPING_CONFLICT   = 'conflict';
	private const MAPPING_UNREADABLE = 'unreadable';

	/**
	 * Every internal code that means "this command is no longer current".
	 *
	 * They arrive from four different components — the repository's guard, the
	 * repository's own fence checks, the provisioning-scoped mapping bind, and
	 * the provisioning application plan — and they all mean the same thing, so
	 * they are normalised in ONE place (currentness()). Left to
	 * ProvisioningResponse::category() they would scatter: `lock_unavailable`
	 * would survive into a reduced body after a site had already been created,
	 * and `portal_sync_plan_not_current` would match the `portal_sync_` prefix
	 * and be reported as a retryable `sync_failed`.
	 */
	private const CURRENTNESS_CODES = [
		'lock_unavailable',
		'mapping_provisioning_not_current',
		'portal_sync_plan_not_current',
		/*
		 * An unproven blog context after provisioning work. Not a currentness
		 * failure in the fence sense, but it belongs here for the same reason:
		 * once the pull may have applied, "we are not sure" must be `unverified`
		 * and must never be reported as a retryable `sync_failed` — and before
		 * any mutation it is the honest `lock_unavailable`.
		 */
		'portal_sync_context_unverified',
	];

	/** Immutable terminal snapshot, fixed before the mutex is released. */
	private array $captured = [];

	/** True once anything irreversible or externally visible may have changed. */
	private bool $mutated = false;

	/**
	 * The operation row this command is acting on, once one is SAFELY ESTABLISHED.
	 *
	 * Null through the whole of classification, and set at exactly the two points
	 * where the machine commits to acting on a row: when run() dispatches to a
	 * branch, and when a marker-matched partial site is attached and the row
	 * changes underneath the suspend. Held on the instance for one reason —
	 * finalize_after_throw() is reached from outside every frame that had the row
	 * in a local, and "is there a row?" is the question that decides whether a
	 * Throwable may touch stored state at all.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $row = null;

	/**
	 * The Hall mutex for this request, once acquired.
	 *
	 * Held on the instance rather than threaded through every method because
	 * the LAST thing before a terminal snapshot is fixed — success or failure —
	 * is a currentness proof, and those snapshot methods are reached from a
	 * dozen places that have no business also carrying a lock.
	 */
	private ?ProvisioningMutex $mutex = null;

	private int $portal_hall_id = 0;
	private array $request      = [];
	private array $fence        = [];
	private int $actor_user_id  = 0;

	/** @var callable(int,int,string,array,ProvisioningMutex):(mixed) */
	private $synchronizer;

	/**
	 * @param callable|null $synchronizer Provisioning full-pull. The default is
	 *        the committed PortalSyncService trusted path; harnesses inject a
	 *        fixture transport so application behaviour is proven without a live
	 *        Portal. It is the FETCHER that is replaceable, never the mapping,
	 *        the locks, the fence or the transaction.
	 */
	public function __construct( ?callable $synchronizer = null ) {
		$this->synchronizer = $synchronizer ?? static function (
			int $portal_hall_id,
			int $blog_id,
			string $site_ownership_id,
			array $fence,
			ProvisioningMutex $mutex
		) {
			return ( new PortalSyncService() )->synchronizeForProvisioning(
				$mutex,
				$portal_hall_id,
				$blog_id,
				$site_ownership_id,
				$fence
			);
		};
	}

	/**
	 * Run one validated command to a terminal response.
	 *
	 * @param array<string,mixed> $request  ProvisioningCanonicalRequest output.
	 * @param int                 $actor_user_id Authenticated App Password account.
	 */
	public function handle( array $request, int $actor_user_id ): \WP_REST_Response {
		$this->request        = $request;
		$this->portal_hall_id = (int) $request['portal_hall_id'];
		$this->actor_user_id  = $actor_user_id;
		$this->fence          = [
			'operation_id'    => (string) $request['operation_id'],
			'intent_sequence' => (int) $request['intent_sequence'],
		];

		/*
		 * THE HALL MUTEX, AND ONLY THIS HALL'S. ProvisioningMutex is keyed by
		 * `portal_hall_id`, so two Halls provision concurrently; cross-Hall
		 * address exclusion is UNIQUE(site_slug) in the ledger and never a
		 * global lock. It also refuses to be taken while a sync lock is held,
		 * which is what keeps the ADR-042 lock order from depending on this
		 * comment.
		 */
		$mutex = ProvisioningMutex::acquire( $this->portal_hall_id );
		if ( $mutex instanceof \WP_Error ) {
			/*
			 * NO AUDIT HERE, and the reduced body. Classification was never
			 * reached, so there is no operation identity to attribute and no
			 * terminal outcome to record — ADR-042 lists a pre-classification
			 * lock failure among the paths that write no provisioning audit.
			 */
			return ProvisioningResponse::reduced( 'lock_unavailable', 503, $this->portal_hall_id );
		}

		$this->mutex = $mutex;

		try {
			$response = $this->run( $mutex );
		} catch ( \Throwable $throwable ) {
			/*
			 * Contained at the state machine's own boundary. The exception is
			 * never read: its message and trace can quote SQL, stored values and
			 * paths. Everything else about how this ends is decided by the
			 * finalizer, which — unlike the bare terminal() this replaced — knows
			 * whether a row exists, whether the command is still current, and
			 * whether the failure belongs on the row.
			 */
			unset( $throwable );
			$response = $this->finalize_after_throw();
		} finally {
			// Released only after the terminal snapshot above is fixed.
			$this->mutex = null;
			$mutex->release();
		}

		/*
		 * EXACTLY ONE TERMINAL AUDIT ATTEMPT, from the immutable captured
		 * outcome, after the mutex is released. It cannot change the response —
		 * the response object already exists — which is precisely why an
		 * ordinary AuditLog failure must not roll back a confirmed provisioning
		 * result.
		 */
		if ( [] !== $this->captured ) {
			ProvisioningAudit::record( $this->captured );
		}

		return $response;
	}

	/* ------------------------------------------------------------------ *
	 * Classification
	 * ------------------------------------------------------------------ */

	private function run( ProvisioningMutex $mutex ): \WP_REST_Response {
		$stored = ProvisioningOperationRepository::load( $this->portal_hall_id );

		if ( $stored instanceof \WP_Error ) {
			/*
			 * UNREADABLE or CORRUPT/IMPOSSIBLE stored state. Both fail closed as
			 * `unverified` with every state and site field null, no row
			 * mutation, and exactly one terminal `portal.commerce.unverified`
			 * audit carrying safe request identity and a null blog_id. The two
			 * are deliberately not distinguished to Portal: naming which one it
			 * was would describe stored state to a caller that must not learn it.
			 */
			return $this->terminal( 'unverified' );
		}

		$classification = ProvisioningOperationRepository::classify( $stored, $this->request );
		if ( $classification instanceof \WP_Error ) {
			return $this->terminal( $this->currentness( $classification, 'internal_error' ) );
		}

		if ( ProvisioningOperationRepository::CLASSIFY_SUPERSEDED === $classification ) {
			// Zero mutation, and never touches the current row's error field.
			return $this->terminal( 'superseded' );
		}
		if ( ProvisioningOperationRepository::CLASSIFY_CONFLICT === $classification ) {
			return $this->terminal( 'conflict' );
		}

		$row = ( ProvisioningOperationRepository::CLASSIFY_EXACT_RETRY === $classification )
			? $stored
			: $this->accept_new_command( $stored );

		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		// CLASSIFICATION IS OVER AND A ROW IS ESTABLISHED. From here a Throwable
		// has something to fence against and something to stamp; before here it
		// deliberately has neither.
		$this->row = $row;

		return ProvisioningCanonicalRequest::SUSPENDED === $row['desired_state']
			? $this->suspend( $mutex, $row )
			: $this->enable( $mutex, $row );
	}

	/**
	 * Persist an accepted new command (precedence (e)), or refuse it.
	 *
	 * THE RELATIONAL COMMAND-SHAPE RULES LIVE HERE, not in the pre-mutex
	 * validator, because they are unanswerable before durable state is known: a
	 * null address is correct for a suspend and for a post-ownership enable, and
	 * wrong for a first enable. They are ADR-042 lifecycle rules, so they fail as
	 * `conflict` with ZERO mutation — never as the pre-mutex `validation_failed`,
	 * which would tell Portal to fix a request that is in fact well formed.
	 *
	 * @param array<string,mixed>|null $stored
	 * @return array<string,mixed>|\WP_REST_Response
	 */
	private function accept_new_command( ?array $stored ) {
		$operational = ProvisioningCanonicalRequest::OPERATIONAL === $this->request['desired_state'];
		$bound       = null !== $stored && null !== $stored['blog_id'];

		if ( $operational ) {
			if ( $bound && ( null !== $this->request['site_slug'] || null !== $this->request['hall_name'] ) ) {
				/*
				 * ADR-042 null rule 5: once durable ownership exists an
				 * operational command carries JSON null for both. A non-null
				 * slug is an attempted RENAME of a durably bound site — an
				 * identity conflict, explicitly `conflict` and not
				 * `slug_conflict`, with zero row mutation.
				 */
				return $this->terminal( 'conflict' );
			}
			if ( ! $bound && ( null === $this->request['site_slug'] || null === $this->request['hall_name'] ) ) {
				// Before durable ownership there is no address to inherit, so a
				// command that names none describes a site nobody could create.
				return $this->terminal( 'conflict' );
			}
		}

		if ( null === $stored ) {
			$inserted = ProvisioningOperationRepository::insert_first_command( $this->request );
			if ( $inserted instanceof \WP_Error ) {
				return $this->terminal( $this->currentness( $inserted, 'internal_error' ) );
			}

			if ( 'stored' === $inserted['outcome'] ) {
				// Accepted-command persistence is inside ADR-042's protected
				// mutation scope, so from here a lost proof is `unverified`
				// rather than "nothing happened".
				$this->mutated = true;

				return $inserted['row'];
			}
			if ( 'raced' === $inserted['outcome'] ) {
				/*
				 * Another writer won the PRIMARY KEY race. Re-classify against
				 * what is ACTUALLY stored rather than assume anything about it;
				 * this cannot recurse further, because the row now exists.
				 */
				return $this->reclassify_after_race( $inserted['row'] );
			}

			// A foreign row already holds this operation_id or this site_slug.
			// Conflict, zero mutation, and no hint of which Hall holds it.
			return $this->terminal( 'conflict' );
		}

		$replaced = ProvisioningOperationRepository::replace_command( $stored, $this->request );
		if ( $replaced instanceof \WP_Error ) {
			return $this->terminal( $this->currentness( $replaced, 'internal_error' ), $stored['blog_id'] );
		}

		$this->mutated = true;

		return $replaced;
	}

	/**
	 * @param array<string,mixed>|null $row
	 * @return array<string,mixed>|\WP_REST_Response
	 */
	private function reclassify_after_race( ?array $row ) {
		if ( ! is_array( $row ) ) {
			return $this->terminal( 'unverified' );
		}

		$classification = ProvisioningOperationRepository::classify( $row, $this->request );
		if ( $classification instanceof \WP_Error ) {
			return $this->terminal( $this->currentness( $classification, 'internal_error' ) );
		}
		if ( ProvisioningOperationRepository::CLASSIFY_EXACT_RETRY === $classification ) {
			return $row;
		}
		if ( ProvisioningOperationRepository::CLASSIFY_SUPERSEDED === $classification ) {
			return $this->terminal( 'superseded' );
		}

		return $this->terminal( 'conflict' );
	}

	/* ------------------------------------------------------------------ *
	 * Suspend
	 * ------------------------------------------------------------------ */

	/**
	 * Disable means SUSPEND, and suspension preserves everything.
	 *
	 * NO PLATFORM FILTER ON ANY SUSPEND PATH. A suspend never creates,
	 * initializes or resumes a site, so `platform_unavailable` can never block
	 * safe suspension — which is the whole reason ADR-042 keeps the filter out
	 * of this branch.
	 *
	 * @param array<string,mixed> $row
	 */
	private function suspend( ProvisioningMutex $mutex, array $row ): \WP_REST_Response {
		if ( null !== $row['blog_id'] ) {
			return $this->suspend_bound( $mutex, $row );
		}

		if ( null === $row['site_ownership_id'] ) {
			/*
			 * PRE-BIND, NO OWNERSHIP. Automatic creation could not previously
			 * have started, so there is nothing to find and nothing to bind. An
			 * unrelated site may well sit at a reserved address; it is not this
			 * Hall's and is deliberately not touched, inspected or reported.
			 */
			return $this->cancel_without_site( $mutex, $row );
		}

		// Ownership exists but nothing is bound: this Hall's own create may have
		// started. Resolve the reserved address and decide by the MARKER.
		$address = PlatformGateway::resolveAddress( (string) $row['site_slug'] );
		if ( $address instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $address, 'platform_unavailable' ) );
		}

		$existing = PlatformGateway::existingSiteId( $address );
		if ( $existing instanceof \WP_Error ) {
			return $this->fail( $row, 'platform_unavailable' );
		}
		if ( null === $existing ) {
			// Ownership ID is PRESERVED, never regenerated, on this path.
			return $this->cancel_without_site( $mutex, $row );
		}

		/*
		 * EVERY CANDIDATE FACT IS PROVEN BEFORE ANYTHING IS BOUND. Binding is
		 * durable: the row keeps that `blog_id` for the life of the Hall, and a
		 * wrong one cannot be taken back by the machine endpoint. The marker
		 * proves WHO owns the site; these prove the site is one this command may
		 * adopt at all.
		 */
		$candidate = $this->verify_partial_candidate( $row, $existing, $address );
		if ( is_string( $candidate ) ) {
			return $this->fail( $row, $candidate );
		}

		// MARKER-MATCHED PARTIAL SITE. Attach it under the current fence, then
		// suspend it exactly like a durable one — without initializing it, and
		// without disturbing `archived`.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		$this->mutated = true;
		$attached      = ProvisioningOperationRepository::attach_blog_id( $this->portal_hall_id, $existing, $this->fence );
		if ( $attached instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $attached, 'unverified' ) );
		}

		// The row has changed underneath this command; a later Throwable must
		// attribute itself to the site that was just bound, not to no site.
		$this->row = $attached;

		return $this->suspend_bound( $mutex, $attached );
	}

	/**
	 * Is this unbound candidate a site this command may safely attach?
	 *
	 * Proven from AUTHORITATIVE SITE STATE, before `attach_blog_id()`, because
	 * attachment is durable and the alternatives to refusing are all worse than
	 * refusing. Every refusal leaves the candidate completely untouched: not
	 * attached, not given Commerce state, not mapped, not initialized, not
	 * archived or unarchived, not repaired and not deleted — and names nothing
	 * about it.
	 *
	 * `archived = 1` is required here and nowhere else. Every site this platform
	 * creates is archived from insertion and stays archived until an ENABLE
	 * completes, so an unbound candidate that is NOT archived has had its
	 * lifecycle changed by something outside this lifecycle. Adopting it would
	 * bind a reachable store to a Hall that believes it is still quarantined.
	 *
	 * @param array<string,mixed> $row
	 * @param \BingoCommerce\Platform\HallSiteAddress $address
	 * @return true|string True, or an ADR-042 error category.
	 */
	private function verify_partial_candidate( array $row, int $candidate, $address ) {
		if ( $candidate < 1 ) {
			return 'site_ownership_unproven';
		}

		$site = get_site( $candidate );
		if ( ! $site instanceof \WP_Site ) {
			// The address query answered, but the site cannot be loaded. Never
			// read that as an empty address.
			return 'platform_unavailable';
		}

		$network_id = (int) get_current_network_id();
		if ( (int) $site->site_id !== $network_id ) {
			return 'site_ownership_unproven';
		}
		if ( is_main_site( $candidate, $network_id ) ) {
			return 'site_ownership_unproven';
		}
		if ( '1' === (string) $site->deleted || '1' === (string) $site->spam ) {
			return 'site_ownership_unproven';
		}
		if ( '1' !== (string) $site->archived ) {
			return 'site_ownership_unproven';
		}

		/*
		 * THE ADDRESS MUST RESOLVE UNIQUELY BACK TO THIS SITE. `existingSiteId()`
		 * already refuses an ambiguous address, and this re-reads it against the
		 * loaded row so a duplicate or a moved site cannot be adopted through a
		 * stale answer.
		 */
		if ( ! $address instanceof \BingoCommerce\Platform\HallSiteAddress || ! $address->matchesSite( $site ) ) {
			return 'site_ownership_unproven';
		}

		$resolved = PlatformGateway::existingSiteId( $address );
		if ( $resolved instanceof \WP_Error ) {
			return 'platform_unavailable';
		}
		if ( $resolved !== $candidate ) {
			return 'site_ownership_unproven';
		}

		$marker = HallCommerceAccess::read_marker_value( $candidate );
		if ( HallCommerceAccess::MARKER_VALID !== $marker['status']
			|| null === $marker['value']
			|| ! hash_equals( (string) $row['site_ownership_id'], (string) $marker['value'] ) ) {
			// Absent, different, invalid or unreadable: never bind, delete,
			// rewrite or claim a successful cancellation. An operator decides.
			return 'site_ownership_unproven';
		}

		return true;
	}

	/**
	 * Suspend a Hall whose operation row carries a `blog_id`.
	 *
	 * Covers both the durable operational site and the just-attached
	 * marker-matched partial one: ADR-042 gives them the same verification, the
	 * same mapping rules and the same response, and the only difference —
	 * whether the site happens to be archived — is a lifecycle fact this path
	 * must not change in either direction.
	 *
	 * @param array<string,mixed> $row
	 */
	private function suspend_bound( ProvisioningMutex $mutex, array $row ): \WP_REST_Response {
		$blog_id = (int) $row['blog_id'];

		$verified = $this->verify_bound_site( $row, true );
		if ( is_string( $verified ) ) {
			return $this->fail( $row, $verified );
		}

		/*
		 * Mapping is INSPECTED, never created and never repaired, on suspend.
		 * Absence is acceptable and stays absent; an exact mapping is preserved
		 * untouched; a conflict or an unreadable mapping table refuses the
		 * suspension rather than confirming one against state nobody verified.
		 */
		$mapping = $this->inspect_mapping( $row );
		if ( ! in_array( $mapping['state'], [ self::MAPPING_EXACT, self::MAPPING_ABSENT ], true ) ) {
			return $this->fail( $row, $mapping['category'] ?? 'mapping_invalid' );
		}

		$site_url = $this->authoritative_site_url( $blog_id );
		if ( null === $site_url ) {
			return $this->fail( $row, 'site_missing' );
		}

		if ( ProvisioningOperationRepository::is_confirmed( $row )
			&& $this->state_settled_at( $blog_id, HallCommerceState::SUSPENDED ) ) {
			// Confirmed in the ledger AND enforced on the site itself.
			return $this->success( ProvisioningResponse::ALREADY_APPLIED, ProvisioningResponse::STATE_SUSPENDED, $blog_id, $site_url );
		}

		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		$this->mutated = true;
		$written       = $this->write_state( $blog_id, HallCommerceState::SUSPENDED, HallCommerceState::PHASE_SETTLED );
		if ( null !== $written ) {
			return $this->fail( $row, $written );
		}

		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		$confirmed = ProvisioningOperationRepository::confirm_enforced_sequence( $this->portal_hall_id, $this->fence );
		if ( $confirmed instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $confirmed, 'confirmation_failed' ) );
		}

		/*
		 * NOTHING ELSE CHANGED. The site keeps its domain, its `archived` flag
		 * exactly as it was, its marker, its mapping, its slug, its ownership
		 * ID, its products, its orders and its settings. Suspension is a
		 * Commerce-state fact enforced by HallCommerceGate, not a site
		 * lifecycle operation.
		 */
		return $this->success( ProvisioningResponse::APPLIED, ProvisioningResponse::STATE_SUSPENDED, $blog_id, $site_url );
	}

	/**
	 * Pre-bind cancellation: confirm the suspend without creating anything.
	 *
	 * @param array<string,mixed> $row
	 */
	private function cancel_without_site( ProvisioningMutex $mutex, array $row ): \WP_REST_Response {
		if ( ProvisioningOperationRepository::is_confirmed( $row ) ) {
			return $this->success( ProvisioningResponse::ALREADY_APPLIED, ProvisioningResponse::STATE_SUSPENDED, null, null );
		}

		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		$this->mutated = true;
		$confirmed     = ProvisioningOperationRepository::confirm_enforced_sequence( $this->portal_hall_id, $this->fence );
		if ( $confirmed instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $confirmed, 'confirmation_failed' ) );
		}

		/*
		 * No site, no mapping, no per-site HallCommerceState, and — critically —
		 * the persisted `site_ownership_id`, if any, is left exactly as it is.
		 * Regenerating it would orphan a partial create that a later command
		 * still has to be able to recognise.
		 */
		return $this->success( ProvisioningResponse::APPLIED, ProvisioningResponse::STATE_SUSPENDED, null, null );
	}

	/* ------------------------------------------------------------------ *
	 * Enable
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $row
	 */
	private function enable( ProvisioningMutex $mutex, array $row ): \WP_REST_Response {
		// 0. Confirmed retry: `already_applied` only once the EXTERNAL facts agree.
		$settled = $this->already_operational( $row );
		if ( $settled instanceof \WP_REST_Response ) {
			return $settled;
		}
		if ( is_string( $settled ) ) {
			return $this->fail( $row, $settled );
		}

		// 1. Address and durable slug.
		if ( null === $row['blog_id'] ) {
			$reserved = $this->reserve_address( $mutex, $row );
			if ( $reserved instanceof \WP_REST_Response ) {
				return $reserved;
			}
			$row = $reserved;
		} else {
			/*
			 * AN ALREADY-BOUND ROW IS VERIFIED BEFORE THE PLATFORM IS ASKED TO
			 * DO ANYTHING. Two reasons, and the second is the important one:
			 *
			 *   - there is no point running a WooCommerce initialization against
			 *     a site this operation cannot prove it owns; and
			 *   - the platform consumer defends itself and answers
			 *     `site_ownership_unproven` for everything it refuses, which is
			 *     the right answer for a consumer that knows nothing about this
			 *     Hall's ledger. The ORCHESTRATOR can tell those cases apart —
			 *     a durable slug that no longer describes the bound site is a
			 *     same-Hall invariant failure and ADR-042 names it
			 *     `slug_conflict`, not ownership doubt — so it must be the one
			 *     that classifies them.
			 */
			$verified = $this->verify_bound_site( $row );
			if ( is_string( $verified ) ) {
				return $this->fail( $row, $verified );
			}
		}

		// 2. Permanent ownership identity, before the first platform create.
		if ( null === $row['site_ownership_id'] ) {
			$guard = $this->guard( $mutex );
			if ( null !== $guard ) {
				return $this->fail( $row, $guard );
			}

			$assigned = ProvisioningOperationRepository::assign_site_ownership_id( $this->portal_hall_id, $this->fence );
			if ( $assigned instanceof \WP_Error ) {
				return $this->fail( $row, $this->currentness( $assigned, 'state_write_failed' ) );
			}
			// The permanent ownership identity is now durable. It is never
			// regenerated, and from here a lost proof is `unverified`.
			$this->mutated = true;
			$row           = $assigned;
		}

		// 3. Guard, then the single platform invocation for this attempt.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		$this->mutated = true;
		$platform      = $this->invoke_platform( $row );

		/*
		 * 4. THE FENCE IS RE-PROVEN BEFORE THE PLATFORM RESULT IS INTERPRETED,
		 * and regardless of whether that result was a success or an error.
		 *
		 * The consumer may have created the site, initialized WooCommerce and
		 * then failed on a later step, so an error tells us nothing about
		 * whether the world changed — and a WooCommerce install takes long
		 * enough for a newer Disable to arrive inside it. Reporting the
		 * consumer's own category first would answer a command that is no longer
		 * current, and could answer `site_create_failed` for an attempt that
		 * left a real archived, marked site behind.
		 */
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}

		if ( is_string( $platform ) ) {
			return $this->fail( $row, $this->currentness( $platform, 'internal_error' ) );
		}

		// 5. Attach the returned blog_id under the current fence.
		if ( null === $row['blog_id'] ) {
			$attached = ProvisioningOperationRepository::attach_blog_id(
				$this->portal_hall_id,
				(int) $platform['blog_id'],
				$this->fence
			);
			if ( $attached instanceof \WP_Error ) {
				return $this->fail( $row, $this->currentness( $attached, 'unverified' ) );
			}
			$row = $attached;
		} elseif ( (int) $row['blog_id'] !== (int) $platform['blog_id'] ) {
			// The platform answered about a different site than the one this
			// Hall is bound to. Never re-point the row; never create another.
			return $this->fail( $row, 'site_ownership_unproven' );
		}

		$blog_id = (int) $row['blog_id'];

		// 6-7. Three-fact ownership, then the authoritative URL, read independently.
		$verified = $this->verify_bound_site( $row );
		if ( is_string( $verified ) ) {
			return $this->fail( $row, $verified );
		}

		$site_url = $this->authoritative_site_url( $blog_id );
		if ( null === $site_url ) {
			return $this->fail( $row, 'site_missing' );
		}

		// 8. Transitional state, so the Hall is blocked while it is being built.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}
		$written = $this->write_state( $blog_id, HallCommerceState::PROVISIONING, HallCommerceState::PHASE_TRANSITIONAL );
		if ( null !== $written ) {
			return $this->fail( $row, $written );
		}

		// 9. Hall mapping: create the exact one, preserve an exact one, never repair.
		$mapped = $this->ensure_mapping( $mutex, $row );
		if ( null !== $mapped ) {
			return $this->fail( $row, $mapped );
		}

		// 10. Portal full pull through the trusted provisioning path.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}
		$synchronized = call_user_func(
			$this->synchronizer,
			$this->portal_hall_id,
			$blog_id,
			(string) $row['site_ownership_id'],
			$this->fence,
			$mutex
		);
		if ( $synchronized instanceof \WP_Error ) {
			/*
			 * A LOST MUTEX OR FENCE DURING THE PULL IS NOT `sync_failed`. By
			 * this point the site exists, the mapping exists and transitional
			 * Commerce state is written, so a currentness failure here is
			 * `unverified` — and `portal_sync_plan_not_current` would otherwise
			 * match the `portal_sync_` prefix and be reported as a retryable
			 * synchronization problem, which is a different instruction to
			 * Portal entirely.
			 */
			return $this->fail( $row, $this->currentness( $synchronized, 'sync_failed' ) );
		}

		// 11. Setup prerequisites, READ-ONLY. The evaluator never repairs.
		$readiness = SetupReadiness::evaluate( $blog_id );
		if ( $readiness instanceof \WP_Error || ! is_array( $readiness ) || empty( $readiness['ready'] ) ) {
			return $this->fail( $row, 'readiness_failed' );
		}

		// 12. Settled operational state, only now that the store is actually ready.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}
		$written = $this->write_state( $blog_id, HallCommerceState::OPERATIONAL, HallCommerceState::PHASE_SETTLED );
		if ( null !== $written ) {
			return $this->fail( $row, $written );
		}

		// 13-14. Clear `archived`, with an authoritative read-back.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}
		$cleared = $this->clear_archived( $blog_id );
		if ( null !== $cleared ) {
			return $this->fail( $row, $cleared );
		}

		// 15. Confirm enforcement and clear the stale error category together.
		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $this->fail( $row, $guard );
		}
		$confirmed = ProvisioningOperationRepository::confirm_enforced_sequence( $this->portal_hall_id, $this->fence );
		if ( $confirmed instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $confirmed, 'confirmation_failed' ) );
		}

		return $this->success( ProvisioningResponse::APPLIED, ProvisioningResponse::STATE_OPERATIONAL, $blog_id, $site_url );
	}

	/**
	 * Is this exact confirmed retry ALREADY enforced on the site itself?
	 *
	 * LEDGER EQUALITY ALONE IS NOT ENOUGH for a bound operational site. The
	 * confirmation row records that this command once finished; it does not
	 * record that the world still matches. `already_applied` therefore re-proves
	 * EVERY enforced fact the enable path established — three-fact ownership,
	 * `archived` cleared, settled operational Commerce state, an EXACT ACTIVE
	 * Hall mapping, setup readiness, and a readable authoritative URL — and any
	 * one of them missing means this is not a completed command.
	 *
	 * Readiness is included deliberately. A store that has lost a required
	 * prerequisite since confirmation is not operational however confident the
	 * ledger is, and reporting `already_applied` for it would leave Portal
	 * believing a broken Hall is live with nothing left to retry.
	 *
	 * @param array<string,mixed> $row
	 * @return \WP_REST_Response|string|null Response when already applied, an
	 *         error category when the retry must fail, null to continue.
	 */
	private function already_operational( array $row ) {
		if ( ! ProvisioningOperationRepository::is_confirmed( $row ) || null === $row['blog_id'] ) {
			return null;
		}

		$blog_id = (int) $row['blog_id'];

		$verified = $this->verify_bound_site( $row );
		if ( is_string( $verified ) ) {
			// A confirmed row whose site cannot be proven is a real failure, not
			// something to resume: resuming would run the platform filter
			// against a site this operation may not own.
			return $verified;
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site || '0' !== (string) $site->archived ) {
			return null;
		}
		if ( ! $this->state_settled_at( $blog_id, HallCommerceState::OPERATIONAL ) ) {
			return null;
		}

		/*
		 * THE MAPPING MUST BE EXACT AND ACTIVE, not merely "not conflicting".
		 * A mapping that has since been deleted is ABSENT, and an absent mapping
		 * is something the enable path can and must restore — so it falls
		 * through to a resume rather than being reported as already applied. A
		 * conflict or an unreadable mapping table is a real failure.
		 */
		$mapping = $this->inspect_mapping( $row );
		if ( self::MAPPING_ABSENT === $mapping['state'] ) {
			return null;
		}
		if ( self::MAPPING_EXACT !== $mapping['state'] ) {
			return $mapping['category'] ?? 'mapping_invalid';
		}

		/*
		 * READINESS, RE-PROVEN. Read-only, and the same evaluator the enable
		 * path gates on. An unreadable evaluation is not a pass: `readiness` is
		 * only true when the evaluator actually answered.
		 */
		$readiness = SetupReadiness::evaluate( $blog_id );
		if ( $readiness instanceof \WP_Error || ! is_array( $readiness ) || empty( $readiness['ready'] ) ) {
			/*
			 * A confirmed command whose store is no longer ready is NOT
			 * `already_applied`. There is nothing here for the enable path to
			 * resume — re-running it would re-invoke the platform filter and
			 * still fail the same gate — so it is reported as the documented
			 * `readiness_failed`, which is retryable once the store is repaired.
			 */
			return 'readiness_failed';
		}

		$site_url = $this->authoritative_site_url( $blog_id );
		if ( null === $site_url ) {
			return 'site_missing';
		}

		return $this->success( ProvisioningResponse::ALREADY_APPLIED, ProvisioningResponse::STATE_OPERATIONAL, $blog_id, $site_url );
	}

	/**
	 * Validate and reserve this command's address, before ownership exists.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|\WP_REST_Response
	 */
	private function reserve_address( ProvisioningMutex $mutex, array $row ) {
		$slug = $row['request_site_slug'];
		if ( ! is_string( $slug ) ) {
			// Unreachable for a validated operational row; asserted anyway,
			// because reserving an address the command did not ask for would
			// mint an ownership identity for the wrong site.
			return $this->fail( $row, 'conflict' );
		}

		// WordPress restrictions and network topology FIRST, so a reserved
		// subdirectory name or an over-long host is `address_invalid` rather
		// than a reservation that can never be created at.
		$address = PlatformGateway::resolveAddress( $slug );
		if ( $address instanceof \WP_Error ) {
			return $this->fail( $row, $this->currentness( $address, 'platform_unavailable' ) );
		}

		if ( $slug !== $row['site_slug'] ) {
			/*
			 * A NEWER COMMAND WANTS A DIFFERENT ADDRESS. Permitted only while
			 * this Hall owns no site — but if an ownership ID already exists, a
			 * partial create may be sitting at the PREVIOUS address, and
			 * ADR-042 forbids a new slug from silently abandoning or bypassing
			 * it. The old address is therefore checked before anything is moved.
			 */
			if ( null !== $row['site_ownership_id'] ) {
				$orphan = $this->orphan_at_previous_address( $row );
				if ( null !== $orphan ) {
					return $this->fail( $row, $orphan );
				}
			}

			$guard = $this->guard( $mutex );
			if ( null !== $guard ) {
				return $this->fail( $row, $guard );
			}

			$reserved = ProvisioningOperationRepository::replace_unbound_slug( $this->portal_hall_id, $this->fence, $slug );
			if ( $reserved instanceof \WP_Error ) {
				return $this->fail( $row, $this->currentness( $reserved, 'state_write_failed' ) );
			}
			$this->mutated = true;
			$row           = $reserved;
		}

		if ( null === $row['site_ownership_id'] ) {
			/*
			 * BEFORE THE FIRST OWNERSHIP UUID IS MINTED, an existing WordPress
			 * site at this address is `address_occupied` — full stop. There is
			 * no marker this Hall could match yet, so the site is by definition
			 * somebody else's, and minting an identity for it would manufacture
			 * ownership of a foreign or legacy site.
			 */
			$existing = PlatformGateway::existingSiteId( $address );
			if ( $existing instanceof \WP_Error ) {
				return $this->fail( $row, 'platform_unavailable' );
			}
			if ( null !== $existing ) {
				return $this->fail( $row, 'address_occupied' );
			}
		}

		return $row;
	}

	/**
	 * Is an unfinished create still sitting at this Hall's previous address?
	 *
	 * @param array<string,mixed> $row
	 * @return string|null Error category, or null when the address is clear.
	 */
	private function orphan_at_previous_address( array $row ): ?string {
		$previous = PlatformGateway::resolveAddress( (string) $row['site_slug'] );
		if ( $previous instanceof \WP_Error ) {
			return 'platform_unavailable';
		}

		$existing = PlatformGateway::existingSiteId( $previous );
		if ( $existing instanceof \WP_Error ) {
			return 'platform_unavailable';
		}

		// ANY site at the old address blocks the move — this Hall's own
		// marker-matched partial create included. Recovering it is a command for
		// the original slug, or an explicit operator procedure; it is never a
		// side effect of asking for a different address.
		return null === $existing ? null : 'site_ownership_unproven';
	}

	/**
	 * The single platform invocation for this attempt.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|string Result, or an error category.
	 */
	private function invoke_platform( array $row ) {
		$command = [
			'portal_hall_id'      => $this->portal_hall_id,
			'operation_id'        => (string) $row['operation_id'],
			'site_ownership_id'   => (string) $row['site_ownership_id'],
			'site_slug'           => (string) $row['site_slug'],
			'hall_name'           => $row['request_hall_name'],
			'blog_id'             => $row['blog_id'],
			'request_fingerprint' => (string) $row['request_fingerprint'],
		];

		// The whole cross-plugin boundary — the seed rule, Throwable
		// containment, the permitted error codes and the result shape — lives in
		// PlatformGateway, so this orchestrator holds no second copy of it.
		return PlatformGateway::provisionHallSite( $command );
	}

	/* ------------------------------------------------------------------ *
	 * Shared verification
	 * ------------------------------------------------------------------ */

	/**
	 * The three durable-ownership facts, plus the site's own identity.
	 *
	 * @param array<string,mixed> $row
	 * @param bool $address_optional Suspend path only. Permits the supplementary
	 *        platform topology check to be STEPPED OVER when the platform
	 *        component is not installed — never when an installed one fails.
	 * @return true|string True, or an error category.
	 */
	private function verify_bound_site( array $row, bool $address_optional = false ) {
		$blog_id = $row['blog_id'] ?? null;
		if ( ! is_int( $blog_id ) || $blog_id < 1 || null === $row['site_ownership_id'] ) {
			return 'site_ownership_unproven';
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return 'site_missing';
		}

		$network_id = get_current_network_id();
		if ( (int) $site->site_id !== (int) $network_id || is_main_site( $blog_id, (int) $network_id ) ) {
			return 'site_missing';
		}
		if ( '1' === (string) $site->deleted || '1' === (string) $site->spam ) {
			return 'site_missing';
		}

		/*
		 * THE DURABLE SLUG MUST STILL DESCRIBE THIS SITE. A same-Hall invariant
		 * that has come apart after identity was established is `slug_conflict`
		 * — an operator repair — and explicitly NOT `conflict`, which is about
		 * the request rather than about stored state.
		 *
		 * `$address_optional` IS AN ABSENT-COMPONENT ALLOWANCE, NOT A FAILURE
		 * ALLOWANCE, and the difference is the whole point of this block.
		 *
		 * ADR-042: "suspend skips `bingocommerce_platform_provision_hall_site`
		 * but MUST verify permanent site ownership using `site_ownership_id`
		 * (three-fact agreement) ...; `platform_unavailable` MUST NOT block safe
		 * suspension." So when the platform plugin is NOT INSTALLED, the ADR
		 * names the authority that still applies — the three-fact marker
		 * agreement below, which needs nothing from the platform — and this
		 * supplementary topology check is stepped over. That is the only case
		 * the allowance covers.
		 *
		 * An INSTALLED resolver that fails is a different fact entirely: a
		 * storage error, a corrupt or duplicated `blogs` row, or a throw. It is
		 * not evidence that the address is fine, and the previous shape — which
		 * ignored EVERY WP_Error whenever `$address_optional` was set — let a
		 * failed read stand in for a passed check. Every one of those now fails
		 * closed, on both paths, in the same way.
		 *
		 * Component absence is therefore established POSITIVELY, by asking the
		 * gateway whether the resolver is installed, and never inferred from an
		 * error code that absence and failure share.
		 */
		$address = PlatformGateway::resolveAddress( (string) $row['site_slug'] );
		if ( $address instanceof \WP_Error ) {
			if ( ! $address_optional || PlatformGateway::resolverInstalled() ) {
				// Enable path, or an installed resolver that could not answer.
				return $this->currentness( $address, 'platform_unavailable' );
			}
			// Genuinely absent component, on a suspend. Stepped over; the
			// three-fact agreement below is still required and still decides.
		} elseif ( ! $address->matchesSite( $site ) ) {
			return 'slug_conflict';
		} else {
			/*
			 * AND THE ADDRESS MUST RESOLVE UNIQUELY BACK TO THIS BLOG. The row
			 * comparison above proves this site sits at the durable address; it
			 * does not prove that NOTHING ELSE does. A duplicate at one address
			 * is a same-Hall invariant this endpoint must never resolve by
			 * picking a row, and an unreadable answer is not a confirmation.
			 * Neither foreign row is named.
			 *
			 * NO `$address_optional` EXEMPTION HERE, AND NONE IS POSSIBLE: this
			 * branch is reached holding a HallSiteAddress the resolver itself
			 * built, so the component is installed by construction and a
			 * WP_Error can only mean it failed. A duplicated or unreadable
			 * address is refused; a VERIFIED absence is `site_missing`; a
			 * different blog is `slug_conflict`.
			 */
			$resolved = PlatformGateway::existingSiteId( $address );
			if ( $resolved instanceof \WP_Error ) {
				return 'platform_unavailable';
			}
			if ( null === $resolved ) {
				return 'site_missing';
			}
			if ( $resolved !== $blog_id ) {
				return 'slug_conflict';
			}
		}

		$owned = ProvisioningOperationRepository::has_durable_ownership( $row );
		if ( $owned instanceof \WP_Error || true !== $owned ) {
			return 'site_ownership_unproven';
		}

		return true;
	}

	/**
	 * Inspect the Hall mapping WITHOUT creating or repairing it.
	 *
	 * FOUR OUTCOMES, KEPT APART. The previous shape returned null for BOTH "an
	 * exact active mapping is present" and "there is verifiably no mapping at
	 * all", which are opposite facts: one of them may be reported to Portal as
	 * `already_applied`, and the other must never be. Overloading null also left
	 * an unreadable mapping table indistinguishable from an absent row, so a
	 * storage failure could have been answered by creating a second mapping.
	 *
	 * `category` is set only for the two failure states, and never names the
	 * foreign Hall or blog that caused a conflict.
	 *
	 * @param array<string,mixed> $row
	 * @return array{state:string,category:?string}
	 */
	private function inspect_mapping( array $row ): array {
		$blog_id = (int) $row['blog_id'];
		$repo    = new HallMappingRepository();

		$by_hall = $repo->findByPortalHallId( $this->portal_hall_id );
		if ( $by_hall instanceof \WP_Error ) {
			// "We could not ask" is not "there is no mapping".
			return $this->mapping_state( self::MAPPING_UNREADABLE, $this->currentness( $by_hall, 'mapping_invalid' ) );
		}
		if ( null !== $by_hall ) {
			if ( (int) $by_hall['blog_id'] !== $blog_id ) {
				// This Hall is bound to a DIFFERENT site. Never repaired here,
				// and the foreign blog id is never disclosed.
				return $this->mapping_state( self::MAPPING_CONFLICT, 'mapping_invalid' );
			}
			if ( HallMappingRepository::STATUS_ACTIVE !== $by_hall['status'] ) {
				// A disabled mapping is an explicit operator decision. Silently
				// re-enabling it would undo that decision from a machine call.
				return $this->mapping_state( self::MAPPING_CONFLICT, 'mapping_invalid' );
			}

			return $this->mapping_state( self::MAPPING_EXACT );
		}

		$by_blog = $repo->findByBlogId( $blog_id );
		if ( $by_blog instanceof \WP_Error ) {
			return $this->mapping_state( self::MAPPING_UNREADABLE, $this->currentness( $by_blog, 'mapping_invalid' ) );
		}
		if ( null !== $by_blog ) {
			// The site is bound to ANOTHER Hall. Never disclosed, never repaired.
			return $this->mapping_state( self::MAPPING_CONFLICT, 'mapping_invalid' );
		}

		// VERIFIED absence. Acceptable on the suspend path, where no mapping is
		// created; the enable path creates the exact one from here and from
		// nowhere else.
		return $this->mapping_state( self::MAPPING_ABSENT );
	}

	/** @return array{state:string,category:?string} */
	private function mapping_state( string $state, ?string $category = null ): array {
		return [ 'state' => $state, 'category' => $category ];
	}

	/**
	 * Enable path: the exact active mapping must exist by the end of this step.
	 *
	 * A mapping is created ONLY from verified absence. A conflict and an
	 * unreadable mapping table both fail safely without creating anything and
	 * without naming the foreign identity involved.
	 *
	 * @param array<string,mixed> $row
	 * @return string|null Error category, or null on success.
	 */
	private function ensure_mapping( ProvisioningMutex $mutex, array $row ): ?string {
		$inspected = $this->inspect_mapping( $row );

		if ( self::MAPPING_EXACT === $inspected['state'] ) {
			return null;
		}
		if ( self::MAPPING_ABSENT !== $inspected['state'] ) {
			return $inspected['category'] ?? 'mapping_invalid';
		}

		$blog_id = (int) $row['blog_id'];
		$repo    = new HallMappingRepository();

		$guard = $this->guard( $mutex );
		if ( null !== $guard ) {
			return $guard;
		}

		/*
		 * The provisioning-scoped bind: the site is deliberately still archived
		 * at this point, and the repository grants that single relaxation only
		 * against a held Hall mutex plus an exact ownership witness.
		 */
		$bound = $repo->bindForProvisioning(
			$mutex,
			$this->portal_hall_id,
			$blog_id,
			(string) $row['site_ownership_id']
		);
		if ( $bound instanceof \WP_Error ) {
			return $this->currentness( $bound, 'mapping_invalid' );
		}
		if ( ! is_array( $bound )
			|| (int) ( $bound['blog_id'] ?? 0 ) !== $blog_id
			|| (int) ( $bound['portal_hall_id'] ?? 0 ) !== $this->portal_hall_id
			|| HallMappingRepository::STATUS_ACTIVE !== ( $bound['status'] ?? '' ) ) {
			// Read-back decides, never the write's own report.
			return 'mapping_invalid';
		}

		/*
		 * RE-READ THROUGH THE ORDINARY INSPECTION. The bind returned its own
		 * read-back; this proves the mapping is exact from the same path every
		 * later check uses, so a bind that reported success against a row a
		 * concurrent writer then changed cannot be mistaken for one.
		 */
		$confirmed = $this->inspect_mapping( $row );

		return self::MAPPING_EXACT === $confirmed['state']
			? null
			: ( $confirmed['category'] ?? 'mapping_invalid' );
	}

	/**
	 * Write one Commerce state, or return the error category.
	 *
	 * @return string|null
	 */
	private function write_state( int $blog_id, string $state, int $phase ): ?string {
		$intent  = (int) $this->request['intent_sequence'];
		$written = HallCommerceState::write( $blog_id, $state, $intent, $phase );

		if ( $written instanceof \WP_Error ) {
			return $this->currentness( $written, 'state_write_failed' );
		}
		if ( ! is_array( $written ) ) {
			return 'unverified';
		}

		$outcome = (string) ( $written['outcome'] ?? '' );
		if ( HallCommerceState::APPLIED === $outcome || HallCommerceState::ALREADY_APPLIED === $outcome ) {
			return null;
		}
		if ( HallCommerceState::UNVERIFIED === $outcome ) {
			return 'unverified';
		}

		if ( HallCommerceState::SUPERSEDED === $outcome ) {
			/*
			 * A HIGHER STORED SEQUENCE. Benign in exactly one case: this same
			 * intent has already reached a LATER PHASE, which is what a resumed
			 * enable sees when it re-writes its transitional state after the
			 * settled one was already recorded. Any other superseding sequence
			 * belongs to a NEWER command, and overwriting it is the thing the
			 * phase-packed sequence exists to prevent.
			 */
			$stored = $written['stored'] ?? null;
			if ( is_array( $stored )
				&& is_int( $stored['seq'] ?? null )
				&& ( $stored['seq'] >> HallCommerceState::PHASE_BITS ) === $intent ) {
				return null;
			}

			return 'state_write_failed';
		}

		// CONFLICT: equal sequence, different state. A genuine disagreement.
		return 'state_write_failed';
	}

	/** Is the stored Commerce state exactly this state, settled at this intent? */
	private function state_settled_at( int $blog_id, string $state ): bool {
		$stored = HallCommerceState::read( $blog_id );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		$expected = HallCommerceState::pack( (int) $this->request['intent_sequence'], HallCommerceState::PHASE_SETTLED );

		return is_int( $expected )
			&& ( $stored['seq'] ?? null ) === $expected
			&& ( $stored['state'] ?? null ) === $state;
	}

	/**
	 * Clear `archived`, and prove it from storage.
	 *
	 * @return string|null Error category, or null on proven success.
	 */
	private function clear_archived( int $blog_id ): ?string {
		$before = get_site( $blog_id );
		if ( ! $before instanceof \WP_Site ) {
			return 'site_missing';
		}
		if ( '0' === (string) $before->archived ) {
			return null;
		}

		$updated = wp_update_site( $blog_id, [ 'archived' => 0 ] );

		/*
		 * THE READ-BACK DECIDES, NOT THE RETURN VALUE. wp_update_site() reports
		 * the site id on success, but an affected-row count of zero is
		 * indistinguishable from "already correct" at the driver level, and a
		 * driver that reports failure may still have applied the statement. The
		 * cache is cleared first so the answer comes from storage.
		 */
		clean_blog_cache( $blog_id );
		$after = get_site( $blog_id );

		if ( ! $after instanceof \WP_Site ) {
			return 'unverified';
		}
		if ( '0' !== (string) $after->archived ) {
			// PROVEN still archived, so nothing was applied: a known-failed
			// write, not an unverifiable one.
			return $updated instanceof \WP_Error ? 'state_write_failed' : 'unverified';
		}

		return null;
	}

	/**
	 * The authoritative WordPress site URL, read from STORAGE and verified
	 * against the site's own address.
	 *
	 * NOT get_site_url(). ADR-042 makes BingoCommerce the authority for the
	 * final URL Portal stores and displays, and `get_site_url()` ends in
	 * `apply_filters( 'site_url', ... )` — with `pre_option_siteurl` and
	 * `option_siteurl` ahead of it. Any of those can return an arbitrary string,
	 * and Portal would store it as the Hall's address. The platform filter's own
	 * `site_url` is advisory and is likewise never trusted.
	 *
	 * So the value is read from the site's own options table with one prepared
	 * statement, and then it has to AGREE WITH THE SITE ROW this request already
	 * verified. Four outcomes are kept apart — unreadable, missing, invalid, and
	 * a verified URL — and only the last is returned.
	 *
	 * @return string|null Null when no URL can be authoritatively established.
	 */
	private function authoritative_site_url( int $blog_id ): ?string {
		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return null;
		}

		$stored = $this->read_site_option( $blog_id, 'siteurl' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			// Unreadable, absent or empty. Never guessed at and never rebuilt
			// from the slug: a constructed URL is not an authority.
			return null;
		}

		/*
		 * Control characters are rejected before parsing. wp_parse_url() is
		 * tolerant, and a newline or a NUL inside a URL that later reaches a
		 * header or a log is a separate problem from whether the URL is right.
		 */
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $stored ) ) {
			return null;
		}

		$parts = wp_parse_url( $stored );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			// Absolute http/https only. `javascript:`, `data:`, `//host` and a
			// relative path are all refused.
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			// Userinfo, a query and a fragment have no place in a site URL, and
			// each is a way to make one URL look like another.
			return null;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || $host !== strtolower( (string) $site->domain ) ) {
			// The host must be the one the VERIFIED site row carries.
			return null;
		}

		/*
		 * The path must be the site's own. WordPress stores `siteurl`
		 * untrailingslashed while the site row's path is trailing-slashed, so
		 * both are normalised before comparison rather than one being rewritten.
		 */
		$path      = (string) ( $parts['path'] ?? '' );
		$want_path = (string) $site->path;
		if ( untrailingslashit( $path ) !== untrailingslashit( $want_path ) ) {
			return null;
		}

		/*
		 * A port is permitted only where the NETWORK itself uses one, and then
		 * only the same one. An installation on :8443 must keep it; an
		 * installation without a port must not acquire one from a rewritten
		 * option.
		 *
		 * AND THE NETWORK AUTHORITY ITSELF MUST BE POSITIVELY VERIFIED. The
		 * previous shape asked one question — "which port?" — and answered null
		 * both for a verified network that uses no port AND for a network row
		 * that was absent, duplicated, corrupt, unparseable or unreadable. Those
		 * are opposite facts: the first says a site URL may carry no port, and
		 * the second says nothing at all is known. Collapsed together, a storage
		 * failure silently became the assertion "the network uses no port", and
		 * a portless site URL was then CONFIRMED to Portal on the strength of a
		 * failed read. Nothing is confirmed from an unverified authority.
		 */
		$network = $this->network_authority();
		if ( true !== $network['verified'] ) {
			return null;
		}
		if ( ( $parts['port'] ?? null ) !== $network['port'] ) {
			return null;
		}

		return $stored;
	}

	/**
	 * THE NETWORK'S OWN `siteurl`, AS A STRUCTURED, FAIL-CLOSED STORAGE RESULT.
	 *
	 * Read from network storage for the same reason as the site URL above: the
	 * filtered accessors — `network_site_url()` ends in
	 * `apply_filters( 'network_site_url', ... )`, and `get_network_option()`
	 * runs `pre_site_option_*` / `site_option_*` over a cached value — can be
	 * changed by anything on the installation, and this is the authority those
	 * accessors are supposed to be derived from.
	 *
	 * WHY A STRUCTURE AND NOT AN `?int`. There are three distinguishable facts
	 * here, not two: a VERIFIED network that uses no port, a VERIFIED network
	 * that uses one, and NOTHING KNOWN. Returning `?int` merged the first and
	 * the last, so every storage failure asserted "no port" — see the caller.
	 *
	 * WHAT THE INSTALLED MULTISITE STORAGE ACTUALLY IS, source-checked rather
	 * than assumed. `wp-admin/includes/schema.php` declares `$wpdb->sitemeta`
	 * with `PRIMARY KEY (meta_id)` and NON-UNIQUE keys on `meta_key` and
	 * `site_id` — there is no `UNIQUE (site_id, meta_key)` — and
	 * `get_network_option()` reads it with an unbounded `get_row()` and takes
	 * whichever row the server returns first. So two rows for one key is a state
	 * the schema permits and core would silently pick between. This read asks
	 * for `LIMIT 2` precisely so that state is SEEN, and refuses it.
	 *
	 * UNVERIFIED, every one of them, with no way for a caller to tell them
	 * apart: absent, duplicated, non-string, empty, control-character-bearing,
	 * unparseable, non-http(s), userinfo/query/fragment-bearing, out-of-range
	 * port, unreadable (a driver error) and thrown.
	 *
	 * The network URL's HOST and PATH are deliberately NOT compared against
	 * `get_network()`: that is a different, separately filterable authority, and
	 * a legitimate installation whose network row and network `siteurl` differ
	 * in spelling would have every Hall refused. The question this answers is
	 * narrow — does the installation itself use a port, and is that answer
	 * trustworthy — and it is answered from one place.
	 *
	 * @return array{verified:bool,port:?int}
	 */
	private function network_authority(): array {
		global $wpdb;

		$unverified = [ 'verified' => false, 'port' => null ];

		if ( ! $wpdb instanceof \wpdb ) {
			return $unverified;
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 2",
					get_current_network_id(),
					'siteurl'
				)
			);
			$error  = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			// Never read: its message and trace can quote SQL and stored values.
			unset( $throwable );

			return $unverified;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $values ) ) {
			// Unreadable. NOT "the network uses no port".
			return $unverified;
		}
		if ( 1 !== count( $values ) ) {
			// Absent (0), or ambiguous (2+). Neither is an authority.
			return $unverified;
		}

		$value = $values[0];
		if ( ! is_string( $value ) || '' === $value ) {
			return $unverified;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			// Rejected before parsing, for the same reason as the site URL:
			// wp_parse_url() is tolerant of things a URL must not contain.
			return $unverified;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return $unverified;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return $unverified;
		}
		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return $unverified;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $unverified;
		}

		$port = $parts['port'] ?? null;
		if ( null === $port ) {
			// VERIFIED, and this installation uses no port.
			return [ 'verified' => true, 'port' => null ];
		}
		if ( ! is_int( $port ) || $port < 1 || $port > 65535 ) {
			return $unverified;
		}

		// VERIFIED, and this installation uses exactly this port.
		return [ 'verified' => true, 'port' => $port ];
	}

	/**
	 * Read one option from a site's OWN options table, unfiltered and uncached.
	 *
	 * get_blog_option() runs `pre_option_*` / `option_*` and caches a miss in
	 * `notoptions`, so it cannot answer a question whose whole point is what
	 * storage holds. The table name is composed from `$wpdb->get_blog_prefix()`
	 * and an integer, never from request data, and is re-checked against the
	 * identifier grammar before it reaches a statement.
	 *
	 * @return string|null Null for unreadable AND for absent: both mean the same
	 *                     thing to every caller here — no authoritative value.
	 */
	private function read_site_option( int $blog_id, string $option ): ?string {
		global $wpdb;

		if ( $blog_id < 1 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
			return null;
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $option ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return null;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return null;
		}

		return is_string( $row['option_value'] ) ? $row['option_value'] : null;
	}

	/**
	 * Re-prove mutex ownership and the command fence.
	 *
	 * @return string|null Error category, or null when it is still safe to act.
	 */
	private function guard( ProvisioningMutex $mutex ): ?string {
		return $this->prove_current( $mutex );
	}

	/**
	 * THE ONE CURRENTNESS PROOF. Mutex ownership AND the command fence.
	 *
	 * Used in two places and written once so they cannot drift:
	 *
	 *   - before each irreversible or externally visible mutation; and
	 *   - immediately before EVERY terminal snapshot that speaks for a current
	 *     operation row, success as well as failure.
	 *
	 * The second is not covered by the first. Proving currentness before the
	 * last MUTATION leaves a window between that mutation and the response in
	 * which a newer command can replace this one — and an `applied` or
	 * `already_applied` answered from that window tells Portal a command it has
	 * already superseded is complete. Everything between the last guard and the
	 * snapshot is a read, but reads take time, and a WooCommerce install or a
	 * Portal round-trip earlier in the same request has already made that window
	 * wide.
	 *
	 * DELIBERATELY NOT APPLIED to superseded / conflict classification or to an
	 * unreadable pre-classification row: by construction those paths have no
	 * matching current request row to fence against, and fencing them would turn
	 * a correct refusal into a spurious `lock_unavailable`.
	 *
	 * @return string|null Error category, or null when it is still safe to act.
	 */
	private function prove_current( ?ProvisioningMutex $mutex = null ): ?string {
		$mutex = $mutex ?? $this->mutex;

		if ( ! $mutex instanceof ProvisioningMutex ) {
			// No lock to prove anything with. Fails closed, in whichever
			// direction the mutation state makes honest.
			return $this->mutated ? 'unverified' : 'lock_unavailable';
		}

		$ok = ProvisioningOperationRepository::guard( $mutex, $this->portal_hall_id, $this->fence, $this->mutated );
		if ( true === $ok ) {
			return null;
		}

		return $this->currentness( $ok, $this->mutated ? 'unverified' : 'lock_unavailable' );
	}

	/**
	 * THE ONE PLACE A FAILURE CATEGORY IS DECIDED, for every component.
	 *
	 * Two rules, applied in this order:
	 *
	 *   1. A CURRENTNESS CODE — from whatever component raised it — is
	 *      `lock_unavailable` while nothing may have changed and `unverified`
	 *      once something may have.
	 *   2. ANY category that reduces to `lock_unavailable` after a possible
	 *      mutation becomes `unverified` too. `lock_unavailable` is a REDUCED,
	 *      outcome-absent body meaning "nothing happened, retry"; emitting it
	 *      after a site may exist would tell Portal to retry a create that
	 *      already succeeded, and would omit the operation identity Portal needs
	 *      to reconcile.
	 *
	 * @param \WP_Error|string $error
	 */
	private function currentness( $error, string $fallback ): string {
		$code = $error instanceof \WP_Error ? (string) $error->get_error_code() : (string) $error;

		if ( in_array( $code, self::CURRENTNESS_CODES, true ) ) {
			return $this->mutated ? 'unverified' : 'lock_unavailable';
		}

		$category = $error instanceof \WP_Error
			? ProvisioningResponse::category( $error, $fallback )
			: $code;

		if ( ! in_array( $category, ProvisioningResponse::ERROR_CATEGORIES, true ) ) {
			$category = $fallback;
		}

		return ( $this->mutated && 'lock_unavailable' === $category ) ? 'unverified' : $category;
	}

	/* ------------------------------------------------------------------ *
	 * Terminal snapshots
	 * ------------------------------------------------------------------ */

	/**
	 * A failure whose Hall operation row IS safely established.
	 *
	 * The allowlisted category is persisted on the row under the current fence,
	 * so a later `already_applied` cannot be granted while a stale failure
	 * stands. A persistence failure here never upgrades the reported outcome:
	 * the category the caller decided on is what Portal is told.
	 *
	 * @param array<string,mixed> $row
	 */
	private function fail( array $row, string $category ): \WP_REST_Response {
		/*
		 * The audit's `blog_id` is present-with-null before a durable bind and
		 * the bound site id after one, so a failure that happened after this
		 * Hall acquired a site is attributed to that site.
		 */
		$blog_id = $row['blog_id'] ?? null;
		$blog_id = ( is_int( $blog_id ) && $blog_id > 0 ) ? $blog_id : null;

		if ( in_array( $category, ProvisioningOperationRepository::LAST_ERROR_CATEGORIES, true ) ) {
			/*
			 * PERSISTING THE CATEGORY IS ITSELF A ROW MUTATION, so it is guarded
			 * like one: proven current first, marked as a possible mutation
			 * before it runs, and proven current again before the answer is
			 * fixed. A category stamped onto a row that has since moved on is a
			 * stale failure that would then block a newer command's
			 * `already_applied`, which requires a null error field.
			 *
			 * The repository's own conditional write would refuse a stale fence
			 * anyway; this is about what Portal is TOLD, which the repository
			 * cannot decide.
			 */
			$lost = $this->prove_current();
			if ( null === $lost ) {
				$this->mutated = true;

				// Deliberately not consulted: this failure is already decided,
				// and a persistence problem must not change which failure Portal
				// is told about.
				ProvisioningOperationRepository::record_error_category( $this->portal_hall_id, $this->fence, $category );

				$lost = $this->prove_current();
			}

			if ( null !== $lost ) {
				return $this->terminal( $lost, $blog_id );
			}

			return $this->terminal( $category, $blog_id );
		}

		// Categories that are never persisted still get the same final proof:
		// the answer must describe a command that is still current.
		$lost = $this->prove_current();

		return $this->terminal( $lost ?? $category, $blog_id );
	}

	/**
	 * THE GUARDED, NON-RECURSIVE FAILURE FINALIZER for an escaped Throwable.
	 *
	 * The bare `terminal( $this->mutated ? 'unverified' : 'internal_error' )`
	 * this replaces was wrong in three specific ways, and each is fixed here:
	 *
	 *   1. IT NEVER PROVED CURRENTNESS. terminal() does not, by design — it is
	 *      reached from fail() and from classification, and those prove for
	 *      themselves. So a Throwable arriving after a newer command had already
	 *      replaced this one produced a terminal `internal_error` audit
	 *      attributed to a fence that no longer existed. Every other terminal
	 *      answer in this class that speaks for a current operation row proves
	 *      first; this one now does too.
	 *
	 *   2. IT NEVER PERSISTED THE CATEGORY. fail() stamps an allowlisted failure
	 *      onto the row precisely so a later retry cannot be granted
	 *      `already_applied` while a failure stands. A Throwable is a failure
	 *      like any other, and leaving the row's error field untouched made the
	 *      one failure mode nobody planned for the one that a later command
	 *      could not see.
	 *
	 *   3. IT COULD NOT TELL A PRE-CLASSIFICATION THROW FROM A LATER ONE. Before
	 *      a row is established there is no fence to prove and no row to stamp,
	 *      and touching stored state on that path would attribute a failure to
	 *      whatever happened to be persisted for this Hall.
	 *
	 * NON-RECURSIVE BY CONSTRUCTION. It is reached only from handle()'s single
	 * catch, it never calls run(), fail() or success(), and the two steps that
	 * can themselves fail — the currentness proof and the terminal snapshot —
	 * are each contained on their own below. A Throwable raised INSIDE the
	 * finalizer therefore cannot re-enter it.
	 *
	 * The reported category is decided BEFORE anything else happens, and nothing
	 * after that point may upgrade it: a persistence problem must not change
	 * which failure Portal is told about.
	 */
	private function finalize_after_throw(): \WP_REST_Response {
		/*
		 * Post-mutation uncertainty is `unverified`; before the first mutation
		 * the throw is a definite, non-mutating `internal_error`. Neither ever
		 * describes the exception.
		 */
		$category = $this->mutated ? 'unverified' : 'internal_error';

		if ( null === $this->row ) {
			/*
			 * PRE-CLASSIFICATION. No row has been safely established for this
			 * command, so there is nothing this failure may be fenced against
			 * and nothing it may be stamped onto: no guard, no persistence, no
			 * row read of any kind. The answer is exactly what the previous
			 * shape gave — which was only ever correct on this path.
			 */
			return $this->terminal_guarded( $category, null );
		}

		$blog_id = $this->row['blog_id'] ?? null;
		$blog_id = ( is_int( $blog_id ) && $blog_id > 0 ) ? $blog_id : null;

		/*
		 * 1. PROVE CURRENTNESS FIRST. A lost proof diverts to what the loss
		 * itself means — `unverified` once anything may have changed, and the
		 * reduced, outcome-absent `lock_unavailable` while nothing has. Either
		 * way this command's own `internal_error` is never audited against a
		 * fence a newer command has taken over.
		 */
		$lost = $this->prove_guarded();
		if ( null !== $lost ) {
			return $this->terminal_guarded( $lost, $blog_id );
		}

		if ( in_array( $category, ProvisioningOperationRepository::LAST_ERROR_CATEGORIES, true ) ) {
			/*
			 * 2. PERSIST THE CATEGORY, under the fence just proven. The write is
			 * itself a row mutation, so it is marked as one BEFORE it runs: if
			 * it lands and the proof afterwards is lost, the honest answer is
			 * the one that does not claim nothing happened.
			 */
			$this->mutated = true;

			try {
				// Deliberately not consulted, exactly as in fail(): this failure
				// is already decided.
				ProvisioningOperationRepository::record_error_category( $this->portal_hall_id, $this->fence, $category );
			} catch ( \Throwable $throwable ) {
				// The stamp is best-effort. A storage problem here cannot be
				// allowed to raise a second exception out of the finalizer.
				unset( $throwable );
			}

			// 3. RE-PROVE, so the answer describes a command that is still the
			// current one at the moment it is fixed.
			$lost = $this->prove_guarded();
			if ( null !== $lost ) {
				return $this->terminal_guarded( $lost, $blog_id );
			}
		}

		return $this->terminal_guarded( $category, $blog_id );
	}

	/**
	 * prove_current(), contained. A proof that THROWS is a proof that failed,
	 * and it fails in whichever direction the mutation state makes honest.
	 */
	private function prove_guarded(): ?string {
		try {
			return $this->prove_current();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return $this->mutated ? 'unverified' : 'lock_unavailable';
		}
	}

	/**
	 * terminal(), contained.
	 *
	 * Unreachable by construction — terminal() builds a body out of values this
	 * object already holds and touches no storage — and present anyway, because
	 * this is the last frame before the exception would escape into the REST
	 * server, which would answer with WordPress's own error shape rather than
	 * the ADR-042 one. The fallback builds the SAME documented full body by
	 * hand and captures no audit, because if terminal() could not run then no
	 * snapshot was fixed to audit from.
	 */
	private function terminal_guarded( string $category, ?int $blog_id ): \WP_REST_Response {
		try {
			return $this->terminal( $category, $blog_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			if ( 'lock_unavailable' === $category && ! $this->mutated ) {
				return ProvisioningResponse::reduced( 'lock_unavailable', 503, $this->portal_hall_id );
			}

			return ProvisioningResponse::failure(
				$this->portal_hall_id,
				(string) ( $this->request['operation_id'] ?? '' ),
				(int) ( $this->request['intent_sequence'] ?? 0 ),
				$this->mutated ? 'unverified' : 'internal_error'
			);
		}
	}

	/** Capture the immutable terminal snapshot for a non-success outcome. */
	private function terminal( string $category, ?int $blog_id = null ): \WP_REST_Response {
		/*
		 * Asserted at the one exit point, not merely upheld by every caller.
		 * `lock_unavailable` is the reduced, outcome-absent "nothing happened"
		 * answer; emitting it once a mutation may have occurred would be the
		 * single most damaging thing this class could tell Portal, so the last
		 * thing before the response is built is to rule it out.
		 */
		if ( $this->mutated && 'lock_unavailable' === $category ) {
			$category = 'unverified';
		}

		if ( 'lock_unavailable' === $category ) {
			/*
			 * ADR-042 fixes `lock_unavailable` as a REDUCED body with the
			 * outcome ABSENT, and its audit action table is keyed on the six
			 * outcomes. With no outcome there is no terminal action to record,
			 * so no audit is attempted — the same treatment the ADR gives every
			 * other path that produces no outcome.
			 */
			return ProvisioningResponse::reduced( 'lock_unavailable', 503, $this->portal_hall_id );
		}

		$outcome = ProvisioningResponse::outcome_for( $category );

		$this->capture( $outcome, $category, null, $blog_id );

		return ProvisioningResponse::failure(
			$this->portal_hall_id,
			(string) $this->request['operation_id'],
			(int) $this->request['intent_sequence'],
			$category
		);
	}

	/**
	 * Capture the immutable terminal snapshot for a 200 success.
	 *
	 * THE LAST THING BEFORE A SUCCESS IS A CURRENTNESS PROOF. `applied` and
	 * `already_applied` are the two answers Portal acts on irreversibly — it
	 * stores the returned URL and stops retrying — so neither may be given for a
	 * command that a newer one has replaced since the last mutation. On a lost
	 * proof this diverts to the ordinary terminal failure instead, which is
	 * `lock_unavailable` when nothing can have changed and `unverified` once
	 * something may have.
	 *
	 * terminal() never calls back into here, so the divert cannot recurse.
	 */
	private function success( string $outcome, string $commerce_state, ?int $blog_id, ?string $site_url ): \WP_REST_Response {
		$lost = $this->prove_current();
		if ( null !== $lost ) {
			return $this->terminal( $lost, $blog_id );
		}

		$this->capture( $outcome, null, $commerce_state, $blog_id );

		return ProvisioningResponse::success(
			$this->portal_hall_id,
			(string) $this->request['operation_id'],
			(int) $this->request['intent_sequence'],
			$outcome,
			$commerce_state,
			$blog_id,
			$site_url
		);
	}

	/**
	 * Fix the audit snapshot. Written once; a later call cannot overwrite it.
	 *
	 * The first capture wins because the terminal outcome is decided at exactly
	 * one point, and a second write could only come from an unwinding path that
	 * has already lost the real answer.
	 */
	private function capture( string $outcome, ?string $error_category, ?string $commerce_state, ?int $blog_id ): void {
		if ( [] !== $this->captured ) {
			return;
		}

		$this->captured = [
			'desired_state'    => (string) $this->request['desired_state'],
			'outcome'          => $outcome,
			'portal_hall_id'   => $this->portal_hall_id,
			'operation_id'     => (string) $this->request['operation_id'],
			'intent_sequence'  => (int) $this->request['intent_sequence'],
			'portal_actor_id'  => (int) $this->request['portal_actor_id'],
			'error_category'   => $error_category,
			'effective_access' => ( null === $commerce_state )
				? null
				: ProvisioningResponse::effective_access( $commerce_state ),
			'blog_id'          => $blog_id,
			'actor_user_id'    => $this->actor_user_id,
		];
	}

	/** The immutable terminal snapshot, for tests and for the caller's logs. */
	public function capturedOutcome(): array {
		return $this->captured;
	}
}
