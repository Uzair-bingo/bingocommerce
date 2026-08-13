<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared network-wide platform audit service (ADR-023).
 *
 * This class is the ONLY application-level code path permitted to insert rows
 * into the canonical audit table. Controllers, admin screens, REST endpoints,
 * and feature plugins must route through record() rather than touching the
 * table directly, and must not build competing general-purpose audit logs.
 *
 * APPEND-ONLY. There is intentionally no update(), delete(), or truncate()
 * method, and none may be added: a correction is expressed as a NEW audit
 * event, never as a rewrite of history. No role or capability grants edit or
 * delete access to audit rows, and the table is not exposed as a CRUD UI.
 *
 * NO EXTENSION HOOKS. record() deliberately fires no filters or actions.
 * It is expected to be called from security-sensitive code paths, where an
 * arbitrary third-party callback could introduce irreversible side effects that
 * no database rollback can undo. Sensitive-field policy is code-owned for
 * Phase 1 and is changed by editing this class under review — never by a hook.
 *
 * ATOMIC MUTATION + AUDIT (ADR-023) — deliberately not implemented here:
 *
 * A generic `atomic( callable $mutation, ... )` wrapper is intentionally
 * absent, because inspection of the APIs the first consumer (Hall Staff
 * Management, BR-033) will need showed that such a wrapper would promise more
 * than it can deliver.
 *
 * TRANSACTION SAFETY IS NOT PROVIDED BY THIS CLASS.
 *
 * No security-sensitive consumer may assume its operation is atomic merely
 * because it called record() between START TRANSACTION and COMMIT. Wrapping
 * statements in a transaction does not, on its own, make an operation
 * transactionally safe. Before any transactional use, consumer-level review of
 * ALL of the following is mandatory:
 *
 *  - Every database write the mutation performs, directly or through a
 *    WordPress/WooCommerce API, including writes to tables the caller did not
 *    expect and any DDL (MySQL commits implicitly on CREATE/ALTER/DROP).
 *  - wpdb reconnect behaviour. wpdb silently reconnects and re-runs a query on
 *    MySQL error 2006 (class-wpdb.php, query()). If that fires during the
 *    mutation, the write commits standalone on a fresh autocommit connection
 *    and a later ROLLBACK is a no-op. A connection-identity check can detect
 *    this but cannot undo it.
 *  - Every WordPress hook the mutation triggers. add_user_to_blog(), for
 *    example, fires the can_add_user_to_blog filter and the add_user_to_blog
 *    action; third-party callbacks may send mail or call external services.
 *  - Object cache state. ROLLBACK reverts the database but NOT the WordPress
 *    object cache, so a rolled-back role grant can stay readable via
 *    current_user_can() for the remainder of the request — worse under a
 *    persistent cache (ADR-007). Cache reconciliation is the consumer's job.
 *  - Irreversible side effects. Email, webhooks, external API calls, queued
 *    remote jobs, and file writes are never rolled back and must not occur
 *    inside the transaction.
 *
 * Where that review cannot establish real atomicity for a given mutation, the
 * correct outcome is to report the limitation — not to present the operation
 * as atomic.
 */
final class AuditLog {

	/** Maximum encoded size of a single structured payload column, in bytes. */
	const MAX_PAYLOAD_BYTES = 65535;

	/** Structured payload keys accepted by record(). */
	const PAYLOAD_KEYS = [ 'previous_value', 'new_value', 'context' ];

	/**
	 * Payload key tokens that must never appear in an audit event.
	 *
	 * Matched against the key split into word tokens, so `device_token` is
	 * rejected while `bypass` and `scorecard` are not. The audit trail is an
	 * accountability record, not a place to accumulate secrets or card data.
	 */
	const SENSITIVE_KEY_TOKENS = [
		'password',
		'passwd',
		'pwd',
		'pass',
		'secret',
		'token',
		'salt',
		'nonce',
		'cookie',
		'credential',
		'credentials',
		'auth',
		'authorization',
		'signature',
		'card',
		'pan',
		'cvv',
		'cvc',
		'otp',
	];

