<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ADR-042 provisioning-operation ledger: one mutable row per Portal Hall.
 *
 * PERSISTENCE AND CLASSIFICATION ONLY. Nothing here authenticates a request,
 * registers a route, invokes the platform provisioning filter, creates or
 * initializes a WordPress site, mutates a Hall mapping, applies a Portal
 * snapshot, or writes HallCommerceState. Writing a row changes no request's
 * behaviour; the orchestrator that gives these primitives meaning is a later
 * increment.
 *
 * FOUR READ OUTCOMES ARE KEPT APART, because collapsing any two of them turns a
 * failure into a licence:
 *
 *   - VERIFIED ABSENCE (null) — the table answered and holds no row for this
 *     Hall, so this is a first command.
 *   - VERIFIED VALID ROW (array) — every persisted external-derived value and
 *     relational invariant checked out.
 *   - UNREADABLE (`operation_read_failed`) — the storage could not be asked.
 *   - CORRUPT / INTERNALLY IMPOSSIBLE (`operation_row_invalid`) — it answered
 *     with state this build must never act on.
 *
 * The last two fail closed. Treating either as "no row" would let a first-
 * command insert run against a Hall that already owns a site.
 *
 * CONCURRENCY LIVES IN THE DATABASE, NOT IN PHP. There is no SELECT-then-INSERT
 * anywhere: a first command is a plain INSERT whose PRIMARY KEY resolves
 * simultaneous writers, and every subsequent mutation is a single conditional
 * UPDATE whose WHERE clause carries the current Hall, `operation_id` and
 * `intent_sequence` fence. AFFECTED-ROW COUNTS ALONE NEVER PROVE SUCCESS; every
 * mutation is followed by an authoritative read-back, and the read-back decides.
 *
 * NOTHING LEAKS. Every failure is one of a fixed set of categories. No SQL,
 * table name, driver diagnostic, stored payload, candidate UUID, foreign Hall
 * identity or credential is ever returned.
 */
final class ProvisioningOperationRepository {

	/* Classification results (ADR-042 precedence (a)-(e)). */
	public const CLASSIFY_NEW_COMMAND = 'new_command';
	public const CLASSIFY_EXACT_RETRY = 'exact_retry';
	public const CLASSIFY_SUPERSEDED  = 'superseded';
	public const CLASSIFY_CONFLICT    = 'conflict';

	/** Datetime representation every stored row must use (UTC). */
	private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

	/** ADR-042 allows exactly three internal ownership-UUID attempts. */
	public const OWNERSHIP_ID_ATTEMPTS = 3;

