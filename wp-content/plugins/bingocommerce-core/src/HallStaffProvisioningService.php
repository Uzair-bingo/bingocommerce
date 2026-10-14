<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Create/Invite workflow for new operational Hall Staff identities
 * (BR-035, BR-036).
 *
 * Account creation lives entirely OUTSIDE the ADR-025 transaction boundary.
 * There is nothing to roll back and an unassigned account is harmless, so the
 * transport, HallStaffRetryToken, HallStaffIntent, and the assignment
 * transaction are all consumed unchanged through their existing public APIs.
 *
 * Contains no HMAC logic: all signing and verification goes through
 * HallStaffCreationRetryToken. This is also the only class that constructs a
 * HallStaffProvisioningContext, and only ever after verifying a creation token.
 */
final class HallStaffProvisioningService {

	private const LOCK_TIMEOUT_SECONDS = 5;

	private const MAX_LOGIN_LENGTH  = 60;
	private const LOGIN_SUFFIX_ROOM = 4;
	private const MAX_LOGIN_SUFFIX  = 99;

	public const DUPLICATE_DETECTED     = 'duplicate_identity_detected';
	public const DUPLICATE_UNRECORDED   = 'duplicate_identity_unrecorded';
	public const PROVISIONING_INCOMPLETE = 'provisioning_incomplete';
	public const AUDIT_UNRECORDED       = 'creation_audit_unrecorded';
	public const IDENTITY_CONFLICT      = 'creation_identity_conflict';
	public const WITNESS_UNRESOLVED     = 'creation_witness_unresolved';
	public const UNEXPECTED_MEMBERSHIP  = 'unexpected_membership_state';

	/**
	 * Serialize trusted SSO creation/recovery with the existing human writer.
	 * No network call belongs inside this callback. This exposes no second lock,
	 * actor bypass, account writer, or knowledge of the integration's tables.
	 *
	 * @return mixed|\WP_Error
	 */
	public static function with_portal_sso_creation_lock( string $planned_username, callable $work ) {
		if ( ! HallContext::is_hall() || ! HallStaffIdentity::is_portal_sso_username( $planned_username ) ) {
			return self::error( 'invalid_sso_creation', 'The SSO creation identity is invalid.' );
		}
		$locked = self::acquire_lock();
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			return $work();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Confirm only the creation audit for an already-created SSO account.
	 * The trusted integration supplies the link's original creation facts.
	 * This entry creates no account, role, invitation, link, or retry token.
	 *
	 * @return HallStaffSsoCreationIntent|\WP_Error
	 */
	public static function record_portal_sso_creation(
		int $user_id,
		int $creation_blog_id,
		string $planned_username,
		string $creation_operation_id,
		string $creation_sso_reference
	) {
		global $wpdb;

		$site = get_site( $creation_blog_id );
		if ( $user_id < 1 || ! $site || (int) $site->network_id !== get_current_network_id()
			|| is_main_site( $creation_blog_id ) || ! wp_is_uuid( $creation_operation_id, 4 )
			|| ! HallStaffIdentity::is_portal_sso_username( $planned_username )
			|| '' === $creation_sso_reference || strlen( $creation_sso_reference ) > 64 ) {
			return self::error( 'invalid_sso_creation', 'The SSO creation identity is invalid.' );
		}
		$account = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE ID = %d", $user_id
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $account )
			|| $account['user_login'] !== $planned_username || ! is_email( $account['user_email'] )
			|| is_super_admin( $user_id ) ) {
			return self::error( 'target_not_eligible', 'The SSO account could not be verified.' );
		}
		$intent = new HallStaffSsoCreationIntent(
			strtolower( $creation_operation_id ), get_current_network_id(), $creation_blog_id, $user_id,
			(string) $account['user_email'], $planned_username, $creation_sso_reference
		);

