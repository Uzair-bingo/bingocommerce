<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ADR-042 response contract: two exact body shapes and one fixed HTTP matrix.
 *
 * EVERY CUSTOM BODY IS BUILT HERE AND NOWHERE ELSE. A response assembled at the
 * point of failure drifts key by key; this class owns the whole disclosure
 * surface, so what Portal can ever be told is one reviewable table.
 *
 * THE KEY SETS ARE EXACT, IN BOTH DIRECTIONS. A full body always carries its ten
 * keys and a reduced body never carries more than three, including on the paths
 * where a value is null. `null` is part of the contract — it is how Portal is
 * told "this call says nothing about your site connection" — so a key is never
 * omitted merely because its value is null.
 *
 * NOTHING INTERNAL CROSSES THIS BOUNDARY. `site_ownership_id`, the request
 * fingerprint, a candidate UUID, a foreign Hall or blog identity, a slug that is
 * not this Hall's, a WP_Error message, SQL, a table prefix, a driver
 * diagnostic, a filesystem path, a lock name and a stack trace are all absent by
 * construction: the only values that can reach a body are the ones passed to the
 * two builders below.
 *
 * UNKNOWN INTERNAL ERROR CODES ARE REDUCED, NEVER FORWARDED. Every component
 * this orchestrator calls has its own vocabulary; category() maps the ones
 * ADR-042 names and answers `internal_error` for everything else, so a new code
 * introduced anywhere downstream can never widen the disclosure surface.
 */
final class ProvisioningResponse {

	/** The exact api_version STRING. Not 1, not "1.0". */
	public const API_VERSION = '1';

	/* Outcomes (ADR-042). */
	public const APPLIED         = 'applied';
	public const ALREADY_APPLIED = 'already_applied';
	public const SUPERSEDED      = 'superseded';
	public const CONFLICT        = 'conflict';
	public const FAILED          = 'failed';
	public const UNVERIFIED      = 'unverified';

	/* Commerce states echoed to Portal. */
	public const STATE_OPERATIONAL = 'operational';
	public const STATE_SUSPENDED   = 'suspended';

	/* Effective access (billing-neutral MVP). */
	public const ACCESS_ALLOWED       = 'allowed';
	public const ACCESS_PORTAL_BLOCKED = 'portal_blocked';

	/** The closed response `error_category` allowlist. Nothing else may appear. */
	public const ERROR_CATEGORIES = [
		'authentication_failed',
		'authorization_failed',
		'validation_failed',
		'lock_unavailable',
		'superseded',
		'conflict',
		'address_invalid',
		'address_occupied',
		'slug_conflict',
		'mapping_invalid',
		'site_missing',
		'site_create_failed',
		'site_ownership_unproven',
		'readiness_failed',
		'sync_failed',
		'state_write_failed',
		'confirmation_failed',
		'platform_unavailable',
		'unverified',
		'internal_error',
	];

	/**
	 * The deterministic HTTP/outcome matrix, exactly as ADR-042 fixes it.
	 *
	 * There is no implementation choice here and no default branch: a category
	 * absent from this table cannot be returned, because category() has already
	 * reduced it to one that is present.
	 *
	 * @var array<string,array{status:int,outcome:string}>
	 */
	public const MATRIX = [
		'superseded'              => [ 'status' => 409, 'outcome' => self::SUPERSEDED ],
		'conflict'                => [ 'status' => 409, 'outcome' => self::CONFLICT ],
		'address_invalid'         => [ 'status' => 400, 'outcome' => self::FAILED ],
		'address_occupied'        => [ 'status' => 409, 'outcome' => self::FAILED ],
		'slug_conflict'           => [ 'status' => 409, 'outcome' => self::FAILED ],
		'mapping_invalid'         => [ 'status' => 409, 'outcome' => self::FAILED ],
		'site_missing'            => [ 'status' => 409, 'outcome' => self::FAILED ],
		'site_create_failed'      => [ 'status' => 503, 'outcome' => self::FAILED ],
		'site_ownership_unproven' => [ 'status' => 409, 'outcome' => self::FAILED ],
		'readiness_failed'        => [ 'status' => 503, 'outcome' => self::FAILED ],
		'sync_failed'             => [ 'status' => 503, 'outcome' => self::FAILED ],
		'state_write_failed'      => [ 'status' => 503, 'outcome' => self::FAILED ],
		'platform_unavailable'    => [ 'status' => 503, 'outcome' => self::FAILED ],
		'internal_error'          => [ 'status' => 503, 'outcome' => self::FAILED ],
		'confirmation_failed'     => [ 'status' => 503, 'outcome' => self::UNVERIFIED ],
		'unverified'              => [ 'status' => 503, 'outcome' => self::UNVERIFIED ],
	];

