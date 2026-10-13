<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-048's internal grant entry and its dedicated immutable transport request.
 *
 * The integration caller must first confirm the Portal answer and linked user.
 * No HTTP handler exposes this entry. Core never reads the integration's links.
 * Only this entry builds the request; a null actor on a human intent is not SSO.
 */
final class HallStaffSsoGrant {
	public readonly ?int $actor_user_id;
	public readonly string $action;
	public readonly ?string $before_role;
	public readonly string $after_role;
	public readonly bool $safeguard_override;

	private function __construct(
		public readonly string $operation_id,
		public readonly int $network_id,
		public readonly int $blog_id,
		public readonly int $target_user_id,
		public readonly string $planned_username,
		public readonly string $sso_reference,
		public readonly string $creation_operation_id
	) {
		$this->actor_user_id = null;
		$this->action = 'hall_staff.assigned';
		$this->before_role = null;
		$this->after_role = Roles::HALL_MANAGER;
		$this->safeguard_override = false;
	}

	/**
	 * Grant on the current Hall after verifying the original creation witness.
	 * An optional server-owned operation ID supports reconciliation of the same
	 * grant. It is never a user-facing retry token or a request parameter.
	 *
	 * @return HallStaffResult|\WP_Error
	 */
	public static function grant(
		HallStaffSsoCreationIntent $creation,
		int $expected_blog_id,
		string $sso_reference,
		?string $operation_id = null
	) {
		global $wpdb;

		$operation_id = null === $operation_id ? null : strtolower( $operation_id );
		$creation_site = get_site( $creation->blog_id );
		if ( ! HallContext::is_hall() || $expected_blog_id !== get_current_blog_id()
			|| $creation->network_id !== get_current_network_id() || ! $creation_site
			|| (int) $creation_site->network_id !== get_current_network_id() || is_main_site( $creation->blog_id ) ) {
			return new \WP_Error( 'invalid_hall_context', 'SSO may grant access only on the current Hall.' );
		}
		if ( ! HallStaffIdentity::is_portal_sso_username( $creation->user_login )
			|| '' === $sso_reference || strlen( $sso_reference ) > 64
			|| ( null !== $operation_id && ! wp_is_uuid( $operation_id, 4 ) )
			|| $operation_id === $creation->operation_id ) {
			return new \WP_Error( 'invalid_sso_grant', 'The SSO grant identity is invalid.' );
		}

		$target = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, user_login FROM {$wpdb->users} WHERE ID = %d", $creation->created_user_id
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $target )
			|| $target['user_login'] !== $creation->user_login || is_super_admin( $creation->created_user_id ) ) {
			return new \WP_Error( 'target_not_eligible', 'The SSO account could not be verified.' );
		}

		$witness = AuditLog::find_operation_witness( $creation->operation_id );
		if ( ! is_array( $witness ) || ! $creation->matches_witness( $witness ) ) {
			return new \WP_Error( 'sso_creation_audit_required', 'The account creation audit must be confirmed before granting access.' );
		}

		$intent = new self(
			$operation_id ?? wp_generate_uuid4(), get_current_network_id(), $expected_blog_id,
			$creation->created_user_id, $creation->user_login, $sso_reference, $creation->operation_id
		);
		// BR-036 remains the same authoritative pre-transaction gate.
		$eligible = HallStaffEligibility::assert_operational( $intent->target_user_id );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$result = HallStaffTransactionTransport::execute( $intent, $intent->audit_event(), null !== $operation_id );
		$data = is_wp_error( $result ) ? $result->get_error_data() : null;
		$status = is_array( $data ) ? ( $data['status'] ?? null ) : $result;
		if ( ! is_wp_error( $result ) || in_array( $status, [ HallStaffTransactionTransport::OUTCOME_UNKNOWN, HallStaffTransactionTransport::CONSISTENCY_VIOLATION ], true ) ) {
			clean_user_cache( $intent->target_user_id );
			wp_cache_delete( $intent->blog_id . '_user_count', 'blog-details' );
		}
		if ( is_wp_error( $result ) ) {
			$data = is_array( $data ) ? $data : [];
			$data['operation_id'] = $intent->operation_id;
			$result->add_data( $data );
			return $result;
		}
		return new HallStaffResult( $result, $intent->operation_id, '', $intent->blog_id, $intent->target_user_id, $intent->after_role );
	}

	public function audit_event(): array {
		return [
			'blog_id' => $this->blog_id, 'actor_user_id' => null,
			'action' => $this->action, 'target_type' => 'user', 'target_id' => $this->target_user_id,
			'previous_value' => [ 'role' => null ], 'new_value' => [ 'role' => Roles::HALL_MANAGER ],
			'context' => [
				'intent_version' => 1, 'safeguard_override' => false, 'source' => 'portal_sso',
				'sso_reference' => $this->sso_reference, 'creation_operation_id' => $this->creation_operation_id,
			],
		];
	}

	public function matches_witness( array $witness ): bool {
		$prepared = AuditLog::prepare_transactional_event( $this->audit_event(), $this->operation_id );
		return ! is_wp_error( $prepared ) && $prepared->matches_witness( $witness );
	}
}
