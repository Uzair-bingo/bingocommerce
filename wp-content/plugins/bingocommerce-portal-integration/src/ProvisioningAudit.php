<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single terminal provisioning audit attempt (ADR-042).
 *
 * ORDINARY AuditLog, NOT THE ADR-025 TRANSACTIONAL BOUNDARY. Provisioning is
 * not one atomic cross-system transaction — a site, a mapping, a Portal pull and
 * an operation row are four separate durable facts — so there is no transaction
 * for a mandatory audit to be part of. ADR-042 says so explicitly, and it
 * follows that an AuditLog failure must NOT roll back an already-confirmed
 * result: the site really was created, and forgetting that to preserve a log
 * would be the more dangerous of the two failures.
 *
 * EXACTLY ONE ATTEMPT PER CLASSIFIED REQUEST, AND NONE BEFORE CLASSIFICATION.
 * `record()` is called once, from the orchestrator's terminal boundary, with the
 * immutable captured outcome. Requests that never reach classification — core
 * pre-callback failures, authentication, authorization, manual validation,
 * schema unreadiness and a pre-classification lock failure — write nothing here,
 * because the caller never calls this class on those paths.
 *
 * THE ONE ACCEPTED EXCEPTION IS `lock_unavailable` (ADR-042 clarification,
 * 2026-09-13). That response is by definition the REDUCED body with `outcome`
 * ABSENT, and action_for() below is keyed on the six outcomes — so a response
 * with no outcome has no terminal action to record. Every GENUINELY
 * PRE-MUTATION currentness failure is therefore exempt, whether or not
 * classification had been reached: the Hall mutex could not be acquired, or the
 * mutex/fence was lost before this request could have changed anything.
 *
 * The exemption is narrow on purpose, and it is the orchestrator that keeps it
 * narrow: once a mutation MAY have occurred, the same loss is `unverified`, not
 * `lock_unavailable` — a full body, with an outcome, and exactly one
 * `portal.commerce.unverified` attempt through this class.
 *
 * THE CONTEXT ALLOWLIST IS EXHAUSTIVE AND IT IS A CONSTRUCTION, NOT A FILTER.
 * The array below is built key by key from the eight permitted fields, so
 * `site_ownership_id`, the request fingerprint, the Portal token, the payload,
 * a URL, the Hall name, a slug, SQL, a foreign Hall or blog identity and any
 * diagnostic cannot appear even if a caller passed them: there is no code path
 * that copies an unknown key into the context.
 */
final class ProvisioningAudit {

	/* The closed terminal action vocabulary. */
	public const ACTION_APPLIED         = 'portal.commerce.applied';
	public const ACTION_ALREADY_APPLIED = 'portal.commerce.already_applied';
	public const ACTION_SUPERSEDED      = 'portal.commerce.superseded';
	public const ACTION_CONFLICT        = 'portal.commerce.conflict';
	public const ACTION_FAILED          = 'portal.commerce.failed';
	public const ACTION_UNVERIFIED      = 'portal.commerce.unverified';
	public const ACTION_SUSPENDED       = 'portal.commerce.suspended';

	public const ACTIONS = [
		self::ACTION_APPLIED,
		self::ACTION_ALREADY_APPLIED,
		self::ACTION_SUPERSEDED,
		self::ACTION_CONFLICT,
		self::ACTION_FAILED,
		self::ACTION_UNVERIFIED,
		self::ACTION_SUSPENDED,
	];

	/** The ONLY message any audit failure logs. No payload, ever. */
	public const FAILURE_MESSAGE = 'BingoCommerce provisioning audit could not be recorded.';

	/** The exhaustive context allowlist. */
	public const CONTEXT_KEYS = [
		'portal_actor_id',
		'portal_hall_id',
		'operation_id',
		'intent_sequence',
		'desired_state',
		'outcome',
		'error_category',
		'effective_access',
	];