	/**
	 * Internal error codes this build maps onto ADR-042 categories.
	 *
	 * Every key is a code produced by a committed BingoCommerce component. The
	 * point of the table is that it is a table: a code that is not in it is not
	 * guessed at, not pattern-matched and not forwarded — category() answers
	 * `internal_error`, which is honest and discloses nothing.
	 *
	 * @var array<string,string>
	 */
	private const CODE_CATEGORIES = [
		/* Repository and lock vocabulary. */
		'lock_unavailable'                 => 'lock_unavailable',
		'provisioning_mutex_reentrant'     => 'lock_unavailable',
		'provisioning_mutex_order_violation' => 'internal_error',
		'invalid_portal_hall_id'           => 'internal_error',
		'operation_read_failed'            => 'unverified',
		'operation_row_invalid'            => 'unverified',
		'unverified'                       => 'unverified',
		'state_write_failed'               => 'state_write_failed',
		'confirmation_failed'              => 'confirmation_failed',
		'conflict'                         => 'conflict',
		'superseded'                       => 'superseded',
		'exact_retry'                      => 'conflict',
		'internal_error'                   => 'internal_error',
		'validation_failed'                => 'conflict',
		'address_invalid'                  => 'address_invalid',
		'address_occupied'                 => 'address_occupied',
		'slug_conflict'                    => 'slug_conflict',
		'site_ownership_unproven'          => 'site_ownership_unproven',
		'site_missing'                     => 'site_missing',
		'site_create_failed'               => 'site_create_failed',
		'platform_unavailable'             => 'platform_unavailable',
		'readiness_failed'                 => 'readiness_failed',
		'mapping_invalid'                  => 'mapping_invalid',

		/* HallCommerceState vocabulary. */
		'invalid_blog_id'                  => 'state_write_failed',
		'invalid_state'                    => 'internal_error',
		'invalid_phase'                    => 'internal_error',
		'sequence_out_of_range'            => 'internal_error',
		'state_schema_unavailable'         => 'platform_unavailable',
		'state_precondition_failed'        => 'state_write_failed',
		'state_read_failed'                => 'unverified',
		'state_storage_unavailable'        => 'platform_unavailable',
		'state_row_invalid'                => 'unverified',
		'unsupported_connection_mode'      => 'platform_unavailable',
		'site_deleted'                     => 'site_missing',
		'site_spam'                        => 'site_missing',
		'site_not_found'                   => 'site_missing',
		'site_wrong_network'               => 'site_missing',
		'main_site_not_allowed'            => 'site_missing',
		'multisite_required'               => 'platform_unavailable',

		/* Hall mapping vocabulary. */
		'mapping_schema_unavailable'       => 'platform_unavailable',
		'mapping_read_failed'              => 'mapping_invalid',
		'mapping_persistence_failed'       => 'state_write_failed',
		'mapping_state_changed'            => 'mapping_invalid',
		'mapping_state_inconsistent'       => 'mapping_invalid',
		'mapping_not_found'                => 'mapping_invalid',
		'mapping_disabled'                 => 'mapping_invalid',
		'portal_hall_already_mapped'       => 'mapping_invalid',
		'blog_already_mapped'              => 'mapping_invalid',
		'site_archived'                    => 'mapping_invalid',
		'invalid_mapping_status'           => 'internal_error',
		'invalid_mapping_lookup'           => 'internal_error',
		'mapping_ownership_unproven'       => 'site_ownership_unproven',
		'mapping_provisioning_not_current' => 'lock_unavailable',
		'portal_sync_context_unverified'   => 'unverified',

		/* SetupReadiness vocabulary. */
		'setup_readiness_context_not_restored' => 'readiness_failed',
	];

