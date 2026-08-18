<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential invitation for operational Hall Staff identities.
 *
 * Reuses the native WordPress new-user notification and password-reset key
 * lifecycle: wp_new_user_notification( $id, null, 'user' ) mails only the
 * employee, get_password_reset_key() stores "time():hash" in
 * user_activation_key, check_password_reset_key() expires it, and
 * wp_set_password() clears it on use. No custom token system.
 *
 * AT MOST ONCE. An outbound email is an external side effect that cannot be made
 * exactly-once by a later user-meta write (send succeeds, process dies, retry
 * sends a second mail). The issued marker is therefore written BEFORE the send;
 * if the marker cannot be written, nothing is sent and Resend is offered.
 */
final class HallStaffInvitation {

	/** Records that BingoCommerce INITIATED an invitation — not that it arrived. */
	public const ISSUED_META_KEY = 'bc_invitation_issued_at';

	public const RESEND_THROTTLE_SECONDS = 60;

	private const LOCK_TIMEOUT_SECONDS = 5;

	public const NOT_COMPLETED     = 'invitation_not_completed';
	public const MARKER_UNWRITABLE = 'invitation_marker_unwritable';

	/**
	 * Per-user, installation/network-scoped issuance lock.
	 *
	 * Scoped per user so two different employees are never serialized against
	 * each other, and discriminated by database + prefix + network so distinct
	 * installations sharing a MySQL server cannot collide. 48 chars, inside the
	 * 64-char GET_LOCK limit.
	 */
	private static function lock_name( int $target_user_id ): string {
		global $wpdb;

		$discriminator = hash( 'sha256', DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id() );

		return 'bc:hsi:' . substr( $discriminator, 0, 32 ) . ':' . $target_user_id;
	}

	/** @var \WP_Error|null Captured by the temporary wp_mail_failed listener. */
	private static ?\WP_Error $mail_error = null;

	/**
	 * Automatic invitation, gated on ALL FOUR conditions (BR-035):
	 *
	 *   1. assignment returned COMMITTED_VERIFIED or IDEMPOTENT_REPLAY_SUCCESS
	 *   2. bc_identity_type === staff_operational
	 *   3. bc_invitation_issued_at is ABSENT
	 *   4. a verified provisioning context covers THIS EXACT created_user_id
	 *
	 * Gate 4 is why the context is a mandatory typed parameter: the generic
	 * existing-user assignment path has no context to pass, so it cannot reach
	 * this method at all. Conditions 1-3 alone are insufficient — an identity
	 * provisioned earlier keeps its provenance marker forever and may legitimately
	 * have no issued marker, so an ordinary cross-hall assignment would otherwise
	 * email an already-active employee.
	 *
	 * Returning false is a NORMAL outcome, not a failure.
	 *
	 * @return true|false|\WP_Error true = sent, false = correctly suppressed,
	 *                              WP_Error = marker written but send failed.
	 */
	public static function maybe_send_automatic(
		HallStaffProvisioningContext $context,
		int $target_user_id,
		string $assignment_status
	) {
		// Gate 1.
		if ( ! in_array(
			$assignment_status,
			[ HallStaffResult::COMMITTED_VERIFIED, HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS ],
			true
		) ) {
			return false;
		}

		// Gate 4, runtime half. (The structural half is this method's signature.)
		if ( ! $context->covers( $target_user_id ) ) {
			return false;
		}

		// Gate 2. Provenance is one necessary-but-insufficient condition here and
		// is never used for authorization or BR-036 eligibility.
		if ( HallStaffCreationIntent::IDENTITY_TYPE !== get_user_meta( $target_user_id, 'bc_identity_type', true ) ) {
			return false;
		}

		// Gate 3.
		if ( '' !== (string) get_user_meta( $target_user_id, self::ISSUED_META_KEY, true ) ) {
			return false;
		}

		return self::issue( $target_user_id );
	}

