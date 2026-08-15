<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable, fully validated audit row ready for persistence.
 *
 * AuditLog is the only factory. This value deliberately contains no callbacks,
 * lazy lookups, database handles, or unresolved WordPress objects so it is safe
 * to carry across the Hall Staff BEGIN boundary.
 */
final class PreparedAuditEvent {

	public function __construct(
		private readonly string $table,
		private readonly ?string $operation_id,
		private readonly ?int $blog_id,
		private readonly ?int $actor_user_id,
		private readonly string $action,
		private readonly ?string $target_type,
		private readonly ?string $target_id,
		private readonly ?string $previous_value,
		private readonly ?string $new_value,
		private readonly ?string $context,
		private readonly string $created_at
	) {}

	public function table(): string {
		return $this->table;
	}

	public function operation_id(): ?string {
		return $this->operation_id;
	}

	/**
	 * Return a fresh copy of the final database column values.
	 *
	 * @return array<string,int|string|null>
	 */
	public function columns(): array {
		return [
			'operation_id'  => $this->operation_id,
			'blog_id'       => $this->blog_id,
			'actor_user_id' => $this->actor_user_id,
			'action'        => $this->action,
			'target_type'   => $this->target_type,
			'target_id'     => $this->target_id,
			'previous_value' => $this->previous_value,
			'new_value'     => $this->new_value,
			'context'       => $this->context,
			'created_at'    => $this->created_at,
		];
	}

	/**
	 * Compare the immutable intent columns of an authoritative audit witness.
	 * created_at is deliberately excluded: it records the successful attempt's
	 * time and is not part of operation intent.
	 *
	 * @param array<string,mixed> $witness
	 */
	public function matches_witness( array $witness ): bool {
		$expected = $this->columns();

		foreach ( [
			'operation_id',
			'blog_id',
			'actor_user_id',
			'action',
			'target_type',
			'target_id',
			'previous_value',
			'new_value',
			'context',
		] as $key ) {
			if ( ! array_key_exists( $key, $witness ) ) {
				return false;
			}

			$actual = $witness[ $key ];

			if ( in_array( $key, [ 'blog_id', 'actor_user_id' ], true ) ) {
				$actual = null === $actual ? null : (int) $actual;
			} elseif ( null !== $actual ) {
				$actual = (string) $actual;
			}

			if ( $expected[ $key ] !== $actual ) {
				return false;
			}
		}

		return true;
	}
}