	/**
	 * Sensitive key names that only read as sensitive when whole, compared
	 * against the key with all non-alphanumeric characters removed.
	 */
	const SENSITIVE_KEY_NAMES = [
		'apikey',
		'privatekey',
		'secretkey',
		'accesskey',
		'sessionid',
		'sessiontoken',
		'creditcard',
		'cardnumber',
		'cardholder',
		'ccnumber',
		'securitycode',
		'resettoken',
		'bearertoken',
		'refreshtoken',
	];

	/**
	 * Record one audit event.
	 *
	 * Expected shape:
	 *
	 *     [
	 *         'blog_id'        => int|null,  // required key; null = network-scoped event
	 *         'actor_user_id'  => int|null,  // required key; null = system-generated event
	 *         'action'         => string,    // required, e.g. 'hall_staff.role_changed'
	 *         'target_type'    => string|null,
	 *         'target_id'      => string|int|null,
	 *         'previous_value' => array|null,
	 *         'new_value'      => array|null,
	 *         'context'        => array|null,
	 *     ]
	 *
	 * blog_id and actor_user_id are required KEYS (their values may be null) so
	 * that tenant attribution and actor attribution are always a conscious
	 * decision rather than an accidental omission.
	 *
	 * Actor identity is supplied by the calling trusted server-side service and
	 * must be derived from authenticated server context — never accepted from
	 * client-supplied request input. record() does not infer it, because
	 * legitimate system and background events have no current user.
	 *
	 * @param array $event Event definition.
	 * @return int|\WP_Error Inserted audit row ID, or \WP_Error on failure.
	 */
	public static function record( array $event ) {
		$prepared = self::prepare_event( $event );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return self::insert( $prepared );
	}

	/**
	 * Validate and normalize an event into the exact column set for insertion.
	 *
	 * Runs before any database work so an invalid event costs nothing and can
	 * never leave a partial row behind.
	 *
	 * @return array|\WP_Error
	 */
	private static function prepare_event( array $event ) {
		$action = self::validate_action( $event );
		if ( is_wp_error( $action ) ) {
			return $action;
		}

		$blog_id = self::validate_blog_id( $event );
		if ( is_wp_error( $blog_id ) ) {
			return $blog_id;
		}

		$actor_user_id = self::validate_actor( $event );
		if ( is_wp_error( $actor_user_id ) ) {
			return $actor_user_id;
		}

		$target = self::validate_target( $event );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$payloads = self::validate_payloads( $event );
		if ( is_wp_error( $payloads ) ) {
			return $payloads;
		}

		return [
			'blog_id'        => $blog_id,
			'actor_user_id'  => $actor_user_id,
			'action'         => $action,
			'target_type'    => $target['target_type'],
			'target_id'      => $target['target_id'],
			'previous_value' => $payloads['previous_value'],
			'new_value'      => $payloads['new_value'],
			'context'        => $payloads['context'],
			// UTC is the single canonical convention for this table. Written
			// explicitly from application code so the value never depends on
			// the database server's timezone, and never accepted from the
			// caller so events cannot be backdated.
			'created_at'     => current_time( 'mysql', true ),
		];
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function validate_action( array $event ) {
		$action = isset( $event['action'] ) && is_string( $event['action'] ) ? trim( $event['action'] ) : '';

		if ( '' === $action ) {
			return self::invalid_event( 'A non-empty action is required.' );
		}

		if ( strlen( $action ) > 100 ) {
			return self::invalid_event( 'The action exceeds the maximum length of 100 characters.' );
		}

		// Stable, machine-readable, namespaced event types only
		// (e.g. hall_staff.role_changed) — never free-form prose.
		if ( ! preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $action ) ) {
			return self::invalid_event( 'The action must be a namespaced machine-readable event type.' );
		}

		return $action;
	}

	/**
	 * @return int|null|\WP_Error
	 */
	private static function validate_blog_id( array $event ) {
		if ( ! array_key_exists( 'blog_id', $event ) ) {
			return self::invalid_event( 'A blog_id is required; pass null only for genuinely network-scoped events.' );
		}

		$blog_id = $event['blog_id'];

		if ( null === $blog_id ) {
			return null;
		}

		if ( ! is_int( $blog_id ) && ! ( is_string( $blog_id ) && ctype_digit( $blog_id ) ) ) {
			return new \WP_Error(
				'invalid_blog',
				__( 'The blog_id must be a positive integer or null.', 'bingocommerce-core' )
			);
		}

		$blog_id = (int) $blog_id;

		if ( $blog_id < 1 ) {
			return new \WP_Error(
				'invalid_blog',
				__( 'The blog_id must be a positive integer or null.', 'bingocommerce-core' )
			);
		}

		// Hall attribution is the highest-severity correctness concern on this
		// platform, so a hall-scoped event must name a site that exists.
		if ( is_multisite() && null === get_site( $blog_id ) ) {
			return new \WP_Error(
				'invalid_blog',
				__( 'The blog_id does not match an existing site.', 'bingocommerce-core' )
			);
		}

		return $blog_id;
	}

