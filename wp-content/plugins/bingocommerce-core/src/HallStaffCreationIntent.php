<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable canonical identity of one Hall Staff account-provisioning operation.
 *
 * Mirrors HallStaffIntent in discipline: a retry must prove that the durable
 * audit witness matches EVERY immutable field, never merely that a row with the
 * same operation_id exists.
 */
final class HallStaffCreationIntent {

	public const TOKEN_VERSION  = 1;
	public const TOKEN_AUDIENCE = 'bc_hall_staff_creation_retry';
	public const ACTION         = 'hall_staff.user_created';
	public const IDENTITY_TYPE  = 'staff_operational';

	public function __construct(
		public readonly string $operation_id,
		public readonly int $network_id,
		public readonly int $blog_id,
		public readonly int $actor_user_id,
		public readonly int $created_user_id,
		public readonly string $normalized_email,
		public readonly string $user_login,
		public readonly string $identity_type,
		public readonly string $requested_role,
		public readonly int $issued_at,
		public readonly int $expires_at
	) {}

	/**
	 * Fixed-order payload used for the signed creation retry token.
	 *
	 * @return array<string,int|string>
	 */
	public function token_payload(): array {
		return [
			'version'          => self::TOKEN_VERSION,
			'audience'         => self::TOKEN_AUDIENCE,
			'operation_id'     => $this->operation_id,
			'network_id'       => $this->network_id,
			'blog_id'          => $this->blog_id,
			'actor_user_id'    => $this->actor_user_id,
			'created_user_id'  => $this->created_user_id,
			'normalized_email' => $this->normalized_email,
			'user_login'       => $this->user_login,
			'identity_type'    => $this->identity_type,
			'requested_role'   => $this->requested_role,
			'issued_at'        => $this->issued_at,
			'expires_at'       => $this->expires_at,
		];
	}

	/**
	 * Domain facts for the creation witness.
	 *
	 * requested_role is deliberately absent: the role transition is recorded by
	 * the assignment's own mandatory ADR-025 witness, which already carries
	 * previous_value/new_value roles. Duplicating it here would put one fact
	 * under two owners. requested_role is protected by the token signature and
	 * by the two-token cross-check instead.
	 *
	 * @return array{email:string,user_login:string,identity_type:string}
	 */
	public function new_value(): array {
		return [
			'email'         => $this->normalized_email,
			'user_login'    => $this->user_login,
			'identity_type' => $this->identity_type,
		];
	}

	/** @return array{intent_version:int} */
	public function audit_context(): array {
		return [ 'intent_version' => self::TOKEN_VERSION ];
	}

	/**
	 * The full audit event for this creation, ready for AuditLog::record().
	 *
	 * @return array<string,mixed>
	 */
	public function audit_event(): array {
		return [
			'blog_id'        => $this->blog_id,
			'actor_user_id'  => $this->actor_user_id,
			'action'         => self::ACTION,
			'target_type'    => 'user',
			'target_id'      => $this->created_user_id,
			'previous_value' => null,
			'new_value'      => $this->new_value(),
			'context'        => $this->audit_context(),
			'operation_id'   => $this->operation_id,
		];
	}

	/**
	 * Compare every immutable field against an authoritative witness row.
	 *
	 * created_at is deliberately excluded: it records when the successful attempt
	 * landed and is not part of operation intent.
	 *
	 * @param array<string,mixed> $witness
	 */
	public function matches_witness( array $witness ): bool {
		$expected = [
			'operation_id'   => $this->operation_id,
			'blog_id'        => $this->blog_id,
			'actor_user_id'  => $this->actor_user_id,
			'action'         => self::ACTION,
			'target_type'    => 'user',
			'target_id'      => (string) $this->created_user_id,
			'previous_value' => null,
			'new_value'      => wp_json_encode( $this->new_value() ),
			'context'        => wp_json_encode( $this->audit_context() ),
		];

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $witness ) ) {
				return false;
			}

			$actual = $witness[ $key ];

			if ( in_array( $key, [ 'blog_id', 'actor_user_id' ], true ) ) {
				$actual = null === $actual ? null : (int) $actual;
			} elseif ( null !== $actual ) {
				$actual = (string) $actual;
			}

			if ( $value !== $actual ) {
				return false;
			}
		}

		return true;
	}
}
