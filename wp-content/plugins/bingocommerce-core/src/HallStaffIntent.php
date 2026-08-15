<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Immutable canonical identity and before/after state for one Hall Staff operation. */
final class HallStaffIntent {

	public const TOKEN_VERSION = 1;
	public const TOKEN_AUDIENCE = 'bc_hall_staff_retry';

	public function __construct(
		public readonly string $operation_id,
		public readonly int $network_id,
		public readonly int $blog_id,
		public readonly int $actor_user_id,
		public readonly int $target_user_id,
		public readonly string $action,
		public readonly ?string $before_role,
		public readonly ?string $after_role,
		public readonly bool $safeguard_override,
		public readonly int $issued_at,
		public readonly int $expires_at
	) {}

	/**
	 * Fixed-order, PII-free payload used for signed retry tokens.
	 *
	 * @return array<string,int|string|bool|null>
	 */
	public function token_payload(): array {
		return [
			'version'            => self::TOKEN_VERSION,
			'audience'           => self::TOKEN_AUDIENCE,
			'operation_id'       => $this->operation_id,
			'network_id'         => $this->network_id,
			'blog_id'            => $this->blog_id,
			'actor_user_id'      => $this->actor_user_id,
			'target_user_id'     => $this->target_user_id,
			'action'             => $this->action,
			'before_role'        => $this->before_role,
			'after_role'         => $this->after_role,
			'safeguard_override' => $this->safeguard_override,
			'issued_at'          => $this->issued_at,
			'expires_at'         => $this->expires_at,
		];
	}

	/** @return array{role:?string} */
	public function previous_value(): array {
		return [ 'role' => $this->before_role ];
	}

	/** @return array{role:?string} */
	public function new_value(): array {
		return [ 'role' => $this->after_role ];
	}

	/** @return array{intent_version:int,safeguard_override:bool} */
	public function audit_context(): array {
		return [
			'intent_version'     => self::TOKEN_VERSION,
			'safeguard_override' => $this->safeguard_override,
		];
	}

	/** @param array<string,mixed> $witness */
	public function matches_witness( array $witness ): bool {
		$expected = [
			'operation_id'  => $this->operation_id,
			'blog_id'       => $this->blog_id,
			'actor_user_id' => $this->actor_user_id,
			'action'        => $this->action,
			'target_type'   => 'user',
			'target_id'     => (string) $this->target_user_id,
			'previous_value' => wp_json_encode( $this->previous_value() ),
			'new_value'     => wp_json_encode( $this->new_value() ),
			'context'       => wp_json_encode( $this->audit_context() ),
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