	/**
	 * Internal codes that mean "this synchronization did not complete".
	 *
	 * Kept as a PREFIX set rather than an exhaustive list because the snapshot
	 * validator, transport and HTTP client each own a large, evolving vocabulary
	 * of `portal_*` codes, and every one of them means the same thing to Portal:
	 * the full pull failed and the command is retryable. Matching the prefix is
	 * what keeps a newly added validator code from falling through to
	 * `internal_error` and being reported as unretryable.
	 *
	 * @var array<int,string>
	 */
	private const SYNC_PREFIXES = [ 'portal_snapshot_', 'portal_sync_', 'portal_game_type_', 'portal_request_', 'portal_http_', 'portal_authentication_', 'portal_hall_not_', 'portal_configuration_', 'portal_base_url_', 'portal_integration_token_' ];

	/**
	 * Reduce ANY internal WP_Error to one allowlisted response category.
	 *
	 * @param mixed  $error    A WP_Error, or any value at all.
	 * @param string $fallback The category to use when the code is unknown.
	 */
	public static function category( $error, string $fallback = 'internal_error' ): string {
		if ( ! in_array( $fallback, self::ERROR_CATEGORIES, true ) ) {
			$fallback = 'internal_error';
		}
		if ( ! $error instanceof \WP_Error ) {
			return $fallback;
		}

		$code = (string) $error->get_error_code();

		if ( array_key_exists( $code, self::CODE_CATEGORIES ) ) {
			return self::CODE_CATEGORIES[ $code ];
		}
		foreach ( self::SYNC_PREFIXES as $prefix ) {
			if ( 0 === strncmp( $code, $prefix, strlen( $prefix ) ) ) {
				return 'sync_failed';
			}
		}

		// Deliberately NOT the code itself, however plausible it looks. An
		// unrecognised code is an unreviewed disclosure.
		return $fallback;
	}

	/**
	 * The reduced body: `api_version`, `error_category`, and `portal_hall_id`
	 * only when the path ID was already validly parsed.
	 *
	 * @param int|null $portal_hall_id Null until manual path validation succeeds.
	 */
	public static function reduced( string $error_category, int $status, ?int $portal_hall_id = null ): \WP_REST_Response {
		$body = [
			'api_version'    => self::API_VERSION,
			'error_category' => self::safe_category( $error_category ),
		];

		if ( null !== $portal_hall_id && $portal_hall_id >= 1 ) {
			$body['portal_hall_id'] = $portal_hall_id;
		}

		return self::respond( $body, $status );
	}

	/**
	 * The full body: exactly ten keys, always.
	 *
	 * @param array{
	 *   portal_hall_id:int, operation_id:string, intent_sequence:int,
	 *   outcome:string, commerce_state:?string, effective_access:?string,
	 *   blog_id:?int, site_url:?string, error_category:?string
	 * } $fields
	 */
	public static function full( array $fields, int $status ): \WP_REST_Response {
		return self::respond( self::body( $fields ), $status );
	}