	/**
	 * Explicit human-initiated resend.
	 *
	 * Takes NO provisioning context and works regardless of provenance, so legacy
	 * staff, existing operational staff, and anyone whose automatic invite was
	 * correctly suppressed can still be given credentials deliberately.
	 *
	 * @return true|\WP_Error
	 */
	public static function resend( int $target_user_id ) {
		if ( ! current_user_can( 'bc_manage_hall_staff' ) || ! HallContext::is_hall() ) {
			return new \WP_Error(
				'invitation_resend_denied',
				__( 'You are not authorized to resend invitations for this hall.', 'bingocommerce-core' )
			);
		}

		$user = get_userdata( $target_user_id );

		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'target_not_eligible', __( 'That user does not exist.', 'bingocommerce-core' ) );
		}

		// Only current staff of the hall the actor manages.
		$staff = HallStaffService::list_current_hall_staff();

		if ( is_wp_error( $staff ) ) {
			return $staff;
		}

		if ( ! in_array( $target_user_id, array_column( $staff, 'id' ), true ) ) {
			return new \WP_Error(
				'target_not_staff',
				__( 'Invitations may only be resent to current Hall Staff of this hall.', 'bingocommerce-core' )
			);
		}

		// The throttle is evaluated INSIDE the issuance lock, so two concurrent
		// resends cannot both observe a stale timestamp and both pass it.
		$issued = self::issue( $target_user_id, self::RESEND_THROTTLE_SECONDS );

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		if ( false === $issued ) {
			return new \WP_Error(
				'invitation_throttled',
				__( 'An invitation is already being sent. Wait a minute before resending.', 'bingocommerce-core' )
			);
		}

		return true;
	}

	/**
	 * Claim the invitation under a per-user advisory lock, then send.
	 *
	 * Read-then-write on user meta is not atomic: two concurrent requests can
	 * both observe an absent marker and both send. The lock makes the claim
	 * indivisible, so the marker check that matters happens INSIDE it.
	 *
	 * @param int      $target_user_id
	 * @param int|null $throttle_seconds When set, an existing marker newer than
	 *                                   this many seconds suppresses the send
	 *                                   (explicit Resend). When null, ANY marker
	 *                                   suppresses it (automatic invitation).
	 * @return true|false|\WP_Error
	 */
	private static function issue( int $target_user_id, ?int $throttle_seconds = null ) {
		global $wpdb;

		$lock = self::lock_name( $target_user_id );

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT_SECONDS ) );

		if ( 1 !== (int) $acquired ) {
			// Another request is claiming this invitation right now. Never race it.
			return false;
		}

		try {
			// Re-read INSIDE the lock, BYPASSING the object cache. The caller's
			// gate check already primed the cache with "absent", so get_user_meta()
			// here would return that stale value and let a second concurrent
			// request through. Only the database is authoritative for the claim.
			$stored = self::stored_marker( $target_user_id );

			// A read failure is never evidence that no invitation was issued.
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			$existing = null === $stored ? 0 : (int) $stored;

			if ( null === $throttle_seconds ) {
				if ( $existing > 0 ) {
					return false;
				}
			} elseif ( $existing > 0 && ( time() - $existing ) < $throttle_seconds ) {
				return new \WP_Error(
					'invitation_throttled',
					__( 'An invitation was just sent. Wait a minute before resending.', 'bingocommerce-core' )
				);
			}

			return self::claim_and_send( $target_user_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Marker first, then send. Only ever called while holding the issuance lock.
	 *
	 * @return true|false|\WP_Error
	 */
	private static function claim_and_send( int $target_user_id ) {
		// The marker claims the single invitation BEFORE the external side
		// effect, so a crash between the two can never produce a second email.
		$now = (string) time();
		update_user_meta( $target_user_id, self::ISSUED_META_KEY, $now );

		// update_user_meta() also returns false when the stored value is merely
		// unchanged, so its return value cannot decide this. Confirm against the
		// database that OUR exact timestamp was persisted — "non-empty" would
		// accept somebody else's claim, or a stale prior value, as our own.
		$confirmed = self::stored_marker( $target_user_id );

		if ( is_wp_error( $confirmed ) || $now !== $confirmed ) {
			// At-most-once cannot be guaranteed, so send nothing.
			return new \WP_Error(
				self::MARKER_UNWRITABLE,
				__( 'Staff access was created, but the invitation could not be started. Use Resend Invitation.', 'bingocommerce-core' ),
				[ 'status' => self::MARKER_UNWRITABLE, 'target_user_id' => $target_user_id ]
			);
		}

		clean_user_cache( $target_user_id );

		self::$mail_error = null;
		add_action( 'wp_mail_failed', [ self::class, 'capture_mail_failure' ] );

		try {
			wp_new_user_notification( $target_user_id, null, 'user' );
		} catch ( \Throwable $throwable ) {
			self::$mail_error = new \WP_Error( 'wp_mail_failed', $throwable->getMessage() );
		} finally {
			remove_action( 'wp_mail_failed', [ self::class, 'capture_mail_failure' ] );
		}

		if ( self::$mail_error instanceof \WP_Error ) {
			$error = new \WP_Error(
				self::NOT_COMPLETED,
				__( 'Staff access was created, but the invitation could not be completed. Use Resend Invitation.', 'bingocommerce-core' ),
				[ 'status' => self::NOT_COMPLETED, 'target_user_id' => $target_user_id ]
			);
			self::$mail_error = null;
			return $error;
		}

		return true;
	}

	/**
	 * Read the issued marker straight from the database.
	 *
	 * Cache-bypassing on purpose: the claim decision is made under an advisory
	 * lock, where a process-local cached value written before the lock was held
	 * would be stale and could admit a second concurrent send.
	 *
	 * Three outcomes are distinguished and must stay distinguished:
	 * a string (marker present), null (genuinely absent), or WP_Error (the read
	 * failed — which is never evidence of absence).
	 *
	 * @return string|null|\WP_Error
	 */
	private static function stored_marker( int $target_user_id ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$target_user_id,
				self::ISSUED_META_KEY
			)
		);

		// get_var() returns null both for "no row" and for a failed query, so a
		// read failure must never be cast to "no marker" — that would license a
		// duplicate send. last_error is reset per query and is authoritative.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'invitation_marker_unreadable',
				__( 'The invitation state could not be read. No invitation was sent. Retry or contact Platform Admin.', 'bingocommerce-core' ),
				[ 'status' => 'invitation_marker_unreadable', 'target_user_id' => $target_user_id ]
			);
		}

		return null === $value ? null : (string) $value;
	}

	/** @param mixed $error */
	public static function capture_mail_failure( $error ): void {
		self::$mail_error = $error instanceof \WP_Error
			? $error
			: new \WP_Error( 'wp_mail_failed', 'The invitation email could not be sent.' );
	}
}
