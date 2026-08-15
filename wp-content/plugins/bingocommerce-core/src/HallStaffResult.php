<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Structured success result for canonical Hall Staff mutations. */
final class HallStaffResult {

	public const COMMITTED_VERIFIED        = 'COMMITTED_VERIFIED';
	public const IDEMPOTENT_REPLAY_SUCCESS = 'IDEMPOTENT_REPLAY_SUCCESS';

	public function __construct(
		public readonly string $status,
		public readonly string $operation_id,
		public readonly string $retry_token,
		public readonly int $blog_id,
		public readonly int $target_user_id,
		public readonly ?string $role
	) {}

	/** @return array<string,int|string|null> */
	public function to_array(): array {
		return [
			'status'         => $this->status,
			'operation_id'   => $this->operation_id,
			'retry_token'    => $this->retry_token,
			'blog_id'        => $this->blog_id,
			'target_user_id' => $this->target_user_id,
			'role'           => $this->role,
		];
	}
}
