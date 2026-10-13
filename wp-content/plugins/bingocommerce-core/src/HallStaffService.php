<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical and only supported application writer for protected hall roles. */
final class HallStaffService {

	/**
	 * @param int|null $expected_user_id When supplied (Create/Invite provisioning),
	 *                                   the uniquely resolved identity MUST be this
	 *                                   exact user or nothing is mutated. Generic
	 *                                   callers pass null but still require a
	 *                                   unique email and unique login.
	 * @return HallStaffResult|\WP_Error
	 */
	public static function assign_existing_user( string $email, string $role, ?string $retry_token = null, ?int $expected_user_id = null ) {
		$context = self::authenticate_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$role = sanitize_key( $role );
		if ( ! self::allowed_role( $role ) ) {
			return self::error( 'invalid_staff_role', 'Only Hall Manager or Game Operator may be assigned.' );
		}

		if ( null !== $retry_token && '' !== $retry_token ) {
			$intent = self::verify_retry_context( $retry_token, $context, 'hall_staff.assigned', $role );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			$email = sanitize_email( wp_unslash( trim( $email ) ) );
			if ( '' === $email || ! is_email( $email ) ) {
				return self::error( 'retry_intent_mismatch', 'The retry email does not match the signed operation target.' );
			}

			// Authoritative, cache-bypassing resolution. A duplicate email or login
			// appearing since the token was issued must fail closed rather than let
			// the retry act on an arbitrarily chosen row.
			$resolved = HallStaffIdentity::resolve_unique( $email );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( null === $resolved || $resolved !== $intent->target_user_id ) {
				return self::error( 'retry_intent_mismatch', 'The retry email does not match the signed operation target.' );
			}

			if ( null !== $expected_user_id && $resolved !== $expected_user_id ) {
				return self::error( 'retry_intent_mismatch', 'The retry email does not match the provisioned account.' );
			}

			$target = get_userdata( $resolved );
			if ( ! $target instanceof \WP_User ) {
				return self::error( 'retry_intent_mismatch', 'The retry email does not match the signed operation target.' );
			}

			$authorized = self::authorize_context( $context );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}

			// BR-036 is re-evaluated on every retry. A signed intent proves what
			// was requested, never that the target is still eligible: its
			// network role state may have changed since the token was issued.
			// Fail closed before entering ADR-025 retry execution.
			$eligible = HallStaffEligibility::assert_operational( $intent->target_user_id );
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}