	/**
	 * @return int|null|\WP_Error
	 */
	private static function validate_actor( array $event ) {
		if ( ! array_key_exists( 'actor_user_id', $event ) ) {
			return self::invalid_event( 'An actor_user_id is required; pass null only for system-generated events.' );
		}

		$actor = $event['actor_user_id'];

		if ( null === $actor ) {
			return null;
		}

		if ( ! is_int( $actor ) && ! ( is_string( $actor ) && ctype_digit( $actor ) ) ) {
			return self::invalid_event( 'The actor_user_id must be a positive integer or null.' );
		}

		$actor = (int) $actor;

		if ( $actor < 1 ) {
			return self::invalid_event( 'The actor_user_id must be a positive integer or null.' );
		}

		/*
		 * Existence is deliberately NOT verified here. An audit event is
		 * frequently written as part of removing or deleting the very identity
		 * it references, and a lookup would make the accountability record fail
		 * exactly when it matters most.
		 */
		return $actor;
	}

	/**
	 * Target type and identifier are all-or-nothing: a type without an
	 * identifier (or the reverse) cannot be resolved later, so it is rejected
	 * rather than stored as a half-record.
	 *
	 * @return array|\WP_Error
	 */
	private static function validate_target( array $event ) {
		$type = $event['target_type'] ?? null;
		$id   = $event['target_id'] ?? null;

		if ( null === $type && null === $id ) {
			return [
				'target_type' => null,
				'target_id'   => null,
			];
		}

		if ( null === $type || null === $id ) {
			return self::invalid_event( 'target_type and target_id must be provided together.' );
		}

		if ( ! is_string( $type ) || ! preg_match( '/^[a-z0-9_]+$/', $type ) || strlen( $type ) > 50 ) {
			return self::invalid_event( 'The target_type must be a lowercase machine-readable string of 50 characters or fewer.' );
		}

		/*
		 * Target identifiers are not assumed to be integers — tickets, devices,
		 * and future external references may all be non-numeric — but they are
		 * restricted to string or int. Floats and booleans have no stable string
		 * form worth persisting in an accountability record (0.1 + 0.2, true
		 * casting to "1"), and no current requirement calls for either.
		 */
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return self::invalid_event( 'The target_id must be a string or an integer.' );
		}

		$id = trim( (string) $id );

		if ( '' === $id || strlen( $id ) > 191 ) {
			return self::invalid_event( 'The target_id must be between 1 and 191 characters.' );
		}

