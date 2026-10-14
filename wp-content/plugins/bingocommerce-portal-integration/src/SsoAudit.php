<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Only server-selected categories and identity facts reach the audit writer. */
final class SsoAudit {
	public static function record( string $event, int $blog, int $hall, string $reference, string $reason, int $portal_user = 0, int $user = 0, bool $support = false ): bool {
		if ( ! class_exists( '\BingoCommerce\Core\AuditLog' ) || ! SsoExchangeValidator::reference( $reference )
			|| ! in_array( $event, [ 'exchanged', 'refused', 'account_ready' ], true ) ) { return false; }
		$reasons = [ 'verified', 'ready', 'exchange_failed', 'invalid_response', 'mapping_unavailable', 'configuration_unavailable',
			'email_conflict', 'invalid_email', 'username_occupied', 'super_admin', 'restore_mismatch', 'account_missing',
			'recovery_unproven', 'access_removed', 'creation_failed', 'creation_audit_failed', 'grant_failed', 'storage_failed', 'unexpected_failure' ];
		if ( ! in_array( $reason, $reasons, true ) ) { $reason = 'unexpected_failure'; }
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$result = \BingoCommerce\Core\AuditLog::record( [
				'operation_id' => wp_generate_uuid4(), 'blog_id' => $blog, 'actor_user_id' => null,
				'action' => 'portal_sso.' . $event, 'target_type' => $user > 0 ? 'user' : 'hall', 'target_id' => $user > 0 ? $user : $blog,
				'context' => [ 'source' => 'portal_sso', 'sso_reference' => $reference, 'portal_hall_id' => $hall,
					'portal_installation_id' => constant( 'BINGOCOMMERCE_PORTAL_INSTALLATION_ID' ),
					'portal_user_id' => $portal_user ?: null, 'wp_user_id' => $user ?: null, 'reason' => $reason, 'support_review' => $support ],
			] );
			return ! is_wp_error( $result );
		} catch ( \Throwable $ignored ) {
			return false;
		} finally { $wpdb->suppress_errors( $old ); }
	}
}