			return self::execute( $intent, $retry_token, true );
		}

		$authorized = self::authorize_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$email = sanitize_email( wp_unslash( trim( $email ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return self::error( 'invalid_email', 'Enter a valid email address.' );
		}

		// Authoritative, cache-bypassing resolution. bc_users has no unique index
		// on user_email or user_login, so get_user_by() would happily return one
		// of several colliding rows; a protected role must never be granted to an
		// arbitrarily chosen account.
		$resolved = HallStaffIdentity::resolve_unique( $email );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( null === $resolved ) {
			return self::error( 'target_not_eligible', 'No eligible existing network user matches that email address.' );
		}

		if ( null !== $expected_user_id && $resolved !== $expected_user_id ) {
			return self::error( 'identity_state_anomaly', 'That email no longer resolves to the provisioned account. No hall access has been granted. Contact Platform Admin.' );
		}

		$target = get_userdata( $resolved );
		if ( ! $target instanceof \WP_User ) {
			return self::error( 'target_not_eligible', 'No eligible existing network user matches that email address.' );
		}

		if ( is_super_admin( $target->ID ) ) {
			return $context['actor_is_super']
				? self::error( 'super_admin_target_denied', 'A Super Admin cannot receive or change a protected hall role.' )
				: self::error( 'target_not_eligible', 'No eligible existing network user matches that email address.' );
		}

		if ( (int) $target->ID === $context['actor_user_id'] ) {
			return self::error( 'target_not_eligible', 'No eligible existing network user matches that email address.' );
		}

		// BR-036: network-wide operational identity eligibility. Evaluated as a
		// pre-transaction check that fails closed; never revalidated inside the
		// ADR-025 transaction, and never reads provenance metadata.
		$eligible = HallStaffEligibility::assert_operational( (int) $target->ID );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$current = self::current_role( (int) $target->ID, $context['blog_id'] );
		if ( is_wp_error( $current ) ) {
			return $context['actor_is_super']
				? $current
				: self::error( 'target_not_eligible', 'No eligible existing network user matches that email address.' );
		}

		if ( $role === $current ) {
			return self::error( 'already_assigned', 'The user already has that Hall Staff role.' );
		}

		if ( null !== $current ) {
			return self::error( 'role_change_required', 'The user is already Hall Staff. Use the role-change action.' );
		}

		return self::execute_new( $context, (int) $target->ID, 'hall_staff.assigned', null, $role, false );
	}

	/** @return HallStaffResult|\WP_Error */
	public static function change_role( int $target_user_id, string $new_role, ?string $retry_token = null ) {
		$context = self::authenticate_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$new_role = sanitize_key( $new_role );
		if ( ! self::allowed_role( $new_role ) ) {
			return self::error( 'invalid_staff_role', 'Only Hall Manager or Game Operator may be assigned.' );
		}

		if ( null !== $retry_token && '' !== $retry_token ) {
			$intent = self::verify_retry_context( $retry_token, $context, 'hall_staff.role_changed', $new_role, $target_user_id );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			$authorized = self::authorize_context( $context );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}

			// BR-036 is re-evaluated on every retry: the signed intent proves what
			// was requested, never that the target is still eligible.
			$eligible = HallStaffEligibility::assert_operational( $intent->target_user_id );
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}

			return self::execute( $intent, $retry_token, true );
		}

		$authorized = self::authorize_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$target = get_userdata( $target_user_id );
		if ( ! $target instanceof \WP_User ) {
			return self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( is_super_admin( $target_user_id ) ) {
			return $context['actor_is_super']
				? self::error( 'super_admin_target_denied', 'A Super Admin cannot receive or change a protected hall role.' )
				: self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( $target_user_id === $context['actor_user_id'] && ! $context['actor_is_super'] ) {
			return self::error( 'self_change_denied', 'A Hall Manager cannot change their own hall role.' );
		}

		// BR-036: an identity may only hold a protected hall role while its
		// network-wide capability state is canonical. Applied to role changes as
		// well as assignment; deliberately NOT applied to removal, so an invalid
		// identity can always be stripped of protected access.
		$eligible = HallStaffEligibility::assert_operational( $target_user_id );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$current = self::current_role( $target_user_id, $context['blog_id'] );
		if ( is_wp_error( $current ) ) {
			return $context['actor_is_super']
				? $current
				: self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( null === $current ) {
			return self::error( 'target_not_staff', 'The user is not canonical Hall Staff on this hall.' );
		}

		if ( $new_role === $current ) {
			return self::error( 'role_unchanged', 'The user already has that Hall Staff role.' );
		}

		$override = $context['actor_is_super']
			&& (
				$target_user_id === $context['actor_user_id']
				|| ( Roles::HALL_MANAGER === $current && self::is_last_hall_manager( $context['blog_id'] ) )
			);

		return self::execute_new( $context, $target_user_id, 'hall_staff.role_changed', $current, $new_role, $override );
	}

	/** @return HallStaffResult|\WP_Error */
	public static function remove_staff( int $target_user_id, ?string $retry_token = null ) {
		$context = self::authenticate_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( null !== $retry_token && '' !== $retry_token ) {
			$intent = self::verify_retry_context( $retry_token, $context, 'hall_staff.removed', null, $target_user_id );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			$authorized = self::authorize_context( $context );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}

			return self::execute( $intent, $retry_token, true );
		}

		$authorized = self::authorize_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$target = get_userdata( $target_user_id );
		if ( ! $target instanceof \WP_User ) {
			return self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( is_super_admin( $target_user_id ) && ! $context['actor_is_super'] ) {
			return self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( $target_user_id === $context['actor_user_id'] && ! $context['actor_is_super'] ) {
			return self::error( 'self_removal_denied', 'A Hall Manager cannot remove their own hall membership.' );
		}

		$current = self::current_role( $target_user_id, $context['blog_id'] );
		if ( is_wp_error( $current ) ) {
			return $context['actor_is_super']
				? $current
				: self::error( 'target_not_eligible', 'The requested Hall Staff target is not eligible.' );
		}

		if ( null === $current ) {
			return self::error( 'target_not_staff', 'The user is not canonical Hall Staff on this hall.' );
		}

		$override = $context['actor_is_super']
			&& (
				is_super_admin( $target_user_id )
				|| $target_user_id === $context['actor_user_id']
				|| ( Roles::HALL_MANAGER === $current && self::is_last_hall_manager( $context['blog_id'] ) )
			);

		return self::execute_new( $context, $target_user_id, 'hall_staff.removed', $current, null, $override );
	}

	/**
	 * List only current-hall protected staff for the canonical admin screen.
	 *
	 * @return array<int,array{id:int,email:string,display_name:string,role:string,managed_by_portal:bool}>|\WP_Error
	 */
	public static function list_current_hall_staff() {
		$context = self::authenticate_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$authorized = self::authorize_context( $context );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$users = get_users(
			[
				'blog_id' => $context['blog_id'],
				'role__in' => [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ],
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);
		$staff = [];

		foreach ( $users as $user ) {
			$role = self::current_role( (int) $user->ID, $context['blog_id'] );
			if ( is_wp_error( $role ) ) {
				return $role;
			}
			if ( is_string( $role ) ) {
				$staff[] = [
					'id'           => (int) $user->ID,
					'email'        => (string) $user->user_email,
					'display_name' => (string) $user->display_name,
					'role'         => $role,
					'managed_by_portal' => HallStaffIdentity::is_portal_sso_username( (string) $user->user_login ),
				];
			}
		}

		return $staff;
	}

	/** @return array{network_id:int,blog_id:int,actor_user_id:int,actor_is_super:bool}|\WP_Error */
	private static function authenticate_context() {
		if ( ! is_user_logged_in() ) {
			return self::error( 'authentication_required', 'You must be signed in to manage Hall Staff.' );
		}

		if ( ! HallContext::is_hall() ) {
			return self::error( 'invalid_hall_context', 'Hall Staff may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );

		return [
			'network_id'     => get_current_network_id(),
			'blog_id'        => get_current_blog_id(),
			'actor_user_id'  => $actor_id,
			'actor_is_super' => $is_super,
		];
	}

	/** @param array<string,mixed> $context @return true|\WP_Error */
	private static function authorize_context( array $context ) {
		if ( $context['actor_is_super'] ) {
			return true;
		}

		$actor = wp_get_current_user();
		if ( ! current_user_can( 'bc_manage_hall_staff' ) || ! in_array( Roles::HALL_MANAGER, $actor->roles, true ) ) {
			return self::error( 'staff_management_denied', 'You are not authorized to manage Hall Staff for this hall.' );
		}

		return true;
	}

	/** @param array<string,mixed> $context @return HallStaffIntent|\WP_Error */
	private static function verify_retry_context(
		string $token,
		array $context,
		string $action,
		?string $after_role,
		?int $target_id = null
	) {
		$intent = HallStaffRetryToken::verify( $token );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if (
			$intent->network_id !== $context['network_id']
			|| $intent->blog_id !== $context['blog_id']
			|| $intent->actor_user_id !== $context['actor_user_id']
			|| $intent->action !== $action
			|| $intent->after_role !== $after_role
			|| ( $intent->safeguard_override && ! $context['actor_is_super'] )
			|| ( null !== $target_id && $intent->target_user_id !== $target_id )
		) {
			return self::error( 'retry_intent_mismatch', 'The retry token does not match this actor, hall, target, action, or intended state.' );
		}

		return $intent;
	}

	/** @param array<string,mixed> $context @return HallStaffResult|\WP_Error */
	private static function execute_new(
		array $context,
		int $target_id,
		string $action,
		?string $before,
		?string $after,
		bool $safeguard_override
	) {
		$issued = time();
		$intent = new HallStaffIntent(
			wp_generate_uuid4(),
			$context['network_id'],
			$context['blog_id'],
			$context['actor_user_id'],
			$target_id,
			$action,
			$before,
			$after,
			$safeguard_override,
			$issued,
			$issued + HallStaffRetryToken::LIFETIME
		);

		try {
			$token = HallStaffRetryToken::issue( $intent );
		} catch ( \Throwable $throwable ) {
			return self::error( 'retry_token_creation_failed', 'The Hall Staff operation identity could not be created.' );
		}

		return self::execute( $intent, $token, false );
	}

	/** @return HallStaffResult|\WP_Error */
	private static function execute( HallStaffIntent $intent, string $retry_token, bool $is_retry ) {
		global $wpdb;

		// Apply before retry reconciliation too: a signed human retry must not
		// bypass the current protection of a Portal-managed target.
		$login = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $intent->target_user_id ) );
		if ( null === $login || '' !== (string) $wpdb->last_error ) {
			return self::error( 'target_not_eligible', 'The Hall Staff account could not be verified.' );
		}
		if ( HallStaffIdentity::is_portal_sso_username( (string) $login ) && ! is_super_admin( $intent->actor_user_id ) ) {
			return self::error( 'managed_by_portal', 'Only a Platform Admin may manage this Portal account.' );
		}

		$audit_event = [
			'blog_id'        => $intent->blog_id,
			'actor_user_id'  => $intent->actor_user_id,
			'action'         => $intent->action,
			'target_type'    => 'user',
			'target_id'      => $intent->target_user_id,
			'previous_value' => $intent->previous_value(),
			'new_value'      => $intent->new_value(),
			'context'        => $intent->audit_context(),
		];

		$result = HallStaffTransactionTransport::execute( $intent, $audit_event, $is_retry );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;

			if ( in_array( $status, [ HallStaffTransactionTransport::OUTCOME_UNKNOWN, HallStaffTransactionTransport::CONSISTENCY_VIOLATION ], true ) ) {
				self::clean_targeted_caches( $intent );
				error_log( sprintf( 'BingoCommerce Hall Staff %s for operation %s.', strtolower( $status ), $intent->operation_id ) );
			}

			$data = is_array( $data ) ? $data : [];
			$data['operation_id'] = $intent->operation_id;
			$data['retry_token']  = $retry_token;
			$result->add_data( $data );
			return $result;
		}

		self::clean_targeted_caches( $intent );

		return new HallStaffResult(
			$result,
			$intent->operation_id,
			$retry_token,
			$intent->blog_id,
			$intent->target_user_id,
			$intent->after_role
		);
	}

	private static function clean_targeted_caches( HallStaffIntent $intent ): void {
		clean_user_cache( $intent->target_user_id );
		wp_cache_delete( $intent->blog_id . '_user_count', 'blog-details' );

		if ( $intent->actor_user_id === $intent->target_user_id ) {
			wp_set_current_user( 0 );
			wp_set_current_user( $intent->actor_user_id );
		}
	}

	/** @return string|null|\WP_Error */
	private static function current_role( int $user_id, int $blog_id ) {
		global $wpdb;

		$key  = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';
		$rows = get_user_meta( $user_id, $key, false );

		if ( count( $rows ) > 1 ) {
			return self::error( 'duplicate_membership_rows', 'Duplicate membership rows must be repaired before Hall Staff can be changed.' );
		}

		if ( [] === $rows ) {
			return null;
		}

		$value = $rows[0];
		if ( ! is_array( $value ) || 1 !== count( $value ) ) {
			return self::error( 'role_conflict', 'The target has a non-canonical role or capability state on this hall.' );
		}

		$role = array_key_first( $value );
		if ( true !== $value[ $role ] || ! self::allowed_role( $role ) ) {
			return self::error( 'role_conflict', 'The target has a non-canonical role or capability state on this hall.' );
		}

		return $role;
	}

	private static function allowed_role( string $role ): bool {
		return in_array( $role, [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true );
	}

	private static function is_last_hall_manager( int $blog_id ): bool {
		$managers = get_users(
			[
				'blog_id' => $blog_id,
				'role'    => Roles::HALL_MANAGER,
				'fields'  => 'ids',
				'number'  => 2,
			]
		);

		return count( $managers ) <= 1;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-core' ) );
	}
}