	/** Closed `last_error_category` vocabulary. */
	public const LAST_ERROR_CATEGORIES = [
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

	/** Columns every read selects, in a fixed order. */
	private const COLUMNS = 'portal_hall_id, blog_id, site_ownership_id, site_slug, request_site_slug, request_hall_name, portal_actor_id, operation_id, intent_sequence, enforced_sequence, desired_state, request_canonical_version, request_fingerprint, last_error_category, created_at, updated_at';

	/* ------------------------------------------------------------------ *
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Load one Hall's operation row.
	 *
	 * @param mixed $portal_hall_id
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public static function load( $portal_hall_id ) {
		if ( ! is_int( $portal_hall_id ) || $portal_hall_id < 1 ) {
			return self::error( 'validation_failed', 'The Portal Hall ID is invalid.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$previous = $wpdb->suppress_errors( true );
		try {
			$row   = $wpdb->get_row(
				$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE portal_hall_id = %d", $portal_hall_id ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			// The exception object is never read: its message and trace can
			// quote SQL, stored values and connection details.
			unset( $throwable );

			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error ) {
			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		}
		if ( ! is_array( $row ) ) {
			// VERIFIED absence: the statement succeeded and returned no row.
			return null;
		}

		return self::validate_stored_row( $row, $portal_hall_id );
	}

	/**
	 * Validate a stored row before it is cast, compared, hashed or classified.
	 *
	 * Representation is checked before value throughout. Casting straight to
	 * int would turn "12abc", "1e3" and a negative into plausible-looking
	 * identifiers, and casting a sequence beyond PHP's integer range would make
	 * it compare wrongly against a request.
	 *
	 * THE FINGERPRINT IS RECOMPUTED, not merely pattern-checked. A row whose
	 * stored fingerprint disagrees with its own stored request values cannot be
	 * used to recognise an exact retry, which is precisely what classification
	 * would use it for.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate_stored_row( array $row, int $portal_hall_id ) {
		$hall = self::stored_unsigned( $row['portal_hall_id'] ?? null );
		if ( null === $hall || $hall < 1 || $hall !== $portal_hall_id ) {
			return self::corrupt();
		}

		// is_uuid() already requires a string, so the value is never cast: an
		// integer that merely looks like an identifier is not one.
		$operation_id = $row['operation_id'] ?? null;
		if ( ! ProvisioningCanonicalRequest::is_uuid( $operation_id ) ) {
			return self::corrupt();
		}

		$intent = self::stored_unsigned( $row['intent_sequence'] ?? null );
		if ( null === $intent
			|| $intent < 1
			|| $intent > ProvisioningCanonicalRequest::max_intent_sequence() ) {
			return self::corrupt();
		}

		$enforced = self::stored_unsigned( $row['enforced_sequence'] ?? null );
		if ( null === $enforced
			|| $enforced > ProvisioningCanonicalRequest::max_intent_sequence()
			|| $enforced > $intent ) {
			// enforced_sequence may be 0 (nothing confirmed yet) but can never
			// run ahead of the intent it is supposed to be enforcing.
			return self::corrupt();
		}

		$desired_state = $row['desired_state'] ?? null;
		if ( ! is_string( $desired_state )
			|| ! in_array( $desired_state, ProvisioningCanonicalRequest::DESIRED_STATES, true ) ) {
			return self::corrupt();
		}

		$canonical_version = self::stored_unsigned( $row['request_canonical_version'] ?? null );
		if ( ProvisioningCanonicalRequest::CANONICAL_VERSION !== $canonical_version ) {
			return self::corrupt();
		}

		$fingerprint = $row['request_fingerprint'] ?? null;
		if ( ! ProvisioningCanonicalRequest::is_fingerprint( $fingerprint ) ) {
			return self::corrupt();
		}

		$actor = self::stored_unsigned( $row['portal_actor_id'] ?? null );
		if ( null === $actor || $actor < 1 ) {
			return self::corrupt();
		}

		$site_ownership_id = self::stored_nullable_string( $row['site_ownership_id'] ?? null );
		if ( false === $site_ownership_id ) {
			return self::corrupt();
		}
		if ( null !== $site_ownership_id && ! ProvisioningCanonicalRequest::is_uuid( $site_ownership_id ) ) {
			return self::corrupt();
		}

		foreach ( [ 'site_slug', 'request_site_slug' ] as $slug_field ) {
			$slug = self::stored_nullable_string( $row[ $slug_field ] ?? null );
			if ( false === $slug ) {
				return self::corrupt();
			}
			if ( null !== $slug && ! ProvisioningCanonicalRequest::is_slug( $slug ) ) {
				return self::corrupt();
			}
			$row[ $slug_field ] = $slug;
		}

		$hall_name = self::stored_nullable_string( $row['request_hall_name'] ?? null );
		if ( false === $hall_name ) {
			return self::corrupt();
		}
		if ( null !== $hall_name && ! ProvisioningCanonicalRequest::is_valid_hall_name( $hall_name ) ) {
			return self::corrupt();
		}

		// A numeric column, so it uses the strict unsigned parser and never the
		// textual one: `blog_id` is the only nullable field here that is a number.
		$blog_id = $row['blog_id'] ?? null;
		if ( null !== $blog_id ) {
			$blog_id = self::stored_unsigned( $blog_id );
			if ( null === $blog_id || $blog_id < 1 ) {
				return self::corrupt();
			}
		}

		$last_error = self::stored_nullable_string( $row['last_error_category'] ?? null );
		if ( false === $last_error ) {
			return self::corrupt();
		}
		if ( null !== $last_error && ! in_array( $last_error, self::LAST_ERROR_CATEGORIES, true ) ) {
			return self::corrupt();
		}

		foreach ( [ 'created_at', 'updated_at' ] as $stamp ) {
			$value = $row[ $stamp ] ?? null;
			if ( ! is_string( $value ) || ! self::is_timestamp( $value ) ) {
				return self::corrupt();
			}
		}

		/*
		 * RELATIONAL INVARIANTS. These are the ones an ownership-null branch
		 * would otherwise misread as "no site created":
		 *
		 *   - a bound blog requires BOTH a permanent ownership ID and a durable
		 *     slug, because durable ownership is a three-fact agreement and two
		 *     of those facts live in this row;
		 *   - an ownership ID requires a durable slug, because the ID is only
		 *     ever assigned against a reserved address.
		 */
		if ( null !== $blog_id && ( null === $site_ownership_id || null === $row['site_slug'] ) ) {
			return self::corrupt();
		}
		if ( null !== $site_ownership_id && null === $row['site_slug'] ) {
			return self::corrupt();
		}

		/*
		 * REQUEST-FIELD LIFECYCLE INVARIANTS (ADR-042 null rules). The request
		 * columns describe the CURRENT command, so a row whose stored request
		 * shape could never have been a valid command is not a row to act on:
		 *
		 *   - a suspend command carries neither address nor name;
		 *   - the address and the name travel together, always, in both
		 *     directions — one of them alone describes no command this build
		 *     accepts, and would let a half-applied replacement look current;
		 *   - an operational command that has NOT yet bound a site must name
		 *     the address it wants, because there is no durable ownership to
		 *     fall back on. Only after `blog_id` exists may they both be null,
		 *     which is the post-ownership "enable, unchanged address" form.
		 */
		if ( ProvisioningCanonicalRequest::SUSPENDED === $desired_state
			&& ( null !== $row['request_site_slug'] || null !== $hall_name ) ) {
			return self::corrupt();
		}
		if ( ( null === $row['request_site_slug'] ) !== ( null === $hall_name ) ) {
			return self::corrupt();
		}
		if ( ProvisioningCanonicalRequest::OPERATIONAL === $desired_state
			&& null === $blog_id
			&& ( null === $row['request_site_slug'] || null === $hall_name ) ) {
			return self::corrupt();
		}

		$validated = [
			'portal_hall_id'            => $hall,
			'blog_id'                   => $blog_id,
			'site_ownership_id'         => $site_ownership_id,
			'site_slug'                 => $row['site_slug'],
			'request_site_slug'         => $row['request_site_slug'],
			'request_hall_name'         => $hall_name,
			'portal_actor_id'           => $actor,
			'operation_id'              => $operation_id,
			'intent_sequence'           => $intent,
			'enforced_sequence'         => $enforced,
			'desired_state'             => $desired_state,
			'request_canonical_version' => $canonical_version,
			'request_fingerprint'       => $fingerprint,
			'last_error_category'       => $last_error,
			'created_at'                => $row['created_at'],
			'updated_at'                => $row['updated_at'],
		];

		$recomputed = ProvisioningCanonicalRequest::fingerprint(
			[
				'portal_hall_id'  => $validated['portal_hall_id'],
				'operation_id'    => $validated['operation_id'],
				'intent_sequence' => $validated['intent_sequence'],
				'desired_state'   => $validated['desired_state'],
				'site_slug'       => $validated['request_site_slug'],
				'hall_name'       => $validated['request_hall_name'],
				'portal_actor_id' => $validated['portal_actor_id'],
			]
		);
		if ( ! is_string( $recomputed ) || ! hash_equals( $validated['request_fingerprint'], $recomputed ) ) {
			return self::corrupt();
		}

		return $validated;
	}

	/* ------------------------------------------------------------------ *
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * The exact ADR-042 precedence order, and nothing else.
	 *
	 * (a) same current UUID + same sequence + same fingerprint -> exact retry
	 * (b) same current UUID, ANY other difference               -> conflict
	 * (c) different UUID, lower sequence                        -> superseded
	 * (d) different UUID, equal sequence                        -> conflict
	 * (e) different UUID, higher sequence                       -> new command
	 *
	 * (b) IS EVALUATED BEFORE (c). A mismatch on the CURRENT command is a
	 * conflict whether the supplied sequence is lower or higher, so a Portal
	 * that reuses a UUID with a changed payload can never be answered
	 * `superseded` — which would read as "someone newer already won" rather
	 * than "your own command disagrees with itself".
	 *
	 * @param array<string,mixed>|null $stored Verified-absent (null) or verified-valid row.
	 * @param array<string,mixed>      $request Validated canonical request.
	 * @return string|\WP_Error
	 */
	public static function classify( ?array $stored, array $request ) {
		foreach ( [ 'operation_id', 'intent_sequence', 'request_fingerprint' ] as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				return self::error( 'validation_failed', 'The provisioning command is incomplete.' );
			}
		}
		if ( ! ProvisioningCanonicalRequest::is_uuid( $request['operation_id'] )
			|| ! is_int( $request['intent_sequence'] )
			|| ! ProvisioningCanonicalRequest::is_fingerprint( $request['request_fingerprint'] ) ) {
			return self::error( 'validation_failed', 'The provisioning command is invalid.' );
		}

		if ( null === $stored ) {
			return self::CLASSIFY_NEW_COMMAND;
		}

		if ( hash_equals( (string) $stored['operation_id'], (string) $request['operation_id'] ) ) {
			$same = $stored['intent_sequence'] === $request['intent_sequence']
				&& hash_equals( (string) $stored['request_fingerprint'], (string) $request['request_fingerprint'] );

			return $same ? self::CLASSIFY_EXACT_RETRY : self::CLASSIFY_CONFLICT;
		}

		if ( $request['intent_sequence'] < $stored['intent_sequence'] ) {
			return self::CLASSIFY_SUPERSEDED;
		}
		if ( $request['intent_sequence'] === $stored['intent_sequence'] ) {
			return self::CLASSIFY_CONFLICT;
		}