		// Never relabel a human staff identity. Read back without a stale meta
		// cache before claiming this marker in the mandatory creation audit.
		$markers = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
			$user_id, 'bc_identity_type'
		) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $markers )
			|| ( [] !== $markers && [ HallStaffSsoCreationIntent::IDENTITY_TYPE ] !== $markers ) ) {
			return self::failure( self::PROVISIONING_INCOMPLETE, 'The SSO identity marker could not be confirmed.', $intent, null );
		}
		if ( [] === $markers ) {
			add_user_meta( $user_id, 'bc_identity_type', HallStaffSsoCreationIntent::IDENTITY_TYPE, true );
		}
		$markers = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
			$user_id, 'bc_identity_type'
		) );
		if ( '' !== (string) $wpdb->last_error || [ HallStaffSsoCreationIntent::IDENTITY_TYPE ] !== $markers ) {
			return self::failure( self::PROVISIONING_INCOMPLETE, 'The SSO identity marker could not be saved.', $intent, null );
		}
		$result = self::resolve_creation_witness( $intent, null );
		return is_wp_error( $result ) ? $result : $intent;
	}

	/**
	 * Create (or route to) an operational staff identity and assign it.
	 *
	 * @param string      $email          Raw submitted email.
	 * @param string      $display_name   Raw submitted display name.
	 * @param string      $role           Raw submitted role (ignored when recovering).
	 * @param string|null $creation_token Creation retry token, for recovery.
	 * @param string|null $assignment_token Phase A retry token, for assignment-stage recovery.
	 * @return HallStaffResult|\WP_Error
	 */
	public static function create_and_assign(
		string $email,
		string $display_name,
		string $role,
		?string $creation_token = null,
		?string $assignment_token = null
	) {
		$context = self::authenticate_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( null !== $creation_token && '' !== $creation_token ) {
			return self::recover( $context, $creation_token, $assignment_token, $display_name, $role );
		}

		$authorized = self::authorize( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$email = self::normalize_email( $email );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$display_name = sanitize_text_field( wp_unslash( $display_name ) );
		if ( '' === $display_name ) {
			return self::error( 'invalid_display_name', 'Enter a display name for the staff member.' );
		}

		$role = sanitize_key( $role );
		if ( ! in_array( $role, [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true ) ) {
			return self::error( 'invalid_staff_role', 'Only Hall Manager or Game Operator may be assigned.' );
		}

		$operation_id = wp_generate_uuid4();

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			// Authoritative, cache-bypassing, and unique-or-nothing. A duplicate
			// quarantined by an earlier attempt must keep failing closed here
			// rather than let a resubmission pick one of the colliding rows.
			$existing = HallStaffIdentity::resolve_unique( $email );

			if ( is_wp_error( $existing ) ) {
				return $existing;
			}

			if ( null !== $existing ) {
				// Existing identity: release the lock and hand off to the
				// unchanged Phase A service. No provisioning context is ever
				// constructed here, so gate 4 fails by construction and no
				// automatic invitation is possible on this path.
				self::release_lock();
				return self::assign_existing_identity( $existing, $email, $role );
			}

			$login = self::generate_login( $email );
			if ( is_wp_error( $login ) ) {
				return $login;
			}

			$created = wpmu_create_user( $login, wp_generate_password( 24, true, true ), $email );

			if ( ! is_int( $created ) || $created < 1 ) {
				return self::error( 'user_creation_failed', 'The operational staff identity could not be created.' );
			}

			$duplicate = self::detect_duplicate( $context, $created, $email, $login );
			if ( is_wp_error( $duplicate ) ) {
				return $duplicate;
			}

			$issued = time();
			$intent = new HallStaffCreationIntent(
				$operation_id,
				$context['network_id'],
				$context['blog_id'],
				$context['actor_user_id'],
				$created,
				$email,
				$login,
				HallStaffCreationIntent::IDENTITY_TYPE,
				$role,
				$issued,
				$issued + HallStaffCreationRetryToken::LIFETIME
			);

			try {
				$token = HallStaffCreationRetryToken::issue( $intent );
			} catch ( \Throwable $throwable ) {
				return self::error( 'retry_token_creation_failed', 'The provisioning operation identity could not be created.' );
			}

			$prepared = self::complete_provisioning( $intent, $token, $display_name );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
		} finally {
			self::release_lock();
		}

		return self::assign_and_invite( $intent, $token, null );
	}

	/**
	 * Resume a provisioning attempt from its signed creation token.
	 *
	 * @param array<string,mixed> $context
	 * @return HallStaffResult|\WP_Error
	 */
	private static function recover(
		array $context,
		string $creation_token,
		?string $assignment_token,
		string $display_name,
		string $submitted_role
	) {
		$intent = HallStaffCreationRetryToken::verify( $creation_token );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		// Layer 1: signed intent versus the live request.
		if (
			$intent->network_id !== $context['network_id']
			|| $intent->blog_id !== $context['blog_id']
			|| $intent->actor_user_id !== $context['actor_user_id']
		) {
			return self::mismatch();
		}

		// The signed requested_role is authoritative. A retry may never flip
		// GO <-> HM; a submitted role is accepted only when it agrees.
		$submitted_role = sanitize_key( $submitted_role );
		if ( '' !== $submitted_role && $submitted_role !== $intent->requested_role ) {
			return self::mismatch();
		}

		$authorized = self::authorize( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		// The token proves what was signed; it proves nothing about the identity
		// as it stands NOW. Bind the two together before either shape continues.
		$bound = self::verify_signed_identity( $intent );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		if ( null !== $assignment_token && '' !== $assignment_token ) {
			return self::recover_assignment_stage( $intent, $creation_token, $assignment_token );
		}

		return self::recover_provisioning_stage( $intent, $creation_token, $display_name );
	}

	/**
	 * Prove the signed intent still describes the live identity.
	 *
	 * A creation token is minted once and remains valid for its lifetime, but the
	 * account it names can be renamed, re-emailed, deleted, or shadowed by another
	 * row in the meantime. Recovery must never act on drift, so every check below
	 * reads the database directly — the object cache is not authoritative here —
	 * and any drift, duplicate, missing row, or read failure fails closed with no
	 * assignment and no invitation.
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_signed_identity( HallStaffCreationIntent $intent ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE ID = %d",
				$intent->created_user_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return self::anomaly( $intent, 'The provisioned identity could not be verified.' );
		}

		if ( ! is_array( $row ) ) {
			return self::anomaly( $intent, 'The provisioned identity no longer exists.' );
		}

		if ( 0 !== strcasecmp( (string) $row['user_email'], $intent->normalized_email ) ) {
			return self::anomaly( $intent, 'The provisioned account\'s email no longer matches this operation.' );
		}

		if ( 0 !== strcasecmp( (string) $row['user_login'], $intent->user_login ) ) {
			return self::anomaly( $intent, 'The provisioned account\'s username no longer matches this operation.' );
		}

		// The signed email and login must still resolve to exactly this identity
		// and nothing else, or a competing row has appeared since creation.
		$matches = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE user_email = %s OR user_login = %s",
				$intent->normalized_email,
				$intent->user_login
			)
		);

		if ( '' !== (string) $wpdb->last_error || ! is_array( $matches ) ) {
			return self::anomaly( $intent, 'The provisioned identity could not be verified as unique.' );
		}

		if ( 1 !== count( $matches ) || (int) $matches[0] !== $intent->created_user_id ) {
			return self::anomaly( $intent, 'Another account now shares this email or username.' );
		}

		return true;
	}

	private static function anomaly( HallStaffCreationIntent $intent, string $message ): \WP_Error {
		return new \WP_Error(
			'identity_state_anomaly',
			__( $message . ' No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
			[ 'status' => 'identity_state_anomaly', 'created_user_id' => $intent->created_user_id ]
		);
	}

	/**
	 * Shape (a) — provisioning_incomplete / creation_audit_unrecorded only.
	 *
	 * Hall Staff assignment was never attempted, so the target must still be
	 * genuinely UNASSIGNED. A membership here is unexplained by this workflow and
	 * fails closed. This precondition is correct ONLY in this shape.
	 *
	 * @return HallStaffResult|\WP_Error
	 */
	private static function recover_provisioning_stage(
		HallStaffCreationIntent $intent,
		string $creation_token,
		string $display_name
	) {
		$memberships = HallStaffEligibility::count_memberships( $intent->created_user_id );

		if ( is_wp_error( $memberships ) ) {
			return $memberships;
		}

		if ( $memberships > 0 ) {
			return self::failure(
				self::UNEXPECTED_MEMBERSHIP,
				'This account already holds a site membership that this provisioning attempt did not create. Contact Platform Admin.',
				$intent,
				$creation_token
			);
		}

		$display_name = sanitize_text_field( wp_unslash( $display_name ) );

		if ( '' === $display_name ) {
			$existing = get_userdata( $intent->created_user_id );
			$display_name = $existing instanceof \WP_User ? (string) $existing->display_name : '';
		}

		if ( '' === $display_name ) {
			return self::error( 'invalid_display_name', 'Enter a display name for the staff member.' );
		}

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$prepared = self::complete_provisioning( $intent, $creation_token, $display_name );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
		} finally {
			self::release_lock();
		}

		return self::assign_and_invite( $intent, $creation_token, null );
	}

	/**
	 * Shape (b) — the assignment was attempted and failed or was ambiguous.
	 *
	 * DELIBERATELY NO zero-membership precondition. The original ADR-025
	 * assignment may have COMMITTED with only its acknowledgement lost, so the
	 * protected role may already exist entirely legitimately. Current role state
	 * is never used as proof of the commit outcome — only the Phase A audit
	 * witness is authoritative, and only Phase A reconciliation decides.
	 *
	 * @return HallStaffResult|\WP_Error
	 */
	private static function recover_assignment_stage(
		HallStaffCreationIntent $intent,
		string $creation_token,
		string $assignment_token
	) {
		// The creation token is minted as soon as created_user_id exists — before
		// the creation audit is written — so a valid token is NOT by itself proof
		// that provisioning ever reached the assignment stage. The durable
		// creation witness is that proof, and it must be established before a
		// provisioning context (and therefore automatic invitation) can exist.
		$witness = AuditLog::find_operation_witness( $intent->operation_id );

		if ( is_wp_error( $witness ) ) {
			return self::failure( self::WITNESS_UNRESOLVED, self::unresolved_message(), $intent, $creation_token );
		}

		if ( null === $witness ) {
			return self::failure(
				self::WITNESS_UNRESOLVED,
				'This provisioning attempt never completed its account record, so it cannot be reconciled as an assignment. Retry or contact Platform Admin.',
				$intent,
				$creation_token
			);
		}

		if ( ! $intent->matches_witness( $witness ) ) {
			return self::failure( self::IDENTITY_CONFLICT, self::conflict_message(), $intent, $creation_token );
		}

		$assignment_intent = HallStaffRetryToken::verify( $assignment_token );

		if ( is_wp_error( $assignment_intent ) ) {
			return $assignment_intent;
		}

		// Cross-check the two verified intents before any context exists.
		if (
			$assignment_intent->actor_user_id !== $intent->actor_user_id
			|| $assignment_intent->network_id !== $intent->network_id
			|| $assignment_intent->blog_id !== $intent->blog_id
			|| $assignment_intent->target_user_id !== $intent->created_user_id
			|| $assignment_intent->after_role !== $intent->requested_role
			|| 'hall_staff.assigned' !== $assignment_intent->action
		) {
			return self::mismatch();
		}

		return self::assign_and_invite( $intent, $creation_token, $assignment_token );
	}

	/**
	 * Display name, provenance, and the mandatory creation witness.
	 *
	 * Ordered so the audit's new_value.identity_type claim is already true when
	 * the row is written.
	 *
	 * @return true|\WP_Error
	 */
	private static function complete_provisioning(
		HallStaffCreationIntent $intent,
		string $creation_token,
		string $display_name
	) {
		$updated = wp_update_user(
			[
				'ID'           => $intent->created_user_id,
				'display_name' => $display_name,
			]
		);

		if ( is_wp_error( $updated ) ) {
			return self::failure(
				self::PROVISIONING_INCOMPLETE,
				'The account was created but its profile could not be saved. No hall access has been granted. Retry or contact Platform Admin.',
				$intent,
				$creation_token
			);
		}

		if ( false === update_user_meta( $intent->created_user_id, 'bc_identity_type', HallStaffCreationIntent::IDENTITY_TYPE ) ) {
			$stored = get_user_meta( $intent->created_user_id, 'bc_identity_type', true );

			if ( HallStaffCreationIntent::IDENTITY_TYPE !== $stored ) {
				return self::failure(
					self::PROVISIONING_INCOMPLETE,
					'The account was created but its provisioning record could not be saved. No hall access has been granted. Retry or contact Platform Admin.',
					$intent,
					$creation_token
				);
			}
		}

		return self::resolve_creation_witness( $intent, $creation_token );
	}

	/**
	 * Write, or authoritatively resolve, the mandatory creation witness.
	 *
	 * A duplicate-key collision alone is NEVER success: the row is re-read and
	 * every immutable field compared. Existence of the operation_id proves
	 * nothing about intent.
	 *
	 * @return true|\WP_Error
	 */
	private static function resolve_creation_witness( HallStaffCreationIntent|HallStaffSsoCreationIntent $intent, ?string $creation_token ) {
		$existing = AuditLog::find_operation_witness( $intent->operation_id );

		if ( is_wp_error( $existing ) ) {
			return self::failure( self::WITNESS_UNRESOLVED, self::unresolved_message(), $intent, $creation_token );
		}

		if ( is_array( $existing ) ) {
			return $intent->matches_witness( $existing )
				? true
				: self::failure( self::IDENTITY_CONFLICT, self::conflict_message(), $intent, $creation_token );
		}

		$recorded = AuditLog::record( $intent->audit_event() );

		if ( ! is_wp_error( $recorded ) ) {
			return true;
		}

		if ( 'duplicate_operation_id' !== $recorded->get_error_code() ) {
			return self::failure( self::AUDIT_UNRECORDED, self::unrecorded_message(), $intent, $creation_token );
		}

		// Concurrent retry: another worker inserted first. Re-read and compare.
		$witness = AuditLog::find_operation_witness( $intent->operation_id );

		if ( is_wp_error( $witness ) || null === $witness ) {
			return self::failure( self::WITNESS_UNRESOLVED, self::unresolved_message(), $intent, $creation_token );
		}

		return $intent->matches_witness( $witness )
			? true
			: self::failure( self::IDENTITY_CONFLICT, self::conflict_message(), $intent, $creation_token );
	}

	/**
	 * Assign through the UNCHANGED Phase A service, then evaluate the invite gate.
	 *
	 * @return HallStaffResult|\WP_Error
	 */
	private static function assign_and_invite(
		HallStaffCreationIntent $intent,
		string $creation_token,
		?string $assignment_token
	) {
		// The expected-target invariant: whatever the email resolves to now, it
		// must be the exact account this provisioning operation created, or no
		// ADR-025 mutation happens at all. Guards the window between the
		// signed-identity check and the assignment itself.
		$result = HallStaffService::assign_existing_user(
			$intent->normalized_email,
			$intent->requested_role,
			$assignment_token,
			$intent->created_user_id
		);

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : [];
			$data['creation_retry_token'] = $creation_token;
			$data['created_user_id']      = $intent->created_user_id;
			$result->add_data( $data );
			return $result;
		}

		// The assignment must have acted on the exact identity this provisioning
		// operation created. A success result naming any other user can never be
		// allowed to authorize an invitation.
		if ( $result->target_user_id !== $intent->created_user_id ) {
			return new \WP_Error(
				'identity_state_anomaly',
				__( 'The assignment result does not name the provisioned account. No invitation has been sent. Contact Platform Admin.', 'bingocommerce-core' ),
				[
					'status'          => 'identity_state_anomaly',
					'created_user_id' => $intent->created_user_id,
					'assignment'      => $result->to_array(),
				]
			);
		}

		// Gate 4 material: constructed only here, only after the creation token
		// verified, and never persisted or serialized.
		$context = new HallStaffProvisioningContext(
			$intent->created_user_id,
			$intent->network_id,
			$intent->blog_id,
			$intent->actor_user_id,
			$intent->requested_role
		);

		$invited = HallStaffInvitation::maybe_send_automatic(
			$context,
			$intent->created_user_id,
			$result->status
		);

		if ( is_wp_error( $invited ) ) {
			// The assignment COMMITTED; only the invitation failed. Both authentic
			// tokens are returned so the caller can reconcile this exact operation
			// through shape (b) without fabricating anything.
			$invited->add_data(
				[
					'status'               => $invited->get_error_code(),
					'assignment'           => $result->to_array(),
					'assignment_status'    => $result->status,
					'retry_token'          => $result->retry_token,
					'creation_retry_token' => $creation_token,
					'created_user_id'      => $intent->created_user_id,
				]
			);
			return $invited;
		}

		return $result;
	}

	/**
	 * Existing-identity path: BR-036 gate, then the unchanged Phase A service.
	 *
	 * No HallStaffProvisioningContext is constructed and HallStaffInvitation is
	 * never called, so automatic invitation is unreachable here by construction.
	 *
	 * @return HallStaffResult|\WP_Error
	 */
	private static function assign_existing_identity( int $user_id, string $email, string $role ) {
		$eligible = HallStaffEligibility::assert_operational( $user_id );

		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		return HallStaffService::assign_existing_user( $email, $role );
	}

	/**
	 * Post-creation duplicate detection on BOTH email and login.
	 *
	 * The fixed lock serializes our own creators; it does nothing about external
	 * writers, and an external writer can collide on either column. There is no
	 * database-level backstop, so this is detection, not prevention.
	 *
	 * @param array<string,mixed> $context
	 * @return true|\WP_Error
	 */
	private static function detect_duplicate( array $context, int $created_user_id, string $email, string $login ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE user_email = %s OR user_login = %s",
				$email,
				$login
			),
			ARRAY_A
		);

		// get_results() cannot distinguish "no rows" from "query failed", so a
		// non-empty last_error is authoritative. Under database uncertainty we
		// must never continue to display name, provenance, audit, or assignment.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'duplicate_check_failed',
				__( 'The account was created but could not be verified as unique. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => 'duplicate_check_failed', 'created_user_id' => $created_user_id ]
			);
		}

		// We just created this identity, so it must be here. Zero rows means the
		// row vanished or the read is not seeing our own write — an anomaly, not
		// a clean result.
		if ( 0 === count( $rows ) ) {
			return new \WP_Error(
				'identity_state_anomaly',
				__( 'The account could not be located immediately after creation. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => 'identity_state_anomaly', 'created_user_id' => $created_user_id ]
			);
		}

		// Exactly one row is valid only when it is the identity we created.
		if ( 1 === count( $rows ) ) {
			if ( (int) $rows[0]['ID'] === $created_user_id ) {
				return true;
			}

			return new \WP_Error(
				'identity_state_anomaly',
				__( 'The account created does not match the account now holding this email or username. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => 'identity_state_anomaly', 'created_user_id' => $created_user_id ]
			);
		}

		$competing = [];
		$by_email  = false;
		$by_login  = false;

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];

			if ( $id === $created_user_id ) {
				continue;
			}

			$competing[] = $id;

			if ( 0 === strcasecmp( (string) $row['user_email'], $email ) ) {
				$by_email = true;
			}

			if ( 0 === strcasecmp( (string) $row['user_login'], $login ) ) {
				$by_login = true;
			}
		}

		$detection = $by_email && $by_login ? 'both' : ( $by_login ? 'user_login' : 'email' );

		$recorded = AuditLog::record(
			[
				'blog_id'        => $context['blog_id'],
				'actor_user_id'  => $context['actor_user_id'],
				'action'         => 'hall_staff.duplicate_identity_detected',
				'target_type'    => 'user',
				'target_id'      => $created_user_id,
				'previous_value' => null,
				'new_value'      => [
					'email'              => $email,
					'user_login'         => $login,
					'created_user_id'    => $created_user_id,
					'competing_user_ids' => $competing,
				],
				'context'        => [
					'intent_version' => HallStaffCreationIntent::TOKEN_VERSION,
					'detection'      => $detection,
				],
				'operation_id'   => wp_generate_uuid4(),
			]
		);

		if ( is_wp_error( $recorded ) ) {
			return new \WP_Error(
				self::DUPLICATE_UNRECORDED,
				__( 'A duplicate account was detected but could not be recorded. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => self::DUPLICATE_UNRECORDED, 'created_user_id' => $created_user_id ]
			);
		}

		return new \WP_Error(
			self::DUPLICATE_DETECTED,
			__( 'Another account with this email or username was created at the same time. No hall access has been granted. Contact Platform Admin.', 'bingocommerce-core' ),
			[ 'status' => self::DUPLICATE_DETECTED, 'created_user_id' => $created_user_id ]
		);
	}

	/**
	 * Deterministic internal login. The user-facing identity is the email;
	 * user_login is never entered by a Hall Manager and never compared.
	 *
	 * @return string|\WP_Error
	 */
	private static function generate_login( string $email ) {
		$base = strtolower( sanitize_user( substr( $email, 0, (int) strpos( $email, '@' ) ), true ) );
		$base = preg_replace( '/\s+/', '', $base );

		if ( '' === $base ) {
			$base = 'staff';
		}

		$base = substr( $base, 0, self::MAX_LOGIN_LENGTH - self::LOGIN_SUFFIX_ROOM );

		$available = self::login_available( $base );
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( $available ) {
			return $base;
		}

		for ( $suffix = 2; $suffix <= self::MAX_LOGIN_SUFFIX; $suffix++ ) {
			$candidate = $base . $suffix;
			$available = self::login_available( $candidate );

			if ( is_wp_error( $available ) ) {
				return $available;
			}

			if ( $available ) {
				return $candidate;
			}
		}

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$candidate = $base . strtolower( wp_generate_password( 4, false, false ) );
			$available = self::login_available( $candidate );

			if ( is_wp_error( $available ) ) {
				return $available;
			}

			if ( $available ) {
				return $candidate;
			}
		}

		return self::error( 'user_login_unavailable', 'A unique staff username could not be generated.' );
	}

	/**
	 * Cache-bypassing availability read.
	 *
	 * username_exists()/email_exists() resolve through the object cache, so under
	 * a persistent cache they can answer from stale data. Inside the creation
	 * lock only the database is authoritative.
	 *
	 * @return bool|\WP_Error
	 */
	private static function login_available( string $login ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login )
		);

		if ( null === $found && '' !== (string) $wpdb->last_error ) {
			return self::error( 'user_lookup_failed', 'The staff username could not be verified.' );
		}

		return null === $found;
	}

	/**
	 * One fixed network-wide creation lock.
	 *
	 * Deliberately NOT keyed by email: two different emails can generate the same
	 * candidate user_login, so an email-keyed lock would let them race on the
	 * username. Creation is a rare human-initiated admin action, so serializing
	 * it network-wide costs nothing.
	 *
	 * @return true|\WP_Error
	 */
	private static function acquire_lock() {
		global $wpdb;

		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_TIMEOUT_SECONDS )
		);

		if ( 1 !== (int) $acquired ) {
			return self::error( 'user_create_lock_timeout', 'Staff provisioning is busy. Retry shortly.' );
		}

		return true;
	}

	private static function release_lock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	private static function lock_name(): string {
		global $wpdb;

		$discriminator = hash(
			'sha256',
			DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id()
		);

		return 'bc:hsu:' . substr( $discriminator, 0, 32 ) . ':create';
	}

	/** @return string|\WP_Error */
	private static function normalize_email( string $email ) {
		$email = strtolower( sanitize_email( wp_unslash( trim( $email ) ) ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return self::error( 'invalid_email', 'Enter a valid email address.' );
		}

		return $email;
	}

	/** @return array{network_id:int,blog_id:int,actor_user_id:int,actor_is_super:bool}|\WP_Error */
	private static function authenticate_context() {
		if ( ! is_user_logged_in() ) {
			return self::error( 'authentication_required', 'You must be signed in to manage Hall Staff.' );
		}

		if ( ! HallContext::is_hall() ) {
			return self::error( 'invalid_hall_context', 'Hall Staff may be managed only from a hall site.' );
		}

		return [
			'network_id'     => get_current_network_id(),
			'blog_id'        => get_current_blog_id(),
			'actor_user_id'  => get_current_user_id(),
			'actor_is_super' => is_super_admin( get_current_user_id() ),
		];
	}

	/** @param array<string,mixed> $context @return true|\WP_Error */
	private static function authorize( array $context ) {
		if ( $context['actor_is_super'] ) {
			return true;
		}

		$actor = wp_get_current_user();

		if ( ! current_user_can( 'bc_manage_hall_staff' ) || ! in_array( Roles::HALL_MANAGER, $actor->roles, true ) ) {
			return self::error( 'staff_management_denied', 'You are not authorized to manage Hall Staff for this hall.' );
		}

		return true;
	}

	private static function failure(
		string $status,
		string $message,
		HallStaffCreationIntent|HallStaffSsoCreationIntent $intent,
		?string $creation_token
	): \WP_Error {
		$data = [
			'status'          => $status,
			'creation_retry_token' => $creation_token,
			'created_user_id' => $intent->created_user_id,
			'operation_id'    => $intent->operation_id,
		];
		if ( $intent instanceof HallStaffSsoCreationIntent ) {
			unset( $data['creation_retry_token'] );
		}
		return new \WP_Error(
			$status,
			__( $message, 'bingocommerce-core' ),
			$data
		);
	}

	private static function mismatch(): \WP_Error {
		return self::error(
			'retry_intent_mismatch',
			'The retry does not match this actor, hall, target, role, or intended state.'
		);
	}

	private static function unresolved_message(): string {
		return 'The provisioning record could not be confirmed. No hall access has been granted. Contact Platform Admin.';
	}

	private static function conflict_message(): string {
		return 'This provisioning operation conflicts with an existing record. No hall access has been granted. Contact Platform Admin.';
	}

	private static function unrecorded_message(): string {
		return 'The account was created but its audit record could not be saved. No hall access has been granted. Retry or contact Platform Admin.';
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-core' ), [ 'status' => $code ] );
	}
}
