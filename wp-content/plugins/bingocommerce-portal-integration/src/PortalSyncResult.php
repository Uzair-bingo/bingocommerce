<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of one confirmed Phase 3E snapshot application.
 *
 * Counts describe rows the committed transaction actually changed. A repeated
 * identical snapshot therefore reports every count as zero while still being a
 * success: the sync-state marker advanced, and no domain row moved.
 *
 * Nothing here carries a Portal name, a raw body, or a credential.
 */
final class PortalSyncResult {

	public const APPLIED = 'portal_snapshot_applied';

	/** @var array<int,array<string,mixed>> */
	public array $warnings = [];

	public function __construct(
		public readonly string $status,
		public readonly int $portal_hall_id,
		public readonly int $blog_id,
		public readonly string $generated_at,
		public readonly int $game_types_created,
		public readonly int $game_types_renamed,
		public readonly int $session_templates_created,
		public readonly int $session_templates_updated,
		public readonly int $session_templates_marked_not_current
	) {}

	/** True when the committed transaction changed no Catalog or Session row. */
	public function isNoOp(): bool {
		return 0 === $this->game_types_created
			&& 0 === $this->game_types_renamed
			&& 0 === $this->session_templates_created
			&& 0 === $this->session_templates_updated
			&& 0 === $this->session_templates_marked_not_current;
	}

	/** @return array<string,int> */
	public function counts(): array {
		return [
			'game_types_created'                   => $this->game_types_created,
			'game_types_renamed'                   => $this->game_types_renamed,
			'session_templates_created'            => $this->session_templates_created,
			'session_templates_updated'            => $this->session_templates_updated,
			'session_templates_marked_not_current' => $this->session_templates_marked_not_current,
		];
	}
}