		return self::CLASSIFY_NEW_COMMAND;
	}

	/* ------------------------------------------------------------------ *
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Persist a Hall's FIRST command.
	 *
	 * A plain INSERT, deliberately. The PRIMARY KEY on `portal_hall_id` is what
	 * resolves two simultaneous first writers; a SELECT-then-INSERT would decide
	 * from a read that is already stale by the time the write is issued.
	 *
	 * The durable `site_slug` reservation is established here for an operational
	 * command, because the reservation IS the durable slug column and
	 * `UNIQUE(site_slug)` is the only cross-Hall address exclusion. A suspend
	 * command reserves nothing.
	 *
	 * @param array<string,mixed> $request Validated canonical request.
	 * @return array{outcome:string,row:?array<string,mixed>}|\WP_Error
	 */
	public static function insert_first_command( array $request ) {
		$checked = self::require_canonical( $request );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		/*
		 * A FIRST command is by definition pre-ownership: there is no durable
		 * `blog_id` to fall back on, so an operational one that names no
		 * address describes a site nobody could create. The post-ownership
		 * null form is legitimate only on a row that already carries a bound
		 * site, which this Hall does not yet have.
		 */
		if ( ProvisioningCanonicalRequest::OPERATIONAL === $request['desired_state']
			&& ( null === $request['site_slug'] || null === $request['hall_name'] ) ) {
			return self::error( 'validation_failed', 'A first operational command must carry an address and a Hall name.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();
		$now   = gmdate( self::TIMESTAMP_FORMAT );

		$durable_slug = ProvisioningCanonicalRequest::OPERATIONAL === $request['desired_state']
			? $request['site_slug']
			: null;

		$args      = [ $request['portal_hall_id'] ];
		$slug      = self::nullable( $durable_slug, $args );
		$req_slug  = self::nullable( $request['site_slug'], $args );
		$req_name  = self::nullable( $request['hall_name'], $args );
		$args      = array_merge(
			$args,
			[
				$request['portal_actor_id'],
				$request['operation_id'],
				$request['intent_sequence'],
				$request['desired_state'],
				$request['request_canonical_version'],
				$request['request_fingerprint'],
				$now,
				$now,
			]
		);

		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
			 (portal_hall_id, blog_id, site_ownership_id, site_slug, request_site_slug, request_hall_name,
			  portal_actor_id, operation_id, intent_sequence, enforced_sequence, desired_state,
			  request_canonical_version, request_fingerprint, last_error_category, created_at, updated_at)
			 VALUES (%d, NULL, NULL, {$slug}, {$req_slug}, {$req_name}, %d, %s, %d, 0, %s, %d, %s, NULL, %s, %s)",
			$args
		);

		/*
		 * The write outcome is recorded but NOT returned, because a refused
		 * INSERT is the ordinary result of a retry and of a lost primary-key
		 * race, and neither of those is a failure. The read-back below is
		 * authoritative on BOTH paths: a reported success whose row does not
		 * carry this command is not a success, and a reported failure may still
		 * have stored exactly this command already.
		 */
		$written = self::execute( $sql );

		$stored = self::load( $request['portal_hall_id'] );
		if ( $stored instanceof \WP_Error ) {
			return self::error( 'unverified', 'The provisioning operation could not be confirmed.' );
		}

		if ( null === $stored ) {
			if ( ! $written instanceof \WP_Error ) {
				// The statement reported success yet this Hall holds no row. That
				// is not a refusal to classify; it is an unexplained result.
				return self::error( 'unverified', 'The provisioning operation could not be confirmed.' );
			}

			// No row exists, so nothing was inserted. Either a UNIQUE index
			// other than the primary key refused it, or the statement failed.
			return self::classify_insert_refusal( $request );
		}

		if ( self::matches_command( $stored, $request ) ) {
			return [ 'outcome' => 'stored', 'row' => $stored ];
		}

		/*
		 * A row exists for this Hall but is not ours. Another writer won the
		 * primary-key race, so the caller must re-classify against what is
		 * actually stored rather than assume anything about it.
		 */
		return [ 'outcome' => 'raced', 'row' => $stored ];
	}

	/**
	 * Why was an insert refused, when no row for this Hall exists afterwards?
	 *
	 * Answered by PROBING the closed set of possibilities rather than by parsing
	 * the driver's duplicate-key message: an error string is a diagnostic, not a
	 * contract, and control flow must not depend on its wording. The probes
	 * return only a fixed category and never the conflicting row, its Hall, or
	 * any other foreign identity.
	 *
	 * @param array<string,mixed> $request
	 * @return array{outcome:string,row:?array<string,mixed>}|\WP_Error
	 */
	private static function classify_insert_refusal( array $request ) {
		$foreign_operation = self::foreign_holder( 'operation_id', $request['operation_id'], $request['portal_hall_id'] );
		if ( $foreign_operation instanceof \WP_Error ) {
			return $foreign_operation;
		}
		if ( $foreign_operation ) {
			return [ 'outcome' => 'operation_id_taken', 'row' => null ];
		}

		if ( null !== $request['site_slug']
			&& ProvisioningCanonicalRequest::OPERATIONAL === $request['desired_state'] ) {
			$foreign_slug = self::foreign_holder( 'site_slug', $request['site_slug'], $request['portal_hall_id'] );
			if ( $foreign_slug instanceof \WP_Error ) {
				return $foreign_slug;
			}
			if ( $foreign_slug ) {
				return [ 'outcome' => 'site_slug_taken', 'row' => null ];
			}
		}

		return self::error( 'state_write_failed', 'The provisioning operation could not be stored.' );
	}

	/**
	 * Is a UNIQUE value held by some OTHER Hall's row?
	 *
	 * COUNT-ONLY, and the count itself is read strictly. `(int)` on an
	 * unreadable answer produces 0, which reads as "nobody holds it" — the
	 * exact wrong answer on the exact failure where a caller is deciding
	 * whether a duplicate key refused its write.
	 *
	 * @param mixed $value
	 * @return bool|\WP_Error
	 */
	private static function foreign_holder( string $column, $value, int $portal_hall_id ) {
		if ( ! in_array( $column, [ 'operation_id', 'site_slug', 'site_ownership_id' ], true ) ) {
			// The column name is never request data, but it reaches the
			// statement as an identifier, so the closed set is enforced anyway.
			return self::error( 'internal_error', 'The provisioning probe is not permitted.' );
		}
		if ( ! is_string( $value ) ) {
			return self::error( 'internal_error', 'The provisioning probe value is invalid.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$previous = $wpdb->suppress_errors( true );
		try {
			// COUNT only. The conflicting row's Hall ID is deliberately never
			// selected, so it cannot be disclosed even accidentally.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$column} = %s AND portal_hall_id <> %d",
					$value,
					$portal_hall_id
				)
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		return self::probe_count( $count, $error );
	}

	/**
	 * Accept a newer command onto an existing row (precedence (e)).
	 *
	 * The guard carries the PREVIOUS identity and requires the new sequence to
	 * be strictly greater, so two concurrent newer commands cannot both apply
	 * and an older one can never overwrite a newer one. Durable facts —
	 * `blog_id`, `site_ownership_id`, `site_slug` — are untouched here: a nulled
	 * request field describes the CURRENT command's request, never the durable
	 * reservation. `last_error_category` is cleared as part of the same guarded
	 * replacement, so the new command does not inherit the old one's failure.
	 *
	 * PRECEDENCE IS RE-DECIDED HERE, NOT TRUSTED. A caller that classified
	 * wrongly, classified against a different row, or never classified at all
	 * must not be able to overwrite a stored command through this method, so
	 * the supplied row is re-validated and re-classified and only (e) — a
	 * DIFFERENT `operation_id` with a STRICTLY HIGHER sequence — is allowed to
	 * write. Every other classification returns its own fixed outcome and
	 * mutates nothing, including the retry and the same-UUID-changed-sequence
	 * case that a bare `WHERE operation_id = ...` guard would happily accept.
	 *
	 * @param array<string,mixed> $stored  Verified-valid current row.
	 * @param array<string,mixed> $request Validated canonical request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function replace_command( array $stored, array $request ) {
		$checked = self::require_canonical( $request );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}
		if ( ( $stored['portal_hall_id'] ?? null ) !== $request['portal_hall_id'] ) {
			return self::error( 'internal_error', 'The provisioning command does not match the stored Hall.' );
		}

		$stored = self::validate_stored_row( $stored, $request['portal_hall_id'] );
		if ( $stored instanceof \WP_Error ) {
			return $stored;
		}

		$classification = self::classify( $stored, $request );
		if ( $classification instanceof \WP_Error ) {
			return $classification;
		}
		if ( self::CLASSIFY_NEW_COMMAND !== $classification ) {
			return self::classification_refusal( $classification );
		}
		if ( hash_equals( $stored['operation_id'], (string) $request['operation_id'] )
			|| $request['intent_sequence'] <= $stored['intent_sequence'] ) {
			// Unreachable through classify(), and asserted anyway: this is the
			// one place where getting (e) wrong overwrites a live command.
			return self::error( 'conflict', 'The provisioning command conflicts with the stored command.' );
		}

		/*
		 * A pre-ownership row has no durable site to inherit, so an operational
		 * replacement must still name its address — the same rule a first
		 * command obeys, applied where the row, not the request, supplies the
		 * context.
		 */
		if ( ProvisioningCanonicalRequest::OPERATIONAL === $request['desired_state']
			&& null === $stored['blog_id']
			&& ( null === $request['site_slug'] || null === $request['hall_name'] ) ) {
			return self::error( 'validation_failed', 'An operational command must carry an address and a Hall name before a site exists.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$args     = [];
		$req_slug = self::nullable( $request['site_slug'], $args );
		$req_name = self::nullable( $request['hall_name'], $args );
		$args     = array_merge(
			$args,
			[
				$request['portal_actor_id'],
				$request['operation_id'],
				$request['desired_state'],
				$request['request_canonical_version'],
				$request['request_fingerprint'],
				gmdate( self::TIMESTAMP_FORMAT ),
				$request['intent_sequence'],
				$request['portal_hall_id'],
				$stored['operation_id'],
				$stored['intent_sequence'],
				$request['intent_sequence'],
			]
		);

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET
			   request_site_slug         = {$req_slug},
			   request_hall_name         = {$req_name},
			   portal_actor_id           = %d,
			   operation_id              = %s,
			   desired_state             = %s,
			   request_canonical_version = %d,
			   request_fingerprint       = %s,
			   last_error_category       = NULL,
			   updated_at                = %s,
			   intent_sequence           = %d
			 WHERE portal_hall_id = %d
			   AND operation_id = %s
			   AND intent_sequence = %d
			   AND %d > intent_sequence",
			$args
		);

		/*
		 * `intent_sequence` is assigned LAST. MySQL evaluates SET assignments
		 * left to right, so every other column must be decided against the OLD
		 * sequence; advancing the sequence first would leave the rest of the row
		 * describing a command that is no longer current.
		 *
		 * The write outcome is recorded but never returned on its own: a driver
		 * that reports failure may still have applied the statement, and one
		 * that reports success may have applied it to nothing. The read-back
		 * below decides.
		 */
		$written = self::execute( $sql );

		$row = self::load( $request['portal_hall_id'] );
		if ( $row instanceof \WP_Error || null === $row ) {
			return self::error( 'unverified', 'The provisioning operation could not be confirmed.' );
		}
		if ( self::matches_command( $row, $request ) ) {
			// PROVEN stored, whatever the driver said.
			return $row;
		}

		if ( ! self::fence_matches( $row, $stored ) ) {
			/*
			 * The row carries neither this command nor the one that was
			 * guarded against, so the supplied view was stale. Whether this
			 * replacement never applied or applied and was itself replaced
			 * cannot be told apart from here, and reporting "nothing happened"
			 * for the second case would be a lie, so the outcome is unproven.
			 */
			return self::error( 'unverified', 'The provisioning operation could not be confirmed.' );
		}

		// The OLD command is provably still stored: nothing was replaced.
		if ( $written instanceof \WP_Error ) {
			$foreign = self::foreign_holder( 'operation_id', $request['operation_id'], $request['portal_hall_id'] );
			if ( true === $foreign ) {
				/*
				 * Another Hall's row already carries this `operation_id`, so
				 * `UNIQUE(operation_id)` refused the replacement. That is a
				 * command-identity collision, reported as a conflict with no
				 * hint of which Hall holds it.
				 */
				return self::error( 'conflict', 'The provisioning command conflicts with the stored command.' );
			}

			return self::error( 'state_write_failed', 'The provisioning operation could not be stored.' );
		}

		return self::error( 'unverified', 'The provisioning operation could not be confirmed.' );
	}

	/**
	 * The fixed result for a classification that must not write.
	 *
	 * One outcome per classification and nothing else: the caller learns that
	 * its command was a retry, a conflict or superseded, and learns nothing
	 * about the stored command it lost to.
	 */
	private static function classification_refusal( string $classification ): \WP_Error {
		if ( self::CLASSIFY_EXACT_RETRY === $classification ) {
			return self::error( self::CLASSIFY_EXACT_RETRY, 'The provisioning command is already the stored command.' );
		}
		if ( self::CLASSIFY_SUPERSEDED === $classification ) {
			return self::error( self::CLASSIFY_SUPERSEDED, 'The provisioning command has been superseded.' );
		}

		return self::error( self::CLASSIFY_CONFLICT, 'The provisioning command conflicts with the stored command.' );
	}

	/**
	 * Reserve or move this Hall's UNBOUND durable address.
	 *
	 * THE ADDRESS BELONGS TO THE CURRENT OPERATIONAL COMMAND. The slug written
	 * here must be exactly the one that command asked for, and that command
	 * must also carry a Hall name, because the durable reservation is the
	 * address the site will be created at — not a value a caller may choose
	 * independently of the command it is carrying out. A SUSPEND command never
	 * reserves anything: suspension takes a site away, and a Hall that is
	 * suspending an address it never held is an orchestration mistake, not a
	 * reservation.
	 *
	 * Permitted only while no site is bound and the current fence matches, and
	 * it alters this Hall's row alone — never another Hall, site or mapping.
	 * Once durable ownership exists the address is immutable.
	 *
	 * The preflight read decides ADMISSIBILITY only. Every one of its
	 * conditions is repeated in the UPDATE's own WHERE clause, so the guarantee
	 * against a concurrent writer remains the conditional statement and not the
	 * read.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function replace_unbound_slug( int $portal_hall_id, array $fence, string $slug ) {
		if ( ! ProvisioningCanonicalRequest::is_slug( $slug ) ) {
			return self::error( 'address_invalid', 'The site slug is not a valid DNS label.' );
		}
		$checked = self::require_fence( $fence );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		$current = self::load( $portal_hall_id );
		if ( $current instanceof \WP_Error ) {
			// Nothing has been written, and the row could not be read, so the
			// caller is told exactly that rather than given a reservation.
			return $current;
		}
		if ( null === $current || ! self::fence_matches( $current, $fence ) ) {
			return self::error( 'lock_unavailable', 'The provisioning command is no longer current.' );
		}
		if ( ProvisioningCanonicalRequest::OPERATIONAL !== $current['desired_state'] ) {
			return self::error( 'internal_error', 'A suspend command does not reserve an address.' );
		}
		if ( null === $current['request_site_slug'] || $slug !== $current['request_site_slug'] ) {
			return self::error( 'internal_error', 'The address does not match the current command.' );
		}
		if ( null === $current['request_hall_name'] ) {
			return self::error( 'internal_error', 'The current command carries no Hall name.' );
		}
		if ( null !== $current['blog_id'] ) {
			return self::error( 'internal_error', 'The address is immutable once a site is bound.' );
		}
		if ( $slug === $current['site_slug'] ) {
			// Already reserved by this very command. Nothing to write.
			return $current;
		}

		/*
		 * PREFLIGHT: an address another Hall already holds is refused before
		 * any write, which is the ordinary "that subdomain is taken" answer.
		 * A refusal discovered by UNIQUE(site_slug) at write time is a
		 * different thing and is classified below.
		 */
		$occupied = self::foreign_holder( 'site_slug', $slug, $portal_hall_id );
		if ( $occupied instanceof \WP_Error ) {
			return $occupied;
		}
		if ( true === $occupied ) {
			return self::error( 'address_occupied', 'The requested address is already reserved.' );
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET site_slug = %s, updated_at = %s
			 WHERE portal_hall_id = %d AND operation_id = %s AND intent_sequence = %d
			   AND blog_id IS NULL
			   AND desired_state = %s
			   AND request_site_slug = %s
			   AND request_hall_name IS NOT NULL",
			$slug,
			gmdate( self::TIMESTAMP_FORMAT ),
			$portal_hall_id,
			$fence['operation_id'],
			$fence['intent_sequence'],
			ProvisioningCanonicalRequest::OPERATIONAL,
			$slug
		);

		$written = self::execute( $sql );

		$row = self::load( $portal_hall_id );
		if ( $row instanceof \WP_Error || null === $row ) {
			return self::error( 'unverified', 'The address reservation could not be confirmed.' );
		}
		if ( self::fence_matches( $row, $fence ) && $slug === $row['site_slug'] ) {
			// PROVEN reserved, whatever the driver reported.
			return $row;
		}
		if ( ! self::fence_matches( $row, $fence ) ) {
			// execute() has already been invoked. A different current command
			// means this write may have landed and then been replaced; that is
			// unverified, never a retryable "nothing happened".
			return self::error( 'unverified', 'The address reservation could not be confirmed.' );
		}

		if ( $written instanceof \WP_Error ) {
			/*
			 * The fence still holds and the address is provably NOT reserved,
			 * so the statement was refused. A foreign holder that appeared
			 * between the preflight and the write is the ADR-042 concurrent
			 * foreign-collision path: conflict, nothing mutated, and no hint of
			 * who took it.
			 */
			$raced = self::foreign_holder( 'site_slug', $slug, $portal_hall_id );
			if ( true === $raced ) {
				return self::error( 'conflict', 'The provisioning command conflicts with a concurrent reservation.' );
			}

			return self::error( 'state_write_failed', 'The address reservation could not be written.' );
		}

		return self::error( 'unverified', 'The address reservation could not be confirmed.' );
	}

	/**
	 * Assign the permanent internal `site_ownership_id`, or reuse the existing one.
	 *
	 * Exactly three lowercase UUIDv4 attempts, each permitted ONLY while the
	 * field is still null, the current fence still matches, and the current
	 * command is an OPERATIONAL one whose durable `site_slug` is the current
	 * requested address (both request fields non-null, and
	 * `site_slug === request_site_slug`). A suspend command never receives a
	 * new ownership identity — there is no site being brought into existence
	 * for it to witness — though an identity assigned by an earlier enable is
	 * still returned to it unchanged, which is exactly how a later suspend
	 * proves it is talking about the same site.
	 *
	 * AN ATTEMPT IS SPENT ONLY ON A PROVEN COLLISION. "The write was refused
	 * and the field is still null" is not proof of a duplicate key: a deadlock,
	 * a dropped connection, a filtered statement and a genuine
	 * `UNIQUE(site_ownership_id)` collision all look identical from here. Each
	 * refusal is therefore probed for a foreign holder of that exact candidate,
	 * and only a positive answer costs one of the three attempts. Anything else
	 * returns the write failure immediately, because burning the budget on
	 * ordinary failures would report `internal_error` — "this identity space is
	 * exhausted" — for a database that was merely briefly unavailable.
	 *
	 * The candidate UUIDs are never returned or logged on the failure path.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function assign_site_ownership_id( int $portal_hall_id, array $fence ) {
		$checked = self::require_fence( $fence );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		for ( $attempt = 0; $attempt < self::OWNERSHIP_ID_ATTEMPTS; $attempt++ ) {
			$row = self::load( $portal_hall_id );
			if ( $row instanceof \WP_Error ) {
				return self::error( 'unverified', 'The site ownership identity could not be confirmed.' );
			}
			if ( null === $row || ! self::fence_matches( $row, $fence ) ) {
				return self::error( 'lock_unavailable', 'The provisioning command is no longer current.' );
			}
			if ( null !== $row['site_ownership_id'] ) {
				return $row;
			}
			if ( ProvisioningCanonicalRequest::OPERATIONAL !== $row['desired_state'] ) {
				return self::error( 'internal_error', 'A suspend command does not establish site ownership.' );
			}
			if ( null === $row['request_site_slug']
				|| null === $row['request_hall_name']
				|| null === $row['site_slug']
				|| $row['site_slug'] !== $row['request_site_slug'] ) {
				// The ID is only ever assigned against the currently requested
				// reserved address. A higher operational command that names a
				// different slug must reserve that slug first; allocating
				// against the previous durable slug would mint an identity for
				// the wrong address.
				return self::error( 'internal_error', 'No address is reserved for this Hall.' );
			}

			$candidate = strtolower( wp_generate_uuid4() );
			if ( ! ProvisioningCanonicalRequest::is_uuid( $candidate ) ) {
				return self::error( 'internal_error', 'A site ownership identity could not be generated.' );
			}

			$sql = $wpdb->prepare(
				"UPDATE {$table} SET site_ownership_id = %s, updated_at = %s
				 WHERE portal_hall_id = %d AND operation_id = %s AND intent_sequence = %d
				   AND site_ownership_id IS NULL
				   AND desired_state = %s
				   AND request_site_slug IS NOT NULL
				   AND request_hall_name IS NOT NULL
				   AND site_slug IS NOT NULL
				   AND site_slug = request_site_slug",
				$candidate,
				gmdate( self::TIMESTAMP_FORMAT ),
				$portal_hall_id,
				$fence['operation_id'],
				$fence['intent_sequence'],
				ProvisioningCanonicalRequest::OPERATIONAL
			);

			$written = self::execute( $sql );

			$after = self::load( $portal_hall_id );
			if ( $after instanceof \WP_Error ) {
				return self::error( 'unverified', 'The site ownership identity could not be confirmed.' );
			}
			if ( null === $after || ! self::fence_matches( $after, $fence ) ) {
				// execute() has already been invoked. A missing or superseded
				// row is unverified: reporting lock_unavailable would claim
				// nothing changed.
				return self::error( 'unverified', 'The site ownership identity could not be confirmed.' );
			}
			if ( null !== $after['site_ownership_id'] ) {
				// Read-back decides, not the affected-row count: the value now
				// stored is the value that counts, whoever wrote it.
				return $after;
			}
			if ( ! $written instanceof \WP_Error ) {
				// The statement reported success yet the field is still null.
				// That is not a collision to retry; it is an unexplained result.
				return self::error( 'unverified', 'The site ownership identity could not be confirmed.' );
			}

			$collided = self::foreign_holder( 'site_ownership_id', $candidate, $portal_hall_id );
			if ( $collided instanceof \WP_Error ) {
				// The collision question could not be answered, so it must not
				// be assumed either way, and no attempt is spent on a guess.
				return self::error( 'state_write_failed', 'The site ownership identity could not be written.' );
			}
			if ( true !== $collided ) {
				return self::error( 'state_write_failed', 'The site ownership identity could not be written.' );
			}

			// PROVEN collision on UNIQUE(site_ownership_id): spend one attempt
			// and try another candidate. The candidate is discarded silently.
		}

		return self::error( 'internal_error', 'A unique site ownership identity could not be allocated.' );
	}

	/**
	 * Attach a verified `blog_id` under the current fence.
	 *
	 * `blog_id IS NULL` in the guard means an already-bound row is never
	 * re-pointed at a different site. A `UNIQUE(blog_id)` collision means some
	 * other Hall row already claims this site: neither row is overwritten,
	 * durable ownership is not established, and the caller receives
	 * `site_ownership_unproven` with no `blog_id`, `site_url`, foreign Hall ID,
	 * slug or ownership ID in it.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function attach_blog_id( int $portal_hall_id, int $blog_id, array $fence ) {
		if ( $blog_id < 1 ) {
			return self::error( 'validation_failed', 'The WordPress site ID is invalid.' );
		}
		$checked = self::require_fence( $fence );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET blog_id = %d, updated_at = %s
			 WHERE portal_hall_id = %d AND operation_id = %s AND intent_sequence = %d
			   AND blog_id IS NULL AND site_ownership_id IS NOT NULL",
			$blog_id,
			gmdate( self::TIMESTAMP_FORMAT ),
			$portal_hall_id,
			$fence['operation_id'],
			$fence['intent_sequence']
		);

		$written = self::execute( $sql );

		$row = self::load( $portal_hall_id );
		if ( $row instanceof \WP_Error ) {
			return self::error( 'unverified', 'The site attachment could not be confirmed.' );
		}
		if ( null === $row || ! self::fence_matches( $row, $fence ) ) {
			return self::error( 'unverified', 'The site attachment could not be confirmed.' );
		}
		if ( $blog_id === $row['blog_id'] ) {
			return $row;
		}

		if ( $written instanceof \WP_Error ) {
			$foreign = self::foreign_holder_blog( $blog_id, $portal_hall_id );
			if ( true === $foreign ) {
				return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
			}
		}

		return self::error( 'unverified', 'The site attachment could not be confirmed.' );
	}

	/**
	 * Is this `blog_id` already claimed by another Hall's row?
	 *
	 * @return bool|\WP_Error
	 */
	private static function foreign_holder_blog( int $blog_id, int $portal_hall_id ) {
		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$previous = $wpdb->suppress_errors( true );
		try {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND portal_hall_id <> %d",
					$blog_id,
					$portal_hall_id
				)
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		return self::probe_count( $count, $error );
	}

	/**
	 * A COUNT probe's answer, or a read failure.
	 *
	 * The representation is validated before the comparison: a null, a
	 * non-numeric answer or a reported error is "we could not ask", never
	 * "nobody holds it".
	 *
	 * @param mixed $count
	 * @return bool|\WP_Error
	 */
	private static function probe_count( $count, string $error ) {
		if ( '' !== $error ) {
			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		}

		$parsed = self::stored_unsigned( $count );
		if ( null === $parsed ) {
			return self::error( 'operation_read_failed', 'The provisioning operation could not be read.' );
		}

		return $parsed > 0;
	}

	/**
	 * Record this command's failure category under the current fence.
	 *
	 * Persisted only while this identity and sequence remain current, so a
	 * superseded or conflicting command can never stamp its category onto a row
	 * that has already moved on.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function record_error_category( int $portal_hall_id, array $fence, string $category ) {
		if ( ! in_array( $category, self::LAST_ERROR_CATEGORIES, true ) ) {
			return self::error( 'internal_error', 'The provisioning error category is not permitted.' );
		}

		return self::write_error_category( $portal_hall_id, $fence, $category );
	}

	/**
	 * Clear this command's failure category under the current fence.
	 *
	 * Successful confirmation must leave no stale error behind, because
	 * `already_applied` requires a null error field. A clear that cannot be read
	 * back is NOT success.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function clear_error_category( int $portal_hall_id, array $fence ) {
		return self::write_error_category( $portal_hall_id, $fence, null );
	}

	/**
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function write_error_category( int $portal_hall_id, array $fence, ?string $category ) {
		$checked = self::require_fence( $fence );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$args     = [];
		$recorded = self::nullable( $category, $args );
		$args     = array_merge(
			$args,
			[
				gmdate( self::TIMESTAMP_FORMAT ),
				$portal_hall_id,
				$fence['operation_id'],
				$fence['intent_sequence'],
			]
		);

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET last_error_category = {$recorded}, updated_at = %s
			 WHERE portal_hall_id = %d AND operation_id = %s AND intent_sequence = %d",
			$args
		);

		// Recorded, never returned on its own: the read-back below decides.
		$written = self::execute( $sql );

		$row = self::load( $portal_hall_id );
		if ( $row instanceof \WP_Error || null === $row ) {
			return self::error( 'unverified', 'The provisioning error category could not be confirmed.' );
		}
		if ( ! self::fence_matches( $row, $fence ) ) {
			return self::error( 'unverified', 'The provisioning error category could not be confirmed.' );
		}
		if ( $category === $row['last_error_category'] ) {
			// PROVEN, whatever the driver reported.
			return $row;
		}
		if ( $written instanceof \WP_Error ) {
			return self::error( 'state_write_failed', 'The provisioning error category could not be written.' );
		}

		return self::error( 'unverified', 'The provisioning error category could not be confirmed.' );
	}

	/**
	 * Confirm enforcement: `enforced_sequence` becomes `intent_sequence`.
	 *
	 * The single point at which a command becomes "done". The guard requires the
	 * current identity AND sequence, and the read-back must show full
	 * enforcement with a null error field — the two facts `already_applied`
	 * depends on. Anything less is `unverified`, never success.
	 *
	 * @param array<string,mixed> $fence
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function confirm_enforced_sequence( int $portal_hall_id, array $fence ) {
		$checked = self::require_fence( $fence );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		global $wpdb;
		$table = ProvisioningMigrations::operations_table();

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET enforced_sequence = intent_sequence, last_error_category = NULL, updated_at = %s
			 WHERE portal_hall_id = %d AND operation_id = %s AND intent_sequence = %d",
			gmdate( self::TIMESTAMP_FORMAT ),
			$portal_hall_id,
			$fence['operation_id'],
			$fence['intent_sequence']
		);

		// Recorded, never returned on its own: the read-back below decides.
		$written = self::execute( $sql );

		$row = self::load( $portal_hall_id );
		if ( $row instanceof \WP_Error || null === $row ) {
			return self::error( 'unverified', 'The provisioning command could not be confirmed.' );
		}
		if ( ! self::fence_matches( $row, $fence ) ) {
			// A lost fence after execute() is never a confirmation.
			return self::error( 'unverified', 'The provisioning command could not be confirmed.' );
		}
		if ( $row['enforced_sequence'] === $row['intent_sequence'] && null === $row['last_error_category'] ) {
			// PROVEN confirmed, whatever the driver reported.
			return $row;
		}
		if ( $written instanceof \WP_Error ) {
			return self::error( 'confirmation_failed', 'The provisioning command could not be confirmed.' );
		}

		return self::error( 'unverified', 'The provisioning command could not be confirmed.' );
	}

	/**
	 * Is this command fully confirmed already?
	 *
	 * @param array<string,mixed> $row
	 */
	public static function is_confirmed( array $row ): bool {
		return ( $row['enforced_sequence'] ?? null ) === ( $row['intent_sequence'] ?? 0 )
			&& null === ( $row['last_error_category'] ?? null );
	}

	/* ------------------------------------------------------------------ *
	 * Fencing
	 * ------------------------------------------------------------------ */

	/**
	 * Does the stored row still carry this command's identity and sequence?
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $fence
	 */
	public static function fence_matches( array $row, array $fence ): bool {
		return is_string( $row['operation_id'] ?? null )
			&& is_string( $fence['operation_id'] ?? null )
			&& hash_equals( (string) $row['operation_id'], (string) $fence['operation_id'] )
			&& ( $row['intent_sequence'] ?? null ) === ( $fence['intent_sequence'] ?? null );
	}

	/**
	 * The pre-mutation guard: mutex ownership AND the command fence, together.
	 *
	 * Called immediately before each irreversible or externally visible
	 * mutation. The two failure mappings are deliberately different and depend
	 * on the caller, not on this method's own view of the world:
	 *
	 *   - NOTHING HAS BEEN MUTATED YET -> `lock_unavailable`. It is honest and
	 *     it is retryable: no work was done, so the same command may come again.
	 *   - A MUTATION MAY ALREADY HAVE HAPPENED -> `unverified`. Reporting
	 *     `lock_unavailable` there would tell Portal nothing changed, which
	 *     might be false.
	 *
	 * @param array<string,mixed> $fence
	 * @return true|\WP_Error
	 */
	public static function guard( ProvisioningMutex $mutex, int $portal_hall_id, array $fence, bool $mutation_possible ) {
		$failure = $mutation_possible
			? self::error( 'unverified', 'The provisioning outcome could not be verified.' )
			: self::error( 'lock_unavailable', 'The provisioning command is no longer current.' );

		if ( $mutex->portalHallId() !== $portal_hall_id ) {
			return self::error( 'internal_error', 'The provisioning mutex does not cover this Hall.' );
		}
		if ( ! $mutex->isStillHeld() ) {
			return $failure;
		}

		$row = self::load( $portal_hall_id );
		if ( $row instanceof \WP_Error || null === $row || ! self::fence_matches( $row, $fence ) ) {
			return $failure;
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Durable ownership
	 * ------------------------------------------------------------------ */

	/**
	 * The ADR-042 three-fact durable-ownership test.
	 *
	 * All three must agree: the row carries `blog_id`; the row carries
	 * `site_ownership_id`; and that blog's `bc_commerce_managed` witness equals
	 * that same ownership ID. Slug equality is not one of the facts, and the
	 * current Portal `operation_id` is not an ownership witness.
	 *
	 * READ-ONLY. It creates, repairs and writes nothing.
	 *
	 * @param array<string,mixed> $row
	 * @return bool|\WP_Error True/false when the answer is proven; WP_Error when
	 *                        the witness could not be read at all.
	 */
	public static function has_durable_ownership( array $row ) {
		$blog_id  = $row['blog_id'] ?? null;
		$ownership = $row['site_ownership_id'] ?? null;

		if ( ! is_int( $blog_id ) || $blog_id < 1 || ! ProvisioningCanonicalRequest::is_uuid( $ownership ) ) {
			return false;
		}

		$marker = HallCommerceAccess::read_marker_value( $blog_id );
		if ( HallCommerceAccess::MARKER_UNREADABLE === $marker['status'] ) {
			// "We could not ask" is not "the answer is no". Collapsing them
			// would let a database failure look like an unowned site.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( HallCommerceAccess::MARKER_VALID !== $marker['status'] || null === $marker['value'] ) {
			return false;
		}

		return hash_equals( (string) $ownership, (string) $marker['value'] );
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * A bound placeholder for a value, or the SQL keyword `NULL`.
	 *
	 * `wpdb::prepare()` renders a PHP null as an EMPTY STRING, so a nullable
	 * column written through `%s` would store `''` rather than SQL NULL — and an
	 * empty slug, Hall name or error category is not a value validate_stored_row()
	 * can ever read back, so the row would be permanently unusable. The keyword
	 * is a fixed literal chosen here and is never derived from request data;
	 * every non-null value still travels as a bound parameter, appended to
	 * `$args` in placeholder order.
	 *
	 * @param mixed            $value
	 * @param array<int,mixed> $args
	 */
	private static function nullable( $value, array &$args ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		$args[] = $value;

		return '%s';
	}

	/**
	 * Run one prepared statement with errors suppressed.
	 *
	 * @param string|false $sql
	 * @return true|\WP_Error
	 */
	private static function execute( $sql ) {
		if ( ! is_string( $sql ) || '' === $sql ) {
			return self::error( 'internal_error', 'The provisioning statement could not be prepared.' );
		}

		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			$result = $wpdb->query( $sql );
			$error  = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'state_write_failed', 'The provisioning operation could not be written.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( false === $result || '' !== $error ) {
			return self::error( 'state_write_failed', 'The provisioning operation could not be written.' );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $request
	 */
	private static function matches_command( array $row, array $request ): bool {
		return hash_equals( (string) $row['operation_id'], (string) $request['operation_id'] )
			&& $row['intent_sequence'] === $request['intent_sequence']
			&& hash_equals( (string) $row['request_fingerprint'], (string) $request['request_fingerprint'] );
	}

	/**
	 * @param array<string,mixed> $request
	 * @return true|\WP_Error
	 */
	private static function require_canonical( array $request ) {
		$fields = [
			'portal_hall_id',
			'operation_id',
			'intent_sequence',
			'desired_state',
			'site_slug',
			'hall_name',
			'portal_actor_id',
			'request_canonical_version',
			'request_fingerprint',
		];
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				return self::error( 'validation_failed', 'The provisioning command is incomplete.' );
			}
		}

		if ( ! is_int( $request['portal_hall_id'] ) || $request['portal_hall_id'] < 1
			|| ! ProvisioningCanonicalRequest::is_uuid( $request['operation_id'] )
			|| ! is_int( $request['intent_sequence'] )
			|| $request['intent_sequence'] < 1
			|| $request['intent_sequence'] > ProvisioningCanonicalRequest::max_intent_sequence()
			|| ! in_array( $request['desired_state'], ProvisioningCanonicalRequest::DESIRED_STATES, true )
			|| ProvisioningCanonicalRequest::CANONICAL_VERSION !== $request['request_canonical_version']
			|| ! ProvisioningCanonicalRequest::is_fingerprint( $request['request_fingerprint'] )
			|| ! is_int( $request['portal_actor_id'] ) || $request['portal_actor_id'] < 1 ) {
			return self::error( 'validation_failed', 'The provisioning command is invalid.' );
		}
		if ( null !== $request['site_slug'] && ! ProvisioningCanonicalRequest::is_slug( $request['site_slug'] ) ) {
			return self::error( 'validation_failed', 'The site slug is invalid.' );
		}
		if ( null !== $request['hall_name'] && ! ProvisioningCanonicalRequest::is_valid_hall_name( $request['hall_name'] ) ) {
			return self::error( 'validation_failed', 'The Hall name is invalid.' );
		}

		/*
		 * THE ADR-042 LIFECYCLE SHAPE, ENFORCED HERE RATHER THAN ASSUMED.
		 * Field validation answers "is this a well-formed value"; it cannot
		 * answer "is this a command this state may issue", because a null
		 * address is correct for a suspend and for a post-ownership enable and
		 * wrong for everything else. Persistence is the last place that can
		 * refuse an impossible combination before it becomes a stored row that
		 * validate_stored_row() would then have to reject forever.
		 */
		if ( ProvisioningCanonicalRequest::SUSPENDED === $request['desired_state']
			&& ( null !== $request['site_slug'] || null !== $request['hall_name'] ) ) {
			return self::error( 'validation_failed', 'A suspend command carries no address or Hall name.' );
		}
		if ( ( null === $request['site_slug'] ) !== ( null === $request['hall_name'] ) ) {
			return self::error( 'validation_failed', 'The address and the Hall name must be supplied together.' );
		}

		/*
		 * The fingerprint is recomputed rather than trusted. A caller that
		 * assembled the array by hand must not be able to persist a request
		 * whose stored fingerprint does not describe its own stored values —
		 * validate_stored_row() would then correctly refuse to read it back.
		 */
		$recomputed = ProvisioningCanonicalRequest::fingerprint( $request );
		if ( ! is_string( $recomputed ) || ! hash_equals( (string) $request['request_fingerprint'], $recomputed ) ) {
			return self::error( 'validation_failed', 'The provisioning fingerprint does not match the command.' );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $fence
	 * @return true|\WP_Error
	 */
	private static function require_fence( array $fence ) {
		if ( ! ProvisioningCanonicalRequest::is_uuid( $fence['operation_id'] ?? null )
			|| ! is_int( $fence['intent_sequence'] ?? null )
			|| $fence['intent_sequence'] < 1 ) {
			return self::error( 'internal_error', 'The provisioning command fence is invalid.' );
		}

		return true;
	}

	/**
	 * Strict unsigned-integer reading of a stored value.
	 *
	 * Representation before value: the digits are matched first, then the cast
	 * is checked to round-trip, so anything outside PHP's integer range is
	 * rejected rather than silently clamped into a comparable-looking number.
	 *
	 * @param mixed $value
	 */
	private static function stored_unsigned( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d+\z/', $value ) ) {
			return null;
		}

		$parsed = (int) $value;

		return (string) $parsed === $value ? $parsed : null;
	}

	/**
	 * A stored nullable TEXTUAL column: null, a string, or FALSE for "not a value".
	 *
	 * False rather than null for the rejection, so a genuine SQL NULL and an
	 * unusable type cannot be confused.
	 *
	 * NOTHING IS CAST INTO A STRING HERE. An integer slug, Hall name, UUID,
	 * fingerprint or error category is not one of those things spelled
	 * differently; it is a value that could not have been written by this build,
	 * and casting it would manufacture a plausible-looking value out of storage
	 * this code must refuse. Numeric columns have their own strict parser.
	 *
	 * @param mixed $value
	 * @return string|null|false
	 */
	private static function stored_nullable_string( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return $value;
		}

		return false;
	}

	private static function is_timestamp( string $value ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( self::TIMESTAMP_FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		// createFromFormat() rolls impossible calendar values forward instead of
		// failing, so an exact round-trip is required before the value is trusted.
		return $parsed instanceof \DateTimeImmutable && $value === $parsed->format( self::TIMESTAMP_FORMAT );
	}

	/**
	 * The single corrupt-row error.
	 *
	 * Deliberately one fixed message for every invariant above: naming which
	 * column disagreed would describe stored state to a caller that must not
	 * learn it, and every one of them leads to the same fail-closed handling.
	 */
	private static function corrupt(): \WP_Error {
		return self::error( 'operation_row_invalid', 'The stored provisioning operation is not usable.' );
	}

	/** Fixed categories only: no SQL, driver text, payload, UUID or credential. */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
