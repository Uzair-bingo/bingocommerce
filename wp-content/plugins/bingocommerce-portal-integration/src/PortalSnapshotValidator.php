<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict, side-effect-free validator for the Portal Hall snapshot v1 payload.
 *
 * Turns the raw HTTP 200 body produced by PortalHttpClient into a trusted,
 * reconstructed in-memory array, or into a WP_Error. Nothing here reads or
 * writes the database, issues a request, switches sites, or applies a snapshot;
 * snapshot application/import is a later phase.
 *
 * Every accepted field is listed by hand below. A field the Portal adds later
 * cannot reach the reconstructed result by default — it rejects the whole
 * snapshot until it is deliberately allowlisted here.
 */
final class PortalSnapshotValidator {

	private const API_VERSION = 'v1';

	private const TIMESTAMP_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-](?:[01]\d|2[0-3]):[0-5]\d\z/';
	private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:sP';

	private const ENVELOPE_FIELDS = [ 'api_version', 'generated_at', 'hall', 'game_types', 'sessions' ];
	private const HALL_FIELDS = [ 'id', 'name', 'state', 'updated_at' ];
	private const GAME_TYPE_FIELDS = [ 'id', 'hall_id', 'name', 'updated_at' ];
	private const SESSION_FIELDS = [ 'id', 'hall_id', 'name', 'status', 'updated_at' ];

	private const HALL_STATES = [ 'active', 'inactive' ];
	private const SESSION_STATUSES = [ 'active', 'draft' ];

	private const HALL_NAME_MAX = 255;
	private const GAME_TYPE_NAME_MAX = 50;
	private const SESSION_NAME_MAX = 255;

	/**
	 * Validate one raw Portal Hall snapshot body.
	 *
	 * The expected Portal Hall ID comes from trusted mapping/orchestration
	 * context. It is never derived from the payload, a hostname, or a name.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( string $raw_body, int $expected_portal_hall_id ) {
		if ( $expected_portal_hall_id < 1 ) {
			return $this->error( 'invalid_portal_hall_id', 'The expected Portal Hall ID is invalid.' );
		}

		$snapshot = $this->decode( $raw_body );
		if ( $snapshot instanceof \WP_Error ) {
			return $snapshot;
		}

		$envelope = $this->require_exact_members( $snapshot, self::ENVELOPE_FIELDS, 'root' );
		if ( $envelope instanceof \WP_Error ) {
			return $envelope;
		}

		if ( ! is_string( $snapshot->api_version ) || self::API_VERSION !== $snapshot->api_version ) {
			return $this->error(
				'portal_snapshot_unsupported_version',
				'The Portal snapshot API version is not supported.',
				'api_version'
			);
		}

		if ( ! $this->is_portal_timestamp( $snapshot->generated_at ) ) {
			return $this->contract_error( 'generated_at' );
		}

		$hall = $this->validate_hall( $snapshot->hall, $expected_portal_hall_id );
		if ( $hall instanceof \WP_Error ) {
			return $hall;
		}

		$game_types = $this->validate_collection(
			$snapshot->game_types,
			'game_types',
			self::GAME_TYPE_FIELDS,
			self::GAME_TYPE_NAME_MAX,
			null,
			$expected_portal_hall_id
		);
		if ( $game_types instanceof \WP_Error ) {
			return $game_types;
		}

		$sessions = $this->validate_collection(
			$snapshot->sessions,
			'sessions',
			self::SESSION_FIELDS,
			self::SESSION_NAME_MAX,
			self::SESSION_STATUSES,
			$expected_portal_hall_id
		);
		if ( $sessions instanceof \WP_Error ) {
			return $sessions;
		}

		// A new array built from validated members only. The decoded objects are
		// deliberately not returned, so nothing outside the allowlist can travel
		// onward with the snapshot.
		return [
			'api_version'  => $snapshot->api_version,
			'generated_at' => $snapshot->generated_at,
			'hall'         => $hall,
			'game_types'   => $game_types,
			'sessions'     => $sessions,
		];
	}

	/** @return \stdClass|\WP_Error */
	private function decode( string $raw_body ) {
		if ( '' === $raw_body ) {
			return $this->error( 'portal_snapshot_invalid_json', 'The Portal snapshot response is empty.' );
		}

		try {
			// Objects stay objects so {} and [] remain distinguishable, and
			// JSON_BIGINT_AS_STRING keeps an oversized integer as a string
			// instead of rounding it into a plausible Portal identity. The
			// invalid-UTF-8 tolerance flags are deliberately not used.
			//
			// The depth argument is explicitly PHP's standard 512, supplied
			// because the flags argument follows it positionally. That 512 is a
			// technical decoder safety bound only: it carries no Portal v1
			// business or API meaning, BingoCommerce derives no domain
			// semantics from it, and no BingoCommerce domain constant is
			// introduced for it. Changing this technical bound later requires
			// implementation review, but does not by itself require a Portal
			// API-version change. A body nested beyond it fails safely as
			// invalid JSON.
			$decoded = json_decode(
				$raw_body,
				false,
				512,
				JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
			);
		} catch ( \JsonException $exception ) {
			// The upstream message can quote the offending body, so only the
			// stable category escapes.
			return $this->error( 'portal_snapshot_invalid_json', 'The Portal snapshot response is not valid JSON.' );
		}

		if ( ! $decoded instanceof \stdClass ) {
			return $this->contract_error( 'root' );
		}

		return $decoded;
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_hall( $value, int $expected_portal_hall_id ) {
		if ( ! $value instanceof \stdClass ) {
			return $this->contract_error( 'hall' );
		}

		$members = $this->require_exact_members( $value, self::HALL_FIELDS, 'hall' );
		if ( $members instanceof \WP_Error ) {
			return $members;
		}

		if ( ! $this->is_portal_id( $value->id ) ) {
			return $this->contract_error( 'hall.id' );
		}
		if ( $value->id !== $expected_portal_hall_id ) {
			return $this->error(
				'portal_snapshot_hall_mismatch',
				'The Portal snapshot describes a different Portal Hall.',
				'hall.id'
			);
		}
		if ( ! $this->is_valid_name( $value->name, self::HALL_NAME_MAX ) ) {
			return $this->contract_error( 'hall.name' );
		}
		if ( ! $this->is_enum( $value->state, self::HALL_STATES ) ) {
			return $this->contract_error( 'hall.state' );
		}
		if ( ! $this->is_nullable_timestamp( $value->updated_at ) ) {
			return $this->contract_error( 'hall.updated_at' );
		}

		return [
			'id'         => $value->id,
			'name'       => $value->name,
			'state'      => $value->state,
			'updated_at' => $value->updated_at,
		];
	}

	/**
	 * One Game Type or Session Template collection.
	 *
	 * Input order is preserved exactly. The Portal happens to order by ID, but
	 * that is not required here and no sorting is applied.
	 *
	 * @param mixed $value
	 * @param array<int,string> $fields
	 * @param array<int,string>|null $statuses Allowed status values, or null when the row carries no status.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function validate_collection(
		$value,
		string $path,
		array $fields,
		int $name_max,
		?array $statuses,
		int $expected_portal_hall_id
	) {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return $this->contract_error( $path );
		}

		$rows = [];
		$seen_ids = [];
		foreach ( $value as $index => $row ) {
			$row_path = $path . '[' . $index . ']';

			if ( ! $row instanceof \stdClass ) {
				return $this->contract_error( $row_path );
			}

			$members = $this->require_exact_members( $row, $fields, $row_path );
			if ( $members instanceof \WP_Error ) {
				return $members;
			}

			if ( ! $this->is_portal_id( $row->id ) ) {
				return $this->contract_error( $row_path . '.id' );
			}
			// Identity is unique per collection. The same number may legitimately
			// name one Game Type and one Session, so the sets are kept separate.
			if ( isset( $seen_ids[ $row->id ] ) ) {
				return $this->error(
					'portal_snapshot_duplicate_identity',
					'The Portal snapshot repeats a Portal identity within one collection.',
					$row_path . '.id'
				);
			}
			$seen_ids[ $row->id ] = true;

			if ( ! $this->is_portal_id( $row->hall_id ) ) {
				return $this->contract_error( $row_path . '.hall_id' );
			}
			if ( $row->hall_id !== $expected_portal_hall_id ) {
				return $this->error(
					'portal_snapshot_hall_mismatch',
					'The Portal snapshot contains a record owned by a different Portal Hall.',
					$row_path . '.hall_id'
				);
			}

			if ( ! $this->is_valid_name( $row->name, $name_max ) ) {
				return $this->contract_error( $row_path . '.name' );
			}
			if ( ! $this->is_nullable_timestamp( $row->updated_at ) ) {
				return $this->contract_error( $row_path . '.updated_at' );
			}

			$reconstructed = [
				'id'         => $row->id,
				'hall_id'    => $row->hall_id,
				'name'       => $row->name,
				'updated_at' => $row->updated_at,
			];

			if ( null !== $statuses ) {
				if ( ! $this->is_enum( $row->status, $statuses ) ) {
					return $this->contract_error( $row_path . '.status' );
				}
				$reconstructed = [
					'id'         => $row->id,
					'hall_id'    => $row->hall_id,
					'name'       => $row->name,
					'status'     => $row->status,
					'updated_at' => $row->updated_at,
				];
			}

			$rows[] = $reconstructed;
		}

		return $rows;
	}

	/**
	 * The decoded object must carry exactly the allowlisted members.
	 *
	 * A missing member and an unknown member are both fatal, so an added Portal
	 * field cannot pass unnoticed and a partial document cannot be accepted.
	 *
	 * @param array<int,string> $allowed
	 * @return true|\WP_Error
	 */
	private function require_exact_members( \stdClass $object, array $allowed, string $path ) {
		$members = get_object_vars( $object );
		if ( count( $members ) !== count( $allowed ) ) {
			return $this->contract_error( $path );
		}
		foreach ( $allowed as $field ) {
			// array_key_exists(), not isset(): a required member explicitly set
			// to null is present and must be validated, not reported missing.
			if ( ! array_key_exists( $field, $members ) ) {
				return $this->contract_error( $path );
			}
		}

		return true;
	}

	/** @param mixed $value */
	private function is_portal_id( $value ): bool {
		// Native integers only. A numeric string, float, or boolean is a
		// contract violation, never something to coerce into an identity.
		return is_int( $value ) && $value >= 1;
	}

	/**
	 * @param mixed $value
	 * @param array<int,string> $allowed
	 */
	private function is_enum( $value, array $allowed ): bool {
		return is_string( $value ) && in_array( $value, $allowed, true );
	}

	/** @param mixed $value */
	private function is_valid_name( $value, int $max ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return false;
		}

		// ADR-039 rejects control/format characters and line/paragraph
		// separators, and requires a non-whitespace, non-separator character.
		// Inspect the original value only; accepted Portal names stay unchanged.
		if (
			0 !== preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value )
			|| 1 !== preg_match( '/[^\s\p{Z}]/u', $value )
		) {
			return false;
		}

		$length = $this->unicode_length( $value );

		return null !== $length && $length <= $max;
	}

	/**
	 * Unicode character count, matching the Portal's own character-based limits.
	 *
	 * Returns null when the value cannot be counted safely, which rejects the
	 * name rather than falling back to a byte length that would accept names
	 * longer than the Portal column allows.
	 */
	private function unicode_length( string $value ): ?int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value );

		return false === $count ? null : (int) $count;
	}

	/** @param mixed $value */
	private function is_nullable_timestamp( $value ): bool {
		return null === $value || $this->is_portal_timestamp( $value );
	}

	/**
	 * The exact Portal Atom serialization: YYYY-MM-DDTHH:MM:SS±HH:MM.
	 *
	 * No fractional seconds, and an explicit numeric offset — the Portal formats
	 * with DateTime::ATOM, which never emits a `Z` suffix.
	 *
	 * @param mixed $value
	 */
	private function is_portal_timestamp( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( self::TIMESTAMP_PATTERN, $value ) ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( self::TIMESTAMP_FORMAT, $value );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return false;
		}

		// createFromFormat() rolls impossible calendar values forward instead of
		// failing, so an accepted parse is not enough on its own: the warnings
		// and an exact round-trip both have to agree.
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( 0 !== (int) ( $errors['warning_count'] ?? 0 ) || 0 !== (int) ( $errors['error_count'] ?? 0 ) ) ) {
			return false;
		}

		return $value === $parsed->format( self::TIMESTAMP_FORMAT );
	}

	private function contract_error( string $path ): \WP_Error {
		return $this->error(
			'portal_snapshot_invalid_contract',
			'The Portal snapshot does not match the supported contract.',
			$path
		);
	}

	/**
	 * Only a validator-generated structural path is ever attached.
	 *
	 * No decoded value, Portal name, request argument, credential, upstream
	 * response, or raw body byte reaches an error message or its data.
	 *
	 * The message is a fixed validator-owned developer string and is passed
	 * through unlocalized on purpose: __() fires the gettext filters, and this
	 * class must stay callback-free so validating untrusted Portal data cannot
	 * run arbitrary third-party code. Localization, if ever wanted, belongs at
	 * a later presentation boundary.
	 */
	private function error( string $code, string $message, string $path = '' ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			'' === $path ? [] : [ 'path' => $path ]
		);
	}
}