	/**
	 * The full body as a plain array, for the audit snapshot and for tests.
	 *
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public static function body( array $fields ): array {
		$category = $fields['error_category'] ?? null;
		$category = ( null === $category ) ? null : self::safe_category( (string) $category );

		return [
			'api_version'      => self::API_VERSION,
			'portal_hall_id'   => (int) $fields['portal_hall_id'],
			'operation_id'     => (string) $fields['operation_id'],
			'intent_sequence'  => (int) $fields['intent_sequence'],
			'outcome'          => (string) $fields['outcome'],
			'commerce_state'   => $fields['commerce_state'] ?? null,
			'effective_access' => $fields['effective_access'] ?? null,
			'blog_id'          => $fields['blog_id'] ?? null,
			'site_url'         => $fields['site_url'] ?? null,
			'error_category'   => $category,
		];
	}

	/**
	 * A non-200 full body, built from the matrix.
	 *
	 * ALL FOUR SITE/STATE FIELDS ARE NULL AND ARE NOT PARAMETERS. ADR-042
	 * requires it, and a null here must never instruct Portal to erase a site
	 * connection it has already confirmed — so the only way to emit a non-200
	 * full body is through this method, where they cannot be set at all.
	 */
	public static function failure( int $portal_hall_id, string $operation_id, int $intent_sequence, string $error_category ): \WP_REST_Response {
		$category = self::safe_category( $error_category );
		$mapped   = self::MATRIX[ $category ] ?? self::MATRIX['internal_error'];

		return self::full(
			[
				'portal_hall_id'   => $portal_hall_id,
				'operation_id'     => $operation_id,
				'intent_sequence'  => $intent_sequence,
				'outcome'          => $mapped['outcome'],
				'commerce_state'   => null,
				'effective_access' => null,
				'blog_id'          => null,
				'site_url'         => null,
				'error_category'   => $category,
			],
			$mapped['status']
		);
	}

	/**
	 * A 200 success full body.
	 *
	 * `effective_access` is DERIVED from the Commerce state, never passed in.
	 * The billing-neutral MVP rule is `operational` -> `allowed` and
	 * `suspended` -> `portal_blocked`, and deriving it here means no caller can
	 * report a state and an access that disagree — and none can invent
	 * `billing_restricted`, which this increment must never produce.
	 */
	public static function success( int $portal_hall_id, string $operation_id, int $intent_sequence, string $outcome, string $commerce_state, ?int $blog_id, ?string $site_url ): \WP_REST_Response {
		return self::full(
			[
				'portal_hall_id'   => $portal_hall_id,
				'operation_id'     => $operation_id,
				'intent_sequence'  => $intent_sequence,
				'outcome'          => $outcome,
				'commerce_state'   => $commerce_state,
				'effective_access' => self::effective_access( $commerce_state ),
				'blog_id'          => $blog_id,
				'site_url'         => $site_url,
				'error_category'   => null,
			],
			200
		);
	}

	/**
	 * The billing-neutral effective access for a Commerce state.
	 *
	 * `billing_restricted` is deliberately unreachable: no authoritative BR-016
	 * billing resolver exists in this tree, and ADR-042 forbids this increment
	 * from inventing, persisting or inferring a billing status.
	 */
	public static function effective_access( string $commerce_state ): string {
		return self::STATE_OPERATIONAL === $commerce_state
			? self::ACCESS_ALLOWED
			: self::ACCESS_PORTAL_BLOCKED;
	}

	/** The HTTP status ADR-042 fixes for one category. */
	public static function status_for( string $error_category ): int {
		$mapped = self::MATRIX[ self::safe_category( $error_category ) ] ?? self::MATRIX['internal_error'];

		return $mapped['status'];
	}

	/** The outcome ADR-042 fixes for one category. */
	public static function outcome_for( string $error_category ): string {
		$mapped = self::MATRIX[ self::safe_category( $error_category ) ] ?? self::MATRIX['internal_error'];

		return $mapped['outcome'];
	}

	/** Last line of defence: a category outside the allowlist never ships. */
	private static function safe_category( string $category ): string {
		return in_array( $category, self::ERROR_CATEGORIES, true ) ? $category : 'internal_error';
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private static function respond( array $body, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body );
		$response->set_status( $status );

		return $response;
	}
}