		return [
			'target_type' => $type,
			'target_id'   => $id,
		];
	}

	/**
	 * Validate, screen, and JSON-encode the structured payload columns.
	 *
	 * @return array|\WP_Error
	 */
	private static function validate_payloads( array $event ) {
		$encoded = [];

		foreach ( self::PAYLOAD_KEYS as $key ) {
			$value = $event[ $key ] ?? null;

			if ( null === $value ) {
				$encoded[ $key ] = null;
				continue;
			}

			if ( ! is_array( $value ) ) {
				return self::invalid_event( sprintf( 'The %s payload must be an array or null.', $key ) );
			}

			if ( [] === $value ) {
				$encoded[ $key ] = null;
				continue;
			}

			$offending = self::find_sensitive_key( $value );

			if ( null !== $offending ) {
				return new \WP_Error(
					'sensitive_data_rejected',
					sprintf(
						/* translators: 1: payload column name, 2: offending key name. */
						__( 'The %1$s payload contains a key that may carry sensitive data: %2$s', 'bingocommerce-core' ),
						$key,
						$offending
					)
				);
			}

			$json = wp_json_encode( $value );

			if ( false === $json ) {
				return new \WP_Error(
					'serialization_failed',
					sprintf(
						/* translators: %s: payload column name. */
						__( 'The %s payload could not be encoded as JSON.', 'bingocommerce-core' ),
						$key
					)
				);
			}

			if ( strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
				return new \WP_Error(
					'payload_too_large',
					sprintf(
						/* translators: %s: payload column name. */
						__( 'The %s payload exceeds the maximum audit payload size.', 'bingocommerce-core' ),
						$key
					)
				);
			}

			$encoded[ $key ] = $json;
		}

		return $encoded;
	}

	/**
	 * Recursively search a payload for a key that may carry sensitive data.
	 *
	 * Keys only — value inspection is not attempted, because guessing whether
	 * an arbitrary string is a secret is unreliable. Consumers remain
	 * responsible for keeping payloads minimal: IDs and before/after state,
	 * not duplicated user profiles.
	 *
	 * @return string|null The offending key, or null when the payload is clean.
	 */
	private static function find_sensitive_key( array $payload ): ?string {
		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				return $key;
			}

			if ( is_array( $value ) ) {
				$nested = self::find_sensitive_key( $value );

				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Whether a payload key may carry sensitive data.
	 *
	 * Normalization runs BEFORE lowercasing, because lowercasing first destroys
	 * the camelCase word boundaries that carry the meaning: `userPassword`
	 * collapses to `userpassword`, which matches nothing.
	 *
	 *   1. Split camelCase/PascalCase runs   ( userPassword -> user Password )
	 *   2. Split acronym-to-word runs        ( APIToken     -> API Token     )
	 *   3. Lowercase
	 *   4. Split on any non-alphanumeric run and on letter-to-digit boundaries,
	 *      which normalizes snake_case, kebab-case, dot.case, spaces, and
	 *      trailing digits into the same token list
	 *
	 * A key is rejected when any single token is a sensitive term, when any two
	 * ADJACENT tokens join into a sensitive compound (so `private key` and
	 * `user_private_key` are both caught), or when the whole key compacted to
	 * alphanumerics is a sensitive compound.
	 *
	 * Compounds are matched pairwise rather than by single token so that `key`
	 * and `number` stay legal on their own — `meta_key` and `cache_key` are
	 * ordinary audit payload keys and must keep working.
	 */
	private static function is_sensitive_key( string $key ): bool {
		$compact = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $key ) );

		if ( in_array( $compact, self::SENSITIVE_KEY_NAMES, true ) ) {
			return true;
		}

		$spaced = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', $key );
		$spaced = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1 $2', (string) $spaced );

		$tokens = preg_split(
			'/[^a-z0-9]+|(?<=[a-z])(?=[0-9])/',
			strtolower( (string) $spaced ),
			-1,
			PREG_SPLIT_NO_EMPTY
		);
		$tokens = (array) $tokens;

		foreach ( $tokens as $token ) {
			if ( in_array( $token, self::SENSITIVE_KEY_TOKENS, true ) ) {
				return true;
			}
		}

		$count = count( $tokens );

		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( in_array( $tokens[ $i ] . $tokens[ $i + 1 ], self::SENSITIVE_KEY_NAMES, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Insert a fully prepared event.
	 *
	 * @return int|\WP_Error
	 */
	private static function insert( array $prepared ) {
		global $wpdb;

		$table = Migrations::audit_log_table();

		$inserted = $wpdb->insert(
			$table,
			$prepared,
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Captured before any further query runs: $wpdb->last_error is
			// reset by the next successful statement, including the table
			// lookup below, which would otherwise discard the one diagnostic
			// worth keeping.
			$db_error = $wpdb->last_error;

			// Only reached on failure, so the extra lookup never costs anything
			// on the success path.
			if ( ! Migrations::table_exists( $table ) ) {
				return new \WP_Error(
					'audit_table_missing',
					__( 'The BingoCommerce audit table does not exist.', 'bingocommerce-core' )
				);
			}

			// The raw database error is retained for server-side diagnosis only
			// and is never surfaced in the user-facing message.
			return new \WP_Error(
				'insert_failed',
				__( 'The audit event could not be recorded.', 'bingocommerce-core' ),
				[ 'db_error' => $db_error ]
			);
		}

		return (int) $wpdb->insert_id;
	}

	private static function invalid_event( string $message ): \WP_Error {
		return new \WP_Error( 'invalid_event', $message );
	}
}
