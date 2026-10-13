<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fixed creation facts from ADR-048. Not a human intent or a retry token. */
final class HallStaffSsoCreationIntent {
	public const IDENTITY_TYPE = 'portal_sso';
	public readonly ?int $actor_user_id;

	public function __construct(
		public readonly string $operation_id,
		public readonly int $network_id,
		public readonly int $blog_id,
		public readonly int $created_user_id,
		public readonly string $normalized_email,
		public readonly string $user_login,
		public readonly string $creation_sso_reference
	) {
		$this->actor_user_id = null;
	}

	public function audit_event(): array {
		return [
			'operation_id'   => $this->operation_id,
			'blog_id'        => $this->blog_id,
			'actor_user_id'  => null,
			'action'         => HallStaffCreationIntent::ACTION,
			'target_type'    => 'user',
			'target_id'      => (string) $this->created_user_id,
			'previous_value' => null,
			'new_value'      => [
				'email'         => $this->normalized_email,
				'user_login'    => $this->user_login,
				'identity_type' => self::IDENTITY_TYPE,
			],
			'context'       => [ 'intent_version' => 1, 'source' => 'portal_sso', 'sso_reference' => $this->creation_sso_reference ],
		];
	}

	/** Compare all durable creation facts, including the null system actor. */
	public function matches_witness( array $witness ): bool {
		foreach ( $this->audit_event() as $key => $expected ) {
			if ( ! array_key_exists( $key, $witness ) ) {
				return false;
			}
			$actual = $witness[ $key ];
			if ( in_array( $key, [ 'blog_id', 'actor_user_id' ], true ) ) {
				$actual = null === $actual ? null : (int) $actual;
			} elseif ( null !== $actual ) {
				$actual = (string) $actual;
			}
			if ( is_array( $expected ) ) {
				$expected = wp_json_encode( $expected );
			}
			if ( $expected !== $actual ) {
				return false;
			}
		}
		return true;
	}
}