	/**
	 * The exact one-action mapping ADR-042 fixes.
	 *
	 * A SUSPENDED command that succeeded is `portal.commerce.suspended`, whether
	 * it was applied now or already applied earlier, and whether it cancelled a
	 * pre-bind Enable with no site or suspended a marker-matched partial one.
	 * Every other outcome maps to its own action, so the desired state is what
	 * distinguishes an operational success from a suspension — not the presence
	 * of a blog_id.
	 */
	public static function action_for( string $desired_state, string $outcome ): string {
		if ( ProvisioningCanonicalRequest::SUSPENDED === $desired_state
			&& in_array( $outcome, [ ProvisioningResponse::APPLIED, ProvisioningResponse::ALREADY_APPLIED ], true ) ) {
			return self::ACTION_SUSPENDED;
		}

		switch ( $outcome ) {
			case ProvisioningResponse::APPLIED:
				return self::ACTION_APPLIED;
			case ProvisioningResponse::ALREADY_APPLIED:
				return self::ACTION_ALREADY_APPLIED;
			case ProvisioningResponse::SUPERSEDED:
				return self::ACTION_SUPERSEDED;
			case ProvisioningResponse::CONFLICT:
				return self::ACTION_CONFLICT;
			case ProvisioningResponse::UNVERIFIED:
				return self::ACTION_UNVERIFIED;
			default:
				return self::ACTION_FAILED;
		}
	}

	/**
	 * Attempt the one terminal audit for a classified request.
	 *
	 * NEVER THROWS AND NEVER CHANGES THE RESULT. It is called after the terminal
	 * response snapshot is already fixed, so there is nothing here that could
	 * alter it even by accident; a failure writes one fixed line and returns.
	 *
	 * @param array{
	 *   desired_state:string, outcome:string, portal_hall_id:int,
	 *   operation_id:string, intent_sequence:int, portal_actor_id:int,
	 *   error_category:?string, effective_access:?string, blog_id:?int,
	 *   actor_user_id:?int
	 * } $captured Immutable terminal snapshot.
	 */
	public static function record( array $captured ): void {
		try {
			if ( ! class_exists( '\BingoCommerce\Core\AuditLog' ) ) {
				error_log( self::FAILURE_MESSAGE );

				return;
			}

			$action = self::action_for(
				(string) ( $captured['desired_state'] ?? '' ),
				(string) ( $captured['outcome'] ?? '' )
			);

			/*
			 * `blog_id` is present-with-null before a durable bind and the bound
			 * site id after one. AuditLog verifies that a non-null blog_id names
			 * an existing site, so an id that cannot be resolved is dropped to
			 * null rather than failing the whole accountability record.
			 */
			$blog_id = $captured['blog_id'] ?? null;
			if ( ! is_int( $blog_id ) || $blog_id < 1 || ! get_site( $blog_id ) instanceof \WP_Site ) {
				$blog_id = null;
			}

			/*
			 * The actor is the AUTHENTICATED APPLICATION PASSWORD ACCOUNT — the
			 * WordPress user that made this request. `portal_actor_id` is Portal
			 * attribution and travels in the context, never as the actor: it is
			 * not a WordPress user ID and treating it as one would attribute the
			 * action to whichever local account happened to share that number.
			 */
			$actor = $captured['actor_user_id'] ?? null;
			if ( ! is_int( $actor ) || $actor < 1 ) {
				$actor = null;
			}

			$recorded = \BingoCommerce\Core\AuditLog::record(
				[
					'blog_id'       => $blog_id,
					'actor_user_id' => $actor,
					'action'        => $action,
					'context'       => self::context( $captured ),
				]
			);

			if ( is_wp_error( $recorded ) ) {
				// The WP_Error is never read: AuditLog embeds driver diagnostics
				// in its own failure data, and none of that belongs in a log line
				// written from a machine endpoint.
				error_log( self::FAILURE_MESSAGE );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			error_log( self::FAILURE_MESSAGE );
		}
	}

	/**
	 * Build the context from the allowlist, key by key.
	 *
	 * @param array<string,mixed> $captured
	 * @return array<string,mixed>
	 */
	public static function context( array $captured ): array {
		return [
			'portal_actor_id'  => (int) ( $captured['portal_actor_id'] ?? 0 ),
			'portal_hall_id'   => (int) ( $captured['portal_hall_id'] ?? 0 ),
			'operation_id'     => (string) ( $captured['operation_id'] ?? '' ),
			'intent_sequence'  => (int) ( $captured['intent_sequence'] ?? 0 ),
			'desired_state'    => (string) ( $captured['desired_state'] ?? '' ),
			'outcome'          => (string) ( $captured['outcome'] ?? '' ),
			'error_category'   => $captured['error_category'] ?? null,
			'effective_access' => $captured['effective_access'] ?? null,
		];
	}
}
